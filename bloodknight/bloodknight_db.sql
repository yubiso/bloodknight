CREATE DATABASE IF NOT EXISTS bloodknight_db;
USE bloodknight_db;

-- --- RESET: DROP OLD TABLES & VIEWS ---
SET FOREIGN_KEY_CHECKS = 0;
-- Drop Views
DROP VIEW IF EXISTS view_donation_history;
DROP VIEW IF EXISTS view_upcoming_appointments;
DROP VIEW IF EXISTS view_donor_profile;
DROP VIEW IF EXISTS view_hospital_directory;
DROP VIEW IF EXISTS view_active_drives;
DROP VIEW IF EXISTS view_urgent_alerts;
-- Drop Tables
DROP TABLE IF EXISTS appointment;
DROP TABLE IF EXISTS notification;
DROP TABLE IF EXISTS blood_drive;
DROP TABLE IF EXISTS donor_user;
DROP TABLE IF EXISTS hospital;
SET FOREIGN_KEY_CHECKS = 1;

-- ===============================================================
-- 1. TABLES (ENTITIES)
-- ===============================================================

-- 1. Entity: USER (Donors)
CREATE TABLE IF NOT EXISTS donor_user (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    blood_type VARCHAR(5),
    phone_number VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Entity: HOSPITAL (Healthcare Institutions)
CREATE TABLE IF NOT EXISTS hospital (
    hospital_id INT AUTO_INCREMENT PRIMARY KEY,
    hospital_name VARCHAR(255) NOT NULL,
    hospital_address TEXT NOT NULL,
    contact_number VARCHAR(20),
    hospital_type ENUM('Government Hospital', 'Private Hospital', 'Specialist Center', 'Blood Bank', 'Clinic') NOT NULL,
    admin_name VARCHAR(100) NOT NULL,
    admin_email VARCHAR(100) NOT NULL UNIQUE,
    admin_phone VARCHAR(20),
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. Entity: BLOOD_DRIVE
CREATE TABLE IF NOT EXISTS blood_drive (
    drive_id INT AUTO_INCREMENT PRIMARY KEY,
    hospital_id INT NOT NULL,
    drive_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    location_name VARCHAR(150),
    coordinates VARCHAR(100),
    status ENUM('Upcoming', 'Active', 'Completed') DEFAULT 'Upcoming',
    FOREIGN KEY (hospital_id) REFERENCES hospital(hospital_id) ON DELETE CASCADE
);

-- 4. Entity: APPOINTMENT
CREATE TABLE IF NOT EXISTS appointment (
    appt_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    drive_id INT NOT NULL,
    selected_time TIME NOT NULL,
    status ENUM('Pending', 'Confirmed', 'Completed', 'Cancelled', 'Did Not Show') DEFAULT 'Pending',
    
    -- HISTORY COLUMNS (Only filled if status='Completed')
    donation_date DATE NULL, 
    volume_ml INT NULL,
    notes TEXT NULL,
    
    FOREIGN KEY (user_id) REFERENCES donor_user(user_id) ON DELETE CASCADE,
    FOREIGN KEY (drive_id) REFERENCES blood_drive(drive_id) ON DELETE CASCADE,
    UNIQUE INDEX unique_slot (drive_id, selected_time)
);

-- 5. Entity: NOTIFICATION
CREATE TABLE IF NOT EXISTS notification (
    alert_id INT AUTO_INCREMENT PRIMARY KEY,
    hospital_id INT NOT NULL,
    target_blood_type VARCHAR(5),
    message_content TEXT NOT NULL,
    urgency_level ENUM('Low', 'High', 'Critical') DEFAULT 'High',
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (hospital_id) REFERENCES hospital(hospital_id) ON DELETE CASCADE
);

-- ===============================================================
-- 2. VIEWS (VIRTUAL TABLES FOR APP LOGIC)
-- ===============================================================

-- VIEW A: DONOR PROFILE (Safe Public View)
-- Hides passwords, useful for displaying profile info on the app
CREATE VIEW view_donor_profile AS
SELECT 
    user_id,
    full_name,
    email,
    blood_type,
    phone_number,
    created_at
FROM donor_user;

-- VIEW B: HOSPITAL DIRECTORY
-- Lists hospitals without exposing admin login credentials
CREATE VIEW view_hospital_directory AS
SELECT 
    hospital_id,
    hospital_name,
    hospital_address,
    hospital_type,
    contact_number,
    admin_email AS public_inquiry_email
FROM hospital;

-- VIEW C: ACTIVE BLOOD DRIVES
-- Shows drives that are Upcoming/Active, joined with Hospital Name
CREATE VIEW view_active_drives AS
SELECT 
    bd.drive_id,
    h.hospital_name,
    bd.location_name,
    bd.drive_date,
    bd.start_time,
    bd.end_time,
    bd.status
FROM blood_drive bd
JOIN hospital h ON bd.hospital_id = h.hospital_id
WHERE bd.status IN ('Upcoming', 'Active') 
AND bd.drive_date >= CURDATE();

-- VIEW D: URGENT ALERTS
-- Shows notifications, sorted by Urgency (Critical first), joined with Hospital
CREATE VIEW view_urgent_alerts AS
SELECT 
    n.alert_id,
    h.hospital_name,
    n.target_blood_type,
    n.message_content,
    n.urgency_level,
    n.sent_at
FROM notification n
JOIN hospital h ON n.hospital_id = h.hospital_id
ORDER BY FIELD(n.urgency_level, 'Critical', 'High', 'Low'), n.sent_at DESC;

-- VIEW E: DONATION HISTORY (Past & Completed Only)
CREATE VIEW view_donation_history AS
SELECT 
    a.appt_id,
    a.user_id,
    u.full_name,
    u.blood_type,
    a.donation_date,
    a.volume_ml,
    h.hospital_name
FROM appointment a
JOIN donor_user u ON a.user_id = u.user_id
JOIN blood_drive bd ON a.drive_id = bd.drive_id
JOIN hospital h ON bd.hospital_id = h.hospital_id
WHERE a.status = 'Completed';

-- VIEW F: UPCOMING APPOINTMENTS (User Schedule)
CREATE OR REPLACE VIEW view_upcoming_appointments AS
SELECT 
    a.appt_id,
    a.user_id,
    u.full_name,
    u.blood_type, -- Added this
    bd.drive_date,
    a.selected_time,
    bd.location_name,
    a.status,
    a.source      -- Added this (requires column to exist first)
FROM appointment a
JOIN donor_user u ON a.user_id = u.user_id
JOIN blood_drive bd ON a.drive_id = bd.drive_id
WHERE a.status IN ('Pending', 'Confirmed') 
AND bd.drive_date >= CURDATE();
-- ===============================================================
-- 3. TEST DATA
-- ===============================================================

-- Sample Donors
INSERT INTO donor_user (email, password_hash, full_name, blood_type, phone_number) VALUES 
('soldier1@example.com', 'hash123', 'John Doe', 'A+', '012-1111111'),
('soldier2@example.com', 'hash123', 'Jane Smith', 'O-', '012-2222222'),
('sarah@example.com', 'hash123', 'Sarah Jenkins', 'O+', '012-3333333');

-- Sample Hospital
INSERT INTO hospital (hospital_name, hospital_address, contact_number, hospital_type, admin_name, admin_email, admin_phone, password_hash) VALUES 
('Queen Elizabeth Hospital', 'Kota Kinabalu, Sabah', '088-324600', 'Government Hospital', 'Dr. Ahmad', 'admin@qeh.gov.my', '012-3456789', 'hash123'),
('KPJ Sabah', 'Damai, KK', '088-322000', 'Private Hospital', 'Dr. Siti', 'admin@kpj.com', '012-9999999', 'hash123');

-- Sample Drives
INSERT INTO blood_drive (hospital_id, drive_date, start_time, end_time, location_name, status) VALUES 
(1, CURDATE() + INTERVAL 5 DAY, '09:00:00', '17:00:00', 'City Hall Ops Center', 'Upcoming'), -- Future Drive (ID 1)
(1, CURDATE() - INTERVAL 100 DAY, '08:30:00', '15:30:00', 'Suria Sabah Shopping Mall', 'Completed'); -- Past Drive (ID 2)

-- Sample Appointments
INSERT INTO appointment (user_id, drive_id, selected_time, status, donation_date, volume_ml, notes) VALUES 
(1, 1, '09:30:00', 'Pending', NULL, NULL, NULL), -- Future
(3, 2, '11:00:00', 'Completed', CURDATE() - INTERVAL 100 DAY, 450, 'Success'); -- History

-- Sample Notifications
INSERT INTO notification (hospital_id, target_blood_type, message_content, urgency_level) VALUES 
(1, 'O-', 'Emergency O- needed immediately', 'Critical'),
(2, 'AB+', 'Low stock alert for AB+', 'High');

ALTER TABLE appointment ADD COLUMN source VARCHAR(20) DEFAULT 'Online';
ALTER TABLE donor_user ADD COLUMN last_donation_date DATE NULL;

