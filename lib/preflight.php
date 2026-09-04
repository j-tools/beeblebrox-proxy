<?php
// What this needs from PHP: checked before anything tries to use it, and filled in where filling it
// in is honest.
//
// Written in PHP 5 syntax on purpose, and required first thing by config.php, which every entry
// point loads. That ordering is the whole point: nothing in this application uses syntax newer than
// PHP 5.6, so an old PHP still *parses* every file and only dies later, at the moment something
// calls a function it does not have. On a production server, where display_errors is off, that is a
// blank 500 on the first page somebody opens, with the reason only in a log they may not have.
//
// The floor is deliberately low. This installs on whichever box somebody already has facing the
// internet, and those are frequently not new — a Debian 10 or 11 web server is PHP 7.3 or 7.4, and
// there is nothing in a webhook relay that needs anything younger.

// --- what 8.0 added that this actually uses, which is two string functions -------------------------
//
// Polyfilled rather than made a requirement. They are the only reason this would not run on PHP 7,
// they are four lines, and the alternative was turning away every server older than 2020 over
// str_starts_with.

if (!function_exists('str_starts_with')) {
  function str_starts_with($haystack, $needle) {
    return strncmp($haystack, $needle, strlen($needle)) === 0;
  }
}

if (!function_exists('str_contains')) {
  function str_contains($haystack, $needle) {
    return $needle === '' || strpos($haystack, $needle) !== false;
  }
}

function bbl_preflight_problems() {
  $problems = array();

  // 7.3 is where setcookie() and session_set_cookie_params() take an options array, which is how the
  // SameSite attribute is set. Going lower would mean smuggling SameSite through the path argument
  // as a string hack, and a cookie attribute that keeps this application's session out of other
  // sites' requests is not something to drop in order to support a PHP that has been end-of-life
  // since 2019.
  if (version_compare(PHP_VERSION, '7.3', '<')) {
    $problems[] = 'This needs PHP 7.3 or newer. This server is running PHP ' . PHP_VERSION . '. '
      . 'Most hosting panels let you pick the version per site, and that is usually all this takes.';
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
