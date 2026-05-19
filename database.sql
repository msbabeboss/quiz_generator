-- Smart Real-Time Quiz Generator
-- Roles: teacher (creates/manages quizzes, sees all results), student (takes quizzes)

CREATE DATABASE IF NOT EXISTS quiz_db;
USE quiz_db;

-- ============================================================
-- TABLE DEFINITIONS
-- ============================================================

-- 1. users
CREATE TABLE users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  email         VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('teacher','student') NOT NULL DEFAULT 'student',
  status        ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 2. quizzes
CREATE TABLE quizzes (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(255) NOT NULL,
  description   TEXT,
  created_by    INT NOT NULL,
  time_limit    INT NOT NULL COMMENT 'Duration in seconds',
  is_randomized TINYINT(1) DEFAULT 0,
  is_active     TINYINT(1) DEFAULT 1,
  expires_at    DATETIME NULL COMMENT 'Auto-delete after this date (NULL = never)',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

-- 3. questions
CREATE TABLE questions (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  quiz_id        INT NOT NULL,
  question_text  TEXT NOT NULL,
  question_type  ENUM('mcq','true_false','identification','fill_blank','enumeration') NOT NULL,
  correct_answer VARCHAR(500) NOT NULL,
  points         INT DEFAULT 1,
  order_index    INT DEFAULT 0,
  FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
);

-- 4. question_options
CREATE TABLE question_options (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  question_id  INT NOT NULL,
  option_label CHAR(1) NOT NULL COMMENT 'A, B, C, D for MCQ; T, F for True/False',
  option_text  VARCHAR(500) NOT NULL,
  FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
);

-- 5. quiz_sessions
CREATE TABLE quiz_sessions (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  quiz_id      INT NOT NULL,
  student_id   INT NOT NULL,
  started_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  submitted_at TIMESTAMP NULL,
  status       ENUM('in_progress','submitted','timed_out') DEFAULT 'in_progress',
  FOREIGN KEY (quiz_id)    REFERENCES quizzes(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES users(id),
  INDEX idx_session_lookup (student_id, quiz_id, status)
);

-- 6. answers
CREATE TABLE answers (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  session_id     INT NOT NULL,
  question_id    INT NOT NULL,
  student_answer VARCHAR(255),
  is_correct     TINYINT(1) DEFAULT 0,
  answered_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (session_id)  REFERENCES quiz_sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
  INDEX idx_answers_session (session_id)
);

-- 7. results
CREATE TABLE results (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  session_id   INT NOT NULL UNIQUE,
  student_id   INT NOT NULL,
  quiz_id      INT NOT NULL,
  score        INT DEFAULT 0,
  total_points INT DEFAULT 0,
  percentage   DECIMAL(5,2) DEFAULT 0.00,
  computed_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (session_id)  REFERENCES quiz_sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id)  REFERENCES users(id),
  FOREIGN KEY (quiz_id)     REFERENCES quizzes(id) ON DELETE CASCADE,
  INDEX idx_results_quiz (quiz_id, percentage DESC)
);

-- 8. quiz_access_codes (per-section exam codes)
CREATE TABLE quiz_access_codes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  quiz_id    INT NOT NULL,
  teacher_id INT NOT NULL,
  code       CHAR(8) NOT NULL UNIQUE,
  label      VARCHAR(100) DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (quiz_id)    REFERENCES quizzes(id) ON DELETE CASCADE,
  FOREIGN KEY (teacher_id) REFERENCES users(id)   ON DELETE CASCADE
);

-- 9. quiz_enrollments (student enrolled via exam code)
CREATE TABLE quiz_enrollments (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  quiz_id     INT NOT NULL,
  student_id  INT NOT NULL,
  code_id     INT NOT NULL,
  enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_enrollment (quiz_id, student_id),
  FOREIGN KEY (quiz_id)    REFERENCES quizzes(id)           ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES users(id)             ON DELETE CASCADE,
  FOREIGN KEY (code_id)    REFERENCES quiz_access_codes(id) ON DELETE CASCADE
);

-- 10. classrooms (room-code based groups)
CREATE TABLE classrooms (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id  INT NOT NULL,
  name        VARCHAR(100) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  room_code   CHAR(8) NOT NULL UNIQUE,
  is_active   TINYINT(1) DEFAULT 1,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 11. classroom_enrollments (students joined via room code)
CREATE TABLE classroom_enrollments (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  classroom_id INT NOT NULL,
  student_id   INT NOT NULL,
  enrolled_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_room_student (classroom_id, student_id),
  FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id)   REFERENCES users(id)      ON DELETE CASCADE
);

-- 12. classroom_quizzes (quizzes assigned to a classroom)
CREATE TABLE classroom_quizzes (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  classroom_id INT NOT NULL,
  quiz_id      INT NOT NULL,
  added_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_room_quiz (classroom_id, quiz_id),
  FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
  FOREIGN KEY (quiz_id)      REFERENCES quizzes(id)    ON DELETE CASCADE
);

-- 13. flashcard_sessions
CREATE TABLE flashcard_sessions (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  quiz_id      INT NOT NULL,
  student_id   INT NOT NULL,
  total_cards  INT NOT NULL DEFAULT 0,
  correct      INT NOT NULL DEFAULT 0,
  wrong        INT NOT NULL DEFAULT 0,
  score        INT NOT NULL DEFAULT 0,
  total_points INT NOT NULL DEFAULT 0,
  percentage   DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  completed    TINYINT(1) NOT NULL DEFAULT 0,
  started_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  finished_at  TIMESTAMP NULL,
  FOREIGN KEY (quiz_id)    REFERENCES quizzes(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES users(id)   ON DELETE CASCADE,
  INDEX idx_fc_quiz    (quiz_id),
  INDEX idx_fc_student (student_id)
);

-- ============================================================
-- STORED PROCEDURES
-- ============================================================

DELIMITER $

CREATE PROCEDURE GetQuizResults(IN p_quiz_id INT)
BEGIN
  SELECT
    u.username,
    r.score,
    r.total_points,
    r.percentage,
    qs.submitted_at,
    qs.status
  FROM results r
  JOIN quiz_sessions qs ON r.session_id = qs.id
  JOIN users u ON r.student_id = u.id
  WHERE r.quiz_id = p_quiz_id
  ORDER BY r.percentage DESC, qs.submitted_at ASC;
END$

CREATE PROCEDURE ComputeScore(IN p_student_id INT, IN p_quiz_id INT)
BEGIN
  DECLARE v_session_id  INT;
  DECLARE v_score       INT DEFAULT 0;
  DECLARE v_total       INT DEFAULT 0;
  DECLARE v_percentage  DECIMAL(5,2) DEFAULT 0.00;

  SELECT id INTO v_session_id
  FROM quiz_sessions
  WHERE student_id = p_student_id AND quiz_id = p_quiz_id
  ORDER BY started_at DESC LIMIT 1;

  SELECT
    SUM(CASE WHEN a.is_correct = 1 THEN q.points ELSE 0 END),
    SUM(q.points)
  INTO v_score, v_total
  FROM answers a
  JOIN questions q ON a.question_id = q.id
  WHERE a.session_id = v_session_id;

  IF v_total > 0 THEN
    SET v_percentage = (v_score / v_total) * 100;
  END IF;

  INSERT INTO results (session_id, student_id, quiz_id, score, total_points, percentage)
  VALUES (v_session_id, p_student_id, p_quiz_id, v_score, v_total, v_percentage)
  ON DUPLICATE KEY UPDATE
    score        = v_score,
    total_points = v_total,
    percentage   = v_percentage,
    computed_at  = CURRENT_TIMESTAMP;
END$

DELIMITER ;

-- ============================================================
-- TRIGGERS
-- ============================================================

DELIMITER $

CREATE TRIGGER after_answer_insert
BEFORE INSERT ON answers
FOR EACH ROW
BEGIN
  DECLARE v_correct VARCHAR(255);
  SELECT correct_answer INTO v_correct FROM questions WHERE id = NEW.question_id;
  IF NEW.student_answer IS NOT NULL AND NEW.student_answer = v_correct THEN
    SET NEW.is_correct = 1;
  ELSE
    SET NEW.is_correct = 0;
  END IF;
END$

CREATE TRIGGER after_session_submit
AFTER UPDATE ON quiz_sessions
FOR EACH ROW
BEGIN
  IF NEW.status IN ('submitted', 'timed_out') AND OLD.status = 'in_progress' THEN
    CALL ComputeScore(NEW.student_id, NEW.quiz_id);
  END IF;
END$

DELIMITER ;
