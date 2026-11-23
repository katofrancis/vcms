<?php

declare(strict_types=1);
/**
 * Web helpers: session, CSRF, users and permissions, login lockout, validation, images.
 */
require __DIR__ . '/core.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('kvc_session');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
if (isset($_SESSION['user'])) {                       // automatic sign-out after inactivity
    if (time() - (int)($_SESSION['last'] ?? time()) > IDLE_MINUTES * 60) {
        unset($_SESSION['user']);
    } else {
        $_SESSION['last'] = time();
    }
}
/** Read-only requests release the session lock at once, so one person's slow page never blocks their other requests
 *  (and several people on the same computer or browser profile never queue behind each other). */
function release_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

/* ---------- responses ---------- */
function out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $json = (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (strlen($json) > 1400 && function_exists('gzencode') && str_contains((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip')) {
        $json = (string)gzencode($json, 4);
        header('Content-Encoding: gzip');
        header('Vary: Accept-Encoding');
    }
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}
function fail(string $msg, int $code = 400, array $extra = []): never
{
    out(['ok' => false, 'error' => $msg] + $extra, $code);
}
set_exception_handler(function (Throwable $e) {
    error_log('[visitation-cards] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $hint = $e instanceof PDOException ? 'Database error. Check that MySQL is running and the system is installed.' : 'Unexpected server error.';
    fail($hint, 500);
});

/* ---------- request helpers ---------- */
function json_body(): array
{
    $raw = file_get_contents('php://input');
    $d = json_decode($raw === false ? '' : $raw, true);
    return is_array($d) ? $d : [];
}
function check_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'], $sent)) {
        fail('Your session expired. Reload the page and try again.', 403);
    }
}
function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/* ---------- permissions: a role gives a starting set, an administrator can tune it for each person ---------- */
const PERM_CATALOG = [
    'students.view'   => 'See students, cards and the dashboard',
    'students.write'  => 'Register, import and edit students',
    'photos.upload'   => 'Upload student photos (single and bulk)',
    'students.delete' => 'Delete students',
    'students.promote' => 'Promote students to the next class / graduate them',
    'cards.print'     => 'Preview, print and save cards as PDF',
    'reports.view'    => 'View and export reports',
    'streams.manage'  => 'Set up classes and streams',
    'design.manage'   => 'Change school details and card design',
    'users.manage'    => 'Manage staff accounts, passwords and permissions',
    'backup.manage'   => 'Run and download backups',
];
const ROLE_NAMES = ['system_admin' => 'System administrator', 'school_admin' => 'School administrator', 'data_entry' => 'Data entry officer', 'printing' => 'Printing officer'];
function role_defaults(string $role): array
{
    $all = array_keys(PERM_CATALOG);
    return match ($role) {
        'system_admin' => $all,
        'school_admin' => $all,
        'data_entry'   => ['students.view', 'students.write', 'photos.upload', 'reports.view'],
        'printing'     => ['students.view', 'cards.print', 'reports.view'],
        default        => [],
    };
}
function effective_perms(array $u): array
{
    $list = role_defaults((string)$u['role']);
    if ($u['perms'] !== null && $u['perms'] !== '') {
        $custom = json_decode((string)$u['perms'], true);
        if (is_array($custom)) {
            $list = array_values(array_intersect(array_keys(PERM_CATALOG), array_map('strval', $custom)));
        }
    }
    if ($u['role'] === 'system_admin') {
        $list = array_keys(PERM_CATALOG);
    }          // the system administrator always keeps everything
    return $list;
}

/** The signed-in user, read fresh from the database on every request:
 *  a disabled/deleted account or a changed permission takes effect immediately, not at the next sign-in. */
function me(bool $reset = false): ?array
{
    static $cache = false;
    if ($reset) {
        $cache = false;
    }
    if ($cache !== false) {
        return $cache;
    }
    $id = (int)($_SESSION['user']['id'] ?? 0);
    if ($id <= 0) {
        return $cache = null;
    }
    $q = db()->prepare('SELECT id,school_id,username,full_name,role,perms,must_change,active FROM users WHERE id=?');
    $q->execute([$id]);
    $u = $q->fetch();
    if (!$u || !(int)$u['active']) {
        unset($_SESSION['user']);
        return $cache = null;
    }
    $u['id'] = (int)$u['id'];
    $u['school_id'] = (int)$u['school_id'];
    $u['must_change'] = (bool)(int)$u['must_change'];
    $u['perm_list'] = effective_perms($u);
    return $cache = $u;
}
/** The school this system serves (single-school system: every account belongs to it). */
function sid(): int
{
    $u = me();
    if (!$u) {
        fail('Please sign in.', 401);
    }
    return $u['school_id'];
}
/** Require signed-in + a permission (or any of several). $allowChange lets a person with a temporary password reach the password screen. */
function need(string|array $perm, bool $allowChange = false): array
{
    $u = me();
    if (!$u) {
        fail('Please sign in.', 401);
    }
    if ($u['must_change'] && !$allowChange) {
        fail('You must choose a new password before continuing.', 403, ['must_change' => true]);
    }
    $any = (array)$perm;
    if (!array_intersect($any, $u['perm_list'])) {
        fail('You do not have permission for this action.', 403);
    }
    return $u;
}
function can_do(string $perm): bool
{
    $u = me();
    return $u && in_array($perm, $u['perm_list'], true);
}
function audit(string $action, string $detail = ''): void
{
    $u = me();
    db()->prepare('INSERT INTO audit_logs (school_id,user_id,action,detail,ip) VALUES (?,?,?,?,?)')
        ->execute([$u ? sid() : 0, $u['id'] ?? null, $action, mb_substr($detail, 0, 255), client_ip()]);
}

/* ---------- login lockout: per account, per (computer + username) and, much higher, per computer ---------- */
function lock_left(string $key): int
{
    $q = db()->prepare('SELECT locked_until FROM login_attempts WHERE ip=?');
    $q->execute([$key]);
    $t = $q->fetchColumn();
    return $t ? max(0, strtotime((string)$t) - time()) : 0;
}
function login_lock_seconds(string $name): int
{
    return max(lock_left(client_ip()), lock_left(client_ip() . '|' . strtolower($name)));
}
/** @return array{0:int,1:bool} [failures in the window, now locked] */
function bump_attempts(string $key, int $max): array
{
    $pdo = db();
    $now = time();
    $until = date('Y-m-d H:i:s', $now + LOCK_MINUTES * 60);
    $q = $pdo->prepare('SELECT fails, updated_at FROM login_attempts WHERE ip=?');
    $q->execute([$key]);
    $row = $q->fetch();
    $f = ($row && $now - strtotime((string)$row['updated_at']) < 1800) ? (int)$row['fails'] : 0;
    $f++;
    $lock = $f >= $max;
    $pdo->prepare('INSERT INTO login_attempts (ip,fails,locked_until,updated_at) VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE fails=VALUES(fails), locked_until=VALUES(locked_until), updated_at=VALUES(updated_at)')
        ->execute([$key, $lock ? 0 : $f, $lock ? $until : null, date('Y-m-d H:i:s', $now)]);
    return [$f, $lock];
}
/** Register one wrong entry. Returns [attemptsLeft, lockedSeconds]. */
function register_failure(?array $user, string $name): array
{
    $pdo = db();
    $until = date('Y-m-d H:i:s', time() + LOCK_MINUTES * 60);
    [$fPair, $lPair] = bump_attempts(client_ip() . '|' . strtolower($name), MAX_FAILS);
    [, $lIp] = bump_attempts(client_ip(), IP_MAX_FAILS);
    $fUser = $fPair;
    $lUser = false;
    if ($user) {
        $fUser = (int)$user['failed_logins'] + 1;
        $lUser = $fUser >= MAX_FAILS;
        $pdo->prepare('UPDATE users SET failed_logins=?, locked_until=? WHERE id=?')->execute([$lUser ? 0 : $fUser, $lUser ? $until : null, $user['id']]);
    }
    $locked = $lPair || $lIp || $lUser;
    return [max(0, MAX_FAILS - max($fPair, $fUser)), $locked ? LOCK_MINUTES * 60 : 0];
}
function clear_failures(int $userId, string $name): void
{
    db()->prepare('DELETE FROM login_attempts WHERE ip=?')->execute([client_ip() . '|' . strtolower($name)]);
    db()->prepare('UPDATE users SET failed_logins=0, locked_until=NULL WHERE id=?')->execute([$userId]);
}

/* ---------- passwords ---------- */
function password_problem(string $pw, string $username = ''): ?string
{
    if (strlen($pw) < 8) {
        return 'The password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
        return 'Use both letters and numbers in the password.';
    }
    if ($username !== '' && strcasecmp($pw, $username) === 0) {
        return 'The password must not be the same as the username.';
    }
    return null;
}
/** Readable one-time password (no look-alike characters). */
function temp_password(): string
{
    $a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $p = '';
    for ($i = 0; $i < 10; $i++) {
        $p .= $a[random_int(0, strlen($a) - 1)];
    }
    return $p . random_int(2, 9);
}

/* ---------- validation ---------- */
const RX_NAME   = '/^\p{L}[\p{L}\' .-]*$/u';
const RX_PLACE  = '/^\p{L}[\p{L}\' .,-]*$/u';
const RX_ADM    = '/^[A-Za-z0-9][A-Za-z0-9\/-]*$/';
const RX_PHONE  = '/^\d{10,12}$/';
const RX_STREAM = '/^[A-Za-z0-9][A-Za-z0-9 ]*$/';
const RELATIONS = ['Father', 'Mother', 'Guardian', 'Other'];

/**
 * Sanitise and validate one student + guardian record.
 * Admission number: blank = assigned automatically (new students only); a value is accepted only for importing existing records.
 * @return array{0: array<string,string>, 1: list<array{f:string,m:string}>}  [clean data, errors]
 */
function validate_student(array $in, ?int $selfId = null): array
{
    $d = [
        'adm'    => clean($in['adm'] ?? '', 20),
        'first'  => clean($in['first'] ?? '', 40),
        'last'   => clean($in['last'] ?? '', 40),
        'cls'    => strtoupper(clean($in['cls'] ?? '', 3)),
        'stream' => clean($in['stream'] ?? '', 15),
        'origin' => clean($in['origin'] ?? '', 60),
        'pf'     => clean($in['pf'] ?? '', 40),
        'pl'     => clean($in['pl'] ?? '', 40),
        'pc'     => preg_replace('/\s+/', '', clean($in['pc'] ?? '', 20)) ?? '',
        'pa'     => clean($in['pa'] ?? '', 150),
        'rel'    => ucfirst(strtolower(clean($in['rel'] ?? '', 10))),
    ];
    if (preg_match('/^256(\d{9})$/', $d['pc'], $m)) {
        $d['pc'] = '0' . $m[1];
    }   // 256772856765 and 0772856765 are the same number
    $er = [];
    $E = function (string $f, string $m) use (&$er) { $er[] = ['f' => $f, 'm' => $m]; };
    $pdo = db();
    $S = sid();
    $self = $selfId ?? 0;

    if ($d['adm'] !== '') {
        if (!preg_match(RX_ADM, $d['adm'])) {
            $E('adm', 'Admission number may contain only letters, numbers, "/" and "-".');
        } else {
            $q = $pdo->prepare('SELECT id FROM students WHERE school_id=? AND admission_no=? AND id<>?');
            $q->execute([$S, $d['adm'], $self]);
            if ($q->fetch()) {
                $E('adm', 'Admission number ' . $d['adm'] . ' already belongs to another student; it must be unique.');
            }
        }
    }
    foreach ([['first', 'First name'], ['last', 'Last name'], ['pf', 'Guardian first name'], ['pl', 'Guardian last name']] as [$k, $label]) {
        if ($d[$k] === '') {
            $E($k, $label . ' is required.');
        } elseif (!preg_match(RX_NAME, $d[$k])) {
            $E($k, $label . ' must contain letters only (no digits or symbols).');
        }
    }
    if (!in_array($d['cls'], CLASSES, true)) {
        $E('cls', 'Choose a class (S1 to S6).');
    } elseif ($d['stream'] === '') {
        $E('stream', 'Choose a stream.');
    } else {   // the stream must be one the administrator defined for this class
        $q = $pdo->prepare('SELECT stream_name FROM class_streams WHERE school_id=? AND class_name=? AND stream_name=?');
        $q->execute([$S, $d['cls'], $d['stream']]);
        $name = $q->fetchColumn();
        if ($name === false) {
            $E('stream', 'Stream "' . $d['stream'] . '" is not set up for ' . $d['cls'] . '. Ask the administrator to add it under Classes & Streams.');
        } else {
            $d['stream'] = (string)$name;
        }
    }
    if ($d['origin'] !== '' && !preg_match(RX_PLACE, $d['origin'])) {
        $E('origin', 'Place of origin must contain letters only.');
    }
    if ($d['pc'] === '') {
        $E('pc', 'Contact number is required.');
    } elseif (!preg_match(RX_PHONE, $d['pc'])) {
        $E('pc', 'Contact must be digits only, 10 to 12 digits (for example 0772856765).');
    }
    if (!in_array($d['rel'], RELATIONS, true)) {
        $E('rel', 'Choose the relationship to the student.');
    }

    if (!$er) {   // same student entered twice (same name + same guardian contact)
        $q = $pdo->prepare('SELECT s.admission_no FROM students s JOIN guardians g ON g.id=s.guardian_id
            WHERE s.school_id=? AND s.id<>? AND s.first_name=? AND s.last_name=? AND g.phone=? LIMIT 1');
        $q->execute([$S, $self, $d['first'], $d['last'], $d['pc']]);
        if ($row = $q->fetch()) {
            $E('first', 'This student is already registered (admission ' . $row['admission_no'] . ': same name and guardian contact).');
        }
    }
    return [$d, $er];
}

/* ---------- images ---------- */
/** Check an uploaded image without storing it. Returns [error message or null, image info]. */
function check_image(array $f): array
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $e = (int)($f['error'] ?? 0);
        return [$e === UPLOAD_ERR_INI_SIZE || $e === UPLOAD_ERR_FORM_SIZE ? 'The image is larger than the server allows (see upload_max_filesize in php.ini).' : 'The image could not be uploaded.', null];
    }
    if ($f['size'] > 10 * 1048576) {
        return ['The image is larger than 10 MB.', null];
    }
    if (!is_uploaded_file($f['tmp_name'])) {
        return ['Invalid upload.', null];
    }
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
        return ['The file must be a JPG, PNG, GIF or WebP image.', null];
    }
    if (!function_exists('imagecreatefromstring') && !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        return ['Use a JPG or PNG image (or enable the PHP GD extension).', null];
    }
    if (!function_exists('imagecreatefromstring') && $f['size'] > 4 * 1048576) {
        return ['Without the GD extension the image must be under 4 MB.', null];
    }
    return [null, $info];
}
/** Store an uploaded image under a random name (optionally prefixed) and return the relative path.
 *  With the GD extension it is re-encoded, corrected for phone rotation and resized (this also removes hidden payloads). */
function save_image(array $f, string $dir, int $max, string $fmt = 'jpeg', string $prefix = ''): string
{
    [$err, $info] = check_image($f);
    if ($err) {
        throw new RuntimeException($err);
    }
    $abs = APP_ROOT . '/' . $dir;
    if (!is_dir($abs) && !mkdir($abs, 0755, true)) {
        throw new RuntimeException('Could not create the uploads folder.');
    }
    $base = $prefix . bin2hex(random_bytes($prefix === '' ? 12 : 8));

    if (!function_exists('imagecreatefromstring')) {          // GD not enabled: store validated JPG/PNG unchanged
        $name = $base . ($info[2] === IMAGETYPE_PNG ? '.png' : '.jpg');
        if (!move_uploaded_file($f['tmp_name'], "$abs/$name")) {
            throw new RuntimeException('The image could not be stored (check folder permissions).');
        }
        return "$dir/$name";
    }
    $src = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
    if (!$src) {
        throw new RuntimeException('The image could not be read.');
    }
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data') && function_exists('imagerotate')) {   // phone photos: honour the rotation flag
        $ex = @exif_read_data($f['tmp_name']);
        $o = is_array($ex) ? (int)($ex['Orientation'] ?? 1) : 1;
        $deg = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($deg) {
            $rot = imagerotate($src, $deg, 0);
            if ($rot) {
                $src = $rot;
            }
        }
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $k = min(1, $max / max($w, $h));
    $nw = max(1, (int)round($w * $k));
    $nh = max(1, (int)round($h * $k));
    $dst = imagecreatetruecolor($nw, $nh);
    if ($fmt === 'png') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    } else {
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $name = $base . ($fmt === 'png' ? '.png' : '.jpg');
    $ok = $fmt === 'png' ? imagepng($dst, "$abs/$name", 6) : imagejpeg($dst, "$abs/$name", 85);
    if (!$ok) {
        throw new RuntimeException('The image could not be stored (check folder permissions).');
    }
    return "$dir/$name";
}
const UPLOAD_RX = '#^uploads/(photos|logo)/(\d+/)?[A-Za-z0-9_-]+\.(jpg|png)$#';
/** Delete an uploaded file (and its small copy), but only if it lives in our upload folders. */
function delete_upload(?string $rel): void
{
    if (!$rel || !preg_match(UPLOAD_RX, $rel)) {
        return;
    }
    @unlink(APP_ROOT . '/' . $rel);
    if (str_ends_with($rel, '.jpg')) {
        @unlink(APP_ROOT . '/' . thumb_path($rel));
    }
}

/* ---------- small copies of student photos, for lists ---------- */
const THUMB_W = 56;
const THUMB_H = 70;
/** uploads/photos/1/12-ab34.jpg  ->  uploads/photos/1/12-ab34_t.jpg */
function thumb_path(string $rel): string
{
    return substr($rel, 0, -4) . '_t.jpg';
}
function make_thumb(string $srcAbs, string $dstAbs): bool
{
    if (!function_exists('imagecreatefromstring')) {
        return false;
    }
    $src = @imagecreatefromstring((string)@file_get_contents($srcAbs));
    if (!$src) {
        return false;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $k = min(THUMB_W / $w, THUMB_H / $h, 1);
    $nw = max(1, (int)round($w * $k));
    $nh = max(1, (int)round($h * $k));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return imagejpeg($dst, $dstAbs, 72);
}
/** The small copy as a data: address, so a list needs no extra request per photo. It is made on first use for older photos. */
function thumb_data_uri(?string $rel): ?string
{
    if (!$rel || !str_ends_with($rel, '.jpg') || !preg_match(UPLOAD_RX, $rel)) {
        return null;
    }
    $t = APP_ROOT . '/' . thumb_path($rel);
    if (!is_file($t) && !make_thumb(APP_ROOT . '/' . $rel, $t)) {
        return null;
    }
    $bytes = @file_get_contents($t);
    return $bytes === false ? null : 'data:image/jpeg;base64,' . base64_encode($bytes);
}
/** URL of a student's photo. The student id is in the URL, so a photo can only ever be shown for the student it belongs to. */
function photo_url(int $studentId, ?string $rel): ?string
{
    return $rel ? 'api/index.php?action=photo&id=' . $studentId . '&v=' . substr(md5($rel), 0, 8) : null;
}
function logo_url(?string $rel): ?string
{
    if (!$rel) {
        return null;
    }
    if (preg_match('#^uploads/logo/([A-Za-z0-9_-]+\.(?:jpg|png))$#', $rel, $m)) {
        return 'api/index.php?action=logo&f=' . $m[1];
    }
    return $rel;   // bundled default such as assets/img/logo.png
}
