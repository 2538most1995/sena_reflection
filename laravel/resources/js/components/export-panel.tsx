import { appFetch as fetch, appUrl } from '@/lib/app-url'
import { useEffect, useState } from 'react'
import { ArrowClockwise, DownloadSimple, FilePdf, FileZip, WarningCircle } from '@phosphor-icons/react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'

type Filters = { year?: string; semester?: string; week?: number | string; tambon?: string; search?: string }
type ExportTask = { id: string; status: 'queued' | 'processing' | 'ready' | 'failed'; total: number; completed: number; error: string | null; download_url: string | null; filters: Filters & { scope: string }; created_at: string }

export function ExportPanel({ filters }: { filters: Filters }) {
  const [items, setItems] = useState<ExportTask[]>([])
  const [pending, setPending] = useState(false)
  const [error, setError] = useState<string | null>(null)
  useEffect(() => {
    const controller = new AbortController()
    const refresh = async () => {
      if (document.hidden) return
      try {
        const response = await fetch('/exports', { signal: controller.signal, headers: { Accept: 'application/json' } })
        if (response.ok) setItems((await response.json()).items)
      } catch { /* The next poll recovers a temporary network failure. */ }
    }
    void refresh()
    const timer = window.setInterval(refresh, 3000)
    return () => { controller.abort(); window.clearInterval(timer) }
  }, [])
  const submit = async (scope: 'week' | 'all', id?: string) => {
    setPending(true); setError(null)
    try {
      const response = await fetch(id ? `/exports/${id}/retry` : '/exports', {
        method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '' },
        body: JSON.stringify({ ...filters, scope }),
      })
      const payload = await response.json()
      if (!response.ok) throw new Error(payload.message || 'เริ่มสร้างเอกสารไม่สำเร็จ')
      setItems((previous) => [payload, ...previous.filter((item) => item.id !== payload.id)].slice(0, 10))
    } catch (issue) { setError(issue instanceof Error ? issue.message : 'เชื่อมต่อไม่สำเร็จ กรุณาลองใหม่') }
    finally { setPending(false) }
  }
  const canExportWeek = filters.year && filters.semester && filters.week
  return <section className="mb-5 rounded-xl border bg-background p-4" aria-label="สร้างและดาวน์โหลดเอกสาร">
    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div><h3 className="text-sm font-semibold">สร้างเอกสารตามตัวกรอง</h3><p className="mt-1 text-xs leading-5 text-muted-foreground">{canExportWeek ? `สัปดาห์ ${filters.week} / ภาคเรียน ${filters.semester} / ปี ${filters.year}` : 'เลือกปี ภาคเรียน และสัปดาห์ เพื่อสร้าง PDF รายสัปดาห์'} งานจะทำต่อเบื้องหลัง ปิดหน้านี้แล้วกลับมาดาวน์โหลดได้</p></div>
      <div className="flex shrink-0 flex-wrap gap-2"><Button type="button" variant="outline" size="sm" disabled={pending || !canExportWeek} onClick={() => void submit('week')}><FilePdf size={17} />PDF รายสัปดาห์</Button><Button type="button" variant="outline" size="sm" disabled={pending} onClick={() => void submit('all')}><FileZip size={17} />ZIP ตามตัวกรอง</Button></div>
    </div>
    {error && <p className="mt-3 flex items-center gap-2 text-sm text-destructive" role="alert"><WarningCircle size={18} />{error}</p>}
    {items.length > 0 && <div className="mt-4 divide-y border-t">{items.slice(0, 5).map((item) => <div key={item.id} className="flex flex-wrap items-center gap-3 py-3">
      <span className="grid size-8 place-items-center rounded-md bg-muted">{item.filters.scope === 'week' ? <FilePdf size={18} /> : <FileZip size={18} />}</span>
      <div className="min-w-0 flex-1"><p className="text-sm font-medium">{item.filters.scope === 'week' ? `PDF สัปดาห์ ${item.filters.week}` : 'ZIP บันทึกตามตัวกรอง'} <span className="text-xs text-muted-foreground">{item.completed}/{item.total} บันทึก</span></p>{item.error && <p className="text-xs text-destructive">{item.error}</p>}{item.status === 'queued' && <p className="text-xs text-muted-foreground">รอสร้างเอกสาร</p>}</div>
      <Badge variant={item.status === 'ready' ? 'success' : 'outline'}>{({ queued: 'รอคิว', processing: 'กำลังสร้าง', ready: 'พร้อมดาวน์โหลด', failed: 'ลองใหม่ได้' })[item.status]}</Badge>
      {item.download_url && <Button asChild size="sm"><a href={appUrl(item.download_url)}><DownloadSimple size={16} />ดาวน์โหลด</a></Button>}
      {item.status === 'failed' && <Button type="button" size="sm" variant="outline" disabled={pending} onClick={() => void submit(item.filters.scope === 'week' ? 'week' : 'all', item.id)}><ArrowClockwise size={16} />ลองใหม่</Button>}
    </div>)}</div>}
  </section>
}
