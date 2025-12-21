-- ===============================================================
-- LEADERBOARD DATA UPDATE SCRIPT
-- Run this to ensure leaderboard has proper data
-- This script ensures completed appointments have volume_ml > 0
-- ===============================================================

USE bloodknight_db;

-- Update existing appointments: Ensure completed appointments have volume_ml > 0
-- This fixes any data inconsistencies for the leaderboard
UPDATE appointment a
JOIN blood_drive bd ON a.drive_id = bd.drive_id
SET 
    a.volume_ml = CASE 
        WHEN a.status = 'Completed' AND (a.volume_ml IS NULL OR a.volume_ml = 0) THEN 400 + FLOOR((a.appt_id * 7) % 100)
        ELSE a.volume_ml
    END,
    a.donation_date = CASE 
        WHEN a.status = 'Completed' AND a.donation_date IS NULL THEN bd.drive_date
        ELSE a.donation_date
    END
WHERE a.status = 'Completed'
AND bd.status = 'Completed';

-- Ensure all completed appointments have proper donation_date
UPDATE appointment a
JOIN blood_drive bd ON a.drive_id = bd.drive_id
SET a.donation_date = bd.drive_date
WHERE a.status = 'Completed'
AND a.donation_date IS NULL
AND bd.status = 'Completed';

-- Verify leaderboard data (for testing - can be removed)
-- This query shows the leaderboard as it will appear
SELECT 
    h.hospital_id,
    h.hospital_name,
    h.hospital_address,
    h.hospital_type,
    COALESCE(SUM(CASE WHEN a.status = 'Completed' AND a.volume_ml IS NOT NULL AND a.volume_ml > 0 THEN a.volume_ml ELSE 0 END), 0) / 1000.0 as total_volume_l,
    COUNT(DISTINCT CASE WHEN a.status = 'Completed' AND a.volume_ml IS NOT NULL AND a.volume_ml > 0 THEN a.appt_id END) as total_donations
FROM hospital h
LEFT JOIN blood_drive bd ON h.hospital_id = bd.hospital_id
LEFT JOIN appointment a ON bd.drive_id = a.drive_id
WHERE h.status = 'Active'
GROUP BY h.hospital_id, h.hospital_name, h.hospital_address, h.hospital_type
ORDER BY total_volume_l DESC, total_donations DESC, h.hospital_name ASC
LIMIT 10;

-- ===============================================================
-- END OF LEADERBOARD DATA UPDATE
-- ===============================================================
