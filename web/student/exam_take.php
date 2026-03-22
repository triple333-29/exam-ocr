<?php
session_name('USERSESS');
session_start();
date_default_timezone_set('Asia/Bangkok');
require_once 'config.php';

// ต้องล็อกอิน และต้องเป็น user
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') === 'admin') {
    header('Location: login.php');
    exit;
}

$full_name = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'User');
$accessCode = trim($_GET['access_code'] ?? '');
$examId = isset($_GET['exam_id']) ? (int) $_GET['exam_id'] : 0;

// หา exam จาก access_code หรือ exam_id
$exam = null;
if ($accessCode !== '') {
    $st = $pdo->prepare("SELECT * FROM exams WHERE access_code = ? LIMIT 1");
    $st->execute([$accessCode]);
    $exam = $st->fetch(PDO::FETCH_ASSOC);
    if ($exam)
        $examId = (int) $exam['id'];
} else if ($examId > 0) {
    $st = $pdo->prepare("SELECT * FROM exams WHERE id = ? LIMIT 1");
    $st->execute([$examId]);
    $exam = $st->fetch(PDO::FETCH_ASSOC);
    if ($exam)
        $accessCode = (string) ($exam['access_code'] ?? '');
}

if (!$exam || $examId <= 0) {
    http_response_code(404);
    die("ไม่พบข้อสอบ");
}

/**
 * Helpers: ตรวจชื่อคอลัมน์/ตารางให้ยืดหยุ่น
 */
function table_exists(PDO $pdo, string $t): bool
{
    $st = $pdo->prepare("SHOW TABLES LIKE :t");
    $st->execute([':t' => $t]);
    return (bool) $st->fetchColumn();
}
function col_exists(PDO $pdo, string $table, string $col): bool
{
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
    $st->execute([':c' => $col]);
    return (bool) $st->fetchColumn();
}
function pick_column(PDO $pdo, string $table, array $cands): ?string
{
    foreach ($cands as $c) {
        if (col_exists($pdo, $table, $c))
            return $c;
    }
    return null;
}

/**
 * render_question_html()
 * - ถ้า content มี HTML tag → render เป็น HTML จริง (ไม่ escape)
 * - เขียน path ของ src ใน <img>, <video>, <audio>, <source>
 *   ให้ชี้ไปที่ ../teacher/uploads/ (จาก student/ folder)
 * - ป้องกัน XSS โดยกรอง tag/attribute อันตรายออก
 */
function render_question_html(string $html): string
{
    if ($html === '') return '';

    // path ไปยัง uploads ของฝั่ง teacher (relative จาก student/)
    $teacherUploads = '../teacher/uploads/';

    // rewrite src ของ media elements ที่เป็น path local ให้ชี้ไปที่ teacher/uploads/
    $html = preg_replace_callback(
        '/(<(?:img|video|audio|source)[^>]*?\s)src=(["\'])([^"\']*)\2/i',
        function ($m) use ($teacherUploads) {
            $tag   = $m[1];
            $quote = $m[2];
            $src   = $m[3];

            // URL ภายนอก (http/https/protocol-relative) → ไม่แตะ
            if (preg_match('/^(?:https?:)?\/\//i', $src)) {
                return $m[0];
            }
            // ชี้ไปที่ teacher/uploads อยู่แล้ว → ไม่แตะ
            if (strpos($src, '../teacher/') !== false) {
                return $m[0];
            }
            // เอาเฉพาะชื่อไฟล์ (ตัด path prefix ออก) แล้วต่อกับ teacherUploads
            $filename = basename($src);
            return $tag . 'src=' . $quote . $teacherUploads . htmlspecialchars($filename, ENT_QUOTES) . $quote;
        },
        $html
    );

    // กรอง tag/attribute อันตราย (script, on*, style inline ที่มี expression ฯลฯ)
    // อนุญาต: p, br, b, strong, i, em, u, s, ul, ol, li, h1-h6,
    //          table, thead, tbody, tr, th, td, pre, code, blockquote,
    //          img, video, audio, source, figure, figcaption, div, span, a
    $html = preg_replace('/<script[\s\S]*?<\/script>/i', '', $html);
    $html = preg_replace('/<style[\s\S]*?<\/style>/i', '', $html);
    $html = preg_replace('/\bon\w+\s*=\s*["\'][^"\']*["\']/i', '', $html);   // on* handlers
    $html = preg_replace('/\bon\w+\s*=[^\s>]*/i', '', $html);

    return $html;
}

$sectionTable = table_exists($pdo, 'exam_sections') ? 'exam_sections' : (table_exists($pdo, 'exam_section') ? 'exam_section' : null);
$questionTable = table_exists($pdo, 'questions') ? 'questions' : null;
$choiceTable = table_exists($pdo, 'choices') ? 'choices' : null;

if (!$questionTable)
    die("ไม่พบตาราง questions");

$secTitleCol = $sectionTable ? (pick_column($pdo, $sectionTable, ['section_title', 'title', 'name']) ?? null) : null;
$secOrderCol = $sectionTable ? (pick_column($pdo, $sectionTable, ['section_order', 'order_no', 'sort_order', 'order']) ?? null) : null;

$qTextCol = pick_column($pdo, $questionTable, ['question', 'question_text', 'text']) ?? 'question';
$qTypeCol = pick_column($pdo, $questionTable, ['type', 'question_type']) ?? 'type';
$qSecCol = pick_column($pdo, $questionTable, ['section_id', 'exam_section_id']) ?? 'section_id';
$qScoreCol = pick_column($pdo, $questionTable, ['score', 'points', 'mark', 'marks']) ?? null;
$qAnswerCol = pick_column($pdo, $questionTable, ['answer', 'correct_answer', 'expected_answer', 'answer_key']) ?? null;

$cTextCol = $choiceTable ? (pick_column($pdo, $choiceTable, ['choice_text', 'text', 'title']) ?? 'choice_text') : null;

// sections
$sections = [];
if ($sectionTable) {
    $secTitleSelect = $secTitleCol ? $secTitleCol : "'Section' AS section_title";
    $secOrderSelect = $secOrderCol ? $secOrderCol : "0 AS section_order";
    $sqlS = "
        SELECT id, exam_id, {$secOrderSelect} AS section_order, {$secTitleSelect} AS section_title
        FROM {$sectionTable}
        WHERE exam_id = :exam_id
        ORDER BY section_order ASC, id ASC
    ";
    $stS = $pdo->prepare($sqlS);
    $stS->execute([':exam_id' => $examId]);
    $sections = $stS->fetchAll(PDO::FETCH_ASSOC);
}

// questions
$scoreSelect = $qScoreCol ? ", {$qScoreCol} AS score" : ", 1 AS score";
$answerSelect = $qAnswerCol ? ", {$qAnswerCol} AS answer" : ", NULL AS answer";

$sqlQ = "
    SELECT id, exam_id,
           {$qSecCol} AS section_id,
           {$qTextCol} AS question,
           {$qTypeCol} AS type
           {$scoreSelect}
           {$answerSelect}
    FROM {$questionTable}
    WHERE exam_id = :exam_id
    ORDER BY section_id ASC, id ASC
";
$stQ = $pdo->prepare($sqlQ);
$stQ->execute([':exam_id' => $examId]);
$questions = $stQ->fetchAll(PDO::FETCH_ASSOC);

// --- Randomization seed: ทำให้ลำดับที่สุ่ม "คงที่" ระหว่างทำข้อสอบ (ภายใน session) ---
$shuffleSeedKey = 'shuffle_seed_exam_' . $examId;
if (!isset($_SESSION[$shuffleSeedKey])) {
    try {
        $_SESSION[$shuffleSeedKey] = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        // fallback กรณี PHP เก่า/ไม่มี random_bytes()
        $_SESSION[$shuffleSeedKey] = uniqid('', true);
    }
}
$shuffleSeed = (string) $_SESSION[$shuffleSeedKey];


// choices
$choicesByQuestion = [];
if ($choiceTable && !empty($questions)) {
    $ids = implode(',', array_map('intval', array_column($questions, 'id')));
    $sqlC = "
        SELECT id, question_id, {$cTextCol} AS choice_text, is_correct
        FROM {$choiceTable}
        WHERE question_id IN ($ids)
        ORDER BY id ASC
    ";
    $stC = $pdo->query($sqlC);
    $choices = $stC->fetchAll(PDO::FETCH_ASSOC);
    foreach ($choices as $c) {
        $qid = (int) $c['question_id'];
        $choicesByQuestion[$qid][] = $c;
    }

    // ✅ สุ่มลำดับตัวเลือกภายในข้อ (คงที่ตาม seed ใน session)
    foreach ($choicesByQuestion as $qid => &$opts) {
        usort($opts, function ($a, $b) use ($shuffleSeed, $qid) {
            $ka = hash('sha256', $shuffleSeed . '|c|' . (int) $qid . '|' . (int) ($a['id'] ?? 0));
            $kb = hash('sha256', $shuffleSeed . '|c|' . (int) $qid . '|' . (int) ($b['id'] ?? 0));
            return strcmp($ka, $kb);
        });
    }
    unset($opts);
}

// ── sub_questions: ดึงโจทย์ย่อยของทุก question ในข้อสอบนี้ ──────────────────
$subsByQuestion = []; // [ question_id => [ ['id'=>, 'sub_question'=>, 'sub_answer'=>], ... ] ]
if (!empty($questions) && table_exists($pdo, 'sub_questions')) {
    $qids = implode(',', array_map('intval', array_column($questions, 'id')));
    $stSub = $pdo->query("
        SELECT id, question_id, sub_question, sub_answer
        FROM sub_questions
        WHERE question_id IN ($qids)
        ORDER BY id ASC
    ");
    foreach ($stSub->fetchAll(PDO::FETCH_ASSOC) as $sub) {
        $subsByQuestion[(int) $sub['question_id']][] = $sub;
    }

    // ✅ สุ่มลำดับโจทย์ย่อยภายในข้อ (คงที่ตาม seed ใน session)
    foreach ($subsByQuestion as $qid => &$subs) {
        usort($subs, function ($a, $b) use ($shuffleSeed, $qid) {
            $ka = hash('sha256', $shuffleSeed . '|sub|' . (int) $qid . '|' . (int) ($a['id'] ?? 0));
            $kb = hash('sha256', $shuffleSeed . '|sub|' . (int) $qid . '|' . (int) ($b['id'] ?? 0));
            return strcmp($ka, $kb);
        });
    }
    unset($subs);
}

// group questions by section
$sectionsMap = [];
foreach ($sections as $s) {
    $sid = (int) $s['id'];
    $sectionsMap[$sid] = [
        'id' => $sid,
        'title' => $s['section_title'] ?: 'Section',
        'order' => (int) ($s['section_order'] ?? 0),
        'questions' => []
    ];
}
if (empty($sectionsMap)) {
    $sectionsMap[0] = ['id' => 0, 'title' => 'ข้อสอบ', 'order' => 0, 'questions' => []];
}
foreach ($questions as $q) {
    $sid = isset($q['section_id']) ? (int) $q['section_id'] : 0;
    if (!isset($sectionsMap[$sid])) {
        $sectionsMap[$sid] = ['id' => $sid, 'title' => 'Section', 'order' => 0, 'questions' => []];
    }
    $sectionsMap[$sid]['questions'][] = $q;
}
$sectionsFinal = array_values($sectionsMap);
usort($sectionsFinal, fn($a, $b) => $a['order'] <=> $b['order']);

// ✅ สุ่มลำดับข้อ "ภายใน section เดียวกันเท่านั้น" (คงที่ตาม seed ใน session)
foreach ($sectionsFinal as &$s) {
    $sid = (int) ($s['id'] ?? 0);
    if (!empty($s['questions'])) {
        usort($s['questions'], function ($a, $b) use ($shuffleSeed, $sid) {
            $ka = hash('sha256', $shuffleSeed . '|q|' . (int) $sid . '|' . (int) ($a['id'] ?? 0));
            $kb = hash('sha256', $shuffleSeed . '|q|' . (int) $sid . '|' . (int) ($b['id'] ?? 0));
            return strcmp($ka, $kb);
        });
    }
}
unset($s);


// เตรียมข้อมูลสำหรับ Navigation (เลขข้อภายในแต่ละ section)
$navSections = [];
$__qno = 0;
foreach ($sectionsFinal as $s) {
    $sid = (int) ($s['id'] ?? 0);
    $qs = [];
    foreach (($s['questions'] ?? []) as $q) {
        $__qno++;
        $qs[] = [
            'no' => $__qno,
            'qid' => (int) ($q['id'] ?? 0),
        ];
    }
    $navSections[] = [
        'id' => $sid,
        'title' => (string) ($s['title'] ?? 'Section'),
        'count' => count($qs),
        'questions' => $qs
    ];
}


// --- submit logic (เหมือนที่คุณบอกว่า logic ถูกต้องแล้ว) ---
function get_client_ip(): string
{
    foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $v = (string) $_SERVER[$k];
            if (strpos($v, ',') !== false)
                $v = trim(explode(',', $v)[0]);
            return $v;
        }
    }
    return '0.0.0.0';
}
function normalize_answer(?string $s): string
{
    $s = trim((string) $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}
function check_text_correct(?string $given, ?string $expected): ?int
{
    $expected = trim((string) ($expected ?? ''));
    if ($expected === '')
        return null;
    $g = normalize_answer($given);
    if ($g === '')
        return 0;

    $parts = preg_split("/\r\n|\r|\n|\||,|;/", $expected);
    $cands = [];
    foreach ($parts as $p) {
        $p = normalize_answer($p);
        if ($p !== '')
            $cands[] = $p;
    }
    if (!$cands)
        return null;
    return in_array($g, $cands, true) ? 1 : 0;
}

/**
 * Normalize สำหรับคำตอบแบบ "คำเดียว" (อังกฤษ/ตัวเลข)
 * - lower
 * - trim
 * - เอาเฉพาะ a-z 0-9 (กันพวก .,!? และช่องว่าง)
 */
function normalize_single_word(?string $s): string
{
    $s = trim((string) $s);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]/', '', $s);
    return $s ?? '';
}


function grade_single_word_with_levenshtein(?string $givenRaw, ?string $expectedRaw, float $maxScore): array
{
    $expectedRaw = trim((string) ($expectedRaw ?? ''));
    if ($expectedRaw === '') {

        return [null, null];
    }

    $given = normalize_single_word($givenRaw);
    if ($given === '') {
        return [0, 0.0];
    }

    $parts = preg_split("/\r\n|\r|\n|\||,|;/", $expectedRaw);
    $expectedList = [];
    foreach ($parts as $p) {
        $p = normalize_single_word($p);
        if ($p !== '')
            $expectedList[] = $p;
    }
    if (!$expectedList) {
        return [null, null];
    }

    // หา dist ที่น้อยที่สุด (ใกล้ที่สุด) โดยใช้ built-in levenshtein()
    $bestDist = null;
    $bestMaxLen = null;
    $bestExpected = '';
    foreach ($expectedList as $exp) {
        $dist = levenshtein($given, $exp); // 
        $ml = max(strlen($given), strlen($exp));
        if ($bestDist === null || $dist < $bestDist) {
            $bestDist = $dist;
            $bestMaxLen = ($ml > 0) ? $ml : 1;
            $bestExpected = $exp;
        }
    }

    // กันคำสั้นมาก (<=2 ตัว) 
    if (strlen($bestExpected) <= 2) {
        if ($given === $bestExpected)
            return [1, (float) $maxScore];
        return [0, 0.0];
    }

    $sim = 1.0 - ((float) $bestDist / (float) $bestMaxLen);
    if ($sim >= 0.90) {
        return [1, (float) $maxScore];
    } elseif ($sim >= 0.80) {

        return [0, (float) ($maxScore * 0.80)];
    }
    return [0, 0.0];
}


$submitMsg = null;
$submitError = null;

$attemptKey = 'attempt_started_at_' . $examId;
if (!isset($_SESSION[$attemptKey])) {
    $_SESSION[$attemptKey] = date('Y-m-d H:i:s');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $startedAt = (string) ($_SESSION[$attemptKey] ?? date('Y-m-d H:i:s'));
        $submittedAt = date('Y-m-d H:i:s');

        $totalQuestions = count($questions);
        $answeredQuestions = 0;

        $ansChoice = $_POST['ans'] ?? [];
        $ansText = $_POST['ans_text'] ?? [];
        $ansSub  = $_POST['ans_sub'] ?? [];   // [ question_id => [ sub_id => text ] ]

        $scoreMax = 0.0;
        foreach ($questions as $q) {
            $qid = (int) $q['id'];
            $qMax = isset($q['score']) ? (float) $q['score'] : 1.0;
            if ($qMax < 0)
                $qMax = 0.0;
            $scoreMax += $qMax;

            $hasChoice = isset($ansChoice[$qid]) && trim((string) $ansChoice[$qid]) !== '';
            $hasText   = isset($ansText[$qid])   && trim((string) $ansText[$qid])   !== '';
            // ข้อที่มีโจทย์ย่อย: นับว่าตอบแล้วถ้ากรอกอย่างน้อย 1 ช่อง
            $hasSub    = !empty($ansSub[$qid]) && count(array_filter(
                array_map('trim', (array) $ansSub[$qid])
            )) > 0;
            if ($hasChoice || $hasText || $hasSub)
                $answeredQuestions++;
        }

        // --- ปิดระบบตรวจอัตโนมัติ "เฉพาะอัตนัย" ---
// - ข้อปรนัย (choice/mcq/radio) ยังคงตรวจและให้คะแนนเหมือนเดิม
// - ข้ออัตนัยทั้งหมด (short/essay/paragraph ฯลฯ) เก็บคำตอบอย่างเดียว ไม่ตรวจ ไม่ให้คะแนน
        $correctChoiceIds = [];
        foreach ($choicesByQuestion as $cqid => $opts) {
            foreach ($opts as $c) {
                if (!empty($c['is_correct']))
                    $correctChoiceIds[(int) $cqid][] = (int) $c['id'];
            }
        }

        $scoreTotal = 0.0;
        $answerRows = [];

        foreach ($questions as $q) {
            $qid = (int) $q['id'];
            $qType = strtolower(trim((string) ($q['type'] ?? '')));
            $isChoice = in_array($qType, ['choice', 'multiple_choice', 'mcq', 'radio'], true);

            $qMax = isset($q['score']) ? (float) $q['score'] : 1.0;
            if ($qMax < 0)
                $qMax = 0.0;

            $selectedChoiceId = null;
            $answerText = null;
            $isCorrect = null;
            $score = null;

            if ($isChoice) {
                if (isset($ansChoice[$qid]) && trim((string) $ansChoice[$qid]) !== '') {
                    $selectedChoiceId = (int) $ansChoice[$qid];

                    // เก็บข้อความตัวเลือกไว้ใน answer_text (เผื่อแสดงผลภายหลัง)
                    $choiceText = null;
                    foreach (($choicesByQuestion[$qid] ?? []) as $c) {
                        if ((int) $c['id'] === $selectedChoiceId) {
                            $choiceText = (string) ($c['choice_text'] ?? null);
                            break;
                        }
                    }
                    $answerText = $choiceText;

                    // ตรวจคำตอบปรนัยเหมือนเดิม
                    $isCorrect = in_array($selectedChoiceId, $correctChoiceIds[$qid] ?? [], true) ? 1 : 0;
                    $score = $isCorrect ? $qMax : 0.0;
                }
            } else {
                // อัตนัย: เก็บคำตอบอย่างเดียว ไม่ตรวจ ไม่ให้คะแนน
                // ข้อที่มีโจทย์ย่อย → answer_text ของข้อแม่เป็น NULL
                // (คำตอบจะถูก INSERT แยกใน exam_sub_answers ด้านล่าง)
                $hasSubs = !empty($subsByQuestion[$qid]);
                if (!$hasSubs) {
                    if (isset($ansText[$qid]) && trim((string) $ansText[$qid]) !== '') {
                        $answerText = trim((string) $ansText[$qid]);
                    }
                }
                $isCorrect = null;
                $score     = null;
            }

            if ($score !== null)
                $scoreTotal += (float) $score;

            $answerRows[$qid] = [
                'selected_choice_id' => $selectedChoiceId,
                'answer_text' => $answerText,
                'is_correct' => $isCorrect,
                'score' => $score,
                'max_score' => $qMax,
                'feedback' => null,
            ];
        }


        $stAttempt = $pdo->prepare("
            INSERT INTO exam_attempts
                (exam_id, user_id, access_code, started_at, submitted_at, total_questions, answered_questions, score_total, score_max, client_ip)
            VALUES
                (:exam_id, :user_id, :access_code, :started_at, :submitted_at, :total_questions, :answered_questions, :score_total, :score_max, :client_ip)
        ");
        $stAttempt->execute([
            ':exam_id' => $examId,
            ':user_id' => (int) $_SESSION['user_id'],
            ':access_code' => $accessCode,
            ':started_at' => $startedAt,
            ':submitted_at' => $submittedAt,
            ':total_questions' => $totalQuestions,
            ':answered_questions' => $answeredQuestions,
            ':score_total' => $scoreTotal,
            ':score_max' => $scoreMax,
            ':client_ip' => get_client_ip(),
        ]);
        $attemptId = (int) $pdo->lastInsertId();

        $stAns = $pdo->prepare("
            INSERT INTO exam_answers
                (attempt_id, question_id, selected_choice_id, answer_text, is_correct, score, max_score, feedback)
            VALUES
                (:attempt_id, :question_id, :selected_choice_id, :answer_text, :is_correct, :score, :max_score, :feedback)
        ");
        foreach ($questions as $q) {
            $qid = (int) $q['id'];
            $row = $answerRows[$qid] ?? [];
            $stAns->execute([
                ':attempt_id'        => $attemptId,
                ':question_id'       => $qid,
                ':selected_choice_id'=> $row['selected_choice_id'] ?? null,
                ':answer_text'       => $row['answer_text'] ?? null,
                ':is_correct'        => array_key_exists('is_correct', $row) ? $row['is_correct'] : null,
                ':score'             => array_key_exists('score', $row) ? $row['score'] : null,
                ':max_score'         => $row['max_score'] ?? (isset($q['score']) ? (float) $q['score'] : 1.0),
                ':feedback'          => null,
            ]);
        }

        // ── INSERT exam_sub_answers (โจทย์ย่อย) ──────────────────────────────────
        // เฉพาะข้อที่มี sub_questions เท่านั้น
        if (!empty($subsByQuestion)) {
            $stSub = $pdo->prepare("
                INSERT INTO exam_sub_answers
                    (attempt_id, question_id, sub_question_id, answer_text, is_correct, score, max_score, feedback)
                VALUES
                    (:attempt_id, :question_id, :sub_question_id, :answer_text, :is_correct, :score, :max_score, :feedback)
            ");

            foreach ($subsByQuestion as $qid => $subs) {
                // คะแนนเต็มของข้อแม่ หารด้วยจำนวนโจทย์ย่อย = max_score เฉลี่ยต่อโจทย์ย่อย
                $parentMax   = (float) ($answerRows[(int) $qid]['max_score'] ?? 1.0);
                $subCount    = count($subs);
                $subMaxScore = $subCount > 0 ? round($parentMax / $subCount, 4) : 0.0;

                foreach ($subs as $sub) {
                    $subId  = (int) $sub['id'];
                    $val    = trim((string) ($ansSub[$qid][$subId] ?? ''));

                    $stSub->execute([
                        ':attempt_id'      => $attemptId,
                        ':question_id'     => (int) $qid,
                        ':sub_question_id' => $subId,
                        ':answer_text'     => $val !== '' ? $val : null,
                        ':is_correct'      => null,        // รอตรวจภายหลัง
                        ':score'           => null,        // รอตรวจภายหลัง
                        ':max_score'       => $subMaxScore,
                        ':feedback'        => null,
                    ]);
                }
            }
        }
        // ─────────────────────────────────────────────────────────────────────────

        $pdo->commit();
        unset($_SESSION[$attemptKey]);
        unset($_SESSION[$shuffleSeedKey]);

        
        header('Location: finish.php?exam_id=' . $examId . '&attempt_id=' . $attemptId);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        $submitError = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($exam['title']) ?> - ทำข้อสอบ</title>
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
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        header {
            background: transparent;
            padding: 14px 18px 0;
        }

        .topbar {
            max-width: 1200px;
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
            color: #0b3a2a;
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
            font-weight: 600;
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
            max-width: 1200px;
            margin: 0 auto;
            padding: 10px 18px 28px;
        }

        h1 {
            margin: 10px 0 14px;
            font-size: 32px;
            letter-spacing: .2px;
            color: var(--accent);
        }

        .grid {
            display: grid;
            grid-template-columns: 360px minmax(0, 1fr);
            gap: 14px;
            align-items: start;
        }

        .grid>* {
            min-width: 0;
        }

        @media (max-width: 1100px) {
            .grid {
                grid-template-columns: 1fr;
            }

            .sticky {
                position: static;
            }
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);


            min-width: 0;
        }

        .square {
            border-radius: 14px;
        }

        .accent-wrap {
            position: relative;
        }

        .accent-wrap::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 5px;
            background: var(--accent);
            border-top-left-radius: var(--radius);
            border-bottom-left-radius: var(--radius);
        }

        .card-head {
            padding: 14px 16px 10px;
            border-bottom: 1px solid var(--border);
        }

        .card-body {
            padding: 16px 18px 18px;
        }

        .sticky {
            position: sticky;
            top: 14px;
            align-self: start;
        }

        .exam-title {
            margin: 0 0 6px;
            font-weight: 800;
            font-size: 16px;
        }

        .exam-sub {
            font-size: 13px;
            color: var(--muted);
            line-height: 1.4;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            font-size: 13px;
        }

        .pill {
            display: inline-flex;
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: rgba(15, 118, 110, .08);
            color: #0b3a2a;
            font-weight: 700;
        }

        .nav {
            margin-top: 14px;
            display: grid;
            gap: 10px;
        }

        .nav a {
            text-decoration: none;
            color: var(--text);
            display: flex;
            justify-content: space-between;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: rgba(255, 255, 255, .7);
        }

        .nav a:hover {
            border-color: rgba(15, 118, 110, .35);
            box-shadow: 0 0 0 4px rgba(15, 118, 110, .08);
        }

        /* --- Navigation: เลขข้อในแต่ละ Section --- */
        .nav-sec {
            border: 1px solid var(--border);
            border-radius: 14px;
            background: rgba(255, 255, 255, .65);
            padding: 10px 12px;

            overflow: hidden;
        }

        .nav-sec+.nav-sec {
            margin-top: 10px;
        }

        .nav-sec-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }

        .nav-sec-head a {
            text-decoration: none;
            color: var(--text);
            font-weight: 800;
        }

        .nav-qs {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 8px;
        }

        @media (max-width: 980px) {
            .nav-qs {
                grid-template-columns: repeat(12, 1fr);
            }
        }

        .nav-q {
            display: grid;
            place-items: center;
            height: 30px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text);
            font-weight: 800;
            font-size: 12px;
            cursor: pointer;
            user-select: none;
            text-decoration: none;
        }

        .nav-q:hover {
            border-color: rgba(15, 118, 110, .35);
            box-shadow: 0 0 0 4px rgba(15, 118, 110, .08);
        }

        .nav-q.answered {
            background: rgba(15, 118, 109, 0.74);
            border-color: rgba(15, 118, 109, 0.57);
            color: #fff;
        }

        .muted {
            color: var(--muted);
        }

        .section {
            margin: 0 0 18px;
        }

        .section-title {
            margin: 0 0 10px;
            font-weight: 800;
            color: var(--accent);
        }

        .q {
            border: 1px solid var(--border);
            border-radius: 14px;
            background: #fff;
            padding: 14px;
            margin: 10px 0;
        }

        /* ไฮไลท์ข้อที่ตอบแล้ว */
        .q.answered {
            border-color: rgba(15, 118, 110, .45);
            box-shadow: 0 0 0 4px rgba(15, 118, 110, .06);
        }

        /* ไฮไลท์ข้อที่ยังไม่ตอบตอนกดส่ง */
        .q.missing {
            border-color: rgba(239, 68, 68, .55);
            box-shadow: 0 0 0 4px rgba(239, 68, 68, .08);
        }

        .q-head {
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }


        .q-head>div {
            min-width: 0;
        }

        .q-no {
            width: 28px;
            height: 28px;
            min-width: 28px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            background: rgba(15, 118, 110, .12);
            color: var(--accent);
            font-weight: 800;
        }

        .q-text {
            font-weight: 800;
            font-size: 14px;
            word-break: break-word;
            overflow-wrap: anywhere;
        }

        /* ===== Media ภายใน q-text ===== */
        .q-text img {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 10px 0;
            border-radius: 10px;
            border: 1px solid var(--border);
        }
        .q-text video {
            max-width: 100%;
            display: block;
            margin: 10px 0;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: #000;
        }
        .q-text audio {
            width: 100%;
            display: block;
            margin: 10px 0;
        }
        .q-text table {
            border-collapse: collapse;
            width: 100%;
            margin: 12px 0;
            font-size: 13px;
            font-weight: 400;
        }
        .q-text table th,
        .q-text table td {
            border: 1px solid #cbd5e1;
            padding: 8px 10px;
            text-align: left;
        }
        .q-text table th {
            background: #f1f5f9;
            font-weight: 700;
        }
        .q-text pre {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 14px 16px;
            margin: 10px 0;
            white-space: pre-wrap;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 13px;
            font-weight: 400;
            line-height: 1.6;
            color: #1e293b;
            overflow-x: auto;
        }
        .q-text p  { margin: 6px 0; font-weight: 400; }
        .q-text ul,
        .q-text ol { margin: 6px 0 6px 20px; font-weight: 400; }
        /* ===== End Media ===== */

        .q-meta {
            font-size: 12px;
            margin-top: 4px;
            color: var(--muted);
        }

        .answers {
            margin-top: 10px;
            display: grid;
            gap: 10px;
        }

        .choice {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: rgba(255, 255, 255, .85);

            /* ✅ FIX: สำคัญเหมือนกัน */
            min-width: 0;
        }

        .choice input {
            margin-top: 3px;
        }

        textarea,
        input[type="text"] {
            width: 100%;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid var(--border);
            outline: none;
            font: inherit;
            background: #fff;
        }

        textarea:focus,
        input[type="text"]:focus {
            border-color: rgba(15, 118, 110, .45);
            box-shadow: 0 0 0 4px rgba(15, 118, 110, .10);
        }

        textarea {
            min-height: 110px;
            resize: vertical;
        }

        .actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 14px;
            flex-wrap: wrap;
        }

        .btn {
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text);
            padding: 10px 14px;
            border-radius: 12px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 750;
        }

        .btn-primary {
            background: var(--accent);
            color: #fff;
            border-color: transparent;
        }

        .btn-primary:hover {
            filter: brightness(1.05);
        }

        .success {
            padding: 12px 12px;
            border-radius: 12px;
            border: 1px solid rgba(16, 185, 129, .35);
            background: rgba(16, 185, 129, .10);
            margin-bottom: 12px;
        }

        .missing-box {
            padding: 12px 14px;
            border-radius: 0 0 12px 12px;
            border: 1px solid rgba(239, 68, 68, .35);
            border-top: none;
            background: rgba(239, 68, 68, .08);
            font-size: 0.85rem;
            line-height: 1.6;
        }

        .missing-box a {
            color: #ef4444;
            text-decoration: underline;
            cursor: pointer;
            font-weight: 800;
        }

        .empty {
            padding: 12px 12px;
            border-radius: 12px;
            border: 1px dashed var(--border);
            color: var(--muted);
        }

        /* ===== Sub-questions ===== */
        .sub-qs {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }

        .sub-q {
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #f8fafc;
            overflow: hidden;
        }

        .sub-q-head {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px 14px 8px;
        }

        .sub-q-badge {
            min-width: 24px;
            height: 24px;
            border-radius: 8px;
            background: rgba(15, 118, 110, .12);
            color: var(--accent);
            font-weight: 800;
            font-size: 11px;
            display: grid;
            place-items: center;
            flex: 0 0 auto;
            margin-top: 1px;
        }

        .sub-q-text {
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
            line-height: 1.55;
            flex: 1;
            word-break: break-word;
        }

        .sub-q-text img   { max-width: 100%; height: auto; border-radius: 8px; margin: 6px 0; display: block; }
        .sub-q-text video { max-width: 100%; border-radius: 8px; margin: 6px 0; display: block; }
        .sub-q-text audio { width: 100%; margin: 6px 0; display: block; }

        .sub-q-answer {
            padding: 0 14px 12px;
        }

        .sub-q-answer input[type="text"] {
            font-size: 13px;
            padding: 8px 10px;
            border-radius: 10px;
        }
        /* ===== End Sub-questions ===== */
    
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
                <a class="logout" href="logout.php" id="logoutLink">ออกจากระบบ</a>
            </div>
        </div>
    </header>

    <main>
        <h1>ทำข้อสอบ</h1>

        <div class="grid">
            <!-- LEFT -->
            <div class="card square accent-wrap sticky">
                <div class="card-head">
                    <p class="exam-title"><?= htmlspecialchars($exam['title']) ?></p>

                    <div class="exam-sub">
                        <?= nl2br(htmlspecialchars((string) ($exam['instruction_content'] ?? ''))) ?>
                    </div>

                    <div class="badge">
                        <span class="muted">รหัสข้อสอบ:</span>
                        <span class="pill"><?= htmlspecialchars($accessCode) ?></span>
                    </div>
                </div>

                <div class="card-body">
                    <div class="muted" style="font-weight:800;margin-bottom:8px;">ไปยังส่วนต่าง ๆ</div>
                    <div class="nav">
                        <?php foreach ($navSections as $ns): ?>
                            <div class="nav-sec">
                                <div class="nav-sec-head">
                                    <a href="#sec<?= (int) $ns['id'] ?>"><?= htmlspecialchars($ns['title']) ?></a>
                                    <span class="muted"><?= (int) $ns['count'] ?> ข้อ</span>
                                </div>

                                <?php if (!empty($ns['questions'])): ?>
                                    <div class="nav-qs">
                                        <?php foreach ($ns['questions'] as $qq): ?>
                                            <a class="nav-q" href="#q<?= (int) $qq['no'] ?>" data-qno="<?= (int) $qq['no'] ?>">
                                                <?= (int) $qq['no'] ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="empty" style="margin-top:8px;">ยังไม่มีคำถามในส่วนนี้</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div id="missingBox" class="missing-box" style="display:none;"></div>
            </div>

            <!-- RIGHT -->
            <div class="card">
                <div class="card-body">
                    <form method="post" id="examForm">

                        <?php if (!empty($submitError)): ?>
                            <div class="missing-box" style="display:block;"><?= htmlspecialchars($submitError) ?></div>
                        <?php endif; ?>

                        <?php if ($submitMsg): ?>
                            <div class="success"><?= htmlspecialchars($submitMsg) ?></div>
                        <?php endif; ?>

                        <?php if (empty($sectionsFinal)): ?>
                            <div class="empty">ยังไม่มีคำถามในข้อสอบนี้</div>
                        <?php else: ?>
                            <?php $qNo = 0; ?>

                            <?php foreach ($sectionsFinal as $s): ?>
                                <div class="section" id="sec<?= (int) $s['id'] ?>">
                                    <div class="section-title"><?= htmlspecialchars($s['title']) ?></div>

                                    <?php foreach ($s['questions'] as $q):
                                        $qNo++;
                                        $qid     = (int) $q['id'];
                                        $qType   = strtolower(trim((string) $q['type']));
                                        $isChoice = in_array($qType, ['choice', 'multiple_choice', 'mcq', 'radio'], true);
                                        $isEssay  = in_array($qType, ['essay', 'paragraph', 'long'], true);
                                        $subQs    = $subsByQuestion[$qid] ?? [];
                                        $hasSubs  = !empty($subQs);
                                        ?>
                                        <div class="q" id="q<?= $qNo ?>" data-qno="<?= $qNo ?>" data-qid="<?= $qid ?>"
                                             data-hassubs="<?= $hasSubs ? '1' : '0' ?>">
                                            <div class="q-head">
                                                <div class="q-no"><?= $qNo ?></div>
                                                <div style="flex:1;">
                                                    <div class="q-text"><?= render_question_html((string) $q['question']) ?></div>
                                                    <div class="q-meta">ประเภท: <?= htmlspecialchars($q['type'] ?: 'unknown') ?></div>

                                                    <div class="answers">
                                                        <?php if ($isChoice): ?>
                                                            <?php $opts = $choicesByQuestion[$qid] ?? []; ?>
                                                            <?php if (empty($opts)): ?>
                                                                <div class="empty">ไม่มีตัวเลือกสำหรับข้อนี้</div>
                                                            <?php else: ?>
                                                                <?php foreach ($opts as $c): ?>
                                                                    <label class="choice">
                                                                        <input type="radio" name="ans[<?= $qid ?>]"
                                                                            value="<?= (int) $c['id'] ?>">
                                                                        <span><?= htmlspecialchars((string) $c['choice_text']) ?></span>
                                                                    </label>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>

                                                        <?php elseif ($isEssay): ?>
                                                            <textarea name="ans_text[<?= $qid ?>]"
                                                                placeholder="พิมพ์คำตอบของคุณ..."></textarea>

                                                        <?php elseif ($qType === 'short_answer' && $hasSubs): ?>
                                                            <?php /* ── โจทย์ย่อย (sub_questions) ── */ ?>
                                                            <div class="sub-qs">
                                                                <?php foreach ($subQs as $si => $sub):
                                                                    $subId = (int) $sub['id'];
                                                                    $alpha = chr(97 + $si); // a, b, c, ...
                                                                ?>
                                                                    <div class="sub-q">
                                                                        <div class="sub-q-head">
                                                                            <div class="sub-q-badge"><?= htmlspecialchars($alpha) ?></div>
                                                                            <div class="sub-q-text">
                                                                                <?= render_question_html((string) $sub['sub_question']) ?>
                                                                            </div>
                                                                        </div>
                                                                        <div class="sub-q-answer">
                                                                            <input type="text"
                                                                                name="ans_sub[<?= $qid ?>][<?= $subId ?>]"
                                                                                placeholder="พิมพ์คำตอบ...">
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>

                                                        <?php else: ?>
                                                            <input type="text" name="ans_text[<?= $qid ?>]"
                                                                placeholder="พิมพ์คำตอบของคุณ...">
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>

                            <div class="actions">
                                <a class="btn" href="home.php">กลับหน้าหลัก</a>
                                <button class="btn btn-primary" type="submit" href="finish.php">ส่งคำตอบ</button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </main>

    
    <!-- ===== Logout Confirm Modal ===== -->
    <div class="modal-backdrop" id="logoutModal" aria-hidden="true">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="logoutTitle">
            <div class="modal-head">
                <p class="modal-title" id="logoutTitle">ยืนยันการออกจากระบบ</p>
            </div>
            <div class="modal-body">
                คุณต้องการออกจากระบบใช่ไหม?<br>
                <span class="muted">คำตอบที่ยังไม่ได้กด “ส่งคำตอบ” จะไม่ถูกบันทึก</span>
            </div>
            <div class="modal-actions">
                <button class="btn" type="button" id="logoutCancel">ยกเลิก</button>
                <button class="btn btn-danger" type="button" id="logoutConfirm">ออกจากระบบ</button>
            </div>
        </div>
    </div>
    <!-- ===== End Modal ===== -->

<script>
        (function () {
            const form = document.getElementById('examForm');
            const missingBox = document.getElementById('missingBox');
            if (!form || !missingBox) return;

            function isAnswered(qEl) {
                // ข้อที่มีโจทย์ย่อย: ถือว่าตอบแล้วถ้ากรอกอย่างน้อย 1 ช่อง
                if (qEl.dataset.hassubs === '1') {
                    const subs = qEl.querySelectorAll('.sub-q-answer input[type="text"]');
                    for (const inp of subs) {
                        if ((inp.value || '').trim().length > 0) return true;
                    }
                    return false;
                }
                const radios = qEl.querySelectorAll('input[type="radio"]');
                if (radios && radios.length) {
                    for (const r of radios) if (r.checked) return true;
                    return false;
                }
                const txt = qEl.querySelector('textarea, input[type="text"]');
                if (txt) return (txt.value || '').trim().length > 0;
                return true;
            }

            function setAnsweredUI(qEl) {
                const qno = qEl.getAttribute('data-qno');
                const answered = isAnswered(qEl);
                qEl.classList.toggle('answered', answered);

                const navBtn = document.querySelector(`.nav-q[data-qno="${qno}"]`);
                if (navBtn) navBtn.classList.toggle('answered', answered);
            }

            function refreshAllAnswered() {
                document.querySelectorAll('.q[data-qno]').forEach(q => setAnsweredUI(q));
            }

            function clearMissing() {
                document.querySelectorAll('.q.missing').forEach(el => el.classList.remove('missing'));
                missingBox.style.display = 'none';
                missingBox.innerHTML = '';
            }

            function showMissing(missing) {
                const items = missing.map(m => `<a data-qno="${m.qno}">ข้อ ${m.qno}</a>`).join(', ');
                missingBox.innerHTML = `ยังไม่ได้ทำ ${missing.length} ข้อ: ${items}`;
                missingBox.style.display = 'block';

                missingBox.querySelectorAll('a[data-qno]').forEach(a => {
                    a.addEventListener('click', () => {
                        const qno = a.getAttribute('data-qno');
                        const target = document.querySelector(`.q[data-qno="${qno}"]`);
                        if (target) target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    });
                });
            }

            // อัปเดตสถานะ "ตอบแล้ว" ตอนพิมพ์/เลือกคำตอบ
            form.addEventListener('input', (e) => {
                const qEl = e.target.closest('.q[data-qno]');
                if (qEl) setAnsweredUI(qEl);
            });
            form.addEventListener('change', (e) => {
                const qEl = e.target.closest('.q[data-qno]');
                if (qEl) setAnsweredUI(qEl);
            });

            // คลิกเลขข้อใน Navigation -> เลื่อนไปยังข้อนั้นแบบนุ่มนวล
            document.querySelectorAll('.nav-q[data-qno]').forEach(btn => {
                btn.addEventListener('click', (ev) => {
                    ev.preventDefault();
                    const qno = btn.getAttribute('data-qno');
                    const target = document.getElementById(`q${qno}`);
                    if (target) target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                });
            });

            // โหลดหน้าแล้วเช็คว่า browser เติมค่าไว้ไหม (back/forward cache)
            refreshAllAnswered();

            function fillDashInTextInputs(qEl) {
                // เติม "-" เฉพาะข้อที่พิมพ์ตอบ (input[type=text] / textarea) ที่ยังว่างอยู่
                if (qEl.dataset.hassubs === '1') {
                    const subs = qEl.querySelectorAll('.sub-q-answer input[type="text"]');
                    subs.forEach(inp => {
                        if ((inp.value || '').trim() === '') inp.value = '-';
                    });
                } else {
                    const radios = qEl.querySelectorAll('input[type="radio"]');
                    if (!radios || radios.length === 0) {
                        const txt = qEl.querySelector('textarea, input[type="text"]');
                        if (txt && (txt.value || '').trim() === '') txt.value = '-';
                    }
                }
            }

            form.addEventListener('submit', (e) => {
                clearMissing();
                const qs = Array.from(document.querySelectorAll('.q[data-qno]'));
                const missing = [];
                for (const q of qs) {
                    if (!isAnswered(q)) {
                        missing.push({ qno: q.getAttribute('data-qno'), el: q });
                        q.classList.add('missing');
                    }
                }

                if (missing.length > 0) {
                    e.preventDefault();

                    // เติม "-" ในข้อที่พิมพ์ตอบซึ่งยังว่างอยู่ทันที
                    missing.forEach(m => fillDashInTextInputs(m.el));
                    refreshAllAnswered();

                    // กรองเฉพาะข้อที่ยังไม่ได้ตอบจริงๆ (radio ที่ยังไม่ได้เลือก)
                    const stillMissing = missing.filter(m => !isAnswered(m.el));
                    stillMissing.forEach(m => m.el.classList.add('missing'));

                    // แสดงข้อความแจ้งเตือน
                    const dashFilled = missing.length - stillMissing.length;
                    let msg = '';
                    if (dashFilled > 0) {
                        msg += `ข้อที่ยังไม่ได้ตอบจะถูกใส่เครื่องหมาย "-" เอาไว้ กดส่งคำตอบอีกครั้งถ้าไม่ประสงค์จะตอบในข้อนั้นๆ`;
                    }
                    if (stillMissing.length > 0) {
                        const items = stillMissing.map(m => `<a data-qno="${m.qno}">ข้อ ${m.qno}</a>`).join(', ');
                        if (msg) msg += '<br>';
                        msg += `ยังไม่ได้เลือกคำตอบ ${stillMissing.length} ข้อ: ${items}`;
                    }
                    missingBox.innerHTML = msg;
                    missingBox.style.display = 'block';

                    missingBox.querySelectorAll('a[data-qno]').forEach(a => {
                        a.addEventListener('click', () => {
                            const target = document.querySelector(`.q[data-qno="${a.getAttribute('data-qno')}"]`);
                            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        });
                    });

                    const scrollTarget = stillMissing.length > 0 ? stillMissing[0].el : missingBox;
                    scrollTarget.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        })();
    </script>

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
            window.location.href = logoutLink.href;
        });

        modal.addEventListener('click', function(e){
            if (e.target === modal) closeModal();
        });

        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape' && modal.classList.contains('show')) closeModal();
        });
    })();
    </script>

</body>

</html>