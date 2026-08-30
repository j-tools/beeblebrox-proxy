<?php
// Two pages at one address, and deliberately so.
//
// Signed out, this explains what the thing is to somebody who has just been handed the repository.
// It is the only page written for a reader who does not already know, which makes it the landing
// page, and it says nothing about this particular installation — no instance, no worker address,
// nothing that would matter given this is the machine deliberately left reachable.
//
// Signed in, it is the dashboard: whether anything is arriving, and what happened to it.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/deliveries.php';
require_once __DIR__ . '/lib/view.php';

// The database is the first thing that can be wrong, and a stack trace is a poor way to say so on
// the page somebody opens first.
try {
  db();
  $reachable = true;
} catch (Throwable $e) {
  $reachable = false;
  $db_error = $e->getMessage();
}

if ($reachable) {
  bbl_session_start();
}

if (!$reachable || !bbl_signed_in()) {
  view_header('A way in to a machine that has none', false);
  view_masthead();
  ?>
  <p class="lede">Your Beeblebrox instance pushes work to a worker by posting to it. That only works
     if it can reach the worker — and a machine on an office network or behind a home router cannot
     be reached from outside. This sits on an address that can be, on the same network as the worker,
     and passes each envelope on unchanged.</p>

  <ol class="steps">
    <li><strong>The instance posts here</strong>
      <span>One address to open up, on one machine, instead of one per worker. The envelope names a
        task and nothing more — the briefing is fetched separately by the worker, with the worker's
        own key, so none of the work passes through this at all.</span></li>
    <li><strong>It is checked</strong>
      <span>Against the instance it says it came from, and against the signature too if you give this
        the signing secret. Anything else never reaches your network.</span></li>
    <li><strong>It is handed on, byte for byte</strong>
      <span>Same body, same headers, so the signature still verifies at the far end. Nothing is
        parsed, rewritten or held.</span></li>
    <li><strong>The worker's answer goes back</strong>
      <span>Its status and its words, not this machine's. The dispatcher decides whether to retry
        from that, so it has to be the truth about what happened.</span></li>
  </ol>

<?php if (!$reachable): ?>
  <?php // The only state in which telling somebody to go and set up a config file is any use.
        // Anyone reading the page below this has plainly already done it — the page would not
        // render at all otherwise. ?>
  <p class="error">This copy cannot open its database: <?= h($db_error) ?></p>
  <div class="card">
    <h3 style="margin-top:0">Before this can do anything</h3>
    <p class="small">There is nothing to install — the database is one SQLite file and it makes
       itself. Almost always this means the web server is not allowed to write to
       <code><?= h(dirname(bbl_config()['db_file'])) ?></code>. <code>INSTALL.md</code> section 2 has
       it, including how to put the file somewhere else.</p>
  </div>
<?php else: ?>
  <div class="actions">
    <?php // Named for what actually happens next, which is different the first time. ?>
    <a href="login.php" class="primary">
      <?= setting('admin_password_hash') === '' ? 'Set this one up' : 'Sign in' ?></a>
  </div>
<?php endif; ?>
<?php
  view_footer();
  exit;
}

// --- signed in ---------------------------------------------------------------------------------

$notice = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'probe') {
  bbl_check_csrf();
  require_once __DIR__ . '/lib/deliver.php';
  $probe = deliver_probe();
  if ($probe['ok']) {
    $notice = 'The worker answered at ' . $probe['url'] . '.';
  } else {
    $error = 'No good answer from ' . ($probe['url'] ?: 'the worker') . ': ' . $probe['error'];
  }
}

$gaps = settings_gaps();
$counts = delivery_counts_today();
$last = delivery_last_at();
$recent = deliveries_recent(12);

view_header('Dashboard', true);
view_flash($error, $notice);
?>

<?php if ($gaps): ?>
  <h2>Not ready yet</h2>
  <div class="card">
    <ul>
<?php foreach ($gaps as $gap): ?>
      <li><?= h($gap) ?></li>
<?php endforeach; ?>
    </ul>
    <div class="actions">
      <a href="setup.php" class="primary">Finish setup</a>
      <a href="settings.php" class="secondary">All settings</a>
      <a href="diagnostics.php" class="secondary">Diagnostics</a>
    </div>
  </div>
<?php endif; ?>

<h2>Today</h2>
<div class="card">
  <div class="facts">
    <div><span class="k">Through to the worker</span><span class="v"><?= (int)$counts['ok'] ?></span></div>
    <div><span class="k">Did not get through</span><span class="v"><?= (int)$counts['bad'] ?></span></div>
    <?php // The one number that separates "nothing is arriving" from "everything that arrives is
          // being refused" — which are the same empty page and completely different problems. ?>
    <div><span class="k">Last envelope</span><span class="v"><?=
      h($last ? view_ago($last) : 'never') ?></span></div>
    <div><span class="k">Sending to</span><span class="v"><?=
      h(worker_hook_url() ?: 'nowhere yet') ?></span></div>
  </div>
  <form method="post" class="actions">
    <?= bbl_csrf_field() ?>
    <input type="hidden" name="action" value="probe">
    <button type="submit">Check the worker now</button>
    <a href="deliveries.php" class="secondary">All deliveries</a>
  </form>
  <p class="small muted">Asks the worker whether it is there. Nothing is sent to it — an envelope
     only ever arrives from your instance.</p>
</div>

<h2>Recent</h2>
<?php if (!$recent): ?>
  <p class="muted">Nothing has arrived yet. Point a dispatcher on your instance at
     <code><?= h(bbl_hook_url()) ?></code>, or use its own test button, and it will show here either
     way.</p>
<?php else: ?>
<?php foreach ($recent as $row) { view_delivery_row($row); } ?>
<?php endif; ?>

<?php
view_footer();
