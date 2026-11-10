<?php
declare(strict_types=1);
/*
 * Lab 05 - Event Registration
 * ONE FILE = everything: settings, helpers, HTML form (Task A), PHP validation (Task B),
 * database insert (Task C) and the extension page (open index.php?view=list).
 * Styling is in style.css, the database is created by database.sql.
 */

/* ============ 1. SETTINGS (change the MySQL login here if needed; XAMPP default shown) ============ */
$DB = ['host' => 'localhost', 'name' => 'lab05_events', 'user' => 'root', 'pass' => ''];
$APP_TITLE = 'Lab 05 - Event Registration';
$NAV = [
    'index.php' => 'Register',
    'index.php?view=list' => 'Totals per workshop',
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
    $modes = ['In-person' => 'In-person', 'Online' => 'Online'];
    $workshops = [];

    try {
        foreach (db()->query('SELECT id, title, workshop_date FROM workshops ORDER BY workshop_date') as $r) {
            $workshops[$r['id']] = $r['title'] . ' (' . $r['workshop_date'] . ')';
        }
    } catch (PDOException $ex) {
        $errors['_form'] = db_fail($ex);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($errors['_form'])) {
        if (!csrf_ok()) {
            $errors['_form'] = 'Your session expired. Please reload the page and try again.';
        } else {
            $name = post('participant_name');
            $email = strtolower(post('email'));
            $phone = post('phone');
            $ws = post('workshop');
            $mode = post('mode');
            $seats = post('seats');
            $special = post('special');

            if ($name === '') { $errors['participant_name'] = 'Participant name is required.'; }
            elseif ($m = name_error($name)) { $errors['participant_name'] = $m; }

            if ($email === '') { $errors['email'] = 'Email is required.'; }
            elseif (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['email'] = 'Enter a valid email address.'; }

            $phoneClean = null;
            if ($phone !== '' && ($phoneClean = normalise_phone($phone)) === null) { $errors['phone'] = 'Enter 9 digits starting with 6 or 2, or leave empty.'; }

            if ($ws === '') { $errors['workshop'] = 'Please choose a workshop.'; }
            elseif (!in_list($ws, $workshops)) { $errors['workshop'] = 'Invalid workshop selected.'; }

            if ($mode === '') { $errors['mode'] = 'Please choose an attendance mode.'; }
            elseif (!in_list($mode, $modes)) { $errors['mode'] = 'Invalid attendance mode.'; }

            if ($seats === '') { $errors['seats'] = 'Number of seats is required.'; }
            elseif (!preg_match('/^[1-3]$/', $seats)) { $errors['seats'] = 'Seats must be a whole number from 1 to 3.'; }

            if (mb_strlen($special) > 300) { $errors['special'] = 'Maximum 300 characters (currently ' . mb_strlen($special) . ').'; }
            if (!checked('terms')) { $errors['terms'] = 'You must accept the event terms.'; }

            if (!$errors) {
                try {
                    $st = db()->prepare('SELECT 1 FROM registrations WHERE email = ? AND workshop_id = ?');
                    $st->execute([$email, (int)$ws]);
                    if ($st->fetchColumn()) {
                        $errors['email'] = 'This email is already registered for this workshop. Nothing was saved.';
                    } else {
                        $ins = db()->prepare('INSERT INTO registrations (participant_name, email, phone, workshop_id, attendance_mode, seats, special_requirements, terms_accepted)
                                              VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
                        $ok = $ins->execute([$name, $email, $phoneClean, (int)$ws, $mode, (int)$seats, $special === '' ? null : $special]);
                        if ($ok && $ins->rowCount() === 1) {
                            flash_set('Registration #' . db()->lastInsertId() . ' confirmed for ' . $seats . ' seat(s).');
                            header('Location: index.php');
                            exit;
                        }
                        $errors['_form'] = 'The registration could not be saved. Please try again.';
                    }
                } catch (PDOException $ex) {
                    if (is_duplicate($ex)) { $errors['email'] = 'This email is already registered for this workshop. Nothing was saved.'; }
                    else { $errors['_form'] = db_fail($ex); }
                }
            }
        }
    }
} else {
    // ---- extension challenge page ----
    // Extension challenge: registration totals per workshop (workshops without registrations show 0).
    $rows = [];
    $problem = null;
    try {
        $rows = db()->query(
            'SELECT w.title, w.workshop_date, COUNT(r.id) AS registrations, COALESCE(SUM(r.seats), 0) AS total_seats,
                    COALESCE(SUM(r.attendance_mode = "In-person"), 0) AS in_person, COALESCE(SUM(r.attendance_mode = "Online"), 0) AS online
             FROM workshops w LEFT JOIN registrations r ON r.workshop_id = w.id
             GROUP BY w.id, w.title, w.workshop_date ORDER BY w.workshop_date'
        )->fetchAll();
    } catch (PDOException $ex) {
        $problem = db_fail($ex);
    }
    if (!empty($problem)) { $errors['_form'] = $problem; }
}

/* ============ 7. THE PAGE (HTML) ============ */
$pageTitle = ($view === 'list') ? 'Registration Totals per Workshop' : 'Workshop Registration';
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
<?php if (!$rows): ?>
    <p class="muted">No records found.</p>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Workshop</th><th scope="col">Date</th><th scope="col">Registrations</th><th scope="col">Total seats</th><th scope="col">In-person</th><th scope="col">Online</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr><td><?= e($r['title']) ?></td><td><?= e($r['workshop_date']) ?></td><td><?= e($r['registrations']) ?></td><td><?= e($r['total_seats']) ?></td><td><?= e($r['in_person']) ?></td><td><?= e($r['online']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted"><?= count($rows) ?> record(s).</p>
<?php endif; ?>
<?php else: ?>
<form method="post" action="index.php" novalidate>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="field<?= cls('participant_name') ?>">
        <label for="participant_name">Participant name <span class="req" aria-hidden="true">*</span></label>
        <input type="text" id="participant_name" name="participant_name" value="<?= val('participant_name') ?>" maxlength="80" required<?= aria('participant_name') ?>>
        <?= err('participant_name') ?>
    </div>

    <div class="field<?= cls('email') ?>">
        <label for="email">Email <span class="req" aria-hidden="true">*</span></label>
        <input type="email" id="email" name="email" value="<?= val('email') ?>" maxlength="190" required<?= aria('email') ?>>
        <?= err('email') ?>
    </div>

    <div class="field<?= cls('phone') ?>">
        <label for="phone">Phone (optional)</label>
        <input type="tel" id="phone" name="phone" value="<?= val('phone') ?>" placeholder="6XX XXX XXX"<?= aria('phone') ?>>
        <?= err('phone') ?>
    </div>

    <div class="field<?= cls('workshop') ?>">
        <label for="workshop">Workshop <span class="req" aria-hidden="true">*</span></label>
        <select id="workshop" name="workshop" required<?= aria('workshop') ?>>
            <option value="">Choose an option</option>
            <?php foreach ($workshops as $v => $l): ?>
                <option value="<?= e($v) ?>"<?= sel('workshop', (string)$v) ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
        <?= err('workshop') ?>
    </div>

    <div class="field<?= cls('mode') ?>">
        <fieldset>
            <legend>Attendance mode <span class="req" aria-hidden="true">*</span></legend>
            <div class="choices">
                <?php foreach ($modes as $v => $l): ?>
                    <label class="inline"><input type="radio" name="mode" value="<?= e($v) ?>"<?= chkd('mode', (string)$v) ?>> <?= e($l) ?></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?= err('mode') ?>
    </div>

    <div class="field<?= cls('seats') ?>">
        <label for="seats">Number of seats (1-3) <span class="req" aria-hidden="true">*</span></label>
        <input type="number" id="seats" name="seats" value="<?= val('seats') ?>" min="1" max="3" step="1" required<?= aria('seats') ?>>
        <?= err('seats') ?>
    </div>

    <div class="field<?= cls('special') ?>">
        <label for="special">Special requirements</label>
        <textarea id="special" name="special" rows="4" maxlength="300"<?= aria('special') ?>><?= val('special') ?></textarea>
        <?= err('special') ?>
    </div>

    <div class="field<?= cls('terms') ?>">
        <span class="legend">Accept event terms <span class="req" aria-hidden="true">*</span></span>
        <label class="inline" for="terms"><input type="checkbox" id="terms" name="terms" value="1"<?= chkd('terms') ?><?= aria('terms') ?>> I agree</label>
        <?= err('terms') ?>
    </div>

    <div class="buttons">
        <button type="submit" class="primary">Submit Form</button>
        <button type="reset" class="secondary">Reset</button>
    </div>
</form>
<?php endif; ?>
</main>
</body>
</html>
