<?php
/**
 * ============================================================================
 * INSTALL WIZARD
 * ============================================================================
 * Run this once to set up the database and create an admin account.
 * DELETE or RENAME this file after installation is complete.
 * ============================================================================
 */

$step    = (int)($_GET['step'] ?? 1);
$errors  = [];
$success = false;

// ─── Step 2: Process form submission ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $dbHost    = trim($_POST['db_host']    ?? 'localhost');
    $dbPort    = trim($_POST['db_port']    ?? '3306');
    $dbName    = trim($_POST['db_name']    ?? 'ai_chat_db');
    $dbUser    = trim($_POST['db_user']    ?? 'root');
    $dbPass    = $_POST['db_pass']          ?? '';
    $adminUser = trim($_POST['admin_user'] ?? '');
    $adminPass = $_POST['admin_pass']      ?? '';
    $adminPass2 = $_POST['admin_pass2']    ?? '';

    // Validate
    if (empty($adminUser))              $errors[] = 'กรุณากรอก Admin Username';
    if (strlen($adminPass) < 8)         $errors[] = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
    if ($adminPass !== $adminPass2)     $errors[] = 'รหัสผ่านไม่ตรงกัน';

    if (empty($errors)) {
        try {
            // Connect to MariaDB
            $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            // Create database if not exists
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");

            // ── Create tables ──────────────────────────────────────────────
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `settings` (
                    `key`       VARCHAR(100)  NOT NULL,
                    `value`     LONGTEXT,
                    `label`     VARCHAR(255)  DEFAULT '',
                    `group`     VARCHAR(50)   DEFAULT 'general',
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `users` (
                    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                    `username`   VARCHAR(100)  NOT NULL,
                    `password`   VARCHAR(255)  NOT NULL,
                    `display_name` VARCHAR(150) DEFAULT '',
                    `role`       ENUM('admin','user') NOT NULL DEFAULT 'user',
                    `is_active`  TINYINT(1)    NOT NULL DEFAULT 1,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `last_login` TIMESTAMP NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_username` (`username`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `conversations` (
                    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                    `user_id`    INT UNSIGNED  NOT NULL,
                    `title`      VARCHAR(500)  DEFAULT 'New Chat',
                    `model`      VARCHAR(100)  DEFAULT '',
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_user_id` (`user_id`),
                    CONSTRAINT `fk_conv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `messages` (
                    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `conversation_id` INT UNSIGNED    NOT NULL,
                    `role`            ENUM('user','assistant','system') NOT NULL,
                    `content`         LONGTEXT        NOT NULL,
                    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_conv_id` (`conversation_id`),
                    CONSTRAINT `fk_msg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // ── Insert default settings ────────────────────────────────────
            $defaults = [
                ['api_key',       'ollama',                        'API Key',              'api'],
                ['base_url',      'http://localhost:11434/v1',      'Base URL',             'api'],
                ['model',         'llama3:8b',                     'Default Model',        'api'],
                ['system_prompt', 'You are a helpful AI assistant. Be concise and helpful.', 'System Prompt', 'api'],
                ['max_tokens',    '4096',                          'Max Tokens',           'api'],
                ['site_name',     'AI Chat',                       'Site Name',            'general'],
                ['models_list',   'llama3:8b,llama3:70b,mistral,codellama', 'Available Models (comma-separated)', 'api'],
            ];

            $stmt = $pdo->prepare("INSERT IGNORE INTO `settings` (`key`, `value`, `label`, `group`) VALUES (?,?,?,?)");
            foreach ($defaults as $row) {
                $stmt->execute($row);
            }

            // ── Create admin user ──────────────────────────────────────────
            $hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $pdo->prepare("INSERT INTO `users` (`username`, `password`, `display_name`, `role`) VALUES (?, ?, ?, 'admin') ON DUPLICATE KEY UPDATE `password` = VALUES(`password`), `role` = 'admin'");
            $stmt->execute([$adminUser, $hash, $adminUser]);

            // ── Write config.php ───────────────────────────────────────────
            $configContent = <<<PHP
<?php
/**
 * Database Configuration
 * Auto-generated by install.php on {$_SERVER['REQUEST_TIME_FLOAT']}
 * Edit manually if needed.
 */

define('DB_HOST',    '{$dbHost}');
define('DB_PORT',    '{$dbPort}');
define('DB_NAME',    '{$dbName}');
define('DB_USER',    '{$dbUser}');
define('DB_PASS',    '{$dbPass}');
define('DB_CHARSET', 'utf8mb4');

define('APP_VERSION', '2.0.0');
PHP;
            file_put_contents(__DIR__ . '/config.php', $configContent);

            $success = true;
            $step = 3;

        } catch (PDOException $e) {
            $errors[] = 'ไม่สามารถเชื่อมต่อฐานข้อมูล: ' . htmlspecialchars($e->getMessage());
        } catch (Exception $e) {
            $errors[] = 'เกิดข้อผิดพลาด: ' . htmlspecialchars($e->getMessage());
        }
    }
}
require_once __DIR__ . '/theme.php';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AI Chat — ติดตั้งระบบ</title>
<?= themeAntiFlash() ?>
<style>
<?= themeVars() ?>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.card{background:var(--bg2);border:1px solid var(--border);border-radius:20px;padding:40px;max-width:520px;width:100%;box-shadow:0 20px 60px var(--shadow)}
h1{font-size:24px;font-weight:700;margin-bottom:6px;color:var(--text)}
.sub{color:var(--muted);font-size:14px;margin-bottom:32px}
.step-bar{display:flex;gap:8px;margin-bottom:32px}
.step{flex:1;height:4px;border-radius:2px;background:var(--border2)}
.step.done{background:#10a37f}
.step.active{background:#5436da}
.section-title{font-size:12px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:12px;margin-top:24px}
.section-title:first-of-type{margin-top:0}
label{display:block;font-size:13px;color:var(--text2);margin-bottom:6px;margin-top:16px}
label:first-child{margin-top:0}
input{width:100%;padding:11px 14px;background:var(--bg);border:1px solid var(--border2);border-radius:10px;color:var(--text);font-size:14px;outline:none;transition:.2s}
input:focus{border-color:#5436da;box-shadow:0 0 0 3px rgba(84,54,218,.15)}
.row-2{display:grid;grid-template-columns:1fr 100px;gap:12px}
.btn{display:block;width:100%;padding:13px;background:#10a37f;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;margin-top:28px;transition:.2s}
.btn:hover{background:#0d8f6d}
.errors{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);border-radius:10px;padding:14px 16px;margin-bottom:20px}
.errors p{color:#f87171;font-size:14px;margin:4px 0}
.success-icon{text-align:center;font-size:64px;margin-bottom:16px}
.success h2{font-size:22px;text-align:center;margin-bottom:8px}
.success p{color:var(--muted);text-align:center;font-size:14px;margin-bottom:6px}
.link-btn{display:inline-block;padding:12px 24px;background:#5436da;color:#fff;border-radius:10px;text-decoration:none;font-weight:600;margin:8px 4px;font-size:14px}
.link-btn.green{background:#10a37f}
.warning{background:rgba(234,179,8,.1);border:1px solid rgba(234,179,8,.3);border-radius:10px;padding:12px 16px;color:#eab308;font-size:13px;margin-top:16px}
.top-theme{position:absolute;top:20px;right:20px}
</style>
</head>
<body style="position:relative">
<div class="card">
    <h1>🚀 ติดตั้ง AI Chat</h1>
    <p class="sub">ตั้งค่าฐานข้อมูลและบัญชี Admin เพื่อเริ่มใช้งาน</p>

    <div class="step-bar">
        <div class="step <?= $step >= 1 ? ($step > 1 ? 'done' : 'active') : '' ?>"></div>
        <div class="step <?= $step >= 2 ? ($step > 2 ? 'done' : 'active') : '' ?>"></div>
        <div class="step <?= $step >= 3 ? 'done' : '' ?>"></div>
    </div>

    <?php if ($step === 3 && $success): ?>
    <div class="success">
        <div class="success-icon">✅</div>
        <h2>ติดตั้งสำเร็จ!</h2>
        <p>ฐานข้อมูลและบัญชี Admin ถูกสร้างเรียบร้อยแล้ว</p>
        <p style="text-align:center;margin-top:24px">
            <a href="admin.php" class="link-btn">เข้าสู่ Admin Panel</a>
            <a href="chat.php" class="link-btn green">เริ่มแชท</a>
        </p>
        <div class="warning">
            ⚠️ <strong>คำเตือน:</strong> กรุณาลบหรือเปลี่ยนชื่อไฟล์ <code>install.php</code> เพื่อความปลอดภัย
        </div>
    </div>

    <?php else: ?>

    <?php if (!empty($errors)): ?>
    <div class="errors">
        <?php foreach ($errors as $e): ?>
        <p>• <?= $e ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST">
        <div class="section-title">📡 การเชื่อมต่อฐานข้อมูล</div>
        <div class="row-2">
            <div>
                <label>Host</label>
                <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" placeholder="localhost">
            </div>
            <div>
                <label>Port</label>
                <input type="text" name="db_port" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>" placeholder="3306">
            </div>
        </div>
        <label>ชื่อฐานข้อมูล</label>
        <input type="text" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? 'ai_chat_db') ?>" placeholder="ai_chat_db">
        <label>Username</label>
        <input type="text" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? 'root') ?>" placeholder="root">
        <label>Password <span style="color:#64748b;font-size:11px">(เว้นว่างถ้าไม่มีรหัสผ่าน)</span></label>
        <input type="password" name="db_pass" placeholder="" autocomplete="new-password" id="dbPassInput">
        <div style="margin-top:6px;display:flex;align-items:center;gap:8px">
            <input type="checkbox" id="noDbPass" onchange="document.getElementById('dbPassInput').disabled=this.checked; if(this.checked) document.getElementById('dbPassInput').value='';" style="width:auto;accent-color:#10a37f">
            <label for="noDbPass" style="margin:0;color:#94a3b8;cursor:pointer">ไม่มีรหัสผ่าน (XAMPP default)</label>
        </div>

        <div class="section-title" style="margin-top:28px">👤 บัญชี Admin</div>
        <label>Username</label>
        <input type="text" name="admin_user" value="<?= htmlspecialchars($_POST['admin_user'] ?? '') ?>" placeholder="admin" required>
        <label>รหัสผ่าน (อย่างน้อย 8 ตัว)</label>
        <input type="password" name="admin_pass" placeholder="••••••••" required>
        <label>ยืนยันรหัสผ่าน</label>
        <input type="password" name="admin_pass2" placeholder="••••••••" required>

        <button type="submit" class="btn">ติดตั้งระบบ →</button>
    </form>
    <?php endif; ?>
</div>
<?= themeToggleBtn('theme-float') ?>
<?= themeScript() ?>
</body>
</html>
