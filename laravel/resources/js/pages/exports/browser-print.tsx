import { Head } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import { ArrowClockwise, ArrowLeft, Printer, WarningCircle } from '@phosphor-icons/react'
import { Button } from '@/components/ui/button'
import { appUrl } from '@/lib/app-url'
import { prepareBrowserExport, type BrowserExportRecord } from '@/lib/browser-export'

export default function BrowserPrint({ records, title }: { records: BrowserExportRecord[]; title: string }) {
  const target = useRef<HTMLDivElement>(null)
  const [attempt, setAttempt] = useState(0)
  const [completed, setCompleted] = useState(0)
  const [pages, setPages] = useState(0)
  const [ready, setReady] = useState(false)
  const [paused, setPaused] = useState(false)
  const [error, setError] = useState<string | null>(null)
  useEffect(() => {
    const controller = new AbortController()
    let finished = false
    setReady(false); setError(null); setCompleted(0); setPages(0)
    setPaused(document.hidden)
    delete document.body.dataset.browserExportReady
    delete document.body.dataset.browserExportError
    const beforePrint = () => { document.body.dataset.browserExportPrinting = 'true' }
    const afterPrint = () => { delete document.body.dataset.browserExportPrinting }
    window.addEventListener('beforeprint', beforePrint)
    window.addEventListener('afterprint', afterPrint)
    const visibilityChanged = () => {
      if (finished) return
      if (document.hidden) { controller.abort(); setPaused(true) }
      else if (controller.signal.aborted) setAttempt(value => value + 1)
    }
    document.addEventListener('visibilitychange', visibilityChanged)
    if (document.hidden) controller.abort()
    if (!controller.signal.aborted && records.length && target.current) {
      prepareBrowserExport(records, target.current, controller.signal, (done, count) => { setCompleted(done); setPages(count) })
        .then(count => {
          if (controller.signal.aborted) return
          finished = true
          document.body.dataset.browserExportReady = 'true'
          document.body.dataset.browserExportSections = String(count)
          setReady(true)
        }).catch(issue => {
          if (controller.signal.aborted) return
          document.body.dataset.browserExportError = 'true'
          setError(issue instanceof Error ? issue.message : 'เตรียมเอกสารไม่สำเร็จ')
        })
    }
    return () => {
      controller.abort()
      window.removeEventListener('beforeprint', beforePrint)
      window.removeEventListener('afterprint', afterPrint)
      document.removeEventListener('visibilitychange', visibilityChanged)
      for (const key of ['browserExportReady', 'browserExportError', 'browserExportSections', 'browserExportPrinting']) delete document.body.dataset[key]
    }
  }, [records, attempt])
  return <>
    <Head title={title} />
    <style>{`
      .browser-export-controls{position:sticky;top:0;z-index:30;}
      .browser-export-documents{overflow:auto;padding:20px 8px;background:#e8efed;}
      .browser-export-print-page{display:block;margin:0 auto 24px;width:max-content;max-width:none;}
      @media print{
        html,body,#app{margin:0!important;padding:0!important;background:white!important;min-width:0!important;}
        .browser-export-controls{display:none!important;}
        .browser-export-documents{display:block!important;overflow:visible!important;padding:0!important;background:white!important;}
        .browser-export-print-page{margin:0!important;max-width:none!important;break-before:page;}
        .browser-export-print-page:first-of-type{break-before:auto;}
        body:not([data-browser-export-ready="true"]) .browser-export-documents{display:none!important;}
        body:not([data-browser-export-ready="true"]) .browser-export-controls{display:block!important;position:static!important;}
      }
    `}</style>
    <header className="browser-export-controls border-b bg-background p-4 shadow-sm">
      <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4">
        <div className="min-w-0"><h1 className="text-lg font-semibold">{title}</h1>
          <p className="mt-1 text-sm text-muted-foreground" role="status">{!records.length ? 'ไม่พบบันทึกตามตัวกรอง' : ready ? `พร้อมพิมพ์ ${records.length} บันทึก · ${pages} ส่วนเอกสาร` : paused ? 'พักการเตรียมเอกสาร กลับแท็บนี้เพื่อทำต่อ' : `กำลังเตรียมเอกสาร ${completed}/${records.length} บันทึก`}</p>
          <p className="mt-1 text-xs leading-5 text-muted-foreground">เลือก Save as PDF ในหน้าต่างพิมพ์ · ขนาด 100% · ปิดหัวกระดาษและท้ายกระดาษ · ใช้ขนาดกระดาษตามเอกสาร</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button asChild variant="outline" size="sm"><a href={appUrl('/records')}><ArrowLeft size={17} />กลับรายการ</a></Button>
          {error && <Button variant="outline" size="sm" onClick={() => setAttempt(value => value + 1)}><ArrowClockwise size={17} />ลองโหลดอีกครั้ง</Button>}
          <Button size="sm" data-testid="browser-export-print" disabled={!ready} onClick={() => window.print()}><Printer size={17} />พิมพ์ / บันทึก PDF</Button>
        </div>
      </div>
      {error && <p className="mx-auto mt-3 flex max-w-6xl items-start gap-2 text-sm text-destructive" role="alert"><WarningCircle size={20} className="shrink-0" />{error} ยังไม่เปิดให้พิมพ์จนกว่าเอกสารจะครบ</p>}
      {records.length > 0 && <details className="mx-auto mt-2 max-w-6xl text-xs"><summary className="cursor-pointer text-muted-foreground">เปิดพิมพ์แยกรายการ</summary><ul className="mt-2 grid max-h-40 gap-2 overflow-auto">{records.map(record => <li key={record.revision_id}><a className="text-primary underline underline-offset-2" href={record.print_url} target="_blank" rel="noopener">{record.date} · {record.subject} · {record.level}</a></li>)}</ul></details>}
    </header>
    <div className="browser-export-documents" ref={target} />
  </>
}
