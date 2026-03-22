<?php
/**
 * auto_grade_progress.php
 * ───────────────────────
 * Polling endpoint: อ่านสถานะ job จาก DB table (auto_grade_jobs)
 * ไม่มี logic การตรวจอีกต่อไป — ทุกอย่างถูกทำใน auto_grade_worker.php
 *
 * GET params:
 *   job_id   int   required
 *
 * Response JSON:
 *   { ok, status, total_items, done_items, message, last_error }
 *
 * status values:
 *   queued   → worker ยังไม่ได้เริ่ม
 *   running  → worker กำลังตรวจ
 *   done     → เสร็จแล้ว
 *   error    → เกิดข้อผิดพลาด (ดู last_error)
 *   stale    → worker ไม่ได้ start ภายในเวลาที่กำหนด (ให้ frontend แจ้งผู้ใช้)
 */

declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
header('Content-Type: application/json; charset=utf-8');

// ─── DB config ───────────────────────────────────────────────────────────────
require_once 'config.php';

// ─── config ──────────────────────────────────────────────────────────────────
// ถ้า job ยังเป็น 'queued' นานกว่า X วินาที แสดงว่า worker ไม่ได้ถูก start
// (เช่น exec() ถูก disable บน shared hosting)
define('STALE_QUEUED_SECONDS', 60);

// ─── helpers ─────────────────────────────────────────────────────────────────
function json_out(array $a): never
{
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── input ───────────────────────────────────────────────────────────────────
$job_id = (int) trim((string) ($_GET['job_id'] ?? ''));
if ($job_id <= 0) {
    json_out(['ok' => false, 'message' => 'job_id หาย']);
}

// ─── connect ─────────────────────────────────────────────────────────────────
try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset("utf8mb4");
} catch (Throwable $e) {
    json_out(['ok' => false, 'message' => 'เชื่อมต่อ DB ไม่ได้: ' . $e->getMessage()]);
}

// ─── query job ───────────────────────────────────────────────────────────────
try {
    $st = $conn->prepare("
        SELECT id, status, total_items, done_items, message, last_error,
               created_at, started_at, finished_at
        FROM   auto_grade_jobs
        WHERE  id = ?
        LIMIT  1
    ");
    $st->bind_param('i', $job_id);
    $st->execute();
    $job = $st->get_result()->fetch_assoc();
} catch (Throwable $e) {
    // ตารางอาจยังไม่มี (ยังไม่เคย start งาน)
    json_out([
        'ok'      => false,
        'message' => 'ไม่พบตาราง auto_grade_jobs — กรุณาเรียก auto_grade_start.php ก่อน',
    ]);
}

if (!$job) {
    json_out(['ok' => false, 'message' => 'ไม่พบ job_id นี้']);
}

$status     = (string) $job['status'];
$totalItems = (int) $job['total_items'];
$doneItems  = (int) $job['done_items'];
$message    = (string) ($job['message'] ?? '');
$lastError  = $job['last_error'] ?? null;

// ─── ตรวจ stale: queued นานเกินไปโดยที่ worker ไม่ได้เริ่ม ─────────────────
if ($status === 'queued' && !empty($job['created_at'])) {
    $createdTs = strtotime($job['created_at']);
    if ($createdTs !== false && (time() - $createdTs) > STALE_QUEUED_SECONDS) {
        // อัปเดต status เป็น error ใน DB
        try {
            $upStale = $conn->prepare("
                UPDATE auto_grade_jobs
                SET status = 'error',
                    last_error = 'Worker ไม่ได้เริ่มภายใน " . STALE_QUEUED_SECONDS . " วินาที — exec() อาจถูก disable หรือ worker.php ไม่พบ',
                    finished_at = NOW()
                WHERE id = ?
            ");
            $upStale->bind_param('i', $job_id);
            $upStale->execute();
        } catch (Throwable) {}

        json_out([
            'ok'          => true,
            'status'      => 'error',
            'total_items' => $totalItems,
            'done_items'  => $doneItems,
            'message'     => 'Worker ไม่ได้เริ่มภายในเวลาที่กำหนด',
            'last_error'  => 'exec() อาจถูก disable บนเซิร์ฟเวอร์นี้ กรุณาตรวจสอบการตั้งค่า PHP หรือรัน worker ด้วยมือ: php auto_grade_worker.php ' . $job_id,
        ]);
    }
}

// ─── response ─────────────────────────────────────────────────────────────────
json_out([
    'ok'          => true,
    'status'      => $status,
    'total_items' => $totalItems,
    'done_items'  => $doneItems,
    'message'     => $message,
    'last_error'  => $lastError,
]);