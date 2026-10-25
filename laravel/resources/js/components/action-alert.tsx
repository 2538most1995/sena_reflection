import '../../css/action-alert.css'

import * as Dialog from '@radix-ui/react-dialog'
import { Check, Info, SpinnerGap, WarningCircle, X } from '@phosphor-icons/react'
import { useEffect, useRef, useState, useSyncExternalStore } from 'react'
import { actionAlert, type ActionAlertStatus } from '@/lib/action-alert'

const statusLabels: Record<ActionAlertStatus, string> = {
  loading: 'กำลังดำเนินการ',
  success: 'ทำรายการเรียบร้อย',
  error: 'ตรวจสอบอีกครั้ง',
  info: 'แจ้งให้ทราบ',
}

const statusIcons = { loading: SpinnerGap, success: Check, error: WarningCircle, info: Info }

export function ActionAlertHost() {
  const alerts = useSyncExternalStore(actionAlert.subscribe, actionAlert.getSnapshot, actionAlert.getSnapshot)
  const current = alerts[0]
  const contentRef = useRef<HTMLDivElement>(null)
  const returnFocusRef = useRef<HTMLElement | null>(null)
  const [hovered, setHovered] = useState(false)
  const [focused, setFocused] = useState(false)
  const [hidden, setHidden] = useState(() => typeof document !== 'undefined' && document.hidden)
  const remainingRef = useRef(3200)
  const paused = hovered || focused || hidden

  useEffect(() => {
    const updateVisibility = () => setHidden(document.hidden)
    document.addEventListener('visibilitychange', updateVisibility)
    return () => document.removeEventListener('visibilitychange', updateVisibility)
  }, [])

  useEffect(() => { remainingRef.current = 3200 }, [current?.id, current?.status])

  useEffect(() => {
    if (!current || current.status !== 'success' || paused) return
    const startedAt = performance.now()
    const timer = window.setTimeout(() => actionAlert.dismiss(current.id), remainingRef.current)
    return () => {
      window.clearTimeout(timer)
      remainingRef.current = Math.max(0, remainingRef.current - (performance.now() - startedAt))
    }
  }, [current?.id, current?.status, paused])

  const Icon = current ? statusIcons[current.status] : Info

  return <Dialog.Root open={Boolean(current)} onOpenChange={open => { if (!open && current) actionAlert.dismiss(current.id) }}>
    <Dialog.Portal>
      <Dialog.Overlay className="action-alert-overlay" />
      <Dialog.Content
        ref={contentRef}
        className="action-alert"
        data-status={current?.status}
        role={current?.status === 'error' ? 'alertdialog' : 'dialog'}
        tabIndex={-1}
        onOpenAutoFocus={event => {
          event.preventDefault()
          returnFocusRef.current = document.activeElement instanceof HTMLElement ? document.activeElement : null
          contentRef.current?.focus({ preventScroll: true })
        }}
        onCloseAutoFocus={event => {
          event.preventDefault()
          if (returnFocusRef.current?.isConnected) returnFocusRef.current.focus({ preventScroll: true })
          setHovered(false)
          setFocused(false)
        }}
        onPointerDownOutside={event => event.preventDefault()}
        onMouseEnter={() => setHovered(true)}
        onMouseLeave={() => setHovered(false)}
        onFocusCapture={event => { if (event.target !== event.currentTarget) setFocused(true) }}
        onBlurCapture={event => { if (!event.currentTarget.contains(event.relatedTarget)) setFocused(false) }}
      >
        <Dialog.Close asChild><button type="button" className="action-alert-close" aria-label="ปิดการแจ้งเตือน"><X size={19} /></button></Dialog.Close>
        <div className="action-alert-symbol" aria-hidden="true"><Icon size={36} weight={current?.status === 'success' ? 'bold' : 'regular'} /></div>
        <p className="action-alert-eyebrow">{current ? statusLabels[current.status] : ''}</p>
        <div aria-live={current?.status === 'error' ? 'assertive' : 'polite'} aria-atomic="true">
          <Dialog.Title className="action-alert-title">{current?.title}</Dialog.Title>
          <Dialog.Description className="action-alert-description">{current?.description || (current?.status === 'loading' ? 'กรุณารอสักครู่ ระบบกำลังจัดการให้คุณ' : ' ')}</Dialog.Description>
        </div>
        {current?.status === 'loading' && <div className="action-alert-progress" aria-hidden="true"><span /></div>}
        <Dialog.Close asChild><button type="button" className="action-alert-button">{current?.status === 'loading' ? 'ซ่อนหน้าต่าง' : 'ตกลง'}</button></Dialog.Close>
        <p className="action-alert-footnote">{alerts.length > 1 ? `มีอีก ${alerts.length - 1} รายการรอแจ้งเตือน` : current?.status === 'loading' ? 'ปิดหน้าต่างนี้ได้ งานจะดำเนินการต่อ' : current?.status === 'success' ? 'หน้าต่างนี้จะปิดอัตโนมัติ' : 'กด Escape เพื่อปิดหน้าต่าง'}</p>
      </Dialog.Content>
    </Dialog.Portal>
  </Dialog.Root>
}
