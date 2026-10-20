<?php
$laravelEntryPoint = __DIR__.'/laravel/public/index.php';
if (is_file($laravelEntryPoint)) {
    if (! is_file(__DIR__.'/laravel/vendor/autoload.php')) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        echo 'The application is being prepared. Please try again shortly.';
        exit;
    }

    require $laravelEntryPoint;
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/teacher_directory.php';
$authenticatedUser = getAuthenticatedUserOrNull();
if ($authenticatedUser) {
    try {
        $lastTeacherSync = (int) ($_SESSION['teacher_sync_at'] ?? 0);
        if ($lastTeacherSync === 0 || (time() - $lastTeacherSync) > 300) {
            syncTeacherUsersWithLocalDatabase($pdo);
            $_SESSION['teacher_sync_at'] = time();
        }
        $authenticatedUser = getAuthenticatedUserOrNull();
    } catch (Throwable $e) {
    }
}
$printAccessToken = $authenticatedUser ? generateSignedUserToken($authenticatedUser, 'print', 60 * 30) : null;
$appLogoPath = null;
try {
    $logoStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'app_logo_path' LIMIT 1");
    $logoStmt->execute();
    $appLogoPath = normalizeUploadPath($logoStmt->fetchColumn() ?: '');
} catch (Throwable $e) {
    $appLogoPath = null;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ระบบบันทึกหลังสอน สกร. อำเภอเสนา</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
</head>
<body>

<!-- ========== LOGIN PAGE ========== -->
<div id="login-page">
  <div class="login-card">
    <div class="login-logo">
      <div class="logo-icon">📚</div>
      <h1>ระบบบันทึกหลังการสอน<br>สกร. อำเภอเสนา</h1>
      <p>ศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอเสนา</p>
    </div>
    <div class="login-error" id="login-error">ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง</div>
    <div class="form-group">
      <label>ชื่อผู้ใช้งาน</label>
      <input type="text" id="username" placeholder="กรอกชื่อผู้ใช้" autocomplete="username">
    </div>
    <div class="form-group">
      <label>รหัสผ่าน</label>
      <div class="password-toggle-wrap">
        <input type="password" id="password" placeholder="กรอกรหัสผ่าน" autocomplete="current-password">
        <button type="button" class="password-toggle-btn" id="toggle-password" aria-label="แสดงรหัสผ่าน" aria-pressed="false" title="แสดงรหัสผ่าน">
          <span class="password-toggle-icon" aria-hidden="true">👁</span>
        </button>
      </div>
    </div>
    <button class="btn btn-primary btn-full" id="doLoginBtn" style="margin-top:8px;padding:14px;">
      🔐 เข้าสู่ระบบ
    </button>
  </div>
</div>

<!-- ========== MAIN APP ========== -->
<div id="app">
  <!-- Topbar -->
  <div class="topbar">
    <div class="topbar-brand">
      <button class="mobile-menu-toggle" id="mobileMenuBtn">☰</button>
      <div class="icon">📚</div>
      <div>
        <h1>สกร. อำเภอเสนา</h1>
        <p>ระบบบันทึกหลังการสอน</p>
      </div>
    </div>
    <div class="topbar-right">
      <div class="user-badge">
        <div class="avatar" id="user-avatar">A</div>
        <div class="info">
          <strong id="user-display-name">Admin</strong>
          <span id="user-role-display">ผู้ดูแลอำเภอ</span>
        </div>
      </div>
      <button class="btn-logout" id="doLogoutBtn">ออกจากระบบ</button>
    </div>
  </div>

  <div class="main-layout">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <!-- Sidebar -->
    <div class="sidebar">
      <nav id="sidebar-nav">
        <!-- filled by JS -->
      </nav>
    </div>

    <!-- Content -->
    <div class="content" id="main-content-area">
      <!-- ===== DASHBOARD (DISTRICT) ===== -->
      <div class="page active" id="page-dashboard">
        <div class="page-header" style="margin-top:0;">
          <div>
            <h2 style="margin-top:0; line-height:1; display:flex; align-items:center; gap:8px;">📊 ภาพรวมอำเภอเสนา</h2>
            <p style="margin-top:4px; margin-bottom:0;">สรุปข้อมูลการบันทึกหลังการสอนทุกกลุ่มเรียน</p>
          </div>
          <button class="btn btn-outline btn-sm" id="exportReportBtn">📄 ส่งออก CSV</button>
        </div>

        <div class="stats-grid" id="dashboard-stats"></div>

        <div id="dashboard-monthly-students"></div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;" id="dashboard-double">
          <!-- left -->
          <div class="card">
            <div class="card-header"><h3>📈 บันทึกล่าสุด</h3></div>
            <div class="table-responsive">
              <table><thead><tr>
                <th>กลุ่มเรียน</th><th>รายวิชา</th><th>สัปดาห์ที่</th><th>วันที่</th>
              </tr></thead>
              <tbody id="recent-records-tbody"></tbody>
              </table>
            </div>
          </div>
          <!-- right -->
          <div class="card">
            <div class="card-header"><h3>🏘️ สถานะกลุ่มเรียน</h3></div>
            <div style="padding:16px;" id="tambon-status-list"></div>
          </div>
        </div>

        <div class="card record-list-card">
          <div class="card-header record-list-header">
            <div>
              <h3>📋 รายการบันทึกทั้งหมด</h3>
              <p class="card-subtitle">เลือกบันทึกเพื่อดาวน์โหลดเฉพาะรายการ หรือใช้ตัวกรองสัปดาห์เพื่อดาวน์โหลดเป็นชุด</p>
            </div>
            <div class="record-filter-row">
              <input type="text" placeholder="🔍 ค้นหา..." id="search-all">
              <select id="filter-tambon-select">
                <option value="">ทุกกลุ่มเรียน</option>
              </select>
              <select id="filter-week-select">
                <option value="">ทุกสัปดาห์</option>
              </select>
              <select id="filter-image-select">
                <option value="">รูป: ทั้งหมด</option>
                <option value="with">มีรูปประกอบ</option>
                <option value="without">ยังไม่มีรูป</option>
              </select>
            </div>
          </div>
          <div class="record-export-bar" id="all-records-export-bar">
            <label class="record-select-all">
              <input type="checkbox" id="select-all-records">
              <span>เลือกทั้งหมดที่แสดง</span>
            </label>
            <span class="record-selected-count" id="all-records-selected-count">ยังไม่ได้เลือก</span>
            <div class="record-export-actions">
              <button class="btn btn-outline btn-sm export-format-btn" id="download-selected-pdf"><span class="file-type-icon file-type-icon--pdf">PDF</span><span>PDF</span></button>
              <button class="btn btn-primary btn-sm export-format-btn" id="download-selected-doc"><span class="file-type-icon file-type-icon--word">W</span><span>Word .docx</span></button>
            </div>
          </div>
          <div class="table-responsive">
            <table><thead><tr>
              <th class="record-select-col"></th><th>กลุ่มเรียน</th><th>รายวิชา/รหัส</th><th>สัปดาห์</th><th>วันที่</th><th>ผู้เรียน</th><th>สถานะ</th><th></th>
            </tr></thead>
            <tbody id="all-records-tbody"></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ===== TAMBON VIEW (DISTRICT) ===== -->
      <div class="page" id="page-tambons">
        <div class="page-header">
          <div>
            <h2>🏘️ กลุ่มเรียนทั้งหมด</h2>
            <p>คลิกเพื่อดูข้อมูลการบันทึกของแต่ละกลุ่มเรียน</p>
          </div>
        </div>
        <div class="tambon-grid" id="tambon-grid"></div>
      </div>

      <!-- ===== MY RECORDS (TAMBON) ===== -->
      <div class="page" id="page-my-records">
        <div class="page-header">
          <div>
            <h2 id="my-records-title">📝 บันทึกหลังการสอน</h2>
            <p id="my-records-subtitle">ตำบลของฉัน</p>
          </div>
          <button class="btn btn-primary" id="openNewRecordBtn">➕ เพิ่มบันทึกใหม่</button>
        </div>

        <div class="stats-grid" id="tambon-stats"></div>

        <div id="tambon-monthly-students"></div>

        <div class="card record-list-card">
          <div class="card-header record-list-header">
            <div>
              <h3>รายการบันทึกทั้งหมด</h3>
              <p class="card-subtitle">กรองรายสัปดาห์และเลือกดาวน์โหลด PDF หรือ Word ได้จากตาราง</p>
            </div>
            <div class="record-filter-row">
              <input type="text" placeholder="🔍 ค้นหา..." id="search-my">
              <select id="filter-my-week-select">
                <option value="">ทุกสัปดาห์</option>
              </select>
              <select id="filter-my-image-select">
                <option value="">รูป: ทั้งหมด</option>
                <option value="with">มีรูปประกอบ</option>
                <option value="without">ยังไม่มีรูป</option>
              </select>
            </div>
          </div>
          <div class="record-export-bar" id="my-records-export-bar">
            <label class="record-select-all">
              <input type="checkbox" id="select-my-records">
              <span>เลือกทั้งหมดที่แสดง</span>
            </label>
            <span class="record-selected-count" id="my-records-selected-count">ยังไม่ได้เลือก</span>
            <div class="record-export-actions">
              <button class="btn btn-outline btn-sm export-format-btn" id="download-my-selected-pdf"><span class="file-type-icon file-type-icon--pdf">PDF</span><span>PDF</span></button>
              <button class="btn btn-primary btn-sm export-format-btn" id="download-my-selected-doc"><span class="file-type-icon file-type-icon--word">W</span><span>Word .docx</span></button>
            </div>
          </div>
          <div class="table-responsive">
            <table><thead><tr>
              <th class="record-select-col"></th><th>สัปดาห์ที่</th><th>วันที่</th><th>รายวิชา</th><th>ระดับ</th><th>ผู้เรียน/เข้า</th><th>สถานะ</th><th></th>
            </tr></thead>
            <tbody id="my-records-tbody"></tbody>
            </table>
          </div>
          <div id="my-records-empty" class="empty-state" style="display:none;">
            <div class="es-icon">📝</div>
            <p>ยังไม่มีรายการบันทึก</p>
            <small>กดปุ่ม "เพิ่มบันทึกใหม่" เพื่อเริ่มต้น</small>
          </div>
        </div>
      </div>

      <!-- ===== STUDENT ANALYSIS ===== -->
      <div class="page" id="page-student-analysis">
        <div class="page-header">
          <div>
            <h2>📈 วิเคราะห์ผู้เรียน</h2>
            <p>สรุปการมาเรียน ขาดเรียน วิธีเรียน และรายละเอียดรายบุคคล</p>
          </div>
          <button class="btn btn-outline" id="refreshStudentAnalysisBtn">รีเฟรชข้อมูล</button>
        </div>

        <div class="analysis-filter-bar">
          <select id="analysis-tambon-filter">
            <option value="">ทุกตำบล</option>
          </select>
          <select id="analysis-level-filter">
            <option value="">ทุกระดับชั้น</option>
            <option value="ประถมศึกษา">ประถมศึกษา</option>
            <option value="มัธยมศึกษาตอนต้น">มัธยมศึกษาตอนต้น</option>
            <option value="มัธยมศึกษาตอนปลาย">มัธยมศึกษาตอนปลาย</option>
          </select>
          <select id="analysis-class-filter">
            <option value="">ทุกกลุ่มเรียน</option>
          </select>
          <input type="text" id="analysis-search" placeholder="ค้นหานักเรียน/รหัส/กลุ่มเรียน">
        </div>

        <div class="stats-grid" id="analysis-stats"></div>

        <div class="analysis-layout">
          <div class="card">
            <div class="card-header"><h3>ภาพรวมตามระดับ/กลุ่มเรียน</h3></div>
            <div class="analysis-tabs">
              <button class="analysis-tab active" data-analysis-tab="classes">กลุ่มเรียน</button>
              <button class="analysis-tab" data-analysis-tab="levels">ระดับชั้น</button>
            </div>
            <div class="table-responsive">
              <table>
                <thead>
                  <tr><th>ระดับ/กลุ่ม</th><th>ครั้งเรียน</th><th>นักเรียน</th><th>มาเรียน</th><th>ขาด</th><th>ออนไลน์</th><th>อัตรามาเรียน</th></tr>
                </thead>
                <tbody id="analysis-group-tbody"></tbody>
              </table>
            </div>
          </div>

          <div class="card">
            <div class="card-header">
              <h3>สรุปรายบุคคล</h3>
              <select id="analysis-student-filter">
                <option value="">เลือกนักเรียนเพื่อดูรายละเอียด</option>
              </select>
            </div>
            <div class="table-responsive">
              <table>
                <thead>
                  <tr><th>นักเรียน</th><th>กลุ่มเรียน</th><th>มา</th><th>ขาด</th><th>ออนไลน์</th><th>อัตรา</th><th></th></tr>
                </thead>
                <tbody id="analysis-student-tbody"></tbody>
              </table>
            </div>
          </div>
        </div>

      </div>

      <!-- ===== TAMBON MGMT ===== -->
      <div class="page" id="page-tambon-mgmt">
        <div class="page-header">
          <div>
            <h2>🏘️ จัดการกลุ่มเรียน</h2>
            <p>เพิ่ม แก้ไข หรือลบ รายชื่อกลุ่มเรียนในระบบ</p>
          </div>
          <button class="btn btn-primary" id="openAddTambonBtn">➕ เพิ่มกลุ่มเรียน</button>
        </div>
        <div class="card">
          <div class="card-header"><h3>รายชื่อกลุ่มเรียนทั้งหมด</h3></div>
          <div class="table-responsive">
            <table><thead><tr>
              <th style="width: 80px; text-align: center;">ไอคอน</th><th>ชื่อกลุ่มเรียน</th><th></th>
            </tr></thead>
            <tbody id="tambon-list-tbody"></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ===== ADMIN MANAGEMENT ===== -->
      <div class="page" id="page-admin-mgmt">
      <div class="page-header">
        <div>
          <h2>👥 จัดการผู้ดูแลระบบ</h2>
          <p>เพิ่ม/แก้ไขชื่อผู้ใช้และกำหนดสิทธิ์ผู้ดูแลระดับตำบลหรืออำเภอ</p>
        </div>
        <button class="btn btn-primary" id="openAddAdminBtn">➕ เพิ่มผู้ดูแล</button>
      </div>
        <div class="card">
          <div class="card-header"><h3>รายชื่อผู้ดูแลระบบ</h3></div>
          <div class="table-responsive">
            <table><thead><tr>
              <th>ชื่อ-นามสกุล</th><th>ชื่อผู้ใช้</th><th>ตำบล</th><th>บทบาท</th><th>บันทึกล่าสุด</th><th></th>
            </tr></thead>
            <tbody id="admin-table-tbody"></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ===== PRINT SETTINGS ===== -->
      <div class="page" id="page-print-settings">
        <div class="page-header print-settings-hero">
          <div>
            <h2>ตั้งค่า PDF</h2>
            <p id="print-settings-subtitle">แก้ไขข้อความ ตำแหน่งลายเซ็น แล้วดูตัวอย่าง PDF ขนาดเต็มด้านล่าง</p>
          </div>
          <div class="print-settings-save-group">
            <span class="print-settings-status" id="print-settings-save-status">พร้อมบันทึก</span>
            <button class="btn btn-primary" id="savePrintSettingsBtn" onclick="savePrintSettings()">บันทึกการตั้งค่า</button>
          </div>
        </div>

        <!-- ===== PDF PREVIEW (LARGE) ===== -->
        <div class="print-pdf-hero-card">
          <div class="print-pdf-hero-header">
            <div class="print-pdf-hero-header__left">
              <div class="print-pdf-hero-icon">📄</div>
              <div>
                <h3>ตัวอย่าง PDF จริง</h3>
                <p>แสดงผลลัพธ์ PDF เต็มขนาด | ข้อมูลบันทึกครบ ผู้ลงนามเลื่อนตามอัตโนมัติ</p>
              </div>
            </div>
            <div class="print-pdf-hero-header__right">
              <div class="print-zoom-controls">
                <button type="button" class="btn-zoom-action" id="btn-zoom-out" onclick="changePdfZoom(-0.1)" title="ซูมออก">➖</button>
                <span class="zoom-value" id="pdf-zoom-val" onclick="resetPdfZoom()" title="คลิกเพื่อรีเซ็ต">100%</span>
                <button type="button" class="btn-zoom-action" id="btn-zoom-in" onclick="changePdfZoom(0.1)" title="ซูมเข้า">➕</button>
                <button type="button" class="btn-zoom-action" id="btn-zoom-reset" onclick="resetPdfZoom()" title="รีเซ็ตการซูม">🔄</button>
              </div>
              <span class="print-pdf-status-pill" id="print-preview-selected-label">กำลังแสดง PDF จริง</span>
            </div>
          </div>
          <div class="print-pdf-hero-body">
            <div class="print-pdf-preview-large" id="print-layout-pdf-preview">
              <iframe id="print-layout-pdf-frame" title="ตัวอย่าง PDF จาก HTML preview"></iframe>
              <div class="print-layout-pdf-loading" id="print-layout-pdf-loading">
                <div class="print-pdf-loading-spinner"></div>
                <span>กำลังสร้างตัวอย่าง PDF...</span>
              </div>
            </div>
          </div>
        </div>

        <!-- ===== DRAG WORKSPACE (collapsible) ===== -->
        <details class="print-drag-details" id="print-drag-details" open>
          <summary class="print-drag-summary">
            <span class="print-drag-summary__icon">↕</span>
            <span class="print-drag-summary__text"><strong>แก้ไขตำแหน่งลายเซ็น</strong> | คลิกเพื่อเปิดพื้นที่ลากจัดตำแหน่ง</span>
          </summary>
          <div class="print-drag-details__content">
            <div class="print-position-panel" id="print-position-panel" aria-label="ตัวช่วยเลื่อนตำแหน่ง PDF">
              <div class="print-position-panel__intro">
                <div>
                  <strong>จัดตำแหน่งลายเซ็น</strong>
                  <span>เลือกส่วนที่ต้องการแก้ กรอกค่า X/Y หรือใช้ปุ่มเลื่อนทีละระยะ</span>
                </div>
                <span class="print-position-panel__badge" id="print-position-target-badge">ผู้บันทึก</span>
              </div>
              <div class="print-position-panel__controls">
                <label class="print-position-field">
                  <span>เลือกส่วนที่ต้องการปรับ</span>
                  <select id="print-position-target" class="form-control">
                    <option value="sig1">ผู้บันทึก</option>
                    <option value="sig2_signature">หัวหน้างาน</option>
                    <option value="sig3_signature">ผู้อำนวยการ</option>
                  </select>
                </label>
                <label class="print-position-field print-position-field--small">
                  <span>ระยะต่อคลิก</span>
                  <input type="number" id="print-position-step" class="form-control" min="1" value="5">
                </label>
                <label class="print-position-field print-position-field--small">
                  <span>ซ้าย/ขวา X</span>
                  <input type="number" id="print-position-x" class="form-control" value="0">
                </label>
                <label class="print-position-field print-position-field--small">
                  <span>ขึ้น/ลง Y</span>
                  <input type="number" id="print-position-y" class="form-control" value="0">
                </label>
                <label class="print-position-field">
                  <span>จัดแนวลายเซ็น</span>
                  <select id="print-position-align" class="form-control">
                    <option value="flex-start">ชิดซ้าย</option>
                    <option value="center">กึ่งกลาง</option>
                    <option value="flex-end">ชิดขวา</option>
                  </select>
                </label>
                <div class="print-position-pad" aria-label="ปุ่มเลื่อนตำแหน่ง">
                  <button type="button" class="print-position-pad__btn print-position-pad__btn--up" data-print-nudge="up">ขึ้น</button>
                  <button type="button" class="print-position-pad__btn print-position-pad__btn--left" data-print-nudge="left">ซ้าย</button>
                  <button type="button" class="print-position-pad__btn print-position-pad__btn--right" data-print-nudge="right">ขวา</button>
                  <button type="button" class="print-position-pad__btn print-position-pad__btn--down" data-print-nudge="down">ลง</button>
                </div>
                <div class="print-position-steps" aria-label="เลือกความละเอียดการขยับ">
                  <button type="button" data-print-step="1">1px</button>
                  <button type="button" data-print-step="5">5px</button>
                  <button type="button" data-print-step="10">10px</button>
                </div>
                <button type="button" class="btn btn-secondary print-position-reset" id="print-position-reset">รีเซ็ต X/Y</button>
                <button type="button" class="btn btn-outline print-position-reset-all" id="print-position-reset-all">รีเซ็ตตำแหน่งทั้งหมด</button>
              </div>
            </div>

            <div class="print-drag-page-wrapper">
              <div class="print-layout-page print-layout-page--drag-workspace" id="print-layout-page" tabindex="0" aria-hidden="false">
                <div class="print-layout-page-title">บันทึกหลังการสอนการเรียนรู้รายสัปดาห์</div>

                <div class="print-layout-row print-layout-row--head-1">
                  <span class="print-layout-label-nowrap">ศูนย์การเรียนรู้</span>
                  <span class="print-layout-line print-layout-line--wide print-layout-line--nowrap">ตำบลตัวอย่างกลุ่มเรียน</span>
                  <span>ศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอ</span>
                  <span class="print-layout-line">เสนา</span>
                </div>

                <div class="print-layout-row print-layout-row--head-2">
                  <span>สัปดาห์ที่</span>
                  <span class="print-layout-line">10</span>
                  <span>วันที่</span>
                  <span class="print-layout-line">9</span>
                  <span>เดือน</span>
                  <span class="print-layout-line">เมษายน</span>
                  <span>พ.ศ.</span>
                  <span class="print-layout-line">2569</span>
                </div>

                <div class="print-layout-row print-layout-row--head-3">
                  <span>ระดับ</span>
                  <span class="print-layout-line print-layout-line--wide print-layout-line--nowrap">มัธยมศึกษาตอนต้น</span>
                  <span>ภาคเรียนที่</span>
                  <span class="print-layout-line">1</span>
                  <span>ปีการศึกษา</span>
                  <span class="print-layout-line">2569</span>
                </div>

                <div class="print-layout-row print-layout-row--head-4">
                  <span>รายวิชา :</span>
                  <span class="print-layout-line print-layout-line--wide">ทักษะการเรียนรู้</span>
                  <span>รหัสวิชา :</span>
                  <span class="print-layout-line">ทช11001</span>
                </div>

                <div class="print-layout-row print-layout-row--head-5">
                  <span>ผู้เรียนทั้งหมด</span>
                  <span class="print-layout-line">20</span>
                  <span>คน เข้าเรียน</span>
                  <span class="print-layout-line">18</span>
                  <span>คน ไม่มาเรียน</span>
                  <span class="print-layout-line">2</span>
                  <span>คน</span>
                </div>

                <div class="print-layout-guide-title">๑. ผลการจัดการเรียนการสอน</div>
                <div class="print-layout-dotted-text-area">
                  <div class="print-layout-dotted-text-row">ตัวอย่างผลการจัดการเรียนการสอน</div>
                </div>

                <div class="print-layout-guide-title">๒. สภาพปัญหาและอุปสรรค</div>
                <div class="print-layout-dotted-text-area">
                  <div class="print-layout-dotted-text-row">ตัวอย่างสภาพปัญหาและอุปสรรค</div>
                </div>

                <div class="print-layout-guide-title">๓. ข้อเสนอแนะ / แนวทางแก้ไขปัญหา</div>
                <div class="print-layout-dotted-text-area">
                  <div class="print-layout-dotted-text-row">ตัวอย่างข้อเสนอแนะและแนวทางแก้ไขปัญหา</div>
                </div>

                <div class="print-layout-signatures" id="print-preview-sig1-wrap">
                  <div class="print-layout-draggable print-layout-draggable--signature-block" id="print-preview-sig1" data-preview-block="sig1">
                    <div class="print-layout-drag-handle">ผู้บันทึก</div>
                    <div class="print-layout-signature-line" id="print-preview-sig1-line">
                      <span class="print-layout-editable" id="print-preview-sig1-sign-prefix" data-setting-input="set_sig1_sign_prefix" data-preview-block="sig1_sign_prefix">(ลงชื่อ)</span>
                      <span class="print-layout-inline-dot" id="print-preview-sig1-dot"></span>
                      <span class="print-layout-editable print-layout-signature-suffix" id="print-preview-sig1-sign-suffix" data-setting-input="set_sig1_sign_suffix" data-empty-fallback="ผู้บันทึก">ผู้บันทึก</span>
                      <span class="print-layout-signature-balance" id="print-preview-sig1-balance"></span>
                    </div>
                    <div class="print-layout-signature-name" id="print-preview-sig1-name-row">( <span class="print-layout-editable" id="print-preview-sig1-name" data-setting-input="set_sig1_name" data-empty-fallback="ชื่อผู้บันทึก" data-preserve-empty="1">ชื่อผู้บันทึก</span> )</div>
                    <div class="print-layout-signature-pos" id="print-preview-sig1-pos-row"><span class="print-layout-editable" id="print-preview-sig1-pos" data-setting-input="set_sig1_pos" data-empty-fallback="ครูผู้สอน">ครูผู้สอน</span></div>
                  </div>
                </div>

                <div class="print-layout-draggable print-layout-draggable--section" id="print-preview-sig2-block" data-preview-block="sig2_block">
                  <div class="print-layout-drag-handle">บล็อกหัวหน้างาน</div>
                  <div class="print-layout-section-divider"></div>
                  <div class="print-layout-title-text print-layout-editable" id="print-preview-sig2-title-text" data-setting-input="set_sig2_title" data-empty-fallback="ความคิดเห็นของหัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ">ความคิดเห็นของหัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ</div>
                </div>

                <div class="print-layout-section-signatures" id="print-preview-sig2-signature-wrap">
                  <div class="print-layout-draggable print-layout-draggable--signature-block" id="print-preview-sig2-signature" data-preview-block="sig2_signature">
                    <div class="print-layout-drag-handle">ลายเซ็นหัวหน้างาน</div>
                    <div class="print-layout-section-signature" id="print-preview-sig2">
                      <div class="print-layout-signature-line" id="print-preview-sig2-line">
                        <span class="print-layout-editable" id="print-preview-sig2-sign-prefix" data-setting-input="set_sig2_sign_label" data-preview-block="sig2_sign_label">(ลงชื่อ)</span>
                        <span class="print-layout-inline-dot" id="print-preview-sig2-dot"></span>
                        <span class="print-layout-signature-balance" id="print-preview-sig2-balance"></span>
                      </div>
                      <div class="print-layout-signature-name" id="print-preview-sig2-name-row">( <span class="print-layout-editable" id="print-preview-sig2-name" data-setting-input="set_sig2_name" data-empty-fallback="........................................................" data-preserve-empty="1">........................................................</span> )</div>
                      <div class="print-layout-signature-pos" id="print-preview-sig2-pos-row"><span class="print-layout-editable" id="print-preview-sig2-pos" data-setting-input="set_sig2_pos" data-empty-fallback="หัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ" data-allow-br="1">หัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ</span></div>
                    </div>
                  </div>
                </div>

                <div class="print-layout-draggable print-layout-draggable--section" id="print-preview-sig3-block" data-preview-block="sig3_block">
                  <div class="print-layout-drag-handle">บล็อกผู้อำนวยการ</div>
                  <div class="print-layout-title-text print-layout-editable" id="print-preview-sig3-title-text" data-setting-input="set_sig3_title" data-empty-fallback="ข้อคิดเห็นของผู้อำนวยการสถานศึกษา">ข้อคิดเห็นของผู้อำนวยการสถานศึกษา</div>
                  <div class="print-layout-single-line-dot"></div>
                  <div class="print-layout-section-signatures" id="print-preview-sig3-wrap">
                    <div class="print-layout-draggable print-layout-draggable--signature-block print-layout-section-signature print-layout-section-signature--wide" id="print-preview-sig3" data-preview-block="sig3_signature">
                      <div class="print-layout-drag-handle">ลายเซ็นผู้อำนวยการ</div>
                      <div class="print-layout-signature-line" id="print-preview-sig3-line">
                        <span class="print-layout-editable" id="print-preview-sig3-sign-prefix" data-setting-input="set_sig3_sign_label" data-preview-block="sig3_sign_label">(ลงชื่อ)</span>
                        <span class="print-layout-inline-dot" id="print-preview-sig3-dot"></span>
                        <span class="print-layout-signature-balance" id="print-preview-sig3-balance"></span>
                      </div>
                      <div class="print-layout-signature-name" id="print-preview-sig3-name-row">( <span class="print-layout-editable" id="print-preview-sig3-name" data-setting-input="set_sig3_name" data-empty-fallback="ชื่อผู้อำนวยการ" data-preserve-empty="1">ชื่อผู้อำนวยการ</span> )</div>
                      <div class="print-layout-signature-pos" id="print-preview-sig3-pos-row"><span class="print-layout-editable" id="print-preview-sig3-pos" data-setting-input="set_sig3_pos" data-empty-fallback="ครู รักษาการในตำแหน่ง<br>ผู้อำนวยการสถานศึกษา ศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอเสนา" data-allow-br="1">ครู รักษาการในตำแหน่ง<br>ผู้อำนวยการสถานศึกษา ศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอเสนา</span></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </details>

        <div class="card print-settings-form-card" id="print-settings-general-card">
          <div class="card-header print-settings-card-header">
            <div>
              <h3>การตั้งค่าเอกสารทั่วไป</h3>
              <p>ตั้งค่าขนาดตัวอักษรและความหนาเส้นประจุดโดยรวม</p>
            </div>
            <span>เอกสาร</span>
          </div>
          <div class="print-settings-card-body">
            <div class="print-settings-row">
              <div class="print-settings-col">
                <label for="set_pdf_font_size_pt" class="form-label" style="display: flex; justify-content: space-between; align-items: center;">
                  <span>ขนาดตัวอักษร PDF</span>
                  <span id="font-size-offset-label" class="badge bg-primary" style="font-size: 13px; font-weight: bold; background-color: #2f67b1 !important; border-radius: 4px; padding: 4px 8px;">16 pt</span>
                </label>
                <select id="set_pdf_font_size_pt" class="form-control" style="margin-top: 8px;">
                  <option value="10">10 pt</option>
                  <option value="11">11 pt</option>
                  <option value="12">12 pt</option>
                  <option value="13">13 pt</option>
                  <option value="14">14 pt</option>
                  <option value="15">15 pt</option>
                  <option value="16" selected>16 pt</option>
                  <option value="17">17 pt</option>
                  <option value="18">18 pt</option>
                  <option value="19">19 pt</option>
                  <option value="20">20 pt</option>
                </select>
                <input type="hidden" id="set_font_size_offset" value="0">
              </div>
            </div>
            <div class="print-settings-grid print-settings-grid-3" style="margin-top: 18px;">
              <div class="form-group">
                <label for="set_pdf_line_spacing">ระยะบรรทัดข้อความ</label>
                <select id="set_pdf_line_spacing" class="form-control">
                  <option value="20">20 px</option><option value="22">22 px</option><option value="24">24 px</option>
                  <option value="26" selected>26 px</option><option value="28">28 px</option><option value="30">30 px</option>
                  <option value="32">32 px</option><option value="34">34 px</option><option value="36">36 px</option>
                  <option value="38">38 px</option><option value="40">40 px</option>
                </select>
              </div>
              <div class="form-group">
                <label for="set_signature_font_size_pt">ขนาดฟอนต์ลายเซ็น</label>
                <select id="set_signature_font_size_pt" class="form-control">
                  <option value="10">10 pt</option><option value="11">11 pt</option><option value="12">12 pt</option>
                  <option value="13">13 pt</option><option value="14">14 pt</option><option value="15">15 pt</option>
                  <option value="16" selected>16 pt</option><option value="17">17 pt</option><option value="18">18 pt</option>
                  <option value="19">19 pt</option><option value="20">20 pt</option>
                </select>
              </div>
              <div class="form-group">
                <label for="set_attendance_font_size_pt">ขนาดฟอนต์รายชื่อ</label>
                <select id="set_attendance_font_size_pt" class="form-control">
                  <option value="10">10 pt</option><option value="11">11 pt</option><option value="12">12 pt</option>
                  <option value="13">13 pt</option><option value="14">14 pt</option><option value="15">15 pt</option>
                  <option value="16" selected>16 pt</option><option value="17">17 pt</option><option value="18">18 pt</option>
                  <option value="19">19 pt</option><option value="20">20 pt</option>
                </select>
              </div>
            </div>
            <div class="print-settings-grid print-settings-grid-4" style="margin-top: 18px;">
              <div class="form-group">
                <label for="set_pdf_margin_top_px">ขอบกระดาษบน</label>
                <input type="number" id="set_pdf_margin_top_px" class="form-control" min="20" max="140" step="1" value="57">
              </div>
              <div class="form-group">
                <label for="set_pdf_margin_right_px">ขอบกระดาษขวา</label>
                <input type="number" id="set_pdf_margin_right_px" class="form-control" min="20" max="160" step="1" value="76">
              </div>
              <div class="form-group">
                <label for="set_pdf_margin_bottom_px">ขอบกระดาษล่าง</label>
                <input type="number" id="set_pdf_margin_bottom_px" class="form-control" min="20" max="140" step="1" value="38">
              </div>
              <div class="form-group">
                <label for="set_pdf_margin_left_px">ขอบกระดาษซ้าย</label>
                <input type="number" id="set_pdf_margin_left_px" class="form-control" min="20" max="160" step="1" value="94">
              </div>
            </div>
            <div class="print-settings-row" style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #edf2f7;">
              <div class="print-settings-col">
                <label for="set_pdf_dot_size" class="form-label" style="display: flex; justify-content: space-between; align-items: center;">
                  <span>ปรับความหนาเส้นประจุด</span>
                  <span id="pdf-dot-size-label" class="badge bg-primary" style="font-size: 13px; font-weight: bold; background-color: #2f67b1 !important; border-radius: 4px; padding: 4px 8px;">ปกติ (2px)</span>
                </label>
                <div style="display: flex; align-items: center; gap: 14px; margin-top: 8px;">
                  <span style="font-size: 13px; color: #666; font-weight: 500;">บางที่สุด (1px)</span>
                  <input type="range" class="form-range" id="set_pdf_dot_size" min="1" max="4" step="1" value="2" style="flex: 1; accent-color: #2f67b1; height: 6px; border-radius: 4px; cursor: pointer;">
                  <span style="font-size: 13px; color: #666; font-weight: 500;">หนาที่สุด (4px)</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="card print-settings-form-card" id="print-settings-sig1-card">
          <div class="card-header print-settings-card-header">
            <div>
              <h3>ส่วนที่ 1: ผู้บันทึก</h3>
              <p>แก้คำหน้าลายเซ็น ชื่อ และตำแหน่งผู้บันทึก</p>
            </div>
            <span>ครูผู้สอน</span>
          </div>
          <div class="print-settings-card-body">
            <input type="hidden" id="set_sig1_sign_suffix" value="ผู้บันทึก">
            <input type="hidden" id="set_sig1_sign_prefix_offset_x" value="0">
            <input type="hidden" id="set_sig1_sign_prefix_offset_y" value="0">
            <input type="hidden" id="set_sig1_line_offset_x" value="0">
            <input type="hidden" id="set_sig1_line_offset_y" value="0">
            <input type="hidden" id="set_sig1_name_offset_x" value="0">
            <input type="hidden" id="set_sig1_name_offset_y" value="0">
            <input type="hidden" id="set_sig1_pos_offset_x" value="0">
            <input type="hidden" id="set_sig1_pos_offset_y" value="0">
            <div class="print-settings-grid print-settings-grid-2 print-settings-position-options">
              <div class="form-group">
                <label>คำหน้าลายเซ็น</label>
                <input type="text" id="set_sig1_sign_prefix" class="form-control" placeholder="เช่น (ลงชื่อ)" value="(ลงชื่อ)">
              </div>
              <div class="form-group">
                <label>ชื่อผู้ลงนาม <small>(ปล่อยว่างใช้ชื่อผู้บันทึกจากรายการ)</small></label>
                <input type="text" id="set_sig1_name" class="form-control" placeholder="ชื่อ นามสกุล">
              </div>
            </div>
            <div class="print-settings-grid print-settings-grid-2">
              <div class="form-group">
                <label>ตำแหน่ง</label>
                <input type="text" id="set_sig1_pos" class="form-control" placeholder="เช่น ครูผู้สอน">
              </div>
            </div>
            <div class="print-settings-grid print-settings-grid-2">
              <div class="form-group">
                <label>การจัดตำแหน่ง PDF</label>
                <select id="set_sig1_align" class="form-control">
                  <option value="flex-start">ชิดซ้าย (Left)</option>
                  <option value="center">กึ่งกลาง (Center)</option>
                  <option value="flex-end">ชิดขวา (Right)</option>
                </select>
              </div>
              <div class="form-group">
                <label>ความกว้างเส้นประ (px)</label>
                <input type="number" id="set_sig1_dot_width" class="form-control" placeholder="เช่น 180" value="180">
              </div>
            </div>
            
            <div class="print-settings-grid print-settings-grid-3 print-settings-style-grid">
              <div class="form-group">
                <label>ระยะห่างด้านบน (px)</label>
                <input type="number" id="set_sig1_mt" class="form-control" placeholder="เช่น 48" value="48">
              </div>
              <div class="form-group print-settings-coordinate-field">
                <label>เลื่อนซ้าย/ขวา (px)</label>
                <input type="number" id="set_sig1_offset" class="form-control" placeholder="เช่น 20 หรือ -20" value="0">
              </div>
              <div class="form-group print-settings-coordinate-field">
                <label>เลื่อนขึ้น/ลงละเอียด (px)</label>
                <input type="number" id="set_sig1_offset_y" class="form-control" placeholder="เช่น 8 หรือ -8" value="0">
              </div>
            </div>
          </div>
        </div>
        
        <div class="card print-settings-form-card" id="print-settings-sig2-card">
          <div class="card-header print-settings-card-header">
            <div>
              <h3>ส่วนที่ 2: หัวหน้างาน</h3>
              <p>แก้หัวข้อ ชื่อ และตำแหน่งหัวหน้างาน</p>
            </div>
            <span>ระดับอำเภอ</span>
          </div>
          <div class="print-settings-card-body">
            <input type="hidden" id="set_sig2_sign_label_offset_x" value="0">
            <input type="hidden" id="set_sig2_sign_label_offset_y" value="0">
            <input type="hidden" id="set_sig2_line_offset_x" value="0">
            <input type="hidden" id="set_sig2_line_offset_y" value="0">
            <input type="hidden" id="set_sig2_name_offset_x" value="0">
            <input type="hidden" id="set_sig2_name_offset_y" value="0">
            <input type="hidden" id="set_sig2_pos_offset_x" value="0">
            <input type="hidden" id="set_sig2_pos_offset_y" value="0">
            <input type="hidden" id="set_sig2_block_offset_x" value="0">
            <input type="hidden" id="set_sig2_block_offset_y" value="0">
            <input type="hidden" id="set_sig2_title_offset_x" value="0">
            <input type="hidden" id="set_sig2_title_offset_y" value="0">
            <div class="form-group">
              <label>หัวข้อความคิดเห็น</label>
              <input type="text" id="set_sig2_title" class="form-control" placeholder="เช่น ความคิดเห็นของหัวหน้างาน...">
            </div>
            <div class="print-settings-grid print-settings-grid-2 print-settings-position-options">
              <div class="form-group">
                <label>คำหน้าลายเซ็น</label>
                <input type="text" id="set_sig2_sign_label" class="form-control" placeholder="เช่น (ลงชื่อ)" value="(ลงชื่อ)">
              </div>
              <div class="form-group">
                <label>ชื่อผู้ลงนาม <small>(ปล่อยว่างให้เซ็นด้วยปากกา)</small></label>
                <input type="text" id="set_sig2_name" class="form-control" placeholder="">
              </div>
            </div>
            <div class="print-settings-grid print-settings-grid-2">
              <div class="form-group">
                <label>ตำแหน่ง</label>
                <input type="text" id="set_sig2_pos" class="form-control" placeholder="เช่น หัวหน้างานการจัดการเรียนรู้...">
              </div>
            </div>
            <div class="print-settings-grid print-settings-grid-2">
              <div class="form-group">
                <label>การจัดตำแหน่ง PDF</label>
                <select id="set_sig2_align" class="form-control">
                  <option value="flex-start">ชิดซ้าย (Left)</option>
                  <option value="center">กึ่งกลาง (Center)</option>
                  <option value="flex-end">ชิดขวา (Right)</option>
                </select>
              </div>
              <div class="form-group">
                <label>ความกว้างเส้นประ (px)</label>
                <input type="number" id="set_sig2_dot_width" class="form-control" placeholder="เช่น 180" value="180">
              </div>
            </div>
            
            <div class="print-settings-grid print-settings-grid-2 print-settings-legacy-position-grid">
              <div class="form-group">
                <label>เลื่อนทั้งบล็อกซ้าย/ขวา (px)</label>
                <input type="number" id="set_sig2_block_offset_x" class="form-control" placeholder="เช่น 10 หรือ -10" value="0">
              </div>
              <div class="form-group">
                <label>เลื่อนทั้งบล็อกขึ้น/ลง (px)</label>
                <input type="number" id="set_sig2_block_offset_y" class="form-control" placeholder="เช่น 8 หรือ -8" value="0">
              </div>
            </div>

            <div class="print-settings-grid print-settings-grid-2 print-settings-legacy-position-grid">
              <div class="form-group">
                <label>เลื่อนหัวข้อซ้าย/ขวา (px)</label>
                <input type="number" id="set_sig2_title_offset_x" class="form-control" placeholder="เช่น 10 หรือ -10" value="0">
              </div>
              <div class="form-group">
                <label>เลื่อนหัวข้อขึ้น/ลง (px)</label>
                <input type="number" id="set_sig2_title_offset_y" class="form-control" placeholder="เช่น 6 หรือ -6" value="0">
              </div>
            </div>

            <div class="print-settings-grid print-settings-grid-3 print-settings-style-grid">
              <div class="form-group">
                <label>ระยะห่างด้านบน (px)</label>
                <input type="number" id="set_sig2_mt" class="form-control" placeholder="เช่น 40" value="40">
              </div>
              <div class="form-group print-settings-coordinate-field">
                <label>เลื่อนซ้าย/ขวา (px)</label>
                <input type="number" id="set_sig2_offset" class="form-control" placeholder="เช่น 20 หรือ -20" value="0">
              </div>
              <div class="form-group print-settings-coordinate-field">
                <label>เลื่อนขึ้น/ลงละเอียด (px)</label>
                <input type="number" id="set_sig2_offset_y" class="form-control" placeholder="เช่น 8 หรือ -8" value="0">
              </div>
            </div>
          </div>
        </div>
        
        <div class="card print-settings-form-card" id="print-settings-sig3-card">
          <div class="card-header print-settings-card-header">
            <div>
              <h3>ส่วนที่ 3: ผู้อำนวยการ</h3>
              <p>แก้หัวข้อ ชื่อ และตำแหน่งผู้อำนวยการ</p>
            </div>
            <span>ลงนามปิดท้าย</span>
          </div>
          <div class="print-settings-card-body">
            <input type="hidden" id="set_sig3_sign_label_offset_x" value="0">
            <input type="hidden" id="set_sig3_sign_label_offset_y" value="0">
            <input type="hidden" id="set_sig3_line_offset_x" value="0">
            <input type="hidden" id="set_sig3_line_offset_y" value="0">
            <input type="hidden" id="set_sig3_name_offset_x" value="0">
            <input type="hidden" id="set_sig3_name_offset_y" value="0">
            <input type="hidden" id="set_sig3_pos_offset_x" value="0">
            <input type="hidden" id="set_sig3_pos_offset_y" value="0">
            <input type="hidden" id="set_sig3_block_offset_x" value="0">
            <input type="hidden" id="set_sig3_block_offset_y" value="0">
            <input type="hidden" id="set_sig3_title_offset_x" value="0">
            <input type="hidden" id="set_sig3_title_offset_y" value="0">
            <div class="form-group">
              <label>หัวข้อความคิดเห็น</label>
              <input type="text" id="set_sig3_title" class="form-control" placeholder="เช่น ข้อคิดเห็นของผู้อำนวยการสถานศึกษา">
            </div>
            <div class="print-settings-grid print-settings-grid-2 print-settings-position-options">
              <div class="form-group">
                <label>คำหน้าลายเซ็น</label>
                <input type="text" id="set_sig3_sign_label" class="form-control" placeholder="เช่น (ลงชื่อ)" value="(ลงชื่อ)">
              </div>
              <div class="form-group">
                <label>ชื่อผู้ลงนาม</label>
                <input type="text" id="set_sig3_name" class="form-control" placeholder="ชื่อ นามสกุล (ถ้ามี)">
              </div>
            </div>
            <div class="print-settings-grid print-settings-grid-2">
              <div class="form-group">
                <label>ตำแหน่ง <small>(ใส่ &lt;br&gt; เพื่อขึ้นบรรทัดใหม่)</small></label>
                <!-- Use input type text to allow typing HTML like <br> natively since it maps directly to DB -->
                <input type="text" id="set_sig3_pos" class="form-control" placeholder="สามารถใส่ <br> เพื่อขึ้นบรรทัดใหม่ได้">
              </div>
            </div>
            <div class="print-settings-grid print-settings-grid-2">
              <div class="form-group">
                <label>การจัดตำแหน่ง PDF</label>
                <select id="set_sig3_align" class="form-control">
                  <option value="flex-start">ชิดซ้าย (Left)</option>
                  <option value="center">กึ่งกลาง (Center)</option>
                  <option value="flex-end">ชิดขวา (Right)</option>
                </select>
              </div>
              <div class="form-group">
                <label>ความกว้างเส้นประ (px)</label>
                <input type="number" id="set_sig3_dot_width" class="form-control" placeholder="เช่น 200" value="200">
              </div>
            </div>
            
            <div class="print-settings-grid print-settings-grid-2 print-settings-legacy-position-grid">
              <div class="form-group">
                <label>เลื่อนทั้งบล็อกซ้าย/ขวา (px)</label>
                <input type="number" id="set_sig3_block_offset_x" class="form-control" placeholder="เช่น 10 หรือ -10" value="0">
              </div>
              <div class="form-group">
                <label>เลื่อนทั้งบล็อกขึ้น/ลง (px)</label>
                <input type="number" id="set_sig3_block_offset_y" class="form-control" placeholder="เช่น 8 หรือ -8" value="0">
              </div>
            </div>

            <div class="print-settings-grid print-settings-grid-2 print-settings-legacy-position-grid">
              <div class="form-group">
                <label>เลื่อนหัวข้อซ้าย/ขวา (px)</label>
                <input type="number" id="set_sig3_title_offset_x" class="form-control" placeholder="เช่น 10 หรือ -10" value="0">
              </div>
              <div class="form-group">
                <label>เลื่อนหัวข้อขึ้น/ลง (px)</label>
                <input type="number" id="set_sig3_title_offset_y" class="form-control" placeholder="เช่น 6 หรือ -6" value="0">
              </div>
            </div>

            <div class="print-settings-grid print-settings-grid-3 print-settings-style-grid">
              <div class="form-group">
                <label>ระยะห่างด้านบน (px)</label>
                <input type="number" id="set_sig3_mt" class="form-control" placeholder="เช่น 38" value="38">
              </div>
              <div class="form-group print-settings-coordinate-field">
                <label>เลื่อนซ้าย/ขวา (px)</label>
                <input type="number" id="set_sig3_offset" class="form-control" placeholder="เช่น 20 หรือ -20" value="0">
              </div>
              <div class="form-group print-settings-coordinate-field">
                <label>เลื่อนขึ้น/ลงละเอียด (px)</label>
                <input type="number" id="set_sig3_offset_y" class="form-control" placeholder="เช่น 8 หรือ -8" value="0">
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ===== SETTINGS ===== -->
      <div class="page" id="page-settings">
        <div class="page-header">
          <h2>⚙️ ตั้งค่าระบบ</h2>
        </div>
        <div class="settings-grid">
        <div class="card settings-card" style="max-width:500px;">
          <div class="card-header"><h3>เปลี่ยนรหัสผ่าน</h3></div>
          <div class="modal-body">
            <div class="form-group">
              <label>รหัสผ่านปัจจุบัน</label>
              <input type="password" id="cur-pass" placeholder="รหัสผ่านปัจจุบัน">
            </div>
            <div class="form-group">
              <label>รหัสผ่านใหม่</label>
              <input type="password" id="new-pass" placeholder="รหัสผ่านใหม่ (อย่างน้อย 6 ตัว)">
            </div>
            <div class="form-group">
              <label>ยืนยันรหัสผ่านใหม่</label>
              <input type="password" id="confirm-pass" placeholder="ยืนยันรหัสผ่านใหม่">
            </div>
            <button class="btn btn-primary" id="changePasswordBtn">💾 บันทึกรหัสผ่าน</button>
          </div>
        </div>
        <div class="card settings-card">
          <div class="card-header">
            <div>
              <h3>โปรไฟล์ผู้ใช้</h3>
              <p class="card-subtitle">รูปนี้จะแสดงบนแถบด้านบนของบัญชีที่กำลังใช้งาน</p>
            </div>
          </div>
          <div class="settings-media-body">
            <div class="settings-profile-preview" id="profile-preview">A</div>
            <div class="settings-media-copy">
              <strong id="profile-name-preview">ผู้ใช้งาน</strong>
              <span>JPG, PNG, GIF หรือ WebP สูงสุด 3MB</span>
              <label class="btn btn-outline btn-sm settings-file-button">
                เลือกรูปโปรไฟล์
                <input type="file" id="profile-image-input" accept="image/jpeg,image/png,image/gif,image/webp">
              </label>
              <button class="btn btn-primary btn-sm" id="uploadProfileBtn" type="button">อัปโหลดรูปโปรไฟล์</button>
            </div>
          </div>
        </div>
        <div class="card settings-card settings-branding-card" id="branding-settings-card">
          <div class="card-header">
            <div>
              <h3>แบรนด์ของระบบ</h3>
              <p class="card-subtitle">โลโก้จะแสดงในหน้าเข้าสู่ระบบและส่วนหัวของระบบ</p>
            </div>
            <span class="settings-admin-only">ผู้ดูแลอำเภอ</span>
          </div>
          <div class="settings-media-body">
            <div class="settings-logo-preview" id="logo-preview">📚</div>
            <div class="settings-media-copy">
              <strong>โลโก้หน่วยงาน</strong>
              <span>แนะนำ PNG พื้นหลังโปร่งใส สูงสุด 4MB</span>
              <label class="btn btn-outline btn-sm settings-file-button">
                เลือกไฟล์โลโก้
                <input type="file" id="logo-image-input" accept="image/jpeg,image/png,image/gif,image/webp">
              </label>
              <button class="btn btn-primary btn-sm" id="uploadLogoBtn" type="button">อัปโหลดโลโก้</button>
            </div>
          </div>
        </div>
        <div class="card settings-card settings-health-card" id="code-health-card">
          <div class="card-header">
            <div>
              <h3>ตรวจสุขภาพโค้ด</h3>
              <p class="card-subtitle">ตรวจ syntax, security pattern, migration และการป้องกัน uploads</p>
            </div>
            <button class="btn btn-outline btn-sm" id="runCodeHealthBtn" type="button">เริ่มตรวจ</button>
          </div>
          <div class="code-health-summary" id="code-health-summary">ยังไม่ได้เรียกใช้งานตัวตรวจ</div>
          <pre class="code-health-output" id="code-health-output" hidden></pre>
        </div>
        </div>
      </div>

    </div><!-- /content -->
  </div><!-- /main-layout -->
</div><!-- /app -->

<!-- ========== MODAL: NEW/EDIT RECORD ========== -->
<div class="modal-overlay" id="modal-record">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h3 id="modal-record-title">📝 เพิ่มบันทึกหลังการสอน</h3>
      <button class="modal-close" data-close="modal-record">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="record-id">
      <div class="form-section">
        <div class="form-section-title">ข้อมูลทั่วไป</div>
        <div class="form-row">
          <div class="form-group">
            <label>ศูนย์การเรียนรู้ (ตำบล)</label>
            <input type="text" id="rec-tambon" placeholder="ชื่อตำบล/ศูนย์">
          </div>
          <div class="form-group">
            <label>สัปดาห์ที่</label>
            <input type="number" id="rec-week" min="1" max="20" placeholder="เช่น 1">
          </div>
        </div>
        <div class="form-row three">
          <div class="form-group">
            <label>วันที่สอน</label>
            <input type="date" id="rec-date">
          </div>
          <div class="form-group">
            <label>ระดับ</label>
            <select id="rec-level">
              <option value="">-- เลือกระดับ --</option>
              <option>ประถมศึกษา</option>
              <option>มัธยมศึกษาตอนต้น</option>
              <option>มัธยมศึกษาตอนปลาย</option>
            </select>
          </div>
          <div class="form-group">
            <label>ภาคเรียนที่</label>
            <select id="rec-semester">
              <option value="1">1</option>
              <option value="2">2</option>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>รายวิชา</label>
            <input type="text" id="rec-subject" placeholder="ชื่อรายวิชา">
          </div>
          <div class="form-group">
            <label>รหัสวิชา</label>
            <input type="text" id="rec-subject-code" placeholder="เช่น พต21001">
          </div>
        </div>
        <div class="student-attendance-section">
          <div class="form-section-title">กลุ่มเรียนและรายชื่อนักเรียน</div>
          <input type="hidden" id="rec-class-group-code">
          <input type="hidden" id="rec-class-name">
          <div class="form-row class-group-row">
            <div class="form-group">
              <label>กลุ่มเรียน</label>
              <select id="rec-class-group">
                <option value="">-- เลือกกลุ่มเรียนจาก API นักเรียน --</option>
              </select>
              <div class="student-api-hint" id="student-group-meta">เลือกกลุ่มเรียนเพื่อโหลดรายชื่อนักเรียน</div>
            </div>
            <div class="form-group class-group-actions">
              <label>&nbsp;</label>
              <button type="button" class="btn btn-outline" id="reloadStudentGroupsBtn">โหลดกลุ่มเรียน</button>
            </div>
          </div>
          <div class="student-toolbar" id="student-toolbar" style="display:none;">
            <input type="text" id="student-search" placeholder="ค้นหาชื่อนักเรียน/รหัสนักเรียน">
            <div class="student-toolbar-actions">
              <button type="button" class="btn btn-sm btn-outline" id="selectAllStudentsBtn">เลือกทั้งหมด</button>
              <button type="button" class="btn btn-sm btn-outline" id="clearAllStudentsBtn">ล้างที่เลือก</button>
            </div>
          </div>
          <div class="student-attendance-summary" id="student-attendance-summary" style="display:none;">
            เลือกมาเรียน 0 จาก 0 คน
          </div>
          <div class="student-list" id="student-list">
            <div class="student-list-empty">ยังไม่ได้เลือกกลุ่มเรียน</div>
          </div>
        </div>
        <div class="form-row three">
          <div class="form-group">
            <label>ผู้เรียนทั้งหมด (คน)</label>
            <input type="number" id="rec-total" min="0" placeholder="0">
          </div>
          <div class="form-group">
            <label>เข้าเรียน (คน)</label>
            <input type="number" id="rec-present" min="0" placeholder="0">
          </div>
          <div class="form-group">
            <label>ไม่มาเรียน (คน)</label>
            <input type="number" id="rec-absent" min="0" placeholder="0" readonly style="background:#f5f5f5;">
          </div>
        </div>
        <div class="form-group">
          <label>ปีการศึกษา</label>
          <select id="rec-year" aria-label="ปีการศึกษา">
            <option value="">-- เลือกปีการศึกษา --</option>
          </select>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title">1. ผลการจัดการเรียนการสอน</div>
        <div class="form-group">
          <textarea class="tall char-counted" id="rec-result" data-target="cnt-result" placeholder="บันทึกผลการจัดการเรียนการสอน..."></textarea>
          <div class="char-count"><span id="cnt-result">0</span> ตัวอักษร</div>
          <div class="form-hint">💡 พิมพ์ <code>*</code> เพื่อขึ้นบรรทัดแบบเป็นข้อๆ และ <code>***</code> เพื่อขึ้นหน้าใหม่ใน PDF</div>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title">2. สภาพปัญหาและอุปสรรค</div>
        <div class="form-group">
          <textarea class="tall char-counted" id="rec-problem" data-target="cnt-problem" placeholder="บันทึกสภาพปัญหาและอุปสรรค..."></textarea>
          <div class="char-count"><span id="cnt-problem">0</span> ตัวอักษร</div>
          <div class="form-hint">💡 พิมพ์ <code>*</code> เพื่อขึ้นบรรทัดแบบเป็นข้อๆ และ <code>***</code> เพื่อขึ้นหน้าใหม่ใน PDF</div>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title">3. ข้อเสนอแนะ / แนวทางแก้ไขปัญหา</div>
        <div class="form-group">
          <textarea class="tall char-counted" id="rec-suggestion" data-target="cnt-suggest" placeholder="บันทึกข้อเสนอแนะ..."></textarea>
          <div class="char-count"><span id="cnt-suggest">0</span> ตัวอักษร</div>
          <div class="form-hint">💡 พิมพ์ <code>*</code> เพื่อขึ้นบรรทัดแบบเป็นข้อๆ และ <code>***</code> เพื่อขึ้นหน้าใหม่ใน PDF</div>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title">ภาพประกอบการจัดการเรียนการสอน</div>
        <div class="image-upload-area" id="upload-area">
          <div class="upload-icon">🖼️</div>
          <div class="upload-text">
            <strong>คลิกหรือลากไฟล์รูปภาพมาวาง</strong><br>
            รองรับ JPG, PNG, WEBP (สูงสุด 8MB ต่อรูป)
          </div>
          <input type="file" id="file-input" multiple accept="image/*" style="display:none">
        </div>
        <div id="image-upload-hint" style="margin-top:10px;font-size:13px;color:#6b7a90;">
          อัปโหลดได้เฉพาะ 2 รูป หรือ 4 รูปเท่านั้น
        </div>
        <div class="image-previews" id="image-previews"></div>
      </div>

      <div class="form-section admin-only" id="rec-supervisor-section" style="display:none;">
        <div class="form-section-title">ความคิดเห็นของหัวหน้างาน</div>
        <div class="form-group">
          <textarea id="rec-supervisor-comment" placeholder="ความคิดเห็น (กรอกโดยหัวหน้างาน)..." style="min-height:60px;resize:vertical;width:100%;padding:12px 16px;border:2px solid var(--border);border-radius:10px;font-family:Sarabun;font-size:15px;"></textarea>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" data-close="modal-record">ยกเลิก</button>
      <button class="btn btn-success" id="saveRecordBtn">💾 บันทึกข้อมูล</button>
    </div>
  </div>
</div>

<!-- ========== MODAL: VIEW RECORD ========== -->
<div class="modal-overlay" id="modal-view">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h3>📄 รายละเอียดบันทึกหลังการสอน</h3>
      <button class="modal-close" data-close="modal-view">✕</button>
    </div>
    <div class="modal-body" id="modal-view-body"></div>
    <div class="modal-footer modal-footer--record-actions">
      <button class="btn btn-outline modal-action-btn" data-close="modal-view">ปิด</button>
      <button class="btn btn-primary modal-action-btn" id="btn-edit-record">✏️ แก้ไข</button>
      <button class="btn btn-danger modal-action-btn" id="btnDownloadPdf">📄 ดาวน์โหลด PDF</button>
      <button class="btn btn-accent modal-action-btn" id="btnPrintRecord">🖨️ พิมพ์</button>
    </div>
  </div>
</div>

<!-- ========== MODAL: PDF SETTINGS BEFORE PRINT ========== -->
<div class="modal-overlay" id="modal-pdf-preprint">
  <div class="modal modal-xl pdf-preprint-modal">
    <div class="modal-header">
      <h3>ตั้งค่า PDF ก่อนพิมพ์/ดาวน์โหลด</h3>
      <button class="modal-close" data-close="modal-pdf-preprint">✕</button>
    </div>
    <div class="modal-body pdf-preprint-body">
      <section class="pdf-preprint-preview">
        <div class="pdf-preprint-preview-header">
          <div class="pdf-preprint-toolbar">
            <label>
              <span>ฟอนต์เอกสาร</span>
              <select id="preprint-page-font" class="form-control">
                <option value="10">10 pt</option><option value="11">11 pt</option><option value="12">12 pt</option>
                <option value="13">13 pt</option><option value="14">14 pt</option><option value="15">15 pt</option>
                <option value="16" selected>16 pt</option><option value="17">17 pt</option><option value="18">18 pt</option>
                <option value="19">19 pt</option><option value="20">20 pt</option>
              </select>
            </label>
            <label>
              <span>บรรทัด</span>
              <select id="preprint-line-spacing" class="form-control">
                <option value="20">20 px</option><option value="22">22 px</option><option value="24">24 px</option>
                <option value="26" selected>26 px</option><option value="28">28 px</option><option value="30">30 px</option>
                <option value="32">32 px</option><option value="34">34 px</option><option value="36">36 px</option>
                <option value="38">38 px</option><option value="40">40 px</option>
              </select>
            </label>
            <label>
              <span>ขอบบน</span>
              <input type="number" id="preprint-margin-top" class="form-control" min="20" max="140" step="1" value="57">
            </label>
            <label>
              <span>ขอบขวา</span>
              <input type="number" id="preprint-margin-right" class="form-control" min="20" max="160" step="1" value="76">
            </label>
            <label>
              <span>ขอบล่าง</span>
              <input type="number" id="preprint-margin-bottom" class="form-control" min="20" max="140" step="1" value="38">
            </label>
            <label>
              <span>ขอบซ้าย</span>
              <input type="number" id="preprint-margin-left" class="form-control" min="20" max="160" step="1" value="94">
            </label>
            <label>
              <span>ฟอนต์ลายเซ็น</span>
              <select id="preprint-signature-font" class="form-control">
                <option value="10">10 pt</option><option value="11">11 pt</option><option value="12">12 pt</option>
                <option value="13">13 pt</option><option value="14">14 pt</option><option value="15">15 pt</option>
                <option value="16" selected>16 pt</option><option value="17">17 pt</option><option value="18">18 pt</option>
                <option value="19">19 pt</option><option value="20">20 pt</option>
              </select>
            </label>
            <label>
              <span>ฟอนต์รายชื่อ</span>
              <select id="preprint-attendance-font" class="form-control">
                <option value="10">10 pt</option><option value="11">11 pt</option><option value="12">12 pt</option>
                <option value="13">13 pt</option><option value="14">14 pt</option><option value="15">15 pt</option>
                <option value="16" selected>16 pt</option><option value="17">17 pt</option><option value="18">18 pt</option>
                <option value="19">19 pt</option><option value="20">20 pt</option>
              </select>
            </label>
            <label>
              <span>ผู้ลงนาม</span>
              <select id="preprint-signature-target" class="form-control">
                <option value="sig1">ผู้บันทึก</option>
                <option value="sig2">หัวหน้างาน</option>
                <option value="sig3">ผู้อำนวยการ</option>
              </select>
            </label>
            <label>
              <span>ภาพ</span>
              <select id="preprint-image-target" class="form-control">
                <option value="all">ทั้งหมด</option>
                <option value="1">รูปที่ 1</option>
                <option value="2">รูปที่ 2</option>
                <option value="3">รูปที่ 3</option>
                <option value="4">รูปที่ 4</option>
              </select>
            </label>
            <label>
              <span>ระยะรูป</span>
              <select id="preprint-image-gap" class="form-control">
                <option value="0">0 px</option><option value="8">8 px</option><option value="12">12 px</option>
                <option value="16" selected>16 px</option><option value="20">20 px</option><option value="24">24 px</option>
                <option value="32">32 px</option><option value="40">40 px</option><option value="56">56 px</option>
                <option value="72">72 px</option>
              </select>
            </label>
          </div>
          <div class="pdf-preprint-toggle-bar">
            <label class="pdf-preprint-toggle-label" title="เปิด-ปิดหน้าบัญชีลงเวลาผู้เรียนใน PDF">
              <input type="checkbox" id="preprint-show-attendance" checked>
              <span>📋 แสดงหน้าบัญชีลงเวลาผู้เรียน</span>
            </label>
          </div>
          <div class="pdf-preprint-preview-actions">
            <div class="pdf-preprint-tabs pdf-preprint-tabs--single" aria-label="สถานะหน้าตัวอย่าง PDF">
              <span class="pdf-preprint-tabs-label">แสดงทุกหน้า: บันทึกและรูปภาพ</span>
            </div>
            <button type="button" class="btn btn-outline" id="preprint-reset-layout">รีเซ็ตตำแหน่ง</button>
            <button type="button" class="btn btn-secondary" id="preprint-refresh-preview">ดูตัวอย่างใหม่</button>
          </div>
        </div>
        <div class="pdf-preprint-inspector">
          <span id="preprint-status">พร้อมตั้งค่า</span>
          <span>ลากบนหน้า PDF เพื่อเลื่อนตำแหน่ง</span>
          <span>จับปุ่มมุมขวาล่างของกรอบรูปเพื่อย่อ/ขยาย</span>
          <span id="preprint-page-font-value">16 pt</span>
          <span id="preprint-line-spacing-value">26 px</span>
          <span id="preprint-signature-font-value">16 pt</span>
          <span id="preprint-attendance-font-value">16 pt</span>
          <span id="preprint-margin-top-value">57 px</span>
          <span id="preprint-margin-right-value">76 px</span>
          <span id="preprint-margin-bottom-value">38 px</span>
          <span id="preprint-margin-left-value">94 px</span>
          <span id="preprint-signature-x-value">0 px</span>
          <span id="preprint-signature-y-value">0 px</span>
          <span id="preprint-image-width-value">300 px</span>
          <span id="preprint-image-height-value">225 px</span>
          <span id="preprint-image-x-value">0 px</span>
          <span id="preprint-image-y-value">0 px</span>
          <span id="preprint-image-gap-value">16 px</span>
        </div>
        <div class="pdf-preprint-hidden-controls" aria-hidden="true">
          <input type="number" id="preprint-signature-x" min="-220" max="220" step="1" value="0" tabindex="-1">
          <input type="number" id="preprint-signature-y" min="-220" max="220" step="1" value="0" tabindex="-1">
          <input type="number" id="preprint-image-width" min="180" max="620" step="5" value="300" tabindex="-1">
          <input type="number" id="preprint-image-height" min="120" max="560" step="5" value="225" tabindex="-1">
          <input type="number" id="preprint-image-x" min="-220" max="220" step="1" value="0" tabindex="-1">
          <input type="number" id="preprint-image-y" min="-220" max="220" step="1" value="0" tabindex="-1">
        </div>
        <div class="pdf-preprint-live-wrap" id="preprint-live-wrap">
          <div class="pdf-preprint-live-hint">ดับเบิลคลิกหัวข้อหรือข้อความในเอกสารเพื่อแก้ไขได้โดยตรง ตัวอย่างจะแยกเป็นหน้า A4 ชัดเจนเหมือนตอนสร้างไฟล์ และปุ่มพิมพ์จะเปิดหน้าต่างพิมพ์โดยไม่ดาวน์โหลด</div>
          <div class="pdf-preprint-live-scroll" id="preprint-live-preview" aria-label="แบบจำลองหน้า PDF จริง">
            <iframe id="preprint-live-frame" title="ตัวอย่างตำแหน่ง PDF แบบแก้ไขสด"></iframe>
            <div class="preprint-pdf-canvas-preview" id="preprint-pdf-canvas-preview"></div>
          </div>
        </div>
        <div class="pdf-preprint-pdf-wrap" id="preprint-pdf-wrap" hidden>
          <iframe id="preprint-pdf-frame" title="ตัวอย่างก่อนดาวน์โหลด"></iframe>
        </div>
      </section>
    </div>
    <div class="modal-footer pdf-preprint-footer">
      <button class="btn btn-outline" data-close="modal-pdf-preprint">ยกเลิก</button>
      <button class="btn btn-secondary" id="preprint-save-settings">บันทึกค่า</button>
      <button class="btn btn-primary" id="preprint-confirm-print">บันทึกและดาวน์โหลด PDF</button>
    </div>
  </div>
</div>

<!-- ========== MODAL: STUDENT ANALYSIS DETAIL ========== -->
<div class="modal-overlay" id="modal-analysis-detail">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h3 id="analysis-detail-title">รายละเอียดรายบุคคล</h3>
      <button class="modal-close" data-close="modal-analysis-detail">✕</button>
    </div>
    <div class="modal-body">
      <div class="analysis-student-summary" id="analysis-detail-summary"></div>
      <div class="table-responsive">
        <table>
          <thead>
            <tr><th>สัปดาห์</th><th>วันที่</th><th>รายวิชา</th><th>กลุ่มเรียน</th><th>สถานะ</th><th>วิธีเรียน</th></tr>
          </thead>
          <tbody id="analysis-detail-tbody"></tbody>
        </table>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" data-close="modal-analysis-detail">ปิด</button>
    </div>
  </div>
</div>

<div class="modal-overlay modal-overlay--image-viewer" id="modal-image-viewer">
  <div class="image-viewer-shell">
    <button class="image-viewer-close" data-close="modal-image-viewer" aria-label="ปิดการดูรูป">✕</button>
    <img id="image-viewer-img" src="" alt="ภาพขยาย">
  </div>
</div>

<!-- ========== MODAL: ADD ADMIN ========== -->
<div class="modal-overlay" id="modal-admin">
  <div class="modal">
    <div class="modal-header">
      <h3 id="modal-admin-title">👤 เพิ่มผู้ดูแลระบบ</h3>
      <button class="modal-close" data-close="modal-admin">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="admin-id">
      <div class="form-group">
        <label>ชื่อ-นามสกุล</label>
        <input type="text" id="admin-name" placeholder="ชื่อ-นามสกุล">
      </div>
      <div class="form-group">
        <label>ชื่อผู้ใช้</label>
        <input type="text" id="admin-username" placeholder="ชื่อผู้ใช้ (ภาษาอังกฤษ/ตัวเลข)">
      </div>
      <div class="form-group">
        <label>รหัสผ่าน</label>
        <input type="password" id="admin-password" placeholder="รหัสผ่าน">
      </div>
      <div class="form-group">
        <label>บทบาท</label>
        <select id="admin-role">
          <option value="tambon">ผู้ดูแลตำบล</option>
          <option value="district">ผู้ดูแลอำเภอ</option>
        </select>
      </div>
      <div class="form-group">
        <label id="admin-tambon-label">ตำบล</label>
        <select id="admin-tambon">
          <option value="">-- เลือกตำบล --</option>
        </select>
        <small id="admin-tambon-help" style="display:block;margin-top:6px;color:#7a8699;">เลือกเฉพาะกรณีเป็นผู้ดูแลระดับตำบล</small>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" data-close="modal-admin">ยกเลิก</button>
      <button class="btn btn-primary" id="saveAdminBtn">💾 บันทึก</button>
    </div>
  </div>
</div>

<!-- ========== MODAL: MANAGE TAMBON ========== -->
<div class="modal-overlay" id="modal-manage-tambon">
  <div class="modal">
    <div class="modal-header">
      <h3 id="modal-tambon-title">เพิ่มกลุ่มเรียน</h3>
      <button class="modal-close" data-close="modal-manage-tambon">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="manage-tambon-id">
      <div class="form-group">
        <label>ชื่อกลุ่มเรียน</label>
        <input type="text" id="manage-tambon-name" placeholder="ชื่อกลุ่มเรียน">
      </div>
      <div class="form-group">
        <label>ไอคอน (Emoji/ข้อความสั้นๆ)</label>
        <input type="text" id="manage-tambon-icon" placeholder="เช่น 🏘️">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" data-close="modal-manage-tambon">ยกเลิก</button>
      <button class="btn btn-primary" id="saveTambonBtn">💾 บันทึก</button>
    </div>
  </div>
</div>

<!-- Global Loader -->
<div id="global-loader">
  <div class="spinner"></div>
  <p>กำลังบันทึกข้อมูล กรุณารอสักครู่...</p>
</div>

<!-- Toast container -->
<div class="toast-container" id="toast-container"></div>

<!-- Check initial auth state injected from session -->
<script>
  window.AUTH_USER = <?php echo json_encode($authenticatedUser); ?>;
  window.AUTH_PRINT_TOKEN = <?php echo json_encode($printAccessToken); ?>;
  window.APP_LOGO_PATH = <?php echo json_encode($appLogoPath); ?>;
</script>
<script src="node_modules/html2canvas/dist/html2canvas.min.js"></script>
<script src="node_modules/jspdf/dist/jspdf.umd.min.js"></script>
<script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
</body>
</html>
