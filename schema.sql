-- Database Schema for Registration System
-- Target Database: MySQL

-- Create Database and select it
CREATE DATABASE IF NOT EXISTS `registration_db`;
USE `registration_db`;

-- Drop tables if they exist to allow clean re-runs (respecting foreign key constraints)
DROP TABLE IF EXISTS `students`;
DROP TABLE IF EXISTS `users`;

-- 1. Create the `users` table
CREATE TABLE `users` (
    `user_id` INT AUTO_INCREMENT,
    `username` VARCHAR(50) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('super_admin', 'staff') NOT NULL DEFAULT 'staff',
    PRIMARY KEY (`user_id`),
    UNIQUE KEY `idx_unique_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default super_admin account
-- Username: 'Beetacomsuperadmin'
-- Password: 'Beetaacommri1971' (Hashed using PHP's password_hash() with PASSWORD_DEFAULT)
INSERT INTO `users` (`username`, `password_hash`, `role`) VALUES (
    'Beetacomsuperadmin', 
    '$2y$10$BNsaoK3efdbTFeJvTJOSpOdOZcRI1.flhzpZTbeJcgTmUTAeCNc/K', 
    'super_admin'
);

-- 2. Create the `students` table
CREATE TABLE `students` (
    `id` INT AUTO_INCREMENT,
    `course_code` VARCHAR(10) NOT NULL,
    `batch_year` VARCHAR(4) NOT NULL,
    `is_nvq` VARCHAR(5) DEFAULT NULL,
    `sequence_number` INT NOT NULL,
    `index_number` VARCHAR(50) NOT NULL,
    `is_historical` TINYINT(1) NOT NULL DEFAULT 0,
    `registration_date` DATE NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `address` TEXT NOT NULL,
    `contact_no` VARCHAR(10) NOT NULL,
    `nic` VARCHAR(12) NOT NULL,
    `dob` DATE NOT NULL,
    `gender` ENUM('Male', 'Female') NOT NULL,
    `guardian_name` VARCHAR(100) DEFAULT NULL,
    `guardian_details` TEXT DEFAULT NULL,
    `added_by` INT DEFAULT NULL,
    
    -- Educational Qualifications (boolean/TINYINT)
    `gce_al_science` TINYINT(1) NOT NULL DEFAULT 0,
    `gce_al_maths` TINYINT(1) NOT NULL DEFAULT 0,
    `gce_al_commerce` TINYINT(1) NOT NULL DEFAULT 0,
    `gce_al_art` TINYINT(1) NOT NULL DEFAULT 0,
    `gce_al_tech` TINYINT(1) NOT NULL DEFAULT 0,
    `gce_ol` TINYINT(1) NOT NULL DEFAULT 0,
    `other_edu` TINYINT(1) NOT NULL DEFAULT 0,
    `kids_grade` TINYINT(1) NOT NULL DEFAULT 0,
    
    -- NVQ Courses (boolean/TINYINT)
    `ict_tech` TINYINT(1) NOT NULL DEFAULT 0,
    `computer_app_ast` TINYINT(1) NOT NULL DEFAULT 0,
    `graphic_designer` TINYINT(1) NOT NULL DEFAULT 0,
    `pre_school` TINYINT(1) NOT NULL DEFAULT 0,
    
    -- Non-NVQ Courses (boolean/TINYINT)
    `non_nvq_app_ast` TINYINT(1) NOT NULL DEFAULT 0,
    `non_nvq_graphic` TINYINT(1) NOT NULL DEFAULT 0,
    `hr` TINYINT(1) NOT NULL DEFAULT 0,
    `english` TINYINT(1) NOT NULL DEFAULT 0,
    `web_design` TINYINT(1) NOT NULL DEFAULT 0,
    `beetaa_kids` TINYINT(1) NOT NULL DEFAULT 0,
    `other_course` TINYINT(1) NOT NULL DEFAULT 0,
    
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_unique_index_number` (`index_number`),
    
    -- Constraints & Foreign Keys
    CONSTRAINT `fk_students_added_by` FOREIGN KEY (`added_by`) 
        REFERENCES `users` (`user_id`) 
        ON DELETE SET NULL 
        ON UPDATE CASCADE,
        
    -- Strictly enforce 12-digit NIC format (only digits, length 12)
    CONSTRAINT `chk_nic_format` CHECK (`nic` REGEXP '^[0-9]{12}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Create the `payment_plans` table
CREATE TABLE IF NOT EXISTS `payment_plans` (
    `plan_id` INT AUTO_INCREMENT,
    `student_id` INT NOT NULL,
    `plan_type` ENUM('full', 'installment', 'pending') NOT NULL DEFAULT 'pending',
    `base_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `final_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (`plan_id`),
    CONSTRAINT `fk_payment_plans_student` FOREIGN KEY (`student_id`) 
        REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Create the `payment_records` table
CREATE TABLE IF NOT EXISTS `payment_records` (
    `receipt_id` INT AUTO_INCREMENT,
    `student_id` INT NOT NULL,
    `amount_paid` DECIMAL(10,2) NOT NULL,
    `payment_date` DATE NOT NULL,
    `installment_number` INT NOT NULL,
    PRIMARY KEY (`receipt_id`),
    CONSTRAINT `fk_payment_records_student` FOREIGN KEY (`student_id`) 
        REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
