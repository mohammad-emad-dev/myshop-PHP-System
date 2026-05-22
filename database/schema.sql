-- Inventory and Order Management System (IOMS)
-- Database Schema
-- Created: 2025-12-22
DROP DATABASE IF EXISTS ioms_db;
CREATE DATABASE ioms_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ioms_db;
-- ========================================
-- Table: Staff
-- Purpose: Store user authentication data
-- ========================================
CREATE TABLE Staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username (username)
) ENGINE = InnoDB;
-- ========================================
-- Table: Product
-- Purpose: Store inventory item details
-- ========================================
CREATE TABLE Product (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    stock INT NOT NULL DEFAULT 0,
    image_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_stock (stock)
) ENGINE = InnoDB;
-- ========================================
-- Table: Order
-- Purpose: Store general order information
-- ========================================
CREATE TABLE `Order` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    total_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    staff_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES Staff(id) ON DELETE RESTRICT,
    INDEX idx_order_date (order_date),
    INDEX idx_staff_id (staff_id)
) ENGINE = InnoDB;
-- ========================================
-- Table: OrderDetail
-- Purpose: Store items linked to each order
-- ========================================
CREATE TABLE OrderDetail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10, 2) NOT NULL,
    subtotal DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES `Order`(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES Product(id) ON DELETE RESTRICT,
    INDEX idx_order_id (order_id),
    INDEX idx_product_id (product_id)
) ENGINE = InnoDB;
-- ========================================
-- Sample Data
-- ========================================
-- Insert default admin user (password: admin123)
INSERT INTO Staff (username, password, full_name)
VALUES (
        'admin',
        '$2y$10$FBOb02FOQBMm3GKVr9dQye1H2X8xys7qvLddk2qT.GzoL1CBBsV.6',
        'System Administrator'
    ),
    (
        'john',
        '$2y$10$FBOb02FOQBMm3GKVr9dQye1H2X8xys7qvLddk2qT.GzoL1CBBsV.6',
        'John Smith'
    ),
    (
        'sarah',
        '$2y$10$FBOb02FOQBMm3GKVr9dQye1H2X8xys7qvLddk2qT.GzoL1CBBsV.6',
        'Sarah Johnson'
    );
-- Insert sample products
INSERT INTO Product (name, description, price, stock, image_path)
VALUES (
        'Wireless Mouse',
        'Ergonomic wireless mouse with 2.4GHz connectivity',
        29.99,
        150,
        NULL
    ),
    (
        'Mechanical Keyboard',
        'RGB backlit mechanical gaming keyboard',
        89.99,
        75,
        NULL
    ),
    (
        'USB-C Hub',
        '7-in-1 USB-C multiport adapter',
        45.50,
        200,
        NULL
    ),
    (
        'Laptop Stand',
        'Adjustable aluminum laptop stand',
        34.99,
        120,
        NULL
    ),
    (
        'Webcam HD',
        '1080p webcam with built-in microphone',
        59.99,
        90,
        NULL
    ),
    (
        'External SSD 1TB',
        'Portable solid state drive with USB 3.1',
        119.99,
        60,
        NULL
    ),
    (
        'Wireless Headset',
        'Bluetooth noise-cancelling headset',
        79.99,
        85,
        NULL
    ),
    (
        'Monitor 24"',
        'Full HD IPS display with HDMI',
        189.99,
        45,
        NULL
    ),
    (
        'Cable Management Kit',
        'Desktop cable organizer set',
        15.99,
        300,
        NULL
    ),
    (
        'Phone Holder',
        'Adjustable desk phone mount',
        12.99,
        250,
        NULL
    );
-- Insert sample orders
INSERT INTO `Order` (order_date, total_amount, staff_id)
VALUES ('2025-12-20 10:30:00', 119.98, 1),
    ('2025-12-21 14:15:00', 279.97, 2),
    ('2025-12-22 09:45:00', 165.48, 1);
-- Insert order details
INSERT INTO OrderDetail (
        order_id,
        product_id,
        quantity,
        unit_price,
        subtotal
    )
VALUES (1, 1, 2, 29.99, 59.98),
    (1, 3, 1, 45.50, 45.50),
    (1, 9, 1, 15.99, 15.99),
    (2, 2, 1, 89.99, 89.99),
    (2, 8, 1, 189.99, 189.99),
    (3, 6, 1, 119.99, 119.99),
    (3, 7, 1, 79.99, 79.99);
-- Update order totals (recalculate from order details)
UPDATE `Order` o
SET total_amount = (
        SELECT SUM(subtotal)
        FROM OrderDetail
        WHERE order_id = o.id
    );