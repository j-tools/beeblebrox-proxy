<?php
// Everything a person configures, read and written in one place.
//
// There are two questions this thing actually needs answered — which Beeblebrox, and which worker —
// and everything else below is a default that works. That is deliberate: a proxy is installed by
// somebody who is trying to get past a router, not by somebody who wants to configure a proxy.
//
// One value can be a secret. A signing secret has to be *used* rather than compared, because
// checking a signature means reproducing the same HMAC the dispatcher produced, so it cannot be
// hashed and is stored encrypted under SECRET_KEY instead.

require_once __DIR__ . '/secrets.php';

function bbl_setting_defaults() {
  return [
    // Which Beeblebrox this relays for. Every envelope names the instance it came from, and one that
    // names a different instance is refused — this is what stops a proxy that somebody found being
    // used to reach a worker on your network from somewhere else.
    'instance_url'        => '',

    // What to call the company whose work passes through here. Presentation only — nothing routes on
    // it — but it is what the bar says, and somebody running a proxy for two companies needs to know
    // at a glance which window is which.
    'company_name'        => '',

    // Where an accepted envelope goes. The base address of the worker is enough; hook.php is added
    // if the address does not already name a file.
    'worker_url'          => '',

    'accept_webhooks'     => '1',

    // How long to wait for the worker. The worker's own hook.php only records the envelope and
    // answers — the work happens on its own schedule afterwards — so this covers an acceptance and
    // not a run. Long enough for a sleeping machine's network card to wake up, short enough that the
    // dispatcher upstream has not already given up on us.
    'deliver_timeout'     => '15',

    // Optional second lock. Empty means this proxy forwards without checking the signature and the
    // worker checks it, which is the ordinary setup: the worker has to check anyway, and a proxy
    // holding the secret adds a second copy of it and a second clock that can drift.
    //
    // Set it when this box is on a public address and you would rather a forged envelope was refused
    // here than allowed as far as the machine it was aimed at.
    'webhook_secret'      => '',
    // Only consulted when a secret is set. Also the replay window.
    'signature_tolerance' => '300',

    // Comma-separated. Empty means any address. Worth filling in here rather than on the worker: the
    // instance calls this from one known address, while what reaches the worker comes from this
    // proxy and tells it nothing.
    'allowed_ips'         => '',

    // Sign-in for these pages. Set on first run.
    'admin_password_hash' => '',

    // Which setup questions have actually been answered, as opposed to which settings happen to hold
    // something. Both of the wizard's steps could otherwise look answered before anybody read them.
    'setup_answered'      => '',
  ];
}

function bbl_secret_settings() {
  return ['webhook_secret'];
}

// All settings, defaults filled in, secrets left encrypted. Read once per request.
function settings_raw($refresh = false) {
  static $cache = null;
  if ($cache === null || $refresh) {
    $cache = bbl_setting_defaults();
    foreach (db_all('SELECT name, value FROM settings') as $row) {
      $cache[$row['name']] = $row['value'];
    }
  }
  return $cache;
}

function setting($name, $default = null) {
  $all = settings_raw();
  return array_key_exists($name, $all) ? $all[$name] : $default;
}

function setting_bool($name) {
  return (string)setting($name) === '1';
}

function setting_int($name, $default = 0) {
  $value = setting($name);
  return $value === null || $value === '' ? $default : (int)$value;
}

// The plaintext of a secret, or '' when none is stored. Throws only if the stored value cannot be
// decrypted, which means SECRET_KEY changed and the value has to be entered again — a caller
// swallowing that would turn a fixable configuration error into a mystery.
function setting_secret($name) {
  $stored = (string)setting($name);
  return $stored === '' ? '' : secrets_decrypt($stored);
}

function setting_secret_is_set($name) {
  return (string)setting($name) !== '';
}

// Writes one setting, encrypting it if it is one of the secret ones. Passing '' for a secret clears
// it; a caller that means "leave it alone" must not call this at all.
function setting_set($name, $value) {
  $is_secret = in_array($name, bbl_secret_settings(), true);
  if ($is_secret && (string)$value !== '') {
    $value = secrets_encrypt($value);
  }
  db_exec(
    'INSERT INTO settings (name, value, is_secret) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE value = VALUES(value), is_secret = VALUES(is_secret)',
    [$name, (string)$value, $is_secret ? 1 : 0]
  );
  settings_raw(true);
}

// The instance base URL with no trailing slash, which is what every comparison wants. '' when unset.
function instance_base() {
  return rtrim((string)setting('instance_url'), '/');
}

// What the company is called, falling back to the instance's own name — which is the same word in
// every hosted case, since an instance is <company>.beeblebrox.cloud.
//
// Capitalized on the way out, because the fallback is a hostname label and a bar reading "zaphod
// Beeblebrox" looks like something went wrong rather than like a name. Anything typed on the
// settings page is used exactly as typed.
function company_name() {
  $name = trim((string)setting('company_name'));
  return $name !== '' ? $name : ucfirst(instance_name());
}

// The first label of the instance's hostname: 'zaphod' out of zaphod.beeblebrox.cloud. This is the
// word a person actually knows — nobody thinks of their company as a URL — so it is what the setup
// wizard asks for and what a company name is guessed from.
function instance_name() {
  $host = parse_url(instance_base(), PHP_URL_HOST);
  if (!$host) {
    return '';
  }
  $labels = explode('.', $host);
  return $labels[0];
}

// Turns whatever somebody typed into the instance's base URL. Same rule as the local worker's, so a
// bare instance name works in both places rather than being a convenience of one of them.
//
// A bare word is the case worth having: an instance is <company>.beeblebrox.cloud, so 'zaphod' is
// enough. Anything with a dot or a scheme in it is taken as an address, which is what a self-hosted
// instance needs. Throws rather than guessing at something unusable, because the message is the
// whole value here.
function instance_normalize($input) {
  $input = trim((string)$input);
  if ($input === '') {
    return '';
  }
  if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/i', $input)) {
    return 'https://' . strtolower($input) . '.beeblebrox.cloud';
  }
  if (!preg_match('#^https?://#i', $input)) {
    // No scheme but not a bare name either — a hostname, or something with a port or a path. https
    // is the only sane assumption for an instance; a plain-http one can still be typed in full.
    $input = 'https://' . $input;
  }
  $host = parse_url($input, PHP_URL_HOST);
  if (!$host || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $host)) {
    throw new RuntimeException(
      "\"{$input}\" is not an address this can use. Either the name of the instance on its own — " .
      'zaphod, say, for zaphod.beeblebrox.cloud — or the full address of a self-hosted one.');
  }
  return rtrim($input, '/');
}

// The worker's base address with no trailing slash, or '' when unset.
function worker_base() {
  return rtrim((string)setting('worker_url'), '/');
}

// Turns whatever somebody typed into the worker's base address.
//
// http is assumed rather than https, which is the opposite of the rule for an instance and is the
// right way round for both: an instance is a hosted site with a certificate, and a worker is a
// machine on the same LAN reached by name or by address, where https would mean a self-signed
// certificate and an argument with curl. An address typed with https:// is of course kept.
//
// A port is the normal case here, so the host is validated and the rest of the URL is left alone.
function worker_normalize($input) {
  $input = trim((string)$input);
  if ($input === '') {
    return '';
  }
  if (!preg_match('#^https?://#i', $input)) {
    $input = 'http://' . $input;
  }
  $parts = parse_url($input);
  if ($parts === false || empty($parts['host'])) {
    throw new RuntimeException(
      "\"{$input}\" is not an address this can send to. The worker's own address is enough — " .
      '192.168.1.20:8080, say, or the name it answers to on your network.');
  }
  // Hostname or bare IPv4. Deliberately not a full IPv6 grammar: a literal in brackets survives
  // parse_url and would only be rejected here for being unusual, which it is not.
  $host = trim($parts['host'], '[]');
  if (!preg_match('/^[a-z0-9]([a-z0-9.\-:]*[a-z0-9])?$/i', $host)) {
    throw new RuntimeException("\"{$parts['host']}\" is not a host name or address.");
  }
  return rtrim($input, '/');
}

// The exact URL an envelope is posted to.
//
// The setting holds a base address because that is all anybody knows off the top of their head, and
// hook.php is where a Beeblebrox worker listens. An address that already names a file is taken as
// meant — a receiver behind a rewrite, or one somebody has renamed — because guessing over the top
// of an explicit path would make that installation unconfigurable.
function worker_hook_url() {
  $base = worker_base();
  if ($base === '') {
    return '';
  }
  $path = (string)parse_url($base, PHP_URL_PATH);
  return substr($path, -4) === '.php' ? $base : $base . '/hook.php';
}

// Whether enough is configured to do anything at all, and what is missing if not. Used by the
// dashboard, the menu and tools/selftest.php, so all three say the same words.
//
// A missing signing secret is deliberately not in here. This forwards unverified by default and the
// worker does the checking, so listing it would be reporting the normal setup as incomplete.
function settings_gaps() {
  $gaps = [];
  if (instance_base() === '') {
    $gaps[] = 'No instance. Nothing knows which Beeblebrox this relays for, so every envelope is ' .
      'refused as coming from the wrong one.';
  }
  if (worker_base() === '') {
    $gaps[] = 'No worker address. There is nowhere to send anything that arrives.';
  }
  if (!setting_bool('accept_webhooks')) {
    $gaps[] = 'Forwarding is switched off, so nothing is passed on.';
  }
  if (setting('admin_password_hash') === '') {
    $gaps[] = 'No password is set for these pages.';
  }
  return $gaps;
}
