-- LAB 03 - Clinic Appointment Booking
CREATE DATABASE IF NOT EXISTS lab03_clinic CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lab03_clinic;

CREATE TABLE IF NOT EXISTS departments (
    id   TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(60) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_department_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS doctors (
    id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name     VARCHAR(80) NOT NULL,
    department_id TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_doctor_department FOREIGN KEY (department_id) REFERENCES departments (id)
) ENGINE=InnoDB;

-- Fictional sample staff (no real people)
INSERT IGNORE INTO departments (name) VALUES ('General Medicine'), ('Paediatrics'), ('Cardiology'), ('Dermatology');
INSERT INTO doctors (full_name, department_id)
SELECT * FROM (
    SELECT 'Dr. Neba Fon' AS n, (SELECT id FROM departments WHERE name='General Medicine') AS d UNION ALL
    SELECT 'Dr. Amina Bello', (SELECT id FROM departments WHERE name='General Medicine') UNION ALL
    SELECT 'Dr. Paul Etoga', (SELECT id FROM departments WHERE name='Paediatrics') UNION ALL
    SELECT 'Dr. Grace Mbarga', (SELECT id FROM departments WHERE name='Cardiology') UNION ALL
    SELECT 'Dr. Samuel Ndzi', (SELECT id FROM departments WHERE name='Dermatology')
) seed WHERE NOT EXISTS (SELECT 1 FROM doctors);

CREATE TABLE IF NOT EXISTS appointments (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_name     VARCHAR(80)  NOT NULL,
    email            VARCHAR(190) NULL,                            -- optional
    phone            CHAR(9)      NOT NULL,
    department_id    TINYINT UNSIGNED NOT NULL,
    doctor_id        SMALLINT UNSIGNED NOT NULL,
    appointment_date DATE         NOT NULL,
    time_slot        TIME         NOT NULL,
    reason           VARCHAR(500) NOT NULL,
    consent          TINYINT(1)   NOT NULL,
    status           ENUM('Pending','Confirmed','Cancelled','Completed') NOT NULL DEFAULT 'Pending',
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- A doctor cannot be double-booked for the same date + slot (also true for cancelled rows;
    -- delete or move a cancelled booking to free the slot).
    UNIQUE KEY uq_doctor_slot (doctor_id, appointment_date, time_slot),
    CONSTRAINT fk_appt_department FOREIGN KEY (department_id) REFERENCES departments (id),
    CONSTRAINT fk_appt_doctor     FOREIGN KEY (doctor_id)     REFERENCES doctors (id),
    CONSTRAINT chk_consent CHECK (consent = 1)
) ENGINE=InnoDB;
