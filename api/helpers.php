<?php

declare(strict_types=1);
/** Business logic used by api/index.php: settings, paged student queries, admission numbers, photos, import, promotion, reports. */

/* ---------- dashboard numbers ---------- */
function dashboard_stats(): array
{
    $S = sid();
    $pdo = db();
    $q = $pdo->prepare("SELECT COUNT(*) total, COALESCE(SUM(c.status='printed'),0) printed, COALESCE(SUM(s.photo IS NULL),0) nophoto
        FROM students s LEFT JOIN visitation_cards c ON c.student_id=s.id WHERE s.school_id=? AND s.status='active'");
    $q->execute([$S]);
    $k = array_map('intval', $q->fetch());
    $q = $pdo->prepare("SELECT class_name cls, stream, COUNT(*) n FROM students WHERE school_id=? AND status='active' GROUP BY class_name, stream ORDER BY class_name, stream");
    $q->execute([$S]);
    $bk = null;
    if (can_do('backup.manage')) {
        $st = bk_status();
        $age = isset($st['time']) && ($st['ok'] ?? false) ? (time() - strtotime((string)$st['time'])) / 3600 : null;
        $bk = ['ok' => ($st['ok'] ?? false) && $age !== null && $age <= BACKUP_WARN_HOURS, 'time' => $st['time'] ?? null,
            'age_hours' => $age === null ? null : round($age, 1), 'error' => $st['error'] ?? null, 'never' => !$st];
    }
    return ['total' => $k['total'], 'printed' => $k['printed'], 'nophoto' => $k['nophoto'], 'heat' => $q->fetchAll(),
        'recent' => students_page(['sort' => 'recent', 'size' => 8, 'nocount' => 1, 'thumbs' => 1])['students'], 'backup' => $bk];
}

/* ---------- school settings ---------- */
const DEFAULT_LOGO = 'assets/img/vcms-logo.png';
/** The school's own logo, or null while it still has the product emblem (the emblem is never printed on a school's cards). */
function school_logo_url(?string $rel): ?string
{
    return ($rel === null || $rel === '' || $rel === DEFAULT_LOGO) ? null : logo_url($rel);
}
function school_has_students(int $S): bool
{
    $q = db()->prepare('SELECT 1 FROM students WHERE school_id=? LIMIT 1');
    $q->execute([$S]);
    return (bool)$q->fetchColumn();
}
function school_is_ready(): bool
{
    return (int)school_row(sid())['setup_done'] === 1;
}
/** What the sign-in page may show before anybody is signed in: the product, and the school's name and logo once it has been set up. */
function brand_info(): array
{
    $b = ['product' => APP_NAME, 'version' => APP_VERSION, 'installed' => true, 'problem' => '', 'school' => '', 'logo' => null];
    try {
        $r = db()->query('SELECT s.name, s.logo, s.setup_done, (SELECT COUNT(*) FROM users) AS users FROM schools s ORDER BY s.id LIMIT 1')->fetch();
    } catch (Throwable $e) {
        // no database yet = the installer has not been run; any other failure means MySQL cannot be reached
        $missing = (int)$e->getCode() === 1049 || str_contains($e->getMessage(), 'Unknown database');
        return ['installed' => false, 'problem' => $missing ? 'tables' : 'db'] + $b;
    }
    if (!$r || (int)$r['users'] === 0) {
        return ['installed' => false, 'problem' => 'tables'] + $b;    // the installer has not been run yet
    }
    if ((int)$r['setup_done'] === 1) {
        $b['school'] = (string)$r['name'];
        $b['logo'] = school_logo_url($r['logo']);
    }
    return $b;
}
function school_settings(): array
{
    $S = sid();
    $s = school_row($S);
    $done = (int)$s['setup_done'] === 1;
    $base = verify_base($s);
    return [
        'id' => $S, 'setup_done' => $done, 'product' => APP_NAME, 'version' => APP_VERSION,
        'code' => $done ? $s['code'] : '', 'code_locked' => $done && school_has_students($S),
        'name' => $s['name'], 'motto' => $s['motto'], 'address' => $s['address'], 'contact' => $s['contact'],
        'color' => $s['color'], 'font' => $s['font'], 'card_w' => (int)$s['card_w'], 'card_h' => (int)$s['card_h'],
        'watermark' => $s['watermark'], 'signer' => $s['signer'], 'logo' => school_logo_url($s['logo']),
        'verify_url' => $s['verify_url'], 'verify_base' => $base, 'verify_local' => is_local_host((string)parse_url($base, PHP_URL_HOST)),
    ];
}
/** Example admission number for hints, using this school's own initials. */
function adm_example(): string
{
    $s = school_row(sid());
    return ((int)$s['setup_done'] === 1 ? $s['code'] : 'ABC') . '/0001';
}
function payload(bool $withDashboard = false): array
{
    $u = me();
    $p = ['ok' => true, 'csrf' => $_SESSION['csrf'], 'user' => null, 'settings' => null, 'streams' => null, 'brand' => brand_info()];
    if (!$u) {
        return $p;
    }
    $p['user'] = ['id' => $u['id'], 'username' => $u['username'], 'full_name' => $u['full_name'], 'role' => $u['role'], 'role_name' => ROLE_NAMES[$u['role']] ?? $u['role'],
        'perms' => $u['perm_list'], 'must_change' => $u['must_change'], 'school_id' => sid()];
    $p['settings'] = school_settings();
    $p['streams'] = streams_map(sid());
    if ($withDashboard && can_do('students.view')) {
        $p['dash'] = dashboard_stats();
    }   // saves the dashboard its own round trip
    return $p;
}
function lock_msg(int $sec): string
{
    return 'Too many wrong entries. Sign-in is blocked for ' . max(1, (int)ceil($sec / 60)) . ' more minute(s).';
}

/* ---------- students: paged, searchable, filtered on the server ---------- */
const STUDENT_COLS = 's.id, s.admission_no AS adm, s.first_name AS `first`, s.last_name AS `last`, s.class_name AS cls, s.stream, s.origin,
    s.registered_on AS reg, s.photo, s.status, s.rev, g.first_name AS pf, g.last_name AS pl, g.phone AS pc, g.address AS pa, g.relationship AS rel,
    c.card_no AS cardNo, COALESCE(c.print_count,0) AS prints, (c.status = \'printed\') AS printed';

/** @return array{0:string,1:array,2:string} FROM + WHERE text, its arguments, and a lighter FROM + WHERE that joins only the card, and only when the filters need it (for counting) */
function student_parts(array $g): array
{
    $args = [sid()];
    $w = ' WHERE s.school_id=?';
    $needCard = false;
    $st = (string)($g['status'] ?? 'active');
    if (in_array($st, ['active', 'graduated', 'left'], true)) {
        $w .= ' AND s.status=?';
        $args[] = $st;
    }
    $cls = strtoupper(clean($g['cls'] ?? '', 3));
    if (in_array($cls, CLASSES, true)) {
        $w .= ' AND s.class_name=?';
        $args[] = $cls;
    }
    $stream = clean($g['stream'] ?? '', 15);
    if ($stream !== '') {
        $w .= ' AND s.stream=?';
        $args[] = $stream;
    }
    $pr = (string)($g['printed'] ?? '');
    if ($pr === 'p') {
        $w .= " AND c.status='printed'";
        $needCard = true;
    } elseif ($pr === 'n') {
        $w .= " AND (c.status IS NULL OR c.status<>'printed')";
        $needCard = true;
    }
    $ph = (string)($g['photo'] ?? '');
    if ($ph === 'none') {
        $w .= ' AND s.photo IS NULL';
    } elseif ($ph === 'has') {
        $w .= ' AND s.photo IS NOT NULL';
    }
    $q = clean($g['q'] ?? '', 80);
    if ($q !== '') {
        foreach (array_slice(preg_split('/\s+/', $q) ?: [], 0, 4) as $tok) {           // every word must match somewhere
            $like = '%' . addcslashes($tok, '%_\\') . '%';
            // guardians and card numbers are searched in small sub-queries, which is about twice as fast as joining them to every student
            $w .= ' AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.admission_no LIKE ?
                OR s.guardian_id IN (SELECT id FROM guardians WHERE school_id=? AND (first_name LIKE ? OR last_name LIKE ? OR phone LIKE ?))
                OR s.id IN (SELECT student_id FROM visitation_cards WHERE school_id=? AND card_no LIKE ?))';
            array_push($args, $like, $like, $like, $args[0], $like, $like, $like, $args[0], $like);
        }
    }
    $light = ' FROM students s' . ($needCard ? ' LEFT JOIN visitation_cards c ON c.student_id=s.id' : '');
    return [' FROM students s JOIN guardians g ON g.id=s.guardian_id LEFT JOIN visitation_cards c ON c.student_id=s.id' . $w, $args, $light . $w];
}
function student_order(array $g): string
{
    return ($g['sort'] ?? '') === 'recent' ? ' ORDER BY s.id DESC' : ' ORDER BY s.class_name, s.stream, s.last_name, s.first_name, s.id';
}
function fmt_student(array $r, ?string $qrBase = null, ?array $school = null, bool $thumb = false): array
{
    $r['id'] = (int)$r['id'];
    $r['prints'] = (int)$r['prints'];
    $r['printed'] = (bool)$r['printed'];
    $r['rev'] = (int)$r['rev'];
    if ($thumb) {
        $r['thumb'] = thumb_data_uri($r['photo']);
    }
    $r['photo'] = photo_url($r['id'], $r['photo']);
    if ($qrBase !== null && $school !== null && $r['cardNo']) {
        $r['vurl'] = $qrBase . '/verify.php?c=' . rawurlencode((string)$r['cardNo']) . '&k=' . qr_sig($school, (string)$r['cardNo']);
    }
    return $r;
}
/** One page of students. $g = filters (q, cls, stream, printed, photo, status, sort, page, size, qr)
 *  and options: thumbs = include a small photo, nocount = skip counting the whole list (when only the first rows are wanted). */
function students_page(array $g): array
{
    $S = sid();
    [$from, $args, $countFrom] = student_parts($g);
    $pdo = db();
    $size = max(1, min(200, (int)($g['size'] ?? 25)));
    $page = max(1, (int)($g['page'] ?? 1));
    $school = !empty($g['qr']) ? school_row($S) : null;
    $base = $school ? verify_base($school) : null;
    $withThumb = !empty($g['thumbs']);

    $count = function () use ($pdo, $countFrom, $args): int {
        $c = $pdo->prepare('SELECT COUNT(*)' . $countFrom);
        $c->execute($args);
        return (int)$c->fetchColumn();
    };
    $fetch = function (int $pageNo) use ($pdo, $from, $args, $g, $size, $base, $school, $withThumb): array {
        $q = $pdo->prepare('SELECT ' . STUDENT_COLS . $from . student_order($g) . ' LIMIT ' . $size . ' OFFSET ' . (($pageNo - 1) * $size));
        $q->execute($args);
        return array_map(fn ($r) => fmt_student($r, $base, $school, $withThumb), $q->fetchAll());
    };

    if (!empty($g['nocount'])) {                       // only the first rows are wanted: no need to count the whole list
        $rows = $fetch(1);
        return ['students' => $rows, 'total' => count($rows), 'page' => 1, 'pages' => 1, 'size' => $size];
    }
    if ($page === 1) {
        $rows = $fetch(1);
        $total = count($rows) < $size ? count($rows) : $count();     // a short first page already tells the total
    } else {
        $total = $count();
        $page = min($page, max(1, (int)ceil($total / $size)));
        $rows = $fetch($page);
    }
    return ['students' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $size)), 'size' => $size];
}
function student_one(int $id, bool $qr = false): ?array
{
    $S = sid();
    $q = db()->prepare('SELECT ' . STUDENT_COLS . ' FROM students s JOIN guardians g ON g.id=s.guardian_id LEFT JOIN visitation_cards c ON c.student_id=s.id WHERE s.school_id=? AND s.id=?');
    $q->execute([$S, $id]);
    $r = $q->fetch();
    if (!$r) {
        return null;
    }
    $school = $qr ? school_row($S) : null;
    return fmt_student($r, $school ? verify_base($school) : null, $school);
}

/* ---------- admission numbers: assigned by the system, atomic, never reused ---------- */
/** Must be called INSIDE a transaction. Format: SCHOOLINITIALS/0001 */
function next_admission(int $S, array $school): string
{
    $pdo = db();
    for ($i = 0; $i < 200; $i++) {
        $pdo->prepare('INSERT INTO admission_seq (school_id,last_seq) VALUES (?,LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE last_seq=LAST_INSERT_ID(last_seq+1)')->execute([$S]);
        $n = (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
        $adm = sprintf('%s/%04d', $school['code'], $n);
        $e = $pdo->prepare('SELECT 1 FROM students WHERE school_id=? AND admission_no=?');
        $e->execute([$S, $adm]);
        if (!$e->fetch()) {
            return $adm;
        }                    // skips numbers already taken by imported legacy records
    }
    throw new RuntimeException('Could not allocate an admission number.');
}
/** Insert guardian + student + card in one transaction. Returns [student id, admission number]. */
function insert_student(array $d): array
{
    $pdo = db();
    $S = sid();
    $school = school_row($S);
    if ((int)$school['setup_done'] !== 1) {
        throw new RuntimeException('The school has not been set up yet.');   // admission numbers need the school initials
    }
    $pdo->beginTransaction();
    try {
        $adm = $d['adm'] !== '' ? $d['adm'] : next_admission($S, $school);
        $pdo->prepare('INSERT INTO guardians (school_id,first_name,last_name,phone,address,relationship) VALUES (?,?,?,?,?,?)')
            ->execute([$S, $d['pf'], $d['pl'], $d['pc'], $d['pa'], $d['rel']]);
        $gid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO students (school_id,admission_no,first_name,last_name,class_name,stream,origin,guardian_id,registered_on) VALUES (?,?,?,?,?,?,?,?,CURDATE())')
            ->execute([$S, $adm, $d['first'], $d['last'], $d['cls'], $d['stream'], $d['origin'], $gid]);
        $sidn = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO visitation_cards (school_id,student_id,card_no) VALUES (?,?,?)')->execute([$S, $sidn, card_no_for($school, $sidn)]);
        $pdo->commit();
        return [$sidn, $adm];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/* ---------- photos: always stored under the student's own id ---------- */
function attach_photo(int $studentId, array $file): string
{
    $S = sid();
    $pdo = db();
    $q = $pdo->prepare('SELECT photo FROM students WHERE id=? AND school_id=?');
    $q->execute([$studentId, $S]);
    $row = $q->fetch();
    if (!$row) {
        throw new RuntimeException('Student not found.');
    }
    $rel = save_image($file, 'uploads/photos/' . $S, 480, 'jpeg', $studentId . '-');   // 480 px is more than a card needs at 300 dpi
    make_thumb(APP_ROOT . '/' . $rel, APP_ROOT . '/' . thumb_path($rel));
    $pdo->prepare('UPDATE students SET photo=? WHERE id=? AND school_id=?')->execute([$rel, $studentId, $S]);
    delete_upload($row['photo']);
    return (string)photo_url($studentId, $rel);
}

/* ---------- matching a photo file to a student by name ---------- */
/** "Anna  Okello-Mugisha 2" -> ['anna', 'mugisha', 'okello']: lower case, letters only, sorted, so the order of the names does not matter */
function name_tokens(string $s): array
{
    $t = preg_split('/[^\p{L}]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    sort($t);
    return $t;
}
/** Active students whose first and last name together are exactly the words in $stem. */
function students_by_name(string $stem): array
{
    $tokens = name_tokens($stem);
    if (count($tokens) < 2) {
        return [];
    }                       // one word is too little to be sure
    usort($tokens, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    $like = '%' . addcslashes($tokens[0], '%_\\') . '%';   // the longest word narrows the search; the exact check is below
    $q = db()->prepare("SELECT id, admission_no, first_name, last_name, class_name, stream, photo FROM students
        WHERE school_id=? AND status='active' AND (first_name LIKE ? OR last_name LIKE ?)");
    $q->execute([sid(), $like, $like]);
    $want = name_tokens(implode(' ', $tokens));
    return array_values(array_filter($q->fetchAll(), fn ($r) => name_tokens($r['first_name'] . ' ' . $r['last_name']) === $want));
}

/* ---------- spreadsheet / CSV import ---------- */
const HEAD_MAP = [
    'admissionno' => 'adm', 'admissionnumber' => 'adm', 'admission' => 'adm', 'admno' => 'adm',
    'firstname' => 'first', 'first' => 'first', 'lastname' => 'last', 'last' => 'last',
    'class' => 'cls', 'stream' => 'stream', 'placeoforigin' => 'origin', 'origin' => 'origin',
    'guardianfirstname' => 'pf', 'parentfirstname' => 'pf', 'pfirst' => 'pf',
    'guardianlastname' => 'pl', 'parentlastname' => 'pl', 'plast' => 'pl',
    'guardiancontact' => 'pc', 'guardianphone' => 'pc', 'guardiancontactnumber' => 'pc', 'contact' => 'pc', 'phone' => 'pc',
    'guardianaddress' => 'pa', 'residentialaddress' => 'pa', 'address' => 'pa', 'relationship' => 'rel',
];
function csv_read(string $path): array
{
    $h = fopen($path, 'r');
    if (!$h) {
        throw new RuntimeException('The file could not be read.');
    }
    $first = (string)fgets($h);
    rewind($h);
    $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    $rows = [];
    while (($r = fgetcsv($h, 0, $sep)) !== false) {
        $rows[] = array_map(fn ($x) => trim((string)$x), $r);
        if (count($rows) > 2100) {
            throw new RuntimeException('The file has more than 2000 rows. Split it into smaller files.');
        }
    }
    fclose($h);
    if ($rows) {
        $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]) ?? $rows[0][0];
    }
    return $rows;
}
function table_to_records(array $table): array
{
    $table = array_values(array_filter($table, fn ($r) => count(array_filter($r, fn ($c) => $c !== '')) > 0));
    if (!$table) {
        throw new RuntimeException('The file is empty.');
    }
    $map = [];
    foreach ($table[0] as $i => $h) {
        $key = HEAD_MAP[preg_replace('/[^a-z]/', '', strtolower((string)$h))] ?? null;
        if ($key && !in_array($key, $map, true)) {
            $map[$i] = $key;
        }
    }
    $missing = array_diff(['first', 'last', 'cls', 'stream', 'pf', 'pl', 'pc'], $map);      // admission number is optional: blank = automatic
    if ($missing) {
        $names = ['first' => 'First Name', 'last' => 'Last Name', 'cls' => 'Class', 'stream' => 'Stream', 'pf' => 'Guardian First Name', 'pl' => 'Guardian Last Name', 'pc' => 'Guardian Contact'];
        throw new RuntimeException('Missing column(s): ' . implode(', ', array_map(fn ($m) => $names[$m], $missing)) . '. Use the downloaded template.');
    }
    $recs = [];
    foreach (array_slice($table, 1) as $r) {
        $rec = array_fill_keys(array_values($map), '');
        foreach ($map as $i => $key) {
            $rec[$key] = (string)($r[$i] ?? '');
        }
        $rec['cls'] = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $rec['cls']) ?? '');
        $pc = preg_replace('/[\s-]/', '', ltrim($rec['pc'], '+')) ?? '';
        $rec['pc'] = preg_match('/^7\d{8}$/', $pc) ? '0' . $pc : $pc;       // Excel drops the leading 0 of numbers
        if (trim($rec['rel'] ?? '') === '') {
            $rec['rel'] = 'Guardian';
        }
        $recs[] = $rec;
    }
    if (!$recs) {
        throw new RuntimeException('The file has a header row but no students.');
    }
    return $recs;
}
function import_process(array $recs, bool $commit): array
{
    $rows = [];
    $ok = 0;
    $bad = 0;
    $seenA = [];
    $seenP = [];
    foreach ($recs as $i => $in) {
        $no = $i + 2;
        $a = '';
        [$d, $er] = validate_student($in, null);
        if (!$er) {
            $a = strtolower($d['adm']);
            $p = strtolower($d['first'] . '|' . $d['last'] . '|' . $d['pc']);
            if ($a !== '' && isset($seenA[$a])) {
                $er[] = ['f' => 'adm', 'm' => 'Admission number repeated in this file (row ' . $seenA[$a] . ').'];
            } elseif (isset($seenP[$p])) {
                $er[] = ['f' => 'first', 'm' => 'Same student appears twice in this file (row ' . $seenP[$p] . ').'];
            }
        }
        $assigned = $d['adm'];
        if (!$er) {
            if ($a !== '') {
                $seenA[$a] = $no;
            }
            $seenP[$p] = $no;
            if ($commit) {
                try {
                    [, $assigned] = insert_student($d);
                } catch (PDOException $e) {
                    $er[] = ['f' => 'adm', 'm' => 'Could not be saved (duplicate admission number).'];
                }
            }
        }
        $er ? $bad++ : $ok++;
        if (count($rows) < 600) {
            $rows[] = ['row' => $no, 'adm' => $assigned !== '' ? $assigned : ($commit ? '' : '(automatic)'), 'name' => trim($d['first'] . ' ' . $d['last']), 'cls' => $d['cls'], 'stream' => $d['stream'], 'ok' => !$er, 'msg' => implode(' ', array_column($er, 'm'))];
        }
    }
    return ['total' => count($recs), 'valid' => $ok, 'rejected' => $bad, 'rows' => $rows];
}

/* ---------- promotion ---------- */
/**
 * Work out who moves where. $p: from_cls, to ('S2'.. | 'graduate' | 'left'), map {fromStream: toStream}, ids (optional subset).
 * @return array{moves:list<array>,problems:list<string>}
 */
function promotion_moves(array $p, bool $lock = false): array
{
    $S = sid();
    $pdo = db();
    $from = strtoupper(clean($p['from_cls'] ?? '', 3));
    $to = (string)($p['to'] ?? '');
    if (!in_array($from, CLASSES, true)) {
        throw new RuntimeException('Choose the class to move students out of.');
    }
    $leaving = in_array($to, ['graduate', 'left'], true);
    if (!$leaving) {
        if (!in_array($to, CLASSES, true)) {
            throw new RuntimeException('Choose the class to move students into.');
        }
        if ($to === $from) {
            throw new RuntimeException('The new class must be different from the current class.');
        }
    }
    $args = [$S, $from];
    $sql = "SELECT id, admission_no, first_name, last_name, stream FROM students WHERE school_id=? AND class_name=? AND status='active'";
    if (isset($p['ids']) && is_array($p['ids'])) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $p['ids']), fn ($i) => $i > 0)));
        if (!$ids) {
            return ['moves' => [], 'problems' => ['No students are selected.']];
        }
        $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $args = array_merge($args, $ids);
    }
    $q = $pdo->prepare($sql . ' ORDER BY last_name, first_name' . ($lock ? ' FOR UPDATE' : ''));
    $q->execute($args);
    $targets = $leaving ? [] : streams_map($S)[$to];
    $map = is_array($p['map'] ?? null) ? $p['map'] : [];
    $moves = [];
    $problems = [];
    foreach ($q->fetchAll() as $s) {
        if ($leaving) {
            $ts = $s['stream'];
            $status = $to === 'graduate' ? 'graduated' : 'left';
            $tc = $from;
        } else {
            $ts = clean($map[$s['stream']] ?? '', 15);
            $status = 'active';
            $tc = $to;
            if ($ts === '' && in_array($s['stream'], $targets, true)) {
                $ts = $s['stream'];
            }          // same stream name exists in the new class
            if ($ts === '' || !in_array($ts, $targets, true)) {
                $problems['No stream chosen in ' . $to . ' for students now in ' . $from . ' ' . $s['stream'] . '.'] = true;
                continue;
            }
        }
        $moves[] = ['id' => (int)$s['id'], 'adm' => $s['admission_no'], 'name' => $s['first_name'] . ' ' . $s['last_name'], 'from_cls' => $from, 'from_stream' => $s['stream'], 'to_cls' => $tc, 'to_stream' => $ts, 'to_status' => $status];
    }
    return ['moves' => $moves, 'problems' => array_keys($problems)];
}
/** Apply moves inside the caller's transaction. */
function promotion_apply(int $promoId, array $moves): void
{
    $S = sid();
    $pdo = db();
    $item = $pdo->prepare('INSERT INTO promotion_items (promotion_id,student_id,from_class,from_stream,to_class,to_stream,to_status) VALUES (?,?,?,?,?,?,?)');
    $up = $pdo->prepare("UPDATE students SET class_name=?, stream=?, status=?, status_on=IF(?='active', NULL, CURDATE()), rev=rev+1 WHERE id=? AND school_id=? AND status='active'");
    $card = $pdo->prepare("UPDATE visitation_cards SET status='unprinted' WHERE student_id=?");   // the class on the card changed: print it again
    foreach ($moves as $m) {
        $item->execute([$promoId, $m['id'], $m['from_cls'], $m['from_stream'], $m['to_cls'], $m['to_stream'], $m['to_status']]);
        $up->execute([$m['to_cls'], $m['to_stream'], $m['to_status'], $m['to_status'], $m['id'], $S]);
        $card->execute([$m['id']]);
    }
}
/** Run one or more promotion steps as ONE all-or-nothing operation. Returns [batch id, students moved]. */
function promotion_run(array $steps, string $label): array
{
    $pdo = db();
    $S = sid();
    $u = me();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO promotions (school_id,user_id,label) VALUES (?,?,?)')->execute([$S, $u['id'], mb_substr($label, 0, 120)]);
        $pid = (int)$pdo->lastInsertId();
        $moved = 0;
        foreach ($steps as $st) {
            $r = promotion_moves($st, true);
            if ($r['problems']) {
                throw new RuntimeException(implode(' ', $r['problems']));
            }
            promotion_apply($pid, $r['moves']);
            $moved += count($r['moves']);
        }
        $pdo->prepare('UPDATE promotions SET moved=? WHERE id=?')->execute([$moved, $pid]);
        $pdo->commit();
        return [$pid, $moved];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
/** Put students of a batch back where they were (only those still exactly where the promotion left them). */
function promotion_undo(int $promoId): array
{
    $pdo = db();
    $S = sid();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM promotions WHERE id=? AND school_id=? FOR UPDATE');
        $q->execute([$promoId, $S]);
        $p = $q->fetch();
        if (!$p) {
            throw new RuntimeException('Promotion not found.');
        }
        if ((int)$p['undone']) {
            throw new RuntimeException('This promotion was already undone.');
        }
        $items = $pdo->prepare('SELECT * FROM promotion_items WHERE promotion_id=?');
        $items->execute([$promoId]);
        $up = $pdo->prepare("UPDATE students SET class_name=?, stream=?, status='active', status_on=NULL, rev=rev+1
            WHERE id=? AND school_id=? AND class_name=? AND stream=? AND status=?");
        $card = $pdo->prepare("UPDATE visitation_cards SET status='unprinted' WHERE student_id=?");
        $back = 0;
        $skipped = 0;
        foreach ($items->fetchAll() as $i) {
            $up->execute([$i['from_class'], $i['from_stream'], $i['student_id'], $S, $i['to_class'], $i['to_stream'], $i['to_status']]);
            if ($up->rowCount() > 0) {
                $back++;
                $card->execute([$i['student_id']]);
            } else {
                $skipped++;
            }
        }
        $pdo->prepare('UPDATE promotions SET undone=1 WHERE id=?')->execute([$promoId]);
        $pdo->commit();
        return ['restored' => $back, 'skipped' => $skipped];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/* ---------- reports (server side, paged) ---------- */
const NAME_SQL = "CONCAT(s.first_name,' ',s.last_name)";
function report_def(string $kind): ?array
{
    $defs = [
        'reg' => ['head' => ['Adm', 'Name', 'Class', 'Stream', 'Registered'], 'sel' => 's.admission_no, ' . NAME_SQL . ', s.class_name, s.stream, s.registered_on', 'where' => '', 'order' => 's.registered_on DESC, s.id DESC'],
        'pri' => ['head' => ['Adm', 'Name', 'Card no', 'Status', 'Times printed'], 'sel' => 's.admission_no, ' . NAME_SQL . ", c.card_no, IF(c.status='printed','Printed','Unprinted'), COALESCE(c.print_count,0)", 'where' => '', 'order' => 's.class_name, s.stream, s.last_name, s.first_name'],
        'pho' => ['head' => ['Adm', 'Name', 'Class'], 'sel' => 's.admission_no, ' . NAME_SQL . ", CONCAT(s.class_name,' ',s.stream)", 'where' => ' AND s.photo IS NULL', 'order' => 's.class_name, s.stream, s.last_name'],
        'rpr' => ['head' => ['Adm', 'Name', 'Card no', 'Prints'], 'sel' => 's.admission_no, ' . NAME_SQL . ', c.card_no, c.print_count', 'where' => ' AND c.print_count>1', 'order' => 'c.print_count DESC, s.last_name'],
        'cmp' => ['head' => ['Adm', 'Name', 'Missing'], 'sel' => 's.admission_no, ' . NAME_SQL . ", CONCAT_WS(', ', IF(s.photo IS NULL,'photo',NULL), IF(s.origin='','place of origin',NULL), IF(g.address='','guardian address',NULL))",
            'where' => " AND (s.photo IS NULL OR s.origin='' OR g.address='')", 'order' => 's.class_name, s.stream, s.last_name'],
        'cls' => ['head' => ['Class', 'Stream', 'Students'], 'sel' => 's.class_name, s.stream, COUNT(*)', 'where' => '', 'order' => 's.class_name, s.stream', 'group' => ' GROUP BY s.class_name, s.stream'],
    ];
    return $defs[$kind] ?? null;
}
/** @return array{head:array,rows:array,total:int,page:int,pages:int} */
function report_page(string $kind, int $page, int $size, bool $all = false): array
{
    $def = report_def($kind);
    if (!$def) {
        throw new RuntimeException('Unknown report.');
    }
    $S = sid();
    $pdo = db();
    $from = " FROM students s JOIN guardians g ON g.id=s.guardian_id LEFT JOIN visitation_cards c ON c.student_id=s.id WHERE s.school_id=? AND s.status='active'" . $def['where'] . ($def['group'] ?? '');
    $args = [$S];
    $c = $pdo->prepare('SELECT COUNT(*) FROM (SELECT 1' . $from . ') t');
    $c->execute($args);
    $total = (int)$c->fetchColumn();
    $pages = max(1, (int)ceil($total / $size));
    $page = max(1, min($pages, $page));
    $lim = $all ? '' : ' LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size);
    $q = $pdo->prepare('SELECT ' . $def['sel'] . $from . ' ORDER BY ' . $def['order'] . $lim);
    $q->execute($args);
    return ['head' => $def['head'], 'rows' => $q->fetchAll(PDO::FETCH_NUM), 'total' => $total, 'page' => $page, 'pages' => $pages];
}
