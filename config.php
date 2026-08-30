<?php
// Central config loader, the same shape as the platform's and the local worker's: environment
// variables first, falling back to config.local.php. Only these values differ between one
// installation and another — the code is identical, which is what makes this shareable at all.
//
// What lives here is only what has to exist before the database does: how to reach the database, the
// key that unwraps a stored secret, and where this proxy answers. Everything operational — which
// Beeblebrox this relays for, and which worker it relays to — lives in the database and is edited on
// the settings page, because somebody standing this up should not have to open a PHP file.

function bbl_config() {
  static $cfg = null;
  if ($cfg !== null) {
    return $cfg;
  }

  $local_file = __DIR__ . '/config.local.php';
  $local = file_exists($local_file) ? require $local_file : [];
  $env = function ($key, $default = null) {
    return getenv($key) !== false ? getenv($key) : $default;
  };

  $cfg = [
    'db_host'     => $env('DB_HOST',     $local['db_host']     ?? '127.0.0.1'),
    'db_port'     => (int)$env('DB_PORT', $local['db_port']    ?? 3306),
    'db_user'     => $env('DB_USER',     $local['db_user']     ?? ''),
    'db_password' => $env('DB_PASSWORD', $local['db_password'] ?? ''),
    'db_name'     => $env('DB_NAME',     $local['db_name']     ?? ''),

    // The address the instance posts to. Also decides the session cookie's Secure flag, so it is
    // never derived from the request — this box is the one on a public address, and a forged Host
    // header must not be able to turn the flag off.
    'site_url'    => $env('SITE_URL',    $local['site_url']    ?? 'http://proxy.beeblebrox.cloud'),

    // Wraps the optional signing secret in the database, so a database dump on its own is not a
    // credential breach. Unlike the local worker, this may be left empty: a proxy that does not
    // check signatures itself has no secret to store, and that is the ordinary case.
    'secret_key'  => $env('SECRET_KEY',  $local['secret_key']  ?? ''),

    'session_lifetime_days' => (int)$env('SESSION_LIFETIME_DAYS', $local['session_lifetime_days'] ?? 30),
  ];
  return $cfg;
}

// The public Beeblebrox site. Anything explaining Beeblebrox itself lives there, as opposed to an
// instance, which belongs to one company. Not configurable — it is the same site for every customer,
// and a setting nobody would ever change is a setting somebody can get wrong.
function bbl_public_site() {
  return 'https://beeblebrox.cloud';
}

// A human label for which proxy this is, used in the page title and in the answer this sends back to
// the instance, so a chain with two hops in it can be read from either end.
function bbl_env_label() {
  $host = parse_url(bbl_config()['site_url'], PHP_URL_HOST) ?: 'proxy';
  return $host;
}

// Where an envelope is posted to reach this proxy. Said in several places — diagnostics, settings,
// the landing page — and it must be the same string in all of them.
function bbl_hook_url() {
  return rtrim(bbl_config()['site_url'], '/') . '/hook.php';
}

function bbl_is_configured() {
  $cfg = bbl_config();
  return $cfg['db_name'] !== '' && $cfg['db_user'] !== '';
}
