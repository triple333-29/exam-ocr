<?php
/**
 * auto_grade_debug.php
 * ────────────────────
 * วินิจฉัยปัญหา "ค้างที่กำลังเข้าคิว"
 * เปิดไฟล์นี้ใน browser ตรงๆ เพื่อดูผล
 *
 * ⚠️  ลบไฟล์นี้ออกหลังจาก debug เสร็จแล้ว
 */
declare(strict_types=1);

// ─── DB config ───────────────────────────────────────────────────────────────
$DB_HOST = "localhost";
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "exam_ocr";

$PYTHON_CLI = "python";  // เปลี่ยนตรงนี้ถ้าใช้ python3 หรือ py

// ─── helpers ─────────────────────────────────────────────────────────────────
function ok(string $s): string  { return '<span style="color:#15803d;font-weight:700">✓ ' . htmlspecialchars($s) . '</span>'; }
function err(string $s): string { return '<span style="color:#b91c1c;font-weight:700">✗ ' . htmlspecialchars($s) . '</span>'; }
function warn(string $s): string{ return '<span style="color:#b45309;font-weight:700">⚠ ' . htmlspecialchars($s) . '</span>'; }
function info(string $s): string{ return '<span style="color:#1d4ed8;">' . htmlspecialchars($s) . '</span>'; }
function row(string $label, string $value): string {
    return '<tr><td style="color:#6b7280;padding:6px 14px 6px 0;white-space:nowrap;vertical-align:top">'
         . htmlspecialchars($label) . '</td><td style="padding:6px 0;">' . $value . '</td></tr>';
}

$workerScript = __DIR__ . DIRECTORY_SEPARATOR . 'auto_grade_worker.php';
$pyScript     = __DIR__ . DIRECTORY_SEPARATOR . 'auto_grade_nlp_stream.py';
$phpBin       = PHP_BINARY ?: 'php';

?><!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>Auto-Grade Debug</title>
  <style>
    body{font-family:system-ui,-apple-system,sans-serif;margin:0;background:#f4f7f6;color:#111827;padding:20px;}
    .wrap{max-width:860px;margin:auto;}
    h1{margin:0 0 4px;font-size:20px;}
    .sub{color:#6b7280;font-size:13px;margin-bottom:20px;}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:14px;box-shadow:0 2px 8px rgba(0,0,0,.04);}
    .card h2{margin:0 0 12px;font-size:15px;border-bottom:1px solid #f3f4f6;padding-bottom:8px;}
    table{border-collapse:collapse;width:100%;font-size:13px;}
    code{background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:12px;font-family:monospace;word-break:break-all;}
    .btn{display:inline-block;padding:9px 16px;border-radius:8px;background:#0f766e;color:#fff;border:none;cursor:pointer;font-size:13px;margin-top:8px;}
    .btn:hover{filter:brightness(.95)}
    .btnGray{background:#6b7280;}
    pre{background:#1e293b;color:#e2e8f0;padding:12px;border-radius:8px;font-size:12px;overflow-x:auto;margin:8px 0 0;}
    .alert{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:10px;}
    .alert.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;}
    .alert.danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;}
  </style>
</head>
<body>
<div class="wrap">
  <h1>🔍 Auto-Grade Debug</h1>
  <div class="sub">ใช้หน้านี้วินิจฉัยปัญหา "กำลังเข้าคิว" แล้วค้างไม่ทำงาน</div>

  <?php

  // ══════════════════════════════════════════════════════════════════
  // ACTION: รัน worker ด้วยมือ
  // ══════════════════════════════════════════════════════════════════
  if (isset($_POST['run_worker']) && isset($_POST['job_id'])) {
    $jid = (int)$_POST['job_id'];
    if ($jid > 0) {
      $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($workerScript) . ' ' . $jid;
      echo '<div class="card"><h2>▶ รัน Worker (job #' . $jid . ')</h2>';
      echo '<p style="font-size:13px;">คำสั่ง: <code>' . htmlspecialchars($cmd) . '</code></p>';
      // รันแบบ foreground เพื่อดู output ตรงๆ
      $output = [];
      $code   = 0;
      exec($cmd . ' 2>&1', $output, $code);
      echo '<p>Exit code: <b>' . $code . '</b></p>';
      echo '<pre>' . htmlspecialchars(implode("\n", array_slice($output, 0, 60))) . '</pre>';
      echo '</div>';
    }
  }

  // ACTION: ล้าง job เก่า
  if (isset($_POST['clear_stuck'])) {
    try {
      $pdo = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
      $pdo->set_charset("utf8mb4");
      $pdo->query("UPDATE auto_grade_jobs SET status='error', last_error='Cleared by debug tool', finished_at=NOW() WHERE status IN ('queued','running')");
      echo '<div class="alert info">ล้าง job ที่ค้างแล้ว (' . $pdo->affected_rows . ' รายการ)</div>';
    } catch(Throwable $e) {
      echo '<div class="alert danger">ล้างไม่ได้: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
  }

  ?>

  <!-- ══ SECTION 1: PHP Environment ══ -->
  <div class="card">
    <h2>1. PHP Environment</h2>
    <table>
      <?= row('PHP Version',     ok(PHP_VERSION)) ?>
      <?= row('SAPI',            info(PHP_SAPI)) ?>
      <?= row('OS',              info(PHP_OS_FAMILY . ' — ' . PHP_OS)) ?>
      <?= row('PHP_BINARY',      '<code>' . htmlspecialchars($phpBin) . '</code> ' . (is_executable($phpBin) ? ok('พบและรันได้') : err('ไม่พบหรือรันไม่ได้'))) ?>
      <?= row('max_execution_time', info(ini_get('max_execution_time') . ' วินาที')) ?>
      <?= row('disable_functions', '<code>' . htmlspecialchars(ini_get('disable_functions') ?: '(ไม่มี)') . '</code>') ?>
    </table>
  </div>

  <!-- ══ SECTION 2: ไฟล์ที่จำเป็น ══ -->
  <div class="card">
    <h2>2. ไฟล์ที่จำเป็น</h2>
    <table>
      <?= row('auto_grade_worker.php', is_file($workerScript) ? ok('พบแล้ว') : err('ไม่พบ! วางไฟล์ในโฟลเดอร์เดียวกัน')) ?>
      <?= row('auto_grade_nlp_stream.py', is_file($pyScript) ? ok('พบแล้ว') : err('ไม่พบ! ต้องวางในโฟลเดอร์เดียวกัน')) ?>
      <?= row('Path ของ worker', '<code>' . htmlspecialchars($workerScript) . '</code>') ?>
    </table>
  </div>

  <!-- ══ SECTION 3: ฟังก์ชัน spawn process ══ -->
  <?php
  $execOk      = function_exists('exec')        && !in_array('exec',        array_map('trim', explode(',', ini_get('disable_functions'))));
  $shellExecOk = function_exists('shell_exec')  && !in_array('shell_exec',  array_map('trim', explode(',', ini_get('disable_functions'))));
  $procOpenOk  = function_exists('proc_open')   && !in_array('proc_open',   array_map('trim', explode(',', ini_get('disable_functions'))));
  $popenOk     = function_exists('popen')       && !in_array('popen',       array_map('trim', explode(',', ini_get('disable_functions'))));
  ?>
  <div class="card">
    <h2>3. ฟังก์ชัน Spawn Process (จำเป็นเพื่อรัน worker)</h2>
    <table>
      <?= row('exec()',       $execOk      ? ok('ใช้ได้') : err('ถูก disable — spawn worker ไม่ได้!')) ?>
      <?= row('shell_exec()', $shellExecOk ? ok('ใช้ได้') : err('ถูก disable')) ?>
      <?= row('proc_open()',  $procOpenOk  ? ok('ใช้ได้') : warn('ถูก disable — Python NLP จะทำงานไม่ได้!')) ?>
      <?= row('popen()',      $popenOk     ? ok('ใช้ได้') : err('ถูก disable')) ?>
    </table>
    <?php if (!$execOk && !$shellExecOk && !$popenOk): ?>
    <div class="alert danger" style="margin-top:10px;">
      <b>ปัญหาหลัก:</b> ฟังก์ชัน exec/shell_exec/popen ทุกตัวถูก disable<br>
      Worker ไม่สามารถ spawn ได้อัตโนมัติ → ต้องรันจาก CLI แทน:<br>
      <code>php <?= htmlspecialchars($workerScript) ?> {job_id}</code>
    </div>
    <?php elseif (!$procOpenOk): ?>
    <div class="alert info" style="margin-top:10px;">
      <b>หมายเหตุ:</b> proc_open() ถูก disable — Python NLP (SentenceTransformer) ทำงานไม่ได้
      Worker จะ fallback เป็น PHP similarity ปกติแทน (ไม่รองรับภาษาไทยเต็มที่)
    </div>
    <?php endif; ?>
  </div>

  <!-- ══ SECTION 4: ทดสอบ HTTP Trigger (วิธีใหม่แทน spawn) ══ -->
  <div class="card">
    <h2>4. ทดสอบ HTTP Trigger (วิธีที่ใช้จริงบน Windows/Apache)</h2>
    <p style="font-size:13px;color:#6b7280;margin-top:0;">
      ระบบใหม่ใช้ HTTP fire-and-forget แทน exec() ดังนั้น exec ถูก disable ก็ไม่เป็นไร
    </p>
    <?php
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    $runUrl = $scheme . '://' . $host . $dir . '/auto_grade_run.php?job_id=0&token=test';
    $runFile = __DIR__ . DIRECTORY_SEPARATOR . 'auto_grade_run.php';
    ?>
    <table>
      <?= row('auto_grade_run.php', is_file($runFile) ? ok('พบแล้ว') : err('ไม่พบ! วางไฟล์ในโฟลเดอร์เดียวกัน')) ?>
      <?= row('Runner URL', '<code>' . htmlspecialchars($runUrl) . '</code>') ?>
      <?php
      // ทดสอบ fsockopen
      $fsockOk = function_exists('fsockopen') && !in_array('fsockopen', array_map('trim', explode(',', ini_get('disable_functions'))));
      $curlOk  = function_exists('curl_init');
      $urlFopenOk = ini_get('allow_url_fopen');
      echo row('fsockopen()',       $fsockOk   ? ok('ใช้ได้ — HTTP trigger จะทำงาน') : warn('ไม่พบ'));
      echo row('curl',              $curlOk    ? ok('ใช้ได้ — HTTP trigger fallback') : warn('ไม่พบ'));
      echo row('allow_url_fopen',   $urlFopenOk ? ok('เปิดอยู่ — HTTP trigger fallback') : warn('ปิดอยู่'));
      ?>
    </table>
    <?php if (!$fsockOk && !$curlOk && !$urlFopenOk): ?>
    <div class="alert danger" style="margin-top:10px;">
      <b>ปัญหา:</b> fsockopen, curl และ allow_url_fopen ไม่สามารถใช้ได้เลย<br>
      ไม่สามารถ trigger runner ผ่าน HTTP ได้<br>
      <b>วิธีแก้:</b> เปิด php.ini แล้วเปิด <code>allow_url_fopen = On</code> หรือเปิด curl extension
    </div>
    <?php else: ?>
    <div class="alert info" style="margin-top:10px;">
      ✅ HTTP trigger พร้อมทำงาน — exec() ที่ถูก disable ไม่ใช่ปัญหาอีกต่อไป
    </div>
    <?php endif; ?>

    <?php
    // ทดสอบ exec/python แยกต่างหาก (optional info)
    if ($execOk || $shellExecOk) {
      echo '<p style="font-size:13px;margin-top:12px;color:#6b7280;">ข้อมูลเพิ่มเติม (exec ยังใช้ได้ แต่ระบบไม่ได้ใช้แล้ว):</p>';
      if ($execOk) {
        $out = null;
        exec(escapeshellarg($phpBin) . ' -r "echo 42;" 2>&1', $outArr, $code);
        $out = implode('', $outArr);
        echo '<div style="font-size:12px;color:#6b7280;margin-bottom:4px;">';
        echo 'exec() test: ' . (trim($out) === '42' ? ok('PHP รันได้') : warn('รันไม่ได้: ' . htmlspecialchars($out)));
        echo '</div>';
      }
    }

    // ทดสอบ Python
    $pyTestOk = false;
    if ($execOk) {
      exec(escapeshellarg($PYTHON_CLI) . ' -c "print(1+1)" 2>&1', $pyArr);
      $pyOut = implode('', $pyArr);
      $pyTestOk = trim($pyOut) === '2';
    } elseif ($shellExecOk) {
      $pyOut = shell_exec(escapeshellarg($PYTHON_CLI) . ' -c "print(1+1)" 2>&1');
      $pyTestOk = trim((string)$pyOut) === '2';
    }
    if ($procOpenOk) {
      echo '<div style="font-size:13px;margin-top:6px;">';
      echo 'Python NLP: ' . ($pyTestOk ? ok($PYTHON_CLI . ' พบและรันได้ — NLP จะทำงาน') : warn($PYTHON_CLI . ' รันไม่ได้ — worker จะใช้ PHP similarity แทน'));
      echo '</div>';
    } else {
      echo '<div style="font-size:13px;margin-top:6px;">' . warn('proc_open() ถูก disable — Python NLP จะทำงานไม่ได้แม้ HTTP trigger จะใช้ได้') . '</div>';
    }
    ?>
  </div>

  <!-- ══ SECTION 5: DB + Job Status ══ -->
  <div class="card">
    <h2>5. Database & Job Status</h2>
    <?php
    $dbOk = false;
    $jobs = [];
    try {
      $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
      $conn->set_charset("utf8mb4");
      $dbOk = true;
      echo '<table>' . row('DB connection', ok('เชื่อมต่อได้ (' . $DB_NAME . ')')) . '</table>';

      // ตรวจว่ามีตาราง auto_grade_jobs
      $tbl = $conn->query("SHOW TABLES LIKE 'auto_grade_jobs'");
      if (!$tbl || $tbl->num_rows === 0) {
        echo '<p>' . err('ไม่พบตาราง auto_grade_jobs — กรุณากด "ตรวจอัตโนมัติ" อย่างน้อย 1 ครั้งก่อน') . '</p>';
      } else {
        $jobs = $conn->query("SELECT * FROM auto_grade_jobs ORDER BY id DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
        if (!$jobs) {
          echo '<p style="color:#6b7280;font-size:13px;">ไม่มี job ในตาราง auto_grade_jobs</p>';
        } else {
          echo '<table style="margin-top:10px;font-size:12px;">';
          echo '<tr style="background:#f9fafb;"><th style="padding:4px 10px;text-align:left;">id</th><th>attempt</th><th>status</th><th>items</th><th>message</th><th>error</th><th>created_at</th><th>started_at</th></tr>';
          foreach ($jobs as $j) {
            $statusColor = match($j['status']) {
              'done'    => '#15803d',
              'error'   => '#b91c1c',
              'running' => '#1d4ed8',
              default   => '#b45309',
            };
            echo '<tr style="border-top:1px solid #f3f4f6;">';
            echo '<td style="padding:4px 10px;">' . (int)$j['id'] . '</td>';
            echo '<td style="padding:4px 8px;">' . (int)$j['attempt_id'] . '</td>';
            echo '<td style="padding:4px 8px;color:' . $statusColor . ';font-weight:700;">' . htmlspecialchars($j['status']) . '</td>';
            echo '<td style="padding:4px 8px;">' . (int)$j['done_items'] . '/' . (int)$j['total_items'] . '</td>';
            echo '<td style="padding:4px 8px;max-width:200px;overflow:hidden;">' . htmlspecialchars(mb_strimwidth((string)($j['message'] ?? ''), 0, 60, '…')) . '</td>';
            echo '<td style="padding:4px 8px;max-width:200px;color:#b91c1c;overflow:hidden;">' . htmlspecialchars(mb_strimwidth((string)($j['last_error'] ?? ''), 0, 80, '…')) . '</td>';
            echo '<td style="padding:4px 8px;white-space:nowrap;">' . htmlspecialchars((string)($j['created_at'] ?? '-')) . '</td>';
            echo '<td style="padding:4px 8px;white-space:nowrap;">' . htmlspecialchars((string)($j['started_at'] ?? '-')) . '</td>';
            echo '</tr>';
          }
          echo '</table>';
        }
      }
    } catch(Throwable $e) {
      echo '<p>' . err('เชื่อมต่อ DB ไม่ได้: ' . htmlspecialchars($e->getMessage())) . '</p>';
    }
    ?>

    <!-- ปุ่มล้าง job ที่ค้าง -->
    <?php if ($dbOk && !empty($jobs)): ?>
    <form method="post" style="margin-top:10px;">
      <button class="btn btnGray" name="clear_stuck" type="submit"
        onclick="return confirm('ล้าง job ที่ค้าง (queued/running) ทั้งหมด?')">
        🗑 ล้าง Job ที่ค้าง
      </button>
    </form>
    <?php endif; ?>
  </div>

  <!-- ══ SECTION 6: รัน Worker ด้วยมือ ══ -->
  <?php
  $stuckJobs = array_filter($jobs ?? [], fn($j) => in_array($j['status'], ['queued','running']));
  ?>
  <?php if (!empty($stuckJobs)): ?>
  <div class="card">
    <h2>6. รัน Worker ด้วยมือ (สำหรับ Job ที่ค้าง)</h2>
    <p style="font-size:13px;color:#6b7280;">กรณีที่ exec() ใช้ได้ กดปุ่มนี้เพื่อรัน worker โดยตรง (อาจใช้เวลานาน — รอจนหน้าโหลดเสร็จ)</p>
    <?php foreach ($stuckJobs as $j): ?>
    <form method="post" style="display:inline-block;margin-right:8px;margin-bottom:8px;">
      <input type="hidden" name="job_id" value="<?= (int)$j['id'] ?>">
      <button class="btn" name="run_worker" type="submit">
        ▶ รัน Job #<?= (int)$j['id'] ?> (attempt #<?= (int)$j['attempt_id'] ?>)
      </button>
    </form>
    <?php endforeach; ?>

    <div class="alert info" style="margin-top:10px;">
      <b>หรือรัน CLI โดยตรง:</b><br>
      <?php foreach ($stuckJobs as $j): ?>
      <code>php <?= htmlspecialchars($workerScript) ?> <?= (int)$j['id'] ?></code><br>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══ SECTION 7: สรุปปัญหาและวิธีแก้ ══ -->
  <div class="card">
    <h2>7. สรุปปัญหาและวิธีแก้ไข</h2>
    <?php
    $hasSpawnMethod = $execOk || $shellExecOk || $popenOk;
    $hasWorker = is_file($workerScript);
    $hasPy = is_file($pyScript);
    ?>
    <?php
    $runFileOk   = is_file(__DIR__ . '/auto_grade_run.php');
    $httpTrigOk  = (function_exists('fsockopen') || function_exists('curl_init') || ini_get('allow_url_fopen'));
    ?>
    <table style="font-size:13px;">
      <?php if (!$hasWorker): ?>
      <?= row('🔴 auto_grade_worker.php หาย', 'วางไฟล์ในโฟลเดอร์เดียวกัน') ?>
      <?php endif; ?>
      <?php if (!$runFileOk): ?>
      <?= row('🔴 auto_grade_run.php หาย', 'วางไฟล์ใหม่ที่ได้รับในโฟลเดอร์เดียวกัน') ?>
      <?php endif; ?>
      <?php if (!$hasPy): ?>
      <?= row('🟡 auto_grade_nlp_stream.py หาย', 'Python NLP จะไม่ทำงาน — worker จะใช้ PHP similarity แทน') ?>
      <?php endif; ?>
      <?php if (!$httpTrigOk): ?>
      <?= row('🔴 HTTP trigger ไม่ได้', 'เปิด php.ini: <code>allow_url_fopen = On</code> หรือเปิด curl extension') ?>
      <?php else: ?>
      <?= row('🟢 HTTP trigger', 'fsockopen/curl/allow_url_fopen พร้อม — exec ที่ disable ไม่ใช่ปัญหา') ?>
      <?php endif; ?>
      <?php if (!$procOpenOk): ?>
      <?= row('🟡 proc_open ถูก disable', 'Python NLP ทำงานไม่ได้<br>แก้ php.ini: ลบ proc_open จาก disable_functions') ?>
      <?php endif; ?>
      <?php if ($hasWorker && $runFileOk && $httpTrigOk): ?>
      <?= row('✅ พร้อมทำงาน', 'ลองกดตรวจอัตโนมัติอีกครั้ง ถ้ายังค้างให้ดู Section 5 ว่า job เปลี่ยน status เป็น running ไหม') ?>
      <?php endif; ?>
    </table>
  </div>

  <p style="font-size:12px;color:#9ca3af;text-align:center;">⚠️ ลบ auto_grade_debug.php ออกหลังจาก debug เสร็จ</p>
</div>
</body>
</html>