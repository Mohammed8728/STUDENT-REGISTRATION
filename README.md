# Lab 01 - Student Registration
PHP 8 + MySQL/MariaDB, PDO prepared statements, server-side validation.

## Files in this folder (only 4)
| File | What it contains |
|---|---|
| `index.php` | Everything in one file: MySQL login (top of the file), the HTML form (Task A), PHP validation (Task B), database insert (Task C) and the extension page (open `index.php?view=list`). |
| `style.css` | All the CSS |
| `database.sql` | Database `lab01_students`: tables, constraints and sample data |
| `README.md` | This file |

## Setup (XAMPP on Windows)
1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Copy this folder (`lab01_student_registration`) into `C:\xampp\htdocs\`.
3. Open **http://localhost/phpmyadmin** > **Import** > choose `database.sql` > **Go**. (It creates the database `lab01_students`; importing twice is safe.)
4. Open **http://localhost/lab01_student_registration/** in the browser.
5. If your MySQL account is not `root` with an empty password, change the `$DB` line at the top of `index.php`.

Requires PHP 8.1+ with `pdo_mysql`, `mbstring` and `fileinfo` (all enabled in XAMPP by default).

## Validation rules
- Full name: 3-80 characters; letters (any language) plus space . ' -
- Email: valid format, stored lower-case, UNIQUE per student (duplicate -> rejected, no new row).
- Telephone (documented local format): 9 digits starting with 6 (mobile) or 2 (landline), e.g. 677 123 456. Spaces/dots/dashes and an optional +237 or 237 prefix are removed first; 9 digits are stored.
- Date of birth: real calendar date, strictly in the past, not before 1900.
- Programme, level and policy acceptance: mandatory (programme/level must be one of the offered values).
- Gender: optional; if sent it must be Male or Female.

All validation runs on the server (the form uses `novalidate` so that the server messages can be demonstrated; browser attributes such as `required`/`type` are kept only to help users). Each field shows its own message and keeps the typed (HTML-escaped) value after a rejected submission.

## Database design
`programmes` (lookup, seeded) and `students` (PK `id`, UNIQUE `email`, FK `programme_id`, CHECK level 1-4, `created_at` timestamp).

Business rules are enforced twice: by a friendly check in PHP **and** by a database constraint (so a race condition cannot create a bad row).

## Security and reliability
- Every SQL statement uses PDO prepared statements with real server-side preparation (no concatenation of user input).
- All output is escaped with `htmlspecialchars` (XSS); a CSRF token protects the POST.
- "Success" is shown only when the insert returned exactly 1 affected row, followed by a redirect (Post/Redirect/Get) so refreshing does not resubmit.
- Database errors are logged with `error_log` and the user sees a generic message; no SQL or stack trace is exposed (tested with MySQL stopped).

## Assumptions
Gender is not listed as mandatory, so it is optional. The "Option A / Option B" placeholders were replaced by Male / Female. Levels are Year 1-4 and programmes are sample values stored in the `programmes` table.

## Test cases and results
Executed on 2026-11-07 against PHP 8.3.6 and MariaDB 10.11 by sending real HTTP requests to the form (session + CSRF token). Result: **13 of 13 passed.** All data is fictional.

| # | Category | Test | Expected | Actual result | Pass |
|---|---|---|---|---|---|
| 01-1 | Valid | All fields valid | Saved: Registration successful | SAVED - Registration successful. Student record #1 was saved. | PASS |
| 01-2 | Missing required | Empty name, no policy acceptance | Rejected: Full name is required | REJECTED - Full name is required. / You must accept the registration policy. / Please correct the highlighted fields and submit again. | PASS |
| 01-3 | Invalid value | Email without domain | Rejected: valid email | REJECTED - Enter a valid email address (e.g. name@example.com). / Please correct the highlighted fields and submit again. | PASS |
| 01-4 | Invalid value | Telephone with 5 digits | Rejected: 9 digits | REJECTED - Enter 9 digits starting with 6 or 2 (e.g. 677 123 456). / Please correct the highlighted fields and submit again. | PASS |
| 01-5 | Invalid value | Date of birth in the future | Rejected: must be in the past | REJECTED - Date of birth must be in the past. / Please correct the highlighted fields and submit again. | PASS |
| 01-6 | Invalid value | Name with digits | Rejected: letters and spaces | REJECTED - Use letters and spaces only (. ' - are allowed). / Please correct the highlighted fields and submit again. | PASS |
| 01-7 | Duplicate | Same email, different letter case | Rejected: already registered | REJECTED - This email is already registered. Nothing was saved. / Please correct the highlighted fields and submit again. | PASS |
| 01-8 | Security | SQL injection text in name is rejected, table survives | Rejected: letters and spaces | REJECTED - Use letters and spaces only (. ' - are allowed). / Please correct the highlighted fields and submit again. | PASS |
| 01-9 | Valid | Apostrophe + hyphen name and +237 phone prefix | Saved: Registration successful | SAVED - Registration successful. Student record #2 was saved. | PASS |
| 01-CSRF | Security | POST without CSRF token | Rejected: session expired | REJECTED - Your session expired. Please reload the page and try again. | PASS |
| 01-X | Extension | Extension: list page shows saved rows | HTTP 200 and contains "alice.ngwa@example.com" | HTTP 200, contains: True | PASS |
| 01-X | Extension | Extension: search finds "jean" | HTTP 200 and contains "Neil" | HTTP 200, contains: True | PASS |
| 01-X | Extension | Extension: search with no match | HTTP 200 and contains "No records found" | HTTP 200, contains: True | PASS |

**Database failure test:** with the MySQL server stopped, the page shows "The system could not complete your request because of a database problem. Nothing was saved. Please try again later." No stack trace appears and a POST never reports success.

## Database evidence (queries run after the tests)
```
-- Saved rows
SELECT s.id,s.full_name,s.email,s.telephone,s.date_of_birth,p.name programme,s.level,s.created_at FROM students s JOIN programmes p ON p.id=s.programme_id;
+----+--------------------+------------------------+-----------+---------------+----------------------+-------+---------------------+
| id | full_name          | email                  | telephone | date_of_birth | programme            | level | created_at          |
+----+--------------------+------------------------+-----------+---------------+----------------------+-------+---------------------+
|  1 | Alice Ngwa         | alice.ngwa@example.com | 677123456 | 2003-05-14    | Software Engineering |     3 | 2026-11-07 09:08:35 |
|  2 | Jean-Pierre O'Neil | jp.oneil@example.com   | 677123457 | 2003-05-14    | Software Engineering |     3 | 2026-11-07 09:08:35 |
+----+--------------------+------------------------+-----------+---------------+----------------------+-------+---------------------+

-- Table still exists after injection attempt
SELECT COUNT(*) AS students_rows FROM students;
+---------------+
| students_rows |
+---------------+
|             2 |
+---------------+

-- Duplicate emails (must be empty)
SELECT email,COUNT(*) c FROM students GROUP BY email HAVING c>1;
(no rows)
```

## Screenshots to take for your report
1. The empty form. 2. A valid submission with the success message. 3. A missing required value (errors visible). 4. An invalid value. 5. The duplicate/conflict being rejected. 6. phpMyAdmin showing the saved rows. 7. The extension page (`index.php?view=list`).
