<?php
/**
 * ============================================================================
 * ADMIN PANEL — AI Chat Management System
 * ============================================================================
 * PHP 8.0+ | MariaDB 10+
 * ============================================================================
 */
declare(strict_types=1);

// ─── Bootstrap ───────────────────────────────────────────────────────────────
if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: install.php');
    exit;
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/theme.php';

session_start();

// ─── Database ─────────────────────────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            die('<p style="color:red;font-family:sans-serif;padding:20px">ไม่สามารถเชื่อมต่อฐานข้อมูล: '
                . htmlspecialchars($e->getMessage())
                . '<br><a href="install.php">ติดตั้งใหม่</a></p>');
        }
    }
    return $pdo;
}

// ─── Auto-migrate: create models table if missing ────────────────────────────
function ensureModelsTable(): void {
    db()->exec("
        CREATE TABLE IF NOT EXISTS `models` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`       VARCHAR(200) NOT NULL,
            `label`      VARCHAR(200) NOT NULL DEFAULT '',
            `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
            `sort_order` SMALLINT     NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_model_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // Seed from settings.models_list if models table is empty
    $count = (int) db()->query('SELECT COUNT(*) FROM `models`')->fetchColumn();
    if ($count === 0) {
        $stmt = db()->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $stmt->execute(['models_list']);
        $row  = $stmt->fetch();
        $list = $row ? $row['value'] : 'llama3:8b';
        $ins  = db()->prepare('INSERT IGNORE INTO `models` (`name`,`label`,`sort_order`) VALUES (?,?,?)');
        foreach (array_filter(array_map('trim', explode(',', $list))) as $i => $m) {
            $ins->execute([$m, $m, $i]);
        }
    }
}
ensureModelsTable();

// ─── Auto-migrate: create api_servers table if missing ───────────────────────
function ensureApiServersTable(): void {
    db()->exec("
        CREATE TABLE IF NOT EXISTS `api_servers` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`       VARCHAR(200) NOT NULL,
            `base_url`   VARCHAR(500) NOT NULL,
            `api_key`    VARCHAR(500) NOT NULL DEFAULT 'ollama',
            `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
            `sort_order` SMALLINT     NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // Ensure models table has server_id column
    try {
        db()->exec("ALTER TABLE `models` ADD COLUMN IF NOT EXISTS `server_id` INT UNSIGNED NULL DEFAULT NULL AFTER `sort_order`");
    } catch (Throwable) {}
    // Seed default server from global settings if empty
    $count = (int)db()->query('SELECT COUNT(*) FROM `api_servers`')->fetchColumn();
    if ($count === 0) {
        $r   = db()->query("SELECT `key`,`value` FROM settings WHERE `key` IN ('base_url','api_key')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $url = $r['base_url'] ?? 'http://localhost:11434/v1';
        $key = $r['api_key']  ?? 'ollama';
        db()->prepare("INSERT INTO `api_servers` (name, base_url, api_key, sort_order) VALUES (?,?,?,0)")
            ->execute(['Default Server', $url, $key]);
    }
}
ensureApiServersTable();

// ─── Settings helpers ────────────────────────────────────────────────────────
function getSetting(string $key, string $default = ''): string {
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $stmt = db()->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $stmt->execute([$key]);
        $row  = $stmt->fetch();
        $cache[$key] = $row ? (string)$row['value'] : $default;
    }
    return $cache[$key];
}

function setSetting(string $key, string $value): void {
    db()->prepare('INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)')
        ->execute([$key, $value]);
}

function e(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmtTokens(int $n): string {
    if ($n >= 1_000_000) return round($n / 1_000_000, 1) . 'M';
    if ($n >= 1_000)     return round($n / 1_000, 1) . 'K';
    return (string)$n;
}

/**
 * แก้ไขค่า token: NULL = ใช้ค่ากลาง, มิฉะนั้นใช้ค่า user
 * คืนค่า effective value และ flag ว่ากำลัง inherit จาก global หรือไม่
 */
function resolveAdminToken(?int $userVal, string $settingKey, int $fallback = 0): array {
    if ($userVal !== null) return ['val' => $userVal, 'inherited' => false];
    return ['val' => (int)getSetting($settingKey, (string)$fallback), 'inherited' => true];
}

// ─── Auth ─────────────────────────────────────────────────────────────────────
$page          = $_GET['page'] ?? 'dashboard';
$adminId       = $_SESSION['admin_id']       ?? null;
$adminUsername = $_SESSION['admin_username'] ?? 'Admin';

if ($page === 'logout') {
    session_destroy();
    header('Location: index.php');
    exit;
}

$loginError = '';
if (!$adminId && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $uname = trim($_POST['username'] ?? '');
    $upass = $_POST['password'] ?? '';
    $stmt  = db()->prepare('SELECT id, password, role, is_active FROM users WHERE username = ?');
    $stmt->execute([$uname]);
    $user = $stmt->fetch();

    if ($user && $user['is_active'] && password_verify($upass, $user['password'])) {
        if ($user['role'] !== 'admin') {
            $loginError = 'บัญชีนี้ไม่มีสิทธิ์เข้า Admin Panel';
        } else {
            $_SESSION['admin_id']       = $user['id'];
            $_SESSION['admin_role']     = $user['role'];
            $_SESSION['admin_username'] = $uname;
            db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
            header('Location: admin.php');
            exit;
        }
    } else {
        $loginError = 'Username หรือ Password ไม่ถูกต้อง';
    }
}

if (!$adminId) {
    renderLoginPage($loginError);
    exit;
}

// ─── POST actions ─────────────────────────────────────────────────────────────
$flashMsg  = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* ── API Servers CRUD ── */
    if (isset($_POST['add_api_server'])) {
        $sName   = trim($_POST['server_name']    ?? '');
        $sUrl    = rtrim(trim($_POST['server_base_url'] ?? ''), '/');
        $sKey    = trim($_POST['server_api_key'] ?? 'ollama');
        if ($sName && $sUrl) {
            $sortMax = (int)db()->query('SELECT COALESCE(MAX(sort_order),0) FROM api_servers')->fetchColumn();
            db()->prepare('INSERT INTO api_servers (name, base_url, api_key, sort_order) VALUES (?,?,?,?)')
                ->execute([$sName, $sUrl, $sKey ?: 'ollama', $sortMax + 1]);
            $flashMsg = 'เพิ่ม API Server สำเร็จ';
        }
        header('Location: admin.php?page=api_servers&msg=' . urlencode($flashMsg)); exit;
    }
    if (isset($_POST['edit_api_server'])) {
        $sid  = (int)($_POST['edit_server_id'] ?? 0);
        $sName = trim($_POST['edit_server_name']    ?? '');
        $sUrl  = rtrim(trim($_POST['edit_server_base_url'] ?? ''), '/');
        $sKey  = trim($_POST['edit_server_api_key'] ?? '');
        if ($sid && $sName && $sUrl) {
            db()->prepare('UPDATE api_servers SET name=?, base_url=?, api_key=? WHERE id=?')
                ->execute([$sName, $sUrl, $sKey ?: 'ollama', $sid]);
        }
        header('Location: admin.php?page=api_servers&msg=' . urlencode('บันทึกสำเร็จ')); exit;
    }
    if (isset($_POST['delete_api_server'])) {
        $sid = (int)($_POST['delete_api_server'] ?? 0);
        if ($sid) {
            db()->prepare('UPDATE models SET server_id = NULL WHERE server_id = ?')->execute([$sid]);
            db()->prepare('DELETE FROM api_servers WHERE id = ?')->execute([$sid]);
        }
        header('Location: admin.php?page=api_servers&msg=' . urlencode('ลบ Server สำเร็จ')); exit;
    }
    if (isset($_POST['toggle_api_server'])) {
        $sid = (int)($_POST['toggle_api_server'] ?? 0);
        if ($sid) db()->prepare('UPDATE api_servers SET is_active = 1 - is_active WHERE id = ?')->execute([$sid]);
        header('Location: admin.php?page=api_servers'); exit;
    }
    if (isset($_POST['assign_model_server'])) {
        $mid = (int)($_POST['model_id']   ?? 0);
        $sid = $_POST['model_server_id'] === '' ? null : (int)$_POST['model_server_id'];
        if ($mid) db()->prepare('UPDATE models SET server_id = ? WHERE id = ?')->execute([$sid, $mid]);
        header('Location: admin.php?page=api_servers&msg=' . urlencode('กำหนด Server สำเร็จ')); exit;
    }

    /* ── Settings ── */
    if (isset($_POST['save_token_settings'])) {
        foreach (['default_token_limit','default_token_reset_hours'] as $f) {
            if (array_key_exists($f, $_POST)) setSetting($f, trim($_POST[$f]));
        }
        // ถ้าเปลี่ยน allow_reset_global ให้ apply ไปยัง user ที่ยังใช้ค่ากลาง (token_reset_hours IS NULL) ทันที
        header('Location: admin.php?page=tokens&saved=1'); exit; // tokens page stays separate
    }

    /* ── Test API connection (AJAX) ── */
    if (isset($_GET['api']) && $_GET['api'] === 'test_connection') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $testKey = trim($_POST['api_key'] ?? getSetting('api_key',''));
            $testUrl = rtrim(trim($_POST['base_url'] ?? getSetting('base_url','')), '/');
            if (empty($testUrl)) { echo json_encode(['ok'=>false,'msg'=>'กรุณากรอก Base URL ก่อนทดสอบ']); exit; }

            $endpoint = $testUrl . '/models';
            $headers  = ['Authorization: Bearer ' . $testKey, 'Content-Type: application/json', 'Accept: application/json'];
            $http = 0; $body = false; $curlErr = '';

            if (function_exists('curl_init')) {
                // ── cURL path ──────────────────────────────────────────
                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 3,
                ]);
                $body    = curl_exec($ch);
                $http    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlNo  = curl_errno($ch);
                $curlErr = curl_error($ch);
                curl_close($ch);

                if ($curlErr) {
                    $friendly = match(true) {
                        $curlNo === CURLE_COULDNT_CONNECT       => 'ไม่สามารถเชื่อมต่อกับ Server ได้ — ตรวจสอบ IP/Port และ Firewall',
                        $curlNo === CURLE_OPERATION_TIMEOUTED,
                        $curlNo === CURLE_COULDNT_RESOLVE_HOST  => 'หมดเวลาเชื่อมต่อ — Server ไม่ตอบสนองภายใน 10 วินาที',
                        default                                  => 'เชื่อมต่อไม่ได้: ' . $curlErr,
                    };
                    echo json_encode(['ok'=>false,'msg'=>$friendly]);
                    exit;
                }
            } else {
                // ── file_get_contents fallback (ไม่มี cURL) ────────────
                $ctx  = stream_context_create(['http'=>[
                    'method'          => 'GET',
                    'header'          => implode("\r\n", $headers),
                    'timeout'         => 10,
                    'ignore_errors'   => true,
                ]]);
                $body = @file_get_contents($endpoint, false, $ctx);
                if ($body === false) {
                    $err = error_get_last()['message'] ?? 'unknown';
                    // แปลงข้อความ PHP warning เป็นภาษาไทย
                    if (str_contains($err, 'Connection refused') || str_contains($err, 'failed to open')) {
                        echo json_encode(['ok'=>false,'msg'=>'ไม่สามารถเชื่อมต่อกับ Server ได้ — ตรวจสอบ IP/Port และ Firewall']);
                    } elseif (str_contains($err, 'timed out')) {
                        echo json_encode(['ok'=>false,'msg'=>'หมดเวลาเชื่อมต่อ — Server ไม่ตอบสนองภายใน 10 วินาที']);
                    } else {
                        echo json_encode(['ok'=>false,'msg'=>'เชื่อมต่อไม่ได้: ' . $err]);
                    }
                    exit;
                }
                // ดึง HTTP status จาก $http_response_header
                if (!empty($http_response_header[0]) && preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0], $m)) {
                    $http = (int)$m[1];
                } else {
                    $http = 200;
                }
            }

            if ($http === 0) {
                echo json_encode(['ok'=>false,'msg'=>'ไม่ได้รับการตอบกลับจาก Server — ตรวจสอบ Base URL']);
                exit;
            }

            $data = json_decode((string)$body, true);

            if ($http >= 200 && $http < 300) {
                $count = isset($data['data']) && is_array($data['data']) ? count($data['data']) : '—';
                echo json_encode(['ok'=>true,'msg'=>"เชื่อมต่อสำเร็จ ✓  (HTTP {$http} · พบ {$count} model)"]);
            } elseif ($http === 401 || $http === 403) {
                echo json_encode(['ok'=>false,'msg'=>"API Key ไม่ถูกต้องหรือไม่มีสิทธิ์เข้าถึง (HTTP {$http})"]);
            } elseif ($http === 404) {
                echo json_encode(['ok'=>false,'msg'=>"ไม่พบ Endpoint — ตรวจสอบ Base URL ว่าลงท้ายด้วย /v1 (HTTP 404)"]);
            } elseif ($http >= 500) {
                $serverMsg = isset($data['error']['message']) ? ': ' . $data['error']['message'] : '';
                echo json_encode(['ok'=>false,'msg'=>"Server ฝั่ง AI เกิดข้อผิดพลาด (HTTP {$http}){$serverMsg}"]);
            } else {
                echo json_encode(['ok'=>false,'msg'=>"Server ตอบกลับ HTTP {$http} — ไม่สามารถเชื่อมต่อได้"]);
            }
        } catch (Throwable $ex) {
            echo json_encode(['ok'=>false,'msg'=>'เกิดข้อผิดพลาดภายใน: ' . $ex->getMessage()]);
        }
        exit;
    }

    if (isset($_POST['save_settings'])) {
        foreach (['api_key','base_url','model','max_tokens'] as $f) {
            if (array_key_exists($f, $_POST)) setSetting($f, trim($_POST[$f]));
        }
        header('Location: admin.php?page=api_servers&msg=' . urlencode('บันทึก Global API สำเร็จ')); exit;
    }

    if (isset($_POST['save_general_settings'])) {
        foreach (['site_name','system_prompt','registration_note'] as $f) {
            if (array_key_exists($f, $_POST)) setSetting($f, trim($_POST[$f]));
        }
        setSetting('allow_registration', isset($_POST['allow_registration']) ? '1' : '0');
        header('Location: admin.php?page=general_settings&msg=' . urlencode('บันทึกการตั้งค่าสำเร็จ')); exit;
    }

    /* ── Add user ── */
    if (isset($_POST['add_user'])) {
        $uname           = trim($_POST['new_username'] ?? '');
        $upass           = $_POST['new_password'] ?? '';
        $urole           = in_array($_POST['new_role'] ?? '', ['admin','user']) ? $_POST['new_role'] : 'user';
        $udisplay        = trim($_POST['new_display'] ?? $uname);
        // เว้นว่าง = NULL (ใช้ค่ากลาง), มีค่า = ตั้งเองสำหรับ user นี้
        $tokenLimit      = ($_POST['new_token_limit']       ?? '') !== '' ? max(0,(int)$_POST['new_token_limit'])       : null;
        $tokenResetHours = ($_POST['new_token_reset_hours'] ?? '') !== '' ? max(0,(int)$_POST['new_token_reset_hours']) : null;
        if (empty($uname) || strlen($upass) < 6) {
            $flashMsg  = 'Username ต้องไม่ว่าง และรหัสผ่านต้องอย่างน้อย 6 ตัว';
            $flashType = 'error';
        } else {
            try {
                $hash = password_hash($upass, PASSWORD_BCRYPT, ['cost' => 12]);
                db()->prepare('INSERT INTO users (username,password,display_name,role,token_limit,token_reset_hours) VALUES (?,?,?,?,?,?)')
                    ->execute([$uname, $hash, $udisplay, $urole, $tokenLimit, $tokenResetHours]);
                $flashMsg = "เพิ่มผู้ใช้ {$uname} สำเร็จ";
            } catch (PDOException) {
                $flashMsg  = 'Username นี้มีอยู่แล้ว';
                $flashType = 'error';
            }
        }
    }

    /* ── Edit user ── */
    if (isset($_POST['edit_user'])) {
        $uid             = (int)$_POST['edit_uid'];
        $udisplay        = trim($_POST['edit_display'] ?? '');
        $urole           = in_array($_POST['edit_role'] ?? '', ['admin','user']) ? $_POST['edit_role'] : 'user';
        // เว้นว่าง = NULL (ใช้ค่ากลาง), มีค่า = ค่าส่วนตัว
        $rawLimit        = $_POST['edit_token_limit']       ?? '';
        $rawReset        = $_POST['edit_token_reset_hours'] ?? '';
        $tokenLimit      = $rawLimit  === '' ? null : max(0, (int)$rawLimit);
        $tokenResetHours = $rawReset  === '' ? null : max(0, (int)$rawReset);
        if ($uid === (int)$adminId) $urole = 'admin';
        db()->prepare('UPDATE users SET display_name=?, role=?, token_limit=?, token_reset_hours=? WHERE id=?')
            ->execute([$udisplay, $urole, $tokenLimit, $tokenResetHours, $uid]);
        $newpass = $_POST['edit_password'] ?? '';
        if (strlen($newpass) >= 6) {
            $hash = password_hash($newpass, PASSWORD_BCRYPT, ['cost' => 12]);
            db()->prepare('UPDATE users SET password=? WHERE id=?')->execute([$hash, $uid]);
        }
        $flashMsg = 'อัปเดตข้อมูลผู้ใช้แล้ว';
    }

    /* ── Reset token usage ── */
    if (isset($_POST['reset_tokens'])) {
        $uid = (int)$_POST['reset_tokens_uid'];
        db()->prepare('UPDATE users SET tokens_used=0, tokens_reset_at=NOW() WHERE id=?')->execute([$uid]);
        $flashMsg = 'รีเซ็ต token usage แล้ว';
    }

    /* ── Approve pending registration ── */
    if (isset($_POST['approve_user'])) {
        $uid = (int)$_POST['approve_user'];
        db()->prepare('UPDATE users SET is_active=1 WHERE id=? AND is_active=0')->execute([$uid]);
        $flashMsg = 'อนุมัติผู้ใช้แล้ว';
    }

    /* ── Reject (delete) pending registration ── */
    if (isset($_POST['reject_user'])) {
        $uid = (int)$_POST['reject_user'];
        db()->prepare('DELETE FROM users WHERE id=? AND is_active=0')->execute([$uid]);
        $flashMsg = 'ปฏิเสธและลบคำขอแล้ว';
    }

    /* ── Toggle user active ── */
    if (isset($_POST['toggle_user'])) {
        $uid = (int)$_POST['toggle_user'];
        if ($uid !== (int)$adminId) {
            db()->prepare('UPDATE users SET is_active = NOT is_active WHERE id=?')->execute([$uid]);
            $flashMsg = 'อัปเดตสถานะผู้ใช้แล้ว';
        }
    }

    /* ── Delete user ── */
    if (isset($_POST['delete_user'])) {
        $uid = (int)$_POST['delete_user'];
        if ($uid === (int)$adminId) {
            $flashMsg = 'ไม่สามารถลบบัญชีตัวเองได้';  $flashType = 'error';
        } else {
            db()->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
            $flashMsg = 'ลบผู้ใช้แล้ว';
        }
    }

    /* ── Reset user password ── */
    if (isset($_POST['reset_password'])) {
        $uid   = (int)$_POST['reset_uid'];
        $npass = $_POST['new_pass_reset'] ?? '';
        if (strlen($npass) < 6) {
            $flashMsg = 'รหัสผ่านต้องอย่างน้อย 6 ตัว';  $flashType = 'error';
        } else {
            $hash = password_hash($npass, PASSWORD_BCRYPT, ['cost' => 12]);
            db()->prepare('UPDATE users SET password=? WHERE id=?')->execute([$hash, $uid]);
            $flashMsg = 'รีเซ็ตรหัสผ่านสำเร็จ';
        }
    }

    /* ── Delete conversation ── */
    if (isset($_POST['delete_conv'])) {
        db()->prepare('DELETE FROM conversations WHERE id=?')->execute([(int)$_POST['delete_conv']]);
        $flashMsg = 'ลบการสนทนาแล้ว';
    }

    /* ── Add model ── */
    if (isset($_POST['add_model'])) {
        $mname   = trim($_POST['model_name']    ?? '');
        $mlabel  = trim($_POST['model_label']   ?? $mname);
        $msrvId  = ($_POST['model_server_id'] ?? '') !== '' ? (int)$_POST['model_server_id'] : null;
        $addMsg  = 'กรุณากรอกชื่อ Model';
        if (!empty($mname)) {
            try {
                $maxOrd = (int) db()->query('SELECT COALESCE(MAX(sort_order),0) FROM `models`')->fetchColumn();
                try {
                    db()->prepare('INSERT INTO `models` (name,label,sort_order,server_id) VALUES (?,?,?,?)')
                        ->execute([$mname, $mlabel ?: $mname, $maxOrd + 1, $msrvId]);
                } catch (Throwable) {
                    db()->prepare('INSERT INTO `models` (name,label,sort_order) VALUES (?,?,?)')
                        ->execute([$mname, $mlabel ?: $mname, $maxOrd + 1]);
                }
                $addMsg = "เพิ่ม Model {$mname} แล้ว";
            } catch (PDOException) {
                $addMsg = 'Model นี้มีอยู่แล้ว';
            }
        }
        header('Location: admin.php?page=api_servers&msg=' . urlencode($addMsg)); exit;
    }

    /* ── Toggle model active ── */
    if (isset($_POST['toggle_model'])) {
        db()->prepare('UPDATE `models` SET is_active = NOT is_active WHERE id=?')->execute([(int)$_POST['toggle_model']]);
        header('Location: admin.php?page=api_servers&msg=' . urlencode('อัปเดตสถานะ Model แล้ว')); exit;
    }

    /* ── Delete model ── */
    if (isset($_POST['delete_model'])) {
        db()->prepare('DELETE FROM `models` WHERE id=?')->execute([(int)$_POST['delete_model']]);
        header('Location: admin.php?page=api_servers&msg=' . urlencode('ลบ Model แล้ว')); exit;
    }

    /* ── Edit model ── */
    if (isset($_POST['edit_model'])) {
        $mid      = (int)$_POST['edit_model_id'];
        $mname    = trim($_POST['edit_model_name']    ?? '');
        $mlabel   = trim($_POST['edit_model_label']   ?? '');
        $mactive  = isset($_POST['edit_model_active']) ? 1 : 0;
        $msrvId   = $_POST['edit_model_server_id'] !== '' ? (int)$_POST['edit_model_server_id'] : null;
        if (!empty($mname)) {
            try {
                db()->prepare('UPDATE `models` SET name=?, label=?, is_active=?, server_id=? WHERE id=?')
                    ->execute([$mname, $mlabel ?: $mname, $mactive, $msrvId, $mid]);
            } catch (Throwable) {
                db()->prepare('UPDATE `models` SET name=?, label=?, is_active=? WHERE id=?')
                    ->execute([$mname, $mlabel ?: $mname, $mactive, $mid]);
            }
        }
        header('Location: admin.php?page=api_servers&msg=' . urlencode('แก้ไข Model แล้ว')); exit;
    }

    /* ── Reorder model ── */
    if (isset($_POST['model_order'])) {
        $ids = array_map('intval', explode(',', $_POST['model_order']));
        $upd = db()->prepare('UPDATE `models` SET sort_order=? WHERE id=?');
        foreach ($ids as $i => $id) {
            if ($id > 0) $upd->execute([$i, $id]);
        }
        // Return JSON for AJAX call
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // Map legacy page names to merged api_servers tabs
    $pageMap = ['models' => 'api_servers', 'settings' => 'api_servers'];
    $destPage = $pageMap[$page] ?? urlencode($page);
    header('Location: admin.php?page=' . $destPage
        . ($flashMsg ? '&msg=' . urlencode($flashMsg) . '&type=' . urlencode($flashType) : ''));
    exit;
}

if (isset($_GET['msg'])) {
    $flashMsg  = e($_GET['msg']);
    $flashType = $_GET['type'] ?? 'success';
}

// ─── Dashboard stats ──────────────────────────────────────────────────────────
function getDashboardStats(): array {
    $pdo   = db();
    $stats = [
        'users'       => $pdo->query('SELECT COUNT(*) FROM users WHERE is_active=1')->fetchColumn(),
        'pending'     => $pdo->query('SELECT COUNT(*) FROM users WHERE is_active=0')->fetchColumn(),
        'convs'       => $pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn(),
        'msgs'        => $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn(),
        'today_msgs'  => $pdo->query('SELECT COUNT(*) FROM messages WHERE DATE(created_at)=CURDATE()')->fetchColumn(),
        'today_convs' => $pdo->query('SELECT COUNT(*) FROM conversations WHERE DATE(created_at)=CURDATE()')->fetchColumn(),
        'models'      => $pdo->query('SELECT COUNT(*) FROM `models` WHERE is_active=1')->fetchColumn(),
        'recent'      => $pdo->query('
            SELECT c.id, c.title, c.model, c.updated_at, u.username
            FROM conversations c JOIN users u ON u.id=c.user_id
            ORDER BY c.updated_at DESC LIMIT 8
        ')->fetchAll(),
    ];
    return $stats;
}

// ─── Login page ───────────────────────────────────────────────────────────────
function renderLoginPage(string $error = ''): void { ?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Login — AI Chat</title>
<?= themeAntiFlash() ?>
<?= themeFavicon() ?>
<style>
<?= themeVars() ?>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center}
.card{background:var(--bg2);border:1px solid var(--border);border-radius:20px;padding:40px;max-width:380px;width:90%}
.logo{width:52px;height:52px;background:#5436da;border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:26px}
h1{text-align:center;font-size:22px;font-weight:700;margin-bottom:4px}
.sub{text-align:center;color:var(--muted);font-size:13px;margin-bottom:28px}
label{display:block;font-size:13px;color:var(--text2);margin-bottom:5px;margin-top:16px}
input{width:100%;padding:11px 14px;background:var(--bg);border:1px solid var(--border2);border-radius:10px;color:var(--text);font-size:14px;outline:none;transition:.2s}
input:focus{border-color:#5436da;box-shadow:0 0 0 3px rgba(84,54,218,.15)}
.btn{width:100%;padding:13px;background:#5436da;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;margin-top:24px;transition:.2s}
.btn:hover{background:#4429c0}
.err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);border-radius:8px;padding:10px 14px;color:#f87171;font-size:13px;margin-bottom:16px}
</style>
</head>
<body>
<div class="card">
    <div class="logo">⚙️</div>
    <h1>Admin Panel</h1>
    <p class="sub">AI Chat Management System</p>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
        <input type="hidden" name="login" value="1">
        <label>Username</label>
        <input type="text" name="username" autofocus placeholder="admin" autocomplete="username">
        <label>Password</label>
        <input type="password" name="password" placeholder="••••••••" autocomplete="current-password">
        <button type="submit" class="btn">เข้าสู่ระบบ</button>
    </form>
    <div style="text-align:center;margin-top:18px">
        <a href="index.php" style="font-size:13px;color:#818cf8;text-decoration:none;opacity:.8">← กลับหน้าหลัก</a>
    </div>
</div>
<?= themeToggleBtn('theme-float') ?>
<?= themeScript() ?>
</body>
</html>
<?php }

$siteName = getSetting('site_name', 'AI Chat');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — <?= e($siteName) ?></title>
<?= themeAntiFlash() ?>
<?= themeFavicon() ?>
<style>
<?= themeVars() ?>
/* ── Reset ── */
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:system-ui,-apple-system,'Segoe UI',sans-serif;background:var(--bg);color:var(--text);display:flex;height:100vh;overflow:hidden}

/* ── Sidebar ── */
.sidebar{width:230px;background:var(--bg3);border-right:1px solid var(--border);display:flex;flex-direction:column;flex-shrink:0;overflow-y:auto}
.sidebar-logo{padding:20px 16px 14px;border-bottom:1px solid var(--border)}
.sidebar-logo h2{font-size:15px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.sidebar-logo span{font-size:11px;color:var(--muted);display:block;margin-top:2px}
.sidebar-section{padding:10px 8px 4px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);padding-left:16px}
nav{padding:6px 8px}
nav a{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:10px;color:var(--text2);font-size:13.5px;text-decoration:none;transition:.15s;margin-bottom:2px;white-space:nowrap}
nav a:hover{background:var(--hover-bg);color:var(--text)}
nav a.active{background:rgba(84,54,218,.18);color:#818cf8;font-weight:600}
nav a.active .ico{filter:none}
.ico{width:20px;text-align:center;font-size:16px;flex-shrink:0}
.sidebar-user{padding:12px 16px;border-top:1px solid var(--border);font-size:12px;color:var(--muted);margin-top:auto}
.sidebar-user strong{color:var(--text2);display:block;font-size:13px}

/* ── Main ── */
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0}
.topbar{padding:14px 24px;border-bottom:1px solid var(--border);background:var(--bg);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.topbar h1{font-size:18px;font-weight:700;display:flex;align-items:center;gap:10px}
.content{flex:1;overflow-y:auto;padding:24px}

/* ── Stat cards ── */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:14px;margin-bottom:24px}
.stat-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:18px 20px}
.stat-card .lbl{font-size:11px;color:var(--muted);margin-bottom:6px;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.stat-card .val{font-size:30px;font-weight:800;color:var(--text);line-height:1}
.stat-card .sub{font-size:11px;color:var(--muted);margin-top:5px}

/* ── Panels / cards ── */
.panel{background:var(--bg2);border:1px solid var(--border);border-radius:14px;margin-bottom:20px;overflow:hidden}
.panel-head{padding:14px 20px;font-size:14px;font-weight:700;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.panel-body{padding:20px}

/* ── Tables ── */
table{width:100%;border-collapse:collapse}
th{padding:9px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);text-align:left;border-bottom:1px solid var(--border);white-space:nowrap}
td{padding:11px 14px;font-size:13.5px;border-bottom:1px solid var(--border);vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:var(--hover-bg)}

/* ── Forms ── */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.fg{margin-bottom:14px}
.fg label{display:block;font-size:12px;color:var(--text2);margin-bottom:5px;font-weight:600}
.fg input,.fg select,.fg textarea{width:100%;padding:9px 12px;background:var(--bg);border:1px solid var(--border2);border-radius:9px;color:var(--text);font-size:13.5px;outline:none;transition:.15s;font-family:inherit}
.fg textarea{resize:vertical;min-height:90px}
.fg input:focus,.fg select:focus,.fg textarea:focus{border-color:#5436da;box-shadow:0 0 0 3px rgba(84,54,218,.12)}
.fg select option{background:var(--bg2)}
.fg small{display:block;color:var(--muted);font-size:11px;margin-top:4px}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 15px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:.15s;text-decoration:none;white-space:nowrap}
.btn-primary{background:#5436da;color:#fff}   .btn-primary:hover{background:#4429c0}
.btn-green  {background:#10a37f;color:#fff}   .btn-green:hover{background:#0d8a6a}
.btn-danger {background:rgba(239,68,68,.14);color:#f87171;border:1px solid rgba(239,68,68,.22)}  .btn-danger:hover{background:rgba(239,68,68,.24)}
.btn-ghost  {background:var(--hover-bg);color:var(--text2);border:1px solid var(--border2)} .btn-ghost:hover{background:var(--active-bg);color:var(--text)}
.btn-warning{background:rgba(234,179,8,.12);color:#eab308;border:1px solid rgba(234,179,8,.2)}    .btn-warning:hover{background:rgba(234,179,8,.22)}
.btn-sm{padding:5px 10px;font-size:12px;border-radius:7px}
.btn-xs{padding:3px 8px;font-size:11px;border-radius:6px}

/* ── Badges ── */
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;letter-spacing:.02em}
.b-admin{background:rgba(84,54,218,.2);color:#818cf8}
.b-user {background:rgba(16,163,127,.14);color:#34d399}
.b-on   {background:rgba(16,163,127,.14);color:#34d399}
.b-off  {background:rgba(239,68,68,.12);color:#f87171}

/* ── Flash ── */
.flash{padding:11px 16px;border-radius:10px;margin-bottom:18px;font-size:13.5px;display:flex;align-items:center;gap:10px}
.flash-success{background:rgba(16,163,127,.1);border:1px solid rgba(16,163,127,.22);color:#34d399}
.flash-error  {background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.22); color:#f87171}

/* ── Modals ── */
.modal-bg{display:none;position:fixed;inset:0;background:var(--overlay);z-index:200;align-items:center;justify-content:center}
.modal-bg.open{display:flex}
.modal{background:var(--bg2);border:1px solid var(--border2);border-radius:16px;padding:28px 32px;width:480px;max-width:92vw;max-height:88vh;overflow-y:auto}
.modal h3{font-size:16px;font-weight:700;margin-bottom:20px;display:flex;align-items:center;gap:8px}
.modal-footer{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap}

/* ── Drag handle (models) ── */
.drag-handle{cursor:grab;color:var(--muted);padding:4px;font-size:16px;user-select:none}
.drag-handle:active{cursor:grabbing}
.drag-over{background:rgba(84,54,218,.1)!important;outline:1px dashed #5436da}

/* ── Message viewer ── */
.msg-item{padding:11px 16px;border-bottom:1px solid var(--border)}
.msg-item:last-child{border-bottom:none}
.msg-item .role{font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:4px}
.role-user{color:#818cf8}.role-assistant{color:#34d399}
.msg-body{color:var(--text2);white-space:pre-wrap;word-break:break-word;font-size:13px;max-height:180px;overflow-y:auto}

/* ── Empty ── */
.empty{text-align:center;padding:36px;color:var(--muted)}
.empty-ico{font-size:36px;margin-bottom:10px}

/* ── Search input ── */
.search-row{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.search-row input{flex:1;min-width:180px;padding:9px 12px;background:var(--bg2);border:1px solid var(--border2);border-radius:9px;color:var(--text);font-size:13px;outline:none}
.search-row input:focus{border-color:#5436da}

/* ── Scrollbar ── */
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--scrollbar);border-radius:3px}

/* ── Divider ── */
.divider{border:none;border-top:1px solid var(--border);margin:20px 0}

@media(max-width:768px){
    .sidebar{position:fixed;left:0;top:0;bottom:0;z-index:100;transform:translateX(-100%);transition:.3s}
    .sidebar.open{transform:none}
    .overlay{display:none;position:fixed;inset:0;background:var(--overlay);z-index:99}
    .overlay.show{display:block}
    .form-grid{grid-template-columns:1fr}
    .topbar .hamburger{display:flex}
}
.hamburger{display:none;background:none;border:none;color:var(--text);cursor:pointer;padding:6px;border-radius:7px;align-items:center}
.hamburger:hover{background:var(--hover-bg)}
</style>
</head>
<body>

<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<!-- ═══════ SIDEBAR ═══════ -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <h2>⚙️ Admin Panel</h2>
        <span><?= e($siteName) ?></span>
    </div>

    <div class="sidebar-section">ภาพรวม</div>
    <nav>
        <a href="?page=dashboard"     class="<?= $page==='dashboard'?'active':'' ?>">     <span class="ico">📊</span>Dashboard</a>
    </nav>

    <div class="sidebar-section">ระบบ AI</div>
    <nav>
        <a href="?page=api_servers" class="<?= in_array($page,['api_servers','settings','models'])?'active':'' ?>"><span class="ico">🖥️</span>API Servers &amp; Models</a>
    </nav>

    <div class="sidebar-section">ระบบ</div>
    <nav>
        <a href="?page=general_settings" class="<?= $page==='general_settings'?'active':'' ?>"><span class="ico">⚙️</span>ตั้งค่าระบบ</a>
    </nav>

    <div class="sidebar-section">ผู้ใช้งาน</div>
    <nav>
        <?php $pendingCount = (int)db()->query('SELECT COUNT(*) FROM users WHERE is_active=0')->fetchColumn(); ?>
        <a href="?page=users" class="<?= $page==='users'?'active':'' ?>" style="display:flex;align-items:center;justify-content:space-between">
            <span><span class="ico">👥</span>จัดการผู้ใช้</span>
            <?php if ($pendingCount > 0): ?><span style="background:#eab308;color:#000;border-radius:10px;font-size:11px;font-weight:700;padding:1px 7px;min-width:20px;text-align:center"><?= $pendingCount ?></span><?php endif; ?>
        </a>
        <a href="?page=tokens"        class="<?= $page==='tokens'?'active':'' ?>">        <span class="ico">🪙</span>ควบคุม Token</a>
        <a href="?page=conversations" class="<?= $page==='conversations'?'active':'' ?>"> <span class="ico">💬</span>การสนทนา</a>
    </nav>

    <div class="sidebar-section">ลิงก์ด่วน</div>
    <nav>
        <a href="chat.php" target="_blank"><span class="ico">🚀</span>เปิด Chat</a>
        <a href="?page=logout" style="color:#f87171"><span class="ico">🚪</span>ออกจากระบบ</a>
    </nav>

    <div class="sidebar-user">
        <strong><?= e($adminUsername) ?></strong>
        Administrator
    </div>

    <?php
        // version จากเวลา commit ล่าสุด: v0.yyMMddHHii
        $null   = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $gitTs  = @shell_exec('git -C ' . escapeshellarg(__DIR__) . ' log -1 --format=%ci 2>' . $null);
        $gitTs  = trim((string)$gitTs);
        $verStr = 'v0.dev';
        if ($gitTs && ($dt = date_create($gitTs))) {
            $verStr = 'v0.' . date_format($dt, 'ymdHi');
        }
    ?>
    <div style="text-align:center;padding:10px 0 14px;font-size:10px;color:var(--muted);letter-spacing:.04em;opacity:.6">
        <?= e($verStr) ?>
    </div>
</div>

<!-- ═══════ MAIN ═══════ -->
<div class="main">
    <div class="topbar">
        <h1>
            <button class="hamburger" id="hamburgerBtn" onclick="toggleSidebar()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="3" y1="6"  x2="21" y2="6"></line>
                    <line x1="3" y1="12" x2="21" y2="12"></line>
                    <line x1="3" y1="18" x2="21" y2="18"></line>
                </svg>
            </button>
            <?php
            $titles = [
                'dashboard'     => '📊 Dashboard',
                'settings'      => '🔧 ตั้งค่า API',
                'models'        => '🤖 จัดการ AI Models',
                'users'         => '👥 จัดการผู้ใช้',
                'conversations' => '💬 การสนทนา',
            ];
            echo e($titles[$page] ?? 'Dashboard');
            ?>
        </h1>
        <div style="display:flex;align-items:center;gap:8px">
            <?= themeToggleBtn() ?>
            <a href="chat.php" class="btn btn-green" target="_blank">🚀 เปิด Chat</a>
        </div>
    </div>

    <div class="content">

    <?php if ($flashMsg): ?>
    <div class="flash flash-<?= e($flashType) ?>">
        <?= $flashType === 'success' ? '✅' : '❌' ?> <?= $flashMsg ?>
    </div>
    <?php endif; ?>

    <!-- ════════════════════════════════════════════════════════════════════
         DASHBOARD
    ════════════════════════════════════════════════════════════════════ -->
    <?php if ($page === 'dashboard'):
        $s = getDashboardStats(); ?>

    <div class="stat-grid">
        <div class="stat-card" <?= (int)$s['pending'] > 0 ? 'style="border-color:rgba(234,179,8,.4)"' : '' ?>>
            <div class="lbl">ผู้ใช้</div>
            <div class="val"><?= number_format((int)$s['users']) ?></div>
            <div class="sub">บัญชีที่ใช้งานได้<?php if ((int)$s['pending'] > 0): ?> · <a href="admin.php?page=users" style="color:#eab308;font-weight:700"><?= (int)$s['pending'] ?> รออนุมัติ</a><?php endif; ?></div>
        </div>
        <div class="stat-card"><div class="lbl">AI Models</div><div class="val"><?= number_format((int)$s['models']) ?></div><div class="sub">ที่เปิดใช้งาน</div></div>
        <div class="stat-card"><div class="lbl">การสนทนา</div><div class="val"><?= number_format((int)$s['convs']) ?></div><div class="sub">ทั้งหมด</div></div>
        <div class="stat-card"><div class="lbl">ข้อความ</div><div class="val"><?= number_format((int)$s['msgs']) ?></div><div class="sub">รวมทุกการสนทนา</div></div>
        <div class="stat-card"><div class="lbl">วันนี้</div><div class="val"><?= number_format((int)$s['today_convs']) ?></div><div class="sub"><?= number_format((int)$s['today_msgs']) ?> ข้อความ</div></div>
    </div>

    <div class="panel">
        <div class="panel-head">🕐 การสนทนาล่าสุด <a href="?page=conversations" class="btn btn-ghost btn-sm">ดูทั้งหมด</a></div>
        <?php if (empty($s['recent'])): ?>
        <div class="empty"><div class="empty-ico">💬</div>ยังไม่มีการสนทนา</div>
        <?php else: ?>
        <table>
            <thead><tr><th>หัวข้อ</th><th>ผู้ใช้</th><th>Model</th><th>เวลา</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($s['recent'] as $r): ?>
            <tr>
                <td><?= e(mb_substr($r['title'] ?? 'New Chat', 0, 55)) ?></td>
                <td><?= e($r['username']) ?></td>
                <td><code style="font-size:11px;color:#818cf8"><?= e($r['model']) ?></code></td>
                <td style="color:var(--muted);font-size:12px"><?= date('d/m/y H:i', strtotime($r['updated_at'])) ?></td>
                <td><a href="?page=conversations&view=<?= $r['id'] ?>" class="btn btn-ghost btn-xs">ดู</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- ════════════════════════════════════════════════════════════════════
         AI SERVERS & MODELS  (server-centric card layout)
    ════════════════════════════════════════════════════════════════════ -->
    <?php elseif (in_array($page, ['api_servers','settings','models'])):
        $serverList = db()->query('SELECT * FROM api_servers ORDER BY sort_order ASC, id ASC')->fetchAll();
        try {
            $allModels = db()->query('SELECT * FROM `models` ORDER BY sort_order ASC, id ASC')->fetchAll();
        } catch (Throwable) { $allModels = []; }
        $modelsByServer = [];
        foreach ($allModels as $_m) { $modelsByServer[(int)($_m['server_id'] ?? 0)][] = $_m; }
        $pageMsg = e($_GET['msg'] ?? '');
    ?>
    <style>
    .srv-card{border:1px solid var(--border2);border-radius:14px;margin-bottom:22px;overflow:hidden;background:var(--bg2)}
    .srv-head{display:flex;align-items:center;gap:10px;padding:13px 16px;background:var(--bg4);border-bottom:1px solid var(--border)}
    .srv-title{font-weight:700;font-size:15px}
    .srv-meta{display:flex;gap:14px;align-items:center;flex-wrap:wrap;padding:4px 16px 10px 16px;background:var(--bg4);border-bottom:1px solid var(--border);font-size:12px}
    .srv-meta code{color:#818cf8;font-size:11px}
    .srv-models table{margin:0;border-radius:0}
    .srv-models thead th{background:rgba(99,102,241,.04);font-size:11px;padding:6px 14px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
    .add-model-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:10px 14px;border-top:1px solid var(--border);background:rgba(99,102,241,.03)}
    .add-model-bar input[type=text]{flex:1;min-width:130px;padding:6px 10px;font-size:13px;border-radius:7px;border:1px solid var(--border2);background:var(--bg3);color:var(--text);font-family:inherit}
    .add-model-bar input[type=text]:focus{outline:none;border-color:#818cf8}
    .srv-empty{padding:16px;color:var(--muted);font-size:13px;text-align:center;border-top:1px dashed var(--border)}
    details>summary{list-style:none}.details>summary::-webkit-details-marker{display:none}
    </style>

    <?php if ($pageMsg): ?>
    <div class="alert alert-success" style="margin-bottom:16px"><?= $pageMsg ?></div>
    <?php endif; ?>

    <!-- ── Add Server button ── -->
    <div style="margin-bottom:18px">
        <button class="btn btn-primary" onclick="document.getElementById('addSrvForm').classList.toggle('d-none')">➕ เพิ่ม AI Server ใหม่</button>
        <div id="addSrvForm" class="panel d-none" style="margin-top:10px;margin-bottom:0">
            <div class="panel-head">➕ เพิ่ม AI Server</div>
            <div class="panel-body">
            <form method="POST">
                <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr">
                    <div class="fg"><label>ชื่อ Server *</label><input type="text" name="server_name" placeholder="เช่น GPU Server 1, OpenAI" required></div>
                    <div class="fg"><label>Base URL *</label><input type="text" name="server_base_url" placeholder="http://192.168.1.10:11434/v1" required></div>
                    <div class="fg"><label>API Key</label><input type="text" name="server_api_key" placeholder="ollama / sk-..."><small>เว้นว่างจะใช้ "ollama"</small></div>
                </div>
                <div style="display:flex;gap:8px">
                    <button type="submit" name="add_api_server" value="1" class="btn btn-primary">เพิ่ม Server</button>
                    <button type="button" class="btn btn-ghost" onclick="document.getElementById('addSrvForm').classList.add('d-none')">ยกเลิก</button>
                </div>
            </form>
            </div>
        </div>
    </div>
    <style>.d-none{display:none!important}</style>

    <?php if (empty($serverList)): ?>
    <div class="panel"><div class="empty"><div class="empty-ico">🖥️</div>ยังไม่มี AI Server — กดปุ่มด้านบนเพื่อเพิ่ม</div></div>
    <?php endif; ?>

    <!-- ── Server Cards ── -->
    <?php foreach ($serverList as $s):
        $sModels = $modelsByServer[(int)$s['id']] ?? [];
    ?>
    <div class="srv-card">
        <div class="srv-head">
            <span style="font-size:18px"><?= $s['is_active'] ? '🖥️' : '💤' ?></span>
            <span class="srv-title"><?= e($s['name']) ?></span>
            <span class="badge <?= $s['is_active'] ? 'b-on' : 'b-off' ?>" style="font-size:11px"><?= $s['is_active'] ? '● เปิด' : '○ ปิด' ?></span>
            <span style="margin-left:auto;font-size:12px;color:var(--muted)"><?= count($sModels) ?> model<?= count($sModels)!==1?'s':'' ?></span>
            <div style="display:flex;gap:5px">
                <form method="POST" style="display:inline"><input type="hidden" name="toggle_api_server" value="<?= $s['id'] ?>">
                    <button class="btn btn-ghost btn-sm"><?= $s['is_active'] ? '⏸ ปิด' : '▶ เปิด' ?></button></form>
                <button class="btn btn-ghost btn-sm" onclick="openEditServer(<?= $s['id'] ?>, '<?= e(addslashes($s['name'])) ?>', '<?= e(addslashes($s['base_url'])) ?>', '<?= e(addslashes($s['api_key'])) ?>')">✏️ แก้ไข</button>
                <form method="POST" onsubmit="return confirm('ลบ Server <?= e(addslashes($s['name'])) ?>?\nModels จะถูกย้ายไป Global')" style="display:inline">
                    <input type="hidden" name="delete_api_server" value="<?= $s['id'] ?>">
                    <button class="btn btn-danger btn-sm">🗑</button>
                </form>
            </div>
        </div>
        <div class="srv-meta">
            <span>🌐 <code><?= e($s['base_url']) ?></code></span>
            <span style="color:var(--muted)">🔑 <code style="color:var(--muted)"><?= e(str_repeat('●', max(0,strlen($s['api_key'])-4)).substr($s['api_key'],-4)) ?></code></span>
        </div>

        <div class="srv-models">
            <?php if (empty($sModels)): ?>
            <div class="srv-empty">ยังไม่มี Model — เพิ่มด้านล่าง</div>
            <?php else: ?>
            <table>
                <thead><tr><th>ชื่อ Model (API)</th><th>Label (แสดงผล)</th><th style="width:80px">สถานะ</th><th style="width:90px">จัดการ</th></tr></thead>
                <tbody>
                <?php foreach ($sModels as $m): ?>
                <tr>
                    <td><code style="color:#818cf8;font-size:13px"><?= e($m['name']) ?></code></td>
                    <td style="color:var(--text2);font-size:13px"><?= e($m['label'] ?: $m['name']) ?></td>
                    <td>
                        <form method="POST" style="display:inline"><input type="hidden" name="toggle_model" value="<?= $m['id'] ?>">
                            <button class="badge <?= $m['is_active'] ? 'b-on' : 'b-off' ?>" style="cursor:pointer;border:none;font-family:inherit;font-size:11px"><?= $m['is_active'] ? '● เปิด' : '○ ปิด' ?></button>
                        </form>
                    </td>
                    <td><div style="display:flex;gap:4px">
                        <button class="btn btn-ghost btn-sm" onclick="openEditModel(<?= $m['id'] ?>, '<?= e(addslashes($m['name'])) ?>', '<?= e(addslashes($m['label'])) ?>', <?= $m['is_active']?1:0 ?>, <?= (int)$s['id'] ?>)">✏️</button>
                        <form method="POST" onsubmit="return confirm('ลบ <?= e(addslashes($m['name'])) ?>?')" style="display:inline">
                            <input type="hidden" name="delete_model" value="<?= $m['id'] ?>"><button class="btn btn-danger btn-sm">🗑</button>
                        </form>
                    </div></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <div class="add-model-bar">
                <form method="POST" style="display:contents">
                    <input type="hidden" name="model_server_id" value="<?= $s['id'] ?>">
                    <span style="font-size:12px;color:var(--muted);white-space:nowrap">➕ เพิ่ม Model:</span>
                    <input type="text" name="model_name" placeholder="ชื่อ Model API เช่น llama3:8b" required>
                    <input type="text" name="model_label" placeholder="Label (ไม่จำเป็น)">
                    <button type="submit" name="add_model" value="1" class="btn btn-primary btn-sm">เพิ่ม</button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Edit Server Modal -->
    <div class="modal-bg" id="editServerModal">
        <div class="modal">
            <h3>✏️ แก้ไข AI Server</h3>
            <form method="POST">
                <input type="hidden" name="edit_api_server" value="1">
                <input type="hidden" name="edit_server_id" id="editServerId">
                <div class="fg"><label>ชื่อ Server</label><input type="text" name="edit_server_name" id="editServerName" required></div>
                <div class="fg"><label>Base URL</label><input type="text" name="base_url" id="settingBaseUrl" required></div>
                <div class="fg"><label>API Key</label><input type="text" name="api_key" id="settingApiKey"></div>
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;flex-wrap:wrap">
                    <button type="button" class="btn btn-ghost btn-sm" id="testConnBtn" onclick="testApiConnection()">🔌 ทดสอบการเชื่อมต่อ</button>
                    <div id="testConnResult" style="font-size:13px;display:none;padding:6px 12px;border-radius:8px;font-weight:500"></div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">💾 บันทึก</button>
                    <button type="button" class="btn btn-ghost" onclick="closeModal('editServerModal')">ยกเลิก</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Model Modal -->
    <div class="modal-bg" id="editModelModal">
        <div class="modal">
            <h3>✏️ แก้ไข AI Model</h3>
            <form method="POST">
                <input type="hidden" name="edit_model" value="1">
                <input type="hidden" name="edit_model_id" id="editModelId">
                <div class="fg"><label>ชื่อ Model (API)</label><input type="text" name="edit_model_name" id="editModelName" required></div>
                <div class="fg"><label>Label (แสดงผล)</label><input type="text" name="edit_model_label" id="editModelLabel" placeholder="เว้นว่างใช้ชื่อ Model"></div>
                <div class="fg">
                    <label>ย้ายไป Server</label>
                    <select name="edit_model_server_id" id="editModelServerId">
                        <option value="">— ไม่ผูก (Global Fallback) —</option>
                        <?php foreach ($serverList as $_srv): ?>
                        <option value="<?= $_srv['id'] ?>"><?= e($_srv['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label style="margin-bottom:8px;display:block">สถานะการใช้งาน</label>
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;user-select:none">
                        <input type="checkbox" name="edit_model_active" id="editModelActive" value="1" style="width:18px;height:18px;accent-color:#10a37f;cursor:pointer">
                        <span id="editModelActiveLabel" style="font-size:13px"></span>
                    </label>
                    <small style="color:var(--muted)">ปิดการใช้งาน = ซ่อนจาก dropdown ในหน้า Chat แต่ยังเก็บข้อมูลไว้</small>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">💾 บันทึก</button>
                    <button type="button" class="btn btn-ghost" onclick="closeModal('editModelModal')">ยกเลิก</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openEditServer(id, name, url, key) {
        document.getElementById('editServerId').value   = id;
        document.getElementById('editServerName').value = name;
        document.getElementById('settingBaseUrl').value = url;
        document.getElementById('settingApiKey').value  = key;
        document.getElementById('testConnResult').style.display = 'none';
        document.getElementById('editServerModal').classList.add('open');
    }
    </script>

    <!-- ════════════════════════════════════════════════════════════════════
         GENERAL SETTINGS
    ════════════════════════════════════════════════════════════════════ -->
    <?php elseif ($page === 'general_settings'):
        $gsMsg = e($_GET['msg'] ?? '');
    ?>
    <?php if ($gsMsg): ?>
    <div class="alert alert-success" style="margin-bottom:16px"><?= $gsMsg ?></div>
    <?php endif; ?>

    <form method="POST">
    <input type="hidden" name="save_general_settings" value="1">
    <div class="panel">
        <div class="panel-head">💬 ตั้งค่าทั่วไป</div>
        <div class="panel-body">
            <div class="fg">
                <label>ชื่อเว็บไซต์</label>
                <input type="text" name="site_name" value="<?= e(getSetting('site_name','AI Chat')) ?>">
            </div>
            <div class="fg">
                <label>System Prompt</label>
                <textarea name="system_prompt" rows="6"><?= e(getSetting('system_prompt')) ?></textarea>
                <small>ส่งเป็น system message ทุกครั้งที่มีการสนทนา</small>
            </div>
        </div>
    </div>
    <div class="panel">
        <div class="panel-head">📝 การลงทะเบียน</div>
        <div class="panel-body">
            <div class="fg" style="flex-direction:row;align-items:center;gap:10px">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:600;margin:0">
                    <input type="checkbox" name="allow_registration" value="1" <?= getSetting('allow_registration','1')==='1'?'checked':'' ?> style="width:18px;height:18px;accent-color:#10a37f">
                    เปิดให้ผู้ใช้ลงทะเบียนขอเข้าใช้งาน (ต้อง Admin อนุมัติ)
                </label>
            </div>
            <div class="fg">
                <label>ข้อความแจ้งผู้ลงทะเบียน</label>
                <input type="text" name="registration_note" value="<?= e(getSetting('registration_note','กรุณารอการอนุมัติจาก Admin ก่อนเข้าใช้งาน')) ?>">
                <small>แสดงใต้ฟอร์มลงทะเบียน และหลังส่งคำขอสำเร็จ</small>
            </div>
        </div>
    </div>
    <button type="submit" class="btn btn-primary">💾 บันทึกการตั้งค่า</button>
    </form>

    <!-- ════════════════════════════════════════════════════════════════════
         USERS
    ════════════════════════════════════════════════════════════════════ -->
    <?php elseif ($page === 'users'):
        // ตรวจว่า column token ใหม่มีหรือไม่ (อาจยังไม่ได้ run migration บน server)
        try {
            $users = db()->query('SELECT id,username,display_name,role,is_active,created_at,last_login,token_limit,tokens_used,tokens_total,tokens_reset_at,token_reset_hours FROM users WHERE is_active=1 ORDER BY role DESC, created_at ASC')->fetchAll();
        } catch (Throwable) {
            // fallback: ไม่มี token columns (migration ยังไม่ได้รัน)
            $users = db()->query('SELECT id,username,display_name,role,is_active,created_at,last_login,NULL AS token_limit,0 AS tokens_used,0 AS tokens_total,NULL AS tokens_reset_at,NULL AS token_reset_hours FROM users WHERE is_active=1 ORDER BY role DESC, created_at ASC')->fetchAll();
        }
        try {
            $pendingUsers = db()->query('SELECT id,username,display_name,created_at FROM users WHERE is_active=0 ORDER BY created_at ASC')->fetchAll();
        } catch (Throwable) {
            $pendingUsers = [];
        }
    ?>

    <!-- Pending registrations -->
    <?php if (!empty($pendingUsers)): ?>
    <div class="panel" style="border:1px solid rgba(234,179,8,.35);background:rgba(234,179,8,.04)">
        <div class="panel-head" style="color:#eab308">
            🕐 รออนุมัติการลงทะเบียน
            <span class="badge" style="background:rgba(234,179,8,.18);color:#eab308;font-size:13px"><?= count($pendingUsers) ?> คน</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Username</th>
                    <th>ชื่อที่แสดง</th>
                    <th>ส่งคำขอเมื่อ</th>
                    <th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pendingUsers as $pu): ?>
            <tr>
                <td style="color:var(--muted)"><?= $pu['id'] ?></td>
                <td><strong><?= e($pu['username']) ?></strong></td>
                <td style="color:var(--text2)"><?= e($pu['display_name'] ?: '—') ?></td>
                <td style="color:var(--muted);font-size:12px"><?= date('d/m/y H:i', strtotime($pu['created_at'])) ?></td>
                <td>
                    <div style="display:flex;gap:6px">
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="approve_user" value="<?= $pu['id'] ?>">
                            <button class="btn btn-primary btn-sm">✅ อนุมัติ</button>
                        </form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('ปฏิเสธและลบคำขอของ <?= e(addslashes($pu['username'])) ?>?')">
                            <input type="hidden" name="reject_user" value="<?= $pu['id'] ?>">
                            <button class="btn btn-danger btn-sm">✖ ปฏิเสธ</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Add user -->
    <div class="panel">
        <div class="panel-head">➕ เพิ่มผู้ใช้ใหม่</div>
        <div class="panel-body">
        <form method="POST">
            <div class="form-grid">
                <div class="fg">
                    <label>Username *</label>
                    <input type="text" name="new_username" required placeholder="username">
                </div>
                <div class="fg">
                    <label>ชื่อที่แสดง</label>
                    <input type="text" name="new_display" placeholder="ชื่อ-นามสกุล">
                </div>
                <div class="fg">
                    <label>Password * (อย่างน้อย 6 ตัว)</label>
                    <input type="password" name="new_password" required autocomplete="new-password">
                </div>
                <div class="fg">
                    <label>บทบาท</label>
                    <select name="new_role">
                        <option value="user">User — ใช้งาน Chat</option>
                        <option value="admin">Admin — จัดการระบบ</option>
                    </select>
                </div>
                <?php
                    $glbLimNew = (int)getSetting('default_token_limit','0');
                    $glbRstNew = (int)getSetting('default_token_reset_hours','0');
                ?>
                <div class="fg">
                    <label>Token Limit <small style="font-weight:400;text-transform:none;color:var(--muted)">เว้นว่าง = ใช้ค่ากลาง</small></label>
                    <input type="number" name="new_token_limit" min="0" step="1000"
                           placeholder="ค่ากลาง: <?= $glbLimNew > 0 ? number_format($glbLimNew) . ' token' : 'ไม่จำกัด' ?>">
                    <small style="color:var(--muted)">🌐 ค่ากลางปัจจุบัน: <?= $glbLimNew > 0 ? fmtTokens($glbLimNew) : 'ไม่จำกัด' ?> · ใส่ตัวเลขเพื่อกำหนดค่าเฉพาะ</small>
                </div>
                <div class="fg">
                    <label>รีเซ็ตทุก (ชั่วโมง) <small style="font-weight:400;text-transform:none;color:var(--muted)">เว้นว่าง = ใช้ค่ากลาง</small></label>
                    <input type="number" name="new_token_reset_hours" min="0" step="1"
                           placeholder="ค่ากลาง: <?= $glbRstNew > 0 ? $glbRstNew . ' ชม.' : 'ไม่รีเซ็ต' ?>">
                    <small style="color:var(--muted)">🌐 ค่ากลางปัจจุบัน: <?= $glbRstNew > 0 ? $glbRstNew . ' ชม.' : 'ไม่รีเซ็ต' ?> · 24 = รายวัน · 168 = รายสัปดาห์</small>
                </div>
            </div>
            <button type="submit" name="add_user" value="1" class="btn btn-primary">➕ เพิ่มผู้ใช้</button>
        </form>
        </div>
    </div>

    <!-- Users table -->
    <div class="panel">
        <div class="panel-head">
            👥 ผู้ใช้ทั้งหมด
            <span class="badge b-user" style="font-size:13px"><?= count($users) ?> คน</span>
        </div>
        <?php if (empty($users)): ?>
        <div class="empty"><div class="empty-ico">👤</div>ยังไม่มีผู้ใช้</div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Username</th>
                    <th>ชื่อที่แสดง</th>
                    <th>บทบาท</th>
                    <th>สถานะ</th>
                    <th>Token Usage</th>
                    <th>เข้าล่าสุด</th>
                    <th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
            <?php
                $tUsed  = (int)($u['tokens_used']  ?? 0);
                $tTotal = (int)($u['tokens_total'] ?? 0);
                // resolve: NULL → ใช้ค่ากลาง
                $tLimitR      = resolveAdminToken(
                    $u['token_limit'] !== null ? (int)$u['token_limit'] : null,
                    'default_token_limit', 0
                );
                $tResetR      = resolveAdminToken(
                    $u['token_reset_hours'] !== null ? (int)$u['token_reset_hours'] : null,
                    'default_token_reset_hours', 0
                );
                $tLimit      = $tLimitR['val'];
                $tResetHours = $tResetR['val'];
                $tResetAt    = $u['tokens_reset_at'] ?? null;
                $tPct        = ($tLimit > 0) ? min(100, round($tUsed/$tLimit*100)) : 0;
                $tColor      = $tPct >= 90 ? '#f87171' : ($tPct >= 70 ? '#eab308' : '#34d399');
                // คำนวณเวลา reset ถัดไป
                $nextResetStr = '';
                if ($tResetHours > 0 && $tResetAt) {
                    $nextTs = strtotime($tResetAt) + ($tResetHours * 3600);
                    $diff   = $nextTs - time();
                    if ($diff > 0) {
                        $rh = floor($diff/3600); $rm = floor(($diff%3600)/60);
                        $nextResetStr = $rh > 0 ? "รีเซ็ตใน {$rh}ชม.{$rm}น." : "รีเซ็ตใน {$rm}น.";
                    } else {
                        $nextResetStr = 'รีเซ็ตครั้งถัดไป (รอ request)';
                    }
                } elseif ($tResetHours > 0) {
                    $nextResetStr = "รีเซ็ตทุก {$tResetHours}ชม.";
                }
            ?>
            <tr>
                <td style="color:var(--muted)"><?= $u['id'] ?></td>
                <td>
                    <strong><?= e($u['username']) ?></strong>
                    <?php if ($u['id'] == $adminId): ?>
                    <span style="font-size:10px;color:#818cf8;font-weight:600"> (คุณ)</span>
                    <?php endif; ?>
                </td>
                <td style="color:var(--text2)"><?= e($u['display_name'] ?: '—') ?></td>
                <td><span class="badge b-<?= $u['role'] ?>"><?= $u['role'] === 'admin' ? '⭐ Admin' : '👤 User' ?></span></td>
                <td>
                    <?php if ($u['id'] != $adminId): ?>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="toggle_user" value="<?= $u['id'] ?>">
                        <button class="badge <?= $u['is_active'] ? 'b-on' : 'b-off' ?>" style="cursor:pointer;border:none;font-family:inherit">
                            <?= $u['is_active'] ? '● เปิด' : '○ ปิด' ?>
                        </button>
                    </form>
                    <?php else: ?>
                    <span class="badge b-on">● เปิด</span>
                    <?php endif; ?>
                </td>
                <td style="min-width:160px">
                    <!-- badge แสดงว่าใช้ค่ากลางหรือค่าส่วนตัว -->
                    <div style="margin-bottom:4px;display:flex;gap:4px;flex-wrap:wrap">
                        <?php if ($tLimitR['inherited']): ?>
                        <span style="font-size:9px;padding:1px 6px;border-radius:8px;background:rgba(99,102,241,.18);color:#818cf8;font-weight:600">🌐 ค่ากลาง</span>
                        <?php else: ?>
                        <span style="font-size:9px;padding:1px 6px;border-radius:8px;background:rgba(16,163,127,.15);color:#34d399;font-weight:600">👤 ส่วนตัว</span>
                        <?php endif; ?>
                        <?php if ($tResetR['inherited'] && $tResetHours > 0): ?>
                        <span style="font-size:9px;padding:1px 6px;border-radius:8px;background:rgba(99,102,241,.12);color:#818cf8;font-weight:600">⏱ กลาง</span>
                        <?php elseif (!$tResetR['inherited'] && $tResetHours > 0): ?>
                        <span style="font-size:9px;padding:1px 6px;border-radius:8px;background:rgba(16,163,127,.1);color:#34d399;font-weight:600">⏱ ส่วนตัว</span>
                        <?php endif; ?>
                    </div>
                    <!-- ช่วงปัจจุบัน -->
                    <div style="font-size:12px;font-weight:600;color:<?= $tColor ?>;margin-bottom:3px">
                        <?= fmtTokens($tUsed) ?>
                        <?php if ($tLimit > 0): ?>
                        / <?= fmtTokens($tLimit) ?>
                        <span style="color:var(--muted);font-weight:400">(<?= $tPct ?>%)</span>
                        <?php else: ?>
                        <span style="color:var(--muted);font-weight:400">/ ไม่จำกัด</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($tLimit > 0): ?>
                    <div style="height:4px;border-radius:2px;background:var(--border2);overflow:hidden;margin-bottom:3px">
                        <div style="height:100%;width:<?= $tPct ?>%;background:<?= $tColor ?>;border-radius:2px;transition:.3s"></div>
                    </div>
                    <?php endif; ?>
                    <!-- สถิติสะสม -->
                    <?php if ($tTotal > 0): ?>
                    <div style="font-size:10px;color:var(--muted)">รวมทั้งหมด: <?= fmtTokens($tTotal) ?></div>
                    <?php endif; ?>
                    <!-- เวลา reset -->
                    <?php if ($nextResetStr): ?>
                    <div style="font-size:10px;color:#eab308;margin-top:2px">⏱ <?= $nextResetStr ?></div>
                    <?php endif; ?>
                    <?php if ($tUsed > 0): ?>
                    <form method="POST" style="margin-top:4px;display:inline">
                        <input type="hidden" name="reset_tokens_uid" value="<?= $u['id'] ?>">
                        <button name="reset_tokens" value="1" class="btn btn-xs btn-ghost" style="font-size:10px" onclick="return confirm('รีเซ็ต token usage ของ <?= e($u['username']) ?>?')">↺ รีเซ็ต</button>
                    </form>
                    <?php endif; ?>
                </td>
                <td style="color:var(--muted);font-size:12px"><?= $u['last_login'] ? date('d/m/y H:i', strtotime($u['last_login'])) : '—' ?></td>
                <td>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        <?php
                            $rawTL  = $u['token_limit']       !== null ? (int)$u['token_limit']       : 'null';
                            $rawTR  = $u['token_reset_hours'] !== null ? (int)$u['token_reset_hours'] : 'null';
                            $glbLim = (int)getSetting('default_token_limit','0');
                            $glbRst = (int)getSetting('default_token_reset_hours','0');
                        ?>
                        <button class="btn btn-ghost btn-sm"
                            onclick="openEditUser(<?= $u['id'] ?>,'<?= e(addslashes($u['username'])) ?>','<?= e(addslashes($u['display_name'])) ?>','<?= $u['role'] ?>',<?= $rawTL ?>,<?= $rawTR ?>,<?= $glbLim ?>,<?= $glbRst ?>)">
                            ✏️ แก้ไข
                        </button>
                        <?php if ($u['id'] != $adminId): ?>
                        <form method="POST" onsubmit="return confirm('ลบผู้ใช้ <?= e(addslashes($u['username'])) ?> และข้อมูลทั้งหมด?')" style="display:inline">
                            <input type="hidden" name="delete_user" value="<?= $u['id'] ?>">
                            <button class="btn btn-danger btn-sm">🗑 ลบ</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Edit User Modal -->
    <div class="modal-bg" id="editUserModal">
        <div class="modal">
            <h3>✏️ แก้ไขผู้ใช้ — <span id="editUserNameLabel"></span></h3>
            <form method="POST">
                <input type="hidden" name="edit_user" value="1">
                <input type="hidden" name="edit_uid" id="editUid">
                <div class="fg">
                    <label>Username (แก้ไขไม่ได้)</label>
                    <input type="text" id="editUsernameShow" disabled style="opacity:.5">
                </div>
                <div class="fg">
                    <label>ชื่อที่แสดง</label>
                    <input type="text" name="edit_display" id="editDisplay" placeholder="ชื่อ-นามสกุล">
                </div>
                <div class="fg">
                    <label>บทบาท</label>
                    <select name="edit_role" id="editRole">
                        <option value="user">User — ใช้งาน Chat</option>
                        <option value="admin">Admin — จัดการระบบ</option>
                    </select>
                </div>
                <div class="fg">
                    <label>Token Limit <small style="font-weight:400;text-transform:none;color:var(--muted)">เว้นว่าง = ใช้ค่ากลาง</small></label>
                    <input type="number" name="edit_token_limit" id="editTokenLimit" min="0" step="1000">
                    <small id="editTokenLimitHint" style="color:var(--muted)">เว้นว่าง = ใช้ค่ากลาง · 0 = ไม่จำกัดสำหรับ user นี้ · 1K token ≈ 750 คำ</small>
                </div>
                <div class="fg">
                    <label>รีเซ็ตทุก (ชั่วโมง) <small style="font-weight:400;text-transform:none;color:var(--muted)">เว้นว่าง = ใช้ค่ากลาง</small></label>
                    <input type="number" name="edit_token_reset_hours" id="editTokenResetHours" min="0" step="1">
                    <small id="editResetHint" style="color:var(--muted)">เว้นว่าง = ใช้ค่ากลาง · 0 = ไม่รีเซ็ต · 24 = รายวัน · 168 = รายสัปดาห์</small>
                </div>
                <hr class="divider">
                <div class="fg">
                    <label>เปลี่ยนรหัสผ่าน <small style="font-weight:400;text-transform:none">(เว้นว่างถ้าไม่ต้องการเปลี่ยน)</small></label>
                    <input type="password" name="edit_password" placeholder="รหัสผ่านใหม่ (อย่างน้อย 6 ตัว)" autocomplete="new-password">
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">💾 บันทึก</button>
                    <button type="button" class="btn btn-ghost" onclick="closeModal('editUserModal')">ยกเลิก</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════════
         TOKEN MANAGEMENT
    ════════════════════════════════════════════════════════════════════ -->
    <?php elseif ($page === 'tokens'):
        $glbLim = (int)getSetting('default_token_limit','0');
        $glbRst = (int)getSetting('default_token_reset_hours','0');
        // สถิติ: user ที่ใช้ค่ากลาง vs ค่าส่วนตัว
        try {
            $tStats = db()->query("
                SELECT
                    SUM(token_limit IS NULL)           AS lim_global,
                    SUM(token_limit IS NOT NULL)       AS lim_custom,
                    SUM(token_reset_hours IS NULL)     AS rst_global,
                    SUM(token_reset_hours IS NOT NULL) AS rst_custom,
                    SUM(tokens_used)                   AS total_used,
                    COUNT(*)                            AS total_users
                FROM users WHERE is_active=1
            ")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $tStats = ['lim_global'=>0,'lim_custom'=>0,'rst_global'=>0,'rst_custom'=>0,'total_used'=>0,'total_users'=>0];
        }
        // top 5 users by tokens_used
        try {
            $topUsers = db()->query("
                SELECT username, display_name,
                       tokens_used, tokens_total,
                       COALESCE(token_limit, " . (int)getSetting('default_token_limit','0') . ") AS eff_limit
                FROM users WHERE is_active=1
                ORDER BY tokens_used DESC LIMIT 5
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $topUsers = [];
        }
    ?>
    <h2 class="page-title">🪙 ควบคุม Token</h2>
    <?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success">💾 บันทึกการตั้งค่า Token เรียบร้อยแล้ว</div>
    <?php endif; ?>

    <!-- ── สถิติรวม ── -->
    <div class="stat-grid" style="margin-bottom:20px">
        <div class="stat-card">
            <div class="lbl">👥 ผู้ใช้งาน</div>
            <div class="val"><?= number_format((int)$tStats['total_users']) ?></div>
            <div class="sub">บัญชีที่ใช้งานได้</div>
        </div>
        <div class="stat-card">
            <div class="lbl">🌐 ใช้ค่ากลาง</div>
            <div class="val"><?= number_format((int)$tStats['lim_global']) ?></div>
            <div class="sub">คน — Limit จาก Global</div>
        </div>
        <div class="stat-card">
            <div class="lbl">👤 ค่าส่วนตัว</div>
            <div class="val"><?= number_format((int)$tStats['lim_custom']) ?></div>
            <div class="sub">คน — Limit กำหนดเอง</div>
        </div>
        <div class="stat-card">
            <div class="lbl">📊 Token รอบนี้</div>
            <div class="val"><?= fmtTokens((int)$tStats['total_used']) ?></div>
            <div class="sub">รวมทุกผู้ใช้</div>
        </div>
    </div>

    <!-- ── ค่ากลาง ── -->
    <form method="POST">
    <div class="panel">
        <div class="panel-head">⚙️ ค่ากลาง (Global Default) — ใช้เมื่อ user ไม่ได้ตั้งค่าส่วนตัว</div>
        <div class="panel-body">
            <div class="form-grid" style="gap:16px">
                <div class="fg">
                    <label>Token Limit ค่ากลาง</label>
                    <input type="number" name="default_token_limit" min="0" step="1000"
                           value="<?= $glbLim ?>" placeholder="0 = ไม่จำกัด">
                    <small>
                        0 = ไม่จำกัด · 1K token ≈ 750 คำ ·
                        มีผู้ใช้ <strong><?= (int)$tStats['lim_global'] ?></strong> คนใช้ค่านี้อยู่
                    </small>
                </div>
                <div class="fg">
                    <label>รีเซ็ตทุก (ชั่วโมง) ค่ากลาง</label>
                    <input type="number" name="default_token_reset_hours" min="0" step="1"
                           value="<?= $glbRst ?>" placeholder="0 = ไม่รีเซ็ต">
                    <small>
                        0 = ไม่รีเซ็ต · 24 = รายวัน · 168 = รายสัปดาห์ · 720 = รายเดือน ·
                        มีผู้ใช้ <strong><?= (int)$tStats['rst_global'] ?></strong> คนใช้ค่านี้อยู่
                    </small>
                </div>
            </div>
            <div style="background:rgba(99,102,241,.1);border:1px solid rgba(99,102,241,.25);border-radius:9px;padding:12px 16px;font-size:12px;color:var(--muted);margin-top:14px;line-height:1.7">
                <strong style="color:#818cf8">📌 วิธีทำงาน</strong><br>
                🌐 <strong>ค่ากลาง</strong> — ใช้กับผู้ใช้ที่ยังไม่ได้ตั้งค่าส่วนตัว (แสดง badge "ค่ากลาง" ในตารางผู้ใช้)<br>
                👤 <strong>ค่าส่วนตัว</strong> — ตั้งรายบุคคลผ่าน <a href="?page=users" style="color:#818cf8">จัดการผู้ใช้</a> → แก้ไข → ใส่ตัวเลขในช่อง Token Limit / รีเซ็ตทุก<br>
                🔄 เว้นว่างในช่อง User = ลบค่าส่วนตัวออก ให้กลับมาใช้ค่ากลางนี้
            </div>
        </div>
    </div>
    <button type="submit" name="save_token_settings" value="1" class="btn btn-primary">💾 บันทึกค่ากลาง</button>
    </form>

    <!-- ── Top Users ── -->
    <?php if (!empty($topUsers)): ?>
    <div class="panel" style="margin-top:20px">
        <div class="panel-head">📈 ผู้ใช้ที่ใช้ Token สูงสุด (รอบนี้)</div>
        <div class="panel-body" style="padding:0">
        <table class="data-table">
            <thead><tr>
                <th>ผู้ใช้</th>
                <th>ใช้ไป (รอบนี้)</th>
                <th>Limit ที่ใช้</th>
                <th>%</th>
                <th>สถิติรวม</th>
            </tr></thead>
            <tbody>
            <?php foreach ($topUsers as $tu):
                $pct = ($tu['eff_limit'] > 0) ? min(100, round($tu['tokens_used'] / $tu['eff_limit'] * 100)) : 0;
                $barColor = $pct >= 90 ? '#f87171' : ($pct >= 70 ? '#f97316' : '#10a37f');
            ?>
            <tr>
                <td>
                    <div style="font-weight:600"><?= e($tu['display_name'] ?: $tu['username']) ?></div>
                    <div style="font-size:11px;color:var(--muted)">@<?= e($tu['username']) ?></div>
                </td>
                <td style="font-weight:700;color:<?= $barColor ?>"><?= fmtTokens((int)$tu['tokens_used']) ?></td>
                <td style="color:var(--muted)"><?= $tu['eff_limit'] > 0 ? fmtTokens((int)$tu['eff_limit']) : 'ไม่จำกัด' ?></td>
                <td>
                    <?php if ($tu['eff_limit'] > 0): ?>
                    <div style="display:flex;align-items:center;gap:6px">
                        <div style="flex:1;height:5px;background:var(--bg3);border-radius:4px;min-width:60px">
                            <div style="width:<?= $pct ?>%;height:100%;background:<?= $barColor ?>;border-radius:4px"></div>
                        </div>
                        <span style="font-size:11px;color:<?= $barColor ?>;font-weight:600;min-width:32px"><?= $pct ?>%</span>
                    </div>
                    <?php else: ?>
                    <span style="color:var(--muted);font-size:11px">—</span>
                    <?php endif; ?>
                </td>
                <td style="color:var(--muted);font-size:12px"><?= fmtTokens((int)$tu['tokens_total']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ════════════════════════════════════════════════════════════════════
         CONVERSATIONS
    ════════════════════════════════════════════════════════════════════ -->
    <?php elseif ($page === 'conversations'):
        $viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;

        if ($viewId > 0) {
            $conv = db()->prepare('SELECT c.*,u.username FROM conversations c JOIN users u ON u.id=c.user_id WHERE c.id=?');
            $conv->execute([$viewId]);
            $conv = $conv->fetch();
            $msgs = [];
            if ($conv) {
                $ms = db()->prepare('SELECT * FROM messages WHERE conversation_id=? ORDER BY created_at ASC');
                $ms->execute([$viewId]);
                $msgs = $ms->fetchAll();
            }
        } else {
            $pg     = max(1,(int)($_GET['p']??1));
            $pp     = 20;
            $offset = ($pg-1)*$pp;
            $search = trim($_GET['q']??'');

            if ($search) {
                $like = "%{$search}%";
                $stmt = db()->prepare('SELECT c.id,c.title,c.model,c.updated_at,u.username,(SELECT COUNT(*) FROM messages WHERE conversation_id=c.id) cnt FROM conversations c JOIN users u ON u.id=c.user_id WHERE c.title LIKE ? OR u.username LIKE ? ORDER BY c.updated_at DESC LIMIT ? OFFSET ?');
                $stmt->execute([$like,$like,$pp,$offset]);
                $total = db()->prepare('SELECT COUNT(*) FROM conversations c JOIN users u ON u.id=c.user_id WHERE c.title LIKE ? OR u.username LIKE ?');
                $total->execute([$like,$like]);
            } else {
                $stmt = db()->prepare('SELECT c.id,c.title,c.model,c.updated_at,u.username,(SELECT COUNT(*) FROM messages WHERE conversation_id=c.id) cnt FROM conversations c JOIN users u ON u.id=c.user_id ORDER BY c.updated_at DESC LIMIT ? OFFSET ?');
                $stmt->execute([$pp,$offset]);
                $total = db()->query('SELECT COUNT(*) FROM conversations');
            }
            $convList  = $stmt->fetchAll();
            $totalRows = (int)$total->fetchColumn();
            $totalPgs  = (int)ceil($totalRows / $pp);
        }
    ?>

    <?php if ($viewId > 0 && $conv): ?>
    <!-- Single conversation view -->
    <div style="margin-bottom:14px">
        <a href="?page=conversations" class="btn btn-ghost btn-sm">← กลับ</a>
    </div>
    <div class="panel">
        <div class="panel-head">
            <?= e(mb_substr($conv['title'] ?? 'New Chat', 0, 80)) ?>
            <div style="display:flex;gap:8px">
                <form method="POST" onsubmit="return confirm('ลบการสนทนานี้?')">
                    <input type="hidden" name="delete_conv" value="<?= $conv['id'] ?>">
                    <button class="btn btn-danger btn-sm">🗑 ลบ</button>
                </form>
            </div>
        </div>
        <div class="panel-body" style="padding-bottom:0">
            <div style="display:flex;gap:16px;flex-wrap:wrap;color:#64748b;font-size:12px;margin-bottom:14px">
                <span>👤 <?= e($conv['username']) ?></span>
                <span>🤖 <?= e($conv['model']) ?></span>
                <span>📅 <?= date('d/m/Y H:i', strtotime($conv['created_at'])) ?></span>
                <span>💬 <?= count($msgs) ?> ข้อความ</span>
            </div>
        </div>
        <?php if (empty($msgs)): ?>
        <div class="empty"><div class="empty-ico">💬</div>ไม่มีข้อความ</div>
        <?php else: ?>
        <?php foreach ($msgs as $m): ?>
        <div class="msg-item">
            <div class="role role-<?= $m['role'] ?>"><?= strtoupper($m['role']) ?> — <?= date('H:i:s', strtotime($m['created_at'])) ?></div>
            <div class="msg-body"><?= e($m['content']) ?></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <!-- Conversation list -->
    <form class="search-row" method="GET">
        <input type="hidden" name="page" value="conversations">
        <input type="text" name="q" value="<?= e($search ?? '') ?>" placeholder="🔍 ค้นหาหัวข้อหรือ username...">
        <button class="btn btn-ghost">ค้นหา</button>
        <?php if (!empty($search)): ?><a href="?page=conversations" class="btn btn-ghost">✕ ล้าง</a><?php endif; ?>
    </form>

    <div class="panel">
        <div class="panel-head">💬 การสนทนาทั้งหมด <span class="badge b-user"><?= number_format($totalRows) ?> รายการ</span></div>
        <?php if (empty($convList)): ?>
        <div class="empty"><div class="empty-ico">💬</div>ไม่พบการสนทนา</div>
        <?php else: ?>
        <table>
            <thead><tr><th>หัวข้อ</th><th>ผู้ใช้</th><th>Model</th><th>ข้อความ</th><th>เวลา</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($convList as $c): ?>
            <tr>
                <td><?= e(mb_substr($c['title'] ?? 'New Chat', 0, 55)) ?></td>
                <td><?= e($c['username']) ?></td>
                <td><code style="font-size:11px;color:#818cf8"><?= e($c['model']) ?></code></td>
                <td style="color:var(--muted)"><?= $c['cnt'] ?></td>
                <td style="color:var(--muted);font-size:12px"><?= date('d/m/y H:i', strtotime($c['updated_at'])) ?></td>
                <td style="display:flex;gap:6px">
                    <a href="?page=conversations&view=<?= $c['id'] ?>" class="btn btn-ghost btn-xs">ดู</a>
                    <form method="POST" onsubmit="return confirm('ลบ?')" style="display:inline">
                        <input type="hidden" name="delete_conv" value="<?= $c['id'] ?>">
                        <button class="btn btn-danger btn-xs">ลบ</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($totalPgs > 1): ?>
        <div style="padding:14px 16px;display:flex;gap:8px;align-items:center;border-top:1px solid rgba(255,255,255,.06)">
            <?php if ($pg > 1): ?><a href="?page=conversations&p=<?= $pg-1 ?><?= !empty($search)?'&q='.urlencode($search):'' ?>" class="btn btn-ghost btn-sm">← ก่อนหน้า</a><?php endif; ?>
            <span style="color:var(--muted);font-size:13px">หน้า <?= $pg ?> / <?= $totalPgs ?></span>
            <?php if ($pg < $totalPgs): ?><a href="?page=conversations&p=<?= $pg+1 ?><?= !empty($search)?'&q='.urlencode($search):'' ?>" class="btn btn-ghost btn-sm">ถัดไป →</a><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; // end page switch ?>

    </div><!-- /content -->
</div><!-- /main -->

<script>
// ── Sidebar toggle (mobile) ──────────────────────────────────────────────────
function toggleSidebar() {
    const s = document.getElementById('sidebar');
    const o = document.getElementById('overlay');
    s.classList.toggle('open');
    o.classList.toggle('show');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('overlay').classList.remove('show');
}

// ── Modal helpers ─────────────────────────────────────────────────────────────
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-bg').forEach(m => {
    // editUserModal ปิดได้เฉพาะปุ่มยกเลิก/บันทึก เท่านั้น
    if (m.id === 'editUserModal') return;
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

// ── Edit User Modal ───────────────────────────────────────────────────────────
// tokenLimit / resetHours: null = ยังไม่ตั้ง (ใช้ค่ากลาง), number = ค่าส่วนตัว
// glbLimit / glbReset: ค่ากลางปัจจุบัน (เพื่อแสดง placeholder)
function openEditUser(uid, username, display, role, tokenLimit, resetHours, glbLimit, glbReset) {
    document.getElementById('editUid').value              = uid;
    document.getElementById('editUsernameShow').value     = username;
    document.getElementById('editUserNameLabel').textContent = username;
    document.getElementById('editDisplay').value          = display;
    document.getElementById('editRole').value             = role;

    const limEl  = document.getElementById('editTokenLimit');
    const rstEl  = document.getElementById('editTokenResetHours');
    const limHint = document.getElementById('editTokenLimitHint');
    const rstHint = document.getElementById('editResetHint');

    if (tokenLimit === null || tokenLimit === undefined) {
        limEl.value       = '';
        limEl.placeholder = glbLimit > 0 ? `ค่ากลาง: ${glbLimit.toLocaleString()} token` : 'ค่ากลาง: ไม่จำกัด';
        limHint.innerHTML = `<span style="color:#818cf8">🌐 ปัจจุบันใช้ค่ากลาง</span> · เพิ่มตัวเลขเพื่อกำหนดค่าเฉพาะ user นี้`;
    } else {
        limEl.value       = tokenLimit;
        limEl.placeholder = '0 = ไม่จำกัด';
        limHint.innerHTML = `<span style="color:#34d399">👤 ค่าส่วนตัว</span> · ลบตัวเลขออกเพื่อกลับไปใช้ค่ากลาง (${glbLimit > 0 ? glbLimit.toLocaleString() : 'ไม่จำกัด'})`;
    }

    if (resetHours === null || resetHours === undefined) {
        rstEl.value       = '';
        rstEl.placeholder = glbReset > 0 ? `ค่ากลาง: ทุก ${glbReset} ชม.` : 'ค่ากลาง: ไม่รีเซ็ต';
        rstHint.innerHTML = `<span style="color:#818cf8">🌐 ปัจจุบันใช้ค่ากลาง</span> · เพิ่มตัวเลขเพื่อกำหนดค่าเฉพาะ user นี้`;
    } else {
        rstEl.value       = resetHours;
        rstEl.placeholder = '0 = ไม่รีเซ็ต';
        rstHint.innerHTML = `<span style="color:#34d399">👤 ค่าส่วนตัว</span> · ลบตัวเลขออกเพื่อกลับไปใช้ค่ากลาง (${glbReset > 0 ? glbReset + ' ชม.' : 'ไม่รีเซ็ต'})`;
    }

    document.querySelector('#editUserModal input[name="edit_password"]').value = '';
    document.getElementById('editUserModal').classList.add('open');
}

// ── Edit Model Modal ──────────────────────────────────────────────────────────
function openEditModel(id, name, label, isActive, serverId) {
    document.getElementById('editModelId').value    = id;
    document.getElementById('editModelName').value  = name;
    document.getElementById('editModelLabel').value = label;
    const chk = document.getElementById('editModelActive');
    const lbl = document.getElementById('editModelActiveLabel');
    function syncModelActiveLabel() {
        lbl.textContent = chk.checked ? '● เปิดใช้งาน' : '○ ปิดการใช้งาน';
        lbl.style.color = chk.checked ? '#10a37f' : 'var(--muted)';
    }
    chk.checked  = !!isActive;
    chk.onchange = syncModelActiveLabel;
    syncModelActiveLabel();
    const sel = document.getElementById('editModelServerId');
    if (sel) sel.value = serverId || '';
    document.getElementById('editModelModal').classList.add('open');
}

// ── Test API Connection ───────────────────────────────────────────────────────
async function testApiConnection() {
    const btn    = document.getElementById('testConnBtn');
    const result = document.getElementById('testConnResult');
    const apiKey = document.getElementById('settingApiKey')?.value ?? '';
    const baseUrl= document.getElementById('settingBaseUrl')?.value ?? '';

    btn.disabled    = true;
    btn.textContent = '⏳ กำลังทดสอบ…';
    result.style.display = 'none';

    try {
        const fd = new FormData();
        fd.append('api_key',  apiKey);
        fd.append('base_url', baseUrl);
        const res  = await fetch('?api=test_connection', { method: 'POST', body: fd });
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); }
        catch(_) { data = { ok: false, msg: `Server ตอบกลับข้อมูลที่ไม่ใช่ JSON (HTTP ${res.status}) — ตรวจสอบ Base URL` }; }

        result.style.display    = 'block';
        result.style.background = data.ok ? 'rgba(16,163,127,.15)' : 'rgba(239,68,68,.12)';
        result.style.border     = '1px solid ' + (data.ok ? 'rgba(16,163,127,.4)' : 'rgba(239,68,68,.3)');
        result.style.color      = data.ok ? '#34d399' : '#f87171';
        result.textContent      = data.msg;
    } catch(e) {
        result.style.display    = 'block';
        result.style.background = 'rgba(239,68,68,.12)';
        result.style.border     = '1px solid rgba(239,68,68,.3)';
        result.style.color      = '#f87171';
        result.textContent      = 'ไม่สามารถส่งคำขอได้: ' + e.message;
    } finally {
        btn.disabled    = false;
        btn.textContent = '🔌 ทดสอบการเชื่อมต่อ';
    }
}

// ── Drag-to-reorder models ────────────────────────────────────────────────────
(function() {
    const tbody = document.getElementById('modelTableBody');
    if (!tbody) return;
    let dragging = null;

    tbody.querySelectorAll('.drag-handle').forEach(handle => {
        handle.addEventListener('mousedown', () => {
            const row = handle.closest('tr');
            row.setAttribute('draggable', 'true');
            row.addEventListener('dragend', () => row.removeAttribute('draggable'), { once: true });
        });
    });

    tbody.addEventListener('dragstart', e => {
        dragging = e.target.closest('tr');
        e.dataTransfer.effectAllowed = 'move';
    });
    tbody.addEventListener('dragover', e => {
        e.preventDefault();
        const target = e.target.closest('tr');
        if (target && target !== dragging) {
            tbody.querySelectorAll('tr').forEach(r => r.classList.remove('drag-over'));
            target.classList.add('drag-over');
            const rows  = [...tbody.querySelectorAll('tr')];
            const dragI = rows.indexOf(dragging);
            const tgtI  = rows.indexOf(target);
            if (dragI < tgtI) target.after(dragging);
            else target.before(dragging);
        }
    });
    tbody.addEventListener('dragend', () => {
        tbody.querySelectorAll('tr').forEach(r => r.classList.remove('drag-over'));
        // Save order via POST
        const ids = [...tbody.querySelectorAll('tr[data-id]')].map(r => r.dataset.id).join(',');
        fetch('admin.php?page=models', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'model_order=' + encodeURIComponent(ids)
        });
    });
})();
</script>
<?= themeScript() ?>
</body>
</html>
