<?php
session_name('TEACHERSESS');
session_start();
require_once 'config.php';

header('Content-Type: application/json');

// ปิด Error Report เพื่อไม่ให้ PHP Warning ปนไปกับ JSON
ini_set('display_errors', '0');
error_reporting(0);

//ตรวจสอบสิทธิ์ (ต้องเป็น Teacher)
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access']);
    exit;
}

// ตรวจสอบ Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Request Method']);
    exit;
}

try {
    // รับข้อมูล JSON
    $inputJSON = file_get_contents('php://input');
    $inputData = json_decode($inputJSON, true);

    if (!isset($inputData['exam_id'])) {
        throw new Exception('ไม่พบรหัสข้อสอบ (exam_id)');
    }

    $examId = $inputData['exam_id'];
    $userId = $_SESSION['user_id'];

    $pdo->beginTransaction();

    // ตรวจสอบความเป็นเจ้าของ (สำคัญมาก ป้องกันการลบของคนอื่น)
    $stmtCheck = $pdo->prepare("SELECT id FROM exams WHERE id = ? AND created_by = ?");
    $stmtCheck->execute([$examId, $userId]);
    if (!$stmtCheck->fetch()) {
        throw new Exception('คุณไม่มีสิทธิ์ลบข้อสอบนี้');
    }

    // เริ่มลบข้อมูลตามลำดับ (ลบลูกก่อน -> ลบแม่)

    // หา Question IDs ทั้งหมดก่อน เพื่อไปลบ Choice/SubQuestions
    $stmtGetQ = $pdo->prepare("SELECT id FROM questions WHERE exam_id = ?");
    $stmtGetQ->execute([$examId]);
    $questionIds = $stmtGetQ->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($questionIds)) {
        $inQuery = implode(',', array_fill(0, count($questionIds), '?'));

        // ลบ Choices
        $sqlDelChoice = "DELETE FROM choices WHERE question_id IN ($inQuery)";
        $pdo->prepare($sqlDelChoice)->execute($questionIds);

        // ลบ Sub Questions
        $sqlDelSub = "DELETE FROM sub_questions WHERE question_id IN ($inQuery)";
        $pdo->prepare($sqlDelSub)->execute($questionIds);
    }

    // ลบ Questions (คำถาม)
    $pdo->prepare("DELETE FROM questions WHERE exam_id = ?")->execute([$examId]);

    // ลบ Sections (ตอน/ส่วน)
    $pdo->prepare("DELETE FROM exam_sections WHERE exam_id = ?")->execute([$examId]);

    // ลบ Exam (ตัวข้อสอบหลัก)
    $pdo->prepare("DELETE FROM exams WHERE id = ?")->execute([$examId]);

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => 'ลบข้อสอบเรียบร้อยแล้ว']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
