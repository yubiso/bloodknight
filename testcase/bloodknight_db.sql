CREATE DATABASE IF NOT EXISTS bloodknight_db;
USE bloodknight_db;

-- ===============================================================
-- 1. CLEAN SLATE & SCHEMA SETUP
-- ===============================================================

SET FOREIGN_KEY_CHECKS = 0;
DROP VIEW IF EXISTS view_donation_history;
DROP VIEW IF EXISTS view_upcoming_appointments;
DROP VIEW IF EXISTS view_donor_profile;
DROP VIEW IF EXISTS view_hospital_directory;
DROP VIEW IF EXISTS view_active_drives;
DROP VIEW IF EXISTS view_urgent_alerts;
DROP TABLE IF EXISTS blood_report;
DROP TABLE IF EXISTS appointment;
DROP TABLE IF EXISTS notification;
DROP TABLE IF EXISTS blood_drive;
DROP TABLE IF EXISTS donor_user;
DROP TABLE IF EXISTS hospital;
DROP TABLE IF EXISTS bk_admin;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. Entity: BLOODKIGHT ADMIN
CREATE TABLE IF NOT EXISTS bk_admin (
    admin_id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Entity: USER (Donors)
CREATE TABLE IF NOT EXISTS donor_user (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    ic_number VARCHAR(14) NOT NULL UNIQUE,
    blood_type VARCHAR(5),
    phone_number VARCHAR(20),
    gender VARCHAR(10) NULL,
    profile_pic VARCHAR(255) NULL,
    last_donation_date DATE NULL,
    status ENUM('Active', 'Inactive', 'Blacklisted') DEFAULT 'Active',
    blacklist_reason TEXT NULL,
    blacklisted_at TIMESTAMP NULL,
    blacklisted_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (blacklisted_by) REFERENCES bk_admin(admin_id) ON DELETE SET NULL
);

-- 3. Entity: HOSPITAL
CREATE TABLE IF NOT EXISTS hospital (
    hospital_id INT AUTO_INCREMENT PRIMARY KEY,
    hospital_name VARCHAR(255) NOT NULL,
    hospital_address TEXT NOT NULL,
    contact_number VARCHAR(20),
    hospital_type ENUM('Government Hospital', 'Private Hospital', 'Specialist Center', 'Blood Bank', 'Clinic') NOT NULL,
    admin_name VARCHAR(100) NOT NULL,
    admin_email VARCHAR(100) NOT NULL UNIQUE,
    admin_phone VARCHAR(20),
    password_hash VARCHAR(255) NULL, 
    status ENUM('Pending', 'Active', 'Inactive', 'Rejected', 'Blacklisted') DEFAULT 'Pending', 
    rejection_reason TEXT NULL, 
    blacklist_reason TEXT NULL, 
    blacklisted_at TIMESTAMP NULL, 
    blacklisted_by INT NULL, 
    processed_at TIMESTAMP NULL, 
    processed_by INT NULL, 
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (processed_by) REFERENCES bk_admin(admin_id) ON DELETE SET NULL,
    FOREIGN KEY (blacklisted_by) REFERENCES bk_admin(admin_id) ON DELETE SET NULL
);

-- 4. Entity: BLOOD_DRIVE
CREATE TABLE IF NOT EXISTS blood_drive (
    drive_id INT AUTO_INCREMENT PRIMARY KEY,
    hospital_id INT NOT NULL,
    drive_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    location_name VARCHAR(150),
    full_address TEXT NULL, 
    coordinates VARCHAR(100),
    status ENUM('Upcoming', 'Active', 'Completed') DEFAULT 'Upcoming',
    FOREIGN KEY (hospital_id) REFERENCES hospital(hospital_id) ON DELETE CASCADE
);

-- 5. Entity: APPOINTMENT
CREATE TABLE IF NOT EXISTS appointment (
    appt_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    drive_id INT NOT NULL,
    selected_time TIME NOT NULL,
    status ENUM('Pending', 'Confirmed', 'Completed', 'Cancelled', 'Did Not Show') DEFAULT 'Pending',
    donation_date DATE NULL, 
    volume_ml INT NULL,
    notes TEXT NULL,
    source VARCHAR(20) DEFAULT 'Online',
    FOREIGN KEY (user_id) REFERENCES donor_user(user_id) ON DELETE CASCADE,
    FOREIGN KEY (drive_id) REFERENCES blood_drive(drive_id) ON DELETE CASCADE,
    UNIQUE INDEX unique_slot (drive_id, selected_time)
);

-- 6. Entity: NOTIFICATION
CREATE TABLE IF NOT EXISTS notification (
    alert_id INT AUTO_INCREMENT PRIMARY KEY,
    hospital_id INT NOT NULL,
    target_blood_type VARCHAR(5),
    title VARCHAR(100) NULL,
    message_content TEXT NOT NULL,
    urgency_level ENUM('Low', 'High', 'Critical') DEFAULT 'High',
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (hospital_id) REFERENCES hospital(hospital_id) ON DELETE CASCADE
);

-- 7. Entity: BLOOD_REPORT
CREATE TABLE IF NOT EXISTS blood_report (
    report_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    appt_id INT NULL,
    report_date DATE NOT NULL,
    hemoglobin DECIMAL(5,2),
    hematocrit DECIMAL(5,2),
    platelet_count INT,
    volume_ml INT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES donor_user(user_id) ON DELETE CASCADE
);

-- ===============================================================
-- 2. VIEWS
-- ===============================================================

CREATE VIEW view_donor_profile AS SELECT user_id, full_name, email, blood_type, phone_number, created_at FROM donor_user;
CREATE VIEW view_hospital_directory AS SELECT hospital_id, hospital_name, hospital_address, hospital_type, contact_number, admin_email AS public_inquiry_email FROM hospital;
CREATE VIEW view_active_drives AS SELECT bd.drive_id, h.hospital_name, bd.location_name, bd.drive_date, bd.start_time, bd.end_time, bd.status FROM blood_drive bd JOIN hospital h ON bd.hospital_id = h.hospital_id WHERE bd.status IN ('Upcoming', 'Active') AND bd.drive_date >= CURDATE();
CREATE VIEW view_urgent_alerts AS SELECT n.alert_id, h.hospital_name, n.target_blood_type, n.message_content, n.urgency_level, n.sent_at FROM notification n JOIN hospital h ON n.hospital_id = h.hospital_id ORDER BY FIELD(n.urgency_level, 'Critical', 'High', 'Low'), n.sent_at DESC;
CREATE VIEW view_donation_history AS SELECT a.appt_id, a.user_id, u.full_name, u.blood_type, a.donation_date, a.volume_ml, h.hospital_name FROM appointment a JOIN donor_user u ON a.user_id = u.user_id JOIN blood_drive bd ON a.drive_id = bd.drive_id JOIN hospital h ON bd.hospital_id = h.hospital_id WHERE a.status = 'Completed';
CREATE VIEW view_upcoming_appointments AS SELECT a.appt_id, a.user_id, u.full_name, u.blood_type, bd.drive_date, a.selected_time, bd.location_name, a.status, a.source FROM appointment a JOIN donor_user u ON a.user_id = u.user_id JOIN blood_drive bd ON a.drive_id = bd.drive_id WHERE a.status IN ('Pending', 'Confirmed') AND bd.drive_date >= CURDATE();

-- ===============================================================
-- 3. DYNAMIC DATA INSERTION
-- ===============================================================

-- 3.1 VARIABLE SETUP
SET @START_DATE = '2024-01-01';
SET @END_DATE = DATE_ADD(CURDATE(), INTERVAL 3 MONTH);

-- 3.2 Insert Admin
INSERT INTO bk_admin (admin_id, email, password_hash, full_name) VALUES (1, 'bloodknight.about@gmail.com', '$2y$10$bjPj8LdSw1.FCOugboeXFe2TBh7P69ZiRo53uvwXWfv.Zq106WM6.', 'BloodKnight Admin') ON DUPLICATE KEY UPDATE email=VALUES(email);

-- 3.3 Insert Hospitals (16 Total)
INSERT INTO hospital (hospital_id, hospital_name, hospital_address, contact_number, hospital_type, admin_name, admin_email, admin_phone, password_hash, status) VALUES
(1, 'Queen Elizabeth Hospital', 'Kota Kinabalu', '088-324600', 'Government Hospital', 'Dr. Ahmad', 'admin@qeh.gov.my', '012-1111', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(2, 'KPJ Sabah Specialist', 'Kota Kinabalu', '088-322000', 'Private Hospital', 'Dr. Siti', 'admin@kpj.com', '012-2222', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(3, 'Gleneagles KK', 'Kota Kinabalu', '088-518888', 'Private Hospital', 'Dr. Lim', 'admin@glen.com', '012-3333', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(4, 'Sabah Women & Children', 'Likas', '088-315555', 'Government Hospital', 'Dr. Rosnah', 'admin@swch.gov.my', '012-4444', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(5, 'Tawau Hospital', 'Tawau', '089-773333', 'Government Hospital', 'Dr. Mohd', 'admin@tawau.gov.my', '012-5555', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(6, 'Sandakan Hospital', 'Sandakan', '089-221555', 'Government Hospital', 'Dr. James', 'admin@sandakan.gov.my', '012-6666', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(7, 'Keningau Hospital', 'Keningau', '087-331222', 'Government Hospital', 'Dr. Mary', 'admin@keningau.gov.my', '012-7777', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(8, 'Lahad Datu Hospital', 'Lahad Datu', '089-881222', 'Government Hospital', 'Dr. Chin', 'admin@ld.gov.my', '012-8888', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(9, 'Kudat Hospital', 'Kudat', '088-611333', 'Government Hospital', 'Dr. Evelyn', 'admin@kudat.gov.my', '012-9999', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(10, 'Beaufort Hospital', 'Beaufort', '087-211777', 'Government Hospital', 'Dr. Kamal', 'admin@beaufort.gov.my', '013-1111', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(11, 'Columbia Asia KK', 'Kota Kinabalu', '088-301000', 'Private Hospital', 'Ms. Janet', 'admin@cahkk.com', '013-2222', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(12, 'Rafflesia Medical', 'Penampang', '088-721000', 'Specialist Center', 'Dr. Patrick', 'admin@rafflesia.com', '013-3333', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(13, 'Semporna Hospital', 'Semporna', '089-781555', 'Government Hospital', 'Dr. Wong', 'admin@semporna.gov.my', '013-4444', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(14, 'Klinik Luyang', 'Luyang', '088-251001', 'Blood Bank', 'Ms. Norliza', 'admin@kkluyang.gov.my', '013-5555', NULL, 'Pending'),
(15, 'Tuaran Hospital', 'Tuaran', '088-788222', 'Government Hospital', 'Dr. Helen', 'admin@tuaran.gov.my', '013-6666', NULL, 'Pending'),
(16, 'Agent Clinic', 'Kota Kinabalu', '088-123456', 'Clinic', 'Dr. Ali', 'agentali14@gmail.com', '012-3456789', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active')
ON DUPLICATE KEY UPDATE hospital_name=VALUES(hospital_name), admin_email=VALUES(admin_email), status=VALUES(status);

-- 3.4 Generate MASSIVE Donor Pool (5000 Donors)
-- LOGIC: PRECISE Malaysia Blood Stats
-- O+: 34.32%, A+: 30.35%, B+: 27.37%, AB+: 7.46%, Rares < 1%
INSERT INTO donor_user (email, password_hash, full_name, ic_number, blood_type, phone_number, gender, status)
WITH RECURSIVE Donors AS (
    SELECT 1 AS id UNION ALL SELECT id + 1 FROM Donors WHERE id < 5000
)
SELECT
    CONCAT('donor', d.id, '@test.com'),
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    CONCAT('Sabah Donor ', d.id),
    CONCAT(LPAD(d.id, 6, '0'), '-', LPAD(d.id % 12 + 1, 2, '0'), '-', LPAD(d.id % 9000 + 1000, 4, '0')),
    CASE 
        WHEN (d.id * 7919) % 10000 < 3432 THEN 'O+'   -- 34.32%
        WHEN (d.id * 7919) % 10000 < 6467 THEN 'A+'   -- 30.35%
        WHEN (d.id * 7919) % 10000 < 9204 THEN 'B+'   -- 27.37%
        WHEN (d.id * 7919) % 10000 < 9950 THEN 'AB+'  -- 7.46%
        WHEN (d.id * 7919) % 10000 < 9967 THEN 'O-'   -- 0.17%
        WHEN (d.id * 7919) % 10000 < 9982 THEN 'A-'   -- 0.15%
        WHEN (d.id * 7919) % 10000 < 9996 THEN 'B-'   -- 0.14%
        ELSE 'AB-'                                    -- 0.04%
    END,
    CONCAT('01', FLOOR(2 + (d.id % 8)), '-', LPAD(d.id, 7, '0')),
    CASE (d.id % 2) WHEN 0 THEN 'Male' ELSE 'Female' END, 'Active'
FROM Donors d
ON DUPLICATE KEY UPDATE email=VALUES(email);

-- 3.5 Generate Agent Clinic Donors (Same Logic)
INSERT INTO donor_user (email, password_hash, full_name, ic_number, blood_type, phone_number, gender, status)
WITH RECURSIVE AgentDonors AS (
    SELECT 1 AS id UNION ALL SELECT id + 1 FROM AgentDonors WHERE id < 800
)
SELECT
    CONCAT('agent.donor', d.id, '@test.com'),
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    CONCAT('Agent Donor ', d.id),
    CONCAT(LPAD(d.id + 200000, 6, '0'), '-12-', LPAD(d.id + 2000, 4, '0')),
    CASE 
        WHEN (d.id * 7919) % 10000 < 3432 THEN 'O+' 
        WHEN (d.id * 7919) % 10000 < 6467 THEN 'A+' 
        WHEN (d.id * 7919) % 10000 < 9204 THEN 'B+' 
        WHEN (d.id * 7919) % 10000 < 9950 THEN 'AB+' 
        ELSE 'O-' -- Fallback
    END,
    CONCAT('013-', LPAD(d.id, 7, '0')),
    'Male', 'Active'
FROM AgentDonors d
ON DUPLICATE KEY UPDATE email=VALUES(email);

-- ===============================================================
-- 4. BLOOD DRIVE GENERATION (FULL HISTORY + THIS WEEK)
-- ===============================================================

-- 4.1 Tier 1: City Giants (Weekly)
INSERT INTO blood_drive (hospital_id, drive_date, start_time, end_time, location_name, status, full_address)
WITH RECURSIVE DateSeries AS (
    SELECT @START_DATE AS drive_date, 1 AS rn
    UNION ALL
    SELECT DATE_ADD(drive_date, INTERVAL 3 DAY), rn + 1 FROM DateSeries WHERE DATE_ADD(drive_date, INTERVAL 3 DAY) <= @END_DATE
)
SELECT 
    h.hospital_id,
    ds.drive_date,
    '06:00:00', '22:00:00',
    CONCAT(h.hospital_name, ' Mega Drive'),
    CASE WHEN ds.drive_date < CURDATE() THEN 'Completed' ELSE 'Upcoming' END,
    h.hospital_address
FROM DateSeries ds
JOIN hospital h ON h.hospital_id IN (1, 4, 16);

-- 4.2 Tier 2: Towns (Bi-Weekly)
INSERT INTO blood_drive (hospital_id, drive_date, start_time, end_time, location_name, status, full_address)
WITH RECURSIVE DateSeries AS (
    SELECT @START_DATE AS drive_date, 1 AS rn
    UNION ALL
    SELECT DATE_ADD(drive_date, INTERVAL 7 DAY), rn + 1 FROM DateSeries WHERE DATE_ADD(drive_date, INTERVAL 7 DAY) <= @END_DATE
)
SELECT 
    h.hospital_id,
    ds.drive_date,
    '08:00:00', '18:00:00',
    CONCAT(h.hospital_name, ' Community Event'),
    CASE WHEN ds.drive_date < CURDATE() THEN 'Completed' ELSE 'Upcoming' END,
    h.hospital_address
FROM DateSeries ds
JOIN hospital h ON h.hospital_id IN (2, 3, 5, 6, 11, 12, 14, 15);

-- 4.3 Tier 3: Rural (Monthly)
INSERT INTO blood_drive (hospital_id, drive_date, start_time, end_time, location_name, status, full_address)
WITH RECURSIVE DateSeries AS (
    SELECT @START_DATE AS drive_date, 1 AS rn
    UNION ALL
    SELECT DATE_ADD(drive_date, INTERVAL 7 DAY), rn + 1 FROM DateSeries WHERE DATE_ADD(drive_date, INTERVAL 7 DAY) <= @END_DATE
)
SELECT 
    h.hospital_id,
    ds.drive_date,
    '09:00:00', '15:00:00',
    CONCAT(h.hospital_name, ' Rural Outreach'),
    CASE WHEN ds.drive_date < CURDATE() THEN 'Completed' ELSE 'Upcoming' END,
    h.hospital_address
FROM DateSeries ds
JOIN hospital h ON h.hospital_id IN (7, 8, 9, 10, 13);

-- 4.4 SPECIAL LOGIC: AGENT CLINIC REAL LOCATIONS (Kota Kinabalu)
UPDATE blood_drive 
SET location_name = CASE (drive_id % 7)
    WHEN 0 THEN 'Imago Shopping Mall'
    WHEN 1 THEN 'Suria Sabah Shopping Mall'
    WHEN 2 THEN '1Borneo Hypermall'
    WHEN 3 THEN 'City Mall Shopping Center'
    WHEN 4 THEN 'Megalong Shopping Mall'
    WHEN 5 THEN 'Kompleks Karamunsing'
    ELSE 'Centre Point Sabah'
END,
full_address = CASE (drive_id % 7)
    WHEN 0 THEN 'Imago Shopping Mall, KK Times Square, Phase 2, Off Coastal Highway, 88100 Kota Kinabalu, Sabah'
    WHEN 1 THEN 'Suria Sabah Shopping Mall, Jalan Tun Fuad Stephens, 88000 Kota Kinabalu, Sabah'
    WHEN 2 THEN '1Borneo Hypermall, Jalan Sulaman, 88400 Kota Kinabalu, Sabah'
    WHEN 3 THEN 'City Mall Shopping Center, Jalan Lintas, 88300 Kota Kinabalu, Sabah'
    WHEN 4 THEN 'Megalong Shopping Mall, Jalan Penampang, 89500 Penampang, Sabah'
    WHEN 5 THEN 'Kompleks Karamunsing, Jalan Tuaran, 88300 Kota Kinabalu, Sabah'
    ELSE 'Centre Point Sabah, Jalan Coastal, 88000 Kota Kinabalu, Sabah'
END
WHERE hospital_id = 16;

-- 4.5 FORCE "THIS WEEK" DRIVES FOR EVERY HOSPITAL (Last 7 Days)
INSERT INTO blood_drive (hospital_id, drive_date, start_time, end_time, location_name, status, full_address)
SELECT 
    h.hospital_id,
    DATE_SUB(CURDATE(), INTERVAL (h.hospital_id % 5 + 1) DAY), 
    '08:00:00', '18:00:00',
    CONCAT(h.hospital_name, ' Weekly Event'),
    'Completed',
    h.hospital_address
FROM hospital h;

-- ===============================================================
-- 5. APPOINTMENT GENERATION (THE CORE ENGINE)
-- ===============================================================

-- 5.1 TIER 1: CITY GIANTS (QEH & Women/Child) -> ~180-250 Donations
INSERT INTO appointment (user_id, drive_id, selected_time, status, donation_date, volume_ml, notes, source)
SELECT
    d.user_id, bd.drive_id,
    CASE 
        WHEN WEEK(bd.drive_date) % 2 != 0 THEN ADDTIME('06:00:00', SEC_TO_TIME((d.user_id % 300) * 60)) 
        ELSE ADDTIME('16:00:00', SEC_TO_TIME((d.user_id % 300) * 60)) 
    END,
    'Completed', bd.drive_date, 450, 'Success', 'Walk-in'
FROM blood_drive bd
JOIN donor_user d ON d.user_id BETWEEN 1 AND 2500 
WHERE bd.hospital_id IN (1, 4) 
AND bd.status = 'Completed'
AND (d.user_id + bd.drive_id) % 15 = 0
ON DUPLICATE KEY UPDATE status=VALUES(status);

-- 5.2 TIER 2: MAJOR TOWNS -> ~60-80 Donations
INSERT INTO appointment (user_id, drive_id, selected_time, status, donation_date, volume_ml, notes, source)
SELECT
    d.user_id, bd.drive_id,
    ADDTIME('09:00:00', SEC_TO_TIME(((d.user_id + bd.drive_id) % 400) * 60)), 
    'Completed', bd.drive_date, 450, 'Success', 'Online'
FROM blood_drive bd
JOIN donor_user d ON d.user_id BETWEEN 2501 AND 4500 
WHERE bd.hospital_id IN (2, 3, 5, 6, 11, 12, 14, 15) 
AND bd.status = 'Completed'
AND (d.user_id + bd.drive_id) % 25 = 0 
ON DUPLICATE KEY UPDATE status=VALUES(status);

-- 5.3 TIER 3: RURAL -> ~15-30 Donations
INSERT INTO appointment (user_id, drive_id, selected_time, status, donation_date, volume_ml, notes, source)
SELECT
    d.user_id, bd.drive_id,
    ADDTIME('08:00:00', SEC_TO_TIME(((d.user_id + bd.drive_id) % 200) * 60)),
    'Completed', bd.drive_date, 450, 'Success', 'Walk-in'
FROM blood_drive bd
JOIN donor_user d ON d.user_id BETWEEN 4501 AND 5800 
WHERE bd.hospital_id IN (7, 8, 9, 10, 13) 
AND bd.status = 'Completed'
AND (d.user_id + bd.drive_id) % 40 = 0 
ON DUPLICATE KEY UPDATE status=VALUES(status);

-- 5.4 AGENT CLINIC (Special Competitive Volume)
INSERT INTO appointment (user_id, drive_id, selected_time, status, donation_date, volume_ml, notes, source)
SELECT
    d.user_id, bd.drive_id,
    CASE 
        WHEN WEEK(bd.drive_date) % 2 = 0 THEN ADDTIME('06:00:00', SEC_TO_TIME((d.user_id % 180) * 60))
        ELSE ADDTIME('17:00:00', SEC_TO_TIME((d.user_id % 240) * 60))
    END,
    'Completed', bd.drive_date, 450, 'Success', 'Online'
FROM blood_drive bd
CROSS JOIN (SELECT user_id FROM donor_user WHERE email LIKE 'agent.donor%' LIMIT 150) d 
WHERE bd.hospital_id = 16 
AND bd.status = 'Completed'
ON DUPLICATE KEY UPDATE status=VALUES(status);

-- 5.5 FORCE RARE BLOOD TYPES FOR TELEMETRY (THIS WEEK ONLY)
-- Ensures "Live Telemetry" shows non-zero values for rare types at Agent Clinic
INSERT INTO appointment (user_id, drive_id, selected_time, status, donation_date, volume_ml, notes, source)
SELECT 
    d.user_id, bd.drive_id, 
    ADDTIME('10:00:00', SEC_TO_TIME((d.user_id % 60) * 60)),
    'Completed', bd.drive_date, 450, 'Priority', 'Walk-in'
FROM donor_user d
JOIN blood_drive bd ON bd.hospital_id = 16 AND bd.drive_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE()
WHERE d.blood_type IN ('O-', 'A-', 'B-', 'AB-')
AND d.user_id < 2000 -- Limit to subset
LIMIT 15 -- Force at least 15 rare donations
ON DUPLICATE KEY UPDATE status=VALUES(status);

-- 3.12 Notifications
INSERT INTO notification (hospital_id, target_blood_type, message_content, urgency_level, sent_at, title)
SELECT 16, 'O+', 'High demand for O+ blood.', 'High', NOW(), 'Urgent O+ Request';

-- 3.13 Update Last Donation Dates
UPDATE donor_user u
JOIN (SELECT user_id, MAX(donation_date) AS max_date FROM appointment WHERE status = 'Completed' GROUP BY user_id) a ON u.user_id = a.user_id
SET u.last_donation_date = a.max_date;