<?php
declare(strict_types=1);
/*
 * Lab 03 - Clinic Appointments
 * ONE FILE = everything: settings, helpers, HTML form (Task A), PHP validation (Task B),
 * database insert (Task C) and the extension page (open index.php?view=list).
 * Styling is in style.css, the database is created by database.sql.
 */

/* ============ 1. SETTINGS (change the MySQL login here if needed; XAMPP default shown) ============ */
$DB = ['host' => 'localhost', 'name' => 'lab03_clinic', 'user' => 'root', 'pass' => ''];
$APP_TITLE = 'Lab 03 - Clinic Appointments';
$NAV = [
    'index.php' => 'Book appointment',
    'index.php?view=list' => 'Schedule by doctor',
];

/* ============ 2. HELPER FUNCTIONS ============ */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Validation messages, keyed by field name ('_form' = general message). */
$errors = [];


/** Escape for HTML output (prevents XSS). */
function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** Trimmed POST string ('' when missing or an array). */
function post(string $k): string { $v = $_POST[$k] ?? ''; return is_string($v) ? trim($v) : ''; }

/** Previously submitted value (so a rejected form keeps what the user typed). */
function old(string $k): string { $v = $_POST[$k] ?? ''; return is_string($v) ? $v : ''; }

function checked(string $k): bool { return ($_POST[$k] ?? '') === '1'; }

/* ---------- CSRF ---------- */
function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function csrf_ok(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf']) && is_string($_POST['csrf'])
        && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

/* ---------- Flash message (post/redirect/get) ---------- */
function flash_set(string $msg): void { $_SESSION['flash'] = $msg; }
function flash_show(): void
{
    if (!empty($_SESSION['flash'])) {
        echo '<div class="alert success" role="status">' . e($_SESSION['flash']) . '</div>';
        unset($_SESSION['flash']);
    }
}
function form_error_show(): void
{
    global $errors;
    if (isset($errors['_form'])) {
        echo '<div class="alert danger" role="alert">' . e($errors['_form']) . '</div>';
    }
    elseif ($errors) {
        echo '<div class="alert danger" role="alert">Please correct the highlighted fields and submit again.</div>';
    }
}

/* ---------- Small helpers used inside the HTML templates ---------- */
function val(string $n): string   { return e(old($n)); }                                   // value="..."
function cls(string $n): string   { global $errors; return isset($errors[$n]) ? ' has-error' : ''; }   // red border
function err(string $n): string   { global $errors; return isset($errors[$n]) ? '<span class="error" id="err-' . e($n) . '">' . e($errors[$n]) . '</span>' : ''; }
function aria(string $n): string  { global $errors; return isset($errors[$n]) ? ' aria-invalid="true" aria-describedby="err-' . e($n) . '"' : ''; }
function sel(string $n, string $v): string  { return old($n) === $v ? ' selected' : ''; }   // <option selected>
function chkd(string $n, string $v = '1'): string { return old($n) === $v ? ' checked' : ''; }  // checked

/* ---------- Reusable validators ---------- */

/** Real calendar date in YYYY-MM-DD form, or null. */
function parse_date(string $s): ?DateTimeImmutable
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    $err = DateTimeImmutable::getLastErrors();
    if ($d === false || ($err && ($err['warning_count'] || $err['error_count'])) || $d->format('Y-m-d') !== $s) {
        return null;
    }
    return $d;
}
function today(): DateTimeImmutable { return new DateTimeImmutable('today'); }

/** Person name: 3-80 chars, letters + space . ' - (any language / accents). */
function name_error(string $s, int $min = 3, int $max = 80): ?string
{
    $len = mb_strlen($s);
    if ($len < $min || $len > $max) { return "Must be between $min and $max characters."; }
    if (!preg_match("/^\p{L}[\p{L} .'\-]*$/u", $s)) { return "Use letters and spaces only (. ' - are allowed)."; }
    return null;
}

/**
 * Cameroon phone, accepted local format: 9 digits starting with 6 (mobile) or 2 (landline).
 * Spaces, dots, dashes and an optional +237 / 237 prefix are removed first.
 * Returns the clean 9 digits, or null when invalid.
 */
function normalise_phone(string $raw): ?string
{
    $s = preg_replace('/[\s.\-()]/', '', $raw);
    if (preg_match('/^(?:\+?237)(\d{9})$/', $s, $m)) { $s = $m[1]; }
    return preg_match('/^[26]\d{8}$/', $s) ? $s : null;
}

/** Required-select check: value must exist in the allowed list. */
function in_list(string $v, array $allowed): bool
{
    return $v !== '' && array_key_exists($v, $allowed);
}

/* ============ 3. DATABASE CONNECTION ============ */
/**
 * Returns one shared PDO connection.
 * - Exceptions are enabled so every failure can be handled.
 * - Real prepared statements (EMULATE_PREPARES = false): user input is never
 *   concatenated into SQL, it is always sent separately as parameters.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    global $DB;
    $c = $DB;
    $dsn = "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/** Logs the technical error, returns a safe message for the user. */
function db_fail(PDOException $e): string
{
    error_log('[DB] ' . $e->getMessage());
    return 'The system could not complete your request because of a database problem. Nothing was saved. Please try again later.';
}

/** True when the exception is a duplicate-key violation (MySQL error 1062). */
function is_duplicate(PDOException $e): bool
{
    return ($e->errorInfo[1] ?? 0) === 1062;
}

/* ============ 5. LAB CONSTANTS AND FUNCTIONS ============ */

/* ============ 6. PROCESSING ============ */
$view = (($_GET['view'] ?? '') === 'list') ? 'list' : 'form';

if ($view === 'form') {
    // ---- the form: validate the POST and insert ----
    $slots = ['08:00' => '08:00', '09:00' => '09:00', '10:00' => '10:00', '11:00' => '11:00',
              '14:00' => '14:00', '15:00' => '15:00', '16:00' => '16:00'];
    $departments = $doctorLabels = $doctorDept = [];

    try {
        $departments = db()->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (db()->query('SELECT d.id, d.full_name, d.department_id, p.name AS dept FROM doctors d JOIN departments p ON p.id = d.department_id ORDER BY d.full_name') as $r) {
            $doctorLabels[$r['id']] = $r['full_name'] . ' (' . $r['dept'] . ')';
            $doctorDept[$r['id']] = (string)$r['department_id'];
        }
    } catch (PDOException $ex) {
        $errors['_form'] = db_fail($ex);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($errors['_form'])) {
        if (!csrf_ok()) {
            $errors['_form'] = 'Your session expired. Please reload the page and try again.';
        } else {
            $name = post('patient_name');
            $email = strtolower(post('email'));
            $phone = post('phone');
            $dept = post('department');
            $doctor = post('doctor');
            $date = post('appointment_date');
            $slot = post('time_slot');
            $reason = post('reason');

            if ($name === '') { $errors['patient_name'] = 'Patient name is required.'; }
            elseif ($m = name_error($name)) { $errors['patient_name'] = $m; }

            if ($email !== '' && (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL))) { $errors['email'] = 'Enter a valid email address or leave it empty.'; }

            $phoneClean = null;
            if ($phone === '') { $errors['phone'] = 'Phone is required.'; }
            elseif (($phoneClean = normalise_phone($phone)) === null) { $errors['phone'] = 'Enter 9 digits starting with 6 or 2 (e.g. 677 123 456).'; }

            if ($dept === '') { $errors['department'] = 'Please choose a department.'; }
            elseif (!in_list($dept, $departments)) { $errors['department'] = 'Invalid department selected.'; }

            if ($doctor === '') { $errors['doctor'] = 'Please choose a doctor.'; }
            elseif (!in_list($doctor, $doctorLabels)) { $errors['doctor'] = 'Invalid doctor selected.'; }
            elseif (!isset($errors['department']) && $doctorDept[$doctor] !== $dept) { $errors['doctor'] = 'This doctor does not work in the selected department.'; }

            if ($date === '') { $errors['appointment_date'] = 'Appointment date is required.'; }
            elseif (parse_date($date) === null) { $errors['appointment_date'] = 'Enter a real date (YYYY-MM-DD).'; }
            elseif (parse_date($date) <= today()) { $errors['appointment_date'] = 'The appointment must be on a future date.'; }

            if ($slot === '') { $errors['time_slot'] = 'Please choose a time slot.'; }
            elseif (!in_list($slot, $slots)) { $errors['time_slot'] = 'Invalid time slot selected.'; }

            $len = mb_strlen($reason);
            if ($reason === '') { $errors['reason'] = 'Symptoms or reason is required.'; }
            elseif ($len < 10 || $len > 500) { $errors['reason'] = "Must be between 10 and 500 characters (currently $len)."; }

            if (!checked('consent')) { $errors['consent'] = 'Consent to data use is required.'; }

            if (!$errors) {
                try {
                    $st = db()->prepare('SELECT 1 FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND time_slot = ?');
                    $st->execute([(int)$doctor, $date, $slot . ':00']);
                    if ($st->fetchColumn()) {
                        $errors['time_slot'] = 'That doctor is already booked for this date and slot. Choose another slot. Nothing was saved.';
                    } else {
                        $ins = db()->prepare("INSERT INTO appointments (patient_name, email, phone, department_id, doctor_id, appointment_date, time_slot, reason, consent, status)
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'Pending')");
                        $ok = $ins->execute([$name, $email === '' ? null : $email, $phoneClean, (int)$dept, (int)$doctor, $date, $slot . ':00', $reason]);
                        if ($ok && $ins->rowCount() === 1) {
                            flash_set('Appointment request #' . db()->lastInsertId() . ' saved with status Pending.');
                            header('Location: index.php');
                            exit;
                        }
                        $errors['_form'] = 'The appointment could not be saved. Please try again.';
                    }
                } catch (PDOException $ex) {
                    if (is_duplicate($ex)) { $errors['time_slot'] = 'That doctor is already booked for this date and slot. Nothing was saved.'; }
                    else { $errors['_form'] = db_fail($ex); }
                }
            }
        }
    }
} else {
    // ---- extension challenge page ----
    // Extension challenge: simple appointment schedule grouped by doctor.
    $filter = isset($_GET['doctor']) && ctype_digit((string)$_GET['doctor']) ? (int)$_GET['doctor'] : 0;
    $doctors = $byDoctor = [];
    $problem = null;
    try {
        $doctors = db()->query('SELECT id, full_name FROM doctors ORDER BY full_name')->fetchAll(PDO::FETCH_KEY_PAIR);
        $sql = 'SELECT d.id AS doctor_id, d.full_name AS doctor, a.appointment_date, TIME_FORMAT(a.time_slot, "%H:%i") AS slot,
                       a.patient_name, a.status, a.id
                FROM appointments a JOIN doctors d ON d.id = a.doctor_id';
        $params = [];
        if ($filter > 0) { $sql .= ' WHERE d.id = ?'; $params[] = $filter; }
        $st = db()->prepare($sql . ' ORDER BY d.full_name, a.appointment_date, a.time_slot');
        $st->execute($params);
        foreach ($st as $r) { $byDoctor[$r['doctor']][] = $r; }
    } catch (PDOException $ex) {
        $problem = db_fail($ex);
    }
    if (!empty($problem)) { $errors['_form'] = $problem; }
}

/* ============ 7. THE PAGE (HTML) ============ */
$pageTitle = ($view === 'list') ? 'Appointment Schedule by Doctor' : 'Clinic Appointment Booking';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> - <?= e($APP_TITLE) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="top">
    <strong><?= e($APP_TITLE) ?></strong>
    <nav aria-label="Main">
        <?php foreach ($NAV as $href => $label): ?>
            <a href="<?= e($href) ?>"<?= (str_contains($href, 'view=list') === ($view === 'list')) ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
</header>

<main class="card<?= $view === 'list' ? ' wide' : '' ?>">
    <h1><?= e($pageTitle) ?></h1>
    <?php flash_show(); form_error_show(); ?>

<?php if ($view === 'list'): ?>
<form class="search" method="get" action="index.php">
    <input type="hidden" name="view" value="list">
    <label for="doctor" class="muted">Doctor</label>
    <select id="doctor" name="doctor">
        <option value="0">All doctors</option>
        <?php foreach ($doctors as $id => $n): ?>
            <option value="<?= e($id) ?>"<?= $filter === (int)$id ? ' selected' : '' ?>><?= e($n) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="primary">Show</button>
</form>

<?php if (!$byDoctor): ?>
    <p class="muted">No appointments booked.</p>
<?php endif; ?>
<?php foreach ($byDoctor as $doctor => $rows): ?>
    <h2><?= e($doctor) ?></h2>
<?php if (!$rows): ?>
    <p class="muted">No records found.</p>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Date</th><th scope="col">Time</th><th scope="col">Patient</th><th scope="col">Status</th><th scope="col">Ref #</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr><td><?= e($r['appointment_date']) ?></td><td><?= e($r['slot']) ?></td><td><?= e($r['patient_name']) ?></td><td><?= e($r['status']) ?></td><td><?= e($r['id']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted"><?= count($rows) ?> record(s).</p>
<?php endif; ?>
<?php endforeach; ?>
<?php else: ?>
<form method="post" action="index.php" novalidate>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="field<?= cls('patient_name') ?>">
        <label for="patient_name">Patient name <span class="req" aria-hidden="true">*</span></label>
        <input type="text" id="patient_name" name="patient_name" value="<?= val('patient_name') ?>" maxlength="80" autocomplete="name" required<?= aria('patient_name') ?>>
        <?= err('patient_name') ?>
    </div>

    <div class="field<?= cls('email') ?>">
        <label for="email">Email (optional)</label>
        <input type="email" id="email" name="email" value="<?= val('email') ?>" maxlength="190"<?= aria('email') ?>>
        <?= err('email') ?>
    </div>

    <div class="field<?= cls('phone') ?>">
        <label for="phone">Phone <span class="req" aria-hidden="true">*</span></label>
        <input type="tel" id="phone" name="phone" value="<?= val('phone') ?>" placeholder="6XX XXX XXX" required<?= aria('phone') ?>>
        <?= err('phone') ?>
    </div>

    <div class="field<?= cls('department') ?>">
        <label for="department">Department <span class="req" aria-hidden="true">*</span></label>
        <select id="department" name="department" required<?= aria('department') ?>>
            <option value="">Choose an option</option>
            <?php foreach ($departments as $v => $l): ?>
                <option value="<?= e($v) ?>"<?= sel('department', (string)$v) ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
        <?= err('department') ?>
    </div>

    <div class="field<?= cls('doctor') ?>">
        <label for="doctor">Preferred doctor <span class="req" aria-hidden="true">*</span></label>
        <select id="doctor" name="doctor" required<?= aria('doctor') ?>>
            <option value="">Choose an option</option>
            <?php foreach ($doctorLabels as $v => $l): ?>
                <option value="<?= e($v) ?>"<?= sel('doctor', (string)$v) ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
        <?= err('doctor') ?>
    </div>

    <div class="field<?= cls('appointment_date') ?>">
        <label for="appointment_date">Appointment date <span class="req" aria-hidden="true">*</span></label>
        <input type="date" id="appointment_date" name="appointment_date" value="<?= val('appointment_date') ?>" min="<?= e(today()->modify('+1 day')->format('Y-m-d')) ?>" required<?= aria('appointment_date') ?>>
        <?= err('appointment_date') ?>
    </div>

    <div class="field<?= cls('time_slot') ?>">
        <label for="time_slot">Time slot <span class="req" aria-hidden="true">*</span></label>
        <select id="time_slot" name="time_slot" required<?= aria('time_slot') ?>>
            <option value="">Choose an option</option>
            <?php foreach ($slots as $v => $l): ?>
                <option value="<?= e($v) ?>"<?= sel('time_slot', (string)$v) ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
        <?= err('time_slot') ?>
    </div>

    <div class="field<?= cls('reason') ?>">
        <label for="reason">Symptoms or reason <span class="req" aria-hidden="true">*</span></label>
        <textarea id="reason" name="reason" rows="4" maxlength="500" placeholder="10 to 500 characters" required<?= aria('reason') ?>><?= val('reason') ?></textarea>
        <?= err('reason') ?>
    </div>

    <div class="field<?= cls('consent') ?>">
        <span class="legend">Consent to data use <span class="req" aria-hidden="true">*</span></span>
        <label class="inline" for="consent"><input type="checkbox" id="consent" name="consent" value="1"<?= chkd('consent') ?><?= aria('consent') ?>> I agree</label>
        <?= err('consent') ?>
    </div>

    <div class="buttons">
        <button type="submit" class="primary">Submit Form</button>
        <button type="reset" class="secondary">Reset</button>
    </div>
</form>
<p class="muted">Use fictional data only when testing. Do not enter real patient information.</p>
<?php endif; ?>
</main>
</body>
</html>
