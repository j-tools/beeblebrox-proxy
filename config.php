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
    // One SQLite file, created on first use. Nothing to install, nothing to grant, and one file to
    // copy if this ever moves to another machine. It defaults inside the repository because there is
    // no portable directory outside it that is certain to be writable — which is why data/ ships
    // with an .htaccess denying it, and why moving this somewhere unservable is the first
    // suggestion in INSTALL.md for anybody not on Apache.
    'db_file'     => $env('DB_FILE',     $local['db_file']     ?? __DIR__ . '/data/proxy.sqlite'),

    // The address the instance posts to, exactly as it will be called — including a subdirectory, if
    // this is sharing a web server with other sites, which is the usual way it is installed.
    //
    // There is no useful default. This is the one machine here that is deliberately reachable from
    // outside, and no two installations agree on what it is called; a placeholder that happened to
    // work would only mean the wrong URL was printed everywhere as the one to give the dispatcher.
    // It also decides the session cookie's Secure flag and path, and is never derived from the
    // request — a forged Host header must not be able to turn that flag off.
    'site_url'    => $env('SITE_URL',    $local['site_url']    ?? 'http://localhost'),

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

// The path this is served under, with slashes at both ends: '/' at the root of its own vhost,
// '/beeblebrox-proxy/' in a subdirectory.
//
// This is a shared web server by definition — it is whichever box you already had that the internet
// can reach — so this application is very often not alone on its hostname. Cookies are scoped to
// this path so that signing in here neither sees nor disturbs whatever else lives on the same
// domain.
function bbl_cookie_path() {
  $path = (string)parse_url(bbl_config()['site_url'], PHP_URL_PATH);
  $path = '/' . trim($path, '/');
  return $path === '/' ? '/' : $path . '/';
}

// Where an envelope is posted to reach this proxy. Said in several places — diagnostics, settings,
// the landing page — and it must be the same string in all of them.
function bbl_hook_url() {
  return rtrim(bbl_config()['site_url'], '/') . '/hook.php';
}

function bbl_is_configured() {
  return bbl_config()['db_file'] !== '';
}
