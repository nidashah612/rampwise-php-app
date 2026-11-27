-- RampWise schema (SQLite). Run via: php db/seed.php --reset
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS buildings (
  id   INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS routes (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  from_id       INTEGER NOT NULL REFERENCES buildings(id),
  to_id         INTEGER NOT NULL REFERENCES buildings(id),
  distance_m    INTEGER NOT NULL CHECK (distance_m BETWEEN 10 AND 2000),
  steps         INTEGER NOT NULL DEFAULT 0 CHECK (steps >= 0),
  slope         TEXT    NOT NULL CHECK (slope IN ('flat','gentle','steep')),
  has_lift      INTEGER NOT NULL DEFAULT 0 CHECK (has_lift IN (0,1)),
  covered       INTEGER NOT NULL DEFAULT 0 CHECK (covered IN (0,1)),
  status        TEXT    NOT NULL DEFAULT 'open' CHECK (status IN ('open','closed')),
  closed_reason TEXT,
  closed_by     TEXT,            -- session id of the reporter (NULL = seeded)
  owner_uid     TEXT,            -- session id of the creator (NULL = seeded, read-only)
  created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CHECK (from_id <> to_id)
);

CREATE TABLE IF NOT EXISTS reports (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  route_id     INTEGER NOT NULL REFERENCES routes(id) ON DELETE CASCADE,
  reason       TEXT NOT NULL,
  reporter_uid TEXT NOT NULL,
  created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_routes_pair ON routes(from_id, to_id);
