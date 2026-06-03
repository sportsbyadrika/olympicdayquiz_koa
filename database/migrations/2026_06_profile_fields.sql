-- =====================================================================
-- Migration: profile field additions (Jun 2026)
-- Adds association district/address, expert details, school contact_person
-- and master-table dropdowns (school_types, syllabi).
--
-- Safe to run once on an existing database that was created from the
-- original schema.sql. Fresh installs already include these via schema.sql.
-- =====================================================================

-- Master lookup tables ------------------------------------------------
CREATE TABLE IF NOT EXISTS school_types (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(100) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_school_type_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS syllabi (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(100) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_syllabus_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO school_types (name) VALUES ('Government'), ('Aided'), ('Private')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO syllabi (name) VALUES ('State'), ('CBSE'), ('ICSE'), ('Other')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Associations: district + address ------------------------------------
ALTER TABLE associations
  ADD COLUMN district VARCHAR(190) DEFAULT NULL AFTER contact_phone,
  ADD COLUMN address  VARCHAR(500) DEFAULT NULL AFTER district;

-- Experts: details ----------------------------------------------------
ALTER TABLE experts
  ADD COLUMN details TEXT DEFAULT NULL AFTER phone;

-- Schools: contact_person + master FKs --------------------------------
ALTER TABLE schools
  ADD COLUMN contact_person VARCHAR(190) DEFAULT NULL AFTER participant2_name,
  ADD COLUMN school_type_id INT UNSIGNED DEFAULT NULL AFTER contact,
  ADD COLUMN syllabus_id    INT UNSIGNED DEFAULT NULL AFTER school_type_id,
  ADD KEY idx_school_type (school_type_id),
  ADD KEY idx_school_syllabus (syllabus_id),
  ADD CONSTRAINT fk_school_type FOREIGN KEY (school_type_id) REFERENCES school_types(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_school_syllabus FOREIGN KEY (syllabus_id) REFERENCES syllabi(id) ON DELETE SET NULL;
