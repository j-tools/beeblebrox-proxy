-- beeblebrox-proxy — the whole schema, with every migration folded in.
--
-- A fresh install loads this file alone and is then current; tools/migrate.php records the
-- migrations as already applied so it never tries to run them over the top.
--
-- Written for MySQL 8 and MariaDB 10.4 alike, which rules out a few conveniences: no functional
-- defaults beyond CURRENT_TIMESTAMP, no JSON type (LONGTEXT, because MariaDB's JSON is an alias for
-- it anyway and the difference would only bite on somebody else's server).

SET NAMES utf8mb4;

-- Which migration files have run. Loading schema.sql seeds this with all of them.
CREATE TABLE IF NOT EXISTS schema_migrations (
  filename   VARCHAR(190) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Everything a person configures. Kept here rather than in config.local.php because somebody
-- standing this up should be able to do it on a page, and because the worker's address changes the
-- day its DHCP lease does.
--
-- is_secret marks a value stored encrypted under SECRET_KEY. The settings page shows those as
-- "set" or "not set" and never reads one back, which is why an empty submission means "leave it
-- alone" rather than "clear it".
CREATE TABLE IF NOT EXISTS settings (
  name       VARCHAR(64) NOT NULL PRIMARY KEY,
  value      LONGTEXT NULL,
  is_secret  TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  remote_addr     VARCHAR(64) NULL,

  -- Read out of the envelope for the list view. Nothing routes on any of it — this proxy has exactly
  -- one place to send anything — so an envelope missing all of it is still forwarded.
  event           VARCHAR(32) NULL,
  instance        VARCHAR(190) NULL,
  task_id         INT UNSIGNED NULL,
  chain_id        INT UNSIGNED NULL,
  role_slug       VARCHAR(64) NULL,

  -- Whether it left this machine at all. A refusal here never reaches the worker; everything else is
  -- the worker's own answer, relayed untouched.
  forwarded       TINYINT(1) NOT NULL DEFAULT 0,
  reason          VARCHAR(190) NULL,

  target_url      VARCHAR(500) NULL,
  body            LONGTEXT NULL,
  response_status INT NULL,
  response_body   LONGTEXT NULL,
  transport_error VARCHAR(255) NULL,
  duration_ms     INT UNSIGNED NULL,

  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_deliveries_created (created_at),
  KEY idx_deliveries_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions for the local UI. In the database rather than in files so that clearing PHP's temp
-- directory does not sign you out, and so a second installation on the same host keeps its own.
CREATE TABLE IF NOT EXISTS sessions (
  id          VARCHAR(128) NOT NULL,
  payload     MEDIUMTEXT NOT NULL,
  last_active INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sessions_last_active (last_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
