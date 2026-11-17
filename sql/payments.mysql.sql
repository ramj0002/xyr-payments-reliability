-- Tables added to XYR's existing MySQL database (products, product_variants
-- and sales already exist). Money is stored in integer cents.

CREATE TABLE IF NOT EXISTS payment_orders (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  user_id               INT NOT NULL,
  status                ENUM('pending','paid','failed','quarantined') NOT NULL DEFAULT 'pending',
  amount_cents          INT NOT NULL,
  currency              CHAR(3) NOT NULL,
  stripe_session_id     VARCHAR(255) NULL UNIQUE,
  stripe_payment_intent VARCHAR(255) NULL,
  paid_at               DATETIME NULL,
  created_at            DATETIME NOT NULL,
  updated_at            DATETIME NOT NULL,
  INDEX idx_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_order_items (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  order_id          INT NOT NULL,
  product_id        INT NOT NULL,
  size              VARCHAR(50) NOT NULL,
  quantity          INT NOT NULL,
  unit_amount_cents INT NOT NULL,
  FOREIGN KEY (order_id) REFERENCES payment_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per Stripe event ever applied: makes webhook handling idempotent.
CREATE TABLE IF NOT EXISTS payment_events (
  event_id    VARCHAR(255) PRIMARY KEY,
  type        VARCHAR(100) NOT NULL,
  outcome     VARCHAR(30) NULL,
  received_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payments that didn't match their order. Nothing here touches stock or sales
-- until a person resolves it.
CREATE TABLE IF NOT EXISTS payment_quarantine (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  order_id    INT NULL,
  reference   VARCHAR(255) NOT NULL,
  reason      VARCHAR(60) NOT NULL,
  details     JSON NULL,
  created_at  DATETIME NOT NULL,
  resolved_at DATETIME NULL,
  UNIQUE KEY uniq_reference_reason (reference, reason)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
