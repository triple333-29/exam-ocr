<?php
header('Content-Type: application/json');
$targetDir = "uploads/";
if (!file_exists($targetDir)) mkdir($targetDir, 0777, true);

$allowedTypes = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'video/mp4',
    'video/webm',
    'audio/mpeg',
    'audio/wav',
    'audio/mp3'
];

if (isset($_FILES['file'])) {
    $fileType = $_FILES['file']['type'];

    if (!in_array($fileType, $allowedTypes)) {
        echo json_encode(['success' => false, 'message' => 'ไฟล์ประเภทนี้ไม่ได้รับอนุญาต']);
        exit;
    }

    $fileName = time() . '_' . basename($_FILES['file']['name']);
    $targetFilePath = $targetDir . $fileName;

    if (move_uploaded_file($_FILES['file']['tmp_name'], $targetFilePath)) {
        echo json_encode(['success' => true, 'filePath' => $targetFilePath]);
    } else {
        echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการบันทึกไฟล์']);
    }
}
