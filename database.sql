-- LAB 01 - Student Registration
-- phpMyAdmin > Import > choose this file > Go
CREATE DATABASE IF NOT EXISTS lab01_students CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lab01_students;

-- Reference table: degree programmes (one row per programme, referenced by students)
CREATE TABLE IF NOT EXISTS programmes (
    id   TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_programme_name (name)
) ENGINE=InnoDB;

INSERT IGNORE INTO programmes (name) VALUES
 ('Software Engineering'), ('Computer Networks'), ('Information Systems'), ('Cybersecurity');

-- Main table: one row per registered student
CREATE TABLE IF NOT EXISTS students (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,          -- primary key
    full_name       VARCHAR(80)  NOT NULL,
    email           VARCHAR(190) NOT NULL,                         -- stored lower-case
    telephone       CHAR(9)      NOT NULL,                         -- 9 digits, no prefix
    date_of_birth   DATE         NOT NULL,
    programme_id    TINYINT UNSIGNED NOT NULL,
    level           TINYINT UNSIGNED NOT NULL,                     -- degree year 1-4
    gender          ENUM('Male','Female') NULL,
    policy_accepted TINYINT(1)   NOT NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP, -- submission timestamp
    PRIMARY KEY (id),
    UNIQUE KEY uq_student_email (email),                           -- rejects duplicate e-mails
    CONSTRAINT fk_student_programme FOREIGN KEY (programme_id) REFERENCES programmes (id),
    CONSTRAINT chk_level  CHECK (level BETWEEN 1 AND 4),
    CONSTRAINT chk_policy CHECK (policy_accepted = 1)
) ENGINE=InnoDB;
