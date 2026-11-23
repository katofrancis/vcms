<?php

declare(strict_types=1);
/**
 * Restore the system from a backup ZIP.
 *
 *   php tools/restore.php latest
 *   php tools/restore.php "C:\vcms-backups\vcms-backup-20261110-130000.zip"
 *   php tools/restore.php <file> --yes          (no question asked; for scripts)
 *   php tools/restore.php <file> --db-only      (leave the photos folder alone)
 *
 * What it does, in order (and refuses to continue if any check fails):
 *   1. proves the backup is intact (ZIP opens, SHA-256 of database.sql matches, dump is complete)
 *   2. takes a SAFETY BACKUP of the current system ("vcms-prerestore-*.zip", never deleted automatically)
 *   3. replaces every table with the backup's tables
 *   4. moves the current photos aside to uploads/_before_restore_<time>/ and puts the backed-up photos back
 *   5. upgrades the database to the current program version, then counts rows and photo files to prove the result
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}
require __DIR__ . '/../api/backup_lib.php';

$args = array_slice($argv, 1);
$yes = in_array('--yes', $args, true);
$dbOnly = in_array('--db-only', $args, true);
$target = null;
foreach ($args as $a) {
    if ($a[0] !== '-') {
        $target = $a;
    }
}
if ($target === null) {
    echo "Usage: php tools/restore.php <backup.zip | latest> [--yes] [--db-only]\n";
    exit(2);
}

if ($target === 'latest') {
    $l = array_values(array_filter(bk_list(), fn ($f) => $f['kind'] === 'backup'));
    $target = $l ? BACKUP_DIR . '/' . $l[0]['name'] : '';
} elseif (!is_file($target) && is_file(BACKUP_DIR . '/' . $target)) {
    $target = BACKUP_DIR . '/' . $target;
}
if (!$target || !is_file($target)) {
    fwrite(STDERR, "Backup file not found.\n");
    exit(2);
}
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "The PHP zip extension is not enabled (php.ini: extension=zip).\n");
    exit(2);
}

echo "Checking $target ...\n";
if ($p = bk_verify($target)) {
    fwrite(STDERR, "BACKUP REJECTED: $p\nNothing was changed. Try an older backup.\n");
    exit(1);
}
$zip = new ZipArchive();
$zip->open($target);
$man = json_decode((string)$zip->getFromName('manifest.json'), true);
echo "  Backup made : {$man['created_at']}  (program {$man['app_version']}, schema {$man['schema_version']})\n";
echo '  Students    : ' . ($man['tables']['students'] ?? '?') . "   Photo files: {$man['upload_files']}\n";
if ((int)$man['schema_version'] > SCHEMA_VERSION) {
    fwrite(STDERR, "This backup is from a NEWER version of the program. Install the newer program first.\n");
    exit(1);
}
echo "  Backup is intact.\n\n";

if (!$yes) {
    echo 'WARNING: this REPLACES all current data' . ($dbOnly ? '' : ' and photos') . " with the backup.\nA safety backup of the current system is taken first.\nType RESTORE to continue: ";
    if (trim((string)fgets(STDIN)) !== 'RESTORE') {
        echo "Cancelled. Nothing changed.\n";
        exit(0);
    }
}

// 2. safety backup of whatever exists now
$hasData = false;
try {
    $c = db_connect();
    $hasData = (bool)$c->query("SHOW TABLES LIKE 'students'")->fetch();
} catch (Throwable $e) { /* database does not exist yet: fresh computer */
}
if ($hasData) {
    echo "Taking a safety backup of the current system ...\n";
    $s = kvc_backup('prerestore');
    if (!$s['ok']) {
        fwrite(STDERR, 'Safety backup FAILED (' . $s['error'] . "). Restore cancelled; nothing was changed.\n");
        exit(1);
    }
    echo '  Saved as ' . BACKUP_DIR . DIRECTORY_SEPARATOR . $s['file'] . "\n";
}

// 3. database
$tmp = sys_get_temp_dir() . '/kvc-restore-' . bin2hex(random_bytes(4)) . '.sql';
$in = $zip->getStream('database.sql');
$out = fopen($tmp, 'wb');
stream_copy_to_stream($in, $out);
fclose($in);
fclose($out);
db_connect(false)->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = db_connect();
echo "Restoring the database ...\n";
$h = fopen($tmp, 'rb');
$buf = '';
$n = 0;
while (($line = fgets($h)) !== false) {
    if (rtrim($line, "\r\n") === '-- ;;') {
        $stmt = trim($buf);
        $buf = '';
        if ($stmt !== '') {
            try {
                $pdo->exec($stmt);
                $n++;
            } catch (Throwable $e) {
                fclose($h);
                @unlink($tmp);
                fwrite(STDERR, "\nRESTORE ERROR in statement " . ($n + 1) . ': ' . $e->getMessage() . "\nThe database may be half-restored. Run the restore again (a safety backup exists), or restore the prerestore ZIP.\n");
                exit(1);
            }
        }
        continue;
    }
    if ($buf === '' && (str_starts_with($line, '--') || trim($line) === '')) {
        continue;
    }     // comment lines between statements
    $buf .= $line;
}
fclose($h);
@unlink($tmp);
echo "  $n statements applied.\n";

// 4. photos and logo
if (!$dbOnly) {
    $up = APP_ROOT . '/uploads';
    $aside = $up . '/_before_restore_' . date('Ymd-His');
    foreach (['photos', 'logo'] as $d) {
        if (is_dir("$up/$d")) {
            @mkdir($aside, 0755, true);
            rename("$up/$d", "$aside/$d");
        }
    }
    $cnt = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if (!preg_match('#^uploads/(photos|logo)/#', $name) || str_contains($name, '..') || str_ends_with($name, '/')) {
            continue;
        }
        $dest = APP_ROOT . '/' . $name;
        @mkdir(dirname($dest), 0755, true);
        $s = $zip->getStream($name);
        $o = fopen($dest, 'wb');
        stream_copy_to_stream($s, $o);
        fclose($s);
        fclose($o);
        $cnt++;
    }
    foreach (['photos', 'logo'] as $d) {
        if (!is_file("$up/$d/index.html")) {
            @mkdir("$up/$d", 0755, true);
            @file_put_contents("$up/$d/index.html", '');
        }
    }
    echo "  $cnt photo/logo files put back. The previous photos were kept in " . (is_dir($aside) ? $aside : '(none existed)') . "\n";
}
$zip->close();

// 5. upgrade + prove
echo "Upgrading to the current program version and checking ...\n";
require_once __DIR__ . '/../api/core.php';
$db = db();
$bad = 0;
foreach ($man['tables'] as $t => $rows) {
    $have = (int)$db->query('SELECT COUNT(*) FROM `' . $t . '`')->fetchColumn();
    if ($have !== (int)$rows) {
        echo "  MISMATCH in $t: backup has $rows rows, database has $have\n";
        $bad++;
    }
}
$missing = 0;
foreach ($db->query('SELECT photo FROM students WHERE photo IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $p) {
    if (!is_file(APP_ROOT . '/' . $p)) {
        $missing++;
    }
}
echo $bad ? "  $bad table(s) differ from the backup.\n" : "  Every table has exactly the number of rows the backup recorded.\n";
echo $missing ? "  WARNING: $missing student photo file(s) are missing on disk" . ($dbOnly ? ' (you used --db-only).' : '.') . "\n" : "  All student photos referenced in the database are present.\n";
echo $bad ? "\nRestore finished WITH DIFFERENCES - read the lines above.\n" : "\nRESTORE COMPLETE. Sign in and check the dashboard totals against the figures above.\n";
exit($bad ? 1 : 0);
