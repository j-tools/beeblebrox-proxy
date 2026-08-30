<?php
// Deciding whether an envelope may be passed on.
//
// A proxy sits on a public address and points at a machine that does not have one, which is the
// whole reason it exists — and also the reason it is worth being careful here. Whatever reaches
// this is one hop from a worker that will eventually start a program on somebody's own machine.
//
// The signature check below is the same code as the worker's, byte for byte in what it computes, so
// that turning it on here can never produce a different verdict from the one the worker will reach.

// The signature the platform produces, reproduced here. Over "timestamp.body" rather than the body
// alone, so a captured envelope cannot be replayed tomorrow with its signature still valid.
function signature_expected($body, $secret, $timestamp) {
  return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
}

// Returns null when the envelope is acceptable, or a sentence saying why it is not. A sentence
// rather than a code because it goes straight into the delivery log, which is the only place
// anybody will look when deliveries are failing.
//
// The reason is deliberately specific in the log and deliberately vague in the HTTP response:
// telling a caller which half of the check it failed is telling it how to pass.
function signature_check($body, $signature, $timestamp, $secret, $tolerance_seconds) {
  if ($secret === '') {
    return 'no signing secret is configured on this proxy';
  }
  if ($signature === '') {
    return 'no X-Beeblebrox-Signature header';
  }
  if ($timestamp === '' || !ctype_digit((string)$timestamp)) {
    return 'no usable X-Beeblebrox-Timestamp header';
  }

  $drift = abs(time() - (int)$timestamp);
  if ($drift > $tolerance_seconds) {
    return "the timestamp is {$drift}s away from this machine's clock, past the {$tolerance_seconds}s " .
           'tolerance — either a replay, or the two clocks disagree';
  }

  // hash_equals, so a wrong signature cannot be discovered a character at a time.
  if (!hash_equals(signature_expected($body, $secret, (string)$timestamp), $signature)) {
    return 'the signature does not match — this proxy and the dispatcher hold different secrets';
  }
  return null;
}

// An empty list means any address. That is the right default rather than a lax one: an instance
// behind a load balancer or a CDN does not call from one address, and pinning the wrong one is a
// silence that looks exactly like a broken port forward.
function ip_allowed($remote, $list) {
  $list = trim((string)$list);
  if ($list === '') {
    return true;
  }
  foreach (preg_split('/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY) as $allowed) {
    if ($allowed === $remote) {
      return true;
    }
  }
  return false;
}

// The headers an envelope arrived with that belong to Beeblebrox rather than to HTTP, returned as
// "Name: value" lines ready to hand to curl.
//
// Everything X-Beeblebrox-* is passed through without this having to know what any of it means.
// That is the point: the signature covers the body, so the worker has to see the same headers this
// did, and a header the platform adds next year has to survive a proxy written today. Nothing else
// is forwarded — Host, Content-Length and the rest describe this hop and would be wrong on the next
// one.
function passthrough_headers(array $server) {
  $out = [];
  foreach ($server as $key => $value) {
    if (strncmp($key, 'HTTP_X_BEEBLEBROX_', 18) !== 0) {
      continue;
    }
    // HTTP_X_BEEBLEBROX_SIGNATURE came in as X-Beeblebrox-Signature and has to go out the same way.
    // Header names are case-insensitive, but a signature header that reads X-BEEBLEBROX-SIGNATURE in
    // a packet capture costs somebody an hour deciding whether that is the problem.
    $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5))), ' '));
    // A header value cannot contain a newline. One that does is somebody trying to write a second
    // header into the request this makes, so the whole line is dropped rather than repaired.
    if (is_string($value) && strpbrk($value, "\r\n") === false) {
      $out[] = $name . ': ' . $value;
    }
  }
  return $out;
}
