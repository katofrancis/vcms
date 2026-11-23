<?php

declare(strict_types=1);
/**
 * Visitation Cards Management System - core (no session): database connection, versioned migrations, school helpers, QR signing.
 * Used by the web API, the QR verify page and the command-line tools (backup / restore / reset password).
 */
require_once __DIR__ . '/config.php';

const APP_NAME       = 'Visitation Cards Management System';
const APP_SHORT      = 'VCMS';
const APP_VERSION    = '4.0';
const SCHEMA_VERSION = 5;

if (!function_exists('mb_substr')) {   // fallback if the mbstring extension is switched off
    function mb_substr(string $s, int $start, ?int $len = null, ?string $enc = null): string
    {
        preg_match_all('/./us', $s, $m);
        return implode('', array_slice($m[0], $start, $len));
    }
}

/* ---------- database ---------- */
function db_connect(bool $withDb = true, bool $buffered = true): PDO
{
    $opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
        $opt[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
    }
    if (!$buffered) {
        $opt[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = false;
    }
    return new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ($withDb ? ';dbname=' . DB_NAME : '') . ';charset=utf8mb4', DB_USER, DB_PASS, $opt);
}
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = db_connect();
        migrate($pdo);
    }
    return $pdo;
}

/* ---------- migrations (safe to run any number of times, safe when several users open the system at once) ---------- */
function db_has_table(PDO $p, string $t): bool
{
    $q = $p->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$t]);
    return (int)$q->fetchColumn() > 0;
}
function db_has_col(PDO $p, string $t, string $c): bool
{
    $q = $p->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $q->execute([$t, $c]);
    return (int)$q->fetchColumn() > 0;
}
function db_has_idx(PDO $p, string $t, string $i): bool
{
    $q = $p->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
    $q->execute([$t, $i]);
    return (int)$q->fetchColumn() > 0;
}
function run_sql_file(PDO $pdo, string $file): void
{
    $sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(';', (string)$sql))) as $stmt) {
        $pdo->exec($stmt);
    }
}
function add_col(PDO $p, string $t, string $c, string $def): void
{
    if (!db_has_col($p, $t, $c)) {
        $p->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
    }
}
function add_idx(PDO $p, string $t, string $i, string $ddl): void
{
    if (!db_has_idx($p, $t, $i)) {
        $p->exec("ALTER TABLE `$t` ADD $ddl");
    }
}
function db_has_fk(PDO $p, string $t, string $n): bool
{
    $q = $p->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE='FOREIGN KEY'");
    $q->execute([$t, $n]);
    return (int)$q->fetchColumn() > 0;
}
function drop_idx(PDO $p, string $t, string $i): void
{
    if (db_has_idx($p, $t, $i)) {
        $p->exec("ALTER TABLE `$t` DROP INDEX `$i`");
    }
}
/** One cheap query on every request: is the database already at the current structure? */
function schema_current(PDO $pdo): bool
{
    try {
        return (int)$pdo->query('SELECT v FROM schema_version WHERE id=1')->fetchColumn() >= SCHEMA_VERSION;
    } catch (PDOException $e) {
        return false;                                   // the table does not exist yet: a new or very old database
    }
}
function migrate(PDO $pdo): void
{
    if (schema_current($pdo)) {
        return;
    }

    $pdo->query("SELECT GET_LOCK('kvc_migrate', 120)")->fetchColumn();      // only one request migrates; the others wait, then find it done
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (id TINYINT NOT NULL PRIMARY KEY, v INT NOT NULL) ENGINE=InnoDB');
        if (schema_current($pdo)) {
            return;
        }

        run_sql_file($pdo, APP_ROOT . '/database/schema.sql');      // all tables (only created when missing)

        /* ----- databases made by older versions: bring them up to the current structure ----- */
        add_col($pdo, 'schools', 'code', 'VARCHAR(8) NULL');
        add_col($pdo, 'schools', 'qr_secret', 'CHAR(64) NULL');
        add_col($pdo, 'schools', 'verify_url', "VARCHAR(200) NOT NULL DEFAULT ''");
        add_col($pdo, 'schools', 'active', 'TINYINT(1) NOT NULL DEFAULT 1');
        foreach ($pdo->query('SELECT id,name,code,qr_secret FROM schools')->fetchAll() as $s) {
            $code = $s['code'];
            if ($code === null || $code === '') {
                $words = preg_split('/\s+/', strtoupper(preg_replace('/[^A-Za-z ]/', ' ', (string)$s['name'])) ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $code = substr(implode('', array_map(fn ($w) => $w[0], $words)), 0, 6) ?: 'SCH';
                $dup = $pdo->prepare('SELECT COUNT(*) FROM schools WHERE code=? AND id<>?');
                $dup->execute([$code, $s['id']]);
                if ((int)$dup->fetchColumn() > 0) {
                    $code = substr($code, 0, 4) . $s['id'];
                }
                $pdo->prepare('UPDATE schools SET code=? WHERE id=?')->execute([$code, $s['id']]);
            }
            if (!$s['qr_secret']) {
                $pdo->prepare('UPDATE schools SET qr_secret=? WHERE id=?')->execute([bin2hex(random_bytes(32)), $s['id']]);
            }
        }
        add_idx($pdo, 'schools', 'uq_school_code', 'UNIQUE KEY uq_school_code (code)');

        add_col($pdo, 'users', 'perms', 'TEXT NULL');
        add_col($pdo, 'users', 'must_change', 'TINYINT(1) NOT NULL DEFAULT 0');
        add_col($pdo, 'users', 'active', 'TINYINT(1) NOT NULL DEFAULT 1');
        add_col($pdo, 'users', 'pw_changed_at', 'DATETIME NULL');

        add_col($pdo, 'students', 'status', "ENUM('active','graduated','left') NOT NULL DEFAULT 'active'");
        add_col($pdo, 'students', 'status_on', 'DATE NULL');
        add_col($pdo, 'students', 'rev', 'INT UNSIGNED NOT NULL DEFAULT 1');
        add_idx($pdo, 'students', 'idx_name', 'KEY idx_name (school_id, last_name, first_name)');
        $pdo->exec('ALTER TABLE login_attempts MODIFY ip VARCHAR(90) NOT NULL');      // keys are "ip" and "ip|username"

        /* ----- version 3: no terms and no academic year ----- */
        if (db_has_col($pdo, 'visitation_cards', 'term_id')) {
            // one card per student: keep the one from the current term, otherwise the newest
            $pdo->exec('DROP TEMPORARY TABLE IF EXISTS kvc_keep');
            $cur = db_has_col($pdo, 'schools', 'current_term_id') ? 'sc.current_term_id' : 'NULL';
            $pdo->exec("CREATE TEMPORARY TABLE kvc_keep (PRIMARY KEY (id)) AS
                SELECT COALESCE(MAX(CASE WHEN c.term_id = $cur THEN c.id END), MAX(c.id)) AS id
                FROM visitation_cards c JOIN students st ON st.id = c.student_id JOIN schools sc ON sc.id = st.school_id GROUP BY c.student_id");
            $pdo->exec('DELETE c FROM visitation_cards c LEFT JOIN kvc_keep k ON k.id = c.id WHERE k.id IS NULL');
            $pdo->exec('DROP TEMPORARY TABLE kvc_keep');
            add_idx($pdo, 'visitation_cards', 'uq_card_student', 'UNIQUE KEY uq_card_student (student_id)');
            if (db_has_fk($pdo, 'visitation_cards', 'fk_cards_term')) {
                $pdo->exec('ALTER TABLE visitation_cards DROP FOREIGN KEY fk_cards_term');
            }
            drop_idx($pdo, 'visitation_cards', 'uq_card_student_term');
            drop_idx($pdo, 'visitation_cards', 'idx_card_term');
            $pdo->exec('ALTER TABLE visitation_cards DROP COLUMN term_id');
        }
        $pdo->exec('DROP TABLE IF EXISTS terms');
        if (db_has_col($pdo, 'schools', 'current_term_id')) {
            $pdo->exec('ALTER TABLE schools DROP COLUMN current_term_id');
        }
        if (db_has_col($pdo, 'schools', 'academic_year')) {
            $pdo->exec('ALTER TABLE schools DROP COLUMN academic_year');
        }

        // admission numbers no longer carry a year: one running counter per school (older years are added together so numbering carries on)
        if (db_has_table($pdo, 'admission_seq') && db_has_col($pdo, 'admission_seq', 'yr')) {
            $pdo->exec('CREATE TABLE admission_seq_new (school_id INT UNSIGNED NOT NULL PRIMARY KEY, last_seq INT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $pdo->exec('INSERT INTO admission_seq_new (school_id, last_seq) SELECT school_id, SUM(last_seq) FROM admission_seq GROUP BY school_id');
            $pdo->exec('DROP TABLE admission_seq');
            $pdo->exec('RENAME TABLE admission_seq_new TO admission_seq');
        } else {
            $pdo->exec('CREATE TABLE IF NOT EXISTS admission_seq (school_id INT UNSIGNED NOT NULL PRIMARY KEY, last_seq INT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
        // every enrolled student has exactly one card
        $pdo->exec("INSERT IGNORE INTO visitation_cards (school_id, student_id, card_no)
            SELECT st.school_id, st.id, CONCAT('VC-', sc.code, '-', LPAD(st.id, 5, '0')) FROM students st JOIN schools sc ON sc.id = st.school_id
            LEFT JOIN visitation_cards c ON c.student_id = st.id WHERE st.status = 'active' AND c.id IS NULL");

        /* ----- version 4: one index that serves the normal student list (filter and sort without a separate sorting step) ----- */
        add_idx($pdo, 'students', 'idx_list', 'KEY idx_list (school_id, status, class_name, stream, last_name, first_name)');
        drop_idx($pdo, 'students', 'idx_status');

        /* ----- version 5: the school describes itself after installation (first-run setup) ----- */
        add_col($pdo, 'schools', 'setup_done', 'TINYINT(1) NOT NULL DEFAULT 0');
        // a school that already has a name was set up by an earlier version: it does not see the setup screen
        $pdo->exec("UPDATE schools SET setup_done = 1 WHERE setup_done = 0 AND name <> ''");

        $pdo->prepare('INSERT INTO schema_version (id,v) VALUES (1,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute([SCHEMA_VERSION]);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('kvc_migrate')")->fetchColumn();
    }
}

/* ---------- sanitising ---------- */
/** Trim, strip HTML tags and control characters, collapse spaces, cap the length. */
function clean(mixed $v, int $max = 255): string
{
    $v = is_scalar($v) ? (string)$v : '';
    $v = strip_tags($v);
    $v = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $v) ?? '';
    $v = preg_replace('/\s+/u', ' ', $v) ?? '';
    return mb_substr(trim($v), 0, $max);
}

const CLASSES = ['S1', 'S2', 'S3', 'S4', 'S5', 'S6'];

/** Suggested school initials from a school name: "Green Hills Secondary School" -> GHSS, "Kigezi" -> KIG.
 *  Small joining words (of, the, and ...) are skipped. The same rules are used by the setup screen in assets/js/app.js. */
function initials_from_name(string $name): string
{
    $t = $name;
    if (function_exists('iconv')) {
        $x = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
        if ($x !== false) {
            $t = $x;
        }
    }
    $t = strtoupper(str_replace(["'", "\u{2019}", '`'], '', $t));
    $words = preg_split('/[^A-Z]+/', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $skip = ['OF', 'THE', 'AND', 'FOR', 'IN', 'AT', 'ON', 'A', 'AN', 'DE', 'LA', 'LE', 'DU', 'DES', 'ET', 'DA', 'DI'];
    $words = array_values(array_filter($words, fn ($w) => !in_array($w, $skip, true)));
    if (!$words) {
        return '';
    }
    $code = '';
    foreach ($words as $w) {
        $code .= $w[0];
    }
    if (strlen($code) < 2) {
        $code = substr($words[0], 0, 3);
    }
    return substr($code, 0, 6);
}

/* ---------- school and card numbers ---------- */
function school_row(int $sid): array
{
    $q = db()->prepare('SELECT * FROM schools WHERE id=?');
    $q->execute([$sid]);
    $r = $q->fetch();
    if (!$r) {
        throw new RuntimeException('School not found.');
    }
    return $r;
}
function card_no_for(array $school, int $studentId): string
{
    return sprintf('VC-%s-%05d', $school['code'], $studentId);
}

/* ---------- classes and streams ---------- */
function streams_map(int $sid): array
{
    $map = array_fill_keys(CLASSES, []);
    $q = db()->prepare('SELECT class_name, stream_name FROM class_streams WHERE school_id=? ORDER BY class_name, stream_name');
    $q->execute([$sid]);
    foreach ($q->fetchAll() as $r) {
        $map[$r['class_name']][] = $r['stream_name'];
    }
    return $map;
}

/* ---------- QR verification: signed links so a forger cannot invent a valid card number ---------- */
function qr_sig(array $school, string $cardNo): string
{
    return substr(hash_hmac('sha256', $cardNo, (string)$school['qr_secret']), 0, 12);
}
function is_local_host(string $host): bool
{
    return (bool)preg_match('/^(localhost|127\.|\[?::1\]?|0\.0\.0\.0)/i', $host);
}
/** Address that phones will use to reach verify.php (no trailing slash). */
function verify_base(array $school): string
{
    $v = trim((string)($school['verify_url'] ?? '')) ?: (VERIFY_BASE_URL !== '' ? VERIFY_BASE_URL : '');
    if ($v !== '') {
        return rtrim($v, '/');
    }
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $path = rtrim(str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api/index.php')))), '/');
    if (is_local_host($host)) {                                   // try the computer's network address instead of "localhost"
        $ip = @gethostbyname((string)gethostname());
        if ($ip && !is_local_host($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
            $port = preg_match('/:(\d+)$/', $host, $m) ? ':' . $m[1] : '';
            $host = $ip . $port;
        }
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https://' : 'http://') . $host . $path;
}
function verify_url_for(array $school, string $cardNo): string
{
    return verify_base($school) . '/verify.php?c=' . rawurlencode($cardNo) . '&k=' . qr_sig($school, $cardNo);
}
