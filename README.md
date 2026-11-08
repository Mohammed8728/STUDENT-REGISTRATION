# Lab 02 - Library Book Borrowing
PHP 8 + MySQL/MariaDB, PDO prepared statements, server-side validation.

## Files in this folder (only 4)
| File | What it contains |
|---|---|
| `index.php` | Everything in one file: MySQL login (top of the file), the HTML form (Task A), PHP validation (Task B), database insert (Task C) and the extension page (open `index.php?view=list`). |
| `style.css` | All the CSS |
| `database.sql` | Database `lab02_library`: tables, constraints and sample data |
| `README.md` | This file |

## Setup (XAMPP on Windows)
1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Copy this folder (`lab02_library_borrowing`) into `C:\xampp\htdocs\`.
3. Open **http://localhost/phpmyadmin** > **Import** > choose `database.sql` > **Go**. (It creates the database `lab02_library`; importing twice is safe.)
4. Open **http://localhost/lab02_library_borrowing/** in the browser.
5. If your MySQL account is not `root` with an empty password, change the `$DB` line at the top of `index.php`.

Requires PHP 8.1+ with `pdo_mysql`, `mbstring` and `fileinfo` (all enabled in XAMPP by default).

## Validation rules
- Student ID, ISBN, title, borrow date and due date: mandatory.
- Student ID: 4-20 letters, digits or hyphens (stored upper-case).
- ISBN: hyphens/spaces ignored; ISBN-10 (last character may be X) or ISBN-13, and the check digit must be correct.
- Borrow date: real date, not in the future. Due date: real date, strictly after the borrow date.
- Loan duration: optional; if given it must equal the number of days between the dates. If empty it is calculated. Assumption: maximum loan is 30 days.
- Purpose: optional, max 255 characters. Agreement to library rules: mandatory.
- ACTIVE LOAN (defined): a loan is active while `is_active = 1` (not returned). Returning a book sets `is_active = NULL` and `returned_date`. A second ACTIVE loan of the same book by the same student is rejected (application check + UNIQUE key).

All validation runs on the server (the form uses `novalidate` so that the server messages can be demonstrated; browser attributes such as `required`/`type` are kept only to help users). Each field shows its own message and keeps the typed (HTML-escaped) value after a rejected submission.

## Database design
`loans` (PK `loan_id`, dates, `is_active`, UNIQUE (`student_id`,`isbn`,`is_active`), CHECK due_date > borrow_date).

Business rules are enforced twice: by a friendly check in PHP **and** by a database constraint (so a race condition cannot create a bad row).

## Security and reliability
- Every SQL statement uses PDO prepared statements with real server-side preparation (no concatenation of user input).
- All output is escaped with `htmlspecialchars` (XSS); a CSRF token protects the POST.
- "Success" is shown only when the insert returned exactly 1 affected row, followed by a redirect (Post/Redirect/Get) so refreshing does not resubmit.
- Database errors are logged with `error_log` and the user sees a generic message; no SQL or stack trace is exposed (tested with MySQL stopped).

## Assumptions
Returned loans are not modelled by a form (not required). To return a book run: `UPDATE loans SET is_active = NULL, returned_date = CURDATE() WHERE loan_id = ?;` after which the student may borrow that book again (tested).

## Test cases and results
Executed on 2026-11-07 against PHP 8.3.6 and MariaDB 10.11 by sending real HTTP requests to the form (session + CSRF token). Result: **11 of 11 passed.** All data is fictional.

| # | Category | Test | Expected | Actual result | Pass |
|---|---|---|---|---|---|
| 02-1 | Valid | ISBN-13 loan, 14 days | Saved: Loan recorded | SAVED - Loan recorded. Loan ID #1, due on 2026-11-18. | PASS |
| 02-2 | Missing required | No student ID, no agreement | Rejected: Student ID is required | REJECTED - Student ID is required. / You must agree to the library rules. / Please correct the highlighted fields and submit again. | PASS |
| 02-3 | Invalid value | ISBN with wrong check digit | Rejected: valid ISBN | REJECTED - Enter a valid ISBN-10 (X allowed as last character) or ISBN-13. / Please correct the highlighted fields and submit again. | PASS |
| 02-4 | Invalid value | Due date before borrow date | Rejected: after the borrow date | REJECTED - Due date must be after the borrow date. / Please correct the highlighted fields and submit again. | PASS |
| 02-5 | Invalid value | Borrow date in the future | Rejected: cannot be in the future | REJECTED - Borrow date cannot be in the future. / Duration does not match the dates (7 days). / Please correct the highlighted fields and submit again. | PASS |
| 02-6 | Invalid value | Duration does not match dates | Rejected: does not match | REJECTED - Duration does not match the dates (14 days). / Please correct the highlighted fields and submit again. | PASS |
| 02-7 | Duplicate | Same student borrows the same book again while loan is active | Rejected: already has an active loan | REJECTED - This student already has an active loan for this book. Nothing was saved. / Please correct the highlighted fields and submit again. | PASS |
| 02-8 | Valid | Same student, different book (ISBN-10 ending in X) | Saved: Loan recorded | SAVED - Loan recorded. Loan ID #2, due on 2026-11-18. | PASS |
| 02-9 | Valid | Different student may borrow the first book | Saved: Loan recorded | SAVED - Loan recorded. Loan ID #3, due on 2026-11-18. | PASS |
| 02-CSRF | Security | POST without CSRF token | Rejected: session expired | REJECTED - Your session expired. Please reload the page and try again. | PASS |
| 02-X | Extension | Extension: active loans listed | HTTP 200 and contains "STU1001" | HTTP 200, contains: True | PASS |

**Database failure test:** with the MySQL server stopped, the page shows "The system could not complete your request because of a database problem. Nothing was saved. Please try again later." No stack trace appears and a POST never reports success.

## Database evidence (queries run after the tests)
```
-- Saved loans
SELECT loan_id,student_id,isbn,book_title,borrow_date,due_date,loan_days,is_active FROM loans;
+---------+------------+---------------+----------------------------+-------------+------------+-----------+-----------+
| loan_id | student_id | isbn          | book_title                 | borrow_date | due_date   | loan_days | is_active |
+---------+------------+---------------+----------------------------+-------------+------------+-----------+-----------+
|       1 | STU1001    | 9780306406157 | Introduction to Algorithms | 2026-11-04  | 2026-11-18 |        14 |         1 |
|       2 | STU1001    | 080442957X    | Another Book               | 2026-11-04  | 2026-11-18 |        14 |         1 |
|       3 | STU2002    | 9780306406157 | Introduction to Algorithms | 2026-11-04  | 2026-11-18 |        14 |         1 |
+---------+------------+---------------+----------------------------+-------------+------------+-----------+-----------+

-- Duplicate active loans (must be empty)
SELECT student_id,isbn,COUNT(*) c FROM loans WHERE is_active=1 GROUP BY student_id,isbn HAVING c>1;
(no rows)
-- Return the first loan, then the same student can borrow it again
UPDATE loans SET is_active=NULL, returned_date=CURDATE() WHERE loan_id=(SELECT m FROM (SELECT MIN(loan_id) m FROM loans) x);
(no rows)
```

## Screenshots to take for your report
1. The empty form. 2. A valid submission with the success message. 3. A missing required value (errors visible). 4. An invalid value. 5. The duplicate/conflict being rejected. 6. phpMyAdmin showing the saved rows. 7. The extension page (`index.php?view=list`).
