-- OPTCG Preorder Watcher - database schema (MySQL 5.7+ / MariaDB 10.2+)
-- Run once via install.php or import in phpMyAdmin.

CREATE TABLE IF NOT EXISTS shops (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(100) NOT NULL,
  country         CHAR(2)      NOT NULL DEFAULT 'DE',
  platform        VARCHAR(20)  NOT NULL DEFAULT 'auto',  -- auto | shopify | woocommerce | jsonld | html
  base_url        VARCHAR(255) NOT NULL,
  config          TEXT         NULL,
  active          TINYINT(1)   NOT NULL DEFAULT 1,
  last_run_at     DATETIME     NULL,
  last_ok_at      DATETIME     NULL,
  last_error      TEXT         NULL,
  last_raw_count  INT          NULL,
  last_item_count INT          NULL,
  fail_streak     INT          NOT NULL DEFAULT 0,       -- failed runs in a row; auto-paused at 20
  UNIQUE KEY uq_base_url (base_url(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id          INT UNSIGNED NOT NULL,
  external_id      VARCHAR(255) NOT NULL,
  title            VARCHAR(500) NOT NULL,
  url              VARCHAR(1000) NOT NULL,
  image            VARCHAR(1000) NULL,
  set_code         VARCHAR(10)  NULL,
  product_type     VARCHAR(10)  NOT NULL,
  status           VARCHAR(20)  NOT NULL,          -- preorder | available | sold_out | not_orderable
  hidden           TINYINT(1)   NOT NULL DEFAULT 0, -- hidden by you ("not orderable" button)
  price            DECIMAL(10,2) NULL,
  currency         CHAR(3)      NOT NULL DEFAULT 'EUR',
  first_seen       DATETIME     NOT NULL,
  last_seen        DATETIME     NOT NULL,
  active           TINYINT(1)   NOT NULL DEFAULT 1,
  last_alert_price DECIMAL(10,2) NULL,
  UNIQUE KEY uq_shop_ext (shop_id, external_id(190)),
  KEY idx_status (active, status),
  CONSTRAINT fk_products_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS price_history (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id  INT UNSIGNED NOT NULL,
  price       DECIMAL(10,2) NULL,
  status      VARCHAR(20)  NOT NULL,
  recorded_at DATETIME     NOT NULL,
  KEY idx_product (product_id, recorded_at),
  CONSTRAINT fk_history_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An alert is sent when an active product matches a rule and its price is <= max_price.
CREATE TABLE IF NOT EXISTS alert_rules (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label        VARCHAR(100) NOT NULL,
  match_text   VARCHAR(100) NOT NULL DEFAULT '',     -- e.g. 'OP-14' or 'Anniversary'; empty = all
  product_type VARCHAR(10)  NOT NULL DEFAULT 'any',  -- display | case | special | any
  country      VARCHAR(3)   NOT NULL DEFAULT 'any',  -- DE | NL | any
  statuses     VARCHAR(10)  NOT NULL DEFAULT 'preorder', -- preorder | available | both
  max_price    DECIMAL(10,2) NOT NULL,
  active       TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS runs (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  started_at   DATETIME NOT NULL,
  finished_at  DATETIME NOT NULL,
  shops_ok     INT NOT NULL DEFAULT 0,
  shops_failed INT NOT NULL DEFAULT 0,
  items        INT NOT NULL DEFAULT 0,
  alerts_sent  INT NOT NULL DEFAULT 0,
  message      TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(50)  PRIMARY KEY,
  v TEXT         NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip           VARCHAR(45) NOT NULL,
  attempted_at DATETIME    NOT NULL,
  KEY idx_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- seed data
INSERT IGNORE INTO settings (k, v) VALUES
  ('alert_email', ''),
  ('product_types', 'display,case,special');
