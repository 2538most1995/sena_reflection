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
import { appUrl } from '@/lib/app-url'
import { backupRequest as request, type BackupPreflight } from '@/lib/semester-backup-api'
import { SemesterTermRepair } from '@/components/semester-term-repair'
import { uploadSemesterZip } from '@/lib/semester-backup-upload'

type Counts = { records: number; attendance: number; images: number; documents: number; revisions: number; missing_files?: number; asset_files?: number; format_version?: number }
type Semester = { year: string; semester: string; records: number; attendance: number; images: number; active_drafts: number }
type Backup = {
  id: string; year: string; semester: string; status: 'ready' | 'archived' | 'restored' | 'imported' | 'offline'; counts: Counts; size_bytes: number
  created_at: string; archived_at: string | null; restored_at: string | null; downloaded_at: string | null
  download_url: string; archive_confirmation: string; restore_confirmation: string; release_confirmation: string; offload_confirmation: string; closeout_confirmation: string
}
type ActivePeriod = { term: string; year: string; semester: string; source: string; notice: string | null }
type Props = { semesters: Semester[]; backups: Backup[]; activePeriod?: ActivePeriod; limits: { max_upload_mb: number; max_archive_mb?: number }; backupPagination?: { page: number; last_page: number; total: number; per_page: number } }
type StorageInspection = { releasable_count: number; releasable_bytes: number; shared_count: number; changed_count: number; missing_count: number; deleted_count?: number; deleted_bytes?: number; failed_count?: number }
type PendingUpload = { id: string; name: string; uploaded_parts: number; parts: number; total_bytes: number }
type Inspection = { backup: Backup; can_archive: boolean; can_restore: boolean; can_closeout: boolean; closeout_blockers: string[]; draft_review: { count: number; fingerprint: string }; blockers: string[]; conflicts: string[]; missing_files: { disk: string; path: string }[]; storage: StorageInspection | null }
type Review = { backup: Backup; mode: 'archive' | 'restore' | 'release' | 'offload' | 'closeout' }
const base = '/admin/semester-backups'
const statuses = { ready: 'สำรองแล้ว · ข้อมูลเดิมยังอยู่', archived: 'ล้างบันทึกออกจากระบบแล้ว', restored: 'นำข้อมูลกลับแล้ว', imported: 'ตรวจไฟล์นำเข้าแล้ว', offline: 'เก็บ ZIP ไว้นอกเซิร์ฟเวอร์' }
const count = (value: number) => value.toLocaleString('th-TH')
const term = (value: { semester: string; year: string }) => `${value.semester}/${value.year}`
const periodOrder = (value: { semester: string; year: string }) => /^25\d{2}$/.test(value.year) && /^[123]$/.test(value.semester) ? Number(value.year) * 10 + Number(value.semester) : 0
const size = (value: number) => `${(value / (value < 1024 * 1024 ? 1024 : 1024 * 1024)).toLocaleString('th-TH', { maximumFractionDigits: 1 })} ${value < 1024 * 1024 ? 'KB' : 'MB'}`
const date = (value: string) => new Intl.DateTimeFormat('th-TH', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value.replace(' ', 'T')))

function BackupCounts({ counts }: { counts: Counts }) {
  return <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
    {([['บันทึก', counts.records], ['รายชื่อเข้าเรียน', counts.attendance], ['รูปกิจกรรม', counts.images], ['เอกสารจัดหน้า', counts.documents], ['ฉบับย้อนหลัง', counts.revisions]] as const).map(([label, value]) => <div key={label}><dt className="text-xs text-muted-foreground">{label}</dt><dd className="mt-0.5 font-semibold tabular-nums">{count(value)}</dd></div>)}
  </dl>
}

export default function SemesterBackups(initial: Props) {
  const [data, setData] = useState(initial)
  const first = initial.semesters.find(period => initial.activePeriod && periodOrder(period) > 0 && periodOrder(period) < periodOrder(initial.activePeriod)) || initial.semesters[0]
  const [scope, setScope] = useState({ year: first?.year || String(new Date().getFullYear() + 543), semester: first?.semester || '1' })
  const [working, setBusy] = useState(false)
  const [repairBusy, setRepairBusy] = useState(false)
  const busy = working || repairBusy
  const busyRef = useRef(false)
  const [error, setError] = useState<string | null>(null)
  const [review, setReview] = useState<Review | null>(null)
  const [inspection, setInspection] = useState<Inspection | null>(null)
  const [inspectionError, setInspectionError] = useState<string | null>(null)
  const [confirmation, setConfirmation] = useState('')
  const [copied, setCopied] = useState(false)
  const [acceptMissing, setAcceptMissing] = useState(false)
  const [pauseDrafts, setPauseDrafts] = useState(false)
  const [offloadZip, setOffloadZip] = useState(true)
  const [restoredScope, setRestoredScope] = useState<{ year: string; semester: string } | null>(null)
  const [preflight, setPreflight] = useState<BackupPreflight | null>(null)
  const [preflightError, setPreflightError] = useState<string | null>(null)
  const [allowMissing, setAllowMissing] = useState(false)
  const [preflightAttempt, setPreflightAttempt] = useState(0)
  const uploadRef = useRef<HTMLInputElement>(null)
  const uploadController = useRef<AbortController | null>(null)
  const [uploadProgress, setUploadProgress] = useState<{ completed: number; total: number } | null>(null)
  const [pendingUploads, setPendingUploads] = useState<PendingUpload[]>([])
  useEffect(() => () => uploadController.current?.abort(), [])
  useEffect(() => { const controller = new AbortController(); request<{ uploads: PendingUpload[] }>(`${base}/uploads`, { signal: controller.signal }).then(result => { if (!controller.signal.aborted) setPendingUploads(result.uploads) }).catch(() => {}); return () => controller.abort() }, [])
  const selected = data.semesters.find(item => item.year === scope.year && item.semester === scope.semester)
  const oldPeriods = data.activePeriod ? data.semesters.filter(period => periodOrder(period) > 0 && periodOrder(period) < periodOrder(data.activePeriod!)) : []
  const closeoutCandidate = data.backups.find(item => item.year === scope.year && item.semester === scope.semester && ['ready', 'imported', 'restored'].includes(item.status))
  useEffect(() => {
    const controller = new AbortController()
    setPreflight(null); setPreflightError(null); setAllowMissing(false)
    if (!selected?.records) return () => controller.abort()
    request<BackupPreflight>(`${base}/preflight?${new URLSearchParams(scope)}`, { signal: controller.signal }).then(result => { if (!controller.signal.aborted) setPreflight(result) }).catch(reason => { if (!controller.signal.aborted) setPreflightError(reason instanceof Error ? reason.message : 'ตรวจไฟล์ต้นฉบับไม่สำเร็จ') })
    return () => controller.abort()
  }, [scope.year, scope.semester, selected?.records, preflightAttempt])
  const reload = async (page = 1) => {
    const [current, sessions] = await Promise.all([request<Props>(`${base}?backup_page=${page}`), request<{ uploads: PendingUpload[] }>(`${base}/uploads`).catch(() => null)])
    setData(current); if (sessions) setPendingUploads(sessions.uploads)
  }
  const changePage = async (page: number) => {
    if (busyRef.current) return
    busyRef.current = true; setBusy(true)
    try { await reload(page) } catch (reason) { actionAlert.error('โหลดชุดสำรองไม่สำเร็จ', reason instanceof Error ? reason.message : 'กรุณาลองอีกครั้ง') }
    finally { busyRef.current = false; setBusy(false) }
  }

  useEffect(() => {
    setInspection(null); setInspectionError(null); setConfirmation(''); setCopied(false); setAcceptMissing(false); setPauseDrafts(false); setOffloadZip(true)
    if (!review) return
    const controller = new AbortController()
    request<Inspection>(`${base}/${review.backup.id}`, { signal: controller.signal }).then(result => { if (!controller.signal.aborted) setInspection(result) }).catch(reason => {
      if (!controller.signal.aborted) setInspectionError(reason instanceof Error ? reason.message : 'ตรวจชุดสำรองไม่สำเร็จ')
    })
    return () => controller.abort()
  }, [review])

  const perform = async (title: string, operation: () => Promise<unknown>, success: string | (() => string), description: string | (() => string)) => {
    if (busyRef.current) return
    busyRef.current = true; setBusy(true); setError(null)
    const alert = actionAlert.loading(title, 'กรุณารอให้ระบบตรวจข้อมูลและไฟล์ครบก่อนปิดหน้านี้')
    try {
      await operation()
      await reload()
      const message = typeof description === 'function' ? description() : description
      actionAlert.success(typeof success === 'function' ? success() : success, message, alert)
    } catch (reason) {
      const message = reason instanceof Error ? reason.message : 'ดำเนินการไม่สำเร็จ'
      setError(message); actionAlert.error('ดำเนินการไม่สำเร็จ', message, alert)
      setPreflightAttempt(value => value + 1)
      await reload().catch(() => {})
    } finally { busyRef.current = false; setBusy(false) }
  }

  const create = (event: FormEvent) => {
    event.preventDefault()
    void perform('กำลังสร้างชุดสำรอง', () => request(base, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...scope, allow_missing: allowMissing, preflight_fingerprint: preflight?.fingerprint }) }), 'สร้าง ZIP แล้ว · ข้อมูลเดิมยังอยู่', 'ขั้นต่อไป: ดาวน์โหลด ZIP ให้เสร็จ แล้วกด ล้างข้อมูลที่สำรองแล้ว เพื่อเอาบันทึกออกจากระบบและคืนพื้นที่รูป')
  }
  const upload = (file?: File) => {
    if (!file || busyRef.current) return
    const maximum = data.limits.max_archive_mb ?? data.limits.max_upload_mb
    if (file.size > maximum * 1024 * 1024) { actionAlert.error('ไฟล์ใหญ่เกินกำหนด', `นำเข้าไฟล์ได้ไม่เกิน ${maximum} MB`); return }
    uploadController.current = new AbortController()
    void perform('กำลังอัปโหลดและตรวจชุดสำรอง', async () => {
      try { return await uploadSemesterZip(file, request, (completed, total) => setUploadProgress({ completed, total }), uploadController.current!.signal) }
      finally { setUploadProgress(null); uploadController.current = null }
    }, 'ตรวจไฟล์นำเข้าแล้ว', 'ข้อมูลยังไม่ถูกนำกลับ กดตรวจและนำกลับเพื่อตรวจรายการก่อนยืนยัน')
  }
  const titles = { archive: 'ล้างเฉพาะบันทึก', restore: 'นำข้อมูลกลับ', release: 'คืนพื้นที่รูปต้นฉบับ', offload: 'ลบ ZIP บนเซิร์ฟเวอร์', closeout: 'ล้างข้อมูลที่สำรองแล้ว' }
  const confirm = () => {
    if (!review || !inspection || busyRef.current) return
    const { backup, mode } = review
    setReview(null)
    let released: StorageInspection | null = null
    let cleanupError: string | null = null
    let serverZipRemoved = false
    let offloadError: string | null = null
    let paused = 0
    void perform(`กำลัง${titles[mode]}`, async () => {
      const result = await request<{ storage?: StorageInspection; cleanup_error?: string | null; paused_drafts?: number; server_zip_removed?: boolean; offload_error?: string | null }>(`${base}/${backup.id}/${mode}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ confirmation, external_copy: copied, accept_missing_files: acceptMissing, pause_active_drafts: mode === 'closeout' && pauseDrafts, draft_fingerprint: mode === 'closeout' && pauseDrafts ? inspection.draft_review.fingerprint : null, offload_server_zip: mode === 'closeout' && offloadZip }) })
      released = result.storage || null
      cleanupError = result.cleanup_error || null; paused = result.paused_drafts || 0
      serverZipRemoved = Boolean(result.server_zip_removed); offloadError = result.offload_error || null
      if (mode === 'restore') setRestoredScope({ year: backup.year, semester: backup.semester })
    }, () => cleanupError || offloadError ? 'ล้างบันทึกแล้ว · คืนพื้นที่ยังไม่ครบ' : mode === 'closeout' ? 'ล้างข้อมูลภาคเรียนแล้ว' : `${titles[mode]}แล้ว`, () => cleanupError || offloadError || (mode === 'closeout' ? `นำ ${count(backup.counts.records)} บันทึกออกจากระบบแล้ว พักร่าง ${paused} ร่างโดยเก็บงานส่วนตัวไว้ครบ ${serverZipRemoved ? `ลบ ZIP สำเนาบนเซิร์ฟเวอร์ คืนเพิ่ม ${size(backup.size_bytes)} แล้ว เก็บ ZIP ในเครื่องไว้สำหรับนำกลับ ` : ''}` : '') + ((mode === 'release' || mode === 'closeout') && released ? `ลบ ${released.deleted_count || 0} ไฟล์ คืนพื้นที่ ${size(released.deleted_bytes || 0)} คงไฟล์ที่ใช้ร่วมกันหรือเปลี่ยนหลังสำรองไว้ ลบไม่สำเร็จ ${released.failed_count || 0} ไฟล์ สามารถตรวจและลองใหม่ได้` : mode === 'offload' ? 'นำกลับได้โดยอัปโหลด ZIP ที่ดาวน์โหลดเก็บไว้ พร้อมใช้ APP_KEY เดิมของระบบ' : mode === 'restore' ? 'กดเปิดดูภาคเรียนที่นำกลับในหน้านี้ เพื่อดู แก้ไข และพิมพ์เอกสารย้อนหลังได้' : 'ข้อมูลอยู่ในชุดสำรองแล้ว กดคืนพื้นที่รูปต้นฉบับเพื่อลบเฉพาะไฟล์ที่ไม่มีงานอื่นใช้'))
  }
  const expected = review ? ({ archive: review.backup.archive_confirmation, restore: review.backup.restore_confirmation, release: review.backup.release_confirmation, offload: review.backup.offload_confirmation, closeout: review.backup.closeout_confirmation })[review.mode] : ''
  const canConfirm = Boolean(inspection && review && confirmation === expected && (review.mode === 'closeout' ? inspection.can_closeout && copied && (!inspection.missing_files.length || acceptMissing) && (!inspection.draft_review.count || pauseDrafts || review.backup.status === 'archived') : review.mode === 'archive' ? inspection.can_archive && copied && (!inspection.missing_files.length || acceptMissing) : review.mode === 'restore' ? inspection.can_restore : review.mode === 'release' ? copied && (inspection.storage?.releasable_count || 0) > 0 : copied && Boolean(inspection.backup.downloaded_at)))

  return <AppShell>
    <Head title="สำรองข้อมูลภาคเรียน" />
    <PageHeader title="สำรองข้อมูลภาคเรียน" description="จัดการบันทึกทุกตำบลแยกตามภาคเรียน เก็บและนำกลับมาเรียกดูได้เมื่อจำเป็น" action={<Button asChild variant="outline"><AppLink href="/admin"><ArrowLeft size={17} />จัดการระบบ</AppLink></Button>} />
    {error && <div role="alert" className="mb-5 flex items-start gap-3 whitespace-pre-line rounded-xl border border-destructive/25 bg-destructive/5 p-4 text-sm text-destructive"><WarningCircle size={20} className="shrink-0" />{error}</div>}
    {data.activePeriod && <section className="mb-6 rounded-xl border bg-card p-5" aria-label="เริ่มภาคเรียนใหม่"><h2 className="font-semibold">ภาคเรียนใช้งาน {data.activePeriod.term}</h2><p className="mt-2 text-sm leading-7 text-muted-foreground">{oldPeriods.length ? `ยังมีบันทึกภาคเรียนเก่า ${count(oldPeriods.reduce((sum, period) => sum + period.records, 0))} บันทึก ใน ${oldPeriods.length} ภาคเรียน เลือกแต่ละภาคเรียนเพื่อสำรอง ดาวน์โหลด และล้างข้อมูลออกจากเซิร์ฟเวอร์` : 'ไม่พบบันทึกภาคเรียนก่อนหน้าในระบบ พร้อมใช้งานภาคเรียนปัจจุบัน'}</p>{data.activePeriod.notice && <p className="mt-2 text-sm text-amber-700 dark:text-amber-300">{data.activePeriod.notice}</p>}<div className="mt-3 flex flex-wrap gap-2">{oldPeriods.map(period => <Button key={`${period.year}|${period.semester}`} variant={scope.year === period.year && scope.semester === period.semester ? 'secondary' : 'outline'} size="sm" disabled={busy} onClick={() => setScope({ year: period.year, semester: period.semester })}>สำรอง {term(period)} · {count(period.records)} บันทึก</Button>)}<Button asChild size="sm" variant="outline"><AppLink href="/records/create">สร้างบันทึกภาคเรียนใหม่</AppLink></Button></div></section>}
    {restoredScope && <section className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-primary/25 bg-primary/5 p-4"><p className="text-sm">นำภาคเรียน {term(restoredScope)} กลับแล้ว เปิดดูข้อมูลย้อนหลังได้โดยไม่เปลี่ยนภาคเรียนเริ่มต้นของงานใหม่</p><Button asChild size="sm"><AppLink href={`/?${new URLSearchParams(restoredScope)}`}>เปิดดูภาคเรียนที่นำกลับ</AppLink></Button></section>}
    <div className="mb-6 rounded-xl border bg-card p-4 text-sm leading-7"><b>ปิดภาคเรียนและคืนพื้นที่</b><p className="text-muted-foreground">1. สร้าง ZIP → 2. ดาวน์โหลดให้เสร็จ → 3. กดล้างข้อมูลที่สำรองแล้ว เพื่อล้างบันทึกและคืนพื้นที่รูปในครั้งเดียว เมื่อตรวจ ZIP ที่เก็บไว้นอกระบบแล้ว ลบ ZIP สำเนาบนเซิร์ฟเวอร์เพื่อคืนพื้นที่ส่วนนี้ได้ด้วย</p></div>
    {closeoutCandidate && selected?.records ? <section className="mb-6 flex flex-col gap-4 rounded-xl border border-primary/25 bg-primary/5 p-5 sm:flex-row sm:items-center sm:justify-between" aria-label="ขั้นตอนล้างหลังสำรอง"><div><h2 className="font-semibold">ภาคเรียน {term(scope)} สำรองแล้ว · ยังมี {count(selected.records)} บันทึกอยู่ในระบบ</h2><p className="mt-1 text-sm text-muted-foreground">ดาวน์โหลด ZIP ให้เสร็จ แล้วกดปุ่มนี้เพื่อเอาข้อมูลภาคเรียนเก่าออกจากภาพรวมและคืนพื้นที่รูป</p></div><Button variant="destructive" disabled={busy} className="shrink-0" onClick={() => setReview({ backup: closeoutCandidate, mode: 'closeout' })}>ล้างข้อมูลที่สำรองแล้ว</Button></section> : null}
    <SemesterTermRepair semesters={data.semesters} busy={busy} onBusyChange={value => { busyRef.current = value; setRepairBusy(value) }} onComplete={async target => { await reload(); setScope(target) }} />
    <div className="mb-6 grid gap-4 lg:grid-cols-2">
      <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><Archive size={20} className="text-primary" />สำรองภาคเรียน</CardTitle><p className="text-sm text-muted-foreground">ZIP รวมบันทึกและรูปที่เปิดดูได้ แตกไฟล์แล้วเปิด index.html เพื่ออ่านภาษาไทย หรือเก็บ ZIP เดิมไว้เพื่อนำกลับเข้าระบบ</p></CardHeader><CardContent>
        <form onSubmit={create} className="grid gap-4">
          <div className="grid grid-cols-2 gap-3"><FormField label="ปีการศึกษา (พ.ศ.)"><NativeSelect value={scope.year} disabled={busy} onChange={event => setScope({ ...scope, year: event.target.value })}>{[...new Set([...data.semesters.map(item => item.year), scope.year])].map(year => <option key={year} value={year}>{year}{!/^25\d{2}$/.test(year) ? ' (รูปแบบปีเดิมผิด)' : ''}</option>)}</NativeSelect></FormField><FormField label="ภาคเรียน"><NativeSelect value={scope.semester} disabled={busy} onChange={event => setScope({ ...scope, semester: event.target.value })}>{!['1', '2', '3'].includes(scope.semester) && <option value={scope.semester}>{scope.semester}</option>}<option value="1">ภาคเรียน 1</option><option value="2">ภาคเรียน 2</option><option value="3">ภาคเรียน 3 (ข้อมูลเดิม)</option></NativeSelect></FormField></div>
          <div className="rounded-lg bg-muted/50 p-3 text-sm"><b>{count(selected?.records || 0)} บันทึก</b><span className="text-muted-foreground"> · {count(selected?.attendance || 0)} รายชื่อเข้าเรียน · {count(selected?.images || 0)} รูป</span>{Boolean(selected?.active_drafts) && <p className="mt-2 text-amber-700 dark:text-amber-300">มีร่างค้าง {count(selected!.active_drafts)} ร่าง เลือกพักร่างพร้อมล้างภาคเรียนได้ ข้อความและรูปส่วนตัวจะยังอยู่ครบ · <AppLink href="/teaching-drafts/manage" className="underline">จัดการร่างของฉัน</AppLink></p>}</div>
          {!preflight && !preflightError && selected?.records ? <p role="status" className="text-sm text-muted-foreground">กำลังตรวจรูปและเอกสารทั้งหมดในภาคเรียน…</p> : null}
          {preflightError && <p role="alert" className="whitespace-pre-line text-sm text-destructive">{preflightError}</p>}
          <Button type="button" size="sm" variant="ghost" disabled={busy || !selected?.records} onClick={() => setPreflightAttempt(value => value + 1)}>ตรวจไฟล์ต้นฉบับใหม่</Button>
          {preflight && <div className="grid gap-3 rounded-lg border p-3 text-sm">
            <p>ไฟล์ที่สำรองได้ <b>{count(preflight.available_files)} ไฟล์ · {size(preflight.total_bytes)}</b> รวมข้อความ รายชื่อ รูป และเอกสารจัดหน้า</p>
            {!preflight.fits_limit && <p role="alert" className="text-destructive">ข้อมูลเกินขนาด ZIP ที่ระบบรองรับ ข้อมูลยังไม่ถูกลบ</p>}
            {preflight.missing_files.length > 0 && <><p className="font-medium text-amber-700 dark:text-amber-300">รูปต้นฉบับสูญหายก่อนสำรอง {count(preflight.missing_files.length)} ไฟล์</p><details><summary className="cursor-pointer">ดูรายการไฟล์ที่ขาด</summary><ul className="mt-2 max-h-32 overflow-auto break-all text-xs">{preflight.missing_files.map(file => <li key={`${file.disk}:${file.path}`}>{file.path}</li>)}</ul></details><label className="flex items-start gap-2"><input type="checkbox" className="mt-1" checked={allowMissing} onChange={event => setAllowMissing(event.target.checked)} />ฉันรับทราบว่า ZIP จะสำรองข้อความและไฟล์ที่ยังมีอยู่ทั้งหมด พร้อมรายงานไฟล์ที่ขาด โดยไม่สามารถกู้รูปที่สูญหายไปแล้ว</label></>}
          </div>}
          <Button type="submit" disabled={busy || !selected?.records || !preflight || !preflight.fits_limit || (preflight.missing_files.length > 0 && !allowMissing)}><ShieldCheck size={19} />สร้างชุดสำรอง {scope.semester}/{scope.year}</Button>
        </form>
      </CardContent></Card>
      <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><UploadSimple size={20} className="text-primary" />นำเข้าชุดสำรอง</CardTitle><p className="text-sm text-muted-foreground">เลือก ZIP ที่สร้างจากระบบนี้ ระบบตรวจไฟล์ก่อนนำข้อมูลกลับ</p></CardHeader><CardContent className="grid gap-4">
        <div className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">รองรับ ZIP ทั้งภาคเรียนไม่เกิน {data.limits.max_archive_mb ?? data.limits.max_upload_mb} MB อัปโหลดทีละส่วนเพื่อลดภาระโฮสต์ การอัปโหลดเป็นการตรวจไฟล์เท่านั้น ต้องยืนยันอีกครั้งก่อนนำข้อมูลกลับ</div>
        <input ref={uploadRef} type="file" accept=".zip,application/zip" aria-label="เลือกไฟล์สำรอง ZIP" className="block w-full text-sm file:mr-3 file:rounded-lg file:border file:border-input file:bg-card file:px-3 file:py-2 file:text-foreground" disabled={busy} onChange={event => { upload(event.target.files?.[0]); event.target.value = '' }} />
        {uploadProgress && <div role="status" className="text-sm"><p>อัปโหลดส่วนที่ {uploadProgress.completed}/{uploadProgress.total} กรุณาเปิดหน้านี้ไว้จนตรวจไฟล์เสร็จ</p><progress className="mt-2 w-full accent-primary" value={uploadProgress.completed} max={uploadProgress.total} /></div>}
        {pendingUploads.length > 0 && <div className="grid gap-2 rounded-lg border p-3 text-sm"><b>การอัปโหลดที่ยังไม่เสร็จ</b>{pendingUploads.map(upload => <div key={upload.id} className="flex flex-wrap items-center justify-between gap-2"><span className="break-all">{upload.name} · {upload.uploaded_parts}/{upload.parts} ส่วน</span><Button size="sm" variant="outline" disabled={busy} onClick={() => void perform('กำลังยกเลิกอัปโหลดค้าง', () => request(`${base}/uploads/${upload.id}`, { method: 'DELETE' }), 'ล้างไฟล์อัปโหลดค้างแล้ว', 'เลือกไฟล์ ZIP เพื่อเริ่มอัปโหลดใหม่ได้ ข้อมูลบันทึกเดิมยังอยู่')}>ยกเลิกและคืนพื้นที่ชั่วคราว</Button></div>)}</div>}
        <p className="flex items-start gap-2 text-xs leading-relaxed text-muted-foreground"><ShieldCheck size={17} className="shrink-0" />นำกลับได้เมื่อไม่ชนกับข้อมูลปัจจุบัน ระบบจะไม่เขียนทับงานที่มีอยู่</p>
      </CardContent></Card>
    </div>
    <Card className="mb-6"><CardHeader><CardTitle className="text-base">ภาคเรียนที่มีข้อมูลอยู่ในระบบ</CardTitle></CardHeader><CardContent>
      <div className="flex flex-wrap gap-2">{data.semesters.length ? data.semesters.map(item => <Button key={`${item.year}|${item.semester}`} type="button" variant={item.year === scope.year && item.semester === scope.semester ? 'secondary' : 'outline'} size="sm" disabled={busy} onClick={() => setScope({ year: item.year, semester: item.semester })}>{term(item)} · {count(item.records)} บันทึก</Button>) : <p className="text-sm text-muted-foreground">ยังไม่มีบันทึกในระบบ</p>}</div>
    </CardContent></Card>
    <Card><CardHeader className="flex-row items-center justify-between gap-3"><div><CardTitle className="text-base">ชุดสำรองที่เก็บไว้</CardTitle><p className="mt-1 text-sm text-muted-foreground">ดาวน์โหลด ตรวจรายการ และนำกลับมาใช้งานจากที่เดียว</p></div><Button variant="ghost" size="icon" disabled={busy} aria-label="รีเฟรชชุดสำรอง" onClick={() => { void changePage(data.backupPagination?.page || 1) }}><ArrowClockwise size={19} /></Button></CardHeader><CardContent className="grid gap-4">
      {data.backups.length ? data.backups.map(backup => <article key={backup.id} className="rounded-xl border p-4 sm:p-5">
        <div className="flex flex-wrap items-start justify-between gap-3"><div className="flex items-start gap-3"><span className="grid size-10 shrink-0 place-items-center rounded-lg bg-accent text-primary"><FileZip size={22} /></span><div><h2 className="font-semibold">ภาคเรียน {term(backup)}</h2><p className="mt-1 text-xs text-muted-foreground">{date(backup.created_at)} · {size(backup.size_bytes)}</p></div></div><Badge variant={backup.status === 'archived' ? 'secondary' : 'outline'}>{statuses[backup.status]}</Badge></div>
        <div className="my-5"><BackupCounts counts={backup.counts} /></div>
        {Boolean(backup.counts.missing_files) && <p className="mb-4 text-sm text-amber-700 dark:text-amber-300">ชุดนี้มีรายงานรูปต้นฉบับที่ขาดก่อนสำรอง {count(backup.counts.missing_files!)} ไฟล์</p>}
          {(backup.counts.format_version || 1) >= 3 && <p className="mb-4 text-sm text-muted-foreground">เปิดอ่านภาษาไทยจาก index.html · รูปอยู่ในโฟลเดอร์ images พร้อมนามสกุล</p>}
        <div className="flex flex-col gap-2 border-t pt-4 sm:flex-row sm:flex-wrap">
          {backup.status !== 'offline' && <>
          <Button asChild variant="outline"><a href={appUrl(backup.download_url)} onClick={event => { if (busy) { event.preventDefault(); return } actionAlert.info('กำลังส่งไฟล์สำรองให้ดาวน์โหลด', 'เก็บ ZIP ในที่ปลอดภัย และตรวจว่าไฟล์ดาวน์โหลดเสร็จก่อนเก็บภาคเรียน') }}><DownloadSimple size={18} />ดาวน์โหลด ZIP</a></Button>
          {backup.status !== 'archived' && <Button type="button" variant="destructive" disabled={busy} onClick={() => setReview({ backup, mode: 'closeout' })}><Archive size={18} />ล้างข้อมูลที่สำรองแล้ว</Button>}
          <Button type="button" variant="secondary" disabled={busy} onClick={() => setReview({ backup, mode: 'restore' })}><ClockCounterClockwise size={18} />ตรวจและนำกลับ</Button>
          {backup.status === 'archived' && <Button type="button" variant="outline" disabled={busy} onClick={() => setReview({ backup, mode: 'release' })}>ตรวจและคืนพื้นที่รูป</Button>}
          <Button type="button" variant="outline" disabled={busy || !backup.downloaded_at} onClick={() => setReview({ backup, mode: 'offload' })}>ลบ ZIP บนเซิร์ฟเวอร์</Button>
          </>}
          {backup.status === 'offline' && <p className="text-sm text-muted-foreground">ZIP เก็บไว้ภายนอกแล้ว เมื่อต้องการใช้ข้อมูล ให้นำเข้าไฟล์ ZIP เดิมจากคอมพิวเตอร์</p>}
        </div>
        {backup.downloaded_at && <p className="mt-3 flex items-center gap-1.5 text-xs text-muted-foreground"><CheckCircle size={15} />ส่งไฟล์ดาวน์โหลดล่าสุด {date(backup.downloaded_at)}</p>}
      </article>) : <div className="rounded-xl border border-dashed p-8 text-center"><Archive size={30} className="mx-auto text-muted-foreground" /><b className="mt-3 block text-sm">ยังไม่มีชุดสำรอง</b><p className="mt-1 text-sm text-muted-foreground">เลือกภาคเรียนด้านบนเพื่อเริ่มสำรองข้อมูล</p></div>}
      {data.backupPagination && data.backupPagination.last_page > 1 && <nav aria-label="หน้ารายการชุดสำรอง" className="flex flex-wrap items-center justify-center gap-3 border-t pt-4"><Button variant="outline" size="sm" disabled={busy || data.backupPagination.page <= 1} onClick={() => void changePage(data.backupPagination!.page - 1)}>ก่อนหน้า</Button><span className="text-sm text-muted-foreground" aria-live="polite">หน้า {count(data.backupPagination.page)} / {count(data.backupPagination.last_page)} · {count(data.backupPagination.total)} ชุด</span><Button variant="outline" size="sm" disabled={busy || data.backupPagination.page >= data.backupPagination.last_page} onClick={() => void changePage(data.backupPagination!.page + 1)}>ถัดไป</Button></nav>}
      <p className="text-xs leading-relaxed text-muted-foreground">ชุดสำรองนี้ใช้กับข้อมูลบันทึกหลังสอน ไม่รวมบัญชี รหัสผ่าน การตั้งค่าระบบ และร่างส่วนตัว เก็บไฟล์ฐานข้อมูลและกุญแจ APP_KEY แยกไว้สำหรับกู้คืนทั้งระบบ คืนพื้นที่ได้เฉพาะรูปที่มีสำเนาใน ZIP และไม่มีบันทึก เอกสาร หรือร่างอื่นใช้อยู่ ลบ ZIP บนเซิร์ฟเวอร์แยกได้เมื่อมีสำเนาภายนอกแล้ว</p>
    </CardContent></Card>
    <Dialog.Root open={Boolean(review)} onOpenChange={open => { if (!open) setReview(null) }}><Dialog.Portal><Dialog.Overlay className="fixed inset-0 z-40 bg-foreground/35 backdrop-blur-sm" /><Dialog.Content className="fixed left-1/2 top-1/2 z-50 max-h-[90dvh] w-[min(94vw,560px)] -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl border bg-card p-5 shadow-2xl outline-none sm:p-6">
      <div className="flex items-start justify-between gap-3"><Dialog.Title className="text-lg font-semibold">{review ? titles[review.mode] : ''} {review && term(review.backup)}</Dialog.Title><Dialog.Close asChild><Button variant="ghost" size="icon" className="size-8" aria-label="ปิดหน้าตรวจชุดสำรอง"><X size={19} /></Button></Dialog.Close></div>
      <Dialog.Description className="mt-2 text-sm leading-relaxed text-muted-foreground">{review?.mode === 'closeout' ? 'เอาบันทึก รายชื่อ และเอกสารของภาคเรียนนี้ออกจากภาพรวม พร้อมลบรูปต้นฉบับที่สำรองแล้วและไม่มีงานอื่นใช้ เก็บ ZIP ไว้เพื่อนำกลับ ร่างส่วนตัวยังคงอยู่และสามารถเลือกพักไว้ได้' : review?.mode === 'archive' ? 'บันทึกทุกตำบลในภาคเรียนนี้จะออกจากภาพรวม เรียกกลับด้วย ZIP ได้ รูปต้นฉบับจะยังอยู่จนกดคืนพื้นที่แยกต่างหาก' : review?.mode === 'release' ? 'ลบเฉพาะไฟล์ต้นฉบับที่มีใน ZIP และไม่มีงานอื่นใช้ ตรวจ checksum ซ้ำก่อนลบ เรียกรูปกลับได้เมื่อนำเข้าชุดสำรอง' : review?.mode === 'offload' ? 'ลบเฉพาะ ZIP สำเนาบนเซิร์ฟเวอร์ เมื่อจะนำกลับต้องอัปโหลด ZIP ที่เก็บไว้ในคอมพิวเตอร์ และใช้ APP_KEY เดิม' : 'ข้อมูลจะกลับเข้าไปในภาพรวมและเอกสารของภาคเรียนเดิม ต้องไม่มีรหัสรายการชนกับงานปัจจุบัน'}</Dialog.Description>
      <div className="my-5 rounded-lg bg-muted/40 p-4">{review && <BackupCounts counts={review.backup.counts} />}</div>
      {!inspection && !inspectionError && <p role="status" className="my-5 text-sm text-muted-foreground">กำลังตรวจไฟล์และข้อมูลปัจจุบัน…</p>}
      {inspectionError && <p role="alert" className="my-5 whitespace-pre-line text-sm text-destructive">{inspectionError}</p>}
      {inspection && (() => {
        const issues = review?.mode === 'closeout' ? inspection.closeout_blockers : review?.mode === 'archive' ? inspection.blockers : review?.mode === 'restore' ? inspection.conflicts : []
        return <>{issues.length > 0 && <div role="alert" className="mb-4 rounded-lg border border-amber-300/60 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-200"><b>ต้องจัดการก่อนดำเนินการ</b><ul className="mt-2 list-disc space-y-1 pl-5">{issues.map((item, index) => <li key={index}>{item}</li>)}</ul></div>}
          {review?.mode !== 'restore' && <label className="mb-4 flex cursor-pointer items-start gap-2 text-sm"><input type="checkbox" className="mt-1 size-4 accent-primary" checked={copied} onChange={event => setCopied(event.target.checked)} />ฉันดาวน์โหลด ZIP เสร็จแล้ว และเก็บสำเนาไว้นอกระบบนี้</label>}
          {review?.mode === 'closeout' && inspection.draft_review.count > 0 && review.backup.status !== 'archived' && <label className="mb-4 flex items-start gap-2 rounded-lg bg-muted/50 p-3 text-sm"><input type="checkbox" className="mt-1" checked={pauseDrafts} onChange={event => setPauseDrafts(event.target.checked)} />พักร่างที่เกี่ยวข้อง {inspection.draft_review.count} ร่างเพื่อปิดภาคเรียน โดยเก็บข้อความ รูป และเอกสารส่วนตัวไว้ครบ เจ้าของนำกลับได้หลังนำภาคเรียนกลับจาก ZIP</label>}
          {review?.mode === 'closeout' && <label className="mb-4 flex items-start gap-2 rounded-lg border p-3 text-sm"><input type="checkbox" className="mt-1" checked={offloadZip} onChange={event => setOffloadZip(event.target.checked)} />ลบ ZIP สำเนาบนเซิร์ฟเวอร์หลังล้างข้อมูลและรูปสำเร็จ เพื่อคืนพื้นที่เพิ่ม เก็บ ZIP ที่ดาวน์โหลดไว้ในเครื่องให้ปลอดภัย เมื่อต้องการดูข้อมูลเก่าให้อัปโหลด ZIP เดิมกลับ</label>}
          {(review?.mode === 'archive' || review?.mode === 'closeout') && inspection.missing_files.length > 0 && <label className="mb-4 flex items-start gap-2 text-sm text-amber-700 dark:text-amber-300"><input type="checkbox" className="mt-1" checked={acceptMissing} onChange={event => setAcceptMissing(event.target.checked)} />รับทราบว่ารูปต้นฉบับ {inspection.missing_files.length} ไฟล์สูญหายก่อนสำรอง และจะกู้กลับจาก ZIP ไม่ได้ ข้อความและข้อมูลอ้างอิงเดิมยังถูกเก็บไว้</label>}
          {review?.mode === 'release' && inspection.storage && <p className="mb-4 rounded-lg bg-muted/40 p-3 text-sm">คืนพื้นที่ได้ {inspection.storage.releasable_count} ไฟล์ · {size(inspection.storage.releasable_bytes)}<br />คงไฟล์ที่ใช้ร่วมกัน {inspection.storage.shared_count} ไฟล์ · เปลี่ยนหลังสำรอง {inspection.storage.changed_count} ไฟล์ · ไม่มีต้นฉบับแล้ว {inspection.storage.missing_count} ไฟล์</p>}
          <FormField label={`พิมพ์ ${expected} เพื่อยืนยัน`}><Input autoComplete="off" spellCheck={false} value={confirmation} onChange={event => setConfirmation(event.target.value)} placeholder={expected} /></FormField>
        </>
      })()}
      <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><Dialog.Close asChild><Button variant="outline">ยกเลิก</Button></Dialog.Close><Button variant={review?.mode !== 'restore' ? 'destructive' : 'default'} disabled={!canConfirm || busy} onClick={confirm}>{review ? `ยืนยัน${titles[review.mode]}` : 'ยืนยัน'}</Button></div>
    </Dialog.Content></Dialog.Portal></Dialog.Root>
  </AppShell>
}
