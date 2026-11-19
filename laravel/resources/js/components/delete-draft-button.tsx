import * as Dialog from '@radix-ui/react-dialog'
import { Trash } from '@phosphor-icons/react'
import { useRef, useState } from 'react'
import { Button } from '@/components/ui/button'
import { actionAlert } from '@/lib/action-alert'
import { appFetch } from '@/lib/app-url'

type DraftToDelete = {
  id: string
  lock_version: number
  last_saved_at: string | null
  scope?: { week?: number | string | null; year?: string | null; semester?: string | null }
}

export function DeleteDraftButton({ draft, disabled, onDeleted, onRefresh }: {
  draft: DraftToDelete
  disabled?: boolean
  onDeleted: (id: string) => void
  onRefresh: () => void
}) {
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const deleting = useRef(false)
  const week = draft.scope?.week ? `สัปดาห์ที่ ${draft.scope.week}` : 'ยังไม่เลือกสัปดาห์'
  const period = [week, draft.scope?.year && `ปี ${draft.scope.year}`, draft.scope?.semester && `ภาคเรียน ${draft.scope.semester}`].filter(Boolean).join(' · ')

  const remove = async () => {
    if (deleting.current) return
    deleting.current = true
    setBusy(true)
    setError('')
    try {
      const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || ''
      const response = await appFetch(`/teaching-drafts/${encodeURIComponent(draft.id)}`, {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ lock_version: draft.lock_version }),
      })
      if ([404, 409, 422].includes(response.status)) {
        setOpen(false)
        onRefresh()
        actionAlert.info('กรุณาตรวจรายการร่างล่าสุด', response.status === 409 ? 'ร่างมีการเปลี่ยนแปลง กรุณาตรวจสอบอีกครั้งก่อนลบ' : 'ร่างนี้ไม่พร้อมให้ลบแล้ว')
        return
      }
      const result = await response.json().catch(() => null)
      if (!response.ok || result?.deleted !== true) {
        setError(response.status === 419 || response.status === 401 ? 'กรุณาเข้าสู่ระบบอีกครั้ง' : 'ลบร่างไม่สำเร็จ กรุณาลองใหม่')
        return
      }
      setOpen(false)
      onDeleted(draft.id)
      actionAlert.success('ลบร่างแล้ว')
    } catch {
      setError('ติดต่อระบบไม่สำเร็จ กรุณาลองใหม่')
    } finally {
      deleting.current = false
      setBusy(false)
    }
  }

  return <Dialog.Root open={open} onOpenChange={next => {
    if (deleting.current) return
    setError('')
    setOpen(next)
  }}>
    <Dialog.Trigger asChild>
      <Button type="button" variant="outline" size="sm" className="shrink-0 text-destructive hover:text-destructive" disabled={disabled}>
        <Trash size={16} />ลบร่าง
      </Button>
    </Dialog.Trigger>
    <Dialog.Portal>
      <Dialog.Overlay className="fixed inset-0 z-40 bg-foreground/35" />
      <Dialog.Content role="alertdialog" className="fixed left-1/2 top-1/2 z-50 max-h-[85dvh] w-[min(92vw,440px)] -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl border bg-card p-5 shadow-2xl outline-none sm:p-6">
        <Dialog.Title className="text-lg font-semibold">ลบฉบับร่างนี้หรือไม่</Dialog.Title>
        <Dialog.Description className="mt-2 text-sm leading-6 text-muted-foreground">
          ร่าง ข้อความ และรูปของฉบับนี้จะถูกลบถาวร บันทึกที่ยืนยันแล้วจะยังอยู่
        </Dialog.Description>
        <div className="mt-4 rounded-lg border bg-muted/30 px-3 py-2 text-sm">
          <p>{period}</p>
          {draft.last_saved_at && <p className="mt-1 text-xs text-muted-foreground">บันทึกล่าสุด {new Date(draft.last_saved_at).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' })}</p>}
        </div>
        {error && <p role="alert" className="mt-3 text-sm text-destructive">{error}</p>}
        <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Dialog.Close asChild><Button type="button" variant="outline" disabled={busy}>ยกเลิก</Button></Dialog.Close>
          <Button type="button" variant="destructive" disabled={busy} onClick={() => void remove()}>{busy ? 'กำลังลบร่าง…' : 'ยืนยันลบร่าง'}</Button>
        </div>
      </Dialog.Content>
    </Dialog.Portal>
  </Dialog.Root>
}
