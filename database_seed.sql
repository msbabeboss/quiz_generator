-- ============================================================
-- database_seed.sql — Demo users for testing
--
-- Password for ALL accounts: admin123
--
-- Import AFTER database.sql:
--   mysql -u root -p quiz_db < database_seed.sql
--
-- Accounts created:
--   Teacher  → username: teacher_demo  / password: admin123
--   Student  → username: student_demo  / password: admin123
--
-- ⚠️  FOR DEMO / TESTING ONLY.
--     Delete or change these accounts before going to production.
-- ============================================================

USE quiz_db;

INSERT INTO users (username, email, password_hash, role, status) VALUES
(
    'teacher_demo',
    'teacher@demo.com',
    '$2y$12$ttc.7Mj24qYIlO5N2z3kSOWa43S3h56B/FxH4xDnX5xJL1mAyIM6y',
    'teacher',
    'active'
),
(
    'student_demo',
    'student@demo.com',
    '$2y$12$xabr2rqBPyCMWVn2oOlAzekwSbOdijX3ZWB8BS.Tvj5GA0mVcj1Tm',
    'student',
    'active'
);
