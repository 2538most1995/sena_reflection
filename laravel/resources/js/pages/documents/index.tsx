import { Head } from '@inertiajs/react'
import { FilePlus2, FileText, Search, Trash2 } from 'lucide-react'
import { useRef, useState } from 'react'
import { AppShell, PageHeader } from '@/components/app-shell'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { AppLink } from '@/components/app-link'
import { appRouter } from '@/lib/app-router'
import { appUrl } from '@/lib/app-url'
import { actionAlert } from '@/lib/action-alert'
import { documentRequest } from '@/lib/document-api'
import type { WebDocument } from '@/lib/document-model'

type Entry = Pick<WebDocument, 'id' | 'title' | 'revision' | 'updatedAt'> & { bound?: boolean }
export default function DocumentsIndex({ documents: initial }: { documents: Entry[] }) {
  const [documents, setDocuments] = useState(initial)
  const [query, setQuery] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [pendingDelete, setPendingDelete] = useState<string | null>(null)
  const busyRef = useRef(false)
  const create = async () => {
    if (busyRef.current) return
    busyRef.current = true
    setBusy(true); setError('')
    const alertId = actionAlert.loading('กำลังสร้างเอกสาร', 'กำลังเตรียมเอกสารฉบับใหม่ให้คุณ')
    try {
      const result = await documentRequest<{ document: WebDocument }>('/api/documents', { method: 'POST', body: JSON.stringify({ title: 'เอกสารไม่มีชื่อ' }) })
      actionAlert.success('สร้างเอกสารแล้ว', 'เอกสารฉบับใหม่พร้อมให้แก้ไข', alertId)
      appRouter.visit(`/documents/${result.document.id}/edit`)
    } catch (error) {
      const message = error instanceof Error ? error.message : 'สร้างเอกสารไม่สำเร็จ'
      busyRef.current = false
      setError(message); setBusy(false)
      actionAlert.error('สร้างเอกสารไม่สำเร็จ', message, alertId)
    }
  }
  const remove = async (id: string) => {
    if (busyRef.current) return
    busyRef.current = true
    setBusy(true); setError('')
    const alertId = actionAlert.loading('กำลังลบเอกสาร', 'กำลังลบเอกสารที่คุณเลือก')
    try {
      await documentRequest(`/api/documents/${id}`, { method: 'DELETE' })
      setDocuments((current) => current.filter((entry) => entry.id !== id)); setPendingDelete(null)
      actionAlert.success('ลบเอกสารแล้ว', 'นำเอกสารออกจากรายการเรียบร้อย', alertId)
    }
    catch (error) {
      const message = error instanceof Error ? error.message : 'ลบเอกสารไม่สำเร็จ'
      setError(message)
      actionAlert.error('ลบเอกสารไม่สำเร็จ', message, alertId)
    }
    finally { busyRef.current = false; setBusy(false) }
  }
  return <AppShell><Head title="เอกสาร" /><PageHeader title="เอกสารของคุณ" description="สร้าง จัดหน้า และพิมพ์เอกสาร พร้อมบันทึกอัตโนมัติและประวัติรุ่น" action={<Button onClick={create} disabled={busy}><FilePlus2 size={18} />{busy ? 'กำลังดำเนินการ…' : 'เอกสารใหม่'}</Button>} />
    {error && <p role="alert" className="mb-4 rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">{error}</p>}
    <div className="relative mb-6 max-w-md"><Search size={18} className="absolute left-3 top-3.5 text-muted-foreground" /><Input aria-label="ค้นหาเอกสาร" placeholder="ค้นหาชื่อเอกสาร" className="pl-10" value={query} onChange={(event) => setQuery(event.target.value)} /></div>
    {documents.length === 0 ? <div className="rounded-2xl border border-dashed bg-muted/30 p-14 text-center"><FileText size={40} className="mx-auto mb-4 text-muted-foreground" /><h2 className="text-lg font-semibold">เริ่มเอกสารฉบับแรก</h2><p className="mt-2 text-sm text-muted-foreground">สร้างเอกสารเปล่า หรือนำเข้าจากหน้า “จัดเอกสารก่อนพิมพ์” ของบันทึกหลังสอน</p><Button className="mt-5" disabled={busy} onClick={create}><FilePlus2 size={18} />สร้างเอกสาร</Button></div> : <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">{documents.filter((entry) => entry.title.toLocaleLowerCase().includes(query.toLocaleLowerCase())).map((entry) => <article key={entry.id} className="rounded-xl border bg-card p-5 shadow-sm"><AppLink href={appUrl(`/documents/${entry.id}/edit`)} className="focus-ring block rounded-lg"><span className="mb-5 grid h-28 place-items-center rounded-lg border bg-slate-50 text-blue-600"><FileText size={45} strokeWidth={1.25} /></span><h2 className="truncate font-semibold">{entry.title}</h2><p className="mt-2 text-xs text-muted-foreground">รุ่นที่ {entry.revision} · {new Date(entry.updatedAt).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' })}</p></AppLink><div className="mt-5 flex items-center justify-end gap-2">{entry.bound ? <span className="mr-auto text-xs text-muted-foreground">เอกสารแนบของบันทึกหลังสอน</span> : pendingDelete === entry.id ? <><span className="mr-auto text-xs text-destructive">ลบเอกสารและไฟล์แนบ?</span><Button size="sm" variant="ghost" disabled={busy} onClick={() => setPendingDelete(null)}>ยกเลิก</Button><Button size="sm" variant="destructive" disabled={busy} onClick={() => remove(entry.id)}>ลบ</Button></> : <Button size="icon" variant="ghost" aria-label={`ลบ ${entry.title}`} disabled={busy} onClick={() => setPendingDelete(entry.id)}><Trash2 size={16} /></Button>}</div></article>)}</div>}
  </AppShell>
}
