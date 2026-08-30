<?php
// Shared guard for everything in tools/. These write to the database and reach out over the network;
// served over HTTP they would be an unauthenticated way to do both.

function tools_require_cli() {
  if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
  }
}

// Says which migration is missing rather than letting the first query that needs the table throw a
// stack trace at somebody who then has to work out which table it meant.
function tools_require_table($table, $migration) {
  if (!db_table_exists($table)) {
    fwrite(STDERR, "The '{$table}' table does not exist yet. Apply db/migrations/{$migration} with " .
      "tools/migrate.php, then run this again.\n");
    exit(1);
  }
}
