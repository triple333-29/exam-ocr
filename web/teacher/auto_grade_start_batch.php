<?php
/**
 * auto_grade_start_batch.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Batch endpoint: รับ attempt_ids[] หลายตัวพร้อมกัน
 *   1. สร้าง job ทุก attempt ในคราวเดียว
 *   2. Fire worker เพียง 1 ครั้ง
 *   3. Worker claim ทุก job ที่ queued → ส่ง Python ใน call เดียว
 *   ⇒ Python โหลด model ครั้งเดียวเสมอ ไม่ว่าจะมีกี่ attempt
 *
 * POST params:
 *   attempt_ids[]   int[]  required  (ส่งหลาย attempt_id พร้อมกัน)
 *   force           int    optional  1 = ตรวจใหม่แม้จะตรวจแล้ว
 *
 * Response JSON:
 *   { ok, jobs: [{attempt_id, job_id, status, total_items}, ...], message }
 */

declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
header('Content-Type: application/json; charset=utf-8');

require_once 'config.php';

define('RUNNER_SECRET', 'change_this_to_a_random_string_12345'); // ต้องตรงกับ auto_grade_run.php

// ─── helpers ─────────────────────────────────────────────────────────────────
function json_out(array $a): never
{
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

function makeToken(int $job_id): string
{
    return hash('sha256', $job_id . '|' . RUNNER_SECRET);
}

function fireAndForget(string $url): array
{
    $parts  = parse_url($url);
    $scheme = $parts['scheme'] ?? 'http';
    $host   = $parts['host']   ?? 'localhost';
    $port   = $parts['port']   ?? ($scheme === 'https' ? 443 : 80);
    $path   = ($parts['path']  ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

    if (function_exists('fsockopen')) {
        $fp = @fsockopen(($scheme === 'https' ? 'ssl://' : '') . $host, $port, $en, $es, 5);
        if ($fp) {
            fwrite($fp, "GET {$path} HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\nX-Internal-Runner: 1\r\n\r\n");
            fclose($fp);
            return ['ok' => true, 'method' => 'fsockopen'];
        }
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 1, CURLOPT_NOSIGNAL => 1,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        ]);
        curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno === 0 || $errno === 28) return ['ok' => true, 'method' => 'curl'];
    }
    if (ini_get('allow_url_fopen')) {
        @file_get_contents($url, false, stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 1,
                       'header' => "Connection: close\r\n", 'ignore_errors' => true],
            'ssl'  => ['verify_peer' => false],
        ]));
        return ['ok' => true, 'method' => 'file_get_contents'];
    }
    return ['ok' => false, 'method' => 'none', 'error' => 'fsockopen/curl/allow_url_fopen ใช้ไม่ได้'];
}

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
$rawIds = $_POST['attempt_ids'] ?? [];
if (!is_array($rawIds) || empty($rawIds)) {
    json_out(['ok' => false, 'message' => 'attempt_ids[] ต้องเป็น array และไม่ว่าง']);
}
$force = isset($_POST['force']) ? (int)$_POST['force'] : 0;

// sanitize + deduplicate
$attemptIds = array_values(array_unique(array_map('intval', $rawIds)));
$attemptIds = array_filter($attemptIds, fn($id) => $id > 0);
$attemptIds = array_values($attemptIds);

if (empty($attemptIds)) {
    json_out(['ok' => false, 'message' => 'ไม่มี attempt_id ที่ถูกต้อง']);
}

// ─── connect ─────────────────────────────────────────────────────────────────
try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset("utf8mb4");
} catch (Throwable $e) {
    json_out(['ok' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}

ensureJobsTable($conn);

// ─── นับ items ต่อ attempt และ handle ซ้ำ ─────────────────────────────────────
$writtenTypes = "('short_answer','short','essay','long_answer','written','text')";

// ตรวจ sub_questions schema ครั้งเดียว
$subKeyCol = null;
try {
    $subChk = $conn->query("SHOW TABLES LIKE 'sub_questions'");
    if ($subChk && $subChk->num_rows > 0) {
        $colRes  = $conn->query("SHOW COLUMNS FROM sub_questions");
        $subCols = array_column($colRes->fetch_all(MYSQLI_ASSOC), 'Field');
        foreach (['answer','key_answer','correct_answer','answer_text','sub_answer'] as $c) {
            if (in_array($c, $subCols, true)) { $subKeyCol = $c; break; }
        }
    }
} catch (Throwable) {}

$jobs = [];  // ผลลัพธ์ที่จะส่งกลับ JS

// job_id แรกที่สร้างใหม่จริงๆ (สำหรับ fire worker)
$firstNewJobId = null;

foreach ($attemptIds as $attemptId) {

    // ── กันซ้ำ: มี job กำลังทำงานอยู่แล้ว ──────────────────────────────────
    $chk = $conn->prepare("
        SELECT id, status, total_items, done_items
        FROM   auto_grade_jobs
        WHERE  attempt_id = ?
          AND  status NOT IN ('done','error')
        ORDER  BY id DESC LIMIT 1
    ");
    $chk->bind_param('i', $attemptId);
    $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();

    if ($existing) {
        // Stuck check (queued > 90s หรือ running > 600s)
        $isStuck = false;
        if ($existing['status'] === 'queued') {
            $age = time() - strtotime($existing['created_at'] ?? 'now');
            $isStuck = $age > 90;
        } elseif ($existing['status'] === 'running') {
            $age = time() - strtotime($existing['started_at'] ?? 'now');
            $isStuck = $age > 600;
        }

        if (!$isStuck) {
            $jobs[] = [
                'attempt_id'  => $attemptId,
                'job_id'      => (int)$existing['id'],
                'status'      => $existing['status'],
                'total_items' => (int)$existing['total_items'],
                'done_items'  => (int)$existing['done_items'],
                'reused'      => true,
            ];
            continue;
        }
        // Stuck → mark error แล้วสร้างใหม่
        $conn->query("UPDATE auto_grade_jobs SET status='error', last_error='Job stuck — respawning', finished_at=NOW() WHERE id=" . (int)$existing['id']);
    }

    // ── นับ items ─────────────────────────────────────────────────────────────
    $noRegrade = $force !== 1
        ? "AND (ea.feedback IS NULL OR ea.feedback NOT LIKE '%AUTO_GRADE_V1:%')"
        : "";

    $st = $conn->prepare("
        SELECT COUNT(*) AS cnt
        FROM exam_answers ea JOIN questions q ON q.id = ea.question_id
        WHERE ea.attempt_id = ?
          AND q.type IN {$writtenTypes}
          AND ea.answer_text IS NOT NULL AND ea.answer_text <> ''
          AND q.answer IS NOT NULL AND q.answer <> ''
          {$noRegrade}
    ");
    $st->bind_param('i', $attemptId);
    $st->execute();
    $mainCount = (int)($st->get_result()->fetch_assoc()['cnt'] ?? 0);

    $subCount = 0;
    if ($subKeyCol !== null) {
        $noRegradeSub = $force !== 1
            ? "AND (esa.feedback IS NULL OR esa.feedback NOT LIKE '%AUTO_GRADE_V1:%')"
            : "";
        $st2 = $conn->prepare("
            SELECT COUNT(*) AS cnt
            FROM exam_sub_answers esa JOIN sub_questions sq ON sq.id = esa.sub_question_id
            WHERE esa.attempt_id = ?
              AND esa.answer_text IS NOT NULL AND esa.answer_text <> ''
              AND sq.`{$subKeyCol}` IS NOT NULL AND sq.`{$subKeyCol}` <> ''
              {$noRegradeSub}
        ");
        $st2->bind_param('i', $attemptId);
        $st2->execute();
        $subCount = (int)($st2->get_result()->fetch_assoc()['cnt'] ?? 0);
    }

    $totalItems = $mainCount + $subCount;

    if ($totalItems === 0 && $force !== 1) {
        $jobs[] = [
            'attempt_id'  => $attemptId,
            'job_id'      => null,
            'status'      => 'already_done',
            'total_items' => 0,
            'done_items'  => 0,
        ];
        continue;
    }

    // ── สร้าง job ─────────────────────────────────────────────────────────────
    $ins = $conn->prepare("
        INSERT INTO auto_grade_jobs (attempt_id, status, total_items, done_items, force_regrade, message)
        VALUES (?, 'queued', ?, 0, ?, 'กำลังเข้าคิว…')
    ");
    $ins->bind_param('iii', $attemptId, $totalItems, $force);
    $ins->execute();
    $newJobId = (int)$conn->insert_id;

    if ($firstNewJobId === null) {
        $firstNewJobId = $newJobId; // จะใช้ fire worker ครั้งเดียว
    }

    $jobs[] = [
        'attempt_id'  => $attemptId,
        'job_id'      => $newJobId,
        'status'      => 'queued',
        'total_items' => $totalItems,
        'done_items'  => 0,
    ];
}

// ─── Fire worker ครั้งเดียว ────────────────────────────────────────────────
// Worker จะ claim ทุก job ที่ queued พร้อมกัน → Python call เดียว
$triggerResult = ['ok' => true, 'method' => 'not_needed'];

if ($firstNewJobId !== null) {
    $token         = makeToken($firstNewJobId);
    $runnerUrl     = buildRunnerUrl($firstNewJobId, $token);
    $triggerResult = fireAndForget($runnerUrl);

    if (!$triggerResult['ok']) {
        // mark ทุก job ใหม่เป็น error
        foreach ($jobs as &$j) {
            if (($j['status'] ?? '') === 'queued' && isset($j['job_id'])) {
                $errMsg = 'HTTP trigger failed: ' . ($triggerResult['error'] ?? 'unknown');
                $upErr  = $conn->prepare("UPDATE auto_grade_jobs SET status='error', last_error=?, finished_at=NOW() WHERE id=?");
                $upErr->bind_param('si', $errMsg, $j['job_id']);
                $upErr->execute();
                $j['status'] = 'error';
            }
        }
        unset($j);

        json_out([
            'ok'      => false,
            'message' => 'HTTP trigger ล้มเหลว — ตรวจสอบ fsockopen/curl',
            'jobs'    => $jobs,
        ]);
    }
}

// ─── Response ────────────────────────────────────────────────────────────────
$newCount   = count(array_filter($jobs, fn($j) => ($j['status'] ?? '') === 'queued'));
$skipCount  = count($jobs) - $newCount;

json_out([
    'ok'      => true,
    'jobs'    => $jobs,
    'trigger' => $triggerResult['method'] ?? 'none',
    'message' => "สร้างคิวแล้ว {$newCount} attempt (ข้าม {$skipCount}) — worker จะตรวจรวมกันใน Python call เดียว",
]);
