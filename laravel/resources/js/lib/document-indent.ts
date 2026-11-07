import type { EditorState } from '@tiptap/pm/state'

const PX_PER_MM = 96 / 25.4
export type IndentKind = 'first' | 'hanging' | 'left' | 'right'
export type ParagraphIndent = { indent: number; rightIndent: number; firstIndent: number }
export type IndentTarget = ParagraphIndent & { position: number }
const clamp = (value: number, min: number, max: number) => Math.max(min, Math.min(Math.max(min, max), value))

/** firstIndent is a signed pixel offset from the left indent; the other values are millimeters. */
export function moveParagraphIndent(value: ParagraphIndent, kind: IndentKind, delta: number, width: number): ParagraphIndent {
  const first = value.indent + value.firstIndent / PX_PER_MM
  const end = width - value.rightIndent - 10
  if (kind === 'first') return { ...value, firstIndent: (clamp(first + delta, 0, end) - value.indent) * PX_PER_MM }
  if (kind === 'hanging') {
    const indent = clamp(value.indent + delta, 0, Math.min(120, end))
    return { ...value, indent, firstIndent: (first - indent) * PX_PER_MM }
  }
  if (kind === 'right') return { ...value, rightIndent: clamp(value.rightIndent - delta, 0, Math.min(120, width - Math.max(value.indent, first) - 10)) }
  const indent = clamp(value.indent + delta, Math.max(0, -value.firstIndent / PX_PER_MM), Math.min(120, end - Math.max(0, value.firstIndent / PX_PER_MM)))
  return { ...value, indent }
}

export function paragraphIndentTargets(state: EditorState): IndentTarget[] {
  const targets: IndentTarget[] = []
  const { from, to, $from, empty } = state.selection
  const add = (position: number) => {
    const node = state.doc.nodeAt(position)
    if (!node || !['paragraph', 'heading'].includes(node.type.name)) return
    const resolved = state.doc.resolve(position)
    const inherited = resolved.parent.type.name === 'recordBody' && resolved.index() === 0 ? Number(resolved.parent.attrs.firstIndent) || 0 : 0
    targets.push({ position, indent: Number(node.attrs.indent) || 0, rightIndent: Number(node.attrs.rightIndent) || 0, firstIndent: node.attrs.firstIndent === null || node.attrs.firstIndent === undefined ? inherited : Number(node.attrs.firstIndent) || 0 })
  }
  if (empty) { if ($from.depth) add($from.before()); return targets }
  state.doc.nodesBetween(from, to, (node, position) => {
    if (['paragraph', 'heading'].includes(node.type.name)) { add(position); return false }
  })
  return targets
}
