-- ============================================================
-- Migration: Add ON DELETE CASCADE to quiz_sessions, answers,
--            and results foreign keys.
--
-- This is OPTIONAL — the PHP deleteQuiz() function now handles
-- deletion in the correct order without relying on DB cascades.
--
-- Run this in phpMyAdmin against quiz_db if you want the DB
-- to also enforce cascades independently of PHP.
--
-- Step 1: Find your actual FK constraint names by running:
--
--   SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME
--   FROM information_schema.KEY_COLUMN_USAGE
--   WHERE TABLE_SCHEMA = 'quiz_db'
--     AND REFERENCED_TABLE_NAME IN ('quizzes','quiz_sessions','questions');
--
-- Step 2: Replace the names in the ALTER TABLE statements below
--         with the names returned by Step 1, then run this file.
-- ============================================================

USE quiz_db;

-- ── quiz_sessions: quiz_id FK ────────────────────────────────
ALTER TABLE quiz_sessions
    DROP FOREIGN KEY quiz_sessions_ibfk_1;   -- replace with real name from Step 1

ALTER TABLE quiz_sessions
    ADD CONSTRAINT fk_qs_quiz_id
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE;

-- ── answers: session_id and question_id FKs ──────────────────
ALTER TABLE answers
    DROP FOREIGN KEY answers_ibfk_1,         -- replace with real name from Step 1
    DROP FOREIGN KEY answers_ibfk_2;         -- replace with real name from Step 1

ALTER TABLE answers
    ADD CONSTRAINT fk_ans_session_id
    FOREIGN KEY (session_id)  REFERENCES quiz_sessions(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_ans_question_id
    FOREIGN KEY (question_id) REFERENCES questions(id)     ON DELETE CASCADE;

-- ── results: session_id and quiz_id FKs ──────────────────────
ALTER TABLE results
    DROP FOREIGN KEY results_ibfk_1,         -- replace with real name from Step 1
    DROP FOREIGN KEY results_ibfk_3;         -- replace with real name from Step 1

ALTER TABLE results
    ADD CONSTRAINT fk_res_session_id
    FOREIGN KEY (session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_res_quiz_id
    FOREIGN KEY (quiz_id)    REFERENCES quizzes(id)       ON DELETE CASCADE;
