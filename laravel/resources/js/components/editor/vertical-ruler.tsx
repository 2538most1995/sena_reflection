import { useEffect, useRef, useState, type KeyboardEvent, type PointerEvent, type RefObject } from 'react'
import type { Editor } from '@tiptap/react'
import { closeHistory } from '@tiptap/pm/history'
import { MM_TO_PX, PAGE_GAP, type PageSettings } from '@/lib/document-model'
import { paragraphIndentTargets } from '@/lib/document-indent'
import { paragraphSpacingPreviewKey, type ParagraphSpacingTarget } from './document-extensions'
import { nativeTextSelectionActive } from './document-native-selection'

export const VERTICAL_RULER_GUTTER = 48
type Kind = 'top' | 'bottom' | 'before' | 'after'
const labels: Record<Kind, string> = { top: 'ขอบบนกระดาษ', bottom: 'ขอบล่างกระดาษ', before: 'ระยะก่อนย่อหน้า', after: 'ระยะหลังย่อหน้า' }
type Margins = PageSettings['margins']
type Profile = { targets: ParagraphSpacingTarget[]; page: number; beforeY: number; afterY: number }
type Gesture = { kind: Kind; pointer: number; startY: number; profile: Profile; margins: Margins; doc: Editor['state']['doc'] | null; delta: number; frame: number }
type Props = {
  editor: Editor | null; canvas: RefObject<HTMLDivElement | null>; page: number; top: number; width: number; height: number; zoom: number; margins: Margins; disabled: boolean
  onPreviewMargins: (margins: Margins | null) => void; onCommitMargins: (margins: Margins) => void
}
const limit = (value: number, maximum: number) => Math.max(0, Math.min(Math.max(0, maximum), value))

export function VerticalRuler(props: Props) {
  const { editor, canvas, page, top, width, height, zoom, margins, disabled } = props
  const latest = useRef(props)
  latest.current = props
  const [profile, setProfile] = useState<Profile>({ targets: [], page: -1, beforeY: 0, afterY: 0 })
  const [preview, setPreview] = useState<{ kind: Kind; value: number; y: number } | null>(null)
  const gesture = useRef<Gesture | null>(null)
  const measure = (): Profile => {
    if (!editor || editor.isDestroyed || !canvas.current) return { targets: [], page: -1, beforeY: 0, afterY: 0 }
    const candidates = paragraphIndentTargets(editor.state)
    const root = canvas.current.getBoundingClientRect()
    const scale = root.width / latest.current.width
    const targets = candidates.flatMap(target => {
      const node = editor.state.doc.nodeAt(target.position)
      const element = editor.view.nodeDOM(target.position)
      if (!node || !(element instanceof HTMLElement)) return []
      const style = getComputedStyle(element)
      return [{ position: target.position, before: Number(node.attrs.spaceBefore) || 0, after: node.attrs.spaceAfter === null ? (parseFloat(style.marginBottom) || 0) * 0.75 : Number(node.attrs.spaceAfter) || 0 }]
    })
    const element = candidates[0] && editor.view.nodeDOM(candidates[0].position)
    if (!(element instanceof HTMLElement) || !scale) return { targets: [], page: -1, beforeY: 0, afterY: 0 }
    const rect = element.getBoundingClientRect()
    const y = (rect.top - root.top) / scale
    const selectedPage = Math.max(0, Math.floor((y + 1) / (height + PAGE_GAP)))
    return { targets, page: selectedPage, beforeY: y - selectedPage * (height + PAGE_GAP), afterY: (rect.bottom - root.top) / scale - selectedPage * (height + PAGE_GAP) + (targets[0]?.after || 0) / 0.75 }
  }
  const cancel = () => {
    const current = gesture.current
    if (!current) return
    gesture.current = null
    cancelAnimationFrame(current.frame)
    setPreview(null)
    latest.current.onPreviewMargins(null)
    if (editor && !editor.isDestroyed) editor.view.dispatch(editor.state.tr.setMeta(paragraphSpacingPreviewKey, []))
  }
  useEffect(() => {
    if (!editor || editor.isDestroyed) return
    let frame = 0
    const refresh = () => {
      if (gesture.current && editor.state.doc !== gesture.current.doc) { cancel(); return }
      cancelAnimationFrame(frame)
      frame = requestAnimationFrame(() => { if (!gesture.current && !editor.isDestroyed && !nativeTextSelectionActive(editor.view)) setProfile(measure()) })
    }
    const key = (event: globalThis.KeyboardEvent) => { if (event.key === 'Escape' && gesture.current) { event.preventDefault(); cancel(); refresh() } }
    editor.on('transaction', refresh)
    const observer = new ResizeObserver(refresh)
    observer.observe(editor.view.dom)
    window.addEventListener('keydown', key)
    window.addEventListener('blur', cancel)
    refresh()
    return () => { editor.off('transaction', refresh); observer.disconnect(); window.removeEventListener('keydown', key); window.removeEventListener('blur', cancel); cancel(); cancelAnimationFrame(frame) }
  }, [editor, page, height, zoom, disabled])

  const marginValue = (kind: 'top' | 'bottom', source: Margins, delta: number) => ({ ...source, [kind]: limit(source[kind] + (kind === 'bottom' ? -delta : delta), Math.min(100, height / MM_TO_PX - source[kind === 'top' ? 'bottom' : 'top'] - 10)) })
  const spacingValues = (source: Profile, kind: 'before' | 'after', delta: number) => source.targets.map(target => ({ ...target, [kind]: limit(target[kind] + delta, 120) }))
  const yFor = (kind: Kind) => kind === 'top' ? margins.top * MM_TO_PX : kind === 'bottom' ? height - margins.bottom * MM_TO_PX : kind === 'before' ? profile.beforeY : profile.afterY
  const valueFor = (kind: Kind) => kind === 'top' || kind === 'bottom' ? margins[kind] : profile.targets[0]?.[kind] || 0
  const text = (kind: Kind, value: number) => `${labels[kind]} ${(kind === 'top' || kind === 'bottom' ? value / 10 : value).toLocaleString('th-TH', { maximumFractionDigits: 2 })} ${kind === 'top' || kind === 'bottom' ? 'ซม.' : 'pt'}`
  const applyPreview = (current: Gesture, delta: number) => {
    current.delta = delta
    if (current.kind === 'top' || current.kind === 'bottom') {
      const next = marginValue(current.kind, current.margins, delta)
      latest.current.onPreviewMargins(next)
      setPreview({ kind: current.kind, value: next[current.kind], y: (current.kind === 'top' ? next.top * MM_TO_PX : height - next.bottom * MM_TO_PX) * zoom })
    } else if (editor && !editor.isDestroyed) {
      const next = spacingValues(current.profile, current.kind, delta)
      editor.view.dispatch(editor.state.tr.setMeta(paragraphSpacingPreviewKey, next))
      const actual = (next[0]?.[current.kind] || 0) - (current.profile.targets[0]?.[current.kind] || 0)
      setPreview({ kind: current.kind, value: next[0]?.[current.kind] || 0, y: (current.kind === 'before' ? current.profile.beforeY : current.profile.afterY) * zoom + actual / 0.75 * zoom })
    }
  }
  const commit = (current: Gesture) => {
    if (current.kind === 'top' || current.kind === 'bottom') latest.current.onCommitMargins(marginValue(current.kind, current.margins, current.delta))
    else if (editor && !editor.isDestroyed && editor.state.doc === current.doc) {
      const next = spacingValues(current.profile, current.kind, current.delta)
      const tr = closeHistory(editor.state.tr).setMeta(paragraphSpacingPreviewKey, []).setMeta('documentGestureBoundary', true)
      for (const target of next) {
        const node = tr.doc.nodeAt(target.position)
        if (node) tr.setNodeMarkup(target.position, undefined, { ...node.attrs, spaceBefore: target.before, spaceAfter: target.after })
      }
      editor.view.dispatch(tr)
      editor.view.dispatch(closeHistory(editor.state.tr).setMeta('addToHistory', false))
    }
    latest.current.onPreviewMargins(null)
    setProfile(measure())
  }
  const start = (event: PointerEvent<HTMLButtonElement>, kind: Kind) => {
    if (disabled || event.button !== 0 || !event.isPrimary || gesture.current || editor?.view.composing) return
    event.preventDefault()
    const source = measure()
    if ((kind === 'before' || kind === 'after') && (!source.targets.length || source.page !== page)) return
    event.currentTarget.setPointerCapture(event.pointerId)
    setProfile(source)
    gesture.current = { kind, pointer: event.pointerId, startY: event.clientY, profile: source, margins: { ...margins }, doc: editor?.state.doc || null, delta: 0, frame: 0 }
    setPreview({ kind, value: valueFor(kind), y: yFor(kind) * zoom })
  }
  const update = (event: PointerEvent<HTMLButtonElement>) => {
    const current = gesture.current
    if (!current || current.pointer !== event.pointerId) return
    const pixels = (event.clientY - current.startY) / zoom
    const units = current.kind === 'top' || current.kind === 'bottom' ? pixels / MM_TO_PX : pixels * 0.75
    const step = event.altKey ? 0.1 : current.kind === 'top' || current.kind === 'bottom' ? 0.5 : 1
    cancelAnimationFrame(current.frame)
    current.frame = requestAnimationFrame(() => applyPreview(current, Math.round(units / step) * step))
  }
  const finish = (event: PointerEvent<HTMLButtonElement>, rejected = false) => {
    const current = gesture.current
    if (!current || current.pointer !== event.pointerId) return
    cancelAnimationFrame(current.frame)
    if (!rejected) {
      const pixels = (event.clientY - current.startY) / zoom
      const units = current.kind === 'top' || current.kind === 'bottom' ? pixels / MM_TO_PX : pixels * 0.75
      const step = event.altKey ? 0.1 : current.kind === 'top' || current.kind === 'bottom' ? 0.5 : 1
      current.delta = Math.round(units / step) * step
    }
    gesture.current = null
    setPreview(null)
    if (!rejected && current.delta !== 0) commit(current)
    else { latest.current.onPreviewMargins(null); if (editor && !editor.isDestroyed) editor.view.dispatch(editor.state.tr.setMeta(paragraphSpacingPreviewKey, [])) }
    if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId)
  }
  const keyboard = (event: KeyboardEvent<HTMLButtonElement>, kind: Kind) => {
    if (disabled || !['ArrowUp', 'ArrowDown'].includes(event.key)) return
    event.preventDefault()
    const step = event.shiftKey ? 5 : event.altKey ? 0.1 : kind === 'top' || kind === 'bottom' ? 0.5 : 1
    commit({ kind, pointer: -1, startY: 0, profile: measure(), margins, doc: editor?.state.doc || null, delta: (event.key === 'ArrowUp' ? -1 : 1) * step, frame: 0 })
  }
  return <div className="document-vertical-ruler-control" role="group" aria-label={`ไม้บรรทัดแนวตั้งหน้า ${page + 1}`} data-print-hidden style={{ top, height: height * zoom }}>
    <div className="document-vertical-margin" style={{ height: margins.top * MM_TO_PX * zoom }} />
    <div className="document-vertical-margin" style={{ top: (height - margins.bottom * MM_TO_PX) * zoom, bottom: 0 }} />
    {Array.from({ length: Math.floor(height / MM_TO_PX / 5) + 1 }, (_, index) => <span className="document-vertical-tick" key={index} style={{ top: index * 5 * MM_TO_PX * zoom }} aria-hidden="true">{index % 2 === 0 ? index / 2 : ''}</span>)}
    {(['top', 'bottom', ...(profile.page === page && profile.targets.length ? ['before', 'after'] : [])] as Kind[]).map(kind => <button key={kind} type="button" role="slider" aria-orientation="vertical" aria-label={labels[kind]} aria-valuemin={0} aria-valuemax={kind === 'top' || kind === 'bottom' ? 100 : 120} aria-valuenow={Number((preview?.kind === kind ? preview.value : valueFor(kind)).toFixed(2))} aria-valuetext={text(kind, preview?.kind === kind ? preview.value : valueFor(kind))} disabled={disabled || ((kind === 'before' || kind === 'after') && !editor?.isEditable)} title={`${text(kind, valueFor(kind))} · ลากหรือใช้ลูกศรขึ้นลง`}
      className={`document-vertical-marker document-vertical-marker--${kind}`} style={{ top: preview?.kind === kind ? preview.y : yFor(kind) * zoom }} onPointerDown={event => start(event, kind)} onPointerMove={update} onPointerUp={event => finish(event)} onPointerCancel={event => finish(event, true)} onLostPointerCapture={event => finish(event, true)} onKeyDown={event => keyboard(event, kind)}><span /></button>)}
    {preview && <><div className="document-vertical-guide" style={{ top: preview.y, width: width * zoom }} /><output className="document-vertical-value" style={{ top: Math.max(2, preview.y - 32) }}>{text(preview.kind, preview.value)}</output></>}
  </div>
}
