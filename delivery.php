<?php
// One envelope in full: what arrived, what happened to it, and what came back.
//
// The body is shown as it was received rather than pretty-printed. A signature is over exactly those
// bytes, so when the question is "why does the signature not match", a reformatted copy is not
// evidence — it is a different document.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/deliveries.php';
require_once __DIR__ . '/lib/view.php';

bbl_session_start();
bbl_require_signin();

$row = delivery_find($_GET['id'] ?? 0);
if (!$row) {
  http_response_code(404);
  view_header('Not found', true);
  echo '<p class="muted">No delivery with that id.</p>';
  view_footer();
  exit;
}

$outcome = delivery_outcome($row);

view_header('Delivery ' . (int)$row['id'], true);
?>
<h2><?= $row['task_id'] ? 'Task #' . (int)$row['task_id'] : 'Envelope' ?>
  <span class="badge delivery-<?= h($outcome['state']) ?>"><?= h($outcome['label']) ?></span></h2>

<div class="card">
  <div class="facts">
    <div><span class="k">Arrived</span><span class="v"><?= h(view_ago($row['created_at'])) ?></span></div>
    <div><span class="k">From</span><span class="v"><?= h($row['remote_addr'] ?: '—') ?></span></div>
    <div><span class="k">Names instance</span><span class="v"><?= h($row['instance'] ?: '—') ?></span></div>
    <div><span class="k">Role</span><span class="v"><?= h($row['role_slug'] ?: '—') ?></span></div>
    <div><span class="k">Ticket</span><span class="v"><?= $row['chain_id']
      ? '#' . (int)$row['chain_id'] : '—' ?></span></div>
    <div><span class="k">Event</span><span class="v"><?= h($row['event'] ?: '—') ?></span></div>
    <div><span class="k">Sent to</span><span class="v"><?= h($row['target_url'] ?: 'nowhere') ?></span></div>
    <div><span class="k">Took</span><span class="v"><?= h(view_duration($row['duration_ms'])
      ?: '—') ?></span></div>
  </div>
</div>

<?php if (!(int)$row['forwarded']): ?>
  <h2>Why it went no further</h2>
  <div class="card">
    <p style="margin:0"><?= h($row['reason'] ?: 'No reason was recorded, which should not happen.') ?></p>
  </div>
<?php elseif ($row['transport_error']): ?>
  <h2>It never arrived</h2>
  <div class="card">
    <p style="margin:0"><?= h($row['transport_error']) ?></p>
    <p class="small muted" style="margin:.6rem 0 0">The envelope left this machine and nothing
       answered. That is the worker or the network between here and it, not anything on this
       page.</p>
  </div>
<?php endif; ?>

<?php if ($row['response_status'] !== null): ?>
  <h2>What the worker said</h2>
  <div class="card">
    <p class="small muted" style="margin-top:0">HTTP <?= (int)$row['response_status'] ?>, passed back
       to the instance exactly as it came. The dispatcher decides whether to retry from this.</p>
    <div class="output"><?= h($row['response_body'] ?: '(empty)') ?></div>
  </div>
<?php endif; ?>

<h2>The envelope</h2>
<div class="card">
  <p class="small muted" style="margin-top:0">As received, unformatted — the signature covers these
     exact bytes. It names a task and nothing more; the briefing never passes through here.</p>
  <div class="output"><?= h($row['body'] ?: '(empty)') ?></div>
</div>

<div class="actions">
  <a href="deliveries.php" class="secondary">All deliveries</a>
</div>

<?php
view_footer();
