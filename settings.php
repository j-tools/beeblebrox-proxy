<?php
// Everything a person configures, on one page, in the order somebody setting this up needs it.
//
// The signing secret is write-only. Leaving it blank keeps what is stored, which is the only sane
// behavior for a field that cannot show its current value — otherwise saving a change to the timeout
// would silently stop this checking signatures.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view.php';
require_once __DIR__ . '/lib/deliver.php';

bbl_session_start();
bbl_require_signin();

$error = null;
$notice = null;

// Plain values, saved as typed. The secret and the password are handled separately below, because
// "empty means leave it alone" is true for those and false for these.
$plain = [
  'instance_url', 'company_name', 'worker_url', 'accept_webhooks',
  'deliver_timeout', 'signature_tolerance', 'allowed_ips',
];
$checkboxes = ['accept_webhooks'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  bbl_check_csrf();
  $action = $_POST['action'] ?? 'save';

  if ($action === 'password') {
    $password = (string)($_POST['password'] ?? '');
    if (strlen($password) < 10) {
      $error = 'Use at least 10 characters.';
    } elseif ($password !== (string)($_POST['password_again'] ?? '')) {
      $error = 'The two entries do not match.';
    } else {
      setting_set('admin_password_hash', password_hash($password, PASSWORD_DEFAULT));
      $notice = 'Password changed.';
    }
  } else {
    try {
      // Same parsing as the setup wizard, so a bare instance name and a bare worker address work in
      // both places rather than being conveniences that only exist the first time.
      $instance = instance_normalize($_POST['instance_url'] ?? '');
      $worker = worker_normalize($_POST['worker_url'] ?? '');
      foreach ($plain as $name) {
        $value = in_array($name, $checkboxes, true)
          ? (empty($_POST[$name]) ? '0' : '1')
          : trim((string)($_POST[$name] ?? ''));
        if ($name === 'instance_url') {
          $value = $instance;
        }
        if ($name === 'worker_url') {
          $value = $worker;
        }
        setting_set($name, $value);
      }
      // Empty means unchanged; there is a separate checkbox for actually clearing it, because
      // "stop checking signatures here" and "I did not retype it" must not look the same.
      foreach (bbl_secret_settings() as $name) {
        if (!empty($_POST['clear_' . $name])) {
          setting_set($name, '');
        } elseif (trim((string)($_POST[$name] ?? '')) !== '') {
          setting_set($name, trim((string)$_POST[$name]));
        }
      }
      $notice = 'Saved.';

      // Testing saves first, deliberately. Testing what is stored while the form shows something
      // else is the kind of answer that costs an afternoon.
      if ($action === 'test') {
        $probe = deliver_probe();
        if (!$probe['ok']) {
          throw new RuntimeException('Saved, but ' . ($probe['url'] ?: 'the worker') . ' ' .
            $probe['error']);
        }
        $health = deliver_instance_health(instance_base());
        $notice = $health['ok']
          ? 'Saved, and both ends answered.'
          : 'Saved, and the worker answered. The instance did not: ' . $health['error'] .
            ' That does not stop a delivery — envelopes come from it, this never calls it.';
      }
    } catch (Throwable $e) {
      $error = $e->getMessage();
      // The message says whether the save happened, so a "Saved." above it would only be read twice.
      $notice = null;
    }
  }
}

view_header('Settings', true);
view_flash($error, $notice);
?>

<?php // One form for every plain setting, because saving writes all of them: a second form posting
      // half of them would blank the other half every time it was used. ?>
<form method="post">
  <?= bbl_csrf_field() ?>

<h2>What goes where</h2>
<div class="card stack">
  <label>Beeblebrox instance
    <input type="text" name="instance_url" value="<?= h(setting('instance_url')) ?>"
           placeholder="zaphod">
    <small>The name on its own is enough — <code>zaphod</code> becomes
      <code>https://zaphod.beeblebrox.cloud</code>. Every envelope has to name this instance or it is
      refused here, which is what stops this address being of use to anybody else.</small>
  </label>
  <label>Company name
    <input type="text" name="company_name" value="<?= h(setting('company_name')) ?>"
           placeholder="<?= h(instance_name() ?: 'taken from the instance name') ?>">
    <small>What the top of every page here says, so two of these are never mistaken for each other.
      Presentation only — nothing routes on it.</small>
  </label>
  <label>Worker
    <input type="text" name="worker_url" value="<?= h(setting('worker_url')) ?>"
           placeholder="192.168.1.20:8080">
    <small>Its address on this network. <code>/hook.php</code> is added unless you name a file
      yourself; plain <code>http</code> unless you type <code>https://</code>. Envelopes go to
      <code><?= h(worker_hook_url() ?: 'nowhere yet') ?></code>.</small>
  </label>
  <label class="inline"><input type="checkbox" name="accept_webhooks" value="1"
    <?= setting_bool('accept_webhooks') ? 'checked' : '' ?>> Forward what arrives</label>
  <p class="small muted" style="margin:0">Off answers the instance with a 503, which its dispatcher
     reads as "try again later" rather than as a failure. That is the one to use while you are
     working on the worker.</p>
  <label>Wait for the worker
    <input type="text" name="deliver_timeout" value="<?= h(setting('deliver_timeout')) ?>">
    <small>Seconds. The worker's <code>hook.php</code> only writes the envelope down and answers —
      the work happens on its own schedule afterwards — so this covers an acceptance, not a run.
      Fifteen is plenty; much more and the dispatcher upstream has given up before this has.</small>
  </label>
</div>

<h2>Locks</h2>
<div class="card">
  <p class="small" style="margin:0">This machine is the one deliberately reachable from outside, so
     these are what keep a forged envelope from being carried onto the network the rest of this exists
     to keep closed. Refused here, it never touches the worker at all.</p>
  <p class="small" style="margin:.5rem 0 0">The allow list is worth setting here and nowhere else:
     your instance calls this from one address, while everything the worker sees arrives from this
     machine and so tells it nothing.</p>
  <p class="small" style="margin:.5rem 0 0">Both are optional, and leaving them empty is a working
     setup rather than a lax one — the worker verifies every signature itself, because it is the one
     that acts on the envelope. These stop rubbish a step earlier, on the machine where a stranger can
     reach it.</p>
</div>
<div class="card stack">
  <label>Signing secret
    <input type="password" name="webhook_secret" autocomplete="off"
           placeholder="<?= setting_secret_is_set('webhook_secret')
             ? 'stored — leave empty to keep it' : 'not set: signatures are checked by the worker only' ?>">
    <small>The same string the dispatcher on your instance signs with, and the same one the worker
      holds. Nothing is re-signed here, so getting it wrong refuses everything rather than breaking
      anything downstream.
<?php if (!secrets_available()): ?>
      <strong>SECRET_KEY is not set in <code>config.local.php</code>, so this cannot be stored
      yet.</strong>
<?php endif; ?>
    </small>
  </label>
<?php if (setting_secret_is_set('webhook_secret')): ?>
  <label class="inline"><input type="checkbox" name="clear_webhook_secret" value="1">
    Forget it and go back to forwarding unchecked</label>
<?php endif; ?>
  <label>Clock tolerance
    <input type="text" name="signature_tolerance" value="<?= h(setting('signature_tolerance')) ?>">
    <small>Seconds, and only consulted when a secret is set. Also the replay window. Keep it the same
      as the worker's, or this machine's clock becomes a second thing that can refuse a good
      envelope.</small>
  </label>
  <label>Allow list
    <input type="text" name="allowed_ips" value="<?= h(setting('allowed_ips')) ?>"
           placeholder="empty: any address">
    <small>Comma separated. Worth filling in here rather than on the worker: your instance calls this
      from one address, while everything the worker sees arrives from this machine and tells it
      nothing. Leave it empty if the instance is behind something that does not have one
      address.</small>
  </label>
</div>

<div class="actions">
  <button type="submit" name="action" value="save">Save</button>
  <button type="submit" name="action" value="test" class="secondary">Save and test</button>
</div>
</form>

<h2>What to give the instance</h2>
<div class="card">
  <p class="small">On the instance, the role's webhook dispatcher points here instead of at the
     worker. Nothing else about it changes — same signing secret, same envelope.</p>
  <div class="facts">
    <div><span class="k">Kind</span><span class="v">webhook</span></div>
    <div><span class="k">URL</span><span class="v"><?= h(bbl_hook_url()) ?></span></div>
    <div><span class="k">Timeout</span><span class="v">a little over
      <?= h(setting('deliver_timeout')) ?>s</span></div>
  </div>
  <p class="small muted" style="margin-top:.8rem">The dispatcher's timeout has to outlast this one,
     or it gives up while the worker is still being asked and every delivery looks like a failure it
     will retry. Use the dispatcher's own test button once it is pointed here — it sends a real
     signed envelope naming task 0, which no task ever is, and it shows on the
     <a href="deliveries.php">deliveries page</a> whichever way it goes.</p>
</div>

<h2>This page</h2>
<form method="post" class="card stack">
  <?= bbl_csrf_field() ?>
  <input type="hidden" name="action" value="password">
  <label>New password
    <input type="password" name="password" autocomplete="new-password">
    <small>At least 10 characters.</small>
  </label>
  <label>Again
    <input type="password" name="password_again" autocomplete="new-password">
  </label>
  <div class="actions">
    <button type="submit">Change it</button>
  </div>
</form>

<?php
view_footer();
