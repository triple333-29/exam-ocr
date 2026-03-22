<?php
session_name('USERSESS');
session_start();
require_once 'config.php';

// ต้องล็อกอิน และต้องเป็น user
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') === 'admin') {
    header('Location: login.php');
    exit;
}

$full_name = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'User');

$error = '';
$accessCode = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accessCode = trim($_POST['access_code'] ?? '');

    if ($accessCode === '') {
        $error = 'กรุณากรอกรหัสข้อสอบ';
    } else {
        // ตรวจว่ามีข้อสอบจริงไหม (optional แต่แนะนำ)
        $st = $pdo->prepare("SELECT id FROM exams WHERE access_code = :code LIMIT 1");
        $st->execute([':code' => $accessCode]);
        $exam = $st->fetch(PDO::FETCH_ASSOC);

        if (!$exam) {
            $error = 'ไม่พบข้อสอบตามรหัสที่กรอก กรุณาตรวจสอบอีกครั้ง';
        } else {
            header('Location: exam_take.php?access_code=' . urlencode($accessCode));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>หน้าหลักผู้ใช้ - Exam OCR</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root{
            --bg: #eef2f1;          /* พื้นหลังเทาอมเขียวแบบรูป */
            --card: #ffffff;
            --text: #0f172a;
            --muted: #6b7280;
            --border: #e5e7eb;
            --accent: #0f766e;      /* verdian/teal */
            --accent2:#10b981;      /* ปุ่มเขียว */
            --danger-bg:#fee2e2;
            --danger-text:#991b1b;
            --shadow: 0 18px 40px rgba(2, 6, 23, .06);
            --radius: 16px;
        }

        *{ box-sizing: border-box; }
        body{
            margin:0;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        /* top bar */
        header{
            background: transparent;
            padding: 18px 18px 6px;
        }
        .topbar{
            max-width: 980px;
            margin: 0 auto;
            display:flex;
            justify-content: space-between;
            align-items: center;
        }
        .brand{
            display:flex;
            gap:10px;
            align-items: center;
        }
        .dot{
            width:10px;height:10px;border-radius:999px;
            background: var(--accent);
            box-shadow: 0 0 0 6px rgba(15,118,110,.10);
        }
        .brand strong{
            font-size: 14px;
            letter-spacing: .2px;
            color:#0b3a2a;
        }
        .user{
            font-size: 13px;
            color: var(--muted);
            display:flex;
            gap:10px;
            align-items:center;
        }
        .logout{
            text-decoration:none;
            color: #0b3a2a;
            font-weight: 650;
            padding: 8px 12px;
            border-radius: 999px;
            border:1px solid var(--border);
            background: rgba(255,255,255,.7);
        }
        .logout:hover{
            border-color: rgba(15,118,110,.35);
            box-shadow: 0 0 0 4px rgba(15,118,110,.10);
        }

        main{
            max-width: 980px;
            margin: 0 auto;
            padding: 10px 18px 28px;
        }

        h1{
            margin: 10px 0 14px;
            font-size: 32px;
            letter-spacing: .2px;
            color: var(--accent);
            font-weight: 800;
        }

        /* cards */
        .card{
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .card-head{
            padding: 18px 18px 12px;
            border-bottom: 1px solid rgba(229,231,235,.7);
        }
        .card-title{
            margin:0;
            font-size: 16px;
            font-weight: 750;
        }
        .card-sub{
            margin-top:6px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.4;
        }

        /* accent bar like the screenshot (left border) */
        .accent-wrap{
            position: relative;
        }
        .accent-wrap::before{
            content:"";
            position:absolute;
            left:0; top:0; bottom:0;
            width: 5px;
            background: var(--accent);
            border-top-left-radius: var(--radius);
            border-bottom-left-radius: var(--radius);
        }

        .card-body{
            padding: 16px 18px 18px;
        }

        label{
            display:block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 8px;
            color:#111827;
        }

        .input{
            width: 100%;
            padding: 12px 12px;
            border-radius: 12px;
            border: 1px solid #d1d5db;
            background: #fff;
            font-size: 14px;
            outline: none;
        }
        .input:focus{
            border-color: rgba(15,118,110,.55);
            box-shadow: 0 0 0 4px rgba(15,118,110,.14);
        }

        .actions{
            margin-top: 14px;
            display:flex;
            justify-content: flex-end; /* ปุ่มชิดขวาเหมือนรูป */
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn{
            border: 1px solid var(--border);
            background: #fff;
            padding: 10px 14px;
            border-radius: 12px;
            font-weight: 750;
            cursor: pointer;
            font-size: 14px;
        }
        .btn:hover{
            border-color: rgba(15,118,110,.35);
            box-shadow: 0 0 0 4px rgba(15,118,110,.10);
        }

        .btn-primary{
            border-color: transparent;
            background: var(--accent);
            color: #fff;
            padding: 10px 16px;
        }
        .btn-primary:hover{
            filter: brightness(.95);
            box-shadow: 0 0 0 6px rgba(15,118,110,.12);
        }

        .error{
            margin-top: 12px;
            padding: 10px 12px;
            border-radius: 12px;
            background: var(--danger-bg);
            color: var(--danger-text);
            border: 1px solid #fecaca;
            font-size: 13px;
        }

        .hint{
            margin-top: 10px;
            color: var(--muted);
            font-size: 12.5px;
        }
        .badge{
            display:inline-block;
            padding: 2px 10px;
            border-radius: 999px;
            background: #f3f4f6;
            border: 1px solid var(--border);
            color:#374151;
            font-size: 12px;
        }

        /* ===== Logout Confirm Modal ===== */
        .modal-backdrop{
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .45);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 18px;
            z-index: 9999;
        }
        .modal-backdrop.show{ display:flex; }

        .modal{
            width: min(440px, 100%);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: 0 18px 40px rgba(2, 6, 23, .12);
            overflow: hidden;
        }
        .modal-head{
            padding: 16px 16px 10px;
            border-bottom: 1px solid rgba(229,231,235,.75);
        }
        .modal-title{
            margin:0;
            font-size: 16px;
            font-weight: 800;
            color: var(--text);
        }
        .modal-body{
            padding: 12px 16px 6px;
            color: var(--muted);
            font-size: 13.5px;
            line-height: 1.55;
        }
        .modal-actions{
            padding: 12px 16px 16px;
            display:flex;
            justify-content:flex-end;
            gap:10px;
        }
        .btn-danger{
            border-color: transparent;
            background: #dc2626;
            color: #fff;
        }
        .btn-danger:hover{
            filter: brightness(.95);
            box-shadow: 0 0 0 6px rgba(220,38,38,.12);
        }
        /* ===== End Modal ===== */
    </style>
</head>
<body>

<header>
    <div class="topbar">
        <div class="brand">
            <span class="dot"></span>
            <strong>Exam OCR - Student</strong>
        </div>
        <div class="user">
            <span>สวัสดี, <?= htmlspecialchars($full_name) ?></span>
            <!-- เพิ่ม id เพื่อดักคลิกแล้วเปิด modal -->
            <a class="logout" href="logout.php" id="logoutLink">ออกจากระบบ</a>
        </div>
    </div>
</header>

<main>
    <h1>ทำข้อสอบ</h1>

    <div class="card accent-wrap">
        <div class="card-head">
            <p class="card-title">เข้าทำข้อสอบด้วยรหัสข้อสอบ</p>
            <div class="card-sub">
                กรอกรหัสข้อสอบที่ได้รับ แล้วกด “เริ่มทำข้อสอบ”
            </div>
        </div>

        <div class="card-body">
            <form method="post" autocomplete="off">
                <label for="access_code">รหัสข้อสอบ</label>
                <input
                    id="access_code"
                    class="input"
                    type="text"
                    name="access_code"
                    placeholder="เช่น ABC123"
                    value="<?= htmlspecialchars($accessCode) ?>"
                >

                <?php if ($error): ?>
                    <div class="error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="actions">
                    <button class="btn btn-primary" type="submit">เริ่มทำข้อสอบ</button>
                </div>

            </form>
        </div>
    </div>

</main>

<!-- ===== Logout Confirm Modal (เพิ่มใหม่) ===== -->
<div class="modal-backdrop" id="logoutModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="logoutTitle">
        <div class="modal-head">
            <p class="modal-title" id="logoutTitle">ยืนยันการออกจากระบบ</p>
        </div>
        <div class="modal-body">
            คุณต้องการออกจากระบบใช่ไหม? <br>
        </div>
        <div class="modal-actions">
            <button class="btn" type="button" id="logoutCancel">ยกเลิก</button>
            <button class="btn btn-danger" type="button" id="logoutConfirm">ออกจากระบบ</button>
        </div>
    </div>
</div>

<script>
(function(){
    const logoutLink = document.getElementById('logoutLink');
    const modal = document.getElementById('logoutModal');
    const btnCancel = document.getElementById('logoutCancel');
    const btnConfirm = document.getElementById('logoutConfirm');

    if (!logoutLink || !modal || !btnCancel || !btnConfirm) return;

    const openModal = () => {
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        btnCancel.focus();
    };

    const closeModal = () => {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        logoutLink.focus();
    };

    logoutLink.addEventListener('click', function(e){
        e.preventDefault();
        openModal();
    });

    btnCancel.addEventListener('click', closeModal);

    btnConfirm.addEventListener('click', function(){
        // ไปหน้า logout.php จริง
        window.location.href = logoutLink.href;
    });

    // คลิกนอกกล่องเพื่อปิด
    modal.addEventListener('click', function(e){
        if (e.target === modal) closeModal();
    });

    // กด ESC เพื่อปิด
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && modal.classList.contains('show')) {
            closeModal();
        }
    });
})();
</script>
<!-- ===== End Modal ===== -->

</body>
</html>