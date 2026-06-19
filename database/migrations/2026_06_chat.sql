-- =====================================================================
-- Migration: chat (school <-> admin/expert messaging)
-- Safe to run once on an existing database.
-- =====================================================================

CREATE TABLE IF NOT EXISTS chat_threads (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  school_id       INT UNSIGNED NOT NULL,
  last_message_at DATETIME NULL DEFAULT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_chat_school (school_id),
  KEY idx_chat_last (last_message_at),
  CONSTRAINT fk_chat_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  thread_id      INT UNSIGNED NOT NULL,
  sender_user_id INT UNSIGNED NULL DEFAULT NULL,
  sender_role    ENUM('school','admin','expert') NOT NULL,
  body           TEXT NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_msg_thread (thread_id, id),
  CONSTRAINT fk_msg_thread FOREIGN KEY (thread_id) REFERENCES chat_threads(id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_user FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_reads (
  thread_id    INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  last_read_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (thread_id, user_id),
  CONSTRAINT fk_read_thread FOREIGN KEY (thread_id) REFERENCES chat_threads(id) ON DELETE CASCADE,
  CONSTRAINT fk_read_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
