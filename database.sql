-- LAB 02 - Library Book Borrowing
CREATE DATABASE IF NOT EXISTS lab02_library CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lab02_library;

-- ACTIVE LOAN DEFINITION: a loan is "active" while is_active = 1 (book not yet returned).
-- When the book comes back, set is_active = NULL and fill returned_date.
-- UNIQUE(student_id, isbn, is_active) then blocks a 2nd ACTIVE loan of the same book by the same
-- student, because MySQL allows many NULLs: returned loans never conflict, active ones (1) do.
CREATE TABLE IF NOT EXISTS loans (
    loan_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,           -- unique loan ID
    student_id     VARCHAR(20)  NOT NULL,
    isbn           VARCHAR(13)  NOT NULL,                          -- digits only (X allowed last in ISBN-10)
    book_title     VARCHAR(200) NOT NULL,
    borrow_date    DATE         NOT NULL,
    due_date       DATE         NOT NULL,
    loan_days      SMALLINT UNSIGNED NOT NULL,
    purpose        VARCHAR(255) NULL,
    rules_accepted TINYINT(1)   NOT NULL,
    is_active      TINYINT(1)   NULL DEFAULT 1,
    returned_date  DATE         NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (loan_id),
    UNIQUE KEY uq_one_active_loan (student_id, isbn, is_active),
    KEY idx_active_due (is_active, due_date),
    CONSTRAINT chk_due_after_borrow CHECK (due_date > borrow_date),
    CONSTRAINT chk_rules CHECK (rules_accepted = 1)
) ENGINE=InnoDB;
