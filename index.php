<?php
declare(strict_types=1);
/*
 * Lab 01 - Student Registration
 * ONE FILE = everything: settings, helpers, HTML form (Task A), PHP validation (Task B),
 * database insert (Task C) and the extension page (open index.php?view=list).
 * Styling is in style.css, the database is created by database.sql.
 */

/* ============ 1. SETTINGS (change the MySQL login here if needed; XAMPP default shown) ============ */
$DB = ['host' => 'localhost', 'name' => 'lab01_students', 'user' => 'root', 'pass' => ''];
$APP_TITLE = 'Lab 01 - Student Registration';
$NAV = [
    'index.php' => 'Register',
    'index.php?view=list' => 'Search registrations',
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
    $levels  = [1 => 'Year 1', 2 => 'Year 2', 3 => 'Year 3', 4 => 'Year 4'];
    $genders = ['Male' => 'Male', 'Female' => 'Female'];
    $programmes = [];

    try {
        $programmes = db()->query('SELECT id, name FROM programmes ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $ex) {
        $errors['_form'] = db_fail($ex);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($errors['_form'])) {
        if (!csrf_ok()) {
            $errors['_form'] = 'Your session expired. Please reload the page and try again.';
        } else {
            $name = post('full_name');
            $email = strtolower(post('email'));
            $phone = post('telephone');
            $dob = post('date_of_birth');
            $programme = post('programme');
            $level = post('level');
            $gender = post('gender');

            // ---- Validation (server side) ----
            if ($name === '') { $errors['full_name'] = 'Full name is required.'; }
            elseif ($m = name_error($name)) { $errors['full_name'] = $m; }

            if ($email === '') { $errors['email'] = 'Email address is required.'; }
            elseif (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['email'] = 'Enter a valid email address (e.g. name@example.com).'; }

            $phoneClean = null;
            if ($phone === '') { $errors['telephone'] = 'Telephone is required.'; }
            elseif (($phoneClean = normalise_phone($phone)) === null) { $errors['telephone'] = 'Enter 9 digits starting with 6 or 2 (e.g. 677 123 456).'; }

            if ($dob === '') { $errors['date_of_birth'] = 'Date of birth is required.'; }
            elseif (($d = parse_date($dob)) === null) { $errors['date_of_birth'] = 'Enter a real date (YYYY-MM-DD).'; }
            elseif ($d >= today()) { $errors['date_of_birth'] = 'Date of birth must be in the past.'; }
            elseif ($d < new DateTimeImmutable('1900-01-01')) { $errors['date_of_birth'] = 'Date of birth cannot be before 1900.'; }

            if ($programme === '') { $errors['programme'] = 'Please choose a programme.'; }
            elseif (!in_list($programme, $programmes)) { $errors['programme'] = 'Invalid programme selected.'; }

            if ($level === '') { $errors['level'] = 'Please choose a level.'; }
            elseif (!in_list($level, $levels)) { $errors['level'] = 'Invalid level selected.'; }

            if ($gender !== '' && !in_list($gender, $genders)) { $errors['gender'] = 'Invalid gender option.'; }

            if (!checked('policy')) { $errors['policy'] = 'You must accept the registration policy.'; }

            // ---- Duplicate e-mail check, then insert ----
            if (!$errors) {
                try {
                    $st = db()->prepare('SELECT 1 FROM students WHERE email = ?');
                    $st->execute([$email]);
                    if ($st->fetchColumn()) {
                        $errors['email'] = 'This email is already registered. Nothing was saved.';
                    } else {
                        $ins = db()->prepare('INSERT INTO students (full_name, email, telephone, date_of_birth, programme_id, level, gender, policy_accepted)
                                              VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
                        $ok = $ins->execute([$name, $email, $phoneClean, $dob, (int)$programme, (int)$level, $gender === '' ? null : $gender]);
                        if ($ok && $ins->rowCount() === 1) {          // success only after a confirmed insert
                            flash_set('Registration successful. Student record #' . db()->lastInsertId() . ' was saved.');
                            header('Location: index.php');
                            exit;
                        }
                        $errors['_form'] = 'The record could not be saved. Please try again.';
                    }
                } catch (PDOException $ex) {
                    $errors[is_duplicate($ex) ? 'email' : '_form'] = is_duplicate($ex)
                        ? 'This email is already registered. Nothing was saved.' : db_fail($ex);
                }
            }
        }
    }
} else {
    // ---- extension challenge page ----
    // Extension challenge: searchable list of registrations (name, email, programme).
    $q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
    $rows = [];
    $problem = null;
    try {
        $sql = 'SELECT s.id, s.full_name, s.email, s.telephone, s.date_of_birth, p.name AS programme,
                       CONCAT("Year ", s.level) AS level, s.gender, s.created_at
                FROM students s JOIN programmes p ON p.id = s.programme_id';
        $params = [];
        if ($q !== '') {
            $sql .= ' WHERE s.full_name LIKE ? OR s.email LIKE ? OR p.name LIKE ?';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $params = [$like, $like, $like];
        }
        $st = db()->prepare($sql . ' ORDER BY s.created_at DESC, s.id DESC LIMIT 200');
        $st->execute($params);
        $rows = $st->fetchAll();
    } catch (PDOException $ex) {
        $problem = db_fail($ex);
    }
    if (!empty($problem)) { $errors['_form'] = $problem; }
}

/* ============ 7. THE PAGE (HTML) ============ */
$pageTitle = ($view === 'list') ? 'Registered Students' : 'Student Registration';
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
<form class="search" method="get" action="index.php" role="search">
    <input type="hidden" name="view" value="list">
    <label for="q" class="muted">Search</label>
    <input type="text" id="q" name="q" value="<?= e($q) ?>" placeholder="Name, email or programme">
    <button type="submit" class="primary">Search</button>
</form>

<?php if (!$rows): ?>
    <p class="muted">No records found.</p>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">ID</th><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Phone</th><th scope="col">Date of birth</th><th scope="col">Programme</th><th scope="col">Level</th><th scope="col">Gender</th><th scope="col">Registered at</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr><td><?= e($r['id']) ?></td><td><?= e($r['full_name']) ?></td><td><?= e($r['email']) ?></td><td><?= e($r['telephone']) ?></td><td><?= e($r['date_of_birth']) ?></td><td><?= e($r['programme']) ?></td><td><?= e($r['level']) ?></td><td><?= e($r['gender']) ?></td><td><?= e($r['created_at']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted"><?= count($rows) ?> record(s).</p>
<?php endif; ?>
<?php else: ?>
<form method="post" action="index.php" novalidate>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="field<?= cls('full_name') ?>">
        <label for="full_name">Full name <span class="req" aria-hidden="true">*</span></label>
        <input type="text" id="full_name" name="full_name" value="<?= val('full_name') ?>" maxlength="80" autocomplete="name" required<?= aria('full_name') ?>>
        <?= err('full_name') ?>
    </div>

    <div class="field<?= cls('email') ?>">
        <label for="email">Email address <span class="req" aria-hidden="true">*</span></label>
        <input type="email" id="email" name="email" value="<?= val('email') ?>" maxlength="190" autocomplete="email" required<?= aria('email') ?>>
        <?= err('email') ?>
    </div>

    <div class="field<?= cls('telephone') ?>">
        <label for="telephone">Telephone <span class="req" aria-hidden="true">*</span></label>
        <input type="tel" id="telephone" name="telephone" value="<?= val('telephone') ?>" placeholder="6XX XXX XXX" autocomplete="tel" required<?= aria('telephone') ?>>
        <?= err('telephone') ?>
    </div>

    <div class="field<?= cls('date_of_birth') ?>">
        <label for="date_of_birth">Date of birth <span class="req" aria-hidden="true">*</span></label>
        <input type="date" id="date_of_birth" name="date_of_birth" value="<?= val('date_of_birth') ?>" max="<?= e(today()->modify('-1 day')->format('Y-m-d')) ?>" required<?= aria('date_of_birth') ?>>
        <?= err('date_of_birth') ?>
    </div>

    <div class="field<?= cls('programme') ?>">
        <label for="programme">Programme <span class="req" aria-hidden="true">*</span></label>
        <select id="programme" name="programme" required<?= aria('programme') ?>>
            <option value="">Choose an option</option>
            <?php foreach ($programmes as $v => $l): ?>
                <option value="<?= e($v) ?>"<?= sel('programme', (string)$v) ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
        <?= err('programme') ?>
    </div>

    <div class="field<?= cls('level') ?>">
        <label for="level">Level <span class="req" aria-hidden="true">*</span></label>
        <select id="level" name="level" required<?= aria('level') ?>>
            <option value="">Choose an option</option>
            <?php foreach ($levels as $v => $l): ?>
                <option value="<?= e($v) ?>"<?= sel('level', (string)$v) ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
        <?= err('level') ?>
    </div>

    <div class="field<?= cls('gender') ?>">
        <fieldset>
            <legend>Gender</legend>
            <div class="choices">
                <?php foreach ($genders as $v => $l): ?>
                    <label class="inline"><input type="radio" name="gender" value="<?= e($v) ?>"<?= chkd('gender', (string)$v) ?>> <?= e($l) ?></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?= err('gender') ?>
    </div>

    <div class="field<?= cls('policy') ?>">
        <span class="legend">Accept registration policy <span class="req" aria-hidden="true">*</span></span>
        <label class="inline" for="policy"><input type="checkbox" id="policy" name="policy" value="1"<?= chkd('policy') ?><?= aria('policy') ?>> I agree</label>
        <?= err('policy') ?>
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
