# Lab 05 - Event Registration
PHP 8 + MySQL/MariaDB, PDO prepared statements, server-side validation.

## Files in this folder (only 4)
| File | What it contains |
|---|---|
| `index.php` | Everything in one file: MySQL login (top of the file), the HTML form (Task A), PHP validation (Task B), database insert (Task C) and the extension page (open `index.php?view=list`). |
| `style.css` | All the CSS |
| `database.sql` | Database `lab05_events`: tables, constraints and sample data |
| `README.md` | This file |

## Setup (XAMPP on Windows)
1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Copy this folder (`lab05_event_registration`) into `C:\xampp\htdocs\`.
3. Open **http://localhost/phpmyadmin** > **Import** > choose `database.sql` > **Go**. (It creates the database `lab05_events`; importing twice is safe.)
4. Open **http://localhost/lab05_event_registration/** in the browser.
5. If your MySQL account is not `root` with an empty password, change the `$DB` line at the top of `index.php`.

Requires PHP 8.1+ with `pdo_mysql`, `mbstring` and `fileinfo` (all enabled in XAMPP by default).

## Validation rules
- Participant name (3-80 chars, letters), email, workshop, attendance mode and terms: mandatory.
- Email: valid format, stored lower-case.
- Seats: whole number from 1 to 3.
- Special requirements: optional, max 300 characters. Phone: optional; if given, same 9-digit format as the other labs.
- Attendance mode: In-person or Online.
- Same email + same workshop is rejected; the same email may register for a DIFFERENT workshop (UNIQUE on the pair, not on email alone).

All validation runs on the server (the form uses `novalidate` so that the server messages can be demonstrated; browser attributes such as `required`/`type` are kept only to help users). Each field shows its own message and keeps the typed (HTML-escaped) value after a rejected submission.

## Database design
`workshops` (lookup, seeded) and `registrations` (PK `id`, UNIQUE (`email`,`workshop_id`), FK workshop, CHECK seats 1-3).

Business rules are enforced twice: by a friendly check in PHP **and** by a database constraint (so a race condition cannot create a bad row).

## Security and reliability
- Every SQL statement uses PDO prepared statements with real server-side preparation (no concatenation of user input).
- All output is escaped with `htmlspecialchars` (XSS); a CSRF token protects the POST.
- "Success" is shown only when the insert returned exactly 1 affected row, followed by a redirect (Post/Redirect/Get) so refreshing does not resubmit.
- Database errors are logged with `error_log` and the user sees a generic message; no SQL or stack trace is exposed (tested with MySQL stopped).

## Assumptions
Workshop titles and dates are sample data. No capacity limit was requested, so none is enforced.

## Test cases and results
Executed on 2026-11-07 against PHP 8.3.6 and MariaDB 10.11 by sending real HTTP requests to the form (session + CSRF token). Result: **10 of 10 passed.** All data is fictional.

| # | Category | Test | Expected | Actual result | Pass |
|---|---|---|---|---|---|
| 05-1 | Valid | Online registration, 2 seats | Saved: confirmed for 2 seat | SAVED - Registration #1 confirmed for 2 seat(s). | PASS |
| 05-2 | Missing required | No name, no mode, terms unchecked | Rejected: Participant name is required | REJECTED - Participant name is required. / Please choose an attendance mode. / You must accept the event terms. / Please correct the highlighted fields and submit again. | PASS |
| 05-3 | Invalid value | 4 seats (maximum is 3) | Rejected: 1 to 3 | REJECTED - Seats must be a whole number from 1 to 3. / Please correct the highlighted fields and submit again. | PASS |
| 05-4 | Invalid value | 0 seats | Rejected: 1 to 3 | REJECTED - Seats must be a whole number from 1 to 3. / Please correct the highlighted fields and submit again. | PASS |
| 05-5 | Invalid value | Invalid email | Rejected: valid email | REJECTED - Enter a valid email address. / Please correct the highlighted fields and submit again. | PASS |
| 05-6 | Invalid value | Special requirements of 301 characters | Rejected: 300 characters | REJECTED - Maximum 300 characters (currently 301). / Please correct the highlighted fields and submit again. | PASS |
| 05-7 | Duplicate | Same email for the same workshop | Rejected: already registered for this workshop | REJECTED - This email is already registered for this workshop. Nothing was saved. / Please correct the highlighted fields and submit again. | PASS |
| 05-8 | Valid | Same email, DIFFERENT workshop is allowed | Saved: confirmed for 1 seat | SAVED - Registration #2 confirmed for 1 seat(s). | PASS |
| 05-CSRF | Security | POST without CSRF token | Rejected: session expired | REJECTED - Your session expired. Please reload the page and try again. | PASS |
| 05-X | Extension | Extension: totals per workshop | HTTP 200 and contains "Web Security Essentials" | HTTP 200, contains: True | PASS |

**Database failure test:** with the MySQL server stopped, the page shows "The system could not complete your request because of a database problem. Nothing was saved. Please try again later." No stack trace appears and a POST never reports success.

## Database evidence (queries run after the tests)
```
-- Saved registrations
SELECT id,participant_name,email,workshop_id,attendance_mode,seats,created_at FROM registrations;
+----+------------------+-------------------------+-------------+-----------------+-------+---------------------+
| id | participant_name | email                   | workshop_id | attendance_mode | seats | created_at          |
+----+------------------+-------------------------+-------------+-----------------+-------+---------------------+
|  1 | Daniel Mbah      | daniel.mbah@example.com |           1 | Online          |     2 | 2026-11-07 09:08:35 |
|  2 | Daniel Mbah      | daniel.mbah@example.com |           2 | In-person       |     1 | 2026-11-07 09:08:35 |
+----+------------------+-------------------------+-------------+-----------------+-------+---------------------+

-- Totals per workshop
SELECT w.title,COUNT(r.id) registrations,COALESCE(SUM(r.seats),0) seats FROM workshops w LEFT JOIN registrations r ON r.workshop_id=w.id GROUP BY w.id;
+---------------------------------+---------------+-------+
| title                           | registrations | seats |
+---------------------------------+---------------+-------+
| Introduction to Cloud Computing |             1 |     2 |
| Web Security Essentials         |             1 |     1 |
| Data Analysis with Python       |             0 |     0 |
| Agile Project Management        |             0 |     0 |
+---------------------------------+---------------+-------+
```

## Screenshots to take for your report
1. The empty form. 2. A valid submission with the success message. 3. A missing required value (errors visible). 4. An invalid value. 5. The duplicate/conflict being rejected. 6. phpMyAdmin showing the saved rows. 7. The extension page (`index.php?view=list`).
