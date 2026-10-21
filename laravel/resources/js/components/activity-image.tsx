import * as Dialog from '@radix-ui/react-dialog'
import { ArrowCounterClockwise, DownloadSimple, ImageSquare, Minus, Plus, X } from '@phosphor-icons/react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { Button } from '@/components/ui/button'
import { appUrl } from '@/lib/app-url'
import type { RecordRow } from '@/types'

type ImageData = NonNullable<RecordRow['images']>[number]

export function ActivityImage({ recordId, image, index }: { recordId: string; image: ImageData; index: number }) {
  const original = image.view_url ? appUrl(image.view_url) : String(image.id) !== '0' ? appUrl(`/records/${encodeURIComponent(recordId)}/images/${encodeURIComponent(String(image.id))}`) : null
  const preview = image.preview_src || original || ''
  const [source, setSource] = useState(preview)
  const [failed, setFailed] = useState(!preview)
  const [open, setOpen] = useState(false)
  const [viewerSource, setViewerSource] = useState(preview)
  const [loading, setLoading] = useState(false)
  const [zoom, setZoom] = useState(1)
  const [natural, setNatural] = useState({ width: 1, height: 1 })
  const [viewport, setViewport] = useState({ width: 1, height: 1 })
  const observerRef = useRef<ResizeObserver | null>(null)

  useEffect(() => {
    if (!open) return
    setZoom(1)
    setViewerSource(source)
    if (!original) return
    let active = true
    const fullImage = new Image()
    setLoading(true)
    const timer = window.setTimeout(() => { active = false; setLoading(false) }, 10000)
    fullImage.onload = () => {
      if (!active) return
      window.clearTimeout(timer)
      setViewerSource(original)
      setLoading(false)
    }
    fullImage.onerror = () => { if (active) { window.clearTimeout(timer); setLoading(false) } }
    fullImage.src = original
    return () => { active = false; window.clearTimeout(timer); fullImage.onload = null; fullImage.onerror = null }
  }, [open, original, source])

  const observeViewport = useCallback((element: HTMLDivElement | null) => {
    observerRef.current?.disconnect()
    observerRef.current = null
    if (!element) return
    const measure = () => setViewport({ width: element.clientWidth, height: element.clientHeight })
    measure()
    observerRef.current = new ResizeObserver(measure)
    observerRef.current.observe(element)
  }, [])

  const fit = Math.min(viewport.width / natural.width, viewport.height / natural.height, 1)
  const width = Math.max(1, Math.round(natural.width * fit * zoom))
  const height = Math.max(1, Math.round(natural.height * fit * zoom))
  const extension = image.file_path.match(/\.(jpe?g|png|gif|webp)(?:[?#]|$)/i)?.[1] || 'jpg'

  return <Dialog.Root open={open} onOpenChange={setOpen}>
    <div className="overflow-hidden rounded-lg border bg-muted/30">
      {failed ? <div className="flex aspect-square flex-col items-center justify-center gap-2 p-3 text-center text-xs text-muted-foreground" role="status"><ImageSquare size={28} /><span>โหลดรูปกิจกรรม {index + 1} ไม่สำเร็จ</span><Button type="button" size="sm" variant="outline" disabled={!original} onClick={() => { setFailed(false); setSource(`${original}?retry=${Date.now()}`) }}>ลองใหม่</Button></div>
        : <Dialog.Trigger asChild><button type="button" aria-label={`ดูรูปกิจกรรม ${index + 1}`} className="focus-ring block w-full cursor-zoom-in"><img src={source} alt={`รูปกิจกรรม ${index + 1}`} loading="lazy" onError={() => { if (original && source !== original) setSource(original); else setFailed(true) }} className="aspect-square w-full object-cover" /></button></Dialog.Trigger>}
    </div>
    <Dialog.Portal>
      <Dialog.Overlay className="fixed inset-0 z-50 bg-black/75" />
      <Dialog.Content className="fixed inset-2 z-50 flex max-h-[calc(100dvh-16px)] flex-col overflow-hidden rounded-xl border bg-background shadow-2xl sm:inset-6" aria-describedby={`activity-image-description-${index}`}>
        <header className="flex shrink-0 flex-wrap items-center justify-between gap-2 border-b p-3">
          <Dialog.Title className="text-sm font-semibold">รูปกิจกรรม {index + 1}</Dialog.Title>
          <Dialog.Description id={`activity-image-description-${index}`} className="sr-only">ใช้ปุ่มย่อหรือขยายเพื่อดูรายละเอียด เลื่อนเพื่อดูส่วนต่าง ๆ ของภาพ และกด Escape เพื่อปิด</Dialog.Description>
          <div className="flex items-center gap-1">
            <Button type="button" variant="ghost" size="icon" aria-label="ย่อรูป" disabled={zoom <= 0.25} onClick={() => setZoom(Math.max(0.25, zoom - 0.25))}><Minus size={18} /></Button>
            <span className="min-w-12 text-center text-xs tabular-nums" aria-live="polite">{Math.round(zoom * 100)}%</span>
            <Button type="button" variant="ghost" size="icon" aria-label="ขยายรูป" disabled={zoom >= 5} onClick={() => setZoom(Math.min(5, zoom + 0.25))}><Plus size={18} /></Button>
            <Button type="button" variant="ghost" size="icon" aria-label="แสดงรูปพอดีหน้าจอ" onClick={() => setZoom(1)}><ArrowCounterClockwise size={18} /></Button>
            <Button asChild variant="ghost" size="icon"><a href={viewerSource} download={`activity-${index + 1}.${extension}`} aria-label="ดาวน์โหลดรูป"><DownloadSimple size={18} /></a></Button>
            <Dialog.Close asChild><Button type="button" variant="outline" size="icon" aria-label="ปิดรูป"><X size={18} /></Button></Dialog.Close>
          </div>
        </header>
        <div ref={observeViewport} className="min-h-0 flex-1 overflow-auto overscroll-contain bg-muted/40">
          <div className="grid place-items-center" style={{ width: Math.max(viewport.width, width), height: Math.max(viewport.height, height) }}>
            <img src={viewerSource} alt={`รูปกิจกรรม ${index + 1} แบบขยาย`} onLoad={(event) => setNatural({ width: event.currentTarget.naturalWidth, height: event.currentTarget.naturalHeight })} onError={() => { if (viewerSource !== source) setViewerSource(source) }} draggable={false} style={{ width, height, maxWidth: 'none' }} />
          </div>
        </div>
        <p className="shrink-0 border-t px-3 py-2 text-xs text-muted-foreground" role="status">{loading ? 'กำลังโหลดรูปต้นฉบับ…' : 'ย่อ–ขยายเพื่อดูรายละเอียด · เลื่อนเพื่อดูส่วนอื่น · Esc ปิดรูป'}</p>
      </Dialog.Content>
    </Dialog.Portal>
  </Dialog.Root>
}
