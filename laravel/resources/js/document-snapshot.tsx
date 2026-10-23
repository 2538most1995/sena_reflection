import { createRoot } from 'react-dom/client'
import { DocumentCanvas } from './components/editor/document-canvas'
import { openDocumentPrint } from './lib/document-print'
import type { WebDocument } from './lib/document-model'
import '../css/document-editor.css'

// Headless Chrome's virtual clock advances timers but may omit compositor animation frames.
// This read-only, local-file entry uses the same layout callbacks on a deterministic 16 ms clock.
if (document.documentElement.dataset.documentSnapshotMode === 'local') {
  window.requestAnimationFrame = callback => window.setTimeout(() => callback(performance.now()), 16)
  window.cancelAnimationFrame = handle => window.clearTimeout(handle)
}

const container = document.getElementById('document-snapshot-root')
const payload = document.getElementById('document-snapshot-data')
let timer: ReturnType<typeof setTimeout> | undefined
let freezing = false
let hasFailed = false
let incompleteChecks = 0

function paginationIsComplete(canvas: HTMLElement) {
  const body = canvas.querySelector<HTMLElement>('.document-body-editor')
  const pages = [...canvas.querySelectorAll<HTMLElement>('.document-page')]
  const lastPage = pages.at(-1)
  if (!body || !lastPage) return false
  const bottom = lastPage.getBoundingClientRect().bottom
  return [...body.children].filter(element => !element.matches('[data-rm-pagination],.rm-pages-wrapper,.rm-first-page-header')).every(element => element.getBoundingClientRect().bottom <= bottom + 2)
}

function failed(error: unknown) {
  if (hasFailed) return
  hasFailed = true
  document.body.dataset.documentPrintError = 'true'
  const status = document.createElement('p')
  status.setAttribute('role', 'alert')
  status.textContent = error instanceof Error ? error.message : 'เตรียมเอกสารสำหรับพิมพ์ไม่สำเร็จ กรุณาลองใหม่'
  document.body.append(status)
}

window.addEventListener('error', event => failed(event.error || new Error('โหลดส่วนประกอบเอกสารไม่สำเร็จ กรุณาลองใหม่')))
window.addEventListener('unhandledrejection', event => failed(event.reason))

try {
  if (!container || !payload?.textContent) throw new Error('ไม่พบข้อมูลเอกสารสำหรับพิมพ์')
  const model = JSON.parse(payload.textContent) as WebDocument
  const settle = () => {
    if (freezing) return
    if (timer) clearTimeout(timer)
    timer = setTimeout(async () => {
      if (freezing) return
      try {
        await document.fonts.ready
        const canvas = container.querySelector<HTMLElement>('[data-document-canvas]')
        if (!canvas || !canvas.querySelector('.ProseMirror')) throw new Error('จัดหน้าเอกสารไม่สำเร็จ')
        if (!paginationIsComplete(canvas)) {
          if (++incompleteChecks < 10) { settle(); return }
          throw new Error('จัดหน้าเอกสารยังไม่ครบ จึงยังสร้าง PDF ไม่ได้ กรุณาลองใหม่')
        }
        freezing = true
        await openDocumentPrint(model, canvas, window)
        if (!hasFailed) {
          document.body.dataset.documentPrintPageCount = String(document.querySelectorAll('.document-print-page').length)
          document.body.dataset.documentPrintReady = 'true'
        }
      } catch (error) { failed(error) }
    }, 200)
  }
  createRoot(container).render(<DocumentCanvas document={model} onChange={() => {}} onEditor={editor => { if (editor) settle() }} selectedObjectId={null} onSelectObject={() => {}} zoom={1} showRuler={false} showGrid={false} showMarks={false} onPages={settle} readOnly />)
} catch (error) { failed(error) }
