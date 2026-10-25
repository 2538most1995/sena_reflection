import { Head } from '@inertiajs/react'
import type { Editor } from '@tiptap/react'
import { ArrowLeft, Check, ChevronLeft, ChevronRight, Copy, Download, FileText, History, LoaderCircle, PanelLeftClose, PanelRight, Plus, Printer, Redo2, Save, Search, Undo2, X } from 'lucide-react'
import * as Dialog from '@radix-ui/react-dialog'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { AppShell } from '@/components/app-shell'
import { AppLink } from '@/components/app-link'
import { Button } from '@/components/ui/button'
import { EditorRibbon, type EditorView } from '@/components/editor/editor-ribbon'
import { ObjectProperties } from '@/components/editor/object-properties'
import { DocumentCanvas } from '@/components/editor/document-canvas'
import { useDocumentAutosave } from '@/hooks/use-document-autosave'
import { documentRequest } from '@/lib/document-api'
import { appRouter } from '@/lib/app-router'
import { appUrl } from '@/lib/app-url'
import { actionAlert } from '@/lib/action-alert'
import { createDocumentObject, pageDimensions, MM_TO_PX, type DocumentAsset, type DocumentEditorContext, type DocumentObject, type DocumentVersion, type WebDocument } from '@/lib/document-model'
import { openDocumentPrint } from '@/lib/document-print'
import { createDocumentThumbnails, type DocumentPageView } from '@/lib/document-thumbnails'
import { constrainObject } from '@/lib/document-layout'
import { findDocumentText } from '@/lib/document-search'
import '@/../css/document-editor.css'

function plainDocumentText(document: WebDocument): string {
  const walk = (node: { text?: string; content?: unknown[] }): string => node.text || node.content?.map((child) => walk(child as typeof node)).join(' ') || ''
  return [walk(document.content), ...document.objects.filter((object) => object.type === 'textbox').map((object) => walk(object.content || {}))].join(' ')
}
type Snapshot = Pick<WebDocument, 'title' | 'content' | 'pageSettings' | 'objects'>
const snapshot = (document: WebDocument): Snapshot => ({ title: document.title, content: document.content, pageSettings: document.pageSettings, objects: document.objects })

export default function DocumentEdit({ document: initial, editorContext }: { document: WebDocument; editorContext?: DocumentEditorContext }) {
  const autosave = useDocumentAutosave(initial)
  const doc = autosave.document
  const context = doc.editorContext || editorContext
  const [editor, setEditor] = useState<Editor | null>(null)
  const [, selectionTick] = useState(0)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [view, setView] = useState<EditorView>({ ruler: true, grid: false, marks: false, thumbnails: window.innerWidth >= 640, properties: window.innerWidth >= 1100 })
  const [zoom, setZoom] = useState(() => window.innerWidth < 640 ? 0.5 : 0.85)
  const [pages, setPages] = useState<DocumentPageView[]>([])
  const [thumbnails, setThumbnails] = useState<string[]>([])
  const [currentPage, setCurrentPage] = useState(0)
  const [busy, setBusy] = useState(false)
  const actionInProgress = useRef(false)
  const latestSaveState = useRef({ status: autosave.status, error: autosave.error })
  latestSaveState.current = { status: autosave.status, error: autosave.error }
  const notifiedSaveError = useRef('')
  const saveFeedbackPending = useRef(false)
  const [notice, setNotice] = useState('')
  const [modal, setModal] = useState<'find' | 'versions' | 'review' | null>(null)
  const [versions, setVersions] = useState<DocumentVersion[]>([])
  const [find, setFind] = useState('')
  const [replacement, setReplacement] = useState('')
  const [matches, setMatches] = useState<{ from: number; to: number }[]>([])
  const [matchIndex, setMatchIndex] = useState(0)
  const [historyIndex, setHistoryIndex] = useState(0)
  const history = useRef<Snapshot[]>([snapshot(initial)])
  const historyPosition = useRef(0)
  const historyTime = useRef(0)
  const canvasHost = useRef<HTMLDivElement>(null)
  const stage = useRef<HTMLDivElement>(null)
  const uploadInput = useRef<HTMLInputElement>(null)
  const uploadInline = useRef(false)
  const replaceImageId = useRef<string | null>(null)
  const thumbnailUrls = useRef<string[]>([])
  const selected = doc.objects.find((object) => object.id === selectedId) || null
  const text = useMemo(() => plainDocumentText(doc), [doc.content, doc.objects])
  const wordCount = useMemo(() => Array.from(new Intl.Segmenter('th', { granularity: 'word' }).segment(text)).filter((part) => part.isWordLike).length, [text])
  const commit = useCallback((changes: Partial<WebDocument>, options?: { historyBoundary?: boolean }) => {
    const current = autosave.latest.current
    const next = { ...current, ...changes }
    if (JSON.stringify(snapshot(next)) === JSON.stringify(snapshot(current))) return
    const now = Date.now()
    const stack = history.current.slice(0, historyPosition.current + 1)
    if (!options?.historyBoundary && now - historyTime.current < 350 && stack.length > 1) stack[stack.length - 1] = snapshot(next)
    else stack.push(snapshot(next))
    history.current = stack.slice(-100); historyPosition.current = history.current.length - 1; historyTime.current = options?.historyBoundary ? 0 : now
    setHistoryIndex(historyPosition.current)
    autosave.update(changes)
  }, [autosave.update, autosave.latest])
  const restoreHistory = useCallback((index: number) => {
    const value = history.current[index]
    if (!value) return
    historyPosition.current = index; historyTime.current = 0; setHistoryIndex(index)
    autosave.update(value); setSelectedId(null)
  }, [autosave.update])
  const onPages = useCallback((next: DocumentPageView[]) => setPages((current) => JSON.stringify(current) === JSON.stringify(next) ? current : next), [])
  const replaceDocument = (document: WebDocument) => {
    autosave.replace(document); history.current = [snapshot(document)]; historyPosition.current = 0; setHistoryIndex(0); setSelectedId(null)
  }
  const beginAction = useCallback((title: string, description?: string) => {
    if (actionInProgress.current) return null
    actionInProgress.current = true; setBusy(true); setNotice('')
    return actionAlert.loading(title, description)
  }, [])
  const finishAction = useCallback(() => { actionInProgress.current = false; setBusy(false) }, [])
  const reportSaveFailure = useCallback((id?: string) => {
    const current = latestSaveState.current
    const failure = autosave.saveFailure.current
    const message = failure?.message || current.error
    const conflict = failure?.conflict || current.status === 'conflict'
    saveFeedbackPending.current = false
    if (message) notifiedSaveError.current = `${conflict ? 'conflict' : 'error'}:${message}`
    actionAlert.error('บันทึกเอกสารไม่สำเร็จ', message || (conflict ? 'เอกสารมีรุ่นใหม่จากอีกหน้าต่าง กรุณาเก็บสำเนาหรือโหลดรุ่นล่าสุด' : 'ข้อมูลที่แก้ไขยังอยู่ในหน้านี้ กรุณาตรวจข้อความแจ้งเตือนแล้วลองบันทึกอีกครั้ง'), id)
  }, [autosave.saveFailure])
  const saveWithFeedback = useCallback(async () => {
    saveFeedbackPending.current = true
    const saved = await autosave.save()
    if (saved) saveFeedbackPending.current = false
    return saved
  }, [autosave.save])
  const saveDocument = useCallback(async () => {
    const id = beginAction('กำลังบันทึกเอกสาร', 'กรุณารอสักครู่ ระบบกำลังเก็บการแก้ไขล่าสุด')
    if (!id) return
    try {
      if (latestSaveState.current.status === 'conflict' || !await saveWithFeedback()) { reportSaveFailure(id); return }
      actionAlert.success('บันทึกเอกสารแล้ว', 'เก็บการแก้ไขล่าสุดเรียบร้อย', id)
    } finally { finishAction() }
  }, [saveWithFeedback, beginAction, finishAction, reportSaveFailure])
  useEffect(() => {
    if (!autosave.error) { notifiedSaveError.current = ''; return }
    const errorKey = `${autosave.status}:${autosave.error}`
    if (notifiedSaveError.current === errorKey) return
    notifiedSaveError.current = errorKey
    if (saveFeedbackPending.current) { saveFeedbackPending.current = false; return }
    actionAlert.error(autosave.status === 'conflict' ? 'เอกสารมีรุ่นใหม่' : 'บันทึกอัตโนมัติไม่สำเร็จ', autosave.error)
  }, [autosave.error, autosave.status])
  useEffect(() => {
    if (!editor) return
    const update = () => selectionTick((value) => value + 1)
    editor.on('selectionUpdate', update); editor.on('transaction', update)
    return () => { editor.off('selectionUpdate', update); editor.off('transaction', update) }
  }, [editor])
  useEffect(() => {
    if (!view.thumbnails) return
    let cancelled = false
    const timer = window.setTimeout(async () => {
      const canvas = canvasHost.current?.querySelector<HTMLElement>('[data-document-canvas]')
      if (!canvas || !pages.length) return
      try {
        const next = await createDocumentThumbnails(canvas, pages)
        if (cancelled) { next.forEach(URL.revokeObjectURL); return }
        thumbnailUrls.current.forEach(URL.revokeObjectURL); thumbnailUrls.current = next; setThumbnails(next)
      } catch { /* Navigation remains available while optional preview images load. */ }
    }, 700)
    return () => { cancelled = true; clearTimeout(timer) }
  }, [doc.content, doc.objects, doc.pageSettings, pages, view.thumbnails])
  useEffect(() => () => thumbnailUrls.current.forEach(URL.revokeObjectURL), [])
  useEffect(() => {
    const shortcut = (event: KeyboardEvent) => {
      if (event.isComposing || !(event.metaKey || event.ctrlKey)) return
      if (event.key.toLowerCase() === 's') { event.preventDefault(); void saveDocument(); return }
      if (event.key.toLowerCase() === 'f') { event.preventDefault(); setModal('find'); return }
      if ((event.target as HTMLElement)?.closest('input,textarea,select,[contenteditable="true"]')) return
      if (event.key.toLowerCase() === 'z') { event.preventDefault(); restoreHistory(historyPosition.current + (event.shiftKey ? 1 : -1)) }
      if (event.key.toLowerCase() === 'y') { event.preventDefault(); restoreHistory(historyPosition.current + 1) }
    }
    window.addEventListener('keydown', shortcut)
    return () => window.removeEventListener('keydown', shortcut)
  }, [saveDocument, restoreHistory])
  const changeObject = (object: DocumentObject) => {
    if (object.type === 'image' && object.wrap === 'inline' && object.src && editor) {
      editor.chain().focus().insertContent({ type: 'image', attrs: { src: object.src, assetId: object.assetId, width: object.width * MM_TO_PX, height: object.height * MM_TO_PX } }).run()
      commit({ objects: autosave.latest.current.objects.filter((item) => item.id !== object.id) }); setSelectedId(null); return
    }
    const constrained = { ...object, ...constrainObject(object, doc.pageSettings) }
    commit({ objects: autosave.latest.current.objects.map((item) => item.id === object.id ? constrained : item) })
  }
  const insertObject = (type: DocumentObject['type'], shape?: DocumentObject['shape']) => {
    const object = { ...createDocumentObject(type, currentPage + 1), ...(shape ? { shape } : {}), zIndex: Math.max(...doc.objects.map((item) => item.zIndex), 0) + 1 }
    commit({ objects: [...doc.objects, object] }); setSelectedId(object.id); setView((current) => ({ ...current, properties: true }))
  }
  const uploadImage = (inline: boolean) => { if (actionInProgress.current) return; replaceImageId.current = null; uploadInline.current = inline; uploadInput.current?.click() }
  const receiveImage = async (file: File | undefined) => {
    if (!file) return
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) { const message = 'เลือกรูป JPG, PNG หรือ WebP ไม่เกิน 5 MB'; setNotice(message); actionAlert.error('เพิ่มรูปภาพไม่สำเร็จ', message); if (uploadInput.current) uploadInput.current.value = ''; return }
    const id = beginAction('กำลังอัปโหลดรูปภาพ', file.name)
    if (!id) { if (uploadInput.current) uploadInput.current.value = ''; return }
    try {
      const body = new FormData(); body.append('file', file)
      const result = await documentRequest<{ asset: DocumentAsset }>(`/api/documents/${doc.id}/assets`, { method: 'POST', body })
      const asset = result.asset
      autosave.update({ assets: [...autosave.latest.current.assets, asset] })
      if (replaceImageId.current) {
        commit({ objects: autosave.latest.current.objects.map((object) => object.id === replaceImageId.current ? { ...object, assetId: asset.id, src: asset.url } : object) })
        replaceImageId.current = null
      }
      else if (uploadInline.current && editor) editor.chain().focus().insertContent({ type: 'image', attrs: { src: asset.url, assetId: asset.id, alt: asset.name, width: 240, height: Math.round(240 * asset.height / asset.width) } }).run()
      else {
        const object = { ...createDocumentObject('image', currentPage + 1), assetId: asset.id, src: asset.url, height: Math.min(100, 60 * asset.height / asset.width) }
        commit({ objects: [...autosave.latest.current.objects, object] }); setSelectedId(object.id)
      }
      actionAlert.success('เพิ่มรูปภาพแล้ว', 'รูปภาพพร้อมจัดวางในเอกสาร', id)
    } catch (error) { const message = error instanceof Error ? error.message : 'อัปโหลดรูปไม่สำเร็จ'; setNotice(message); actionAlert.error('เพิ่มรูปภาพไม่สำเร็จ', message, id) }
    finally { finishAction(); if (uploadInput.current) uploadInput.current.value = '' }
  }
  const fit = (mode: 'page' | 'width') => {
    const container = stage.current
    if (!container) return
    const dimensions = pageDimensions(doc.pageSettings)
    const width = (container.clientWidth - 64) / (dimensions.width * MM_TO_PX)
    const height = (container.clientHeight - 64) / (dimensions.height * MM_TO_PX)
    setZoom(Math.max(0.5, Math.min(2, mode === 'page' ? Math.min(width, height) : width)))
  }
  const navigatePage = (index: number) => { setCurrentPage(index); stage.current?.scrollTo({ top: (pages[index]?.top || 0) * zoom, behavior: 'instant' }) }
  const print = async () => {
    if (actionInProgress.current) return
    const canvas = canvasHost.current?.querySelector<HTMLElement>('[data-document-canvas]')
    if (!canvas) { actionAlert.error('เอกสารยังไม่พร้อมพิมพ์', 'กรุณารอให้หน้ากระดาษโหลดเสร็จแล้วลองอีกครั้ง'); return }
    const preview = window.open('', '_blank', 'popup,width=1100,height=850')
    if (!preview) { const message = 'เบราว์เซอร์ปิดกั้นหน้าพิมพ์ กรุณาอนุญาตป๊อปอัปสำหรับเว็บไซต์นี้'; setNotice(message); actionAlert.error('เปิดหน้าพิมพ์ไม่ได้', message); return }
    const id = beginAction(context ? 'กำลังบันทึกและเตรียมหน้าพิมพ์' : 'กำลังเตรียมหน้าพิมพ์', 'กำลังตรวจหน้ากระดาษ ฟอนต์ และรูปภาพ')
    if (!id) { preview.close(); return }
    try {
      if (context && (latestSaveState.current.status === 'conflict' || !await saveWithFeedback())) { preview.close(); reportSaveFailure(id); return }
      const popup = await openDocumentPrint(autosave.latest.current, canvas, preview)
      if (!popup) throw new Error('เบราว์เซอร์ปิดกั้นหน้าพิมพ์ กรุณาอนุญาตป๊อปอัปสำหรับเว็บไซต์นี้')
      actionAlert.success(context ? 'บันทึกแล้ว พร้อมพิมพ์' : 'เอกสารพร้อมพิมพ์', 'เลือก “พิมพ์ / บันทึก PDF” ในหน้าต่างตัวอย่าง แล้วเลือก Save as PDF หากต้องการไฟล์ PDF', id)
    }
    catch (error) { const message = error instanceof Error ? error.message : 'เปิดหน้าพิมพ์ไม่สำเร็จ'; setNotice(message); actionAlert.error('เตรียมหน้าพิมพ์ไม่สำเร็จ', message, id) }
    finally { finishAction() }
  }
  const docx = async () => {
    const id = beginAction('กำลังสร้างไฟล์ DOCX', 'กำลังเตรียมข้อความและรูปภาพสำหรับเอกสาร Word')
    if (!id) return
    try {
      const { exportDocx } = await import('@/lib/document-export')
      const result = await exportDocx(autosave.latest.current)
      const url = URL.createObjectURL(result.blob); const link = window.document.createElement('a'); link.href = url; link.download = `${doc.title.replace(/[\\/:*?"<>|]/g, '_') || 'document'}.docx`; link.click(); setTimeout(() => URL.revokeObjectURL(url), 10000)
      if (result.warnings.length) { const message = result.warnings.join(' · '); setNotice(message); actionAlert.info('เตรียม DOCX แล้ว มีข้อควรตรวจ', `ส่งไฟล์ให้เบราว์เซอร์ดาวน์โหลดแล้ว · ${message}`, id) }
      else actionAlert.success('เตรียมไฟล์ DOCX แล้ว', 'ส่งไฟล์ให้เบราว์เซอร์ดาวน์โหลดแล้ว ตรวจไฟล์ได้จากรายการดาวน์โหลด', id)
    } catch (error) { const message = error instanceof Error ? error.message : 'ส่งออก DOCX ไม่สำเร็จ'; setNotice(message); actionAlert.error('สร้างไฟล์ DOCX ไม่สำเร็จ', message, id) }
    finally { finishAction() }
  }
  const loadVersions = async () => {
    const id = beginAction('กำลังโหลดประวัติเอกสาร')
    if (!id) return
    setModal('versions')
    try { const result = await documentRequest<{ versions: DocumentVersion[] }>(`/api/documents/${doc.id}/versions`); setVersions(result.versions); actionAlert.dismiss(id) }
    catch (error) { const message = error instanceof Error ? error.message : 'โหลดประวัติไม่สำเร็จ'; setNotice(message); actionAlert.error('โหลดประวัติไม่สำเร็จ', message, id) }
    finally { finishAction() }
  }
  const restoreVersion = async (version: DocumentVersion) => {
    const id = beginAction('กำลังคืนรุ่นเอกสาร', `กำลังนำเนื้อหารุ่นที่ ${version.revision} กลับมา`)
    if (!id) return
    try {
      if (latestSaveState.current.status === 'conflict' || !await saveWithFeedback()) { reportSaveFailure(id); return }
      const result = await documentRequest<{ document: WebDocument }>(`/api/documents/${doc.id}/versions/${version.id}/restore`, { method: 'POST', body: JSON.stringify({ revision: autosave.latest.current.revision }) }); replaceDocument(result.document); setModal(null)
      actionAlert.success('คืนรุ่นเอกสารแล้ว', 'เก็บประวัติการคืนรุ่นเรียบร้อย', id)
    }
    catch (error) { const message = error instanceof Error ? error.message : 'คืนรุ่นไม่สำเร็จ'; setNotice(message); actionAlert.error('คืนรุ่นเอกสารไม่สำเร็จ', message, id) }
    finally { finishAction() }
  }
  const findMatches = (query: string) => {
    if (!editor || !query) { setMatches([]); return [] }
    const found = findDocumentText(editor.state.doc, query)
    setMatches(found); return found
  }
  const goMatch = (index: number) => { const found = findMatches(find); if (!found.length) return; const next = (index + found.length) % found.length; setMatchIndex(next); editor?.chain().focus().setTextSelection(found[next]).scrollIntoView().run() }
  const replaceMatches = (all: boolean) => {
    if (!editor || !find) return
    const found = findMatches(find)
    const replace = all ? found : found.slice(matchIndex, matchIndex + 1)
    let transaction = editor.state.tr
    for (const match of replace.reverse()) transaction = transaction.insertText(replacement, match.from, match.to)
    editor.view.dispatch(transaction); findMatches(find)
  }
  const keepCopy = async () => {
    const id = beginAction('กำลังเก็บสำเนาเอกสาร', 'กำลังเก็บฉบับที่คุณแก้ไขเป็นเอกสารใหม่')
    if (!id) return
    try { const result = await documentRequest<{ document: WebDocument }>(`/api/documents/${doc.id}/duplicate`, { method: 'POST', body: JSON.stringify({ ...snapshot(autosave.latest.current), title: `${doc.title.slice(0, 190)} (สำเนา)` }) }); autosave.replace({ ...autosave.latest.current, title: result.document.title }); actionAlert.success('เก็บสำเนาเอกสารแล้ว', result.document.title, id); appRouter.visit(`/documents/${result.document.id}/edit`) }
    catch (error) { const message = error instanceof Error ? error.message : 'เก็บสำเนาไม่สำเร็จ'; setNotice(message); actionAlert.error('เก็บสำเนาไม่สำเร็จ', message, id) }
    finally { finishAction() }
  }
  const loadLatest = async () => {
    const id = beginAction('กำลังโหลดเอกสารรุ่นล่าสุด')
    if (!id) return
    try { const result = await documentRequest<{ document: WebDocument }>(`/api/documents/${doc.id}`); replaceDocument(result.document); actionAlert.success('โหลดรุ่นล่าสุดแล้ว', 'แสดงเอกสารฉบับล่าสุดจากระบบ', id) }
    catch (error) { const message = error instanceof Error ? error.message : 'โหลดรุ่นล่าสุดไม่สำเร็จ'; setNotice(message); actionAlert.error('โหลดรุ่นล่าสุดไม่สำเร็จ', message, id) }
    finally { finishAction() }
  }
  const leaveEditor = async () => {
    if (actionInProgress.current) return
    if (latestSaveState.current.status === 'conflict') { reportSaveFailure(); return }
    const id = beginAction('กำลังบันทึกก่อนออกจากเอกสาร')
    if (!id) return
    try {
      if (!await saveWithFeedback()) { reportSaveFailure(id); return }
      actionAlert.dismiss(id)
      appRouter.visit(context?.returnUrl || '/documents')
    } finally { finishAction() }
  }
  return <AppShell wide><Head title={doc.title} /><div className="de-workspace">
    <header className="de-header"><AppLink href={appUrl(context?.returnUrl || '/documents')} onClick={event => { event.preventDefault(); void leaveEditor() }} className="de-back" aria-label={context ? 'กลับหน้าบันทึก' : 'กลับรายการเอกสาร'}><ArrowLeft size={20} /></AppLink><span className="de-document-icon"><FileText size={24} /></span><div className="de-title-wrap"><input aria-label="ชื่อเอกสาร" className="de-title" value={doc.title} maxLength={200} onChange={(event) => commit({ title: event.target.value })} /></div><div className="de-header-actions"><Button type="button" size="icon" variant="ghost" aria-label="ย้อนกลับ" disabled={historyIndex <= 0} onClick={() => restoreHistory(historyPosition.current - 1)}><Undo2 size={19} /></Button><Button type="button" size="icon" variant="ghost" aria-label="ทำซ้ำ" disabled={historyIndex >= history.current.length - 1} onClick={() => restoreHistory(historyPosition.current + 1)}><Redo2 size={19} /></Button><Button type="button" size="icon" variant="ghost" aria-label="ประวัติเอกสาร" disabled={busy} onClick={loadVersions}><History size={20} /></Button></div></header>
    <section className="de-action-bar" aria-label="บันทึกและส่งออกเอกสาร">
      <div className="de-action-state"><span className="de-action-caption" title={doc.title}>{doc.title}</span><span className="de-save-status" role="status">{autosave.status === 'saved' ? <Check size={13} /> : autosave.status === 'saving' ? <LoaderCircle size={13} className="animate-spin" /> : null}{({ saved: 'บันทึกอัตโนมัติแล้ว', pending: 'มีการแก้ไข · รอบันทึก', saving: 'กำลังบันทึก…', error: 'บันทึกไม่สำเร็จ', conflict: 'เอกสารมีรุ่นใหม่จากอีกหน้าต่าง' })[autosave.status]}</span></div>
      <div className="de-action-buttons">
        <Button type="button" className="de-command-save" disabled={busy || autosave.status === 'saving' || autosave.status === 'conflict'} aria-label="บันทึก" onClick={() => { void saveDocument() }}><Save size={18} /><span>บันทึก</span></Button>
        <Button type="button" variant="outline" className="de-command-print" disabled={busy} aria-label={context ? 'บันทึกและพิมพ์' : 'พิมพ์ / PDF'} onClick={print}><Printer size={18} /><span className="de-command-label-full">{context ? 'บันทึกและพิมพ์' : 'พิมพ์ / PDF'}</span><span className="de-command-label-compact" aria-hidden="true">พิมพ์ / PDF</span></Button>
        <Button type="button" variant="outline" className="de-command-docx" disabled={busy} aria-label="DOCX" title="ดาวน์โหลดเอกสาร Word (.docx)" onClick={docx}><Download size={18} /><span>DOCX</span></Button>
      </div>
    </section>
    {context && <p className="de-source-note"><b>จัดเอกสารก่อนพิมพ์</b> · เริ่มจากรูปแบบเอกสารเดิม แก้ข้อความ รูปภาพ และจัดวางได้จากแถบเครื่องมือ{context.kind === 'draft' ? ' · บันทึกลงฉบับร่าง' : ''}</p>}
    {!context && doc.sourceRecordId && <p className="de-source-note">เอกสารสำเนาจาก <AppLink href={appUrl(`/records/${doc.sourceRecordId}`)}>บันทึกหลังสอน</AppLink> · บันทึกการแก้ไขและประวัติรุ่นไว้ในเอกสารฉบับนี้</p>}
    <EditorRibbon editor={editor} document={doc} onChange={commit} insertObject={insertObject} uploadImage={uploadImage} zoom={zoom} setZoom={setZoom} fit={fit} view={view} setView={setView} onFind={() => setModal('find')} onPrint={print} onDocx={docx} onVersions={loadVersions} busy={busy} wordCount={wordCount} onReview={() => setModal('review')} />
    {(notice || autosave.error) && <div className="de-notice" role="alert"><span>{notice || autosave.error}</span><button aria-label="ปิดข้อความแจ้งเตือน" onClick={() => { setNotice(''); autosave.clearError() }}><X size={16} /></button></div>}
    {autosave.status === 'conflict' && <div className="de-notice"><span>ระบบหยุดบันทึกอัตโนมัติเพื่อเก็บฉบับที่คุณกำลังแก้ไข</span><Button size="sm" variant="outline" onClick={keepCopy} disabled={busy}><Copy size={15} />เก็บเป็นเอกสารใหม่</Button><Button size="sm" variant="outline" disabled={busy} onClick={loadLatest}>โหลดรุ่นล่าสุด</Button></div>}
    <div className="de-panels" data-thumbnails={view.thumbnails} data-properties={view.properties}>
      {view.thumbnails && <aside className="de-thumbnails" aria-label="หน้าเอกสาร"><div className="de-sidebar-heading"><h2>หน้าเอกสาร</h2><button title="ยุบแถบหน้า" aria-label="ยุบแถบหน้า" onClick={() => setView({ ...view, thumbnails: false })}><PanelLeftClose size={15} /></button></div>{pages.map((page, index) => <button key={index} className={`de-thumbnail ${currentPage === index ? 'is-active' : ''}`} aria-label={`ไปหน้า ${index + 1}`} aria-current={currentPage === index ? 'page' : undefined} onClick={() => navigatePage(index)}>{thumbnails[index] ? <img src={thumbnails[index]} alt={`ภาพตัวอย่างหน้า ${index + 1}`} /> : <span className="de-thumbnail-loading" style={{ aspectRatio: `${page.width}/${page.height}` }}><LoaderCircle size={18} className="animate-spin" /></span>}<span>{index + 1}</span></button>)}<Button type="button" variant="outline" size="sm" onClick={() => { editor?.chain().focus('end').insertContent([{ type: 'pageBreak' }, { type: 'paragraph' }]).run() }} disabled={!editor}><Plus size={15} />เพิ่มหน้า</Button></aside>}
      <div ref={stage} className="de-canvas-host document-stage" onScroll={(event) => { const top = event.currentTarget.scrollTop / zoom; const index = pages.findIndex((page, index) => top < (pages[index + 1]?.top ?? Infinity)); if (index >= 0) setCurrentPage(index) }}><div ref={canvasHost}><DocumentCanvas document={doc} onChange={commit} onEditor={setEditor} selectedObjectId={selectedId} onSelectObject={setSelectedId} zoom={zoom} showRuler={view.ruler} showGrid={view.grid} showMarks={view.marks} onPages={onPages} /></div></div>
      {view.properties && <ObjectProperties object={selected} document={doc} editor={editor} onChange={changeObject} onReplaceImage={() => { if (actionInProgress.current) return; replaceImageId.current = selectedId; uploadInline.current = false; uploadInput.current?.click() }} onClose={() => setView((current) => ({ ...current, properties: false }))} onDuplicate={() => { if (selected) { const copy = { ...selected, id: crypto.randomUUID(), x: selected.x + 5, y: selected.y + 5, anchor: selected.anchor.kind === 'paragraph' ? { ...selected.anchor, offsetY: (selected.anchor.offsetY || 0) + 5 } : selected.anchor, zIndex: Math.max(...doc.objects.map((item) => item.zIndex)) + 1 }; commit({ objects: [...doc.objects, copy] }); setSelectedId(copy.id) } }} onDelete={() => { commit({ objects: doc.objects.filter((object) => object.id !== selectedId) }); setSelectedId(null) }} />}
    </div>
    <footer className="de-statusbar"><button onClick={() => setView({ ...view, thumbnails: !view.thumbnails })} title="แสดง / ซ่อนหน้าเอกสาร"><FileText size={15} /></button><span>หน้า {Math.min(currentPage + 1, Math.max(1, pages.length))} จาก {Math.max(1, pages.length)}</span><span>{wordCount.toLocaleString('th-TH')} คำ</span><span className="hidden sm:inline">ภาษาไทย · {doc.pageSettings.size}</span><div className="ml-auto flex items-center gap-2"><button onClick={() => setView({ ...view, properties: !view.properties })} title="คุณสมบัติ" aria-label="แสดง / ซ่อนคุณสมบัติ"><PanelRight size={17} /></button><button aria-label="ซูมออก" onClick={() => setZoom(Math.max(0.5, zoom - 0.1))}><MinusIcon /></button><input aria-label="ซูมเอกสาร" type="range" min={0.5} max={2} step={0.05} value={zoom} onChange={(event) => setZoom(Number(event.target.value))} /><button onClick={() => setZoom(Math.min(2, zoom + 0.1))} aria-label="ซูมเข้า"><Plus size={14} /></button><span>{Math.round(zoom * 100)}%</span></div></footer>
    <input ref={uploadInput} type="file" accept="image/jpeg,image/png,image/webp" className="hidden" aria-label="ไฟล์รูปภาพเอกสาร" onChange={(event) => receiveImage(event.target.files?.[0])} />
    <Dialog.Root open={modal !== null} onOpenChange={(open) => { if (!open) setModal(null) }}><Dialog.Portal><Dialog.Overlay className="de-dialog-overlay" /><Dialog.Content className="de-dialog"><div className="flex items-center justify-between gap-4"><Dialog.Title className="text-lg font-semibold">{modal === 'find' ? 'ค้นหาและแทนที่' : modal === 'versions' ? 'ประวัติรุ่นเอกสาร' : 'ตรวจโครงสร้างเอกสาร'}</Dialog.Title><Dialog.Close aria-label="ปิดหน้าต่าง"><X size={20} /></Dialog.Close></div><Dialog.Description className="mt-1 text-xs text-slate-500">{modal === 'versions' ? 'ระบบเก็บรุ่นก่อนแก้ไขไว้ การคืนรุ่นจะสร้างประวัติใหม่' : 'ทำงานกับข้อมูลต้นฉบับของเอกสารที่กำลังเปิด'}</Dialog.Description>
      {modal === 'find' && <div className="mt-5 grid gap-3"><label className="de-property-field">ค้นหา<input autoFocus value={find} onChange={(event) => { setFind(event.target.value); setMatchIndex(0); findMatches(event.target.value) }} /></label><label className="de-property-field">แทนที่ด้วย<input value={replacement} onChange={(event) => setReplacement(event.target.value)} /></label><div className="flex items-center gap-2"><span className="mr-auto text-xs">พบ {matches.length} ตำแหน่ง</span><Button size="icon" variant="outline" aria-label="ผลก่อนหน้า" onClick={() => goMatch(matchIndex - 1)}><ChevronLeft size={18} /></Button><Button size="icon" variant="outline" aria-label="ผลถัดไป" onClick={() => goMatch(matchIndex + 1)}><ChevronRight size={18} /></Button><Button size="sm" variant="outline" disabled={!matches.length} onClick={() => replaceMatches(false)}>แทนที่</Button><Button size="sm" disabled={!matches.length} onClick={() => replaceMatches(true)}>แทนที่ทั้งหมด</Button></div></div>}
      {modal === 'versions' && <div className="mt-5 max-h-[60dvh] overflow-auto">{busy ? <p>กำลังโหลด…</p> : versions.map((version) => <div key={version.id} className="flex items-center gap-4 border-b py-3"><div className="min-w-0 flex-1"><b className="block truncate text-sm">รุ่นที่ {version.revision} · {version.title}</b><small className="text-slate-500">{new Date(version.createdAt).toLocaleString('th-TH')}</small></div><Button size="sm" variant="outline" disabled={busy || version.revision === doc.revision} onClick={() => restoreVersion(version)}>คืนรุ่นนี้</Button></div>)}</div>}
      {modal === 'review' && <div className="mt-5 grid gap-3 text-sm"><p>✓ ต้นฉบับ JSON schema รุ่น {doc.schemaVersion}</p><p>✓ กระดาษ {doc.pageSettings.size} · {pages.length} หน้า</p><p>✓ วัตถุ {doc.objects.length} ชิ้น · รูปภาพ {doc.assets.length} ไฟล์</p><p>✓ ข้อความ {wordCount.toLocaleString('th-TH')} คำ · {text.length.toLocaleString('th-TH')} ตัวอักษร</p><p>{doc.objects.some((object) => object.x < 0 || object.y < 0 || object.x + object.width > pageDimensions(doc.pageSettings).width || object.y + object.height > pageDimensions(doc.pageSettings).height) ? 'มีวัตถุอยู่นอกขอบกระดาษ กรุณาตรวจตำแหน่งก่อนพิมพ์' : '✓ วัตถุทั้งหมดอยู่ภายในกระดาษ'}</p><p className="text-xs text-slate-500">การตรวจนี้ตรวจโครงสร้างและขอบกระดาษ ไม่ตรวจการสะกดคำ</p></div>}
    </Dialog.Content></Dialog.Portal></Dialog.Root>
  </div></AppShell>
}
function MinusIcon() { return <span aria-hidden="true">−</span> }
