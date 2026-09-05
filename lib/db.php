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

// Why SQLite could not open a file, in words. Everything here is a question about the filesystem, so
// it holds whether or not PDO said anything useful — which it does not.
function db_open_diagnosis($file) {
  $dir = dirname($file);
  $said = [];

  if (!is_dir($dir)) {
    // Naming the parent is the actionable half: a directory that cannot be created almost always
    // means its parent is not writable by this account.
    $parent = dirname($dir);
    $said[] = "the directory {$dir} does not exist and could not be created";
    $said[] = is_dir($parent)
      ? "its parent {$parent} " . (is_writable($parent) ? 'is writable, so something else refused' : 'is not writable')
      : "and its parent {$parent} is not there either";
  } elseif (!is_writable($dir)) {
    $said[] = "the directory {$dir} is not writable, and SQLite writes -wal and -shm files beside " .
      'the database as well as the database itself';
  } else {
    $said[] = "the directory {$dir} exists and is writable";
  }

  if (!is_dir($dir)) {
    // No point discussing the file when the directory it would sit in does not exist.
  } elseif (is_dir($file)) {
    $said[] = 'but the database path is a directory, not a file';
  } elseif (file_exists($file)) {
    $said[] = is_writable($file)
      ? 'the file exists and is writable'
      : 'the file exists but is not writable';
  } else {
    $said[] = 'the file does not exist yet, which is normal — it is created on first use';
  }

  // Who to grant it to. The account the web server runs as is the thing somebody needs to name in a
  // chown or a Windows permission dialog, and it is never the account that unpacked the zip.
  $user = null;
  if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
    $info = @posix_getpwuid(posix_geteuid());
    $user = $info['name'] ?? null;
  }
  if ($user === null) {
    $user = getenv('USERNAME') ?: getenv('USER') ?: @get_current_user();
  }
  if ($user !== '' && $user !== false && $user !== null) {
    $said[] = "this PHP is running as '{$user}', which is the account that needs to be allowed to " .
      'write there';
  }

  return implode('; ', $said) . '.';
}
// Single connection per request, opened on first use, with the schema created if it is not there.
function db() {
  static $pdo = null;
  if ($pdo !== null) {
    return $pdo;
  }
  $file = bbl_config()['db_file'];

  $dir = dirname($file);
  if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
    throw new RuntimeException('Cannot create the directory for the database — ' .
      db_open_diagnosis($file));
  }
  $fresh = !file_exists($file);

  // SQLite answers "unable to open database file" to every reason it could not: the directory is not
  // writable, the file is not writable, the path is not a file, the parent is not traversable. Ten
  // words that name none of them, on the one screen somebody sees before anything works.
  //
  // So the reasons are asked here and named. The web server almost never runs as the account that
  // unpacked the zip, which is why writability is asked about the directory as well as the file —
  // SQLite needs to create -wal and -shm beside the database, so a writable file in a read-only
  // directory still fails.
  try {
    $pdo = new PDO('sqlite:' . $file, null, null, [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  } catch (Throwable $e) {
    throw new RuntimeException($e->getMessage() . ' — ' . db_open_diagnosis($file), 0, $e);
  }

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
