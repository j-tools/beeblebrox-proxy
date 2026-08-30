<?php
// The delivery log: writing a row for every envelope, and reading them back.
//
// A proxy has no other state. There are no jobs, no queue and nothing to resume — an envelope is
// either passed on or it is not, and it is over in a second either way. So this table is the entire
// account of what the thing has done, and the only thing anybody will have to work from when a
// worker is not getting its work.

// Started before anything is decided, so a row exists even if what follows throws. Returns the id to
// finish, or null if the log itself is unwritable — a proxy that cannot write its own log still has
// to forward, and failing a delivery over a lost line would be much the worse of the two.
function delivery_open(array $fields) {
  try {
    return (int)db_exec(
      'INSERT INTO deliveries (remote_addr, event, instance, task_id, chain_id, role_slug, body)
       VALUES (?, ?, ?, ?, ?, ?, ?)',
      [
        (string)($fields['remote_addr'] ?? ''),
        ($fields['event'] ?? null) === null ? null : mb_strimwidth((string)$fields['event'], 0, 32, ''),
        ($fields['instance'] ?? null) === null ? null : mb_strimwidth((string)$fields['instance'], 0, 190, ''),
        ($fields['task_id'] ?? null) === null ? null : (int)$fields['task_id'],
        ($fields['chain_id'] ?? null) === null ? null : (int)$fields['chain_id'],
        ($fields['role_slug'] ?? null) === null ? null : mb_strimwidth((string)$fields['role_slug'], 0, 64, ''),
        mb_strimwidth((string)($fields['body'] ?? ''), 0, 65000, '…'),
      ]
    );
  } catch (Throwable $e) {
    return null;
  }
}

// Why it went no further. The reason is the whole content of a refusal, so it is stored in full
// while the response columns stay empty — there was no response.
function delivery_refused($id, $reason) {
  if ($id === null) {
    return;
  }
  try {
    db_exec('UPDATE deliveries SET forwarded = 0, reason = ? WHERE id = ?',
      [mb_strimwidth((string)$reason, 0, 190, '…'), (int)$id]);
  } catch (Throwable $e) {
    // Same trade as delivery_open: the answer to the instance matters more than the line about it.
  }
}

function delivery_forwarded($id, $target_url, array $result) {
  if ($id === null) {
    return;
  }
  try {
    db_exec(
      'UPDATE deliveries
          SET forwarded = 1, target_url = ?, response_status = ?, response_body = ?,
              transport_error = ?, duration_ms = ?
        WHERE id = ?',
      [
        mb_strimwidth((string)$target_url, 0, 500, ''),
        (int)$result['status'] === 0 ? null : (int)$result['status'],
        mb_strimwidth((string)$result['body'], 0, 65000, '…'),
        $result['error'] === '' ? null : mb_strimwidth((string)$result['error'], 0, 255, '…'),
        (int)$result['duration_ms'],
        (int)$id,
      ]
    );
  } catch (Throwable $e) {
  }
}

// What became of one envelope, in one word, from the three columns that decide it between them.
//
// Four outcomes rather than a pass and a fail, because the three ways this goes wrong are three
// different jobs: our own refusal is a setting on this page, a transport error is the worker or the
// network, and a refusal by the worker is a setting on the worker. Collapsing them would make the
// list say "failed" to all three and send whoever reads it looking in the wrong place first.
function delivery_outcome(array $row) {
  if (!(int)$row['forwarded']) {
    return ['state' => 'refused', 'label' => 'refused here'];
  }
  if ($row['response_status'] === null) {
    return ['state' => 'failed', 'label' => 'never arrived'];
  }
  $status = (int)$row['response_status'];
  if ($status >= 200 && $status < 300) {
    return ['state' => 'ok', 'label' => 'accepted'];
  }
  return ['state' => 'rejected', 'label' => 'worker said ' . $status];
}

function deliveries_recent($limit = 20) {
  return db_all('SELECT * FROM deliveries ORDER BY id DESC LIMIT ' . max(1, (int)$limit));
}

function delivery_find($id) {
  return db_one('SELECT * FROM deliveries WHERE id = ?', [(int)$id]);
}

// The last day, split the way the bar shows it. A count over all time would stop moving after the
// first week and stop meaning anything.
function delivery_counts_today() {
  $row = db_one(
    "SELECT
       SUM(forwarded = 1 AND response_status >= 200 AND response_status < 300) AS ok,
       SUM(forwarded = 0 OR response_status IS NULL OR response_status < 200
           OR response_status >= 300) AS bad,
       COUNT(*) AS total
     FROM deliveries
     WHERE created_at > NOW() - INTERVAL 1 DAY");
  return [
    'ok'    => (int)($row['ok'] ?? 0),
    'bad'   => (int)($row['bad'] ?? 0),
    'total' => (int)($row['total'] ?? 0),
  ];
}

// When something last came through at all. The one number that separates "nothing is arriving" from
// "everything that arrives is being refused", which look identical on a quiet page.
function delivery_last_at() {
  $row = db_one('SELECT created_at FROM deliveries ORDER BY id DESC LIMIT 1');
  return $row ? $row['created_at'] : null;
}
