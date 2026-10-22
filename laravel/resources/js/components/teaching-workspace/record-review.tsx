import { appUrl } from '@/lib/app-url'
import { ArrowLeft, CheckCircle, FilePdf, ImageSquare, NotePencil, Student, WarningCircle } from '@phosphor-icons/react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import type { LevelCode, TeachingDraftImage, TeachingDraftPayload } from './types'
import { selectedTeachingLevels, teachingLevels } from './types'

export type LevelIssue = { levelCode: LevelCode; message: string; field: string }

export function reviewIssues(payload: TeachingDraftPayload, images: TeachingDraftImage[]): LevelIssue[] {
  const selectedLevels = selectedTeachingLevels(payload)
  const scopeLevel = selectedLevels[0]?.code || '1'
  const scopeIssues: LevelIssue[] = []
  if (!selectedLevels.length) scopeIssues.push({ levelCode: scopeLevel, message: 'เลือกระดับชั้นที่สอนอย่างน้อย 1 ระดับ', field: 'scope.levels' })
  if (!payload.scope.tambon) scopeIssues.push({ levelCode: scopeLevel, message: 'ยังไม่ได้เลือกตำบลของชุดงาน', field: 'scope.tambon' })
  if (!/^25\d{2}$/.test(payload.scope.year)) scopeIssues.push({ levelCode: scopeLevel, message: 'ระบุปีการศึกษาเป็น พ.ศ. 4 หลัก', field: 'scope.year' })
  if (!payload.scope.week || Number(payload.scope.week) < 1 || Number(payload.scope.week) > 20) scopeIssues.push({ levelCode: scopeLevel, message: 'เลือกสัปดาห์ที่ 1-20', field: 'scope.week' })
  if (!payload.scope.date) scopeIssues.push({ levelCode: scopeLevel, message: 'เลือกวันที่สอน', field: 'scope.date' })
  return [...scopeIssues, ...selectedLevels.flatMap(({ code }) => {
    const level = payload.levels[code]
    const levelImages = images.filter((image) => image.level_code === code)
    const issues: LevelIssue[] = []
    if (!level.class_name.trim() && !level.class_group_code.trim()) issues.push({ levelCode: code, message: 'ยังไม่ได้เลือกกลุ่มเรียน', field: 'group' })
    if (!level.subject.trim()) issues.push({ levelCode: code, message: 'ยังไม่ได้ระบุรายวิชา', field: 'subject' })
    if (!level.result.trim()) issues.push({ levelCode: code, message: 'ยังไม่ได้บันทึกผลการจัดการเรียนรู้', field: 'result' })
    if (level.attendance_mode === 'roster' && !level.attendance.length) issues.push({ levelCode: code, message: 'ยังไม่มีรายชื่อนักศึกษา', field: 'attendance' })
    if (!level.attendance_reviewed) issues.push({ levelCode: code, message: level.attendance_mode === 'roster' ? 'ยังไม่ได้ยืนยันตรวจรายชื่อ' : 'ยังไม่ได้ยืนยันยอดผู้เรียน', field: 'attendance' })
    if (level.attendance_mode === 'counts' && (!Number.isInteger(level.total) || !Number.isInteger(level.present) || level.total < 0 || level.total > 10000 || level.present < 0 || level.present > level.total)) issues.push({ levelCode: code, message: 'ตรวจยอดผู้เรียนทั้งหมดและจำนวนมาเรียนให้ถูกต้อง', field: 'attendance' })
    if (![0, 2, 4].includes(levelImages.length)) issues.push({ levelCode: code, message: `รูปภาพต้องมี 0, 2 หรือ 4 รูป (ขณะนี้ ${levelImages.length})`, field: 'images' })
    return issues
  })]
}

export function WorkspaceSummary({ payload, images, activeLevel, onSelectLevel }: { payload: TeachingDraftPayload; images: TeachingDraftImage[]; activeLevel: LevelCode; onSelectLevel: (code: LevelCode) => void }) {
  const issues = reviewIssues(payload, images)
  const selectedLevels = selectedTeachingLevels(payload)

  return <div className="grid gap-3">
    <div className="flex items-center justify-between"><b className="text-sm">ความพร้อมของชุดงาน</b><Badge variant={issues.length ? 'outline' : 'secondary'}>{issues.length ? `เหลือ ${issues.length} จุด` : 'พร้อมตรวจ'}</Badge></div>
    <div className="grid gap-2 sm:grid-cols-3 2xl:grid-cols-1">
      {selectedLevels.map((item) => {
        const value = payload.levels[item.code]
        const count = images.filter((image) => image.level_code === item.code).length
        const levelIssues = issues.filter((issue) => issue.levelCode === item.code)
        return <button key={item.code} type="button" onClick={() => onSelectLevel(item.code)} className={cn('focus-ring rounded-xl border p-3 text-left transition-[border-color,background-color,transform] duration-150 active:scale-[.99]', activeLevel === item.code ? 'border-primary/35 bg-primary/[.055]' : 'hover:bg-muted/30')}>
          <div className="flex items-center justify-between gap-2"><b className="text-xs">{item.shortName}</b>{levelIssues.length ? <WarningCircle size={17} className="text-amber-600" weight="fill" /> : <CheckCircle size={17} className="text-primary" weight="fill" />}</div>
          <p className="mt-1 truncate text-xs text-muted-foreground">{value.subject || 'ยังไม่ระบุวิชา'}</p>
          <div className="mt-2 flex gap-3 text-[10px] text-muted-foreground"><span>{value.present}/{value.total} คน</span><span>{count} รูป</span></div>
        </button>
      })}
    </div>
  </div>
}

type ReviewProps = {
  payload: TeachingDraftPayload
  images: TeachingDraftImage[]
  previewLevel: LevelCode
  previewUrl: string | null
  previewing: boolean
  previewStale: boolean
  previewedLevels: LevelCode[]
  previewFormat: 'pdf' | 'browser'
  rendererUnavailable: boolean
  onBrowserPreview: () => Promise<void>
  onBrowserPreviewLoaded: (frame: HTMLIFrameElement, level: LevelCode, url: string) => Promise<void>
  finalizing: boolean
  busy: boolean
  onBack: () => void
  onFix: (level: LevelCode, field?: string) => void
  onPreviewLevel: (level: LevelCode) => void
  onPreview: () => Promise<void>
  onEditDocument: (level: LevelCode) => Promise<void>
  onFinalize: () => Promise<void>
}

export function RecordReview({ payload, images, previewLevel, previewUrl, previewing, previewStale, previewedLevels, previewFormat, rendererUnavailable, onBrowserPreview, onBrowserPreviewLoaded, finalizing, busy, onBack, onFix, onPreviewLevel, onPreview, onEditDocument, onFinalize }: ReviewProps) {
  const issues = reviewIssues(payload, images)
  const selectedLevels = selectedTeachingLevels(payload)
  const totalLevels = selectedLevels.length

  return <div className="grid gap-6">
    <div className="flex flex-col gap-4 rounded-xl border bg-card p-4 sm:flex-row sm:items-center sm:justify-between">
      <div><div className="flex items-center gap-2"><Badge variant="secondary">ขั้นตอนสุดท้าย</Badge><b>ตรวจ {totalLevels} ระดับที่เลือกก่อนยืนยัน</b></div><p className="mt-1 text-xs text-muted-foreground">บันทึกเฉพาะระดับชั้นที่เปิดสอนในสัปดาห์นี้</p></div>
      <Button type="button" variant="outline" disabled={finalizing} onClick={onBack}><ArrowLeft size={17} />กลับไปแก้ไข</Button>
    </div>

    {issues.length > 0 && <section className="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4" aria-labelledby="review-issues-heading">
      <div className="flex items-center gap-2 text-amber-900 dark:text-amber-100"><WarningCircle size={20} weight="fill" /><h2 id="review-issues-heading" className="font-semibold">ยังยืนยันไม่ได้</h2></div>
      <div className="mt-3 grid gap-2 sm:grid-cols-2">{issues.map((issue, index) => {
        const level = teachingLevels.find((item) => item.code === issue.levelCode)
        return <button key={`${issue.levelCode}-${issue.field}-${index}`} type="button" onClick={() => onFix(issue.levelCode, issue.field)} className="focus-ring flex items-center justify-between gap-3 rounded-lg bg-background/80 px-3 py-2 text-left text-xs hover:bg-background"><span><b>{issue.field.startsWith('scope.') ? 'ข้อมูลสัปดาห์' : level?.shortName}</b> - {issue.message}</span><span className="text-primary">แก้ไข</span></button>
      })}</div>
    </section>}

    <section className="grid gap-3" aria-labelledby="review-levels-heading">
      <h2 id="review-levels-heading" className="text-lg font-semibold">สรุปข้อมูลที่จะบันทึก</h2>
      <div className={cn('grid gap-3', totalLevels === 2 ? 'lg:grid-cols-2' : totalLevels === 3 ? 'lg:grid-cols-3' : '')}>{selectedLevels.map((item) => {
        const value = payload.levels[item.code]
        const levelImages = images.filter((image) => image.level_code === item.code)
        const present = value.attendance_mode === 'roster' ? value.attendance.filter((row) => row.present).length : value.present
        const total = value.attendance_mode === 'roster' ? value.attendance.length : value.total
        return <article key={item.code} className="rounded-xl border bg-card p-4">
          <div className="flex items-start justify-between gap-3"><div><Badge variant="outline">{item.name}</Badge><h3 className="mt-3 font-semibold">{value.subject || 'ยังไม่ระบุวิชา'}</h3><p className="mt-1 text-xs text-muted-foreground">{value.subject_code || 'ไม่ระบุรหัสวิชา'} · {value.class_name || 'ยังไม่เลือกกลุ่ม'}</p></div>{reviewIssues(payload, images).some((issue) => issue.levelCode === item.code) ? <WarningCircle size={20} className="text-amber-600" weight="fill" /> : <CheckCircle size={20} className="text-primary" weight="fill" />}</div>
          <div className="mt-4 grid grid-cols-2 gap-2"><span className="rounded-lg bg-muted/45 p-2.5 text-xs"><Student size={17} className="mb-1 text-primary" />มาเรียน <b>{present}/{total}</b></span><span className="rounded-lg bg-muted/45 p-2.5 text-xs"><ImageSquare size={17} className="mb-1 text-primary" />รูป <b>{levelImages.length}</b></span></div>
          <dl className="mt-4 grid max-h-80 gap-3 overflow-y-auto pr-1 text-xs leading-6"><div><dt className="font-medium text-muted-foreground">ผลการจัดการเรียนรู้</dt><dd className="mt-1 whitespace-pre-wrap break-words">{value.result || 'ไม่ได้ระบุ'}</dd></div><div><dt className="font-medium text-muted-foreground">ปัญหาและอุปสรรค</dt><dd className="mt-1 whitespace-pre-wrap break-words">{value.problem || 'ไม่ได้ระบุ'}</dd></div><div><dt className="font-medium text-muted-foreground">ข้อเสนอแนะและแนวทางแก้ไข</dt><dd className="mt-1 whitespace-pre-wrap break-words">{value.suggestion || 'ไม่ได้ระบุ'}</dd></div></dl>
          {levelImages.length > 0 && <div className="mt-4 grid grid-cols-2 gap-2">{levelImages.map((image, index) => <img key={image.id} src={appUrl(image.url)} alt={`ภาพประกอบ ${item.shortName} ลำดับ ${index + 1}`} className="aspect-[4/3] w-full rounded-lg border object-contain bg-muted/20" />)}</div>}
          {value.attendance_mode === 'roster' && <details className="mt-4 rounded-lg border p-3 text-xs"><summary className="cursor-pointer font-medium">ตรวจรายชื่อผู้เรียน {value.attendance.length} คน</summary><ul className="mt-3 grid max-h-60 gap-2 overflow-y-auto">{value.attendance.map((row) => <li key={row.student_id} className="flex items-start justify-between gap-3 border-b pb-2 last:border-0"><span>{row.full_name}<small className="block text-muted-foreground">{row.student_id}</small></span><span className={row.present ? 'shrink-0 text-primary' : 'shrink-0 text-muted-foreground'}>{row.present ? row.learning_method || 'มาเรียน' : 'ขาดเรียน'}</span></li>)}</ul></details>}
          <Button type="button" variant="ghost" size="sm" className="mt-4 w-full" onClick={() => onFix(item.code)}>กลับไปตรวจระดับนี้</Button>
        </article>
      })}</div>
    </section>

    <section id="document-review" className="grid scroll-mt-28 gap-4 rounded-xl border bg-card p-4 sm:p-5" aria-labelledby="pdf-review-heading">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h2 id="pdf-review-heading" className="flex items-center gap-2 font-semibold"><FilePdf size={20} className="text-primary" />ตรวจเอกสารก่อนยืนยัน</h2><p className="mt-1 text-xs text-muted-foreground">ตรวจข้อความ รูป และเค้าโครงก่อนยืนยัน · ตรวจแล้ว {previewedLevels.length}/{totalLevels} ระดับ</p></div><div className="flex flex-wrap gap-2">{selectedLevels.map((item) => <Button key={item.code} type="button" size="sm" disabled={previewing || finalizing} variant={previewLevel === item.code ? 'secondary' : 'ghost'} onClick={() => onPreviewLevel(item.code)}>{previewedLevels.includes(item.code) && <CheckCircle size={15} weight="fill" />}{item.shortName}</Button>)}<Button type="button" size="sm" variant="outline" disabled={previewing || finalizing || busy} onClick={() => void onEditDocument(previewLevel)}><NotePencil size={17} />จัดเอกสารและรูปภาพ</Button><Button type="button" size="sm" disabled={previewing || finalizing || busy} onClick={() => void onPreview()}>{previewing ? 'กำลังสร้าง...' : previewFormat === 'browser' || rendererUnavailable ? 'ลองสร้าง PDF อีกครั้ง' : previewUrl && !previewStale ? 'สร้างตัวอย่างใหม่' : 'สร้างตัวอย่าง PDF'}</Button></div></div>
      {rendererUnavailable && <div role="status" className="flex flex-col gap-3 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm sm:flex-row sm:items-center sm:justify-between"><p>ระบบสร้าง PDF บนเซิร์ฟเวอร์ยังไม่พร้อม คุณสามารถตรวจเอกสารผ่านเบราว์เซอร์ให้ครบทุกระดับ แล้วยืนยันบันทึกได้</p><Button type="button" variant="outline" disabled={previewing || finalizing || busy} onClick={() => void onBrowserPreview()}>ตรวจเอกสารผ่านเบราว์เซอร์</Button></div>}
      {previewUrl && previewFormat === 'browser' && <p className="text-xs text-muted-foreground">ตัวอย่างนี้เป็นเอกสารผ่านเบราว์เซอร์ ใช้ข้อความ รูป และเค้าโครงฉบับเดียวกับที่บันทึก · ปุ่มพิมพ์ / บันทึก PDF อยู่ในตัวอย่าง</p>}
      {previewUrl && previewStale && <div className="rounded-lg bg-amber-500/10 px-3 py-2 text-xs font-medium text-amber-800 dark:text-amber-200">เอกสารยังไม่พร้อมหรือร่างมีการแก้ไข กรุณารอให้โหลดครบ หรือสร้างตัวอย่างใหม่ก่อนยืนยัน</div>}
      {previewUrl ? <iframe key={previewUrl} id="workspace-document-preview" src={appUrl(previewUrl)} onLoad={(event) => { if (previewFormat === 'browser') void onBrowserPreviewLoaded(event.currentTarget, previewLevel, previewUrl) }} title={`ตัวอย่างเอกสาร ${teachingLevels.find((item) => item.code === previewLevel)?.name}`} className="h-[70vh] min-h-[560px] w-full rounded-xl border bg-white" /> : <div className="grid min-h-[420px] place-items-center rounded-xl border border-dashed bg-muted/20 text-center"><div><FilePdf size={38} className="mx-auto text-muted-foreground" /><b className="mt-3 block">ยังไม่ได้สร้างตัวอย่าง PDF</b><p className="mt-1 text-sm text-muted-foreground">เลือกประเภทระดับ แล้วสร้างตัวอย่างก่อนยืนยัน</p></div></div>}
    </section>

    <div className="sticky bottom-3 z-10 flex flex-col gap-3 rounded-xl border bg-background/95 p-4 shadow-[0_16px_46px_rgba(20,58,55,.18)] backdrop-blur sm:flex-row sm:items-center sm:justify-between">
      <div className="text-sm">{issues.length ? <span className="font-medium text-amber-700 dark:text-amber-200">แก้ไขอีก {issues.length} จุดก่อนยืนยัน</span> : previewedLevels.length < totalLevels ? <button type="button" className="focus-ring text-left font-medium text-amber-700 underline underline-offset-4 dark:text-amber-200" onClick={() => document.getElementById('document-review')?.scrollIntoView({ block: 'start' })}>ตรวจเอกสารฉบับล่าสุดให้ครบทั้ง {totalLevels} ระดับ ({previewedLevels.length}/{totalLevels})</button> : <span className="flex items-center gap-2 font-medium text-primary"><CheckCircle size={19} weight="fill" />ข้อมูลและเอกสารพร้อมยืนยัน</span>}</div>
      <Button type="button" size="lg" disabled={issues.length > 0 || previewedLevels.length < totalLevels || finalizing || previewing || busy} onClick={() => void onFinalize()}>{finalizing ? `กำลังยืนยัน ${totalLevels} ระดับ...` : `ยืนยันบันทึก ${totalLevels} ระดับ`}<CheckCircle size={19} weight="bold" /></Button>
    </div>
  </div>
}
