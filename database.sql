-- LAB 06 - Customer Support Ticket
CREATE DATABASE IF NOT EXISTS lab06_support CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lab06_support;

CREATE TABLE IF NOT EXISTS issue_categories (
    id   TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(60) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_issue_category (name)
) ENGINE=InnoDB;
INSERT IGNORE INTO issue_categories (name) VALUES ('Login / Account'), ('Software Bug'), ('Hardware'), ('Network / Internet'), ('Billing'), ('Other');

CREATE TABLE IF NOT EXISTS support_tickets (
    ticket_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,           -- ticket ID
    ticket_ref      VARCHAR(20)  NULL,                              -- public reference, e.g. TCK-2026-00012
    customer_name   VARCHAR(80)  NOT NULL,
    email           VARCHAR(190) NOT NULL,
    category_id     TINYINT UNSIGNED NOT NULL,
    priority        ENUM('Low','Medium','High') NOT NULL,
    subject         VARCHAR(120) NOT NULL,
    description     TEXT         NOT NULL,
    screenshot_file VARCHAR(40)  NULL,                              -- server-generated name only
    status          ENUM('Pending','In Progress','Resolved','Closed') NOT NULL DEFAULT 'Pending',
    confirmed       TINYINT(1)   NOT NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ticket_id),
    UNIQUE KEY uq_ticket_ref (ticket_ref),
    KEY idx_status (status),
    CONSTRAINT fk_ticket_category FOREIGN KEY (category_id) REFERENCES issue_categories (id),
    CONSTRAINT chk_confirmed CHECK (confirmed = 1)
) ENGINE=InnoDB;
