-- =====================================================================
-- Migration: cancel individual questions within a slot
-- Safe to run once on an existing database.
-- =====================================================================

ALTER TABLE slot_questions
  ADD COLUMN cancelled TINYINT(1) NOT NULL DEFAULT 0 AFTER sequence_no;
