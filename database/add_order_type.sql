-- Add order_type column to Order table
-- This column tracks whether the order is a sale (stock deduction) or purchase (stock addition)
-- Run this SQL command in your MySQL database before using the new POS features
ALTER TABLE `Order`
ADD COLUMN order_type ENUM('sale', 'purchase') NOT NULL DEFAULT 'sale';