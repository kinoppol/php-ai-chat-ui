<?php
/**
 * ============================================================================
 * DATABASE MIGRATION RUNNER
 * ============================================================================
 * เรียกใช้ผ่าน browser: http://localhost/php-ai-chat-ui/migrate.php
 * หรือ CLI: php migrate.php
 *
 * เพิ่ม migration ใหม่ใน $migrations array ด้านล่าง
 * แต่ละ migration จะถูกรันครั้งเดียวเท่านั้น (ติดตามด้วยตาราง schema_migrations)
 * ============================================================================
 */
declare(strict_types=1);

if (!file_exists(__DIR__ . '/config.php')) {
    die("ยังไม่ได้ติดตั้งระบบ — รัน install.php ก่อน\n");
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/theme.php';

$isCli = PHP_SAPI === 'cli';

// ─── PDO connection ───────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("ไม่สามารถเชื่อมต่อฐานข้อมูล: " . $e->getMessage() . "\n");
}

// ─── Ensure tracking table ────────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `id`         VARCHAR(100) NOT NULL,
        `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ─── Migration definitions ────────────────────────────────────────────────────
// Format: 'YYYYMMDD_NNN_description' => callable(PDO): void
$migrations = [

    '20250101_001_initial_schema' => function(PDO $pdo): void {
        // สร้างตารางหลักทั้งหมด (idempotent)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `settings` (
                `key`        VARCHAR(100)  NOT NULL,
                `value`      LONGTEXT,
                `label`      VARCHAR(255)  DEFAULT '',
                `group`      VARCHAR(50)   DEFAULT 'general',
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `users` (
                `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                `username`     VARCHAR(100)  NOT NULL,
                `password`     VARCHAR(255)  NOT NULL,
                `display_name` VARCHAR(150)  DEFAULT '',
                `role`         ENUM('admin','user') NOT NULL DEFAULT 'user',
                `is_active`    TINYINT(1)    NOT NULL DEFAULT 1,
                `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `last_login`   TIMESTAMP NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_username` (`username`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `conversations` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`    INT UNSIGNED NOT NULL,
                `title`      VARCHAR(500)  DEFAULT 'New Chat',
                `model`      VARCHAR(100)  DEFAULT '',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_user_id` (`user_id`),
                CONSTRAINT `fk_conv_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
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
                CONSTRAINT `fk_msg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `conversations`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    '20250102_001_add_models_table' => function(PDO $pdo): void {
        $pdo->exec("
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
        // Seed จาก settings.models_list ถ้ายังไม่มี
        $count = (int) $pdo->query('SELECT COUNT(*) FROM `models`')->fetchColumn();
        if ($count === 0) {
            $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ?');
            $stmt->execute(['models_list']);
            $row  = $stmt->fetch();
            $list = $row ? $row['value'] : 'llama3:8b';
            $ins  = $pdo->prepare('INSERT IGNORE INTO `models` (`name`,`label`,`sort_order`) VALUES (?,?,?)');
            foreach (array_filter(array_map('trim', explode(',', $list))) as $i => $m) {
                $ins->execute([$m, $m, $i]);
            }
        }
    },

    '20250103_001_add_api_key_settings' => function(PDO $pdo): void {
        // เพิ่ม default settings สำหรับ API key และ server config
        $defaults = [
            ['api_key',       'ollama',                                          'API Key',              'api'],
            ['base_url',      'http://localhost:11434/v1',                        'Base URL',             'api'],
            ['model',         'llama3:8b',                                       'Default Model',        'api'],
            ['system_prompt', 'You are a helpful AI assistant. Be concise and helpful.', 'System Prompt','api'],
            ['max_tokens',    '4096',                                            'Max Tokens',           'api'],
            ['site_name',     'AI Chat',                                         'Site Name',            'general'],
            ['models_list',   'llama3:8b',                                       'Models List (legacy)', 'api'],
        ];
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO `settings` (`key`,`value`,`label`,`group`) VALUES (?,?,?,?)'
        );
        foreach ($defaults as $row) {
            $stmt->execute($row);
        }
    },

    '20250621_001_add_token_tracking' => function(PDO $pdo): void {
        // token_limit: 0 = ไม่จำกัด, >0 = จำนวน token สูงสุดที่ใช้ได้
        $pdo->exec("ALTER TABLE `users`
            ADD COLUMN IF NOT EXISTS `token_limit`    BIGINT UNSIGNED NOT NULL DEFAULT 0     COMMENT '0=unlimited' AFTER `last_login`,
            ADD COLUMN IF NOT EXISTS `tokens_used`    BIGINT UNSIGNED NOT NULL DEFAULT 0     AFTER `token_limit`,
            ADD COLUMN IF NOT EXISTS `tokens_reset_at` TIMESTAMP NULL DEFAULT NULL           AFTER `tokens_used`
        ");
    },

    '20250621_002_add_registration_settings' => function(PDO $pdo): void {
        $stmt = $pdo->prepare("INSERT IGNORE INTO `settings` (`key`,`value`,`label`,`group`) VALUES (?,?,?,?)");
        $stmt->execute(['allow_registration', '1',  'เปิดให้ลงทะเบียนใช้งาน (1=เปิด, 0=ปิด)', 'general']);
        $stmt->execute(['registration_note',  'กรุณารอการอนุมัติจาก Admin ก่อนเข้าใช้งาน', 'ข้อความสำหรับผู้ลงทะเบียน', 'general']);
    },

    '20250621_003_add_token_stats_and_reset' => function(PDO $pdo): void {
        // tokens_total: สะสมตลอด (สถิติ), token_reset_hours: ช่วงรีเซ็ต (0=ไม่รีเซ็ต)
        $pdo->exec("ALTER TABLE `users`
            ADD COLUMN IF NOT EXISTS `tokens_total`      BIGINT UNSIGNED NOT NULL DEFAULT 0  COMMENT 'cumulative all-time usage' AFTER `tokens_used`,
            ADD COLUMN IF NOT EXISTS `token_reset_hours` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=no auto reset' AFTER `tokens_reset_at`
        ");
        // Settings: ค่าเริ่มต้นสำหรับผู้ใช้ใหม่
        $stmt = $pdo->prepare("INSERT IGNORE INTO `settings` (`key`,`value`,`label`,`group`) VALUES (?,?,?,?)");
        $stmt->execute(['default_token_limit',       '0', 'Token Limit เริ่มต้นสำหรับผู้ใช้ใหม่ (0=ไม่จำกัด)',           'token']);
        $stmt->execute(['default_token_reset_hours', '0', 'รีเซ็ต Token ทุก N ชั่วโมง (0=ไม่รีเซ็ตอัตโนมัติ)',          'token']);
    },

    '20250621_004_token_nullable_global_inherit' => function(PDO $pdo): void {
        // NULL = ใช้ค่ากลาง (global setting)
        // 0    = ตั้งไว้ชัดเจนว่าไม่จำกัด / ไม่รีเซ็ต
        // >0   = ค่าที่ตั้งเองสำหรับ user นี้โดยเฉพาะ
        $pdo->exec("ALTER TABLE `users`
            MODIFY COLUMN `token_limit`      BIGINT UNSIGNED  NULL DEFAULT NULL COMMENT 'NULL=use global default',
            MODIFY COLUMN `token_reset_hours` SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL=use global default'
        ");
    },

    '20250625_001_add_api_servers_table' => function(PDO $pdo): void {
        $pdo->exec("
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
        // Seed จาก global settings เพื่อให้ระบบเดิมยังทำงานได้
        $count = (int)$pdo->query('SELECT COUNT(*) FROM `api_servers`')->fetchColumn();
        if ($count === 0) {
            $r = $pdo->query("SELECT `key`,`value` FROM settings WHERE `key` IN ('base_url','api_key')")->fetchAll(PDO::FETCH_KEY_PAIR);
            $url = $r['base_url'] ?? 'http://localhost:11434/v1';
            $key = $r['api_key']  ?? 'ollama';
            $pdo->prepare("INSERT INTO `api_servers` (name, base_url, api_key, sort_order) VALUES (?,?,?,0)")
                ->execute(['Default Server', $url, $key]);
        }
    },

    '20250625_002_add_server_id_to_models' => function(PDO $pdo): void {
        $pdo->exec("ALTER TABLE `models`
            ADD COLUMN IF NOT EXISTS `server_id` INT UNSIGNED NULL DEFAULT NULL
                COMMENT 'NULL=use global settings' AFTER `sort_order`
        ");
        // กำหนด Default Server ให้ทุก model ที่ยังไม่มี server_id
        $defId = $pdo->query('SELECT id FROM api_servers ORDER BY sort_order ASC, id ASC LIMIT 1')->fetchColumn();
        if ($defId) {
            $pdo->prepare('UPDATE `models` SET `server_id` = ? WHERE `server_id` IS NULL')->execute([$defId]);
        }
    },

];

// ─── Run pending migrations ───────────────────────────────────────────────────
$applied = $pdo->query('SELECT id FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

$results = [];
foreach ($migrations as $id => $fn) {
    if (isset($applied[$id])) {
        $results[] = ['id' => $id, 'status' => 'skip', 'msg' => 'Already applied'];
        continue;
    }
    try {
        $pdo->beginTransaction();
        $fn($pdo);
        // DDL (ALTER TABLE) ทำให้ MySQL/MariaDB auto-commit transaction
        // ตรวจว่า transaction ยังเปิดอยู่ก่อน commit
        if ($pdo->inTransaction()) {
            $pdo->prepare('INSERT INTO schema_migrations (id) VALUES (?)')->execute([$id]);
            $pdo->commit();
        } else {
            // DDL ทำ auto-commit แล้ว — บันทึก migration ID แยกต่างหาก
            $pdo->prepare('INSERT INTO schema_migrations (id) VALUES (?)')->execute([$id]);
        }
        $results[] = ['id' => $id, 'status' => 'ok', 'msg' => 'Applied successfully'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $results[] = ['id' => $id, 'status' => 'error', 'msg' => $e->getMessage()];
    }
}

// ─── Output ───────────────────────────────────────────────────────────────────
if ($isCli) {
    foreach ($results as $r) {
        $icon = match($r['status']) { 'ok' => '✅', 'skip' => '⏭️ ', 'error' => '❌' };
        echo "{$icon}  [{$r['status']}] {$r['id']}: {$r['msg']}\n";
    }
    echo "\nDone.\n";
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Migration Runner — AI Chat</title>
<?= themeAntiFlash() ?>
<?= themeFavicon() ?>
<style>
<?= themeVars() ?>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:system-ui,sans-serif;background:var(--bg);color:var(--text);padding:32px;min-height:100vh}
.card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:28px;max-width:700px;margin:0 auto}
h1{font-size:22px;font-weight:700;margin-bottom:6px}
.sub{color:var(--muted);font-size:13px;margin-bottom:24px}
table{width:100%;border-collapse:collapse}
th{text-align:left;padding:9px 12px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);border-bottom:1px solid var(--border)}
td{padding:11px 12px;font-size:13px;border-bottom:1px solid var(--border);vertical-align:top}
tr:last-child td{border-bottom:none}
.ok   {color:#34d399}.skip{color:var(--muted)}.error{color:#f87171}
.badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700}
.b-ok   {background:rgba(52,211,153,.12);color:#34d399}
.b-skip {background:rgba(100,116,139,.12);color:var(--muted)}
.b-error{background:rgba(248,113,113,.12);color:#f87171}
.actions{margin-top:20px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
a.btn{padding:9px 16px;border-radius:9px;text-decoration:none;font-size:13px;font-weight:600;display:inline-block}
.btn-primary{background:#5436da;color:#fff}
.btn-ghost  {background:var(--hover-bg);color:var(--text2);border:1px solid var(--border2)}
code{font-family:monospace;font-size:12px;color:#818cf8}
</style>
</head>
<body>
<div class="card">
    <h1>🗄️ Migration Runner</h1>
    <p class="sub">AI Chat Database Migrations — รัน <?= date('d/m/Y H:i:s') ?></p>
    <table>
        <thead><tr><th>Migration ID</th><th>สถานะ</th><th>ข้อความ</th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
        <tr>
            <td><code><?= htmlspecialchars($r['id']) ?></code></td>
            <td><span class="badge b-<?= $r['status'] ?>"><?= strtoupper($r['status']) ?></span></td>
            <td class="<?= $r['status'] ?>"><?= htmlspecialchars($r['msg']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="actions">
        <a href="admin.php" class="btn btn-primary">⚙️ ไป Admin Panel</a>
        <a href="chat.php"  class="btn btn-ghost">🚀 ไป Chat</a>
        <?= themeToggleBtn() ?>
    </div>
</div>
<?= themeScript() ?>
</body>
</html>
