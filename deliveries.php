<?php
// Every envelope that has arrived, newest first, and a way to see only the ones that did not get
// through. There is no other history to look at — a proxy holds nothing else.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/deliveries.php';
require_once __DIR__ . '/lib/view.php';

bbl_session_start();
bbl_require_signin();

$problems_only = ($_GET['show'] ?? '') === 'problems';

// "Did not get through" is one condition rather than three, because the page's job here is to
// separate what worked from what did not; which of the three ways it failed is the row's job.
$rows = $problems_only
  ? db_all('SELECT * FROM deliveries
             WHERE forwarded = 0 OR response_status IS NULL
                OR response_status < 200 OR response_status >= 300
             ORDER BY id DESC LIMIT 100')
  : db_all('SELECT * FROM deliveries ORDER BY id DESC LIMIT 100');

view_header('Deliveries', true);
?>
<h2>Deliveries</h2>
<div class="actions">
  <a href="deliveries.php" class="<?= $problems_only ? 'secondary' : 'primary' ?>">Everything</a>
  <a href="deliveries.php?show=problems"
     class="<?= $problems_only ? 'primary' : 'secondary' ?>">Only what did not get through</a>
</div>

<?php if (!$rows): ?>
  <p class="muted"><?= $problems_only
    ? 'Nothing has failed. That is the page you want to keep empty.'
    : 'Nothing has arrived yet.' ?></p>
<?php else: ?>
<?php foreach ($rows as $row) { view_delivery_row($row); } ?>
<?php if (count($rows) === 100): ?>
  <p class="small muted">The last hundred. Older ones are still in the <code>deliveries</code>
     table.</p>
<?php endif; ?>
<?php endif; ?>

<?php
view_footer();
