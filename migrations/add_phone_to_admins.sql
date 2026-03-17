-- Add phone_number column to admins table
ALTER TABLE `admins` ADD COLUMN `phone_number` varchar(20) DEFAULT NULL AFTER `email`;

-- Update the ECSD head record with a phone number
UPDATE `admins` SET `phone_number` = '09123456789' WHERE `username` = 'ecsdhead';
