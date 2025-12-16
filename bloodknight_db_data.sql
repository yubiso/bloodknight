USE bloodknight_db;

-- ===============================================================
-- 3. VARIABLE SETUP
-- ===============================================================

-- Define a start date for the simulation (approx. 2 years ago)
SET @START_DATE = DATE_SUB(CURDATE(), INTERVAL 2 YEAR);
-- Calculate the future end date (2 months ahead)
SET @END_DATE = DATE_ADD(CURDATE(), INTERVAL 2 MONTH); 

-- ===============================================================
-- 4. INSERT DATA
-- ===============================================================

-- 4.1 Insert BloodKnight admin (Default Data)
INSERT INTO bk_admin (admin_id, email, password_hash, full_name)
VALUES (1, 'bloodknight.about@gmail.com', '$2y$10$bjPj8LdSw1.FCOugboeXFe2TBh7P69ZiRo53uvwXWfv.Zq106WM6.', 'BloodKnight Admin')
ON DUPLICATE KEY UPDATE email=VALUES(email);

-- 4.2 Sample Hospitals (15 Hospitals total)
-- IDs 1-13 are 'Active'. IDs 14-15 are 'Pending' to simulate registration requests.

INSERT INTO hospital (hospital_id, hospital_name, hospital_address, contact_number, hospital_type, admin_name, admin_email, admin_phone, password_hash, status) VALUES
(1, 'Queen Elizabeth Hospital', 'Lorong Bersatu, Off Jalan Damai, 88300 Kota Kinabalu, Sabah', '088-324600', 'Government Hospital', 'Dr. Ahmad Razak', 'admin@qeh.gov.my', '012-3456789', 'hash123', 'Active') ON DUPLICATE KEY UPDATE hospital_name=VALUES(hospital_name), admin_email=VALUES(admin_email), status=VALUES(status);

INSERT INTO hospital (hospital_id, hospital_name, hospital_address, contact_number, hospital_type, admin_name, admin_email, admin_phone, password_hash, status) VALUES
(2, 'KPJ Sabah Specialist Hospital', 'Jalan Damai, Luyang, 88300 Kota Kinabalu, Sabah', '088-322000', 'Private Hospital', 'Dr. Siti Nurhaliza', 'admin@kpjsabah.com', '012-9999999', 'hash123', 'Active') ON DUPLICATE KEY UPDATE hospital_name=VALUES(hospital_name), admin_email=VALUES(admin_email), status=VALUES(status);

INSERT INTO hospital (hospital_id, hospital_name, hospital_address, contact_number, hospital_type, admin_name, admin_email, admin_phone, password_hash, status) VALUES
(3, 'Gleneagles Kota Kinabalu', 'Block A & B, Lot 1 & 2, Off Jalan Lapangan Terbang, 88000 Kota Kinabalu, Sabah', '088-518888', 'Private Hospital', 'Dr. Lim Wei Chuan', 'admin@gleneagles-kk.com', '012-8888888', 'hash123', 'Active') ON DUPLICATE KEY UPDATE hospital_name=VALUES(hospital_name), admin_email=VALUES(admin_email), status=VALUES(status);

INSERT INTO hospital (hospital_id, hospital_name, hospital_address, contact_number, hospital_type, admin_name, admin_email, admin_phone, password_hash, status) VALUES
(4, 'Sabah Women and Children Hospital', 'Likas, 88400 Kota Kinabalu, Sabah', '088-315555', 'Government Hospital', 'Dr. Rosnah Binti Ahmad', 'admin@swch.gov.my', '012-7777777', 'hash123', 'Active') ON DUPLICATE KEY UPDATE hospital_name=VALUES(hospital_name), admin_email=VALUES(admin_email), status=VALUES(status);

INSERT INTO hospital (hospital_id, hospital_name, hospital_address, contact_number, hospital_type, admin_name, admin_email, admin_phone, password_hash, status) VALUES
(5, 'Tawau Hospital', 'Jalan Apas, 91000 Tawau, Sabah', '089-773333', 'Government Hospital', 'Dr. Mohd Azmi', 'admin@tawauhospital.gov.my', '012-6666666', 'hash123', 'Active') ON DUPLICATE KEY UPDATE hospital_name=VALUES(hospital_name), admin_email=VALUES(admin_email), status=VALUES(status);

INSERT INTO hospital (hospital_id, hospital_name, hospital_address, contact_number, hospital_type, admin_name, admin_email, admin_phone, password_hash, status) VALUES
(6, 'Sandakan Hospital', 'Jalan Utara, 90000 Sandakan, Sabah', '089-221555', 'Government Hospital', 'Dr. James Wong', 'admin@sandakanhospital.gov.my', '012-5555555', 'hash123', 'Active') ON DUPLICATE KEY UPDATE hospital_name=VALUES(hospital_name), admin_email=VALUES(admin_email), status=VALUES(status);

INSERT INTO hospital (hospital_id, hospital_name, hospital_address, contact_number, hospital_type, admin_name, admin_email, admin_phone, password_hash, status) VALUES
(7, 'Keningau Hospital', 'Jalan Hospital, 89007 Keningau, Sabah', '087-331222', 'Government Hospital', 'Dr. Mary Lim', 'admin@keningauhospital.gov.my', '012-4444444', 'hash123', 'Active') ON DUPLICATE KEY UPDATE hospital_name=VALUES(hospital_name), admin_email=VALUES(admin_email), status=VALUES(status);

INSERT INTO hospital (hospital_id, hospital_name, hospital_address, contact_number, hospital_type, admin_name, admin_email, admin_phone, password_hash, status) VALUES
(8, 'Lahad Datu Hospital', 'Jalan Segama, 91100 Lahad Datu, Sabah', '089-881222', 'Government Hospital', 'Dr. Chin Vui Ming', 'admin@ldhospital.gov.my', '013-8881222', 'hash123', 'Active') ON DUPLICATE KEY UPDATE hospital_name=VALUES(hospital_name), admin_email=VALUES(admin_email), status=VALUES(status);

INSERT INTO hospital (hospital_id, hospital_name, hospital_address, contact_number, hospital_type, admin_name, admin_email, admin_phone, password_hash, status) VALUES
(9, 'Kudat Hospital', 'Jalan Sikuati, 89050 Kudat, Sabah', '088-611333', 'Government Hospital', 'Dr. Evelyn Ho', 'admin@kudathospital.gov.my', '013-6113333', 'hash123', 'Active') ON DUPLICATE KEY UPDATE hospital_name=VALUES(hospital_name), admin_email=VALUES(admin_email), status=VALUES(status);

INSERT INTO hospital (hospital_id, hospital_name, hospital_address, contact_number, hospital_type, admin_name, admin_email, admin_phone, password_hash, status) VALUES
(10, 'Beaufort Hospital', 'Jalan Hospital, 89800 Beaufort, Sabah', '087-211777', 'Government Hospital', 'Dr. Kamal Ariffin', 'admin@beauforthospital.gov.my', '013-2117777', 'hash123', 'Active') ON DUPLICATE KEY UPDATE hospital_name=VALUES(hospital_name), admin_email=VALUES(admin_email), status=VALUES(status);

INSERT INTO hospital (hospital_id, hospital_name, hospital_address, contact_number, hospital_type, admin_name, admin_email, admin_phone, password_hash, status) VALUES
(11, 'Columbia Asia Hospital - Kota Kinabalu', 'No 7, Jalan Harapan, 88300 Kota Kinabalu, Sabah', '088-301000', 'Private Hospital', 'Ms. Janet Wong', 'admin@cahkk.com', '012-3010000', 'hash123', 'Active') ON DUPLICATE KEY UPDATE hospital_name=VALUES(hospital_name), admin_email=VALUES(admin_email), status=VALUES(status);

INSERT INTO hospital (hospital_id, hospital_name, hospital_address, contact_number, hospital_type, admin_name, admin_email, admin_phone, password_hash, status) VALUES
(12, 'Rafflesia Medical Centre', 'Jalan Bundusan, Penampang, 88200 Kota Kinabalu, Sabah', '088-721000', 'Specialist Center', 'Dr. Patrick Loo', 'admin@rafflesia.com', '012-7210000', 'hash123', 'Active') ON DUPLICATE KEY UPDATE hospital_name=VALUES(hospital_name), admin_email=VALUES(admin_email), status=VALUES(status);

INSERT INTO hospital (hospital_id, hospital_name, hospital_address, contact_number, hospital_type, admin_name, admin_email, admin_phone, password_hash, status) VALUES
(13, 'Semporna Hospital', 'Jalan Hospital, 91308 Semporna, Sabah', '089-781555', 'Government Hospital', 'Dr. Wong Kee Lee', 'admin@sempornahospital.gov.my', '013-7815555', 'hash123', 'Active') ON DUPLICATE KEY UPDATE hospital_name=VALUES(hospital_name), admin_email=VALUES(admin_email), status=VALUES(status);

-- HOSPITAL 14: PENDING REQUEST
INSERT INTO hospital (hospital_id, hospital_name, hospital_address, contact_number, hospital_type, admin_name, admin_email, admin_phone, password_hash, status) VALUES
(14, 'Klinik Kesihatan Luyang (Blood Bank)', 'Luyang, 88300 Kota Kinabalu, Sabah', '088-251001', 'Blood Bank', 'Ms. Norliza Binti Ismail', 'admin@kkluyang.gov.my', '012-2510011', 'hash123', 'Pending') ON DUPLICATE KEY UPDATE hospital_name=VALUES(hospital_name), admin_email=VALUES(admin_email), status=VALUES(status);

-- HOSPITAL 15: PENDING REQUEST
INSERT INTO hospital (hospital_id, hospital_name, hospital_address, contact_number, hospital_type, admin_name, admin_email, admin_phone, password_hash, status) VALUES
(15, 'Tuaran Hospital', 'Jalan Hospital, 89208 Tuaran, Sabah', '088-788222', 'Government Hospital', 'Dr. Helen Kong', 'admin@tuaranhospital.gov.my', '013-7882222', 'hash123', 'Pending') ON DUPLICATE KEY UPDATE hospital_name=VALUES(hospital_name), admin_email=VALUES(admin_email), status=VALUES(status);


-- 4.3 Sample Donors (200 Donors)
-- Recursive CTE to generate 200 users
INSERT INTO donor_user (email, password_hash, full_name, ic_number, blood_type, phone_number, last_donation_date)
WITH RECURSIVE Donors AS (
    SELECT 1 AS id
    UNION ALL
    SELECT id + 1 FROM Donors WHERE id < 200 -- Generating 200 users
)
SELECT
    CONCAT('donor', d.id, '@test.com') AS email,
    'hash123' AS password_hash,
    CASE FLOOR(1 + (RAND() * 10))
        WHEN 1 THEN CONCAT('Ahmad Bin ', d.id)
        WHEN 2 THEN CONCAT('Siti Binti ', d.id)
        WHEN 3 THEN CONCAT('Lim Wei ', d.id)
        WHEN 4 THEN CONCAT('Goh Mei ', d.id)
        WHEN 5 THEN CONCAT('Ravi A/L ', d.id)
        WHEN 6 THEN CONCAT('Mary J. ', d.id)
        WHEN 7 THEN CONCAT('Haziq Z. ', d.id)
        WHEN 8 THEN CONCAT('Chong K. ', d.id)
        WHEN 9 THEN CONCAT('Nurul H. ', d.id)
        ELSE CONCAT('Tan P. ', d.id)
    END AS full_name,
    LPAD(d.id, 6, '0') + 100000 AS ic_number, 
    CASE FLOOR(1 + (RAND() * 8)) 
        WHEN 1 THEN 'O+'
        WHEN 2 THEN 'O+'
        WHEN 3 THEN 'A+'
        WHEN 4 THEN 'A+'
        WHEN 5 THEN 'B+'
        WHEN 6 THEN 'AB+'
        WHEN 7 THEN 'O-'
        ELSE 'A-'
    END AS blood_type,
    CONCAT('01', FLOOR(RAND() * 10), '-', LPAD(FLOOR(RAND() * 10000000), 7, '0')) AS phone_number,
    -- Initial last donation date: Randomly set between 90 days and 2 years ago, or NULL
    CASE 
        WHEN RAND() < 0.1 THEN NULL 
        ELSE DATE_SUB(CURDATE(), INTERVAL FLOOR(90 + RAND() * 640) DAY) 
    END AS last_donation_date
FROM Donors d
ON DUPLICATE KEY UPDATE email=VALUES(email); 


-- 4.4 Sample Drives (Weekly drives for 2 years)
INSERT INTO blood_drive (hospital_id, drive_date, start_time, end_time, location_name, status)
WITH RECURSIVE DateSeries AS (
    SELECT @START_DATE AS drive_date, 1 as rn
    UNION ALL
    -- Extend time series to cover the 2-year period
    SELECT DATE_ADD(drive_date, INTERVAL 7 DAY), rn + 1 FROM DateSeries WHERE DATE_ADD(drive_date, INTERVAL 7 DAY) <= @END_DATE
)
SELECT 
    (ds.rn % 15) + 1 AS hospital_id, -- Cycle through all 15 hospitals
    ds.drive_date,
    TIME(CONCAT(FLOOR(7 + RAND() * 2), ':', LPAD(FLOOR(RAND() * 60), 2, '0'), ':00')) AS start_time, 
    TIME(CONCAT(FLOOR(15 + RAND() * 3), ':', LPAD(FLOOR(RAND() * 60), 2, '0'), ':00')) AS end_time, 
    CASE 
        WHEN (ds.rn % 5 = 0) THEN 'University Hall' 
        WHEN (ds.rn % 5 = 1) THEN 'Shopping Mall'
        WHEN (ds.rn % 5 = 2) THEN 'Community Hall'
        WHEN (ds.rn % 5 = 3) THEN 'Hospital Foyer'
        ELSE 'Town Square'
    END AS location_name,
    CASE WHEN ds.drive_date < CURDATE() THEN 'Completed' ELSE 'Upcoming' END AS status
FROM DateSeries ds;


-- 4.5 Sample Appointments (For 200 Users)
INSERT INTO appointment (user_id, drive_id, selected_time, status, donation_date, volume_ml, notes)
SELECT
    -- Pseudo-random distribution to cover the 200 users using Modulo 200
    ((bd.rn * 13) % 200) + 1 AS user_id, 
    bd.drive_id,
    TIME(CONCAT(FLOOR(9 + RAND() * 6), ':', LPAD(FLOOR(RAND() * 60), 2, '0'), ':00')) AS selected_time, 
    CASE WHEN bd.status = 'Completed' THEN 'Completed' ELSE 'Pending' END AS status,
    CASE 
        WHEN bd.status = 'Completed' THEN bd.drive_date 
        ELSE NULL 
    END AS donation_date,
    CASE 
        WHEN bd.status = 'Completed' AND (RAND() > 0.1) THEN 450 
        WHEN bd.status = 'Completed' AND (RAND() <= 0.1) THEN 0 
        ELSE NULL 
    END AS volume_ml,
    CASE 
        WHEN bd.status = 'Completed' AND (RAND() > 0.1) THEN 'Successful donation'
        WHEN bd.status = 'Completed' AND (RAND() <= 0.1) THEN 'Did not meet iron requirement'
        ELSE NULL 
    END AS notes
FROM (
    SELECT 
        bd.drive_id, 
        bd.drive_date,
        bd.status,
        ROW_NUMBER() OVER (ORDER BY bd.drive_date, bd.drive_id) AS rn
    FROM blood_drive bd
    WHERE bd.drive_date BETWEEN @START_DATE AND @END_DATE
) bd
-- Schedule appointments for roughly 60% of the drives
WHERE bd.rn % 3 != 0; 


-- 4.6 Update Donor Last Donation Date (Logic: 90-day interval)
UPDATE donor_user u
JOIN (
    SELECT 
        a.user_id, 
        MAX(a.donation_date) AS max_donation_date
    FROM appointment a
    WHERE a.status = 'Completed' AND a.volume_ml > 0
    GROUP BY a.user_id
) AS latest_donation ON u.user_id = latest_donation.user_id
SET u.last_donation_date = latest_donation.max_donation_date;


-- 4.7 Sample Notifications 
INSERT INTO notification (hospital_id, target_blood_type, message_content, urgency_level, sent_at) VALUES
(1, 'O-', 'CRITICAL SHORTAGE: O- blood needed immediately at QEH.', 'Critical', DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
(5, 'A+', 'URGENT: Low stock of A+ blood at Tawau Hospital. Please donate.', 'High', DATE_SUB(CURDATE(), INTERVAL 2 DAY)),
(1, 'AB+', 'Stock alert for AB+ at QEH. All donors welcome.', 'Low', DATE_SUB(CURDATE(), INTERVAL 7 DAY)),
(6, 'B-', 'B- blood required for emergency surgery in Sandakan.', 'Critical', CURDATE()),
(10, 'O+', 'High demand for O+ in Beaufort. Upcoming drive next week.', 'High', DATE_SUB(CURDATE(), INTERVAL 3 DAY)),
(15, 'A-', 'Tuaran Hospital needs A- urgently. Drive open now.', 'High', CURDATE()),
(2, 'B+', 'KPJ Sabah blood bank running low on B+ supply.', 'High', DATE_SUB(CURDATE(), INTERVAL 5 DAY));


-- 4.8 Sample Blood Reports
INSERT INTO blood_report (user_id, appt_id, report_date, hemoglobin, hematocrit, platelet_count, white_blood_cell_count, red_blood_cell_count, blood_pressure, temperature, notes)
SELECT
    a.user_id,
    a.appt_id,
    a.donation_date AS report_date,
    CASE 
        WHEN a.volume_ml > 0 THEN 13.5 + RAND() * 2.0  
        ELSE 11.0 + RAND() * 2.0 
    END AS hemoglobin,
    CASE 
        WHEN a.volume_ml > 0 THEN 40.0 + RAND() * 5.0 
        ELSE 33.0 + RAND() * 5.0 
    END AS hematocrit,
    200000 + FLOOR(RAND() * 100000) AS platelet_count,
    5.0 + RAND() * 5.0 AS white_blood_cell_count,
    4.5 + RAND() * 1.5 AS red_blood_cell_count,
    CONCAT(FLOOR(100 + RAND() * 40), '/', FLOOR(60 + RAND() * 30)) AS blood_pressure,
    98.0 + RAND() * 1.5 AS temperature,
    a.notes
FROM appointment a
WHERE a.status = 'Completed' AND a.donation_date IS NOT NULL;