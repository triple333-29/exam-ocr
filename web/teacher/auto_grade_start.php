<?php
/**
 * auto_grade_start.php  (v3 — Windows/Apache compatible)
 * ────────────────────────────────────────────────────────
 * สร้าง DB job แล้ว trigger auto_grade_run.php ผ่าน HTTP fire-and-forget
 * วิธีนี้ทำงานได้บน Windows + Apache (mpm_winnt) ซึ่ง exec/shell_exec ใช้ไม่ได้
 *
 * POST params:
 *   attempt_id   int   required
 *   force        int   optional  1 = ตรวจใหม่แม้จะตรวจแล้ว
 */

declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
header('Content-Type: application/json; charset=utf-8');

require_once 'config.php';

// ── Secret สำหรับ sign token (เปลี่ยนเป็นค่าสุ่มของตัวเอง) ────────────────
// ต้องตรงกับค่าใน auto_grade_run.php
define('RUNNER_SECRET', 'change_this_to_a_random_string_12345');

// ─── helpers ─────────────────────────────────────────────────────────────────
function json_out(array $a): never
{
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * สร้าง token สำหรับ job นี้
 * ตรวจใน auto_grade_run.php เพื่อกัน external call
 */
function makeToken(int $job_id): string
{
    return hash('sha256', $job_id . '|' . RUNNER_SECRET);
}

/**
 * ส่ง HTTP GET แบบ fire-and-forget
 * ส่งแล้วปิด connection ทันที ไม่รอ response
 * คืน ['ok'=>bool, 'method'=>string, 'error'=>string]
 */
function fireAndForget(string $url): array
{
    $parts = parse_url($url);
    if (!$parts) return ['ok' => false, 'method' => 'none', 'error' => 'URL parse failed'];

    $scheme = $parts['scheme'] ?? 'http';
    $host   = $parts['host']   ?? 'localhost';
    $port   = $parts['port']   ?? ($scheme === 'https' ? 443 : 80);
    $path   = ($parts['path']  ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    $prefix = $scheme === 'https' ? 'ssl://' : '';

    // ── Method 1: fsockopen ───────────────────────────────────────────────
    if (function_exists('fsockopen')) {
        $fp = @fsockopen($prefix . $host, $port, $errno, $errstr, 5);
        if ($fp) {
            $req = "GET {$path} HTTP/1.1\r\n"
                 . "Host: {$host}\r\n"
                 . "Connection: close\r\n"
                 . "X-Internal-Runner: 1\r\n"
                 . "\r\n";
            fwrite($fp, $req);
            fclose($fp);  // ปิดทันที ไม่รอ response
            return ['ok' => true, 'method' => 'fsockopen'];
        }
    }

    // ── Method 2: curl non-blocking (timeout=1) ───────────────────────────
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 1,   // timeout 1 วินาที = แทบไม่รอ response
            CURLOPT_NOSIGNAL       => 1,
            CURLOPT_HTTPHEADER     => ['X-Internal-Runner: 1'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        curl_exec($ch); // เราไม่สนใจ error TIMEOUT ที่นี่
        $curlErrno = curl_errno($ch);
        curl_close($ch);
        // CURLE_OPERATION_TIMEDOUT (28) = request ถูกส่งแล้วแต่ timeout ก่อนได้ response = ok
        if ($curlErrno === 0 || $curlErrno === 28) {
            return ['ok' => true, 'method' => 'curl'];
        }
    }

    // ── Method 3: file_get_contents + stream context ──────────────────────
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 1,
                'header'  => "X-Internal-Runner: 1\r\nConnection: close\r\n",
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => false],
        ]);
        @file_get_contents($url, false, $ctx);
        return ['ok' => true, 'method' => 'file_get_contents'];
    }

    return ['ok' => false, 'method' => 'none', 'error' => 'fsockopen, curl, และ allow_url_fopen ใช้ไม่ได้ทั้งหมด'];
}

/**
 * สร้าง URL ของ auto_grade_run.php โดย detect จาก request ปัจจุบัน
 */
function buildRunnerUrl(int $job_id, string $token): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    return $scheme . '://' . $host . $dir . '/auto_grade_run.php'
         . '?job_id=' . $job_id . '&token=' . urlencode($token);
}

function ensureJobsTable(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS auto_grade_jobs (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            attempt_id    INT          NOT NULL,
            status        VARCHAR(16)  NOT NULL DEFAULT 'queued',
            total_items   INT          NOT NULL DEFAULT 0,
            done_items    INT          NOT NULL DEFAULT 0,
            message       VARCHAR(255) NULL,
            last_error    MEDIUMTEXT   NULL,
            force_regrade TINYINT(1)   NOT NULL DEFAULT 0,
            created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at    DATETIME     NULL,
            finished_at   DATETIME     NULL,
            INDEX (attempt_id),
            INDEX (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// ─── input ───────────────────────────────────────────────────────────────────
$attempt_id = isset($_POST['attempt_id']) ? (int) $_POST['attempt_id'] : 0;
$force      = isset($_POST['force'])      ? (int) $_POST['force']      : 0;

if ($attempt_id <= 0) json_out(['ok' => false, 'message' => 'attempt_id ไม่ถูกต้อง']);

// ─── connect ─────────────────────────────────────────────────────────────────
try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset("utf8mb4");
} catch (Throwable $e) {
    json_out(['ok' => false, 'message' => 'เชื่อมต่อ DB ไม่ได้: ' . $e->getMessage()]);
}

// ตรวจว่าไฟล์ที่จำเป็นมีอยู่
$workerScript = __DIR__ . DIRECTORY_SEPARATOR . 'auto_grade_worker.php';
$runScript    = __DIR__ . DIRECTORY_SEPARATOR . 'auto_grade_run.php';
if (!is_file($workerScript)) json_out(['ok' => false, 'message' => 'ไม่พบ auto_grade_worker.php']);
if (!is_file($runScript))    json_out(['ok' => false, 'message' => 'ไม่พบ auto_grade_run.php']);

ensureJobsTable($conn);

// ─── กันกดซ้ำ ────────────────────────────────────────────────────────────────
$chk = $conn->prepare("
    SELECT id, status, total_items, done_items, created_at
    FROM   auto_grade_jobs
    WHERE  attempt_id = ?
      AND  status NOT IN ('done','error')
    ORDER BY id DESC LIMIT 1
");
$chk->bind_param('i', $attempt_id);
$chk->execute();
$existing = $chk->get_result()->fetch_assoc();

if ($existing) {
    $stuck = false;
    if ($existing['status'] === 'queued' && !empty($existing['created_at'])) {
        $stuck = (time() - strtotime($existing['created_at'])) > 90;
    }
    if (!$stuck) {
        json_out([
            'ok'          => true,
            'status'      => $existing['status'],
            'job_id'      => (int) $existing['id'],
            'total_items' => (int) $existing['total_items'],
            'done_items'  => (int) $existing['done_items'],
            'message'     => 'มีงานเดิมกำลังทำอยู่',
        ]);
    }
    // Stuck → mark error แล้วสร้างใหม่
    $conn->query("UPDATE auto_grade_jobs SET status='error', last_error='Job stuck — respawning', finished_at=NOW() WHERE id=" . (int)$existing['id']);
}

// ─── นับ items ────────────────────────────────────────────────────────────────
$writtenTypes = "('short_answer','short','essay','long_answer','written','text')";
$noRegrade    = $force !== 1 ? "AND (ea.feedback IS NULL OR ea.feedback NOT LIKE '%AUTO_GRADE_V1:%')" : "";

$st = $conn->prepare("
    SELECT COUNT(*) AS cnt
    FROM exam_answers ea JOIN questions q ON q.id = ea.question_id
    WHERE ea.attempt_id = ?
      AND q.type IN {$writtenTypes}
      AND ea.answer_text IS NOT NULL AND ea.answer_text <> ''
      AND q.answer IS NOT NULL AND q.answer <> ''
      {$noRegrade}
");
$st->bind_param('i', $attempt_id);
$st->execute();
$mainCount = (int) ($st->get_result()->fetch_assoc()['cnt'] ?? 0);

$subCount = 0;
try {
    $subChk = $conn->query("SHOW TABLES LIKE 'sub_questions'");
    if ($subChk && $subChk->num_rows > 0) {
        $colRes  = $conn->query("SHOW COLUMNS FROM sub_questions");
        $subCols = array_column($colRes->fetch_all(MYSQLI_ASSOC), 'Field');
        $keyCol  = null;
        foreach (['answer','key_answer','correct_answer','answer_text','sub_answer'] as $c) {
            if (in_array($c, $subCols, true)) { $keyCol = $c; break; }
        }
        if ($keyCol !== null) {
            $noRegradeSub = $force !== 1 ? "AND (esa.feedback IS NULL OR esa.feedback NOT LIKE '%AUTO_GRADE_V1:%')" : "";
            $st2 = $conn->prepare("
                SELECT COUNT(*) AS cnt
                FROM exam_sub_answers esa JOIN sub_questions sq ON sq.id = esa.sub_question_id
                WHERE esa.attempt_id = ?
                  AND esa.answer_text IS NOT NULL AND esa.answer_text <> ''
                  AND sq.`{$keyCol}` IS NOT NULL AND sq.`{$keyCol}` <> ''
                  {$noRegradeSub}
            ");
            $st2->bind_param('i', $attempt_id);
            $st2->execute();
            $subCount = (int) ($st2->get_result()->fetch_assoc()['cnt'] ?? 0);
        }
    }
} catch (Throwable) {}

$totalItems = $mainCount + $subCount;

if ($totalItems === 0 && $force !== 1) {
    json_out(['ok' => true, 'status' => 'already_done', 'message' => 'ไม่มีคำตอบข้อเขียนที่ต้องตรวจ (หรือถูกตรวจแล้ว)']);
}

// ─── สร้าง DB job ─────────────────────────────────────────────────────────────
$ins = $conn->prepare("
    INSERT INTO auto_grade_jobs (attempt_id, status, total_items, done_items, force_regrade, message)
    VALUES (?, 'queued', ?, 0, ?, 'กำลังเข้าคิว…')
");
$ins->bind_param('iii', $attempt_id, $totalItems, $force);
$ins->execute();
$job_id = (int) $conn->insert_id;

// ─── สร้าง token แล้ว trigger runner ────────────────────────────────────────
$token      = makeToken($job_id);
$runnerUrl  = buildRunnerUrl($job_id, $token);
$trigger    = fireAndForget($runnerUrl);

if (!$trigger['ok']) {
    // HTTP trigger ล้มเหลว → mark error ทันที
    $errMsg = 'HTTP trigger ล้มเหลว: ' . ($trigger['error'] ?? 'unknown')
            . ' | URL: ' . $runnerUrl;
    $upErr = $conn->prepare("UPDATE auto_grade_jobs SET status='error', last_error=?, finished_at=NOW() WHERE id=?");
    $upErr->bind_param('si', $errMsg, $job_id);
    $upErr->execute();

    json_out([
        'ok'         => false,
        'status'     => 'error',
        'job_id'     => $job_id,
        'message'    => 'trigger runner ล้มเหลว',
        'last_error' => $errMsg,
        'hint'       => 'ตรวจสอบว่า fsockopen หรือ curl เปิดอยู่ใน php.ini',
    ]);
}

json_out([
    'ok'           => true,
    'status'       => 'queued',
    'job_id'       => $job_id,
    'total_items'  => $totalItems,
    'done_items'   => 0,
    'trigger'      => $trigger['method'],   // debug: วิธีที่ใช้ fire
    'message'      => 'กำลังเข้าคิว… (ข้อปกติ ' . $mainCount . ', โจทย์ย่อย ' . $subCount . ')',
]);