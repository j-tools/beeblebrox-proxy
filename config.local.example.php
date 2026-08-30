<?php
// Copy to config.local.php and fill in. config.local.php is gitignored and must never be committed.
//
// Keys map to environment variables one-for-one — db_host is DB_HOST, secret_key is SECRET_KEY — and
// an environment variable always wins over the value here. That is only useful if you run this in a
// container; on a plain PHP host this file is the whole configuration.
//
// One installation per worker. If two machines behind the same router each need work pushed to them,
// each gets its own proxy on its own address, because an envelope names a task and not a machine —
// there is nothing in it for a proxy to route on.

return [
  'db_host'     => '127.0.0.1',
  'db_port'     => 3306,

  'db_name'     => 'beeblebrox_proxy',
  'db_user'     => 'id_beeblebrox_proxy',
  'db_password' => '',

  // The address your Beeblebrox instance can reach this on. Whatever the port forward, the tunnel or
  // the DNS record points at, written exactly as the instance will call it, because the session
  // cookie's Secure flag is decided from the scheme here and nowhere else.
  'site_url'    => 'https://proxy.example.com',

  // Only needed if you want this proxy to check signatures itself, which is optional — the worker
  // checks them anyway. Leave it empty and you can skip generating one.
  // Generate with:  php -r "echo bin2hex(random_bytes(32));"
  'secret_key'  => '',
];
