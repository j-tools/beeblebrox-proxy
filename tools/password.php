<?php
// Forgetting the password for these pages, from a terminal.
//
//   timeout 60 php tools/password.php --forget
//
// The way back in when nobody remembers it. There is no mysql client to reach for any more — the
// store is a SQLite file — so this is the escape hatch, and it is deliberately the only thing this
// does: clearing the hash puts the site back into its first-run state, where the next visit sets a
// new password in a browser. Nothing else is touched, so the instance, the worker and the whole
// delivery log survive it.
//
// A --set that took a password would put it in shell history, which is a worse place for it than
// this machine's screen.

require_once __DIR__ . '/cli.php';
tools_require_cli();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/settings.php';

if (!in_array('--forget', $argv, true)) {
  fwrite(STDERR, "Usage: php tools/password.php --forget\n\n" .
    "Clears the password for these pages. The next visit to the site asks for a new one, the way a\n" .
    "fresh install does. Nothing else is changed.\n");
  exit(1);
}

if ((string)setting('admin_password_hash') === '') {
  echo "There is no password set. The site is already asking for one.\n";
  exit(0);
}

setting_set('admin_password_hash', '');

// Every existing session goes with it. Leaving them would mean whoever is signed in stays signed in
// through the reset, which is the wrong answer when the reason for the reset is not knowing who
// that is.
db_exec('DELETE FROM sessions');

echo "Forgotten, and everybody signed out. Open the site and set a new one.\n";
