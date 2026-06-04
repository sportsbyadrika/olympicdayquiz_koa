-- =====================================================================
-- Migration: sports master + association_sports (Sport Managed)
-- Safe to run once on an existing database.
-- =====================================================================

CREATE TABLE IF NOT EXISTS sports (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(100) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sport_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS association_sports (
  association_id INT UNSIGNED NOT NULL,
  sport_id       INT UNSIGNED NOT NULL,
  PRIMARY KEY (association_id, sport_id),
  KEY idx_as_sport (sport_id),
  CONSTRAINT fk_as_assoc FOREIGN KEY (association_id) REFERENCES associations(id) ON DELETE CASCADE,
  CONSTRAINT fk_as_sport FOREIGN KEY (sport_id) REFERENCES sports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sports (name) VALUES
  ('Athletics'), ('Football'), ('Swimming'), ('Basketball'), ('Volleyball'),
  ('Hockey'), ('Badminton'), ('Cricket'), ('Table Tennis'), ('Tennis'),
  ('Kabaddi'), ('Kho-Kho'), ('Handball'), ('Cycling'), ('Boxing'),
  ('Wrestling'), ('Judo'), ('Gymnastics'), ('Weightlifting'), ('Chess')
ON DUPLICATE KEY UPDATE name = VALUES(name);
