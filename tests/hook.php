<?php
// End-to-end checks against a running proxy: real HTTP, real envelopes, real rows in the delivery
// log afterwards.
//
//   timeout 180 php tests/hook.php http://127.0.0.1:8776
//
// Run against the copy you are working on, never a production one — every request here lands in its
// delivery log, and the last of them reaches the worker.
//
// The envelopes are all connection tests: event "test", task id 0, which no real task ever is. A
// Beeblebrox worker answers one and queues nothing, so the worst this can do at the far end is add a
// line to a log there too.
//
// It reads the proxy's own configuration out of the database to know which instance to name and,
// if one is stored, which secret to sign with. It writes nothing.

require_once __DIR__ . '/../tools/cli.php';
tools_require_cli();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/security.php';

$base = rtrim((string)($argv[1] ?? ''), '/');
if ($base === '') {
  fwrite(STDERR, "Usage: php tests/hook.php <base-url-of-the-proxy>\n");
  exit(1);
}
$url = $base . '/hook.php';

$failures = 0;

function result($ok, $what, $detail = '') {
  global $failures;
  if (!$ok) {
    $failures++;
  }
  printf("  %s  %s\n", $ok ? 'ok  ' : 'FAIL', $what);
  if ($detail !== '') {
    echo '        ' . $detail . "\n";
  }
}

// One request, with whatever headers are asked for and no others — a test that quietly sent a
// well-formed signature everywhere would never find out what happens without one.
function post($url, $body, array $headers) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json', 'Expect:'], $headers),
  ]);
  $response = (string)curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error = curl_error($ch);
  curl_close($ch);
  return ['status' => $status, 'body' => $response, 'error' => $error];
}

function signed_headers($body, $secret) {
  $ts = (string)time();
  return ['X-Beeblebrox-Timestamp: ' . $ts, 'X-Beeblebrox-Signature: ' .
    signature_expected($body, $secret, $ts)];
}

$instance = instance_base();
$has_secret = setting_secret_is_set('webhook_secret');
$secret = $has_secret ? setting_secret('webhook_secret') : 'not-the-real-secret';

echo "proxy:    {$url}\n";
echo "relays for: " . ($instance ?: '(nothing configured)') . "\n";
echo "worker:   " . (worker_hook_url() ?: '(nothing configured)') . "\n";
echo 'checks signatures here: ' . ($has_secret ? 'yes' : 'no, the worker does') . "\n\n";

if ($instance === '') {
  fwrite(STDERR, "No instance is configured, so every envelope would be refused for that reason " .
    "alone and nothing below would mean anything. Finish setup first.\n");
  exit(1);
}

// --- what should never get past this machine -----------------------------------------------------

echo "Refusals\n";

// The same answer a Beeblebrox worker gives, and the one this proxy's own worker check looks for at
// the other end of the chain. Worth asserting here so the two halves cannot drift apart.
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false]);
curl_exec($ch);
$get_status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
result($get_status === 405, 'a GET is answered with 405, the way a receiver identifies itself',
  "HTTP {$get_status}");

$r = post($url, '', []);
result($r['status'] === 400 || $r['status'] === 401, 'an empty body is refused',
  "HTTP {$r['status']}" . ($has_secret ? ' (401 — the signature is checked before the body)' : ''));

$r = post($url, 'not json at all', $has_secret ? signed_headers('not json at all', $secret) : []);
result($r['status'] === 400, 'a body that is not JSON is refused', "HTTP {$r['status']}");

$wrong = json_encode(['event' => 'test', 'instance' => 'https://somebody-else.beeblebrox.cloud',
                      'task' => ['id' => 0]]);
$r = post($url, $wrong, $has_secret ? signed_headers($wrong, $secret) : []);
result($r['status'] === 409, 'an envelope naming a different instance is refused', "HTTP {$r['status']}");

$none = json_encode(['event' => 'test', 'task' => ['id' => 0]]);
$r = post($url, $none, $has_secret ? signed_headers($none, $secret) : []);
result($r['status'] === 409, 'an envelope naming no instance at all is refused', "HTTP {$r['status']}");

$good_body = json_encode(['event' => 'test', 'instance' => $instance, 'task' => ['id' => 0]]);
if ($has_secret) {
  $r = post($url, $good_body, signed_headers($good_body, 'the-wrong-secret'));
  result($r['status'] === 401, 'a signature made with the wrong secret is refused',
    "HTTP {$r['status']}");

  $stale = (string)(time() - 4000);
  $r = post($url, $good_body, ['X-Beeblebrox-Timestamp: ' . $stale,
    'X-Beeblebrox-Signature: ' . signature_expected($good_body, $secret, $stale)]);
  result($r['status'] === 401, 'a correctly signed envelope from an hour ago is refused',
    "HTTP {$r['status']}");
} else {
  echo "  --    signature checks skipped: no secret is stored here, so the worker does them\n";
}

// --- and what should ----------------------------------------------------------------------------

echo "\nThe whole chain\n";

$r = post($url, $good_body, signed_headers($good_body, $secret));
if ($r['status'] === 502 || $r['status'] === 504) {
  result(false, 'the envelope reached the worker',
    "HTTP {$r['status']} — it never got there. " . mb_strimwidth($r['body'], 0, 200, '…'));
} elseif ($r['status'] === 0) {
  result(false, 'the proxy answered at all', $r['error']);
} else {
  result(true, 'the envelope reached the worker and it answered', "HTTP {$r['status']}: " .
    mb_strimwidth(trim($r['body']), 0, 300, '…'));
  if ($r['status'] >= 200 && $r['status'] < 300) {
    result(true, 'the worker accepted it', 'Which means the two ends hold the same signing secret.');
  } elseif ($r['status'] === 401 && !$has_secret) {
    // Expected, and not a failure: this test cannot know the real secret when the proxy does not
    // hold one, so the worker refusing the signature still proves the envelope arrived intact.
    echo "  --    the worker refused the signature, which is expected: this test signed with a\n" .
         "        placeholder because no secret is stored on the proxy. Reaching the worker at all\n" .
         "        is what was being tested.\n";
  } else {
    result(false, 'the worker accepted it',
      "It answered HTTP {$r['status']}. The chain works; the worker refused the envelope.");
  }
}

echo "\n" . ($failures === 0 ? "All good.\n" : "{$failures} failure(s).\n");
echo "Every request above is on the deliveries page.\n";
exit($failures === 0 ? 0 : 1);
