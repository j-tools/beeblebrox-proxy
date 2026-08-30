<?php
// The one thing this application does: hand an envelope to the worker exactly as it arrived, and
// bring back exactly what the worker said.
//
// "Exactly" is not a figure of speech. The signature covers the raw bytes of the body together with
// the timestamp header, so anything that decodes and re-encodes the JSON on the way through — a
// re-ordered key, a re-escaped slash, a normalized number — makes a valid envelope arrive invalid,
// and the symptom at the far end is "the signature does not match", which reads like a wrong secret
// and is not. The body is therefore never parsed on the path it travels; it is parsed separately,
// only to fill in the log.

require_once __DIR__ . '/settings.php';

// POSTs the body to the worker with the headers it arrived with.
//
// Redirects are deliberately not followed. curl drops the body and the headers on a 307 anyway, and
// a redirect from a receiver is a host asking to be handed somebody's envelope — there is no
// legitimate reason for hook.php to send one.
function deliver_post($url, $body, array $headers, $timeout_seconds) {
  $started = microtime(true);

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT        => max(1, (int)$timeout_seconds),
    // A worker that is switched off refuses the connection immediately; one that is asleep or gone
    // from the network does not answer at all. Capping the connect phase well under the total is
    // what keeps the second case from spending the whole budget before a single byte is sent.
    CURLOPT_CONNECTTIMEOUT => min(5, max(1, (int)$timeout_seconds)),
    CURLOPT_HTTPHEADER     => $headers,
  ]);

  $response = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $transport = curl_error($ch);
  curl_close($ch);

  return [
    'status'      => $status,
    'body'        => $response === false ? '' : (string)$response,
    'error'       => $transport,
    'duration_ms' => (int)round((microtime(true) - $started) * 1000),
  ];
}

// The headers this proxy adds on top of the ones it passes through.
//
// The incoming Content-Type is repeated rather than replaced with a canonical one, because the
// promise this makes is that the worker sees what the instance sent. It falls back to JSON only when
// there was none, since a request without one can be read as form data at the far end.
//
// X-Forwarded-For carries the instance's own address, which is otherwise lost — everything the
// worker sees comes from this machine, so its own allow list and its own log would show one address
// forever.
function deliver_headers(array $passthrough, $remote_addr, $content_type = '') {
  $content_type = trim((string)$content_type);
  if ($content_type === '' || strpbrk($content_type, "\r\n") !== false) {
    $content_type = 'application/json';
  }
  $headers = array_merge([
    'Content-Type: ' . $content_type,
    'Accept: application/json',
    'User-Agent: beeblebrox-proxy/1',
    // curl otherwise waits for a 100-continue on a body over 1KB, which a small PHP receiver never
    // sends, and every delivery then costs a second of nothing happening.
    'Expect:',
    'X-Beeblebrox-Proxy: ' . bbl_env_label(),
  ], $passthrough);
  if ($remote_addr !== '' && strpbrk($remote_addr, "\r\n") === false) {
    $headers[] = 'X-Forwarded-For: ' . $remote_addr;
  }
  return $headers;
}

// A plain GET, for the two things this asks rather than relays: is the worker there, and is the
// instance there. Redirects are not followed here either — the answer to "is the right thing at this
// address" must be about this address.
function deliver_get($url, $timeout_seconds = 8) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT        => max(1, (int)$timeout_seconds),
    CURLOPT_CONNECTTIMEOUT => min(5, max(1, (int)$timeout_seconds)),
    CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: beeblebrox-proxy/1'],
  ]);
  $body = (string)curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $transport = curl_error($ch);
  curl_close($ch);

  return ['status' => $status, 'body' => $body, 'error' => $transport];
}

// Whether the instance somebody just typed is really there. Unauthenticated on the platform, and
// asked that way here — a proxy has no API key and needs none, since it never reads or reports
// anything. This is the only call it ever makes upstream, and only from a page.
function deliver_instance_health($base) {
  $base = rtrim((string)$base, '/');
  if ($base === '') {
    return ['ok' => false, 'error' => 'No instance is configured.'];
  }
  $result = deliver_get($base . '/api/health');
  if ($result['error'] !== '') {
    return ['ok' => false, 'error' => $result['error']];
  }
  if ($result['status'] >= 200 && $result['status'] < 300) {
    return ['ok' => true, 'error' => ''];
  }
  return ['ok' => false, 'error' => "It answered HTTP {$result['status']}."];
}

// Reachability of the worker, asked the way that proves something. A GET on hook.php is answered by
// a Beeblebrox receiver with 405 and a sentence about needing a POST from a dispatcher — so a 405 is
// not a problem here, it is the receiver identifying itself. Anything else is a web server that is
// not the one we are looking for, which is exactly the mistake a typo in a port makes.
function deliver_probe($timeout_seconds = 8) {
  $url = worker_hook_url();
  if ($url === '') {
    return ['ok' => false, 'status' => 0, 'url' => '', 'error' => 'No worker address is configured.'];
  }
  $result = deliver_get($url, $timeout_seconds);
  if ($result['error'] !== '') {
    return ['ok' => false, 'status' => 0, 'url' => $url, 'error' => $result['error']];
  }
  if ($result['status'] === 405) {
    return ['ok' => true, 'status' => 405, 'url' => $url, 'error' => ''];
  }
  return ['ok' => false, 'status' => $result['status'], 'url' => $url,
          'error' => "answered HTTP {$result['status']} rather than the 405 a Beeblebrox receiver " .
                     'answers a GET with. ' . ($result['status'] === 404
                       ? 'Nothing is listening at that path — check the port, and whether the ' .
                         'worker is served from a subdirectory.'
                       : 'Something is there, but it does not look like hook.php.')];
}
