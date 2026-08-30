<?php
// Library-level checks. No server, no instance, no worker — just the pieces where being wrong is
// silent, which is why these are the ones with tests.
//
//   timeout 60 php tests/smoke.php
//
// Nothing here touches the database. A proxy's parsing is where the mistakes live: an address
// normalized one way on the settings page and another way in the wizard, or a header name that goes
// out subtly different from the one the signature was computed over.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/deliveries.php';

$failures = 0;

function is_same($expected, $actual, $what) {
  global $failures;
  if ($expected === $actual) {
    echo "  ok    {$what}\n";
    return;
  }
  $failures++;
  echo "  FAIL  {$what}\n";
  echo '        expected: ' . var_export($expected, true) . "\n";
  echo '        actual:   ' . var_export($actual, true) . "\n";
}

function is_true($actual, $what) {
  is_same(true, (bool)$actual, $what);
}

function throws(callable $fn, $what) {
  try {
    $fn();
  } catch (Throwable $e) {
    is_same(true, true, $what);
    return;
  }
  is_same('an exception', 'no exception', $what);
}

// lib/db.php is deliberately not loaded, and this stands in for the one query settings.php makes.
// The alternative — a test database — would be a lot of setup to prove something about parsing text,
// and copying the parsers into the test would only prove things about the copy.
$GLOBALS['stub_settings'] = [];
function db_all($sql, $params = []) {
  return $GLOBALS['stub_settings'];
}
function stub_setting($name, $value) {
  $GLOBALS['stub_settings'] = [['name' => $name, 'value' => $value]];
  settings_raw(true);
}
require_once __DIR__ . '/../lib/settings.php';

echo "instance_normalize — the name of an instance is enough\n";
is_same('https://zaphod.beeblebrox.cloud', instance_normalize('zaphod'), 'a bare name becomes a URL');
is_same('https://zaphod.beeblebrox.cloud', instance_normalize('  ZAPHOD '), 'trimmed and lowercased');
is_same('https://bb.example.com', instance_normalize('bb.example.com'), 'a hostname gets https');
is_same('http://bb.example.com', instance_normalize('http://bb.example.com'), 'plain http survives');
is_same('https://bb.example.com', instance_normalize('https://bb.example.com/'),
  'a trailing slash is dropped, because every comparison is against a base');
is_same('', instance_normalize('   '), 'nothing typed is nothing stored');
throws(function () { instance_normalize('not a host'); }, 'something unusable is refused, not guessed at');

echo "\nworker_normalize — http, because a worker is on your own network\n";
is_same('http://192.168.1.20:8080', worker_normalize('192.168.1.20:8080'),
  'an address and a port need no scheme');
is_same('http://laptop.local', worker_normalize('laptop.local'), 'so does a name');
is_same('https://worker.example.com', worker_normalize('https://worker.example.com'),
  'https is kept when it is asked for');
is_same('http://192.168.1.20:8080', worker_normalize('http://192.168.1.20:8080/'),
  'a trailing slash is dropped');
is_same('', worker_normalize(''), 'nothing typed is nothing stored');
throws(function () { worker_normalize('http://'); }, 'a scheme with no host is refused');

echo "\nworker_hook_url — the base address is all anybody remembers\n";
stub_setting('worker_url', 'http://192.168.1.20:8080');
is_same('http://192.168.1.20:8080/hook.php', worker_hook_url(), 'hook.php is added to a base');
stub_setting('worker_url', 'http://192.168.1.20:8080/beeblebrox-local');
is_same('http://192.168.1.20:8080/beeblebrox-local/hook.php', worker_hook_url(),
  'and to a subdirectory');
stub_setting('worker_url', 'http://192.168.1.20/receive.php');
is_same('http://192.168.1.20/receive.php', worker_hook_url(),
  'an address that already names a file is taken as meant');
stub_setting('worker_url', '');
is_same('', worker_hook_url(), 'no worker is no URL, not "/hook.php"');

echo "\nsignature_check — the same verdict this and the worker must reach\n";
$secret = 'a-shared-secret';
$body = '{"event":"task.dispatched"}';
$now = (string)time();
$good = signature_expected($body, $secret, $now);

is_same(null, signature_check($body, $good, $now, $secret, 300), 'a correct signature passes');
is_true(signature_check($body, $good, $now, 'wrong-secret', 300), 'a different secret is refused');
is_true(signature_check($body . ' ', $good, $now, $secret, 300),
  'a changed body is refused — which is why nothing re-encodes it on the way through');
is_true(signature_check($body, $good, (string)(time() - 4000), $secret, 300),
  'an old timestamp is refused, so a captured envelope cannot be replayed');
is_true(signature_check($body, $good, $now, '', 300), 'no secret is not a pass');
is_true(signature_check($body, '', $now, $secret, 300), 'no signature header is not a pass');

echo "\npassthrough_headers — what the worker has to see exactly as we did\n";
$server = [
  'HTTP_X_BEEBLEBROX_SIGNATURE' => 'sha256=abc',
  'HTTP_X_BEEBLEBROX_TIMESTAMP' => '1724930000',
  'HTTP_HOST'                   => 'proxy.example.com',
  'HTTP_CONTENT_LENGTH'         => '99',
  'REMOTE_ADDR'                 => '203.0.113.7',
];
$headers = passthrough_headers($server);
is_true(in_array('X-Beeblebrox-Signature: sha256=abc', $headers, true),
  'the signature header goes out spelled the way it came in');
is_true(in_array('X-Beeblebrox-Timestamp: 1724930000', $headers, true), 'so does the timestamp');
is_same(2, count($headers), 'and nothing else — Host and Content-Length describe this hop, not the next');
is_same([], passthrough_headers(['HTTP_X_BEEBLEBROX_EVIL' => "a\r\nX-Injected: yes"]),
  'a value carrying a newline is dropped rather than repaired');

echo "\ndelivery_outcome — three ways to fail, and they send you to three different places\n";
$base = ['forwarded' => 1, 'response_status' => 202];
is_same('ok', delivery_outcome($base)['state'], 'the worker took it');
is_same('rejected', delivery_outcome(['forwarded' => 1, 'response_status' => 401])['state'],
  'the worker refused it — a setting on the worker');
is_same('failed', delivery_outcome(['forwarded' => 1, 'response_status' => null])['state'],
  'it never arrived — the worker or the network');
is_same('refused', delivery_outcome(['forwarded' => 0, 'response_status' => null])['state'],
  'we refused it — a setting here');

echo "\nip_allowed\n";
is_true(ip_allowed('203.0.113.7', ''), 'an empty list means any address');
is_true(ip_allowed('203.0.113.7', '198.51.100.1, 203.0.113.7'), 'a listed address passes');
is_same(false, ip_allowed('203.0.113.8', '203.0.113.7'), 'an unlisted one does not');

echo "\n" . ($failures === 0 ? "All good.\n" : "{$failures} failure(s).\n");
exit($failures === 0 ? 0 : 1);
