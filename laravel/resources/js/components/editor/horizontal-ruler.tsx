import { useEffect, useRef, useState, type KeyboardEvent, type PointerEvent } from 'react'
import type { Editor } from '@tiptap/react'
import { closeHistory } from '@tiptap/pm/history'
import { moveParagraphIndent, paragraphIndentTargets, type IndentKind, type IndentTarget } from '@/lib/document-indent'
import { MM_TO_PX } from '@/lib/document-model'
import { paragraphIndentPreviewKey } from './document-extensions'

export const HORIZONTAL_RULER_HEIGHT = 36
const labels: Record<IndentKind, string> = { first: 'เยื้องบรรทัดแรก', hanging: 'เยื้องแบบห้อย', left: 'เยื้องซ้ายทั้งย่อหน้า', right: 'เยื้องขวา' }
type Snapshot = { targets: IndentTarget[]; origin: number; width: number; widths?: Record<number, number> }
type Gesture = { pointer: number; startX: number; kind: IndentKind; snapshot: Snapshot; doc: Editor['state']['doc']; delta: number; pendingX: number; frame: number }
type Props = { editor: Editor | null; canvas: React.RefObject<HTMLDivElement | null>; width: number; left: number; right: number; zoom: number; height: number; disabled: boolean }

function readSnapshot(editor: Editor, paper: HTMLDivElement | null, width: number, left: number, right: number): Snapshot {
  const targets = paragraphIndentTargets(editor.state)
  const first = targets[0]
  const element = first && editor.view.nodeDOM(first.position)
  if (!(element instanceof HTMLElement) || !paper) return { targets: [], origin: left, width: width - left - right }
  const box = element.getBoundingClientRect(), page = paper.getBoundingClientRect()
  const scale = page.width / width
  if (!scale) return { targets: [], origin: left, width: width - left - right }
  const offset = Number(editor.state.doc.nodeAt(first.position)?.attrs.offsetX) || 0
  const origin = (box.left - page.left) / scale - first.indent * MM_TO_PX - offset
  const available = box.width / scale + (first.indent + first.rightIndent) * MM_TO_PX
  const widths: Record<number, number> = {}
  for (const target of targets) {
    const paragraph = editor.view.nodeDOM(target.position)
    if (paragraph instanceof HTMLElement) widths[target.position] = paragraph.getBoundingClientRect().width / scale + (target.indent + target.rightIndent) * MM_TO_PX
  }
  return { targets, origin, width: available, widths }
}

/** The ruler previews decorations, then commits one document transaction when the pointer is released. */
export function HorizontalRuler({ editor, canvas, width, left, right, zoom, height, disabled }: Props) {
  const [snapshot, setSnapshot] = useState<Snapshot>({ targets: [], origin: left, width: width - left - right })
  const [preview, setPreview] = useState<IndentTarget[] | null>(null)
  const [active, setActive] = useState<IndentKind | null>(null)
  const gesture = useRef<Gesture | null>(null)
  const latest = useRef({ zoom, disabled })
  latest.current = { zoom, disabled }
  const ruler = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!editor || editor.isDestroyed) return
    let frame = 0
    const refresh = () => {
      cancelAnimationFrame(frame)
      frame = requestAnimationFrame(() => {
        if (editor.isDestroyed || gesture.current) return
        setSnapshot(readSnapshot(editor, canvas.current, width, left, right))
      })
    }
    const cancel = () => {
      const current = gesture.current
      if (!current) return
      cancelAnimationFrame(current.frame)
      gesture.current = null
      setPreview(null); setActive(null)
      if (!editor.isDestroyed) editor.view.dispatch(editor.state.tr.setMeta(paragraphIndentPreviewKey, []))
      refresh()
    }
    const transaction = () => {
      if (gesture.current && (editor.state.doc !== gesture.current.doc || latest.current.disabled)) cancel()
      else refresh()
    }
    const key = (event: globalThis.KeyboardEvent) => { if (event.key === 'Escape' && gesture.current) { event.preventDefault(); cancel() } }
    editor.on('transaction', transaction)
    window.addEventListener('keydown', key)
    window.addEventListener('blur', cancel)
    const observer = new ResizeObserver(refresh)
    observer.observe(editor.view.dom)
    refresh()
    return () => {
      editor.off('transaction', transaction)
      window.removeEventListener('keydown', key)
      window.removeEventListener('blur', cancel)
      observer.disconnect()
      cancelAnimationFrame(frame)
      cancel()
      cancelAnimationFrame(frame)
    }
  }, [editor, canvas, width, left, right, zoom, disabled])

  const targets = preview || snapshot.targets
  const first = targets[0]
  const enabled = !disabled && !!first && !!editor?.isEditable
  const positions = first ? { first: first.indent + first.firstIndent / MM_TO_PX, hanging: first.indent, left: first.indent, right: snapshot.width / MM_TO_PX - first.rightIndent } : { first: 0, hanging: 0, left: 0, right: snapshot.width / MM_TO_PX }
  const values = (source: Snapshot, kind: IndentKind, delta: number) => source.targets.map(target => ({ position: target.position, ...moveParagraphIndent(target, kind, delta, (source.widths?.[target.position] ?? source.width) / MM_TO_PX) }))
  const commit = (next: IndentTarget[]) => {
    if (!editor || editor.isDestroyed) return
    const current = paragraphIndentTargets(editor.state)
    const changed = next.some(target => {
      const previous = current.find(item => item.position === target.position)
      return previous && (['indent', 'rightIndent', 'firstIndent'] as const).some(key => Math.abs(target[key] - previous[key]) > 0.000001)
    })
    if (!changed) { editor.view.dispatch(editor.state.tr.setMeta(paragraphIndentPreviewKey, [])); return }
    const tr = closeHistory(editor.state.tr).setMeta(paragraphIndentPreviewKey, []).setMeta('documentGestureBoundary', true)
    for (const target of next) {
      const node = tr.doc.nodeAt(target.position)
      if (node) tr.setNodeMarkup(target.position, undefined, { ...node.attrs, indent: target.indent, rightIndent: target.rightIndent, firstIndent: target.firstIndent })
    }
    editor.view.dispatch(tr)
    setSnapshot(previous => ({ ...previous, targets: next }))
    editor.view.dispatch(closeHistory(editor.state.tr).setMeta('addToHistory', false))
  }
  const update = (x: number, fine = false) => {
    const current = gesture.current
    if (!current || !editor || editor.isDestroyed) return
    const step = fine ? 0.1 : 0.5
    current.delta = Math.round((x - current.startX) / latest.current.zoom / MM_TO_PX / step) * step
    const next = values(current.snapshot, current.kind, current.delta)
    setPreview(next)
    editor.view.dispatch(editor.state.tr.setMeta(paragraphIndentPreviewKey, next))
  }
  const start = (event: PointerEvent<HTMLButtonElement>, kind: IndentKind) => {
    if (!enabled || !editor || editor.view.composing || event.button !== 0 || !event.isPrimary || gesture.current) return
    event.preventDefault()
    event.currentTarget.setPointerCapture(event.pointerId)
    const source = readSnapshot(editor, canvas.current, width, left, right)
    setSnapshot(source)
    gesture.current = { pointer: event.pointerId, kind, startX: event.clientX, snapshot: source, doc: editor.state.doc, delta: 0, pendingX: event.clientX, frame: 0 }
    setActive(kind)
  }
  const move = (event: PointerEvent<HTMLButtonElement>) => {
    const current = gesture.current
    if (!current || current.pointer !== event.pointerId) return
    current.pendingX = event.clientX
    cancelAnimationFrame(current.frame)
    const fine = event.altKey
    current.frame = requestAnimationFrame(() => update(current.pendingX, fine))
  }
  const finish = (event: PointerEvent<HTMLButtonElement>, cancelled = false) => {
    const current = gesture.current
    if (!current || current.pointer !== event.pointerId || !editor) return
    cancelAnimationFrame(current.frame)
    if (!cancelled) update(event.clientX, event.altKey)
    gesture.current = null
    setPreview(null); setActive(null)
    if (!cancelled && current.delta !== 0 && editor.state.doc === current.doc) commit(values(current.snapshot, current.kind, current.delta))
    else editor.view.dispatch(editor.state.tr.setMeta(paragraphIndentPreviewKey, []))
    if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId)
  }
  const keyboard = (event: KeyboardEvent<HTMLButtonElement>, kind: IndentKind) => {
    if (event.key === 'Escape') { event.currentTarget.blur(); return }
    if (!enabled || !editor || gesture.current || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return
    event.preventDefault()
    const delta = event.key === 'Home' ? -1000 : event.key === 'End' ? 1000 : (event.key === 'ArrowLeft' ? -1 : 1) * (event.shiftKey ? 5 : event.altKey ? 0.1 : 0.5)
    commit(values(readSnapshot(editor, canvas.current, width, left, right), kind, delta))
  }
  const x = (kind: IndentKind) => (snapshot.origin + positions[kind] * MM_TO_PX) * zoom
  const valueText = (kind: IndentKind) => `${((kind === 'right' ? first?.rightIndent || 0 : kind === 'hanging' ? -(first?.firstIndent || 0) / MM_TO_PX : positions[kind]) / 10).toLocaleString('th-TH', { maximumFractionDigits: 2 })} ซม.`

  return <div ref={ruler} className="document-ruler" role="group" aria-label="ไม้บรรทัดแนวนอนและระยะเยื้องย่อหน้า" data-print-hidden style={{ width: width * zoom }}>
    <div className="document-ruler-margin" style={{ width: snapshot.origin * zoom }} />
    <div className="document-ruler-margin" style={{ left: (snapshot.origin + snapshot.width) * zoom, right: 0 }} />
    {Array.from({ length: Math.floor(width / MM_TO_PX / 2.5) + 1 }, (_, index) => {
      const tick = index - Math.floor(snapshot.origin / MM_TO_PX / 2.5)
      const mm = tick * 2.5
      return <span className={`document-ruler-tick${tick % 4 === 0 ? ' is-major' : ''}`} key={index} style={{ left: (snapshot.origin + mm * MM_TO_PX) * zoom }} aria-hidden="true">{tick % 4 === 0 ? mm / 10 : ''}</span>
    })}
    {(['first', 'hanging', 'left', 'right'] as const).map(kind => <button key={kind} type="button" role="slider" aria-label={labels[kind]} aria-orientation="horizontal" aria-valuemin={0} aria-valuemax={Number((snapshot.width / MM_TO_PX).toFixed(2))} aria-valuenow={Number(positions[kind].toFixed(2))} aria-valuetext={valueText(kind)} aria-disabled={!enabled} disabled={!enabled}
      title={`${labels[kind]} · ${valueText(kind)} · ลากหรือใช้ปุ่มลูกศร (Shift: 5 มม., Alt: 0.1 มม.)`}
      className={`document-indent-marker document-indent-marker--${kind}${active === kind ? ' is-active' : ''}`} style={{ left: x(kind) }}
      onPointerDown={event => start(event, kind)} onPointerMove={move} onPointerUp={event => finish(event)} onPointerCancel={event => finish(event, true)} onLostPointerCapture={event => finish(event, true)} onKeyDown={event => keyboard(event, kind)} onFocus={() => setActive(kind)} onBlur={() => { if (!gesture.current) setActive(null) }}><span aria-hidden="true" /></button>)}
    {active && enabled && <>
      <div className="document-indent-guide" style={{ left: x(active), height }} />
      <output className="document-indent-value" aria-live="polite" style={{ left: Math.max(4, Math.min(width * zoom - 180, x(active) - 70)) }}>{labels[active]} {valueText(active)}</output>
    </>}
  </div>
}
