import { appFetch as fetch, appUrl } from '@/lib/app-url'
import { useEffect, useState } from 'react'
import { createExportPoller } from '@/lib/export-polling'
import { DownloadSimple, FilePdf, FileZip } from '@phosphor-icons/react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { actionAlert } from '@/lib/action-alert'
import { browserExportQuery, type BrowserExportFilters as Filters } from '@/lib/browser-export-filters'

type ExportTask = { id: string; status: 'queued' | 'processing' | 'ready' | 'failed'; total: number; completed: number; error: string | null; download_url: string | null; filters: Filters & { scope: string }; created_at: string }

export function ExportPanel({ filters }: { filters: Filters }) {
  const [items, setItems] = useState<ExportTask[]>([])
  useEffect(() => {
    const controller = new AbortController()
    const poller = createExportPoller({
      visible: () => !document.hidden,
      schedule: (callback, delay) => window.setTimeout(callback, delay),
      cancel: (timer) => window.clearTimeout(timer as number),
      load: async () => {
        const response = await fetch('/exports', { signal: controller.signal, headers: { Accept: 'application/json' } })
        if (!response.ok) throw new Error('โหลดสถานะเอกสารไม่สำเร็จ')
        const payload: { items: ExportTask[] } = await response.json()
        setItems(payload.items)
        return payload.items.some((item) => item.status === 'queued' || item.status === 'processing')
      },
    })
    const visibilityChanged = () => { if (document.hidden) poller.pause(); else poller.refresh() }
    document.addEventListener('visibilitychange', visibilityChanged)
    poller.refresh()
    return () => { controller.abort(); poller.dispose(); document.removeEventListener('visibilitychange', visibilityChanged) }
  }, [])
  const printUrl = (scope: 'week' | 'all') => {
    const query = browserExportQuery(scope, filters)
    return appUrl(`/exports/browser-print?${query}`)
  }
  const canExportWeek = filters.year && filters.semester && filters.week
  const openingPdf = () => actionAlert.info('เปิดหน้าเตรียม PDF', 'รอจนเอกสารพร้อม แล้วกด “พิมพ์ / บันทึก PDF” ในแท็บใหม่ หากแท็บไม่เปิด กรุณาอนุญาตป๊อปอัปของเว็บไซต์นี้')
  return <section className="mb-5 rounded-xl border bg-background p-4" aria-label="สร้างและดาวน์โหลดเอกสาร">
    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div><h3 className="text-sm font-semibold">สร้างเอกสารตามตัวกรอง</h3><p className="mt-1 text-xs leading-5 text-muted-foreground">{canExportWeek ? `สัปดาห์ ${filters.week} / ภาคเรียน ${filters.semester} / ปี ${filters.year}` : 'เลือกปี ภาคเรียน และสัปดาห์ เพื่อสร้าง PDF รายสัปดาห์'} เปิดหน้าพิมพ์ แล้วเลือก Save as PDF รวมเป็นไฟล์เดียว</p></div>
      <div className="flex shrink-0 flex-wrap gap-2"><Button asChild variant="outline" size="sm" disabled={!canExportWeek}><a href={canExportWeek ? printUrl('week') : undefined} aria-disabled={!canExportWeek} target="_blank" rel="noopener" onClick={event => { if (!canExportWeek) event.preventDefault(); else openingPdf() }}><FilePdf size={17} />PDF รายสัปดาห์</a></Button><Button asChild variant="outline" size="sm"><a href={printUrl('all')} target="_blank" rel="noopener" onClick={openingPdf}><FilePdf size={17} />PDF ตามตัวกรอง</a></Button></div>
    </div>
    {items.length > 0 && <div className="mt-4 divide-y border-t">{items.slice(0, 5).map((item) => <div key={item.id} className="flex flex-wrap items-center gap-3 py-3">
      <span className="grid size-8 place-items-center rounded-md bg-muted">{item.filters.scope === 'week' ? <FilePdf size={18} /> : <FileZip size={18} />}</span>
      <div className="min-w-0 flex-1"><p className="text-sm font-medium">{item.filters.scope === 'week' ? `PDF สัปดาห์ ${item.filters.week}` : 'ZIP บันทึกตามตัวกรอง'} <span className="text-xs text-muted-foreground">{item.completed}/{item.total} บันทึก</span></p>{item.error && <p className="text-xs text-destructive">{item.error}</p>}{item.status === 'queued' && <p className="text-xs text-muted-foreground">รอสร้างเอกสาร</p>}</div>
      <Badge variant={item.status === 'ready' ? 'success' : 'outline'}>{({ queued: 'รอคิว', processing: 'กำลังสร้าง', ready: 'พร้อมดาวน์โหลด', failed: 'ลองใหม่ได้' })[item.status]}</Badge>
      {item.download_url && <Button asChild size="sm"><a href={appUrl(item.download_url)} onClick={() => actionAlert.info('ส่งคำขอดาวน์โหลดแล้ว', 'ตรวจสถานะและไฟล์ในรายการดาวน์โหลดของเบราว์เซอร์ ระยะเวลาขึ้นอยู่กับขนาดไฟล์และการเชื่อมต่อ')}><DownloadSimple size={16} />ดาวน์โหลด</a></Button>}
      {item.status !== 'ready' && <Button asChild size="sm" variant="outline"><a href={appUrl(`/exports/${item.id}/browser-print`)} target="_blank" rel="noopener" onClick={openingPdf}><FilePdf size={16} />เปิดพิมพ์ฉบับนี้</a></Button>}
    </div>)}</div>}
  </section>
}
