# Lab 03 - Clinic Appointment Booking
PHP 8 + MySQL/MariaDB, PDO prepared statements, server-side validation.

## Files in this folder (only 4)
| File | What it contains |
|---|---|
| `index.php` | Everything in one file: MySQL login (top of the file), the HTML form (Task A), PHP validation (Task B), database insert (Task C) and the extension page (open `index.php?view=list`). |
| `style.css` | All the CSS |
| `database.sql` | Database `lab03_clinic`: tables, constraints and sample data |
| `README.md` | This file |

## Setup (XAMPP on Windows)
1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Copy this folder (`lab03_clinic_appointments`) into `C:\xampp\htdocs\`.
3. Open **http://localhost/phpmyadmin** > **Import** > choose `database.sql` > **Go**. (It creates the database `lab03_clinic`; importing twice is safe.)
4. Open **http://localhost/lab03_clinic_appointments/** in the browser.
5. If your MySQL account is not `root` with an empty password, change the `$DB` line at the top of `index.php`.

Requires PHP 8.1+ with `pdo_mysql`, `mbstring` and `fileinfo` (all enabled in XAMPP by default).

## Validation rules
- Patient name (3-80 chars, letters), phone, department, doctor, date and time slot: mandatory.
- Phone: same documented format as Lab 01 (9 digits starting with 6 or 2; optional +237 prefix).
- Email: optional, but must be valid when supplied.
- Appointment date: real date strictly after today.
- Doctor must belong to the selected department.
- Time slot must be one of the offered slots (08:00-11:00, 14:00-16:00).
- Reason: 10-500 characters. Consent: mandatory.
- Initial status is always `Pending`. The same doctor cannot be booked twice for the same date + slot (application check + UNIQUE key).

All validation runs on the server (the form uses `novalidate` so that the server messages can be demonstrated; browser attributes such as `required`/`type` are kept only to help users). Each field shows its own message and keeps the typed (HTML-escaped) value after a rejected submission.

## Database design
`departments`, `doctors` (FK department), `appointments` (PK `id`, status default Pending, UNIQUE (`doctor_id`,`appointment_date`,`time_slot`)).

Business rules are enforced twice: by a friendly check in PHP **and** by a database constraint (so a race condition cannot create a bad row).

## Security and reliability
- Every SQL statement uses PDO prepared statements with real server-side preparation (no concatenation of user input).
- All output is escaped with `htmlspecialchars` (XSS); a CSRF token protects the POST.
- "Success" is shown only when the insert returned exactly 1 affected row, followed by a redirect (Post/Redirect/Get) so refreshing does not resubmit.
- Database errors are logged with `error_log` and the user sees a generic message; no SQL or stack trace is exposed (tested with MySQL stopped).

## Assumptions
Doctors and departments are fictional sample data. The double-booking rule also covers Cancelled rows (delete or move the cancelled booking to free the slot). Do not enter real patient data.

## Test cases and results
Executed on 2026-11-07 against PHP 8.3.6 and MariaDB 10.11 by sending real HTTP requests to the form (session + CSRF token). Result: **12 of 12 passed.** All data is fictional.

| # | Category | Test | Expected | Actual result | Pass |
|---|---|---|---|---|---|
| 03-1 | Valid | Appointment without email | Saved: saved with status Pending | SAVED - Appointment request #1 saved with status Pending. | PASS |
| 03-2 | Missing required | No phone, no doctor, no consent | Rejected: Phone is required | REJECTED - Phone is required. / Please choose a doctor. / Consent to data use is required. / Please correct the highlighted fields and submit again. | PASS |
| 03-3 | Invalid value | Appointment date in the past | Rejected: future date | REJECTED - The appointment must be on a future date. / Please correct the highlighted fields and submit again. | PASS |
| 03-4 | Invalid value | Reason shorter than 10 characters | Rejected: between 10 and 500 | REJECTED - Must be between 10 and 500 characters (currently 4). / Please correct the highlighted fields and submit again. | PASS |
| 03-5 | Invalid value | Invalid optional email | Rejected: valid email | REJECTED - Enter a valid email address or leave it empty. / Please correct the highlighted fields and submit again. | PASS |
| 03-6 | Invalid value | Doctor from another department | Rejected: does not work in the selected department | REJECTED - This doctor does not work in the selected department. / Please correct the highlighted fields and submit again. | PASS |
| 03-7 | Conflict | Same doctor, date and slot booked by another patient | Rejected: already booked | REJECTED - That doctor is already booked for this date and slot. Choose another slot. Nothing was saved. / Please correct the highlighted fields and submit again. | PASS |
| 03-8 | Valid | Same doctor and date, different slot | Saved: saved with status Pending | SAVED - Appointment request #2 saved with status Pending. | PASS |
| 03-9 | Valid | Other doctor, same date and slot, with email | Saved: saved with status Pending | SAVED - Appointment request #3 saved with status Pending. | PASS |
| 03-CSRF | Security | POST without CSRF token | Rejected: session expired | REJECTED - Your session expired. Please reload the page and try again. | PASS |
| 03-X | Extension | Extension: schedule grouped by doctor | HTTP 200 and contains "Dr. Neba Fon" | HTTP 200, contains: True | PASS |
| 03-X | Extension | Extension: filter by doctor | HTTP 200 and contains "Dina Eko" | HTTP 200, contains: True | PASS |

**Database failure test:** with the MySQL server stopped, the page shows "The system could not complete your request because of a database problem. Nothing was saved. Please try again later." No stack trace appears and a POST never reports success.

## Database evidence (queries run after the tests)
```
-- Saved appointments
SELECT a.id,a.patient_name,a.phone,d.full_name doctor,a.appointment_date,a.time_slot,a.status FROM appointments a JOIN doctors d ON d.id=a.doctor_id;
+----+--------------+-----------+-----------------+------------------+-----------+---------+
| id | patient_name | phone     | doctor          | appointment_date | time_slot | status  |
+----+--------------+-----------+-----------------+------------------+-----------+---------+
|  1 | Brenda Tabi  | 699887766 | Dr. Neba Fon    | 2026-11-14       | 09:00:00  | Pending |
|  2 | Carl Fru     | 655443322 | Dr. Neba Fon    | 2026-11-14       | 10:00:00  | Pending |
|  3 | Dina Eko     | 699887766 | Dr. Amina Bello | 2026-11-14       | 09:00:00  | Pending |
+----+--------------+-----------+-----------------+------------------+-----------+---------+

-- Double bookings (must be empty)
SELECT doctor_id,appointment_date,time_slot,COUNT(*) c FROM appointments GROUP BY 1,2,3 HAVING c>1;
(no rows)
```

## Screenshots to take for your report
1. The empty form. 2. A valid submission with the success message. 3. A missing required value (errors visible). 4. An invalid value. 5. The duplicate/conflict being rejected. 6. phpMyAdmin showing the saved rows. 7. The extension page (`index.php?view=list`).
