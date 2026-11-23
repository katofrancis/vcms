<?php

declare(strict_types=1);
/**
 * Emergency password reset for ANY account, including the last administrator who is locked out:
 *     php tools/reset-password.php <username>
 * Prints a one-time password. The person must choose a new one at the next sign-in.
 * Anyone who can run this already has the computer, which is why it needs no old password.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}
require __DIR__ . '/../api/core.php';
$u = $argv[1] ?? '';
if ($u === '') {
    echo "Usage: php tools/reset-password.php <username>\n";
    exit(2);
}
$q = db()->prepare('SELECT id FROM users WHERE username=?');
$q->execute([$u]);
$id = $q->fetchColumn();
if (!$id) {
    fwrite(STDERR, "No user named \"$u\".\n");
    exit(1);
}
$a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
$pw = '';
for ($i = 0; $i < 10; $i++) {
    $pw .= $a[random_int(0, strlen($a) - 1)];
}
$pw .= random_int(2, 9);
db()->prepare('UPDATE users SET password_hash=?, must_change=1, active=1, failed_logins=0, locked_until=NULL WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
db()->prepare('DELETE FROM login_attempts WHERE ip LIKE ? OR ip NOT LIKE ?')->execute(['%|' . strtolower($u), '%|%']);
echo "Password for \"$u\" is now:  $pw\nThe account is unlocked and enabled. A new password must be chosen at the next sign-in.\n";
