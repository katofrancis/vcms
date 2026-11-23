<?php

declare(strict_types=1);
/**
 * Backup engine shared by tools/backup.php (scheduled job), the "Back up now" button and tools/restore.php.
 *
 * One backup = one ZIP file containing
 *   database.sql   every table (structure + data), written from ONE consistent snapshot while people keep working
 *   uploads/...    every student photo and the school logo
 *   manifest.json  date, schema version, row counts, SHA-256 of database.sql (used to prove the backup is intact)
 * The ZIP is re-opened and checked after it is written, then copied to BACKUP_COPY_DIR (if set), then old backups are pruned.
 */
require_once __DIR__ . '/core.php';

// New files are named vcms-...; backups made by earlier versions (kibatsi-...) stay recognised, restorable and pruned like the rest.
const BK_PATTERN = '/^(?:vcms|kibatsi)-(backup|prerestore)-(\d{8})-(\d{6})\.zip$/';
const BK_END     = '-- END OF BACKUP';
const BK_SEP     = "\n-- ;;\n";                 // statement separator line (a comment, so phpMyAdmin can import the file too)

function bk_log(string $msg): void
{
    $dir = BACKUP_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($dir . '/backup.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}
function bk_status(): array
{
    $f = BACKUP_DIR . '/last_backup.json';
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return is_array($d) ? $d : [];
}
function bk_save_status(array $s): void
{
    if (!is_dir(BACKUP_DIR)) {
        @mkdir(BACKUP_DIR, 0755, true);
    }
    @file_put_contents(BACKUP_DIR . '/last_backup.json', json_encode($s, JSON_PRETTY_PRINT));
}
/** @return list<array{name:string,size:int,time:int,kind:string}> newest first */
function bk_list(): array
{
    $out = [];
    if (is_dir(BACKUP_DIR)) {
        foreach (scandir(BACKUP_DIR) ?: [] as $n) {
            if (preg_match(BK_PATTERN, $n, $m)) {
                $p = BACKUP_DIR . '/' . $n;
                $out[] = ['name' => $n, 'size' => (int)filesize($p), 'time' => (int)filemtime($p), 'kind' => $m[1]];
            }
        }
    }
    usort($out, fn ($a, $b) => $b['time'] <=> $a['time'] ?: strcmp($b['name'], $a['name']));
    return $out;
}

/** Write every table to $file as SQL, from one consistent snapshot. Returns table => row count. */
function bk_dump(string $file): array
{
    $pdo = db_connect(true, false);                      // its own connection, unbuffered: big tables never fill memory
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    $h = fopen($file, 'wb');
    if (!$h) {
        throw new RuntimeException('Cannot write ' . $file);
    }
    $w = fn (string $stmt) => fwrite($h, $stmt . BK_SEP);
    fwrite($h, "-- Visitation Cards Management System backup\n-- created: " . date('c') . "\n-- database: " . DB_NAME . "\n-- app " . APP_VERSION . ', schema ' . SCHEMA_VERSION . "\n");
    $w('SET NAMES utf8mb4;');
    $w('SET FOREIGN_KEY_CHECKS=0;');
    $w("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';");
    $tables = [];
    $rs = $pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'");
    foreach ($rs->fetchAll(PDO::FETCH_NUM) as $r) {
        $tables[] = $r[0];
    }
    $counts = [];
    foreach ($tables as $t) {
        $create = $pdo->query('SHOW CREATE TABLE `' . $t . '`')->fetch(PDO::FETCH_NUM)[1];
        $w('DROP TABLE IF EXISTS `' . $t . '`;');
        $w($create . ';');
        $n = 0;
        $batch = [];
        $size = 0;
        $cols = null;
        $flush = function () use (&$batch, &$size, $t, &$cols, $w) {
            if ($batch) {
                $w('INSERT INTO `' . $t . '` (' . $cols . ') VALUES ' . implode(',', $batch) . ';');
                $batch = [];
                $size = 0;
            }
        };
        $q = $pdo->query('SELECT * FROM `' . $t . '`');
        while ($row = $q->fetch(PDO::FETCH_NUM)) {
            if ($cols === null) {
                $names = [];
                for ($i = 0; $i < $q->columnCount(); $i++) {
                    $names[] = '`' . $q->getColumnMeta($i)['name'] . '`';
                }
                $cols = implode(',', $names);
            }
            $vals = array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row);
            $line = '(' . implode(',', $vals) . ')';
            $batch[] = $line;
            $size += strlen($line);
            $n++;
            if (count($batch) >= 300 || $size > 900000) {
                $flush();
            }
        }
        $q->closeCursor();
        $flush();
        $counts[$t] = $n;
    }
    $w('SET FOREIGN_KEY_CHECKS=1;');
    fwrite($h, BK_END . "\n");
    fclose($h);
    $pdo->exec('COMMIT');
    return $counts;
}

function bk_add_dir(ZipArchive $zip, string $abs, string $rel, int &$files): void
{
    if (!is_dir($abs)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $name = $rel . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($abs) + 1));
        $zip->addFile($f->getPathname(), $name);
        $files++;
    }
}

/** Re-open a finished backup and prove it is complete. Returns null when fine, otherwise the problem. */
function bk_verify(string $zipFile): ?string
{
    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::CHECKCONS) !== true) {
        return 'The ZIP file is damaged and cannot be opened.';
    }
    $mj = $zip->getFromName('manifest.json');
    $man = $mj ? json_decode($mj, true) : null;
    if (!is_array($man) || empty($man['sql_sha256'])) {
        $zip->close();
        return 'manifest.json is missing or unreadable.';
    }
    $s = $zip->getStream('database.sql');
    if (!$s) {
        $zip->close();
        return 'database.sql is missing from the backup.';
    }
    $ctx = hash_init('sha256');
    $tail = '';
    while (!feof($s)) {
        $chunk = fread($s, 1048576);
        if ($chunk === false) {
            break;
        } hash_update($ctx, $chunk);
        $tail = substr($tail . $chunk, -64);
    }
    fclose($s);
    $zip->close();
    if (!hash_equals((string)$man['sql_sha256'], hash_final($ctx))) {
        return 'The database part of the backup does not match its checksum.';
    }
    if (strpos($tail, BK_END) === false) {
        return 'The database dump is incomplete (end marker missing).';
    }
    return null;
}

function bk_retention(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }
    $files = [];
    foreach (scandir($dir) ?: [] as $n) {
        if (preg_match(BK_PATTERN, $n, $m) && $m[1] === 'backup') {
            $files[$n] = $m[2] . $m[3];
        }
    }   // name => YYYYMMDDHHMMSS (the date is what orders them, whichever prefix the name has)
    arsort($files);                                           // newest first
    $keep = array_slice(array_keys($files), 0, BACKUP_KEEP_LAST);
    $months = [];
    foreach ($files as $n => $stamp) {
        $mo = substr($stamp, 0, 6);
        if (!isset($months[$mo]) && count($months) < BACKUP_KEEP_MONTHS) {
            $months[$mo] = true;
            $keep[] = $n;
        }
    }
    $del = 0;
    foreach (array_keys($files) as $n) {
        if (!in_array($n, $keep, true) && @unlink($dir . '/' . $n)) {
            $del++;
        }
    }
    return $del;
}

/**
 * Make one backup now.
 * @param string $kind 'backup' (normal) or 'prerestore' (safety copy taken by restore.php; never pruned automatically)
 * @return array{ok:bool,file?:string,size?:int,seconds?:float,tables?:array,photos?:int,warnings:list<string>,error?:string}
 */
function kvc_backup(string $kind = 'backup'): array
{
    $t0 = microtime(true);
    $warn = [];
    if (!class_exists('ZipArchive')) {
        return bk_fail('The PHP zip extension is not enabled. In C:\\xampp\\php\\php.ini remove the ";" before extension=zip, then restart Apache.', $warn);
    }
    if (!is_dir(BACKUP_DIR) && !@mkdir(BACKUP_DIR, 0755, true)) {
        return bk_fail('Cannot create the backup folder ' . BACKUP_DIR . '. Change BACKUP_DIR in api/config.php.', $warn);
    }
    if (!is_writable(BACKUP_DIR)) {
        return bk_fail('The backup folder ' . BACKUP_DIR . ' is not writable.', $warn);
    }

    $lock = fopen(BACKUP_DIR . '/.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return bk_fail('Another backup is already running. Try again in a minute.', $warn);
    }
    $tmpSql = BACKUP_DIR . '/.tmp-' . bin2hex(random_bytes(4)) . '.sql';
    try {
        $counts = bk_dump($tmpSql);
        $sha = hash_file('sha256', $tmpSql);
        $stamp = date('Ymd-His');
        $final = BACKUP_DIR . '/vcms-' . $kind . '-' . $stamp . '.zip';
        $part = $final . '.part';
        $zip = new ZipArchive();
        if ($zip->open($part, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the ZIP file.');
        }
        $files = 0;
        $zip->addFile($tmpSql, 'database.sql');
        bk_add_dir($zip, APP_ROOT . '/uploads', 'uploads', $files);
        $zip->addFromString('manifest.json', json_encode([
            'created_at' => date('c'), 'app_version' => APP_VERSION, 'schema_version' => SCHEMA_VERSION, 'database' => DB_NAME,
            'kind' => $kind, 'tables' => $counts, 'upload_files' => $files, 'sql_sha256' => $sha, 'host' => gethostname(),
        ], JSON_PRETTY_PRINT));
        $zip->addFromString('READ-ME-FIRST.txt', "Visitation Cards Management System backup.\nTo restore: php tools/restore.php \"<this file>\"  (see docs/RECOVERY.md).\nDo not unzip over a running system by hand.\n");
        if (!$zip->close()) {
            throw new RuntimeException('The ZIP file could not be finished (is the disk full?).');
        }
        @unlink($tmpSql);
        if (!rename($part, $final)) {
            throw new RuntimeException('Could not finalise the ZIP file.');
        }

        if ($problem = bk_verify($final)) {
            @rename($final, $final . '.BAD');
            throw new RuntimeException('Verification failed: ' . $problem);
        }
        if (BACKUP_COPY_DIR !== '' && $kind === 'backup') {                       // second copy on another drive
            if (!is_dir(BACKUP_COPY_DIR)) {
                @mkdir(BACKUP_COPY_DIR, 0755, true);
            }
            if (is_dir(BACKUP_COPY_DIR) && @copy($final, BACKUP_COPY_DIR . '/' . basename($final)) && filesize(BACKUP_COPY_DIR . '/' . basename($final)) === filesize($final)) {
                bk_retention(BACKUP_COPY_DIR);
            } else {
                $warn[] = 'The second copy could not be written to ' . BACKUP_COPY_DIR . ' (drive missing or full?). The main backup is fine.';
            }
        }
        if ($kind === 'backup') {
            bk_retention(BACKUP_DIR);
        }
        $res = ['ok' => true, 'file' => basename($final), 'size' => (int)filesize($final), 'seconds' => round(microtime(true) - $t0, 1), 'tables' => $counts, 'photos' => $files, 'warnings' => $warn];
        if ($kind === 'backup') {
            bk_save_status(['ok' => true, 'time' => date('c'), 'file' => basename($final), 'size' => $res['size'], 'seconds' => $res['seconds'], 'warnings' => $warn, 'students' => $counts['students'] ?? null, 'photos' => $files]);
        }
        bk_log('OK ' . basename($final) . ' ' . round($res['size'] / 1048576, 2) . ' MB, ' . ($counts['students'] ?? '?') . ' students, ' . $files . ' upload files' . ($warn ? ' | WARNING: ' . implode(' ', $warn) : ''));
        return $res;
    } catch (Throwable $e) {
        @unlink($tmpSql);
        if (isset($final)) {
            @unlink($final . '.part');
        }
        return bk_fail($e->getMessage(), $warn, $kind);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
function bk_fail(string $msg, array $warn, string $kind = 'backup'): array
{
    bk_log('FAILED ' . $msg);
    if ($kind === 'backup') {
        $prev = bk_status();
        bk_save_status(['ok' => false, 'time' => date('c'), 'error' => $msg, 'last_good' => ($prev['ok'] ?? false) ? ($prev['time'] ?? null) : ($prev['last_good'] ?? null)]);
    }
    return ['ok' => false, 'error' => $msg, 'warnings' => $warn];
}
