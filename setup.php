<?php
// Setting this up. Two questions, because there are only two things this needs to know: whose work
// it is passing on, and where to.
//
// Both are checked at the moment they are entered rather than trusted. A wrong instance name or a
// worker on the wrong port is otherwise found much later, as nothing happening, which is the hardest
// symptom there is to work back from — and on a proxy it is the only symptom there is.
//
// It writes exactly what the settings page writes, through the same setting_set. There is no
// separate state to get out of step, and no way to end up configured through one and not the other.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view.php';
require_once __DIR__ . '/lib/deliver.php';

bbl_session_start();
bbl_require_signin();

// A step is done when somebody answered it, not when the setting behind it happens to hold
// something — otherwise a value carried over from a half-finished attempt ticks off a question
// nobody has read.
function setup_steps() {
  $answered = setup_answered();
  return [
    'company' => ['title' => 'Whose work',  'done' => in_array('company', $answered, true)],
    'worker'  => ['title' => 'Where to',    'done' => in_array('worker', $answered, true)],
  ];
}

function setup_answered() {
  return preg_split('/[\s,]+/', (string)setting('setup_answered'), -1, PREG_SPLIT_NO_EMPTY);
}

function setup_mark_answered($step) {
  $answered = setup_answered();
  if (!in_array($step, $answered, true)) {
    $answered[] = $step;
    setting_set('setup_answered', implode(',', $answered));
  }
}

function setup_first_unanswered() {
  foreach (setup_steps() as $name => $step) {
    if (!$step['done']) {
      return $name;
    }
  }
  return 'done';
}

$steps = setup_steps();
$step = $_GET['step'] ?? '';
if (!isset($steps[$step]) && $step !== 'done') {
  $step = setup_first_unanswered();
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  bbl_check_csrf();
  $step = $_POST['step'] ?? $step;
  try {
    if ($step === 'company') {
      $url = instance_normalize($_POST['instance'] ?? '');
      if ($url === '') {
        throw new RuntimeException('Nothing to go on yet — the name of the instance is enough.');
      }
      setting_set('instance_url', $url);

      // Guessed from the instance name, because that is the same word in every hosted case and
      // typing it twice is a question nobody should be asked.
      $company = trim((string)($_POST['company_name'] ?? ''));
      setting_set('company_name', $company !== '' ? $company : ucfirst(instance_name()));

      // Checked because this address is not decoration: every envelope has to name it or it is
      // refused, so a typo here refuses everything, silently, forever.
      $health = deliver_instance_health($url);
      if (!$health['ok'] && empty($_POST['confirm_company'])) {
        throw new RuntimeException("Saved, but nothing answered at {$url}. " .
          ($health['error'] ?: '') . ' Check the name and try again, or submit again to carry on — ' .
          'an instance that is simply down at this moment is not a wrong address.');
      }
      setup_mark_answered('company');
      header('Location: setup.php?step=worker');
      exit;
    }

    if ($step === 'worker') {
      $url = worker_normalize($_POST['worker_url'] ?? '');
      if ($url === '') {
        throw new RuntimeException('Without an address there is nowhere to send anything.');
      }
      setting_set('worker_url', $url);

      // A GET on a Beeblebrox receiver is answered with 405 and a sentence about wanting a POST, so
      // this asks a question only the right thing can answer. "Something is listening" would pass on
      // a router's login page.
      $probe = deliver_probe();
      if (!$probe['ok'] && empty($_POST['confirm_worker'])) {
        throw new RuntimeException("Saved, but {$probe['url']} " . $probe['error'] .
          ' Submit again to carry on regardless — this is worth doing if the worker is simply ' .
          'switched off right now.');
      }
      setup_mark_answered('worker');
      header('Location: setup.php?step=done');
      exit;
    }
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$steps = setup_steps();

view_header('Setup', true);
view_masthead();
?>

<h2>Setting up</h2>

<ol class="wizard">
<?php foreach ($steps as $name => $meta): ?>
  <li class="<?= $name === $step ? 'here' : ($meta['done'] ? 'done' : '') ?>">
<?php if ($meta['done'] && $name !== $step): ?>
    <a href="setup.php?step=<?= h($name) ?>"><?= h($meta['title']) ?></a>
<?php else: ?>
    <?= h($meta['title']) ?>
<?php endif; ?>
  </li>
<?php endforeach; ?>
  <li class="<?= $step === 'done' ? 'here' : '' ?>">Done</li>
</ol>

<?php view_flash($error); ?>

<?php if ($step === 'company'): ?>
  <div class="card">
    <p class="lede" style="margin-top:0">Which Beeblebrox does this pass work on for?</p>
    <form method="post" class="stack">
      <?= bbl_csrf_field() ?>
      <input type="hidden" name="step" value="company">
<?php if ($error !== null): ?>
      <!-- Set once the check has already failed and been shown, so a second submission is a person
           saying they know and meant it rather than the same refusal on a loop. -->
      <input type="hidden" name="confirm_company" value="1">
<?php endif; ?>
      <label>The instance
        <input type="text" name="instance" autofocus required
               value="<?= h(instance_name() ?: '') ?>" placeholder="zaphod">
        <small>Just the name is enough — <code>zaphod</code> becomes
          <code>https://zaphod.beeblebrox.cloud</code>. If the instance is somewhere else, put the
          whole address in instead.</small>
      </label>
      <label>Call it
        <input type="text" name="company_name" value="<?= h(setting('company_name')) ?>"
               placeholder="left empty, the name above is used">
        <small>What appears at the top of every page here, so two of these are never mistaken for
          each other. It links back to the instance.</small>
      </label>
      <button type="submit">Continue</button>
    </form>
  </div>
  <p class="small muted">Every envelope says which instance sent it, and one that says anything else
     is refused here rather than carried onto your network. So this is not a label: it is the reason
     an address somebody else finds is no use to them.</p>

<?php elseif ($step === 'worker'): ?>
  <div class="card">
    <p class="lede" style="margin-top:0">Where should envelopes go?</p>
    <form method="post" class="stack">
      <?= bbl_csrf_field() ?>
      <input type="hidden" name="step" value="worker">
<?php if ($error !== null): ?>
      <!-- Set once the probe has already failed and been shown, so a second submission is a person
           saying they know and meant it rather than the same refusal on a loop. -->
      <input type="hidden" name="confirm_worker" value="1">
<?php endif; ?>
      <label>The worker
        <input type="text" name="worker_url" autofocus required
               value="<?= h(setting('worker_url')) ?>" placeholder="192.168.1.20:8080">
        <small>Its address on this network is enough — a name or an IP, with the port if it is not
          80. <code>/hook.php</code> is added for you. Plain <code>http</code> unless you type
          <code>https://</code>, because a certificate for a machine on a LAN is a fight nobody
          needs.</small>
      </label>
      <button type="submit">Check it and finish</button>
    </form>
  </div>
  <p class="small muted">The check asks the worker's <code>hook.php</code> for a page and expects to
     be told off for not posting. That is a Beeblebrox receiver identifying itself — "something is
     listening" would be answered just as happily by a router's login screen.</p>

<?php else: ?>
  <div class="card">
    <p class="lede" style="margin-top:0">This relays for
      <a href="<?= h(instance_base()) ?>" target="_blank" rel="noopener"><?= h(company_name()) ?></a>,
      into <code><?= h(worker_hook_url()) ?></code>.</p>
    <p class="small">One thing left, and it is not a setting here:</p>
    <ol class="steps">
      <li><strong>Point the dispatcher at this address</strong>
        <span>On the instance, the role's webhook dispatcher needs
          <code><?= h(bbl_hook_url()) ?></code> instead of the worker's own address. Its signing
          secret does not change and this does not need to know it — the worker still checks the
          signature, because the envelope reaches it exactly as it was signed.</span></li>
    </ol>
    <div class="actions">
      <a class="secondary" href="diagnostics.php">Check everything</a>
      <a class="secondary" href="index.php">Dashboard</a>
      <a class="secondary" href="settings.php">All settings</a>
    </div>
  </div>
  <div class="card">
    <p class="small" style="margin:0"><strong>Worth doing, not required.</strong> This is the machine
      you have deliberately made reachable, so two things on the
      <a href="settings.php">settings page</a> are worth a minute: the <strong>signing secret</strong>,
      which lets this refuse a forged envelope here instead of carrying it to the worker, and the
      <strong>allow list</strong>, which is easier to fill in here than on the worker — the worker
      sees every envelope arriving from this machine and learns nothing from that.</p>
  </div>
<?php endif; ?>

<?php if ($step !== 'done'): ?>
  <p class="small muted">Both of these are on the <a href="settings.php">settings page</a> too,
     alongside the ones this does not ask about.</p>
<?php endif; ?>

<?php
view_footer();
