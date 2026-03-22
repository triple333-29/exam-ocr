<?php
session_name('TEACHERSESS');
session_start();
require_once 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {

            if ($user['role'] !== 'teacher') {
                $error = 'บัญชีนี้ไม่ใช่สิทธิ์อาจารย์';
            } else {
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role']      = $user['role']; // teacher

                header('Location: teacher_home.php');
                exit;
            }

        } else {
            $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>เข้าสู่ระบบอาจารย์ - Exam OCR</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            background: #0f172a;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .login-box {
            background: #ffffff;
            padding: 24px 32px;
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(15,23,42,0.45);
            width: 340px;
        }
        h1 {
            margin: 0 0 16px;
            font-size: 20px;
            text-align: center;
        }
        .form-group {
            margin-bottom: 12px;
        }
        label {
            display: block;
            font-size: 14px;
            margin-bottom: 4px;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 8px 10px;
            border-radius: 6px;
            border: 1px solid #d1d5db;
            font-size: 14px;
        }
        button {
            width: 100%;
            padding: 10px;
            border-radius: 6px;
            border: none;
            background: #16a34a;
            color: white;
            font-size: 14px;
            cursor: pointer;
            margin-top: 4px;
        }
        button:hover {
            background: #15803d;
        }
        .error {
            color: #b91c1c;
            font-size: 13px;
            margin-bottom: 8px;
            text-align: center;
        }
    </style>
</head>
<body>
<div class="login-box">
    <h1>เข้าสู่ระบบอาจารย์</h1>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="form-group">
            <label for="username">ชื่อผู้ใช้</label>
            <input type="text" name="username" id="username">
        </div>
        <div class="form-group">
            <label for="password">รหัสผ่าน</label>
            <input type="password" name="password" id="password">
        </div>
        <button type="submit">เข้าสู่ระบบ</button>
    </form>
</div>
</body>
</html>
