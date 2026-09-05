<?php
// Storage. One SQLite file, created on first use.
//
// The worker keeps its state in MySQL and has to: it holds jobs, their events, project mappings and
// a scheduler that must claim a task exactly once while another process might be doing the same. A
// proxy has none of that. It holds eight settings, a password hash, a session and an append-only
// log — nothing relational, nothing transactional, and a handful of rows a day. Asking whoever
// installs this to stand up a database server, make a user, grant it and load a schema would be more
// work than the thing it is forwarding.
//
// So the whole store is a file, and it makes itself. There is no install step for it at all.
//
// Every query in the application goes through these helpers, so there is exactly one place where
// values are bound and no code path where a value can reach SQL unbound.

// Single connection per request, opened on first use, with the schema created if it is not there.
function db() {
  static $pdo = null;
  if ($pdo !== null) {
    return $pdo;
  }
  $file = bbl_config()['db_file'];

  $dir = dirname($file);
  if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
    throw new RuntimeException("Cannot create the directory for the database: {$dir}");
  }
  $fresh = !file_exists($file);

  $pdo = new PDO('sqlite:' . $file, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);

  // Values come back as the types they were stored as rather than all as strings. This is a
  // convenience, not something anything depends on — every caller that compares or does arithmetic
  // casts first — which is why it is set separately and allowed to fail. Before PHP 8.1 the SQLite
  // driver returned everything as a string and did not accept this at all, and refusing to start
  // over a nicety would be the wrong trade.
  try {
    $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
  } catch (Throwable $e) {
  }

  // A write while a page is reading would otherwise fail outright rather than wait. Both matter
  // here for the same reason: a delivery arriving while somebody has the deliveries page open is the
  // normal case, not the unlucky one.
  $pdo->exec('PRAGMA journal_mode = WAL');
  $pdo->exec('PRAGMA busy_timeout = 5000');
  $pdo->exec('PRAGMA foreign_keys = ON');

  // Write-ahead logging arrived in SQLite 3.7.0 (2010) and is the oldest thing here that matters:
  // PRAGMA journal_mode silently reports the mode it kept rather than failing, so a library without
  // it would leave every read blocking behind every write with nothing saying why.
  //
  // Nothing else in this application needs a modern library. It deliberately does not use ON CONFLICT
  // DO UPDATE (3.24), because the box a company already has facing the internet is exactly where an
  // old one lives — Debian 9 ships 3.16 with a PHP this supports.
  //
  // The message names the interpreter, because the usual cause of a version surprise is the web
  // server running a different PHP from the one somebody installed.
  $sqlite = $pdo->query('SELECT sqlite_version()')->fetchColumn();
  if (version_compare($sqlite, '3.7.0', '<')) {
    $ini = php_ini_loaded_file();
    throw new RuntimeException(
      'This copy cannot open its database: PHP ' . PHP_VERSION . ' here is built against SQLite ' .
      "{$sqlite}, and 3.7.0 or newer is needed for write-ahead logging. The PHP answering this " .
      'request is ' . (PHP_BINARY !== '' ? PHP_BINARY : php_sapi_name()) . ', reading ' .
      ($ini !== false ? $ini : 'no php.ini') . ' — if that is not the PHP you installed, the web ' .
      'server is loading a different one.');
  }

  if ($fresh) {
    db_install($pdo);
  }
  return $pdo;
}

// Creates the schema in a database that has none.
//
// Every statement is CREATE ... IF NOT EXISTS, so running this over a database that already has the
// tables is harmless — which matters, because the file being absent is not the only way to arrive
// here with nothing in it.
function db_install(PDO $pdo) {
  $schema = file_get_contents(__DIR__ . '/../db/schema.sql');
  if ($schema === false) {
    throw new RuntimeException('db/schema.sql is missing, so the database cannot be created.');
  }
  $pdo->exec($schema);

  // The migrations are recorded as applied rather than run: schema.sql has every one of them folded
  // in already, and the runner must never apply one over the top of a schema that has it.
  foreach (glob(__DIR__ . '/../db/migrations/*.sql') ?: [] as $file) {
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO schema_migrations (filename) VALUES (?)');
    $stmt->execute([basename($file)]);
  }
}

// Prepares, binds and executes. Types are derived from the PHP values rather than declared at every
// call site, which is what keeps callers readable enough that nobody is tempted to interpolate.
function db_stmt($sql, $params = []) {
  $stmt = db()->prepare($sql);
  foreach (array_values($params) as $index => $value) {
    if ($value === null) {
      $type = PDO::PARAM_NULL;
    } elseif (is_int($value) || is_bool($value)) {
      $type = PDO::PARAM_INT;
      $value = (int)$value;
    } else {
      $type = PDO::PARAM_STR;
    }
    $stmt->bindValue($index + 1, $value, $type);
  }
  $stmt->execute();
  return $stmt;
}

function db_all($sql, $params = []) {
  return db_stmt($sql, $params)->fetchAll();
}

function db_one($sql, $params = []) {
  $row = db_stmt($sql, $params)->fetch();
  return $row === false ? null : $row;
}

// Returns the new id for an INSERT, otherwise the number of affected rows.
//
// Whether it was an INSERT is decided from the statement rather than from lastInsertId(), because
// SQLite's is per-connection and does not reset: after an UPDATE it still holds the id from the last
// INSERT on the same request, so trusting it would have every update report a row id as its result.
function db_exec($sql, $params = []) {
  $stmt = db_stmt($sql, $params);
  if (preg_match('/^\s*INSERT\b/i', $sql)) {
    return (int)db()->lastInsertId();
  }
  return $stmt->rowCount();
}

function db_begin() {
  db()->beginTransaction();
}

function db_commit() {
  db()->commit();
}

function db_rollback() {
  db()->rollBack();
}

function db_count($sql, $params = []) {
  $row = db_one($sql, $params);
  return $row === null ? 0 : (int)reset($row);
}

// Whether a table exists. SQLite keeps its catalog in sqlite_master, which is where the checks and
// the migration runner ask — there is no information_schema.
function db_table_exists($table) {
  return db_count("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]) > 0;
}
