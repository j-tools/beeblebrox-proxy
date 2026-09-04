<?php
// What this needs from PHP, checked before anything tries to use it.
//
// Written in PHP 5 syntax on purpose, and required first thing by config.php, which every entry
// point loads. That ordering is the whole point: nothing in this application uses syntax newer than
// PHP 5.6, so an old PHP still *parses* every file and only dies later, at the moment something
// calls a function it does not have. On a production server, where display_errors is off, that is a
// blank 500 on the first page somebody opens, with the reason only in a log they may not have.
//
// So the requirements are stated here, in words, before they can be discovered as a crash.

function bbl_preflight_problems() {
  $problems = array();

  // 8.0 for str_starts_with and str_contains, which are called on the very first page view. 8.1 is
  // what the documentation asks for and what this is tested on; 8.0 is where it stops being a
  // fatal, so that is the honest line to draw for refusing to start.
  if (version_compare(PHP_VERSION, '8.0', '<')) {
    $problems[] = 'This needs PHP 8.0 or newer, and 8.1 is what it is tested on. This server is '
      . 'running PHP ' . PHP_VERSION . '. Most hosting panels let you pick the version per site, '
      . 'and that is usually all this takes.';
  }

  $needed = array(
    'pdo_sqlite' => 'the whole store is one SQLite file, so without this there is nowhere to keep '
      . 'settings or the delivery log',
    'curl'       => 'envelopes are handed to the worker with curl',
    'mbstring'   => 'used to trim what goes in the delivery log without cutting a character in half',
    'openssl'    => 'only used if you store a signing secret, but it is checked here so it is not '
      . 'discovered on the day you do',
  );
  foreach ($needed as $extension => $why) {
    if (!extension_loaded($extension)) {
      $problems[] = 'The ' . $extension . ' extension is not loaded — ' . $why . '.';
    }
  }

  return $problems;
}

// Says what is wrong and stops, rather than letting the first call to a missing function decide how
// this fails. Plain text, because it has to be equally readable in a browser and in the delivery log
// of a dispatcher that posted to a machine in this state.
function bbl_preflight() {
  $problems = bbl_preflight_problems();
  if (!$problems) {
    return;
  }
  if (!headers_sent()) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
  }
  echo "Beeblebrox Proxy cannot start on this server.\n";
  echo str_repeat('-', 60) . "\n\n";
  foreach ($problems as $problem) {
    echo '* ' . wordwrap($problem, 76, "\n  ") . "\n\n";
  }
  echo "Nothing has been installed or changed. Fix the above and reload this page.\n";
  exit;
}

bbl_preflight();
