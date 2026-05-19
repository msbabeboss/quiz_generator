-- ============================================================
-- Migration: Add expires_at column to quizzes table.
--
-- Run this ONCE in phpMyAdmin against quiz_db.
-- Existing quizzes will get expires_at = created_at + 30 days.
-- New quizzes will automatically get expires_at = NOW() + 30 days
-- (set by the application layer in QuizManager::createQuiz).
-- ============================================================

USE quiz_db;

ALTER TABLE quizzes
    ADD COLUMN expires_at DATETIME NULL
        COMMENT 'Auto-delete after this date (NULL = never)'
        AFTER is_active;

-- Back-fill existing quizzes: expire 30 days from their creation date
UPDATE quizzes
SET expires_at = DATE_ADD(created_at, INTERVAL 30 DAY)
WHERE expires_at IS NULL;
