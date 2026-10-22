import { Extension, Node, mergeAttributes, type CommandProps, type JSONContent } from '@tiptap/core'
import { Plugin, PluginKey } from '@tiptap/pm/state'
import { Decoration, DecorationSet } from '@tiptap/pm/view'
import type { DocumentObject } from '@/lib/document-model'

declare module '@tiptap/core' {
  interface Commands<ReturnType> {
    documentParagraph: {
      setParagraphSpacing: (before: number, after: number) => ReturnType
      setParagraphLineHeight: (height: number) => ReturnType
      setParagraphIndent: (millimeters: number) => ReturnType
      increaseIndent: () => ReturnType
      decreaseIndent: () => ReturnType
    }
    documentPageBreak: { insertPageBreak: () => ReturnType }
  }
}

export const ParagraphFormatting = Extension.create({
  name: 'documentParagraph',
  onCreate() {
    if (!this.editor.isEditable) return
    this.editor.commands.command(({ tr }) => {
      tr.doc.descendants((node, position) => {
        if (['paragraph', 'heading'].includes(node.type.name) && !node.attrs.id) tr.setNodeMarkup(position, undefined, { ...node.attrs, id: crypto.randomUUID() })
      })
      tr.setMeta('addToHistory', false)
      return true
    })
  },
  addGlobalAttributes() {
    return [{
      types: ['paragraph', 'heading'],
      attributes: {
        id: { default: null, parseHTML: element => element.getAttribute('data-paragraph-id'), renderHTML: attrs => attrs.id ? { 'data-paragraph-id': attrs.id } : {} },
        lineHeight: { default: null, parseHTML: element => element.style.lineHeight || null, renderHTML: attrs => attrs.lineHeight ? { style: `line-height: ${attrs.lineHeight}` } : {} },
        spaceBefore: { default: 0, parseHTML: element => parseFloat(element.style.marginTop) || 0, renderHTML: attrs => ({ style: `margin-top:${Number(attrs.spaceBefore) || 0}pt` }) },
        spaceAfter: { default: 8, parseHTML: element => parseFloat(element.style.marginBottom) || 8, renderHTML: attrs => ({ style: `margin-bottom:${Number(attrs.spaceAfter) || 0}pt` }) },
        indent: { default: 0, parseHTML: element => parseFloat(element.getAttribute('data-indent') || '') || 0, renderHTML: attrs => ({ 'data-indent': Number(attrs.indent) || 0, style: `margin-left:${Number(attrs.indent) || 0}mm` }) },
      },
    }]
  },
  addCommands() {
    const update = (values: Record<string, number>) => ({ commands }: CommandProps) => commands.updateAttributes('paragraph', values) && commands.updateAttributes('heading', values)
    return {
      setParagraphSpacing: (before, after) => update({ spaceBefore: Math.max(0, before), spaceAfter: Math.max(0, after) }),
      setParagraphLineHeight: height => update({ lineHeight: Math.max(0.7, Math.min(4, height)) }),
      setParagraphIndent: millimeters => update({ indent: Math.max(0, Math.min(120, millimeters)) }),
      increaseIndent: () => ({ commands, editor }) => commands.setParagraphIndent(Number(editor.getAttributes('paragraph').indent ?? editor.getAttributes('heading').indent ?? 0) + 5),
      decreaseIndent: () => ({ commands, editor }) => commands.setParagraphIndent(Number(editor.getAttributes('paragraph').indent ?? editor.getAttributes('heading').indent ?? 0) - 5),
    }
  },
  addProseMirrorPlugins() {
    return [new Plugin({
      key: new PluginKey('documentParagraphIds'),
      appendTransaction(transactions, _old, state) {
        if (!transactions.some(transaction => transaction.docChanged)) return null
        const transaction = state.tr
        const ids = new Set<string>()
        state.doc.descendants((node, position) => {
          if (!['paragraph', 'heading'].includes(node.type.name)) return
          if (!node.attrs.id || ids.has(node.attrs.id)) transaction.setNodeMarkup(position, undefined, { ...node.attrs, id: crypto.randomUUID() })
          else ids.add(node.attrs.id)
        })
        return transaction.docChanged ? transaction.setMeta('addToHistory', false) : null
      },
    })]
  },
})

export const PageBreak = Node.create({
  name: 'pageBreak',
  group: 'block',
  atom: true,
  selectable: true,
  parseHTML: () => [{ tag: 'div[data-page-break]' }],
  renderHTML: ({ HTMLAttributes }) => ['div', mergeAttributes(HTMLAttributes, { 'data-page-break': '', class: 'document-manual-page-break', 'aria-label': 'ตัวแบ่งหน้า' })],
  addCommands() { return { insertPageBreak: () => ({ commands }) => commands.insertContent([{ type: 'pageBreak' }, { type: 'paragraph' }]) } },
  addKeyboardShortcuts() { return { 'Mod-Enter': () => this.editor.commands.insertPageBreak() } },
})

export type FlowReservation = { id: string; position: number; nodeSize: number; side: 'left' | 'right'; width: number; height: number; offset: number; wrap: DocumentObject['wrap'] }
export const objectFlowKey = new PluginKey<DecorationSet>('documentObjectFlow')

export const ObjectTextFlow = Extension.create({
  name: 'documentObjectFlow',
  addProseMirrorPlugins() {
    return [new Plugin({
      key: objectFlowKey,
      state: {
        init: () => DecorationSet.empty,
        apply(transaction, previous, _oldState, state) {
          const reservations = transaction.getMeta(objectFlowKey) as FlowReservation[] | undefined
          if (!reservations) return previous.map(transaction.mapping, transaction.doc)
          const grouped = new Map<number, FlowReservation[]>()
          for (const reservation of reservations) grouped.set(reservation.position, [...(grouped.get(reservation.position) || []), reservation])
          return DecorationSet.create(state.doc, [...grouped.entries()].map(([position, items]) => {
            let left = 0, right = 0, top = 0
            for (const item of items) {
              if (item.wrap === 'top-bottom') top = Math.max(top, item.height + item.offset)
              else if (item.side === 'left') left = Math.max(left, item.width)
              else right = Math.max(right, item.width)
            }
            return Decoration.node(position, position + items[0].nodeSize, {
              class: 'document-wrapped-paragraph',
              'data-wrap-objects': items.map(item => item.id).join(' '),
              style: `box-sizing:border-box;padding-left:${left}px;padding-right:${right}px;padding-top:${top}px`,
            })
          }))
        },
      },
      props: { decorations: state => objectFlowKey.getState(state) },
    })]
  },
})

export function hasDocumentContent(content: JSONContent | undefined): JSONContent {
  return content?.type === 'doc' ? content : { type: 'doc', content: [{ type: 'paragraph' }] }
}
