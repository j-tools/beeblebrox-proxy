<?php
// Layout and presentation. Same visual language as the platform and the local worker, on purpose —
// these are parts of one product, and somebody moving between the windows should not have to notice.

require_once __DIR__ . '/html.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/updates.php';
require_once __DIR__ . '/deliveries.php';

function view_label($value) {
  return ucfirst(str_replace('_', ' ', (string)$value));
}

// Whether the bar may ask the database anything.
//
// The landing page deliberately renders when the database is unreachable, in order to say so — and
// the header sits above that message. Since the bar names the company it reads a setting, and a
// header that throws on the way to the message replaces the message with a stack trace. This is
// presentation deciding what it is able to show, not a fallback hiding a failure: every caller that
// needs a real answer still gets the exception.
function view_settings_readable() {
  static $ok = null;
  if ($ok === null) {
    try {
      settings_raw();
      $ok = true;
    } catch (Throwable $e) {
      $ok = false;
    }
  }
  return $ok;
}

function view_company() {
  return view_settings_readable() && instance_base() !== '' ? company_name() : '';
}

// Where the mark in the bar points: the customer's own instance once there is one, and the public
// site until then, which is the honest answer to "what is this thing" at the moment somebody is
// most likely to be asking it.
function view_home_url() {
  return view_company() === '' ? bbl_public_site() : instance_base();
}

// Compared against the database clock rather than PHP's. The two are not reliably in step on a
// machine that has been asleep, and a PHP-computed difference can be hours out.
function view_ago($datetime) {
  if (!$datetime) {
    return '';
  }
  $mins = (int)db_one("SELECT CAST((julianday('now') - julianday(?)) * 1440 AS INTEGER) AS m",
    [$datetime])['m'];
  if ($mins < 1)    { return 'just now'; }
  if ($mins < 60)   { return $mins . 'm ago'; }
  if ($mins < 1440) { return intdiv($mins, 60) . 'h ago'; }
  return intdiv($mins, 1440) . 'd ago';
}

function view_duration($ms) {
  if ($ms === null) {
    return '';
  }
  $ms = (int)$ms;
  return $ms < 1000 ? $ms . 'ms' : round($ms / 1000, 1) . 's';
}

// A full-size mark inside the page, for the two screens where somebody is still working out what
// this is: the front door and the setup they walk through once. Everywhere else the bar carries it.
function view_masthead() {
  $company = view_company();
  ?>
  <a class="masthead" href="<?= h(view_home_url()) ?>" target="_blank" rel="noopener"
     title="<?= h($company === '' ? 'What Beeblebrox is' : 'Open ' . $company) ?>">
    <img src="assets/favicon-180.png" alt="">
    <span class="masthead-words">
      <span class="masthead-name"><?= h($company === '' ? 'Beeblebrox' : $company . ' Beeblebrox') ?></span>
      <span class="masthead-sub">Webhook proxy</span>
    </span>
  </a>
<?php
}

function view_head($title) {
  ?>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?> — Beeblebrox Proxy</title>
  <link rel="icon" href="assets/favicon-32.png" sizes="32x32" type="image/png">
  <link rel="apple-touch-icon" href="assets/favicon-180.png">
  <link rel="stylesheet" href="assets/style.css">
  <link rel="stylesheet" href="assets/proxy.css">
<?php
}

function view_menu_items() {
  $items = [
    ['href' => 'index.php',       'label' => 'Dashboard'],
    ['href' => 'deliveries.php',  'label' => 'Deliveries'],
    // Settings and Diagnostics are not what this is for. They are what you open when something
    // is wrong or not set up yet, which is a different kind of visit from looking at the work —
    // so they sit at the foot of the drawer with the build number, out of the way of the things
    // used every day.
    ['href' => 'settings.php',    'label' => 'Settings', 'foot' => true],
    ['href' => 'diagnostics.php', 'label' => 'Diagnostics', 'foot' => true],
  ];
  // Offered only while there is something it would still ask. Once everything is answered the
  // settings page is the place to change any of it, and a permanent "Setup" entry would suggest
  // otherwise.
  if (settings_gaps()) {
    array_unshift($items, ['href' => 'setup.php', 'label' => 'Finish setup']);
  }
  return $items;
}

// The identity block, rendered once and used in the bar and in the drawer both.
//
// It was written twice, and two copies of a thing that has to match is how they stop matching: the
// drawer had the wording without the mark, and different markup around it. One function instead, so
// there is nothing to keep in step — and the same block as the worker's and the instance's, since a
// person moving between the three windows should not have to notice which one they are in.
//
// The mark and the wording are one link, and it leads out of here rather than to the dashboard: the
// instance is somewhere else and is the thing somebody in this window wants to get back to. Before
// there is an instance the public site is the only honest answer to what this is.
function view_brand_block() {
  $company = view_company();
  ?>
  <a class="brand-block" href="<?= h(view_home_url()) ?>" target="_blank" rel="noopener"
     title="<?= h($company === '' ? 'What Beeblebrox is' : 'Open ' . $company) ?>">
    <img class="brand-mark" src="assets/favicon-32.png" width="28" height="28" alt="Beeblebrox">
    <span class="brand">
<?php if ($company !== ''): ?>
      <span class="brand-kicker">Relaying for</span>
      <span class="brand-company"><?= h($company) ?> <span class="muted">Beeblebrox</span></span>
<?php else: ?>
      <span class="brand-kicker">Beeblebrox</span>
      <span class="brand-company">Webhook proxy</span>
<?php endif; ?>
    </span>
  </a>
<?php
}

function view_header($title, $signed_in = false) {
  $here = basename($_SERVER['SCRIPT_NAME'] ?? '');
  // The last day rather than all time. A proxy that has been up for a month would otherwise show a
  // number that stopped meaning anything in its first week.
  $counts = $signed_in ? delivery_counts_today() : ['ok' => 0, 'bad' => 0];
  ?>
<!doctype html>
<html lang="en">
<head>
<?php view_head($title); ?>
</head>
<body>
<?php if ($signed_in): ?>
<!-- The drawer is a checkbox and two labels: no JavaScript, and it therefore cannot fail to open. -->
<input type="checkbox" id="drawer-toggle" class="drawer-toggle" hidden>
<?php endif; ?>
<header class="bar">
<?php if ($signed_in): ?>
  <label for="drawer-toggle" class="hamburger" title="Menu" aria-label="Menu"><span></span></label>
<?php endif; ?>
<?php view_brand_block(); ?>
<?php if ($signed_in): ?>
  <span class="bar-counts">
    <a href="deliveries.php?show=problems" class="count<?= $counts['bad'] ? ' count-live' : '' ?>">
      <?= (int)$counts['bad'] ?> <span class="muted">not through</span></a>
    <a href="deliveries.php" class="count"><?= (int)$counts['ok'] ?> <span class="muted">today</span></a>
  </span>
<?php endif; ?>
</header>

<?php if ($signed_in): ?>
<nav class="drawer" aria-label="Main">
  <?php /* What this is and whose, rather than the hostname it happens to be served under. The host
           said nothing a person needed: somebody looking at this window knows which address they
           typed, and 'localhost' is what it reads on the machine where the address is a tunnel or a
           port forward. The company is the word they actually recognise.

           bbl_env_label() is still what identifies this proxy to the instance, in the
           X-Beeblebrox-Proxy header and in every answer it relays, so a chain with two hops in it
           can be read from either end. That is a different question from what to put on a screen. */ ?>
  <?php /* The bar's block, not a second telling of it — the mark, the wording and the link, the same
           in both places.

           Then the other half of the sentence this application is: what it relays to. The full
           address rather than the host, because a port and a path are exactly what somebody checks
           when envelopes are not arriving. */ ?>
  <div class="drawer-who">
    <?php view_brand_block(); ?>
<?php if (company_name() === ''): ?>
    <span class="muted small">not set up yet</span>
<?php endif; ?>
<?php if (worker_base() !== ''): ?>
    <span class="brand-kicker">Relaying to</span>
    <span class="drawer-target"><?= h(worker_base()) ?></span>
<?php elseif (company_name() !== ''): ?>
    <span class="brand-kicker">Relaying to</span>
    <span class="muted small">no worker configured</span>
<?php endif; ?>
  </div>
<?php $menu = view_menu_items();
  $feet = array_values(array_filter($menu, function ($i) { return !empty($i['foot']); }));
  $main = array_values(array_filter($menu, function ($i) { return empty($i['foot']); })); ?>
<?php foreach ($main as $item): ?>
  <a class="drawer-item<?= $item['href'] === $here ? ' current' : '' ?>" href="<?= h($item['href']) ?>">
    <?= h($item['label']) ?></a>
<?php endforeach; ?>
<?php /* Pinned to the bottom by margin-top:auto on the group, so the everyday items stay
         where the thumb expects them however many there are. */ ?>
  <div class="drawer-foot">
<?php foreach ($feet as $item): ?>
      <a class="drawer-item<?= $item['href'] === $here ? ' current' : '' ?>" href="<?= h($item['href']) ?>">
        <?= h($item['label']) ?></a>
<?php endforeach; ?>
<?php /* Which copy this is, where somebody looking for it would look. A number rather than a
         commit because a number can be compared out loud: "you are on 26, the newest is 28". A
         checkout has no number — the release workflow writes it into the archive — and says so
         instead of showing nothing. */ ?>
<?php $build = bbl_build(); ?>
<?php $newer = updates_available(); ?>
    <p class="drawer-version muted small">
<?php if ($build['number'] !== null): ?>
      Build <?= (int)$build['number'] ?><?= $build['built'] !== null
        ? ', ' . h($build['built']) : '' ?>
<?php elseif ($build['commit'] !== null): ?>
      Commit <?= h(substr($build['commit'], 0, 7)) ?>
<?php else: ?>
      From a checkout
<?php endif; ?>
    </p>
<?php /* Shown only when there is something newer — a field that usually reads "up to date" gets
         looked at twice and never again, and this has to be noticed on the day it appears. */ ?>
<?php if ($newer !== null): ?>
    <p class="drawer-update">
      <a href="<?= h($newer['url']) ?>" target="_blank" rel="noopener">Build <?= (int)$newer['latest'] ?> is out</a>
    </p>
<?php endif; ?>
    <form method="post" action="logout.php" class="drawer-signout">
      <?= bbl_csrf_field() ?>
      <button type="submit" class="link">Sign out</button>
    </form>
  </div>
</nav>
<label for="drawer-toggle" class="scrim" aria-hidden="true"></label>
<?php endif; ?>

<main>
<?php
}

function view_footer() {
  echo "</main>\n</body>\n</html>\n";
}

function view_flash($error = null, $ok = null) {
  if ($error) {
    echo '<p class="error">' . h($error) . "</p>\n";
  }
  if ($ok) {
    echo '<p class="ok">' . h($ok) . "</p>\n";
  }
}

// One delivery, as a line you can read without opening it: which task, what happened to it, and how
// long the worker took to say so.
function view_delivery_row(array $row) {
  $outcome = delivery_outcome($row);
  ?>
  <a class="row-link" href="delivery.php?id=<?= (int)$row['id'] ?>">
    <span class="id"><?= $row['task_id'] ? '#' . (int)$row['task_id'] : '—' ?></span>
    <strong><?= h($row['role_slug'] ?: ($row['event'] ?: 'envelope')) ?></strong>
    <span class="title"><?= h(mb_strimwidth(
      (string)($row['reason'] ?: $row['transport_error'] ?: $row['response_body']), 0, 90, '…')) ?></span>
    <span class="meta">
      <span class="badge delivery-<?= h($outcome['state']) ?>"><?= h($outcome['label']) ?></span>
      <span class="muted small">
        <?= h($row['remote_addr']) ?>
<?php if ($row['duration_ms'] !== null): ?>
        &middot; <?= h(view_duration($row['duration_ms'])) ?>
<?php endif; ?>
        &middot; <?= h(view_ago($row['created_at'])) ?>
      </span>
    </span>
  </a>
<?php
}
