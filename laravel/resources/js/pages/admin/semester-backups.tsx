import { Head } from '@inertiajs/react'
import * as Dialog from '@radix-ui/react-dialog'
import { Archive, ArrowClockwise, ArrowLeft, CheckCircle, ClockCounterClockwise, DownloadSimple, FileZip, ShieldCheck, UploadSimple, WarningCircle, X } from '@phosphor-icons/react'
import { FormEvent, useEffect, useRef, useState } from 'react'
import { AppShell, PageHeader } from '@/components/app-shell'
import { AppLink } from '@/components/app-link'
import { FormField } from '@/components/form-field'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { NativeSelect } from '@/components/ui/native-select'
import { actionAlert } from '@/lib/action-alert'
import { appFetch, appUrl } from '@/lib/app-url'

type Counts = { records: number; attendance: number; images: number; documents: number; revisions: number }
type Semester = { year: string; semester: string; records: number; attendance: number; images: number; active_drafts: number }
type Backup = {
  id: string; year: string; semester: string; status: 'ready' | 'archived' | 'restored' | 'imported'; counts: Counts; size_bytes: number
  created_at: string; archived_at: string | null; restored_at: string | null; downloaded_at: string | null
  download_url: string; archive_confirmation: string; restore_confirmation: string
}
type Props = { semesters: Semester[]; backups: Backup[]; limits: { max_upload_mb: number }; backupPagination?: { page: number; last_page: number; total: number; per_page: number } }
type Inspection = { backup: Backup; can_archive: boolean; can_restore: boolean; blockers: string[]; conflicts: string[] }
type Review = { backup: Backup; mode: 'archive' | 'restore' }
const base = '/admin/semester-backups'
const statuses = { ready: 'สำรองพร้อมใช้', archived: 'เก็บออกจากข้อมูลปัจจุบันแล้ว', restored: 'นำข้อมูลกลับแล้ว', imported: 'ตรวจไฟล์นำเข้าแล้ว' }
const count = (value: number) => value.toLocaleString('th-TH')
const term = (value: { semester: string; year: string }) => `${value.semester}/${value.year}`
const size = (value: number) => `${(value / (value < 1024 * 1024 ? 1024 : 1024 * 1024)).toLocaleString('th-TH', { maximumFractionDigits: 1 })} ${value < 1024 * 1024 ? 'KB' : 'MB'}`
const date = (value: string) => new Intl.DateTimeFormat('th-TH', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value.replace(' ', 'T')))

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || ''
  const response = await appFetch(path, { ...init, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...init.headers } })
  const payload = await response.json().catch(() => null)
  if (!response.ok) throw new Error(Object.values(payload?.errors || {}).flat().join('\n') || payload?.message || (response.status === 413 ? 'ไฟล์ใหญ่เกินขนาดที่เซิร์ฟเวอร์อนุญาต' : 'ติดต่อระบบสำรองข้อมูลไม่สำเร็จ กรุณาลองใหม่'))
  return payload as T
}

function BackupCounts({ counts }: { counts: Counts }) {
  return <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
    {([['บันทึก', counts.records], ['รายชื่อเข้าเรียน', counts.attendance], ['รูปกิจกรรม', counts.images], ['เอกสารจัดหน้า', counts.documents], ['ฉบับย้อนหลัง', counts.revisions]] as const).map(([label, value]) => <div key={label}><dt className="text-xs text-muted-foreground">{label}</dt><dd className="mt-0.5 font-semibold tabular-nums">{count(value)}</dd></div>)}
  </dl>
}

export default function SemesterBackups(initial: Props) {
  const [data, setData] = useState(initial)
  const first = initial.semesters[0]
  const [scope, setScope] = useState({ year: first?.year || String(new Date().getFullYear() + 543), semester: first?.semester || '1' })
  const [busy, setBusy] = useState(false)
  const busyRef = useRef(false)
  const [error, setError] = useState<string | null>(null)
  const [review, setReview] = useState<Review | null>(null)
  const [inspection, setInspection] = useState<Inspection | null>(null)
  const [inspectionError, setInspectionError] = useState<string | null>(null)
  const [confirmation, setConfirmation] = useState('')
  const [copied, setCopied] = useState(false)
  const uploadRef = useRef<HTMLInputElement>(null)
  const selected = data.semesters.find(item => item.year === scope.year && item.semester === scope.semester)
  const reload = async (page = 1) => setData(await request<Props>(`${base}?backup_page=${page}`))
  const changePage = async (page: number) => {
    if (busyRef.current) return
    busyRef.current = true; setBusy(true)
    try { await reload(page) } catch (reason) { actionAlert.error('โหลดชุดสำรองไม่สำเร็จ', reason instanceof Error ? reason.message : 'กรุณาลองอีกครั้ง') }
    finally { busyRef.current = false; setBusy(false) }
  }

  useEffect(() => {
    setInspection(null); setInspectionError(null); setConfirmation(''); setCopied(false)
    if (!review) return
    const controller = new AbortController()
    request<Inspection>(`${base}/${review.backup.id}`, { signal: controller.signal }).then(setInspection).catch(reason => {
      if (!controller.signal.aborted) setInspectionError(reason instanceof Error ? reason.message : 'ตรวจชุดสำรองไม่สำเร็จ')
    })
    return () => controller.abort()
  }, [review])

  const perform = async (title: string, operation: () => Promise<unknown>, success: string, description: string) => {
    if (busyRef.current) return
    busyRef.current = true; setBusy(true); setError(null)
    const alert = actionAlert.loading(title, 'กรุณารอให้ระบบตรวจข้อมูลและไฟล์ครบก่อนปิดหน้านี้')
    try {
      await operation()
      await reload()
      actionAlert.success(success, description, alert)
    } catch (reason) {
      const message = reason instanceof Error ? reason.message : 'ดำเนินการไม่สำเร็จ'
      setError(message); actionAlert.error('ดำเนินการไม่สำเร็จ', message, alert)
    } finally { busyRef.current = false; setBusy(false) }
  }

  const create = (event: FormEvent) => {
    event.preventDefault()
    void perform('กำลังสร้างชุดสำรอง', () => request(base, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(scope) }), 'สำรองข้อมูลแล้ว', 'ดาวน์โหลด ZIP เก็บไว้ในที่ปลอดภัย ก่อนเก็บภาคเรียนออกจากข้อมูลปัจจุบัน')
  }
  const upload = (file?: File) => {
    if (!file) return
    if (file.size > data.limits.max_upload_mb * 1024 * 1024) { actionAlert.error('ไฟล์ใหญ่เกินกำหนด', `นำเข้าไฟล์ได้ไม่เกิน ${data.limits.max_upload_mb} MB`); return }
    const body = new FormData(); body.append('archive', file)
    void perform('กำลังตรวจไฟล์สำรอง', () => request(`${base}/import`, { method: 'POST', body }), 'ตรวจไฟล์นำเข้าแล้ว', 'ข้อมูลยังไม่ถูกนำกลับ กดตรวจและนำกลับเพื่อตรวจรายการก่อนยืนยัน')
  }
  const confirm = () => {
    if (!review || !inspection || busyRef.current) return
    const { backup, mode } = review
    setReview(null)
    void perform(mode === 'archive' ? 'กำลังเก็บภาคเรียนเก่า' : 'กำลังนำข้อมูลกลับ', () => request(`${base}/${backup.id}/${mode}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ confirmation }) }), mode === 'archive' ? 'เก็บภาคเรียนเก่าแล้ว' : 'นำข้อมูลกลับแล้ว', mode === 'archive' ? 'ข้อมูลภาคเรียนนี้อยู่ในชุดสำรอง สามารถนำกลับมาเรียกดูได้ภายหลัง' : 'เปิดภาพรวมแล้วเลือกปีและภาคเรียนนี้ เพื่อดู แก้ไข และพิมพ์เอกสารได้')
  }
  const expected = review ? review.mode === 'archive' ? review.backup.archive_confirmation : review.backup.restore_confirmation : ''
  const canConfirm = Boolean(inspection && (review?.mode === 'archive' ? inspection.can_archive && copied : inspection.can_restore) && confirmation === expected)

  return <AppShell>
    <Head title="สำรองข้อมูลภาคเรียน" />
    <PageHeader title="สำรองข้อมูลภาคเรียน" description="จัดการบันทึกทุกตำบลแยกตามภาคเรียน เก็บและนำกลับมาเรียกดูได้เมื่อจำเป็น" action={<Button asChild variant="outline"><AppLink href="/admin"><ArrowLeft size={17} />จัดการระบบ</AppLink></Button>} />
    {error && <div role="alert" className="mb-5 flex items-start gap-3 whitespace-pre-line rounded-xl border border-destructive/25 bg-destructive/5 p-4 text-sm text-destructive"><WarningCircle size={20} className="shrink-0" />{error}</div>}
    <div className="mb-6 grid gap-4 lg:grid-cols-2">
      <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><Archive size={20} className="text-primary" />สำรองภาคเรียน</CardTitle><p className="text-sm text-muted-foreground">เริ่มจากสร้าง ZIP พร้อมบันทึก รูป รายชื่อ และประวัติเอกสาร</p></CardHeader><CardContent>
        <form onSubmit={create} className="grid gap-4">
          <div className="grid grid-cols-2 gap-3"><FormField label="ปีการศึกษา (พ.ศ.)"><Input required inputMode="numeric" pattern="[0-9]{4}" maxLength={4} value={scope.year} onChange={event => setScope({ ...scope, year: event.target.value })} /></FormField><FormField label="ภาคเรียน"><NativeSelect value={scope.semester} onChange={event => setScope({ ...scope, semester: event.target.value })}><option value="1">ภาคเรียน 1</option><option value="2">ภาคเรียน 2</option></NativeSelect></FormField></div>
          <div className="rounded-lg bg-muted/50 p-3 text-sm"><b>{count(selected?.records || 0)} บันทึก</b><span className="text-muted-foreground"> · {count(selected?.attendance || 0)} รายชื่อเข้าเรียน · {count(selected?.images || 0)} รูป</span>{Boolean(selected?.active_drafts) && <p className="mt-2 text-amber-700 dark:text-amber-300">มีร่างที่ยังไม่ยืนยัน {count(selected!.active_drafts)} ร่าง ต้องให้เจ้าของจัดการก่อนเก็บภาคเรียน</p>}</div>
          <Button type="submit" disabled={busy || !selected?.records}><ShieldCheck size={19} />สร้างชุดสำรอง {scope.semester}/{scope.year}</Button>
        </form>
      </CardContent></Card>
      <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><UploadSimple size={20} className="text-primary" />นำเข้าชุดสำรอง</CardTitle><p className="text-sm text-muted-foreground">เลือก ZIP ที่สร้างจากระบบนี้ ระบบตรวจไฟล์ก่อนนำข้อมูลกลับ</p></CardHeader><CardContent className="grid gap-4">
        <div className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">รองรับไฟล์สำรองภาคเรียนไม่เกิน {data.limits.max_upload_mb} MB การอัปโหลดเป็นการตรวจไฟล์เท่านั้น ต้องยืนยันอีกครั้งก่อนนำข้อมูลกลับ</div>
        <input ref={uploadRef} type="file" accept=".zip,application/zip" aria-label="เลือกไฟล์สำรอง ZIP" className="block w-full text-sm file:mr-3 file:rounded-lg file:border file:border-input file:bg-card file:px-3 file:py-2 file:text-foreground" disabled={busy} onChange={event => { upload(event.target.files?.[0]); event.target.value = '' }} />
        <p className="flex items-start gap-2 text-xs leading-relaxed text-muted-foreground"><ShieldCheck size={17} className="shrink-0" />นำกลับได้เมื่อไม่ชนกับข้อมูลปัจจุบัน ระบบจะไม่เขียนทับงานที่มีอยู่</p>
      </CardContent></Card>
    </div>
    <Card className="mb-6"><CardHeader><CardTitle className="text-base">ภาคเรียนที่มีข้อมูลอยู่ในระบบ</CardTitle></CardHeader><CardContent>
      <div className="flex flex-wrap gap-2">{data.semesters.length ? data.semesters.map(item => <Button key={term(item)} type="button" variant={term(item) === term(scope) ? 'secondary' : 'outline'} size="sm" disabled={busy} onClick={() => setScope({ year: item.year, semester: item.semester })}>{term(item)} · {count(item.records)} บันทึก</Button>) : <p className="text-sm text-muted-foreground">ยังไม่มีบันทึกในระบบ</p>}</div>
    </CardContent></Card>
    <Card><CardHeader className="flex-row items-center justify-between gap-3"><div><CardTitle className="text-base">ชุดสำรองที่เก็บไว้</CardTitle><p className="mt-1 text-sm text-muted-foreground">ดาวน์โหลด ตรวจรายการ และนำกลับมาใช้งานจากที่เดียว</p></div><Button variant="ghost" size="icon" disabled={busy} aria-label="รีเฟรชชุดสำรอง" onClick={() => { void changePage(data.backupPagination?.page || 1) }}><ArrowClockwise size={19} /></Button></CardHeader><CardContent className="grid gap-4">
      {data.backups.length ? data.backups.map(backup => <article key={backup.id} className="rounded-xl border p-4 sm:p-5">
        <div className="flex flex-wrap items-start justify-between gap-3"><div className="flex items-start gap-3"><span className="grid size-10 shrink-0 place-items-center rounded-lg bg-accent text-primary"><FileZip size={22} /></span><div><h2 className="font-semibold">ภาคเรียน {term(backup)}</h2><p className="mt-1 text-xs text-muted-foreground">{date(backup.created_at)} · {size(backup.size_bytes)}</p></div></div><Badge variant={backup.status === 'archived' ? 'secondary' : 'outline'}>{statuses[backup.status]}</Badge></div>
        <div className="my-5"><BackupCounts counts={backup.counts} /></div>
        <div className="flex flex-col gap-2 border-t pt-4 sm:flex-row sm:flex-wrap">
          <Button asChild variant="outline"><a href={appUrl(backup.download_url)} onClick={event => { if (busy) { event.preventDefault(); return } actionAlert.info('กำลังส่งไฟล์สำรองให้ดาวน์โหลด', 'เก็บ ZIP ในที่ปลอดภัย และตรวจว่าไฟล์ดาวน์โหลดเสร็จก่อนเก็บภาคเรียน') }}><DownloadSimple size={18} />ดาวน์โหลด ZIP</a></Button>
          {backup.status !== 'archived' && <Button type="button" variant="outline" disabled={busy} onClick={() => setReview({ backup, mode: 'archive' })}><Archive size={18} />ตรวจและเก็บภาคเรียน</Button>}
          <Button type="button" variant="secondary" disabled={busy} onClick={() => setReview({ backup, mode: 'restore' })}><ClockCounterClockwise size={18} />ตรวจและนำกลับ</Button>
        </div>
        {backup.downloaded_at && <p className="mt-3 flex items-center gap-1.5 text-xs text-muted-foreground"><CheckCircle size={15} />ส่งไฟล์ดาวน์โหลดล่าสุด {date(backup.downloaded_at)}</p>}
      </article>) : <div className="rounded-xl border border-dashed p-8 text-center"><Archive size={30} className="mx-auto text-muted-foreground" /><b className="mt-3 block text-sm">ยังไม่มีชุดสำรอง</b><p className="mt-1 text-sm text-muted-foreground">เลือกภาคเรียนด้านบนเพื่อเริ่มสำรองข้อมูล</p></div>}
      {data.backupPagination && data.backupPagination.last_page > 1 && <nav aria-label="หน้ารายการชุดสำรอง" className="flex flex-wrap items-center justify-center gap-3 border-t pt-4"><Button variant="outline" size="sm" disabled={busy || data.backupPagination.page <= 1} onClick={() => void changePage(data.backupPagination!.page - 1)}>ก่อนหน้า</Button><span className="text-sm text-muted-foreground" aria-live="polite">หน้า {count(data.backupPagination.page)} / {count(data.backupPagination.last_page)} · {count(data.backupPagination.total)} ชุด</span><Button variant="outline" size="sm" disabled={busy || data.backupPagination.page >= data.backupPagination.last_page} onClick={() => void changePage(data.backupPagination!.page + 1)}>ถัดไป</Button></nav>}
      <p className="text-xs leading-relaxed text-muted-foreground">ชุดสำรองนี้ใช้กับข้อมูลบันทึกหลังสอน ไม่รวมบัญชี รหัสผ่าน การตั้งค่าระบบ และร่างส่วนตัว เก็บไฟล์ฐานข้อมูลและกุญแจ APP_KEY แยกไว้สำหรับกู้คืนทั้งระบบ รูปต้นฉบับยังเก็บไว้เพื่อรักษาเอกสารที่อ้างอิงร่วมกัน</p>
    </CardContent></Card>
    <Dialog.Root open={Boolean(review)} onOpenChange={open => { if (!open) setReview(null) }}><Dialog.Portal><Dialog.Overlay className="fixed inset-0 z-40 bg-foreground/35 backdrop-blur-sm" /><Dialog.Content className="fixed left-1/2 top-1/2 z-50 max-h-[90dvh] w-[min(94vw,560px)] -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl border bg-card p-5 shadow-2xl outline-none sm:p-6">
      <div className="flex items-start justify-between gap-3"><Dialog.Title className="text-lg font-semibold">{review?.mode === 'archive' ? 'ตรวจและเก็บภาคเรียนเก่า' : 'ตรวจและนำข้อมูลกลับ'} {review && term(review.backup)}</Dialog.Title><Dialog.Close asChild><Button variant="ghost" size="icon" className="size-8" aria-label="ปิดหน้าตรวจชุดสำรอง"><X size={19} /></Button></Dialog.Close></div>
      <Dialog.Description className="mt-2 text-sm leading-relaxed text-muted-foreground">{review?.mode === 'archive' ? 'เมื่อยืนยัน บันทึกทุกตำบลในภาคเรียนนี้จะออกจากภาพรวมและการค้นหาปัจจุบัน เรียกกลับมาใช้ได้ด้วยชุดสำรอง' : 'ข้อมูลจะกลับเข้าไปในภาพรวมและเอกสารของภาคเรียนเดิม ต้องไม่มีรหัสรายการชนกับงานปัจจุบัน'}</Dialog.Description>
      <div className="my-5 rounded-lg bg-muted/40 p-4">{review && <BackupCounts counts={review.backup.counts} />}</div>
      {!inspection && !inspectionError && <p role="status" className="my-5 text-sm text-muted-foreground">กำลังตรวจไฟล์และข้อมูลปัจจุบัน…</p>}
      {inspectionError && <p role="alert" className="my-5 whitespace-pre-line text-sm text-destructive">{inspectionError}</p>}
      {inspection && (() => {
        const issues = review?.mode === 'archive' ? inspection.blockers : inspection.conflicts
        return <>{issues.length > 0 && <div role="alert" className="mb-4 rounded-lg border border-amber-300/60 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-200"><b>ต้องจัดการก่อนดำเนินการ</b><ul className="mt-2 list-disc space-y-1 pl-5">{issues.map((item, index) => <li key={index}>{item}</li>)}</ul></div>}
          {review?.mode === 'archive' && <label className="mb-4 flex cursor-pointer items-start gap-2 text-sm"><input type="checkbox" className="mt-1 size-4 accent-primary" checked={copied} onChange={event => setCopied(event.target.checked)} />ฉันดาวน์โหลด ZIP เสร็จแล้ว และเก็บสำเนาไว้นอกระบบนี้</label>}
          <FormField label={`พิมพ์ ${expected} เพื่อยืนยัน`}><Input autoComplete="off" spellCheck={false} value={confirmation} onChange={event => setConfirmation(event.target.value)} placeholder={expected} /></FormField>
        </>
      })()}
      <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><Dialog.Close asChild><Button variant="outline">ยกเลิก</Button></Dialog.Close><Button variant={review?.mode === 'archive' ? 'destructive' : 'default'} disabled={!canConfirm || busy} onClick={confirm}>{review?.mode === 'archive' ? 'ยืนยันเก็บภาคเรียน' : 'ยืนยันนำข้อมูลกลับ'}</Button></div>
    </Dialog.Content></Dialog.Portal></Dialog.Root>
  </AppShell>
}
