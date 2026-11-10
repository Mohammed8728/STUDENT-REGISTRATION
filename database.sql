-- LAB 05 - Event Registration
CREATE DATABASE IF NOT EXISTS lab05_events CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lab05_events;

CREATE TABLE IF NOT EXISTS workshops (
    id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title         VARCHAR(100) NOT NULL,
    workshop_date DATE NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workshop_title (title)
) ENGINE=InnoDB;
INSERT IGNORE INTO workshops (title, workshop_date) VALUES
 ('Introduction to Cloud Computing', '2026-12-10'),
 ('Web Security Essentials',         '2026-12-12'),
 ('Data Analysis with Python',       '2026-12-17'),
 ('Agile Project Management',        '2026-12-19');

CREATE TABLE IF NOT EXISTS registrations (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    participant_name     VARCHAR(80)  NOT NULL,
    email                VARCHAR(190) NOT NULL,                      -- stored lower-case
    phone                CHAR(9)      NULL,
    workshop_id          SMALLINT UNSIGNED NOT NULL,                 -- chosen workshop
    attendance_mode      ENUM('In-person','Online') NOT NULL,
    seats                TINYINT UNSIGNED NOT NULL,
    special_requirements VARCHAR(300) NULL,
    terms_accepted       TINYINT(1)   NOT NULL,
    created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- uniqueness is e-mail + workshop (NOT e-mail alone): the same person may join different workshops
    UNIQUE KEY uq_email_workshop (email, workshop_id),
    CONSTRAINT fk_reg_workshop FOREIGN KEY (workshop_id) REFERENCES workshops (id),
    CONSTRAINT chk_seats CHECK (seats BETWEEN 1 AND 3),
    CONSTRAINT chk_terms CHECK (terms_accepted = 1)
) ENGINE=InnoDB;
