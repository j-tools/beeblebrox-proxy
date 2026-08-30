<?php
// Applies db/migrations/*.sql once each, in filename order, recording each in schema_migrations.
//
//   timeout 120 php tools/migrate.php
//   timeout 120 php tools/migrate.php --dry-run
//
// A new database never needs this: the application creates it from db/schema.sql, which has every
// migration folded in, and records them all as applied. This is only for a database that already
// exists and is behind.
//
// Every migration file must be re-runnable. Applying one by hand leaves the schema right and
// schema_migrations empty, which is a normal thing to have happened, and the runner has to survive
// it. SQLite has no ADD COLUMN IF NOT EXISTS, so a step that adds one should check pragma_table_info
// first — or the file should be written so that re-running it is simply not an error.

require_once __DIR__ . '/cli.php';
tools_require_cli();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';

$dry_run = in_array('--dry-run', $argv, true);
$cfg = bbl_config();
echo "database: {$cfg['db_file']}\n";

$existed = file_exists($cfg['db_file']);
db();
if (!$existed) {
  echo "created it, with every migration recorded as applied.\n";
  exit(0);
}

$applied = array_column(db_all('SELECT filename FROM schema_migrations'), 'filename');
$files = glob(__DIR__ . '/../db/migrations/*.sql') ?: [];
sort($files);

$pending = array_values(array_filter($files, function ($file) use ($applied) {
  return !in_array(basename($file), $applied, true);
}));

if (!$pending) {
  echo count($files) . ' migration(s), all applied. Nothing to do.' . "\n";
  exit(0);
}

printf("%d pending\n\n", count($pending));

foreach ($pending as $file) {
  $name = basename($file);
  if ($dry_run) {
    echo "  would apply  {$name}\n";
    continue;
  }
  echo "  applying     {$name} ... ";
  try {
    // One transaction per file, so a file that fails half way leaves nothing behind. SQLite can roll
    // back DDL, which is the one thing it does here that MySQL cannot.
    db_begin();
    db()->exec(file_get_contents($file));
    db_exec('INSERT INTO schema_migrations (filename) VALUES (?)', [$name]);
    db_commit();
    echo "done\n";
  } catch (Throwable $e) {
    db_rollback();
    echo "FAILED\n\n";
    fwrite(STDERR, $e->getMessage() . "\n\n");
    fwrite(STDERR,
      "Nothing in that file was applied, and nothing after it was attempted. If the change is in " .
      "fact already present — because it was applied by hand — record it and carry on:\n\n" .
      "  INSERT INTO schema_migrations (filename) VALUES ('{$name}');\n\n");
    exit(1);
  }
}

echo "\n" . ($dry_run ? "Dry run — nothing was applied.\n" : "Up to date.\n");
