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
        'Fine if DB_HOST and the rest are set as environment variables; otherwise copy ' .
        'config.local.example.php and fill it in.');

  try {
    db();
    $out[] = check('pass', 'Database reachable', $cfg['db_name'] . ' on ' . $cfg['db_host']);
    $tables = db_count("SELECT COUNT(*) FROM information_schema.tables
                         WHERE table_schema = ? AND table_name IN
                         ('settings','deliveries','sessions')", [$cfg['db_name']]);
    $out[] = $tables === 3
      ? check('pass', 'Schema is loaded')
      : check('fail', 'Schema is incomplete',
          "{$tables} of 3 tables found. Load db/schema.sql, then run tools/migrate.php.");
  } catch (Throwable $e) {
    $out[] = check('fail', 'Database unreachable', $e->getMessage());
    // Nothing below this reads without a database, so there is no point asking.
    return $out;
  }

  $out[] = setting('admin_password_hash') !== ''
    ? check('pass', 'These pages have a password')
    : check('fail', 'No password on these pages',
        'This is the machine you have deliberately made reachable. Anyone who finds it can point it ' .
        'at a different worker.', 'settings.php');

  // --- the site's own address --------------------------------------------------------------------
  // Not cosmetic: the cookie's Secure flag is decided from it, and it is the address printed
  // everywhere as the one to give the dispatcher. A default left in place is a working proxy nobody
  // can find.
  if (str_starts_with($cfg['site_url'], 'https://')) {
    $out[] = check('pass', 'SITE_URL is an https address', $cfg['site_url']);
  } else {
    $out[] = check('warn', 'SITE_URL is not https', $cfg['site_url'] .
      ' — the session cookie for these pages is sent without the Secure flag, which matters on a ' .
      'machine that is reachable from outside. It also has to be the address your instance calls, ' .
      'or the URL shown on the settings page is the wrong one to give the dispatcher.');
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
      $out[] = check('warn', 'Everything today was refused or failed',
        $counts['bad'] . ' envelope(s) arrived and none reached the worker.', 'deliveries.php?show=problems');
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
  $mins = (int)db_one('SELECT TIMESTAMPDIFF(MINUTE, ?, NOW()) AS m', [$datetime])['m'];
  if ($mins < 1)    { return 'just now'; }
  if ($mins < 60)   { return $mins . ' minutes ago'; }
  if ($mins < 1440) { return intdiv($mins, 60) . ' hours ago'; }
  return intdiv($mins, 1440) . ' days ago';
}
