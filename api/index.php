<?php

declare(strict_types=1);
/**
 * Single endpoint:  api/index.php?action=<name>
 * Receives, sanitises, validates and stores everything in MySQL. Every query is a prepared statement.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/helpers.php';

$action = (string)($_GET['action'] ?? '');
// rarely used code is loaded only by the actions that need it
if (in_array($action, ['template', 'import_file'], true)) {
    require __DIR__ . '/xlsx.php';
}
if (in_array($action, ['session', 'login', 'stats', 'backups', 'backup_run', 'backup_download'], true)) {
    require __DIR__ . '/backup_lib.php';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
}
const READ_ONLY = ['session', 'logo', 'photo', 'students', 'student_get', 'stats', 'report', 'report_csv', 'export_csv', 'streams', 'settings',
    'promotions', 'users', 'audit', 'backups', 'backup_download', 'template', 'names_csv'];
if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($action, READ_ONLY, true)) {
    release_session();
}   // do not hold the session lock while reading

const ANY_STUDENT_READ = ['students.view', 'cards.print', 'students.write', 'students.promote', 'photos.upload'];

switch ($action) {

    /* ---------------- public: school logo ---------------- */
    case 'logo':
        $f = (string)($_GET['f'] ?? '');
        $p = APP_ROOT . '/uploads/logo/' . $f;
        if (!preg_match('/^[A-Za-z0-9_-]+\.(png|jpg)$/', $f) || !is_file($p)) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . (str_ends_with($f, '.png') ? 'image/png' : 'image/jpeg'));
        header('Cache-Control: public, max-age=86400');
        readfile($p);
        exit;

        /* ---------------- signed-in only: a student's photo, looked up by STUDENT id and school ---------------- */
    case 'photo':
        need(['students.view', 'cards.print']);
        $q = db()->prepare('SELECT photo FROM students WHERE id=? AND school_id=?');
        $q->execute([(int)($_GET['id'] ?? 0), sid()]);
        $rel = (string)$q->fetchColumn();
        $p = APP_ROOT . '/' . $rel;
        if (!preg_match(UPLOAD_RX, $rel) || !is_file($p)) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . (str_ends_with($rel, '.png') ? 'image/png' : 'image/jpeg'));
        header('Cache-Control: private, max-age=31536000, immutable');     // the address changes when the photo changes
        header('Content-Length: ' . filesize($p));
        readfile($p);
        exit;

        /* ---------------- session ---------------- */
    case 'session':
        out(payload(!empty($_GET['dash'])));

        // no break
    case 'login':
        $in = json_body();
        $name = clean($in['username'] ?? '', 30);
        $pass = is_string($in['password'] ?? null) ? $in['password'] : '';
        if (($s = login_lock_seconds($name)) > 0) {
            fail(lock_msg($s), 429, ['retry_after' => $s]);
        }
        $q = db()->prepare('SELECT * FROM users WHERE username=?');
        $q->execute([$name]);
        $u = $q->fetch() ?: null;
        if ($u && $u['locked_until'] && ($s = strtotime((string)$u['locked_until']) - time()) > 0) {
            fail(lock_msg($s), 429, ['retry_after' => $s]);
        }
        $good = password_verify($pass, $u ? $u['password_hash'] : password_hash('no-such-user', PASSWORD_DEFAULT)) && $u !== null;
        if (!$good) {
            [$left, $locked] = register_failure($u, $name);
            usleep(400000);
            audit('login_failed', $name);
            if ($locked > 0) {
                audit('lockout', $name);
                fail('Too many wrong entries. Sign-in is blocked for ' . LOCK_MINUTES . ' minutes.', 429, ['retry_after' => $locked]);
            }
            fail('Wrong username or password. ' . $left . ' attempt(s) left before a ' . LOCK_MINUTES . '-minute lock.', 401, ['attempts_left' => $left]);
        }
        if (!(int)$u['active']) {
            fail('This account has been disabled. Ask your administrator.', 403);
        }
        clear_failures((int)$u['id'], $name);
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int)$u['id']];
        $_SESSION['last'] = time();
        me(true);
        audit('login', $u['username']);
        out(payload(!empty($in['dash'])));

        // no break
    case 'logout':
        if (me()) {
            audit('logout', me()['username']);
        }
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        out(['ok' => true, 'csrf' => $_SESSION['csrf']]);

        // no break
    case 'change_password':
        $u = me();
        if (!$u) {
            fail('Please sign in.', 401);
        }
        $in = json_body();
        $cur = (string)($in['current'] ?? '');
        $new = (string)($in['new'] ?? '');
        $q = db()->prepare('SELECT password_hash FROM users WHERE id=?');
        $q->execute([$u['id']]);
        if (!password_verify($cur, (string)$q->fetchColumn())) {
            fail('Your current password is wrong.', 422);
        }
        if ($new === $cur) {
            fail('Choose a password different from the current one.', 422);
        }
        if ($pw = password_problem($new, $u['username'])) {
            fail($pw, 422);
        }
        db()->prepare('UPDATE users SET password_hash=?, must_change=0, pw_changed_at=NOW() WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        audit('password', $u['username']);
        out(['ok' => true]);

        /* ---------------- classes and streams ---------------- */
        // no break
    case 'streams':
        need(ANY_STUDENT_READ);
        out(['ok' => true, 'streams' => streams_map(sid())]);

        // no break
    case 'streams_save':
        need('streams.manage');
        $S = sid();
        $in = json_body()['streams'] ?? [];
        $want = array_fill_keys(CLASSES, []);
        $er = [];
        foreach (CLASSES as $c) {
            $raw = $in[$c] ?? '';
            $list = is_array($raw) ? $raw : preg_split('/[,;\n]+/', (string)$raw);
            $seen = [];
            foreach ($list as $n) {
                $n = clean($n, 15);
                if ($n === '') {
                    continue;
                }
                if (!preg_match(RX_STREAM, $n)) {
                    $er[] = "$c: stream \"$n\" may contain only letters and numbers.";
                    continue;
                }
                if (isset($seen[strtolower($n)])) {
                    continue;
                }
                $seen[strtolower($n)] = true;
                $want[$c][] = $n;
            }
        }
        $pdo = db();
        $have = streams_map($S);
        foreach (CLASSES as $c) {
            $keep = array_map('strtolower', $want[$c]);
            foreach ($have[$c] as $old) {
                if (in_array(strtolower($old), $keep, true)) {
                    continue;
                }
                $q = $pdo->prepare('SELECT COUNT(*) FROM students WHERE school_id=? AND class_name=? AND stream=?');
                $q->execute([$S, $c, $old]);
                if ((int)$q->fetchColumn() > 0) {
                    $er[] = "$c $old cannot be removed: students are registered in it. Move or delete them first.";
                }
            }
        }
        if ($er) {
            fail('Streams not saved.', 422, ['errors' => array_map(fn ($m) => ['f' => '', 'm' => $m], $er)]);
        }
        $pdo->beginTransaction();
        foreach (CLASSES as $c) {
            $keep = array_map('strtolower', $want[$c]);
            foreach ($have[$c] as $old) {
                if (!in_array(strtolower($old), $keep, true)) {
                    $pdo->prepare('DELETE FROM class_streams WHERE school_id=? AND class_name=? AND stream_name=?')->execute([$S, $c, $old]);
                }
            }
            foreach ($want[$c] as $n) {
                $pdo->prepare('INSERT IGNORE INTO class_streams (school_id,class_name,stream_name) VALUES (?,?,?)')->execute([$S, $c, $n]);
            }
        }
        $pdo->commit();
        audit('streams', 'class streams updated');
        out(['ok' => true, 'streams' => streams_map($S)]);

        /* ---------------- students (paged + searched on the server) ---------------- */
        // no break
    case 'students':
        need(['students.view', 'cards.print']);
        $r = students_page($_GET);
        out(['ok' => true] + $r);

        // no break
    case 'student_get':
        need(['students.view', 'cards.print', 'students.write']);
        $s = student_one((int)($_GET['id'] ?? 0));
        if (!$s) {
            fail('Student not found.', 404);
        }
        out(['ok' => true, 'student' => $s]);

        // no break
    case 'stats':
        need('students.view');
        out(['ok' => true] + dashboard_stats());

        // no break
    case 'student_save':
        $u = need('students.write');
        $S = sid();
        if (!school_is_ready()) {
            fail('The school has not been set up yet. Open the Dashboard and enter the school information first.', 409, ['setup_needed' => true]);
        }
        $in = $_POST;
        $id = (isset($in['student_id']) && ctype_digit((string)$in['student_id'])) ? (int)$in['student_id'] : null;
        [$d, $er] = validate_student($in, $id);
        $file = $_FILES['photo'] ?? null;
        $hasPhoto = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (!$er && $hasPhoto) {
            if (!can_do('photos.upload')) {
                $er[] = ['f' => 'photo', 'm' => 'You do not have permission to upload photos.'];
            } elseif ($e = check_image($file)[0]) {
                $er[] = ['f' => 'photo', 'm' => $e];
            }
        }
        if ($er) {
            fail('Not saved. Please correct the highlighted fields.', 422, ['errors' => $er]);
        }

        $pdo = db();
        try {
            if ($id === null) {
                [$newId, $adm] = insert_student($d);
                $perr = null;
                if ($hasPhoto) {
                    try {
                        attach_photo($newId, $file);
                    } catch (RuntimeException $e) {
                        $perr = $e->getMessage();
                    }
                }
                audit('student_add', $adm);
                out(['ok' => true, 'id' => $newId, 'adm' => $adm, 'photo_error' => $perr]);
            }
            $rev = (int)($in['rev'] ?? 0);
            $pdo->beginTransaction();
            $q = $pdo->prepare('SELECT guardian_id, admission_no, rev FROM students WHERE id=? AND school_id=? FOR UPDATE');
            $q->execute([$id, $S]);
            $old = $q->fetch();
            if (!$old) {
                $pdo->rollBack();
                fail('Student not found.', 404);
            }
            if ($rev !== (int)$old['rev']) {
                $pdo->rollBack();
                fail('Someone else changed this student while you were editing. Close this form and open the student again to see their latest details.', 409);
            }
            $pdo->prepare('UPDATE guardians SET first_name=?,last_name=?,phone=?,address=?,relationship=? WHERE id=? AND school_id=?')
                ->execute([$d['pf'], $d['pl'], $d['pc'], $d['pa'], $d['rel'], $old['guardian_id'], $S]);
            $pdo->prepare('UPDATE students SET first_name=?,last_name=?,class_name=?,stream=?,origin=?,rev=rev+1 WHERE id=? AND school_id=?')   // admission number is never changed
                ->execute([$d['first'], $d['last'], $d['cls'], $d['stream'], $d['origin'], $id, $S]);
            $pdo->prepare("UPDATE visitation_cards SET status='unprinted' WHERE student_id=?")->execute([$id]);   // details changed: print again
            $pdo->commit();
            $perr = null;
            if ($hasPhoto) {
                try {
                    attach_photo($id, $file);
                } catch (RuntimeException $e) {
                    $perr = $e->getMessage();
                }
            }
            audit('student_edit', $old['admission_no']);
            out(['ok' => true, 'id' => $id, 'adm' => $old['admission_no'], 'photo_error' => $perr]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23000') {
                fail('Not saved.', 422, ['errors' => [['f' => 'adm', 'm' => 'That admission number is already in use.']]]);
            }
            throw $e;
        }

    case 'student_photo':
        need('photos.upload');
        $id = (int)($_POST['student_id'] ?? 0);
        $file = $_FILES['photo'] ?? null;
        if (!$file) {
            fail('Choose a photo first.', 422);
        }
        if ($e = check_image($file)[0]) {
            fail($e, 422);
        }
        try {
            $url = attach_photo($id, $file);
        } catch (RuntimeException $e) {
            fail($e->getMessage(), $e->getMessage() === 'Student not found.' ? 404 : 422);
        }
        audit('photo', 'student ' . $id);
        out(['ok' => true, 'photo' => $url]);

        /* Bulk photos: the browser sends ONE file per request. The file is named after the admission number (for example ABC_0001.jpg)
           or after the student (Anna Okello.jpg). Nothing is saved unless exactly one student matches. */
    case 'photos_bulk':
        need('photos.upload');
        $f = $_FILES['file'] ?? null;
        $orig = clean($_POST['name'] ?? ($f['name'] ?? ''), 120);
        $replace = ($_POST['replace'] ?? '') === '1';
        $res = fn (string $st, string $msg, ?array $stu = null) => out(['ok' => true, 'status' => $st, 'file' => $orig, 'msg' => $msg, 'student' => $stu]);
        if (!$f) {
            $res('invalid', 'No file was received.');
        }
        $stem = pathinfo($orig, PATHINFO_FILENAME);
        $norm = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $stem) ?? '');
        if ($norm === '') {
            $res('no_match', 'The file name has no admission number or name.');
        }
        if ($e = check_image($f)[0]) {
            $res('invalid', $e);
        }
        $q = db()->prepare("SELECT id, admission_no, first_name, last_name, photo FROM students WHERE school_id=? AND UPPER(REPLACE(REPLACE(admission_no,'/',''),'-',''))=?");
        $q->execute([sid(), $norm]);
        $m = $q->fetchAll();
        $byName = false;
        if (!$m) {
            $m = students_by_name($stem);
            $byName = true;
        }
        if (!$m) {
            $res('no_match', 'No student matches this file name. Name the file after the admission number (' . str_replace('/', '_', adm_example()) . '.jpg) or after the student (Anna Okello.jpg).');
        }
        if (count($m) > 1) {
            $who = implode(', ', array_map(fn ($r) => $r['admission_no'] . (isset($r['class_name']) ? ' (' . $r['class_name'] . ' ' . $r['stream'] . ')' : ''), array_slice($m, 0, 4)));
            $res('ambiguous', $byName ? "More than one student has this name: $who. Rename the file to the admission number." : 'More than one student matches this file name; upload this photo from the student\'s own row.');
        }
        $s = $m[0];
        $stu = ['id' => (int)$s['id'], 'adm' => $s['admission_no'], 'name' => $s['first_name'] . ' ' . $s['last_name']];
        if ($s['photo'] && !$replace) {
            $res('skipped', 'This student already has a photo (tick "Replace existing photos" to overwrite).', $stu);
        }
        try {
            attach_photo((int)$s['id'], $f);
        } catch (RuntimeException $e) {
            $res('invalid', $e->getMessage(), $stu);
        }
        audit('photo', $s['admission_no']);
        $res('saved', $s['photo'] ? 'Photo replaced.' : 'Photo saved.', $stu);

        /* The list of names in a class or stream with their admission numbers, for naming photo files. */
    case 'names_csv':
        need(['students.view', 'photos.upload']);
        [, $args, $lite] = student_parts(['cls' => $_GET['cls'] ?? '', 'stream' => $_GET['stream'] ?? '']);
        $q = db()->prepare('SELECT s.admission_no, s.first_name, s.last_name, s.class_name, s.stream' . $lite . ' ORDER BY s.class_name, s.stream, s.last_name, s.first_name, s.id');
        $q->execute($args);
        $label = trim(preg_replace('/[^A-Za-z0-9]+/', '-', clean($_GET['cls'] ?? '', 3) . '-' . clean($_GET['stream'] ?? '', 15)) ?? '', '-');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="names-' . ($label !== '' ? $label : 'all') . '-' . date('Y-m-d') . '.csv"');
        $o = fopen('php://output', 'w');
        fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, ['Admission No', 'First name', 'Last name', 'Class', 'Stream', 'Photo file name']);
        while ($s = $q->fetch()) {
            fputcsv($o, [$s['admission_no'], $s['first_name'], $s['last_name'], $s['class_name'], $s['stream'], str_replace(['/', '\\'], '_', $s['admission_no']) . '.jpg']);
        }
        fclose($o);
        exit;

    case 'student_delete':
        need('students.delete');
        $S = sid();
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)(json_body()['ids'] ?? [])), fn ($i) => $i > 0)));
        if (!$ids) {
            fail('Select at least one student.', 422);
        }
        $pdo = db();
        $marks = implode(',', array_fill(0, count($ids), '?'));     // only "?" characters are ever concatenated into the SQL
        $q = $pdo->prepare("SELECT photo FROM students WHERE school_id=? AND id IN ($marks)");
        $q->execute(array_merge([$S], $ids));
        $photos = $q->fetchAll(PDO::FETCH_COLUMN);
        $pdo->beginTransaction();
        $del = $pdo->prepare("DELETE FROM students WHERE school_id=? AND id IN ($marks)");
        $del->execute(array_merge([$S], $ids));
        $pdo->prepare('DELETE g FROM guardians g LEFT JOIN students s ON s.guardian_id=g.id WHERE g.school_id=? AND s.id IS NULL')->execute([$S]);
        $pdo->commit();
        foreach ($photos as $p) {
            delete_upload($p);
        }
        audit('student_delete', $del->rowCount() . ' record(s)');
        out(['ok' => true, 'deleted' => $del->rowCount()]);

        /* ---------------- Excel template, upload (preview then import), export ---------------- */
        // no break
    case 'template':
        need('students.write');
        if (!xlsx_available()) {
            fail('Excel support needs the PHP zip extension (extension=zip in php.ini). CSV upload still works.', 501);
        }
        $map = streams_map(sid());
        $pairs = [['Class', 'Stream']];
        $names = [];
        foreach ($map as $c => $list) {
            foreach ($list as $s) {
                $pairs[] = [$c, $s];
                $names[$s] = true;
            }
        }
        $set = school_settings();
        $students = ['name' => 'Students', 'header' => true, 'textCols' => [0, 8], 'widths' => [16, 18, 18, 9, 14, 20, 22, 22, 20, 28, 15],
            'rows' => [['Admission No', 'First Name', 'Last Name', 'Class', 'Stream', 'Place of Origin', 'Guardian First Name', 'Guardian Last Name', 'Guardian Contact', 'Guardian Address', 'Relationship']],
            'validations' => [
                ['ref' => 'D2:D1001', 'formula' => '"S1,S2,S3,S4,S5,S6"'],
                ['ref' => 'K2:K1001', 'formula' => '"Father,Mother,Guardian,Other"'],
            ]];
        if (count($pairs) > 1) {
            $students['validations'][] = ['ref' => 'E2:E1001', 'formula' => 'Streams!$B$2:$B$' . max(2, count($pairs))];
        }
        $help = ['name' => 'Instructions', 'widths' => [110], 'titleRow' => 0, 'rows' => [
            [($set['name'] ?? 'School') . ' - student upload template'],
            ['Fill ONE student per row on the "Students" sheet, then upload this file under Register > Excel upload.'],
            [''],
            ['Rules (the same checks as manual entry):'],
            ['1. Admission No: LEAVE EMPTY. The system gives every new student the next admission number (for example ' . $set['code'] . '/0001).'],
            ['    Only fill it when you are moving records that already have admission numbers; it must then be unique.'],
            ['2. First/Last names and guardian names: letters only.'],
            ['3. Class: S1 to S6. Stream: must be one of the streams set up for that class (see the "Streams" sheet).'],
            ['4. Guardian Contact: digits only, 10 to 12 digits, for example 0772856765.'],
            ['5. Relationship: Father, Mother, Guardian or Other.'],
            ['6. Photos cannot be uploaded in Excel: use the Photos page afterwards (name each photo file after the admission number).'],
            [''],
            ['Example row:   | John | Okello | S1 | East | Sample Town | Ann | Okello | 0772856765 | Sample Address | Mother'],
        ]];
        $streamsSheet = ['name' => 'Streams', 'header' => true, 'widths' => [10, 18], 'rows' => $pairs];
        $bin = xlsx_build([$students, $help, $streamsSheet]);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="student-upload-template.xlsx"');
        header('Content-Length: ' . strlen($bin));
        echo $bin;
        exit;

    case 'import_file':
        need('students.write');
        if (!school_is_ready()) {
            fail('The school has not been set up yet. Open the Dashboard and enter the school information first.', 409, ['setup_needed' => true]);
        }
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            fail('Choose an Excel (.xlsx) or CSV file first.', 422);
        }
        if ($f['size'] > 5 * 1048576) {
            fail('The file is larger than 5 MB.', 422);
        }
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        try {
            if ($ext === 'xlsx') {
                if (!xlsx_available()) {
                    fail('Excel support needs the PHP zip extension (extension=zip in php.ini). Save the sheet as CSV instead.', 501);
                }
                $table = xlsx_read($f['tmp_name']);
            } elseif ($ext === 'csv') {
                $table = csv_read($f['tmp_name']);
            } else {
                fail('Only .xlsx and .csv files are accepted.', 422);
            }
            $recs = table_to_records($table);
        } catch (RuntimeException $e) {
            fail($e->getMessage(), 422);
        }
        set_time_limit(300);
        $commit = ($_POST['commit'] ?? '') === '1';
        $res = import_process($recs, $commit);
        if ($commit) {
            audit('import', $res['valid'] . ' imported, ' . $res['rejected'] . ' rejected');
        }
        out(['ok' => true, 'commit' => $commit] + $res);

    case 'export_csv':
        need('students.view');
        set_time_limit(300);
        [$from, $args] = student_parts($_GET);
        $pdo = db_connect(true, false);                       // unbuffered: even a very large school streams row by row
        $q = $pdo->prepare('SELECT ' . STUDENT_COLS . $from . student_order($_GET));
        $q->execute($args);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="students-' . date('Y-m-d') . '.csv"');
        $o = fopen('php://output', 'w');
        fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, ['admission', 'first', 'last', 'class', 'stream', 'origin', 'pfirst', 'plast', 'phone', 'address', 'relationship', 'cardno', 'printed', 'status']);
        while ($s = $q->fetch()) {
            $row = [$s['adm'], $s['first'], $s['last'], $s['cls'], $s['stream'], $s['origin'], $s['pf'], $s['pl'], $s['pc'], $s['pa'], $s['rel'], $s['cardNo'], $s['printed'] ? 'yes' : 'no', $s['status']];
            fputcsv($o, array_map(fn ($c) => preg_match('/^[=+\-@]/', (string)$c) ? "'" . $c : $c, $row));   // stop formula injection
        }
        fclose($o);
        exit;

    case 'mark_printed':
        $u = need('cards.print');
        $S = sid();
        $in = json_body();
        $act = ($in['action'] ?? '') === 'pdf' ? 'pdf' : 'print';
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($in['ids'] ?? [])), fn ($i) => $i > 0)));
        if (!$ids) {
            fail('Nothing to mark.', 422);
        }
        $pdo = db();
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $q = $pdo->prepare("SELECT id FROM visitation_cards WHERE school_id=? AND student_id IN ($marks)");
        $q->execute(array_merge([$S], $ids));
        $cards = $q->fetchAll(PDO::FETCH_COLUMN);
        $pdo->beginTransaction();
        $up = $pdo->prepare("UPDATE visitation_cards SET status='printed', print_count=print_count+1, last_printed_at=NOW() WHERE id=?");
        $hist = $pdo->prepare('INSERT INTO print_history (card_id,user_id,action) VALUES (?,?,?)');
        foreach ($cards as $cid) {
            $up->execute([$cid]);
            $hist->execute([$cid, $u['id'], $act]);
        }
        $pdo->commit();
        audit('card_' . $act, count($cards) . ' card(s)');
        out(['ok' => true, 'marked' => count($cards)]);

        /* ---------------- reports ---------------- */
        // no break
    case 'report':
        need('reports.view');
        out(['ok' => true] + report_page((string)($_GET['kind'] ?? ''), (int)($_GET['page'] ?? 1), max(10, min(200, (int)($_GET['size'] ?? 50)))));

        // no break
    case 'report_csv':
        need('reports.view');
        $r = report_page((string)($_GET['kind'] ?? ''), 1, 50, true);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="report-' . preg_replace('/[^a-z]/', '', (string)$_GET['kind']) . '-' . date('Y-m-d') . '.csv"');
        $o = fopen('php://output', 'w');
        fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, $r['head']);
        foreach ($r['rows'] as $row) {
            fputcsv($o, array_map(fn ($c) => preg_match('/^[=+\-@]/', (string)$c) ? "'" . $c : $c, $row));
        }
        fclose($o);
        exit;

        /* ---------------- promotion ---------------- */
    case 'promote_preview':
        need('students.promote');
        try {
            $r = promotion_moves(json_body());
        } catch (RuntimeException $e) {
            fail($e->getMessage(), 422);
        }
        $groups = [];
        foreach ($r['moves'] as $m) {
            $k = $m['from_cls'] . ' ' . $m['from_stream'] . ' > ' . ($m['to_status'] === 'active' ? $m['to_cls'] . ' ' . $m['to_stream'] : strtoupper($m['to_status']));
            $groups[$k] = ($groups[$k] ?? 0) + 1;
        }
        $in = json_body();
        $to = (string)($in['to'] ?? '');
        $occupied = 0;
        if (in_array($to, CLASSES, true)) {
            $q = db()->prepare("SELECT COUNT(*) FROM students WHERE school_id=? AND class_name=? AND status='active'");
            $q->execute([sid(), $to]);
            $occupied = (int)$q->fetchColumn();
        }
        out(['ok' => true, 'count' => count($r['moves']), 'groups' => $groups, 'problems' => $r['problems'], 'target_occupied' => $occupied, 'sample' => array_slice($r['moves'], 0, 8)]);

    case 'promote':
        need('students.promote');
        $in = json_body();
        try {
            [$pid, $n] = promotion_run([$in], 'Class ' . clean($in['from_cls'] ?? '', 3) . ' > ' . clean($in['to'] ?? '', 10));
        } catch (RuntimeException $e) {
            fail($e->getMessage(), 422);
        }
        audit('promote', "$n student(s), batch $pid");
        out(['ok' => true, 'moved' => $n, 'batch' => $pid]);

    case 'promote_all':     // whole school: S6 leave, S5 > S6, ... S1 > S2, all or nothing
        need('students.promote');
        $in = json_body();
        $steps = [];
        $steps[] = ['from_cls' => 'S6', 'to' => ($in['s6'] ?? 'graduate') === 'left' ? 'left' : 'graduate'];
        for ($i = 4; $i >= 0; $i--) {
            $steps[] = ['from_cls' => CLASSES[$i], 'to' => CLASSES[$i + 1]];
        }
        if (($in['preview'] ?? false)) {
            $res = [];
            $problems = [];
            foreach ($steps as $st) {
                $r = promotion_moves($st);
                $res[] = ['from' => $st['from_cls'], 'to' => $st['to'], 'count' => count($r['moves'])];
                $problems = array_merge($problems, $r['problems']);
            }
            out(['ok' => true, 'steps' => $res, 'problems' => array_values(array_unique($problems))]);
        }
        try {
            [$pid, $n] = promotion_run($steps, 'Whole school promotion');
        } catch (RuntimeException $e) {
            fail($e->getMessage(), 422);
        }
        audit('promote_all', "$n student(s), batch $pid");
        out(['ok' => true, 'moved' => $n, 'batch' => $pid]);

    case 'promote_undo':
        need('students.promote');
        try {
            $r = promotion_undo((int)(json_body()['id'] ?? 0));
        } catch (RuntimeException $e) {
            fail($e->getMessage(), 422);
        }
        audit('promote_undo', 'batch ' . (int)json_body()['id'] . ': ' . $r['restored'] . ' restored');
        out(['ok' => true] + $r);

    case 'promotions':
        need('students.promote');
        $q = db()->prepare('SELECT p.id, p.label, p.moved, p.undone, p.created_at, u.username FROM promotions p LEFT JOIN users u ON u.id=p.user_id WHERE p.school_id=? ORDER BY p.id DESC LIMIT 20');
        $q->execute([sid()]);
        out(['ok' => true, 'batches' => array_map(fn ($r) => ['id' => (int)$r['id'], 'label' => $r['label'], 'moved' => (int)$r['moved'], 'undone' => (bool)(int)$r['undone'], 'at' => $r['created_at'], 'by' => $r['username']], $q->fetchAll())]);

        /* ---------------- school / card design ---------------- */
        // no break
    case 'settings':
        need(ANY_STUDENT_READ);
        out(['ok' => true, 'settings' => school_settings()]);

        // no break
    case 'school_setup':         // first run: the administrator describes the school (name, initials, card details)
        need('design.manage');
        $S = sid();
        $cur = school_row($S);
        if ((int)$cur['setup_done'] === 1) {
            fail('The school is already set up. Change its details under Card design.', 409);
        }
        $in = $_POST;
        $v = [
            'name' => clean($in['name'] ?? '', 120), 'motto' => clean($in['motto'] ?? '', 120), 'address' => clean($in['address'] ?? '', 200),
            'contact' => clean($in['contact'] ?? '', 120), 'signer' => clean($in['signer'] ?? '', 60) ?: 'Headteacher',
            'color' => strtolower(clean($in['color'] ?? '', 7)) ?: '#14213d',
        ];
        $code = strtoupper(clean($in['code'] ?? '', 8));
        if ($code === '') {
            $code = initials_from_name($v['name']);
        }
        $er = [];
        if (mb_strlen($v['name']) < 3) {
            $er[] = ['f' => 'name', 'm' => 'Enter the full name of the school (at least 3 characters).'];
        }
        if (!preg_match('/^[A-Z]{2,6}$/', $code)) {
            $er[] = ['f' => 'code', 'm' => 'School initials: 2 to 6 capital letters, for example ABC. They start every admission number.'];
        }
        if (!preg_match('/^[0-9+\/\s-]*$/', $v['contact'])) {
            $er[] = ['f' => 'contact', 'm' => 'Contact may contain only digits, spaces, "+", "/" and "-".'];
        }
        if (!preg_match('/^#[0-9a-f]{6}$/', $v['color'])) {
            $er[] = ['f' => 'color', 'm' => 'Choose a valid colour.'];
        }
        $logo = null;
        $file = $_FILES['logo'] ?? null;
        if (!$er && $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $logo = save_image($file, 'uploads/logo', 600, 'png');
            } catch (RuntimeException $e) {
                $er[] = ['f' => 'logo', 'm' => $e->getMessage()];
            }
        }
        if ($er) {
            fail('The school information was not saved. Please correct the highlighted fields.', 422, ['errors' => $er]);
        }
        db()->prepare('UPDATE schools SET name=?,code=?,motto=?,address=?,contact=?,signer=?,color=?,watermark=?,logo=COALESCE(?,logo),setup_done=1 WHERE id=? AND setup_done=0')
            ->execute([$v['name'], $code, $v['motto'], $v['address'], $v['contact'], $v['signer'], $v['color'], $code, $logo, $S]);
        audit('school_setup', $v['name'] . ' (' . $code . ')');
        out(['ok' => true, 'settings' => school_settings()]);

        // no break
    case 'settings_save':
        need('design.manage');
        $S = sid();
        if (!school_is_ready()) {
            fail('Set up the school on the Dashboard first.', 409, ['setup_needed' => true]);
        }
        $in = $_POST;
        $v = [
            'name' => clean($in['name'] ?? '', 120), 'motto' => clean($in['motto'] ?? '', 120), 'address' => clean($in['address'] ?? '', 200),
            'contact' => clean($in['contact'] ?? '', 120), 'verify_url' => rtrim(clean($in['verify_url'] ?? '', 200), '/'),
            'color' => strtolower(clean($in['color'] ?? '', 7)), 'font' => clean($in['font'] ?? '', 40),
            'card_w' => (int)($in['card_w'] ?? 0), 'card_h' => (int)($in['card_h'] ?? 0),
            'watermark' => clean($in['watermark'] ?? '', 40), 'signer' => clean($in['signer'] ?? '', 60),
        ];
        $er = [];
        $cur = school_row($S);
        $code = isset($in['code']) ? strtoupper(clean($in['code'], 8)) : $cur['code'];
        if ($code !== $cur['code']) {          // the initials are inside every admission number, so they are fixed once students exist
            if (school_has_students($S)) {
                $er[] = ['f' => 'code', 'm' => 'The school initials cannot change after students have been registered: they are part of every admission number.'];
            } elseif (!preg_match('/^[A-Z]{2,6}$/', $code)) {
                $er[] = ['f' => 'code', 'm' => 'School initials: 2 to 6 capital letters, for example ABC.'];
            }
        }
        if ($v['name'] === '') {
            $er[] = ['f' => 'name', 'm' => 'School name is required.'];
        }
        if (!preg_match('/^[0-9+\/\s-]*$/', $v['contact'])) {
            $er[] = ['f' => 'contact', 'm' => 'Contact may contain only digits, spaces, "+", "/" and "-".'];
        }
        if ($v['verify_url'] !== '' && !preg_match('#^https?://[A-Za-z0-9.\-]+(:\d{2,5})?(/[A-Za-z0-9._~\-/]*)?$#', $v['verify_url'])) {
            $er[] = ['f' => 'verify_url', 'm' => 'The verification address must look like http://192.168.1.10/vcms'];
        }
        if (!preg_match('/^#[0-9a-f]{6}$/', $v['color'])) {
            $er[] = ['f' => 'color', 'm' => 'Choose a valid colour.'];
        }
        if (!in_array($v['font'], ['Arial', 'Georgia', 'Verdana', 'Trebuchet MS', 'Times New Roman'], true)) {
            $er[] = ['f' => 'font', 'm' => 'Choose a listed font.'];
        }
        if ($v['card_w'] < 100 || $v['card_w'] > 200) {
            $er[] = ['f' => 'card_w', 'm' => 'Card width must be 100 to 200 mm (150 recommended).'];
        }
        if ($v['card_h'] < 50 || $v['card_h'] > 100) {
            $er[] = ['f' => 'card_h', 'm' => 'Card height must be 50 to 100 mm (64 recommended: four fit on one A4 page).'];
        }
        if (!$er && $v['card_w'] < $v['card_h']) {
            $er[] = ['f' => 'card_w', 'm' => 'The card must be wider than it is tall.'];
        }
        $logo = null;
        $file = $_FILES['logo'] ?? null;
        if (!$er && $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $logo = save_image($file, 'uploads/logo', 600, 'png');
            } catch (RuntimeException $e) {
                $er[] = ['f' => 'logo', 'm' => $e->getMessage()];
            }
        }
        if ($er) {
            fail('Settings not saved. Please correct the highlighted fields.', 422, ['errors' => $er]);
        }
        $oldLogo = (string)school_row($S)['logo'];
        db()->prepare('UPDATE schools SET code=?,name=?,motto=?,address=?,contact=?,verify_url=?,color=?,font=?,card_w=?,card_h=?,watermark=?,signer=?,logo=COALESCE(?,logo) WHERE id=?')
            ->execute([$code, $v['name'], $v['motto'], $v['address'], $v['contact'], $v['verify_url'], $v['color'], $v['font'], $v['card_w'], $v['card_h'], $v['watermark'], $v['signer'], $logo, $S]);
        if ($logo) {
            delete_upload($oldLogo);
        }
        audit('settings', $v['name']);
        out(['ok' => true, 'settings' => school_settings()]);

        /* ---------------- users, permissions, passwords ---------------- */
        // no break
    case 'users':
        need('users.manage');
        $q = db()->prepare('SELECT id,username,full_name,role,perms,active,must_change,(locked_until IS NOT NULL AND locked_until>NOW()) locked,created_at FROM users WHERE school_id=? ORDER BY username');
        $q->execute([sid()]);
        $rows = array_map(function ($u) {
            $u['id'] = (int)$u['id'];
            $u['active'] = (bool)(int)$u['active'];
            $u['must_change'] = (bool)(int)$u['must_change'];
            $u['locked'] = (bool)(int)$u['locked'];
            $u['custom'] = $u['perms'] !== null && $u['perms'] !== '';
            $u['perm_list'] = effective_perms($u);
            unset($u['perms']);
            return $u;
        }, $q->fetchAll());
        $me = me();
        $grantable = $me['role'] === 'system_admin' ? array_keys(PERM_CATALOG) : $me['perm_list'];
        out(['ok' => true, 'users' => $rows, 'catalog' => PERM_CATALOG, 'grantable' => $grantable,
            'defaults' => ['system_admin' => role_defaults('system_admin'), 'school_admin' => role_defaults('school_admin'), 'data_entry' => role_defaults('data_entry'), 'printing' => role_defaults('printing')],
            'roles' => ROLE_NAMES]);

        // no break
    case 'user_save':
        $me = need('users.manage');
        $S = sid();
        $pdo = db();
        $in = json_body();
        $id = (int)($in['id'] ?? 0);
        $un = clean($in['username'] ?? '', 30);
        $fn = clean($in['full_name'] ?? '', 80);
        $role = (string)($in['role'] ?? '');
        $custom = isset($in['perms']) && is_array($in['perms']) ? array_values(array_unique(array_map('strval', $in['perms']))) : null;
        $er = [];
        if (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $un)) {
            $er[] = ['f' => 'username', 'm' => 'Username: 3 to 30 letters, numbers, "_", "." or "-".'];
        }
        if (!preg_match(RX_PLACE, $fn)) {
            $er[] = ['f' => 'full_name', 'm' => 'Full name must contain letters only.'];
        }
        if (!isset(ROLE_NAMES[$role])) {
            $er[] = ['f' => 'role', 'm' => 'Choose a role.'];
        }
        if ($role === 'system_admin' && $me['role'] !== 'system_admin') {
            $er[] = ['f' => 'role', 'm' => 'Only a system administrator can create system administrators.'];
        }
        if ($custom !== null) {
            $bad = array_diff($custom, array_keys(PERM_CATALOG));
            if ($bad) {
                $er[] = ['f' => 'perms', 'm' => 'Unknown permission.'];
            }
            $allowed = $me['role'] === 'system_admin' ? array_keys(PERM_CATALOG) : $me['perm_list'];
            $over = array_diff($custom, $allowed);
            if ($over) {
                $er[] = ['f' => 'perms', 'm' => 'You cannot give permissions you do not have yourself.'];
            }
        }
        $newPw = null;
        if ($id === 0) {
            $pw = (string)($in['password'] ?? '');
            if ($pw !== '' && ($pp = password_problem($pw, $un))) {
                $er[] = ['f' => 'password', 'm' => $pp];
            }
            $newPw = $pw !== '' ? $pw : temp_password();
        }
        if ($er) {
            fail('User not saved.', 422, ['errors' => $er]);
        }
        if ($custom !== null && !array_diff(role_defaults($role), $custom) && !array_diff($custom, role_defaults($role))) {
            $custom = null;
        }      // same as the role: no override
        $permJson = $custom === null ? null : json_encode($custom);
        try {
            if ($id === 0) {
                $pdo->prepare('INSERT INTO users (school_id,username,full_name,password_hash,role,perms,must_change) VALUES (?,?,?,?,?,?,1)')
                    ->execute([$S, $un, $fn, password_hash($newPw, PASSWORD_DEFAULT), $role, $permJson]);
                audit('user_add', $un);
                out(['ok' => true, 'temp_password' => $newPw, 'username' => $un]);
            }
            $q = $pdo->prepare('SELECT * FROM users WHERE id=? AND school_id=?');
            $q->execute([$id, $S]);
            $cur = $q->fetch();
            if (!$cur) {
                fail('User not found.', 404);
            }
            if ($cur['role'] === 'system_admin' && $me['role'] !== 'system_admin') {
                fail('Only a system administrator can change a system administrator.', 403);
            }
            if ($id === $me['id']) {
                $role = $cur['role'];
                $permJson = $cur['perms'];
            }           // nobody changes their own role or permissions
            elseif (!users_keep_an_admin($S, $id, $role, $permJson)) {
                fail('That would leave the school without anyone who can manage users. Give someone else that permission first.', 422);
            }
            $pdo->prepare('UPDATE users SET username=?, full_name=?, role=?, perms=? WHERE id=? AND school_id=?')->execute([$un, $fn, $role, $permJson, $id, $S]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                fail('User not saved.', 422, ['errors' => [['f' => 'username', 'm' => 'That username is already taken.']]]);
            }
            throw $e;
        }
        audit('user_save', $un);
        out(['ok' => true]);

    case 'user_reset':        // administrator sets a one-time password; the person must change it at next sign-in
        $me = need('users.manage');
        $S = sid();
        $in = json_body();
        $id = (int)($in['id'] ?? 0);
        $q = db()->prepare('SELECT username, role FROM users WHERE id=? AND school_id=?');
        $q->execute([$id, $S]);
        $t = $q->fetch();
        if (!$t) {
            fail('User not found.', 404);
        }
        if ($t['role'] === 'system_admin' && $me['role'] !== 'system_admin') {
            fail('Only a system administrator can reset a system administrator\'s password.', 403);
        }
        $pw = temp_password();
        db()->prepare('UPDATE users SET password_hash=?, must_change=1, failed_logins=0, locked_until=NULL WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
        db()->prepare('DELETE FROM login_attempts WHERE ip LIKE ?')->execute(['%|' . strtolower($t['username'])]);
        audit('password_reset', $t['username']);
        out(['ok' => true, 'temp_password' => $pw, 'username' => $t['username']]);

        // no break
    case 'user_unlock':
        need('users.manage');
        $q = db()->prepare('SELECT username FROM users WHERE id=? AND school_id=?');
        $q->execute([(int)(json_body()['id'] ?? 0), sid()]);
        $name = $q->fetchColumn();
        if (!$name) {
            fail('User not found.', 404);
        }
        db()->prepare('UPDATE users SET failed_logins=0, locked_until=NULL WHERE username=?')->execute([$name]);
        db()->prepare('DELETE FROM login_attempts WHERE ip LIKE ?')->execute(['%|' . strtolower((string)$name)]);
        audit('user_unlock', (string)$name);
        out(['ok' => true]);

        // no break
    case 'user_toggle':
        $me = need('users.manage');
        $S = sid();
        $in = json_body();
        $id = (int)($in['id'] ?? 0);
        $on = !empty($in['active']);
        $q = db()->prepare('SELECT * FROM users WHERE id=? AND school_id=?');
        $q->execute([$id, $S]);
        $t = $q->fetch();
        if (!$t) {
            fail('User not found.', 404);
        }
        if ($id === $me['id']) {
            fail('You cannot disable your own account.', 422);
        }
        if ($t['role'] === 'system_admin' && $me['role'] !== 'system_admin') {
            fail('Only a system administrator can disable a system administrator.', 403);
        }
        if (!$on && !users_keep_an_admin($S, $id, null, null, true)) {
            fail('That would leave the school without anyone who can manage users.', 422);
        }
        db()->prepare('UPDATE users SET active=? WHERE id=?')->execute([$on ? 1 : 0, $id]);
        audit($on ? 'user_enable' : 'user_disable', $t['username']);
        out(['ok' => true]);

        // no break
    case 'user_delete':
        $me = need('users.manage');
        $S = sid();
        $id = (int)(json_body()['id'] ?? 0);
        if ($id === $me['id']) {
            fail('You cannot delete your own account.', 422);
        }
        $q = db()->prepare('SELECT role,username FROM users WHERE id=? AND school_id=?');
        $q->execute([$id, $S]);
        $u = $q->fetch();
        if (!$u) {
            fail('User not found.', 404);
        }
        if ($u['role'] === 'system_admin' && $me['role'] !== 'system_admin') {
            fail('Only a system administrator can delete a system administrator.', 403);
        }
        if (!users_keep_an_admin($S, $id, null, null, true)) {
            fail('That would leave the school without anyone who can manage users.', 422);
        }
        db()->prepare('DELETE FROM users WHERE id=? AND school_id=?')->execute([$id, $S]);
        audit('user_delete', $u['username']);
        out(['ok' => true]);

        // no break
    case 'audit':
        need('users.manage');
        $q = db()->prepare('SELECT a.created_at, a.action, a.detail, u.username FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE a.school_id=? ORDER BY a.id DESC LIMIT 100');
        $q->execute([sid()]);
        out(['ok' => true, 'log' => $q->fetchAll()]);

        /* ---------------- backups ---------------- */
        // no break
    case 'backups':
        need('backup.manage');
        $st = bk_status();
        $list = bk_list();
        out(['ok' => true, 'status' => $st, 'files' => array_slice($list, 0, 60), 'dir' => BACKUP_DIR, 'copy_dir' => BACKUP_COPY_DIR, 'keep_last' => BACKUP_KEEP_LAST,
            'keep_months' => BACKUP_KEEP_MONTHS, 'zip' => class_exists('ZipArchive'), 'warn_hours' => BACKUP_WARN_HOURS]);

        // no break
    case 'backup_run':
        need('backup.manage');
        set_time_limit(900);
        ignore_user_abort(true);
        release_session();
        $r = kvc_backup('backup');
        if ($r['ok']) {
            out(['ok' => true] + $r);
        }
        fail($r['error'] ?? 'Backup failed.', 500, ['warnings' => $r['warnings'] ?? []]);

        // no break
    case 'backup_download':
        need('backup.manage');
        $f = (string)($_GET['f'] ?? '');
        $p = BACKUP_DIR . '/' . $f;
        if (!preg_match(BK_PATTERN, $f) || !is_file($p)) {
            http_response_code(404);
            exit;
        }
        audit('backup_download', $f);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $f . '"');
        header('Content-Length: ' . filesize($p));
        readfile($p);
        exit;

    default:
        fail('Unknown action.', 404);
}

/** True if the school will still have at least one active person who can manage users after this change. */
function users_keep_an_admin(int $S, int $changingId, ?string $newRole, ?string $newPermsJson, bool $removing = false): bool
{
    $q = db()->prepare('SELECT id,role,perms,active FROM users WHERE school_id=? AND active=1');
    $q->execute([$S]);
    foreach ($q->fetchAll() as $u) {
        if ((int)$u['id'] === $changingId) {
            if ($removing) {
                continue;
            }
            $u['role'] = $newRole ?? $u['role'];
            $u['perms'] = $newRole !== null ? $newPermsJson : $u['perms'];
        }
        if (in_array('users.manage', effective_perms($u), true)) {
            return true;
        }
    }
    return false;
}
