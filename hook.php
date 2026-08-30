<?php
// The receiver. This is the URL a Beeblebrox dispatcher posts to, and the only reason this
// application exists.
//
// It decides whether an envelope is worth passing on, hands it to the worker unchanged, and gives
// the instance back whatever the worker said. That last part is the important one: the dispatcher
// upstream decides whether to retry from the status it gets, so anything invented here — a cheerful
// 202 while nothing arrived, a 500 for a refusal the worker meant — breaks a decision made two
// machines away. This answers with the worker's own words wherever there are any.
//
// Nothing is queued and nothing is retried. That is deliberate. The dispatcher already retries, and
// a proxy that accepted an envelope the worker never saw would be telling the instance the work had
// been handed over when it had not — which is worse than a 502, because a 502 gets retried and a
// lie does not.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/deliver.php';
require_once __DIR__ . '/lib/deliveries.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('X-Beeblebrox-Proxy: ' . bbl_env_label());

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$delivery_id = null;

// The reason is specific in the log and vague here on purpose. Telling a caller which half of the
// check it failed is telling it how to pass.
function refuse($status, $public, $reason) {
  global $delivery_id;
  delivery_refused($delivery_id, $reason);
  http_response_code($status);
  echo json_encode(['error' => $public, 'proxy' => bbl_env_label()]);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  // A Beeblebrox worker answers a GET exactly this way, and this copies it deliberately: it is what
  // makes one of these reachable-and-correct rather than merely reachable, and it is what the setup
  // wizard and the diagnostics page probe for at the other end of the chain.
  http_response_code(405);
  header('Allow: POST');
  echo json_encode(['error' => 'This endpoint takes a POST from a Beeblebrox dispatcher.',
                    'proxy' => bbl_env_label()]);
  exit;
}

$body = (string)file_get_contents('php://input');

// Everything below needs settings, and settings need the database. Asked once, here, so that a
// database that is down produces one sentence the instance can act on rather than a stack trace it
// cannot.
try {
  $accepting = setting_bool('accept_webhooks');
} catch (Throwable $e) {
  http_response_code(503);
  echo json_encode(['error' => 'This proxy cannot reach its own database. Worth retrying.',
                    'proxy' => bbl_env_label()]);
  exit;
}

// Read out of the envelope only to fill in the log. Nothing below routes on any of it — there is one
// worker — and the body that gets forwarded is the raw string above, never anything re-encoded from
// this, because the signature is over those exact bytes.
$envelope = json_decode($body, true);
$envelope = is_array($envelope) ? $envelope : null;
$task = is_array($envelope['task'] ?? null) ? $envelope['task'] : [];

$delivery_id = delivery_open([
  'remote_addr' => $remote,
  'event'       => $envelope['event'] ?? null,
  'instance'    => $envelope['instance'] ?? null,
  'task_id'     => isset($task['id']) ? (int)$task['id'] : null,
  'chain_id'    => isset($task['chain_id']) ? (int)$task['chain_id'] : null,
  'role_slug'   => $task['role'] ?? null,
  'body'        => $body,
]);

if (!$accepting) {
  refuse(503, 'This proxy is not forwarding at the moment.',
    'forwarding is switched off in settings');
}
if (!ip_allowed($remote, setting('allowed_ips'))) {
  refuse(403, 'Refused.', "the address {$remote} is not in the allow list");
}

// Optional, and off unless a secret has been stored. The worker checks the signature whatever
// happens here — it has to, since it is the one that acts on the envelope — so this is a second lock
// rather than the only one, and it is worth having when this box is on a public address: a forged
// envelope is then refused here instead of being carried onto the network it was aimed at.
if (setting_secret_is_set('webhook_secret')) {
  $problem = signature_check(
    $body,
    $_SERVER['HTTP_X_BEEBLEBROX_SIGNATURE'] ?? '',
    $_SERVER['HTTP_X_BEEBLEBROX_TIMESTAMP'] ?? '',
    setting_secret('webhook_secret'),
    setting_int('signature_tolerance', 300)
  );
  if ($problem !== null) {
    refuse(401, 'Refused.', $problem);
  }
}

if ($envelope === null) {
  refuse(400, 'The body is not JSON.', 'the body is not JSON, so it is not an envelope');
}

// The one check that is this proxy's own job rather than a copy of the worker's.
//
// A worker refuses an envelope naming the wrong instance too, but only after it has already been
// carried across the network this exists to keep closed. Doing it here is what makes an address that
// somebody finds useless to them: without the right instance named in the body, nothing gets past
// this machine at all.
$claimed = rtrim((string)($envelope['instance'] ?? ''), '/');
if ($claimed === '' || $claimed !== instance_base()) {
  refuse(409, 'Refused.',
    'the envelope names ' . ($claimed === '' ? 'no instance' : $claimed) . ', and this proxy relays ' .
    'for ' . (instance_base() ?: 'nobody yet'));
}

$target = worker_hook_url();
if ($target === '') {
  refuse(503, 'This proxy has nowhere to send anything yet.',
    'no worker address is configured');
}

$result = deliver_post(
  $target,
  $body,
  deliver_headers(passthrough_headers($_SERVER), $remote),
  setting_int('deliver_timeout', 15)
);
delivery_forwarded($delivery_id, $target, $result);

// Never reached the worker at all: the machine is off, asleep, or not where it used to be. A 504 for
// a timeout and a 502 for everything else, because those mean different things to whoever reads the
// dispatcher's log — one is a worker that is slow, the other is a worker that is not there.
if ($result['status'] === 0) {
  $timed_out = stripos($result['error'], 'timed out') !== false ||
               stripos($result['error'], 'timeout') !== false;
  http_response_code($timed_out ? 504 : 502);
  echo json_encode([
    'error' => 'The worker did not answer. Worth retrying.',
    'proxy' => bbl_env_label(),
    'detail' => $result['error'],
  ]);
  exit;
}

// Anything that is not a plain answer — a redirect, a 1xx — is not something to hand on as though it
// were the worker's verdict. A redirect in particular is a host asking to be given the envelope,
// which is never followed here.
if ($result['status'] < 200 || ($result['status'] >= 300 && $result['status'] < 400)) {
  http_response_code(502);
  echo json_encode([
    'error' => "The worker answered HTTP {$result['status']}, which is not an answer to a delivery.",
    'proxy' => bbl_env_label(),
  ]);
  exit;
}

http_response_code($result['status']);

// The worker's own body, passed through when it is JSON — which is what a Beeblebrox receiver
// always sends, and what the dispatcher expects to be able to read. When it is not, something other
// than the worker answered (a web server's error page, a captive portal), and wrapping it says so
// rather than handing the instance HTML it will only log as unparseable.
$json = json_decode($result['body'], true);
if (is_array($json)) {
  echo $result['body'];
} else {
  echo json_encode([
    'error' => 'The worker answered, but not with JSON. Something other than hook.php replied.',
    'proxy' => bbl_env_label(),
    'worker_said' => mb_strimwidth(trim($result['body']), 0, 300, '…'),
  ]);
}
