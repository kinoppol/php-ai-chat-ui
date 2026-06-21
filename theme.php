<?php
/**
 * Shared Theme System
 * ─────────────────────────────────────────────────────────────────────────────
 * ใช้งาน:
 *   <?= themeAntiFlash() ?>   ← ใส่ใน <head> เป็นบรรทัดแรก (ป้องกัน flash)
 *   <?= themeVars() ?>        ← ใส่ใน <style> บรรทัดแรกสุด
 *   <?= themeToggleBtn() ?>   ← ใส่ใน navbar / header
 *   <?= themeScript() ?>      ← ใส่ก่อน </body>
 */

/** Anti-flash: ต้องรันก่อน CSS ทุกอย่างใน <head> */
function themeAntiFlash(): string {
    return '<script>!function(){var s=localStorage.getItem("ai-theme")||"system",d=document.documentElement,r=s==="system"?(window.matchMedia("(prefers-color-scheme:dark)").matches?"dark":"light"):s;d.setAttribute("data-theme",r);d.setAttribute("data-theme-pref",s)}()</script>';
}

/** CSS custom properties สำหรับ dark / light */
function themeVars(): string { return '
/* ── Dark theme (default) ───────────────────────────────── */
:root,[data-theme="dark"]{
    --bg:         #0f0f13;
    --bg2:        #1a1a27;
    --bg3:        #13131e;
    --bg4:        #202028;
    --chat-bg:    #343541;
    --panel-bg:   #40414f;
    --input-bg:   #40414f;
    --sidebar-bg: #202123;
    --border:     rgba(255,255,255,.07);
    --border2:    rgba(255,255,255,.12);
    --border3:    #565869;
    --text:       #e2e8f0;
    --text-chat:  #ececf1;
    --text2:      #94a3b8;
    --muted:      #64748b;
    --muted-chat: #8e8ea0;
    --shadow:     rgba(0,0,0,.4);
    --overlay:    rgba(0,0,0,.5);
    --msg-user-bg:rgba(255,255,255,.05);
    --msg-hover:  rgba(255,255,255,.02);
    --hover-bg:   rgba(255,255,255,.1);
    --active-bg:  rgba(255,255,255,.15);
    --input-gradient: linear-gradient(transparent,#343541 20%);
    --scrollbar:  rgba(255,255,255,.2);
    --code-bg:    #1e1e1e;
    --code-hd:    #2d2d2d;
}
/* ── Light theme ─────────────────────────────────────────── */
[data-theme="light"]{
    --bg:         #f4f4f8;
    --bg2:        #ffffff;
    --bg3:        #eeeef4;
    --bg4:        #e4e4ec;
    --chat-bg:    #f7f7f8;
    --panel-bg:   #ffffff;
    --input-bg:   #ffffff;
    --sidebar-bg: #eeeef2;
    --border:     rgba(0,0,0,.08);
    --border2:    rgba(0,0,0,.13);
    --border3:    #c8c8d8;
    --text:       #1e1e2e;
    --text-chat:  #343541;
    --text2:      #4a4a6a;
    --muted:      #7878a0;
    --muted-chat: #6e6e80;
    --shadow:     rgba(0,0,0,.1);
    --overlay:    rgba(0,0,0,.35);
    --msg-user-bg:rgba(0,0,0,.04);
    --msg-hover:  rgba(0,0,0,.02);
    --hover-bg:   rgba(0,0,0,.06);
    --active-bg:  rgba(0,0,0,.09);
    --input-gradient: linear-gradient(transparent,#f7f7f8 20%);
    --scrollbar:  rgba(0,0,0,.18);
    --code-bg:    #1e1e1e;
    --code-hd:    #2d2d2d;
}
/* ── Theme toggle button (shared) ────────────────────────── */
.theme-btn{
    display:inline-flex;align-items:center;gap:6px;
    padding:6px 12px;border-radius:9px;
    border:1px solid var(--border2);
    background:var(--hover-bg);
    color:var(--text2);
    font-size:13px;font-weight:600;
    cursor:pointer;transition:.2s;white-space:nowrap;
    font-family:inherit;
}
.theme-btn:hover{background:var(--active-bg);color:var(--text)}
.theme-btn .t-icon{font-size:15px;line-height:1}
.theme-btn .t-lbl{font-size:12px}
/* floating variant */
.theme-float{
    position:fixed;bottom:20px;right:20px;z-index:9999;
    box-shadow:0 4px 16px var(--shadow);
}
'; }

/** ปุ่ม toggle — ใส่ใน navbar/header */
function themeToggleBtn(string $extraClass = ''): string {
    return '<button class="theme-btn ' . htmlspecialchars($extraClass) . '" id="themeToggleBtn" onclick="cycleTheme()" title="เปลี่ยนโหมดการแสดงผล" aria-label="เปลี่ยนธีม">
        <span class="t-icon" id="themeIcon"></span>
        <span class="t-lbl"  id="themeLabel"></span>
    </button>';
}

/** JS engine — ใส่ก่อน </body> */
function themeScript(): string { return '<script>
(function(){
    var PREFS  = ["system","dark","light"];
    var ICONS  = {dark:"🌙", light:"☀️", system:"💻"};
    var LABELS = {dark:"มืด", light:"สว่าง", system:"ระบบ"};

    function applyTheme(pref) {
        var resolved = pref === "system"
            ? (window.matchMedia("(prefers-color-scheme:dark)").matches ? "dark" : "light")
            : pref;
        document.documentElement.setAttribute("data-theme", resolved);
        document.documentElement.setAttribute("data-theme-pref", pref);
        localStorage.setItem("ai-theme", pref);
        var icon = document.getElementById("themeIcon");
        var lbl  = document.getElementById("themeLabel");
        if (icon) icon.textContent = ICONS[pref];
        if (lbl)  lbl.textContent  = LABELS[pref];
    }

    window.cycleTheme = function() {
        var cur  = localStorage.getItem("ai-theme") || "system";
        var next = PREFS[(PREFS.indexOf(cur) + 1) % PREFS.length];
        applyTheme(next);
    };

    // Init button state on load
    applyTheme(localStorage.getItem("ai-theme") || "system");

    // Watch system pref changes
    window.matchMedia("(prefers-color-scheme:dark)").addEventListener("change", function() {
        if ((localStorage.getItem("ai-theme") || "system") === "system") applyTheme("system");
    });
})();
</script>'; }
