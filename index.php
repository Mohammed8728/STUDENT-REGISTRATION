<?php
declare(strict_types=1);
/*
 * Lab 02 - Library Borrowing
 * ONE FILE = everything: settings, helpers, HTML form (Task A), PHP validation (Task B),
 * database insert (Task C) and the extension page (open index.php?view=list).
 * Styling is in style.css, the database is created by database.sql.
 */

/* ============ 1. SETTINGS (change the MySQL login here if needed; XAMPP default shown) ============ */
$DB = ['host' => 'localhost', 'name' => 'lab02_library', 'user' => 'root', 'pass' => ''];
$APP_TITLE = 'Lab 02 - Library Borrowing';
$NAV = [
    'index.php' => 'Borrow a book',
    'index.php?view=list' => 'Active loans',
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
const MAX_LOAN_DAYS = 30;   // assumption: the library lends for at most 30 days
/** ISBN-10 (check digit may be X) or ISBN-13, with checksum. Input already stripped of spaces/hyphens. */
function isbn_valid(string $s): bool
{
    if (preg_match('/^\d{9}[\dX]$/', $s)) {
        $sum = 0;
        for ($i = 0; $i < 10; $i++) { $sum += ($s[$i] === 'X' ? 10 : (int)$s[$i]) * (10 - $i); }
        return $sum % 11 === 0;
    }
    if (preg_match('/^\d{13}$/', $s)) {
        $sum = 0;
        for ($i = 0; $i < 13; $i++) { $sum += (int)$s[$i] * ($i % 2 ? 3 : 1); }
        return $sum % 10 === 0;
    }
    return false;
}

/* ============ 6. PROCESSING ============ */
$view = (($_GET['view'] ?? '') === 'list') ? 'list' : 'form';

if ($view === 'form') {
    // ---- the form: validate the POST and insert ----


    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_ok()) {
            $errors['_form'] = 'Your session expired. Please reload the page and try again.';
        } else {
            $sid = strtoupper(post('student_id'));
            $isbnRaw = post('isbn');
            $isbn = strtoupper(preg_replace('/[\s-]/', '', $isbnRaw));
            $title = post('book_title');
            $borrow = post('borrow_date');
            $due = post('due_date');
            $days = post('loan_days');
            $purpose = post('purpose');

            if ($sid === '') { $errors['student_id'] = 'Student ID is required.'; }
            elseif (!preg_match('/^[A-Z0-9-]{4,20}$/', $sid)) { $errors['student_id'] = 'Use 4-20 letters, digits or hyphens.'; }

            if ($isbnRaw === '') { $errors['isbn'] = 'ISBN is required.'; }
            elseif (!isbn_valid($isbn)) { $errors['isbn'] = 'Enter a valid ISBN-10 (X allowed as last character) or ISBN-13.'; }

            if ($title === '') { $errors['book_title'] = 'Book title is required.'; }
            elseif (mb_strlen($title) < 2 || mb_strlen($title) > 200) { $errors['book_title'] = 'Title must be 2-200 characters.'; }

            $bd = $dd = null;
            if ($borrow === '') { $errors['borrow_date'] = 'Borrow date is required.'; }
            elseif (($bd = parse_date($borrow)) === null) { $errors['borrow_date'] = 'Enter a real date (YYYY-MM-DD).'; }
            elseif ($bd > today()) { $errors['borrow_date'] = 'Borrow date cannot be in the future.'; }

            if ($due === '') { $errors['due_date'] = 'Return due date is required.'; }
            elseif (($dd = parse_date($due)) === null) { $errors['due_date'] = 'Enter a real date (YYYY-MM-DD).'; }
            elseif ($bd && $dd <= $bd) { $errors['due_date'] = 'Due date must be after the borrow date.'; }

            $calc = ($bd && $dd && $dd > $bd) ? (int)$bd->diff($dd)->days : null;
            if ($calc !== null && $calc > MAX_LOAN_DAYS) { $errors['due_date'] = 'Loans are limited to ' . MAX_LOAN_DAYS . ' days.'; }
            if ($days !== '') {                       // optional: if given, it must match the dates
                if (!preg_match('/^\d{1,3}$/', $days) || (int)$days < 1) { $errors['loan_days'] = 'Duration must be a whole number of days (1-' . MAX_LOAN_DAYS . ').'; }
                elseif ($calc !== null && (int)$days !== $calc && !isset($errors['due_date'])) { $errors['loan_days'] = "Duration does not match the dates ($calc days)."; }
            }
            if (mb_strlen($purpose) > 255) { $errors['purpose'] = 'Purpose must be 255 characters or fewer.'; }
            if (!checked('agree')) { $errors['agree'] = 'You must agree to the library rules.'; }

            if (!$errors) {
                try {
                    $st = db()->prepare('SELECT loan_id FROM loans WHERE student_id = ? AND isbn = ? AND is_active = 1');
                    $st->execute([$sid, $isbn]);
                    if ($st->fetchColumn()) {
                        $errors['isbn'] = 'This student already has an active loan for this book. Nothing was saved.';
                    } else {
                        $ins = db()->prepare('INSERT INTO loans (student_id, isbn, book_title, borrow_date, due_date, loan_days, purpose, rules_accepted)
                                              VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
                        $ok = $ins->execute([$sid, $isbn, $title, $borrow, $due, $calc, $purpose === '' ? null : $purpose]);
                        if ($ok && $ins->rowCount() === 1) {
                            flash_set('Loan recorded. Loan ID #' . db()->lastInsertId() . ', due on ' . $due . '.');
                            header('Location: index.php');
                            exit;
                        }
                        $errors['_form'] = 'The loan could not be saved. Please try again.';
                    }
                } catch (PDOException $ex) {
                    if (is_duplicate($ex)) { $errors['isbn'] = 'This student already has an active loan for this book. Nothing was saved.'; }
                    else { $errors['_form'] = db_fail($ex); }
                }
            }
        }
    }
} else {
    // ---- extension challenge page ----
    // Extension challenge: current active loans and their due dates (soonest first).
    $rows = [];
    $problem = null;
    try {
        $rows = db()->query(
            "SELECT loan_id, student_id, isbn, book_title, borrow_date, due_date,
                    DATEDIFF(due_date, CURDATE()) AS days_left,
                    CASE WHEN due_date < CURDATE() THEN 'OVERDUE' ELSE 'On time' END AS state
             FROM loans WHERE is_active = 1 ORDER BY due_date ASC, loan_id ASC"
        )->fetchAll();
    } catch (PDOException $ex) {
        $problem = db_fail($ex);
    }
    if (!empty($problem)) { $errors['_form'] = $problem; }
}

/* ============ 7. THE PAGE (HTML) ============ */
$pageTitle = ($view === 'list') ? 'Active Loans' : 'Library Book Borrowing';
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
    <p class="muted">There are no active loans.</p>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Loan ID</th><th scope="col">Student</th><th scope="col">ISBN</th><th scope="col">Title</th><th scope="col">Borrowed</th><th scope="col">Due date</th><th scope="col">Days left</th><th scope="col">Status</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr><td><?= e($r['loan_id']) ?></td><td><?= e($r['student_id']) ?></td><td><?= e($r['isbn']) ?></td><td><?= e($r['book_title']) ?></td><td><?= e($r['borrow_date']) ?></td><td><?= e($r['due_date']) ?></td><td><?= e($r['days_left']) ?></td><td><?= e($r['state']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted"><?= count($rows) ?> record(s).</p>
<?php endif; ?>
<?php else: ?>
<form method="post" action="index.php" novalidate>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="field<?= cls('student_id') ?>">
        <label for="student_id">Student ID <span class="req" aria-hidden="true">*</span></label>
        <input type="text" id="student_id" name="student_id" value="<?= val('student_id') ?>" maxlength="20" required<?= aria('student_id') ?>>
        <?= err('student_id') ?>
    </div>

    <div class="field<?= cls('isbn') ?>">
        <label for="isbn">Book ISBN <span class="req" aria-hidden="true">*</span></label>
        <input type="text" id="isbn" name="isbn" value="<?= val('isbn') ?>" maxlength="17" placeholder="978-0-306-40615-7" required<?= aria('isbn') ?>>
        <?= err('isbn') ?>
    </div>

    <div class="field<?= cls('book_title') ?>">
        <label for="book_title">Book title <span class="req" aria-hidden="true">*</span></label>
        <input type="text" id="book_title" name="book_title" value="<?= val('book_title') ?>" maxlength="200" required<?= aria('book_title') ?>>
        <?= err('book_title') ?>
    </div>

    <div class="field<?= cls('borrow_date') ?>">
        <label for="borrow_date">Borrow date <span class="req" aria-hidden="true">*</span></label>
        <input type="date" id="borrow_date" name="borrow_date" value="<?= val('borrow_date') ?>" max="<?= e(today()->format('Y-m-d')) ?>" required<?= aria('borrow_date') ?>>
        <?= err('borrow_date') ?>
    </div>

    <div class="field<?= cls('due_date') ?>">
        <label for="due_date">Return due date <span class="req" aria-hidden="true">*</span></label>
        <input type="date" id="due_date" name="due_date" value="<?= val('due_date') ?>" required<?= aria('due_date') ?>>
        <?= err('due_date') ?>
    </div>

    <div class="field<?= cls('loan_days') ?>">
        <label for="loan_days">Loan duration (days)</label>
        <input type="number" id="loan_days" name="loan_days" value="<?= val('loan_days') ?>" min="1" max="<?= MAX_LOAN_DAYS ?>" placeholder="Optional - calculated from the dates"<?= aria('loan_days') ?>>
        <?= err('loan_days') ?>
    </div>

    <div class="field<?= cls('purpose') ?>">
        <label for="purpose">Borrowing purpose</label>
        <input type="text" id="purpose" name="purpose" value="<?= val('purpose') ?>" maxlength="255"<?= aria('purpose') ?>>
        <?= err('purpose') ?>
    </div>

    <div class="field<?= cls('agree') ?>">
        <span class="legend">Agree to library rules <span class="req" aria-hidden="true">*</span></span>
        <label class="inline" for="agree"><input type="checkbox" id="agree" name="agree" value="1"<?= chkd('agree') ?><?= aria('agree') ?>> I agree</label>
        <?= err('agree') ?>
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
