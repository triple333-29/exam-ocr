<?php
session_name('USERSESS');
session_start();
require_once 'config.php';

// ต้องล็อกอิน และต้องเป็น user
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') === 'admin') {
    header('Location: login.php');
    exit;
}

$full_name  = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'User');
$examId     = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;
$attemptId  = isset($_GET['attempt_id']) ? (int)$_GET['attempt_id'] : 0;

// โหลดข้อมูลข้อสอบ (ไว้โชว์ชื่อข้อสอบบนหน้า)
$examTitle = 'ข้อสอบ';
if ($examId > 0) {
    $st = $pdo->prepare("SELECT title FROM exams WHERE id = ? LIMIT 1");
    $st->execute([$examId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['title'])) $examTitle = $row['title'];
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8" />
    <title>บันทึกคำตอบเรียบร้อย - <?= htmlspecialchars($examTitle) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        :root {
            --bg: #eef2f1;
            --card: #ffffff;
            --text: #0f172a;
            --muted: #6b7280;
            --border: #e5e7eb;
            --accent: #0f766e;
            --shadow: 0 18px 40px rgba(2, 6, 23, .06);
            --radius: 16px;
        }

        * {
            box-sizing: border-box
        }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        header {
            background: transparent;
            padding: 14px 18px 0
        }

        .topbar {
            max-width: 1280px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #0b3a2a
        }

        .dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: var(--accent);
            box-shadow: 0 0 0 6px rgba(15, 118, 110, .12);
        }

        .user {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #0b3a2a;
            font-weight: 600
        }

        .logout {
            text-decoration: none;
            color: #0b3a2a;
            font-weight: 650;
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: rgba(255, 255, 255, .7);
        }

        .logout:hover {
            border-color: rgba(15, 118, 110, .35);
            box-shadow: 0 0 0 4px rgba(15, 118, 110, .10);
        }

        main {
            max-width: 1280px;
            margin: 0 auto;
            padding: 8px 18px 28px;
        }

        .wrap {
            min-height: calc(100vh - 80px);
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding-top: 18px;
        }

        .card {
            width: min(980px, 100%);
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 26px 28px;
        }

        .title {
            margin: 0 0 6px;
            font-size: 28px;
            color: var(--accent);
            letter-spacing: .2px;
        }

        .subtitle {
            margin: 0 0 14px;
            color: var(--muted)
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            border-radius: 999px;
            background: rgba(15, 118, 110, .10);
            border: 1px solid rgba(15, 118, 110, .22);
            color: #064e3b;
            font-weight: 700;
            margin: 6px 0 14px;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 14px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: #fff;
            color: #0b3a2a;
            text-decoration: none;
            font-weight: 700;
        }

        .btn.primary {
            background: var(--accent);
            color: #fff;
            border-color: rgba(15, 118, 110, .55);
        }

        .btn:hover {
            box-shadow: 0 0 0 4px rgba(15, 118, 110, .10);
            border-color: rgba(15, 118, 110, .35)
        }

        .meta {
            margin-top: 10px;
            color: var(--muted);
            font-size: 14px
        }

        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace
        }

        @media (max-width: 520px) {
            .card {
                padding: 20px;
            }

            .actions .btn {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <header>
        <div class="topbar">
            <div class="brand">
                <span class="dot"></span>
                <div>
                    <div style="font-weight:800;letter-spacing:.2px">Exam System</div>
                </div>
            </div>
            <div class="user">
                <span><?= htmlspecialchars($full_name) ?></span>
                <a class="logout" href="logout.php">ออกจากระบบ</a>
            </div>
        </div>
    </header>

    <main>
        <div class="wrap">
            <div class="card">
                <h1 class="title">บันทึกคำตอบเรียบร้อยแล้ว</h1>
                <p class="subtitle">ระบบได้บันทึกคำตอบของคุณสำเร็จ คุณสามารถออกจากหน้านี้ได้เลย</p>

                <div class="badge">✓ คำตอบของคุณถูกบันทึกไว้แล้ว</div>

                <div class="meta">
                    ข้อสอบ: <strong><?= htmlspecialchars($examTitle) ?></strong><br>
                    รหัสการส่ง: <span class="mono"><?= (int)$attemptId ?></span>
                </div>
            </div>
        </div>
    </main>
</body>

</html>