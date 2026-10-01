-- ====================================================================
-- AutoExcel: Area, Building & Collector Database Seeder Script
-- Safe to run in Hostinger phpMyAdmin (Does NOT touch or create users)
-- ====================================================================

-- 1. Insert Areas (24 Total)
INSERT IGNORE INTO `areas` (`name`, `created_at`, `updated_at`) VALUES
('Banani-2', NOW(), NOW()),
('Banani', NOW(), NOW()),
('Canbazar-A', NOW(), NOW()),
('Canbazar-E', NOW(), NOW()),
('Canbazar Army-B', NOW(), NOW()),
('Canbazar Army-E', NOW(), NOW()),
('Canbazar Civil-B', NOW(), NOW()),
('Canbazar Civil-E', NOW(), NOW()),
('Mannan Line', NOW(), NOW()),
('Moinul Road', NOW(), NOW()),
('Office Target', NOW(), NOW()),
('Mostofa Kamal', NOW(), NOW()),
('Nirjhor', NOW(), NOW()),
('AHQ', NOW(), NOW()),
('ISPR', NOW(), NOW()),
('CMH', NOW(), NOW()),
('DGFI', NOW(), NOW()),
('AFD', NOW(), NOW()),
('Rajonigondha', NOW(), NOW()),
('Seena Polly', NOW(), NOW()),
('Staff Road-2', NOW(), NOW()),
('Staff Road', NOW(), NOW()),
('Yousuf Road', NOW(), NOW()),
('Zia Koloni', NOW(), NOW());

-- 2. Insert Buildings (80 Total)
INSERT IGNORE INTO `buildings` (`name`, `created_at`, `updated_at`) VALUES
('Uttoron', NOW(), NOW()),
('Uttorayon', NOW(), NOW()),
('Upayon', NOW(), NOW()),
('Un Complex', NOW(), NOW()),
('Ujjibon', NOW(), NOW()),
('Udoyon', NOW(), NOW()),
('Uddipon', NOW(), NOW()),
('Thana Qtr', NOW(), NOW()),
('Surjotorun', NOW(), NOW()),
('Surjoshikha', NOW(), NOW()),
('Surjokiron', NOW(), NOW()),
('Surjodhighal', NOW(), NOW()),
('Sornolata', NOW(), NOW()),
('Sornali', NOW(), NOW()),
('Sopnonir', NOW(), NOW()),
('Sopnolok', NOW(), NOW()),
('Sopnochura-3', NOW(), NOW()),
('Sopnochura-2', NOW(), NOW()),
('Sopnochura', NOW(), NOW()),
('Shantirokkhi Nibash', NOW(), NOW()),
('Shadhinota Shoroni', NOW(), NOW()),
('Seenanir-3', NOW(), NOW()),
('Seenanir-2', NOW(), NOW()),
('Seenanir', NOW(), NOW()),
('Sebanir', NOW(), NOW()),
('Sayashongi', NOW(), NOW()),
('Rupsha', NOW(), NOW()),
('Rupali Bank Qtr', NOW(), NOW()),
('Rajbashor-3', NOW(), NOW()),
('Proyash Qtr', NOW(), NOW()),
('Projonmo', NOW(), NOW()),
('Post Office', NOW(), NOW()),
('Porshi', NOW(), NOW()),
('Pgr', NOW(), NOW()),
('Palki', NOW(), NOW()),
('Palangko', NOW(), NOW()),
('Nokkhotro', NOW(), NOW()),
('Navy House', NOW(), NOW()),
('Mess-c', NOW(), NOW()),
('Mess-b', NOW(), NOW()),
('Mess-a (white)', NOW(), NOW()),
('Mess-a', NOW(), NOW()),
('Manoshi', NOW(), NOW()),
('Malotika', NOW(), NOW()),
('Malobika', NOW(), NOW()),
('Madhurika', NOW(), NOW()),
('Kunjolata', NOW(), NOW()),
('K Hossain Buliding', NOW(), NOW()),
('Issb Officer\'s Mess', NOW(), NOW()),
('Himadri', NOW(), NOW()),
('Hamid Line', NOW(), NOW()),
('Gangchill', NOW(), NOW()),
('Gagri', NOW(), NOW()),
('Enc\'s Complex', NOW(), NOW()),
('Dolna', NOW(), NOW()),
('Dipshikha', NOW(), NOW()),
('Dgms', NOW(), NOW()),
('Chondroprova', NOW(), NOW()),
('Choitali', NOW(), NOW()),
('Chayasurjo', NOW(), NOW()),
('Chayasongi', NOW(), NOW()),
('Chameli', NOW(), NOW()),
('Canpublic Qtr', NOW(), NOW()),
('Bivabori', NOW(), NOW()),
('Bihongo', NOW(), NOW()),
('Banalata', NOW(), NOW()),
('Ashalata', NOW(), NOW()),
('Arshi', NOW(), NOW()),
('Anowar Qtr', NOW(), NOW()),
('Alochaya', NOW(), NOW()),
('Ahq Old Mess (red )', NOW(), NOW()),
('Ahq Old Mess', NOW(), NOW()),
('Ahq Office', NOW(), NOW()),
('Ahq New Mess', NOW(), NOW()),
('Agami', NOW(), NOW()),
('Adomji Qtr Old', NOW(), NOW()),
('Adomji Qtr New', NOW(), NOW()),
('Adomji Old Qtr', NOW(), NOW());

-- 3. Insert Collectors (9 Total)
INSERT IGNORE INTO `collectors` (`name`, `status`, `created_at`, `updated_at`) VALUES
('ISPR', 'active', NOW(), NOW()),
('Mr.Eyamin', 'active', NOW(), NOW()),
('Mr.Esrafil Hossen', 'active', NOW(), NOW()),
('Mr.Al-Amin', 'active', NOW(), NOW()),
('Mr.Eklas', 'active', NOW(), NOW()),
('Mr.Golam Kibria', 'active', NOW(), NOW()),
('Mr.Shimul Mahmud', 'active', NOW(), NOW()),
('CMH', 'active', NOW(), NOW()),
('Office', 'active', NOW(), NOW());

-- 4. Map Collectors to Assigned Areas
INSERT IGNORE INTO `area_collector` (`collector_id`, `area_id`, `created_at`, `updated_at`)
SELECT c.id, a.id, NOW(), NOW()
FROM `collectors` c
JOIN `areas` a ON (
    (c.name = 'ISPR' AND a.name = 'ISPR')
    OR (c.name = 'Mr.Eyamin' AND a.name IN ('Rajonigondha', 'Banani-2', 'Yousuf Road'))
    OR (c.name = 'Mr.Esrafil Hossen' AND a.name IN ('DGFI', 'Mostofa Kamal', 'Zia Koloni', 'Staff Road'))
    OR (c.name = 'Mr.Al-Amin' AND a.name IN ('Canbazar Army-B', 'Canbazar Civil-B', 'Nirjhor', 'AFD', 'Canbazar-A'))
    OR (c.name = 'Mr.Eklas' AND a.name IN ('Canbazar Army-E', 'Canbazar Civil-E', 'Canbazar-E'))
    OR (c.name = 'Mr.Golam Kibria' AND a.name IN ('Mannan Line', 'Seena Polly', 'Staff Road-2'))
    OR (c.name = 'Mr.Shimul Mahmud' AND a.name IN ('Banani', 'AHQ', 'Moinul Road'))
    OR (c.name = 'CMH' AND a.name = 'CMH')
    OR (c.name = 'Office' AND a.name = 'Office Target')
);
