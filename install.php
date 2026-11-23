<?php

declare(strict_types=1);
/**
 * One-time installer for the Visitation Cards Management System.
 * Creates the database, the tables and the first administrator account.
 * The school's own information (name, initials, address, logo ...) is entered afterwards, on the Dashboard.
 */
require __DIR__ . '/api/core.php';
header('X-Content-Type-Options: nosniff');
$lock = __DIR__ . '/install.lock';
$msg = '';
$done = false;
$checks = [];
$h = fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$checks['PHP 8.0 or newer'] = version_compare(PHP_VERSION, '8.0.0', '>=');
$checks['PDO MySQL extension'] = extension_loaded('pdo_mysql');
$optional = [
    'Backups and Excel upload (zip extension)' => class_exists('ZipArchive'),
    'GD image extension (photo resizing; uploads still work without it)' => extension_loaded('gd'),
];
$checks['uploads/ folder is writable'] = is_writable(__DIR__ . '/uploads') && is_writable(__DIR__ . '/uploads/photos') && is_writable(__DIR__ . '/uploads/logo');
$ready = !in_array(false, $checks, true);

if (file_exists($lock)) {
    $msg = 'The system is already installed. For safety, install.php is locked. Delete install.lock only if you really want to run it again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    $user = trim((string)($_POST['username'] ?? ''));
    $name = trim((string)($_POST['full_name'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $user)) {
        $msg = 'Username: 3 to 30 letters, numbers, "_", "." or "-".';
    } elseif (!preg_match('/^\p{L}[\p{L}\' .-]{1,79}$/u', $name)) {
        $msg = 'Full name must contain letters only.';
    } elseif (strlen($pass) < 8 || !preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass)) {
        $msg = 'Password: at least 8 characters, with letters and numbers.';
    } elseif ($pass !== (string)($_POST['password2'] ?? '')) {
        $msg = 'The two passwords do not match.';
    } else {
        try {
            $pdo = db_connect(false);
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo = db();                                  // connects and creates / upgrades every table
            if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
                $msg = 'This database already has user accounts, so no new administrator was created. Sign in with an existing account, or use tools/reset-password.php.';
            } else {
                if ((int)$pdo->query('SELECT COUNT(*) FROM schools')->fetchColumn() === 0) {
                    // an empty school record: the administrator fills it in on the Dashboard (setup_done = 0 until then)
                    $pdo->prepare("INSERT INTO schools (code, name, qr_secret, logo, signer, setup_done) VALUES ('SETUP', '', ?, 'assets/img/vcms-logo.png', 'Headteacher', 0)")
                        ->execute([bin2hex(random_bytes(32))]);
                }
                $sid = (int)$pdo->query('SELECT MIN(id) FROM schools')->fetchColumn();
                $pdo->prepare('INSERT INTO users (school_id,username,full_name,password_hash,role) VALUES (?,?,?,?,"system_admin")')
                    ->execute([$sid, $user, $name, password_hash($pass, PASSWORD_DEFAULT)]);
                file_put_contents($lock, 'installed ' . date('c'));
                $done = true;
            }
        } catch (PDOException $e) {
            $msg = $e->getCode() === '23000'
                ? 'That username already exists. Choose another username.'
                : 'Database error: ' . $e->getMessage() . ' (is MySQL started in XAMPP, and are the details in api/config.php right?)';
        }
    }
}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install - <?= $h(APP_NAME) ?></title>
<style>body{font:15px/1.5 system-ui,Arial,sans-serif;background:#f3f4f7;margin:0;padding:24px;color:#1d2330}
.box{max-width:480px;margin:0 auto;background:#fff;border:1px solid #dde1e8;border-radius:12px;padding:22px}
h1{font-size:19px;margin:0 0 4px}label{display:block;font-size:13px;color:#566;margin:10px 0 3px}input{width:100%;padding:9px;border:1px solid #c9ced8;border-radius:7px;font:inherit;box-sizing:border-box}
button{margin-top:16px;width:100%;padding:11px;border:0;border-radius:8px;background:#14213d;color:#fff;font:inherit;cursor:pointer}button[disabled]{opacity:.5}
.ok{color:#1e8e3e}.no{color:#c2410c}.err{background:#fff3ec;border:1px solid #f5c9b0;padding:10px;border-radius:8px;margin-top:12px}.good{background:#eaf7ee;border:1px solid #b9e2c4;padding:12px;border-radius:8px}
.logo{display:block;width:64px;height:64px;margin:0 auto 10px}</style></head>
<body><div class="box"><img class="logo" src="assets/img/vcms-logo.png" alt=""><h1 style="text-align:center"><?= $h(APP_NAME) ?></h1><p style="margin:0 0 12px;color:#566;text-align:center">Installation &middot; version <?= $h(APP_VERSION) ?></p>
<?php if ($done): ?>
  <div class="good"><b>Installed.</b><br>The database <code><?= $h(DB_NAME) ?></code> was created and your administrator account is ready.<br><br>
  <b>Next:</b> sign in. The Dashboard will ask you for your school's information (name, initials, address, contact and logo). You enter it once, and it is used on the visitation cards, on the Dashboard and in every admission number.<br><br>
  <a href="index.html">Open the system and sign in</a><br><small>For safety, you can also delete install.php now.</small></div>
<?php else: ?>
  <ul style="padding-left:18px"><?php foreach ($checks as $k => $v): ?><li class="<?= $v ? 'ok' : 'no' ?>"><?= $h($k) ?>: <?= $v ? 'OK' : 'MISSING' ?></li><?php endforeach;
    foreach ($optional as $k => $v): ?><li class="<?= $v ? 'ok' : 'no' ?>"><?= $h($k) ?>: <?= $v ? 'OK' : 'not enabled (needed for backups and Excel upload; see the README)' ?></li><?php endforeach; ?></ul>
  <?php if ($msg): ?><div class="err"><?= $h($msg) ?></div><?php endif; ?>
  <?php if (!file_exists($lock)): ?>
  <form method="post" autocomplete="off"><p style="margin:12px 0 0">Create the administrator account. The school's details come next, after you sign in.</p>
    <label>Your full name</label><input name="full_name" required maxlength="80">
    <label>Username</label><input name="username" required maxlength="30" value="admin">
    <label>Password (8+ characters, letters and numbers)</label><input type="password" name="password" required minlength="8">
    <label>Repeat password</label><input type="password" name="password2" required minlength="8">
    <button <?= $ready ? '' : 'disabled' ?>>Install</button></form>
  <?php endif; ?>
<?php endif; ?></div></body></html>
