CREATE DATABASE IF NOT EXISTS bloodknight_db;
USE bloodknight_db;

-- 1. Entity: USER
CREATE TABLE IF NOT EXISTS user (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    blood_type VARCHAR(5),
    phone_number VARCHAR(20),
    total_points INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Entity: BLOOD_BANK
CREATE TABLE IF NOT EXISTS blood_bank (
    bank_id INT AUTO_INCREMENT PRIMARY KEY,
    organization_name VARCHAR(150) NOT NULL,
    address TEXT NOT NULL,
    contact_number VARCHAR(20),
    admin_email VARCHAR(100) NOT NULL
);

-- 3. Entity: BLOOD_DRIVE
CREATE TABLE IF NOT EXISTS blood_drive (
    drive_id INT AUTO_INCREMENT PRIMARY KEY,
    bank_id INT NOT NULL,
    drive_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    location_name VARCHAR(150),
    coordinates VARCHAR(100),
    FOREIGN KEY (bank_id) REFERENCES blood_bank(bank_id) ON DELETE CASCADE
);

-- 4. Entity: APPOINTMENT
CREATE TABLE IF NOT EXISTS appointment (
    appt_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    drive_id INT NOT NULL,
    selected_time TIME NOT NULL,
    status ENUM('Scheduled', 'Completed', 'Cancelled') DEFAULT 'Scheduled',
    FOREIGN KEY (user_id) REFERENCES user(user_id) ON DELETE CASCADE,
    FOREIGN KEY (drive_id) REFERENCES blood_drive(drive_id) ON DELETE CASCADE
);

-- 5. Entity: NOTIFICATION
CREATE TABLE IF NOT EXISTS notification (
    alert_id INT AUTO_INCREMENT PRIMARY KEY,
    bank_id INT NOT NULL,
    target_blood_type VARCHAR(5),
    message_content TEXT NOT NULL,
    urgency_level ENUM('Low', 'High', 'Critical') DEFAULT 'High',
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bank_id) REFERENCES blood_bank(bank_id) ON DELETE CASCADE
);

-- 6. Entity: DONATION_HISTORY
CREATE TABLE IF NOT EXISTS donation_history (
    history_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    drive_id INT,
    donation_date DATE NOT NULL,
    volume_ml INT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES user(user_id) ON DELETE CASCADE
);

-- DUMMY DATA
INSERT INTO user (email, password_hash, full_name, blood_type, phone_number, total_points) 
VALUES 
('sarah@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Sarah Jenkins', 'O+', '012-3456789', 150);

INSERT INTO blood_bank (organization_name, address, contact_number, admin_email)
VALUES ('Pusat Darah Negara', 'Jalan Tun Razak, Kuala Lumpur', '03-26132688', 'admin@pdn.gov.my');

INSERT INTO blood_drive (bank_id, drive_date, start_time, end_time, location_name, coordinates)
VALUES (1, '2023-12-25', '09:00:00', '17:00:00', 'Mid Valley Megamall', '3.1176,101.6776');

INSERT INTO donation_history (user_id, drive_id, donation_date, volume_ml) VALUES 
(1, 1, '2023-01-10', 450),
(1, 1, '2023-04-15', 450),
(1, 1, '2023-07-20', 450);

INSERT INTO notification (bank_id, target_blood_type, message_content, urgency_level)
VALUES (1, 'O-', 'O- Blood needed at PDN immediately due to accident.', 'Critical');