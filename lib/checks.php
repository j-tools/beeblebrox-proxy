<?php
// Every question worth asking about whether this proxy is actually set up, asked in one place so
// that the diagnostics page and tools/selftest.php cannot give different answers.
//
// Three outcomes, and the middle one earns its keep: 'warn' is for something that is not wrong yet
// but will be the reason nothing works later — a worker that has stopped answering, a clock that has
// drifted, a day of arrivals that were all refused.

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/deliver.php';
require_once __DIR__ . '/deliveries.php';

// A check may carry somewhere to go. Most of these are fixed on a page somewhere, and a URL beats a
// sentence describing where that page is.
function check($state, $what, $detail = '', $url = null) {
  return ['state' => $state, 'what' => $what, 'detail' => $detail, 'url' => $url];
}

// $deep runs the checks that cost a network round trip. The dashboard skips them; the diagnostics
// page and the CLI do not.
function checks_run($deep = true) {
  $out = [];
  $cfg = bbl_config();

  // --- the things that have to exist before anything else can ------------------------------------
  $out[] = file_exists(__DIR__ . '/../config.local.php')
    ? check('pass', 'config.local.php is present')
    : check('warn', 'config.local.php is missing',
        'Fine if SITE_URL and the rest are set as environment variables; otherwise copy ' .
        'config.local.example.php and fill it in. Without one, the address printed here as the one ' .
        'to give the dispatcher is a guess.');

  // Which PHP is answering, and which SQLite came with it. Printed always, not only when something is
  // wrong: the usual cause of a version surprise is the web server loading a different PHP from the
  // one somebody installed, and that is invisible until the two are named side by side.
  $ini = php_ini_loaded_file();
  $out[] = check('pass', 'PHP ' . PHP_VERSION . ' as ' . php_sapi_name(),
    (PHP_BINARY !== '' ? PHP_BINARY . ' — ' : '') .
    ($ini !== false ? 'reading ' . $ini : 'no php.ini loaded'));

  try {
    db();
    $out[] = check('pass', 'The database is there', $cfg['db_file']);
    $out[] = check('pass', 'SQLite ' . db()->query('SELECT sqlite_version()')->fetchColumn(),
      'The library this PHP was built with. 3.7.0 or newer is needed, for write-ahead logging.');
    $tables = db_count("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'
                         AND name IN ('settings','deliveries','sessions')");
    $out[] = $tables === 3
      ? check('pass', 'Schema is loaded')
      : check('fail', 'Schema is incomplete',
          "{$tables} of 3 tables found, which should not be possible — the file is created with all " .
          'of them. Delete it and let it be made again, or run tools/migrate.php.');
  } catch (Throwable $e) {
    $out[] = check('fail', 'The database cannot be opened', $e->getMessage() .
      ' It is created on first use, so this is almost always the web server not being allowed to ' .
      'write to ' . dirname($cfg['db_file']) . '.');
    // Nothing below this reads without a database, so there is no point asking.
    return $out;
  }

  // The file holds session ids, so serving it is handing somebody a signed-in session — which is why
  // this is a failure and not a note. Only meaningful when the file is under the directory being
  // served, which is the default and not a requirement.
  $docroot = realpath(__DIR__ . '/..');
  $db_real = realpath($cfg['db_file']);
  if ($deep && $docroot && $db_real && str_starts_with($db_real, $docroot)) {
    $relative = str_replace('\\', '/', substr($db_real, strlen($docroot)));
    $url = rtrim($cfg['site_url'], '/') . $relative;
    $served = deliver_get($url, 8);
    if ($served['status'] >= 200 && $served['status'] < 300) {
      $out[] = check('fail', 'The database file is downloadable',
        'It answered HTTP ' . $served['status'] . ' over the web. It holds session ids, so anybody ' .
        'who fetches it is signed in here. Deny the data/ directory in your web server, or move ' .
        'db_file outside the directory being served.', $url);
    } elseif ($served['status'] === 0) {
      // Nothing answered, which is not the same as a refusal and must not be reported as one. The
      // usual cause is benign and specific: php -S is single-threaded, so it cannot serve this
      // request while it is still busy producing this page. On a real web server it answers.
      $out[] = check('warn', 'Could not tell whether the database file is served',
        'Asking for it over the web got no answer at all (' . $served['error'] . '). That is ' .
        'expected on php -S, which is single-threaded and cannot answer a second request while it ' .
        'is still building this page — but on the server this actually runs on, check it by hand.',
        $url);
    } else {
      $out[] = check('pass', 'The database file is not served',
        'Asked for over the web, it answered HTTP ' . $served['status'] . '.');
    }
  }

  $out[] = setting('admin_password_hash') !== ''
    ? check('pass', 'These pages have a password')
    : check('fail', 'No password on these pages',
        'This is the machine you have deliberately made reachable. Anyone who finds it can point it ' .
        'at a different worker.', 'settings.php');

  // --- the site's own address --------------------------------------------------------------------
  // Not cosmetic, and the one setting that cannot be checked by asking anything: it decides the
  // cookie's Secure flag and path, and it is the address printed everywhere as the one to give the
  // dispatcher. A proxy nobody outside can name is a proxy nobody outside can reach.
  $site_host = (string)parse_url($cfg['site_url'], PHP_URL_HOST);
  if (in_array($site_host, ['localhost', '127.0.0.1', '::1', ''], true)) {
    $out[] = check('fail', 'SITE_URL is still a local address', $cfg['site_url'] .
      ' — that is this machine talking to itself. It has to be the address your Beeblebrox instance ' .
      'calls from the internet, written exactly as it will call it, subdirectory and all. Until it ' .
      'is, the URL to hand the dispatcher is being printed wrong on every page here.');
  } elseif (str_starts_with($cfg['site_url'], 'https://')) {
    $out[] = check('pass', 'SITE_URL is an https address', $cfg['site_url']);
  } else {
    $out[] = check('warn', 'SITE_URL is not https', $cfg['site_url'] .
      ' — the envelope is signed and carries no work, so this is not a disaster, but the password ' .
      'for these pages crosses the internet in the clear and the session cookie goes without its ' .
      'Secure flag.');
  }

  // --- who this relays for -----------------------------------------------------------------------
  if (instance_base() === '') {
    $out[] = check('fail', 'No instance configured',
      'Every envelope names the instance it came from and is refused unless it names this one, so ' .
      'with nothing set here nothing can ever get through.', 'setup.php');
  } else {
    $out[] = check('pass', 'Relays for ' . company_name(), instance_base());
    if ($deep) {
      $health = deliver_instance_health(instance_base());
      $out[] = $health['ok']
        ? check('pass', 'The instance answers')
        // A warning and not a failure: envelopes are pushed from the instance to here, so this
        // proxy never needs to call it. A dead instance simply means nothing will arrive.
        : check('warn', 'The instance did not answer', $health['error'] .
            ' Nothing is delivered by calling it, so this does not stop anything on its own — but ' .
            'if envelopes have stopped arriving, this is why.', instance_base());
    }
  }

  // --- where it goes -----------------------------------------------------------------------------
  if (worker_base() === '') {
    $out[] = check('fail', 'No worker configured', 'There is nowhere to send anything that arrives.',
      'setup.php');
  } else {
    $out[] = check('pass', 'Delivers to ' . worker_hook_url());
    if ($deep) {
      $probe = deliver_probe();
      $out[] = $probe['ok']
        ? check('pass', 'The worker answers as a Beeblebrox receiver',
            'A GET is refused with 405, which is what hook.php does and what a web server that ' .
            'merely exists does not.')
        : check('fail', 'The worker did not answer properly', $probe['error'], $probe['url']);
    }
  }

  $out[] = setting_bool('accept_webhooks')
    ? check('pass', 'Forwarding is on')
    : check('fail', 'Forwarding is switched off',
        'Everything that arrives is answered with a 503 and passed on to nobody.', 'settings.php');

  // --- the optional locks ------------------------------------------------------------------------
  if (setting_secret_is_set('webhook_secret')) {
    $out[] = check('pass', 'Signatures are checked here as well as at the worker',
      'A forged envelope is refused on this machine rather than carried onto your network. Keep the ' .
      "clock tolerance the same at both ends, or this machine's clock becomes a second thing that " .
      'can refuse a good envelope.');
  } else {
    // Deliberately a pass. This is the documented default and the worker checks every signature
    // regardless; calling it a warning would report the ordinary setup as half-finished.
    $out[] = check('pass', 'Signatures are checked at the worker',
      'Nothing is verified here, which is the normal setup — the worker has to check anyway. Storing ' .
      'the signing secret here as well would mean rubbish addressed at your worker got no further ' .
      'than this machine.', 'settings.php');
  }

  $out[] = trim((string)setting('allowed_ips')) === ''
    ? check('pass', 'Any address may deliver',
        'Right when the instance is behind a load balancer or a CDN and does not call from one ' .
        'address. If it does have one, naming it here is worth more than it is on the worker, ' .
        'which only ever sees this machine.', 'settings.php')
    : check('pass', 'Only listed addresses may deliver', setting('allowed_ips'));

  if (!secrets_available() && setting_secret_is_set('webhook_secret')) {
    $out[] = check('fail', 'SECRET_KEY is gone but a secret is stored',
      'The stored signing secret cannot be decrypted, so every envelope is refused. Put SECRET_KEY ' .
      'back, or clear the secret and let the worker do the checking.', 'settings.php');
  }

  // --- what has actually been happening ----------------------------------------------------------
  $last = delivery_last_at();
  if ($last === null) {
    $out[] = check('warn', 'Nothing has ever arrived',
      'Expected until the dispatcher on the instance has been pointed at ' . bbl_hook_url() .
      '. Its own test button is the quickest way to find out whether it can reach this machine.');
  } else {
    $counts = delivery_counts_today();
    if ($counts['total'] === 0) {
      $out[] = check('pass', 'Nothing today', 'The last envelope arrived ' . view_ago_plain($last) . '.');
    } elseif ($counts['ok'] === 0) {
      // The case worth a warning of its own: something is arriving and none of it is getting
      // through, which looks exactly like a quiet day from every count on the dashboard.
      //
      // "Not accepted" rather than "did not reach the worker", because some of them may well have
      // reached it and been refused there — and sending somebody to look at the network when the
      // answer is a mismatched secret is the wrong first place.
      $out[] = check('warn', 'Nothing today was accepted',
        $counts['bad'] . ' envelope(s) arrived and none came back accepted. The list says which were ' .
        'refused here and which the worker refused.', 'deliveries.php?show=problems');
    } else {
      $out[] = check('pass', "{$counts['ok']} through today",
        $counts['bad'] > 0 ? $counts['bad'] . ' did not get through.' : 'None failed.',
        $counts['bad'] > 0 ? 'deliveries.php?show=problems' : null);
    }
  }

  return $out;
}

// The same "5m ago" the pages use, without pulling in the whole view layer — checks_run is called
// from the CLI too, where nothing has been rendered and nothing should be.
function view_ago_plain($datetime) {
  $mins = (int)db_one("SELECT CAST((julianday('now') - julianday(?)) * 1440 AS INTEGER) AS m",
    [$datetime])['m'];
  if ($mins < 1)    { return 'just now'; }
  if ($mins < 60)   { return $mins . ' minutes ago'; }
  if ($mins < 1440) { return intdiv($mins, 60) . ' hours ago'; }
  return intdiv($mins, 1440) . ' days ago';
}
