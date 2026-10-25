import { appUrl } from '@/lib/app-url'
import * as Dialog from '@radix-ui/react-dialog'
import { ArrowLeft, CheckCircle, FilePdf, NotePencil, WarningCircle } from '@phosphor-icons/react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import type { LevelCode, TeachingDraftImage, TeachingDraftPayload } from './types'
import { selectedTeachingLevels, teachingLevels } from './types'
import { reviewIssues, teachingAttendanceCounts, teachingSaveSummary, type LevelIssue } from './review-state'

export { reviewIssues } from './review-state'

export function WorkspaceIssues({ issues, onFix }: { issues: LevelIssue[]; onFix: (level: LevelCode, field?: string) => void }) {
  if (!issues.length) return null
  return <section className="mb-5 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4" aria-labelledby="workspace-issues-heading" role="alert">
    <div className="flex items-center gap-2 text-amber-900 dark:text-amber-100"><WarningCircle size={20} weight="fill" /><h2 id="workspace-issues-heading" className="font-semibold">ตรวจข้อมูลอีก {issues.length} จุดก่อนบันทึก</h2></div>
    <div className="mt-3 grid gap-2 sm:grid-cols-2">{issues.map((issue, index) => <button key={`${issue.levelCode}-${issue.field}-${index}`} type="button" onClick={() => onFix(issue.levelCode, issue.field)} className="focus-ring flex items-center justify-between gap-3 rounded-lg bg-background/80 px-3 py-2 text-left text-xs hover:bg-background"><span><b>{issue.field.startsWith('scope.') ? 'ข้อมูลสัปดาห์' : teachingLevels.find(level => level.code === issue.levelCode)?.shortName}</b> - {issue.message}</span><span className="text-primary">แก้ไข</span></button>)}</div>
  </section>
}

export function WorkspaceSummary({ payload, images, activeLevel, onSelectLevel }: { payload: TeachingDraftPayload; images: TeachingDraftImage[]; activeLevel: LevelCode; onSelectLevel: (code: LevelCode) => void }) {
  const issues = reviewIssues(payload, images)
  return <div className="grid gap-3">
    <div className="flex items-center justify-between"><b className="text-sm">ความพร้อมของชุดงาน</b><Badge variant={issues.length ? 'outline' : 'secondary'}>{issues.length ? `เหลือ ${issues.length} จุด` : 'พร้อมบันทึก'}</Badge></div>
    <div className="grid gap-2 sm:grid-cols-3 2xl:grid-cols-1">{selectedTeachingLevels(payload).map(item => {
      const value = payload.levels[item.code]
      const counts = teachingAttendanceCounts(value)
      const count = images.filter(image => image.level_code === item.code).length
      return <button key={item.code} type="button" onClick={() => onSelectLevel(item.code)} className={cn('focus-ring rounded-xl border p-3 text-left transition-[border-color,background-color,transform] duration-150 active:scale-[.99]', activeLevel === item.code ? 'border-primary/35 bg-primary/[.055]' : 'hover:bg-muted/30')}>
        <div className="flex items-center justify-between gap-2"><b className="text-xs">{item.shortName}</b>{issues.some(issue => issue.levelCode === item.code) ? <WarningCircle size={17} className="text-amber-600" weight="fill" /> : <CheckCircle size={17} className="text-primary" weight="fill" />}</div>
        <p className="mt-1 truncate text-xs text-muted-foreground">{value.subject || 'ยังไม่ระบุวิชา'}</p>
        <div className="mt-2 flex gap-3 text-[10px] text-muted-foreground"><span>มาเรียน {counts.present}/{counts.total} คน</span><span>{count} รูป</span></div>
      </button>
    })}</div>
  </div>
}

export function RecordSaveConfirmation({ open, onOpenChange, payload, images, finalizing, busy, error, onConfirm, onReturnFocus }: {
  open: boolean; onOpenChange: (open: boolean) => void; payload: TeachingDraftPayload; images: TeachingDraftImage[]; finalizing: boolean; busy: boolean; error: string | null; onConfirm: () => Promise<void>; onReturnFocus: () => void
}) {
  const levels = teachingSaveSummary(payload, images)
  return <Dialog.Root open={open} onOpenChange={value => { if (!finalizing) onOpenChange(value) }}><Dialog.Portal>
    <Dialog.Overlay className="fixed inset-0 z-40 bg-foreground/35 backdrop-blur-sm" />
    <Dialog.Content onCloseAutoFocus={event => { event.preventDefault(); onReturnFocus() }} className="fixed left-1/2 top-1/2 z-50 max-h-[85dvh] w-[min(92vw,480px)] -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl border bg-card p-5 shadow-2xl outline-none sm:p-6" onEscapeKeyDown={event => { if (finalizing) event.preventDefault() }} onPointerDownOutside={event => { if (finalizing) event.preventDefault() }}>
      <Dialog.Title className="text-lg font-semibold">ยืนยันบันทึกประจำสัปดาห์</Dialog.Title>
      <Dialog.Description className="mt-2 text-sm text-muted-foreground">สัปดาห์ {payload.scope.week} · วันที่ {payload.scope.date} · บันทึก {levels.length} ระดับที่เลือกเป็นบันทึกจริง</Dialog.Description>
      <ul className="my-5 grid gap-3">{levels.map(level => <li key={level.code} className="rounded-lg border bg-muted/20 px-3 py-2.5"><b className="text-sm">{level.name}</b><p className="mt-0.5 text-xs text-muted-foreground">{level.group} · {level.subject} · มาเรียน {level.present}/{level.total} คน · {level.images} รูป</p></li>)}</ul>
      {error && <p role="alert" className="mb-4 text-sm text-destructive">{error}</p>}
      <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><Dialog.Close asChild><Button type="button" variant="outline" disabled={finalizing}>กลับไปแก้ไข</Button></Dialog.Close><Button type="button" disabled={finalizing || busy} onClick={() => void onConfirm()}>{finalizing ? 'กำลังบันทึก...' : `ยืนยันบันทึก ${levels.length} ระดับ`}<CheckCircle size={18} weight="bold" /></Button></div>
    </Dialog.Content>
  </Dialog.Portal></Dialog.Root>
}

type ReviewProps = {
  payload: TeachingDraftPayload; previewLevel: LevelCode; previewUrl: string | null; previewing: boolean; previewStale: boolean; previewFormat: 'pdf' | 'browser'; rendererUnavailable: boolean
  onBrowserPreview: () => Promise<void>; onBrowserPreviewLoaded: (frame: HTMLIFrameElement, level: LevelCode, url: string) => Promise<void>
  finalizing: boolean; busy: boolean; onBack: () => void; onPreviewLevel: (level: LevelCode) => void; onPreview: () => Promise<void>; onEditDocument: (level: LevelCode) => Promise<void>; onSave: () => void
}

export function RecordReview({ payload, previewLevel, previewUrl, previewing, previewStale, previewFormat, rendererUnavailable, onBrowserPreview, onBrowserPreviewLoaded, finalizing, busy, onBack, onPreviewLevel, onPreview, onEditDocument, onSave }: ReviewProps) {
  const selectedLevels = selectedTeachingLevels(payload)
  return <div className="grid gap-5">
    <div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="flex items-center gap-2 text-lg font-semibold"><FilePdf size={22} className="text-primary" />ดูตัวอย่างเอกสาร</h2><p className="mt-1 text-sm text-muted-foreground">เปิดดูได้ตามต้องการ โดยบันทึกได้จากหน้ากรอกข้อมูลหรือปุ่มด้านล่าง</p></div><Button type="button" variant="outline" disabled={finalizing} onClick={onBack}><ArrowLeft size={17} />กลับไปแก้ไข</Button></div>
    <section id="document-review" className="grid scroll-mt-28 gap-4 rounded-xl border bg-card p-4 sm:p-5" aria-label="ตัวอย่างเอกสาร">
      <div className="flex flex-wrap gap-2">{selectedLevels.map(item => <Button key={item.code} type="button" size="sm" disabled={previewing || finalizing} variant={previewLevel === item.code ? 'secondary' : 'ghost'} onClick={() => onPreviewLevel(item.code)}>{item.shortName}</Button>)}<Button type="button" size="sm" variant="outline" disabled={previewing || finalizing || busy} onClick={() => void onEditDocument(previewLevel)}><NotePencil size={17} />จัดเอกสารและรูปภาพ</Button><Button type="button" size="sm" disabled={previewing || finalizing || busy} onClick={() => void onPreview()}>{previewing ? 'กำลังสร้าง...' : previewUrl && !previewStale ? 'สร้างตัวอย่างใหม่' : 'เปิดตัวอย่าง / พิมพ์ PDF'}</Button></div>
      {rendererUnavailable && <div role="status" className="flex flex-col gap-3 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm sm:flex-row sm:items-center sm:justify-between"><p>ระบบสร้าง PDF ยังไม่พร้อม ร่างยังอยู่และบันทึกข้อมูลต่อได้ หรือเปิดตัวอย่างผ่านเบราว์เซอร์</p><Button type="button" variant="outline" disabled={previewing || finalizing || busy} onClick={() => void onBrowserPreview()}>ดูตัวอย่างผ่านเบราว์เซอร์</Button></div>}
      {previewUrl && previewStale && <p role="status" className="rounded-lg bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-200">ตัวอย่างกำลังโหลดหรือมีข้อมูลใหม่ สร้างตัวอย่างใหม่เมื่อต้องการดูฉบับล่าสุด</p>}
      {previewUrl ? <iframe key={previewUrl} id="workspace-document-preview" src={appUrl(previewUrl)} onLoad={event => { if (previewFormat === 'browser') void onBrowserPreviewLoaded(event.currentTarget, previewLevel, previewUrl) }} title={`ตัวอย่างเอกสาร ${teachingLevels.find(item => item.code === previewLevel)?.name}`} className="h-[70vh] min-h-[420px] w-full rounded-xl border bg-white" /> : <div className="grid min-h-72 place-items-center rounded-xl border border-dashed bg-muted/20 p-5 text-center"><div><FilePdf size={38} className="mx-auto text-muted-foreground" /><b className="mt-3 block">ยังไม่ได้สร้างตัวอย่าง PDF</b><p className="mt-1 text-sm text-muted-foreground">เลือกระดับชั้นแล้วกดสร้างตัวอย่าง</p></div></div>}
    </section>
    <div className="flex justify-end"><Button type="button" size="lg" disabled={finalizing || busy} onClick={onSave}>บันทึก {selectedLevels.length} ระดับ<CheckCircle size={19} /></Button></div>
  </div>
}
