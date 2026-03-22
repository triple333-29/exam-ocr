<?php
/**
 * auto_grade_run.php
 * ──────────────────
 * Worker endpoint — ถูกเรียกแบบ fire-and-forget จาก auto_grade_start.php
 * รัน auto_grade_worker.php โดยตรงใน Apache thread ปัจจุบัน
 * ทำงานต่อเนื่องหลัง caller ปิด connection ไปแล้ว
 *
 * GET params:
 *   job_id   int     required
 *   token    string  required  (HMAC กัน external call)
 *
 * ⚠️  ไม่ควรเรียกตรงจาก browser — ให้ auto_grade_start.php เรียกเท่านั้น
 */

declare(strict_types=1);

// ── Secret (ต้องตรงกับ auto_grade_start.php) ─────────────────────────────
define('RUNNER_SECRET', 'change_this_to_a_random_string_12345');

// ── ตรวจ input ────────────────────────────────────────────────────────────
$job_id = isset($_GET['job_id']) ? (int) $_GET['job_id'] : 0;
$token  = trim((string) ($_GET['token'] ?? ''));

if ($job_id <= 0 || $token === '') {
    http_response_code(400);
    exit('bad request');
}

// ── ตรวจ token (กัน external call) ────────────────────────────────────────
$expected = hash('sha256', $job_id . '|' . RUNNER_SECRET);
if (!hash_equals($expected, $token)) {
    http_response_code(403);
    exit('forbidden');
}

// ── ตอบกลับ HTTP ทันที แล้วทำงานต่อ ─────────────────────────────────────
// caller จะได้รับ response "ok" แล้วปิด connection
// Apache thread นี้ยังทำงานต่อได้เพราะ ignore_user_abort(true)

ignore_user_abort(true);
set_time_limit(0);

if (ob_get_level()) ob_end_clean();
header('Content-Type: text/plain; charset=utf-8');
header('Content-Length: 2');
header('Connection: close');
echo 'ok';
flush();

// FastCGI: ส่ง response ไปก่อน แล้วทำงานต่อ
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// ── ตรวจว่า job มีอยู่จริงและยังเป็น queued ─────────────────────────────


try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require_once 'config.php';
} catch (Throwable $e) {
    exit; // DB ต่อไม่ได้ — หยุด (worker จะ update status เป็น error เอง)
}

$st = $conn->prepare("SELECT status FROM auto_grade_jobs WHERE id = ? LIMIT 1");
$st->bind_param('i', $job_id);
$st->execute();
$job = $st->get_result()->fetch_assoc();

if (!$job || $job['status'] !== 'queued') {
    exit; // job ไม่มีหรือไม่ใช่ queued (อาจถูก run ไปแล้วจาก request ซ้ำ)
}

$conn->close(); // ปิด connection ก่อน include worker (worker จะเปิดใหม่เอง)

// ── include worker ─────────────────────────────────────────────────────────
// worker.php อ่าน job_id จาก:
//   - $argv[1]         ถ้า PHP_SAPI === 'cli'
//   - $_GET['job_id']  ถ้าไม่ใช่ CLI  ← กรณีนี้
//
// เราอยู่ใน Apache (ไม่ใช่ CLI) ดังนั้น worker จะใช้ $_GET['job_id']
// ซึ่ง auto_grade_run.php ได้รับมาจาก query string แล้ว

$workerScript = __DIR__ . DIRECTORY_SEPARATOR . 'auto_grade_worker.php';

if (!is_file($workerScript)) {
    // บันทึก error กลับ DB
    try {
        $err = new mysqli($host, $user, $pass, $db);
        $err->set_charset("utf8mb4");
        $e = $err->prepare("UPDATE auto_grade_jobs SET status='error', last_error='auto_grade_worker.php not found', finished_at=NOW() WHERE id=?");
        $e->bind_param('i', $job_id);
        $e->execute();
    } catch (Throwable) {}
    exit;
}

// worker.php ใช้ $_GET['job_id'] เมื่อไม่ใช่ CLI → ค่านี้ถูกตั้งไว้แล้วใน $_GET
require $workerScript;
