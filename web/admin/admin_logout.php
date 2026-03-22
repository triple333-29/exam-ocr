<?php
session_name('ADMINSESS'); // ใช้ session ของ admin
session_start();

session_unset();
session_destroy();

header('Location: admin_login.php');
exit;