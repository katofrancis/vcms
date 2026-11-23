<?php

declare(strict_types=1);
/**
 * Scheduled backup (run by Windows Task Scheduler / cron):   php tools/backup.php
 * Exit code 0 = good backup made and verified, 1 = failed (see BACKUP_DIR/backup.log).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}
require __DIR__ . '/../api/backup_lib.php';
$r = kvc_backup('backup');
if ($r['ok']) {
    echo 'OK  ' . BACKUP_DIR . DIRECTORY_SEPARATOR . $r['file'] . '  (' . round($r['size'] / 1048576, 2) . ' MB, ' . ($r['tables']['students'] ?? '?') . ' students, ' . $r['photos'] . " upload files, {$r['seconds']}s)\n";
    foreach ($r['warnings'] as $w) {
        echo "WARNING: $w\n";
    }
    exit(0);
}
fwrite(STDERR, 'FAILED: ' . $r['error'] . "\n");
exit(1);
