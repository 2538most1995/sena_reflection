import { useEffect, useRef, useState } from 'react'
import { WarningCircle } from '@phosphor-icons/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { NativeSelect } from '@/components/ui/native-select'
import { FormField } from '@/components/form-field'
import { actionAlert } from '@/lib/action-alert'
import { backupRequest, type BackupPreflight } from '@/lib/semester-backup-api'

type Period = { year: string; semester: string; records: number }
type Preview = { records: number; blockers: string[]; can_repair: boolean; fingerprint: string; expected_confirmation: string; preflight: BackupPreflight }
const label = (period: Period) => `${period.semester}/${period.year}`

export function SemesterTermRepair({ semesters, busy, onBusyChange, onComplete }: {
  semesters: Period[]; busy: boolean; onBusyChange: (value: boolean) => void
  onComplete: (target: { year: string; semester: string }) => Promise<void>
}) {
  const invalid = semesters.filter(period => !/^25\d{2}$/.test(period.year) || !['1', '2', '3'].includes(period.semester))
  const [source, setSource] = useState('')
  const [target, setTarget] = useState({ year: String(new Date().getFullYear() + 543), semester: '1' })
  const [preview, setPreview] = useState<Preview | null>(null)
  const [confirmation, setConfirmation] = useState('')
  const [allowMissing, setAllowMissing] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const working = useRef(false)
  const selected = invalid.find(period => `${period.semester}|${period.year}` === source)
  useEffect(() => { setPreview(null); setConfirmation(''); setAllowMissing(false); setError(null) }, [source, target.year, target.semester])

  if (!invalid.length) return null
  const body = selected ? { source_year: selected.year, source_semester: selected.semester, target_year: target.year, target_semester: target.semester } : null
  const run = async (repair: boolean) => {
    if (!body || busy || working.current || (repair && !preview)) return
    working.current = true; onBusyChange(true); setError(null)
    const alert = actionAlert.loading(repair ? 'กำลังสำรองข้อมูลเดิมและแก้ภาคเรียน' : 'กำลังตรวจข้อมูลภาคเรียน', 'ระบบจะตรวจรายการซ้ำและร่างที่ยังใช้งานก่อนดำเนินการ')
    try {
      if (repair) {
        await backupRequest('/admin/semester-backups/terms/repair', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...body, fingerprint: preview!.fingerprint, backup_fingerprint: preview!.preflight.fingerprint, allow_missing: allowMissing, confirmation }) })
        await onComplete(target)
        setPreview(null); setSource('')
        actionAlert.success('แก้ภาคเรียนแล้ว', 'เก็บ ZIP ของข้อมูลเดิมไว้แล้ว ตรวจบันทึกในภาคเรียนปลายทางและดาวน์โหลดชุดสำรองล่าสุดก่อนเก็บภาคเรียน', alert)
      } else {
        const result = await backupRequest<Preview>('/admin/semester-backups/terms/preview', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
        setPreview(result)
        actionAlert.info('ตรวจภาคเรียนแล้ว', `พบ ${result.records} บันทึก กรุณาตรวจต้นทางและปลายทางก่อนยืนยัน`)
        actionAlert.dismiss(alert)
      }
    } catch (reason) {
      const message = reason instanceof Error ? reason.message : 'ตรวจภาคเรียนไม่สำเร็จ'
      setError(message); actionAlert.error('ยังแก้ภาคเรียนไม่ได้', message, alert)
    } finally { working.current = false; onBusyChange(false) }
  }

  return <section aria-label="แก้ภาคเรียนที่กรอกผิด" className="mb-6 rounded-xl border border-amber-300/60 bg-amber-50/60 p-5 dark:bg-amber-950/15">
    <h2 className="flex items-center gap-2 font-semibold"><WarningCircle size={21} />พบปีหรือภาคเรียนที่กรอกผิด {invalid.length} กลุ่ม</h2>
    <p className="mt-2 text-sm text-muted-foreground">ตรวจและแก้ให้ถูกก่อนสำรองภาคเรียนทั้งหมด ระบบสร้าง ZIP ของข้อมูลเดิมก่อนย้าย และเก็บข้อความ รูป รายชื่อ และประวัติเดิมไว้</p>
    <div className="mt-4 grid gap-3 sm:grid-cols-3">
      <FormField label="ภาคเรียนเดิมที่ต้องแก้"><NativeSelect value={source} disabled={busy} onChange={event => setSource(event.target.value)}><option value="">เลือกภาคเรียนที่ผิด</option>{invalid.map(period => <option key={`${period.year}|${period.semester}`} value={`${period.semester}|${period.year}`}>{label(period)} · {period.records} บันทึก</option>)}</NativeSelect></FormField>
      <FormField label="ปีการศึกษาที่ถูกต้อง (พ.ศ.)"><NativeSelect value={target.year} disabled={busy} onChange={event => setTarget({ ...target, year: event.target.value })}>{Array.from({ length: 100 }, (_, i) => String(2500 + i)).reverse().map(year => <option key={year} value={year}>{year}</option>)}</NativeSelect></FormField>
      <FormField label="ภาคเรียนที่ถูกต้อง"><NativeSelect value={target.semester} disabled={busy} onChange={event => setTarget({ ...target, semester: event.target.value })}><option value="1">ภาคเรียน 1</option><option value="2">ภาคเรียน 2</option></NativeSelect></FormField>
    </div>
    <Button className="mt-4" variant="outline" disabled={busy || !body} onClick={() => void run(false)}>ตรวจรายการก่อนแก้</Button>
    {error && <p role="alert" className="mt-3 whitespace-pre-line text-sm text-destructive">{error}</p>}
    {preview && <div className="mt-4 grid gap-3 rounded-lg border bg-background p-4 text-sm">
      <b>{selected && label(selected)} → {target.semester}/{target.year} · {preview.records} บันทึก</b>
      {preview.blockers.length > 0 && <ul className="list-disc space-y-1 pl-5 text-destructive">{preview.blockers.map(item => <li key={item}>{item}</li>)}</ul>}
      {preview.preflight.missing_files.length > 0 && <label className="flex items-start gap-2 text-amber-800 dark:text-amber-300"><input type="checkbox" className="mt-1" checked={allowMissing} onChange={event => setAllowMissing(event.target.checked)} />ข้อมูลเดิมขาดรูป {preview.preflight.missing_files.length} ไฟล์ ฉันรับทราบว่า ZIP จะเก็บข้อมูลที่ยังอยู่และรายงานไฟล์ที่ขาด ไม่สามารถกู้ภาพที่สูญหายได้</label>}
      <FormField label={`พิมพ์ ${preview.expected_confirmation} เพื่อยืนยัน`}><Input autoComplete="off" value={confirmation} onChange={event => setConfirmation(event.target.value)} /></FormField>
      <Button disabled={busy || !preview.can_repair || !preview.preflight.fits_limit || confirmation !== preview.expected_confirmation || (preview.preflight.missing_files.length > 0 && !allowMissing)} onClick={() => void run(true)}>สำรองข้อมูลเดิมและยืนยันแก้ภาคเรียน</Button>
    </div>}
  </section>
}
