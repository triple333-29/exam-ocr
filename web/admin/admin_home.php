<?php
session_name('ADMINSESS');
session_start();
require_once 'config.php'; 
// ต้องเป็น admin เท่านั้น
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: admin_login.php');
    exit;
}

$full_name = $_SESSION['full_name'] ?? $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>หน้าผู้ดูแลระบบ - Exam OCR</title>
    <style>
        body {
            font-family: sans-serif;
            margin: 0;
            background: #f3f4f6;
        }
        header {
            background: #0f172a;
            color: white;
            padding: 12px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        main {
            padding: 20px;
        }
        a.logout, a.link {
            color: #38bdf8;
            text-decoration: none;
            font-size: 14px;
        }
        a.logout {
            color: #f97316;
        }
    </style>
</head>
<body>
<header>
    <div>Exam OCR - Admin Panel</div>
    <div>
        สวัสดี, <?= htmlspecialchars($full_name) ?>
        | <a class="link" href="manage_users.php">จัดการผู้ใช้</a>
        | <a class="logout" href="admin_logout.php">ออกจากระบบ</a>
    </div>
</header>

<main>
    <h1>หน้าผู้ดูแลระบบ</h1>
    <p>จากหน้านี้แอดมินสามารถจัดการระบบ เช่น reset IP ผู้ใช้, ดูรายการข้อสอบ, ฯลฯ</p>
</main>
</body>
</html>