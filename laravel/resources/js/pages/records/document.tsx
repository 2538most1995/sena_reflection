import { captureDocumentForPrint, composePrintPreview } from '@/lib/document-print-preview'
import { appFetch as fetch, appUrl } from '@/lib/app-url'
import { arrangeImageLayouts, synchronizeImageLayouts } from '@/lib/document-image-layout'
import { AppLink as Link } from '@/components/app-link'
import { appRouter as router } from '@/lib/app-router'
import { Head, useForm } from '@inertiajs/react'
import * as Tabs from '@radix-ui/react-tabs'
import {
  ArrowClockwise, ArrowCounterClockwise, ArrowDown, ArrowLeft, ArrowRight, ArrowUp, ArrowsOutCardinal, FilePdf, FileText,
  FloppyDisk, ImageSquare, ListChecks, Minus, NotePencil, Plus, Printer, TextT,
  Trash, WarningCircle,
} from '@phosphor-icons/react'
import { FormEvent, useEffect, useMemo, useRef, useState } from 'react'
import { AppShell } from '@/components/app-shell'
import { FlashMessages } from '@/components/flash-messages'
import { DocumentRibbon, type DocumentSelection } from '@/components/document-ribbon'
import { documentRequest } from '@/lib/document-api'
import type { WebDocument } from '@/lib/document-model'
import { FormField } from '@/components/form-field'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { NativeSelect } from '@/components/ui/native-select'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { cn } from '@/lib/utils'
import type { RecordRow } from '@/types'

type Props = {
  record: RecordRow
  settings: Record<string, string>
  content: Record<string, string>
  canonicalContent: Record<string, string>
  contentConflicts: string[]
  hasSavedLayout: boolean
  draftContext?: { id: string; levelCode: string; lockVersion: number }
}

type DocumentState = { settings: Record<string, string>; content: Record<string, string> }

type PageGeometry = { pageWidth: number; documentHeight: number; pageCount: number; pages?: { top: number; height: number; width: number }[] }

type LayoutTarget = 'sig1' | 'sig2' | 'sig3' | 'image1' | 'image2' | 'image3' | 'image4'

const layoutTargets = {
  sig1: { kind: 'signature', label: 'ผู้บันทึก', x: 'sig1_offset', y: 'sig1_offset_y', align: 'sig1_align' },
  sig2: { kind: 'signature', label: 'หัวหน้างาน', x: 'sig2_offset', y: 'sig2_offset_y', align: 'sig2_align' },
  sig3: { kind: 'signature', label: 'ผู้อำนวยการ', x: 'sig3_offset', y: 'sig3_offset_y', align: 'sig3_align' },
  image1: { kind: 'image', label: 'รูปที่ 1', x: 'image_1_x_px', y: 'image_1_y_px', width: 'image_1_width_px', height: 'image_1_height_px', fit: 'image_1_object_fit', rotation: 'image_1_rotation_deg', lock: 'image_1_lock_aspect' },
  image2: { kind: 'image', label: 'รูปที่ 2', x: 'image_2_x_px', y: 'image_2_y_px', width: 'image_2_width_px', height: 'image_2_height_px', fit: 'image_2_object_fit', rotation: 'image_2_rotation_deg', lock: 'image_2_lock_aspect' },
  image3: { kind: 'image', label: 'รูปที่ 3', x: 'image_3_x_px', y: 'image_3_y_px', width: 'image_3_width_px', height: 'image_3_height_px', fit: 'image_3_object_fit', rotation: 'image_3_rotation_deg', lock: 'image_3_lock_aspect' },
  image4: { kind: 'image', label: 'รูปที่ 4', x: 'image_4_x_px', y: 'image_4_y_px', width: 'image_4_width_px', height: 'image_4_height_px', fit: 'image_4_object_fit', rotation: 'image_4_rotation_deg', lock: 'image_4_lock_aspect' },
} as const

const imageDefaults: Record<'image1' | 'image2' | 'image3' | 'image4', { x: string; y: string }> = {
  image1: { x: '0', y: '8' },
  image2: { x: '316', y: '8' },
  image3: { x: '0', y: '249' },
  image4: { x: '316', y: '249' },
}

const imagePresets = [
  { label: 'แนวนอน', width: '360', height: '240' },
  { label: 'จัตุรัส', width: '280', height: '280' },
  { label: 'แนวตั้ง', width: '240', height: '340' },
]

const documentContentKeys = new Set([
  'record_subject', 'record_subject_code', 'record_class_group_code', 'record_class_name',
  'record_result', 'record_problem', 'record_suggestion', 'record_supervisor_comment',
])

const localPreviewKey = /^(image_layout_by_id|sig[123]_offset(_y)?|image_[1-4]_(x_px|y_px|width_px|height_px|object_fit|rotation_deg|lock_aspect|crop_x|crop_y))$/

function previewKey(document: DocumentState) {
  return JSON.stringify({ settings: Object.fromEntries(Object.entries(document.settings).filter(([key]) => !localPreviewKey.test(key))), content: document.content })
}

const marginPresets = [
  { label: 'มาตรฐาน', top: '57', right: '76', bottom: '38', left: '94' },
  { label: 'แคบ', top: '32', right: '38', bottom: '32', left: '38' },
  { label: 'กว้าง', top: '72', right: '110', bottom: '56', left: '110' },
]

function PageToggle({ checked, icon, label, description, onCheckedChange }: { checked: boolean; icon: React.ReactNode; label: string; description: string; onCheckedChange: (checked: boolean) => void }) {
  return <label className="flex cursor-pointer items-center gap-3 rounded-xl border bg-background p-3 transition-colors hover:bg-muted/35">
    <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-secondary text-secondary-foreground">{icon}</span>
    <span className="min-w-0 flex-1"><b className="block text-sm">{label}</b><small className="block text-muted-foreground">{description}</small></span>
    <Switch checked={checked} onCheckedChange={onCheckedChange} aria-label={`${checked ? 'ปิด' : 'เปิด'}${label}`} />
  </label>
}

function ToolSection({ title, children, className }: { title: string; children: React.ReactNode; className?: string }) {
  return <section className={cn('grid gap-3 border-b border-border/70 p-4 last:border-b-0', className)}><h2 className="text-sm font-semibold">{title}</h2>{children}</section>
}

export default function RecordDocument({ record, settings, content, canonicalContent, contentConflicts, hasSavedLayout, draftContext }: Props) {
  const documentBase = draftContext ? `/teaching-drafts/${draftContext.id}/document/${draftContext.levelCode}` : `/records/${record.id}/document`
  const returnUrl = draftContext ? `/records/create?draft=${draftContext.id}&review=1` : `/records/${record.id}`
  const form = useForm<{ settings: Record<string, string>; content: Record<string, string>; content_resolution: 'document' | 'record' | '' }>({ settings, content, content_resolution: contentConflicts.length ? '' : 'document' })
  const [target, setTarget] = useState<LayoutTarget>(record.images?.length ? 'image1' : 'sig1')
  const [step, setStep] = useState(1)
  const [toolsOpen, setToolsOpen] = useState(false)
  const [advancedLoading, setAdvancedLoading] = useState(false)
  const [toolsTab, setToolsTab] = useState('content')
  const [textSelection, setTextSelection] = useState<DocumentSelection | null>(null)
  const [currentPage, setCurrentPage] = useState(0)
  const [zoom, setZoom] = useState(() => typeof window !== 'undefined' && window.innerWidth < 640 ? Math.max(0.4, Math.min(0.72, (window.innerWidth - 56) / 794)) : 0.72)
  const [preview, setPreview] = useState('')
  const [previewing, setPreviewing] = useState(true)
  const [previewError, setPreviewError] = useState<string | null>(null)
  const [safeWarnings, setSafeWarnings] = useState<string[]>([])
  const [printPreviewUrl, setPrintPreviewUrl] = useState<string | null>(null)
  const [printPreviewLoading, setPrintPreviewLoading] = useState(false)
  const [printPreviewStale, setPrintPreviewStale] = useState(true)
  const [previewMode, setPreviewMode] = useState<'editor' | 'print'>('editor')
  const [historyIndex, setHistoryIndex] = useState(0)
  const [pageGeometry, setPageGeometry] = useState<PageGeometry>(() => ({ pageWidth: settings.paper_orientation === 'landscape' ? 1123 : 794, documentHeight: 1220, pageCount: 1 }))
  const iframeRef = useRef<HTMLIFrameElement>(null)
  const canvasRef = useRef<HTMLDivElement>(null)
  const saveRef = useRef<() => void>(() => {})
  const printFrameRef = useRef<HTMLIFrameElement>(null)
  const locallyRenderedKeyRef = useRef<string | null>(null)
  const renderedDocumentRef = useRef<string | null>(null)
  const historyRef = useRef<DocumentState[]>([{ settings, content }])
  const historyIndexRef = useRef(0)
  const latestDocumentRef = useRef<DocumentState>({ settings, content })
  const position = layoutTargets[target]
  const imageCount = record.images?.length || 0
  const attendanceCount = record.attendance?.filter((student) => student.present).length || 0
  const remotePreviewKey = useMemo(() => previewKey({ settings: form.data.settings, content: form.data.content }), [form.data.content, form.data.settings])

  const postSelection = (key: LayoutTarget) => iframeRef.current?.contentWindow?.postMessage({ source: 'sena-pdf-layout-select', imageId: key.startsWith('image') ? String(record.images?.[Number(key.replace('image', '')) - 1]?.id || '') : '', label: layoutTargets[key].label }, '*')
  const postLocalPatch = (changes: Record<string, string>) => iframeRef.current?.contentWindow?.postMessage({ source: 'sena-pdf-layout-patch', changes }, '*')
  const openTools = (tab: 'content' | 'paper' | 'elements') => { setToolsTab(tab); setToolsOpen(true) }
  const formatText = (command: string, value?: string) => iframeRef.current?.contentWindow?.postMessage({ source: 'sena-document-format', command, value }, '*')
  const goToPage = (index: number) => {
    setPreviewMode('editor')
    setCurrentPage(index)
    canvasRef.current?.scrollTo({ top: (pageGeometry.pages?.[index]?.top || 0) * zoom, behavior: 'instant' })
  }
  const commitDocument = (next: DocumentState, localChanges: Record<string, string> = {}, resolution?: 'document' | 'record') => {
    next = { ...next, settings: synchronizeImageLayouts(next.settings, record.images || []) }
    if (JSON.stringify(next) === JSON.stringify(latestDocumentRef.current)) return
    const nextHistory = historyRef.current.slice(0, historyIndexRef.current + 1)
    nextHistory.push(next)
    historyRef.current = nextHistory.slice(-100)
    const nextIndex = historyRef.current.length - 1
    historyIndexRef.current = nextIndex
    setHistoryIndex(nextIndex)
    latestDocumentRef.current = next
    form.setData((current) => ({ ...current, ...next, ...(resolution ? { content_resolution: resolution } : {}) }))
    setPrintPreviewStale(true)
    printFrameRef.current?.contentWindow?.postMessage({ source: 'sena-print-invalidated' }, '*')
    if (Object.keys(localChanges).length) postLocalPatch(localChanges)
  }
  const setSetting = (key: string, value: string) => {
    const current = latestDocumentRef.current
    const nextSettings = { ...current.settings, [key]: value }
    commitDocument({ settings: nextSettings, content: current.content }, localPreviewKey.test(key) ? { [key]: value } : {})
  }
  const setContent = (key: string, value: string) => commitDocument({ settings: latestDocumentRef.current.settings, content: { ...latestDocumentRef.current.content, [key]: value } }, {}, 'document')
  const restoreHistory = (index: number) => {
    const snapshot = historyRef.current[index]
    if (!snapshot) return
    locallyRenderedKeyRef.current = null
    latestDocumentRef.current = snapshot
    historyIndexRef.current = index
    setHistoryIndex(index)
    form.setData((current) => ({ ...current, ...snapshot }))
    setPrintPreviewStale(true)
    printFrameRef.current?.contentWindow?.postMessage({ source: 'sena-print-invalidated' }, '*')
    postLocalPatch(Object.fromEntries(Object.entries(snapshot.settings).filter(([key]) => localPreviewKey.test(key))))
  }
  const undo = () => restoreHistory(historyIndexRef.current - 1)
  const redo = () => restoreHistory(historyIndexRef.current + 1)
  const nudge = (key: string, amount: number) => setSetting(key, String((Number(form.data.settings[key]) || 0) + amount))
  const applyMargins = (preset: typeof marginPresets[number]) => {
    const nextSettings = {
      ...form.data.settings,
      pdf_margin_top_px: preset.top,
      pdf_margin_right_px: preset.right,
      pdf_margin_bottom_px: preset.bottom,
      pdf_margin_left_px: preset.left,
    }
    commitDocument({ settings: nextSettings, content: form.data.content })
  }
  const applyTextDensityPreset = (fontSize: string, lineSpacing: string) => {
    const nextSettings = {
      ...form.data.settings,
      pdf_font_size_pt: fontSize,
      pdf_line_spacing: lineSpacing,
    }
    commitDocument({ settings: nextSettings, content: form.data.content })
  }
  const resetTarget = () => {
    const changes: Record<string, string> = { [position.x]: '0', [position.y]: '0' }
    if (position.kind === 'image') {
      const defaults = imageDefaults[target as keyof typeof imageDefaults]
      changes[position.x] = defaults.x
      changes[position.y] = defaults.y
      changes[position.width] = '300'
      changes[position.height] = '225'
      changes[position.fit] = 'contain'
      changes[position.rotation] = '0'
      changes[position.lock] = '1'
    }
    commitDocument({ settings: { ...form.data.settings, ...changes }, content: form.data.content }, changes)
  }
  const applyImagePreset = (width: string, height: string) => {
    if (position.kind !== 'image') return
    const changes = { [position.width]: width, [position.height]: height, [position.lock]: '1' }
    commitDocument({ settings: { ...form.data.settings, ...changes }, content: form.data.content }, changes)
  }
  const arrangeImages = () => {
    const current = latestDocumentRef.current
    const settings = arrangeImageLayouts(current.settings, record.images || [])
    commitDocument({ ...current, settings }, Object.fromEntries(Object.entries(settings).filter(([key]) => localPreviewKey.test(key))))
  }
  const freeResize = () => {
    if (position.kind !== 'image') return
    const changes = { [position.lock]: '0', [position.fit]: 'fill' }
    commitDocument({ settings: { ...form.data.settings, ...changes }, content: form.data.content }, changes)
  }
  const scaleImage = (factor: number) => {
    if (position.kind !== 'image') return
    const width = Number(form.data.settings[position.width]) || 300
    const height = Number(form.data.settings[position.height]) || 225
    const scale = Math.max(10 / Math.min(width, height), Math.min(factor, 2400 / Math.max(width, height)))
    const changes = { [position.width]: String(Math.round(width * scale)), [position.height]: String(Math.round(height * scale)) }
    commitDocument({ settings: { ...form.data.settings, ...changes }, content: form.data.content }, changes)
  }
  const fitOriginalImage = () => iframeRef.current?.contentWindow?.postMessage({ source: 'sena-pdf-layout-fit-image', imageId: String(record.images?.[Number(target.replace('image', '')) - 1]?.id || '') }, '*')
  const rotateImage = (amount: number) => {
    if (position.kind !== 'image') return
    const current = Number(form.data.settings[position.rotation] || 0)
    setSetting(position.rotation, String((current + amount + 360) % 360))
  }
  const resizeImage = (axis: 'width' | 'height', value: string) => {
    if (position.kind !== 'image') return
    const changes: Record<string, string> = { [position[axis]]: value }
    if (value && Number(value) > 0 && form.data.settings[position.lock] === '1') {
      const width = Number(form.data.settings[position.width]) || 300
      const height = Number(form.data.settings[position.height]) || 225
      const other = axis === 'width' ? 'height' : 'width'
      changes[position[other]] = String(Math.round(Number(value) * (axis === 'width' ? height / width : width / height)))
    }
    commitDocument({ settings: { ...form.data.settings, ...changes }, content: form.data.content }, changes)
  }

  useEffect(() => {
    if (locallyRenderedKeyRef.current === remotePreviewKey) { setPreviewing(false); return }
    setPreviewing(true)
    setPreviewError(null)
    const controller = new AbortController()
    const timer = window.setTimeout(async () => {
      try {
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || ''
        const requestedDocument = latestDocumentRef.current
        const response = await fetch(`${documentBase}/preview`, {
          method: 'POST',
          signal: controller.signal,
          headers: { 'Content-Type': 'application/json', Accept: 'text/html, application/json', 'X-CSRF-TOKEN': token },
          body: JSON.stringify({ ...form.data, ...requestedDocument, ...(draftContext ? { lock_version: draftContext.lockVersion } : {}) }),
        })
        if (!response.ok) {
          const payload = await response.json().catch(() => null)
          throw new Error(payload?.message || 'สร้างตัวอย่างเอกสารไม่สำเร็จ')
        }
        const html = await response.text()
        if (!controller.signal.aborted) { renderedDocumentRef.current = previewKey(requestedDocument); setPreview(html) }
      } catch (error) {
        if (!controller.signal.aborted) setPreviewError(error instanceof Error ? error.message : 'สร้างตัวอย่างเอกสารไม่สำเร็จ')
      } finally {
        if (!controller.signal.aborted) setPreviewing(false)
      }
    }, 650)
    return () => { window.clearTimeout(timer); controller.abort() }
  }, [documentBase, remotePreviewKey])

  useEffect(() => {
    const receiveLayoutChange = (event: MessageEvent) => {
      if (event.source !== iframeRef.current?.contentWindow) return
      if (event.data?.source === 'sena-document-selection') { setTextSelection(event.data as DocumentSelection); return }
      if (event.data?.source === 'sena-document-save') { saveRef.current(); return }
      if (event.data?.source === 'sena-pdf-geometry') {
        const geometry = event.data as Partial<PageGeometry> & { safeWarnings?: unknown }
        if (Number.isFinite(geometry.pageWidth) && Number.isFinite(geometry.documentHeight) && Number.isFinite(geometry.pageCount) && Number(geometry.pageWidth) > 0 && Number(geometry.documentHeight) > 0 && Number(geometry.pageCount) > 0) {
          setPageGeometry({ pageWidth: Number(geometry.pageWidth), documentHeight: Number(geometry.documentHeight), pageCount: Number(geometry.pageCount), pages: Array.isArray(geometry.pages) ? geometry.pages.filter((page) => Number.isFinite(page.top) && Number.isFinite(page.height)) : [] })
        }
        setSafeWarnings(Array.isArray(geometry.safeWarnings) ? geometry.safeWarnings.filter((warning): warning is string => typeof warning === 'string') : [])
        return
      }
      if (event.data?.source !== 'sena-pdf-layout') return
      const imageId = String(event.data?.target?.imageId || '')
      const imageIndex = record.images?.findIndex((image) => String(image.id) === imageId) ?? -1
      const matchingTarget = imageIndex >= 0
        ? `image${imageIndex + 1}` as LayoutTarget
        : Object.entries(layoutTargets).find(([, value]) => value.label === event.data?.target?.label)?.[0] as LayoutTarget | undefined
      if (matchingTarget && matchingTarget in layoutTargets) setTarget(matchingTarget)

      const current = latestDocumentRef.current
      const settingChanges = Object.fromEntries(Object.entries(event.data?.changes || {}).filter(([key, value]) => key in current.settings && typeof value === 'string')) as Record<string, string>
      const textChanges = Object.fromEntries(Object.entries(event.data?.textChanges || {}).filter(([, value]) => typeof value === 'string')) as Record<string, string>
      const contentChanges = Object.fromEntries(Object.entries(textChanges).filter(([key]) => documentContentKeys.has(key)))
      const textSettingChanges = Object.fromEntries(Object.entries(textChanges).filter(([key]) => key in current.settings))
      const richChanges = Object.fromEntries(Object.entries(event.data?.richTextChanges || {}).filter(([key, value]) => (documentContentKeys.has(key) || key in current.settings) && value && typeof value === 'object' && typeof (value as { text?: unknown }).text === 'string' && typeof (value as { html?: unknown }).html === 'string'))
      if (Object.keys(richChanges).length) {
        let stored = {}
        try { stored = JSON.parse(current.settings.document_rich_text || '{}') || {} } catch { /* A missing legacy map starts empty. */ }
        textSettingChanges.document_rich_text = JSON.stringify({ ...stored, ...richChanges })
      }
      if (Object.keys(settingChanges).length || Object.keys(textSettingChanges).length || Object.keys(contentChanges).length) {
        const next = {
          settings: { ...current.settings, ...settingChanges, ...textSettingChanges },
          content: { ...current.content, ...contentChanges },
        }
        // The iframe already displays these edits. Replacing srcDoc would reset
        // the caret, native undo history, selection and an in-progress IME input.
        if (renderedDocumentRef.current === previewKey(current)) {
          locallyRenderedKeyRef.current = previewKey(next)
          renderedDocumentRef.current = previewKey(next)
        }
        commitDocument(next, {}, Object.keys(contentChanges).length ? 'document' : undefined)
      }
    }
    window.addEventListener('message', receiveLayoutChange)
    return () => window.removeEventListener('message', receiveLayoutChange)
  }, [record.images])

  useEffect(() => {
    const returnToEditor = (event: MessageEvent) => {
      if (event.source === printFrameRef.current?.contentWindow && event.data?.source === 'sena-print-edit-request') setPreviewMode('editor')
    }
    window.addEventListener('message', returnToEditor)
    return () => window.removeEventListener('message', returnToEditor)
  }, [])

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (form.processing || printPreviewLoading) return
      const modifier = event.metaKey || event.ctrlKey
      if (modifier && event.key.toLowerCase() === 's') { event.preventDefault(); saveRef.current(); return }
      const element = event.target as HTMLElement | null
      if (element?.matches('input, textarea, select, [contenteditable="true"]')) return
      if (!modifier) return
      if (event.key.toLowerCase() === 'z') {
        event.preventDefault()
        event.shiftKey ? redo() : undo()
      } else if (event.key.toLowerCase() === 'y') {
        event.preventDefault()
        redo()
      }
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [historyIndex, form.processing, printPreviewLoading])

  useEffect(() => () => {
    if (printPreviewUrl) URL.revokeObjectURL(printPreviewUrl)
  }, [printPreviewUrl])

  useEffect(() => {
    if (!form.isDirty) return
    const warnBeforeLeaving = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', warnBeforeLeaving)
    return () => window.removeEventListener('beforeunload', warnBeforeLeaving)
  }, [form.isDirty])

  const createPrintPreview = async () => {
    if (previewing || !iframeRef.current || renderedDocumentRef.current !== previewKey(latestDocumentRef.current)) throw new Error('กำลังอัปเดตกระดาษ กรุณารอสักครู่แล้วลองใหม่')
    const requestedDocument = JSON.stringify(latestDocumentRef.current)
    const capturedHtml = await captureDocumentForPrint(iframeRef.current)
    if (requestedDocument !== JSON.stringify(latestDocumentRef.current)) throw new Error('เอกสารมีการแก้ไขระหว่างเตรียมพิมพ์ กรุณาลองอีกครั้ง')
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || ''
    const response = await fetch(`${documentBase}/browser-preview`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'text/html, application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify({ ...form.data, ...latestDocumentRef.current, ...(draftContext ? { lock_version: draftContext.lockVersion } : {}) }),
    })
    if (!response.ok) {
      const payload = await response.json().catch(() => null)
      throw new Error(payload?.message || 'สร้างหน้าพิมพ์ไม่สำเร็จ')
    }
    const html = composePrintPreview(capturedHtml, await response.text())
    if (requestedDocument !== JSON.stringify(latestDocumentRef.current)) throw new Error('เอกสารมีการแก้ไขระหว่างเตรียมพิมพ์ กรุณาอัปเดตตัวอย่างอีกครั้ง')
    return URL.createObjectURL(new Blob([html], { type: 'text/html' }))
  }

  const loadPrintPreview = async () => {
    setPrintPreviewLoading(true)
    setPreviewError(null)
    try {
      const nextUrl = await createPrintPreview()
      setPrintPreviewUrl(nextUrl)
      setPrintPreviewStale(false)
      setPreviewMode('print')
    } catch (error) {
      setPreviewError(error instanceof Error ? error.message : 'สร้างหน้าพิมพ์ไม่สำเร็จ')
    } finally {
      setPrintPreviewLoading(false)
    }
  }

  const resolveContentConflict = (resolution: 'document' | 'record') => {
    if (resolution === 'record') {
      commitDocument({ settings: form.data.settings, content: canonicalContent }, {}, 'record')
      return
    }
    form.setData('content_resolution', 'document')
  }

  const save = async (event?: FormEvent, thenPrint = false) => {
    event?.preventDefault()
    if (contentConflicts.length && !form.data.content_resolution) {
      form.setError('content_resolution', 'เลือกว่าจะใช้เนื้อหาจากเอกสารหรือข้อมูลหลักก่อนบันทึก')
      return
    }
    const popup = thenPrint && !draftContext ? window.open('', '_blank') : null
    if (popup) popup.document.write('<p style="font-family:sans-serif;padding:24px">กำลังเตรียมหน้าพิมพ์…</p>')
    let printUrl: string | null = null
    if (thenPrint && !draftContext) {
      setPrintPreviewLoading(true)
      try { printUrl = await createPrintPreview() }
      catch (error) { popup?.close(); setPreviewError(error instanceof Error ? error.message : 'เตรียมพิมพ์ไม่สำเร็จ'); return }
      finally { setPrintPreviewLoading(false) }
    }
    const savedDocument = JSON.stringify(latestDocumentRef.current)
    form.transform((data) => ({ ...data, ...latestDocumentRef.current, ...(draftContext ? { lock_version: draftContext.lockVersion } : {}) }))
    form.put(appUrl(documentBase), {
      preserveScroll: true,
      onSuccess: () => {
        if (savedDocument !== JSON.stringify(latestDocumentRef.current)) {
          popup?.close()
          if (printUrl) URL.revokeObjectURL(printUrl)
          setPreviewError('บันทึกฉบับก่อนหน้าแล้ว แต่มีการแก้ไขเพิ่มเติม กรุณาบันทึกอีกครั้งก่อนพิมพ์')
          return
        }
        form.setDefaults()
        if (thenPrint) {
          if (draftContext) { router.visit(returnUrl); return }
          if (printUrl) {
            setPrintPreviewUrl(printUrl)
            setPrintPreviewStale(false)
            setPreviewMode('print')
            if (popup) popup.location.href = printUrl
          }
        }
      },
      onError: () => { popup?.close(); if (printUrl) URL.revokeObjectURL(printUrl) },
    })
  }

  const restoreDefault = () => {
    if (window.confirm('คืนรูปแบบเอกสารครั้งนี้เป็นค่าเริ่มต้นของระบบหรือไม่')) {
      router.delete(documentBase, { preserveScroll: true })
    }
  }
  const openAdvancedEditor = async () => {
    setAdvancedLoading(true)
    try {
      const result = await documentRequest<{ document: WebDocument }>(`/api/documents/from-record/${record.id}`, { method: 'POST', body: '{}' })
      router.visit(`/documents/${result.document.id}/edit`)
    } catch (error) { setPreviewError(error instanceof Error ? error.message : 'เปิดตัวแก้ไขขั้นสูงไม่สำเร็จ'); setAdvancedLoading(false) }
  }
  saveRef.current = () => { if (!form.processing && !printPreviewLoading) void save() }

  const tabTrigger = 'focus-ring flex min-h-10 items-center justify-center gap-1.5 whitespace-nowrap rounded-lg px-2 text-xs font-semibold text-muted-foreground data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow-sm'

  return <AppShell>
    <Head title={`จัดเอกสาร ${record.subject}`} />
    <form onSubmit={save} className="grid gap-4">
      <header className="sticky top-16 z-20 -mx-4 -mt-6 border-b bg-background/96 px-4 py-3 backdrop-blur-md sm:-mx-6 sm:-mt-8 sm:px-6 lg:-mx-8 lg:px-8">
        <div className="mx-auto flex max-w-[1440px] flex-col gap-3 lg:flex-row lg:items-center">
          <div className="flex min-w-0 items-center gap-3">
            <Button asChild variant="ghost" size="icon"><Link href={appUrl(returnUrl)} aria-label={draftContext ? 'กลับไปรีวิวฉบับร่าง' : 'กลับไปหน้ารายละเอียด'}><ArrowLeft size={20} /></Link></Button>
            <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-primary text-primary-foreground"><FileText size={21} weight="fill" /></span>
            <div className="min-w-0"><h1 className="truncate text-base font-bold sm:text-lg">จัดเอกสารก่อนพิมพ์</h1><p className="truncate text-xs text-muted-foreground">{record.subject} | สัปดาห์ {record.week} | {record.level}</p></div>
          </div>
          <div className="flex flex-wrap items-center gap-2 lg:ml-auto">
            <Badge variant={previewError ? 'outline' : 'secondary'} className={previewError ? 'border-destructive/30 text-destructive' : undefined}>{previewing ? 'กำลังอัปเดตตัวอย่าง' : previewError ? 'ตัวอย่างมีปัญหา' : form.isDirty ? 'มีการแก้ไขที่ยังไม่บันทึก' : 'บันทึกแล้ว'}</Badge>
            {!draftContext && <Button type="button" variant="outline" size="sm" disabled={advancedLoading || form.processing} onClick={openAdvancedEditor}><NotePencil size={17} />{advancedLoading ? 'กำลังเปิด…' : 'ตัวแก้ไขขั้นสูง / DOCX'}</Button>}
            <div className="flex items-center rounded-lg border bg-background p-0.5" aria-label="ประวัติการแก้ไข">
              <Button type="button" variant="ghost" size="icon" disabled={form.processing || printPreviewLoading || historyIndex <= 0} onClick={undo} aria-label="ย้อนกลับ"><ArrowCounterClockwise size={17} /></Button>
              <Button type="button" variant="ghost" size="icon" disabled={form.processing || printPreviewLoading || historyIndex >= historyRef.current.length - 1} onClick={redo} aria-label="ทำซ้ำ"><ArrowClockwise size={17} /></Button>
            </div>
            {hasSavedLayout && !draftContext && <Button type="button" variant="ghost" size="sm" disabled={form.processing || printPreviewLoading} onClick={restoreDefault}><Trash size={16} />คืนค่าเริ่มต้น</Button>}
            <Button type="submit" variant="outline" disabled={form.processing || printPreviewLoading || (contentConflicts.length > 0 && !form.data.content_resolution)}><FloppyDisk size={17} />บันทึก</Button>
            <Button type="button" disabled={form.processing || printPreviewLoading || previewing || (contentConflicts.length > 0 && !form.data.content_resolution)} onClick={() => save(undefined, true)}><Printer size={18} />{draftContext ? 'บันทึกและกลับรีวิว' : 'บันทึกและพิมพ์'}</Button>
          </div>
        </div>
      </header>

      <FlashMessages />
      {Object.keys(form.errors).length > 0 && <div className="flex items-start gap-2 rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive" role="alert"><WarningCircle size={20} className="mt-0.5 shrink-0" /><div><b>ยังบันทึกเอกสารไม่ได้</b><p className="mt-1">{form.errors['settings.document_rich_text'] || 'ตรวจค่าที่กรอกในแถบเครื่องมือ แล้วลองอีกครั้ง'}</p></div></div>}
      {contentConflicts.length > 0 && !form.data.content_resolution && <div className="grid gap-3 rounded-xl border border-amber-300/70 bg-amber-50 p-4 text-sm text-amber-950 sm:grid-cols-[1fr_auto] sm:items-center" role="alert">
        <div className="flex items-start gap-2"><WarningCircle size={20} className="mt-0.5 shrink-0" /><div><b>เนื้อหาเอกสารต่างจากข้อมูลหลัก</b><p className="mt-1 text-amber-900/80">พบ {contentConflicts.length} ช่องที่เคยแก้จากหน้าจัดเอกสาร กรุณาเลือกฉบับที่จะใช้ก่อนบันทึก ระบบจะไม่ทับข้อมูลให้อัตโนมัติ</p></div></div>
        <div className="flex flex-wrap gap-2"><Button type="button" size="sm" variant="outline" onClick={() => resolveContentConflict('record')}>ใช้ข้อมูลหลัก</Button><Button type="button" size="sm" onClick={() => resolveContentConflict('document')}>ใช้ฉบับเอกสาร</Button></div>
      </div>}
      {safeWarnings.length > 0 && <div className="flex items-start gap-2 rounded-xl border border-amber-300/70 bg-amber-50 p-4 text-sm text-amber-950" role="status"><WarningCircle size={20} className="mt-0.5 shrink-0" /><div><b>มีรูปอยู่นอกพื้นที่ปลอดภัย {safeWarnings.length} รูป</b><p className="mt-1 text-amber-900/80">วางรูปข้ามเส้นประได้ หากต้องการให้เห็นครบ ควรเก็บรูปไว้ภายในขอบกระดาษ A4</p></div></div>}

      <DocumentRibbon selection={textSelection} disabled={form.processing || printPreviewLoading || previewing || previewMode !== 'editor'} onCommand={formatText} onOpenTools={openTools} />

      <Tabs.Root value={toolsTab} onValueChange={setToolsTab} className="grid gap-3">
        <Button type="button" variant="outline" className="lg:hidden" aria-controls="document-tools" aria-expanded={toolsOpen} onClick={() => setToolsOpen(!toolsOpen)}><TextT size={18} />{toolsOpen ? 'ซ่อนเครื่องมือแก้ไข' : 'เปิดเครื่องมือแก้ไขข้อความและรูป'}</Button>


        <div className="grid min-w-0 gap-4 lg:grid-cols-[minmax(0,1fr)_300px] xl:grid-cols-[116px_minmax(0,1fr)_290px]">
          <nav aria-label="หน้าเอกสาร" className="order-1 hidden max-h-[calc(100dvh-370px)] space-y-3 overflow-y-auto rounded-xl border bg-muted/30 p-3 xl:block">
            <h2 className="pb-1 text-xs font-semibold">หน้าเอกสาร</h2>
            {Array.from({ length: pageGeometry.pageCount }, (_, index) => <button key={index} type="button" aria-label={`ไปหน้า ${index + 1}`} aria-current={currentPage === index ? 'page' : undefined} onClick={() => goToPage(index)} className={cn('focus-ring w-full rounded-lg border-2 p-1.5 text-left transition-colors', currentPage === index ? 'border-primary bg-primary/5' : 'border-transparent hover:bg-muted')}><span className="grid aspect-[210/297] gap-1 overflow-hidden rounded-sm border bg-white p-2 shadow-sm" aria-hidden="true"><span className="mx-auto mt-1 h-1 w-3/4 rounded bg-slate-400" /><span className="space-y-1">{Array.from({ length: index === 0 ? 9 : 6 }, (_, line) => <span key={line} className={cn('block h-px bg-slate-200', line % 3 === 0 && 'mr-3')} />)}</span>{index > 0 && imageCount > 0 && <span className="grid grid-cols-2 gap-1">{record.images?.slice(0, 4).map((image) => <span key={image.id} className="aspect-square rounded-sm bg-slate-100" />)}</span>}</span><span className="mt-1.5 block text-center text-[11px]">หน้า {index + 1}</span></button>)}
          </nav>
          <aside id="document-tools" inert={form.processing || printPreviewLoading} className={cn("order-1 h-fit overflow-hidden rounded-xl border bg-card lg:order-3 lg:sticky lg:top-[138px] lg:max-h-[calc(100dvh-370px)] lg:overflow-y-auto", !toolsOpen && "hidden lg:block")}>
        <Tabs.List className="grid grid-cols-3 gap-1 sticky top-0 z-10 rounded-none border-b bg-muted p-1">
          <Tabs.Trigger value="content" className={tabTrigger}><TextT size={18} />เนื้อหา</Tabs.Trigger>
          <Tabs.Trigger value="paper" className={tabTrigger}><FilePdf size={18} />กระดาษ</Tabs.Trigger>
          <Tabs.Trigger value="elements" className={tabTrigger}><ArrowsOutCardinal size={18} />จัดวาง</Tabs.Trigger>
        </Tabs.List>
            <Tabs.Content value="content" className="outline-none">
              <ToolSection title="ข้อมูลบนหัวเอกสาร">
                <FormField label="รายวิชา" error={form.errors['content.record_subject']}><Input value={form.data.content.record_subject || ''} onChange={(event) => setContent('record_subject', event.target.value)} /></FormField>
                <div className="grid grid-cols-2 gap-3"><FormField label="รหัสวิชา"><Input value={form.data.content.record_subject_code || ''} onChange={(event) => setContent('record_subject_code', event.target.value)} /></FormField><FormField label="รหัสกลุ่ม"><Input value={form.data.content.record_class_group_code || ''} onChange={(event) => setContent('record_class_group_code', event.target.value)} /></FormField></div>
                <FormField label="ชื่อกลุ่มเรียน"><Input value={form.data.content.record_class_name || ''} onChange={(event) => setContent('record_class_name', event.target.value)} /></FormField>
              </ToolSection>
              <ToolSection title="ข้อความในบันทึก">
                <p className="text-xs leading-5 text-muted-foreground">แก้ในช่องด้านล่าง หรือคลิกข้อความบนกระดาษ Preview</p>
                <FormField label="ผลการจัดการเรียนรู้"><Textarea rows={5} value={form.data.content.record_result || ''} onChange={(event) => setContent('record_result', event.target.value)} /></FormField>
                <FormField label="ปัญหาและอุปสรรค"><Textarea rows={4} value={form.data.content.record_problem || ''} onChange={(event) => setContent('record_problem', event.target.value)} /></FormField>
                <FormField label="ข้อเสนอแนะ"><Textarea rows={4} value={form.data.content.record_suggestion || ''} onChange={(event) => setContent('record_suggestion', event.target.value)} /></FormField>
                <FormField label="ความคิดเห็นหัวหน้างาน"><Textarea rows={3} value={form.data.content.record_supervisor_comment || ''} onChange={(event) => setContent('record_supervisor_comment', event.target.value)} /></FormField>
              </ToolSection>
            </Tabs.Content>

            <Tabs.Content value="paper" className="outline-none">
              <ToolSection title="ขนาดและแนวกระดาษ">
                <div className="grid grid-cols-2 gap-3"><FormField label="ขนาดกระดาษ"><NativeSelect value={form.data.settings.paper_size} onChange={(event) => setSetting('paper_size', event.target.value)}><option value="A4">A4</option></NativeSelect></FormField><FormField label="แนวกระดาษ"><NativeSelect value={form.data.settings.paper_orientation} onChange={(event) => setSetting('paper_orientation', event.target.value)}><option value="portrait">แนวตั้ง</option><option value="landscape">แนวนอน</option></NativeSelect></FormField></div>
              </ToolSection>
              <ToolSection title="ระยะขอบกระดาษ">
                <div className="grid grid-cols-3 gap-2">{marginPresets.map((preset) => <Button key={preset.label} type="button" size="sm" variant="outline" onClick={() => applyMargins(preset)}>{preset.label}</Button>)}</div>
                <div className="grid grid-cols-2 gap-3"><FormField label="บน"><Input type="number" min={20} max={140} value={form.data.settings.pdf_margin_top_px} onChange={(event) => setSetting('pdf_margin_top_px', event.target.value)} /></FormField><FormField label="ขวา"><Input type="number" min={20} max={160} value={form.data.settings.pdf_margin_right_px} onChange={(event) => setSetting('pdf_margin_right_px', event.target.value)} /></FormField><FormField label="ล่าง"><Input type="number" min={20} max={140} value={form.data.settings.pdf_margin_bottom_px} onChange={(event) => setSetting('pdf_margin_bottom_px', event.target.value)} /></FormField><FormField label="ซ้าย"><Input type="number" min={20} max={160} value={form.data.settings.pdf_margin_left_px} onChange={(event) => setSetting('pdf_margin_left_px', event.target.value)} /></FormField></div>
              </ToolSection>
              <ToolSection title="ตัวอักษรและบรรทัด">
                <div className="grid grid-cols-2 gap-2">
                  <Button type="button" size="sm" variant={form.data.settings.pdf_font_size_pt === '16' && form.data.settings.pdf_line_spacing === '26' ? 'secondary' : 'outline'} onClick={() => applyTextDensityPreset('16', '26')}>มาตรฐาน (16 pt)</Button>
                  <Button type="button" size="sm" variant={form.data.settings.pdf_font_size_pt === '14' && form.data.settings.pdf_line_spacing === '24' ? 'secondary' : 'outline'} onClick={() => applyTextDensityPreset('14', '24')}>กะทัดรัด (14 pt)</Button>
                </div>
                <div className="grid grid-cols-2 gap-3"><FormField label="ขนาดตัวอักษร"><Input type="number" min={10} max={20} value={form.data.settings.pdf_font_size_pt} onChange={(event) => setSetting('pdf_font_size_pt', event.target.value)} /></FormField><FormField label="ระยะบรรทัด"><Input type="number" min={20} max={40} value={form.data.settings.pdf_line_spacing} onChange={(event) => setSetting('pdf_line_spacing', event.target.value)} /></FormField></div>
                <div className="grid grid-cols-2 gap-3"><FormField label="ระยะหลังย่อหน้า (px)"><Input type="number" min={0} max={80} value={form.data.settings.record_paragraph_spacing_px} onChange={(event) => setSetting('record_paragraph_spacing_px', event.target.value)} /></FormField><FormField label="ย่อบรรทัดแรก (px)"><Input type="number" min={0} max={160} value={form.data.settings.record_text_indent_px} onChange={(event) => setSetting('record_text_indent_px', event.target.value)} /></FormField></div>
                <FormField label="จัดแนวข้อความ"><NativeSelect value={form.data.settings.record_text_align} onChange={(event) => setSetting('record_text_align', event.target.value)}><option value="left">ชิดซ้าย</option><option value="justify">กระจายเต็มบรรทัด</option><option value="center">กึ่งกลาง</option><option value="right">ชิดขวา</option></NativeSelect></FormField>
                <p className="text-xs text-muted-foreground">หากเนื้อหาบันทึกยาวจนลายเซ็นตกไปหน้า 2 ให้เลือก &ldquo;กะทัดรัด&rdquo; เพื่อให้ข้อความกระชับและจบในหน้าแรก</p>
              </ToolSection>
              <ToolSection title="หน้าที่ต้องการพิมพ์">
                <PageToggle checked={form.data.settings.show_image_page === '1'} icon={<ImageSquare size={19} />} label="หน้ารูปภาพ" description={`${imageCount} รูปในบันทึกนี้`} onCheckedChange={(checked) => setSetting('show_image_page', checked ? '1' : '0')} />
                <PageToggle checked={form.data.settings.show_attendance_page === '1'} icon={<ListChecks size={19} />} label="หน้ารายชื่อ" description={`${attendanceCount} คนที่มาเรียน`} onCheckedChange={(checked) => setSetting('show_attendance_page', checked ? '1' : '0')} />
              </ToolSection>
            </Tabs.Content>

            <Tabs.Content value="elements" className="outline-none">
              <ToolSection title="เลือกองค์ประกอบ">
                <div className="grid grid-cols-2 gap-2">{(Object.entries(layoutTargets) as [LayoutTarget, typeof layoutTargets[LayoutTarget]][]).map(([key, item]) => {
                  const imageNumber = key.startsWith('image') ? Number(key.replace('image', '')) : 0
                  const unavailable = imageNumber > imageCount
                  return <button key={key} type="button" disabled={unavailable} onClick={() => { setTarget(key); postSelection(key) }} className={cn('focus-ring rounded-lg border px-3 py-2 text-left text-sm font-semibold transition-colors', target === key ? 'border-primary bg-primary/8 text-primary' : 'hover:bg-muted', unavailable && 'cursor-not-allowed opacity-40')}>{item.label}</button>
                })}</div>
              </ToolSection>
              {imageCount > 1 && <ToolSection title="จัดรูปทั้งหน้า"><Button type="button" variant="outline" onClick={arrangeImages}><ImageSquare size={18} />จัดรูปไม่ให้ซ้อนกัน</Button><p className="text-xs leading-5 text-muted-foreground">จัดรูปทั้งหมดเป็นสองคอลัมน์ตามพื้นที่กระดาษ แล้วลากปรับต่อได้ กดย้อนกลับเพื่อคืนแบบเดิม</p></ToolSection>}
              <ToolSection title={`ตำแหน่ง ${position.label}`}>
                <p className="text-xs leading-5 text-muted-foreground">ลากกรอบบน Preview หรือใช้ลูกศรเพื่อขยับอย่างละเอียด ตำแหน่งรูปวัดจากขอบซ้ายและขอบบนของพื้นที่รูป</p>
                {position.kind === 'signature' && <div className="grid gap-3">
                  <FormField label="ระยะก่อนลายเซ็น (px)"><Input type="number" min={0} max={240} value={form.data.settings[`${target}_mt`]} onChange={(event) => setSetting(`${target}_mt`, event.target.value)} /></FormField>
                  {target !== 'sig1' && <FormField label="ระยะก่อนส่วนความคิดเห็น (px)"><Input type="number" min={-120} max={240} value={form.data.settings[`${target}_block_offset_y`]} onChange={(event) => setSetting(`${target}_block_offset_y`, event.target.value)} /></FormField>}
                  <p className="text-xs leading-5 text-muted-foreground">ปรับระยะก่อนลายเซ็นเพื่อลดช่องว่าง ส่วนถัดไปจะเลื่อนตาม ใช้จุดจับ “ย้ายลายเซ็น” เมื่อต้องการลาก และคลิกชื่อหรือตำแหน่งเพื่อพิมพ์แก้ไข</p>
                </div>}
                <div className="grid grid-cols-[1fr_auto_1fr] items-center gap-2"><span /><Button type="button" variant="outline" size="icon" onClick={() => nudge(position.y, -step)} aria-label="เลื่อนขึ้น"><ArrowUp size={18} /></Button><span /><Button type="button" variant="outline" size="icon" onClick={() => nudge(position.x, -step)} aria-label="เลื่อนซ้าย"><ArrowLeft size={18} /></Button><Button type="button" variant="ghost" size="sm" onClick={resetTarget}>คืนตำแหน่ง</Button><Button type="button" variant="outline" size="icon" onClick={() => nudge(position.x, step)} aria-label="เลื่อนขวา"><ArrowRight size={18} /></Button><span /><Button type="button" variant="outline" size="icon" onClick={() => nudge(position.y, step)} aria-label="เลื่อนลง"><ArrowDown size={18} /></Button><span /></div>
                <div className="grid grid-cols-2 gap-3"><FormField label="จากซ้าย"><Input type="number" min={position.kind === 'image' ? -2400 : -400} max={position.kind === 'image' ? 2400 : 400} value={form.data.settings[position.x]} onChange={(event) => setSetting(position.x, event.target.value)} /></FormField><FormField label="จากบน"><Input type="number" min={position.kind === 'image' ? -2400 : -400} max={position.kind === 'image' ? 2400 : 400} value={form.data.settings[position.y]} onChange={(event) => setSetting(position.y, event.target.value)} /></FormField></div>
                <FormField label="ระยะขยับต่อครั้ง"><NativeSelect value={step} onChange={(event) => setStep(Number(event.target.value))}><option value={1}>1 พิกเซล</option><option value={5}>5 พิกเซล</option><option value={10}>10 พิกเซล</option></NativeSelect></FormField>
                {position.kind === 'signature' && <FormField label="การจัดแนว"><NativeSelect value={form.data.settings[position.align]} onChange={(event) => setSetting(position.align, event.target.value)}><option value="flex-start">ชิดซ้าย</option><option value="center">กึ่งกลาง</option><option value="flex-end">ชิดขวา</option></NativeSelect></FormField>}
                {position.kind === 'image' && <div className="grid gap-3">
                  <div className="grid grid-cols-3 gap-2" aria-label="ขนาดรูปสำเร็จรูป">{imagePresets.map((preset) => <Button key={preset.label} type="button" size="sm" variant="outline" onClick={() => applyImagePreset(preset.width, preset.height)}>{preset.label}</Button>)}</div>
                  <div className="grid grid-cols-2 gap-2"><Button type="button" variant="outline" size="sm" onClick={freeResize}>ยืด / บีบอิสระ</Button><Button type="button" variant="outline" size="sm" onClick={fitOriginalImage}>พอดีรูปต้นฉบับ</Button><Button type="button" variant="outline" size="sm" onClick={() => scaleImage(0.9)}><Minus size={16} />ย่อ 10%</Button><Button type="button" variant="outline" size="sm" onClick={() => scaleImage(1.1)}><Plus size={16} />ขยาย 10%</Button></div>
                  <div className="grid grid-cols-2 gap-3"><FormField label="ความกว้าง"><Input type="number" min={10} max={2400} value={form.data.settings[position.width]} onChange={(event) => resizeImage('width', event.target.value)} /></FormField><FormField label="ความสูง"><Input type="number" min={10} max={2400} value={form.data.settings[position.height]} onChange={(event) => resizeImage('height', event.target.value)} /></FormField></div>
                  <label className="flex cursor-pointer items-center justify-between gap-3 rounded-lg border bg-background px-3 py-2.5">
                    <span><b className="block text-sm">ล็อกสัดส่วน</b><small className="block text-muted-foreground">ปิดเพื่อยืดหรือบีบความกว้างและความสูงแยกกัน</small></span>
                    <Switch checked={form.data.settings[position.lock] === '1'} onCheckedChange={(checked) => { const changes = { [position.lock]: checked ? '1' : '0', [position.fit]: checked ? 'contain' : 'fill' }; commitDocument({ settings: { ...form.data.settings, ...changes }, content: form.data.content }, changes) }} aria-label="ล็อกสัดส่วนรูป" />
                  </label>
                  <FormField label="การวางรูปในกรอบ"><NativeSelect value={form.data.settings[position.fit]} onChange={(event) => setSetting(position.fit, event.target.value)}><option value="fill">ยืด / บีบภาพตามกรอบ</option><option value="cover">ครอบเต็มกรอบ</option><option value="contain">แสดงครบทั้งรูป</option></NativeSelect></FormField>
                  {form.data.settings[position.fit] === 'cover' && <div className="grid gap-3 rounded-lg border p-3"><p className="text-xs text-muted-foreground">เลื่อนจุดโฟกัสเพื่อเลือกส่วนภาพที่ต้องการเก็บไว้ในกรอบ</p>{(['x', 'y'] as const).map((axis) => {
                    const key = `image_${target.replace('image', '')}_crop_${axis}`
                    return <FormField key={axis} label={axis === 'x' ? 'ครอบภาพ ซ้าย - ขวา' : 'ครอบภาพ บน - ล่าง'}><input aria-label={axis === 'x' ? 'จุดโฟกัสภาพแนวนอน' : 'จุดโฟกัสภาพแนวตั้ง'} type="range" min="0" max="100" value={form.data.settings[key] || '50'} onChange={(event) => setSetting(key, event.target.value)} className="w-full accent-primary" /></FormField>
                  })}</div>}
                  <div className="grid grid-cols-2 gap-2"><Button type="button" variant="outline" size="sm" onClick={() => rotateImage(-90)}><ArrowCounterClockwise size={17} />หมุนซ้าย</Button><Button type="button" variant="outline" size="sm" onClick={() => rotateImage(90)}><ArrowClockwise size={17} />หมุนขวา</Button></div>
                  <div className="rounded-lg bg-muted/60 p-3 text-xs leading-5 text-muted-foreground"><b className="text-foreground">จุดจับ 8 ทิศบน Preview</b><br />ลากด้านหรือมุมเพื่อปรับขนาด · เลือกยืด / บีบอิสระเพื่อปรับสองด้านแยกกัน · ลากข้ามเส้นประได้ · Shift สลับล็อกสัดส่วนชั่วคราว</div>
                </div>}
              </ToolSection>
              {position.kind === 'signature' && <ToolSection title="ข้อความลายเซ็น">
                <FormField label="ชื่อ"><Input value={form.data.settings[`${target}_name`] || ''} onChange={(event) => setSetting(`${target}_name`, event.target.value)} /></FormField>
                <FormField label="ตำแหน่ง"><Textarea rows={2} value={form.data.settings[`${target}_pos`] || ''} onChange={(event) => setSetting(`${target}_pos`, event.target.value)} /></FormField>
              </ToolSection>}
            </Tabs.Content>
          </aside>

          <section className="order-2 h-fit min-w-0 overflow-hidden rounded-xl border bg-card">
            <header className="flex flex-col gap-3 border-b p-3 sm:flex-row sm:items-center sm:justify-between">
              <div><h2 className="flex items-center gap-2 text-sm font-semibold"><NotePencil size={18} className="text-primary" />{previewMode === 'editor' ? 'แก้ไขบนกระดาษ' : 'ตรวจหน้าพิมพ์ / PDF'}</h2><p className="mt-1 text-xs text-muted-foreground">{previewMode === 'editor' ? 'คลิกข้อความเพื่อพิมพ์ · Enter ขึ้นบรรทัด · ลากรูปเพื่อจัดวาง' : 'ใช้หน้ากระดาษที่จัดวางล่าสุด · เลือกพิมพ์หรือ Save as PDF'}</p></div>
              <div className="flex flex-wrap items-center gap-1">
                <div className="flex rounded-lg bg-muted p-1"><Button type="button" variant={previewMode === 'editor' ? 'secondary' : 'ghost'} size="sm" onClick={() => setPreviewMode('editor')}><NotePencil size={16} />จัดวาง</Button><Button type="button" variant={previewMode === 'print' ? 'secondary' : 'ghost'} size="sm" disabled={printPreviewLoading || previewing} onClick={loadPrintPreview}><FilePdf size={16} />{printPreviewLoading ? 'กำลังสร้าง' : 'ตรวจหน้าพิมพ์'}</Button></div>
                {previewMode === 'editor' && <><Button type="button" variant="ghost" size="icon" onClick={() => setZoom(Math.max(0.4, zoom - 0.08))} aria-label="ซูมออก"><Minus size={17} /></Button><button type="button" aria-label="คืนค่าซูม 72 เปอร์เซ็นต์" className="focus-ring min-w-14 rounded-md px-2 py-1 text-xs font-semibold" onClick={() => setZoom(0.72)}>{Math.round(zoom * 100)}%</button><Button type="button" variant="ghost" size="icon" onClick={() => setZoom(Math.min(1.15, zoom + 0.08))} aria-label="ซูมเข้า"><Plus size={17} /></Button></>}
              </div>
            </header>
            {previewError && <div className="m-4 flex items-start gap-2 rounded-xl border border-destructive/25 bg-destructive/5 p-4 text-sm text-destructive"><WarningCircle size={19} className="mt-0.5 shrink-0" />{previewError}</div>}
            {previewMode === 'print' && printPreviewStale && printPreviewUrl && <div className="m-4 flex items-center justify-between gap-3 rounded-xl border border-amber-300/70 bg-amber-50 p-3 text-sm text-amber-950"><span>เอกสารถูกแก้ไขแล้ว กรุณาอัปเดตหน้าพิมพ์</span><Button type="button" size="sm" variant="outline" disabled={printPreviewLoading || previewing} onClick={loadPrintPreview}>อัปเดตตัวอย่าง</Button></div>}
            <div ref={canvasRef} onScroll={(event) => { const top = event.currentTarget.scrollTop / zoom; const pages = pageGeometry.pages || []; const index = pages.findIndex((page, i) => top < (pages[i + 1]?.top ?? Infinity) - 30); if (index >= 0) setCurrentPage(index) }} className="relative h-[calc(100dvh-460px)] min-h-[480px] overflow-auto bg-muted/55 p-3">
              {previewMode === 'editor' && previewing && <div className="sticky left-4 right-4 top-0 z-10 h-1.5 overflow-hidden rounded-full bg-muted"><span className="block h-full w-1/2 animate-pulse rounded-full bg-primary" /></div>}
              {preview && <div inert={form.processing || printPreviewLoading} hidden={previewMode !== 'editor'} className="mx-auto" style={{ width: `${pageGeometry.pageWidth * zoom}px`, height: `${pageGeometry.documentHeight * zoom}px` }}><iframe ref={iframeRef} title="ตัวจัดเอกสารก่อนพิมพ์" srcDoc={preview} sandbox="allow-scripts" onLoad={() => { setTextSelection(null); postLocalPatch(Object.fromEntries(Object.entries(latestDocumentRef.current.settings).filter(([key]) => localPreviewKey.test(key)))); postSelection(target) }} className="origin-top-left border-0 bg-background shadow-[0_18px_50px_rgba(20,58,55,0.14)]" style={{ width: `${pageGeometry.pageWidth + 4}px`, height: `${pageGeometry.documentHeight}px`, transform: `scale(${zoom})` }} /></div>}
              {previewMode === 'print' && printPreviewUrl && <iframe ref={printFrameRef} title="หน้าพิมพ์และบันทึก PDF" sandbox="allow-scripts allow-modals allow-top-navigation-by-user-activation" src={printPreviewUrl} className="h-full min-h-[650px] w-full rounded-lg border-0 bg-background shadow-[0_18px_50px_rgba(20,58,55,0.14)]" />}
              {previewMode === 'print' && !printPreviewUrl && <div className="grid min-h-[620px] place-items-center text-center"><div><FilePdf size={44} className="mx-auto text-muted-foreground" /><p className="mt-3 text-sm font-semibold">ยังไม่ได้เตรียมหน้าพิมพ์</p><Button type="button" className="mt-3" disabled={printPreviewLoading || previewing} onClick={loadPrintPreview}>เตรียมหน้าพิมพ์จากกระดาษปัจจุบัน</Button></div></div>}
            </div>
            <footer className="flex flex-wrap items-center gap-x-4 gap-y-1 border-t px-4 py-2 text-[11px] text-muted-foreground"><span>หน้า {Math.min(currentPage + 1, pageGeometry.pageCount)} จาก {pageGeometry.pageCount}</span><span>{Object.values(form.data.content).join('').length.toLocaleString('th-TH')} ตัวอักษร</span><span>ภาษาไทย</span><span className="ml-auto">A4 · {form.data.settings.paper_orientation === 'landscape' ? 'แนวนอน' : 'แนวตั้ง'} · {Math.round(zoom * 100)}%</span></footer>
          </section>
        </div>
      </Tabs.Root>
    </form>
  </AppShell>
}
