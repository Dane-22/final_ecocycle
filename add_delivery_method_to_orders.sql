-- Add delivery_method column to Orders table
ALTER TABLE `Orders` ADD COLUMN `delivery_method` ENUM('delivery', 'pickup') NOT NULL DEFAULT 'delivery' AFTER `payment_method`;
