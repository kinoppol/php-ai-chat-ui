<?php
/**
 * ============================================================================
 * AI CHAT INTERFACE v2.0
 * ============================================================================
 * PHP 8.0+ | MariaDB 10+ | OpenAI-compatible API (Ollama / OpenAI / OpenRouter)
 * ============================================================================
 */
declare(strict_types=1);

// ─── Bootstrap: redirect to installer if not configured ──────────────────────
if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: install.php');
    exit;
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/theme.php';

// ─── Database helper ─────────────────────────────────────────────────────────
function chatDb(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException) {
            // DB not ready — redirect to install
            header('Location: install.php');
            exit;
        }
    }
    return $pdo;
}

function chatSetting(string $key, string $default = ''): string {
    static $cache = [];
    if (!isset($cache[$key])) {
        $stmt = chatDb()->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $cache[$key] = $row ? (string)$row['value'] : $default;
    }
    return $cache[$key];
}

/**
 * แก้ไขค่า token ของ user โดยถ้าเป็น NULL ให้ใช้ค่ากลางจาก settings
 * @param int|null $userVal ค่าจาก users table (NULL = ยังไม่ตั้ง → ใช้ global)
 * @param string $settingKey key ของ global setting
 * @param int $fallback ถ้า global ก็ไม่มีให้ใช้ค่านี้
 */
function resolveTokenSetting(?int $userVal, string $settingKey, int $fallback = 0): int {
    if ($userVal !== null) return $userVal;
    return (int)chatSetting($settingKey, (string)$fallback);
}

// ─── Load API config from DB ──────────────────────────────────────────────────
$apiKey            = chatSetting('api_key',            'ollama');
$baseUrl           = chatSetting('base_url',           'http://localhost:11434/v1');
$model             = chatSetting('model',              'llama3:8b');
$systemPrompt      = chatSetting('system_prompt',      'You are a helpful AI assistant. Be concise and helpful.');
$maxTokens         = (int) chatSetting('max_tokens',   '4096');
$siteName          = chatSetting('site_name',          'AI Chat');
$allowRegistration = chatSetting('allow_registration', '1') === '1';
$registrationNote  = chatSetting('registration_note',  'กรุณารอการอนุมัติจาก Admin ก่อนเข้าใช้งาน');
// Load active models from DB (auto-fallback to settings if table missing)
$modelsList   = $model; // fallback
$__modelsData = [];
$noModels     = false;  // true = มี models table แต่ไม่มีโมเดลเปิดอยู่เลย
try {
    $__stmt = chatDb()->query('SELECT m.name, m.label, COALESCE(s.nickname,"") AS server_nick FROM `models` m LEFT JOIN `api_servers` s ON s.id=m.server_id WHERE m.is_active=1 ORDER BY m.sort_order ASC, m.id ASC');
    $__rows = $__stmt->fetchAll(PDO::FETCH_ASSOC);
    // ตรวจว่า models table มีข้อมูลอยู่เลยหรือไม่ (เพื่อแยก "ยังไม่ migrate" vs "ปิดหมด")
    $__total = (int)chatDb()->query('SELECT COUNT(*) FROM `models`')->fetchColumn();
    if ($__total > 0) {
        // table มีข้อมูล — ถ้าไม่มี active = ปิดหมด
        $noModels = empty($__rows);
    }
    if (!empty($__rows)) {
        $modelsList   = implode(',', array_column($__rows, 'name'));
        $__modelsData = $__rows;
    }
} catch (Throwable) { /* models table not yet migrated — ใช้ fallback จาก settings */ }

// ─── Session & Auth ───────────────────────────────────────────────────────────
session_start();

$authenticated = false;
$currentUserId = null;
$currentUsername = '';

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Process login form
if (!isset($_SESSION['chat_user_id']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chat_login'])) {
    $loginUsername = trim($_POST['username'] ?? '');
    $loginPassword = $_POST['password'] ?? '';

    $stmt = chatDb()->prepare('SELECT id, password, display_name, is_active FROM users WHERE username = ?');
    $stmt->execute([$loginUsername]);
    $user = $stmt->fetch();

    if ($user && password_verify($loginPassword, $user['password']) && !$user['is_active']) {
        $loginError = 'บัญชีของคุณยังรอการอนุมัติจาก Admin กรุณารอสักครู่';
    } elseif ($user && $user['is_active'] && password_verify($loginPassword, $user['password'])) {
        $_SESSION['chat_user_id']       = $user['id'];
        $_SESSION['chat_username']      = $loginUsername;
        $_SESSION['chat_display_name']  = $user['display_name'] ?: $loginUsername;
        chatDb()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
        header('Location: chat.php');
        exit;
    } else {
        $loginError = 'Username หรือ Password ไม่ถูกต้อง';
    }
}

// Process registration form
$registerError   = '';
$registerSuccess = '';
if (!isset($_SESSION['chat_user_id']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chat_register'])) {
    if (!$allowRegistration) {
        $registerError = 'ขณะนี้ปิดรับการลงทะเบียน';
    } else {
        $regUsername = trim($_POST['reg_username'] ?? '');
        $regDisplay  = trim($_POST['reg_display']  ?? '');
        $regPassword = $_POST['reg_password'] ?? '';
        $regConfirm  = $_POST['reg_confirm']  ?? '';

        if (mb_strlen($regUsername) < 3) {
            $registerError = 'Username ต้องมีอย่างน้อย 3 ตัวอักษร';
        } elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $regUsername)) {
            $registerError = 'Username ใช้ได้เฉพาะ a-z, 0-9, . _ -';
        } elseif (strlen($regPassword) < 6) {
            $registerError = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
        } elseif ($regPassword !== $regConfirm) {
            $registerError = 'รหัสผ่านไม่ตรงกัน';
        } else {
            try {
                $hash = password_hash($regPassword, PASSWORD_BCRYPT, ['cost' => 12]);
                $displayName = $regDisplay ?: $regUsername;
                // is_active = 0: รอ Admin อนุมัติ
                chatDb()->prepare('INSERT INTO users (username, password, display_name, role, is_active) VALUES (?,?,?,\'user\',0)')
                    ->execute([$regUsername, $hash, $displayName]);
                $registerSuccess = 'ลงทะเบียนสำเร็จ! ' . htmlspecialchars($registrationNote);
            } catch (PDOException) {
                $registerError = 'Username "' . htmlspecialchars($regUsername) . '" มีผู้ใช้งานแล้ว';
            }
        }
    }
}

if (isset($_SESSION['chat_user_id'])) {
    $authenticated   = true;
    $currentUserId   = (int)$_SESSION['chat_user_id'];
    $currentUsername = $_SESSION['chat_display_name'] ?? $_SESSION['chat_username'] ?? 'U';
    // Load token data for display
    $__tr = chatDb()->prepare('SELECT token_limit, tokens_used, tokens_total, token_reset_hours, tokens_reset_at FROM users WHERE id = ?');
    $__tr->execute([$currentUserId]);
    $__td            = $__tr->fetch(PDO::FETCH_ASSOC) ?: [];
    $userTokensUsed  = (int)($__td['tokens_used']  ?? 0);
    $userTokensTotal = (int)($__td['tokens_total'] ?? 0);
    // NULL = ใช้ค่ากลาง (global setting)
    $userTokenLimit = resolveTokenSetting(
        isset($__td['token_limit']) && $__td['token_limit'] !== null ? (int)$__td['token_limit'] : null,
        'default_token_limit', 0
    );
    $userResetHours = resolveTokenSetting(
        isset($__td['token_reset_hours']) && $__td['token_reset_hours'] !== null ? (int)$__td['token_reset_hours'] : null,
        'default_token_reset_hours', 0
    );
    $userResetAt = $__td['tokens_reset_at'] ?? null;
    // ถ้ากำหนด reset_hours แต่ยังไม่มี reset_at → ตั้งเดี๋ยวนี้เป็นจุดเริ่มต้น
    if ($userResetHours > 0 && $userResetAt === null) {
        chatDb()->prepare('UPDATE users SET tokens_reset_at = NOW() WHERE id = ?')->execute([$currentUserId]);
        $userResetAt = date('Y-m-d H:i:s');
    }
    // คำนวณเวลาที่เหลือก่อน reset (วินาที)
    $userSecsLeft = null;
    if ($userResetHours > 0 && $userResetAt) {
        $nextReset    = strtotime($userResetAt) + ($userResetHours * 3600);
        $userSecsLeft = max(0, $nextReset - time());
    }
}

// ─── API: Get conversation list (AJAX) ───────────────────────────────────────
if (isset($_GET['api']) && $_GET['api'] === 'convs' && $authenticated) {
    header('Content-Type: application/json');
    $stmt = chatDb()->prepare('SELECT id, title, model, updated_at FROM conversations WHERE user_id = ? ORDER BY updated_at DESC LIMIT 50');
    $stmt->execute([$currentUserId]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// ─── API: Token status (AJAX) ────────────────────────────────────────────────
if (isset($_GET['api']) && $_GET['api'] === 'token_status' && $authenticated) {
    header('Content-Type: application/json');
    $row = chatDb()->prepare('SELECT token_limit, tokens_used, tokens_total, token_reset_hours, tokens_reset_at FROM users WHERE id = ?');
    $row->execute([$currentUserId]);
    $r          = $row->fetch(PDO::FETCH_ASSOC) ?: [];
    $effLimit = resolveTokenSetting(
        isset($r['token_limit']) && $r['token_limit'] !== null ? (int)$r['token_limit'] : null,
        'default_token_limit', 0
    );
    $resetHours = resolveTokenSetting(
        isset($r['token_reset_hours']) && $r['token_reset_hours'] !== null ? (int)$r['token_reset_hours'] : null,
        'default_token_reset_hours', 0
    );
    $resetAt = $r['tokens_reset_at'] ?? null;
    if ($resetHours > 0 && $resetAt === null) {
        chatDb()->prepare('UPDATE users SET tokens_reset_at = NOW() WHERE id = ?')->execute([$currentUserId]);
        $resetAt = date('Y-m-d H:i:s');
    }
    $secsLeft = null;
    if ($resetHours > 0 && $resetAt) {
        $nextReset = strtotime($resetAt) + ($resetHours * 3600);
        $secsLeft  = max(0, $nextReset - time());
    }
    echo json_encode([
        'limit'       => $effLimit,
        'used'        => (int)($r['tokens_used']  ?? 0),
        'total'       => (int)($r['tokens_total'] ?? 0),
        'reset_hours' => $resetHours,
        'secs_left'   => $secsLeft,
    ]);
    exit;
}

// ─── File upload dir helper ───────────────────────────────────────────────────
function uploadDir(): string {
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("ไม่สามารถสร้างโฟลเดอร์ uploads ได้: {$dir}");
        }
        // Protect directory from direct web access
        file_put_contents($dir . '/.htaccess', "Deny from all\n");
    }
    return $dir;
}
function uploadPath(string $uuid): string { return uploadDir() . '/' . $uuid; }

// ─── API: Upload file ─────────────────────────────────────────────────────────
if (isset($_GET['api']) && $_GET['api'] === 'upload' && $authenticated) {
    header('Content-Type: application/json');
    if (empty($_FILES['files'])) { echo json_encode(['ok'=>false,'msg'=>'ไม่มีไฟล์']); exit; }
    $limitMb = (int)(chatDb()->query("SELECT value FROM settings WHERE `key`='conv_storage_limit_mb'")->fetchColumn() ?: 200);
    $results = [];
    try { $dir = uploadDir(); } catch (RuntimeException $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); exit; }
    foreach ($_FILES['files']['tmp_name'] as $i => $tmp) {
        if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
            $errMap = [1=>'ไฟล์ใหญ่เกินค่า php.ini',2=>'ไฟล์ใหญ่เกิน form limit',3=>'อัปโหลดไม่ครบ',4=>'ไม่มีไฟล์'];
            $results[] = ['ok'=>false,'name'=>basename($_FILES['files']['name'][$i]),'msg'=>$errMap[$_FILES['files']['error'][$i]]??'ข้อผิดพลาด '.$_FILES['files']['error'][$i]]; continue;
        }
        $name = basename($_FILES['files']['name'][$i]);
        $mime = $_FILES['files']['type'][$i] ?: (function_exists('mime_content_type') ? mime_content_type($tmp) : '') ?: 'application/octet-stream';
        $size = (int)$_FILES['files']['size'][$i];
        if ($size > 20 * 1024 * 1024) { $results[] = ['ok'=>false,'name'=>$name,'msg'=>'ไฟล์ใหญ่เกิน 20MB']; continue; }
        $uuid   = bin2hex(random_bytes(16));
        $ext    = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $stored = $uuid . ($ext ? '.' . $ext : '');
        $dest   = $dir . '/' . $stored;
        if (!move_uploaded_file($tmp, $dest)) {
            $writable = is_writable($dir) ? 'เขียนได้' : 'เขียนไม่ได้';
            $results[] = ['ok'=>false,'name'=>$name,'msg'=>"บันทึกไฟล์ล้มเหลว (โฟลเดอร์ {$writable})"]; continue;
        }
        chatDb()->prepare('INSERT INTO conversation_files (uuid, user_id, original_name, mime_type, size) VALUES (?,?,?,?,?)')
            ->execute([$uuid, $currentUserId, $name, $mime, $size]);
        $results[] = ['ok'=>true,'uuid'=>$uuid,'name'=>$name,'mime'=>$mime,'size'=>$size,'url'=>"chat.php?file={$uuid}"];
    }
    echo json_encode(['ok'=>true,'files'=>$results]); exit;
}

// ─── Serve uploaded file ──────────────────────────────────────────────────────
if (isset($_GET['file']) && $authenticated) {
    $uuid = preg_replace('/[^a-f0-9]/i', '', $_GET['file']);
    $row  = chatDb()->prepare('SELECT original_name, mime_type, user_id, conversation_id FROM conversation_files WHERE uuid=?');
    $row->execute([$uuid]);
    $f = $row->fetch(PDO::FETCH_ASSOC);
    if (!$f) { http_response_code(404); exit('Not found'); }
    // Allow owner or admin
    if ((int)$f['user_id'] !== $currentUserId) { http_response_code(403); exit('Forbidden'); }
    // Find stored file
    $glob = glob(uploadDir() . '/' . $uuid . '*');
    if (!$glob) { http_response_code(404); exit('File missing'); }
    $path = $glob[0];
    header('Content-Type: ' . ($f['mime_type'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=86400');
    header('Content-Disposition: inline; filename="' . addslashes($f['original_name']) . '"');
    readfile($path); exit;
}

// ─── API: Get messages for a conversation (AJAX) ─────────────────────────────
if (isset($_GET['api']) && $_GET['api'] === 'msgs' && $authenticated) {
    header('Content-Type: application/json');
    $cid = (int)($_GET['cid'] ?? 0);
    $stmt = chatDb()->prepare('SELECT id FROM conversations WHERE id = ? AND user_id = ?');
    $stmt->execute([$cid, $currentUserId]);
    if (!$stmt->fetch()) { echo json_encode([]); exit; }

    $msgs = chatDb()->prepare('SELECT id, role, content FROM messages WHERE conversation_id = ? ORDER BY created_at ASC');
    $msgs->execute([$cid]);
    $rows = $msgs->fetchAll(PDO::FETCH_ASSOC);

    // Attach file metadata per message
    $msgIds = array_column($rows, 'id');
    $filesByMsg = [];
    if ($msgIds) {
        $ph   = implode(',', array_fill(0, count($msgIds), '?'));
        $fstmt = chatDb()->prepare("SELECT message_id, uuid, original_name, mime_type FROM conversation_files WHERE message_id IN ($ph)");
        $fstmt->execute($msgIds);
        foreach ($fstmt->fetchAll(PDO::FETCH_ASSOC) as $fr) {
            $filesByMsg[(int)$fr['message_id']][] = ['uuid'=>$fr['uuid'],'name'=>$fr['original_name'],'mime'=>$fr['mime_type'],'url'=>'chat.php?file='.$fr['uuid']];
        }
    }
    $result = [];
    foreach ($rows as $r) {
        $result[] = ['role'=>$r['role'], 'content'=>$r['content'], 'files'=>$filesByMsg[(int)$r['id']] ?? []];
    }
    echo json_encode($result); exit;
}

// ─── API: Delete conversation (AJAX) ─────────────────────────────────────────
if (isset($_GET['api']) && $_GET['api'] === 'del_conv' && $authenticated && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $cid = (int)(json_decode(file_get_contents('php://input'), true)['id'] ?? 0);
    // Delete physical files first
    $frows = chatDb()->prepare('SELECT uuid FROM conversation_files WHERE conversation_id=?');
    $frows->execute([$cid]);
    foreach ($frows->fetchAll(PDO::FETCH_COLUMN) as $uuid) {
        foreach (glob(uploadDir() . '/' . $uuid . '*') ?: [] as $fp) { @unlink($fp); }
    }
    chatDb()->prepare('DELETE FROM conversations WHERE id = ? AND user_id = ?')->execute([$cid, $currentUserId]);
    echo json_encode(['ok' => true]);
    exit;
}

// ─── SSE STREAMING ENDPOINT ───────────────────────────────────────────────────
if (isset($_GET['stream']) && $_GET['stream'] == '1' && $authenticated) {

    set_time_limit(0);                          // ไม่จำกัดเวลา PHP execution สำหรับ streaming
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', false);
    @ini_set('implicit_flush', true);
    while (ob_get_level()) ob_end_clean();
    ob_implicit_flush(true);

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    $input = file_get_contents('php://input');
    $data  = json_decode($input, true);

    if (!$data || !isset($data['messages'])) {
        echo "data: " . json_encode(['error' => 'Invalid request']) . "\n\n";
        flush();
        exit;
    }

    $requestMessages = $data['messages'];
    $requestModel    = $data['model'] ?? $model;
    $convId          = isset($data['conv_id']) ? (int)$data['conv_id'] : 0;
    $convTitle       = $data['conv_title'] ?? 'New Chat';
    $fileUuids       = array_filter(array_map('trim', (array)($data['file_uuids'] ?? [])));

    // ── Token limit check + auto-reset ───────────────────────────────────
    $userRow = chatDb()->prepare('SELECT token_limit, tokens_used, tokens_reset_at, token_reset_hours FROM users WHERE id = ?');
    $userRow->execute([$currentUserId]);
    $userTokenData   = $userRow->fetch(PDO::FETCH_ASSOC) ?: [];
    $tokensUsed      = (int)($userTokenData['tokens_used'] ?? 0);
    // NULL = ใช้ค่ากลาง
    $tokenLimit = resolveTokenSetting(
        isset($userTokenData['token_limit']) && $userTokenData['token_limit'] !== null ? (int)$userTokenData['token_limit'] : null,
        'default_token_limit', 0
    );
    $tokenResetHours = resolveTokenSetting(
        isset($userTokenData['token_reset_hours']) && $userTokenData['token_reset_hours'] !== null ? (int)$userTokenData['token_reset_hours'] : null,
        'default_token_reset_hours', 0
    );
    $tokensResetAt = $userTokenData['tokens_reset_at'] ?? null;

    // ตรวจสอบว่าถึงเวลา auto-reset หรือยัง
    if ($tokenResetHours > 0) {
        $shouldReset = false;
        if ($tokensResetAt === null) {
            $shouldReset = true; // ยังไม่เคยตั้งเวลาเริ่มต้น
        } else {
            $nextReset = strtotime($tokensResetAt) + ($tokenResetHours * 3600);
            if (time() >= $nextReset) $shouldReset = true;
        }
        if ($shouldReset) {
            chatDb()->prepare('UPDATE users SET tokens_used=0, tokens_reset_at=NOW() WHERE id=?')->execute([$currentUserId]);
            $tokensUsed = 0;
        }
    }

    if ($tokenLimit > 0 && $tokensUsed >= $tokenLimit) {
        // คำนวณเวลา reset ถัดไป (ถ้ามี)
        $resetMsg = '';
        if ($tokenResetHours > 0 && $tokensResetAt) {
            $nextReset = strtotime($tokensResetAt) + ($tokenResetHours * 3600);
            $diff = $nextReset - time();
            if ($diff > 0) {
                $h = floor($diff / 3600);
                $m = floor(($diff % 3600) / 60);
                $resetMsg = " (รีเซ็ตในอีก {$h}ชม. {$m}น.)";
            }
        }
        echo "data: " . json_encode(['error' => "เกินขีดจำกัด token แล้ว ({$tokensUsed}/{$tokenLimit}){$resetMsg} กรุณาติดต่อผู้ดูแลระบบ"]) . "\n\n";
        flush();
        exit;
    }

    // ── Create or update conversation record in DB ────────────────────────
    if ($convId > 0) {
        // Verify ownership
        $chk = chatDb()->prepare('SELECT id FROM conversations WHERE id = ? AND user_id = ?');
        $chk->execute([$convId, $currentUserId]);
        if (!$chk->fetch()) $convId = 0;
    }

    if ($convId === 0) {
        $ins = chatDb()->prepare('INSERT INTO conversations (user_id, title, model) VALUES (?,?,?)');
        $ins->execute([$currentUserId, mb_substr($convTitle, 0, 490), $requestModel]);
        $convId = (int)chatDb()->lastInsertId();
    } else {
        chatDb()->prepare('UPDATE conversations SET model=?, updated_at=NOW() WHERE id=?')->execute([$requestModel, $convId]);
    }

    // Check storage limit if files attached
    if ($fileUuids && $convId) {
        $limitMb  = (int)(chatDb()->query("SELECT value FROM settings WHERE `key`='conv_storage_limit_mb'")->fetchColumn() ?: 200);
        $stUsed   = chatDb()->prepare('SELECT COALESCE(SUM(size),0) FROM conversation_files WHERE conversation_id=?');
        $stUsed->execute([$convId]);
        $usedBytes = (int)$stUsed->fetchColumn();
        $ph        = implode(',', array_fill(0, count($fileUuids), '?'));
        $stNew     = chatDb()->prepare("SELECT COALESCE(SUM(size),0) FROM conversation_files WHERE uuid IN ($ph)");
        $stNew->execute($fileUuids);
        $newBytes  = (int)$stNew->fetchColumn();
        if (($usedBytes + $newBytes) > $limitMb * 1024 * 1024) {
            echo "data: " . json_encode(['error' => "เกินขีดจำกัดพื้นที่เก็บไฟล์ต่อการสนทนา ({$limitMb} MB)"]) . "\n\n"; flush(); exit;
        }
    }

    // Save the last user message to DB (extract text from content array or plain string)
    $lastUserMsg = null;
    for ($i = count($requestMessages) - 1; $i >= 0; $i--) {
        if ($requestMessages[$i]['role'] === 'user') {
            $c = $requestMessages[$i]['content'];
            if (is_array($c)) {
                $textParts = array_filter($c, fn($p) => ($p['type'] ?? '') === 'text');
                $lastUserMsg = implode(' ', array_column(array_values($textParts), 'text'));
            } else {
                $lastUserMsg = $c;
            }
            break;
        }
    }
    $userMsgId = null;
    if ($lastUserMsg !== null) {
        $ins = chatDb()->prepare('INSERT INTO messages (conversation_id, role, content) VALUES (?,?,?)');
        $ins->execute([$convId, 'user', $lastUserMsg]);
        $userMsgId = (int)chatDb()->lastInsertId();
    }

    // Associate uploaded files with this conversation + message
    if ($fileUuids && $userMsgId) {
        $ph = implode(',', array_fill(0, count($fileUuids), '?'));
        chatDb()->prepare("UPDATE conversation_files SET conversation_id=?, message_id=? WHERE uuid IN ($ph) AND user_id=? AND conversation_id IS NULL")
            ->execute([$convId, $userMsgId, ...$fileUuids, $currentUserId]);
    }

    // Build multimodal content for API from stored files
    if ($fileUuids) {
        $ph    = implode(',', array_fill(0, count($fileUuids), '?'));
        $fRows = chatDb()->prepare("SELECT uuid, original_name, mime_type FROM conversation_files WHERE uuid IN ($ph)");
        $fRows->execute($fileUuids);
        $fileRows = $fRows->fetchAll(PDO::FETCH_ASSOC);

        // Replace last user message content with multimodal array
        for ($i = count($requestMessages) - 1; $i >= 0; $i--) {
            if ($requestMessages[$i]['role'] === 'user') {
                $textContent = is_string($requestMessages[$i]['content'])
                    ? $requestMessages[$i]['content']
                    : (implode(' ', array_column(array_filter((array)$requestMessages[$i]['content'], fn($p)=>($p['type']??'')===('text')), 'text')));
                $parts = [['type'=>'text','text'=>$textContent]];
                foreach ($fileRows as $fr) {
                    $glob = glob(uploadDir() . '/' . $fr['uuid'] . '*');
                    if (!$glob) continue;
                    $b64  = base64_encode(file_get_contents($glob[0]));
                    if (str_starts_with($fr['mime_type'], 'image/')) {
                        $parts[] = ['type'=>'image_url','image_url'=>['url'=>"data:{$fr['mime_type']};base64,{$b64}"]];
                    } else {
                        $text = base64_decode($b64);
                        $parts[] = ['type'=>'text','text'=>"\n\n[ไฟล์: {$fr['original_name']}]\n```\n{$text}\n```"];
                    }
                }
                $requestMessages[$i]['content'] = $parts;
                break;
            }
        }
    } // end if ($fileUuids)

    // Prepend system prompt for API call
    $apiMessages = $requestMessages;
    if (!empty($systemPrompt)) {
        array_unshift($apiMessages, ['role' => 'system', 'content' => $systemPrompt]);
    }

    // Send conv_id back to client first
    echo "data: " . json_encode(['conv_id' => $convId]) . "\n\n";
    flush();

    // ── Resolve API server per model (fallback → global settings) ────────────
    try {
        $srvQ = chatDb()->prepare(
            'SELECT s.base_url, s.api_key FROM api_servers s
             JOIN models m ON m.server_id = s.id
             WHERE m.name = ? AND s.is_active = 1 LIMIT 1'
        );
        $srvQ->execute([$requestModel]);
        $srvRow = $srvQ->fetch(PDO::FETCH_ASSOC);
        if ($srvRow && !empty($srvRow['base_url'])) {
            $baseUrl = rtrim($srvRow['base_url'], '/');
            $apiKey  = $srvRow['api_key'];
        }
    } catch (Throwable) { /* api_servers table may not exist yet — use global */ }

    // stream_options: include_usage รองรับเฉพาะ OpenAI / provider ที่ compatible
    // Ollama บางรุ่นอาจ error ถ้าไม่รู้จัก field นี้ → ใช้ fallback estimation แทน
    $isOpenAI = str_contains($baseUrl, 'openai.com') || str_contains($baseUrl, 'openrouter.ai');
    $postBody  = [
        'model'      => $requestModel,
        'messages'   => $apiMessages,
        'max_tokens' => $maxTokens,
        'stream'     => true,
    ];
    if ($isOpenAI) $postBody['stream_options'] = ['include_usage' => true];
    $postData = json_encode($postBody);

    $fullResponse  = '';
    $totalTokens   = 0;   // จาก API usage field (ถ้ามี)

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $baseUrl . '/chat/completions',
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'Accept: text/event-stream',
        ],
        CURLOPT_TIMEOUT        => 0,    // ไม่จำกัด — ปล่อยให้ streaming จบเองตาม AI response
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$fullResponse, &$totalTokens) {
            $lines = explode("\n", $chunk);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                if (str_starts_with($line, 'data: ')) {
                    $jsonStr = substr($line, 6);
                    if ($jsonStr === '[DONE]') {
                        echo "data: [DONE]\n\n";
                        flush();
                        continue;
                    }
                    $json = json_decode($jsonStr, true);
                    if (!$json) continue;
                    // Capture token usage: OpenAI top-level usage field
                    if (isset($json['usage']['total_tokens'])) {
                        $totalTokens = (int)$json['usage']['total_tokens'];
                    }
                    // Ollama native (non-OpenAI compat) format
                    if (isset($json['prompt_eval_count']) || isset($json['eval_count'])) {
                        $totalTokens = (int)($json['prompt_eval_count'] ?? 0) + (int)($json['eval_count'] ?? 0);
                    }
                    if (isset($json['choices'][0]['delta']['content'])) {
                        $token = $json['choices'][0]['delta']['content'];
                        $fullResponse .= $token;
                        echo "data: " . json_encode(['content' => $token]) . "\n\n";
                        flush();
                    }
                    if (isset($json['error'])) {
                        echo "data: " . json_encode(['error' => $json['error']['message']]) . "\n\n";
                        flush();
                    }
                } elseif (str_starts_with($line, '{')) {
                    // Ollama native streaming (no "data: " prefix)
                    $json = json_decode($line, true);
                    if ($json && (isset($json['prompt_eval_count']) || isset($json['eval_count']))) {
                        $totalTokens = (int)($json['prompt_eval_count'] ?? 0) + (int)($json['eval_count'] ?? 0);
                    }
                }
            }
            return strlen($chunk);
        },
    ]);

    curl_exec($ch);

    if (curl_errno($ch)) {
        echo "data: " . json_encode(['error' => 'Connection error: ' . curl_error($ch)]) . "\n\n";
        flush();
    }
    curl_close($ch);

    // Save assistant response to DB
    if ($fullResponse !== '') {
        try {
            chatDb()->prepare('INSERT INTO messages (conversation_id, role, content) VALUES (?,?,?)')
                ->execute([$convId, 'assistant', $fullResponse]);
        } catch (Throwable) { /* log silently */ }
    }

    // Update token usage — always runs even if fullResponse was empty
    // (ใช้ fallback estimation จาก chars เสมอถ้า API ไม่ส่ง usage กลับมา)
    if ($totalTokens === 0) {
        $inputChars  = array_sum(array_map(fn($m) => mb_strlen($m['content'] ?? ''), $requestMessages));
        $outputChars = mb_strlen($fullResponse);
        $totalTokens = (int)ceil(($inputChars + $outputChars) / 3.5);
    }
    if ($currentUserId) {
        // อัปเดต DB (ถ้ามี token ที่ใช้)
        if ($totalTokens > 0) {
            try {
                chatDb()->prepare('UPDATE users SET tokens_used = tokens_used + ?, tokens_total = tokens_total + ? WHERE id = ?')
                    ->execute([$totalTokens, $totalTokens, $currentUserId]);
            } catch (Throwable) { /* log silently */ }
        }
        // ส่งค่าปัจจุบันกลับ client เสมอ (ไม่ว่า UPDATE สำเร็จหรือไม่)
        try {
            $nr = chatDb()->prepare('SELECT tokens_used, tokens_total FROM users WHERE id = ?');
            $nr->execute([$currentUserId]);
            $nrRow = $nr->fetch(PDO::FETCH_ASSOC) ?: [];
            echo 'data: ' . json_encode([
                'token_update' => true,
                'tokens_used'  => (int)($nrRow['tokens_used']  ?? 0),
                'tokens_total' => (int)($nrRow['tokens_total'] ?? 0),
            ]) . "\n\n";
            flush();
        } catch (Throwable) { /* log silently */ }
    }

    exit;
}

// ─── Login Page ───────────────────────────────────────────────────────────────
if (!$authenticated):
$loginError = $loginError ?? '';
// กำหนดแท็บเริ่มต้น: ถ้า register สำเร็จหรือมี error ให้แสดงแท็บ register
$initTab = ($registerSuccess || $registerError) ? 'register' : 'login';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($siteName) ?> — เข้าสู่ระบบ</title>
    <?= themeAntiFlash() ?>
    <?= themeFavicon() ?>
    <style>
        <?= themeVars() ?>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Söhne','ui-sans-serif',system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:var(--chat-bg);color:var(--text-chat);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
        .login-container{background:var(--panel-bg);border-radius:16px;box-shadow:0 4px 24px var(--shadow);text-align:center;max-width:420px;width:100%;overflow:hidden}
        .lc-head{padding:32px 36px 0}
        .lc-body{padding:0 36px 36px}
        h1{font-size:22px;margin-bottom:6px;font-weight:700}
        .sub{color:var(--muted-chat);margin-bottom:0;font-size:14px}
        /* ── Tabs ── */
        .tabs{display:flex;border-bottom:1px solid var(--border3);margin:20px 0 22px}
        .tab-btn{flex:1;padding:10px 0;font-size:14px;font-weight:600;color:var(--muted-chat);background:none;border:none;border-bottom:2px solid transparent;cursor:pointer;transition:.2s;font-family:inherit;margin-bottom:-1px}
        .tab-btn.active{color:#10a37f;border-bottom-color:#10a37f}
        .tab-btn:hover:not(.active){color:var(--text-chat)}
        /* ── Forms ── */
        .auth-form{display:none;flex-direction:column;gap:14px}
        .auth-form.active{display:flex}
        .auth-form input{padding:13px 15px;border-radius:11px;border:1px solid var(--border3);background:var(--chat-bg);color:var(--text-chat);font-size:15px;outline:none;transition:.2s;text-align:left;width:100%}
        .auth-form input:focus{border-color:#10a37f;box-shadow:0 0 0 3px rgba(16,163,127,.15)}
        .auth-form input::placeholder{color:var(--muted-chat)}
        .auth-form button[type="submit"]{padding:14px;border-radius:12px;border:none;background:#10a37f;color:#fff;font-size:15px;font-weight:600;cursor:pointer;transition:.2s}
        .auth-form button[type="submit"]:hover{background:#0d8f6d}
        .logo{width:52px;height:52px;margin:0 auto 16px;background:linear-gradient(135deg,#10a37f,#059669);border-radius:14px;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(16,163,127,.35)}
        .logo svg{width:28px;height:28px;stroke:white;fill:none}
        .err{background:rgba(255,77,77,.12);border:1px solid rgba(255,77,77,.3);border-radius:9px;padding:10px 14px;color:#ff6b6b;font-size:13px;text-align:left}
        .ok{background:rgba(16,163,127,.12);border:1px solid rgba(16,163,127,.3);border-radius:9px;padding:12px 15px;color:#34d399;font-size:13px;text-align:left;line-height:1.5}
        .ok strong{display:block;font-size:14px;margin-bottom:3px}
        .hint{font-size:12px;color:var(--muted-chat);text-align:left;margin-top:-6px}
        .reg-disabled{background:rgba(100,116,139,.1);border:1px solid var(--border3);border-radius:9px;padding:14px;color:var(--muted-chat);font-size:14px}
    </style>
</head>
<body>
    <div class="login-container">
        <div class="lc-head">
            <div class="logo">
                <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                </svg>
            </div>
            <h1><?= htmlspecialchars($siteName) ?></h1>
            <p class="sub">ยินดีต้อนรับ</p>

            <div class="tabs">
                <button class="tab-btn <?= $initTab === 'login' ? 'active' : '' ?>" onclick="switchTab('login')">เข้าสู่ระบบ</button>
                <?php if ($allowRegistration): ?>
                <button class="tab-btn <?= $initTab === 'register' ? 'active' : '' ?>" onclick="switchTab('register')">ลงทะเบียน</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="lc-body">
            <!-- ── Login Form ── -->
            <form class="auth-form <?= $initTab === 'login' ? 'active' : '' ?>" id="formLogin" method="POST">
                <input type="hidden" name="chat_login" value="1">
                <?php if ($loginError): ?><div class="err"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
                <input type="text"     name="username" placeholder="Username" required <?= $initTab==='login'?'autofocus':'' ?> autocomplete="username">
                <input type="password" name="password" placeholder="Password" required autocomplete="current-password">
                <button type="submit">เข้าสู่ระบบ</button>
            </form>
            <div style="text-align:center;margin-top:14px">
                <a href="index.php" style="font-size:13px;color:var(--muted);text-decoration:none;opacity:.8">← กลับหน้าหลัก</a>
            </div>

            <!-- ── Register Form ── -->
            <?php if ($allowRegistration): ?>
            <form class="auth-form <?= $initTab === 'register' ? 'active' : '' ?>" id="formRegister" method="POST" onsubmit="return validateRegister()">
                <input type="hidden" name="chat_register" value="1">
                <?php if ($registerError): ?><div class="err"><?= htmlspecialchars($registerError) ?></div><?php endif; ?>
                <?php if ($registerSuccess): ?>
                <div class="ok"><strong>✅ ส่งคำขอสำเร็จ!</strong><?= $registerSuccess ?></div>
                <?php endif; ?>
                <?php if (!$registerSuccess): ?>
                <input type="text" name="reg_username" placeholder="Username (a-z, 0-9, . _ -)" required <?= $initTab==='register'?'autofocus':'' ?> autocomplete="username" pattern="[a-zA-Z0-9._-]+" minlength="3" value="<?= htmlspecialchars($_POST['reg_username'] ?? '') ?>">
                <input type="text" name="reg_display"  placeholder="ชื่อที่ต้องการแสดง (ไม่บังคับ)" autocomplete="name" value="<?= htmlspecialchars($_POST['reg_display'] ?? '') ?>">
                <input type="password" name="reg_password" placeholder="รหัสผ่าน (อย่างน้อย 6 ตัว)" required id="regPass" autocomplete="new-password">
                <input type="password" name="reg_confirm"  placeholder="ยืนยันรหัสผ่าน" required id="regConfirm" autocomplete="new-password">
                <p class="hint">📋 <?= htmlspecialchars($registrationNote) ?></p>
                <button type="submit">ส่งคำขอลงทะเบียน</button>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?= themeToggleBtn('theme-float') ?>
    <?= themeScript() ?>
    <script>
    function switchTab(tab) {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
        document.querySelector('.tab-btn[onclick="switchTab(\''+tab+'\')"]').classList.add('active');
        var form = tab === 'login' ? document.getElementById('formLogin') : document.getElementById('formRegister');
        if (form) { form.classList.add('active'); form.querySelector('input:not([type=hidden])').focus(); }
    }
    function validateRegister() {
        var p = document.getElementById('regPass');
        var c = document.getElementById('regConfirm');
        if (!p || !c) return true;
        if (p.value !== c.value) { c.setCustomValidity('รหัสผ่านไม่ตรงกัน'); c.reportValidity(); return false; }
        c.setCustomValidity('');
        return true;
    }
    document.getElementById('regConfirm') && document.getElementById('regConfirm').addEventListener('input', function(){ this.setCustomValidity(''); });
    </script>
</body>
</html>
<?php
exit;
endif;

// ============================================================================
// MAIN CHAT INTERFACE (authenticated)
// ============================================================================
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($siteName) ?></title>
    <?= themeAntiFlash() ?>
    <?= themeFavicon() ?>
    <!-- External Libraries -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <!-- KaTeX — render LaTeX math ($...$ and $$...$$) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/contrib/auto-render.min.js"
        onload="window._katexReady=true"></script>

    <style>
        /* ================================================================
           THEME VARIABLES
           ================================================================ */
        <?= themeVars() ?>

        /* ================================================================
           CSS RESET & BASE STYLES
           ================================================================ */
        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html, body {
            height: 100%;
            overflow: hidden;
        }
        
        body {
            font-family: 'Söhne', 'ui-sans-serif', system-ui, -apple-system, 'Segoe UI', Roboto, Ubuntu, Cantarell, 'Noto Sans', sans-serif;
            background-color: var(--chat-bg);
            color: var(--text-chat);
            font-size: 16px;
            line-height: 1.6;
        }
        
        /* ================================================================
           LAYOUT STRUCTURE
           ================================================================ */
        .app-container {
            display: flex;
            height: 100vh;
            width: 100%;
        }
        
        /* Sidebar */
        .sidebar {
            width: 260px;
            background-color: var(--sidebar-bg);
            display: flex;
            flex-direction: column;
            padding: 8px;
            flex-shrink: 0;
            transition: transform 0.3s ease;
        }
        
        .sidebar-header {
            padding: 8px;
        }
        
        .new-chat-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border: 1px solid var(--border2);
            border-radius: 8px;
            background: transparent;
            color: var(--text-chat);
            font-size: 14px;
            cursor: pointer;
            width: 100%;
            text-align: left;
            transition: background-color 0.2s;
        }
        
        .new-chat-btn:hover {
            background-color: var(--hover-bg);
        }
        
        .new-chat-btn svg {
            width: 16px;
            height: 16px;
            stroke: currentColor;
            stroke-width: 2;
            fill: none;
        }
        
        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            padding: 8px 0;
        }
        
        .chat-history-title {
            padding: 12px;
            font-size: 12px;
            color: var(--muted-chat);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .chat-history-item {
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 14px;
            color: var(--text-chat);
            cursor: pointer;
            transition: background-color 0.2s;
            /* New Flexbox properties for alignment */
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        
        .chat-history-item:hover {
            background-color: var(--hover-bg);
        }

        .chat-history-item.active {
            background-color: var(--active-bg);
        }

        .chat-history-title-text {
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .delete-chat-btn {
            opacity: 0; /* Hidden by default */
            background: transparent;
            border: none;
            color: var(--muted-chat);
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .chat-history-item:hover .delete-chat-btn {
            opacity: 1; /* Show on hover */
        }

        .delete-chat-btn:hover {
            color: #ff4d4d; /* Red color */
            background-color: rgba(255, 77, 77, 0.1);
        }
        
        .delete-chat-btn svg {
            width: 14px;
            height: 14px;
            stroke: currentColor;
            stroke-width: 2;
            fill: none;
        }
        
        .sidebar-footer {
            border-top: 1px solid var(--border2);
            padding: 8px;
        }
        
        .sidebar-footer-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-radius: 8px;
            background: transparent;
            border: none;
            color: var(--text-chat);
            font-size: 14px;
            cursor: pointer;
            width: 100%;
            text-align: left;
            transition: background-color 0.2s;
        }
        
        .sidebar-footer-btn:hover {
            background-color: var(--hover-bg);
        }
        
        .sidebar-footer-btn svg {
            width: 16px;
            height: 16px;
            stroke: currentColor;
            stroke-width: 2;
            fill: none;
        }
        
        /* Main Content Area */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            position: relative;
        }
        
        /* Header */
        .chat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border2);
            background-color: var(--chat-bg);
            position: relative;
            z-index: 10;
        }
        
        .menu-toggle {
            display: none;
            background: transparent;
            border: none;
            color: var(--text-chat);
            cursor: pointer;
            padding: 8px;
            border-radius: 8px;
            transition: background-color 0.2s;
        }
        
        .menu-toggle:hover {
            background-color: var(--hover-bg);
        }
        
        .menu-toggle svg {
            width: 20px;
            height: 20px;
            stroke: currentColor;
            stroke-width: 2;
            fill: none;
        }
        
        .model-selector {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background-color: transparent;
            border: none;
            border-radius: 8px;
            color: var(--text-chat);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .model-selector:hover {
            background-color: var(--hover-bg);
        }
        
        .model-selector svg {
            width: 16px;
            height: 16px;
            stroke: currentColor;
            stroke-width: 2;
            fill: none;
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Chat Messages Area */
        .chat-container {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            scroll-behavior: smooth;
        }
        
        .chat-messages {
            max-width: 768px;
            margin: 0 auto;
            padding: 24px 16px 140px;
        }
        
        /* Empty State */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            padding: 24px;
            text-align: center;
        }
        
        .empty-state-logo {
            width: 72px;
            height: 72px;
            margin-bottom: 24px;
            opacity: 0.45;
        }

        .empty-state-logo svg {
            width: 100%;
            height: 100%;
            fill: var(--text-chat);
        }

        .empty-state h2 {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-chat);
        }

        .empty-state p {
            color: var(--muted-chat);
            font-size: 16px;
        }
        
        /* Message Styles */
        .message {
            display: flex;
            gap: 16px;
            padding: 24px 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        
        .message:last-child {
            border-bottom: none;
        }
        
        .message-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            font-weight: 700;
            letter-spacing: -.5px;
            position: relative;
        }

        .message.user .message-avatar {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: #fff;
            box-shadow: 0 2px 10px rgba(99,102,241,.45);
        }

        .message.assistant .message-avatar {
            background: linear-gradient(135deg, #0ea5a0 0%, #10a37f 60%, #059669 100%);
            box-shadow: 0 2px 10px rgba(16,163,127,.4);
        }

        .message.assistant .message-avatar svg {
            width: 19px;
            height: 19px;
        }
        
        .message-content {
            flex: 1;
            min-width: 0;
            overflow-wrap: break-word;
            word-break: break-word;
        }
        
        .message-content p {
            margin-bottom: 16px;
        }
        
        .message-content p:last-child {
            margin-bottom: 0;
        }
        
        .message-content ul, .message-content ol {
            margin: 16px 0;
            padding-left: 24px;
        }
        
        .message-content li {
            margin-bottom: 8px;
        }
        
        .message-content h1, .message-content h2, .message-content h3, 
        .message-content h4, .message-content h5, .message-content h6 {
            margin: 24px 0 16px;
            font-weight: 600;
        }
        
        .message-content h1 { font-size: 1.5em; }
        .message-content h2 { font-size: 1.3em; }
        .message-content h3 { font-size: 1.15em; }
        
        .message-content a {
            color: #7ab7ff;
            text-decoration: none;
        }
        
        .message-content a:hover {
            text-decoration: underline;
        }
        
        .message-content strong {
            font-weight: 600;
        }
        
        .message-content em {
            font-style: italic;
        }
        
        .message-content blockquote {
            border-left: 3px solid var(--border3);
            padding-left: 16px;
            margin: 16px 0;
            color: var(--text2);
        }

        .message-content hr {
            border: none;
            border-top: 1px solid var(--border2);
            margin: 24px 0;
        }
        
        /* Code Blocks */
        .message-content code {
            font-family: 'Söhne Mono', 'Monaco', 'Andale Mono', 'Ubuntu Mono', monospace;
            font-size: 14px;
        }
        
        .message-content code:not(pre code) {
            background-color: var(--hover-bg);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.9em;
        }
        
        .message-content pre {
            background-color: var(--code-bg);
            border-radius: 8px;
            margin: 16px 0;
            overflow: hidden;
        }
        
        .code-block-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 16px;
            background-color: var(--code-hd);
            font-size: 12px;
            color: var(--muted-chat);
        }

        .code-block-header .language {
            text-transform: lowercase;
        }

        /* ── Streaming code block ── */
        .streaming-code-block {
            background-color: var(--code-bg);
            border-radius: 8px;
            margin: 16px 0;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,.06);
        }
        .streaming-code-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 16px;
            background-color: var(--code-hd);
            font-size: 12px;
            color: var(--muted-chat);
        }
        .streaming-code-dots {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .streaming-code-dots span {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: #10a37f;
            animation: codePulse 1.2s ease-in-out infinite;
        }
        .streaming-code-dots span:nth-child(2) { animation-delay: .2s; }
        .streaming-code-dots span:nth-child(3) { animation-delay: .4s; }
        @keyframes codePulse {
            0%,100% { opacity: .3; transform: scale(.8); }
            50%      { opacity: 1;  transform: scale(1.1); }
        }
        .streaming-code-body {
            padding: 14px 16px;
            font-family: 'Söhne Mono', 'Monaco', 'Andale Mono', monospace;
            font-size: 13px;
            line-height: 1.6;
            color: #a5b4fc;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 340px;
            overflow-y: auto;
            overflow-x: auto;
            position: relative;
            scroll-behavior: smooth;
        }
        
        .copy-code-btn {
            display: flex;
            align-items: center;
            gap: 4px;
            background: transparent;
            border: none;
            color: var(--muted-chat);
            font-size: 12px;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            transition: all 0.2s;
        }
        
        .copy-code-btn:hover {
            background-color: var(--hover-bg);
            color: var(--text-chat);
        }
        
        .copy-code-btn.copied {
            color: #10a37f;
        }
        
        .copy-code-btn svg {
            width: 14px;
            height: 14px;
            stroke: currentColor;
            stroke-width: 2;
            fill: none;
        }
        
        .message-content pre code {
            display: block;
            padding: 16px;
            overflow-x: auto;
            background: transparent !important;
        }
        
        /* Tables */
        .message-content table {
            width: 100%;
            border-collapse: collapse;
            margin: 16px 0;
        }
        
        .message-content th, .message-content td {
            border: 1px solid var(--border2);
            padding: 10px 14px;
            text-align: left;
        }
        
        .message-content th {
            background-color: var(--msg-user-bg);
            font-weight: 600;
        }
        
        .message-content tr:nth-child(even) {
            background-color: var(--msg-hover);
        }
        
        /* Typing Indicator */
        .typing-indicator {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .typing-indicator span {
            width: 8px;
            height: 8px;
            background-color: var(--muted-chat);
            border-radius: 50%;
            animation: typing 1.4s infinite ease-in-out;
        }
        
        .typing-indicator span:nth-child(1) { animation-delay: 0s; }
        .typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
        .typing-indicator span:nth-child(3) { animation-delay: 0.4s; }
        
        @keyframes typing {
            0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
            30% { transform: translateY(-6px); opacity: 1; }
        }
        
        /* Thinking bubble (before first token arrives) */
        .thinking-bubble {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 4px 0;
        }
        .thinking-text {
            font-size: 13px;
            color: var(--muted-chat);
            font-style: italic;
            opacity: 0;
            animation: thinkFadeIn .5s .3s ease forwards;
        }
        @keyframes thinkFadeIn {
            from { opacity: 0; transform: translateX(-4px); }
            to   { opacity: 1; transform: translateX(0); }
        }

        /* Streaming cursor */
        .streaming-cursor {
            display: inline-block;
            width: 2px;
            height: 1.2em;
            background-color: var(--text-chat);
            margin-left: 2px;
            animation: blink 1s infinite;
            vertical-align: text-bottom;
        }
        
        @keyframes blink {
            0%, 50% { opacity: 1; }
            51%, 100% { opacity: 0; }
        }
        
        /* Input Area */
        .input-container {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 16px;
            background: var(--input-gradient);
        }
        
        .input-wrapper {
            max-width: 768px;
            margin: 0 auto;
        }
        
        /* ── Attachment preview ── */
        .attach-preview {
            display: flex; gap: 8px; flex-wrap: wrap;
            padding: 8px 0 4px;
        }
        .attach-chip {
            display: flex; align-items: center; gap: 6px;
            background: rgba(99,102,241,.15); border: 1px solid rgba(99,102,241,.3);
            border-radius: 8px; padding: 4px 8px; font-size: 12px; color: var(--text-chat);
            max-width: 220px;
        }
        .attach-chip img { width: 28px; height: 28px; object-fit: cover; border-radius: 4px; flex-shrink: 0; }
        .attach-chip .chip-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; }
        .attach-chip .chip-rm {
            cursor: pointer; opacity: .6; flex-shrink: 0; font-size: 14px; line-height: 1;
            background: none; border: none; color: inherit; padding: 0 2px;
        }
        .attach-chip .chip-rm:hover { opacity: 1; }
        .attach-btn {
            width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--border2);
            background: transparent; color: var(--muted-chat); cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: .2s; flex-shrink: 0;
        }
        .attach-btn:hover { background: var(--hover-bg); color: var(--text-chat); border-color: rgba(99,102,241,.5); }
        .attach-btn svg { width: 16px; height: 16px; }
        /* ── Message file attachments ── */
        .msg-img {
            max-height: 240px; max-width: 320px; border-radius: 10px;
            margin-top: 8px; display: block; cursor: zoom-in;
            transition: opacity .15s;
        }
        .msg-img:hover { opacity: .88; }
        .msg-file-chip {
            display: inline-flex; align-items: center; gap: 6px;
            margin-top: 6px; padding: 6px 10px;
            background: rgba(99,102,241,.12); border: 1px solid rgba(99,102,241,.25);
            border-radius: 8px; font-size: 12px; color: var(--text-chat);
            text-decoration: none; cursor: pointer; transition: .15s;
        }
        .msg-file-chip:hover { background: rgba(99,102,241,.22); border-color: rgba(99,102,241,.45); }
        /* ── Lightbox ── */
        #lightbox {
            display: none; position: fixed; inset: 0; z-index: 9999;
            background: rgba(0,0,0,.88); align-items: center; justify-content: center;
            cursor: zoom-out;
        }
        #lightbox.open { display: flex; }
        #lightbox img {
            max-width: 92vw; max-height: 92vh; border-radius: 12px;
            box-shadow: 0 20px 80px rgba(0,0,0,.6); object-fit: contain;
            cursor: default;
        }
        #lightboxClose {
            position: fixed; top: 18px; right: 22px; font-size: 32px; color: #fff;
            cursor: pointer; line-height: 1; opacity: .7; transition: .15s;
            background: none; border: none; padding: 0;
        }
        #lightboxClose:hover { opacity: 1; }

        .input-box {
            display: flex;
            flex-direction: column;
            background-color: var(--panel-bg);
            border: 1px solid var(--border2);
            border-radius: 16px;
            padding: 12px 16px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .input-row {
            display: flex; align-items: flex-end; gap: 12px;
        }
        
        .input-box:focus-within {
            border-color: rgba(255,255,255,0.3);
            box-shadow: 0 0 0 1px rgba(255,255,255,0.1);
        }
        
        .input-box textarea {
            flex: 1;
            background: transparent;
            border: none;
            outline: none;
            color: var(--text-chat);
            font-size: 16px;
            font-family: inherit;
            line-height: 1.5;
            resize: none;
            max-height: 200px;
            min-height: 24px;
        }
        
        .input-box textarea::placeholder {
            color: var(--muted-chat);
        }
        
        .send-btn {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: none;
            background-color: #10a37f;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s, opacity 0.2s;
            flex-shrink: 0;
        }
        
        .send-btn:hover:not(:disabled) {
            background-color: #1a7f64;
        }
        
        .send-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .send-btn svg {
            width: 18px;
            height: 18px;
            stroke: currentColor;
            stroke-width: 2;
            fill: none;
        }
        
        .input-footer {
            padding: 10px 16px 8px;
            font-size: 12px;
            color: var(--muted-chat);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .input-footer a {
            color: var(--muted-chat);
            text-decoration: underline;
        }
        /* ── Token Donut ── */
        .token-donut-wrap {
            display: flex;
            align-items: center;
            cursor: pointer;
            gap: 7px;
            font-size: 11px;
            color: var(--muted-chat);
            cursor: default;
            user-select: none;
        }
        .token-donut-wrap.hidden { display: none; }
        .token-donut {
            position: relative;
            width: 28px;
            height: 28px;
            flex-shrink: 0;
        }
        .token-donut svg { width: 28px; height: 28px; transform: rotate(-90deg); }
        .token-donut .track { fill: none; stroke: var(--border2); stroke-width: 4; }
        .token-donut .fill  { fill: none; stroke-width: 4; stroke-linecap: butt;
                              transition: stroke-dasharray .4s ease, stroke .3s; }
        .token-donut .pct-lbl {
            position: absolute; inset: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 7px; font-weight: 700; color: var(--text2); line-height: 1;
        }
        .token-info { display: flex; flex-direction: column; gap: 1px; }
        .token-info .ti-main { font-size: 11px; font-weight: 600; color: var(--text2); }
        .token-info .ti-sub  { font-size: 10px; color: var(--muted-chat); }
        #tokenResetLbl        { border-radius: 2px; padding: 2px 0 2px 6px; }
        #tokenResetLbl .rl-time  { font-weight: 700; display: block; }
        #tokenResetLbl .rl-label { font-size: 10px; opacity: .8; display: block; }

        /* ── Token Detail Popup ── */
        #tokenPopup {
            position: fixed; inset: 0; z-index: 1000;
            display: flex; align-items: flex-end; justify-content: center;
            pointer-events: none;
        }
        #tokenPopup.open { pointer-events: all; }
        #tokenPopup .tp-backdrop {
            position: absolute; inset: 0;
            background: rgba(0,0,0,.4);
            opacity: 0; transition: opacity .2s;
        }
        #tokenPopup.open .tp-backdrop { opacity: 1; }
        #tokenPopup .tp-box {
            position: relative; z-index: 1;
            background: var(--bg2, #1e1e2e);
            border: 1px solid var(--border2, rgba(255,255,255,.12));
            border-radius: 18px 18px 0 0;
            padding: 20px 22px 28px;
            width: 100%; max-width: 420px;
            transform: translateY(100%);
            transition: transform .25s cubic-bezier(.4,0,.2,1);
            box-shadow: 0 -8px 32px rgba(0,0,0,.4);
        }
        #tokenPopup.open .tp-box { transform: translateY(0); }
        .tp-handle {
            width: 36px; height: 4px; border-radius: 2px;
            background: var(--border2, rgba(255,255,255,.2));
            margin: 0 auto 16px;
        }
        .tp-title { font-size: 15px; font-weight: 700; margin-bottom: 16px; color: var(--text, #fff); }
        .tp-donut-row {
            display: flex; align-items: center; gap: 16px; margin-bottom: 18px;
        }
        .tp-donut-big { position: relative; width: 72px; height: 72px; flex-shrink: 0; }
        .tp-donut-big svg { width: 72px; height: 72px; transform: rotate(-90deg); }
        .tp-donut-big .track { fill: none; stroke: var(--border2, rgba(255,255,255,.1)); stroke-width: 5; }
        .tp-donut-big .fill  { fill: none; stroke-width: 5; stroke-linecap: butt;
                               transition: stroke-dasharray .5s ease, stroke .3s; }
        .tp-donut-big .pct-center {
            position: absolute; inset: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; font-weight: 800; color: var(--text, #fff);
        }
        .tp-stats { flex: 1; display: flex; flex-direction: column; gap: 6px; }
        .tp-row { display: flex; justify-content: space-between; align-items: baseline; font-size: 13px; }
        .tp-row .lbl { color: var(--muted, #888); }
        .tp-row .val { font-weight: 700; color: var(--text, #fff); }
        .tp-divider { height: 1px; background: var(--border2, rgba(255,255,255,.08)); margin: 12px 0; }
        .tp-reset-box {
            border-radius: 10px; padding: 10px 14px;
            background: var(--bg3, rgba(255,255,255,.05));
            font-size: 12px; line-height: 1.6; color: var(--muted, #888);
        }
        .tp-reset-box strong { color: var(--text2, #ccc); }
        
        /* Stop Button */
        .stop-btn {
            display: none;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: 1px solid var(--border2);
            background-color: transparent;
            color: var(--text-chat);
            cursor: pointer;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s;
            flex-shrink: 0;
        }
        
        .stop-btn:hover {
            background-color: var(--hover-bg);
        }
        
        .stop-btn svg {
            width: 14px;
            height: 14px;
            fill: currentColor;
        }
        
        .is-streaming .stop-btn {
            display: flex;
        }
        
        .is-streaming .send-btn {
            display: none;
        }
        
        /* Error Message */
        .error-message {
            background-color: rgba(255, 77, 77, 0.1);
            border: 1px solid rgba(255, 77, 77, 0.3);
            border-radius: 8px;
            padding: 12px 16px;
            color: #ff6b6b;
            margin: 16px 0;
            font-size: 14px;
        }
        
        /* Scrollbar Styles */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: transparent;
        }
        
        ::-webkit-scrollbar-thumb {
            background-color: var(--scrollbar);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background-color: var(--scrollbar);
            opacity: .8;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                left: 0;
                top: 0;
                bottom: 0;
                z-index: 100;
                transform: translateX(-100%);
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: var(--overlay);
                z-index: 99;
            }
            
            .sidebar-overlay.show {
                display: block;
            }
            
            .menu-toggle {
                display: flex;
            }
            
            .chat-messages {
                padding: 16px 12px 160px;
            }
            
            .message {
                gap: 12px;
                padding: 16px 0;
            }
            
            .message-avatar {
                width: 32px;
                height: 32px;
                font-size: 13px;
            }
            
            .input-container {
                padding: 12px;
            }
            
            .input-box {
                padding: 10px 12px;
            }
        }
        
        /* Model dropdown */
        .model-dropdown {
            position: relative;
        }
        
        .model-dropdown-menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            margin-top: 8px;
            background-color: var(--panel-bg);
            border: 1px solid var(--border2);
            border-radius: 12px;
            padding: 8px;
            min-width: max-content;
            max-width: min(480px, 90vw);
            max-height: 60vh;
            overflow-y: auto;
            box-shadow: 0 4px 24px rgba(0,0,0,0.3);
            z-index: 50;
        }

        .model-dropdown-menu.show {
            display: block;
        }

        .model-option {
            padding: 10px 14px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            white-space: nowrap;
            transition: background-color 0.2s;
        }
        
        .model-option:hover {
            background-color: var(--hover-bg);
        }
        
        .model-option.active {
            background-color: rgba(16, 163, 127, 0.2);
            color: #10a37f;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar Overlay (mobile) -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <button class="new-chat-btn" id="newChatBtn">
                    <svg viewBox="0 0 24 24">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    New chat
                </button>
            </div>

            <div class="sidebar-content">
                <div class="chat-history-title">Recent Chats</div>
                <div id="chatHistory">
                    <!-- populated by JS via DB API -->
                </div>
            </div>

            <div class="sidebar-footer">
                <div class="sidebar-footer-btn" style="cursor:default;color:var(--muted-chat);font-size:13px;gap:8px">
                    <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:currentColor;stroke-width:2;fill:none">
                        <circle cx="12" cy="8" r="4"></circle><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"></path>
                    </svg>
                    <?= htmlspecialchars($currentUsername) ?>
                </div>
                <a href="?logout=1" class="sidebar-footer-btn">
                    <svg viewBox="0 0 24 24">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                    ออกจากระบบ
                </a>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="chat-header">
                <button class="menu-toggle" id="menuToggle">
                    <svg viewBox="0 0 24 24">
                        <line x1="3" y1="12" x2="21" y2="12"></line>
                        <line x1="3" y1="6" x2="21" y2="6"></line>
                        <line x1="3" y1="18" x2="21" y2="18"></line>
                    </svg>
                </button>
                
                <div class="model-dropdown">
                    <?php if ($noModels): ?>
                    <span class="model-selector" style="cursor:default;opacity:.5;color:var(--muted)">
                        ⚠️ ไม่มี AI Model
                    </span>
                    <?php else: ?>
                    <button class="model-selector" id="modelSelector">
                        <span id="currentModel"><?= htmlspecialchars($model) ?></span>
                        <svg viewBox="0 0 24 24">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </button>
                    <div class="model-dropdown-menu" id="modelDropdown">
                        <?php
                        if (!empty($__modelsData)) {
                            foreach ($__modelsData as $m):
                                $modelLabel = $m['label'] ?: $m['name'];
                        ?>
                        <div class="model-option" data-model="<?= htmlspecialchars($m['name']) ?>">
                            <?php if (!empty($m['server_nick'])): ?>
                            <span style="font-size:11px;opacity:.6;margin-right:4px">(<?= htmlspecialchars($m['server_nick']) ?>)</span>
                            <?php endif; ?>
                            <strong><?= htmlspecialchars($modelLabel) ?></strong>
                        </div>
                        <?php   endforeach;
                        } else {
                            foreach (array_filter(array_map('trim', explode(',', $modelsList))) as $m):
                        ?>
                        <div class="model-option" data-model="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></div>
                        <?php   endforeach;
                        } ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="header-actions">
                    <?= themeToggleBtn() ?>
                </div>
            </header>
            
            <!-- Chat Container -->
            <div class="chat-container" id="chatContainer">
                <div class="chat-messages" id="chatMessages">
                    <!-- Empty State -->
                    <div class="empty-state" id="emptyState">
                        <div class="empty-state-logo">
                            <svg viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                                <!-- sparkle ใหญ่ตรงกลาง -->
                                <path d="M12 2 C12 2 12.6 6.5 14.5 8.5 C16.4 10.5 21 12 21 12 C21 12 16.4 13.5 14.5 15.5 C12.6 17.5 12 22 12 22 C12 22 11.4 17.5 9.5 15.5 C7.6 13.5 3 12 3 12 C3 12 7.6 10.5 9.5 8.5 C11.4 6.5 12 2 12 2Z"/>
                                <!-- sparkle เล็กบนขวา -->
                                <path d="M19 3 C19 3 19.35 5 20.25 5.9 C21.15 6.8 23 7.25 23 7.25 C23 7.25 21.15 7.7 20.25 8.6 C19.35 9.5 19 11.5 19 11.5 C19 11.5 18.65 9.5 17.75 8.6 C16.85 7.7 15 7.25 15 7.25 C15 7.25 16.85 6.8 17.75 5.9 C18.65 5 19 3 19 3Z"/>
                                <!-- sparkle เล็กล่างซ้าย -->
                                <path d="M5 14.5 C5 14.5 5.3 16 6 16.7 C6.7 17.4 8.25 17.75 8.25 17.75 C8.25 17.75 6.7 18.1 6 18.8 C5.3 19.5 5 21 5 21 C5 21 4.7 19.5 4 18.8 C3.3 18.1 1.75 17.75 1.75 17.75 C1.75 17.75 3.3 17.4 4 16.7 C4.7 16 5 14.5 5 14.5Z"/>
                            </svg>
                        </div>
                        <h2>สวัสดี, <?= htmlspecialchars($currentUsername) ?>!</h2>
                        <p>วันนี้ฉันช่วยอะไรคุณได้บ้าง?</p>
                    </div>
                </div>
            </div>
            
            <!-- Input Area -->
            <div class="input-container">
                <div class="input-wrapper">
                    <?php if ($noModels): ?>
                    <div style="width:100%;padding:14px 16px;background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.25);border-radius:12px;font-size:13px;color:#fbbf24;text-align:center">
                        ⚠️ ขณะนี้ไม่มี AI Model ที่เปิดใช้งาน — กรุณาติดต่อผู้ดูแลระบบ
                    </div>
                    <?php else: ?>
                    <input type="file" id="fileInput" multiple accept="image/*,.pdf,.txt,.md,.csv,.json,.py,.js,.ts,.php,.html,.css,.xml,.yaml,.yml" style="display:none">
                    <div class="input-box" id="inputBox">
                        <div id="attachPreview" class="attach-preview" style="display:none"></div>
                        <div class="input-row">
                            <button class="attach-btn" id="attachBtn" type="button" title="แนบไฟล์หรือรูปภาพ">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                                </svg>
                            </button>
                            <textarea
                                id="messageInput"
                                placeholder="Message AI..."
                                rows="1"
                                autofocus
                            ></textarea>
                            <button class="send-btn" id="sendBtn" disabled>
                                <svg viewBox="0 0 24 24">
                                    <line x1="22" y1="2" x2="11" y2="13"></line>
                                    <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                                </svg>
                            </button>
                            <button class="stop-btn" id="stopBtn">
                                <svg viewBox="0 0 24 24">
                                    <rect x="6" y="6" width="12" height="12" rx="2"></rect>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div class="input-footer">
                        <!-- Token Donut Chart -->
                        <div class="token-donut-wrap <?= ($userTokenLimit <= 0) ? 'hidden' : '' ?>" id="tokenDonutWrap" title="คลิกเพื่อดูรายละเอียด" onclick="openTokenPopup()">
                            <div class="token-donut" id="tokenDonut">
                                <svg viewBox="0 0 36 36" xmlns="http://www.w3.org/2000/svg">
                                    <circle class="track" cx="18" cy="18" r="15.9"/>
                                    <circle class="fill"  cx="18" cy="18" r="15.9" id="donutFill"
                                        stroke-dasharray="0 100"
                                        pathLength="100"/>
                                </svg>
                                <div class="pct-lbl" id="donutPct">0%</div>
                            </div>
                            <div class="token-info">
                                <div class="ti-main" id="tokenUsedLbl">—</div>
                                <div class="ti-sub"  id="tokenLimitLbl">—</div>
                            </div>
                            <!-- reset time — แสดงเมื่อมีข้อมูล reset -->
                            <div id="tokenResetLbl" style="display:none;font-size:11px;padding-left:4px;border-left:2px solid;line-height:1.4"></div>
                        </div>
                        <!-- only show separator when donut is visible -->
                        <span id="tokenSep" <?= ($userTokenLimit <= 0) ? 'style="display:none"' : '' ?>>·</span>
                        <span>AI can make mistakes. Consider checking important information.</span>
                    </div>
                    </div><?php endif; // $noModels ?>
            </div>
        </main>
    </div>
    
    <script>
    $(document).ready(function() {
        // ── Token Donut ──────────────────────────────────────────────────
        const TOKEN_LIMIT         = <?= (int)($userTokenLimit ?? 0) ?>;
        const TOKEN_USED_INIT     = <?= (int)($userTokensUsed ?? 0) ?>;
        const TOKEN_SECS_LEFT_INIT = <?= isset($userSecsLeft) && $userSecsLeft !== null ? (int)$userSecsLeft : 'null' ?>;

        function fmtTokens(n) {
            if (n >= 1_000_000) return (n/1_000_000).toFixed(1) + 'M';
            if (n >= 1_000)     return (n/1_000).toFixed(1) + 'K';
            return n.toString();
        }

        let _tokenCountdown = null; // interval id

        // แปลง seconds เป็นข้อความ "เหลือ Xชม. Xน." หรือ "เหลือ Xน. Xว."
        function fmtSecsShort(s) {
            const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
            if (h > 0) return `${h}ชม. ${m}น.`;
            if (m > 0) return `${m}น. ${sec}ว.`;
            return `${sec}ว.`;
        }

        // แปลง secsLeft เป็นเวลาจริงที่จะรีเซ็ต เช่น "วันนี้ 16:30 น." / "พรุ่งนี้ 08:00 น."
        function fmtResetTime(secsLeft) {
            const d   = new Date(Date.now() + secsLeft * 1000);
            const hh  = String(d.getHours()).padStart(2,'0');
            const mm  = String(d.getMinutes()).padStart(2,'0');
            const timeStr = `${hh}:${mm} น.`;
            const todayStart = new Date(); todayStart.setHours(0,0,0,0);
            const dStart     = new Date(d); dStart.setHours(0,0,0,0);
            const diffDays   = Math.round((dStart - todayStart) / 86400000);
            if (diffDays === 0) return `วันนี้ ${timeStr}`;
            if (diffDays === 1) return `พรุ่งนี้ ${timeStr}`;
            const days = ['อา.','จ.','อ.','พ.','พฤ.','ศ.','ส.'];
            return `${days[d.getDay()]} ${timeStr}`;
        }

        // อัปเดต reset label (element หลัง token-info)
        function updateResetLabel(secsLeft) {
            const el = document.getElementById('tokenResetLbl');
            if (!el) return;
            if (secsLeft == null || secsLeft <= 0) { el.style.display = 'none'; return; }

            const WARN    = 7200; // 2 ชั่วโมง
            const timeStr = fmtResetTime(secsLeft);

            if (secsLeft < WARN) {
                // เตือน: สีส้ม/แดง + live countdown
                const urgentColor = secsLeft < 1800 ? '#f87171' : '#f97316';
                el.style.display     = 'block';
                el.style.color       = urgentColor;
                el.style.borderColor = urgentColor;
                el.innerHTML = `<span class="rl-time">รีเซ็ต ${timeStr}</span><span class="rl-label" id="resetCountText">เหลือ ${fmtSecsShort(secsLeft)}</span>`;

                if (_tokenCountdown) clearInterval(_tokenCountdown);
                let s = secsLeft;
                _tokenCountdown = setInterval(() => {
                    s--;
                    const txt = document.getElementById('resetCountText');
                    if (s <= 0) { clearInterval(_tokenCountdown); _tokenCountdown = null; refreshTokenStatus(); }
                    else {
                        const c = s < 1800 ? '#f87171' : '#f97316';
                        el.style.color = c; el.style.borderColor = c;
                        if (txt) txt.textContent = `เหลือ ${fmtSecsShort(s)}`;
                    }
                }, 1000);
            } else {
                // แสดงเวลาจริงที่จะรีเซ็ต + countdown คร่าวๆ
                el.style.display     = 'block';
                el.style.color       = 'var(--muted-chat)';
                el.style.borderColor = 'var(--border2)';
                el.innerHTML = `<span class="rl-time">รีเซ็ต ${timeStr}</span><span class="rl-label">อีก ${fmtSecsShort(secsLeft)}</span>`;

                if (_tokenCountdown) clearInterval(_tokenCountdown);
                const msToWarn = (secsLeft - WARN) * 1000;
                _tokenCountdown = setTimeout(() => { _tokenCountdown = null; refreshTokenStatus(); }, msToWarn);
            }
        }

        function updateDonut(used, limit, secsLeft) {
            const wrap = document.getElementById('tokenDonutWrap');
            const sep  = document.getElementById('tokenSep');
            if (!wrap) return;
            if (limit <= 0) {
                wrap.classList.add('hidden');
                if (sep) sep.style.display = 'none';
                if (_tokenCountdown) { clearInterval(_tokenCountdown); _tokenCountdown = null; }
                const el = document.getElementById('tokenResetLbl');
                if (el) el.style.display = 'none';
                return;
            }
            wrap.classList.remove('hidden');
            if (sep) sep.style.display = '';

            const pct   = Math.min(100, Math.round(used / limit * 100));
            const color = pct >= 90 ? '#f87171' : pct >= 70 ? '#eab308' : '#34d399';

            const fill = document.getElementById('donutFill');
            if (fill) {
                fill.style.strokeDasharray = pct + ' ' + (100 - pct);
                fill.style.stroke = color;
            }
            const pctLbl   = document.getElementById('donutPct');
            const usedLbl  = document.getElementById('tokenUsedLbl');
            const limitLbl = document.getElementById('tokenLimitLbl');
            if (pctLbl)  pctLbl.textContent = pct + '%';
            if (usedLbl) { usedLbl.style.color = color; usedLbl.textContent = fmtTokens(used) + ' token'; }
            if (limitLbl) limitLbl.textContent = 'จาก ' + fmtTokens(limit);

            // อัปเดต reset label แยกต่างหาก
            if (_tokenCountdown) { clearInterval(_tokenCountdown); _tokenCountdown = null; }
            updateResetLabel(secsLeft);
        }

        // init on page load — ใช้ secs_left ที่ PHP คำนวณไว้
        window._rtTokensUsed  = TOKEN_USED_INIT;
        window._rtTokenLimit  = TOKEN_LIMIT;
        window._rtTokensTotal = <?= (int)($userTokensTotal ?? 0) ?>;
        window._rtSecsLeft    = TOKEN_SECS_LEFT_INIT;
        updateDonut(TOKEN_USED_INIT, TOKEN_LIMIT, TOKEN_SECS_LEFT_INIT);

        // refresh after each stream completes — uses exact DB value
        function refreshTokenStatus() {
            if (TOKEN_LIMIT <= 0) return;
            fetch('chat.php?api=token_status')
                .then(r => r.text())
                .then(text => {
                    let d;
                    try { d = JSON.parse(text); } catch(_) { return; }
                    // cache latest values
                    window._rtTokensUsed  = d.used;
                    window._rtSecsLeft    = d.secs_left;
                    window._rtTokensTotal = d.total;
                    window._rtTokenLimit  = d.limit;
                    updateDonut(d.used, d.limit, d.secs_left);
                    // if popup is open, re-render it with fresh data
                    const pop = document.getElementById('tokenPopup');
                    if (pop && pop.classList.contains('open') && typeof renderPopup === 'function') {
                        renderPopup(d.used, d.limit, d.total, d.secs_left);
                    }
                })
                .catch(() => {});
        }

        // ── State ────────────────────────────────────────────────────────
        let messages      = [];
        let isStreaming   = false;
        let currentModel  = '<?= htmlspecialchars($model) ?>';
        let currentConvId = null;   // DB conversation id (int)
        let chatHistories = [];     // [{id, title, model, updated_at}]

        // ── Marked.js ────────────────────────────────────────────────────
        marked.setOptions({ breaks: true, gfm: true });
        const renderer = new marked.Renderer();
        renderer.code = function(tokenOrCode, language) {
            // marked v5+ passes a token object; v4 passes (code, language) directly
            const codeText = (tokenOrCode && typeof tokenOrCode === 'object')
                ? (tokenOrCode.text ?? '')
                : (tokenOrCode ?? '');
            const lang = (tokenOrCode && typeof tokenOrCode === 'object')
                ? (tokenOrCode.lang || 'plaintext')
                : (language || 'plaintext');
            let highlighted;
            try {
                highlighted = lang !== 'plaintext' && hljs.getLanguage(lang)
                    ? hljs.highlight(codeText, { language: lang }).value
                    : hljs.highlightAuto(codeText).value;
            } catch (e) { highlighted = escapeHtml(codeText); }
            return `<pre><div class="code-block-header"><span class="language">${lang}</span><button class="copy-code-btn" onclick="copyCode(this)"><svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg><span>Copy</span></button></div><code class="hljs language-${lang}">${highlighted}</code></pre>`;
        };
        marked.use({ renderer });

        // ── DOM ──────────────────────────────────────────────────────────
        const $chatMessages  = $('#chatMessages');
        const $chatContainer = $('#chatContainer');
        const $messageInput  = $('#messageInput');
        const $sendBtn       = $('#sendBtn');
        const $stopBtn       = $('#stopBtn');
        const $inputBox      = $('#inputBox');
        const $emptyState    = $('#emptyState');
        const $sidebar       = $('#sidebar');
        const $sidebarOverlay= $('#sidebarOverlay');
        const $modelDropdown = $('#modelDropdown');

        // ── Helpers ──────────────────────────────────────────────────────
        function escapeHtml(text) {
            const d = document.createElement('div');
            d.textContent = text;
            return d.innerHTML;
        }
        function scrollToBottom() {
            $chatContainer.animate({ scrollTop: $chatContainer[0].scrollHeight }, 100);
        }
        function autoResizeTextarea() {
            $messageInput.css('height','auto').css('height', Math.min($messageInput[0].scrollHeight, 200) + 'px');
        }
        function updateSendButton() {
            $sendBtn.prop('disabled', (!$messageInput.val().trim() && !pendingAttachments.length) || isStreaming);
        }
        function setStreamingState(s) {
            isStreaming = s;
            $inputBox.toggleClass('is-streaming', s);
            updateSendButton();
        }
        function renderMarkdown(text) { return marked.parse(text); }

        function renderMath(el) {
            if (!window._katexReady || typeof renderMathInElement !== 'function') return;
            renderMathInElement(el, {
                delimiters: [
                    { left: '$$', right: '$$', display: true  },
                    { left: '$',  right: '$',  display: false },
                    { left: '\\(', right: '\\)', display: false },
                    { left: '\\[', right: '\\]', display: true  },
                ],
                throwOnError: false,
            });
        }

        // ── Message Rendering ────────────────────────────────────────────
        function appendMessage(role, content, streaming = false) {
            $emptyState.hide();
            const id = 'msg_' + Date.now() + '_' + Math.random().toString(36).slice(2,6);
            const userInitial = '<?= mb_strtoupper(mb_substr(htmlspecialchars($currentUsername), 0, 1, "UTF-8"), "UTF-8") ?>';
            const avatar = role === 'user'
                ? userInitial
                : `<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:20px;height:20px">
                    <path d="M12 2L13.5 8.5L20 10L13.5 11.5L12 18L10.5 11.5L4 10L10.5 8.5L12 2Z" fill="white" opacity="0.95"/>
                    <path d="M19 16L19.75 18.25L22 19L19.75 19.75L19 22L18.25 19.75L16 19L18.25 18.25L19 16Z" fill="white" opacity="0.7"/>
                    <path d="M5 4L5.5 5.5L7 6L5.5 6.5L5 8L4.5 6.5L3 6L4.5 5.5L5 4Z" fill="white" opacity="0.6"/>
                  </svg>`;
            const thinkingHTML = `<div class="thinking-bubble">
                <span class="typing-indicator"><span></span><span></span><span></span></span>
                <span class="thinking-text">AI กำลังคิด...</span>
            </div>`;
            const body = role === 'user'
                ? `<p>${escapeHtml(content)}</p>`
                : (streaming ? thinkingHTML : renderMarkdown(content));
            $chatMessages.append(`
                <div class="message ${role}" id="${id}">
                    <div class="message-avatar">${avatar}</div>
                    <div class="message-content">${body}</div>
                </div>`);
            // render math in history messages (not streaming ones)
            if (role === 'assistant' && !streaming) {
                renderMath(document.getElementById(id));
            }
            scrollToBottom();
            return id;
        }
        // ── Streaming code block renderer ─────────────────────────────────
        function renderStreaming(content) {
            // Split on ``` to detect open/closed code fences
            const parts = content.split(/(```[\w]*\n?)/);
            let html = '';
            let inCode = false;
            let codeLang = 'plaintext';
            let codeAcc  = '';

            for (let i = 0; i < parts.length; i++) {
                const p = parts[i];
                if (!inCode && /^```([\w]*)/.test(p)) {
                    // Opening fence — enter code mode
                    inCode   = true;
                    codeLang = (p.match(/^```([\w]*)/) || [])[1] || 'plaintext';
                    codeAcc  = '';
                } else if (inCode && p === '```\n' || (inCode && i === parts.length - 1 && p.endsWith('```'))) {
                    // Closing fence — flush as finished code block
                    html += renderMarkdown('```' + codeLang + '\n' + codeAcc + '\n```');
                    inCode = false; codeAcc = '';
                } else if (inCode) {
                    codeAcc += p;
                } else {
                    html += renderMarkdown(p);
                }
            }

            // If still inside an unclosed code block — show streaming block
            if (inCode && codeAcc.trim()) {
                const preview = escapeHtml(codeAcc.replace(/\n$/, ''));
                html += `<div class="streaming-code-block">
                    <div class="streaming-code-header">
                        <span>${codeLang || 'plaintext'}</span>
                        <span class="streaming-code-dots"><span></span><span></span><span></span></span>
                    </div>
                    <div class="streaming-code-body">${preview}</div>
                </div>`;
            }

            return html;
        }

        function updateMessageContent(id, content, isFinal = false) {
            const $el = $(`#${id} .message-content`);
            if (isFinal) {
                $el.html(renderMarkdown(content));
                $el.find('pre code').each(function() { hljs.highlightElement(this); });
                renderMath($el[0]);
            } else {
                $el.html(renderStreaming(content) + '<span class="streaming-cursor"></span>');
                // auto-scroll ทุก streaming-code-body ให้แสดงบรรทัดล่าสุด
                $el.find('.streaming-code-body').each(function() {
                    this.scrollTop = this.scrollHeight;
                });
            }
            scrollToBottom();
        }
        function showError(msg) {
            $chatMessages.append(`<div class="error-message">${escapeHtml(msg)}</div>`);
            scrollToBottom();
        }

        // ── History (from DB via API) ────────────────────────────────────
        async function loadHistoryList() {
            try {
                const res  = await fetch('?api=convs');
                chatHistories = await res.json();
                renderChatHistory();
            } catch (e) { /* silent */ }
        }

        function renderChatHistory() {
            const $h = $('#chatHistory').empty();
            if (!chatHistories.length) {
                $h.append('<div style="padding:12px;color:var(--muted-chat);font-size:13px">ยังไม่มีการสนทนา</div>');
                return;
            }
            chatHistories.forEach(chat => {
                const active = chat.id === currentConvId ? 'active' : '';
                $h.append(`
                    <div class="chat-history-item ${active}" data-id="${chat.id}">
                        <span class="chat-history-title-text">${escapeHtml(chat.title || 'New Chat')}</span>
                        <button class="delete-chat-btn" title="ลบ">
                            <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"></polyline>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                            </svg>
                        </button>
                    </div>`);
            });
        }

        async function loadConversation(convId) {
            try {
                const res  = await fetch(`?api=msgs&cid=${convId}`);
                const msgs = await res.json();
                currentConvId = convId;
                messages = msgs.map(m => ({ role: m.role, content: m.content }));
                $chatMessages.empty();
                if (!msgs.length) { $chatMessages.append($emptyState.show()); }
                else {
                    $emptyState.hide();
                    msgs.forEach(m => {
                        const el = appendMessage(m.role, m.content);
                        if (m.role === 'user' && m.files?.length) {
                            const thumbs = m.files.map(f => renderFileAttachment(f)).join('');
                            $('#' + el + ' .message-content').append(thumbs);
                        }
                    });
                }
                renderChatHistory();
                closeSidebar();
            } catch(e) { showError('โหลดการสนทนาล้มเหลว'); }
        }

        async function deleteConversation(convId) {
            await fetch('?api=del_conv', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id: convId}) });
            if (currentConvId === convId) startNewChat();
            await loadHistoryList();
        }

        function startNewChat() {
            currentConvId = null;
            messages = [];
            $chatMessages.empty();
            $chatMessages.append($emptyState.show());
            renderChatHistory();
            closeSidebar();
            $messageInput.focus();
        }

        // ── Sidebar ──────────────────────────────────────────────────────
        function openSidebar()  { $sidebar.addClass('open');    $sidebarOverlay.addClass('show'); }
        function closeSidebar() { $sidebar.removeClass('open'); $sidebarOverlay.removeClass('show'); }

        // ── File attachment ──────────────────────────────────────────────
        let pendingAttachments = []; // [{uuid, name, mime, url, uploading}]

        function fileEmoji(mime) {
            if (!mime || mime.startsWith('image/')) return '🖼️';
            if (mime === 'application/pdf') return '📄';
            if (mime.startsWith('text/') || /json|yaml|xml|csv/.test(mime)) return '📝';
            return '📎';
        }

        // Render a stored file attachment (clickable image or downloadable chip)
        function renderFileAttachment(f) {
            if (f.mime && f.mime.startsWith('image/')) {
                return `<img class="msg-img" src="${f.url}" alt="${escapeHtml(f.name)}" onclick="openLightbox('${f.url}')" title="${escapeHtml(f.name)}">`;
            }
            return `<a class="msg-file-chip" href="${f.url}" download="${escapeHtml(f.name)}" title="ดาวน์โหลด ${escapeHtml(f.name)}">${fileEmoji(f.mime)} ${escapeHtml(f.name)}</a>`;
        }

        function renderAttachPreview() {
            const box = document.getElementById('attachPreview');
            if (!pendingAttachments.length) { box.style.display='none'; box.innerHTML=''; updateSendButton(); return; }
            box.style.display = 'flex';
            box.innerHTML = pendingAttachments.map((a, i) => {
                if (a.uploading) return `<div class="attach-chip"><span style="font-size:18px">⏳</span><span class="chip-name">${escapeHtml(a.name)}</span></div>`;
                const thumb = a.mime.startsWith('image/') ? `<img src="${a.url}" alt="">` : `<span style="font-size:18px">${fileEmoji(a.mime)}</span>`;
                return `<div class="attach-chip">${thumb}<span class="chip-name" title="${escapeHtml(a.name)}">${escapeHtml(a.name)}</span><button class="chip-rm" onclick="removeAttachment(${i})" title="ลบ">✕</button></div>`;
            }).join('');
            updateSendButton();
        }

        function removeAttachment(i) { pendingAttachments.splice(i, 1); renderAttachPreview(); }

        async function addAttachments(files) {
            for (const file of files) {
                if (file.size > 20 * 1024 * 1024) { showError(`ไฟล์ "${file.name}" ใหญ่เกิน 20MB`); continue; }
                const placeholder = { name: file.name, mime: file.type, uploading: true };
                pendingAttachments.push(placeholder);
                renderAttachPreview();

                const fd = new FormData();
                fd.append('files[]', file);
                try {
                    const res  = await fetch('?api=upload', { method:'POST', body: fd });
                    const data = await res.json();
                    const idx  = pendingAttachments.indexOf(placeholder);
                    if (idx === -1) continue;
                    const f = data.files?.[0];
                    if (f?.ok) {
                        pendingAttachments[idx] = { uuid: f.uuid, name: f.name, mime: f.mime, url: f.url };
                    } else {
                        pendingAttachments.splice(idx, 1);
                        showError(f?.msg || 'อัปโหลดล้มเหลว');
                    }
                } catch { const idx = pendingAttachments.indexOf(placeholder); if (idx !== -1) pendingAttachments.splice(idx, 1); }
                renderAttachPreview();
            }
        }

        $('#attachBtn').on('click', () => document.getElementById('fileInput').click());
        document.getElementById('fileInput').addEventListener('change', function() { addAttachments([...this.files]); this.value=''; });
        document.getElementById('inputBox').addEventListener('dragover', e => { e.preventDefault(); e.currentTarget.style.borderColor='rgba(99,102,241,.6)'; });
        document.getElementById('inputBox').addEventListener('dragleave', e => { e.currentTarget.style.borderColor=''; });
        document.getElementById('inputBox').addEventListener('drop', e => { e.preventDefault(); e.currentTarget.style.borderColor=''; addAttachments([...e.dataTransfer.files]); });

        // ── Streaming API call ───────────────────────────────────────────
        async function sendMessage(userMessage) {
            const attachedFiles = pendingAttachments.filter(a => a.uuid);
            const fileUuids     = attachedFiles.map(a => a.uuid);

            // Store in messages as text (server handles multimodal)
            messages.push({ role: 'user', content: userMessage });

            // Display: text + file previews
            let displayHtml = escapeHtml(userMessage);
            if (attachedFiles.length) {
                displayHtml += attachedFiles.map(a => renderFileAttachment(a)).join('');
            }
            const msgEl = appendMessage('user', '');
            $('#' + msgEl + ' .message-content').html(displayHtml);

            pendingAttachments = [];
            renderAttachPreview();
            $messageInput.val('');
            autoResizeTextarea();
            updateSendButton();
            setStreamingState(true);

            const assistantMsgId = appendMessage('assistant', '', true);
            let assistantContent = '';
            // real-time token estimation: snapshot of current usage before this message
            const _streamUsedBase = TOKEN_LIMIT > 0 ? (window._rtTokensUsed ?? TOKEN_USED_INIT) : 0;
            let   _streamChars    = 0;

            try {
                const convTitle = userMessage.substring(0, 80);
                const response = await fetch('?stream=1', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        messages: messages.map(m => ({ role: m.role, content: m.content })),
                        model: currentModel,
                        conv_id: currentConvId || 0,
                        conv_title: convTitle,
                        file_uuids: fileUuids,
                    })
                });

                if (!response.ok) throw new Error('Network response was not ok');

                const reader  = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;
                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop() || '';
                    for (const line of lines) {
                        if (!line.startsWith('data: ')) continue;
                        const raw = line.slice(6);
                        if (raw === '[DONE]') continue;
                        try {
                            const parsed = JSON.parse(raw);
                            if (parsed.conv_id && !currentConvId) {
                                currentConvId = parsed.conv_id;
                                // refresh sidebar after first message
                                loadHistoryList();
                            }
                            if (parsed.error) throw new Error(parsed.error);
                            if (parsed.token_update) {
                                // exact token count from DB — update donut with real value
                                window._rtTokensUsed = parsed.tokens_used;
                                updateDonut(parsed.tokens_used, TOKEN_LIMIT, window._rtSecsLeft ?? null);
                                // also refresh popup if it's open
                                if (document.getElementById('tokenPopup')?.classList.contains('open')) {
                                    openTokenPopup();
                                }
                            }
                            if (parsed.content) {
                                assistantContent += parsed.content;
                                updateMessageContent(assistantMsgId, assistantContent);
                                // real-time donut update (estimated tokens)
                                if (TOKEN_LIMIT > 0) {
                                    _streamChars += parsed.content.length;
                                    const _est = _streamUsedBase + Math.ceil(_streamChars / 3.5);
                                    updateDonut(_est, TOKEN_LIMIT, window._rtSecsLeft ?? null);
                                }
                            }
                        } catch (e) { if (e.message && e.message !== 'Unexpected token') throw e; }
                    }
                }

                if (assistantContent) {
                    messages.push({ role: 'assistant', content: assistantContent });
                    updateMessageContent(assistantMsgId, assistantContent, true);
                    loadHistoryList(); // refresh sidebar title
                } else {
                    $(`#${assistantMsgId}`).remove();
                }
            } catch (error) {
                $(`#${assistantMsgId}`).remove();
                showError('Error: ' + error.message);
            } finally {
                setStreamingState(false);
                refreshTokenStatus();
            }
        }

        // ── Event Handlers ───────────────────────────────────────────────
        $sendBtn.on('click', () => { const m = $messageInput.val().trim(); if ((m || pendingAttachments.length) && !isStreaming) sendMessage(m || '📎'); });
        $messageInput.on('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); const m = $(this).val().trim(); if ((m || pendingAttachments.length) && !isStreaming) sendMessage(m || '📎'); }
        });
        $messageInput.on('input', () => { autoResizeTextarea(); updateSendButton(); });
        $stopBtn.on('click', () => setStreamingState(false));
        $('#newChatBtn').on('click', startNewChat);

        $('#chatHistory').on('click', '.chat-history-item', function(e) {
            if ($(e.target).closest('.delete-chat-btn').length) return;
            loadConversation($(this).data('id'));
        });
        $('#chatHistory').on('click', '.delete-chat-btn', function(e) {
            e.stopPropagation();
            if (confirm('ลบการสนทนานี้?')) deleteConversation($(this).closest('.chat-history-item').data('id'));
        });

        $('#menuToggle').on('click', () => $sidebar.hasClass('open') ? closeSidebar() : openSidebar());
        $sidebarOverlay.on('click', closeSidebar);

        $('#modelSelector').on('click', function(e) { e.stopPropagation(); $modelDropdown.toggleClass('show'); });
        $('.model-option').on('click', function() {
            currentModel = $(this).data('model');
            const label = $(this).find('strong').text() || currentModel;
            $('#currentModel').text(label);
            $('.model-option').removeClass('active');
            $(this).addClass('active');
            $modelDropdown.removeClass('show');
        });
        $(document).on('click', () => $modelDropdown.removeClass('show'));

        // ── Init ─────────────────────────────────────────────────────────
        loadHistoryList();
        updateSendButton();
        $('.model-option').removeClass('active');
        $(`.model-option[data-model="${currentModel}"]`).addClass('active');
        $messageInput.focus();
    });
    
    // ================================================================
    // GLOBAL FUNCTIONS
    // ================================================================
    function copyCode(btn) {
        const code = $(btn).closest('pre').find('code').text();
        navigator.clipboard.writeText(code).then(() => {
            const $btn = $(btn);
            $btn.addClass('copied');
            $btn.find('span').text('Copied!');
            
            setTimeout(() => {
                $btn.removeClass('copied');
                $btn.find('span').text('Copy');
            }, 2000);
        });
    }
    </script>

    <!-- ── Token Detail Popup ── -->
    <div id="tokenPopup">
        <div class="tp-backdrop" onclick="closeTokenPopup()"></div>
        <div class="tp-box">
            <div class="tp-handle"></div>
            <div class="tp-title">📊 รายละเอียดการใช้ Token</div>

            <div class="tp-donut-row">
                <!-- Donut ขนาดใหญ่ -->
                <div class="tp-donut-big">
                    <svg viewBox="0 0 36 36" xmlns="http://www.w3.org/2000/svg">
                        <circle class="track" cx="18" cy="18" r="15.9"/>
                        <circle class="fill"  cx="18" cy="18" r="15.9" id="popupDonutFill"
                            stroke-dasharray="0 100" pathLength="100"/>
                    </svg>
                    <div class="pct-center" id="popupPct">0%</div>
                </div>
                <!-- ตัวเลขหลัก -->
                <div class="tp-stats">
                    <div class="tp-row">
                        <span class="lbl">ใช้ไปแล้ว</span>
                        <span class="val" id="popupUsed">—</span>
                    </div>
                    <div class="tp-row">
                        <span class="lbl">Limit รอบนี้</span>
                        <span class="val" id="popupLimit">—</span>
                    </div>
                    <div class="tp-row">
                        <span class="lbl">คงเหลือ</span>
                        <span class="val" id="popupRemain">—</span>
                    </div>
                </div>
            </div>

            <div class="tp-divider"></div>

            <div class="tp-row" style="margin-bottom:8px">
                <span class="lbl">สถิติรวมทั้งหมด (ตลอดการใช้งาน)</span>
                <span class="val" id="popupTotal">—</span>
            </div>

            <div class="tp-reset-box" id="popupResetBox" style="display:none">
                <div id="popupResetText"></div>
            </div>
        </div>
    </div>

    <script>
    // ── Token Popup ──────────────────────────────────────────────────────────────
    let _popupSecsLeft = null;
    let _popupTimer    = null;

    function openTokenPopup() {
        const pop = document.getElementById('tokenPopup');
        pop.classList.add('open');

        // แสดงค่า cache ทันที (ถ้ามี) เพื่อไม่ให้ popup ว่าง
        const cu = window._rtTokensUsed, cl = window._rtTokenLimit, ct = window._rtTokensTotal, cs = window._rtSecsLeft;
        if (cu != null && cl != null) renderPopup(cu, cl, ct ?? 0, cs ?? null);

        // ดึงค่าล่าสุดจาก API
        fetch('?api=token_status')
            .then(r => r.text())
            .then(text => {
                let d; try { d = JSON.parse(text); } catch(_) { return; }
                // อัปเดต cache ด้วย
                window._rtTokensUsed  = d.used;
                window._rtTokenLimit  = d.limit;
                window._rtTokensTotal = d.total;
                window._rtSecsLeft    = d.secs_left;
                _popupSecsLeft = d.secs_left;
                renderPopup(d.used, d.limit, d.total, d.secs_left);
                startPopupTimer();
            })
            .catch(() => {});
    }

    function closeTokenPopup() {
        document.getElementById('tokenPopup').classList.remove('open');
        if (_popupTimer) { clearInterval(_popupTimer); _popupTimer = null; }
    }

    function renderPopup(used, limit, total, secsLeft) {
        const pct    = (limit > 0) ? Math.min(100, Math.round(used / limit * 100)) : 0;
        const remain = (limit > 0) ? Math.max(0, limit - used) : null;
        const color  = pct >= 90 ? '#f87171' : pct >= 70 ? '#f97316' : '#10a37f';

        // donut ใหญ่
        document.getElementById('popupDonutFill').style.strokeDasharray = `${pct} ${100 - pct}`;
        document.getElementById('popupDonutFill').style.stroke = color;
        document.getElementById('popupPct').textContent = pct + '%';
        document.getElementById('popupPct').style.color = color;

        document.getElementById('popupUsed').textContent   = used.toLocaleString() + ' token';
        document.getElementById('popupLimit').textContent  = limit > 0 ? limit.toLocaleString() + ' token' : 'ไม่จำกัด';
        document.getElementById('popupRemain').textContent = remain !== null ? remain.toLocaleString() + ' token' : '∞';
        document.getElementById('popupRemain').style.color = remain !== null && remain < limit * 0.1 ? '#f87171' : '';
        document.getElementById('popupTotal').textContent  = total.toLocaleString() + ' token';

        // reset box
        const rb = document.getElementById('popupResetBox');
        const rt = document.getElementById('popupResetText');
        if (secsLeft > 0) {
            rb.style.display = 'block';
            updatePopupResetText(secsLeft);
        } else {
            rb.style.display = 'none';
        }
    }

    function updatePopupResetText(s) {
        const rt = document.getElementById('popupResetText');
        if (!rt) return;
        const timeStr = fmtResetTime(s);
        const remain  = fmtSecsShort(s);
        const urgColor = s < 7200 ? (s < 1800 ? '#f87171' : '#f97316') : 'inherit';
        rt.innerHTML = `⏱ รีเซ็ต Token <strong>${timeStr}</strong> — เหลืออีก <strong style="color:${urgColor}">${remain}</strong>`;
    }

    function startPopupTimer() {
        if (_popupTimer) clearInterval(_popupTimer);
        if (!_popupSecsLeft || _popupSecsLeft <= 0) return;
        _popupTimer = setInterval(() => {
            _popupSecsLeft--;
            if (_popupSecsLeft <= 0) { clearInterval(_popupTimer); _popupTimer = null; }
            updatePopupResetText(_popupSecsLeft);
        }, 1000);
    }

    // ปิดด้วย Escape
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeTokenPopup(); closeLightbox(); } });
    </script>

    <!-- Lightbox -->
    <div id="lightbox" onclick="closeLightbox()">
        <button id="lightboxClose" onclick="closeLightbox()">✕</button>
        <img id="lightboxImg" src="" alt="" onclick="event.stopPropagation()">
    </div>
    <script>
    function openLightbox(src) {
        document.getElementById('lightboxImg').src = src;
        document.getElementById('lightbox').classList.add('open');
    }
    function closeLightbox() {
        document.getElementById('lightbox').classList.remove('open');
        document.getElementById('lightboxImg').src = '';
    }
    </script>

    <?= themeScript() ?>
</body>
</html>