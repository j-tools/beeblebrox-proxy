<?php
// Every question worth asking about whether this proxy is actually set up, and the last few
// envelopes that arrived.
//
// The same checks tools/selftest.php runs, from the same code. Worth running that from a terminal
// as well: this page reaches the worker as the web server's account and from the web server's
// network position, which on a box with more than one interface is not always the same answer.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view.php';
require_once __DIR__ . '/lib/checks.php';

bbl_session_start();
bbl_require_signin();

$checks = checks_run(true);
$marks = ['pass' => '✓', 'warn' => '!', 'fail' => '✕'];
$counts = array_count_values(array_column($checks, 'state'));
$arrivals = deliveries_recent(20);

view_header('Diagnostics', true);
?>
<h2>Checks</h2>
<div class="card">
<?php foreach ($checks as $check): ?>
  <div class="check <?= h($check['state']) ?>">
    <span class="mark"><?= $marks[$check['state']] ?></span>
    <span>
      <span class="what"><?= h($check['what']) ?></span>
<?php if ($check['detail'] !== ''): ?>
      <span class="detail"><?= h($check['detail']) ?></span>
<?php endif; ?>
<?php if (!empty($check['url'])): ?>
      <?php // A link somewhere else opens in a new tab; one of ours replaces the page, because you
            // came here from it and will come back. ?>
      <span class="detail"><a href="<?= h($check['url']) ?>"
<?php if (str_contains($check['url'], '//')): ?> target="_blank" rel="noopener"<?php endif; ?>
        ><?= h($check['url']) ?></a></span>
<?php endif; ?>
    </span>
  </div>
<?php endforeach; ?>
  <p class="small muted" style="margin:.8rem 0 0">
    <?= (int)($counts['pass'] ?? 0) ?> ok,
    <?= (int)($counts['warn'] ?? 0) ?> warning(s),
    <?= (int)($counts['fail'] ?? 0) ?> failure(s).
    Same checks from a terminal: <code>timeout 120 php tools/selftest.php</code></p>
</div>

<h2>Envelopes that arrived</h2>
<?php if (!$arrivals): ?>
  <p class="muted">None yet. Nothing is posted here until a dispatcher on the instance points at
     <code><?= h(bbl_hook_url()) ?></code>.</p>
<?php else: ?>
<div class="card scroll-x">
  <table class="grid">
    <thead><tr><th>When</th><th>From</th><th>Task</th><th></th><th>What happened</th></tr></thead>
    <tbody>
<?php foreach ($arrivals as $row): ?>
<?php $outcome = delivery_outcome($row); ?>
      <tr>
        <td><?= h(view_ago($row['created_at'])) ?></td>
        <td><?= h($row['remote_addr']) ?></td>
        <td><?= $row['task_id']
          ? '<a href="delivery.php?id=' . (int)$row['id'] . '">#' . (int)$row['task_id'] . '</a>'
          : '<a href="delivery.php?id=' . (int)$row['id'] . '">—</a>' ?></td>
        <td><span class="badge delivery-<?= h($outcome['state']) ?>"><?= h($outcome['label']) ?></span></td>
        <td><?= h($row['reason'] ?: ($row['transport_error'] ?: '')) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<h2>What to give the instance</h2>
<div class="card">
  <p class="small">The role's webhook dispatcher on the instance points here instead of at the
     worker. Its signing secret does not change, and this does not have to hold a copy — the envelope
     reaches the worker exactly as it was signed, so the worker's own check still passes.</p>
  <div class="facts">
    <div><span class="k">Kind</span><span class="v">webhook</span></div>
    <div><span class="k">URL</span><span class="v"><?= h(bbl_hook_url()) ?></span></div>
    <div><span class="k">Timeout</span><span class="v">a little over
      <?= h(setting('deliver_timeout')) ?>s</span></div>
  </div>
  <p class="small muted" style="margin-top:.8rem">The dispatcher's timeout has to outlast this
     proxy's wait for the worker, or it gives up while the worker is still being asked and every
     delivery looks like a failure worth retrying.</p>
</div>

<?php
view_footer();
