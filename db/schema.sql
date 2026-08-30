-- beeblebrox-proxy — the whole schema, with every migration folded in.
--
-- Nobody loads this by hand. The application creates the database on first use and then records the
-- migrations as already applied, so there is no install step for storage at all — see lib/db.php.
--
-- SQLite, because there is nothing here a database server would do better: eight settings, a
-- password hash, a session and an append-only log, at a handful of rows a day. Dates are stored as
-- the text datetime('now') produces, which is UTC, and only ever displayed as "5m ago" — so there is
-- no timezone anywhere to get wrong.

-- Which migration files have run. Creating the database seeds this with all of them.
CREATE TABLE IF NOT EXISTS schema_migrations (
  filename   TEXT NOT NULL PRIMARY KEY,
  applied_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Everything a person configures. Kept here rather than in config.local.php because somebody
-- standing this up should be able to do it on a page, and because the worker's address changes the
-- day its DHCP lease does.
--
-- is_secret marks a value stored encrypted under SECRET_KEY. The settings page shows those as
-- "set" or "not set" and never reads one back, which is why an empty submission means "leave it
-- alone" rather than "clear it".
CREATE TABLE IF NOT EXISTS settings (
  name       TEXT NOT NULL PRIMARY KEY,
  value      TEXT,
  is_secret  INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- One row per envelope that arrived, forwarded or refused, with what the worker said back.
--
-- This is the whole observable behavior of a proxy. There is nothing else to look at when work is
-- not reaching a machine, so nothing here is skipped and nothing is summarized away: the body as it
-- arrived, the address it went to, the status that came back and the reason if it did not go at all.
--
-- The body is kept because a signature failure is only ever diagnosed by comparing what was signed
-- against what was received, and there is no second chance to capture it. It is not sensitive in the
-- way it looks: an envelope names a task and never carries the work, which the worker fetches for
-- itself with its own key.
CREATE TABLE IF NOT EXISTS deliveries (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  remote_addr     TEXT,

  -- Read out of the envelope for the list view. Nothing routes on any of it — this proxy has exactly
  -- one place to send anything — so an envelope missing all of it is still forwarded.
  event           TEXT,
  instance        TEXT,
  task_id         INTEGER,
  chain_id        INTEGER,
  role_slug       TEXT,

  -- Whether it left this machine at all. A refusal here never reaches the worker; everything else is
  -- the worker's own answer, relayed untouched.
  forwarded       INTEGER NOT NULL DEFAULT 0,
  reason          TEXT,

  target_url      TEXT,
  body            TEXT,
  response_status INTEGER,
  response_body   TEXT,
  transport_error TEXT,
  duration_ms     INTEGER,

  created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_deliveries_created ON deliveries (created_at);
CREATE INDEX IF NOT EXISTS idx_deliveries_task ON deliveries (task_id);

-- Sessions for these pages. In the database rather than in PHP's own session directory so that
-- clearing that directory does not sign you out, and so this application shares no state with
-- whatever else is running on the same web server — which, given where a proxy has to live, there
-- usually is.
CREATE TABLE IF NOT EXISTS sessions (
  id          TEXT NOT NULL PRIMARY KEY,
  payload     TEXT NOT NULL,
  last_active INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sessions_last_active ON sessions (last_active);
