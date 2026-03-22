<?php
session_name('USERSESS'); // ใช้ session ของ student/user
session_start();

session_unset();
session_destroy();

header('Location: login.php');
exit;