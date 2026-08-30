<?php
// Copy to config.local.php and fill in. config.local.php is gitignored and must never be committed.
//
// There is exactly one value here that has to be right, and it is the first one. Everything
// operational — which Beeblebrox, which worker, the optional locks — is set on a page, because
// somebody standing this up should not have to open a PHP file to do it.
//
// Keys map to environment variables one-for-one — site_url is SITE_URL, db_file is DB_FILE — and an
// environment variable always wins over the value here. That is only useful if you run this in a
// container; on an ordinary web server this file is the whole configuration.
//
// One installation per worker. If two machines behind the same router each need work pushed to them,
// each gets its own proxy on its own address, because an envelope names a task and not a machine —
// there is nothing in it for a proxy to route on.

return [
  // The address your Beeblebrox instance will call, written exactly as it will call it, scheme and
  // subdirectory and all. This is not decoration: it is the URL printed on every page here as the
  // one to give the dispatcher, and it decides the session cookie's Secure flag and path — never
  // the request, because a forged Host header must not be able to turn that flag off on the one
  // machine here that is deliberately reachable.
  'site_url'    => 'https://your-server.example.com',
  // ...or, sharing a server with other sites:
  // 'site_url' => 'https://your-server.example.com/beeblebrox-proxy',

  // The whole store: one SQLite file, created on first use. There is nothing to install and nothing
  // to grant. Left unset it is data/proxy.sqlite inside this directory, which ships with an
  // .htaccess denying it — if you are not on Apache, either deny that directory yourself or point
  // this somewhere the web server does not serve. It holds session ids, so serving it would be
  // handing somebody a signed-in session.
  // 'db_file'  => '/var/lib/beeblebrox-proxy/proxy.sqlite',

  // Only needed if you want this proxy to check signatures itself, which is optional — the worker
  // checks them anyway. Leave it empty and you can skip generating one.
  // Generate with:  php -r "echo bin2hex(random_bytes(32));"
  'secret_key'  => '',
];
