<?php
declare(strict_types=1);

// ── Load site name from DB if available ──────────────────────────────────────
$siteName    = 'AI Chat';
$totalUsers  = null;
$totalConvs  = null;
$isInstalled = file_exists(__DIR__ . '/config.php');

if ($isInstalled) {
    require_once __DIR__ . '/config.php';
    try {
        $__pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $r = $__pdo->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $r->execute(['site_name']); $row = $r->fetch();
        if ($row) $siteName = $row['value'];

        $totalUsers = (int) $__pdo->query('SELECT COUNT(*) FROM users WHERE is_active=1')->fetchColumn();
        $totalConvs = (int) $__pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn();
    } catch (Throwable) { $isInstalled = false; }
}

function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
require_once __DIR__ . '/theme.php';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($siteName) ?> — AI Chat Platform</title>
<meta name="description" content="ระบบแชท AI ที่ใช้งานง่าย รองรับ Ollama, OpenAI และ OpenRouter">
<?= themeAntiFlash() ?>
<?= themeFavicon() ?>
<style>
/* ── Reset & Base ─────────────────────────────────────────────────────────── */
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
:root, [data-theme="dark"] {
    --bg:       #080810;
    --bg2:      #0e0e1a;
    --bg3:      #13131f;
    --border:   rgba(255,255,255,.07);
    --text:     #e2e8f0;
    --muted:    #64748b;
    --accent:   #6366f1;
    --accent2:  #10a37f;
    --purple:   #8b5cf6;
    --pink:     #ec4899;
    --glow:     rgba(99,102,241,.35);
    --card-bg:  rgba(255,255,255,.04);
    --nav-bg:   rgba(8,8,16,.85);
    --input-placeholder: #64748b;
}
[data-theme="light"] {
    --bg:       #f4f4fa;
    --bg2:      #ffffff;
    --bg3:      #eeeef8;
    --border:   rgba(0,0,0,.08);
    --text:     #1e1e2e;
    --muted:    #7878a0;
    --accent:   #5050d0;
    --accent2:  #0d8a6a;
    --purple:   #7c3aed;
    --pink:     #c2185b;
    --glow:     rgba(80,80,208,.2);
    --card-bg:  rgba(0,0,0,.03);
    --nav-bg:   rgba(244,244,250,.92);
    --input-placeholder: #9898b8;
}
/* ── Theme toggle vars (shared) ── */
<?= themeVars() ?>
html { scroll-behavior: smooth; }
body {
    font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
    background: var(--bg);
    color: var(--text);
    line-height: 1.6;
    overflow-x: hidden;
}

/* ── Scrollbar ────────────────────────────────────────────────────────────── */
::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(255,255,255,.15); border-radius: 3px; }

/* ── Background mesh ──────────────────────────────────────────────────────── */
.bg-mesh {
    position: fixed; inset: 0; z-index: 0; pointer-events: none;
    background:
        radial-gradient(ellipse 80% 50% at 20% -10%, rgba(99,102,241,.18) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 80% 110%, rgba(139,92,246,.14) 0%, transparent 55%),
        radial-gradient(ellipse 50% 35% at 50% 50%,  rgba(16,163,127,.06) 0%, transparent 70%);
}
.bg-grid {
    position: fixed; inset: 0; z-index: 0; pointer-events: none; opacity: .025;
    background-image: linear-gradient(var(--text) 1px, transparent 1px),
                      linear-gradient(90deg, var(--text) 1px, transparent 1px);
    background-size: 48px 48px;
}

/* ── Nav ──────────────────────────────────────────────────────────────────── */
nav {
    position: fixed; top: 0; left: 0; right: 0; z-index: 100;
    padding: 0 24px;
    height: 64px;
    display: flex; align-items: center; justify-content: space-between;
    background: var(--nav-bg, rgba(8,8,16,.85));
    backdrop-filter: blur(20px);
    border-bottom: 1px solid var(--border);
}
.nav-logo {
    display: flex; align-items: center; gap: 10px;
    font-size: 18px; font-weight: 800; color: var(--text);
    text-decoration: none; letter-spacing: -.01em;
}
.nav-logo .logo-icon {
    width: 34px; height: 34px;
    background: linear-gradient(135deg, var(--accent), var(--purple));
    border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    box-shadow: 0 0 16px var(--glow);
}
.nav-links { display: flex; align-items: center; gap: 8px; }
.nav-links a {
    padding: 8px 16px; border-radius: 9px; font-size: 13.5px; font-weight: 500;
    text-decoration: none; color: var(--muted);
    transition: .2s;
}
.nav-links a:hover { color: var(--text); background: rgba(255,255,255,.06); }
.nav-links .btn-nav {
    background: linear-gradient(135deg, var(--accent), var(--purple));
    color: #fff !important; font-weight: 600;
    box-shadow: 0 2px 12px var(--glow);
}
.nav-links .btn-nav:hover { opacity: .9; transform: translateY(-1px); }

/* ── Sections ─────────────────────────────────────────────────────────────── */
section { position: relative; z-index: 1; }

/* ── Hero ─────────────────────────────────────────────────────────────────── */
.hero {
    min-height: 100vh;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    text-align: center;
    padding: 100px 24px 60px;
}
.hero-badge {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 6px 16px; border-radius: 99px;
    background: rgba(99,102,241,.1);
    border: 1px solid rgba(99,102,241,.25);
    font-size: 12.5px; font-weight: 600; color: #a5b4fc;
    margin-bottom: 28px;
    animation: fadeUp .6s ease both;
}
.hero-badge .dot { width: 6px; height: 6px; border-radius: 50%; background: #a5b4fc; animation: pulse 2s infinite; }
@keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(.8)} }

.hero h1 {
    font-size: clamp(38px, 7vw, 80px);
    font-weight: 900;
    line-height: 1.07;
    letter-spacing: -.03em;
    margin-bottom: 24px;
    animation: fadeUp .6s .1s ease both;
}
.hero h1 .grad {
    background: linear-gradient(135deg, #a5b4fc 0%, #8b5cf6 40%, #ec4899 80%);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    background-clip: text;
}
.hero-sub {
    font-size: clamp(16px, 2.5vw, 20px);
    color: var(--muted); max-width: 560px;
    margin: 0 auto 40px;
    animation: fadeUp .6s .2s ease both;
}
.hero-actions {
    display: flex; align-items: center; gap: 14px; flex-wrap: wrap; justify-content: center;
    margin-bottom: 60px;
    animation: fadeUp .6s .3s ease both;
}
.btn-hero {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 14px 28px; border-radius: 12px;
    font-size: 15px; font-weight: 700;
    text-decoration: none; transition: .2s;
}
.btn-primary {
    background: linear-gradient(135deg, var(--accent), var(--purple));
    color: #fff;
    box-shadow: 0 4px 20px var(--glow);
}
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 28px var(--glow); }
.btn-outline {
    background: var(--card-bg, rgba(255,255,255,.05));
    border: 1px solid var(--border);
    color: var(--text);
}
.btn-outline:hover { background: var(--hover-bg, rgba(255,255,255,.09)); transform: translateY(-2px); }

/* ── Hero stats ───────────────────────────────────────────────────────────── */
.hero-stats {
    display: flex; gap: 32px; justify-content: center; flex-wrap: wrap;
    animation: fadeUp .6s .4s ease both;
}
.stat-pill {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 20px; border-radius: 12px;
    background: var(--card-bg, rgba(255,255,255,.04));
    border: 1px solid var(--border);
    font-size: 13.5px;
}
.stat-pill .val { font-weight: 800; font-size: 20px; color: var(--text); }
.stat-pill .lbl { color: var(--muted); font-size: 12px; }

/* ── Chat preview mockup ──────────────────────────────────────────────────── */
.mockup-wrap {
    position: relative; z-index: 1;
    padding: 0 24px 80px;
    display: flex; justify-content: center;
    animation: fadeUp .7s .5s ease both;
}
.mockup {
    width: 100%; max-width: 780px;
    background: var(--bg3);
    border: 1px solid var(--border);
    border-radius: 20px;
    overflow: hidden;
    box-shadow:
        0 0 0 1px rgba(255,255,255,.04),
        0 40px 80px rgba(0,0,0,.5),
        0 0 60px rgba(99,102,241,.08);
}
.mockup-bar {
    display: flex; align-items: center; gap: 8px;
    padding: 14px 18px;
    background: rgba(255,255,255,.03);
    border-bottom: 1px solid var(--border);
}
.mockup-bar .dot { width: 11px; height: 11px; border-radius: 50%; }
.dot-red { background: #ff5f57; } .dot-yellow { background: #febc2e; } .dot-green { background: #28c840; }
.mockup-bar .url {
    flex: 1; margin-left: 10px;
    padding: 5px 12px; border-radius: 7px;
    background: rgba(255,255,255,.04);
    font-size: 12px; color: var(--muted); font-family: monospace;
}
.mockup-body { display: flex; height: 360px; }
.mockup-sidebar {
    width: 200px; flex-shrink: 0;
    background: rgba(0,0,0,.2);
    border-right: 1px solid var(--border);
    padding: 12px 8px;
}
.sidebar-item {
    padding: 9px 12px; border-radius: 8px; font-size: 12px; color: var(--muted);
    margin-bottom: 3px; cursor: default;
}
.sidebar-item.active { background: rgba(99,102,241,.15); color: #a5b4fc; }
.sidebar-item.dim { opacity: .45; }
.mockup-chat { flex: 1; display: flex; flex-direction: column; padding: 20px; gap: 16px; overflow: hidden; }
.chat-msg { display: flex; gap: 10px; animation: fadeUp .4s ease both; }
.chat-msg.user { flex-direction: row-reverse; }
.msg-avatar {
    width: 28px; height: 28px; border-radius: 6px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700;
}
.avatar-ai { background: var(--accent2); }
.avatar-u  { background: linear-gradient(135deg,var(--accent),var(--purple)); }
.msg-bubble {
    max-width: 72%; padding: 10px 14px; border-radius: 12px; font-size: 13px; line-height: 1.5;
}
.bubble-ai { background: rgba(255,255,255,.05); color: var(--text); border-radius: 4px 12px 12px 12px; }
.bubble-u  { background: linear-gradient(135deg,var(--accent),var(--purple)); color: #fff; border-radius: 12px 4px 12px 12px; }
.typing-dots { display: inline-flex; gap: 4px; align-items: center; padding: 4px 0; }
.typing-dots span {
    width: 6px; height: 6px; border-radius: 50%; background: var(--muted);
    animation: bounce 1.4s infinite ease-in-out;
}
.typing-dots span:nth-child(2) { animation-delay: .2s; }
.typing-dots span:nth-child(3) { animation-delay: .4s; }
@keyframes bounce { 0%,60%,100%{transform:translateY(0);opacity:.4} 30%{transform:translateY(-6px);opacity:1} }
.mockup-input {
    margin-top: auto; padding: 10px 14px;
    background: rgba(255,255,255,.05); border: 1px solid var(--border); border-radius: 10px;
    font-size: 12.5px; color: var(--muted);
}

/* ── Features ─────────────────────────────────────────────────────────────── */
.features { padding: 80px 24px; }
.section-label {
    text-align: center; font-size: 12px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .1em; color: #a5b4fc;
    margin-bottom: 12px;
}
.section-title {
    text-align: center; font-size: clamp(26px, 4vw, 42px);
    font-weight: 800; letter-spacing: -.02em; margin-bottom: 14px;
}
.section-sub {
    text-align: center; color: var(--muted); font-size: 16px;
    max-width: 500px; margin: 0 auto 56px;
}
.features-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px; max-width: 1100px; margin: 0 auto;
}
.feature-card {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 16px; padding: 28px;
    transition: .25s; position: relative; overflow: hidden;
}
.feature-card::before {
    content: ''; position: absolute; inset: 0; border-radius: inherit; opacity: 0;
    background: linear-gradient(135deg, rgba(99,102,241,.06), rgba(139,92,246,.04));
    transition: .25s;
}
.feature-card:hover { border-color: rgba(99,102,241,.3); transform: translateY(-3px); }
.feature-card:hover::before { opacity: 1; }
.feature-icon {
    width: 44px; height: 44px; border-radius: 12px; margin-bottom: 18px;
    display: flex; align-items: center; justify-content: center; font-size: 22px;
}
.ic-purple { background: rgba(139,92,246,.15); }
.ic-green  { background: rgba(16,163,127,.15); }
.ic-blue   { background: rgba(99,102,241,.15); }
.ic-pink   { background: rgba(236,72,153,.15); }
.ic-orange { background: rgba(249,115,22,.15); }
.ic-cyan   { background: rgba(6,182,212,.15); }
.feature-card h3 { font-size: 16px; font-weight: 700; margin-bottom: 8px; }
.feature-card p  { font-size: 13.5px; color: var(--muted); line-height: 1.6; }

/* ── Providers ────────────────────────────────────────────────────────────── */
.providers { padding: 60px 24px 80px; }
.provider-grid {
    display: grid; grid-template-columns: repeat(auto-fill,minmax(220px,1fr));
    gap: 14px; max-width: 900px; margin: 0 auto;
}
.provider-card {
    background: var(--bg2); border: 1px solid var(--border); border-radius: 14px;
    padding: 22px 24px; display: flex; align-items: center; gap: 14px;
    transition: .2s;
}
.provider-card:hover { border-color: rgba(255,255,255,.14); transform: translateY(-2px); }
.provider-logo {
    width: 40px; height: 40px; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 22px;
    background: var(--card-bg, rgba(255,255,255,.06));
}
.provider-card h4 { font-size: 14px; font-weight: 700; }
.provider-card p  { font-size: 12px; color: var(--muted); margin-top: 2px; }
.provider-tag {
    margin-left: auto; padding: 2px 8px; border-radius: 6px;
    font-size: 10px; font-weight: 700; flex-shrink: 0;
}
.tag-local { background: rgba(16,163,127,.15); color: #34d399; }
.tag-cloud { background: rgba(99,102,241,.15); color: #a5b4fc; }

/* ── How it works ─────────────────────────────────────────────────────────── */
.howto { padding: 60px 24px 80px; }
.steps {
    display: grid; grid-template-columns: repeat(auto-fill,minmax(220px,1fr));
    gap: 20px; max-width: 1000px; margin: 0 auto;
    counter-reset: step;
}
.step-card {
    background: var(--bg2); border: 1px solid var(--border);
    border-radius: 16px; padding: 28px; position: relative;
    counter-increment: step;
}
.step-card::before {
    content: counter(step);
    position: absolute; top: -14px; left: 24px;
    width: 28px; height: 28px; border-radius: 8px;
    background: linear-gradient(135deg,var(--accent),var(--purple));
    font-size: 13px; font-weight: 800; color: #fff;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 12px var(--glow);
}
.step-card h4 { font-size: 15px; font-weight: 700; margin-bottom: 8px; }
.step-card p  { font-size: 13px; color: var(--muted); line-height: 1.6; }
.step-card code {
    display: inline-block; margin-top: 10px; padding: 6px 10px; border-radius: 7px;
    background: rgba(0,0,0,.3); font-family: monospace; font-size: 12px; color: #a5b4fc;
    border: 1px solid rgba(99,102,241,.2);
}

/* ── CTA ──────────────────────────────────────────────────────────────────── */
.cta {
    padding: 80px 24px 100px; text-align: center;
    background: radial-gradient(ellipse 70% 60% at 50% 0%, rgba(99,102,241,.1) 0%, transparent 70%);
}
.cta h2 { font-size: clamp(28px,5vw,52px); font-weight: 900; letter-spacing: -.02em; margin-bottom: 14px; }
.cta p  { font-size: 17px; color: var(--muted); margin-bottom: 36px; }
.cta-actions { display: flex; gap: 14px; justify-content: center; flex-wrap: wrap; }

/* ── Footer ───────────────────────────────────────────────────────────────── */
footer {
    border-top: 1px solid var(--border); padding: 24px;
    text-align: center; font-size: 13px; color: var(--muted);
    background: var(--bg); position: relative; z-index: 1;
}
footer a { color: var(--muted); text-decoration: none; }
footer a:hover { color: var(--text); }

/* ── Animations ───────────────────────────────────────────────────────────── */
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(24px); }
    to   { opacity: 1; transform: translateY(0); }
}
.reveal {
    opacity: 0; transform: translateY(28px);
    transition: opacity .6s ease, transform .6s ease;
}
.reveal.visible { opacity: 1; transform: none; }

/* ── Responsive ───────────────────────────────────────────────────────────── */
@media (max-width: 640px) {
    .nav-links a:not(.btn-nav) { display: none; }
    .mockup-sidebar { display: none; }
    .mockup-body { height: 280px; }
    .hero-stats { gap: 12px; }
    .stat-pill { padding: 8px 14px; }
}
</style>
</head>
<body>

<div class="bg-mesh"></div>
<div class="bg-grid"></div>

<!-- ════════ NAV ════════ -->
<nav>
    <a href="index.php" class="nav-logo">
        <div class="logo-icon">🤖</div>
        <?= e($siteName) ?>
    </a>
    <div class="nav-links">
        <a href="#features">ฟีเจอร์</a>
        <a href="#howto">วิธีใช้</a>
        <?= themeToggleBtn() ?>
        <?php if ($isInstalled): ?>
        <a href="admin.php">Admin</a>
        <a href="chat.php" class="btn-nav">🚀 เริ่มแชท</a>
        <?php else: ?>
        <a href="install.php" class="btn-nav">⚡ ติดตั้งเลย</a>
        <?php endif; ?>
    </div>
</nav>

<!-- ════════ HERO ════════ -->
<section class="hero">
    <div class="hero-badge">
        <span class="dot"></span>
        PHP 8 + MariaDB 10 &nbsp;·&nbsp; Open Source &nbsp;·&nbsp; v<?= defined('APP_VERSION') ? e(APP_VERSION) : '2.0.0' ?>
    </div>

    <h1>
        แชทกับ AI<br>
        <span class="grad">ที่คุณควบคุมได้</span>
    </h1>
    <p class="hero-sub">
        ระบบแชท AI ครบวงจร รองรับ Ollama, OpenAI และ OpenRouter
        พร้อมระบบจัดการ Admin ตั้งค่าผ่านหน้าเว็บ ไม่ต้องแก้ไขโค้ด
    </p>

    <div class="hero-actions">
        <?php if ($isInstalled): ?>
        <a href="chat.php"  class="btn-hero btn-primary">🚀 เริ่มแชทเลย</a>
        <a href="admin.php" class="btn-hero btn-outline">⚙️ Admin Panel</a>
        <?php else: ?>
        <a href="install.php" class="btn-hero btn-primary">⚡ ติดตั้งระบบ</a>
        <a href="#howto"      class="btn-hero btn-outline">📖 วิธีใช้งาน</a>
        <?php endif; ?>
    </div>

    <?php if ($isInstalled && ($totalUsers !== null || $totalConvs !== null)): ?>
    <div class="hero-stats">
        <?php if ($totalUsers !== null): ?>
        <div class="stat-pill">
            <div>
                <div class="val"><?= number_format($totalUsers) ?></div>
                <div class="lbl">ผู้ใช้งาน</div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($totalConvs !== null): ?>
        <div class="stat-pill">
            <div>
                <div class="val"><?= number_format($totalConvs) ?></div>
                <div class="lbl">การสนทนา</div>
            </div>
        </div>
        <?php endif; ?>
        <div class="stat-pill">
            <div>
                <div class="val">∞</div>
                <div class="lbl">AI Models</div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</section>

<!-- ════════ MOCKUP ════════ -->
<div class="mockup-wrap reveal">
    <div class="mockup">
        <div class="mockup-bar">
            <div class="dot dot-red"></div>
            <div class="dot dot-yellow"></div>
            <div class="dot dot-green"></div>
            <div class="url">localhost/chat.php</div>
        </div>
        <div class="mockup-body">
            <div class="mockup-sidebar">
                <div class="sidebar-item active">💬 สวัสดี AI!</div>
                <div class="sidebar-item dim">📝 สรุปรายงาน</div>
                <div class="sidebar-item dim">💡 ไอเดียโปรเจค</div>
                <div class="sidebar-item dim">🔧 แก้บัค Python</div>
            </div>
            <div class="mockup-chat">
                <div class="chat-msg" style="animation-delay:.1s">
                    <div class="msg-avatar avatar-u">U</div>
                    <div class="msg-bubble bubble-u">สวัสดี! ช่วยสรุปข้อดีของ Ollama ให้หน่อยได้ไหม?</div>
                </div>
                <div class="chat-msg" style="animation-delay:.3s">
                    <div class="msg-avatar avatar-ai">🤖</div>
                    <div class="msg-bubble bubble-ai">
                        แน่นอนครับ! Ollama มีข้อดีหลักดังนี้<br>
                        <strong>1. รันบนเครื่องตัวเอง</strong> — ข้อมูลไม่ออกอินเตอร์เน็ต<br>
                        <strong>2. ฟรี 100%</strong> — ไม่มีค่า API<br>
                        <strong>3. รองรับหลาย model</strong> — Llama 3, Mistral...
                    </div>
                </div>
                <div class="chat-msg" style="animation-delay:.5s">
                    <div class="msg-avatar avatar-u">U</div>
                    <div class="msg-bubble bubble-u">เจ๋งมาก! แล้วเริ่มต้นยังไง?</div>
                </div>
                <div class="chat-msg" style="animation-delay:.7s">
                    <div class="msg-avatar avatar-ai">🤖</div>
                    <div class="msg-bubble bubble-ai">
                        <div class="typing-dots"><span></span><span></span><span></span></div>
                    </div>
                </div>
                <div class="mockup-input">พิมพ์ข้อความที่นี่... (Enter เพื่อส่ง)</div>
            </div>
        </div>
    </div>
</div>

<!-- ════════ FEATURES ════════ -->
<section class="features" id="features">
    <div class="section-label">ฟีเจอร์ทั้งหมด</div>
    <h2 class="section-title">ทุกอย่างที่คุณต้องการ</h2>
    <p class="section-sub">ออกแบบมาให้ใช้งานง่าย ตั้งค่าได้ทุกอย่างผ่านหน้าเว็บ</p>

    <div class="features-grid">
        <div class="feature-card reveal">
            <div class="feature-icon ic-purple">⚡</div>
            <h3>Streaming แบบ Real-time</h3>
            <p>รับคำตอบจาก AI แบบ token-by-token ผ่าน Server-Sent Events ไม่ต้องรอให้ AI ตอบจบ</p>
        </div>
        <div class="feature-card reveal">
            <div class="feature-icon ic-green">🤖</div>
            <h3>จัดการ AI Models</h3>
            <p>เพิ่ม ลบ เปิด/ปิด และเรียงลำดับ model ใน dropdown ได้ผ่าน Admin Panel — ลากวางได้เลย</p>
        </div>
        <div class="feature-card reveal">
            <div class="feature-icon ic-blue">👥</div>
            <h3>ระบบผู้ใช้หลายคน</h3>
            <p>สร้างบัญชีผู้ใช้ได้ไม่จำกัด กำหนดสิทธิ์ admin/user แยกชัดเจน</p>
        </div>
        <div class="feature-card reveal">
            <div class="feature-icon ic-pink">💬</div>
            <h3>ประวัติการสนทนา</h3>
            <p>บันทึกทุกการสนทนาลง MariaDB Admin ดูย้อนหลังได้ทั้งหมด ค้นหาและลบได้</p>
        </div>
        <div class="feature-card reveal">
            <div class="feature-icon ic-orange">🔧</div>
            <h3>ตั้งค่าผ่าน UI</h3>
            <p>เปลี่ยน API Key, Base URL, System Prompt, Max Tokens ได้ใน Admin Panel ไม่ต้องแก้โค้ด</p>
        </div>
        <div class="feature-card reveal">
            <div class="feature-icon ic-cyan">✨</div>
            <h3>Markdown & Code Highlight</h3>
            <p>แสดงผล Markdown เต็มรูปแบบ พร้อม syntax highlighting รองรับกว่า 40 ภาษา</p>
        </div>
        <div class="feature-card reveal">
            <div class="feature-icon ic-purple">🗄️</div>
            <h3>Migration System</h3>
            <p>จัดการ database schema เปลี่ยนแปลงได้อย่างปลอดภัย ผ่าน migration runner ในตัว</p>
        </div>
        <div class="feature-card reveal">
            <div class="feature-icon ic-green">📱</div>
            <h3>Responsive Design</h3>
            <p>ใช้งานได้ทั้งบนมือถือและ desktop sidebar ซ่อน/แสดงอัตโนมัติ</p>
        </div>
        <div class="feature-card reveal">
            <div class="feature-icon ic-blue">🔒</div>
            <h3>ปลอดภัย</h3>
            <p>Login ด้วย username/password เข้ารหัส bcrypt ทุก query ใช้ prepared statement</p>
        </div>
    </div>
</section>

<!-- ════════ PROVIDERS ════════ -->
<section class="providers reveal">
    <div class="section-label">AI Providers</div>
    <h2 class="section-title">รองรับทุก Provider</h2>
    <p class="section-sub">เชื่อมต่อกับ AI ได้หลายช่องทาง เปลี่ยนได้ตลอดเวลาผ่าน Admin</p>

    <div class="provider-grid">
        <div class="provider-card">
            <div class="provider-logo">🦙</div>
            <div>
                <h4>Ollama</h4>
                <p>รัน Llama, Mistral บนเครื่องตัวเอง</p>
            </div>
            <span class="provider-tag tag-local">Local</span>
        </div>
        <div class="provider-card">
            <div class="provider-logo">✨</div>
            <div>
                <h4>OpenAI</h4>
                <p>GPT-4o, GPT-4o-mini, o1</p>
            </div>
            <span class="provider-tag tag-cloud">Cloud</span>
        </div>
        <div class="provider-card">
            <div class="provider-logo">🔀</div>
            <div>
                <h4>OpenRouter</h4>
                <p>รวม models หลายร้อยตัว</p>
            </div>
            <span class="provider-tag tag-cloud">Cloud</span>
        </div>
        <div class="provider-card">
            <div class="provider-logo">🔗</div>
            <div>
                <h4>OpenAI-compatible</h4>
                <p>ทุก API ที่ใช้ format เดียวกัน</p>
            </div>
            <span class="provider-tag tag-cloud">Any</span>
        </div>
    </div>
</section>

<!-- ════════ HOW TO ════════ -->
<section class="howto reveal" id="howto">
    <div class="section-label">เริ่มต้นใช้งาน</div>
    <h2 class="section-title">ง่ายใน 4 ขั้นตอน</h2>
    <p class="section-sub">จาก zero ถึง AI chat ในไม่กี่นาที</p>

    <div class="steps">
        <div class="step-card reveal">
            <h4>ติดตั้งระบบ</h4>
            <p>เปิด <code>install.php</code> กรอกข้อมูล MariaDB และตั้งบัญชี Admin ระบบจะสร้างตารางทั้งหมดให้อัตโนมัติ</p>
        </div>
        <div class="step-card reveal">
            <h4>ตั้งค่า AI</h4>
            <p>เข้า Admin Panel ไปที่ <strong>ตั้งค่า API</strong> ใส่ API Key และ Base URL ของ provider ที่ต้องการใช้</p>
            <code>http://localhost:11434/v1</code>
        </div>
        <div class="step-card reveal">
            <h4>เพิ่ม AI Models</h4>
            <p>ไปที่ <strong>จัดการ AI Models</strong> เพิ่มชื่อ model ที่ต้องการ ผู้ใช้จะเห็นใน dropdown ของ Chat</p>
        </div>
        <div class="step-card reveal">
            <h4>เชิญผู้ใช้</h4>
            <p>สร้างบัญชีผู้ใช้ใน <strong>จัดการผู้ใช้</strong> แจก username/password ให้ผู้ใช้ Login ที่ <code>chat.php</code></p>
        </div>
    </div>
</section>

<!-- ════════ CTA ════════ -->
<section class="cta reveal">
    <h2>พร้อมเริ่มต้นแล้วหรือยัง?</h2>
    <p>ติดตั้งใน 5 นาที ใช้งานได้ทันที ไม่ต้องมี Node.js หรือ Docker</p>
    <div class="cta-actions">
        <?php if ($isInstalled): ?>
        <a href="chat.php"    class="btn-hero btn-primary">🚀 เข้าสู่ระบบ Chat</a>
        <a href="admin.php"   class="btn-hero btn-outline">⚙️ Admin Panel</a>
        <?php else: ?>
        <a href="install.php" class="btn-hero btn-primary">⚡ ติดตั้งระบบเลย</a>
        <a href="migrate.php" class="btn-hero btn-outline">🗄️ Run Migrations</a>
        <?php endif; ?>
    </div>
</section>

<!-- ════════ FOOTER ════════ -->
<footer>
    <p>
        <?= e($siteName) ?> v<?= defined('APP_VERSION') ? e(APP_VERSION) : '2.0.0' ?>
        &nbsp;·&nbsp; PHP <?= PHP_MAJOR_VERSION ?>.<?= PHP_MINOR_VERSION ?>
        &nbsp;·&nbsp; Built with PHP 8 + MariaDB 10
        <?php if ($isInstalled): ?>
        &nbsp;·&nbsp; <a href="admin.php">Admin</a>
        &nbsp;·&nbsp; <a href="chat.php">Chat</a>
        <?php endif; ?>
    </p>
</footer>

<script>
// ── Scroll reveal ─────────────────────────────────────────────────────────────
const observer = new IntersectionObserver(entries => {
    entries.forEach((e, i) => {
        if (e.isIntersecting) {
            // Stagger cards in the same parent
            const siblings = [...e.target.parentElement.querySelectorAll('.reveal')];
            const idx = siblings.indexOf(e.target);
            setTimeout(() => e.target.classList.add('visible'), idx * 70);
            observer.unobserve(e.target);
        }
    });
}, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });

document.querySelectorAll('.reveal').forEach(el => observer.observe(el));

// ── Smooth scroll for anchor links ───────────────────────────────────────────
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
        const target = document.querySelector(a.getAttribute('href'));
        if (target) { e.preventDefault(); target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    });
});

// ── Nav scroll effect ─────────────────────────────────────────────────────────
const nav = document.querySelector('nav');
window.addEventListener('scroll', () => {
    nav.style.borderBottomColor = window.scrollY > 40
        ? 'var(--border2, rgba(255,255,255,.1))'
        : 'var(--border, rgba(255,255,255,.07))';
}, { passive: true });
</script>
<?= themeScript() ?>
</body>
</html>
