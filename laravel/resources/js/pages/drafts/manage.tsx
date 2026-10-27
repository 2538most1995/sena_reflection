import { Head } from '@inertiajs/react'
import * as Dialog from '@radix-ui/react-dialog'
import { ArrowLeft, ArrowClockwise, FileText, Pause, Play, X } from '@phosphor-icons/react'
import { useRef, useState } from 'react'
import { AppShell, PageHeader } from '@/components/app-shell'
import { AppLink } from '@/components/app-link'
import { FlashMessages } from '@/components/flash-messages'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { actionAlert } from '@/lib/action-alert'
import { backupRequest as request } from '@/lib/semester-backup-api'

type Draft = {
  id: string; status: 'active' | 'paused'; lock_version: number; images_count: number
  scope: { year: string | null; semester: string | null; week: number | null; tambon: string | null }
  source_record_id: string | null; last_saved_at: string | null
}
const base = '/teaching-drafts'
const saved = (value: string | null) => value ? new Date(value).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' }) : 'ยังไม่ระบุ'

export default function ManageDrafts({ drafts: initial }: { drafts: Draft[] }) {
  const [drafts, setDrafts] = useState(initial)
  const [review, setReview] = useState<Draft | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const working = useRef(false)
  const refresh = async () => { const result = await request<{ drafts: Draft[] }>(`${base}/manage`); setDrafts(result.drafts) }
  const reload = async () => {
    if (working.current) return
    working.current = true; setBusy(true); setError('')
    try { await refresh() }
    catch (reason) { setError(reason instanceof Error ? reason.message : 'โหลดรายการร่างไม่สำเร็จ') }
    finally { working.current = false; setBusy(false) }
  }
  const change = async () => {
    if (!review || working.current) return
    const draft = review
    const pause = draft.status === 'active'
    working.current = true; setBusy(true); setError(''); setReview(null)
    const alert = actionAlert.loading(pause ? 'กำลังพักร่าง' : 'กำลังนำร่างกลับ', 'เก็บข้อความ รูป และเอกสารเดิมไว้ครบ')
    try {
      await request(`${base}/${encodeURIComponent(draft.id)}/${pause ? 'pause' : 'resume'}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ lock_version: draft.lock_version }) })
      await refresh()
      actionAlert.success(pause ? 'พักร่างแล้ว' : 'นำร่างกลับแล้ว', pause ? 'ร่างจะไม่ขวางการเก็บภาคเรียน และยังอยู่ในหน้าจัดการร่างของคุณ' : 'เปิดร่างเพื่อแก้ไขต่อได้ ระบบยังไม่ยืนยันเป็นบันทึกจริง', alert)
    } catch (reason) {
      const message = reason instanceof Error ? reason.message : 'เปลี่ยนสถานะร่างไม่สำเร็จ'
      setError(message); actionAlert.error('ยังเปลี่ยนสถานะร่างไม่ได้', message, alert)
      await refresh().catch(() => {})
    } finally { working.current = false; setBusy(false) }
  }
  const active = drafts.filter(draft => draft.status === 'active').length
  return <AppShell>
    <Head title="จัดการร่างของฉัน" />
    <PageHeader title="จัดการร่างของฉัน" description="พักร่างที่ยังไม่ใช้เพื่อเก็บภาคเรียน โดยเก็บข้อความ รูป และเอกสารไว้ครบ" action={<Button asChild variant="outline"><AppLink href="/"><ArrowLeft size={17} />กลับภาพรวม</AppLink></Button>} />
    <FlashMessages />
    <div className="mb-6 rounded-xl border bg-card p-4 text-sm leading-7"><b>กำลังใช้งาน {active} ร่าง · พักไว้ {drafts.length - active} ร่าง</b><p className="text-muted-foreground">เห็นเฉพาะร่างของคุณ ร่างที่พักไว้จะหยุดการแก้ไขและยืนยันชั่วคราว เมื่อต้องการใช้ต่อให้นำร่างกลับ หากเก็บภาคเรียนแล้วต้องนำชุดสำรองกลับก่อน</p></div>
    {error && <p role="alert" className="mb-4 whitespace-pre-line rounded-xl border border-destructive/25 bg-destructive/5 p-4 text-sm text-destructive">{error}</p>}
    <div className="mb-4 flex justify-end"><Button size="sm" variant="outline" disabled={busy} onClick={() => void reload()}><ArrowClockwise size={17} />รีเฟรชรายการ</Button></div>
    {drafts.length ? <div className="grid gap-4 md:grid-cols-2">{drafts.map(draft => <article key={draft.id} className="rounded-xl border bg-card p-5">
      <div className="flex items-start justify-between gap-3"><h2 className="font-semibold">สัปดาห์ที่ {draft.scope.week || 'ยังไม่ระบุ'}</h2><Badge variant={draft.status === 'active' ? 'default' : 'outline'}>{draft.status === 'active' ? 'กำลังใช้งาน' : 'พักไว้'}</Badge></div>
      <p className="mt-2 text-sm text-muted-foreground">ภาคเรียน {draft.scope.semester || '—'}/{draft.scope.year || '—'} · {draft.images_count} รูป{draft.source_record_id ? ' · ร่างแก้ไขบันทึกเดิม' : ' · ร่างบันทึกใหม่'}</p>
      <p className="mt-1 text-xs text-muted-foreground">บันทึกล่าสุด {saved(draft.last_saved_at)}</p>
      <div className="mt-5 flex flex-wrap gap-2">{draft.status === 'active' && <Button asChild size="sm" disabled={busy}><AppLink href={`/records/create?draft=${encodeURIComponent(draft.id)}`} onClick={event => { if (busy) event.preventDefault() }}><FileText size={17} />เปิดแก้ไข</AppLink></Button>}<Button size="sm" variant="outline" disabled={busy} onClick={() => setReview(draft)}>{draft.status === 'active' ? <Pause size={17} /> : <Play size={17} />}{draft.status === 'active' ? 'พักร่าง' : 'นำร่างกลับมาแก้ไข'}</Button></div>
    </article>)}</div> : <div className="rounded-xl border border-dashed p-10 text-center"><FileText className="mx-auto text-muted-foreground" size={30} /><p className="mt-3 font-semibold">ไม่มีร่างที่กำลังใช้งานหรือพักไว้</p><p className="mt-2 text-sm text-muted-foreground">ร่างที่ยืนยันแล้วเปิดได้จากรายการบันทึกในภาพรวม</p></div>}
    <Dialog.Root open={Boolean(review)} onOpenChange={open => { if (!open) setReview(null) }}><Dialog.Portal><Dialog.Overlay className="fixed inset-0 z-40 bg-foreground/35 backdrop-blur-sm" /><Dialog.Content className="fixed left-1/2 top-1/2 z-50 max-h-[90dvh] w-[min(94vw,500px)] -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl border bg-card p-5 shadow-2xl outline-none sm:p-6">
      <div className="flex items-start justify-between gap-3"><Dialog.Title className="text-lg font-semibold">{review?.status === 'active' ? 'พักร่างนี้ไว้ก่อน?' : 'นำร่างกลับมาแก้ไข?'}</Dialog.Title><Dialog.Close asChild><Button size="icon" variant="ghost" className="size-8" aria-label="ปิดหน้าตรวจร่าง"><X size={19} /></Button></Dialog.Close></div>
      <Dialog.Description className="mt-3 text-sm leading-7 text-muted-foreground">{review?.status === 'active' ? 'พักฉบับที่บันทึกบนเซิร์ฟเวอร์ล่าสุด ร่างจะหยุดแก้ไขและยืนยันชั่วคราว ข้อความ รูป และเอกสารยังอยู่ครบ ร่างส่วนตัวไม่รวมใน ZIP ภาคเรียน และยังเก็บอยู่บนเซิร์ฟเวอร์' : 'ระบบตรวจต้นฉบับและภาคเรียนก่อนนำร่างกลับ หากต้นฉบับเปลี่ยนหรือเก็บภาคเรียนไปแล้ว ร่างจะยังพักไว้และข้อมูลเดิมไม่ถูกเขียนทับ'}</Dialog.Description>
      <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><Dialog.Close asChild><Button variant="outline">ยกเลิก</Button></Dialog.Close><Button disabled={busy} onClick={() => void change()}>{review?.status === 'active' ? 'ยืนยันพักร่าง' : 'ยืนยันนำร่างกลับ'}</Button></div>
    </Dialog.Content></Dialog.Portal></Dialog.Root>
  </AppShell>
}
