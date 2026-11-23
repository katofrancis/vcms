<?php
declare(strict_types=1);
/**
 * Card verification page - opened by scanning the QR code on a visitation card.
 * Public on purpose (a gate officer's phone is not signed in), so it:
 *   - only answers for a card number WITH its secret signature (a forger cannot invent one),
 *   - shows only what is needed to compare the card with the person: photo, name, class, status,
 *   - never shows guardian phone numbers or addresses,
 *   - slows down guessing (30 wrong tries per 10 minutes per device).
 */
require __DIR__ . '/api/core.php';
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$h = fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
$cardNo = clean($_GET['c'] ?? '', 40);
$sig = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_GET['k'] ?? ''))) ?? '';

$state = 'invalid';
$row = null;
$school = null;
$blocked = false;
try {
    $pdo = db();
    $q = $pdo->prepare('SELECT window_start, fails FROM verify_fails WHERE ip=?');
    $q->execute([$ip]);
    $f = $q->fetch();
    if ($f && time() - strtotime((string)$f['window_start']) < 600 && (int)$f['fails'] >= 30) {
        $blocked = true;
    }

    if (!$blocked && $cardNo !== '' && strlen($sig) === 12) {
        $q = $pdo->prepare('SELECT c.card_no, s.admission_no, s.first_name, s.last_name, s.class_name, s.stream, s.photo, s.status AS stu_status, s.school_id
            FROM visitation_cards c JOIN students s ON s.id=c.student_id WHERE c.card_no=?');
        $q->execute([$cardNo]);
        $row = $q->fetch() ?: null;
        if ($row) {
            $school = school_row((int)$row['school_id']);
            if (hash_equals(qr_sig($school, $cardNo), $sig)) {
                $state = $row['stu_status'] === 'active' ? 'valid' : 'left';
            } else {
                $row = null;
            }
        }
    }
    if (!$blocked && $state === 'invalid') {                          // count the miss; log it for the administrator
        $pdo->prepare('INSERT INTO verify_fails (ip,fails,window_start) VALUES (?,1,NOW())
            ON DUPLICATE KEY UPDATE fails = IF(TIMESTAMPDIFF(SECOND, window_start, NOW()) >= 600, 1, fails + 1), window_start = IF(TIMESTAMPDIFF(SECOND, window_start, NOW()) >= 600, NOW(), window_start)')->execute([$ip]);
        $pdo->prepare('INSERT INTO audit_logs (school_id,user_id,action,detail,ip) VALUES (0,NULL,?,?,?)')->execute(['verify_failed', mb_substr($cardNo, 0, 60), $ip]);
    }
} catch (Throwable $e) {
    error_log('[verify] ' . $e->getMessage());
    http_response_code(500);
    $state = 'error';
}

$photo = null;
if ($row && $state === 'valid' && $row['photo'] && preg_match('#^uploads/photos/(\d+/)?[A-Za-z0-9_-]+\.(jpg|png)$#', (string)$row['photo'])) {
    $p = APP_ROOT . '/' . $row['photo'];
    if (is_file($p)) {
        $photo = 'data:image/' . (str_ends_with($p, '.png') ? 'png' : 'jpeg') . ';base64,' . base64_encode((string)file_get_contents($p));
    }
}
$banner = [
    'valid'   => ['VALID CARD', 'ok', 'This card is genuine. Compare the photo and name with the person presenting it.'],
    'left'    => ['NOT VALID', 'bad', 'This student is no longer enrolled. Do not accept this card.'],
    'invalid' => ['NOT VALID - POSSIBLE FORGERY', 'bad', $blocked ? 'Too many unsuccessful checks from this device. Please wait a few minutes.' : 'This card could not be verified. Do not accept it; report it to the school office.'],
    'error'   => ['TEMPORARILY UNAVAILABLE', 'warn', 'The school system could not be reached. Try again shortly.'],
][$state];
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Card verification</title>
<style>
body{margin:0;background:#eef1f6;font:16px/1.45 system-ui,Arial,sans-serif;color:#14213d}
.wrap{max-width:420px;margin:0 auto;padding:14px}
.school{text-align:center;font-weight:700;letter-spacing:.03em;margin:8px 0 12px}
.banner{border-radius:14px;padding:16px;text-align:center;color:#fff}
.banner b{display:block;font-size:22px;letter-spacing:.04em}.banner span{display:block;font-size:14px;margin-top:6px;opacity:.95}
.banner.ok{background:#12805c}.banner.warn{background:#b45309}.banner.bad{background:#b91c1c}
.card{background:#fff;border-radius:14px;margin-top:12px;padding:14px;box-shadow:0 1px 4px rgba(0,0,0,.08)}
.ph{width:100%;max-height:340px;object-fit:contain;background:#eee;border-radius:10px;display:block;margin:0 auto 10px}
.nm{font-size:22px;font-weight:700;margin:2px 0 8px}
.r{display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-top:1px solid #e6e9f0}.r span{color:#64748b}
.foot{font-size:12px;color:#64748b;text-align:center;margin-top:14px}
</style></head><body><div class="wrap">
<?php if ($school): ?><div class="school"><?= $h($school['name']) ?></div><?php endif; ?>
<div class="banner <?= $banner[1] ?>"><b><?= $h($banner[0]) ?></b><span><?= $h($banner[2]) ?></span></div>
<?php if ($row && $state === 'valid'): ?>
<div class="card">
  <?php if ($photo): ?><img class="ph" src="<?= $photo ?>" alt="Student photo"><?php else: ?><div class="ph" style="height:120px;display:flex;align-items:center;justify-content:center;color:#888">NO PHOTO ON FILE</div><?php endif; ?>
  <div class="nm"><?= $h($row['first_name'] . ' ' . $row['last_name']) ?></div>
  <div class="r"><span>Class</span><b><?= $h($row['class_name'] . ' ' . $row['stream']) ?></b></div>
  <div class="r"><span>Admission No</span><b><?= $h($row['admission_no']) ?></b></div>
  <div class="r"><span>Card number</span><b><?= $h($row['card_no']) ?></b></div>
</div>
<?php endif; ?>
<div class="foot">Checked <?= $h(date('d M Y H:i')) ?> &middot; Always compare the photo with the person.</div>
</div></body></html>
