import { Head, Link, router, useForm } from '@inertiajs/react'
import * as Tabs from '@radix-ui/react-tabs'
import {
  ArrowClockwise, ArrowCounterClockwise, ArrowDown, ArrowLeft, ArrowRight, ArrowUp, ArrowsOutCardinal, FilePdf, FileText,
  FloppyDisk, ImageSquare, ListChecks, Minus, NotePencil, Plus, Printer, TextT,
  Trash, WarningCircle,
} from '@phosphor-icons/react'
import { FormEvent, useEffect, useMemo, useRef, useState } from 'react'
import { AppShell } from '@/components/app-shell'
import { FlashMessages } from '@/components/flash-messages'
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

type PageGeometry = { pageWidth: number; documentHeight: number; pageCount: number }

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

const localPreviewKey = /^(sig[123]_offset(_y)?|image_[1-4]_(x_px|y_px|width_px|height_px|object_fit|rotation_deg|lock_aspect|crop_x|crop_y))$/

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
  const [step, setStep] = useState(5)
  const [zoom, setZoom] = useState(0.72)
  const [preview, setPreview] = useState('')
  const [previewing, setPreviewing] = useState(true)
  const [previewError, setPreviewError] = useState<string | null>(null)
  const [safeWarnings, setSafeWarnings] = useState<string[]>([])
  const [actualPdfUrl, setActualPdfUrl] = useState<string | null>(null)
  const [actualPdfLoading, setActualPdfLoading] = useState(false)
  const [actualPdfStale, setActualPdfStale] = useState(true)
  const [previewMode, setPreviewMode] = useState<'editor' | 'pdf'>('editor')
  const [historyIndex, setHistoryIndex] = useState(0)
  const [pageGeometry, setPageGeometry] = useState<PageGeometry>(() => ({ pageWidth: settings.paper_orientation === 'landscape' ? 1123 : 794, documentHeight: 1220, pageCount: 1 }))
  const iframeRef = useRef<HTMLIFrameElement>(null)
  const historyRef = useRef<DocumentState[]>([{ settings, content }])
  const historyIndexRef = useRef(0)
  const latestDocumentRef = useRef<DocumentState>({ settings, content })
  const position = layoutTargets[target]
  const imageCount = record.images?.length || 0
  const attendanceCount = record.attendance?.filter((student) => student.present).length || 0
  const remotePreviewKey = useMemo(() => JSON.stringify({
    settings: Object.fromEntries(Object.entries(form.data.settings).filter(([key]) => !localPreviewKey.test(key))),
    content: form.data.content,
  }), [form.data.content, form.data.settings])

  const postLocalPatch = (changes: Record<string, string>) => iframeRef.current?.contentWindow?.postMessage({ source: 'sena-pdf-layout-patch', changes }, '*')
  const commitDocument = (next: DocumentState, localChanges: Record<string, string> = {}, resolution?: 'document' | 'record') => {
    if (JSON.stringify(next) === JSON.stringify(latestDocumentRef.current)) return
    const nextHistory = historyRef.current.slice(0, historyIndexRef.current + 1)
    nextHistory.push(next)
    historyRef.current = nextHistory.slice(-100)
    const nextIndex = historyRef.current.length - 1
    historyIndexRef.current = nextIndex
    setHistoryIndex(nextIndex)
    latestDocumentRef.current = next
    form.setData((current) => ({ ...current, ...next, ...(resolution ? { content_resolution: resolution } : {}) }))
    setActualPdfStale(true)
    if (Object.keys(localChanges).length) postLocalPatch(localChanges)
  }
  const setSetting = (key: string, value: string) => {
    const nextSettings = { ...form.data.settings, [key]: value }
    commitDocument({ settings: nextSettings, content: form.data.content }, localPreviewKey.test(key) ? { [key]: value } : {})
  }
  const setContent = (key: string, value: string) => commitDocument({ settings: form.data.settings, content: { ...form.data.content, [key]: value } }, {}, 'document')
  const restoreHistory = (index: number) => {
    const snapshot = historyRef.current[index]
    if (!snapshot) return
    latestDocumentRef.current = snapshot
    historyIndexRef.current = index
    setHistoryIndex(index)
    form.setData((current) => ({ ...current, ...snapshot }))
    setActualPdfStale(true)
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
    const controller = new AbortController()
    const timer = window.setTimeout(async () => {
      setPreviewing(true)
      setPreviewError(null)
      try {
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || ''
        const response = await fetch(`${documentBase}/preview`, {
          method: 'POST',
          signal: controller.signal,
          headers: { 'Content-Type': 'application/json', Accept: 'text/html, application/json', 'X-CSRF-TOKEN': token },
          body: JSON.stringify({ ...form.data, ...latestDocumentRef.current, ...(draftContext ? { lock_version: draftContext.lockVersion } : {}) }),
        })
        if (!response.ok) {
          const payload = await response.json().catch(() => null)
          throw new Error(payload?.message || 'สร้างตัวอย่างเอกสารไม่สำเร็จ')
        }
        setPreview(await response.text())
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
      if (event.data?.source === 'sena-pdf-geometry') {
        const geometry = event.data as Partial<PageGeometry> & { safeWarnings?: unknown }
        if (Number.isFinite(geometry.pageWidth) && Number.isFinite(geometry.documentHeight) && Number.isFinite(geometry.pageCount)) {
          setPageGeometry({ pageWidth: Number(geometry.pageWidth), documentHeight: Number(geometry.documentHeight), pageCount: Number(geometry.pageCount) })
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
      if (Object.keys(settingChanges).length || Object.keys(textSettingChanges).length || Object.keys(contentChanges).length) {
        commitDocument({
          settings: { ...current.settings, ...settingChanges, ...textSettingChanges },
          content: { ...current.content, ...contentChanges },
        })
      }
    }
    window.addEventListener('message', receiveLayoutChange)
    return () => window.removeEventListener('message', receiveLayoutChange)
  }, [record.images])

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      const element = event.target as HTMLElement | null
      if (element?.matches('input, textarea, select, [contenteditable="true"]')) return
      const modifier = event.metaKey || event.ctrlKey
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
  }, [historyIndex])

  useEffect(() => () => {
    if (actualPdfUrl) URL.revokeObjectURL(actualPdfUrl)
  }, [actualPdfUrl])

  useEffect(() => {
    if (!form.isDirty) return
    const warnBeforeLeaving = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', warnBeforeLeaving)
    return () => window.removeEventListener('beforeunload', warnBeforeLeaving)
  }, [form.isDirty])

  const loadActualPdf = async () => {
    const requestedDocument = JSON.stringify(latestDocumentRef.current)
    setActualPdfLoading(true)
    setPreviewError(null)
    try {
      const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || ''
      const response = await fetch(`${documentBase}/pdf-preview`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/pdf, application/json', 'X-CSRF-TOKEN': token },
        body: JSON.stringify({ ...form.data, ...latestDocumentRef.current, ...(draftContext ? { lock_version: draftContext.lockVersion } : {}) }),
      })
      if (!response.ok) {
        const payload = await response.json().catch(() => null)
        throw new Error(payload?.message || 'สร้าง PDF ตัวอย่างไม่สำเร็จ')
      }
      const type = response.headers.get('content-type') || ''
      if (!type.includes('application/pdf')) throw new Error('เซิร์ฟเวอร์ไม่ได้ส่งไฟล์ PDF กลับมา')
      const nextUrl = URL.createObjectURL(await response.blob())
      setActualPdfUrl((current) => {
        if (current) URL.revokeObjectURL(current)
        return nextUrl
      })
      setActualPdfStale(requestedDocument !== JSON.stringify(latestDocumentRef.current))
      setPreviewMode('pdf')
    } catch (error) {
      setPreviewError(error instanceof Error ? error.message : 'สร้าง PDF ตัวอย่างไม่สำเร็จ')
    } finally {
      setActualPdfLoading(false)
    }
  }

  const resolveContentConflict = (resolution: 'document' | 'record') => {
    if (resolution === 'record') {
      commitDocument({ settings: form.data.settings, content: canonicalContent }, {}, 'record')
      return
    }
    form.setData('content_resolution', 'document')
  }

  const save = (event?: FormEvent, thenPrint = false) => {
    event?.preventDefault()
    if (contentConflicts.length && !form.data.content_resolution) {
      form.setError('content_resolution', 'เลือกว่าจะใช้เนื้อหาจากเอกสารหรือข้อมูลหลักก่อนบันทึก')
      return
    }
    const popup = thenPrint && !draftContext ? window.open('', '_blank') : null
    if (popup) popup.document.write('<p style="font-family:sans-serif;padding:24px">กำลังสร้าง PDF...</p>')
    form.transform((data) => ({ ...data, ...(draftContext ? { lock_version: draftContext.lockVersion } : {}) }))
    form.put(documentBase, {
      preserveScroll: true,
      onSuccess: () => {
        form.setDefaults()
        if (thenPrint) {
          if (draftContext) { router.visit(returnUrl); return }
          if (popup) popup.location.href = `/records/${record.id}/print`
          else window.location.href = `/records/${record.id}/print`
        }
      },
      onError: () => popup?.close(),
    })
  }

  const restoreDefault = () => {
    if (window.confirm('คืนรูปแบบเอกสารครั้งนี้เป็นค่าเริ่มต้นของระบบหรือไม่')) {
      router.delete(documentBase, { preserveScroll: true })
    }
  }

  const tabTrigger = 'focus-ring flex min-h-10 items-center justify-center gap-2 rounded-lg px-3 text-sm font-semibold text-muted-foreground data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow-sm'

  return <AppShell>
    <Head title={`จัดเอกสาร ${record.subject}`} />
    <form onSubmit={save} className="grid gap-4">
      <header className="sticky top-16 z-20 -mx-4 -mt-6 border-b bg-background/96 px-4 py-3 backdrop-blur-md sm:-mx-6 sm:-mt-8 sm:px-6 lg:-mx-8 lg:px-8">
        <div className="mx-auto flex max-w-[1440px] flex-col gap-3 xl:flex-row xl:items-center">
          <div className="flex min-w-0 items-center gap-3">
            <Button asChild variant="ghost" size="icon"><Link href={returnUrl} aria-label={draftContext ? 'กลับไปรีวิวฉบับร่าง' : 'กลับไปหน้ารายละเอียด'}><ArrowLeft size={20} /></Link></Button>
            <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-primary text-primary-foreground"><FileText size={21} weight="fill" /></span>
            <div className="min-w-0"><h1 className="truncate text-base font-bold sm:text-lg">จัดเอกสารก่อนพิมพ์</h1><p className="truncate text-xs text-muted-foreground">{record.subject} | สัปดาห์ {record.week} | {record.level}</p></div>
          </div>
          <div className="flex flex-wrap items-center gap-2 xl:ml-auto">
            <Badge variant={previewError ? 'outline' : 'secondary'} className={previewError ? 'border-destructive/30 text-destructive' : undefined}>{previewing ? 'กำลังอัปเดตตัวอย่าง' : previewError ? 'ตัวอย่างมีปัญหา' : form.isDirty ? 'มีการแก้ไขที่ยังไม่บันทึก' : 'บันทึกแล้ว'}</Badge>
            <div className="flex items-center rounded-lg border bg-background p-0.5" aria-label="ประวัติการแก้ไข">
              <Button type="button" variant="ghost" size="icon" disabled={historyIndex <= 0} onClick={undo} aria-label="ย้อนกลับ"><ArrowCounterClockwise size={17} /></Button>
              <Button type="button" variant="ghost" size="icon" disabled={historyIndex >= historyRef.current.length - 1} onClick={redo} aria-label="ทำซ้ำ"><ArrowClockwise size={17} /></Button>
            </div>
            {hasSavedLayout && !draftContext && <Button type="button" variant="ghost" size="sm" onClick={restoreDefault}><Trash size={16} />คืนค่าเริ่มต้น</Button>}
            <Button type="submit" variant="outline" disabled={form.processing || (contentConflicts.length > 0 && !form.data.content_resolution)}><FloppyDisk size={17} />บันทึก</Button>
            <Button type="button" disabled={form.processing || (contentConflicts.length > 0 && !form.data.content_resolution)} onClick={() => save(undefined, true)}><Printer size={18} />{draftContext ? 'บันทึกและกลับรีวิว' : 'บันทึกและพิมพ์'}</Button>
          </div>
        </div>
      </header>

      <FlashMessages />
      {Object.keys(form.errors).length > 0 && <div className="flex items-start gap-2 rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive" role="alert"><WarningCircle size={20} className="mt-0.5 shrink-0" /><div><b>ยังบันทึกเอกสารไม่ได้</b><p className="mt-1">ตรวจค่าที่กรอกในแถบเครื่องมือ แล้วลองอีกครั้ง</p></div></div>}
      {contentConflicts.length > 0 && !form.data.content_resolution && <div className="grid gap-3 rounded-xl border border-amber-300/70 bg-amber-50 p-4 text-sm text-amber-950 sm:grid-cols-[1fr_auto] sm:items-center" role="alert">
        <div className="flex items-start gap-2"><WarningCircle size={20} className="mt-0.5 shrink-0" /><div><b>เนื้อหาเอกสารต่างจากข้อมูลหลัก</b><p className="mt-1 text-amber-900/80">พบ {contentConflicts.length} ช่องที่เคยแก้จากหน้าจัดเอกสาร กรุณาเลือกฉบับที่จะใช้ก่อนบันทึก ระบบจะไม่ทับข้อมูลให้อัตโนมัติ</p></div></div>
        <div className="flex flex-wrap gap-2"><Button type="button" size="sm" variant="outline" onClick={() => resolveContentConflict('record')}>ใช้ข้อมูลหลัก</Button><Button type="button" size="sm" onClick={() => resolveContentConflict('document')}>ใช้ฉบับเอกสาร</Button></div>
      </div>}
      {safeWarnings.length > 0 && <div className="flex items-start gap-2 rounded-xl border border-amber-300/70 bg-amber-50 p-4 text-sm text-amber-950" role="status"><WarningCircle size={20} className="mt-0.5 shrink-0" /><div><b>มีรูปอยู่นอกพื้นที่ปลอดภัย {safeWarnings.length} รูป</b><p className="mt-1 text-amber-900/80">ขยับรูปให้พ้นเส้นประด้านใน เพื่อป้องกันขอบรูปถูกตัดตอนพิมพ์</p></div></div>}

      <Tabs.Root defaultValue="content" className="grid gap-4">
        <Tabs.List className="grid grid-cols-3 gap-1 rounded-xl bg-muted p-1.5 lg:max-w-xl">
          <Tabs.Trigger value="content" className={tabTrigger}><TextT size={18} />เนื้อหา</Tabs.Trigger>
          <Tabs.Trigger value="paper" className={tabTrigger}><FilePdf size={18} />กระดาษ</Tabs.Trigger>
          <Tabs.Trigger value="elements" className={tabTrigger}><ArrowsOutCardinal size={18} />องค์ประกอบ</Tabs.Trigger>
        </Tabs.List>

        <div className="grid min-w-0 gap-4 xl:grid-cols-[390px_minmax(0,1fr)]">
          <aside className="h-fit overflow-hidden rounded-xl border bg-card xl:sticky xl:top-[138px] xl:max-h-[calc(100dvh-154px)] xl:overflow-y-auto">
            <Tabs.Content value="content" className="outline-none">
              <ToolSection title="ข้อมูลบนหัวเอกสาร">
                <FormField label="รายวิชา" error={form.errors['content.record_subject']}><Input value={form.data.content.record_subject || ''} onChange={(event) => setContent('record_subject', event.target.value)} /></FormField>
                <div className="grid grid-cols-2 gap-3"><FormField label="รหัสวิชา"><Input value={form.data.content.record_subject_code || ''} onChange={(event) => setContent('record_subject_code', event.target.value)} /></FormField><FormField label="รหัสกลุ่ม"><Input value={form.data.content.record_class_group_code || ''} onChange={(event) => setContent('record_class_group_code', event.target.value)} /></FormField></div>
                <FormField label="ชื่อกลุ่มเรียน"><Input value={form.data.content.record_class_name || ''} onChange={(event) => setContent('record_class_name', event.target.value)} /></FormField>
              </ToolSection>
              <ToolSection title="ข้อความในบันทึก">
                <p className="text-xs leading-5 text-muted-foreground">แก้ในช่องด้านล่าง หรือดับเบิลคลิกข้อความบนกระดาษ Preview</p>
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
                  return <button key={key} type="button" disabled={unavailable} onClick={() => setTarget(key)} className={cn('focus-ring rounded-lg border px-3 py-2 text-left text-sm font-semibold transition-colors', target === key ? 'border-primary bg-primary/8 text-primary' : 'hover:bg-muted', unavailable && 'cursor-not-allowed opacity-40')}>{item.label}</button>
                })}</div>
              </ToolSection>
              <ToolSection title={`ตำแหน่ง ${position.label}`}>
                <p className="text-xs leading-5 text-muted-foreground">ลากกรอบบน Preview หรือใช้ลูกศรเพื่อขยับอย่างละเอียด ตำแหน่งรูปวัดจากขอบซ้ายและขอบบนของพื้นที่รูป</p>
                {position.kind === 'signature' && Number(form.data.settings[position.y] || 0) < -25 && (
                  <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-2.5 text-xs leading-relaxed text-destructive">
                    ⚠️ <b>ระวัง:</b> ลายเซ็นถูกเลื่อนขึ้นมามาก ({form.data.settings[position.y]}px) อาจซ้อนทับกับเนื้อหาด้านบน หากต้องการให้เอกสารจบในหน้าเดียว แนะนำให้กดปุ่ม <b>&ldquo;คืนตำแหน่ง&rdquo;</b> แล้วปรับลดขนาดตัวอักษรหรือระยะบรรทัดในแท็บ <b>&ldquo;กระดาษ&rdquo;</b> แทน
                  </div>
                )}
                {position.kind === 'signature' && (
                  <div className="rounded-lg bg-muted/60 p-2.5 text-xs leading-relaxed text-muted-foreground">
                    💡 <b>คำแนะนำ:</b> หากเนื้อหาบันทึกยาวจนลายเซ็นตกไปหน้า 2 แนะนำให้ไปที่แท็บ <b>&ldquo;กระดาษ&rdquo;</b> แล้วเลือก <b>&ldquo;กะทัดรัด&rdquo;</b> (14 pt) จะช่วยให้เนื้อหาทั้งหมดพอดีในหน้าแรกอย่างเป็นระเบียบ โดยไม่ต้องเลื่อนลายเซ็นขึ้นมาทับเนื้อหา และสามารถกดปุ่ม <b>&ldquo;คืนตำแหน่ง&rdquo;</b> เพื่อรีเซ็ตตำแหน่งลายเซ็นได้
                  </div>
                )}
                <div className="grid grid-cols-[1fr_auto_1fr] items-center gap-2"><span /><Button type="button" variant="outline" size="icon" onClick={() => nudge(position.y, -step)} aria-label="เลื่อนขึ้น"><ArrowUp size={18} /></Button><span /><Button type="button" variant="outline" size="icon" onClick={() => nudge(position.x, -step)} aria-label="เลื่อนซ้าย"><ArrowLeft size={18} /></Button><Button type="button" variant="ghost" size="sm" onClick={resetTarget}>คืนตำแหน่ง</Button><Button type="button" variant="outline" size="icon" onClick={() => nudge(position.x, step)} aria-label="เลื่อนขวา"><ArrowRight size={18} /></Button><span /><Button type="button" variant="outline" size="icon" onClick={() => nudge(position.y, step)} aria-label="เลื่อนลง"><ArrowDown size={18} /></Button><span /></div>
                <div className="grid grid-cols-2 gap-3"><FormField label="จากซ้าย"><Input type="number" min={position.kind === 'image' ? 0 : -400} max={position.kind === 'image' ? 1200 : 400} value={form.data.settings[position.x]} onChange={(event) => setSetting(position.x, event.target.value)} /></FormField><FormField label="จากบน"><Input type="number" min={position.kind === 'image' ? 0 : -400} max={position.kind === 'image' ? 1100 : 400} value={form.data.settings[position.y]} onChange={(event) => setSetting(position.y, event.target.value)} /></FormField></div>
                <FormField label="ระยะขยับต่อครั้ง"><NativeSelect value={step} onChange={(event) => setStep(Number(event.target.value))}><option value={1}>1 พิกเซล</option><option value={5}>5 พิกเซล</option><option value={10}>10 พิกเซล</option></NativeSelect></FormField>
                {position.kind === 'signature' && <FormField label="การจัดแนว"><NativeSelect value={form.data.settings[position.align]} onChange={(event) => setSetting(position.align, event.target.value)}><option value="flex-start">ชิดซ้าย</option><option value="center">กึ่งกลาง</option><option value="flex-end">ชิดขวา</option></NativeSelect></FormField>}
                {position.kind === 'image' && <div className="grid gap-3">
                  <div className="grid grid-cols-3 gap-2" aria-label="ขนาดรูปสำเร็จรูป">{imagePresets.map((preset) => <Button key={preset.label} type="button" size="sm" variant="outline" onClick={() => applyImagePreset(preset.width, preset.height)}>{preset.label}</Button>)}</div>
                  <div className="grid grid-cols-2 gap-3"><FormField label="ความกว้าง"><Input type="number" min={60} max={1000} value={form.data.settings[position.width]} onChange={(event) => resizeImage('width', event.target.value)} /></FormField><FormField label="ความสูง"><Input type="number" min={60} max={950} value={form.data.settings[position.height]} onChange={(event) => resizeImage('height', event.target.value)} /></FormField></div>
                  <label className="flex cursor-pointer items-center justify-between gap-3 rounded-lg border bg-background px-3 py-2.5">
                    <span><b className="block text-sm">ล็อกสัดส่วน</b><small className="block text-muted-foreground">เปิดเมื่อต้องการย่อขยายโดยไม่ให้รูปยืด</small></span>
                    <Switch checked={form.data.settings[position.lock] === '1'} onCheckedChange={(checked) => setSetting(position.lock, checked ? '1' : '0')} aria-label="ล็อกสัดส่วนรูป" />
                  </label>
                  <FormField label="การวางรูปในกรอบ"><NativeSelect value={form.data.settings[position.fit]} onChange={(event) => setSetting(position.fit, event.target.value)}><option value="fill">ยืดเต็มกรอบ</option><option value="cover">ครอบเต็มกรอบ</option><option value="contain">แสดงครบทั้งรูป</option></NativeSelect></FormField>
                  {form.data.settings[position.fit] === 'cover' && <div className="grid gap-3 rounded-lg border p-3"><p className="text-xs text-muted-foreground">เลื่อนจุดโฟกัสเพื่อเลือกส่วนภาพที่ต้องการเก็บไว้ในกรอบ</p>{(['x', 'y'] as const).map((axis) => {
                    const key = `image_${target.replace('image', '')}_crop_${axis}`
                    return <FormField key={axis} label={axis === 'x' ? 'ครอบภาพ ซ้าย - ขวา' : 'ครอบภาพ บน - ล่าง'}><input aria-label={axis === 'x' ? 'จุดโฟกัสภาพแนวนอน' : 'จุดโฟกัสภาพแนวตั้ง'} type="range" min="0" max="100" value={form.data.settings[key] || '50'} onChange={(event) => setSetting(key, event.target.value)} className="w-full accent-primary" /></FormField>
                  })}</div>}
                  <div className="grid grid-cols-2 gap-2"><Button type="button" variant="outline" size="sm" onClick={() => rotateImage(-90)}><ArrowCounterClockwise size={17} />หมุนซ้าย</Button><Button type="button" variant="outline" size="sm" onClick={() => rotateImage(90)}><ArrowClockwise size={17} />หมุนขวา</Button></div>
                  <div className="rounded-lg bg-muted/60 p-3 text-xs leading-5 text-muted-foreground"><b className="text-foreground">จุดจับ 8 ทิศบน Preview</b><br />ลากด้านหรือมุมใดก็ได้ ระบบล็อกสัดส่วนให้ตามค่าเริ่มต้น กด Shift ค้างเพื่อสลับล็อกชั่วคราว</div>
                </div>}
              </ToolSection>
              {position.kind === 'signature' && <ToolSection title="ข้อความลายเซ็น">
                <FormField label="ชื่อ"><Input value={form.data.settings[`${target}_name`] || ''} onChange={(event) => setSetting(`${target}_name`, event.target.value)} /></FormField>
                <FormField label="ตำแหน่ง"><Textarea rows={2} value={form.data.settings[`${target}_pos`] || ''} onChange={(event) => setSetting(`${target}_pos`, event.target.value)} /></FormField>
              </ToolSection>}
            </Tabs.Content>
          </aside>

          <section className="min-w-0 overflow-hidden rounded-xl border bg-card">
            <header className="flex flex-col gap-3 border-b p-3 sm:flex-row sm:items-center sm:justify-between">
              <div><h2 className="flex items-center gap-2 text-sm font-semibold"><NotePencil size={18} className="text-primary" />{previewMode === 'editor' ? 'ตัวจัดเอกสารแบบโต้ตอบ' : 'PDF จริงก่อนดาวน์โหลด'}</h2><p className="mt-1 text-xs text-muted-foreground">{previewMode === 'editor' ? 'ลากรูป ปรับขนาด 8 ทิศ และดับเบิลคลิกข้อความได้โดยไม่โหลด Preview ใหม่' : `เรนเดอร์ด้วยระบบเดียวกับไฟล์ดาวน์โหลดจริง · ${pageGeometry.pageCount} หน้า`}</p></div>
              <div className="flex flex-wrap items-center gap-1">
                <div className="flex rounded-lg bg-muted p-1"><Button type="button" variant={previewMode === 'editor' ? 'secondary' : 'ghost'} size="sm" onClick={() => setPreviewMode('editor')}><NotePencil size={16} />จัดวาง</Button><Button type="button" variant={previewMode === 'pdf' ? 'secondary' : 'ghost'} size="sm" disabled={actualPdfLoading} onClick={loadActualPdf}><FilePdf size={16} />{actualPdfLoading ? 'กำลังสร้าง' : 'ดู PDF จริง'}</Button></div>
                {previewMode === 'editor' && <><Button type="button" variant="ghost" size="icon" onClick={() => setZoom(Math.max(0.4, zoom - 0.08))} aria-label="ซูมออก"><Minus size={17} /></Button><button type="button" className="focus-ring min-w-14 rounded-md px-2 py-1 text-xs font-semibold" onClick={() => setZoom(0.72)}>{Math.round(zoom * 100)}%</button><Button type="button" variant="ghost" size="icon" onClick={() => setZoom(Math.min(1.15, zoom + 0.08))} aria-label="ซูมเข้า"><Plus size={17} /></Button></>}
              </div>
            </header>
            {previewError && <div className="m-4 flex items-start gap-2 rounded-xl border border-destructive/25 bg-destructive/5 p-4 text-sm text-destructive"><WarningCircle size={19} className="mt-0.5 shrink-0" />{previewError}</div>}
            {previewMode === 'pdf' && actualPdfStale && actualPdfUrl && <div className="m-4 flex items-center justify-between gap-3 rounded-xl border border-amber-300/70 bg-amber-50 p-3 text-sm text-amber-950"><span>มีการแก้ไขหลังสร้าง PDF ตัวอย่างล่าสุด</span><Button type="button" size="sm" variant="outline" disabled={actualPdfLoading} onClick={loadActualPdf}>สร้างใหม่</Button></div>}
            <div className="relative h-[calc(100dvh-202px)] min-h-[680px] overflow-auto bg-muted/55 p-4">
              {previewMode === 'editor' && previewing && <div className="sticky left-4 right-4 top-0 z-10 h-1.5 overflow-hidden rounded-full bg-muted"><span className="block h-full w-1/2 animate-pulse rounded-full bg-primary" /></div>}
              {previewMode === 'editor' && preview && <div className="mx-auto" style={{ width: `${pageGeometry.pageWidth * zoom}px`, height: `${pageGeometry.documentHeight * zoom}px` }}><iframe ref={iframeRef} title="ตัวจัดเอกสารก่อนพิมพ์" srcDoc={preview} sandbox="allow-scripts" onLoad={() => postLocalPatch(Object.fromEntries(Object.entries(latestDocumentRef.current.settings).filter(([key]) => localPreviewKey.test(key))))} className="origin-top-left border-0 bg-background shadow-[0_18px_50px_rgba(20,58,55,0.14)]" style={{ width: `${pageGeometry.pageWidth + 4}px`, height: `${pageGeometry.documentHeight}px`, transform: `scale(${zoom})` }} /></div>}
              {previewMode === 'pdf' && actualPdfUrl && <iframe title="PDF จริงก่อนดาวน์โหลด" src={actualPdfUrl} className="h-full min-h-[650px] w-full rounded-lg border-0 bg-background shadow-[0_18px_50px_rgba(20,58,55,0.14)]" />}
              {previewMode === 'pdf' && !actualPdfUrl && <div className="grid min-h-[620px] place-items-center text-center"><div><FilePdf size={44} className="mx-auto text-muted-foreground" /><p className="mt-3 text-sm font-semibold">ยังไม่ได้สร้าง PDF ตัวอย่าง</p><Button type="button" className="mt-3" disabled={actualPdfLoading} onClick={loadActualPdf}>สร้าง PDF จากข้อมูลปัจจุบัน</Button></div></div>}
            </div>
          </section>
        </div>
      </Tabs.Root>
    </form>
  </AppShell>
}
