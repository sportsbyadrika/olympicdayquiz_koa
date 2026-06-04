-- =====================================================================
-- Olympic Day Celebrations 2026 — Sports Quiz Competition
-- Database schema (MySQL 8.x)
-- See PROJECT_SPEC.md section 6 for the data model.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- users : single login table for all roles
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','association','expert','school') NOT NULL,
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role),
  KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- associations : association profile, one flagged as event conductor
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS associations (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id            INT UNSIGNED NOT NULL,
  name               VARCHAR(190) NOT NULL,
  contact_person     VARCHAR(190) DEFAULT NULL,
  contact_email      VARCHAR(190) DEFAULT NULL,
  contact_phone      VARCHAR(40)  DEFAULT NULL,
  district           VARCHAR(190) DEFAULT NULL,
  address            VARCHAR(500) DEFAULT NULL,
  is_event_conductor TINYINT(1) NOT NULL DEFAULT 0,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assoc_user (user_id),
  KEY idx_assoc_conductor (is_event_conductor),
  CONSTRAINT fk_assoc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- sports : master lookup of sports/disciplines
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sports (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(100) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sport_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- association_sports : sports managed by an association (many-to-many)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS association_sports (
  association_id INT UNSIGNED NOT NULL,
  sport_id       INT UNSIGNED NOT NULL,
  PRIMARY KEY (association_id, sport_id),
  KEY idx_as_sport (sport_id),
  CONSTRAINT fk_as_assoc FOREIGN KEY (association_id) REFERENCES associations(id) ON DELETE CASCADE,
  CONSTRAINT fk_as_sport FOREIGN KEY (sport_id) REFERENCES sports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- school_types : master lookup (Government, Aided, Private, …)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS school_types (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(100) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_school_type_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- syllabi : master lookup (State, CBSE, ICSE, …)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS syllabi (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(100) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_syllabus_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- schools : one team of two participants per school
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schools (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           INT UNSIGNED NOT NULL,
  name              VARCHAR(190) NOT NULL,
  code              VARCHAR(60)  NOT NULL,
  email             VARCHAR(190) DEFAULT NULL,
  participant1_name VARCHAR(190) DEFAULT NULL,
  participant2_name VARCHAR(190) DEFAULT NULL,
  contact_person    VARCHAR(190) DEFAULT NULL,
  address           VARCHAR(500) DEFAULT NULL,
  contact           VARCHAR(60)  DEFAULT NULL,
  school_type_id    INT UNSIGNED DEFAULT NULL,
  syllabus_id       INT UNSIGNED DEFAULT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_school_user (user_id),
  UNIQUE KEY uq_school_code (code),
  KEY idx_school_name (name),
  KEY idx_school_type (school_type_id),
  KEY idx_school_syllabus (syllabus_id),
  CONSTRAINT fk_school_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_school_type FOREIGN KEY (school_type_id) REFERENCES school_types(id) ON DELETE SET NULL,
  CONSTRAINT fk_school_syllabus FOREIGN KEY (syllabus_id) REFERENCES syllabi(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- experts : expert profile
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS experts (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  name       VARCHAR(190) NOT NULL,
  email      VARCHAR(190) DEFAULT NULL,
  phone      VARCHAR(40)  DEFAULT NULL,
  details    TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_expert_user (user_id),
  CONSTRAINT fk_expert_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- question_submissions : batch header from an association
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS question_submissions (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  association_id   INT UNSIGNED NOT NULL,
  submission_date  DATE NOT NULL,
  submitted_by_name VARCHAR(190) DEFAULT NULL,
  contact_email    VARCHAR(190) DEFAULT NULL,
  status           ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
  submitted_at     TIMESTAMP NULL DEFAULT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sub_assoc (association_id),
  KEY idx_sub_status (status),
  CONSTRAINT fk_sub_assoc FOREIGN KEY (association_id) REFERENCES associations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- questions_association : per-question rows tied to a submission
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS questions_association (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  submission_id  INT UNSIGNED NOT NULL,
  question_no    INT UNSIGNED NOT NULL DEFAULT 1,
  question_text  TEXT NOT NULL,
  option_a       VARCHAR(500) NOT NULL,
  option_b       VARCHAR(500) NOT NULL,
  option_c       VARCHAR(500) NOT NULL,
  option_d       VARCHAR(500) NOT NULL,
  correct_option ENUM('A','B','C','D') NOT NULL,
  sport          VARCHAR(190) DEFAULT NULL,
  category       VARCHAR(190) DEFAULT NULL,
  difficulty     ENUM('Easy','Medium','Hard') NOT NULL DEFAULT 'Medium',
  reference_source VARCHAR(500) DEFAULT NULL,
  explanation    TEXT DEFAULT NULL,
  status         ENUM('draft','submitted','accepted','rejected') NOT NULL DEFAULT 'draft',
  reject_reason  VARCHAR(500) DEFAULT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_qa_submission (submission_id),
  KEY idx_qa_status (status),
  CONSTRAINT fk_qa_submission FOREIGN KEY (submission_id) REFERENCES question_submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- questions_master : curated master question bank
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS questions_master (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_text      TEXT NOT NULL,
  option_a           VARCHAR(500) NOT NULL,
  option_b           VARCHAR(500) NOT NULL,
  option_c           VARCHAR(500) NOT NULL,
  option_d           VARCHAR(500) NOT NULL,
  correct_option     ENUM('A','B','C','D') NOT NULL,
  sport              VARCHAR(190) DEFAULT NULL,
  category           VARCHAR(190) DEFAULT NULL,
  difficulty         ENUM('Easy','Medium','Hard') NOT NULL DEFAULT 'Medium',
  reference_source   VARCHAR(500) DEFAULT NULL,
  explanation        TEXT DEFAULT NULL,
  suggested_round    ENUM('round1','round2','either') NOT NULL DEFAULT 'either',
  source_association_id INT UNSIGNED DEFAULT NULL,
  source_question_id INT UNSIGNED DEFAULT NULL,
  created_by_expert_id INT UNSIGNED DEFAULT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_qm_round (suggested_round),
  KEY idx_qm_difficulty (difficulty),
  KEY idx_qm_sport (sport),
  KEY idx_qm_source_assoc (source_association_id),
  KEY idx_qm_source_q (source_question_id),
  KEY idx_qm_expert (created_by_expert_id),
  CONSTRAINT fk_qm_source_assoc FOREIGN KEY (source_association_id) REFERENCES associations(id) ON DELETE SET NULL,
  CONSTRAINT fk_qm_source_q FOREIGN KEY (source_question_id) REFERENCES questions_association(id) ON DELETE SET NULL,
  CONSTRAINT fk_qm_expert FOREIGN KEY (created_by_expert_id) REFERENCES experts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- rounds : Round 1, Round 2
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rounds (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  round_no     TINYINT UNSIGNED NOT NULL,
  name         VARCHAR(60) NOT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_round_no (round_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- slots : time slots per round
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS slots (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  round_id          INT UNSIGNED NOT NULL,
  slot_name         VARCHAR(190) NOT NULL,
  start_time        DATETIME NOT NULL,
  end_time          DATETIME NOT NULL,
  slot_duration_min INT UNSIGNED NOT NULL DEFAULT 30,
  quiz_duration_min INT UNSIGNED NOT NULL DEFAULT 15,
  question_count    INT UNSIGNED NOT NULL DEFAULT 30,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_slot_round (round_id),
  KEY idx_slot_start (start_time),
  CONSTRAINT fk_slot_round FOREIGN KEY (round_id) REFERENCES rounds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- slot_schools : slot <-> school assignment
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS slot_schools (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slot_id    INT UNSIGNED NOT NULL,
  school_id  INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_slot_school (slot_id, school_id),
  KEY idx_ss_school (school_id),
  CONSTRAINT fk_ss_slot FOREIGN KEY (slot_id) REFERENCES slots(id) ON DELETE CASCADE,
  CONSTRAINT fk_ss_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- slot_questions : slot <-> master question assignment with sequence
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS slot_questions (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slot_id      INT UNSIGNED NOT NULL,
  question_id  INT UNSIGNED NOT NULL,
  sequence_no  INT UNSIGNED NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_slot_question (slot_id, question_id),
  KEY idx_sq_slot (slot_id),
  KEY idx_sq_question (question_id),
  CONSTRAINT fk_sq_slot FOREIGN KEY (slot_id) REFERENCES slots(id) ON DELETE CASCADE,
  CONSTRAINT fk_sq_question FOREIGN KEY (question_id) REFERENCES questions_master(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- quiz_sessions : a school's attempt in a slot
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS quiz_sessions (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  school_id   INT UNSIGNED NOT NULL,
  slot_id     INT UNSIGNED NOT NULL,
  round_id    INT UNSIGNED NOT NULL,
  start_time  DATETIME NOT NULL,
  end_time    DATETIME NULL DEFAULT NULL,
  status      ENUM('in_progress','submitted','force_submitted') NOT NULL DEFAULT 'in_progress',
  score       INT UNSIGNED NULL DEFAULT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_session_school_slot (school_id, slot_id),
  KEY idx_qs_slot (slot_id),
  KEY idx_qs_round (round_id),
  KEY idx_qs_status (status),
  CONSTRAINT fk_qs_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
  CONSTRAINT fk_qs_slot FOREIGN KEY (slot_id) REFERENCES slots(id) ON DELETE CASCADE,
  CONSTRAINT fk_qs_round FOREIGN KEY (round_id) REFERENCES rounds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- responses : per-question answers within a session
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS responses (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id      INT UNSIGNED NOT NULL,
  question_id     INT UNSIGNED NOT NULL,
  selected_option ENUM('A','B','C','D') NULL DEFAULT NULL,
  status          ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
  answered_at     TIMESTAMP NULL DEFAULT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_resp_session_q (session_id, question_id),
  KEY idx_resp_session (session_id),
  KEY idx_resp_question (question_id),
  CONSTRAINT fk_resp_session FOREIGN KEY (session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_resp_question FOREIGN KEY (question_id) REFERENCES questions_master(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- results : per school per round result
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS results (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  school_id          INT UNSIGNED NOT NULL,
  round_id           INT UNSIGNED NOT NULL,
  session_id         INT UNSIGNED DEFAULT NULL,
  score              INT UNSIGNED NOT NULL DEFAULT 0,
  total_questions    INT UNSIGNED NOT NULL DEFAULT 0,
  qualified_for_next TINYINT(1) NOT NULL DEFAULT 0,
  declared           TINYINT(1) NOT NULL DEFAULT 0,
  declared_at        TIMESTAMP NULL DEFAULT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_result_school_round (school_id, round_id),
  KEY idx_result_round (round_id),
  KEY idx_result_declared (declared),
  CONSTRAINT fk_result_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
  CONSTRAINT fk_result_round FOREIGN KEY (round_id) REFERENCES rounds(id) ON DELETE CASCADE,
  CONSTRAINT fk_result_session FOREIGN KEY (session_id) REFERENCES quiz_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- settings : key/value configurable defaults
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  setting_key   VARCHAR(100) NOT NULL,
  setting_value VARCHAR(255) NOT NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- audit_log : sensitive actions
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED DEFAULT NULL,
  action     VARCHAR(100) NOT NULL,
  entity     VARCHAR(100) DEFAULT NULL,
  entity_id  VARCHAR(100) DEFAULT NULL,
  details    VARCHAR(500) DEFAULT NULL,
  ip         VARCHAR(45)  DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_user (user_id),
  KEY idx_audit_action (action),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- password_resets : forgot-password tokens (sha256-hashed)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used       TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reset_token (token_hash),
  KEY idx_reset_user (user_id),
  KEY idx_reset_expires (expires_at),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- SEED DATA
-- Default password for all seeded users below: "Password@123"
-- bcrypt hash generated with password_hash(..., PASSWORD_BCRYPT)
-- IMPORTANT: change these in production. Regenerate with database/seeds.php
-- =====================================================================

-- All seed accounts share this bcrypt hash for "Password@123"
SET @seed_hash = '$2y$12$u4TANWFamXKx0nNLHCe35Ox/q6IYqWI.ALuFrl5szd4kCewf.quja';

-- Rounds
INSERT INTO rounds (round_no, name) VALUES
  (1, 'Round 1'),
  (2, 'Round 2')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Master lookups
INSERT INTO school_types (name) VALUES ('Government'), ('Aided'), ('Private')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO syllabi (name) VALUES ('State'), ('CBSE'), ('ICSE'), ('Other')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO sports (name) VALUES
  ('Athletics'), ('Football'), ('Swimming'), ('Basketball'), ('Volleyball'),
  ('Hockey'), ('Badminton'), ('Cricket'), ('Table Tennis'), ('Tennis'),
  ('Kabaddi'), ('Kho-Kho'), ('Handball'), ('Cycling'), ('Boxing'),
  ('Wrestling'), ('Judo'), ('Gymnastics'), ('Weightlifting'), ('Chess')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Default settings
INSERT INTO settings (setting_key, setting_value) VALUES
  ('slot_duration', '30'),
  ('quiz_duration', '15'),
  ('question_count', '30'),
  ('force_submit_on_timeout', '1')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- Admin user
INSERT INTO users (email, password_hash, role, status)
VALUES ('admin@olympicday2026.test', @seed_hash, 'admin', 'active')
ON DUPLICATE KEY UPDATE role = VALUES(role);

-- Association user (event conductor)
INSERT INTO users (email, password_hash, role, status)
VALUES ('association@olympicday2026.test', @seed_hash, 'association', 'active')
ON DUPLICATE KEY UPDATE role = VALUES(role);

INSERT INTO associations (user_id, name, contact_person, contact_email, contact_phone, is_event_conductor)
SELECT id, 'Kerala Athletics Association', 'A. Conductor', 'association@olympicday2026.test', '9000000001', 1
FROM users WHERE email = 'association@olympicday2026.test'
ON DUPLICATE KEY UPDATE is_event_conductor = 1;

-- Expert user
INSERT INTO users (email, password_hash, role, status)
VALUES ('expert@olympicday2026.test', @seed_hash, 'expert', 'active')
ON DUPLICATE KEY UPDATE role = VALUES(role);

INSERT INTO experts (user_id, name, email, phone)
SELECT id, 'Dr. Expert Reviewer', 'expert@olympicday2026.test', '9000000002'
FROM users WHERE email = 'expert@olympicday2026.test'
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Sample schools
INSERT INTO users (email, password_hash, role, status)
VALUES ('school1@olympicday2026.test', @seed_hash, 'school', 'active')
ON DUPLICATE KEY UPDATE role = VALUES(role);

INSERT INTO schools (user_id, name, code, email, participant1_name, participant2_name, address, contact)
SELECT id, 'St. Thomas HSS', 'SCH001', 'school1@olympicday2026.test', 'Participant One', 'Participant Two', 'Kochi, Kerala', '9000000010'
FROM users WHERE email = 'school1@olympicday2026.test'
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO users (email, password_hash, role, status)
VALUES ('school2@olympicday2026.test', @seed_hash, 'school', 'active')
ON DUPLICATE KEY UPDATE role = VALUES(role);

INSERT INTO schools (user_id, name, code, email, participant1_name, participant2_name, address, contact)
SELECT id, 'Govt. Model HSS', 'SCH002', 'school2@olympicday2026.test', 'Participant Three', 'Participant Four', 'Thiruvananthapuram, Kerala', '9000000011'
FROM users WHERE email = 'school2@olympicday2026.test'
ON DUPLICATE KEY UPDATE name = VALUES(name);
