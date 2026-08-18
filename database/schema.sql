-- MyShop Inventory, POS, and Order Management System
-- Canonical base schema for MySQL/MariaDB.
--
-- Run this file against the already-created database selected by the
-- deployment command. It intentionally does not create, drop, or select a
-- database and contains no demo users, products, or orders.

SET NAMES utf8mb4;

CREATE TABLE Category (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_category_name UNIQUE (name)
) ENGINE = InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE Staff (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'cashier') NOT NULL DEFAULT 'cashier',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_staff_username UNIQUE (username),
    CONSTRAINT chk_staff_is_active CHECK (is_active IN (0, 1)),
    INDEX idx_staff_role (role),
    INDEX idx_staff_active (is_active),
    INDEX idx_staff_created_at (created_at)
) ENGINE = InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE LoginRateLimit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ip_address VARCHAR(45) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    failure_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    first_attempt_at DATETIME NOT NULL,
    last_attempt_at DATETIME NOT NULL,
    blocked_until DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_login_rate_failure_count CHECK (failure_count <= 5),
    CONSTRAINT uq_login_rate_account_ip UNIQUE (username_hash, ip_address),
    INDEX idx_login_rate_blocked_until (blocked_until),
    INDEX idx_login_rate_last_attempt (last_attempt_at)
) ENGINE = InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE Customer (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NULL,
    email VARCHAR(100) NULL,
    address TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer_name (name)
) ENGINE = InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE Supplier (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NULL,
    email VARCHAR(100) NULL,
    address TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_supplier_name (name)
) ENGINE = InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE Product (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    stock INT NOT NULL DEFAULT 0,
    image_path VARCHAR(255) NULL,
    alert_threshold INT NOT NULL DEFAULT 10,
    category_id INT UNSIGNED NULL,
    barcode VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_product_price_nonnegative CHECK (price >= 0),
    CONSTRAINT chk_product_stock_nonnegative CHECK (stock >= 0),
    CONSTRAINT chk_product_alert_threshold_nonnegative CHECK (alert_threshold >= 0),
    CONSTRAINT uq_product_barcode UNIQUE (barcode),
    CONSTRAINT fk_product_category
        FOREIGN KEY (category_id) REFERENCES Category(id) ON DELETE SET NULL,
    INDEX idx_product_name (name),
    INDEX idx_product_stock (stock),
    INDEX idx_product_category (category_id)
) ENGINE = InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE `Order` (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    total_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    staff_id INT UNSIGNED NOT NULL,
    order_type ENUM('sale', 'purchase') NOT NULL DEFAULT 'sale',
    customer_id INT UNSIGNED NULL,
    supplier_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_order_total_nonnegative CHECK (total_amount >= 0),
    CONSTRAINT fk_order_staff
        FOREIGN KEY (staff_id) REFERENCES Staff(id) ON DELETE RESTRICT,
    CONSTRAINT fk_order_customer
        FOREIGN KEY (customer_id) REFERENCES Customer(id) ON DELETE SET NULL,
    CONSTRAINT fk_order_supplier
        FOREIGN KEY (supplier_id) REFERENCES Supplier(id) ON DELETE SET NULL,
    INDEX idx_order_date (order_date),
    INDEX idx_order_date_type (order_date, order_type),
    INDEX idx_order_staff (staff_id),
    INDEX idx_order_customer (customer_id),
    INDEX idx_order_supplier (supplier_id)
) ENGINE = InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE OrderDetail (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10, 2) NOT NULL,
    subtotal DECIMAL(10, 2) NOT NULL,
    CONSTRAINT chk_order_detail_quantity_positive CHECK (quantity > 0),
    CONSTRAINT chk_order_detail_unit_price_nonnegative CHECK (unit_price >= 0),
    CONSTRAINT chk_order_detail_subtotal_nonnegative CHECK (subtotal >= 0),
    CONSTRAINT fk_order_detail_order
        FOREIGN KEY (order_id) REFERENCES `Order`(id) ON DELETE RESTRICT,
    CONSTRAINT fk_order_detail_product
        FOREIGN KEY (product_id) REFERENCES Product(id) ON DELETE RESTRICT,
    INDEX idx_order_detail_order (order_id),
    INDEX idx_order_detail_product (product_id)
) ENGINE = InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE StockMovement (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    staff_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    movement_type ENUM('sale', 'purchase', 'manual_adjustment') NOT NULL,
    reason TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_stock_movement_product
        FOREIGN KEY (product_id) REFERENCES Product(id) ON DELETE RESTRICT,
    CONSTRAINT fk_stock_movement_staff
        FOREIGN KEY (staff_id) REFERENCES Staff(id) ON DELETE RESTRICT,
    INDEX idx_stock_movement_product_created (product_id, created_at, id),
    INDEX idx_stock_movement_staff (staff_id),
    INDEX idx_stock_movement_created (created_at, id)
) ENGINE = InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- Required system records used by the current application fallback behavior.
-- These are not demo data and contain no credentials.
INSERT INTO Category (id, name, description)
VALUES (1, 'General', 'Default classification for uncategorized products');

INSERT INTO Customer (id, name, phone, email, address)
VALUES (1, 'Walk-in Customer', '0000000000', 'walkin@myshop.com', 'N/A');

INSERT INTO Supplier (id, name, phone, email, address)
VALUES (1, 'General Supplier', '0000000000', 'supplier@myshop.com', 'N/A');
