-- Add delivery_method column to Orders table
ALTER TABLE `orders` ADD COLUMN `delivery_method` VARCHAR(50) DEFAULT 'delivery' AFTER `payment_method`;
