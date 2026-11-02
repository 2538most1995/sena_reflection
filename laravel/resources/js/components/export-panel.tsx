import { appFetch as fetch, appUrl } from '@/lib/app-url'
import { useEffect, useState } from 'react'
import { createExportPoller } from '@/lib/export-polling'
import { DownloadSimple, FilePdf, FileZip } from '@phosphor-icons/react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { actionAlert } from '@/lib/action-alert'
import type { BrowserExportFilters as Filters } from '@/lib/browser-export-filters'

type ExportTask = { id: string; status: 'queued' | 'processing' | 'ready' | 'failed'; total: number; completed: number; error: string | null; download_url: string | null; filters: Filters & { scope: string }; created_at: string }

export function ExportPanel({ filters, availableWeeks }: { filters?: Filters; availableWeeks?: number[] }) {
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

  if (items.length === 0) return null

  return (
    <section className="mb-5 rounded-xl border border-border bg-card p-4 sm:p-5 shadow-none" aria-label="สถานะเอกสารที่สร้าง">
      <div className="divide-y divide-border/60">
        {items.slice(0, 5).map((item) => (
          <div key={item.id} className="flex flex-wrap items-center gap-3 py-3">
            <span className="grid size-8 place-items-center rounded-lg bg-muted border border-border/50 text-foreground">
              {item.filters.scope === 'week' ? <FilePdf size={17} weight="regular" /> : <FileZip size={17} weight="regular" />}
            </span>
            <div className="min-w-0 flex-1">
              <p className="text-sm font-medium text-foreground">
                {item.filters.scope === 'week' ? `PDF สัปดาห์ ${item.filters.week}` : 'ZIP บันทึกตามตัวกรอง'}{' '}
                <span className="text-xs text-muted-foreground font-normal">({item.completed}/{item.total} บันทึก)</span>
              </p>
              {item.error && <p className="text-xs text-destructive">{item.error}</p>}
              {item.status === 'queued' && <p className="text-xs text-muted-foreground">รอสร้างเอกสาร</p>}
            </div>
            <Badge variant={item.status === 'ready' ? 'success' : 'outline'}>
              {({ queued: 'รอคิว', processing: 'กำลังสร้าง', ready: 'พร้อมดาวน์โหลด', failed: 'ลองใหม่ได้' })[item.status]}
            </Badge>
            {item.download_url && (
              <Button asChild size="sm" className="h-8 rounded-lg text-xs">
                <a href={appUrl(item.download_url)} onClick={() => actionAlert.info('ส่งคำขอดาวน์โหลดแล้ว', 'ตรวจสถานะและไฟล์ในรายการดาวน์โหลดของเบราว์เซอร์ ระยะเวลาขึ้นอยู่กับขนาดไฟล์และการเชื่อมต่อ')}>
                  <DownloadSimple size={15} />ดาวน์โหลด
                </a>
              </Button>
            )}
            {item.status !== 'ready' && (
              <Button asChild size="sm" variant="outline" className="h-8 rounded-lg text-xs">
                <a href={appUrl(`/exports/${item.id}/browser-print`)} target="_blank" rel="noopener">
                  <FilePdf size={15} weight="regular" />เปิดพิมพ์ฉบับนี้
                </a>
              </Button>
            )}
          </div>
        ))}
      </div>
    </section>
  )
}
