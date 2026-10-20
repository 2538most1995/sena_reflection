import { appUrl } from '@/lib/app-url'
import { ArrowLeft, ArrowRight, Camera, DotsSixVertical, ImageSquare, Trash, UploadSimple, WarningCircle } from '@phosphor-icons/react'
import { useRef, useState } from 'react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import type { TeachingDraftImage } from './types'

type Props = {
  images: TeachingDraftImage[]
  busy: boolean
  onUpload: (files: File[]) => Promise<void>
  onDelete: (image: TeachingDraftImage) => Promise<void>
  onReorder: (imageIds: string[]) => Promise<void>
}

function formatSize(bytes: number): string {
  if (!bytes) return ''
  if (bytes < 1024 * 1024) return `${Math.ceil(bytes / 1024)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

export function ImageManager({ images, busy, onUpload, onDelete, onReorder }: Props) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [draggedId, setDraggedId] = useState<string | null>(null)
  const [selectionError, setSelectionError] = useState<string | null>(null)
  const validCount = [0, 2, 4].includes(images.length)

  const move = (from: number, to: number) => {
    if (from === to || to < 0 || to >= images.length) return
    const next = [...images]
    const [item] = next.splice(from, 1)
    next.splice(to, 0, item)
    void onReorder(next.map((image) => image.id))
  }

  const addFiles = (files: File[]) => {
    const available = Math.max(0, 4 - images.length)
    const invalid = files.filter((file) => !['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024)
    const valid = files.filter((file) => !invalid.includes(file))
    setSelectionError(invalid.length ? `ไม่ได้เพิ่ม ${invalid.length} ไฟล์: ใช้ JPG, PNG หรือ WEBP ขนาดไม่เกิน 5 MB ต่อรูป` : valid.length > available ? `เพิ่มได้อีก ${available} รูป ไฟล์ที่เกินจำนวนยังไม่ได้เพิ่ม` : null)
    void onUpload(valid.slice(0, available))
    if (inputRef.current) inputRef.current.value = ''
  }

  return <section aria-labelledby="workspace-images-heading" className="grid gap-3">
    <div className="flex flex-wrap items-end justify-between gap-3">
      <div><h3 id="workspace-images-heading" className="font-semibold">รูปภาพประกอบ</h3><p className="mt-0.5 text-xs text-muted-foreground">อัปโหลด 0, 2 หรือ 4 รูป แล้วลากหรือใช้ปุ่มลูกศรเพื่อจัดลำดับ</p></div>
      <Button type="button" variant="outline" size="sm" disabled={busy || images.length >= 4} onClick={() => inputRef.current?.click()}><UploadSimple size={17} />เพิ่มรูป</Button>
      <input ref={inputRef} type="file" accept="image/jpeg,image/png,image/webp" multiple className="sr-only" onChange={(event) => addFiles(Array.from(event.target.files || []))} />
    </div>

    {!validCount && <div className="flex items-start gap-2 rounded-lg border border-amber-500/25 bg-amber-500/10 px-3 py-2 text-xs font-medium text-amber-800 dark:text-amber-200"><WarningCircle size={17} className="mt-0.5 shrink-0" />ขณะเป็นร่างเก็บ {images.length} รูปได้ แต่ก่อนยืนยันต้องมี 0, 2 หรือ 4 รูป</div>}
    {selectionError && <p role="alert" className="text-xs text-destructive">{selectionError}</p>}

    {images.length ? <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
      {images.map((image, index) => <article
        key={image.id}
        draggable={!busy}
        onDragStart={() => setDraggedId(image.id)}
        onDragEnd={() => setDraggedId(null)}
        onDragOver={(event) => event.preventDefault()}
        onDrop={(event) => {
          event.preventDefault()
          const from = images.findIndex((item) => item.id === draggedId)
          if (from >= 0) move(from, index)
          setDraggedId(null)
        }}
        className={cn('group overflow-hidden rounded-xl border bg-card transition-[border-color,box-shadow,opacity] duration-150', draggedId === image.id ? 'opacity-50' : 'hover:border-primary/35 hover:shadow-sm')}
      >
        <div className="relative aspect-[4/3] overflow-hidden bg-muted">
          <img src={appUrl(image.url)} alt={`รูปประกอบลำดับ ${index + 1}`} className="size-full object-cover" />
          <span className="absolute left-2 top-2 grid size-7 place-items-center rounded-md bg-foreground/78 text-xs font-bold text-background">{index + 1}</span>
          <span className="absolute right-2 top-2 grid size-7 cursor-grab place-items-center rounded-md bg-background/90 text-foreground shadow-sm" aria-hidden="true"><DotsSixVertical size={17} weight="bold" /></span>
        </div>
        <div className="grid gap-2 p-2.5">
          <div className="min-w-0"><b className="block truncate text-xs">{image.original_name || `รูปที่ ${index + 1}`}</b><small className="text-[10px] text-muted-foreground">{formatSize(image.size_bytes)}</small></div>
          <div className="flex items-center justify-between gap-1">
            <div className="flex gap-1"><Button type="button" variant="ghost" size="icon" className="size-8" disabled={busy || index === 0} onClick={() => move(index, index - 1)} aria-label={`เลื่อนรูป ${index + 1} ไปทางซ้าย`}><ArrowLeft size={16} /></Button><Button type="button" variant="ghost" size="icon" className="size-8" disabled={busy || index === images.length - 1} onClick={() => move(index, index + 1)} aria-label={`เลื่อนรูป ${index + 1} ไปทางขวา`}><ArrowRight size={16} /></Button></div>
            <Button type="button" variant="ghost" size="icon" className="size-8 text-destructive hover:bg-destructive/10 hover:text-destructive" disabled={busy} onClick={() => { if (window.confirm(`นำ “${image.original_name || `รูปที่ ${index + 1}`}” ออกจากร่างหรือไม่`)) void onDelete(image) }} aria-label={`ลบรูป ${index + 1}`}><Trash size={16} /></Button>
          </div>
        </div>
      </article>)}
    </div> : <button type="button" disabled={busy} onClick={() => inputRef.current?.click()} className="focus-ring grid min-h-36 place-items-center rounded-xl border border-dashed bg-muted/20 px-5 text-center transition-[background-color,border-color,transform] duration-150 hover:border-primary/40 hover:bg-accent/55 active:scale-[.99] disabled:cursor-not-allowed disabled:opacity-60">
      <span><span className="mx-auto grid size-11 place-items-center rounded-xl bg-secondary text-primary"><Camera size={23} /></span><b className="mt-3 block text-sm">เพิ่มรูปภาพการจัดกิจกรรม</b><small className="mt-1 block text-muted-foreground">JPG, PNG หรือ WEBP ไม่เกิน 5 MB ต่อรูป</small></span>
    </button>}

    {busy && <div className="flex items-center gap-2 text-xs font-medium text-muted-foreground" role="status"><ImageSquare size={17} className="animate-pulse text-primary" />กำลังจัดการรูป กรุณาอย่าปิดหน้านี้</div>}
  </section>
}
