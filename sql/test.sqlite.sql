-- SQLite schema for the test suite: the XYR tables this layer touches, plus
-- the payment tables from payments.mysql.sql.

CREATE TABLE products (
  id       INTEGER PRIMARY KEY,
  name     TEXT NOT NULL,
  price    TEXT NOT NULL,
  category TEXT NOT NULL
);

CREATE TABLE product_variants (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  product_id INTEGER NOT NULL,
  size       TEXT NOT NULL,
  stock      INTEGER NOT NULL,
  UNIQUE (product_id, size)
);

CREATE TABLE sales (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  product_id    INTEGER NOT NULL,
  quantity_sold INTEGER NOT NULL,
  sale_price    TEXT NOT NULL,
  size          TEXT,
  sale_date     TEXT NOT NULL
);

CREATE TABLE payment_orders (
  id                    INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id               INTEGER NOT NULL,
  status                TEXT NOT NULL DEFAULT 'pending',
  amount_cents          INTEGER NOT NULL,
  currency              TEXT NOT NULL,
  stripe_session_id     TEXT UNIQUE,
  stripe_payment_intent TEXT,
  paid_at               TEXT,
  created_at            TEXT NOT NULL,
  updated_at            TEXT NOT NULL
);

CREATE TABLE payment_order_items (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id          INTEGER NOT NULL REFERENCES payment_orders(id),
  product_id        INTEGER NOT NULL,
  size              TEXT NOT NULL,
  quantity          INTEGER NOT NULL,
  unit_amount_cents INTEGER NOT NULL
);

CREATE TABLE payment_events (
  event_id    TEXT PRIMARY KEY,
  type        TEXT NOT NULL,
  outcome     TEXT,
  received_at TEXT NOT NULL
);

CREATE TABLE payment_quarantine (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id    INTEGER,
  reference   TEXT NOT NULL,
  reason      TEXT NOT NULL,
  details     TEXT,
  created_at  TEXT NOT NULL,
  resolved_at TEXT,
  UNIQUE (reference, reason)
);
