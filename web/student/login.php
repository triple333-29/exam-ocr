<?php
session_name('USERSESS');
session_start();
require_once 'config.php';

function get_client_ip()
{
    foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) return trim(explode(',', $_SERVER[$k])[0]);
    }
    return 'UNKNOWN';
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        $st = $pdo->prepare("SELECT * FROM users WHERE username=?");
        $st->execute([$username]);
        $user = $st->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            if ($user['role'] === 'admin') {
                $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
            } else {
                $ip = get_client_ip();
                if (!$user['first_login_ip']) {
                    // username นี้ยังไม่เคย login — เช็คก่อนว่า IP นี้ถูกจอง username อื่นไว้หรือยัง
                    $stIp = $pdo->prepare(
                        "SELECT id FROM users WHERE first_login_ip=? AND id<>? LIMIT 1"
                    );
                    $stIp->execute([$ip, $user['id']]);
                    if ($stIp->fetch()) {
                        $error = 'อุปกรณ์นี้ถูกใช้งานด้วยบัญชีอื่นไปแล้ว ไม่สามารถเข้าสู่ระบบได้';
                    } else {
                        $pdo->prepare(
                            "UPDATE users SET first_login_ip=?, first_login_at=NOW() WHERE id=?"
                        )->execute([$ip, $user['id']]);
                    }
                } elseif ($user['first_login_ip'] !== $ip) {
                    $error = 'บัญชีนี้ไม่สามารถเข้าสู่ระบบจากอุปกรณ์นี้ได้';
                }

                if ($error === '') {
                    $_SESSION['user_id']   = $user['id'];
                    $_SESSION['username']  = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role']      = $user['role'];
                    header('Location: home.php');
                    exit;
                }
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
    <title>เข้าสู่ระบบ | Exam OCR</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        :root {
            --bg: #f5f7f6;
            /* ขาวนวล */
            --card: #ffffff;
            --border: #e5e7eb;
            --text: #111827;
            --muted: #6b7280;
            --accent: #0f766e;
            /* verdian */
            --danger: #dc2626;
            --radius: 12px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .card {
            width: 100%;
            max-width: 420px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 22px 22px 24px;
        }

        h1 {
            margin: 0 0 6px;
            font-size: 24px;
            font-weight: 800;
            color: var(--accent);
            text-align: center;
        }

        .sub {
            text-align: center;
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 18px;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .input {
            width: 100%;
            padding: 11px 12px;
            border-radius: 10px;
            border: 1px solid var(--border);
            font-size: 14px;
            outline: none;
            margin-bottom: 14px;
        }

        .input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(15, 118, 110, .15);
        }

        .btn {
            width: 100%;
            border: 0;
            border-radius: 10px;
            padding: 12px;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            background: var(--accent);
            color: #fff;
        }

        .btn:hover {
            filter: brightness(.95);
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 13px;
            margin-bottom: 14px;
        }

        .footer {
            margin-top: 14px;
            text-align: center;
            font-size: 13px;
            color: var(--muted);
        }

        .footer a {
            color: var(--accent);
            font-weight: 700;
            text-decoration: none;
        }

        .footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>

    <div class="card">
        <h1>Exam OCR</h1>
        <div class="sub">เข้าสู่ระบบเพื่อทำข้อสอบ</div>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="on">
            <label for="username">ชื่อผู้ใช้</label>
            <input class="input" id="username" name="username" type="text" autocomplete="username">

            <label for="password">รหัสผ่าน</label>
            <input class="input" id="password" name="password" type="password" autocomplete="current-password">

            <button class="btn" type="submit">เข้าสู่ระบบ</button>
        </form>

        <!-- <div class="footer">
        ผู้ดูแลระบบ? <a href="admin_login.php">เข้าสู่ระบบ Admin</a>
    </div> -->
    </div>

</body>

</html>