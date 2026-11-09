<?php
declare(strict_types=1);
/*
 * Lab 04 - Product Entry
 * ONE FILE = everything: settings, helpers, HTML form (Task A), PHP validation (Task B),
 * database insert (Task C) and the extension page (open index.php?view=list).
 * Styling is in style.css, the database is created by database.sql.
 */

/* ============ 1. SETTINGS (change the MySQL login here if needed; XAMPP default shown) ============ */
$DB = ['host' => 'localhost', 'name' => 'lab04_shop', 'user' => 'root', 'pass' => ''];
$APP_TITLE = 'Lab 04 - Product Entry';
$NAV = [
    'index.php' => 'Add product',
    'index.php?view=list' => 'Inventory',
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

/* ============ 4. SAFE IMAGE UPLOAD ============ */
/**
 * Validates an OPTIONAL image upload on the server (never trusts the browser).
 * Returns ['tmp'=>..., 'ext'=>'jpg|png'] when a valid file was sent, null when
 * no file was sent or when it is invalid (then $errors[$field] is set).
 */
function check_image(string $field, int $maxBytes, array &$errors): ?array
{
    $f = $_FILES[$field] ?? null;
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $limit = number_format($maxBytes / 1048576, 0) . ' MB';
    if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        $errors[$field] = "The file is too large (limit $limit)."; return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        $errors[$field] = 'The upload failed. Please try again.'; return null;
    }
    if ($f['size'] >= $maxBytes) {
        $errors[$field] = "The file is too large (must be under $limit)."; return null;
    }
    // Real content type from the file bytes, not from the name or browser header.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($allowed[$mime]) || @getimagesize($f['tmp_name']) === false) {
        $errors[$field] = 'Only JPEG or PNG images are allowed.'; return null;
    }
    return ['tmp' => $f['tmp_name'], 'ext' => $allowed[$mime]];
}

/** Stores the file under a random server-generated name; returns that name only. */
function save_image(array $img, string $dir): string
{
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Cannot create upload directory');
    }
    // uploaded files must never run as scripts
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "<FilesMatch \"\\.(php|phtml|phar|php[0-9])$\">\n    Require all denied\n</FilesMatch>\nOptions -Indexes\n");
    }
    $name = bin2hex(random_bytes(16)) . '.' . $img['ext'];
    if (!move_uploaded_file($img['tmp'], $dir . '/' . $name)) {
        throw new RuntimeException('Cannot store uploaded file');
    }
    return $name;
}

/* ============ 5. LAB CONSTANTS AND FUNCTIONS ============ */
const MAX_IMAGE_BYTES = 2 * 1024 * 1024;       // 2 MB

/* ============ 6. PROCESSING ============ */
$view = (($_GET['view'] ?? '') === 'list') ? 'list' : 'form';

if ($view === 'form') {
    // ---- the form: validate the POST and insert ----
    $categories = [];

    try {
        $categories = db()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $ex) {
        $errors['_form'] = db_fail($ex);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($errors['_form'])) {
        if (!csrf_ok()) {
            $errors['_form'] = 'Your session expired. Please reload the page and try again.';
        } else {
            $name = post('name');
            $sku = strtoupper(post('sku'));
            $cat = post('category');
            $price = str_replace(',', '.', post('unit_price'));
            $qty = post('quantity');
            $desc = post('description');

            if ($name === '') { $errors['name'] = 'Product name is required.'; }
            elseif (mb_strlen($name) < 3 || mb_strlen($name) > 100) { $errors['name'] = 'Name must be 3-100 characters.'; }

            if ($sku === '') { $errors['sku'] = 'SKU is required.'; }
            elseif (!preg_match('/^[A-Z0-9][A-Z0-9_-]{2,39}$/', $sku)) { $errors['sku'] = 'SKU must be 3-40 characters: letters, digits, - or _.'; }

            if ($cat === '') { $errors['category'] = 'Please choose a category.'; }
            elseif (!in_list($cat, $categories)) { $errors['category'] = 'Invalid category selected.'; }

            if ($price === '') { $errors['unit_price'] = 'Unit price is required.'; }
            elseif (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $price)) { $errors['unit_price'] = 'Enter a number such as 2500 or 2500.50.'; }
            elseif ((float)$price <= 0) { $errors['unit_price'] = 'Price must be greater than zero.'; }

            if ($qty === '') { $errors['quantity'] = 'Quantity is required.'; }
            elseif (!preg_match('/^\d{1,9}$/', $qty)) { $errors['quantity'] = 'Quantity must be a whole number, zero or more.'; }

            if (mb_strlen($desc) > 1000) { $errors['description'] = 'Description must be 1000 characters or fewer (currently ' . mb_strlen($desc) . ').'; }

            $img = check_image('image', MAX_IMAGE_BYTES, $errors);

            if (!$errors) {
                $stored = null;
                try {
                    $st = db()->prepare('SELECT 1 FROM products WHERE sku = ?');
                    $st->execute([$sku]);
                    if ($st->fetchColumn()) {
                        $errors['sku'] = 'This SKU already exists. Choose a different SKU. Nothing was saved.';
                    } else {
                        $stored = $img ? save_image($img, __DIR__ . '/uploads') : null;
                        $ins = db()->prepare('INSERT INTO products (name, sku, category_id, unit_price, quantity, description, image_file, is_available)
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                        $ok = $ins->execute([$name, $sku, (int)$cat, $price, (int)$qty, $desc === '' ? null : $desc, $stored, checked('available') ? 1 : 0]);
                        if ($ok && $ins->rowCount() === 1) {
                            flash_set('Product "' . $name . '" saved with ID #' . db()->lastInsertId() . '.');
                            header('Location: index.php');
                            exit;
                        }
                        $errors['_form'] = 'The product could not be saved. Please try again.';
                    }
                } catch (PDOException | RuntimeException $ex) {
                    if ($ex instanceof PDOException && is_duplicate($ex)) { $errors['sku'] = 'This SKU already exists. Nothing was saved.'; }
                    else { error_log('[Lab04] ' . $ex->getMessage()); $errors['_form'] = 'The product could not be saved because of a system problem. Nothing was saved.'; }
                }
                if ($errors && $stored) { @unlink(__DIR__ . '/uploads/' . $stored); }   // no orphan files
            }
        }
    }
} else {
    // ---- extension challenge page ----
    // Extension challenge: inventory listing sorted by category (then product name).
    $rows = [];
    $problem = null;
    try {
        $rows = db()->query(
            'SELECT c.name AS category, p.name, p.sku, p.unit_price, p.quantity,
                    IF(p.is_available = 1, "Yes", "No") AS available, p.image_file
             FROM products p JOIN categories c ON c.id = p.category_id
             ORDER BY c.name ASC, p.name ASC'
        )->fetchAll();
    } catch (PDOException $ex) {
        $problem = db_fail($ex);
    }
    if (!empty($problem)) { $errors['_form'] = $problem; }
}

/* ============ 7. THE PAGE (HTML) ============ */
$pageTitle = ($view === 'list') ? 'Inventory by Category' : 'Add Product (Administrator)';
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
    <p class="muted">No products yet.</p>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th scope="col">Image</th><th scope="col">Category</th><th scope="col">Product</th><th scope="col">SKU</th>
                    <th scope="col">Price (XAF)</th><th scope="col">Qty</th><th scope="col">For sale</th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <?php if ($r['image_file']): ?>
                            <img class="thumb" src="uploads/<?= e($r['image_file']) ?>" alt="<?= e($r['name']) ?>">
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <td><?= e($r['category']) ?></td>
                    <td><?= e($r['name']) ?></td>
                    <td><?= e($r['sku']) ?></td>
                    <td><?= e(number_format((float)$r['unit_price'], 2)) ?></td>
                    <td><?= e($r['quantity']) ?></td>
                    <td><?= e($r['available']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted"><?= count($rows) ?> product(s).</p>
<?php endif; ?>
<?php else: ?>
<form method="post" action="index.php" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="field<?= cls('name') ?>">
        <label for="name">Product name <span class="req" aria-hidden="true">*</span></label>
        <input type="text" id="name" name="name" value="<?= val('name') ?>" maxlength="100" required<?= aria('name') ?>>
        <?= err('name') ?>
    </div>

    <div class="field<?= cls('sku') ?>">
        <label for="sku">SKU <span class="req" aria-hidden="true">*</span></label>
        <input type="text" id="sku" name="sku" value="<?= val('sku') ?>" maxlength="40" placeholder="e.g. ELEC-001" required<?= aria('sku') ?>>
        <?= err('sku') ?>
    </div>

    <div class="field<?= cls('category') ?>">
        <label for="category">Category <span class="req" aria-hidden="true">*</span></label>
        <select id="category" name="category" required<?= aria('category') ?>>
            <option value="">Choose an option</option>
            <?php foreach ($categories as $v => $l): ?>
                <option value="<?= e($v) ?>"<?= sel('category', (string)$v) ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
        <?= err('category') ?>
    </div>

    <div class="field<?= cls('unit_price') ?>">
        <label for="unit_price">Unit price (XAF) <span class="req" aria-hidden="true">*</span></label>
        <input type="number" id="unit_price" name="unit_price" value="<?= val('unit_price') ?>" min="0.01" step="0.01" required<?= aria('unit_price') ?>>
        <?= err('unit_price') ?>
    </div>

    <div class="field<?= cls('quantity') ?>">
        <label for="quantity">Quantity <span class="req" aria-hidden="true">*</span></label>
        <input type="number" id="quantity" name="quantity" value="<?= val('quantity') ?>" min="0" step="1" required<?= aria('quantity') ?>>
        <?= err('quantity') ?>
    </div>

    <div class="field<?= cls('description') ?>">
        <label for="description">Description</label>
        <textarea id="description" name="description" rows="4" maxlength="1000"<?= aria('description') ?>><?= val('description') ?></textarea>
        <?= err('description') ?>
    </div>

    <div class="field<?= cls('image') ?>">
        <label for="image">Product image (JPEG or PNG, under 2 MB)</label>
        <input type="file" id="image" name="image" accept="image/jpeg,image/png"<?= aria('image') ?>>
        <?= err('image') ?>
    </div>

    <div class="field<?= cls('available') ?>">
        <span class="legend">Available for sale</span>
        <label class="inline" for="available"><input type="checkbox" id="available" name="available" value="1"<?= chkd('available') ?><?= aria('available') ?>> Yes, available for sale</label>
        <?= err('available') ?>
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
