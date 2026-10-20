// ============================================================
//  CONSTANTS & STATE
// ============================================================
window.process = window.process || { env: {} };
window.process.env = window.process.env || {};
window.process.env.NODE_ENV = window.process.env.NODE_ENV || 'production';
var process = window.process;

let TAMBONS = [];
let TAMBON_MAP = new Map();

let currentUser = window.AUTH_USER;
let appLogoPath = window.APP_LOGO_PATH || '';
let currentImages = [];
let editingRecordId = null;
let recordsCache = new Map();
let recordsMetaCache = new Map();
let dashboardState = null;
let lastAllRecordsView = [];
let lastMyRecordsView = [];
let selectedRecordIds = new Set();
let selectedMyRecordIds = new Set();
let studentGroupsCache = new Map();
let studentGroupsByKey = new Map();
let currentStudentRoster = [];
let studentAnalysisState = {
  tab: 'classes',
  classOptions: [],
  students: []
};
let studentAnalysisRequestId = 0;
const ALLOWED_RECORD_IMAGE_COUNTS = [2, 4];
const MAX_RECORD_IMAGES = 4;
const loginPasswordInput = document.getElementById('password');
const loginPasswordToggle = document.getElementById('toggle-password');
const FLOW_PDF_LAYOUT = {
  sig1_align: 'flex-end',
  sig1_dot_width: '180',
  sig1_mt: '48',
  sig1_offset: '0',
  sig1_offset_y: '0',
  sig2_align: 'flex-end',
  sig2_dot_width: '180',
  sig2_mt: '40',
  sig2_offset: '0',
  sig2_offset_y: '0',
  sig2_block_offset_x: '0',
  sig2_block_offset_y: '0',
  sig3_align: 'center',
  sig3_dot_width: '200',
  sig3_mt: '38',
  sig3_offset: '0',
  sig3_offset_y: '0',
  sig3_block_offset_x: '0',
  sig3_block_offset_y: '0'
};
const PRINT_PREVIEW_BLOCK_CONFIG = {
  sig1: {
    label: 'ผู้บันทึก',
    elementId: 'print-preview-sig1',
    wrapId: 'print-preview-sig1-wrap',
    dotId: 'print-preview-sig1-dot',
    xInput: 'set_sig1_offset',
    yInput: 'set_sig1_offset_y',
    marginInput: 'set_sig1_mt',
    alignInput: 'set_sig1_align',
    type: 'signature'
  },
  sig1_line: {
    label: 'ผู้บันทึก: แถว (ลงชื่อ) + เส้น',
    elementId: 'print-preview-sig1-line',
    xInput: 'set_sig1_line_offset_x',
    yInput: 'set_sig1_line_offset_y',
    type: 'signature_part',
    parentBlock: 'sig1'
  },
  sig1_sign_prefix: {
    label: 'ผู้บันทึก: คำว่า (ลงชื่อ)',
    elementId: 'print-preview-sig1-sign-prefix',
    xInput: 'set_sig1_sign_prefix_offset_x',
    yInput: 'set_sig1_sign_prefix_offset_y',
    type: 'signature_part',
    parentBlock: 'sig1_line'
  },
  sig1_name: {
    label: 'ผู้บันทึก: ชื่อ',
    elementId: 'print-preview-sig1-name-row',
    xInput: 'set_sig1_name_offset_x',
    yInput: 'set_sig1_name_offset_y',
    type: 'signature_part',
    parentBlock: 'sig1'
  },
  sig1_pos: {
    label: 'ผู้บันทึก: ตำแหน่ง',
    elementId: 'print-preview-sig1-pos-row',
    xInput: 'set_sig1_pos_offset_x',
    yInput: 'set_sig1_pos_offset_y',
    type: 'signature_part',
    parentBlock: 'sig1'
  },
  sig2_block: {
    label: 'บล็อกหัวหน้างาน',
    elementId: 'print-preview-sig2-block',
    xInput: 'set_sig2_block_offset_x',
    yInput: 'set_sig2_block_offset_y',
    type: 'section',
    preserveWidth: true,
    districtOnly: true
  },
  sig2_title: {
    label: 'หัวหน้างาน: หัวข้อความคิดเห็น',
    elementId: 'print-preview-sig2-title-text',
    xInput: 'set_sig2_title_offset_x',
    yInput: 'set_sig2_title_offset_y',
    type: 'signature_part',
    parentBlock: 'sig2_block',
    districtOnly: true
  },
  sig2_signature: {
    label: 'ลายเซ็นหัวหน้างาน',
    elementId: 'print-preview-sig2-signature',
    wrapId: 'print-preview-sig2-signature-wrap',
    dotId: 'print-preview-sig2-dot',
    xInput: 'set_sig2_offset',
    yInput: 'set_sig2_offset_y',
    marginInput: 'set_sig2_mt',
    alignInput: 'set_sig2_align',
    type: 'signature',
    districtOnly: true
  },
  sig2_line: {
    label: 'หัวหน้างาน: แถว (ลงชื่อ) + เส้น',
    elementId: 'print-preview-sig2-line',
    xInput: 'set_sig2_line_offset_x',
    yInput: 'set_sig2_line_offset_y',
    type: 'signature_part',
    parentBlock: 'sig2_signature',
    districtOnly: true
  },
  sig2_sign_label: {
    label: 'หัวหน้างาน: คำว่า (ลงชื่อ)',
    elementId: 'print-preview-sig2-sign-prefix',
    xInput: 'set_sig2_sign_label_offset_x',
    yInput: 'set_sig2_sign_label_offset_y',
    type: 'signature_part',
    parentBlock: 'sig2_line',
    districtOnly: true
  },
  sig2_name: {
    label: 'หัวหน้างาน: ชื่อ',
    elementId: 'print-preview-sig2-name-row',
    xInput: 'set_sig2_name_offset_x',
    yInput: 'set_sig2_name_offset_y',
    type: 'signature_part',
    parentBlock: 'sig2_signature',
    districtOnly: true
  },
  sig2_pos: {
    label: 'หัวหน้างาน: ตำแหน่ง',
    elementId: 'print-preview-sig2-pos-row',
    xInput: 'set_sig2_pos_offset_x',
    yInput: 'set_sig2_pos_offset_y',
    type: 'signature_part',
    parentBlock: 'sig2_signature',
    districtOnly: true
  },
  sig3_block: {
    label: 'บล็อกผู้อำนวยการ',
    elementId: 'print-preview-sig3-block',
    xInput: 'set_sig3_block_offset_x',
    yInput: 'set_sig3_block_offset_y',
    type: 'section',
    preserveWidth: true,
    districtOnly: true
  },
  sig3_title: {
    label: 'ผู้อำนวยการ: หัวข้อความคิดเห็น',
    elementId: 'print-preview-sig3-title-text',
    xInput: 'set_sig3_title_offset_x',
    yInput: 'set_sig3_title_offset_y',
    type: 'signature_part',
    parentBlock: 'sig3_block',
    districtOnly: true
  },
  sig3_signature: {
    label: 'ลายเซ็นผู้อำนวยการ',
    elementId: 'print-preview-sig3',
    wrapId: 'print-preview-sig3-wrap',
    dotId: 'print-preview-sig3-dot',
    xInput: 'set_sig3_offset',
    yInput: 'set_sig3_offset_y',
    marginInput: 'set_sig3_mt',
    alignInput: 'set_sig3_align',
    type: 'signature',
    districtOnly: true
  },
  sig3_line: {
    label: 'ผู้อำนวยการ: แถว (ลงชื่อ) + เส้น',
    elementId: 'print-preview-sig3-line',
    xInput: 'set_sig3_line_offset_x',
    yInput: 'set_sig3_line_offset_y',
    type: 'signature_part',
    parentBlock: 'sig3_signature',
    districtOnly: true
  },
  sig3_sign_label: {
    label: 'ผู้อำนวยการ: คำว่า (ลงชื่อ)',
    elementId: 'print-preview-sig3-sign-prefix',
    xInput: 'set_sig3_sign_label_offset_x',
    yInput: 'set_sig3_sign_label_offset_y',
    type: 'signature_part',
    parentBlock: 'sig3_line',
    districtOnly: true
  },
  sig3_name: {
    label: 'ผู้อำนวยการ: ชื่อ',
    elementId: 'print-preview-sig3-name-row',
    xInput: 'set_sig3_name_offset_x',
    yInput: 'set_sig3_name_offset_y',
    type: 'signature_part',
    parentBlock: 'sig3_signature',
    districtOnly: true
  },
  sig3_pos: {
    label: 'ผู้อำนวยการ: ตำแหน่ง',
    elementId: 'print-preview-sig3-pos-row',
    xInput: 'set_sig3_pos_offset_x',
    yInput: 'set_sig3_pos_offset_y',
    type: 'signature_part',
    parentBlock: 'sig3_signature',
    districtOnly: true
  }
};
let selectedPrintPreviewBlock = 'sig1';
let activePrintPreviewDrag = null;
let printPreviewAbsoluteState = {};
let printSettingsPdfPreviewTimer = null;
let printSettingsPdfPreviewUrl = null;
let printSettingsPdfPreviewController = null;
let tanstackQueryClient = null;
let tanstackQueryClientPromise = null;
const APP_SCRIPT_URL = document.currentScript?.src || window.location.href;
const TANSTACK_QUERY_CORE_URL = new URL('../../node_modules/@tanstack/query-core/build/modern/index.js', APP_SCRIPT_URL).href;

function getTanstackQueryClient() {
  if (tanstackQueryClient) return Promise.resolve(tanstackQueryClient);
  if (!tanstackQueryClientPromise) {
    if (!globalThis.process) {
      globalThis.process = { env: { NODE_ENV: 'production' } };
    } else if (!globalThis.process.env) {
      globalThis.process.env = { NODE_ENV: 'production' };
    } else if (!globalThis.process.env.NODE_ENV) {
      globalThis.process.env.NODE_ENV = 'production';
    }
    tanstackQueryClientPromise = import(TANSTACK_QUERY_CORE_URL)
      .then(({ QueryClient }) => {
        tanstackQueryClient = new QueryClient({
          defaultOptions: {
            queries: {
              staleTime: 30000,
              gcTime: 5 * 60 * 1000,
              retry: 1,
              refetchOnWindowFocus: false
            }
          }
        });
        return tanstackQueryClient;
      })
      .catch(error => {
        console.warn('TanStack Query Core unavailable, using local cache fallback', error);
        tanstackQueryClientPromise = null;
        return null;
      });
  }
  return tanstackQueryClientPromise;
}

async function queryCached(queryKey, queryFn, options = {}) {
  const client = await getTanstackQueryClient();
  if (!client) return queryFn();
  return client.fetchQuery({
    queryKey,
    queryFn,
    staleTime: options.staleTime ?? 30000,
    gcTime: options.gcTime ?? 5 * 60 * 1000
  });
}

function invalidateQueryCache() {
  if (!tanstackQueryClient) return;
  tanstackQueryClient.invalidateQueries({ queryKey: ['records'] });
  tanstackQueryClient.invalidateQueries({ queryKey: ['dashboard'] });
  tanstackQueryClient.invalidateQueries({ queryKey: ['attendance-report'] });
}

// ============================================================
//  API CLIENT
// ============================================================
async function apiCall(endpoint, method = 'GET', body = null, silent = false) {
  const options = { method, headers: {} };
  if (body) {
    if (body instanceof FormData) {
      options.body = body;
    } else {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(body);
    }
  }
  
  try {
    const res = await fetch(`api/${endpoint}`, options);
    const json = await res.json();
    if (json.status === 'error' && json.message === 'Unauthorized') {
      doLogout(false);
      return null;
    }
    return json;
  } catch (err) {
    console.error(err);
    if (!silent) {
      showToast('❌ ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้', 'error');
    }
    return null;
  }
}

// ============================================================
//  AUTH
// ============================================================
async function doLogin() {
  const u = document.getElementById('username').value.trim();
  const p = document.getElementById('password').value;
  
  if(!u || !p) {
    document.getElementById('login-error').style.display = 'block';
    return;
  }
  
  const res = await apiCall('auth.php?action=login', 'POST', { username: u, password: p });
  if (res && res.status === 'success') {
    currentUser = res.data.user;
    window.AUTH_USER = currentUser;
    window.AUTH_PRINT_TOKEN = res.data.print_token || null;
    document.getElementById('login-error').style.display = 'none';
    startApp();
  } else if (res) {
    document.getElementById('login-error').textContent = res.message;
    document.getElementById('login-error').style.display = 'block';
  }
}

async function doLogout(req = true) {
  if (req) {
    await apiCall('auth.php?action=logout', 'POST');
  }
  invalidateRecordsCache();
  // Drop user-scoped analysis state before returning to login. This prevents
  // a second user in the same browser from seeing the previous roster.
  studentGroupsCache.clear();
  studentGroupsByKey.clear();
  currentStudentRoster = [];
  studentAnalysisState = { tab: 'classes', classOptions: [], students: [] };
  document.querySelectorAll('.modal-overlay.open').forEach(modal => modal.classList.remove('open'));
  document.querySelector('.sidebar')?.classList.remove('open');
  document.getElementById('sidebarOverlay')?.classList.remove('show');
  document.body.classList.remove('modal-open');
  currentUser = null;
  window.AUTH_USER = null;
  applyAppLogo(appLogoPath);
  window.AUTH_PRINT_TOKEN = null;
  document.getElementById('app').style.display = 'none';
  document.getElementById('login-page').style.display = 'flex';
  document.getElementById('username').value = '';
  document.getElementById('password').value = '';
  if (loginPasswordInput) {
    loginPasswordInput.type = 'password';
    syncLoginPasswordToggle();
  }
}

function syncLoginPasswordToggle() {
  if (!loginPasswordInput || !loginPasswordToggle) return;

  const isVisible = loginPasswordInput.type === 'text';
  const label = isVisible ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน';

  loginPasswordToggle.setAttribute('aria-label', label);
  loginPasswordToggle.setAttribute('aria-pressed', isVisible ? 'true' : 'false');
  loginPasswordToggle.setAttribute('title', label);
  loginPasswordToggle.classList.toggle('is-visible', isVisible);
  loginPasswordToggle.querySelector('.password-toggle-icon').textContent = isVisible ? '🙈' : '👁';
}

if (loginPasswordInput) {
  loginPasswordInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') doLogin();
  });
}
if (loginPasswordToggle && loginPasswordInput) {
  syncLoginPasswordToggle();
  loginPasswordToggle.addEventListener('click', () => {
    loginPasswordInput.type = loginPasswordInput.type === 'password' ? 'text' : 'password';
    syncLoginPasswordToggle();
    loginPasswordInput.focus({ preventScroll: true });
  });
}
if (document.getElementById('doLoginBtn')) {
    document.getElementById('doLoginBtn').addEventListener('click', doLogin);
}
if (document.getElementById('doLogoutBtn')) {
    document.getElementById('doLogoutBtn').addEventListener('click', () => doLogout());
}

// mobile menu
if (document.getElementById('mobileMenuBtn')) {
  document.getElementById('mobileMenuBtn').addEventListener('click', () => {
    document.querySelector('.sidebar')?.classList.add('open');
    document.getElementById('sidebarOverlay')?.classList.add('show');
  });
}
if (document.getElementById('sidebarOverlay')) {
  document.getElementById('sidebarOverlay').addEventListener('click', () => {
    document.querySelector('.sidebar')?.classList.remove('open');
    document.getElementById('sidebarOverlay')?.classList.remove('show');
  });
}

// ============================================================
//  APP INIT
// ============================================================
async function loadTambons() {
  const res = await queryCached(['tambons'], () => apiCall('tambons.php'), {
    staleTime: 10 * 60 * 1000,
    gcTime: 30 * 60 * 1000
  });
  if(res && res.status === 'success') {
      TAMBONS = res.data;
      TAMBON_MAP = new Map(TAMBONS.map(t => [t.id, t]));
  }
}

function getTambonById(id) {
  return TAMBON_MAP.get(id);
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function escapeJsString(value) {
  return String(value ?? '')
    .replace(/\\/g, '\\\\')
    .replace(/'/g, "\\'")
    .replace(/\r/g, '')
    .replace(/\n/g, '\\n');
}

function appIcon(name) {
  const icons = {
    home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h5v-6h4v6h5V9.5"/>',
    file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h6"/>',
    editFile: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 17l1.4-.3 5.3-5.3-1.1-1.1-5.3 5.3z"/>',
    plus: '<circle cx="12" cy="12" r="9"/><path d="M12 8v8"/><path d="M8 12h8"/>',
    plusCircle: '<circle cx="12" cy="12" r="9"/><path d="M12 8v8"/><path d="M8 12h8"/>',
    download: '<path d="M12 3v11"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
    chart: '<path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 16v-5"/><path d="M12 16V8"/><path d="M16 16v-9"/>',
    pdf: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M7 15h10"/><path d="M7 18h7"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.1 2.1-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V20h-3v-.2a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1-2.1-2.1.1-.1A1.7 1.7 0 0 0 5 15a1.7 1.7 0 0 0-1.5-1H3v-3h.5A1.7 1.7 0 0 0 5 10a1.7 1.7 0 0 0-.3-1.9l-.1-.1 2.1-2.1.1.1A1.7 1.7 0 0 0 8.7 6a1.7 1.7 0 0 0 1-1.5V4h3v.5a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1 2.1 2.1-.1.1A1.7 1.7 0 0 0 19 10a1.7 1.7 0 0 0 1.5 1h.5v3h-.5a1.7 1.7 0 0 0-1.1 1z"/>',
    users: '<path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>',
    groups: '<path d="M3 11 12 4l9 7"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/>',
    calendar: '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4"/><path d="M16 2v4"/><path d="M3 10h18"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/>',
    building: '<path d="M4 21V7l8-4 8 4v14"/><path d="M9 21v-6h6v6"/><path d="M8 9h.01"/><path d="M12 9h.01"/><path d="M16 9h.01"/><path d="M8 13h.01"/><path d="M12 13h.01"/><path d="M16 13h.01"/>'
  };
  return `<svg class="app-icon" viewBox="0 0 24 24" aria-hidden="true">${icons[name] || icons.file}</svg>`;
}

function resolveImageSrc(src) {
  const raw = String(src ?? '').trim();
  if (!raw) return '';
  if (/^(data:|blob:|https?:\/\/)/i.test(raw)) return raw;
  return raw.replace(/^\/+/, '');
}

function markMissingImage(imgEl) {
  const item = imgEl?.closest?.('.image-preview-item');
  if (!item) return;
  item.classList.add('image-preview-item--missing');
  if (!item.querySelector('.image-missing-label')) {
    item.insertAdjacentHTML('beforeend', '<div class="image-missing-label">ไม่พบไฟล์รูป</div>');
  }
}
window.markMissingImage = markMissingImage;

function buildRecordsCacheKey(tambon = '', search = '') {
  return JSON.stringify({
    role: currentUser?.role || '',
    userId: currentUser?.id || '',
    userTambon: currentUser?.tambon || '',
    tambon: tambon || '',
    search: (search || '').trim().toLowerCase()
  });
}

function invalidateRecordsCache() {
  recordsCache.clear();
  recordsMetaCache.clear();
  dashboardState = null;
  selectedRecordIds.clear();
  selectedMyRecordIds.clear();
  invalidateQueryCache();
}

function debounce(fn, delay = 250) {
  let timerId = null;
  return (...args) => {
    window.clearTimeout(timerId);
    timerId = window.setTimeout(() => fn(...args), delay);
  };
}

function isLikelyMobileDevice() {
  return window.matchMedia('(max-width: 700px)').matches
    || /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent || '');
}

function buildPrintUrl(recordId, options = {}) {
  const params = new URLSearchParams({ id: String(recordId) });
  if (window.AUTH_PRINT_TOKEN) params.set('access_token', window.AUTH_PRINT_TOKEN);
  return `print.php?${params.toString()}`;
}

const CLIENT_PDF_PAGE_MM = { width: 210, height: 297 };
const CLIENT_PDF_RENDER_SCALE = 3;
const CLIENT_PDF_CONTINUATION_TOP_MM = 10;
const CLIENT_PDF_CONTINUATION_BOTTOM_MM = 6;
const PDF_EXPORT_FONT_FAMILIES = ['THSarabunNew', 'Sarabun'];
const PDF_EXPORT_FONT_WEIGHTS = ['400', '700'];
const PDF_EXPORT_FONT_SIZES_PT = [10, 12, 14, 16, 18, 20, 22];
const pdfPreviewReadyDocs = new WeakSet();

function getPrintHtmlPreviewUrl(recordId, options = {}) {
  const url = new URL(buildPrintUrl(recordId), window.location.href);
  url.searchParams.set('html_preview', '1');
  if (options.settingsPreview) url.searchParams.set('settings_preview', '1');
  if (options.pdfExport) url.searchParams.set('pdf_export', '1');
  
  let hideAttendance = options.hideAttendance;
  if (hideAttendance === undefined) {
    const cb = document.getElementById('preprint-show-attendance');
    if (cb) hideAttendance = !cb.checked;
  }
  if (hideAttendance) url.searchParams.set('hide_attendance', '1');
  
  url.searchParams.delete('download');
  return url.toString();
}

function getClientPdfLibraries() {
  const html2canvasLib = window.html2canvas;
  const jsPdfCtor = window.jspdf?.jsPDF || window.jsPDF;
  if (!html2canvasLib || !jsPdfCtor) {
    throw new Error('PDF browser libraries are not loaded');
  }
  return { html2canvasLib, jsPdfCtor };
}

function sanitizePdfFileName(filename, fallback = 'sena_reflection.pdf') {
  const clean = String(filename || fallback)
    .replace(/[\\/:*?"<>|]+/g, '_')
    .replace(/\s+/g, ' ')
    .trim();
  return clean.toLowerCase().endsWith('.pdf') ? clean : `${clean || fallback}.pdf`;
}

function getPdfFilenameFromPreviewDocument(doc, recordId) {
  const title = doc?.title || '';
  return sanitizePdfFileName(title || `บันทึกหลังสอน_${recordId}.pdf`);
}

function getPdfFilenameFromDisposition(disposition, fallback) {
  const header = String(disposition || '');
  const utf8Match = header.match(/filename\*=UTF-8''([^;]+)/i);
  if (utf8Match) {
    try {
      return sanitizePdfFileName(decodeURIComponent(utf8Match[1]), fallback);
    } catch (error) {
      return sanitizePdfFileName(utf8Match[1], fallback);
    }
  }

  const filenameMatch = header.match(/filename="?([^";]+)"?/i);
  if (filenameMatch) {
    try {
      return sanitizePdfFileName(decodeURIComponent(filenameMatch[1]), fallback);
    } catch (error) {
      return sanitizePdfFileName(filenameMatch[1], fallback);
    }
  }

  return sanitizePdfFileName(fallback);
}

function sanitizeDownloadFileName(filename, fallback = 'download', extension = '') {
  let clean = String(filename || fallback)
    .replace(/[\\/:*?"<>|]+/g, '_')
    .replace(/\s+/g, ' ')
    .trim();
  clean = clean || fallback;
  const ext = String(extension || '').replace(/^\./, '');
  if (ext && !clean.toLowerCase().endsWith(`.${ext.toLowerCase()}`)) {
    clean = `${clean.replace(/\.[^.]+$/, '')}.${ext}`;
  }
  return clean;
}

function getFilenameFromDisposition(disposition, fallback, extension = '') {
  const header = String(disposition || '');
  const utf8Match = header.match(/filename\*=UTF-8''([^;]+)/i);
  if (utf8Match) {
    try {
      return sanitizeDownloadFileName(decodeURIComponent(utf8Match[1]), fallback, extension);
    } catch (error) {
      return sanitizeDownloadFileName(utf8Match[1], fallback, extension);
    }
  }

  const filenameMatch = header.match(/filename="?([^";]+)"?/i);
  if (filenameMatch) {
    try {
      return sanitizeDownloadFileName(decodeURIComponent(filenameMatch[1]), fallback, extension);
    } catch (error) {
      return sanitizeDownloadFileName(filenameMatch[1], fallback, extension);
    }
  }

  return sanitizeDownloadFileName(fallback, fallback, extension);
}

function saveBlobAsDownload(blob, filename) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.style.display = 'none';
  document.body.appendChild(link);
  link.click();
  window.setTimeout(() => {
    URL.revokeObjectURL(url);
    link.remove();
  }, 1500);
}

function waitForIframeLoad(frame) {
  return new Promise((resolve, reject) => {
    const timer = window.setTimeout(() => reject(new Error('HTML preview load timeout')), 20000);
    frame.addEventListener('load', () => {
      window.clearTimeout(timer);
      resolve();
    }, { once: true });
    frame.addEventListener('error', () => {
      window.clearTimeout(timer);
      reject(new Error('HTML preview load failed'));
    }, { once: true });
  });
}

async function waitForPreviewAssets(doc) {
  if (!doc) return;
  if (pdfPreviewReadyDocs.has(doc)) {
    await waitForPreviewPaint(doc, 1, 30);
    return;
  }
  await waitForPreviewFonts(doc);
  await decodePreviewImages(doc);
  await waitForPreviewPaint(doc);
  pdfPreviewReadyDocs.add(doc);
}

function waitForPreviewAnimationFrame(doc) {
  const view = doc?.defaultView || window;
  return new Promise(resolve => {
    if (view.requestAnimationFrame) {
      view.requestAnimationFrame(() => resolve());
    } else {
      window.setTimeout(resolve, 16);
    }
  });
}

async function waitForPreviewPaint(doc, frameCount = 3, settleMs = 120) {
  for (let i = 0; i < frameCount; i += 1) {
    await waitForPreviewAnimationFrame(doc);
  }
  if (settleMs > 0) {
    await new Promise(resolve => window.setTimeout(resolve, settleMs));
  }
}

function measurePreviewFontWidth(doc, fontFamilyCss) {
  if (!doc?.body) return 0;
  const probe = doc.createElement('span');
  probe.textContent = 'บันทึกหลังการสอนการเรียนรู้รายสัปดาห์ 2569';
  probe.style.cssText = [
    'position:absolute',
    'left:-10000px',
    'top:-10000px',
    'visibility:hidden',
    'white-space:nowrap',
    'font-size:20pt',
    'font-weight:700',
    `font-family:${fontFamilyCss}`
  ].join(';');
  doc.body.appendChild(probe);
  const width = probe.getBoundingClientRect().width;
  probe.remove();
  return width;
}

async function waitForPreviewFonts(doc, timeoutMs = 12000) {
  if (!doc?.fonts) {
    await waitForPreviewPaint(doc, 4);
    return;
  }

  const fontLoads = [];
  if (doc.fonts.load) {
    PDF_EXPORT_FONT_WEIGHTS.forEach(weight => {
      PDF_EXPORT_FONT_SIZES_PT.forEach(size => {
        PDF_EXPORT_FONT_FAMILIES.forEach(family => {
          fontLoads.push(doc.fonts.load(`${weight} ${size}pt "${family}"`).catch(() => []));
        });
      });
    });
  }

  const deadline = Date.now() + timeoutMs;
  if (fontLoads.length) {
    await Promise.race([
      Promise.all(fontLoads),
      new Promise(resolve => window.setTimeout(resolve, Math.min(6000, timeoutMs)))
    ]);
  }

  try {
    await Promise.race([
      doc.fonts.ready,
      new Promise(resolve => window.setTimeout(resolve, Math.max(1200, deadline - Date.now())))
    ]);
  } catch (error) {
    console.warn('Preview font readiness check failed', error);
  }

  while (Date.now() < deadline) {
    const sarabunReady = !doc.fonts.check || doc.fonts.check('700 20pt "THSarabunNew"') || doc.fonts.check('700 20pt "Sarabun"');
    const measured = measurePreviewFontWidth(doc, '"THSarabunNew", "Sarabun", sans-serif');
    const fallback = measurePreviewFontWidth(doc, 'sans-serif');
    const fontMetricsSettled = measured > 0 && Math.abs(measured - fallback) > 1;
    if (sarabunReady && fontMetricsSettled) {
      await waitForPreviewPaint(doc, 3);
      return;
    }
    await new Promise(resolve => window.setTimeout(resolve, 120));
  }

  await waitForPreviewPaint(doc, 5);
}

function getPreviewImages(target) {
  if (!target) return [];
  if (target.images) return [...target.images];
  return [...(target.querySelectorAll?.('img') || [])];
}

async function decodePreviewImages(target) {
  const images = getPreviewImages(target);
  if (!images.length) return;
  const imagePromises = images.map(async img => {
    if (img.complete && img.naturalWidth > 0) {
      if (img.decode) await img.decode().catch(() => {});
      return;
    }
    await new Promise(resolve => {
      const done = () => resolve();
      img.addEventListener('load', done, { once: true });
      img.addEventListener('error', done, { once: true });
    });
    if (img.decode) await img.decode().catch(() => {});
  });

  await Promise.race([
    Promise.all(imagePromises),
    new Promise(resolve => window.setTimeout(resolve, 12000))
  ]);
}

async function waitForStablePreviewLayout(doc) {
  if (!doc) return;
  const measure = () => {
    const pages = [...doc.querySelectorAll('.page')];
    return pages.map(page => [
      page.scrollWidth,
      page.scrollHeight,
      Math.round(page.getBoundingClientRect().width),
      Math.round(page.getBoundingClientRect().height)
    ].join(':')).join('|');
  };

  let last = '';
  let stableFrames = 0;
  const deadline = Date.now() + 6000;
  while (Date.now() < deadline && stableFrames < 3) {
    await waitForPreviewAnimationFrame(doc);
    const current = measure();
    if (current && current === last) {
      stableFrames += 1;
    } else {
      stableFrames = 0;
      last = current;
    }
  }
}

function blobToDataUrl(blob) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = () => reject(reader.error || new Error('Image read failed'));
    reader.readAsDataURL(blob);
  });
}

async function inlinePreviewImagesForCanvas(doc) {
  const images = [...doc.images].filter(img => {
    const src = img.getAttribute('src') || '';
    if (!src || src.startsWith('data:') || src.startsWith('blob:')) return false;
    try {
      const imageUrl = new URL(img.src, doc.baseURI || window.location.href);
      return imageUrl.origin !== window.location.origin;
    } catch (error) {
      return true;
    }
  });

  await Promise.all(images.map(async img => {
    try {
      const response = await fetch(img.src, { credentials: 'same-origin', cache: 'force-cache' });
      if (!response.ok) throw new Error(`Image fetch failed: ${response.status}`);
      const blob = await response.blob();
      img.src = await blobToDataUrl(blob);
      if (img.decode) {
        await img.decode().catch(() => {});
      }
    } catch (error) {
      console.warn('Could not inline preview image for PDF export', img.src, error);
    }
  }));
}

function ensurePdfExportOverlay() {
  let overlay = document.getElementById('pdf-export-loading-overlay');
  if (overlay) return overlay;

  overlay = document.createElement('div');
  overlay.id = 'pdf-export-loading-overlay';
  overlay.setAttribute('role', 'status');
  overlay.setAttribute('aria-live', 'polite');
  overlay.innerHTML = `
    <div class="pdf-export-loading-card">
      <div class="pdf-export-loading-spinner"></div>
      <div class="pdf-export-loading-title">กำลังเตรียม PDF</div>
      <div class="pdf-export-loading-message">กรุณารอสักครู่...</div>
    </div>
  `;
  document.body.appendChild(overlay);
  return overlay;
}

function setPdfExportLoading(active, message = 'กรุณารอสักครู่...', title = 'กำลังเตรียม PDF') {
  const overlay = ensurePdfExportOverlay();
  overlay.querySelector('.pdf-export-loading-title').textContent = title;
  overlay.querySelector('.pdf-export-loading-message').textContent = message;
  overlay.classList.toggle('is-active', Boolean(active));
  document.body.classList.toggle('pdf-export-loading-active', Boolean(active));
}

function updatePdfExportLoading(message, title = null) {
  const overlay = ensurePdfExportOverlay();
  if (title) overlay.querySelector('.pdf-export-loading-title').textContent = title;
  overlay.querySelector('.pdf-export-loading-message').textContent = message;
}

function expandPreviewFrameForCanvas(frame, doc) {
  if (!frame || !doc) return;
  const width = Math.max(900, doc.documentElement.scrollWidth || 0, doc.body?.scrollWidth || 0);
  const height = Math.max(1300, doc.documentElement.scrollHeight || 0, doc.body?.scrollHeight || 0);
  frame.style.width = `${width + 40}px`;
  frame.style.height = `${height + 80}px`;
}

function findCanvasInkBounds(canvas) {
  const context = canvas.getContext('2d', { willReadFrequently: true });
  if (!context) return null;

  const { width, height } = canvas;
  const pixels = context.getImageData(0, 0, width, height).data;
  let minX = width;
  let minY = height;
  let maxX = -1;
  let maxY = -1;

  for (let y = 0; y < height; y += 2) {
    for (let x = 0; x < width; x += 2) {
      const index = (y * width + x) * 4;
      const alpha = pixels[index + 3];
      if (alpha < 12) continue;
      const red = pixels[index];
      const green = pixels[index + 1];
      const blue = pixels[index + 2];
      if (red > 246 && green > 246 && blue > 246) continue;
      minX = Math.min(minX, x);
      minY = Math.min(minY, y);
      maxX = Math.max(maxX, x);
      maxY = Math.max(maxY, y);
    }
  }

  return maxX >= 0 ? { minX, minY, maxX, maxY } : null;
}

function normalizeCanvasLeftMargin(canvas, pageElement, bounds = null) {
  bounds = bounds || findCanvasInkBounds(canvas);
  if (!bounds) return canvas;

  const pagePaddingLeft = Number(String(getComputedStyle(pageElement).paddingLeft || '0').replace('px', '')) || 0;
  const scale = canvas.width / Math.max(pageElement.getBoundingClientRect().width, 1);
  const targetMinX = Math.round(pagePaddingLeft * scale);

  if (!targetMinX || bounds.minX >= targetMinX * 0.65) {
    return canvas;
  }

  const shiftX = Math.min(
    targetMinX - bounds.minX,
    Math.max(0, canvas.width - bounds.maxX - 4)
  );
  if (shiftX <= 1) return canvas;

  const normalized = document.createElement('canvas');
  normalized.width = canvas.width;
  normalized.height = canvas.height;
  const context = normalized.getContext('2d', { alpha: false });
  context.fillStyle = '#ffffff';
  context.fillRect(0, 0, normalized.width, normalized.height);
  context.drawImage(canvas, shiftX, 0);
  return normalized;
}

function createClientPdfFrame() {
  const frame = document.createElement('iframe');
  frame.title = 'HTML preview for PDF export';
  frame.setAttribute('aria-hidden', 'true');
  frame.style.position = 'fixed';
  frame.style.left = '-10000px';
  frame.style.top = '0';
  frame.style.width = '900px';
  frame.style.height = '1300px';
  frame.style.border = '0';
  frame.style.opacity = '1';
  frame.style.pointerEvents = 'none';
  frame.style.zIndex = '2147483647';
  frame.style.background = '#fff';
  document.body.appendChild(frame);
  return frame;
}

async function renderPrintPageToCanvas(html2canvasLib, page, doc, pdfScale = CLIENT_PDF_RENDER_SCALE, options = {}) {
  const assetsReady = Boolean(options.assetsReady);
  if (!assetsReady) {
    await waitForPreviewAssets(doc);
    await waitForStablePreviewLayout(doc);
  }

  const renderHost = doc.createElement('div');
  renderHost.className = 'client-pdf-render-host';
  renderHost.style.cssText = [
    'position:fixed',
    'left:0',
    'top:0',
    'width:794px',
    'min-height:1300px',
    'padding:0',
    'margin:0',
    'background:#fff',
    'box-sizing:border-box',
    'z-index:2147483647',
    'opacity:1',
    'pointer-events:none'
  ].join(';');

  const pageClone = page.cloneNode(true);
  pageClone.classList.remove('preprint-html-hidden');
  pageClone.style.margin = '0';
  pageClone.style.visibility = 'visible';
  pageClone.style.opacity = '1';
  pageClone.style.width = '794px';
  pageClone.style.maxWidth = '794px';
  renderHost.appendChild(pageClone);
  doc.body.appendChild(renderHost);

  if (pageClone.querySelectorAll('img').length) {
    await decodePreviewImages(pageClone);
  }
  await waitForPreviewPaint(doc, 1, 30);

  const baseOptions = {
    backgroundColor: '#ffffff',
    scale: pdfScale,
    useCORS: true,
    allowTaint: false,
    logging: false,
    imageTimeout: 12000,
    y: 0,
    width: 794,
    height: Math.max(pageClone.scrollHeight, pageClone.getBoundingClientRect().height, 1123),
    windowWidth: 794,
    windowHeight: Math.max(pageClone.scrollHeight, pageClone.getBoundingClientRect().height, 1300),
    scrollX: 0,
    scrollY: 0,
    onclone: (clonedDoc) => {
      const style = clonedDoc.createElement('style');
      style.textContent = `
        html, body {
          margin: 0 !important;
          padding: 0 !important;
          background: #fff !important;
          font-synthesis: none !important;
          letter-spacing: 0 !important;
          -webkit-font-smoothing: antialiased !important;
          text-rendering: geometricPrecision !important;
        }
        .client-pdf-render-host {
          position: fixed !important;
          left: 0 !important;
          top: 0 !important;
          width: 794px !important;
          margin: 0 !important;
          padding: 0 !important;
          background: #fff !important;
          box-sizing: border-box !important;
          transform: none !important;
        }
        .page {
          margin: 0 !important;
          width: 794px !important;
          max-width: 794px !important;
          box-sizing: border-box !important;
          background: #fff !important;
          transform: none !important;
          font-synthesis: none !important;
          letter-spacing: 0 !important;
        }
        .page * {
          font-synthesis: none !important;
          letter-spacing: 0 !important;
        }
        .client-pdf-render-host,
        .client-pdf-render-host * {
          font-family: "THSarabunNew", "Sarabun", sans-serif !important;
        }
      `;
      clonedDoc.head.appendChild(style);
    }
  };

  try {
    await waitForPreviewPaint(doc, 2);
    let lastError = null;
    for (const foreignObjectRendering of [true, false]) {
      try {
        const canvas = await html2canvasLib(renderHost, {
          ...baseOptions,
          foreignObjectRendering
        });
        const bounds = findCanvasInkBounds(canvas);
        if (!bounds) {
          throw new Error(`PDF render produced a blank canvas (foreignObjectRendering=${foreignObjectRendering})`);
        }
        return normalizeCanvasLeftMargin(canvas, pageClone, bounds);
      } catch (error) {
        lastError = error;
        console.warn(
          foreignObjectRendering
            ? 'foreignObject PDF render failed, retrying with standard renderer'
            : 'standard PDF render failed',
          error
        );
      }
    }
    throw lastError || new Error('PDF render failed');
  } finally {
    renderHost.remove();
  }
}

function getPdfPageSliceHeightPx(canvas) {
  return Math.round(canvas.width * (CLIENT_PDF_PAGE_MM.height / CLIENT_PDF_PAGE_MM.width));
}

function mmToCanvasPx(canvas, mm) {
  return Math.round(canvas.width * (mm / CLIENT_PDF_PAGE_MM.width));
}

function collectPrintPaginationHints(page) {
  const pageRect = page.getBoundingClientRect();
  const pageHeight = Math.max(page.scrollHeight, pageRect.height);
  const cuts = new Set([0, pageHeight]);
  const keepWithNextRanges = [];
  const avoidCutRanges = [];
  let contentBottom = 0;
  const addCut = value => {
    const y = Math.round(Number(value) || 0);
    if (y > 0 && y < pageHeight) cuts.add(y);
  };
  const rememberContentBottom = value => {
    contentBottom = Math.max(contentBottom, Math.round(Number(value) || 0));
  };

  page.querySelectorAll([
    'h2',
    '.row-table',
    '.section-title',
    '.dotted-text-area',
    '.signature-flow-section',
    '.signature-flow-group',
    '.separator',
    '.single-line-dot',
    '.image-title',
    '.image-grid-two',
    '.image-grid-four',
    '.attendance-title',
    '.attendance-table'
  ].join(',')).forEach(el => {
    const rect = el.getBoundingClientRect();
    const top = rect.top - pageRect.top;
    const bottom = rect.bottom - pageRect.top;
    addCut(top);
    addCut(bottom);
    rememberContentBottom(bottom);
  });

  page.querySelectorAll('.dotted-text-area').forEach(area => {
    const rect = area.getBoundingClientRect();
    const top = rect.top - pageRect.top;
    const styleTarget = area.querySelector('.dotted-text-content') || area;
    const lineHeight = Number(String(getComputedStyle(styleTarget).lineHeight).replace('px', '')) || 26;
    if (!Number.isFinite(lineHeight) || lineHeight <= 0) return;
    const lineCount = Math.floor(rect.height / lineHeight);
    for (let line = 1; line <= lineCount; line += 1) {
      addCut(top + (line * lineHeight));
    }
  });

  page.querySelectorAll('.image-grid-two tr, .image-grid-four tr, .image-shell-two, .image-shell-four, .preprint-image-item').forEach(el => {
    const rect = el.getBoundingClientRect();
    const top = Math.round(rect.top - pageRect.top);
    const bottom = Math.round(rect.bottom - pageRect.top);
    addCut(top);
    addCut(bottom);
    rememberContentBottom(bottom);
    if (bottom - top > 40) {
      avoidCutRanges.push({ start: top, end: bottom });
    }
  });

  page.querySelectorAll('.signature-flow-group--keep, .signature-flow-section, .pdf-flow-group, .attendance-table').forEach(el => {
    const rect = el.getBoundingClientRect();
    const top = Math.round(rect.top - pageRect.top);
    const bottom = Math.round(rect.bottom - pageRect.top);
    const height = bottom - top;
    addCut(top);
    addCut(bottom);
    rememberContentBottom(bottom);
    if (height > 24 && height < pageHeight * 0.85) {
      avoidCutRanges.push({ start: top, end: bottom, keepWhole: true });
    }
  });

  page.querySelectorAll('.section-title').forEach(title => {
    const titleRect = title.getBoundingClientRect();
    const start = Math.round(titleRect.top - pageRect.top);
    const titleBottom = Math.round(titleRect.bottom - pageRect.top);
    let next = title.nextElementSibling;

    while (next && !next.matches('.dotted-text-area, .single-line-dot, .signature-flow-section, .signature-flow-group, .row-table, .attendance-table')) {
      next = next.nextElementSibling;
    }

    if (!next) return;

    const nextRect = next.getBoundingClientRect();
    const nextTop = Math.round(nextRect.top - pageRect.top);
    const nextBottom = Math.round(nextRect.bottom - pageRect.top);
    const textTarget = next.querySelector?.('.dotted-text-content') || next;
    const lineHeight = Number(String(getComputedStyle(textTarget).lineHeight).replace('px', '')) || 26;
    const firstReadableBlock = next.matches('.dotted-text-area')
      ? nextTop + Math.min(nextRect.height, Math.max(lineHeight * 2, 42))
      : Math.min(nextBottom, nextTop + 70);

    keepWithNextRanges.push({
      start,
      titleBottom,
      minEnd: Math.round(Math.max(titleBottom + 18, firstReadableBlock))
    });
  });

  const resolvedContentBottom = Math.min(pageHeight, Math.max(contentBottom + 8, 1));
  cuts.add(resolvedContentBottom);

  const forcedCuts = [];
  page.querySelectorAll('.manual-page-break').forEach(el => {
    const rect = el.getBoundingClientRect();
    const top = Math.round(rect.top - pageRect.top);
    if (top > 0) forcedCuts.push(top);
  });

  return {
    contentBottom: resolvedContentBottom,
    keepWithNextRanges,
    avoidCutRanges,
    safeCuts: [...cuts].filter(cut => cut <= resolvedContentBottom).sort((a, b) => a - b),
    forcedCuts: forcedCuts.sort((a, b) => a - b)
  };
}

function protectAvoidCutRanges(currentY, candidateY, maxY, avoidCutRanges = []) {
  for (const range of avoidCutRanges) {
    if (candidateY <= range.start + 2 || candidateY >= range.end - 2) continue;
    if (range.start - currentY > 70) return range.start;
    if (range.keepWhole && range.end < maxY - 2) return range.end;
    if (range.end < maxY - 2) return range.end;
  }
  return candidateY;
}

function protectKeepWithNextCut(currentY, candidateY, keepWithNextRanges) {
  for (const range of keepWithNextRanges) {
    if (range.start <= currentY + 8) continue;
    if (range.start >= candidateY) continue;
    if (candidateY < range.minEnd && range.start - currentY > 70) {
      return range.start;
    }
  }

  return candidateY;
}

function hasPrintablePageContent(page) {
  if (!page) return false;
  const text = (page.textContent || '').replace(/\s+/g, '');
  return text.length > 0 || !!page.querySelector('img, table, .dotted-text-area, .section-title, .signature-flow-group');
}

function chooseSafePrintCut(currentY, desiredY, maxY, safeCuts, keepWithNextRanges, avoidCutRanges = []) {
  if (desiredY >= maxY - 2) return maxY;

  const minProgress = Math.min(180, Math.max(70, (desiredY - currentY) * 0.35));
  const minY = currentY + minProgress;
  const backwardsTolerance = 140;
  let best = null;

  for (const cut of safeCuts) {
    if (cut <= minY || cut > desiredY) continue;
    best = cut;
  }

  if (best !== null && best >= desiredY - backwardsTolerance) {
    const kept = protectKeepWithNextCut(currentY, best, keepWithNextRanges);
    return protectAvoidCutRanges(currentY, kept, maxY, avoidCutRanges);
  }

  const kept = protectKeepWithNextCut(currentY, Math.max(minY, desiredY), keepWithNextRanges);
  return protectAvoidCutRanges(currentY, kept, maxY, avoidCutRanges);
}

function addCanvasAsA4Pages(pdf, canvas, pageElement, isFirstPage) {
  const pageSliceHeight = getPdfPageSliceHeightPx(canvas);
  const pagePaddingTopPx = parseFloat(getComputedStyle(pageElement).paddingTop) || 0;
  const pagePaddingBottomPx = parseFloat(getComputedStyle(pageElement).paddingBottom) || 0;
  const pagePaddingTopMm = pagePaddingTopPx * 25.4 / 96;
  const pagePaddingBottomMm = pagePaddingBottomPx * 25.4 / 96;
  const continuationTopMm = Math.max(CLIENT_PDF_CONTINUATION_TOP_MM, pagePaddingTopMm);
  const continuationBottomMm = Math.max(CLIENT_PDF_CONTINUATION_BOTTOM_MM, pagePaddingBottomMm);
  const continuationTop = mmToCanvasPx(canvas, continuationTopMm);
  const continuationBottom = mmToCanvasPx(canvas, continuationBottomMm);
  const scaleY = canvas.height / Math.max(pageElement.scrollHeight, pageElement.getBoundingClientRect().height, 1);
  const paginationHints = collectPrintPaginationHints(pageElement);
  const safeCuts = paginationHints.safeCuts;
  const sourceHeightCss = paginationHints.contentBottom;
  let sourceYCss = 0;
  let firstSliceForElement = true;
  const sliceCanvas = document.createElement('canvas');
  sliceCanvas.width = canvas.width;
  sliceCanvas.height = pageSliceHeight;
  const sliceContext = sliceCanvas.getContext('2d', { alpha: false });

  while (sourceYCss < sourceHeightCss - 1) {
    if (!firstSliceForElement && sourceHeightCss - sourceYCss < 24) {
      break;
    }

    const topPad = firstSliceForElement ? 0 : continuationTop;
    const availableCanvasHeight = pageSliceHeight - topPad - continuationBottom;
    const desiredCutCss = sourceYCss + (availableCanvasHeight / scaleY);
    let nextCutCss = desiredCutCss;

    // Check if there is a forced cut within this page slice
    const forcedCutInSlice = (paginationHints.forcedCuts || []).find(cut => cut > sourceYCss + 2 && cut <= desiredCutCss + 24);
    
    if (forcedCutInSlice) {
      nextCutCss = forcedCutInSlice;
    } else {
      nextCutCss = chooseSafePrintCut(
        sourceYCss,
        desiredCutCss,
        sourceHeightCss,
        safeCuts,
        paginationHints.keepWithNextRanges,
        paginationHints.avoidCutRanges
      );
    }

    if (nextCutCss <= sourceYCss + 2) {
      break;
    }

    const sourceY = Math.round(sourceYCss * scaleY);
    const sourceHeight = Math.max(1, Math.round((nextCutCss - sourceYCss) * scaleY));
    if (sourceHeight < 4) {
      break;
    }

    if (!isFirstPage) {
      pdf.addPage();
    }
    isFirstPage = false;

    sliceContext.fillStyle = '#ffffff';
    sliceContext.fillRect(0, 0, sliceCanvas.width, sliceCanvas.height);
    sliceContext.drawImage(
      canvas,
      0,
      sourceY,
      canvas.width,
      sourceHeight,
      0,
      topPad,
      canvas.width,
      sourceHeight
    );

    pdf.setFillColor(255, 255, 255);
    pdf.rect(0, 0, CLIENT_PDF_PAGE_MM.width, CLIENT_PDF_PAGE_MM.height, 'F');
    pdf.addImage(
      sliceCanvas,
      'PNG',
      0,
      0,
      CLIENT_PDF_PAGE_MM.width,
      CLIENT_PDF_PAGE_MM.height
    );

    sourceYCss = nextCutCss;
    firstSliceForElement = false;
  }

  return isFirstPage;
}

async function loadClientPdfPreviewFrame(frame, previewUrl, previewPayload = null) {
  const loaded = waitForIframeLoad(frame);
  if (previewPayload) {
    const res = await fetch(previewUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(previewPayload)
    });
    if (!res.ok) throw new Error(`HTML preview export failed: ${res.status}`);
    frame.srcdoc = await res.text();
  } else {
    frame.src = previewUrl;
  }
  await loaded;
}

function createHtmlPrintFrame() {
  const frame = document.createElement('iframe');
  frame.title = 'HTML print preview';
  frame.setAttribute('aria-hidden', 'true');
  frame.style.position = 'fixed';
  frame.style.left = '-10000px';
  frame.style.top = '0';
  frame.style.width = '794px';
  frame.style.height = '1123px';
  frame.style.border = '0';
  frame.style.opacity = '1';
  frame.style.pointerEvents = 'none';
  frame.style.background = '#fff';
  document.body.appendChild(frame);
  return frame;
}

async function loadHtmlPrintFrame(frame, recordId, previewPayload = null) {
  const previewUrl = getPrintHtmlPreviewUrl(recordId, {
    settingsPreview: Boolean(previewPayload),
    pdfExport: true
  });
  const loaded = waitForIframeLoad(frame);

  if (previewPayload) {
    const res = await fetch(previewUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(previewPayload)
    });
    if (!res.ok) throw new Error(`HTML print preview failed: ${res.status}`);
    frame.srcdoc = await res.text();
  } else {
    frame.src = previewUrl;
  }

  await loaded;
  const doc = frame.contentDocument || frame.contentWindow?.document;
  if (!doc) throw new Error('Cannot access HTML print document');

  await waitForPreviewAssets(doc);
  await waitForStablePreviewLayout(doc);
  await waitForPreviewPaint(doc, 4);
  return doc;
}

async function printHtmlPreviewForRecord(recordId, previewPayload = null) {
  const frame = createHtmlPrintFrame();
  let cleaned = false;
  const cleanup = () => {
    if (cleaned) return;
    cleaned = true;
    window.setTimeout(() => frame.remove(), 800);
  };

  try {
    await loadHtmlPrintFrame(frame, recordId, previewPayload);
    const printWindow = frame.contentWindow;
    if (!printWindow) throw new Error('Cannot access HTML print window');

    printWindow.addEventListener('afterprint', cleanup, { once: true });
    window.setTimeout(cleanup, 60000);
    printWindow.focus();
    printWindow.print();
  } catch (error) {
    cleanup();
    throw error;
  }
}

async function generateClientPdfObject(recordId, previewPayload = null, pdfScale = CLIENT_PDF_RENDER_SCALE) {
  const { html2canvasLib, jsPdfCtor } = getClientPdfLibraries();
  const frame = createClientPdfFrame();
  const previewUrl = getPrintHtmlPreviewUrl(recordId, {
    settingsPreview: Boolean(previewPayload),
    pdfExport: true
  });

  try {
    updatePdfExportLoading('กำลังโหลดหน้าเอกสาร...');
    await loadClientPdfPreviewFrame(frame, previewUrl, previewPayload);

    const doc = frame.contentDocument || frame.contentWindow?.document;
    if (!doc) {
      throw new Error('Cannot access HTML preview document');
    }

    updatePdfExportLoading('กำลังโหลดฟอนต์ไทยและรูปภาพ...');
    await waitForPreviewAssets(doc);
    await inlinePreviewImagesForCanvas(doc);
    expandPreviewFrameForCanvas(frame, doc);
    await waitForPreviewAssets(doc);
    await waitForStablePreviewLayout(doc);
    await waitForPreviewPaint(doc, 3);

    const pages = [...doc.querySelectorAll('.page')].filter(page => (
      (page.offsetParent !== null || page.offsetHeight > 0) && hasPrintablePageContent(page)
    ));
    if (!pages.length) {
      throw new Error('HTML preview has no printable pages');
    }

    const pdf = new jsPdfCtor({
      orientation: 'portrait',
      unit: 'mm',
      format: 'a4',
      compress: true
    });

    let isFirstPage = true;
    for (const [index, page] of pages.entries()) {
      updatePdfExportLoading(`กำลังสร้างหน้า ${index + 1} จาก ${pages.length}...`);
      const canvas = await renderPrintPageToCanvas(html2canvasLib, page, doc, pdfScale, { assetsReady: true });
      isFirstPage = addCanvasAsA4Pages(pdf, canvas, page, isFirstPage);
    }

    updatePdfExportLoading('กำลังรวมไฟล์ PDF...');
    return { pdf, doc, recordId };
  } finally {
    frame.remove();
  }
}

async function downloadClientPdfFromPrintPreview(recordId, previewPayload = null) {
  const { pdf, doc } = await generateClientPdfObject(recordId, previewPayload);
  updatePdfExportLoading('กำลังดาวน์โหลดไฟล์...');
  pdf.save(getPdfFilenameFromPreviewDocument(doc, recordId));
}

async function downloadServerPdfFromPrintPreview(recordId, previewPayload = null) {
  updatePdfExportLoading('กำลังสร้าง PDF ด้วยระบบพิมพ์จริง...');
  let url = `api/download_pdf.php?id=${encodeURIComponent(recordId)}`;
  const cb = document.getElementById('preprint-show-attendance');
  if (cb && !cb.checked) {
    url += '&hide_attendance=1';
  }
  const res = await fetch(url, {
    method: previewPayload ? 'POST' : 'GET',
    headers: previewPayload ? { 'Content-Type': 'application/json' } : {},
    credentials: 'same-origin',
    body: previewPayload ? JSON.stringify(previewPayload) : undefined
  });

  const contentType = res.headers.get('content-type') || '';
  if (!res.ok || !contentType.includes('application/pdf')) {
    let detail = '';
    try {
      const payload = await res.json();
      detail = payload?.message || payload?.detail || '';
    } catch (error) {
      detail = await res.text().catch(() => '');
    }
    throw new Error(detail || `Server PDF export failed: ${res.status}`);
  }

  const blob = await res.blob();
  const filename = getPdfFilenameFromDisposition(
    res.headers.get('content-disposition'),
    `บันทึกหลังสอน_${recordId}.pdf`
  );
  updatePdfExportLoading('กำลังดาวน์โหลดไฟล์...');
  saveBlobAsDownload(blob, filename);
}

async function downloadPdfForRecord(recordId, previewPayload = null) {
  showToast('กำลังสร้าง PDF กรุณารอสักครู่...', 'info');
  setPdfExportLoading(true, 'กำลังเตรียมหน้าเอกสาร...', 'กำลังสร้าง PDF');
  try {
    try {
      await downloadServerPdfFromPrintPreview(recordId, previewPayload);
    } catch (serverError) {
      console.warn('Server PDF export failed, falling back to browser export', serverError);
      updatePdfExportLoading('กำลังใช้วิธีสำรองในเบราว์เซอร์...');
      await downloadClientPdfFromPrintPreview(recordId, previewPayload);
    }
    showToast('สร้าง PDF สำเร็จ', 'success');
  } catch (error) {
    console.error('Client PDF export failed', error);
    showToast('สร้าง PDF ไม่สำเร็จ กรุณากดดูตัวอย่างใหม่แล้วลองดาวน์โหลดอีกครั้ง', 'error');
    throw error;
  } finally {
    setPdfExportLoading(false);
  }
}

async function printRecordFromPreview(recordId, previewPayload = null) {
  showToast('กำลังสร้างไฟล์สำหรับพิมพ์ กรุณารอสักครู่...', 'info');
  // Change button state if applicable
  const btn = document.getElementById('preprint-confirm-print');
  const oldText = btn ? btn.innerHTML : '';
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังสร้าง...';
  }

  setPdfExportLoading(true, 'กำลังโหลดฟอนต์และจัดหน้าให้ตรงกับเอกสาร...', 'กำลังเตรียมหน้าพิมพ์');
  try {
    updatePdfExportLoading('กำลังเปิดหน้าต่างพิมพ์...');
    await printHtmlPreviewForRecord(recordId, previewPayload);
    showToast('เปิดหน้าต่างพิมพ์แล้ว เลือก Save as PDF ได้เลย', 'success');
  } catch (error) {
    console.error('Client print failed', error);
    showToast('เตรียมพิมพ์ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง', 'error');
    throw error;
  } finally {
    setPdfExportLoading(false);
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = oldText;
    }
  }
}

const PDF_PREPRINT_DEFAULTS = {
  pageFont: 16,
  lineSpacing: 26,
  signatureFont: 16,
  attendanceFont: 16,
  marginTop: 57,
  marginRight: 76,
  marginBottom: 38,
  marginLeft: 94,
  signatureX: 0,
  signatureY: 0,
  imageWidth: 300,
  imageHeight: 225,
  imageX: 0,
  imageY: 0,
  imageGap: 16
};
const PDF_PREPRINT_PAGE = {
  width: 794,
  height: 1123,
  paddingTop: 57,
  paddingRight: 76,
  paddingBottom: 38,
  paddingLeft: 94,
  signatureWidth: 430
};
const PDF_PREPRINT_IMAGE_KEYS = ['image_width_px', 'image_height_px', 'image_offset_x', 'image_offset_y', 'image_gap_px'];
const PDF_PREPRINT_IMAGE_BASE_KEYS = ['width_px', 'height_px', 'offset_x', 'offset_y'];
const PDF_PREPRINT_DOCUMENT_DRAFT_KEYS = new Set([
  'record_result',
  'record_problem',
  'record_suggestion',
  'record_supervisor_comment',
  'record_section1_title',
  'record_section2_title',
  'record_section3_title'
]);
const PDF_PREPRINT_SIGNATURE_CONFIG = {
  sig1: { label: 'ผู้บันทึก', xKey: 'sig1_offset', yKey: 'sig1_offset_y', alignKey: 'sig1_align', mtKey: 'sig1_mt', dotKey: 'sig1_dot_width', defaultAlign: 'flex-end', defaultMt: 48, defaultDot: 180 },
  sig2: { label: 'หัวหน้างาน', xKey: 'sig2_offset', yKey: 'sig2_offset_y', alignKey: 'sig2_align', mtKey: 'sig2_mt', dotKey: 'sig2_dot_width', blockXKey: 'sig2_block_offset_x', blockYKey: 'sig2_block_offset_y', defaultAlign: 'flex-end', defaultMt: 40, defaultDot: 180 },
  sig3: { label: 'ผู้อำนวยการ', xKey: 'sig3_offset', yKey: 'sig3_offset_y', alignKey: 'sig3_align', mtKey: 'sig3_mt', dotKey: 'sig3_dot_width', blockXKey: 'sig3_block_offset_x', blockYKey: 'sig3_block_offset_y', defaultAlign: 'center', defaultMt: 38, defaultDot: 200, paperCenter: true }
};
let pdfPreprintState = {
  recordId: null,
  record: null,
  download: false,
  outputMode: 'download',
  previewPage: 'all',
  previewUrl: '',
  previewTimer: null,
  htmlPreviewTimer: null,
  previewController: null,
  htmlPreviewController: null,
  pdfPreviewDirty: false,
  pdfBlob: null,
  pdfRenderToken: 0,
  pdfPageTypes: [],
  loadedSettings: {},
  draftSettings: {},
  dirtyKeys: new Set(),
  initialized: false,
  drag: null
};

function clampNumber(value, min, max, fallback) {
  const num = Number(value);
  if (!Number.isFinite(num)) return fallback;
  return Math.max(min, Math.min(max, Math.round(num)));
}

function setPreprintValue(id, value) {
  const el = document.getElementById(id);
  if (!el) return;
  const resolved = String(value);
  if (el.tagName === 'SELECT' && !Array.from(el.options).some(option => option.value === resolved)) {
    const option = document.createElement('option');
    option.value = resolved;
    option.textContent = id.includes('font') ? `${resolved} pt` : `${resolved} px`;
    el.appendChild(option);
  }
  el.value = resolved;
}

function getPreprintValue(id, min, max, fallback) {
  return clampNumber(document.getElementById(id)?.value, min, max, fallback);
}

function getPdfPreprintSignatureTarget() {
  const target = document.getElementById('preprint-signature-target')?.value || 'sig1';
  return PDF_PREPRINT_SIGNATURE_CONFIG[target] ? target : 'sig1';
}

function getPdfPreprintImageTarget() {
  const target = document.getElementById('preprint-image-target')?.value || 'all';
  return ['all', '1', '2', '3', '4'].includes(target) ? target : 'all';
}

function getPdfPreprintImageKey(baseKey, target = getPdfPreprintImageTarget()) {
  return target === 'all' ? `image_${baseKey}` : `image_${target}_${baseKey}`;
}

function getPdfPreprintDraftValue(key, min, max, fallback) {
  return clampNumber(
    Object.prototype.hasOwnProperty.call(pdfPreprintState.draftSettings, key)
      ? pdfPreprintState.draftSettings[key]
      : pdfPreprintState.loadedSettings[key],
    min,
    max,
    fallback
  );
}

function setPdfPreprintDraftValue(key, value) {
  pdfPreprintState.draftSettings[key] = String(value);
  pdfPreprintState.dirtyKeys.add(key);
}

function hasPdfPreprintSettingValue(key) {
  const value = Object.prototype.hasOwnProperty.call(pdfPreprintState.draftSettings, key)
    ? pdfPreprintState.draftSettings[key]
    : pdfPreprintState.loadedSettings[key];
  return value !== undefined && value !== null && String(value) !== '';
}

function getPdfPreprintSettingValue(key, fallback = '') {
  const value = Object.prototype.hasOwnProperty.call(pdfPreprintState.draftSettings, key)
    ? pdfPreprintState.draftSettings[key]
    : pdfPreprintState.loadedSettings[key];
  return value !== undefined && value !== null && String(value) !== '' ? String(value) : fallback;
}

function getPdfPreprintNumericSetting(key, min, max, fallback) {
  return clampNumber(getPdfPreprintSettingValue(key, fallback), min, max, fallback);
}

function getPdfPreprintRecord() {
  return pdfPreprintState.record || {};
}

function getPdfPreprintRecordImages() {
  const images = getPdfPreprintRecord().images;
  return Array.isArray(images) ? images.slice(0, 4) : [];
}

function getPdfPreprintRecordTambonName() {
  const record = getPdfPreprintRecord();
  const tambon = record.tambon ? getTambonById(record.tambon) : null;
  return tambon?.name || record.tambon_name || 'ตัวอย่างกลุ่มเรียน';
}

function getPdfPreprintThaiDateParts() {
  const record = getPdfPreprintRecord();
  const date = record.date ? new Date(`${record.date}T00:00:00`) : new Date();
  const resolved = Number.isNaN(date.getTime()) ? new Date() : date;
  const thaiMonths = [
    'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
    'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'
  ];

  return {
    day: String(resolved.getDate()),
    month: thaiMonths[resolved.getMonth()],
    year: String(resolved.getFullYear() + 543)
  };
}

function decodeHtmlEntities(value) {
  const textarea = document.createElement('textarea');
  textarea.innerHTML = String(value ?? '');
  return textarea.value;
}

function normalizeMultilineText(value, fallback = '') {
  const normalized = decodeHtmlEntities(value)
    .replace(/\r\n/g, '\n')
    .replace(/\r/g, '\n')
    .replace(/<br\s*\/?>/gi, '\n');
  return normalized !== '' ? normalized : fallback;
}

function formatPdfPreprintMultiline(value, fallback = '') {
  const text = normalizeMultilineText(value, fallback);
  return text
    .split('\n')
    .map(line => line === '' ? '&nbsp;' : escapeHtml(line))
    .join('<br>');
}

function getPdfPreprintFontStyle() {
  const pageFont = getPreprintValue('preprint-page-font', 10, 20, PDF_PREPRINT_DEFAULTS.pageFont);
  const lineSpacing = getPreprintValue('preprint-line-spacing', 20, 40, PDF_PREPRINT_DEFAULTS.lineSpacing);
  return `--preprint-font-size-offset:${getPdfFontSizeOffsetPx(pageFont)}px;--preprint-line-spacing:${lineSpacing}px;`;
}

function getPdfPreprintAlignJustify(align, paperCenter = false) {
  if (align === 'center' || paperCenter) return 'center';
  if (align === 'flex-start' || align === 'left') return 'flex-start';
  return 'flex-end';
}

function getPdfPreprintSignatureOffset(target, axis) {
  const config = PDF_PREPRINT_SIGNATURE_CONFIG[target] || PDF_PREPRINT_SIGNATURE_CONFIG.sig1;
  const key = axis === 'y' ? config.yKey : config.xKey;
  return getPdfPreprintDraftValue(key, -220, 220, PDF_PREPRINT_DEFAULTS.signatureX);
}

function setPdfPreprintPreviewPage(page) {
  pdfPreprintState.previewPage = ['all', 'document', 'images', 'pdf'].includes(page) ? page : 'all';
  const liveScroll = document.getElementById('preprint-live-preview');
  if (liveScroll && pdfPreprintState.previewPage !== 'pdf') {
    liveScroll.scrollTop = 0;
    liveScroll.scrollLeft = 0;
  }
  renderPdfPreprintLivePreview();
}

function ensurePdfPreprintFramePaint() {
  const frame = document.getElementById('preprint-pdf-frame');
  if (!frame?.src || frame.src === 'about:blank') return;
  const src = frame.src;
  window.requestAnimationFrame(() => {
    frame.src = 'about:blank';
    window.setTimeout(() => {
      frame.src = src;
    }, 30);
  });
}

let pdfPreprintPdfJsPromise = null;

function getPdfPreprintCanvasContainer() {
  return document.getElementById('preprint-pdf-canvas-preview');
}

async function loadPdfPreprintPdfJs() {
  if (!pdfPreprintPdfJsPromise) {
    const pdfJsUrl = new URL('pdfjs.php?file=pdf', window.location.href).href;
    const workerUrl = new URL('pdfjs.php?file=worker', window.location.href).href;
    pdfPreprintPdfJsPromise = import(pdfJsUrl).then(pdfjsLib => {
      pdfjsLib.GlobalWorkerOptions.workerSrc = workerUrl;
      return pdfjsLib;
    });
  }

  return pdfPreprintPdfJsPromise;
}

function classifyPdfPreprintPage(text) {
  const pageText = String(text || '').replace(/\s+/g, '');
  if (pageText.includes('ภาพประกอบการจัดการเรียนการสอน')) return 'images';
  if (pageText.includes('บัญชีรายชื่อผู้เรียน')) return 'attendance';
  return 'document';
}

function getPdfPreprintVisibleTypes() {
  if (pdfPreprintState.previewPage === 'images') return ['images'];
  if (pdfPreprintState.previewPage === 'document') return ['document'];
  return ['document', 'images', 'attendance'];
}

function applyPdfPreprintCanvasPageFilter() {
  const container = getPdfPreprintCanvasContainer();
  if (!container) return;

  const visibleTypes = getPdfPreprintVisibleTypes();
  let visibleCount = 0;
  container.querySelectorAll('.preprint-pdf-page-shell').forEach(shell => {
    const visible = visibleTypes.includes(shell.dataset.preprintPageType || 'document');
    shell.hidden = !visible;
    if (visible) visibleCount += 1;
  });

  const empty = container.querySelector('.preprint-pdf-empty');
  if (empty) empty.remove();
  if (!visibleCount && container.querySelector('.preprint-pdf-page-shell')) {
    const message = document.createElement('div');
    message.className = 'preprint-pdf-empty';
    message.textContent = pdfPreprintState.previewPage === 'images'
      ? 'PDF นี้ยังไม่มีหน้ารูปภาพ'
      : 'ไม่พบหน้าที่เลือกใน PDF นี้';
    container.appendChild(message);
  }
}

function getPdfPreprintImageHandlePosition() {
  const box = getPdfPreprintImageProxyBox();
  return {
    x: clampNumber(box.x + box.w, 18, PDF_PREPRINT_PAGE.width - 18, PDF_PREPRINT_PAGE.width - 40),
    y: clampNumber(box.y + box.h, 18, PDF_PREPRINT_PAGE.height - 18, PDF_PREPRINT_PAGE.height - 40)
  };
}

function getPdfPreprintImageProxyBox() {
  const images = getPdfPreprintRecordImages();
  const imageCount = Math.max(1, images.length || 1);
  const selectedTarget = getPdfPreprintImageTarget();
  const offsetX = getPdfPreprintImageModelValue('offset_x', 'all');
  const offsetY = getPdfPreprintImageModelValue('offset_y', 'all');
  const metrics = getPdfPreprintImageLayoutMetrics();
  const contentWidth = PDF_PREPRINT_PAGE.width - PDF_PREPRINT_PAGE.paddingLeft - PDF_PREPRINT_PAGE.paddingRight;
  const left = PDF_PREPRINT_PAGE.paddingLeft + Math.max(0, (contentWidth - metrics.groupWidth) / 2) + offsetX;
  const top = PDF_PREPRINT_PAGE.paddingTop + (imageCount <= 2 ? 54 : 68) + offsetY;
  if (selectedTarget === 'all') {
    return {
      x: Math.round(left),
      y: Math.round(top),
      w: Math.round(metrics.groupWidth),
      h: Math.round(metrics.groupHeight)
    };
  }

  const selectedIndex = selectedTarget === 'all'
    ? 1
    : clampNumber(selectedTarget, 1, imageCount, 1);
  const selectedItem = metrics.items[selectedIndex - 1] || metrics.items[0];
  const itemX = getPdfPreprintImageModelValue('offset_x', String(selectedIndex));
  const itemY = getPdfPreprintImageModelValue('offset_y', String(selectedIndex));

  return {
    x: Math.round(left + selectedItem.x + itemX),
    y: Math.round(top + selectedItem.y + itemY),
    w: Math.round(selectedItem.width),
    h: Math.round(selectedItem.height)
  };
}

function getPdfPreprintSignatureProxyBox() {
  const target = getPdfPreprintSignatureTarget();
  const config = PDF_PREPRINT_SIGNATURE_CONFIG[target] || PDF_PREPRINT_SIGNATURE_CONFIG.sig1;
  const align = getPdfPreprintSettingValue(config.alignKey, config.defaultAlign);
  const width = 430;
  const height = 112;
  const contentWidth = PDF_PREPRINT_PAGE.width - PDF_PREPRINT_PAGE.paddingLeft - PDF_PREPRINT_PAGE.paddingRight;
  let x = PDF_PREPRINT_PAGE.paddingLeft + contentWidth - width;
  if (align === 'center' || config.paperCenter) {
    x = PDF_PREPRINT_PAGE.paddingLeft + Math.round((contentWidth - width) / 2);
  } else if (align === 'flex-start' || align === 'left') {
    x = PDF_PREPRINT_PAGE.paddingLeft;
  }

  const baseY = {
    sig1: 690,
    sig2: 845,
    sig3: 995
  }[target] || 690;

  return {
    x: Math.round(x + getPdfPreprintSignatureOffset(target, 'x')),
    y: Math.round(baseY + getPdfPreprintSignatureOffset(target, 'y')),
    w: width,
    h: height
  };
}

function buildPdfPreprintCanvasOverlay(pageType) {
  const overlay = document.createElement('div');
  overlay.className = 'preprint-pdf-page-overlay';

  if (pageType === 'images') {
    const surface = document.createElement('div');
    surface.className = 'preprint-pdf-drag-surface';
    surface.dataset.preprintDrag = 'image-group';
    surface.title = 'ลากเพื่อเลื่อนรูปภาพทั้งหมด';
    overlay.appendChild(surface);

    const proxyBox = getPdfPreprintImageProxyBox();
    const proxy = document.createElement('div');
    proxy.className = 'preprint-pdf-proxy-box preprint-pdf-proxy-box--image';
    proxy.dataset.preprintDrag = getPdfPreprintImageTarget() === 'all' ? 'image-group' : 'image';
    proxy.dataset.preprintImageIndex = getPdfPreprintImageTarget();
    proxy.style.left = `${proxyBox.x}px`;
    proxy.style.top = `${proxyBox.y}px`;
    proxy.style.width = `${proxyBox.w}px`;
    proxy.style.height = `${proxyBox.h}px`;
    proxy.innerHTML = `<span>${getPdfPreprintImageTarget() === 'all' ? 'รูปทั้งหมด' : `รูปที่ ${getPdfPreprintImageTarget()}`}</span>`;
    overlay.appendChild(proxy);

    const handlePosition = getPdfPreprintImageHandlePosition();
    const handle = document.createElement('button');
    handle.type = 'button';
    handle.className = 'preprint-pdf-resize-handle';
    handle.dataset.preprintDrag = 'image-resize';
    handle.dataset.preprintImageIndex = getPdfPreprintImageTarget() === 'all' ? 'all' : getPdfPreprintImageTarget();
    handle.setAttribute('aria-label', 'ย่อหรือขยายรูปภาพ');
    handle.style.left = `${handlePosition.x - 12}px`;
    handle.style.top = `${handlePosition.y - 12}px`;
    overlay.appendChild(handle);
    return overlay;
  }

  if (pageType === 'document') {
    const surface = document.createElement('div');
    surface.className = 'preprint-pdf-drag-surface';
    surface.dataset.preprintDrag = 'signature';
    surface.dataset.preprintTarget = getPdfPreprintSignatureTarget();
    surface.title = 'ลากเพื่อเลื่อนผู้ลงนามที่เลือก';
    overlay.appendChild(surface);

    const proxyBox = getPdfPreprintSignatureProxyBox();
    const proxy = document.createElement('div');
    proxy.className = 'preprint-pdf-proxy-box preprint-pdf-proxy-box--signature';
    proxy.dataset.preprintDrag = 'signature';
    proxy.dataset.preprintTarget = getPdfPreprintSignatureTarget();
    proxy.style.left = `${proxyBox.x}px`;
    proxy.style.top = `${proxyBox.y}px`;
    proxy.style.width = `${proxyBox.w}px`;
    proxy.style.height = `${proxyBox.h}px`;
    proxy.innerHTML = `<span>${PDF_PREPRINT_SIGNATURE_CONFIG[getPdfPreprintSignatureTarget()].label}</span>`;
    overlay.appendChild(proxy);
  }

  return overlay;
}

function syncPdfPreprintCanvasOverlays() {
  const container = getPdfPreprintCanvasContainer();
  if (!container) return;

  container.querySelectorAll('.preprint-pdf-page-shell[data-preprint-page-type="images"]').forEach(shell => {
    const proxyBox = getPdfPreprintImageProxyBox();
    const proxy = shell.querySelector('.preprint-pdf-proxy-box--image');
    if (proxy) {
      proxy.dataset.preprintDrag = getPdfPreprintImageTarget() === 'all' ? 'image-group' : 'image';
      proxy.dataset.preprintImageIndex = getPdfPreprintImageTarget();
      proxy.style.left = `${proxyBox.x}px`;
      proxy.style.top = `${proxyBox.y}px`;
      proxy.style.width = `${proxyBox.w}px`;
      proxy.style.height = `${proxyBox.h}px`;
      proxy.innerHTML = `<span>${getPdfPreprintImageTarget() === 'all' ? 'รูปทั้งหมด' : `รูปที่ ${getPdfPreprintImageTarget()}`}</span>`;
    }

    const handlePosition = getPdfPreprintImageHandlePosition();
    const handle = shell.querySelector('.preprint-pdf-resize-handle');
    if (handle) {
      handle.dataset.preprintImageIndex = getPdfPreprintImageTarget() === 'all' ? 'all' : getPdfPreprintImageTarget();
      handle.style.left = `${handlePosition.x - 12}px`;
      handle.style.top = `${handlePosition.y - 12}px`;
    }
  });

  container.querySelectorAll('.preprint-pdf-page-shell[data-preprint-page-type="document"]').forEach(shell => {
    const proxyBox = getPdfPreprintSignatureProxyBox();
    const proxy = shell.querySelector('.preprint-pdf-proxy-box--signature');
    if (proxy) {
      proxy.dataset.preprintTarget = getPdfPreprintSignatureTarget();
      proxy.style.left = `${proxyBox.x}px`;
      proxy.style.top = `${proxyBox.y}px`;
      proxy.style.width = `${proxyBox.w}px`;
      proxy.style.height = `${proxyBox.h}px`;
      proxy.innerHTML = `<span>${PDF_PREPRINT_SIGNATURE_CONFIG[getPdfPreprintSignatureTarget()].label}</span>`;
    }

    const surface = shell.querySelector('.preprint-pdf-drag-surface[data-preprint-drag="signature"]');
    if (surface) {
      surface.dataset.preprintTarget = getPdfPreprintSignatureTarget();
    }
  });
}

async function renderPdfPreprintCanvasPreview(blob = pdfPreprintState.pdfBlob) {
  const container = getPdfPreprintCanvasContainer();
  if (!container || !blob) {
    if (container && !blob) {
      container.innerHTML = '<div class="preprint-pdf-loading">กำลังสร้าง PDF จริง...</div>';
    }
    return;
  }

  const renderToken = ++pdfPreprintState.pdfRenderToken;
  container.innerHTML = '<div class="preprint-pdf-loading">กำลัง render PDF จริง...</div>';

  try {
    const pdfjsLib = await loadPdfPreprintPdfJs();
    const data = await blob.arrayBuffer();
    const pdf = await pdfjsLib.getDocument({ data }).promise;
    const dpr = 1;
    const fragment = document.createDocumentFragment();
    const pageTypes = [];
    const wantedTypes = getPdfPreprintVisibleTypes();
    const shouldRenderType = pageType => (
      pdfPreprintState.previewPage === 'pdf'
      || wantedTypes.includes(pageType)
    );

    for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber += 1) {
      if (renderToken !== pdfPreprintState.pdfRenderToken) return;

      const page = await pdf.getPage(pageNumber);
      const textContent = await page.getTextContent();
      const pageType = classifyPdfPreprintPage(textContent.items.map(item => item.str || '').join(' '));
      pageTypes.push(pageType);
      if (!shouldRenderType(pageType)) {
        continue;
      }

      const baseViewport = page.getViewport({ scale: 1 });
      const displayScale = PDF_PREPRINT_PAGE.width / baseViewport.width;
      const displayViewport = page.getViewport({ scale: displayScale });
      const renderViewport = page.getViewport({ scale: displayScale * dpr });
      const shell = document.createElement('div');
      shell.className = 'preprint-pdf-page-shell';
      shell.dataset.preprintPageType = pageType;
      shell.dataset.preprintPageNumber = String(pageNumber);
      shell.style.width = `${displayViewport.width}px`;
      shell.style.height = `${displayViewport.height}px`;

      const canvas = document.createElement('canvas');
      canvas.width = Math.ceil(renderViewport.width);
      canvas.height = Math.ceil(renderViewport.height);
      canvas.style.width = `${displayViewport.width}px`;
      canvas.style.height = `${displayViewport.height}px`;

      const label = document.createElement('div');
      label.className = 'preprint-pdf-page-label';
      label.textContent = `หน้า ${pageNumber}`;

      shell.appendChild(canvas);
      shell.appendChild(label);
      shell.appendChild(buildPdfPreprintCanvasOverlay(pageType));
      fragment.appendChild(shell);

      const context = canvas.getContext('2d', { alpha: false });
      await page.render({ canvasContext: context, viewport: renderViewport }).promise;

    }

    if (renderToken !== pdfPreprintState.pdfRenderToken) return;
    const firstImagePageIndex = pageTypes.indexOf('images');
    if (firstImagePageIndex >= 0) {
      const shells = [...fragment.querySelectorAll('.preprint-pdf-page-shell')];
      pageTypes.forEach((pageType, index) => {
        if (index > firstImagePageIndex && pageType === 'document') {
          pageTypes[index] = 'attendance';
          if (shells[index]) {
            shells[index].dataset.preprintPageType = 'attendance';
          }
        }
      });
    }
    container.innerHTML = '';
    container.appendChild(fragment);
    pdfPreprintState.pdfPageTypes = pageTypes;
    applyPdfPreprintCanvasPageFilter();
    syncPdfPreprintCanvasOverlays();
  } catch (error) {
    console.error('PDF canvas preview error', error);
    container.innerHTML = '<div class="preprint-pdf-empty">render PDF จริงไม่สำเร็จ</div>';
  }
}

function buildPdfPreprintPreviewUrl(extraParams = {}) {
  if (!pdfPreprintState.recordId) return '';
  const url = new URL(buildPrintUrl(pdfPreprintState.recordId), window.location.href);
  Object.entries(extraParams).forEach(([key, value]) => {
    url.searchParams.set(key, String(value));
  });
  return url.toString();
}

function getPdfPreprintLiveFrame() {
  return document.getElementById('preprint-live-frame');
}

function getPdfPreprintLiveDocument() {
  const frame = getPdfPreprintLiveFrame();
  try {
    return frame?.contentDocument || frame?.contentWindow?.document || null;
  } catch (error) {
    return null;
  }
}

function getPdfPreprintVisibleLivePage() {
  const doc = getPdfPreprintLiveDocument();
  if (!doc) return null;
  return [...doc.querySelectorAll('.page')].find(page => !page.classList.contains('preprint-html-hidden')) || null;
}

function getPdfPreprintImagePages(doc) {
  return [...doc.querySelectorAll('.page')].filter(page => page.dataset.preprintPageGroup === 'images' || page.querySelector('.image-title'));
}

function getPdfPreprintPageGroup(page) {
  if (!page) return 'document';
  if (page.dataset.preprintPageGroup) return page.dataset.preprintPageGroup;
  if (page.querySelector('.image-title')) return 'images';
  if (page.querySelector('.attendance-title, .attendance-title-table, .attendance-table')) return 'attendance';
  return 'document';
}

function shouldMovePreviousBlockWithPageBreak(block) {
  const previous = block?.previousElementSibling;
  if (!previous) return block;
  if (block.matches('.dotted-text-area, .single-line-dot') && previous.matches('.section-title')) {
    return previous;
  }
  if (block.matches('.signature-flow-section') && previous.matches('.section-title, .separator')) {
    return previous;
  }
  return block;
}

function splitPreprintPageForScreen(page, pageNumber) {
  if (!page || page.dataset.preprintPaginated === '1') return pageNumber;
  const doc = page.ownerDocument;
  const group = getPdfPreprintPageGroup(page);
  let current = page;
  let currentPageNumber = pageNumber;

  current.dataset.preprintPageGroup = group;
  current.dataset.preprintPageNumber = String(currentPageNumber);

  for (let guard = 0; guard < 12; guard += 1) {
    const overflow = current.scrollHeight - PDF_PREPRINT_PAGE.height;
    if (overflow <= 8) break;

    const pageRect = current.getBoundingClientRect();
    const children = [...current.children].filter(child => {
      if (child.matches('script, style')) return false;
      return child.getBoundingClientRect().height > 0;
    });
    let breaker = null;
    for (const child of children) {
      const rect = child.getBoundingClientRect();
      const top = rect.top - pageRect.top;
      const bottom = rect.bottom - pageRect.top;
      if (bottom > PDF_PREPRINT_PAGE.height - 8 && top > 24) {
        breaker = shouldMovePreviousBlockWithPageBreak(child);
        break;
      }
    }

    if (!breaker || breaker === current.firstElementChild) break;

    const nextPage = doc.createElement('div');
    nextPage.className = current.className;
    nextPage.classList.add('page--screen-continuation');
    nextPage.dataset.preprintPageGroup = group;
    nextPage.dataset.preprintPageNumber = String(currentPageNumber + 1);
    nextPage.style.cssText = current.style.cssText;
    current.after(nextPage);

    let moving = breaker;
    while (moving) {
      const next = moving.nextElementSibling;
      nextPage.appendChild(moving);
      moving = next;
    }

    current.style.height = `${PDF_PREPRINT_PAGE.height}px`;
    current.style.minHeight = `${PDF_PREPRINT_PAGE.height}px`;
    current.dataset.preprintPaginated = '1';
    current = nextPage;
    currentPageNumber += 1;
  }

  current.style.minHeight = `${PDF_PREPRINT_PAGE.height}px`;
  current.dataset.preprintPaginated = '1';
  return currentPageNumber;
}

function paginatePdfPreprintLiveDocument(doc) {
  if (!doc || doc.defaultView?.__pdfPreprintPaginated) return;
  let pageNumber = 1;
  [...doc.querySelectorAll('.page')].forEach(page => {
    pageNumber = splitPreprintPageForScreen(page, pageNumber) + 1;
  });
  doc.defaultView.__pdfPreprintPaginated = true;
}

function getPdfPreprintLiveContentHeight(doc, page = getPdfPreprintVisibleLivePage()) {
  if (!doc) return PDF_PREPRINT_PAGE.height;
  const body = doc.body;
  const root = doc.documentElement;
  const visiblePages = [...doc.querySelectorAll('.page')].filter(pageEl => !pageEl.classList.contains('preprint-html-hidden'));
  const pageBottom = visiblePages.length
    ? Math.max(...visiblePages.map(pageEl => Math.ceil((pageEl.offsetTop || 0) + (pageEl.scrollHeight || pageEl.offsetHeight || 0))))
    : (page ? Math.ceil((page.offsetTop || 0) + (page.scrollHeight || page.offsetHeight || 0)) : 0);

  return Math.max(
    PDF_PREPRINT_PAGE.height,
    pageBottom,
    Math.ceil(root?.scrollHeight || 0),
    Math.ceil(body?.scrollHeight || 0),
    Math.ceil(root?.offsetHeight || 0),
    Math.ceil(body?.offsetHeight || 0)
  );
}

function syncPdfPreprintLiveFrameSize() {
  const frame = getPdfPreprintLiveFrame();
  const doc = getPdfPreprintLiveDocument();
  const page = getPdfPreprintVisibleLivePage();
  if (!frame || !doc || !page) return;
  frame.style.width = `${PDF_PREPRINT_PAGE.width + 8}px`;
  frame.style.height = `${getPdfPreprintLiveContentHeight(doc, page) + 8}px`;
}

function handlePdfPreprintLiveWheel(event) {
  const liveScroll = document.getElementById('preprint-live-preview');
  if (!liveScroll) return;
  if (!event.deltaX && !event.deltaY) return;
  liveScroll.scrollLeft += event.deltaX;
  liveScroll.scrollTop += event.deltaY;
  event.preventDefault();
}

function syncPdfPreprintLiveFramePage() {
  const doc = getPdfPreprintLiveDocument();
  if (!doc) return;
  const page = pdfPreprintState.previewPage || 'all';
  const pages = [...doc.querySelectorAll('.page')];
  const showAllPages = page === 'all' || page === 'pdf';
  const targetGroup = page === 'images' ? 'images' : 'document';
  const hasTargetGroup = !showAllPages && pages.some(pageEl => getPdfPreprintPageGroup(pageEl) === targetGroup);

  pages.forEach(pageEl => {
    const group = getPdfPreprintPageGroup(pageEl);
    pageEl.classList.toggle('preprint-html-hidden', showAllPages ? false : (hasTargetGroup ? group !== targetGroup : pageEl !== pages[0]));
  });

  doc.defaultView?.scrollTo(0, 0);
  if (doc.documentElement) doc.documentElement.scrollTop = 0;
  if (doc.body) doc.body.scrollTop = 0;
  syncPdfPreprintLiveFrameDraftState();
  syncPdfPreprintLiveFrameSize();
}

function getPdfPreprintLivePreviewUrl() {
  if (!pdfPreprintState.recordId) return '';
  const url = buildPrintUrl(pdfPreprintState.recordId);
  const showAttendance = document.getElementById('preprint-show-attendance')?.checked !== false;
  return `${url}${url.includes('?') ? '&' : '?'}settings_preview=1&html_preview=1${!showAttendance ? '&hide_attendance=1' : ''}`;
}

function getPdfPreprintEditableValue(el) {
  const text = el.innerText || el.textContent || '';
  if (el.dataset.preprintAllowBr === '1') {
    return normalizeMultilineText(text.replace(/\u00a0/g, ' '));
  }
  return text.replace(/\s+/g, ' ').trim();
}

function insertPlainTextIntoEditable(doc, text) {
  const selection = doc.getSelection?.();
  if (!selection || !selection.rangeCount) return;
  selection.deleteFromDocument();
  selection.getRangeAt(0).insertNode(doc.createTextNode(text));
  selection.collapseToEnd();
}

function placeCaretAtEnd(el) {
  const doc = el?.ownerDocument;
  const selection = doc?.defaultView?.getSelection?.();
  if (!el || !doc || !selection) return;
  const range = doc.createRange();
  range.selectNodeContents(el);
  range.collapse(false);
  selection.removeAllRanges();
  selection.addRange(range);
}

function bindPdfPreprintLiveEditable(el) {
  if (!el || el.dataset.preprintEditableBound === '1') return;
  el.dataset.preprintEditableBound = '1';
  el.setAttribute('spellcheck', 'false');
  el.setAttribute('tabindex', '0');
  const requiresDoubleClick = el.dataset.preprintDblclick === '1';
  if (!requiresDoubleClick) {
    el.setAttribute('contenteditable', 'true');
  } else {
    el.setAttribute('role', 'textbox');
    el.setAttribute('aria-label', 'ดับเบิลคลิกเพื่อแก้ไขข้อความ');
  }

  const enterEditMode = () => {
    if (requiresDoubleClick && el.getAttribute('contenteditable') !== 'true') {
      el.setAttribute('contenteditable', 'true');
    }
    el.classList.add('is-editing');
    el.focus();
    placeCaretAtEnd(el);
  };

  const syncValue = () => {
    const key = el.dataset.preprintSetting;
    if (!key) return;
    setPdfPreprintDraftValue(key, getPdfPreprintEditableValue(el));
    setPdfPreprintStatus('แก้ไขข้อความแล้ว กดบันทึกค่า หรือบันทึกและพิมพ์/ดาวน์โหลด');
    markPdfPreprintPreviewStale('แก้ไขข้อความแล้ว กดดูตัวอย่างใหม่เมื่อต้องการสร้าง PDF จริง');
  };

  el.addEventListener('pointerdown', event => {
    event.stopPropagation();
  });
  el.addEventListener('click', event => {
    event.stopPropagation();
    if (requiresDoubleClick) {
      setPdfPreprintStatus('ดับเบิลคลิกข้อความที่ต้องการแก้ไข');
      return;
    }
    el.focus();
  });
  el.addEventListener('dblclick', event => {
    event.preventDefault();
    event.stopPropagation();
    enterEditMode();
  });
  el.addEventListener('focus', () => {
    if (!requiresDoubleClick || el.getAttribute('contenteditable') === 'true') {
      el.classList.add('is-editing');
    }
  });
  el.addEventListener('input', syncValue);
  el.addEventListener('blur', () => {
    el.classList.remove('is-editing');
    syncValue();
    if (requiresDoubleClick) {
      el.removeAttribute('contenteditable');
    }
    schedulePdfPreprintHtmlPreview(350);
  });
  el.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      event.preventDefault();
      el.blur();
      return;
    }
    if (event.key === 'Enter' && el.dataset.preprintAllowBr !== '1') {
      event.preventDefault();
      el.blur();
    }
  });
  el.addEventListener('paste', event => {
    event.preventDefault();
    const pasted = event.clipboardData?.getData('text/plain') || '';
    const normalized = el.dataset.preprintAllowBr === '1'
      ? pasted.replace(/\r\n/g, '\n').replace(/\r/g, '\n')
      : pasted.replace(/\s+/g, ' ');
    insertPlainTextIntoEditable(el.ownerDocument, normalized);
    syncValue();
  });
}

function decoratePdfPreprintLiveFrame() {
  const doc = getPdfPreprintLiveDocument();
  if (!doc) return;

  paginatePdfPreprintLiveDocument(doc);
  doc.querySelectorAll('.preprint-live-editable[data-preprint-setting]').forEach(bindPdfPreprintLiveEditable);

  doc.querySelectorAll('.pdf-preview-block--flow-signature').forEach((block, index) => {
    const target = ['sig1', 'sig2', 'sig3'][index];
    if (!target) return;
    block.dataset.preprintDrag = 'signature';
    block.dataset.preprintTarget = target;
    block.classList.add('preprint-live-draggable');
  });

  const imageLayout = doc.querySelector('.image-layout-inner');
  if (imageLayout) {
    imageLayout.dataset.preprintDrag = 'image-group';
  }

  doc.querySelectorAll('.image-shell-two, .image-shell-four').forEach((shell, index) => {
    if (!shell.querySelector('img, .missing-image-note')) return;
    const imageIndex = String(index + 1);
    shell.dataset.preprintDrag = 'image';
    shell.dataset.preprintImageIndex = imageIndex;
    shell.classList.add('preprint-live-draggable');
    if (!shell.querySelector('.preprint-image-resize-corner')) {
      const button = doc.createElement('button');
      button.type = 'button';
      button.className = 'preprint-image-resize-corner';
      button.dataset.preprintDrag = 'image-resize';
      button.dataset.preprintImageIndex = imageIndex;
      button.setAttribute('aria-label', `ย่อหรือขยายรูปที่ ${imageIndex}`);
      shell.appendChild(button);
    }
  });

  if (!doc.defaultView?.__pdfPreprintBound) {
    doc.addEventListener('pointerdown', handlePdfPreprintLivePointerDown);
    doc.addEventListener('pointermove', handlePdfPreprintPointerMove);
    doc.addEventListener('pointerup', handlePdfPreprintPointerUp);
    doc.addEventListener('pointercancel', handlePdfPreprintPointerUp);
    doc.addEventListener('wheel', handlePdfPreprintLiveWheel, { passive: false });
    doc.defaultView.__pdfPreprintBound = true;
  }

  syncPdfPreprintLiveFramePage();
}

function applyPdfPreprintLiveSignatureState(doc) {
  Object.entries(PDF_PREPRINT_SIGNATURE_CONFIG).forEach(([target, config]) => {
    const block = doc.querySelector(`[data-preprint-drag="signature"][data-preprint-target="${target}"]`);
    if (!block) return;
    const section = block.closest('.signature-flow-section');
    const align = getPdfPreprintSettingValue(config.alignKey, config.defaultAlign);
    const offsetX = getPdfPreprintSignatureOffset(target, 'x')
      + (config.paperCenter ? Math.round((PDF_PREPRINT_PAGE.paddingRight - PDF_PREPRINT_PAGE.paddingLeft) / 2) : 0);
    const offsetY = getPdfPreprintSignatureOffset(target, 'y');
    const mt = getPdfPreprintNumericSetting(config.mtKey, 0, 180, config.defaultMt) + offsetY;

    if (section) {
      section.style.justifyContent = getPdfPreprintAlignJustify(align, config.paperCenter);
      section.style.marginTop = `${mt}px`;
    }
    block.style.transform = `translateX(${offsetX}px)`;
    block.classList.toggle('is-selected', getPdfPreprintSignatureTarget() === target);
  });
}

function getPdfPreprintHasGlobalCustomImageLayout() {
  return ['image_width_px', 'image_height_px', 'image_offset_x', 'image_offset_y', 'image_gap_px']
    .some(key => hasPdfPreprintSettingValue(key));
}

function getPdfPreprintHasCustomImageLayout() {
  if (getPdfPreprintHasGlobalCustomImageLayout()) return true;
  return [1, 2, 3, 4].some(index => (
    hasPdfPreprintSettingValue(`image_${index}_width_px`)
    || hasPdfPreprintSettingValue(`image_${index}_height_px`)
  ));
}

function applyPdfPreprintLiveImageState(doc) {
  const globalCustom = getPdfPreprintHasGlobalCustomImageLayout();
  const customLayout = getPdfPreprintHasCustomImageLayout();
  const imageX = getPdfPreprintImageModelValue('offset_x', 'all');
  const imageY = getPdfPreprintImageModelValue('offset_y', 'all');
  const gap = getPdfPreprintDraftValue('image_gap_px', 0, 72, PDF_PREPRINT_DEFAULTS.imageGap);
  const imageLayoutMetrics = getPdfPreprintImageLayoutMetrics();
  const selectedTarget = getPdfPreprintImageTarget();
  const layout = doc.querySelector('.image-layout-inner');
  const table = doc.querySelector('.image-grid-two, .image-grid-four');

  if (layout) {
    layout.style.transformOrigin = 'center top';
    layout.style.transform = globalCustom ? `translate(${imageX}px, ${imageY}px)` : '';
  }
  if (table) {
    if (customLayout) {
      table.style.width = 'auto';
      table.style.margin = table.classList.contains('image-grid-four') ? '8px auto 0' : '0 auto';
      table.style.borderSpacing = table.classList.contains('image-grid-four') ? '0' : `0 ${gap}px`;
      table.style.borderCollapse = 'separate';
    }
  }

  const fourImageShells = [...doc.querySelectorAll('.image-shell-four')];
  const fourImageRowCount = Math.ceil(fourImageShells.length / 2);

  doc.querySelectorAll('.image-shell-two, .image-shell-four').forEach((shell, index) => {
    if (!shell.querySelector('img, .missing-image-note')) return;
    const imageIndex = index + 1;
    const itemHasSizeCustom = globalCustom
      || hasPdfPreprintSettingValue(`image_${imageIndex}_width_px`)
      || hasPdfPreprintSettingValue(`image_${imageIndex}_height_px`);
    const itemHasOffsetCustom = hasPdfPreprintSettingValue(`image_${imageIndex}_offset_x`)
      || hasPdfPreprintSettingValue(`image_${imageIndex}_offset_y`);
    const displayItem = imageLayoutMetrics.items[imageIndex - 1] || {
      width: getPdfPreprintImageModelValue('width_px', String(imageIndex)),
      height: getPdfPreprintImageModelValue('height_px', String(imageIndex))
    };
    const itemWidth = displayItem.width;
    const itemHeight = displayItem.height;
    const itemX = getPdfPreprintImageModelValue('offset_x', String(imageIndex));
    const itemY = getPdfPreprintImageModelValue('offset_y', String(imageIndex));
    const img = shell.querySelector('img');

    if (shell.classList.contains('image-shell-four') && customLayout) {
      const fourIndex = fourImageShells.indexOf(shell);
      const rowIndex = Math.floor(fourIndex / 2);
      const cellIndex = fourIndex % 2;
      const paddingTop = rowIndex > 0 ? Math.floor(gap / 2) : 0;
      const paddingRight = cellIndex === 0 ? Math.ceil(gap / 2) : 0;
      const paddingBottom = rowIndex < fourImageRowCount - 1 ? Math.ceil(gap / 2) : 0;
      const paddingLeft = cellIndex === 1 ? Math.floor(gap / 2) : 0;
      shell.style.padding = `${paddingTop}px ${paddingRight}px ${paddingBottom}px ${paddingLeft}px`;
    }

    shell.classList.toggle('is-selected', selectedTarget === 'all' || selectedTarget === String(imageIndex));
    if (itemHasSizeCustom) {
      shell.style.width = `${itemWidth}px`;
      shell.style.height = `${itemHeight}px`;
      if (img) {
        img.style.width = `${itemWidth}px`;
        img.style.height = `${itemHeight}px`;
        img.style.maxWidth = 'none';
        img.style.maxHeight = 'none';
        img.style.objectFit = 'fill';
      }
    }
    if (itemHasOffsetCustom) {
      shell.style.transform = `translate(${itemX}px, ${itemY}px)`;
    } else {
      shell.style.transform = '';
    }
  });
}

function syncPdfPreprintLiveFrameDraftState() {
  const doc = getPdfPreprintLiveDocument();
  if (!doc) return;
  applyPdfPreprintLiveSignatureState(doc);
  applyPdfPreprintLiveImageState(doc);
  syncPdfPreprintLiveFrameSize();
}

function markPdfPreprintPreviewStale(message = 'ปรับตำแหน่งแล้ว กดดูตัวอย่างใหม่เมื่อต้องการสร้าง PDF จริง') {
  pdfPreprintState.pdfPreviewDirty = true;
  if (pdfPreprintState.previewPage !== 'pdf') {
    setPdfPreprintStatus(message);
  }
}

function schedulePdfPreprintHtmlPreview(delay = 250) {
  window.clearTimeout(pdfPreprintState.htmlPreviewTimer);
  pdfPreprintState.htmlPreviewTimer = window.setTimeout(refreshPdfPreprintHtmlPreview, delay);
}

async function refreshPdfPreprintHtmlPreview() {
  const frame = getPdfPreprintLiveFrame();
  if (!frame || !pdfPreprintState.recordId) return;

  if (pdfPreprintState.htmlPreviewController) {
    pdfPreprintState.htmlPreviewController.abort();
  }
  const controller = new AbortController();
  pdfPreprintState.htmlPreviewController = controller;

  try {
    const res = await fetch(getPdfPreprintLivePreviewUrl(), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(collectPdfPreprintPayload()),
      signal: controller.signal
    });

    if (!res.ok) throw new Error(`HTML preview failed: ${res.status}`);
    const html = await res.text();
    frame.onload = () => {
      decoratePdfPreprintLiveFrame();
      syncPdfPreprintLiveFramePage();
      setPdfPreprintStatus('ตัวอย่างพร้อมปรับแล้ว');
    };
    frame.srcdoc = html;
  } catch (error) {
    if (error.name === 'AbortError') return;
    console.error('Preprint HTML preview error', error);
    setPdfPreprintStatus('โหลดหน้าปรับตำแหน่งไม่สำเร็จ');
  } finally {
    if (pdfPreprintState.htmlPreviewController === controller) {
      pdfPreprintState.htmlPreviewController = null;
    }
  }
}

function loadSelectedPdfPreprintSignatureControls() {
  const config = PDF_PREPRINT_SIGNATURE_CONFIG[getPdfPreprintSignatureTarget()];
  setPreprintValue('preprint-signature-x', getPdfPreprintDraftValue(config.xKey, -220, 220, PDF_PREPRINT_DEFAULTS.signatureX));
  setPreprintValue('preprint-signature-y', getPdfPreprintDraftValue(config.yKey, -220, 220, PDF_PREPRINT_DEFAULTS.signatureY));
  updatePdfPreprintLabels();
}

function loadSelectedPdfPreprintImageControls() {
  const target = getPdfPreprintImageTarget();
  setPreprintValue('preprint-image-width', getPdfPreprintImageModelValue('width_px', target));
  setPreprintValue('preprint-image-height', getPdfPreprintImageModelValue('height_px', target));
  setPreprintValue('preprint-image-x', getPdfPreprintImageModelValue('offset_x', target));
  setPreprintValue('preprint-image-y', getPdfPreprintImageModelValue('offset_y', target));
  setPreprintValue('preprint-image-gap', getPdfPreprintDraftValue('image_gap_px', 0, 72, PDF_PREPRINT_DEFAULTS.imageGap));
  updatePdfPreprintLabels();
}

function updatePdfPreprintLabels() {
  const pageFont = getPreprintValue('preprint-page-font', 10, 20, PDF_PREPRINT_DEFAULTS.pageFont);
  const lineSpacing = getPreprintValue('preprint-line-spacing', 20, 40, PDF_PREPRINT_DEFAULTS.lineSpacing);
  const signatureFont = getPreprintValue('preprint-signature-font', 10, 20, PDF_PREPRINT_DEFAULTS.signatureFont);
  const attendanceFont = getPreprintValue('preprint-attendance-font', 10, 20, PDF_PREPRINT_DEFAULTS.attendanceFont);
  const signatureX = getPreprintValue('preprint-signature-x', -220, 220, PDF_PREPRINT_DEFAULTS.signatureX);
  const signatureY = getPreprintValue('preprint-signature-y', -220, 220, PDF_PREPRINT_DEFAULTS.signatureY);
  const imageWidth = getPreprintValue('preprint-image-width', 180, 620, PDF_PREPRINT_DEFAULTS.imageWidth);
  const imageHeight = getPreprintValue('preprint-image-height', 120, 560, PDF_PREPRINT_DEFAULTS.imageHeight);
  const imageX = getPreprintValue('preprint-image-x', -220, 220, PDF_PREPRINT_DEFAULTS.imageX);
  const imageY = getPreprintValue('preprint-image-y', -220, 220, PDF_PREPRINT_DEFAULTS.imageY);
  const imageGap = getPreprintValue('preprint-image-gap', 0, 72, PDF_PREPRINT_DEFAULTS.imageGap);

  const labels = {
    'preprint-page-font-value': `${pageFont} pt`,
    'preprint-line-spacing-value': `${lineSpacing} px`,
    'preprint-signature-font-value': `${signatureFont} pt`,
    'preprint-attendance-font-value': `${attendanceFont} pt`,
    'preprint-margin-top-value': `${getPreprintValue('preprint-margin-top', 20, 140, PDF_PREPRINT_DEFAULTS.marginTop)} px`,
    'preprint-margin-right-value': `${getPreprintValue('preprint-margin-right', 20, 160, PDF_PREPRINT_DEFAULTS.marginRight)} px`,
    'preprint-margin-bottom-value': `${getPreprintValue('preprint-margin-bottom', 20, 140, PDF_PREPRINT_DEFAULTS.marginBottom)} px`,
    'preprint-margin-left-value': `${getPreprintValue('preprint-margin-left', 20, 160, PDF_PREPRINT_DEFAULTS.marginLeft)} px`,
    'preprint-signature-x-value': `${signatureX} px`,
    'preprint-signature-y-value': `${signatureY} px`,
    'preprint-image-width-value': `${imageWidth} px`,
    'preprint-image-height-value': `${imageHeight} px`,
    'preprint-image-x-value': `${imageX} px`,
    'preprint-image-y-value': `${imageY} px`,
    'preprint-image-gap-value': `${imageGap} px`
  };
  Object.entries(labels).forEach(([id, text]) => {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
  });

  const signatureBox = document.getElementById('preprint-signature-box');
  if (signatureBox) {
    signatureBox.textContent = `ลาก${PDF_PREPRINT_SIGNATURE_CONFIG[getPdfPreprintSignatureTarget()].label}`;
    signatureBox.style.left = `calc(50% + ${signatureX / 3}px)`;
    signatureBox.style.top = `calc(50% + ${signatureY / 3}px)`;
  }

  const box = document.getElementById('preprint-image-box');
  const stage = document.getElementById('preprint-image-stage');
  if (box && stage) {
    const imageTarget = getPdfPreprintImageTarget();
    const boxWidth = Math.max(60, Math.min(stage.clientWidth - 28, imageWidth * 0.5));
    const boxHeight = Math.max(42, Math.min(stage.clientHeight - 28, imageHeight * 0.5));
    const label = box.querySelector('span');
    if (label) label.textContent = imageTarget === 'all' ? 'ลากรูปทั้งหมด' : `ลากรูปที่ ${imageTarget}`;
    box.style.width = `${boxWidth}px`;
    box.style.height = `${boxHeight}px`;
    box.style.left = `calc(50% + ${imageX / 3}px)`;
    box.style.top = `calc(50% + ${imageY / 3}px)`;
  }

  renderPdfPreprintLivePreview();
  syncPdfPreprintCanvasOverlays();
}

function buildPreprintLine(value) {
  return `<span class="preprint-line">${escapeHtml(value ?? '')}</span>`;
}

function buildPreprintDottedText(value, fallback = '') {
  return `<div class="preprint-dotted-row">${formatPdfPreprintMultiline(value, fallback)}</div>`;
}

function getPdfPreprintSignatureContent(target) {
  const record = getPdfPreprintRecord();
  const config = PDF_PREPRINT_SIGNATURE_CONFIG[target] || PDF_PREPRINT_SIGNATURE_CONFIG.sig1;
  const dotWidth = getPdfPreprintNumericSetting(config.dotKey, 80, 360, config.defaultDot);
  const emptyName = '........................................................';
  const values = {
    sig1: {
      prefix: normalizePrintSignatureLabel(getPdfPreprintSettingValue('sig1_sign_prefix', '(ลงชื่อ)')),
      suffix: getPdfPreprintSettingValue('sig1_sign_suffix', 'ผู้บันทึก'),
      name: getPdfPreprintSettingValue('sig1_name', record.teacher_name || currentUser?.name || 'ชื่อผู้บันทึก'),
      pos: getPdfPreprintSettingValue('sig1_pos', 'ครูผู้สอน')
    },
    sig2: {
      prefix: normalizePrintSignatureLabel(getPdfPreprintSettingValue('sig2_sign_label', '(ลงชื่อ)')),
      suffix: '',
      name: getPdfPreprintSettingValue('sig2_name', emptyName),
      pos: getPdfPreprintSettingValue('sig2_pos', 'หัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ')
    },
    sig3: {
      prefix: normalizePrintSignatureLabel(getPdfPreprintSettingValue('sig3_sign_label', '(ลงชื่อ)')),
      suffix: '',
      name: getPdfPreprintSettingValue('sig3_name', emptyName),
      pos: getPdfPreprintSettingValue('sig3_pos', 'ครู รักษาการในตำแหน่ง<br>ผู้อำนวยการสถานศึกษา ศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอเสนา')
    }
  }[target] || {};

  return `
    <div class="preprint-sig-line">
      <span>${escapeHtml(values.prefix || '(ลงชื่อ)')}</span>
      <span class="preprint-inline-dot" style="width:${dotWidth}px"></span>
      ${values.suffix ? `<span>${escapeHtml(values.suffix)}</span>` : ''}
    </div>
    <div>( ${escapeHtml(values.name || emptyName)} )</div>
    <div>${formatPdfPreprintMultiline(values.pos || '')}</div>
  `;
}

function buildPreprintSignatureBox(target, extraClass = '') {
  const config = PDF_PREPRINT_SIGNATURE_CONFIG[target] || PDF_PREPRINT_SIGNATURE_CONFIG.sig1;
  const selected = getPdfPreprintSignatureTarget() === target;
  return `
    <div class="preprint-signature-box preprint-model-draggable ${selected ? 'is-active' : ''} ${extraClass}"
      data-preprint-drag="signature"
      data-preprint-target="${target}">
      ${getPdfPreprintSignatureContent(target)}
    </div>
  `;
}

function buildPreprintSignatureFlow(target, options = {}) {
  const config = PDF_PREPRINT_SIGNATURE_CONFIG[target] || PDF_PREPRINT_SIGNATURE_CONFIG.sig1;
  const align = getPdfPreprintSettingValue(config.alignKey, config.defaultAlign);
  const offsetX = getPdfPreprintSignatureOffset(target, 'x')
    + (config.paperCenter ? Math.round((PDF_PREPRINT_PAGE.paddingRight - PDF_PREPRINT_PAGE.paddingLeft) / 2) : 0);
  const offsetY = getPdfPreprintSignatureOffset(target, 'y');
  const mt = getPdfPreprintNumericSetting(config.mtKey, 0, 180, config.defaultMt) + offsetY;
  const justify = getPdfPreprintAlignJustify(align, config.paperCenter);

  return `
    <div class="preprint-signature-flow ${options.className || ''}"
      style="justify-content:${justify};margin-top:${mt}px;">
      <div style="transform:translateX(${offsetX}px);">
        ${buildPreprintSignatureBox(target)}
      </div>
    </div>
  `;
}

function buildPdfPreprintDocumentPage() {
  const record = getPdfPreprintRecord();
  const date = getPdfPreprintThaiDateParts();
  const absent = Math.max(0, (Number(record.total) || 0) - (Number(record.present) || 0));
  const sig2BlockX = getPdfPreprintNumericSetting('sig2_block_offset_x', -220, 220, 0);
  const sig2BlockY = getPdfPreprintNumericSetting('sig2_block_offset_y', -220, 220, 0);
  const sig3BlockX = getPdfPreprintNumericSetting('sig3_block_offset_x', -220, 220, 0);
  const sig3BlockY = getPdfPreprintNumericSetting('sig3_block_offset_y', -220, 220, 0);
  const sig2TitleX = getPdfPreprintNumericSetting('sig2_title_offset_x', -220, 220, 0);
  const sig2TitleY = getPdfPreprintNumericSetting('sig2_title_offset_y', -220, 220, 0);
  const sig3TitleX = getPdfPreprintNumericSetting('sig3_title_offset_x', -220, 220, 0);
  const sig3TitleY = getPdfPreprintNumericSetting('sig3_title_offset_y', -220, 220, 0);

  return `
    <div class="preprint-a4-page" style="${getPdfPreprintFontStyle()}">
      <h2>บันทึกหลังการสอนการเรียนรู้รายสัปดาห์</h2>
      <div class="preprint-row preprint-row--1">
        <span>ศูนย์การเรียนรู้</span>${buildPreprintLine(`ตำบล${getPdfPreprintRecordTambonName()}`)}
        <span>ศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอ</span>${buildPreprintLine('เสนา')}
      </div>
      <div class="preprint-row preprint-row--2">
        <span>สัปดาห์ที่</span>${buildPreprintLine(record.week || '')}
        <span>วันที่</span>${buildPreprintLine(date.day)}
        <span>เดือน</span>${buildPreprintLine(date.month)}
        <span>พ.ศ.</span>${buildPreprintLine(date.year)}
      </div>
      <div class="preprint-row preprint-row--3">
        <span>ระดับ</span>${buildPreprintLine(record.level || '')}
        <span>ภาคเรียนที่</span>${buildPreprintLine(record.semester || '')}
        <span>ปีการศึกษา</span>${buildPreprintLine(record.year || '')}
      </div>
      <div class="preprint-row preprint-row--4">
        <span>รายวิชา :</span>${buildPreprintLine(record.subject || '')}
        <span>รหัสวิชา :</span>${buildPreprintLine(record.subject_code || '')}
      </div>
      <div class="preprint-row preprint-row--5">
        <span>ผู้เรียนทั้งหมด</span>${buildPreprintLine(record.total || '')}
        <span>คน เข้าเรียน</span>${buildPreprintLine(record.present || '')}
        <span>คน ไม่มาเรียน</span>${buildPreprintLine(absent)}
        <span>คน</span>
      </div>
      <div class="preprint-section-title">๑. ผลการจัดการเรียนการสอน</div>
      ${buildPreprintDottedText(record.result, 'ตัวอย่างผลการจัดการเรียนการสอน')}
      <div class="preprint-section-title">๒. สภาพปัญหาและอุปสรรค</div>
      ${buildPreprintDottedText(record.problem, 'ตัวอย่างสภาพปัญหาและอุปสรรค')}
      <div class="preprint-section-title">๓. ข้อเสนอแนะ / แนวทางแก้ไขปัญหา</div>
      ${buildPreprintDottedText(record.suggestion, 'ตัวอย่างข้อเสนอแนะและแนวทางแก้ไขปัญหา')}
      ${buildPreprintSignatureFlow('sig1')}
      <div class="preprint-signature-flow--sig2" style="margin-top:${18 + sig2BlockY}px;transform:translateX(${sig2BlockX}px);">
        <div class="preprint-separator"></div>
        <div class="preprint-section-title" style="transform:translate(${sig2TitleX}px, ${sig2TitleY}px);">
          ${escapeHtml(getPdfPreprintSettingValue('sig2_title', 'ความคิดเห็นของหัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ'))}
        </div>
        ${buildPreprintDottedText(record.supervisor_comment || '')}
        ${buildPreprintSignatureFlow('sig2')}
      </div>
      <div class="preprint-signature-flow--sig3-section" style="margin-top:${24 + sig3BlockY}px;transform:translateX(${sig3BlockX}px);">
        <div class="preprint-section-title" style="transform:translate(${sig3TitleX}px, ${sig3TitleY}px);">
          ${escapeHtml(getPdfPreprintSettingValue('sig3_title', 'ข้อคิดเห็นของผู้อำนวยการสถานศึกษา'))}
        </div>
        <div class="preprint-single-line-dot"></div>
        ${buildPreprintSignatureFlow('sig3')}
      </div>
    </div>
  `;
}

function getPdfPreprintImageModelValue(baseKey, target) {
  const key = getPdfPreprintImageKey(baseKey, target);
  const globalFallback = target !== 'all' && ['width_px', 'height_px'].includes(baseKey)
    ? getPdfPreprintImageModelValue(baseKey, 'all')
    : null;
  const limit = {
    width_px: [180, 620, globalFallback ?? PDF_PREPRINT_DEFAULTS.imageWidth],
    height_px: [120, 560, globalFallback ?? PDF_PREPRINT_DEFAULTS.imageHeight],
    offset_x: [-220, 220, PDF_PREPRINT_DEFAULTS.imageX],
    offset_y: [-220, 220, PDF_PREPRINT_DEFAULTS.imageY]
  }[baseKey];
  return getPdfPreprintDraftValue(key, limit[0], limit[1], limit[2]);
}

function getPdfPreprintImageLayoutMetrics() {
  const images = getPdfPreprintRecordImages();
  const imageCount = Math.max(1, images.length || 1);
  const gap = getPdfPreprintDraftValue('image_gap_px', 0, 72, PDF_PREPRINT_DEFAULTS.imageGap);
  const cols = imageCount <= 2 ? 1 : 2;
  const rows = Math.ceil(imageCount / cols);
  const rawItems = Array.from({ length: imageCount }, (_, index) => {
    const target = String(index + 1);
    return {
      width: getPdfPreprintImageModelValue('width_px', target),
      height: getPdfPreprintImageModelValue('height_px', target)
    };
  });
  const rawColumnWidths = Array.from({ length: cols }, (_, colIndex) => (
    rawItems.reduce((maxWidth, item, index) => (
      index % cols === colIndex ? Math.max(maxWidth, item.width) : maxWidth
    ), 0)
  ));
  const rawGridWidth = rawColumnWidths.reduce((sum, width) => sum + width, 0) + ((cols - 1) * gap);
  const contentWidth = PDF_PREPRINT_PAGE.width - PDF_PREPRINT_PAGE.paddingLeft - PDF_PREPRINT_PAGE.paddingRight;
  const globalOffsetX = getPdfPreprintImageModelValue('offset_x', 'all');
  const availableWidth = Math.max(240, contentWidth - (Math.abs(globalOffsetX) * 2));
  const scale = imageCount > 2 && rawGridWidth > availableWidth
    ? availableWidth / rawGridWidth
    : 1;
  const items = rawItems.map((item, index) => ({
    width: Math.max(1, Math.floor(item.width * scale)),
    height: Math.max(1, Math.floor(item.height * scale)),
    row: Math.floor(index / cols),
    col: index % cols
  }));
  const columnWidths = Array.from({ length: cols }, (_, colIndex) => (
    items.reduce((maxWidth, item) => (
      item.col === colIndex ? Math.max(maxWidth, item.width) : maxWidth
    ), 0)
  ));
  const rowHeights = Array.from({ length: rows }, (_, rowIndex) => (
    items.reduce((maxHeight, item) => (
      item.row === rowIndex ? Math.max(maxHeight, item.height) : maxHeight
    ), 0)
  ));
  const columnX = columnWidths.map((_, colIndex) => (
    columnWidths.slice(0, colIndex).reduce((sum, width) => sum + width, 0) + (colIndex * gap)
  ));
  const rowY = rowHeights.map((_, rowIndex) => (
    rowHeights.slice(0, rowIndex).reduce((sum, height) => sum + height, 0) + (rowIndex * gap)
  ));

  items.forEach(item => {
    item.x = columnX[item.col] + Math.round((columnWidths[item.col] - item.width) / 2);
    item.y = rowY[item.row] + Math.round((rowHeights[item.row] - item.height) / 2);
  });

  return {
    cols,
    rows,
    gap,
    scale,
    items,
    groupWidth: columnWidths.reduce((sum, width) => sum + width, 0) + ((cols - 1) * gap),
    groupHeight: rowHeights.reduce((sum, height) => sum + height, 0) + ((rows - 1) * gap)
  };
}

function buildPdfPreprintImageItem(image, index, imageCount) {
  const selectedTarget = getPdfPreprintImageTarget();
  const imageTarget = String(index);
  const isAll = selectedTarget === 'all';
  const displayItem = getPdfPreprintImageLayoutMetrics().items[index - 1] || {
    width: getPdfPreprintImageModelValue('width_px', imageTarget),
    height: getPdfPreprintImageModelValue('height_px', imageTarget)
  };
  const width = displayItem.width;
  const height = displayItem.height;
  const itemX = isAll ? 0 : getPdfPreprintImageModelValue('offset_x', imageTarget);
  const itemY = isAll ? 0 : getPdfPreprintImageModelValue('offset_y', imageTarget);
  const src = resolveImageSrc(image?.data || '');
  const isMissing = !src || image?.missing || image?.exists === false;
  const selectedClass = isAll || selectedTarget === imageTarget ? 'is-selected' : '';

  return `
    <div class="preprint-image-item preprint-model-draggable ${selectedClass}"
      data-preprint-drag="image"
      data-preprint-image-index="${index}"
      style="--preprint-image-width:${width}px;--preprint-image-height:${height}px;transform:translate(${itemX}px, ${itemY}px);">
      ${isMissing
        ? `<div class="preprint-image-placeholder">รูปที่ ${index}<br>ไม่พบไฟล์รูป</div>`
        : `<img src="${escapeHtml(src)}" alt="ภาพที่ ${index}" onerror="markMissingImage(this)">`}
      <button type="button" class="preprint-image-resize-corner" data-preprint-drag="image-resize" data-preprint-image-index="${index}" aria-label="ย่อหรือขยายรูปที่ ${index}"></button>
    </div>
  `;
}

function buildPdfPreprintImagesPage() {
  const images = getPdfPreprintRecordImages();
  const imageCount = images.length;
  const gap = getPdfPreprintDraftValue('image_gap_px', 0, 72, PDF_PREPRINT_DEFAULTS.imageGap);
  const globalX = getPdfPreprintImageModelValue('offset_x', 'all');
  const globalY = getPdfPreprintImageModelValue('offset_y', 'all');

  return `
    <div class="preprint-a4-page" style="${getPdfPreprintFontStyle()}">
      <div class="preprint-image-title">ภาพประกอบการจัดการเรียนการสอน</div>
      ${imageCount ? `
        <div class="preprint-image-layout-inner"
          data-preprint-drag="image-group"
          style="--preprint-image-gap:${gap}px;transform:translate(${globalX}px, ${globalY}px);">
          <div class="preprint-image-grid ${imageCount <= 2 ? 'preprint-image-grid--two' : 'preprint-image-grid--four'}">
            ${images.map((image, idx) => buildPdfPreprintImageItem(image, idx + 1, imageCount)).join('')}
          </div>
        </div>
      ` : `
        <div class="preprint-empty-page">รายการนี้ยังไม่มีภาพประกอบ<br>เมื่อมีรูป 2 หรือ 4 รูป จะสามารถลากและย่อขยายตำแหน่งบนหน้านี้ได้</div>
      `}
    </div>
  `;
}

function renderPdfPreprintLivePreview() {
  const liveWrap = document.getElementById('preprint-live-wrap');
  const pdfWrap = document.getElementById('preprint-pdf-wrap');
  const live = document.getElementById('preprint-live-preview');
  const liveFrame = getPdfPreprintLiveFrame();
  const canvasPreview = getPdfPreprintCanvasContainer();
  if (!liveWrap || !pdfWrap || !live) return;

  const page = pdfPreprintState.previewPage || 'all';
  document.querySelectorAll('[data-preprint-page]').forEach(button => {
    button.classList.toggle('is-active', button.dataset.preprintPage === page);
  });

  if (page === 'pdf') {
    liveWrap.hidden = true;
    pdfWrap.hidden = false;
    return;
  }

  pdfWrap.hidden = true;
  liveWrap.hidden = false;
  if (liveFrame) liveFrame.style.display = '';
  if (canvasPreview) canvasPreview.style.display = 'none';
  syncPdfPreprintLiveFramePage();
  if (!getPdfPreprintLiveDocument()) {
    refreshPdfPreprintHtmlPreview();
  }
}

function setPdfPreprintStatus(message) {
  const el = document.getElementById('preprint-status');
  if (el) el.textContent = message;
}

function collectPdfPreprintPayload(options = {}) {
  const includeDocumentDraft = options.includeDocumentDraft !== false;
  const pageFont = getPreprintValue('preprint-page-font', 10, 20, PDF_PREPRINT_DEFAULTS.pageFont);
  const payload = {
    pdf_font_size_pt: String(pageFont),
    pdf_line_spacing: String(getPreprintValue('preprint-line-spacing', 20, 40, PDF_PREPRINT_DEFAULTS.lineSpacing)),
    font_size_offset: String(getPdfFontSizeOffsetPx(pageFont)),
    signature_font_size_pt: String(getPreprintValue('preprint-signature-font', 10, 20, PDF_PREPRINT_DEFAULTS.signatureFont)),
    attendance_font_size_pt: String(getPreprintValue('preprint-attendance-font', 10, 20, PDF_PREPRINT_DEFAULTS.attendanceFont)),
    pdf_margin_top_px: String(getPreprintValue('preprint-margin-top', 20, 140, PDF_PREPRINT_DEFAULTS.marginTop)),
    pdf_margin_right_px: String(getPreprintValue('preprint-margin-right', 20, 160, PDF_PREPRINT_DEFAULTS.marginRight)),
    pdf_margin_bottom_px: String(getPreprintValue('preprint-margin-bottom', 20, 140, PDF_PREPRINT_DEFAULTS.marginBottom)),
    pdf_margin_left_px: String(getPreprintValue('preprint-margin-left', 20, 160, PDF_PREPRINT_DEFAULTS.marginLeft))
  };

  pdfPreprintState.dirtyKeys.forEach(key => {
    if (!includeDocumentDraft && PDF_PREPRINT_DOCUMENT_DRAFT_KEYS.has(key)) {
      return;
    }
    if (Object.prototype.hasOwnProperty.call(pdfPreprintState.draftSettings, key)) {
      payload[key] = String(pdfPreprintState.draftSettings[key]);
    }
  });

  return payload;
}

function collectPdfPreprintSettingsPayload() {
  return collectPdfPreprintPayload({ includeDocumentDraft: false });
}

function schedulePdfPreprintPreview(delay = 450) {
  window.clearTimeout(pdfPreprintState.previewTimer);
  pdfPreprintState.previewTimer = window.setTimeout(refreshPdfPreprintPreview, delay);
}

async function refreshPdfPreprintPreview() {
  const frame = document.getElementById('preprint-pdf-frame');
  if (!frame || !pdfPreprintState.recordId) return;

  if (pdfPreprintState.previewController) {
    pdfPreprintState.previewController.abort();
  }
  const controller = new AbortController();
  pdfPreprintState.previewController = controller;
  setPdfPreprintStatus('กำลังสร้างตัวอย่าง...');
  try {
    const url = buildPrintUrl(pdfPreprintState.recordId);
    const previewUrl = `${url}${url.includes('?') ? '&' : '?'}settings_preview=1`;
    const res = await fetch(previewUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(collectPdfPreprintPayload()),
      signal: controller.signal
    });

    if (!res.ok) throw new Error(`Preview failed: ${res.status}`);
    const blob = await res.blob();
    pdfPreprintState.pdfBlob = blob;
    if (pdfPreprintState.previewUrl) URL.revokeObjectURL(pdfPreprintState.previewUrl);
    pdfPreprintState.previewUrl = URL.createObjectURL(blob);
    frame.src = `${pdfPreprintState.previewUrl}#toolbar=0&navpanes=0&zoom=90`;
    pdfPreprintState.pdfPreviewDirty = false;
    if (pdfPreprintState.previewPage === 'pdf') {
      ensurePdfPreprintFramePaint();
    }
    setPdfPreprintStatus('ตัวอย่างล่าสุดพร้อมแล้ว');
  } catch (error) {
    if (error.name === 'AbortError') return;
    console.error('Preprint preview error', error);
    setPdfPreprintStatus('สร้างตัวอย่างไม่สำเร็จ');
  } finally {
    if (pdfPreprintState.previewController === controller) {
      pdfPreprintState.previewController = null;
    }
  }
}

function markPdfPreprintDirty(key) {
  if (key) {
    pdfPreprintState.dirtyKeys.add(key);
    // Sync the current DOM value into draftSettings so the payload override is consistent
    const sliderMap = {
      pdf_font_size_pt: ['preprint-page-font', 10, 20, PDF_PREPRINT_DEFAULTS.pageFont],
      pdf_line_spacing: ['preprint-line-spacing', 20, 40, PDF_PREPRINT_DEFAULTS.lineSpacing],
      signature_font_size_pt: ['preprint-signature-font', 10, 20, PDF_PREPRINT_DEFAULTS.signatureFont],
      attendance_font_size_pt: ['preprint-attendance-font', 10, 20, PDF_PREPRINT_DEFAULTS.attendanceFont],
      pdf_margin_top_px: ['preprint-margin-top', 20, 140, PDF_PREPRINT_DEFAULTS.marginTop],
      pdf_margin_right_px: ['preprint-margin-right', 20, 160, PDF_PREPRINT_DEFAULTS.marginRight],
      pdf_margin_bottom_px: ['preprint-margin-bottom', 20, 140, PDF_PREPRINT_DEFAULTS.marginBottom],
      pdf_margin_left_px: ['preprint-margin-left', 20, 160, PDF_PREPRINT_DEFAULTS.marginLeft]
    };
    if (sliderMap[key]) {
      const [id, min, max, fallback] = sliderMap[key];
      pdfPreprintState.draftSettings[key] = String(getPreprintValue(id, min, max, fallback));
    }
  }
  updatePdfPreprintLabels();
  syncPdfPreprintLiveFrameDraftState();
  markPdfPreprintPreviewStale(key && key.startsWith('pdf_')
    ? 'เปลี่ยนค่าฟอนต์แล้ว กดดูตัวอย่างใหม่เพื่อจัดหน้า PDF จริง'
    : undefined);
}

function markPdfPreprintSignatureDirty(axis) {
  const config = PDF_PREPRINT_SIGNATURE_CONFIG[getPdfPreprintSignatureTarget()];
  const key = axis === 'y' ? config.yKey : config.xKey;
  const value = axis === 'y'
    ? getPreprintValue('preprint-signature-y', -220, 220, PDF_PREPRINT_DEFAULTS.signatureY)
    : getPreprintValue('preprint-signature-x', -220, 220, PDF_PREPRINT_DEFAULTS.signatureX);
  setPdfPreprintDraftValue(key, value);
  updatePdfPreprintLabels();
  syncPdfPreprintLiveFrameDraftState();
  markPdfPreprintPreviewStale();
}

function markPdfPreprintImageDirty(baseKey) {
  const inputId = {
    width_px: 'preprint-image-width',
    height_px: 'preprint-image-height',
    offset_x: 'preprint-image-x',
    offset_y: 'preprint-image-y'
  }[baseKey];
  const limits = {
    width_px: [180, 620, PDF_PREPRINT_DEFAULTS.imageWidth],
    height_px: [120, 560, PDF_PREPRINT_DEFAULTS.imageHeight],
    offset_x: [-220, 220, PDF_PREPRINT_DEFAULTS.imageX],
    offset_y: [-220, 220, PDF_PREPRINT_DEFAULTS.imageY]
  }[baseKey];
  if (!inputId || !limits) return;
  setPdfPreprintDraftValue(getPdfPreprintImageKey(baseKey), getPreprintValue(inputId, limits[0], limits[1], limits[2]));
  updatePdfPreprintLabels();
  syncPdfPreprintLiveFrameDraftState();
  markPdfPreprintPreviewStale();
}

function selectPdfPreprintSignatureTarget(target) {
  if (!PDF_PREPRINT_SIGNATURE_CONFIG[target]) return;
  setPreprintValue('preprint-signature-target', target);
  loadSelectedPdfPreprintSignatureControls();
  syncPdfPreprintLiveFrameDraftState();
}

function selectPdfPreprintImageTarget(target) {
  if (!['all', '1', '2', '3', '4'].includes(String(target))) return;
  setPreprintValue('preprint-image-target', String(target));
  loadSelectedPdfPreprintImageControls();
  syncPdfPreprintLiveFrameDraftState();
}

function ensurePdfPreprintGlobalImageSizeDraft() {
  setPdfPreprintDraftValue('image_width_px', getPreprintValue('preprint-image-width', 180, 620, PDF_PREPRINT_DEFAULTS.imageWidth));
  setPdfPreprintDraftValue('image_height_px', getPreprintValue('preprint-image-height', 120, 560, PDF_PREPRINT_DEFAULTS.imageHeight));
}

function getPdfPreprintModelScale() {
  const page = document.querySelector('#preprint-live-preview .preprint-a4-page')
    || document.querySelector('#preprint-live-preview .preprint-pdf-page-shell:not([hidden])')
    || getPdfPreprintVisibleLivePage();
  const rect = page?.getBoundingClientRect();
  if (!rect?.width) return 1;
  return rect.width / PDF_PREPRINT_PAGE.width || 1;
}

function startPdfPreprintModelSignatureDrag(event, target) {
  selectPdfPreprintSignatureTarget(target);
  pdfPreprintState.drag = {
    target: 'signature',
    source: 'model',
    startX: event.clientX,
    startY: event.clientY,
    scale: getPdfPreprintModelScale(),
    x: getPreprintValue('preprint-signature-x', -220, 220, PDF_PREPRINT_DEFAULTS.signatureX),
    y: getPreprintValue('preprint-signature-y', -220, 220, PDF_PREPRINT_DEFAULTS.signatureY)
  };
  event.target.closest('.preprint-model-draggable, .preprint-live-draggable, .preprint-pdf-proxy-box, .preprint-pdf-drag-surface')?.classList.add('is-dragging');
  event.preventDefault();
}

function startPdfPreprintModelImageDrag(event, mode, imageIndex) {
  const currentTarget = getPdfPreprintImageTarget();
  const nextTarget = imageIndex && imageIndex !== 'all'
    ? String(imageIndex)
    : currentTarget;
  selectPdfPreprintImageTarget(nextTarget);
  if (nextTarget === 'all') {
    ensurePdfPreprintGlobalImageSizeDraft();
  }
  pdfPreprintState.drag = {
    target: 'image',
    source: 'model',
    mode,
    startX: event.clientX,
    startY: event.clientY,
    scale: getPdfPreprintModelScale(),
    width: getPreprintValue('preprint-image-width', 180, 620, PDF_PREPRINT_DEFAULTS.imageWidth),
    height: getPreprintValue('preprint-image-height', 120, 560, PDF_PREPRINT_DEFAULTS.imageHeight),
    x: getPreprintValue('preprint-image-x', -220, 220, PDF_PREPRINT_DEFAULTS.imageX),
    y: getPreprintValue('preprint-image-y', -220, 220, PDF_PREPRINT_DEFAULTS.imageY)
  };
  event.target.closest('.preprint-model-draggable, .preprint-live-draggable, .preprint-pdf-proxy-box, .preprint-pdf-drag-surface, .preprint-pdf-resize-handle')?.classList.add('is-dragging');
  event.preventDefault();
}

function handlePdfPreprintLivePointerDown(event) {
  const resizeHandle = event.target.closest('[data-preprint-drag="image-resize"]');
  if (resizeHandle) {
    startPdfPreprintModelImageDrag(event, 'resize', resizeHandle.dataset.preprintImageIndex);
    return;
  }

  const signatureTarget = event.target.closest('[data-preprint-drag="signature"]');
  if (signatureTarget) {
    startPdfPreprintModelSignatureDrag(event, signatureTarget.dataset.preprintTarget);
    return;
  }

  const imageTarget = event.target.closest('[data-preprint-drag="image"]');
  if (imageTarget) {
    startPdfPreprintModelImageDrag(event, 'move', imageTarget.dataset.preprintImageIndex);
    return;
  }

  const imageGroup = event.target.closest('[data-preprint-drag="image-group"]');
  if (imageGroup) {
    selectPdfPreprintImageTarget('all');
    startPdfPreprintModelImageDrag(event, 'move', null);
  }
}

function handlePdfPreprintPointerMove(event) {
  const drag = pdfPreprintState.drag;
  if (!drag) return;
  const dx = event.clientX - drag.startX;
  const dy = event.clientY - drag.startY;
  const moveFactor = drag.source === 'model' ? 1 / (drag.scale || 1) : 3;
  const resizeFactor = drag.source === 'model' ? 1 / (drag.scale || 1) : 2;

  if (drag.target === 'signature') {
    setPreprintValue('preprint-signature-x', clampNumber(drag.x + dx * moveFactor, -220, 220, PDF_PREPRINT_DEFAULTS.signatureX));
    setPreprintValue('preprint-signature-y', clampNumber(drag.y + dy * moveFactor, -220, 220, PDF_PREPRINT_DEFAULTS.signatureY));
    markPdfPreprintSignatureDirty('x');
    markPdfPreprintSignatureDirty('y');
    return;
  }

  if (drag.mode === 'resize') {
    setPreprintValue('preprint-image-width', clampNumber(drag.width + dx * resizeFactor, 180, 620, PDF_PREPRINT_DEFAULTS.imageWidth));
    setPreprintValue('preprint-image-height', clampNumber(drag.height + dy * resizeFactor, 120, 560, PDF_PREPRINT_DEFAULTS.imageHeight));
    markPdfPreprintImageDirty('width_px');
    markPdfPreprintImageDirty('height_px');
  } else {
    setPreprintValue('preprint-image-x', clampNumber(drag.x + dx * moveFactor, -220, 220, PDF_PREPRINT_DEFAULTS.imageX));
    setPreprintValue('preprint-image-y', clampNumber(drag.y + dy * moveFactor, -220, 220, PDF_PREPRINT_DEFAULTS.imageY));
    markPdfPreprintImageDirty('offset_x');
    markPdfPreprintImageDirty('offset_y');
  }
}

function handlePdfPreprintPointerUp() {
  const hadDrag = !!pdfPreprintState.drag;
  if (hadDrag) {
    document.querySelectorAll('.preprint-model-draggable.is-dragging, .preprint-live-draggable.is-dragging, .preprint-pdf-proxy-box.is-dragging, .preprint-pdf-drag-surface.is-dragging, .preprint-pdf-resize-handle.is-dragging').forEach(el => {
      el.classList.remove('is-dragging');
    });
    getPdfPreprintLiveDocument()?.querySelectorAll('.preprint-live-draggable.is-dragging').forEach(el => {
      el.classList.remove('is-dragging');
    });
  }
  pdfPreprintState.drag = null;
  if (hadDrag) {
    syncPdfPreprintLiveFrameDraftState();
    markPdfPreprintPreviewStale();
  }
}

function resetPdfPreprintLayout() {
  setPreprintValue('preprint-page-font', PDF_PREPRINT_DEFAULTS.pageFont);
  setPreprintValue('preprint-line-spacing', PDF_PREPRINT_DEFAULTS.lineSpacing);
  setPreprintValue('preprint-signature-font', PDF_PREPRINT_DEFAULTS.signatureFont);
  setPreprintValue('preprint-attendance-font', PDF_PREPRINT_DEFAULTS.attendanceFont);
  setPreprintValue('preprint-margin-top', PDF_PREPRINT_DEFAULTS.marginTop);
  setPreprintValue('preprint-margin-right', PDF_PREPRINT_DEFAULTS.marginRight);
  setPreprintValue('preprint-margin-bottom', PDF_PREPRINT_DEFAULTS.marginBottom);
  setPreprintValue('preprint-margin-left', PDF_PREPRINT_DEFAULTS.marginLeft);
  setPreprintValue('preprint-signature-x', PDF_PREPRINT_DEFAULTS.signatureX);
  setPreprintValue('preprint-signature-y', PDF_PREPRINT_DEFAULTS.signatureY);
  setPreprintValue('preprint-image-width', PDF_PREPRINT_DEFAULTS.imageWidth);
  setPreprintValue('preprint-image-height', PDF_PREPRINT_DEFAULTS.imageHeight);
  setPreprintValue('preprint-image-x', PDF_PREPRINT_DEFAULTS.imageX);
  setPreprintValue('preprint-image-y', PDF_PREPRINT_DEFAULTS.imageY);
  setPreprintValue('preprint-image-gap', PDF_PREPRINT_DEFAULTS.imageGap);
  const signatureConfig = PDF_PREPRINT_SIGNATURE_CONFIG[getPdfPreprintSignatureTarget()];
  setPdfPreprintDraftValue(signatureConfig.xKey, PDF_PREPRINT_DEFAULTS.signatureX);
  setPdfPreprintDraftValue(signatureConfig.yKey, PDF_PREPRINT_DEFAULTS.signatureY);
  PDF_PREPRINT_IMAGE_BASE_KEYS.forEach(baseKey => {
    const value = {
      width_px: PDF_PREPRINT_DEFAULTS.imageWidth,
      height_px: PDF_PREPRINT_DEFAULTS.imageHeight,
      offset_x: PDF_PREPRINT_DEFAULTS.imageX,
      offset_y: PDF_PREPRINT_DEFAULTS.imageY
    }[baseKey];
    setPdfPreprintDraftValue(getPdfPreprintImageKey(baseKey), value);
  });
  setPdfPreprintDraftValue('image_gap_px', PDF_PREPRINT_DEFAULTS.imageGap);
  ['pdf_font_size_pt', 'pdf_line_spacing', 'signature_font_size_pt', 'attendance_font_size_pt',
    'pdf_margin_top_px', 'pdf_margin_right_px', 'pdf_margin_bottom_px', 'pdf_margin_left_px']
    .forEach(key => pdfPreprintState.dirtyKeys.add(key));
  updatePdfPreprintLabels();
  syncPdfPreprintLiveFrameDraftState();
  markPdfPreprintPreviewStale('รีเซ็ตค่าแล้ว กดดูตัวอย่างใหม่เมื่อต้องการสร้าง PDF จริง');
}

function initializePdfPreprintModal() {
  if (pdfPreprintState.initialized) return;
  pdfPreprintState.initialized = true;

  const bindings = [
    ['preprint-page-font', 'pdf_font_size_pt'],
    ['preprint-line-spacing', 'pdf_line_spacing'],
    ['preprint-margin-top', 'pdf_margin_top_px'],
    ['preprint-margin-right', 'pdf_margin_right_px'],
    ['preprint-margin-bottom', 'pdf_margin_bottom_px'],
    ['preprint-margin-left', 'pdf_margin_left_px'],
    ['preprint-signature-font', 'signature_font_size_pt'],
    ['preprint-attendance-font', 'attendance_font_size_pt']
  ];
  bindings.forEach(([id, key]) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', () => markPdfPreprintDirty(key));
    el.addEventListener('change', () => markPdfPreprintDirty(key));
  });

  document.getElementById('preprint-signature-target')?.addEventListener('change', () => {
    loadSelectedPdfPreprintSignatureControls();
    syncPdfPreprintLiveFrameDraftState();
  });
  document.getElementById('preprint-signature-x')?.addEventListener('input', () => markPdfPreprintSignatureDirty('x'));
  document.getElementById('preprint-signature-y')?.addEventListener('input', () => markPdfPreprintSignatureDirty('y'));
  document.getElementById('preprint-signature-x')?.addEventListener('change', () => markPdfPreprintSignatureDirty('x'));
  document.getElementById('preprint-signature-y')?.addEventListener('change', () => markPdfPreprintSignatureDirty('y'));

  document.getElementById('preprint-image-target')?.addEventListener('change', () => {
    loadSelectedPdfPreprintImageControls();
    syncPdfPreprintLiveFrameDraftState();
  });
  [
    ['preprint-image-width', 'width_px'],
    ['preprint-image-height', 'height_px'],
    ['preprint-image-x', 'offset_x'],
    ['preprint-image-y', 'offset_y']
  ].forEach(([id, key]) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', () => markPdfPreprintImageDirty(key));
    el.addEventListener('change', () => markPdfPreprintImageDirty(key));
  });
  document.getElementById('preprint-image-gap')?.addEventListener('input', () => {
    setPdfPreprintDraftValue('image_gap_px', getPreprintValue('preprint-image-gap', 0, 72, PDF_PREPRINT_DEFAULTS.imageGap));
    updatePdfPreprintLabels();
    syncPdfPreprintLiveFrameDraftState();
    markPdfPreprintPreviewStale();
  });
  document.getElementById('preprint-image-gap')?.addEventListener('change', () => {
    setPdfPreprintDraftValue('image_gap_px', getPreprintValue('preprint-image-gap', 0, 72, PDF_PREPRINT_DEFAULTS.imageGap));
    updatePdfPreprintLabels();
    syncPdfPreprintLiveFrameDraftState();
    markPdfPreprintPreviewStale();
  });

  document.getElementById('preprint-show-attendance')?.addEventListener('change', () => {
    markPdfPreprintPreviewStale();
    schedulePdfPreprintHtmlPreview(100);
  });

  document.getElementById('preprint-refresh-preview')?.addEventListener('click', () => {
    refreshPdfPreprintHtmlPreview();
  });
  document.getElementById('preprint-reset-layout')?.addEventListener('click', resetPdfPreprintLayout);
  document.getElementById('preprint-save-settings')?.addEventListener('click', () => savePdfPreprintSettings(false));
  document.getElementById('preprint-confirm-print')?.addEventListener('click', () => savePdfPreprintSettings(true));
  document.querySelectorAll('[data-preprint-page]').forEach(button => {
    button.addEventListener('click', () => setPdfPreprintPreviewPage(button.dataset.preprintPage));
  });

  const stage = document.getElementById('preprint-image-stage');
  const box = document.getElementById('preprint-image-box');
  const handle = document.getElementById('preprint-image-resize');
	  const startDrag = (event, mode) => {
	    pdfPreprintState.drag = {
	      target: 'image',
	      source: 'stage',
	      mode,
	      startX: event.clientX,
	      startY: event.clientY,
      width: getPreprintValue('preprint-image-width', 180, 620, PDF_PREPRINT_DEFAULTS.imageWidth),
      height: getPreprintValue('preprint-image-height', 120, 560, PDF_PREPRINT_DEFAULTS.imageHeight),
      x: getPreprintValue('preprint-image-x', -220, 220, PDF_PREPRINT_DEFAULTS.imageX),
      y: getPreprintValue('preprint-image-y', -220, 220, PDF_PREPRINT_DEFAULTS.imageY)
    };
    event.preventDefault();
  };
  const signatureBox = document.getElementById('preprint-signature-box');
  const signatureStage = document.getElementById('preprint-signature-stage');
	  const startSignatureDrag = (event) => {
	    pdfPreprintState.drag = {
	      target: 'signature',
	      source: 'stage',
	      startX: event.clientX,
	      startY: event.clientY,
	      x: getPreprintValue('preprint-signature-x', -220, 220, PDF_PREPRINT_DEFAULTS.signatureX),
      y: getPreprintValue('preprint-signature-y', -220, 220, PDF_PREPRINT_DEFAULTS.signatureY)
    };
    event.preventDefault();
  };
  signatureBox?.addEventListener('pointerdown', startSignatureDrag);
  signatureStage?.addEventListener('pointerdown', event => {
    if (event.target === signatureStage) startSignatureDrag(event);
  });
  box?.addEventListener('pointerdown', event => {
    if (event.target === handle) return;
    startDrag(event, 'move');
  });
  handle?.addEventListener('pointerdown', event => startDrag(event, 'resize'));
	  stage?.addEventListener('pointerdown', event => {
	    if (event.target === stage) startDrag(event, 'move');
	  });
  document.getElementById('preprint-live-preview')?.addEventListener('pointerdown', handlePdfPreprintLivePointerDown);
  document.addEventListener('pointermove', handlePdfPreprintPointerMove);
  document.addEventListener('pointerup', handlePdfPreprintPointerUp);
  document.addEventListener('pointercancel', handlePdfPreprintPointerUp);
	}

async function openPdfPreprintSettings(recordId, outputMode = 'download') {
  initializePdfPreprintModal();
  const resolvedOutputMode = outputMode === 'print' ? 'print' : 'download';
  pdfPreprintState.recordId = recordId;
  pdfPreprintState.record = null;
  pdfPreprintState.download = resolvedOutputMode === 'download';
  pdfPreprintState.outputMode = resolvedOutputMode;
  pdfPreprintState.previewPage = 'all';
  pdfPreprintState.loadedSettings = {};
  pdfPreprintState.draftSettings = {};
  pdfPreprintState.dirtyKeys = new Set();
  pdfPreprintState.pdfBlob = null;
  pdfPreprintState.pdfPageTypes = [];
  pdfPreprintState.pdfPreviewDirty = true;
  pdfPreprintState.pdfRenderToken += 1;
  if (pdfPreprintState.previewController) {
    pdfPreprintState.previewController.abort();
    pdfPreprintState.previewController = null;
  }
  if (pdfPreprintState.htmlPreviewController) {
    pdfPreprintState.htmlPreviewController.abort();
    pdfPreprintState.htmlPreviewController = null;
  }
  window.clearTimeout(pdfPreprintState.htmlPreviewTimer);
  const canvasContainer = getPdfPreprintCanvasContainer();
  if (canvasContainer) {
    canvasContainer.innerHTML = '';
  }
  const liveFrame = getPdfPreprintLiveFrame();
  if (liveFrame) {
    liveFrame.removeAttribute('src');
    liveFrame.srcdoc = '';
  }

  const confirmButton = document.getElementById('preprint-confirm-print');
  if (confirmButton) {
    confirmButton.textContent = resolvedOutputMode === 'print'
      ? 'บันทึกและพิมพ์'
      : 'บันทึกและดาวน์โหลด PDF';
  }
  setPdfPreprintStatus('กำลังโหลดค่าของครู...');

  try {
    const [settingsRes, recordRes] = await Promise.all([
      apiCall('settings.php', 'GET'),
      apiCall(`records.php?id=${encodeURIComponent(recordId)}`, 'GET', null, true)
    ]);
    const settings = settingsRes?.status === 'success' ? (settingsRes.data || {}) : {};
    pdfPreprintState.record = recordRes?.status === 'success' ? recordRes.data : null;
    pdfPreprintState.loadedSettings = settings;
    pdfPreprintState.draftSettings = { ...settings };
    const pageFont = settings.pdf_font_size_pt !== undefined
      ? clampNumber(settings.pdf_font_size_pt, 10, 20, PDF_PREPRINT_DEFAULTS.pageFont)
      : inferPdfFontSizePtFromOffset(settings.font_size_offset);
    setPreprintValue('preprint-page-font', pageFont);
    setPreprintValue('preprint-line-spacing', clampNumber(settings.pdf_line_spacing, 20, 40, PDF_PREPRINT_DEFAULTS.lineSpacing));
    setPreprintValue('preprint-signature-font', clampNumber(settings.signature_font_size_pt, 10, 20, pageFont));
    setPreprintValue('preprint-attendance-font', clampNumber(settings.attendance_font_size_pt, 10, 20, pageFont));
    setPreprintValue('preprint-margin-top', clampNumber(settings.pdf_margin_top_px, 20, 140, PDF_PREPRINT_DEFAULTS.marginTop));
    setPreprintValue('preprint-margin-right', clampNumber(settings.pdf_margin_right_px, 20, 160, PDF_PREPRINT_DEFAULTS.marginRight));
    setPreprintValue('preprint-margin-bottom', clampNumber(settings.pdf_margin_bottom_px, 20, 140, PDF_PREPRINT_DEFAULTS.marginBottom));
    setPreprintValue('preprint-margin-left', clampNumber(settings.pdf_margin_left_px, 20, 160, PDF_PREPRINT_DEFAULTS.marginLeft));
    setPreprintValue('preprint-signature-target', 'sig1');
    setPreprintValue('preprint-image-target', 'all');
    loadSelectedPdfPreprintSignatureControls();
    loadSelectedPdfPreprintImageControls();
    updatePdfPreprintLabels();
    openModal('modal-pdf-preprint');
    renderPdfPreprintLivePreview();
    refreshPdfPreprintHtmlPreview();
  } catch (error) {
    console.error('Preprint settings load error', error);
    pdfPreprintState.record = null;
    setPdfPreprintStatus('โหลดค่าไม่สำเร็จ');
    openModal('modal-pdf-preprint');
    updatePdfPreprintLabels();
    refreshPdfPreprintHtmlPreview();
  }
}

async function savePdfPreprintSettings(continueToOutput = false) {
  const button = continueToOutput
    ? document.getElementById('preprint-confirm-print')
    : document.getElementById('preprint-save-settings');
  const originalButtonText = button ? button.innerHTML : '';
  if (button) {
    button.disabled = true;
    button.innerHTML = continueToOutput
      ? '<span class="btn-spinner"></span> กำลังเตรียม...'
      : '<span class="btn-spinner"></span> กำลังบันทึก...';
  }
  setPdfPreprintStatus('กำลังบันทึกค่า...');
  if (continueToOutput) {
    setPdfExportLoading(true, 'กำลังบันทึกค่าก่อนสร้างเอกสาร...', 'กำลังเตรียม PDF');
  }
  let outputStarted = false;
  try {
    const outputPayload = collectPdfPreprintPayload();
    const settingsPayload = collectPdfPreprintSettingsPayload();
    const res = await apiCall('settings.php', 'POST', settingsPayload);
    if (res?.status !== 'success') {
      throw new Error(res?.message || 'Save failed');
    }
    showToast('บันทึกค่าตั้งค่า PDF สำเร็จ', 'success');
    pdfPreprintState.loadedSettings = { ...pdfPreprintState.loadedSettings, ...settingsPayload };
    pdfPreprintState.dirtyKeys = new Set([...pdfPreprintState.dirtyKeys].filter(key => (
      PDF_PREPRINT_DOCUMENT_DRAFT_KEYS.has(key)
    )));
    setPdfPreprintStatus('บันทึกแล้ว');
    if (continueToOutput) {
      outputStarted = true;
      if (pdfPreprintState.outputMode === 'print') {
        await printRecordFromPreview(pdfPreprintState.recordId, outputPayload);
      } else {
        await downloadPdfForRecord(pdfPreprintState.recordId, outputPayload);
      }
      closeModal('modal-pdf-preprint');
    } else {
      refreshPdfPreprintHtmlPreview();
    }
  } catch (error) {
    console.error('Preprint settings save error', error);
    if (outputStarted) {
      setPdfPreprintStatus('สร้างเอกสารไม่สำเร็จ');
    } else {
      showToast('บันทึกค่าตั้งค่า PDF ไม่สำเร็จ', 'error');
      setPdfPreprintStatus('บันทึกไม่สำเร็จ');
    }
  } finally {
    if (continueToOutput) {
      setPdfExportLoading(false);
    }
    if (button) {
      button.disabled = false;
      button.innerHTML = originalButtonText;
    }
  }
}

async function startApp() {
  invalidateRecordsCache();
  await loadTambons();
  document.getElementById('login-page').style.display = 'none';
  document.getElementById('app').style.display = 'block';
  renderTopbar();
  renderSidebarNav();
  
  if (currentUser.role === 'district') {
    showPage('dashboard');
    renderDashboard();
    renderTambonGrid();
    renderAdminTable();
  } else {
    showPage('my-records');
    renderMyRecords();
  }
}

function renderTopbar() {
  const avatar = document.getElementById('user-avatar');
  if (avatar) {
    avatar.textContent = '';
    if (currentUser.avatar_path) {
      const image = document.createElement('img');
      image.src = currentUser.avatar_path;
      image.alt = `รูปโปรไฟล์ ${currentUser.name || ''}`;
      image.loading = 'lazy';
      avatar.appendChild(image);
    } else {
      avatar.textContent = (currentUser.name || 'A').charAt(0);
    }
  }
  document.getElementById('user-display-name').textContent = currentUser.name;
  const tambon = currentUser.role === 'tambon'
    ? getTambonById(currentUser.tambon)?.name || ''
    : 'อำเภอเสนา';
  document.getElementById('user-role-display').textContent =
    currentUser.role === 'district' ? '👑 ผู้ดูแลอำเภอ' : `📍 ตำบล${tambon}`;
  applyAppLogo(appLogoPath);
  syncSettingsMediaPreview();
}

function applyAppLogo(path) {
  const normalized = String(path || '').trim();
  document.querySelectorAll('.login-logo .logo-icon, .topbar-brand .icon, #logo-preview').forEach(container => {
    if (!container) return;
    if (!normalized) {
      if (container.id === 'logo-preview') container.textContent = '📚';
      return;
    }
    container.innerHTML = `<img src="${escapeHtml(normalized)}" alt="โลโก้หน่วยงาน" loading="lazy">`;
  });
}

function renderSidebarNav() {
  const nav = document.getElementById('sidebar-nav');
  let html = '';
  if (currentUser.role === 'district') {
    html = `
      <div class="sidebar-section" style="padding-top:0; margin-top:-2px;">ภาพรวม</div>
      <a class="nav-link active" data-target="dashboard"><span class="nav-icon">${appIcon('home')}</span>หน้าหลัก</a>
      <a class="nav-link" data-target="tambons"><span class="nav-icon">${appIcon('groups')}</span>กลุ่มเรียนทั้งหมด</a>
      <a class="nav-link" data-target="student-analysis"><span class="nav-icon">${appIcon('chart')}</span>วิเคราะห์ผู้เรียน</a>
      <div class="sidebar-section">จัดการ</div>
      <a class="nav-link" data-target="tambon-mgmt"><span class="nav-icon">${appIcon('groups')}</span>จัดการกลุ่มเรียน</a>
      <a class="nav-link" data-target="admin-mgmt"><span class="nav-icon">${appIcon('users')}</span>จัดการผู้ดูแล</a>
      <a class="nav-link" data-target="print-settings"><span class="nav-icon">${appIcon('pdf')}</span>ตั้งค่า PDF</a>
      <a class="nav-link" data-target="settings"><span class="nav-icon">${appIcon('settings')}</span>เข้ารหัส/ตั้งค่าทั่วไป</a>
    `;
  } else {
    const t = getTambonById(currentUser.tambon);
    html = `
      <div class="sidebar-section">ตำบล${t?.name||''}</div>
      <a class="nav-link active" data-target="my-records"><span class="nav-icon">${appIcon('file')}</span>บันทึกหลังการสอน</a>
      <a class="nav-link" data-action="new-record"><span class="nav-icon">${appIcon('plus')}</span>เพิ่มบันทึกหลังแผนใหม่</a>
      <a class="nav-link" data-target="student-analysis"><span class="nav-icon">${appIcon('chart')}</span>วิเคราะห์ผู้เรียน</a>
      <a class="nav-link" data-target="print-settings"><span class="nav-icon">${appIcon('pdf')}</span>ตั้งค่า PDF</a>
      <a class="nav-link" data-target="settings"><span class="nav-icon">${appIcon('settings')}</span>ตั้งค่า</a>
    `;
  }
  nav.innerHTML = html;
  
  document.querySelectorAll('.nav-link').forEach(a => {
      a.addEventListener('click', e => {
          const target = e.currentTarget.getAttribute('data-target');
          const action = e.currentTarget.getAttribute('data-action');

          if (action === 'new-record') {
            showPage('my-records');
            renderMyRecords();

            document.querySelector('.sidebar')?.classList.remove('open');
            document.getElementById('sidebarOverlay')?.classList.remove('show');

            openNewRecord();
            return;
          }

          if (!target) return;
          showPage(target);
          
          document.querySelector('.sidebar')?.classList.remove('open');
          document.getElementById('sidebarOverlay')?.classList.remove('show');
          
          if (target === 'dashboard') renderDashboard();
          if (target === 'tambons') renderTambonGrid();
          if (target === 'tambon-mgmt') renderTambonMgmtTable();
          if (target === 'admin-mgmt') renderAdminTable();
          if (target === 'my-records') renderMyRecords();
          if (target === 'student-analysis') renderStudentAnalysis();
          if (target === 'print-settings') loadPrintSettings();
      });
  });
}

function showPage(id) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.getElementById('page-' + id)?.classList.add('active');
  document.querySelectorAll('#sidebar-nav a').forEach(a => {
      a.classList.remove('active');
      if (a.getAttribute('data-target') === id) a.classList.add('active');
  });
}

// ============================================================
//  MONTHLY STUDENTS CARD (shared between dashboard & my-records)
// ============================================================
function renderMonthlyStudentsCard(containerId, monthlyData, totalUnique) {
  const container = document.getElementById(containerId);
  if (!container) return;

  if (!monthlyData || monthlyData.length === 0) {
    container.innerHTML = '';
    return;
  }

  const maxCount = Math.max(...monthlyData.map(m => m.count), 1);
  const monthCount = monthlyData.length;
  const peakMonth = monthlyData.reduce((best, item) => Number(item.count || 0) > Number(best.count || 0) ? item : best, monthlyData[0]);

  const rows = monthlyData.map((m, index) => {
    const count = Number(m.count || 0);
    const pct = Math.round((count / maxCount) * 100);
    const tone = index % 3 === 0 ? 'blue' : (index % 3 === 1 ? 'green' : 'gold');
    return `
      <div class="monthly-student-row monthly-student-row--${tone}">
        <div class="monthly-student-month">
          <span class="monthly-student-dot"></span>
          <span class="monthly-student-label">${escapeHtml(m.label)}</span>
        </div>
        <div class="monthly-student-meter" aria-label="${escapeHtml(m.label)} ${count} คน">
          <div class="monthly-student-track">
            <div class="monthly-student-bar" style="width:${pct}%"></div>
          </div>
          <span class="monthly-student-percent">${pct}%</span>
        </div>
        <span class="monthly-student-count">${count} คน</span>
      </div>`;
  }).join('');

  container.innerHTML = `
    <div class="card monthly-students-card monthly-students-card--modern">
      <div class="monthly-students-header">
        <div class="monthly-students-heading">
          <span class="monthly-students-icon">${appIcon('chart')}</span>
          <div>
            <h3>ผู้เรียนสะสมรายเดือน</h3>
            <p>สรุปจำนวนผู้เรียนไม่ซ้ำ แยกตามเดือนที่มีการบันทึก</p>
          </div>
        </div>
        <div class="monthly-students-total">
          <span>${Number(totalUnique || 0).toLocaleString('th-TH')}</span>
          <small>คน (ไม่ซ้ำ)</small>
        </div>
      </div>
      <div class="monthly-students-summary">
        <div>
          <strong>${monthCount}</strong>
          <span>เดือนที่มีข้อมูล</span>
        </div>
        <div>
          <strong>${Number(peakMonth?.count || 0).toLocaleString('th-TH')}</strong>
          <span>สูงสุด: ${escapeHtml(peakMonth?.label || '-')}</span>
        </div>
      </div>
      <div class="monthly-students-body">
        ${rows}
      </div>
    </div>
  `;
}

// ============================================================
//  DASHBOARD (DISTRICT)
// ============================================================
async function renderDashboard() {
  const res = await queryCached(['dashboard', 'stats', currentUser?.id || ''], () => apiCall('dashboard.php?action=stats'), {
    staleTime: 30000
  });
  if (!res || res.status !== 'success') return;
  
  const d = res.data;
  dashboardState = d;
  const total = d.stats.total;
  const thisMonth = d.stats.thisMonth;
  const totalStudents = d.stats.totalStudents;
  const uniqueStudents = d.stats.uniqueStudents || 0;
  const allRecords = await fetchAllRecords('', '');
  const classGroups = getClassGroupSummaries(allRecords);
  dashboardState.allRecords = allRecords;

  document.getElementById('dashboard-stats').innerHTML = `
    <div class="stat-card">
      <div class="stat-icon blue">${appIcon('editFile')}</div>
      <div class="stat-info"><div class="num">${total}</div><div class="label">บันทึกทั้งหมด</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon gold">${appIcon('calendar')}</div>
      <div class="stat-info"><div class="num">${thisMonth}</div><div class="label">บันทึกเดือนนี้</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon green">${appIcon('building')}</div>
      <div class="stat-info"><div class="num">${classGroups.length}</div><div class="label">กลุ่มเรียนที่มีบันทึก</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon purple">${appIcon('users')}</div>
      <div class="stat-info"><div class="num">${totalStudents}</div><div class="label">ผู้เรียนสะสม (ไม่ซ้ำ)</div></div>
    </div>
  `;

  // Monthly students card
  renderMonthlyStudentsCard('dashboard-monthly-students', d.monthlyStudents || [], totalStudents);

  // Recent records
  const recent = d.recentRecords || [];
  const rtbody = document.getElementById('recent-records-tbody');
  if (recent.length === 0) {
    rtbody.innerHTML = `<tr><td colspan="4" style="text-align:center;padding:24px;color:#aaa;">ยังไม่มีข้อมูล</td></tr>`;
  } else {
    rtbody.innerHTML = recent.map(r => {
      return `<tr>
        <td><span class="badge badge-blue">${escapeHtml(getRecordGroupLabel(r))}</span></td>
        <td style="font-size:13px;">${escapeHtml(r.subject||'-')}<br><small style="color:#aaa;">${escapeHtml([r.subject_code, cleanGroupDisplayName(r.class_name)].filter(Boolean).join(' | '))}</small></td>
        <td><span class="badge badge-gold">สัปดาห์ ${r.week}</span></td>
        <td style="font-size:13px;">${fmtDate(r.date)}</td>
      </tr>`;
    }).join('');
  }

  // Tambon status
  const tList = document.getElementById('tambon-status-list');
  const statusGroups = classGroups.slice(0, 12);
  tList.innerHTML = statusGroups.length ? statusGroups.map(group => {
    return `<div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f0f0f0;">
      <span style="font-size:13px;">${escapeHtml(group.label)}</span>
      <span class="badge ${group.count > 0 ? 'badge-green' : 'badge-gray'}">${group.count} บันทึก</span>
    </div>`;
  }).join('') : '<div class="record-empty-row">ยังไม่มีกลุ่มเรียนที่มีบันทึก</div>';

  filterAllRecords();
}

async function fetchAllRecords(tambon = '', search = '') {
    const resolvedTambon = currentUser?.role === 'tambon'
      ? (currentUser.tambon || '')
      : (tambon || '');
    const cacheKey = buildRecordsCacheKey(resolvedTambon, search);
    if (recordsCache.has(cacheKey)) {
        return recordsCache.get(cacheKey);
    }
    let url = 'records.php?';
    if (resolvedTambon) url += `tambon=${encodeURIComponent(resolvedTambon)}&`;
    if (search) url += `search=${encodeURIComponent(search)}`;
    const res = await queryCached(
      ['records', currentUser?.role || '', currentUser?.id || '', resolvedTambon || 'all', (search || '').trim().toLowerCase()],
      () => apiCall(url),
      { staleTime: 30000 }
    );
    if (res && res.status === 'success') {
        // New API format: {records: [...], uniqueStudents: N, monthlyStudents: [...]}
        const d = res.data;
        const records = Array.isArray(d) ? d : (d.records || []);
        recordsCache.set(cacheKey, records);
        // Store metadata (unique students, monthly breakdown)
        if (!Array.isArray(d)) {
            recordsMetaCache.set(cacheKey, {
                uniqueStudents: d.uniqueStudents || 0,
                monthlyStudents: d.monthlyStudents || []
            });
        }
        return records;
    }
    return [];
}

function getRecordsMetadata(tambon = '', search = '') {
    const resolvedTambon = currentUser?.role === 'tambon'
      ? (currentUser.tambon || '')
      : (tambon || '');
    const cacheKey = buildRecordsCacheKey(resolvedTambon, search);
    return recordsMetaCache.get(cacheKey) || { uniqueStudents: 0, monthlyStudents: [] };
}

function recordHasImages(record) {
  return Array.isArray(record?.images) && record.images.length > 0;
}

function cleanGroupDisplayName(name) {
  return String(name || '').trim().replace(/\s*\(\d{4,}\)\s*$/u, '').trim();
}

function getRecordGroupLabel(record) {
  const name = cleanGroupDisplayName(record?.class_name || '');
  if (name) return name;
  const tambon = getTambonById(record?.tambon);
  if (tambon?.name) return `ศกร.ระดับตำบล${tambon.name}`;
  return 'ไม่ระบุกลุ่มเรียน';
}

function getRecordGroupKey(record) {
  const name = cleanGroupDisplayName(record?.class_name || '');
  const tambon = String(record?.tambon || '').trim();
  if (name) return `class:${tambon || '-'}|${name.toLowerCase()}`;
  const code = String(record?.class_group_code || '').trim();
  return `code:${code || '-'}`;
}

function getClassGroupSummaries(records) {
  const groups = new Map();
  (records || []).forEach(record => {
    const key = getRecordGroupKey(record);
    const baseName = getRecordGroupLabel(record);
    const code = String(record?.class_group_code || '').trim();
    if (!groups.has(key)) {
      const tambon = getTambonById(record?.tambon);
      groups.set(key, {
        key,
        label: baseName,
        baseName,
        code,
        codes: new Set(),
        icon: tambon?.icon || '🏘️',
        tambonId: record?.tambon || '',
        tambonLabel: tambon?.name || record?.tambon || '',
        count: 0,
        latestDate: '',
        records: []
      });
    }
    const group = groups.get(key);
    group.count += 1;
    group.records.push(record);
    if (code) group.codes.add(code);
    if (!group.latestDate || String(record?.date || '') > group.latestDate) {
      group.latestDate = String(record?.date || '');
    }
  });
  return [...groups.values()].map(group => {
    const codes = [...group.codes].sort((a, b) => a.localeCompare(b, 'th'));
    const displayCode = codes[0] || group.code || '';
    return {
      ...group,
      code: displayCode,
      codes,
      label: group.baseName
    };
  }).sort((a, b) => {
    if (b.count !== a.count) return b.count - a.count;
    return a.label.localeCompare(b.label, 'th');
  });
}

function syncGroupSelect(selectId, records) {
  const select = document.getElementById(selectId);
  if (!select) return;
  const currentValue = select.value;
  const groups = getClassGroupSummaries(records);
  select.innerHTML = '<option value="">ทุกกลุ่มเรียน</option>' +
    groups.map(group => `<option value="${escapeHtml(group.key)}">${escapeHtml(group.label)}</option>`).join('');
  select.value = groups.some(group => group.key === currentValue) ? currentValue : '';
}

function getRecordWeekOptions(records) {
  return [...new Set((records || [])
    .map(record => parseInt(record.week, 10))
    .filter(week => Number.isFinite(week)))]
    .sort((a, b) => a - b);
}

function syncWeekSelect(selectId, records) {
  const select = document.getElementById(selectId);
  if (!select) return;
  const currentValue = select.value;
  const options = getRecordWeekOptions(records);
  select.innerHTML = '<option value="">ทุกสัปดาห์</option>' +
    options.map(week => `<option value="${week}">สัปดาห์ ${week}</option>`).join('');
  select.value = options.some(week => String(week) === String(currentValue)) ? currentValue : '';
}

function filterRecordsForView(records, options = {}) {
  const week = String(options.week || '');
  const imageStatus = String(options.imageStatus || '');
  return (records || []).filter(record => {
    if (week && String(record.week) !== week) return false;
    if (imageStatus === 'with' && !recordHasImages(record)) return false;
    if (imageStatus === 'without' && recordHasImages(record)) return false;
    return true;
  });
}

function getSelectedRecordsForScope(scope) {
  const isMine = scope === 'my';
  const viewRecords = isMine ? lastMyRecordsView : lastAllRecordsView;
  const selectedSet = isMine ? selectedMyRecordIds : selectedRecordIds;
  const selected = viewRecords.filter(record => selectedSet.has(String(record.id)));
  return selected.length ? selected : viewRecords;
}

function updateRecordSelectionUi(scope) {
  const isMine = scope === 'my';
  const viewRecords = isMine ? lastMyRecordsView : lastAllRecordsView;
  const selectedSet = isMine ? selectedMyRecordIds : selectedRecordIds;
  const countEl = document.getElementById(isMine ? 'my-records-selected-count' : 'all-records-selected-count');
  const selectAll = document.getElementById(isMine ? 'select-my-records' : 'select-all-records');
  const visibleIds = new Set(viewRecords.map(record => String(record.id)));
  [...selectedSet].forEach(id => {
    if (!visibleIds.has(id)) selectedSet.delete(id);
  });
  const selectedCount = viewRecords.filter(record => selectedSet.has(String(record.id))).length;
  if (countEl) {
    countEl.textContent = selectedCount > 0
      ? `เลือกแล้ว ${selectedCount} รายการ`
      : `พร้อมดาวน์โหลด ${viewRecords.length} รายการที่แสดง`;
  }
  if (selectAll) {
    selectAll.checked = viewRecords.length > 0 && selectedCount === viewRecords.length;
    selectAll.indeterminate = selectedCount > 0 && selectedCount < viewRecords.length;
  }
}

function toggleRecordSelection(scope, id, checked) {
  const selectedSet = scope === 'my' ? selectedMyRecordIds : selectedRecordIds;
  const normalizedId = String(id || '');
  if (!normalizedId) return;
  if (checked) selectedSet.add(normalizedId);
  else selectedSet.delete(normalizedId);
  updateRecordSelectionUi(scope);
}

function toggleAllVisibleRecords(scope, checked) {
  const isMine = scope === 'my';
  const viewRecords = isMine ? lastMyRecordsView : lastAllRecordsView;
  const selectedSet = isMine ? selectedMyRecordIds : selectedRecordIds;
  viewRecords.forEach(record => {
    const id = String(record.id);
    if (checked) selectedSet.add(id);
    else selectedSet.delete(id);
  });
  if (isMine) renderMyRecordsTable(lastMyRecordsView, { alreadyFiltered: true });
  else renderAllRecordsTable(lastAllRecordsView, { alreadyFiltered: true });
}

function downloadUrl(url) {
  const a = document.createElement('a');
  a.href = url;
  a.rel = 'noopener';
  document.body.appendChild(a);
  a.click();
  a.remove();
}

function setExportButtonLoading(button, loading, label) {
  if (!button) return;
  if (!button.dataset.defaultHtml) {
    button.dataset.defaultHtml = button.innerHTML;
  }
  button.disabled = loading;
  button.classList.toggle('is-loading', loading);
  button.setAttribute('aria-busy', loading ? 'true' : 'false');
  button.innerHTML = loading
    ? `<span class="export-btn-spinner" aria-hidden="true"></span><span>${escapeHtml(label)}</span>`
    : button.dataset.defaultHtml;
}

function setBulkDownloadLoading(scope, format, loading, count = 0) {
  const isMine = scope === 'my';
  const pdfButton = document.getElementById(isMine ? 'download-my-selected-pdf' : 'download-selected-pdf');
  const wordButton = document.getElementById(isMine ? 'download-my-selected-doc' : 'download-selected-doc');
  const targetButton = format === 'docx' ? wordButton : pdfButton;
  const otherButton = format === 'docx' ? pdfButton : wordButton;
  const formatLabel = format === 'docx' ? 'Word .docx' : 'PDF';
  setExportButtonLoading(targetButton, loading, loading ? `กำลังรวม ZIP ${formatLabel}...` : '');
  if (otherButton) {
    otherButton.disabled = loading;
    otherButton.classList.toggle('is-export-disabled', loading);
  }
  const countEl = document.getElementById(isMine ? 'my-records-selected-count' : 'all-records-selected-count');
  if (countEl) {
    if (loading) {
      countEl.dataset.beforeDownloadText = countEl.textContent || '';
      countEl.textContent = `กำลังรวมไฟล์ ZIP ${count} รายการ กรุณารอสักครู่`;
      countEl.classList.add('record-selected-count--loading');
    } else if (countEl.dataset.beforeDownloadText) {
      countEl.textContent = countEl.dataset.beforeDownloadText;
      delete countEl.dataset.beforeDownloadText;
      countEl.classList.remove('record-selected-count--loading');
    }
  }
}

async function downloadSelectedRecordsAsPdf(scope) {
  const records = getSelectedRecordsForScope(scope);
  if (!records.length) {
    showToast('ยังไม่มีรายการสำหรับดาวน์โหลด PDF', 'error');
    return;
  }
  if (records.length === 1) {
    showToast('กำลังดาวน์โหลด PDF 1 รายการ', 'info');
    await downloadPdfForRecord(records[0].id);
    return;
  }

  const params = new URLSearchParams();
  params.set('format', 'pdf');
  params.set('ids', records.map(record => record.id).join(','));
  showToast(`กำลังเตรียมไฟล์ ZIP รวม PDF ${records.length} รายการ`, 'info');
  setBulkDownloadLoading(scope, 'pdf', true, records.length);
  try {
    const res = await fetch(`api/export_bundle.php?${params.toString()}`, { credentials: 'same-origin' });
    if (!res.ok) {
      const message = await res.text().catch(() => '');
      throw new Error(message || `PDF bundle export failed: ${res.status}`);
    }
    const blob = await res.blob();
    const filename = getFilenameFromDisposition(
      res.headers.get('content-disposition'),
      `บันทึกหลังสอน_PDF_${new Date().toISOString().slice(0, 10)}.zip`,
      'zip'
    );
    saveBlobAsDownload(blob, filename);
    showToast(`ดาวน์โหลด ZIP รวม PDF ${records.length} รายการแล้ว`, 'success');
  } catch (error) {
    console.error('PDF bundle export failed', error);
    showToast('ดาวน์โหลด ZIP รวม PDF ไม่สำเร็จ', 'error');
  } finally {
    setBulkDownloadLoading(scope, 'pdf', false);
  }
}

async function downloadSelectedRecordsAsDoc(scope) {
  const records = getSelectedRecordsForScope(scope);
  if (!records.length) {
    showToast('ยังไม่มีรายการสำหรับดาวน์โหลด Word', 'error');
    return;
  }
  const params = new URLSearchParams();
  params.set('ids', records.map(record => record.id).join(','));
  const endpoint = records.length > 1 ? 'api/export_bundle.php' : 'api/export_doc.php';
  if (records.length > 1) params.set('format', 'docx');
  showToast(records.length > 1
    ? `กำลังเตรียมไฟล์ ZIP รวม Word ${records.length} รายการ`
    : 'กำลังเตรียมไฟล์ Word 1 รายการ', 'info');
  if (records.length > 1) {
    setBulkDownloadLoading(scope, 'docx', true, records.length);
  }
  try {
    const res = await fetch(`${endpoint}?${params.toString()}`, { credentials: 'same-origin' });
    if (!res.ok) {
      const message = await res.text().catch(() => '');
      throw new Error(message || `Word export failed: ${res.status}`);
    }
    const blob = await res.blob();
    const extension = records.length > 1 ? 'zip' : 'docx';
    const filename = getFilenameFromDisposition(
      res.headers.get('content-disposition'),
      `บันทึกหลังสอน_${new Date().toISOString().slice(0, 10)}.${extension}`,
      extension
    );
    saveBlobAsDownload(blob, filename);
    showToast(records.length > 1
      ? `ดาวน์โหลด ZIP รวม Word ${records.length} รายการแล้ว`
      : 'ดาวน์โหลด Word แล้ว', 'success');
  } catch (error) {
    console.error('Word export failed', error);
    showToast('ดาวน์โหลด Word ไม่สำเร็จ', 'error');
  } finally {
    if (records.length > 1) {
      setBulkDownloadLoading(scope, 'docx', false);
    }
  }
}

window.toggleRecordSelection = toggleRecordSelection;

async function filterAllRecords() {
  const selectedGroup = document.getElementById('filter-tambon-select')?.value || '';
  const search = document.getElementById('search-all')?.value || '';
  const week = document.getElementById('filter-week-select')?.value || '';
  const imageStatus = document.getElementById('filter-image-select')?.value || '';
  const records = await fetchAllRecords('', search);
  syncGroupSelect('filter-tambon-select', records);
  const groupFiltered = selectedGroup
    ? records.filter(record => getRecordGroupKey(record) === selectedGroup)
    : records;
  syncWeekSelect('filter-week-select', groupFiltered);
  const filteredRecords = filterRecordsForView(groupFiltered, { week, imageStatus });
  renderAllRecordsTable(filteredRecords, { alreadyFiltered: true });
}

function renderAllRecordsTable(records) {
  lastAllRecordsView = records || [];
  const tbody = document.getElementById('all-records-tbody');
  if (!tbody) return;
  if (!lastAllRecordsView.length) {
    tbody.innerHTML = `<tr><td colspan="8" class="record-empty-row">ยังไม่มีบันทึกตามเงื่อนไขที่เลือก</td></tr>`;
    updateRecordSelectionUi('all');
    return;
  }
  tbody.innerHTML = lastAllRecordsView.map(r => {
    const id = String(r.id);
    const checked = selectedRecordIds.has(id) ? 'checked' : '';
    const tambon = getTambonById(r.tambon);
    return `<tr>
      <td class="record-select-col">
        <input type="checkbox" class="record-row-check" ${checked} onchange="toggleRecordSelection('all','${escapeJsString(id)}', this.checked)" aria-label="เลือกบันทึก ${escapeHtml(r.subject || '')}">
      </td>
      <td><span class="badge badge-blue">${escapeHtml(getRecordGroupLabel(r))}</span><br><small style="color:#8a97ad;">${escapeHtml(tambon?.name || r.tambon || '')}</small></td>
      <td style="font-size:13px;">${escapeHtml(r.subject||'-')}<br><small style="color:#aaa;">${escapeHtml([r.subject_code, cleanGroupDisplayName(r.class_name)].filter(Boolean).join(' | '))}</small></td>
      <td><span class="badge badge-gold">สัปดาห์ ${r.week}</span></td>
      <td style="font-size:13px;">${fmtDate(r.date)}</td>
      <td style="font-size:13px;">${r.total||0} / <span style="color:var(--success);font-weight:600;">${r.present||0}</span></td>
      <td><span class="badge ${r.images && r.images.length ? 'badge-green' : 'badge-gray'}">${r.images && r.images.length ? '📷 '+r.images.length+' รูป' : 'ไม่มีรูป'}</span></td>
      <td>
        <button class="btn btn-sm btn-primary" onclick="viewRecord('${r.id}')">👁️ ดู</button>
      </td>
    </tr>`;
  }).join('');
  updateRecordSelectionUi('all');
}

document.getElementById('search-all')?.addEventListener('input', debounce(filterAllRecords, 250));
document.getElementById('filter-tambon-select')?.addEventListener('change', filterAllRecords);
document.getElementById('filter-week-select')?.addEventListener('change', filterAllRecords);
document.getElementById('filter-image-select')?.addEventListener('change', filterAllRecords);
document.getElementById('select-all-records')?.addEventListener('change', e => toggleAllVisibleRecords('all', e.target.checked));
document.getElementById('download-selected-pdf')?.addEventListener('click', () => downloadSelectedRecordsAsPdf('all'));
document.getElementById('download-selected-doc')?.addEventListener('click', () => downloadSelectedRecordsAsDoc('all'));

function populateTambonFilters() {
  const sel = document.getElementById('filter-tambon-select');
  if (!sel) return;
  sel.innerHTML = `<option value="">ทุกกลุ่มเรียน</option>`;
}

// ============================================================
//  CLASS GROUP GRID
// ============================================================
async function renderTambonGrid() {
  const records = dashboardState?.allRecords || await fetchAllRecords();
  if (dashboardState && !dashboardState.allRecords) {
    dashboardState.allRecords = records;
  }
  const groups = getClassGroupSummaries(records);
  const grid = document.getElementById('tambon-grid');
  if (!grid) return;
  if (!groups.length) {
    grid.innerHTML = '<div class="empty-state group-empty-state"><div class="es-icon">🏘️</div><p>ยังไม่มีกลุ่มเรียนที่มีบันทึก</p></div>';
    return;
  }
  grid.innerHTML = groups.map(group => {
    return `<div class="tambon-card" onclick="viewGroupRecords('${escapeJsString(group.key)}')">
      <div class="t-icon">${escapeHtml(group.icon || '🏘️')}</div>
      <div class="t-name">${escapeHtml(group.label)}</div>
      <div class="t-count">${group.count} รายการ</div>
      <div class="t-meta">${escapeHtml(group.tambonLabel || 'ไม่ระบุตำบล')}</div>
      <span class="t-status has-records"></span>
    </div>`;
  }).join('');
}

async function viewGroupRecords(groupKey) {
  const records = await fetchAllRecords('', '');
  const group = getClassGroupSummaries(records).find(item => item.key === groupKey);
  const groupRecords = records.filter(record => getRecordGroupKey(record) === groupKey);
  document.getElementById('modal-view-body').innerHTML = `
    <h3 style="margin-bottom:16px;">🏘️ ${escapeHtml(group?.label || 'กลุ่มเรียน')} | ${groupRecords.length} รายการ</h3>
    ${groupRecords.length === 0 ? '<div class="empty-state"><div class="es-icon">📝</div><p>ยังไม่มีบันทึก</p></div>' :
    `<div class="table-responsive">
      <table style="width:100%;border-collapse:collapse;">
        <thead><tr>
          <th style="padding:8px;background:#f5f8fd;font-size:12px;text-align:left;">สัปดาห์</th>
          <th style="padding:8px;background:#f5f8fd;font-size:12px;text-align:left;">วันที่</th>
          <th style="padding:8px;background:#f5f8fd;font-size:12px;text-align:left;">รายวิชา</th>
          <th style="padding:8px;background:#f5f8fd;font-size:12px;text-align:left;">ผู้เรียน</th>
          <th style="padding:8px;background:#f5f8fd;font-size:12px;"></th>
        </tr></thead>
        <tbody>${groupRecords.map(r=>`
          <tr>
            <td style="padding:8px;border-bottom:1px solid #f0f0f0;">สัปดาห์ ${r.week}</td>
            <td style="padding:8px;border-bottom:1px solid #f0f0f0;font-size:13px;">${fmtDate(r.date)}</td>
            <td style="padding:8px;border-bottom:1px solid #f0f0f0;font-size:13px;">${escapeHtml(r.subject)}<br><small style="color:#888;">${escapeHtml([r.subject_code, getRecordGroupLabel(r)].filter(Boolean).join(' | '))}</small></td>
            <td style="padding:8px;border-bottom:1px solid #f0f0f0;font-size:13px;">${r.total}/${r.present}</td>
            <td style="padding:8px;border-bottom:1px solid #f0f0f0;">
              <button class="btn btn-sm btn-primary" onclick="viewRecord('${r.id}')">ดู</button>
            </td>
          </tr>`).join('')}
        </tbody>
      </table>
    </div>`}
  `;
  setRecordModalActionsVisible(false);
  openModal('modal-view');
}

async function viewTambonRecords(tambonId) {
  const t = getTambonById(tambonId);
  const records = await fetchAllRecords(tambonId);
  document.getElementById('modal-view-body').innerHTML = `
    <h3 style="margin-bottom:16px;">${escapeHtml(t?.icon || '')} ตำบล${escapeHtml(t?.name || '')} | ${records.length} รายการ</h3>
    ${records.length === 0 ? '<div class="empty-state"><div class="es-icon">📝</div><p>ยังไม่มีบันทึก</p></div>' :
    `<div class="table-responsive">
      <table style="width:100%;border-collapse:collapse;">
        <thead><tr>
          <th style="padding:8px;background:#f5f8fd;font-size:12px;text-align:left;">สัปดาห์</th>
          <th style="padding:8px;background:#f5f8fd;font-size:12px;text-align:left;">วันที่</th>
          <th style="padding:8px;background:#f5f8fd;font-size:12px;text-align:left;">รายวิชา</th>
          <th style="padding:8px;background:#f5f8fd;font-size:12px;text-align:left;">ผู้เรียน</th>
          <th style="padding:8px;background:#f5f8fd;font-size:12px;"></th>
        </tr></thead>
        <tbody>${records.map(r=>`
          <tr>
            <td style="padding:8px;border-bottom:1px solid #f0f0f0;">สัปดาห์ ${r.week}</td>
            <td style="padding:8px;border-bottom:1px solid #f0f0f0;font-size:13px;">${fmtDate(r.date)}</td>
            <td style="padding:8px;border-bottom:1px solid #f0f0f0;font-size:13px;">${escapeHtml(r.subject)}${r.class_name ? `<br><small style="color:#888;">${escapeHtml(cleanGroupDisplayName(r.class_name))}</small>` : ''}</td>
            <td style="padding:8px;border-bottom:1px solid #f0f0f0;font-size:13px;">${r.total}/${r.present}</td>
            <td style="padding:8px;border-bottom:1px solid #f0f0f0;">
              <button class="btn btn-sm btn-primary" onclick="viewRecord('${r.id}')">ดู</button>
            </td>
          </tr>`).join('')}
        </tbody>
      </table>
    </div>`}
  `;
  setRecordModalActionsVisible(false);
  openModal('modal-view');
}

// ============================================================
//  MY RECORDS (TAMBON)
// ============================================================
async function renderMyRecords() {
  const t = getTambonById(currentUser.tambon);
  document.getElementById('my-records-title').textContent = `📝 บันทึกหลังการสอน | ตำบล${t?.name||''}`;
  document.getElementById('my-records-subtitle').textContent = `ศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอเสนา | ตำบล${t?.name||''}`;

  const records = await fetchAllRecords(currentUser.tambon);
  const meta = getRecordsMetadata(currentUser.tambon);

  // Stats — use unique students (fallback to SUM if no attendance data)
  const sumTotal = records.reduce((s,r) => s + (parseInt(r.total)||0), 0);
  const totalStudents = meta.uniqueStudents > 0 ? meta.uniqueStudents : sumTotal;
  const weeks = new Set(records.map(r => r.week)).size;
  document.getElementById('tambon-stats').innerHTML = `
    <div class="stat-card">
      <div class="stat-icon blue">${appIcon('editFile')}</div>
      <div class="stat-info"><div class="num">${records.length}</div><div class="label">บันทึกทั้งหมด</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon gold">${appIcon('calendar')}</div>
      <div class="stat-info"><div class="num">${weeks}</div><div class="label">สัปดาห์ที่บันทึก</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon purple">${appIcon('users')}</div>
      <div class="stat-info"><div class="num">${totalStudents}</div><div class="label">ผู้เรียนสะสม (ไม่ซ้ำ)</div></div>
    </div>
  `;

  // Monthly students card
  renderMonthlyStudentsCard('tambon-monthly-students', meta.monthlyStudents || [], totalStudents);
  syncWeekSelect('filter-my-week-select', records);
  renderMyRecordsTable(records);
}

function renderMyRecordsTable(records, options = {}) {
  if (!options.alreadyFiltered) {
    const week = document.getElementById('filter-my-week-select')?.value || '';
    const imageStatus = document.getElementById('filter-my-image-select')?.value || '';
    records = filterRecordsForView(records, { week, imageStatus });
  }
  lastMyRecordsView = records || [];
  const tbody = document.getElementById('my-records-tbody');
  const empty = document.getElementById('my-records-empty');
  if (!lastMyRecordsView.length) {
    if(tbody) tbody.innerHTML = '';
    if(empty) empty.style.display = 'block';
    updateRecordSelectionUi('my');
    return;
  }
  empty.style.display = 'none';
  tbody.innerHTML = lastMyRecordsView.map(r => {
    const id = String(r.id);
    const checked = selectedMyRecordIds.has(id) ? 'checked' : '';
    return `
    <tr>
      <td class="record-select-col">
        <input type="checkbox" class="record-row-check" ${checked} onchange="toggleRecordSelection('my','${escapeJsString(id)}', this.checked)" aria-label="เลือกบันทึก ${escapeHtml(r.subject || '')}">
      </td>
      <td><span class="badge badge-gold">สัปดาห์ ${r.week}</span></td>
      <td style="font-size:13px;">${fmtDate(r.date)}</td>
      <td style="font-size:13px;">${escapeHtml(r.subject||'-')}<br><small style="color:#aaa;">${escapeHtml([r.subject_code, cleanGroupDisplayName(r.class_name)].filter(Boolean).join(' | '))}</small></td>
      <td><span class="badge badge-blue">${r.level||'-'}</span></td>
      <td style="font-size:13px;">${r.total||0} / <span style="color:var(--success);font-weight:600;">${r.present||0}</span> คน</td>
      <td><span class="badge ${r.images && r.images.length ? 'badge-green' : 'badge-gray'}">${r.images && r.images.length ? '📷 '+r.images.length : 'ไม่มีรูป'}</span></td>
      <td style="white-space:nowrap;">
        <button class="btn btn-sm btn-primary" onclick="viewRecord('${r.id}')">👁️ ดู</button>
        <button class="btn btn-sm btn-outline" onclick="editRecord('${r.id}')" style="margin-left:4px;">✏️</button>
        <button class="btn btn-sm btn-danger" onclick="deleteRecord('${r.id}')" style="margin-left:4px;">🗑️</button>
      </td>
    </tr>
  `;
  }).join('');
  updateRecordSelectionUi('my');
}

document.getElementById('search-my')?.addEventListener('input', debounce(async (e) => {
    const records = await fetchAllRecords(currentUser?.tambon || '', e.target.value);
    syncWeekSelect('filter-my-week-select', records);
    renderMyRecordsTable(records);
}, 250));
document.getElementById('filter-my-week-select')?.addEventListener('change', async () => {
    const records = await fetchAllRecords(currentUser?.tambon || '', document.getElementById('search-my')?.value || '');
    renderMyRecordsTable(records);
});
document.getElementById('filter-my-image-select')?.addEventListener('change', async () => {
    const records = await fetchAllRecords(currentUser?.tambon || '', document.getElementById('search-my')?.value || '');
    renderMyRecordsTable(records);
});
document.getElementById('select-my-records')?.addEventListener('change', e => toggleAllVisibleRecords('my', e.target.checked));
document.getElementById('download-my-selected-pdf')?.addEventListener('click', () => downloadSelectedRecordsAsPdf('my'));
document.getElementById('download-my-selected-doc')?.addEventListener('click', () => downloadSelectedRecordsAsDoc('my'));

// ============================================================
//  STUDENT ANALYSIS
// ============================================================
function populateAnalysisTambonFilter(selectedTambon = null) {
  const select = document.getElementById('analysis-tambon-filter');
  if (!select) return;
  const currentValue = selectedTambon !== null ? selectedTambon : select.value;
  if (currentUser?.role === 'district') {
    select.style.display = '';
    select.innerHTML = '<option value="">ทุกตำบล</option>' +
      TAMBONS.map(t => `<option value="${escapeHtml(t.id)}">${escapeHtml(t.name)}</option>`).join('');
    select.value = TAMBONS.some(t => String(t.id) === String(currentValue)) ? currentValue : '';
  } else {
    select.style.display = 'none';
    const userTambon = currentUser?.tambon || '';
    const tambon = getTambonById(userTambon);
    select.innerHTML = `<option value="${escapeHtml(userTambon)}">${escapeHtml(tambon?.name || userTambon || 'ตำบลของฉัน')}</option>`;
    select.value = currentUser?.tambon || '';
  }
}

async function renderStudentAnalysis(studentId = '', options = {}) {
  const requestId = ++studentAnalysisRequestId;
  populateAnalysisTambonFilter();
  const params = new URLSearchParams();
  const tambon = document.getElementById('analysis-tambon-filter')?.value || '';
  const level = document.getElementById('analysis-level-filter')?.value || '';
  const classGroup = document.getElementById('analysis-class-filter')?.value || '';
  const search = document.getElementById('analysis-search')?.value || '';
  const selectedStudent = studentId || (options.preserveSelection ? (document.getElementById('analysis-student-filter')?.value || '') : '');
  const shouldOpenDetail = options.openDetail === true && selectedStudent !== '';
  if (tambon) params.set('tambon', tambon);
  if (level) params.set('level', level);
  if (classGroup) params.set('class_group', classGroup);
  if (search) params.set('search', search);
  if (selectedStudent) params.set('student_id', selectedStudent);

  const queryString = params.toString();
  const res = await queryCached(
    ['attendance-report', currentUser?.role || '', currentUser?.id || '', queryString],
    () => apiCall(`attendance_report.php?${queryString}`),
    { staleTime: 30000 }
  );
  if (requestId !== studentAnalysisRequestId) return;
  if (!res || res.status !== 'success') return;

  const data = await enrichAnalysisWithStudentApi(res.data || {});
  if (requestId !== studentAnalysisRequestId) return;
  studentAnalysisState.classOptions = data.classes || [];
  studentAnalysisState.levels = data.levels || [];
  studentAnalysisState.students = data.students || [];
  renderAnalysisFilters(data, selectedStudent);
  renderAnalysisStats(data.summary || {});
  renderAnalysisGroups(data);
  renderAnalysisStudents(data.students || []);
  if (shouldOpenDetail) {
    renderAnalysisDetail(selectedStudent, data);
  }
}

async function enrichAnalysisWithStudentApi(data) {
  const levelLabel = document.getElementById('analysis-level-filter')?.value || '';
  const classGroup = document.getElementById('analysis-class-filter')?.value || '';
  const search = (document.getElementById('analysis-search')?.value || '').trim().toLowerCase();
  const tambonId = document.getElementById('analysis-tambon-filter')?.value || '';
  const tambonName = tambonId ? (getTambonById(tambonId)?.name || '') : '';

  const payload = await studentApiFetch({
    view: 'students',
    limit: '5000',
    level: STUDENT_LEVEL_MAP[levelLabel] || 'all',
    class_group: classGroup,
    search
  });
  const apiStudents = Array.isArray(payload.items) ? payload.items : [];
  if (!apiStudents.length) {
    return data;
  }

  const existingStudents = Array.isArray(data.students) ? data.students : [];
  const attendanceById = new Map(existingStudents.map(student => [String(student.student_id || ''), { ...student }]));
  const byId = new Map();

  apiStudents.forEach(item => {
    const studentId = String(item.student_id || item.id || item.ID || '').trim();
    if (!studentId) return;

    const fullName = (item.full_name || [item.prename, item.first_name, item.last_name, item.PRENAME, item.NAME, item.SURNAME].filter(Boolean).join(' ')).trim();
    const apiClassName = item.class_name || item.group_name || '';
    const apiGroupCode = item.group_code || item.group || '';
    const apiLevel = item.level_name || apiLevelName(item.level || '');
    const apiTambon = resolveStudentApiTambon(item);

    const haystack = `${studentId} ${fullName} ${apiClassName} ${apiGroupCode}`.toLowerCase();
    if (search && !haystack.includes(search)) return;
    if (!studentMatchesAnalysisTambon(item, tambonId, tambonName)) return;
    if (classGroup && apiClassName !== classGroup && apiGroupCode !== classGroup) return;
    if (levelLabel && apiLevel !== levelLabel) return;

    const existing = attendanceById.get(studentId);
    byId.set(studentId, {
      student_id: studentId,
      full_name: fullName || existing?.full_name || studentId,
      level: apiLevel || existing?.level || '',
      group_code: apiGroupCode || existing?.group_code || '',
      class_name: apiClassName || existing?.class_name || '',
      tambon: apiTambon?.id || existing?.tambon || '',
      tambon_name: apiTambon?.name || existing?.tambon_name || '',
      sessions: existing?.sessions || 0,
      present: existing?.present || 0,
      absent: existing?.absent || 0,
      onsite: existing?.onsite || 0,
      online: existing?.online || 0,
      rate: existing?.rate || 0,
      absences: existing?.absences || [],
      source: existing?.source || 'student_api'
    });
  });

  const mergedStudents = Array.from(byId.values()).sort((a, b) => {
    const absentDiff = (b.absent || 0) - (a.absent || 0);
    if (absentDiff !== 0) return absentDiff;
    const classCompare = String(a.class_name || '').localeCompare(String(b.class_name || ''), 'th');
    if (classCompare !== 0) return classCompare;
    return String(a.full_name || '').localeCompare(String(b.full_name || ''), 'th');
  });

  data.students = mergedStudents;
  data.summary = {
    ...(data.summary || {}),
    students: mergedStudents.length,
    present: mergedStudents.reduce((sum, student) => sum + (Number(student.present) || 0), 0),
    absent: mergedStudents.reduce((sum, student) => sum + (Number(student.absent) || 0), 0),
    onsite: mergedStudents.reduce((sum, student) => sum + (Number(student.onsite) || 0), 0),
    online: mergedStudents.reduce((sum, student) => sum + (Number(student.online) || 0), 0)
  };
  const totalMarks = data.summary.present + data.summary.absent;
  data.summary.rate = totalMarks ? Math.round((data.summary.present / totalMarks) * 1000) / 10 : 0;
  data.classes = buildAnalysisGroupsFromStudents(mergedStudents, 'class');
  data.levels = buildAnalysisGroupsFromStudents(mergedStudents, 'level');

  return data;
}

function normalizeAnalysisFilterValue(value) {
  return String(value || '')
    .replace(/^ตำบล/u, '')
    .replace(/\s+/g, '')
    .trim()
    .toLowerCase();
}

function studentMatchesAnalysisTambon(item, tambonId, tambonName) {
  if (!tambonId && !tambonName) return true;

  const normalizedTambonId = String(tambonId || '').trim();
  const normalizedTambonName = normalizeAnalysisFilterValue(tambonName);
  const resolvedTambon = resolveStudentApiTambon(item);

  if (resolvedTambon?.id && normalizedTambonId) {
    return String(resolvedTambon.id) === normalizedTambonId;
  }
  if (resolvedTambon?.name && normalizedTambonName) {
    return normalizeAnalysisFilterValue(resolvedTambon.name) === normalizedTambonName;
  }

  const candidates = [
    item.tambon,
    item.tambon_id,
    item.tambon_code,
    item.tambon_name,
    item.subdistrict,
    item.subdistrict_name,
    item.class_name,
    item.group_name,
    item.group_code,
    item.teacher_name
  ].map(value => String(value || '').trim()).filter(Boolean);

  if (normalizedTambonId && candidates.some(value => value === normalizedTambonId)) {
    return true;
  }
  if (!normalizedTambonName) {
    return false;
  }

  return candidates.some(value => normalizeAnalysisFilterValue(value).includes(normalizedTambonName));
}

function resolveStudentApiTambon(item) {
  const explicitId = String(item.tambon_id || item.tambon_code || item.subdistrict_id || '').trim();
  if (explicitId) {
    const tambon = getTambonById(explicitId);
    return { id: explicitId, name: tambon?.name || String(item.tambon_name || item.subdistrict_name || '') };
  }

  const className = String(item.class_name || item.group_name || '').trim();
  const normalizedClassName = normalizeAnalysisFilterValue(className);
  if (normalizedClassName.includes('ระดับอำเภอเสนา')) {
    const centerTambon = TAMBONS.find(t => normalizeAnalysisFilterValue(t.name).includes('ศูนย์อำเภอ'));
    if (centerTambon) {
      return { id: centerTambon.id, name: centerTambon.name };
    }
  }

  const classTambon = TAMBONS
    .filter(t => !normalizeAnalysisFilterValue(t.name).includes('ศูนย์อำเภอ'))
    .find(t => {
      const name = normalizeAnalysisFilterValue(t.name);
      return name && normalizedClassName.includes(name);
    });
  if (classTambon) {
    return { id: classTambon.id, name: classTambon.name };
  }

  const teacherUsernames = Array.isArray(item.teachers)
    ? item.teachers.map(teacher => String(teacher?.username || '').trim()).filter(Boolean)
    : [];
  const teacherTambon = TAMBONS.find(t => teacherUsernames.includes(String(t.id)));
  if (teacherTambon) {
    return { id: teacherTambon.id, name: teacherTambon.name };
  }

  const explicitName = String(item.tambon_name || item.subdistrict_name || item.tambon || '').trim();
  if (explicitName) {
    const normalizedName = normalizeAnalysisFilterValue(explicitName);
    const tambon = TAMBONS.find(t => normalizeAnalysisFilterValue(t.name) === normalizedName);
    return { id: tambon?.id || '', name: tambon?.name || explicitName };
  }

  return { id: '', name: '' };
}

function apiLevelName(level) {
  const normalized = String(level || '');
  if (normalized === '1') return 'ประถมศึกษา';
  if (normalized === '2') return 'มัธยมศึกษาตอนต้น';
  if (normalized === '3') return 'มัธยมศึกษาตอนปลาย';
  return normalized;
}

function buildAnalysisGroupsFromStudents(students, mode) {
  const byKey = new Map();

  students.forEach(student => {
    const key = mode === 'level'
      ? (student.level || 'ไม่ระบุระดับ')
      : (student.group_code || student.class_name || 'ไม่ระบุกลุ่ม');
    if (!byKey.has(key)) {
      byKey.set(key, {
        name: mode === 'level' ? key : (student.class_name || key),
        code: mode === 'level' ? key : (student.group_code || ''),
        level: mode === 'level' ? key : (student.level || ''),
        sessions: 0,
        students: 0,
        present: 0,
        absent: 0,
        onsite: 0,
        online: 0,
        rate: 0,
        _studentIds: new Set()
      });
    }

    const group = byKey.get(key);
    group._studentIds.add(student.student_id);
    group.sessions = Math.max(group.sessions, Number(student.sessions) || 0);
    group.present += Number(student.present) || 0;
    group.absent += Number(student.absent) || 0;
    group.onsite += Number(student.onsite) || 0;
    group.online += Number(student.online) || 0;
  });

  const groups = Array.from(byKey.values()).map(group => {
    group.students = group._studentIds.size;
    delete group._studentIds;
    const marks = group.present + group.absent;
    group.rate = marks ? Math.round((group.present / marks) * 1000) / 10 : 0;
    return group;
  });

  return groups.sort((a, b) => {
    const levelCompare = String(a.level || '').localeCompare(String(b.level || ''), 'th');
    if (levelCompare !== 0) return levelCompare;
    return String(a.name || '').localeCompare(String(b.name || ''), 'th');
  });
}

function renderAnalysisFilters(data, selectedStudent) {
  const classSelect = document.getElementById('analysis-class-filter');
  const currentClass = classSelect?.value || '';
  if (classSelect) {
    const classes = data.classes || [];
    classSelect.innerHTML = '<option value="">ทุกกลุ่มเรียน</option>' + classes.map(group => {
      const value = group.code || group.name;
      return `<option value="${escapeHtml(value)}">${escapeHtml([group.level, group.name].filter(Boolean).join(' - '))}</option>`;
    }).join('');
    classSelect.value = classes.some(group => (group.code || group.name) === currentClass) ? currentClass : '';
  }

  const studentSelect = document.getElementById('analysis-student-filter');
  if (studentSelect) {
    const students = data.students || [];
    studentSelect.innerHTML = '<option value="">เลือกนักเรียนเพื่อดูรายละเอียด</option>' + students.map(student => (
      `<option value="${escapeHtml(student.student_id)}">${escapeHtml(student.full_name)} (${escapeHtml(student.student_id)})</option>`
    )).join('');
    studentSelect.value = students.some(student => String(student.student_id || '') === String(selectedStudent || '')) ? selectedStudent : '';
  }
}

function renderAnalysisStats(summary) {
  const totalMarks = (summary.present || 0) + (summary.absent || 0);
  document.getElementById('analysis-stats').innerHTML = `
    <div class="stat-card">
      <div class="stat-icon blue">👥</div>
      <div class="stat-info"><div class="num">${summary.students || 0}</div><div class="label">นักเรียนที่มีข้อมูล</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon gold">📅</div>
      <div class="stat-info"><div class="num">${summary.sessions || 0}</div><div class="label">ครั้งเรียนที่บันทึก</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon green">✅</div>
      <div class="stat-info"><div class="num">${summary.present || 0}/${totalMarks}</div><div class="label">มาเรียนทั้งหมด</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon blue">📈</div>
      <div class="stat-info"><div class="num">${summary.rate || 0}%</div><div class="label">อัตราการมาเรียน</div></div>
    </div>
  `;
}

function renderAnalysisGroups(data) {
  const tbody = document.getElementById('analysis-group-tbody');
  const rows = studentAnalysisState.tab === 'levels' ? (data.levels || []) : (data.classes || []);
  if (!tbody) return;
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:#aaa;">ยังไม่มีข้อมูลการติ๊กชื่อ</td></tr>';
    return;
  }
  tbody.innerHTML = rows.map(row => `
    <tr>
      <td style="font-size:13px;">${escapeHtml(row.name || '-')}<br><small style="color:#888;">${escapeHtml(row.level || row.code || '')}</small></td>
      <td>${row.sessions || 0}</td>
      <td>${row.students || 0}</td>
      <td><span class="badge badge-green">${row.present || 0}</span></td>
      <td><span class="badge ${row.absent ? 'badge-red' : 'badge-gray'}">${row.absent || 0}</span></td>
      <td>${row.online || 0}</td>
      <td><strong>${row.rate || 0}%</strong></td>
    </tr>
  `).join('');
}

function renderAnalysisStudents(students) {
  const tbody = document.getElementById('analysis-student-tbody');
  if (!tbody) return;
  if (!students.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:#aaa;">ยังไม่มีข้อมูลรายบุคคล</td></tr>';
    return;
  }
  tbody.innerHTML = students.map(student => `
    <tr>
      <td style="font-size:13px;"><strong>${escapeHtml(student.full_name || '-')}</strong><br><small style="color:#888;">${escapeHtml(student.student_id || '')}</small></td>
      <td style="font-size:13px;">${escapeHtml(student.class_name || '-')}<br><small style="color:#888;">${escapeHtml(student.level || '')}</small></td>
      <td><span class="badge badge-green">${student.present || 0}</span></td>
      <td><span class="badge ${student.absent ? 'badge-red' : 'badge-gray'}">${student.absent || 0}</span></td>
      <td>${student.online || 0}</td>
      <td><strong>${student.rate || 0}%</strong></td>
      <td><button class="btn btn-sm btn-primary" onclick="showStudentAnalysisDetail('${escapeJsString(student.student_id)}')">ดูรายบุคคล</button></td>
    </tr>
  `).join('');
}

function renderAnalysisDetail(studentId, data) {
  const tbody = document.getElementById('analysis-detail-tbody');
  const title = document.getElementById('analysis-detail-title');
  const summary = document.getElementById('analysis-detail-summary');
  if (!tbody || !title || !summary) return;
  if (!studentId) {
    return;
  }

  const student = (data.students || []).find(item => item.student_id === studentId);
  const rows = data.selected_timeline || [];
  title.textContent = student ? `รายละเอียดรายบุคคล: ${student.full_name}` : 'รายละเอียดรายบุคคล';
  summary.innerHTML = student ? `
    <div class="analysis-summary-pill"><strong>${escapeHtml(student.full_name)}</strong><span>${escapeHtml(student.student_id || '')}</span></div>
    <div class="analysis-summary-pill">กลุ่มเรียน <strong>${escapeHtml(student.class_name || '-')}</strong></div>
    <div class="analysis-summary-pill">มาเรียน <strong>${student.present || 0}</strong></div>
    <div class="analysis-summary-pill">ขาดเรียน <strong>${student.absent || 0}</strong></div>
    <div class="analysis-summary-pill">อัตรา <strong>${student.rate || 0}%</strong></div>
  ` : '';
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:#aaa;">ไม่พบรายละเอียดรายบุคคล</td></tr>';
    openModal('modal-analysis-detail');
    return;
  }

  tbody.innerHTML = rows.map(row => `
    <tr>
      <td><span class="badge badge-gold">สัปดาห์ ${row.week || '-'}</span></td>
      <td style="font-size:13px;">${fmtDate(row.date)}</td>
      <td style="font-size:13px;">${escapeHtml(row.subject || '-')}<br><small style="color:#888;">${escapeHtml(row.subject_code || '')}</small></td>
      <td style="font-size:13px;">${escapeHtml(row.class_name || '-')}</td>
      <td><span class="badge ${row.present ? 'badge-green' : 'badge-red'}">${row.present ? 'มาเรียน' : 'ขาดเรียน'}</span></td>
      <td>${row.present ? escapeHtml(row.learning_method || 'พบกลุ่ม') : '-'}</td>
    </tr>
  `).join('');
  openModal('modal-analysis-detail');
}

function showStudentAnalysisDetail(studentId) {
  const select = document.getElementById('analysis-student-filter');
  if (select) select.value = studentId;
  renderStudentAnalysis(studentId, { openDetail: true });
}

function resetAnalysisFilterSelection(options = {}) {
  if (options.classGroup) {
    const classSelect = document.getElementById('analysis-class-filter');
    if (classSelect) classSelect.value = '';
  }
  if (options.student !== false) {
    const studentSelect = document.getElementById('analysis-student-filter');
    if (studentSelect) studentSelect.value = '';
  }
}

document.getElementById('analysis-tambon-filter')?.addEventListener('change', () => {
  resetAnalysisFilterSelection({ classGroup: true });
  renderStudentAnalysis('');
});
document.getElementById('analysis-level-filter')?.addEventListener('change', () => {
  resetAnalysisFilterSelection({ classGroup: true });
  renderStudentAnalysis('');
});
document.getElementById('analysis-class-filter')?.addEventListener('change', () => {
  resetAnalysisFilterSelection();
  renderStudentAnalysis('');
});
document.getElementById('analysis-search')?.addEventListener('input', debounce(() => {
  resetAnalysisFilterSelection();
  renderStudentAnalysis('');
}, 250));
document.getElementById('analysis-student-filter')?.addEventListener('change', e => {
  const studentId = e.target.value || '';
  renderStudentAnalysis(studentId, { openDetail: studentId !== '' });
});
document.getElementById('refreshStudentAnalysisBtn')?.addEventListener('click', () => {
  const modalIsOpen = document.getElementById('modal-analysis-detail')?.classList.contains('open');
  const studentId = document.getElementById('analysis-student-filter')?.value || '';
  renderStudentAnalysis(studentId, { preserveSelection: true, openDetail: modalIsOpen && studentId !== '' });
});
document.querySelectorAll('.analysis-tab').forEach(btn => {
  btn.addEventListener('click', e => {
    document.querySelectorAll('.analysis-tab').forEach(item => item.classList.remove('active'));
    e.currentTarget.classList.add('active');
    studentAnalysisState.tab = e.currentTarget.dataset.analysisTab || 'classes';
    renderAnalysisGroups({
      classes: studentAnalysisState.classOptions,
      levels: studentAnalysisState.levels || []
    });
  });
});

// ============================================================
//  STUDENT API & ATTENDANCE PICKER
// ============================================================
const STUDENT_LEVEL_MAP = {
  'ประถมศึกษา': '1',
  'มัธยมศึกษาตอนต้น': '2',
  'มัธยมศึกษาตอนปลาย': '3'
};

function getStudentApiLevel() {
  return STUDENT_LEVEL_MAP[document.getElementById('rec-level')?.value || ''] || 'all';
}

function buildStudentGroupKey(group) {
  return [
    group.group_code || '',
    group.class_name || '',
    group.level || ''
  ].join('||');
}

function getSelectedStudentGroup() {
  const key = document.getElementById('rec-class-group')?.value || '';
  return studentGroupsByKey.get(key) || null;
}

async function studentApiFetch(params) {
  const qs = new URLSearchParams(params);
  const res = await apiCall(`student_api.php?${qs.toString()}`, 'GET', null, true);
  if (res && res.status === 'success') {
    return res.data || { items: [], meta: {} };
  }

  if (res?.message) {
    showToast(res.message, 'error');
  }

  return { items: [], meta: {} };
}

async function loadStudentGroups(force = false) {
  const level = getStudentApiLevel();
  const cacheKey = `groups:${level}`;
  const select = document.getElementById('rec-class-group');
  const meta = document.getElementById('student-group-meta');
  if (!select) return [];

  if (!force && studentGroupsCache.has(cacheKey)) {
    populateStudentGroupSelect(studentGroupsCache.get(cacheKey));
    return studentGroupsCache.get(cacheKey);
  }

  if (meta) meta.textContent = 'กำลังโหลดกลุ่มเรียนจาก API...';
  const payload = await studentApiFetch({
    view: 'groups',
    limit: '1000',
    level
  });
  const groups = Array.isArray(payload.items) ? payload.items : [];
  studentGroupsCache.set(cacheKey, groups);
  populateStudentGroupSelect(groups);
  if (meta) meta.textContent = groups.length ? `พบ ${groups.length} กลุ่มเรียน` : 'ไม่พบกลุ่มเรียนจาก API';
  return groups;
}

function populateStudentGroupSelect(groups, selectedGroupCode = '', selectedClassName = '') {
  const select = document.getElementById('rec-class-group');
  if (!select) return;

  studentGroupsByKey = new Map();
  const options = ['<option value="">-- เลือกกลุ่มเรียนจาก API นักเรียน --</option>'];
  let selectedKey = '';

  groups.forEach(group => {
    const key = buildStudentGroupKey(group);
    studentGroupsByKey.set(key, group);
    if (
      selectedKey === '' &&
      ((selectedGroupCode && group.group_code === selectedGroupCode) ||
       (selectedClassName && group.class_name === selectedClassName))
    ) {
      selectedKey = key;
    }
    const teacher = group.teacher_name ? ` | ครู: ${group.teacher_name}` : '';
    const code = group.group_code ? ` (${group.group_code})` : '';
    options.push(`<option value="${escapeHtml(key)}">${escapeHtml(group.level_name || group.level || '')} - ${escapeHtml(group.class_name || group.group_code || '-')}${escapeHtml(code + teacher)}</option>`);
  });

  select.innerHTML = options.join('');
  select.value = selectedKey;
}

function resetStudentAttendancePicker(options = {}) {
  const keepCounts = !!options.keepCounts;
  currentStudentRoster = [];
  document.getElementById('rec-class-group-code').value = '';
  document.getElementById('rec-class-name').value = '';
  document.getElementById('rec-class-group').value = '';
  document.getElementById('student-search').value = '';
  document.getElementById('student-toolbar').style.display = 'none';
  document.getElementById('student-attendance-summary').style.display = 'none';
  document.getElementById('student-list').innerHTML = '<div class="student-list-empty">ยังไม่ได้เลือกกลุ่มเรียน</div>';
  document.getElementById('rec-total').readOnly = false;
  document.getElementById('rec-present').readOnly = false;
  if (!keepCounts) {
    document.getElementById('rec-total').value = '';
    document.getElementById('rec-present').value = '';
    document.getElementById('rec-absent').value = '';
  }
}

async function loadStudentsForSelectedGroup(defaultPresentIds = new Set()) {
  const group = getSelectedStudentGroup();
  const meta = document.getElementById('student-group-meta');
  const list = document.getElementById('student-list');
  if (!group) {
    resetStudentAttendancePicker({ keepCounts: true });
    return;
  }

  document.getElementById('rec-class-group-code').value = group.group_code || '';
  document.getElementById('rec-class-name').value = group.class_name || '';
  if (group.level_name) {
    document.getElementById('rec-level').value = group.level_name;
  }
  if (meta) {
    const teacher = group.teacher_name ? ` | ครูประจำกลุ่ม: ${group.teacher_name}` : '';
    meta.textContent = `${group.class_name || group.group_code || '-'}${teacher}`;
  }
  if (list) list.innerHTML = '<div class="student-list-empty">กำลังโหลดรายชื่อนักเรียน...</div>';

  const groupFilter = group.group_code || group.class_name || '';
  const payload = await studentApiFetch({
    view: 'students',
    limit: '1000',
    level: group.level || getStudentApiLevel(),
    class_group: groupFilter
  });

  currentStudentRoster = (Array.isArray(payload.items) ? payload.items : []).map(student => ({
    student_id: student.student_id || '',
    full_name: student.full_name || [student.prename, student.first_name, student.last_name].filter(Boolean).join(' '),
    group_code: student.group_code || group.group_code || '',
    class_name: student.class_name || group.class_name || '',
    present: defaultPresentIds.has(student.student_id || ''),
    learning_method: 'พบกลุ่ม'
  }));

  renderStudentRoster();
}

function renderStudentRoster() {
  const list = document.getElementById('student-list');
  const toolbar = document.getElementById('student-toolbar');
  const summary = document.getElementById('student-attendance-summary');
  if (!list) return;

  const query = (document.getElementById('student-search')?.value || '').trim().toLowerCase();
  const filtered = currentStudentRoster.filter(student => {
    const haystack = `${student.student_id} ${student.full_name}`.toLowerCase();
    return !query || haystack.includes(query);
  });

  toolbar.style.display = currentStudentRoster.length ? 'flex' : 'none';
  summary.style.display = currentStudentRoster.length ? 'block' : 'none';
  document.getElementById('rec-total').readOnly = currentStudentRoster.length > 0;
  document.getElementById('rec-present').readOnly = currentStudentRoster.length > 0;

  if (!currentStudentRoster.length) {
    list.innerHTML = '<div class="student-list-empty">ไม่พบรายชื่อนักเรียนในกลุ่มนี้</div>';
    updateAttendanceCounts();
    return;
  }

  if (!filtered.length) {
    list.innerHTML = '<div class="student-list-empty">ไม่พบรายชื่อนักเรียนตามคำค้นหา</div>';
    updateAttendanceCounts();
    return;
  }

  list.innerHTML = filtered.map(student => `
    <div class="student-row">
      <input type="checkbox" class="student-present-checkbox" data-student-id="${escapeHtml(student.student_id)}" ${student.present ? 'checked' : ''}>
      <span>
        <span class="student-name">${escapeHtml(student.full_name || '-')}</span>
        <span class="student-code">${escapeHtml(student.student_id || '')}</span>
      </span>
      <span class="student-methods" aria-label="วิธีเรียน">
        <label><input type="radio" class="student-method-radio" name="method-${escapeHtml(student.student_id)}" data-student-id="${escapeHtml(student.student_id)}" value="พบกลุ่ม" ${student.learning_method !== 'ออนไลน์' ? 'checked' : ''} ${student.present ? '' : 'disabled'}> พบกลุ่ม</label>
        <label><input type="radio" class="student-method-radio" name="method-${escapeHtml(student.student_id)}" data-student-id="${escapeHtml(student.student_id)}" value="ออนไลน์" ${student.learning_method === 'ออนไลน์' ? 'checked' : ''} ${student.present ? '' : 'disabled'}> ออนไลน์</label>
      </span>
      <span class="student-status">${student.present ? escapeHtml(student.learning_method || 'พบกลุ่ม') : 'ยังไม่เลือก'}</span>
    </div>
  `).join('');

  list.querySelectorAll('.student-present-checkbox').forEach(input => {
    input.addEventListener('change', () => {
      const student = currentStudentRoster.find(item => item.student_id === input.dataset.studentId);
      if (student) {
        student.present = input.checked;
        if (student.present && !student.learning_method) student.learning_method = 'พบกลุ่ม';
      }
      renderStudentRoster();
    });
  });

  list.querySelectorAll('.student-method-radio').forEach(input => {
    input.addEventListener('change', () => {
      const student = currentStudentRoster.find(item => item.student_id === input.dataset.studentId);
      if (student) {
        student.learning_method = input.value;
        student.present = true;
      }
      renderStudentRoster();
    });
  });

  updateAttendanceCounts();
}

function updateAttendanceCounts() {
  const total = currentStudentRoster.length || (parseInt(document.getElementById('rec-total').value) || 0);
  const present = currentStudentRoster.length
    ? currentStudentRoster.filter(student => student.present).length
    : (parseInt(document.getElementById('rec-present').value) || 0);
  document.getElementById('rec-total').value = total || '';
  document.getElementById('rec-present').value = present || '';
  document.getElementById('rec-absent').value = Math.max(0, total - present);
  const summary = document.getElementById('student-attendance-summary');
  if (summary) summary.textContent = `เลือกมาเรียน ${present} จาก ${total} คน`;
}

function collectAttendancePayload() {
  if (!currentStudentRoster.length) return [];
  return currentStudentRoster.map(student => ({
    student_id: student.student_id,
    full_name: student.full_name,
    group_code: student.group_code || document.getElementById('rec-class-group-code').value,
    class_name: student.class_name || document.getElementById('rec-class-name').value,
    present: !!student.present,
    learning_method: student.learning_method || 'พบกลุ่ม'
  }));
}

function renderSavedAttendance(attendance) {
  currentStudentRoster = (Array.isArray(attendance) ? attendance : []).map(student => ({
    student_id: student.student_id || '',
    full_name: student.full_name || student.name || '',
    group_code: student.group_code || '',
    class_name: student.class_name || '',
    present: !!student.present,
    learning_method: student.learning_method || 'พบกลุ่ม'
  }));
  renderStudentRoster();
}

document.getElementById('reloadStudentGroupsBtn')?.addEventListener('click', () => loadStudentGroups(true));
document.getElementById('rec-class-group')?.addEventListener('change', () => loadStudentsForSelectedGroup());
document.getElementById('rec-level')?.addEventListener('change', () => {
  const select = document.getElementById('rec-class-group');
  if (select && !select.value) loadStudentGroups(false);
});
document.getElementById('student-search')?.addEventListener('input', debounce(renderStudentRoster, 150));
document.getElementById('selectAllStudentsBtn')?.addEventListener('click', () => {
  currentStudentRoster.forEach(student => {
    student.present = true;
    if (!student.learning_method) student.learning_method = 'พบกลุ่ม';
  });
  renderStudentRoster();
});
document.getElementById('clearAllStudentsBtn')?.addEventListener('click', () => {
  currentStudentRoster.forEach(student => { student.present = false; });
  renderStudentRoster();
});

// ============================================================
//  RECORD CRUD
// ============================================================
function getCurrentThaiAcademicYear() {
  return new Date().getFullYear() + 543;
}

function populateAcademicYearOptions(selectedYear = '') {
  const select = document.getElementById('rec-year');
  if (!select) return;

  const currentYear = getCurrentThaiAcademicYear();
  const resolvedSelected = /^\d{4}$/.test(String(selectedYear))
    ? Number(selectedYear)
    : currentYear;
  const years = new Set();

  for (let year = currentYear; year >= currentYear - 7; year -= 1) {
    years.add(year);
  }
  years.add(resolvedSelected);

  select.innerHTML = [...years]
    .sort((a, b) => b - a)
    .map(year => `<option value="${year}">${year}</option>`)
    .join('');
  select.value = String(resolvedSelected);
}

function openNewRecord() {
  editingRecordId = null;
  currentImages = [];
  document.getElementById('modal-record-title').textContent = '📝 เพิ่มบันทึกหลังการสอน';
  const t = getTambonById(currentUser.tambon);
  document.getElementById('rec-tambon').value = t?.name || '';
  document.getElementById('rec-tambon').readOnly = true;
  document.getElementById('rec-date').value = new Date().toISOString().split('T')[0];
  document.getElementById('rec-week').value = '';
  document.getElementById('rec-level').value = '';
  document.getElementById('rec-semester').value = '1';
  document.getElementById('rec-subject').value = '';
  document.getElementById('rec-subject-code').value = '';
  resetStudentAttendancePicker();
  document.getElementById('rec-result').value = '';
  document.getElementById('rec-problem').value = '';
  document.getElementById('rec-suggestion').value = '';
  document.getElementById('rec-supervisor-comment').value = '';
  populateAcademicYearOptions();
  document.getElementById('record-id').value = '';
  renderImagePreviews();
  ['cnt-result','cnt-problem','cnt-suggest'].forEach(id => document.getElementById(id).textContent = '0');
  
  if (currentUser.role === 'district') {
      document.getElementById('rec-supervisor-section').style.display = 'block';
  } else {
      document.getElementById('rec-supervisor-section').style.display = 'none';
  }
  
  openModal('modal-record');
  loadStudentGroups(false);
}

if(document.getElementById('openNewRecordBtn')) document.getElementById('openNewRecordBtn').addEventListener('click', openNewRecord);

async function editRecord(id) {
  const res = await apiCall(`records.php?id=${id}`);
  if (!res || res.status !== 'success') return;
  const r = res.data;
  
  editingRecordId = id;
  currentImages = r.images ? r.images.map(img => ({
    name: img.name,
    data: img.data,
    missing: img.missing,
    exists: img.exists
  })) : [];
  
  document.getElementById('modal-record-title').textContent = '✏️ แก้ไขบันทึกหลังการสอน';
  const t = getTambonById(r.tambon);
  document.getElementById('rec-tambon').value = t?.name || '';
  document.getElementById('rec-tambon').readOnly = true;
  document.getElementById('rec-date').value = r.date;
  document.getElementById('rec-week').value = r.week;
  document.getElementById('rec-level').value = r.level||'';
  document.getElementById('rec-semester').value = r.semester||'1';
  document.getElementById('rec-subject').value = r.subject||'';
  document.getElementById('rec-subject-code').value = r.subject_code||'';
  resetStudentAttendancePicker({ keepCounts: true });
  document.getElementById('rec-class-group-code').value = r.class_group_code || '';
  document.getElementById('rec-class-name').value = r.class_name || '';
  document.getElementById('rec-total').value = r.total||'';
  document.getElementById('rec-present').value = r.present||'';
  document.getElementById('rec-absent').value = (r.total||0) - (r.present||0);
  document.getElementById('rec-result').value = r.result||'';
  document.getElementById('rec-problem').value = r.problem||'';
  document.getElementById('rec-suggestion').value = r.suggestion||'';
  document.getElementById('rec-supervisor-comment').value = r.supervisor_comment||'';
  populateAcademicYearOptions(r.year || '');
  document.getElementById('record-id').value = id;
  
  if (currentUser.role === 'district') {
      document.getElementById('rec-supervisor-section').style.display = 'block';
  } else {
      document.getElementById('rec-supervisor-section').style.display = 'none';
  }
  
  if (r.attendance && r.attendance.length) {
    renderSavedAttendance(r.attendance);
  } else if (r.class_name || r.class_group_code) {
    document.getElementById('student-list').innerHTML = `<div class="student-list-empty">บันทึกนี้มีกลุ่มเรียน ${escapeHtml(r.class_name || r.class_group_code)} แต่ไม่มี snapshot รายชื่อนักเรียน</div>`;
  }
  loadStudentGroups(false).then(groups => populateStudentGroupSelect(groups, r.class_group_code || '', r.class_name || ''));
  renderImagePreviews();
  openModal('modal-record');
}

async function saveRecord() {
  const tambon = currentUser.tambon;
  const week = parseInt(document.getElementById('rec-week').value);
  const date = document.getElementById('rec-date').value;
  const level = document.getElementById('rec-level').value;
  const semester = document.getElementById('rec-semester').value;
  const subject = document.getElementById('rec-subject').value.trim();
  const subjectCode = document.getElementById('rec-subject-code').value.trim();
  const classGroupCode = document.getElementById('rec-class-group-code').value.trim();
  const className = document.getElementById('rec-class-name').value.trim();
  const total = parseInt(document.getElementById('rec-total').value)||0;
  const present = parseInt(document.getElementById('rec-present').value)||0;
  const result = document.getElementById('rec-result').value.trim();
  const problem = document.getElementById('rec-problem').value.trim();
  const suggestion = document.getElementById('rec-suggestion').value.trim();
  const supervisorComment = document.getElementById('rec-supervisor-comment').value.trim();
  const year = document.getElementById('rec-year').value.trim();

  if (!week || !date || !subject) {
    showToast('กรุณากรอกข้อมูลให้ครบถ้วน (สัปดาห์, วันที่, รายวิชา)', 'error');
    return;
  }

  if (present > total) {
    showToast('จำนวนเข้าเรียนต้องไม่มากกว่าผู้เรียนทั้งหมด', 'error');
    return;
  }

  if (!isValidRecordImageCount(currentImages.length)) {
    showToast(`ภาพประกอบต้องอัปโหลด 2 รูป หรือ 4 รูป เท่านั้น ตอนนี้มี ${currentImages.length} รูป`, 'error');
    return;
  }

  const payload = {
      tambon, week, date, level, semester, subject, subject_code: subjectCode, 
      class_group_code: classGroupCode, class_name: className,
      total, present, result, problem, suggestion, supervisorComment, year,
      attendance: collectAttendancePayload(),
      images: currentImages.map(img => img.data) // Array of server paths
  };

  const btn = document.getElementById('saveRecordBtn');
  if (btn.disabled) return;
  
  btn.disabled = true;
  const originalBtnText = btn.innerHTML;
  btn.innerHTML = '⏳ กำลังบันทึก...';
  
  const loader = document.getElementById('global-loader');
  if (loader) {
      document.querySelector('#global-loader p').textContent = 'กำลังบันทึกข้อมูล กรุณารอสักครู่...';
      loader.classList.add('show');
  }

  let res;
  try {
    if (editingRecordId) {
      payload.id = editingRecordId;
      res = await apiCall('records.php', 'PUT', payload);
    } else {
      res = await apiCall('records.php', 'POST', payload);
    }
    
    if (res && res.status === 'success') {
        invalidateRecordsCache();
        showToast(res.message);
        closeModal('modal-record');
        if (currentUser.role === 'tambon') renderMyRecords();
        else { renderDashboard(); renderTambonGrid(); }
    } else if(res) {
        showToast(res.message, 'error');
    }
  } finally {
    btn.disabled = false;
    btn.innerHTML = originalBtnText;
    if (loader) loader.classList.remove('show');
  }
}
if(document.getElementById('saveRecordBtn')) document.getElementById('saveRecordBtn').addEventListener('click', saveRecord);

async function deleteRecord(id) {
  if (!confirm('ต้องการลบบันทึกนี้ใช่หรือไม่?')) return;
  const res = await apiCall(`records.php?id=${id}`, 'DELETE');
  if (res && res.status === 'success') {
      invalidateRecordsCache();
      showToast(res.message);
      if (currentUser.role === 'tambon') renderMyRecords();
      else { renderDashboard(); renderTambonGrid(); }
  } else if (res) {
      showToast(res.message, 'error');
  }
}

// auto-calc absent
document.getElementById('rec-present')?.addEventListener('input', function() {
  const t = parseInt(document.getElementById('rec-total').value)||0;
  const p = parseInt(this.value)||0;
  document.getElementById('rec-absent').value = Math.max(0, t - p);
});
document.getElementById('rec-total')?.addEventListener('input', function() {
  const t = parseInt(this.value)||0;
  const p = parseInt(document.getElementById('rec-present').value)||0;
  document.getElementById('rec-absent').value = Math.max(0, t - p);
});

// ============================================================
//  VIEW RECORD
// ============================================================
function setRecordModalActionsVisible(visible) {
  ['btn-edit-record', 'btnDownloadPdf', 'btnPrintRecord'].forEach(id => {
    const button = document.getElementById(id);
    if (button) button.style.display = visible ? '' : 'none';
  });
}

async function viewRecord(id) {
  const res = await apiCall(`records.php?id=${id}`);
  if (!res || res.status !== 'success') return;
  
  const r = res.data;
  const t = getTambonById(r.tambon);
  const canEdit = currentUser.role === 'district' || (currentUser.role === 'tambon' && currentUser.tambon === r.tambon);
  const attendance = Array.isArray(r.attendance) ? r.attendance : [];
  const attendanceHtml = attendance.length ? `
    <div class="detail-text-block">
      <h4>รายชื่อนักเรียนที่มาเรียน</h4>
      <div class="student-list" style="max-height:220px;">
        ${attendance.map(student => `
          <div class="student-row" style="cursor:default;grid-template-columns:minmax(0,1fr) auto;">
            <span>
              <span class="student-name">${escapeHtml(student.full_name || '-')}</span>
              <span class="student-code">${escapeHtml(student.student_id || '')}</span>
            </span>
            <span class="badge ${student.present ? 'badge-green' : 'badge-gray'}">${student.present ? escapeHtml(student.learning_method || 'พบกลุ่ม') : 'ไม่มา'}</span>
          </div>
        `).join('')}
      </div>
    </div>
  ` : '';

  document.getElementById('modal-view-body').innerHTML = `
    <div style="background:linear-gradient(135deg,#1a3a6b,#2952a3);color:#fff;border-radius:12px;padding:16px 20px;margin-bottom:16px;display:flex;align-items:center;gap:14px;">
      <span style="font-size:32px;">${t?.icon||'📍'}</span>
      <div>
        <div style="font-family:Prompt;font-size:16px;font-weight:600;">บันทึกหลังการสอน | ตำบล${t?.name||''}</div>
        <div style="font-size:13px;opacity:0.8;">ศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอเสนา</div>
      </div>
    </div>

    <div class="record-detail-grid">
      <div class="detail-item"><div class="d-label">สัปดาห์ที่</div><div class="d-val">${r.week}</div></div>
      <div class="detail-item"><div class="d-label">วันที่สอน</div><div class="d-val">${fmtDate(r.date)}</div></div>
      <div class="detail-item"><div class="d-label">รายวิชา</div><div class="d-val">${r.subject||'-'}</div></div>
      <div class="detail-item"><div class="d-label">รหัสวิชา</div><div class="d-val">${r.subject_code||'-'}</div></div>
      <div class="detail-item"><div class="d-label">กลุ่มเรียน</div><div class="d-val">${escapeHtml(cleanGroupDisplayName(r.class_name) || '-')}</div></div>
      <div class="detail-item"><div class="d-label">ระดับ</div><div class="d-val">${r.level||'-'}</div></div>
      <div class="detail-item"><div class="d-label">ภาคเรียน / ปีการศึกษา</div><div class="d-val">${r.semester||'1'} / ${r.year||'-'}</div></div>
      <div class="detail-item"><div class="d-label">ผู้เรียนทั้งหมด</div><div class="d-val">${r.total||0} คน</div></div>
      <div class="detail-item"><div class="d-label">เข้าเรียน / ไม่มาเรียน</div><div class="d-val" style="color:var(--success)">${r.present||0} คน / <span style="color:var(--danger)">${(r.total||0)-(r.present||0)} คน</span></div></div>
    </div>
    ${attendanceHtml}

    <div class="detail-text-block">
      <h4>1. ผลการจัดการเรียนการสอน</h4>
      <p>${r.result || '<span style="color:#aaa">ไม่มีข้อมูล</span>'}</p>
    </div>
    <div class="detail-text-block">
      <h4>2. สภาพปัญหาและอุปสรรค</h4>
      <p>${r.problem || '<span style="color:#aaa">ไม่มีข้อมูล</span>'}</p>
    </div>
    <div class="detail-text-block">
      <h4>3. ข้อเสนอแนะ / แนวทางแก้ไขปัญหา</h4>
      <p>${r.suggestion || '<span style="color:#aaa">ไม่มีข้อมูล</span>'}</p>
    </div>

    ${r.supervisor_comment ? `<div class="detail-text-block" style="border-left:3px solid var(--accent);">
      <h4>💬 ความคิดเห็นของหัวหน้างาน</h4>
      <p>${r.supervisor_comment}</p>
    </div>` : ''}

    ${r.images && r.images.length > 0 ? `
      <div style="margin-top:12px;">
        <div style="font-size:13px;font-weight:700;color:var(--primary);margin-bottom:10px;">📷 ภาพประกอบการจัดการเรียนการสอน (${r.images.length} รูป)</div>
        <div class="image-previews">
          ${r.images.map((img,i) => {
            const imgSrc = resolveImageSrc(img.data);
            const isMissing = img.missing || img.exists === false || !imgSrc;
            const clickAttr = isMissing ? '' : `onclick="openFullImg('${escapeJsString(imgSrc)}', 'ภาพที่ ${i + 1}')"`;
            return `
              <div class="image-preview-item image-preview-item--viewer${isMissing ? ' image-preview-item--missing' : ''}" ${clickAttr}>
                ${isMissing
                  ? `<div class="image-missing-label">ไม่พบไฟล์รูป<br><small>${escapeHtml(img.data || '')}</small></div>`
                  : `<img src="${escapeHtml(imgSrc)}" alt="ภาพที่ ${i + 1}" loading="lazy" onerror="markMissingImage(this)">`}
              </div>
            `;
          }).join('')}
        </div>
      </div>
    ` : '<div style="color:#aaa;font-size:13px;text-align:center;padding:16px;">ไม่มีภาพประกอบ</div>'}

    <div class="record-meta-strip">
      <span>บันทึกเมื่อ: ${fmtDatetime(r.created_at)}</span>
      <span>แก้ไขล่าสุด: ${fmtDatetime(r.updated_at)}</span>
    </div>
  `;

  setRecordModalActionsVisible(true);
  const btnEdit = document.getElementById('btn-edit-record');
  btnEdit.style.display = canEdit ? 'inline-flex' : 'none';
  btnEdit.onclick = () => { closeModal('modal-view'); editRecord(id); };
  
  const btnPrint = document.getElementById('btnPrintRecord');
  if(btnPrint) btnPrint.onclick = () => {
    openPdfPreprintSettings(id, 'print');
  };
  
  const btnDownloadPdf = document.getElementById('btnDownloadPdf');
  if(btnDownloadPdf) btnDownloadPdf.onclick = () => { 
      openPdfPreprintSettings(id, 'download');
  };
  
  openModal('modal-view');
}

function openFullImg(src, alt = 'ภาพขยาย') {
  const imgEl = document.getElementById('image-viewer-img');
  if (!imgEl) return;
  imgEl.src = resolveImageSrc(src);
  imgEl.alt = alt;
  openModal('modal-image-viewer');
}
window.openFullImg = openFullImg;

// ============================================================
//  IMAGE UPLOAD
// ============================================================
const uploadArea = document.getElementById('upload-area');
if(uploadArea) {
    uploadArea.addEventListener('dragover', e => {
        e.preventDefault(); uploadArea.classList.add('drag-over');
    });
    uploadArea.addEventListener('dragleave', e => {
        uploadArea.classList.remove('drag-over');
    });
    uploadArea.addEventListener('drop', e => {
        e.preventDefault(); uploadArea.classList.remove('drag-over');
        processFiles([...e.dataTransfer.files]);
    });
    uploadArea.addEventListener('click', () => document.getElementById('file-input').click());
}

if(document.getElementById('file-input')) {
    document.getElementById('file-input').addEventListener('change', e => {
        processFiles([...e.target.files]);
        e.target.value = '';
    });
}

async function processFiles(files) {
  const imgFiles = files.filter(f => f.type.startsWith('image/'));
  if (imgFiles.length === 0) return;

  if (currentImages.length >= MAX_RECORD_IMAGES) {
    showToast('อัปโหลดได้สูงสุด 4 รูป', 'error');
    return;
  }

  let filesToUpload = imgFiles;
  const remainingSlots = MAX_RECORD_IMAGES - currentImages.length;
  if (imgFiles.length > remainingSlots) {
    showToast(`อัปโหลดได้สูงสุด 4 รูป ระบบจะรับเพิ่มอีก ${remainingSlots} รูปเท่านั้น`, 'error');
    filesToUpload = imgFiles.slice(0, remainingSlots);
  }

  const uploadArea = document.getElementById('upload-area');
  const loader = document.getElementById('global-loader');
  if (uploadArea) {
    uploadArea.style.opacity = '0.5';
    uploadArea.style.pointerEvents = 'none';
  }
  if (loader) {
    document.querySelector('#global-loader p').textContent = 'กำลังประมวลผลและอัปโหลดรูปภาพ...';
    loader.classList.add('show');
  }

  for (const file of filesToUpload) {
    if (file.size > 8 * 1024 * 1024) {
      showToast(`⚠️ ${file.name} ขนาดใหญ่เกินไป (>8MB)`, 'error');
      continue;
    }

    try {
      const compressedData = await compressImageFile(file);
      
      // Upload individual image immediately
      const formData = new FormData();
      const uploadName = `${file.name.replace(/\.[^.]+$/, '') || 'image'}.jpg`;
      formData.append('image', compressedData.blob, uploadName);
      
      const res = await apiCall('upload_image.php', 'POST', formData);
      if (res && res.status === 'success') {
          currentImages.push({ name: file.name, data: res.data.path }); 
      } else {
          showToast(`⚠️ อัปโหลดรูปภาพล้มเหลว: ${res?.message || file.name}`, 'error');
      }
    } catch (e) {
      console.error('Compression/Upload error:', e);
      showToast(`⚠️ เกิดข้อผิดพลาดในการจัดการรูปภาพ: ${file.name}`, 'error');
    }
  }

  renderImagePreviews();
  if (currentImages.length > 0 && !ALLOWED_RECORD_IMAGE_COUNTS.includes(currentImages.length)) {
    showToast(`ต้องอัปโหลด 2 รูป หรือ 4 รูป เท่านั้น ตอนนี้มี ${currentImages.length} รูป`, 'error');
  }
  
  if (uploadArea) {
    uploadArea.style.opacity = '1';
    uploadArea.style.pointerEvents = 'auto';
  }
  if (loader) loader.classList.remove('show');
}

function compressImageFile(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = e => {
      const img = new Image();
      img.onload = () => {
        let width = img.width;
        let height = img.height;
        const maxDimension = 800; // max 800px

        if (width > height && width > maxDimension) {
          height = Math.round((height * maxDimension) / width);
          width = maxDimension;
        } else if (height > maxDimension) {
          width = Math.round((width * maxDimension) / height);
          height = maxDimension;
        }

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, width, height);

        canvas.toBlob(blob => {
          if (!blob) return reject('toBlob failed');
          const previewUrl = URL.createObjectURL(blob);
          resolve({ name: file.name, data: previewUrl, blob: blob });
        }, 'image/jpeg', 0.6);
      };
      img.onerror = () => reject('Image load error');
      img.src = e.target.result;
    };
    reader.onerror = () => reject('File read error');
    reader.readAsDataURL(file);
  });
}

function renderImagePreviews() {
  const previewEl = document.getElementById('image-previews');
  const hintEl = document.getElementById('image-upload-hint');

  previewEl.innerHTML = currentImages.map((img, i) => {
    const imgSrc = resolveImageSrc(img.data);
    const isMissing = img.missing || img.exists === false || !imgSrc;

    return `
      <div class="image-preview-item${isMissing ? ' image-preview-item--missing' : ''}">
        ${isMissing
          ? `<div class="image-missing-label">ไม่พบไฟล์รูป<br><small>${escapeHtml(img.data || '')}</small></div>`
          : `<img src="${escapeHtml(imgSrc)}" alt="${escapeHtml(img.name)}" onerror="markMissingImage(this)">`}
        <button class="remove-img" onclick="removeImage(${i})">✕</button>
        <div class="img-caption">${escapeHtml((img.name || '').substring(0,16))}</div>
      </div>
    `;
  }).join('');

  if (hintEl) {
    if (currentImages.length === 0) {
      hintEl.textContent = 'อัปโหลดได้เฉพาะ 2 รูป หรือ 4 รูปเท่านั้น';
      hintEl.style.color = '#6b7a90';
    } else if (ALLOWED_RECORD_IMAGE_COUNTS.includes(currentImages.length)) {
      hintEl.textContent = `อัปโหลดแล้ว ${currentImages.length} รูป พร้อมบันทึกได้`;
      hintEl.style.color = '#1e824c';
    } else {
      hintEl.textContent = `ตอนนี้มี ${currentImages.length} รูป ต้องอัปโหลดให้ครบ 2 รูป หรือ 4 รูปเท่านั้น`;
      hintEl.style.color = '#c0392b';
    }
  }
}
// export for onclick
window.removeImage = removeImage;
function removeImage(i) {
  currentImages.splice(i, 1);
  renderImagePreviews();
}

function isValidRecordImageCount(count) {
  return count === 0 || ALLOWED_RECORD_IMAGE_COUNTS.includes(count);
}

document.querySelectorAll('.char-counted').forEach(el => {
    el.addEventListener('input', (e) => {
        const targetId = e.target.getAttribute('data-target');
        document.getElementById(targetId).textContent = e.target.value.length;
    });
});

// ============================================================
//  PRINT SETTINGS
// ============================================================
function getPrintSettingsNumericValue(id, fallback = 0) {
  const el = document.getElementById(id);
  if (!el) return fallback;
  const parsed = Number.parseInt(el.value, 10);
  return Number.isFinite(parsed) ? parsed : fallback;
}

function setPrintSettingsNumericValue(id, value) {
  const el = document.getElementById(id);
  if (!el) return;
  el.value = String(value);
}

function getPrintSettingsTextValue(id, fallback = '') {
  const el = document.getElementById(id);
  if (!el) return fallback;
  const value = String(el.value || '');
  return value === '' ? fallback : value;
}

function setPrintSettingsTextValue(id, value) {
  const el = document.getElementById(id);
  if (!el) return;
  el.value = value;
}

function parsePrintPreviewInt(value) {
  const parsed = Number.parseInt(value, 10);
  return Number.isFinite(parsed) ? parsed : null;
}

function buildLegacySectionPreviewState(settings, baseKey, options = {}) {
  const titleX = parsePrintPreviewInt(settings[`${baseKey}_title_abs_x`]);
  const titleY = parsePrintPreviewInt(settings[`${baseKey}_title_abs_y`]);
  const titleW = parsePrintPreviewInt(settings[`${baseKey}_title_abs_w`]);
  const titleH = parsePrintPreviewInt(settings[`${baseKey}_title_abs_h`]);
  const signX = parsePrintPreviewInt(settings[`${baseKey}_abs_x`]);
  const signY = parsePrintPreviewInt(settings[`${baseKey}_abs_y`]);
  const signW = parsePrintPreviewInt(settings[`${baseKey}_abs_w`]);
  const signH = parsePrintPreviewInt(settings[`${baseKey}_abs_h`]);

  if (titleX === null && titleY === null && signX === null && signY === null) {
    return null;
  }

  const defaultWidth = options.defaultWidth ?? 620;
  const defaultSignWidth = options.defaultSignWidth ?? defaultWidth;
  const defaultTitleHeight = options.defaultTitleHeight ?? 38;
  const defaultSignHeight = options.defaultSignHeight ?? 120;
  const defaultBlockHeight = options.defaultBlockHeight ?? 160;
  const fallbackTopFromSign = options.fallbackTopFromSign ?? 92;

  const xCandidates = [titleX, signX].filter(value => value !== null);
  const x = xCandidates.length ? Math.min(...xCandidates) : 0;
  const y = titleY !== null ? titleY : Math.max(0, (signY ?? 0) - fallbackTopFromSign);

  const rightCandidates = [];
  const bottomCandidates = [];

  if (titleX !== null) {
    rightCandidates.push(titleX + (titleW ?? defaultWidth));
    bottomCandidates.push((titleY ?? y) + (titleH ?? defaultTitleHeight) + 70);
  }

  if (signX !== null) {
    rightCandidates.push(signX + (signW ?? defaultSignWidth));
    bottomCandidates.push((signY ?? y) + (signH ?? defaultSignHeight));
  }

  const w = Math.max(defaultWidth, (rightCandidates.length ? Math.max(...rightCandidates) : x + defaultWidth) - x);
  const h = Math.max(defaultBlockHeight, (bottomCandidates.length ? Math.max(...bottomCandidates) : y + defaultBlockHeight) - y);

  return { x, y, w, h };
}

function loadPrintPreviewAbsoluteState(settings = {}) {
  printPreviewAbsoluteState = {};

  Object.keys(PRINT_PREVIEW_BLOCK_CONFIG).forEach(key => {
    const x = parsePrintPreviewInt(settings[`${key}_abs_x`]);
    const y = parsePrintPreviewInt(settings[`${key}_abs_y`]);
    const w = parsePrintPreviewInt(settings[`${key}_abs_w`]);
    const h = parsePrintPreviewInt(settings[`${key}_abs_h`]);

    if (x === null || y === null) return;

    printPreviewAbsoluteState[key] = {
      x,
      y,
      w,
      h
    };
  });

  if (!printPreviewAbsoluteState.sig2_block) {
    const legacySig2State = buildLegacySectionPreviewState(settings, 'sig2', {
      defaultWidth: 620,
      defaultSignWidth: 360,
      defaultSignHeight: 106,
      defaultBlockHeight: 150,
      fallbackTopFromSign: 112
    });
    if (legacySig2State) {
      printPreviewAbsoluteState.sig2_block = legacySig2State;
    }
  }

  if (!printPreviewAbsoluteState.sig2_signature) {
    const signX = parsePrintPreviewInt(settings.sig2_abs_x);
    const signY = parsePrintPreviewInt(settings.sig2_abs_y);
    const signW = parsePrintPreviewInt(settings.sig2_abs_w);
    const signH = parsePrintPreviewInt(settings.sig2_abs_h);

    if (signX !== null && signY !== null) {
      printPreviewAbsoluteState.sig2_signature = {
        x: signX,
        y: signY,
        w: signW ?? 430,
        h: signH ?? 120
      };
    } else if (printPreviewAbsoluteState.sig2_block) {
      const block = printPreviewAbsoluteState.sig2_block;
      const signatureWidth = Math.min(Math.max(Math.round((block.w || 620) * 0.68), 330), 430);
      const signatureHeight = 118;
      const align = String(settings.sig2_align || 'flex-end');
      let signatureX = block.x + Math.max(0, (block.w || 620) - signatureWidth - 12);

      if (align === 'center') {
        signatureX = block.x + Math.round(((block.w || 620) - signatureWidth) / 2);
      } else if (align === 'flex-start' || align === 'left') {
        signatureX = block.x + 12;
      }

      printPreviewAbsoluteState.sig2_signature = {
        x: signatureX,
        y: block.y + Math.max(104, (block.h || 150) - signatureHeight - 2),
        w: signatureWidth,
        h: signatureHeight
      };
    }
  }

  if (!printPreviewAbsoluteState.sig3_block) {
    const legacySig3State = buildLegacySectionPreviewState(settings, 'sig3', {
      defaultWidth: 620,
      defaultSignWidth: 620,
      defaultSignHeight: 128,
      defaultBlockHeight: 132,
      fallbackTopFromSign: 104
    });
    if (legacySig3State) {
      printPreviewAbsoluteState.sig3_block = legacySig3State;
    }
  }
}

function getPrintPreviewBlockState(key) {
  return printPreviewAbsoluteState[key] || null;
}

function setPrintPreviewBlockState(key, patch) {
  const current = getPrintPreviewBlockState(key) || {};
  printPreviewAbsoluteState[key] = {
    ...current,
    ...patch
  };
}

function requestPrintSettingsPdfPreviewNow() {
  schedulePrintSettingsPdfPreview(80);
}

function resetPrintPreviewBlockStateForInput(inputId) {
  Object.entries(PRINT_PREVIEW_BLOCK_CONFIG).forEach(([key, config]) => {
    const managedInputs = [
      config.xInput,
      config.yInput,
      config.marginInput,
      config.alignInput
    ].filter(Boolean);

    if (managedInputs.includes(inputId)) {
      delete printPreviewAbsoluteState[key];
    }
  });
}

function applyLegacyPrintPreviewBlockLayout(config, el, wrapEl) {
  const offsetX = getPrintSettingsNumericValue(config.xInput, 0);
  const offsetY = getPrintSettingsNumericValue(config.yInput, 0);

  if (config.type === 'signature_part') {
    el.style.position = 'relative';
    el.style.left = '';
    el.style.top = '';
    el.style.width = '';
    el.style.transform = `translate(${offsetX}px, ${offsetY}px)`;
    return;
  }

  if (config.type === 'signature') {
    const align = document.getElementById(config.alignInput)?.value || 'flex-end';
    const marginTop = getPrintSettingsNumericValue(config.marginInput, 0);
    if (wrapEl) {
      wrapEl.style.display = 'flex';
      wrapEl.style.justifyContent = align;
      wrapEl.style.marginTop = `${marginTop}px`;
      wrapEl.style.height = '';
    }
  } else if (wrapEl) {
    wrapEl.style.display = 'block';
    wrapEl.style.marginTop = '0px';
    wrapEl.style.height = '';
  }

  el.style.position = 'relative';
  el.style.left = '';
  el.style.top = '';
  el.style.width = config.preserveWidth ? `${Math.round(el.getBoundingClientRect().width || el.offsetWidth || 0)}px` : '';
  el.style.transform = `translate(${offsetX}px, ${offsetY}px)`;
}

function seedPrintPreviewBlockStateFromDom(key) {
  const page = document.getElementById('print-layout-page');
  const config = PRINT_PREVIEW_BLOCK_CONFIG[key];
  const el = config ? document.getElementById(config.elementId) : null;
  if (!page || !el || el.classList.contains('is-hidden')) return;

  const pageRect = page.getBoundingClientRect();
  const rect = el.getBoundingClientRect();

  setPrintPreviewBlockState(key, {
    x: Math.round(rect.left - pageRect.left),
    y: Math.round(rect.top - pageRect.top),
    w: Math.round(rect.width),
    h: Math.round(rect.height)
  });
}

function syncPrintPreviewBlockMetrics(key) {
  const config = PRINT_PREVIEW_BLOCK_CONFIG[key];
  const el = config ? document.getElementById(config.elementId) : null;
  if (!el || el.classList.contains('is-hidden')) return;

  setPrintPreviewBlockState(key, {
    w: Math.round(el.getBoundingClientRect().width),
    h: Math.round(el.getBoundingClientRect().height)
  });
}

function clampPrintPreviewBlockPosition(key, x, y) {
  const page = document.getElementById('print-layout-page');
  const config = PRINT_PREVIEW_BLOCK_CONFIG[key];
  const el = config ? document.getElementById(config.elementId) : null;
  const state = getPrintPreviewBlockState(key);
  if (!page || !el) {
    return { x, y };
  }

  const width = Math.round(state?.w || el.getBoundingClientRect().width || el.offsetWidth || 0);
  const height = Math.round(state?.h || el.getBoundingClientRect().height || el.offsetHeight || 0);
  const maxX = Math.max(0, page.clientWidth - width);
  const maxY = Math.max(0, page.clientHeight - height);

  return {
    x: Math.min(maxX, Math.max(0, x)),
    y: Math.min(maxY, Math.max(0, y))
  };
}

function applyFreeformPrintPreviewBlockLayout(key) {
  const page = document.getElementById('print-layout-page');
  const config = PRINT_PREVIEW_BLOCK_CONFIG[key];
  const el = config ? document.getElementById(config.elementId) : null;
  const wrapEl = config?.wrapId ? document.getElementById(config.wrapId) : null;
  const state = getPrintPreviewBlockState(key);
  if (!page || !config || !el || !state) return;

  if (wrapEl) {
    wrapEl.style.display = 'block';
    wrapEl.style.marginTop = '0';
    wrapEl.style.height = '0';
  }

  el.style.position = 'absolute';
  el.style.left = `${state.x}px`;
  el.style.top = `${state.y}px`;
  el.style.transform = 'none';

  if (
    config.preserveWidth ||
    config.type === 'title' ||
    el.classList.contains('print-layout-draggable--wide') ||
    el.classList.contains('print-layout-draggable--section')
  ) {
    if (state.w) {
      el.style.width = `${state.w}px`;
    }
  } else {
    el.style.width = '';
  }
}

function collectPrintPreviewAbsoluteSettings() {
  // Flow layout mode: clear all absolute positions so PDF uses flow layout
  // The preview drag-and-drop positions are no longer used for PDF rendering
  const metrics = {};

  Object.entries(PRINT_PREVIEW_BLOCK_CONFIG).forEach(([key]) => {
    metrics[`${key}_abs_x`] = '';
    metrics[`${key}_abs_y`] = '';
    metrics[`${key}_abs_w`] = '';
    metrics[`${key}_abs_h`] = '';
  });

  return metrics;
}

function formatPrintPreviewMultiline(value) {
  return formatPdfPreprintMultiline(value);
}

function placeCaretAtEnd(el) {
  if (!el || !window.getSelection || !document.createRange) return;
  const range = document.createRange();
  range.selectNodeContents(el);
  range.collapse(false);
  const selection = window.getSelection();
  selection.removeAllRanges();
  selection.addRange(range);
}

function setEditableText(el, value, fallback = '') {
  if (!el) return;
  if (el.classList.contains('is-editing')) return;
  const resolved = String(value || '') || fallback;
  el.dataset.currentFallback = fallback;
  el.textContent = resolved;
}

function setEditableHtml(el, value, fallback = '') {
  if (!el) return;
  if (el.classList.contains('is-editing')) return;
  const resolved = String(value || '') || fallback;
  el.dataset.currentFallback = fallback;
  el.innerHTML = formatPrintPreviewMultiline(resolved);
}

function getSignatureBalanceWidth(label) {
  return 0; // Balance width is intentionally disabled
}

function setSignatureBalanceWidth(el, label) {
  if (!el) return;
  el.style.width = '0px';
  el.style.display = 'none';
}

function normalizeEditablePlainText(value, fallback = '') {
  const normalized = String(value || '')
    .replace(/\u00a0/g, ' ');
  return normalized || fallback;
}

function normalizeEditableMultilineValue(value, fallback = '') {
  const normalized = normalizeMultilineText(value)
    .replace(/\r\n/g, '\n')
    .replace(/\r/g, '\n')
    .replace(/\u00a0/g, ' ')
    .split('\n')
    .map(line => line.trim())
    .join('\n')
    .replace(/\n{3,}/g, '\n\n')
    .replace(/^\n+|\n+$/g, '');

  return normalized || fallback;
}

function syncEditableElementToInput(el) {
  if (!el) return;

  const inputId = el.dataset.settingInput;
  if (!inputId) return;

  const fallback = el.dataset.currentFallback || el.dataset.emptyFallback || '';
  const isMultiline = el.dataset.allowBr === '1';
  const preserveEmpty = el.dataset.preserveEmpty === '1';
  const inputEl = document.getElementById(inputId);
  const currentValue = inputEl ? String(inputEl.value || '') : '';
  const nextValue = isMultiline
    ? normalizeEditableMultilineValue(el.innerText, fallback)
    : normalizeEditablePlainText(el.innerText, fallback);

  if (preserveEmpty && currentValue === '' && nextValue === fallback) {
    setPrintSettingsTextValue(inputId, '');
    return;
  }

  setPrintSettingsTextValue(inputId, nextValue);
}

function isPrintSettingsPageActive() {
  return document.getElementById('page-print-settings')?.classList.contains('active');
}

function normalizePrintSignatureLabel(value) {
  const label = String(value || '').trim();
  if (label === '' || label === 'ลงชื่อ') return '(ลงชื่อ)';
  return label;
}

function isPrintPositionTargetAvailable(key) {
  const config = PRINT_PREVIEW_BLOCK_CONFIG[key];
  if (!config) return false;
  if (config.districtOnly && currentUser?.role !== 'district') return false;

  const xInput = config.xInput ? document.getElementById(config.xInput) : null;
  const yInput = config.yInput ? document.getElementById(config.yInput) : null;

  return !!((xInput && !xInput.disabled) || (yInput && !yInput.disabled));
}

function getPrintPositionTargetKey() {
  const targetSelect = document.getElementById('print-position-target');
  let key = targetSelect?.value || selectedPrintPreviewBlock || 'sig1';

  if (!isPrintPositionTargetAvailable(key)) {
    key = isPrintPositionTargetAvailable('sig1') ? 'sig1' : Object.keys(PRINT_PREVIEW_BLOCK_CONFIG).find(isPrintPositionTargetAvailable);
  }

  if (targetSelect && key && targetSelect.value !== key) {
    targetSelect.value = key;
  }

  return key || null;
}

function getPrintPositionStep() {
  const stepInput = document.getElementById('print-position-step');
  const rawStep = Number(stepInput?.value);
  const step = Number.isFinite(rawStep) && rawStep > 0 ? rawStep : 5;
  return Math.max(1, Math.round(step));
}

function updatePrintPositionTargetOptions() {
  const targetSelect = document.getElementById('print-position-target');
  if (!targetSelect) return;

  Array.from(targetSelect.options).forEach(option => {
    const isAvailable = isPrintPositionTargetAvailable(option.value);
    option.disabled = !isAvailable;
    option.hidden = !isAvailable;
  });
}

function syncPrintPositionPanel() {
  const panel = document.getElementById('print-position-panel');
  if (!panel) return;

  updatePrintPositionTargetOptions();
  const key = getPrintPositionTargetKey();
  const config = key ? PRINT_PREVIEW_BLOCK_CONFIG[key] : null;
  const xInput = config?.xInput ? document.getElementById(config.xInput) : null;
  const yInput = config?.yInput ? document.getElementById(config.yInput) : null;
  const alignInput = config?.alignInput ? document.getElementById(config.alignInput) : null;
  const panelX = document.getElementById('print-position-x');
  const panelY = document.getElementById('print-position-y');
  const panelAlign = document.getElementById('print-position-align');
  const badge = document.getElementById('print-position-target-badge');
  const resetButton = document.getElementById('print-position-reset');
  const resetAllButton = document.getElementById('print-position-reset-all');
  const canMove = !!config && ((xInput && !xInput.disabled) || (yInput && !yInput.disabled));
  const hasMovableTarget = Object.keys(PRINT_PREVIEW_BLOCK_CONFIG).some(isPrintPositionTargetAvailable);

  if (badge) {
    badge.textContent = config?.label || 'เลือกตำแหน่ง';
  }

  if (panelX) {
    panelX.value = xInput ? getPrintSettingsNumericValue(config.xInput, 0) : 0;
    panelX.disabled = !xInput || xInput.disabled;
  }

  if (panelY) {
    panelY.value = yInput ? getPrintSettingsNumericValue(config.yInput, 0) : 0;
    panelY.disabled = !yInput || yInput.disabled;
  }

  if (panelAlign) {
    panelAlign.value = alignInput?.value || 'center';
    panelAlign.disabled = !alignInput || alignInput.disabled;
  }

  panel.querySelectorAll('[data-print-nudge]').forEach(button => {
    button.disabled = !canMove;
  });

  if (resetButton) {
    resetButton.disabled = !canMove;
  }

  if (resetAllButton) {
    resetAllButton.disabled = !hasMovableTarget;
  }

  const stepValue = String(getPrintPositionStep());
  panel.querySelectorAll('[data-print-step]').forEach(button => {
    button.classList.toggle('is-active', button.dataset.printStep === stepValue);
  });
}

function commitPrintPositionChange(inputIds = []) {
  setPrintSettingsSaveState('dirty', 'มีการแก้ไข');
  inputIds.filter(Boolean).forEach(resetPrintPreviewBlockStateForInput);
  renderPrintSettingsPreview();
  requestPrintSettingsPdfPreviewNow();
  syncPrintPositionPanel();
}

function nudgePrintPosition(direction) {
  const key = getPrintPositionTargetKey();
  const config = key ? PRINT_PREVIEW_BLOCK_CONFIG[key] : null;
  if (!config) return;

  const step = getPrintPositionStep();
  const delta = {
    left: [-step, 0],
    right: [step, 0],
    up: [0, -step],
    down: [0, step]
  }[direction];
  if (!delta) return;

  const changedInputIds = [];
  const xInput = config.xInput ? document.getElementById(config.xInput) : null;
  const yInput = config.yInput ? document.getElementById(config.yInput) : null;

  if (xInput && !xInput.disabled && delta[0] !== 0) {
    xInput.value = getPrintSettingsNumericValue(config.xInput, 0) + delta[0];
    changedInputIds.push(config.xInput);
  }

  if (yInput && !yInput.disabled && delta[1] !== 0) {
    yInput.value = getPrintSettingsNumericValue(config.yInput, 0) + delta[1];
    changedInputIds.push(config.yInput);
  }

  if (changedInputIds.length) {
    commitPrintPositionChange(changedInputIds);
  }
}

function setPrintPositionAxis(axis, rawValue) {
  const key = getPrintPositionTargetKey();
  const config = key ? PRINT_PREVIEW_BLOCK_CONFIG[key] : null;
  const inputId = axis === 'x' ? config?.xInput : config?.yInput;
  const targetInput = inputId ? document.getElementById(inputId) : null;
  const value = Number(rawValue);

  if (!targetInput || targetInput.disabled || !Number.isFinite(value)) return;

  targetInput.value = Math.round(value);
  commitPrintPositionChange([inputId]);
}

function setPrintPositionAlign(value) {
  const key = getPrintPositionTargetKey();
  const config = key ? PRINT_PREVIEW_BLOCK_CONFIG[key] : null;
  const alignInput = config?.alignInput ? document.getElementById(config.alignInput) : null;

  if (!alignInput || alignInput.disabled) return;

  alignInput.value = value;
  commitPrintPositionChange([config.alignInput]);
}

function resetPrintPositionTarget() {
  const key = getPrintPositionTargetKey();
  const config = key ? PRINT_PREVIEW_BLOCK_CONFIG[key] : null;
  if (!config) return;

  const changedInputIds = [];
  const xInput = config.xInput ? document.getElementById(config.xInput) : null;
  const yInput = config.yInput ? document.getElementById(config.yInput) : null;

  if (xInput && !xInput.disabled) {
    xInput.value = 0;
    changedInputIds.push(config.xInput);
  }

  if (yInput && !yInput.disabled) {
    yInput.value = 0;
    changedInputIds.push(config.yInput);
  }

  if (changedInputIds.length) {
    commitPrintPositionChange(changedInputIds);
  }
}

function resetAllPrintPositions() {
  const changedInputIds = new Set();

  Object.entries(PRINT_PREVIEW_BLOCK_CONFIG).forEach(([key, config]) => {
    if (!isPrintPositionTargetAvailable(key)) return;

    [config.xInput, config.yInput].filter(Boolean).forEach(inputId => {
      const input = document.getElementById(inputId);
      if (!input || input.disabled) return;
      if (String(input.value) === '0') return;

      input.value = 0;
      changedInputIds.add(inputId);
    });
  });

  if (changedInputIds.size) {
    commitPrintPositionChange(Array.from(changedInputIds));
  }
}

function selectPrintPreviewBlock(key) {
  if (!PRINT_PREVIEW_BLOCK_CONFIG[key]) return;
  selectedPrintPreviewBlock = key;
  document.querySelectorAll('.print-layout-selected-target').forEach(el => {
    el.classList.remove('print-layout-selected-target');
  });
  document.querySelectorAll('.print-layout-draggable').forEach(el => {
    el.classList.toggle('is-selected', el.dataset.previewBlock === key);
  });

  const config = PRINT_PREVIEW_BLOCK_CONFIG[key];
  const targetEl = config.elementId ? document.getElementById(config.elementId) : null;
  if (targetEl) {
    targetEl.classList.add('print-layout-selected-target');
  }

  const label = document.getElementById('print-preview-selected-label');
  if (label) {
    label.textContent = `กำลังเลือก: ${config.label}`;
  }

  const targetSelect = document.getElementById('print-position-target');
  if (targetSelect && isPrintPositionTargetAvailable(key) && targetSelect.value !== key) {
    targetSelect.value = key;
  }
  syncPrintPositionPanel();
}

function renderPrintSettingsPreview() {
  const previewPage = document.getElementById('print-layout-page');
  if (!previewPage) return;
  // Use flow layout (not freeform/absolute) so preview matches PDF output
  previewPage.classList.remove('print-layout-page--freeform');

  const isDistrict = currentUser?.role === 'district';
  if (!isDistrict && !['sig1', 'sig1_line', 'sig1_sign_prefix', 'sig1_name', 'sig1_pos'].includes(selectedPrintPreviewBlock)) {
    selectedPrintPreviewBlock = 'sig1';
  }

  const sig1Name = getPrintSettingsTextValue('set_sig1_name', currentUser?.name || 'ชื่อผู้บันทึก');
  const sig1Pos = getPrintSettingsTextValue('set_sig1_pos', 'ครูผู้สอน');
  const sig1SignPrefix = normalizePrintSignatureLabel(getPrintSettingsTextValue('set_sig1_sign_prefix', '(ลงชื่อ)'));
  const sig1SignSuffix = getPrintSettingsTextValue('set_sig1_sign_suffix', 'ผู้บันทึก');
  const sig2Title = getPrintSettingsTextValue('set_sig2_title', 'ความคิดเห็นของหัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ');
  const sig2SignLabel = getPrintSettingsTextValue('set_sig2_sign_label', '(ลงชื่อ)');
  const sig2Name = getPrintSettingsTextValue('set_sig2_name', '........................................................');
  const sig2Pos = getPrintSettingsTextValue('set_sig2_pos', 'หัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ');
  const sig2Align = getPrintSettingsInputValue('set_sig2_align', FLOW_PDF_LAYOUT.sig2_align);
  const sig3Title = getPrintSettingsTextValue('set_sig3_title', 'ข้อคิดเห็นของผู้อำนวยการสถานศึกษา');
  const sig3SignLabel = getPrintSettingsTextValue('set_sig3_sign_label', '(ลงชื่อ)');
  const sig3Name = getPrintSettingsTextValue('set_sig3_name', 'นางมุจรินทร์ ชมวิชา');
  const sig3Pos = getPrintSettingsTextValue('set_sig3_pos', 'ครู รักษาการในตำแหน่ง<br>ผู้อำนวยการสถานศึกษา ศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอเสนา');
  const sig3Align = getPrintSettingsInputValue('set_sig3_align', FLOW_PDF_LAYOUT.sig3_align);
  const sig1DotWidth = getPrintSettingsNumericValue('set_sig1_dot_width', Number(FLOW_PDF_LAYOUT.sig1_dot_width));
  const sig2DotWidth = getPrintSettingsNumericValue('set_sig2_dot_width', Number(FLOW_PDF_LAYOUT.sig2_dot_width));
  const sig3DotWidth = getPrintSettingsNumericValue('set_sig3_dot_width', Number(FLOW_PDF_LAYOUT.sig3_dot_width));
  const sig2MarginTop = getPrintSettingsNumericValue('set_sig2_mt', Number(FLOW_PDF_LAYOUT.sig2_mt));
  const sig3MarginTop = getPrintSettingsNumericValue('set_sig3_mt', Number(FLOW_PDF_LAYOUT.sig3_mt));
  const sig3OffsetX = getPrintSettingsNumericValue('set_sig3_offset', Number(FLOW_PDF_LAYOUT.sig3_offset));
  const sig3OffsetY = getPrintSettingsNumericValue('set_sig3_offset_y', Number(FLOW_PDF_LAYOUT.sig3_offset_y));
  const sig2TitleOffsetX = getPrintSettingsNumericValue('set_sig2_title_offset_x', 0);
  const sig2TitleOffsetY = getPrintSettingsNumericValue('set_sig2_title_offset_y', 0);
  const sig3TitleOffsetX = getPrintSettingsNumericValue('set_sig3_title_offset_x', 0);
  const sig3TitleOffsetY = getPrintSettingsNumericValue('set_sig3_title_offset_y', 0);

  const previewSig1SignPrefix = document.getElementById('print-preview-sig1-sign-prefix');
  const previewSig1SignSuffix = document.getElementById('print-preview-sig1-sign-suffix');
  const previewSig1Balance = document.getElementById('print-preview-sig1-balance');
  const previewSig1Name = document.getElementById('print-preview-sig1-name');
  const previewSig1Pos = document.getElementById('print-preview-sig1-pos');
  const previewSig1Dot = document.getElementById('print-preview-sig1-dot');
  const previewSig2Block = document.getElementById('print-preview-sig2-block');
  const previewSig2Title = document.getElementById('print-preview-sig2-title-text');
  const previewSig2SignLabel = document.getElementById('print-preview-sig2-sign-prefix');
  const previewSig2Name = document.getElementById('print-preview-sig2-name');
  const previewSig2Pos = document.getElementById('print-preview-sig2-pos');
  const previewSig2Dot = document.getElementById('print-preview-sig2-dot');
  const previewSig2Balance = document.getElementById('print-preview-sig2-balance');
  const previewSig2Wrap = document.getElementById('print-preview-sig2-signature-wrap');
  const previewSig2 = document.getElementById('print-preview-sig2');
  const previewSig2Signature = document.getElementById('print-preview-sig2-signature');
  const previewSig3Block = document.getElementById('print-preview-sig3-block');
  const previewSig3Title = document.getElementById('print-preview-sig3-title-text');
  const previewSig3SignLabel = document.getElementById('print-preview-sig3-sign-prefix');
  const previewSig3Name = document.getElementById('print-preview-sig3-name');
  const previewSig3Pos = document.getElementById('print-preview-sig3-pos');
  const previewSig3Dot = document.getElementById('print-preview-sig3-dot');
  const previewSig3Balance = document.getElementById('print-preview-sig3-balance');
  const previewSig3Wrap = document.getElementById('print-preview-sig3-wrap');
  const previewSig3 = document.getElementById('print-preview-sig3');

  setEditableText(previewSig1SignPrefix, sig1SignPrefix, '(ลงชื่อ)');
  setEditableText(previewSig1SignSuffix, sig1SignSuffix, 'ผู้บันทึก');
  setSignatureBalanceWidth(previewSig1Balance, sig1SignSuffix ? '' : sig1SignPrefix);
  setEditableText(previewSig1Name, sig1Name, 'ชื่อผู้บันทึก');
  setEditableText(previewSig1Pos, sig1Pos, 'ครูผู้สอน');
  if (previewSig1Dot) previewSig1Dot.style.width = `${sig1DotWidth}px`;
  setEditableText(previewSig2Title, sig2Title, 'ความคิดเห็นของหัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ');
  setEditableText(previewSig2SignLabel, sig2SignLabel, '(ลงชื่อ)');
  setEditableText(previewSig2Name, sig2Name, '........................................................');
  setEditableHtml(previewSig2Pos, sig2Pos, 'หัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ');
  if (previewSig2Dot) previewSig2Dot.style.width = `${sig2DotWidth}px`;
  setSignatureBalanceWidth(previewSig2Balance, sig2SignLabel);
  if (previewSig2Title) previewSig2Title.style.transform = `translate(${sig2TitleOffsetX}px, ${sig2TitleOffsetY}px)`;
  if (previewSig2Wrap) {
    previewSig2Wrap.style.display = 'flex';
    previewSig2Wrap.style.justifyContent = sig2Align;
    previewSig2Wrap.style.marginTop = `${sig2MarginTop}px`;
  }
  if (previewSig2) previewSig2.style.transform = '';
  if (previewSig2Signature) previewSig2Signature.style.width = '';
  setEditableText(previewSig3Title, sig3Title, 'ข้อคิดเห็นของผู้อำนวยการสถานศึกษา');
  setEditableText(previewSig3SignLabel, sig3SignLabel, '(ลงชื่อ)');
  setEditableText(previewSig3Name, sig3Name, 'ชื่อผู้อำนวยการ');
  setEditableHtml(previewSig3Pos, sig3Pos, 'ครู รักษาการในตำแหน่ง<br>ผู้อำนวยการสถานศึกษา ศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอเสนา');
  if (previewSig3Dot) previewSig3Dot.style.width = `${sig3DotWidth}px`;
  setSignatureBalanceWidth(previewSig3Balance, sig3SignLabel);
  if (previewSig3Title) previewSig3Title.style.transform = `translate(${sig3TitleOffsetX}px, ${sig3TitleOffsetY}px)`;
  if (previewSig3Wrap) {
    previewSig3Wrap.style.display = 'flex';
    previewSig3Wrap.style.justifyContent = sig3Align;
    previewSig3Wrap.style.marginTop = `${sig3MarginTop}px`;
  }
  if (previewSig3) previewSig3.style.transform = `translate(${sig3OffsetX}px, ${sig3OffsetY}px)`;
  if (previewSig2Block) previewSig2Block.style.width = '';
  if (previewSig3Block) previewSig3Block.style.width = '';

  Object.entries(PRINT_PREVIEW_BLOCK_CONFIG).forEach(([key, config]) => {
    const el = document.getElementById(config.elementId);
    const wrapEl = config.wrapId ? document.getElementById(config.wrapId) : null;
    if (!el) return;

    const shouldHide = !!config.districtOnly && !isDistrict;
    el.classList.toggle('is-hidden', shouldHide);
    if (wrapEl) {
      wrapEl.style.display = shouldHide ? 'none' : '';
    }
    if (shouldHide) return;
  });

  Object.entries(PRINT_PREVIEW_BLOCK_CONFIG).forEach(([key, config]) => {
    const el = document.getElementById(config.elementId);
    const wrapEl = config.wrapId ? document.getElementById(config.wrapId) : null;
    if (!el || el.classList.contains('is-hidden')) return;

    // Use flow (legacy) layout — blocks position relative to their natural flow,
    // matching exactly how print.php renders in the PDF
    applyLegacyPrintPreviewBlockLayout(config, el, wrapEl);
  });

  selectPrintPreviewBlock(selectedPrintPreviewBlock);
}
/**
 * Sync drag delta to offset input fields (flow layout mode).
 * When the user drags a block in the preview, this updates the offset input fields
 * so the PDF flow layout matches what the user sees.
 */
function syncDragDeltaToOffsetInputs(config, deltaX, deltaY, startInputX, startInputY) {
  const xInput = config.xInput ? document.getElementById(config.xInput) : null;
  const yInput = config.yInput ? document.getElementById(config.yInput) : null;

  if (xInput) {
    xInput.value = Math.round(startInputX + deltaX);
  }
  if (yInput) {
    yInput.value = Math.round(startInputY + deltaY);
  }
}

function handlePrintPreviewPointerDown(e) {
  if (!isPrintSettingsPageActive()) return;
  if (e.target.closest('.print-layout-editable')) {
    const blockEl = e.currentTarget;
    selectPrintPreviewBlock(blockEl.dataset.previewBlock);
    return;
  }
  const blockEl = e.currentTarget;
  const key = blockEl.dataset.previewBlock;
  const config = PRINT_PREVIEW_BLOCK_CONFIG[key];
  if (!config || !isPrintPositionTargetAvailable(key)) return;

  selectPrintPreviewBlock(key);

  // Store starting offset values from input fields
  const startInputX = getPrintSettingsNumericValue(config.xInput, 0);
  const startInputY = getPrintSettingsNumericValue(config.yInput, 0);

  activePrintPreviewDrag = {
    key,
    element: blockEl,
    startX: e.clientX,
    startY: e.clientY,
    startInputX,
    startInputY
  };

  blockEl.classList.add('is-selected');
  blockEl.classList.add('is-dragging');
  blockEl.style.zIndex = '12';
  e.preventDefault();
}

function handlePrintPreviewPointerMove(e) {
  if (!activePrintPreviewDrag) return;
  const config = PRINT_PREVIEW_BLOCK_CONFIG[activePrintPreviewDrag.key];
  if (!config) return;

  const deltaX = Math.round(e.clientX - activePrintPreviewDrag.startX);
  const deltaY = Math.round(e.clientY - activePrintPreviewDrag.startY);

  // Update offset input fields directly — these are the same values print.php uses
  syncDragDeltaToOffsetInputs(config, deltaX, deltaY, activePrintPreviewDrag.startInputX, activePrintPreviewDrag.startInputY);
  setPrintSettingsSaveState('dirty', 'มีการแก้ไข');

  // Re-render the drag workspace immediately. Refreshing the PDF waits until pointerup.
  renderPrintSettingsPreview();
  syncPrintPositionPanel();
}

function handlePrintPreviewPointerUp() {
  if (activePrintPreviewDrag) {
    const config = PRINT_PREVIEW_BLOCK_CONFIG[activePrintPreviewDrag.key];
    const el = config ? document.getElementById(config.elementId) : null;
    if (el) {
      el.style.zIndex = '';
      el.classList.remove('is-dragging');
    }
    requestPrintSettingsPdfPreviewNow();
  }
  activePrintPreviewDrag = null;
}

function handlePrintPreviewKeydown(e) {
  if (!isPrintSettingsPageActive() || !selectedPrintPreviewBlock) return;
  if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(e.key)) return;

  const activeTag = document.activeElement?.tagName;
  if (activeTag === 'INPUT' || activeTag === 'TEXTAREA' || activeTag === 'SELECT') {
    return;
  }

  const config = PRINT_PREVIEW_BLOCK_CONFIG[selectedPrintPreviewBlock];
  if (!config || !isPrintPositionTargetAvailable(selectedPrintPreviewBlock)) return;

  const step = e.shiftKey ? 10 : 1;
  let deltaX = 0;
  let deltaY = 0;
  if (e.key === 'ArrowLeft') deltaX = -step;
  if (e.key === 'ArrowRight') deltaX = step;
  if (e.key === 'ArrowUp') deltaY = -step;
  if (e.key === 'ArrowDown') deltaY = step;

  // Update offset input fields directly and re-render
  const currentInputX = getPrintSettingsNumericValue(config.xInput, 0);
  const currentInputY = getPrintSettingsNumericValue(config.yInput, 0);
  syncDragDeltaToOffsetInputs(config, deltaX, deltaY, currentInputX, currentInputY);
  setPrintSettingsSaveState('dirty', 'มีการแก้ไข');
  renderPrintSettingsPreview();
  requestPrintSettingsPdfPreviewNow();
  syncPrintPositionPanel();

  e.preventDefault();
}

function initializePrintPositionPanel() {
  const panel = document.getElementById('print-position-panel');
  if (!panel || panel.dataset.positionReady === '1') return;

  panel.dataset.positionReady = '1';

  const targetSelect = document.getElementById('print-position-target');
  const xInput = document.getElementById('print-position-x');
  const yInput = document.getElementById('print-position-y');
  const alignSelect = document.getElementById('print-position-align');
  const resetButton = document.getElementById('print-position-reset');
  const resetAllButton = document.getElementById('print-position-reset-all');

  if (targetSelect) {
    targetSelect.addEventListener('change', () => {
      selectPrintPreviewBlock(targetSelect.value);
      syncPrintPositionPanel();
    });
  }

  panel.querySelectorAll('[data-print-nudge]').forEach(button => {
    button.addEventListener('click', () => nudgePrintPosition(button.dataset.printNudge));
  });

  panel.querySelectorAll('[data-print-step]').forEach(button => {
    button.addEventListener('click', () => {
      const stepInput = document.getElementById('print-position-step');
      if (stepInput) {
        stepInput.value = button.dataset.printStep || '5';
      }
      panel.querySelectorAll('[data-print-step]').forEach(stepButton => {
        stepButton.classList.toggle('is-active', stepButton === button);
      });
    });
  });

  if (xInput) {
    xInput.addEventListener('input', () => {
      const rawValue = String(xInput.value).trim();
      if (rawValue === '' || rawValue === '-') return;
      setPrintPositionAxis('x', rawValue);
    });
  }

  if (yInput) {
    yInput.addEventListener('input', () => {
      const rawValue = String(yInput.value).trim();
      if (rawValue === '' || rawValue === '-') return;
      setPrintPositionAxis('y', rawValue);
    });
  }

  if (alignSelect) {
    alignSelect.addEventListener('change', () => setPrintPositionAlign(alignSelect.value));
  }

  if (resetButton) {
    resetButton.addEventListener('click', resetPrintPositionTarget);
  }

  if (resetAllButton) {
    resetAllButton.addEventListener('click', resetAllPrintPositions);
  }

  syncPrintPositionPanel();
}

function initializePrintSettingsWorkbench() {
  const page = document.getElementById('page-print-settings');
  if (!page || page.dataset.previewReady === '1') return;

  page.dataset.previewReady = '1';
  initializePrintPositionPanel();
  resetPdfZoom();

  const fontSizeSelect = document.getElementById('set_pdf_font_size_pt');
  if (fontSizeSelect) {
    const handleFontSizeChange = () => {
      applyPdfFontSizeSetting(fontSizeSelect.value);
      const workspacePage = document.getElementById('print-layout-page');
      if (workspacePage) {
        workspacePage.style.setProperty('--font-size-offset', `${getPdfFontSizeOffsetPx(fontSizeSelect.value)}px`);
      }
      setPrintSettingsSaveState('dirty', 'มีการแก้ไข');
      schedulePrintSettingsPdfPreview(200);
    };
    fontSizeSelect.addEventListener('change', handleFontSizeChange);
  }

  const dotSlider = document.getElementById('set_pdf_dot_size');
  if (dotSlider) {
    const handleDotSlider = () => {
      updatePdfDotSizeLabel(dotSlider.value);
      updateOnScreenDottedLines(dotSlider.value);
      setPrintSettingsSaveState('dirty', 'มีการแก้ไข');
      schedulePrintSettingsPdfPreview(200);
    };
    dotSlider.addEventListener('input', handleDotSlider);
    dotSlider.addEventListener('change', handleDotSlider);
  }

  [
    'set_pdf_line_spacing',
    'set_pdf_margin_top_px',
    'set_pdf_margin_right_px',
    'set_pdf_margin_bottom_px',
    'set_pdf_margin_left_px',
    'set_signature_font_size_pt',
    'set_attendance_font_size_pt'
  ].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    const rerenderDocumentSetting = () => {
      setPrintSettingsSaveState('dirty', 'มีการแก้ไข');
      renderPrintSettingsPreview();
      requestPrintSettingsPdfPreviewNow();
    };
    el.addEventListener('input', rerenderDocumentSetting);
    el.addEventListener('change', rerenderDocumentSetting);
  });

  page.querySelectorAll('input[id^="set_sig"], select[id^="set_sig"]').forEach(el => {
    const rerender = () => {
      setPrintSettingsSaveState('dirty', 'มีการแก้ไข');
      resetPrintPreviewBlockStateForInput(el.id);
      renderPrintSettingsPreview();
      requestPrintSettingsPdfPreviewNow();
      syncPrintPositionPanel();
    };
    el.addEventListener('input', rerender);
    el.addEventListener('change', rerender);
  });

  document.querySelectorAll('.print-layout-draggable--signature-block').forEach(el => {
    el.addEventListener('pointerdown', handlePrintPreviewPointerDown);
    el.addEventListener('click', () => selectPrintPreviewBlock(el.dataset.previewBlock));
  });

  page.querySelectorAll('.print-layout-editable').forEach(el => {
    el.setAttribute('contenteditable', 'true');
    el.setAttribute('spellcheck', 'false');
    el.setAttribute('tabindex', '0');

    el.addEventListener('pointerdown', evt => {
      evt.stopPropagation();
      if (document.activeElement !== el) {
        requestAnimationFrame(() => {
          el.focus();
          placeCaretAtEnd(el);
        });
      }
    });

    el.addEventListener('click', evt => {
      evt.stopPropagation();
      if (document.activeElement !== el) {
        el.focus();
      }
    });

    el.addEventListener('focus', () => {
      el.classList.add('is-editing');
      const blockEl = el.closest('.print-layout-draggable');
      if (blockEl) {
        selectPrintPreviewBlock(blockEl.dataset.previewBlock);
      }
    });

    el.addEventListener('input', () => {
      syncEditableElementToInput(el);
      setPrintSettingsSaveState('dirty', 'มีการแก้ไข');
    });

    el.addEventListener('blur', () => {
      el.classList.remove('is-editing');
      syncEditableElementToInput(el);
      setPrintSettingsSaveState('dirty', 'มีการแก้ไข');
      renderPrintSettingsPreview();
      requestPrintSettingsPdfPreviewNow();
    });

    el.addEventListener('keydown', evt => {
      if (evt.key === 'Enter' && el.dataset.allowBr !== '1') {
        evt.preventDefault();
        el.blur();
      }
    });

    el.addEventListener('paste', evt => {
      evt.preventDefault();
      const text = evt.clipboardData?.getData('text/plain') || '';
      const normalized = el.dataset.allowBr === '1'
        ? text.replace(/\r\n/g, '\n').replace(/\r/g, '\n')
        : text.replace(/\s+/g, ' ');
      document.execCommand('insertText', false, normalized);
    });
  });

  document.addEventListener('pointermove', handlePrintPreviewPointerMove);
  document.addEventListener('pointerup', handlePrintPreviewPointerUp);
  document.addEventListener('keydown', handlePrintPreviewKeydown);
}

function syncPrintSettingsUI() {
  const subtitle = document.getElementById('print-settings-subtitle');
  const sig2Card = document.getElementById('print-settings-sig2-card');
  const sig3Card = document.getElementById('print-settings-sig3-card');
  const page = document.getElementById('page-print-settings');

  const dragDetails = document.getElementById('print-drag-details');
  if (currentUser.role === 'tambon') {
    const tambon = getTambonById(currentUser.tambon);
    if (subtitle) {
      subtitle.textContent = `แก้ไขข้อความผู้บันทึกและขนาดตัวอักษรสำหรับตำบล${tambon?.name || ''} แล้วดูตัวอย่าง PDF ด้านล่าง`;
    }
    if (dragDetails) dragDetails.style.display = 'none';
    if (sig2Card) sig2Card.style.display = 'none';
    if (sig3Card) sig3Card.style.display = 'none';
    if (page) page.classList.add('print-settings-layout-locked');
  } else {
    if (subtitle) {
      subtitle.textContent = 'แก้ไขข้อความ ตำแหน่งลายเซ็น แล้วดูตัวอย่าง PDF ขนาดเต็มด้านล่าง';
    }
    if (dragDetails) dragDetails.style.display = '';
    if (sig2Card) sig2Card.style.display = '';
    if (sig3Card) sig3Card.style.display = '';
    if (page) page.classList.remove('print-settings-layout-locked');
  }
}

function getPrintSettingsInputValue(id, fallback = '') {
  const el = document.getElementById(id);
  return el ? el.value : fallback;
}

function setPrintSettingsSaveState(state, message) {
  const status = document.getElementById('print-settings-save-status');
  const button = document.getElementById('savePrintSettingsBtn');

  if (status) {
    status.textContent = message;
    status.dataset.state = state;
  }

  if (button) {
    button.disabled = state === 'saving';
    button.textContent = state === 'saving' ? 'กำลังบันทึก...' : 'บันทึกการตั้งค่า';
  }
}

function collectSignaturePartOffsetPayload(prefix) {
  const payload = {};
  ['line', 'name', 'pos', 'sign_prefix', 'sign_label'].forEach(part => {
    ['x', 'y'].forEach(axis => {
      const key = `${prefix}_${part}_offset_${axis}`;
      payload[key] = getPrintSettingsInputValue(`set_${key}`, '0');
    });
  });
  return payload;
}

function applySignaturePartOffsetSettings(settings, prefix) {
  ['line', 'name', 'pos', 'sign_prefix', 'sign_label'].forEach(part => {
    ['x', 'y'].forEach(axis => {
      const key = `${prefix}_${part}_offset_${axis}`;
      const el = document.getElementById(`set_${key}`);
      if (el && settings[key] !== undefined) {
        el.value = settings[key];
      }
    });
  });
}

function collectPrintSettingsPayload() {
  const pdfFontSizePt = getPdfFontSizePt();
  const payload = {
    pdf_font_size_pt: String(pdfFontSizePt),
    font_size_offset: String(getPdfFontSizeOffsetPx(pdfFontSizePt)),
    pdf_dot_size: getPrintSettingsInputValue('set_pdf_dot_size', '2'),
    pdf_line_spacing: String(clampNumber(getPrintSettingsInputValue('set_pdf_line_spacing', '26'), 20, 40, 26)),
    pdf_margin_top_px: String(clampNumber(getPrintSettingsInputValue('set_pdf_margin_top_px', '57'), 20, 140, 57)),
    pdf_margin_right_px: String(clampNumber(getPrintSettingsInputValue('set_pdf_margin_right_px', '76'), 20, 160, 76)),
    pdf_margin_bottom_px: String(clampNumber(getPrintSettingsInputValue('set_pdf_margin_bottom_px', '38'), 20, 140, 38)),
    pdf_margin_left_px: String(clampNumber(getPrintSettingsInputValue('set_pdf_margin_left_px', '94'), 20, 160, 94)),
    signature_font_size_pt: String(normalizePdfFontSizePt(getPrintSettingsInputValue('set_signature_font_size_pt', String(pdfFontSizePt)))),
    attendance_font_size_pt: String(normalizePdfFontSizePt(getPrintSettingsInputValue('set_attendance_font_size_pt', String(pdfFontSizePt)))),
    sig1_name: document.getElementById('set_sig1_name').value,
    sig1_pos: document.getElementById('set_sig1_pos').value,
    sig1_align: getPrintSettingsInputValue('set_sig1_align', FLOW_PDF_LAYOUT.sig1_align),
    sig1_dot_width: getPrintSettingsInputValue('set_sig1_dot_width', FLOW_PDF_LAYOUT.sig1_dot_width),
    sig1_mt: getPrintSettingsInputValue('set_sig1_mt', FLOW_PDF_LAYOUT.sig1_mt),
    sig1_offset: getPrintSettingsInputValue('set_sig1_offset', FLOW_PDF_LAYOUT.sig1_offset),
    sig1_offset_y: getPrintSettingsInputValue('set_sig1_offset_y', FLOW_PDF_LAYOUT.sig1_offset_y),
    sig1_sign_prefix: normalizePrintSignatureLabel(document.getElementById('set_sig1_sign_prefix').value),
    sig1_sign_suffix: document.getElementById('set_sig1_sign_suffix')?.value || 'ผู้บันทึก',
    sig1_abs_x: '',
    sig1_abs_y: '',
    sig1_abs_w: '',
    sig1_abs_h: ''
  };

  Object.assign(payload, collectSignaturePartOffsetPayload('sig1'));
  Object.assign(payload, collectPrintPreviewAbsoluteSettings());

  if (currentUser.role === 'district') {
    Object.assign(payload, {
      sig2_title: document.getElementById('set_sig2_title').value,
      sig2_sign_label: normalizePrintSignatureLabel(document.getElementById('set_sig2_sign_label').value),
      sig2_name: document.getElementById('set_sig2_name').value,
      sig2_pos: document.getElementById('set_sig2_pos').value,
      sig2_align: getPrintSettingsInputValue('set_sig2_align', FLOW_PDF_LAYOUT.sig2_align),
      sig3_title: document.getElementById('set_sig3_title').value,
      sig3_sign_label: normalizePrintSignatureLabel(document.getElementById('set_sig3_sign_label').value),
      sig3_name: document.getElementById('set_sig3_name').value,
      sig3_pos: document.getElementById('set_sig3_pos').value,
      sig3_align: getPrintSettingsInputValue('set_sig3_align', FLOW_PDF_LAYOUT.sig3_align),
      sig2_dot_width: getPrintSettingsInputValue('set_sig2_dot_width', FLOW_PDF_LAYOUT.sig2_dot_width),
      sig2_block_offset_x: getPrintSettingsInputValue('set_sig2_block_offset_x', FLOW_PDF_LAYOUT.sig2_block_offset_x),
      sig2_block_offset_y: getPrintSettingsInputValue('set_sig2_block_offset_y', FLOW_PDF_LAYOUT.sig2_block_offset_y),
      sig2_mt: getPrintSettingsInputValue('set_sig2_mt', FLOW_PDF_LAYOUT.sig2_mt),
      sig2_offset: getPrintSettingsInputValue('set_sig2_offset', FLOW_PDF_LAYOUT.sig2_offset),
      sig2_offset_y: getPrintSettingsInputValue('set_sig2_offset_y', FLOW_PDF_LAYOUT.sig2_offset_y),
      ...collectSignaturePartOffsetPayload('sig2'),
      sig2_title_offset_x: getPrintSettingsInputValue('set_sig2_title_offset_x', '0'),
      sig2_title_offset_y: getPrintSettingsInputValue('set_sig2_title_offset_y', '0'),
      sig2_abs_x: '',
      sig2_abs_y: '',
      sig2_abs_w: '',
      sig2_abs_h: '',
      sig2_title_abs_x: '',
      sig2_title_abs_y: '',
      sig2_title_abs_w: '',
      sig2_title_abs_h: '',
      sig2_block_abs_x: '',
      sig2_block_abs_y: '',
      sig2_block_abs_w: '',
      sig2_block_abs_h: '',
      sig2_signature_abs_x: '',
      sig2_signature_abs_y: '',
      sig2_signature_abs_w: '',
      sig2_signature_abs_h: '',
      sig3_dot_width: getPrintSettingsInputValue('set_sig3_dot_width', FLOW_PDF_LAYOUT.sig3_dot_width),
      sig3_block_offset_x: getPrintSettingsInputValue('set_sig3_block_offset_x', FLOW_PDF_LAYOUT.sig3_block_offset_x),
      sig3_block_offset_y: getPrintSettingsInputValue('set_sig3_block_offset_y', FLOW_PDF_LAYOUT.sig3_block_offset_y),
      sig3_mt: getPrintSettingsInputValue('set_sig3_mt', FLOW_PDF_LAYOUT.sig3_mt),
      sig3_offset: getPrintSettingsInputValue('set_sig3_offset', FLOW_PDF_LAYOUT.sig3_offset),
      sig3_offset_y: getPrintSettingsInputValue('set_sig3_offset_y', FLOW_PDF_LAYOUT.sig3_offset_y),
      ...collectSignaturePartOffsetPayload('sig3'),
      sig3_title_offset_x: getPrintSettingsInputValue('set_sig3_title_offset_x', '0'),
      sig3_title_offset_y: getPrintSettingsInputValue('set_sig3_title_offset_y', '0'),
      sig3_abs_x: '',
      sig3_abs_y: '',
      sig3_abs_w: '',
      sig3_abs_h: '',
      sig3_title_abs_x: '',
      sig3_title_abs_y: '',
      sig3_title_abs_w: '',
      sig3_title_abs_h: '',
      sig3_block_abs_x: '',
      sig3_block_abs_y: '',
      sig3_block_abs_w: '',
      sig3_block_abs_h: ''
    });
  }

  return payload;
}

function schedulePrintSettingsPdfPreview(delay = 450) {
  if (!isPrintSettingsPageActive()) return;
  clearTimeout(printSettingsPdfPreviewTimer);
  printSettingsPdfPreviewTimer = setTimeout(refreshPrintSettingsPdfPreview, delay);
}

async function refreshPrintSettingsPdfPreview() {
  const frame = document.getElementById('print-layout-pdf-frame');
  const shell = document.getElementById('print-layout-pdf-preview');
  if (!frame || !shell || !isPrintSettingsPageActive()) return;
  syncPdfPreviewScaleAfterFrameLoad(frame);
  updatePdfPreviewScale();

  if (printSettingsPdfPreviewController) {
    printSettingsPdfPreviewController.abort();
  }
  const controller = new AbortController();
  printSettingsPdfPreviewController = controller;
  shell.classList.add('is-loading');

  try {
    const res = await fetch('print.php?settings_preview=1&html_preview=1', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(collectPrintSettingsPayload()),
      signal: controller.signal
    });

    if (!res.ok) {
      throw new Error(`PDF preview failed: ${res.status}`);
    }

    frame.srcdoc = await res.text();
  } catch (error) {
    if (error.name !== 'AbortError') {
      console.error('PDF preview error', error);
    }
  } finally {
    if (printSettingsPdfPreviewController === controller) {
      shell.classList.remove('is-loading');
      printSettingsPdfPreviewController = null;
    }
    updatePdfPreviewScale();
  }
}

async function loadPrintSettings() {
  setPrintSettingsSaveState('loading', 'กำลังโหลดค่า');
  syncPrintSettingsUI();
  initializePrintSettingsWorkbench();
  loadPrintPreviewAbsoluteState({});
  try {
    const res = await apiCall('settings.php', 'GET');
    if (res && res.status === 'success') {
      const s = res.data;
      loadPrintPreviewAbsoluteState(s);
      if (s.pdf_font_size_pt !== undefined || s.font_size_offset !== undefined) {
        const fontSizePt = s.pdf_font_size_pt !== undefined
          ? normalizePdfFontSizePt(s.pdf_font_size_pt)
          : inferPdfFontSizePtFromOffset(s.font_size_offset);
        applyPdfFontSizeSetting(fontSizePt);
        const workspacePage = document.getElementById('print-layout-page');
        if (workspacePage) {
          workspacePage.style.setProperty('--font-size-offset', `${getPdfFontSizeOffsetPx(fontSizePt)}px`);
        }
      }
      if (s.pdf_dot_size !== undefined) {
        const el = document.getElementById('set_pdf_dot_size');
        if (el) {
          el.value = s.pdf_dot_size;
          updatePdfDotSizeLabel(s.pdf_dot_size);
          updateOnScreenDottedLines(s.pdf_dot_size);
        }
      } else {
        updatePdfDotSizeLabel(2);
        updateOnScreenDottedLines(2);
      }
      const lineSpacingEl = document.getElementById('set_pdf_line_spacing');
      if (lineSpacingEl) {
        lineSpacingEl.value = String(clampNumber(s.pdf_line_spacing, 20, 40, 26));
      }
      const marginFields = {
        set_pdf_margin_top_px: [s.pdf_margin_top_px, 20, 140, 57],
        set_pdf_margin_right_px: [s.pdf_margin_right_px, 20, 160, 76],
        set_pdf_margin_bottom_px: [s.pdf_margin_bottom_px, 20, 140, 38],
        set_pdf_margin_left_px: [s.pdf_margin_left_px, 20, 160, 94]
      };
      Object.entries(marginFields).forEach(([id, [value, min, max, fallback]]) => {
        const el = document.getElementById(id);
        if (el) el.value = String(clampNumber(value, min, max, fallback));
      });
      const signatureFontEl = document.getElementById('set_signature_font_size_pt');
      if (signatureFontEl) {
        signatureFontEl.value = String(normalizePdfFontSizePt(s.signature_font_size_pt || s.pdf_font_size_pt || 16));
      }
      const attendanceFontEl = document.getElementById('set_attendance_font_size_pt');
      if (attendanceFontEl) {
        attendanceFontEl.value = String(normalizePdfFontSizePt(s.attendance_font_size_pt || s.pdf_font_size_pt || 16));
      }
      if (s.sig1_name !== undefined) document.getElementById('set_sig1_name').value = s.sig1_name;
      if (s.sig1_pos !== undefined) document.getElementById('set_sig1_pos').value = s.sig1_pos;
      if (s.sig1_align !== undefined) document.getElementById('set_sig1_align').value = s.sig1_align;
      if (s.sig1_dot_width !== undefined) document.getElementById('set_sig1_dot_width').value = s.sig1_dot_width;
      if (s.sig1_mt !== undefined) document.getElementById('set_sig1_mt').value = s.sig1_mt;
      if (s.sig1_offset !== undefined) document.getElementById('set_sig1_offset').value = s.sig1_offset;
      if (s.sig1_offset_y !== undefined) document.getElementById('set_sig1_offset_y').value = s.sig1_offset_y;
      if (s.sig1_sign_prefix !== undefined) document.getElementById('set_sig1_sign_prefix').value = normalizePrintSignatureLabel(s.sig1_sign_prefix);
      if (s.sig1_sign_suffix !== undefined) document.getElementById('set_sig1_sign_suffix').value = s.sig1_sign_suffix || 'ผู้บันทึก';
      applySignaturePartOffsetSettings(s, 'sig1');

      if (s.sig2_title !== undefined) document.getElementById('set_sig2_title').value = s.sig2_title;
      if (s.sig2_sign_label !== undefined) document.getElementById('set_sig2_sign_label').value = s.sig2_sign_label;
      if (s.sig2_name !== undefined) document.getElementById('set_sig2_name').value = s.sig2_name;
      if (s.sig2_pos !== undefined) document.getElementById('set_sig2_pos').value = s.sig2_pos;
      if (s.sig2_align !== undefined) document.getElementById('set_sig2_align').value = s.sig2_align;
      if (s.sig2_dot_width !== undefined) document.getElementById('set_sig2_dot_width').value = s.sig2_dot_width;
      if (s.sig2_block_offset_x !== undefined) document.getElementById('set_sig2_block_offset_x').value = s.sig2_block_offset_x;
      if (s.sig2_block_offset_y !== undefined) document.getElementById('set_sig2_block_offset_y').value = s.sig2_block_offset_y;
      if (s.sig2_mt !== undefined) document.getElementById('set_sig2_mt').value = s.sig2_mt;
      if (s.sig2_offset !== undefined) document.getElementById('set_sig2_offset').value = s.sig2_offset;
      if (s.sig2_offset_y !== undefined) document.getElementById('set_sig2_offset_y').value = s.sig2_offset_y;
      applySignaturePartOffsetSettings(s, 'sig2');
      if (s.sig2_title_offset_x !== undefined) document.getElementById('set_sig2_title_offset_x').value = s.sig2_title_offset_x;
      if (s.sig2_title_offset_y !== undefined) document.getElementById('set_sig2_title_offset_y').value = s.sig2_title_offset_y;
      
      if (s.sig3_title !== undefined) document.getElementById('set_sig3_title').value = s.sig3_title;
      if (s.sig3_sign_label !== undefined) document.getElementById('set_sig3_sign_label').value = s.sig3_sign_label;
      if (s.sig3_name !== undefined) document.getElementById('set_sig3_name').value = s.sig3_name;
      if (s.sig3_pos !== undefined) document.getElementById('set_sig3_pos').value = s.sig3_pos;
      if (s.sig3_align !== undefined) document.getElementById('set_sig3_align').value = s.sig3_align;
      if (s.sig3_dot_width !== undefined) document.getElementById('set_sig3_dot_width').value = s.sig3_dot_width;
      if (s.sig3_block_offset_x !== undefined) document.getElementById('set_sig3_block_offset_x').value = s.sig3_block_offset_x;
      if (s.sig3_block_offset_y !== undefined) document.getElementById('set_sig3_block_offset_y').value = s.sig3_block_offset_y;
      if (s.sig3_mt !== undefined) document.getElementById('set_sig3_mt').value = s.sig3_mt;
      if (s.sig3_offset !== undefined) document.getElementById('set_sig3_offset').value = s.sig3_offset;
      if (s.sig3_offset_y !== undefined) document.getElementById('set_sig3_offset_y').value = s.sig3_offset_y;
      applySignaturePartOffsetSettings(s, 'sig3');
      if (s.sig3_title_offset_x !== undefined) document.getElementById('set_sig3_title_offset_x').value = s.sig3_title_offset_x;
      if (s.sig3_title_offset_y !== undefined) document.getElementById('set_sig3_title_offset_y').value = s.sig3_title_offset_y;
      setPrintSettingsSaveState('saved', 'โหลดค่าล่าสุดแล้ว');
    }
  } catch(e) {
    console.error('Settings load error', e);
    setPrintSettingsSaveState('error', 'โหลดค่าไม่สำเร็จ');
  }
  renderPrintSettingsPreview();
  schedulePrintSettingsPdfPreview(0);
}

async function savePrintSettings() {
  const payload = collectPrintSettingsPayload();
  setPrintSettingsSaveState('saving', 'กำลังบันทึก');
  
  try {
    const res = await apiCall('settings.php', 'POST', payload);
    if (res.status === 'success') {
      showToast('บันทึกการตั้งค่า PDF สำเร็จ', 'success');
      setPrintSettingsSaveState('saved', 'บันทึกแล้ว');
      await loadPrintSettings();
      schedulePrintSettingsPdfPreview(0);
    } else {
      setPrintSettingsSaveState('error', 'บันทึกไม่สำเร็จ');
      showToast(res.message || 'Error', 'error');
    }
  } catch(e) {
    setPrintSettingsSaveState('error', 'บันทึกไม่สำเร็จ');
    showToast('บันทึกไม่สำเร็จ', 'error');
  }
}

// ============================================================
//  ADMIN MANAGEMENT
// ============================================================
async function renderAdminTable() {
  const res = await apiCall('users.php');
  if (!res || res.status !== 'success') return;
  const users = res.data;
  const tbody = document.getElementById('admin-table-tbody');
  tbody.innerHTML = users.map(u => {
    const t = getTambonById(u.tambon);
    const encodedUser = encodeURIComponent(JSON.stringify({
      id: u.id,
      name: u.name,
      username: u.username,
      role: u.role,
      tambon: u.tambon || ''
    }));
    return `<tr class="admin-table-row">
      <td style="font-weight:500;">${escapeHtml(u.name)}</td>
      <td><code style="background:#f5f5f5;padding:2px 7px;border-radius:5px;font-size:13px;">${escapeHtml(u.username)}</code></td>
      <td>${t ? `${escapeHtml(t.icon)} ตำบล${escapeHtml(t.name)}` : '<span style="color:#aaa">อำเภอ</span>'}</td>
      <td>${u.role === 'district' ? '<span class="role-badge district">👑 อำเภอ</span>' : '<span class="role-badge tambon">📍 ตำบล</span>'}</td>
      <td style="font-size:13px;color:#888;">${u.last_record ? fmtDate(u.last_record) : 'ยังไม่มี'}</td>
      <td>
          <button class="btn btn-sm btn-outline" onclick="editAdmin('${encodedUser}')">✏️</button>
        ${u.role !== 'district' ? `
          <button class="btn btn-sm btn-danger" onclick="deleteAdmin('${u.id}')" style="margin-left:4px;">🗑️</button>
        ` : ''}
      </td>
    </tr>`;
  }).join('');
}

function populateAdminTambonOptions(selectedTambon = '') {
  const sel = document.getElementById('admin-tambon');
  sel.innerHTML = `<option value="">-- เลือกตำบล --</option>` +
    TAMBONS.map(t => `<option value="${escapeHtml(t.id)}" ${t.id === selectedTambon ? 'selected' : ''}>${escapeHtml(t.icon)} ตำบล${escapeHtml(t.name)}</option>`).join('');
}

function updateAdminRoleFields() {
  const role = document.getElementById('admin-role').value;
  const tambonSelect = document.getElementById('admin-tambon');
  const tambonLabel = document.getElementById('admin-tambon-label');
  const tambonHelp = document.getElementById('admin-tambon-help');
  const isTambonAdmin = role === 'tambon';

  tambonSelect.disabled = !isTambonAdmin;
  tambonSelect.required = isTambonAdmin;
  tambonLabel.textContent = isTambonAdmin ? 'ตำบล' : 'ตำบล (ไม่ต้องเลือก)';
  tambonHelp.textContent = isTambonAdmin
    ? 'เลือกตำบลที่ผู้ใช้นี้จะดูแล'
    : 'ผู้ดูแลระดับอำเภอไม่ต้องผูกกับตำบล';

  if (!isTambonAdmin) {
    tambonSelect.value = '';
  }
}

function openAddAdmin() {
  document.getElementById('modal-admin-title').textContent = '👤 เพิ่มผู้ดูแลระบบ';
  document.getElementById('admin-id').value = '';
  document.getElementById('admin-name').value = '';
  document.getElementById('admin-username').value = '';
  document.getElementById('admin-password').value = '';
  document.getElementById('admin-role').value = 'tambon';
  populateAdminTambonOptions('');
  updateAdminRoleFields();
  openModal('modal-admin');
}
if(document.getElementById('openAddAdminBtn')) document.getElementById('openAddAdminBtn').addEventListener('click', openAddAdmin);
if(document.getElementById('admin-role')) document.getElementById('admin-role').addEventListener('change', updateAdminRoleFields);

window.editAdmin = function(encodedUser) {
  const admin = JSON.parse(decodeURIComponent(encodedUser));
  document.getElementById('modal-admin-title').textContent = '✏️ แก้ไขผู้ดูแลระบบ';
  document.getElementById('admin-id').value = admin.id;
  document.getElementById('admin-name').value = admin.name;
  document.getElementById('admin-username').value = admin.username;
  document.getElementById('admin-password').value = ''; // Will update if given
  document.getElementById('admin-role').value = admin.role || 'tambon';
  populateAdminTambonOptions(admin.tambon || '');
  updateAdminRoleFields();
  openModal('modal-admin');
}

async function saveAdmin() {
  const id = document.getElementById('admin-id').value;
  const name = document.getElementById('admin-name').value.trim();
  const username = document.getElementById('admin-username').value.trim();
  const password = document.getElementById('admin-password').value.trim();
  const role = document.getElementById('admin-role').value;
  const tambon = document.getElementById('admin-tambon').value;

  if (!name || !username || (!password && !id) || !role) {
    showToast('กรุณากรอกข้อมูลให้ครบถ้วน', 'error'); return;
  }

  if (role === 'tambon' && !tambon) {
    showToast('กรุณากรอกข้อมูลให้ครบถ้วน', 'error'); return;
  }
  
  const payload = {
    id,
    name,
    username,
    password,
    role,
    tambon: role === 'district' ? '' : tambon
  };
  const method = id ? 'PUT' : 'POST';
  
  const res = await apiCall('users.php', method, payload);
  if (res && res.status === 'success') {
      showToast(res.message);
      closeModal('modal-admin');
      renderAdminTable();
  } else if (res) {
      showToast(res.message, 'error');
  }
}
if(document.getElementById('saveAdminBtn')) document.getElementById('saveAdminBtn').addEventListener('click', saveAdmin);

window.deleteAdmin = async function(id) {
  if (!confirm('ต้องการลบผู้ดูแลนี้ใช่หรือไม่?')) return;
  const res = await apiCall(`users.php?id=${id}`, 'DELETE');
  if (res && res.status === 'success') {
      showToast(res.message);
      renderAdminTable();
  } else if (res) {
      showToast(res.message, 'error');
  }
}

// ============================================================
//  SETTINGS
// ============================================================
async function changePassword() {
  const cur = document.getElementById('cur-pass').value;
  const nw = document.getElementById('new-pass').value;
  const cf = document.getElementById('confirm-pass').value;
  
  if (nw.length < 6) { showToast('รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัว', 'error'); return; }
  if (nw !== cf) { showToast('รหัสผ่านยืนยันไม่ตรงกัน', 'error'); return; }
  
  const res = await apiCall('auth.php?action=change_password', 'POST', { cur_pass: cur, new_pass: nw });
  if (res && res.status === 'success') {
      if (res.data?.print_token) {
        window.AUTH_PRINT_TOKEN = res.data.print_token;
      }
      showToast(res.message);
      ['cur-pass','new-pass','confirm-pass'].forEach(id => document.getElementById(id).value = '');
  } else if (res) {
      showToast(res.message, 'error');
  }
}
if(document.getElementById('changePasswordBtn')) document.getElementById('changePasswordBtn').addEventListener('click', changePassword);

function syncSettingsMediaPreview() {
  const profilePreview = document.getElementById('profile-preview');
  const profileName = document.getElementById('profile-name-preview');
  if (profileName && currentUser) profileName.textContent = currentUser.name || 'ผู้ใช้งาน';
  if (profilePreview && currentUser) {
    profilePreview.textContent = '';
    if (currentUser.avatar_path) {
      profilePreview.innerHTML = `<img src="${escapeHtml(currentUser.avatar_path)}" alt="รูปโปรไฟล์">`;
    } else {
      profilePreview.textContent = (currentUser.name || 'A').charAt(0);
    }
  }
  const logoPreview = document.getElementById('logo-preview');
  if (logoPreview) {
    if (appLogoPath) logoPreview.innerHTML = `<img src="${escapeHtml(appLogoPath)}" alt="โลโก้หน่วยงาน">`;
    else logoPreview.textContent = '📚';
  }
  const brandingCard = document.getElementById('branding-settings-card');
  if (brandingCard) brandingCard.hidden = currentUser?.role !== 'district';
  const healthCard = document.getElementById('code-health-card');
  if (healthCard) healthCard.hidden = currentUser?.role !== 'district';
}

async function uploadProfileImage() {
  const input = document.getElementById('profile-image-input');
  const file = input?.files?.[0];
  if (!file) { showToast('กรุณาเลือกรูปโปรไฟล์ก่อน', 'error'); return; }
  if (file.size > 3 * 1024 * 1024) { showToast('รูปโปรไฟล์ต้องไม่เกิน 3MB', 'error'); return; }
  const form = new FormData();
  form.append('image', file);
  const button = document.getElementById('uploadProfileBtn');
  if (button) { button.disabled = true; button.textContent = 'กำลังอัปโหลด...'; }
  try {
    const res = await apiCall('profile.php', 'POST', form);
    if (res?.status === 'success' && res.data?.user) {
      currentUser = res.data.user;
      window.AUTH_USER = currentUser;
      renderTopbar();
      showToast(res.message || 'อัปเดตรูปโปรไฟล์แล้ว');
      if (input) input.value = '';
    } else if (res) showToast(res.message || 'อัปโหลดไม่สำเร็จ', 'error');
  } finally {
    if (button) { button.disabled = false; button.textContent = 'อัปโหลดรูปโปรไฟล์'; }
  }
}

async function uploadLogoImage() {
  if (currentUser?.role !== 'district') return;
  const input = document.getElementById('logo-image-input');
  const file = input?.files?.[0];
  if (!file) { showToast('กรุณาเลือกไฟล์โลโก้ก่อน', 'error'); return; }
  if (file.size > 4 * 1024 * 1024) { showToast('โลโก้ต้องไม่เกิน 4MB', 'error'); return; }
  const form = new FormData();
  form.append('image', file);
  const button = document.getElementById('uploadLogoBtn');
  if (button) { button.disabled = true; button.textContent = 'กำลังอัปโหลด...'; }
  try {
    const res = await apiCall('logo.php', 'POST', form);
    if (res?.status === 'success' && res.data?.path) {
      appLogoPath = res.data.path;
      window.APP_LOGO_PATH = appLogoPath;
      applyAppLogo(appLogoPath);
      showToast(res.message || 'อัปเดตโลโก้แล้ว');
      if (input) input.value = '';
    } else if (res) showToast(res.message || 'อัปโหลดไม่สำเร็จ', 'error');
  } finally {
    if (button) { button.disabled = false; button.textContent = 'อัปโหลดโลโก้'; }
  }
}

async function runCodeHealthCheck() {
  const button = document.getElementById('runCodeHealthBtn');
  const summary = document.getElementById('code-health-summary');
  const output = document.getElementById('code-health-output');
  if (!button || !summary || !output) return;
  button.disabled = true;
  button.textContent = 'กำลังตรวจ...';
  summary.textContent = 'กำลังตรวจ syntax และ security pattern...';
  output.hidden = true;
  try {
    const res = await apiCall('code_health.php', 'GET', null, true);
    const report = res?.data;
    if (res?.status !== 'success' || !report?.summary) {
      summary.dataset.status = 'fail';
      summary.textContent = res?.message || 'เรียกตัวตรวจไม่สำเร็จ';
      return;
    }
    const s = report.summary;
    summary.dataset.status = s.status;
    summary.textContent = `สถานะ ${s.status.toUpperCase()} | ตรวจ ${s.checks} รายการ | fail ${s.failures} | warning ${s.warnings}`;
    output.textContent = (report.checks || []).map(item => `[${item.status.toUpperCase()}] ${item.label}\n${item.detail || ''}`).join('\n\n');
    output.hidden = false;
  } finally {
    button.disabled = false;
    button.textContent = 'เริ่มตรวจ';
  }
}

if (document.getElementById('uploadProfileBtn')) document.getElementById('uploadProfileBtn').addEventListener('click', uploadProfileImage);
if (document.getElementById('uploadLogoBtn')) document.getElementById('uploadLogoBtn').addEventListener('click', uploadLogoImage);
if (document.getElementById('runCodeHealthBtn')) document.getElementById('runCodeHealthBtn').addEventListener('click', runCodeHealthCheck);
syncSettingsMediaPreview();
applyAppLogo(appLogoPath);

// ============================================================
//  EXPORT
// ============================================================
async function exportReport() {
  let records = getSelectedRecordsForScope('all');
  if (!records.length) {
    records = await fetchAllRecords();
  }
  let csv = 'กลุ่มเรียน,ตำบล,สัปดาห์,วันที่,รายวิชา,รหัสวิชา,ระดับ,ผู้เรียนทั้งหมด,เข้าเรียน,ไม่มาเรียน\n';
  records.forEach(r => {
    const t = getTambonById(r.tambon);
    csv += `"${getRecordGroupLabel(r)}","${t?.name||r.tambon}","${r.week}","${fmtDate(r.date)}","${r.subject||''}","${r.subject_code||''}","${r.level||''}","${r.total||0}","${r.present||0}","${(r.total||0)-(r.present||0)}"\n`;
  });
  const blob = new Blob(['\ufeff'+csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a'); a.href = url;
  a.download = `รายงานสกร_เสนา_${new Date().toISOString().split('T')[0]}.csv`;
  a.click();
  showToast('📄 ส่งออกรายงาน CSV เรียบร้อยแล้ว');
}
if(document.getElementById('exportReportBtn')) document.getElementById('exportReportBtn').addEventListener('click', exportReport);

// ============================================================
//  MODAL
// ============================================================
function openModal(id) {
  const modal = document.getElementById(id);
  if (!modal) return;
  modal.classList.add('open');
  document.body.classList.add('modal-open');
}
function closeModal(id) {
  const modal = document.getElementById(id);
  if (!modal) return;
  modal.classList.remove('open');
  if (!document.querySelector('.modal-overlay.open')) {
    document.body.classList.remove('modal-open');
  }
  if (id === 'modal-image-viewer') {
    const imgEl = document.getElementById('image-viewer-img');
    if (imgEl) {
      imgEl.src = '';
      imgEl.alt = 'ภาพขยาย';
    }
  }
}
window.openModal = openModal;
window.closeModal = closeModal;

document.querySelectorAll('.modal-overlay').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); });
});
document.querySelectorAll('.modal-close, [data-close]').forEach(btn => {
    btn.addEventListener('click', (e) => {
        closeModal(e.target.closest('.modal-overlay').id || e.target.getAttribute('data-close'));
    });
});

document.addEventListener('keydown', e => {
  if (e.key !== 'Escape') return;
  const activeModal = [...document.querySelectorAll('.modal-overlay.open')].pop();
  if (activeModal) {
    closeModal(activeModal.id);
  }
});

// ============================================================
//  TOAST
// ============================================================
function showToast(msg, type = 'success') {
  const c = document.getElementById('toast-container');
  const t = document.createElement('div');
  t.className = `toast${type === 'error' ? ' error' : ''}`;
  const span = document.createElement('span');
  span.textContent = msg;
  t.appendChild(span);
  c.appendChild(t);
  setTimeout(() => { t.style.opacity = '0'; t.style.transform = 'translateX(20px)'; t.style.transition = '0.3s'; setTimeout(() => t.remove(), 300); }, 3000);
}
window.showToast = showToast;

// ============================================================
//  HELPERS
// ============================================================
function fmtDate(d) {
  if (!d) return '-';
  const dt = new Date(d);
  return dt.toLocaleDateString('th-TH', { year: 'numeric', month: 'short', day: 'numeric' });
}
function fmtDatetime(d) {
  if (!d) return '-';
  const dt = new Date(d);
  return dt.toLocaleString('th-TH', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

// ============================================================
//  TAMBON MANAGEMENT
// ============================================================
async function renderTambonMgmtTable() {
  await loadTambons(); // Ensure fresh data
  const tbody = document.getElementById('tambon-list-tbody');
  if(!tbody) return;
  
  tbody.innerHTML = TAMBONS.map(t => {
    const encodedTambon = encodeURIComponent(JSON.stringify({
      id: t.id,
      name: t.name,
      icon: t.icon
    }));
    return `<tr>
      <td style="text-align:center; font-size:28px;">${escapeHtml(t.icon || '🏘️')}</td>
      <td style="font-weight:500;">${escapeHtml(t.name)}</td>
      <td>
        <button class="btn btn-sm btn-outline" onclick="editTambon('${encodedTambon}')">✏️</button>
        <button class="btn btn-sm btn-danger" onclick="deleteTambon('${escapeJsString(t.id)}')" style="margin-left:4px;">🗑️</button>
      </td>
    </tr>`;
  }).join('');
  populateTambonFilters();
}

function openAddTambon() {
  document.getElementById('modal-tambon-title').textContent = '🏘️ เพิ่มกลุ่มเรียน';
  document.getElementById('manage-tambon-id').value = '';
  document.getElementById('manage-tambon-name').value = '';
  document.getElementById('manage-tambon-icon').value = '🏘️';
  openModal('modal-manage-tambon');
}
if(document.getElementById('openAddTambonBtn')) document.getElementById('openAddTambonBtn').addEventListener('click', openAddTambon);

window.editTambon = function(payload, name = '', icon = '') {
  let tambon = { id: payload, name, icon };
  try {
    tambon = JSON.parse(decodeURIComponent(payload));
  } catch (e) {
    // Backward-compatible path for existing inline calls.
  }
  document.getElementById('modal-tambon-title').textContent = '✏️ แก้ไขกลุ่มเรียน';
  document.getElementById('manage-tambon-id').value = tambon.id || '';
  document.getElementById('manage-tambon-name').value = tambon.name || '';
  document.getElementById('manage-tambon-icon').value = tambon.icon || '';
  openModal('modal-manage-tambon');
}

async function saveTambon() {
  const id = document.getElementById('manage-tambon-id').value;
  const name = document.getElementById('manage-tambon-name').value.trim();
  const icon = document.getElementById('manage-tambon-icon').value.trim();

  if (!name) {
    showToast('กรุณากรอกชื่อกลุ่มเรียน', 'error'); return;
  }
  
  const payload = { id, name, icon };
  const method = id ? 'PUT' : 'POST';
  
  const res = await apiCall('tambons.php', method, payload);
  if (res && res.status === 'success') {
      invalidateRecordsCache();
      showToast(res.message);
      closeModal('modal-manage-tambon');
      renderTambonMgmtTable();
  } else if (res) {
      showToast(res.message, 'error');
  }
}
if(document.getElementById('saveTambonBtn')) document.getElementById('saveTambonBtn').addEventListener('click', saveTambon);

window.deleteTambon = async function(id) {
  if (!confirm('ต้องการลบกลุ่มเรียนนี้ใช่หรือไม่? ข้อมูลจะไม่สามารถกู้คืนได้ (หากมีการใช้งานจะไม่สามารถลบได้)')) return;
  const res = await apiCall(`tambons.php?id=${id}`, 'DELETE');
  if (res && res.status === 'success') {
      invalidateRecordsCache();
      showToast(res.message);
      renderTambonMgmtTable();
  } else if (res) {
      showToast(res.message, 'error');
  }
}

// ============================================================
//  PDF PREVIEW ZOOM & FONT SIZE OFFSET HELPERS
// ============================================================
let pdfZoomLevel = 1.0;
let pdfPreviewResizeObserver = null;

function changePdfZoom(delta) {
  setPdfZoom(pdfZoomLevel + delta);
}

function resetPdfZoom() {
  setPdfZoom(1.0);
}

function setPdfZoom(zoom) {
  // Clamp zoom between 0.5 (50%) and 2.0 (200%)
  pdfZoomLevel = Math.max(0.5, Math.min(2.0, parseFloat(zoom) || 1.0));
  updatePdfPreviewScale();
}

function getPdfPreviewFitScale() {
  const previewContainer = document.getElementById('print-layout-pdf-preview');
  const previewBody = previewContainer?.closest('.print-pdf-hero-body');
  if (!previewContainer || !previewBody) return 1;

  const styles = window.getComputedStyle(previewBody);
  const paddingX = (parseFloat(styles.paddingLeft) || 0) + (parseFloat(styles.paddingRight) || 0);
  const availableWidth = Math.max(0, previewBody.clientWidth - paddingX);

  if (!availableWidth) return 1;
  return Math.min(1, Math.max(0.32, availableWidth / 794));
}

function updatePdfPreviewScale() {
  const previewContainer = document.getElementById('print-layout-pdf-preview');
  if (!previewContainer) return;

  const fitScale = getPdfPreviewFitScale();
  const effectiveScale = Math.max(0.32, Math.min(2.0, fitScale * pdfZoomLevel));
  const scaledWidth = Math.round(794 * effectiveScale);
  const scaledHeight = Math.round(1123 * effectiveScale);
  previewContainer.style.setProperty('--pdf-zoom', effectiveScale.toFixed(4));
  previewContainer.style.width = `${scaledWidth}px`;
  previewContainer.style.maxWidth = `${scaledWidth}px`;
  previewContainer.style.flexBasis = `${scaledWidth}px`;
  previewContainer.style.height = `${scaledHeight}px`;
  previewContainer.style.maxHeight = `${scaledHeight}px`;
  previewContainer.style.minHeight = `${scaledHeight}px`;

  const valEl = document.getElementById('pdf-zoom-val');
  if (valEl) {
    valEl.textContent = `${Math.round(effectiveScale * 100)}%`;
    valEl.title = fitScale < 1
      ? `พอดีหน้าจอ ${Math.round(fitScale * 100)}% • คลิกเพื่อรีเซ็ต`
      : 'คลิกเพื่อรีเซ็ต';
  }
}

function initializePdfPreviewResponsive() {
  updatePdfPreviewScale();
  const previewBody = document.querySelector('.print-pdf-hero-body');
  if (!previewBody || pdfPreviewResizeObserver || !window.ResizeObserver) return;

  pdfPreviewResizeObserver = new ResizeObserver(() => updatePdfPreviewScale());
  pdfPreviewResizeObserver.observe(previewBody);
}

window.addEventListener('resize', debounce(updatePdfPreviewScale, 100));
window.addEventListener('orientationchange', () => {
  window.setTimeout(updatePdfPreviewScale, 160);
});

function syncPdfPreviewScaleAfterFrameLoad(frame) {
  if (!frame) return;
  frame.addEventListener('load', () => {
    window.requestAnimationFrame(updatePdfPreviewScale);
  }, { once: true });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initializePdfPreviewResponsive, { once: true });
} else {
  initializePdfPreviewResponsive();
}

function normalizePdfFontSizePt(val) {
  const num = parseInt(val, 10);
  return Number.isFinite(num) ? Math.max(10, Math.min(20, num)) : 16;
}

function inferPdfFontSizePtFromOffset(val) {
  const offset = parseFloat(val);
  if (!Number.isFinite(offset)) return 16;
  const estimatedPt = 16 + (offset * 72 / 96);
  return Array.from({ length: 11 }, (_, index) => index + 10).reduce((closest, option) => (
    Math.abs(option - estimatedPt) < Math.abs(closest - estimatedPt) ? option : closest
  ), 16);
}

function getPdfFontSizePt() {
  return normalizePdfFontSizePt(getPrintSettingsInputValue('set_pdf_font_size_pt', '16'));
}

function getPdfFontSizeOffsetPx(val) {
  const fontSizePt = normalizePdfFontSizePt(val);
  return Number(((fontSizePt - 16) * (96 / 72)).toFixed(2));
}

function applyPdfFontSizeSetting(val) {
  const fontSizePt = normalizePdfFontSizePt(val);
  const select = document.getElementById('set_pdf_font_size_pt');
  const offsetInput = document.getElementById('set_font_size_offset');

  if (select) select.value = String(fontSizePt);
  if (offsetInput) offsetInput.value = String(getPdfFontSizeOffsetPx(fontSizePt));
  updateFontSizeLabel(fontSizePt);
}

function updateFontSizeLabel(val) {
  const lbl = document.getElementById('font-size-offset-label');
  if (!lbl) return;
  lbl.textContent = `${normalizePdfFontSizePt(val)} pt`;
}

function updatePdfDotSizeLabel(val) {
  const lbl = document.getElementById('pdf-dot-size-label');
  if (!lbl) return;
  const num = parseInt(val, 10) || 2;
  if (num === 2) {
    lbl.textContent = 'บางสวย (มาตรฐาน)';
  } else {
    lbl.textContent = `บางสวย ระดับ ${num}`;
  }
}

function updateOnScreenDottedLines(val) {
  const dotSize = parseInt(val, 10) || 2;
  const radius = Math.max(0.5, Math.min(0.7, 0.55 + ((dotSize - 2) * 0.05)));
  const cx = 0.75;
  const cy = 1.25;
  const svgWidth = 4;
  const svgHeight = 2;
  const dotFill = '%23000000';

  const svgUrlHoriz = `data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='${svgWidth}' height='${svgHeight}' viewBox='0 0 ${svgWidth} ${svgHeight}'%3E%3Ccircle cx='${cx}' cy='${cy}' r='${radius.toFixed(2)}' fill='${dotFill}'/%3E%3C/svg%3E`;

  const svgUrlVert = `data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='${svgWidth}' height='23' viewBox='0 0 ${svgWidth} 23'%3E%3Ccircle cx='${cx}' cy='22.15' r='${radius.toFixed(2)}' fill='${dotFill}'/%3E%3C/svg%3E`;

  let styleEl = document.getElementById('dynamic-on-screen-dotted-styles');
  if (!styleEl) {
    styleEl = document.createElement('style');
    styleEl.id = 'dynamic-on-screen-dotted-styles';
    document.head.appendChild(styleEl);
  }

  styleEl.innerHTML = `
    .print-layout-line,
    .print-layout-dotted-text-row,
    .print-layout-guide-comment--small,
    .print-layout-inline-dot,
    .print-layout-section-line,
    .print-layout-single-line-dot {
	      background-image: url("${svgUrlHoriz}") !important;
	      background-size: ${svgWidth}px ${svgHeight}px !important;
	      image-rendering: auto !important;
	    }
	    .print-layout-guide-block,
	    .print-layout-guide-comment {
	      background-image: url("${svgUrlVert}") !important;
	      background-size: ${svgWidth}px 23px !important;
	      image-rendering: auto !important;
	    }
  `;
}

// ============================================================
//  BOOT
// ============================================================
if (currentUser) {
    startApp();
}
