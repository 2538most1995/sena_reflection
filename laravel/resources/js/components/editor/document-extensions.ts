import { Extension, Node, mergeAttributes, type CommandProps, type JSONContent } from '@tiptap/core'
import { Plugin, PluginKey, type EditorState } from '@tiptap/pm/state'
import { closeHistory } from '@tiptap/pm/history'
import { Decoration, DecorationSet } from '@tiptap/pm/view'
import type { Node as ProseMirrorNode } from '@tiptap/pm/model'
import interact from 'interactjs'
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
        field: { default: null, parseHTML: element => element.getAttribute('data-record-field'), renderHTML: attrs => attrs.field ? { 'data-record-field': attrs.field } : {} },
        role: { default: null, parseHTML: element => element.getAttribute('data-record-role'), renderHTML: attrs => attrs.role ? { 'data-record-role': attrs.role } : {} },
        firstIndent: { default: null, parseHTML: element => element.hasAttribute('data-first-indent') ? Number(element.getAttribute('data-first-indent')) : null, renderHTML: attrs => attrs.firstIndent !== null ? { 'data-first-indent': Number(attrs.firstIndent), style: `text-indent:${Number(attrs.firstIndent) || 0}px` } : {} },
        recordLine: { default: false, parseHTML: element => element.getAttribute('data-record-line') === '1', renderHTML: attrs => attrs.recordLine ? { 'data-record-line': '1' } : {} },
        lineHeight: { default: null, parseHTML: element => element.style.lineHeight || null, renderHTML: attrs => attrs.lineHeight ? { style: `line-height: ${attrs.lineHeight}` } : {} },
        spaceBefore: { default: 0, parseHTML: element => parseFloat(element.style.marginTop) || 0, renderHTML: attrs => ({ style: `margin-top:${Number(attrs.spaceBefore) || 0}pt` }) },
        spaceAfter: { default: null, parseHTML: element => element.style.marginBottom ? parseFloat(element.style.marginBottom) : null, renderHTML: attrs => attrs.spaceAfter !== null ? { style: `margin-bottom:${Number(attrs.spaceAfter) || 0}pt` } : {} },
        indent: { default: 0, parseHTML: element => parseFloat(element.getAttribute('data-indent') || '') || 0, renderHTML: attrs => ({ 'data-indent': Number(attrs.indent) || 0, style: `margin-left:${Number(attrs.indent) || 0}mm` }) },
        offsetX: { default: 0, parseHTML: element => Number(element.getAttribute('data-record-offset-x')) || 0, renderHTML: attrs => attrs.offsetX ? { 'data-record-offset-x': Number(attrs.offsetX), style: `margin-left:calc(${Number(attrs.indent) || 0}mm + ${Number(attrs.offsetX) || 0}px)` } : {} },
        offsetY: { default: 0, parseHTML: element => Number(element.getAttribute('data-record-offset-y')) || 0, renderHTML: attrs => attrs.offsetY ? { 'data-record-offset-y': Number(attrs.offsetY), style: `margin-top:calc(${Number(attrs.spaceBefore) || 0}pt + ${Number(attrs.offsetY) || 0}px)` } : {} },
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

function numericAttribute(name: string, fallback: number, minimum = -2400, maximum = 2400) {
  return {
    default: fallback,
    parseHTML: (element: HTMLElement) => element.hasAttribute(`data-record-${name}`) ? Number(element.getAttribute(`data-record-${name}`)) : fallback,
    renderHTML: (attributes: Record<string, unknown>) => ({ [`data-record-${name}`]: Math.min(maximum, Math.max(minimum, Number(attributes[name]) || 0)) }),
  }
}

function booleanAttribute(name: string, fallback = false) {
  return {
    default: fallback,
    parseHTML: (element: HTMLElement) => element.getAttribute(`data-record-${name}`) === '1',
    renderHTML: (attributes: Record<string, unknown>) => ({ [`data-record-${name}`]: attributes[name] ? '1' : '0' }),
  }
}

function recordFieldAttribute() {
  return { default: null, parseHTML: (element: HTMLElement) => element.getAttribute('data-record-field'), renderHTML: (attributes: Record<string, unknown>) => attributes.field ? { 'data-record-field': String(attributes.field) } : {} }
}

export const RecordInfoRow = Node.create({
  name: 'recordInfoRow', group: 'block', content: 'recordInfoCell+', isolating: true,
  addAttributes() { return {
    id: { default: null, parseHTML: element => element.getAttribute('data-record-row-id'), renderHTML: attributes => attributes.id ? { 'data-record-row-id': attributes.id } : {} },
    compact: booleanAttribute('compact'),
    offsetX: numericAttribute('offsetX', 0), offsetY: numericAttribute('offsetY', 0),
  } },
  parseHTML: () => [{ tag: 'table[data-record-node="info-row"]', priority: 1000 }],
  renderHTML({ node, HTMLAttributes }) {
    return ['table', mergeAttributes(HTMLAttributes, { 'data-record-node': 'info-row', class: `record-info-row${node.attrs.compact ? ' record-info-row--compact' : ''}`, style: `margin-left:${node.attrs.compact ? 'auto' : '0'};transform:translateX(${Number(node.attrs.offsetX) || 0}px);margin-top:${Number(node.attrs.offsetY) || 0}px` }), ['tbody', {}, ['tr', {}, 0]]]
  },
})

export const RecordInfoCell = Node.create({
  name: 'recordInfoCell', content: 'inline*', isolating: true,
  addAttributes() { return {
    width: numericAttribute('width', 0, 0, 1000),
    unit: { default: 'percent', parseHTML: element => element.getAttribute('data-record-unit') === 'px' ? 'px' : 'percent', renderHTML: attributes => ({ 'data-record-unit': attributes.unit === 'px' ? 'px' : 'percent' }) },
    dotted: booleanAttribute('dotted'), nowrap: booleanAttribute('nowrap'), label: booleanAttribute('label'), field: recordFieldAttribute(),
    offsetX: numericAttribute('offsetX', 0), offsetY: numericAttribute('offsetY', 0),
  } },
  parseHTML: () => [{ tag: 'td[data-record-node="info-cell"]', priority: 1000 }],
  renderHTML({ node, HTMLAttributes }) {
    const width = Math.min(node.attrs.unit === 'px' ? 1000 : 100, Math.max(0, Number(node.attrs.width) || 0))
    return ['td', mergeAttributes(HTMLAttributes, {
      'data-record-node': 'info-cell', class: `record-info-cell${node.attrs.dotted ? ' record-info-cell--dotted' : ''}${node.attrs.label ? ' record-info-cell--label' : ''}`,
      style: `${width > 0 ? `width:${width}${node.attrs.unit === 'px' ? 'px' : '%'};` : ''}${node.attrs.nowrap ? 'white-space:nowrap;' : ''}${node.attrs.offsetX || node.attrs.offsetY ? `position:relative;left:${Number(node.attrs.offsetX) || 0}px;top:${Number(node.attrs.offsetY) || 0}px;` : ''}`,
    }), 0]
  },
  addKeyboardShortcuts() {
    return { Enter: () => this.editor.state.selection.$from.parent.type.name === 'recordInfoCell' ? this.editor.commands.setHardBreak() : false }
  },
})

export const RecordSectionTitle = Node.create({
  name: 'recordSectionTitle', group: 'block', content: 'inline*', defining: true,
  addAttributes() { return { field: recordFieldAttribute(), offsetX: numericAttribute('offsetX', 0), offsetY: numericAttribute('offsetY', 0), spaceBefore: numericAttribute('spaceBefore', 8, 0, 240) } },
  parseHTML: () => [{ tag: 'div[data-record-node="section-title"]' }],
  renderHTML: ({ node, HTMLAttributes }) => ['div', mergeAttributes(HTMLAttributes, { 'data-record-node': 'section-title', class: 'record-section-title', style: `margin-left:${Number(node.attrs.offsetX) || 0}px;margin-top:${(Number(node.attrs.spaceBefore) || 0) + (Number(node.attrs.offsetY) || 0)}px` }), 0],
})

export const RecordBody = Node.create({
  name: 'recordBody', group: 'block', content: '(paragraph | bulletList | orderedList | pageBreak)+', defining: true,
  addAttributes() { return {
    field: recordFieldAttribute(), minLines: numericAttribute('minLines', 1, 1, 100), lineSpacing: numericAttribute('lineSpacing', 26, 12, 80),
    firstIndent: numericAttribute('firstIndent', 42, 0, 160), spaceAfter: numericAttribute('spaceAfter', 0, 0, 240),
    align: { default: 'justify', parseHTML: element => element.getAttribute('data-record-align') || 'justify', renderHTML: attributes => ({ 'data-record-align': ['left', 'center', 'right', 'justify', 'thai-distributed'].includes(String(attributes.align)) ? String(attributes.align) : 'justify' }) },
  } },
  parseHTML: () => [{ tag: 'div[data-record-node="body"]' }],
  renderHTML({ node, HTMLAttributes }) {
    const line = Math.min(80, Math.max(12, Number(node.attrs.lineSpacing) || 26))
    const align = ['left', 'center', 'right', 'justify', 'thai-distributed'].includes(node.attrs.align) ? node.attrs.align : 'justify'
    return ['div', mergeAttributes(HTMLAttributes, { 'data-record-node': 'body', class: 'record-body', style: `--record-body-line:${line}px;--record-first-indent:${Math.min(160, Math.max(0, Number(node.attrs.firstIndent) || 0))}px;--record-body-align:${align === 'thai-distributed' ? 'justify' : align};--record-body-justify:inter-character;min-height:${Math.max(1, Number(node.attrs.minLines) || 1) * line}px;margin-bottom:${Number(node.attrs.spaceAfter) || 0}px` }), 0]
  },
})

export function signatureMoveTransaction(state: EditorState, position: number, x: number, y: number) {
  const node = state.doc.nodeAt(position)
  if (node?.type.name !== 'recordSignature') return null
  const offsetX = Math.round(Math.max(-2400, Math.min(2400, x)) * 100) / 100
  const offsetY = Math.round(Math.max(-2400, Math.min(2400, y)) * 100) / 100
  if (offsetX === Number(node.attrs.offsetX) && offsetY === Number(node.attrs.offsetY)) return null
  return closeHistory(state.tr).setNodeMarkup(position, undefined, { ...node.attrs, offsetX, offsetY }).setMeta('documentGestureBoundary', true)
}

export const RecordSignature = Node.create({
  name: 'recordSignature', group: 'block', content: 'block+', defining: true,
  addAttributes() { return {
    index: numericAttribute('index', 1, 1, 3), width: numericAttribute('width', 430, 80, 1000), marginTop: numericAttribute('marginTop', 16, -240, 240),
    offsetX: numericAttribute('offsetX', 0), offsetY: numericAttribute('offsetY', 0),
    align: { default: 'right', parseHTML: element => element.getAttribute('data-record-align') || 'right', renderHTML: attributes => ({ 'data-record-align': ['left', 'center', 'right'].includes(String(attributes.align)) ? String(attributes.align) : 'right' }) },
  } },
  parseHTML: () => [{ tag: 'div[data-record-node="signature"]' }],
  renderHTML({ node, HTMLAttributes }) {
    const align = node.attrs.align === 'left' ? 'flex-start' : node.attrs.align === 'center' ? 'center' : 'flex-end'
    return ['div', mergeAttributes(HTMLAttributes, { 'data-record-node': 'signature', class: 'record-signature', style: `justify-content:${align};margin-top:${(Number(node.attrs.marginTop) || 0) + (Number(node.attrs.offsetY) || 0)}px` }), ['div', { class: 'record-signature-content', style: `width:${Number(node.attrs.width) || 430}px;transform:translateX(${Number(node.attrs.offsetX) || 0}px)` }, ['div', { class: 'record-signature-box' }, 0]]]
  },
  addNodeView() {
    return ({ node, editor, getPos, HTMLAttributes }) => {
      let currentNode = node
      const dom = document.createElement('div')
      dom.className = 'record-signature'
      dom.dataset.recordNode = 'signature'
      dom.tabIndex = -1
      const box = document.createElement('div')
      box.className = 'record-signature-content'
      const contentDOM = document.createElement('div')
      contentDOM.className = 'record-signature-box'
      const handle = document.createElement('button')
      handle.type = 'button'
      handle.className = 'record-signature-move-handle'
      handle.dataset.printHidden = 'true'
      handle.contentEditable = 'false'
      handle.textContent = '✥ ย้าย'
      let dragging = false
      let offsetX = 0, offsetY = 0, originalY = 0, scale = 1
      let interaction: { stop: () => void } | null = null
      box.append(handle, contentDOM)
      dom.append(box)
      const update = (next: ProseMirrorNode) => {
        if (next.type.name !== 'recordSignature') return false
        currentNode = next
        for (const [attribute, value] of Object.entries(HTMLAttributes)) {
          if (attribute !== 'class' && attribute !== 'style' && value !== null && value !== undefined) dom.setAttribute(attribute, String(value))
        }
        dom.dataset.recordIndex = String(next.attrs.index)
        dom.dataset.recordAlign = String(next.attrs.align)
        dom.dataset.recordOffsetX = String(next.attrs.offsetX)
        dom.dataset.recordOffsetY = String(next.attrs.offsetY)
        dom.style.justifyContent = next.attrs.align === 'left' ? 'flex-start' : next.attrs.align === 'center' ? 'center' : 'flex-end'
        if (!dragging) dom.style.marginTop = `${(Number(next.attrs.marginTop) || 0) + (Number(next.attrs.offsetY) || 0)}px`
        box.style.width = `${Number(next.attrs.width) || 430}px`
        if (!dragging) box.style.transform = `translateX(${Number(next.attrs.offsetX) || 0}px)`
        handle.title = `ลากเพื่อย้ายลายเซ็นที่ ${next.attrs.index} · ลูกศรขยับ 1 px · Shift 10 px`
        handle.setAttribute('aria-label', `ลากเพื่อย้ายลายเซ็นที่ ${next.attrs.index}`)
        return true
      }
      update(node)
      handle.addEventListener('pointerdown', event => {
        event.preventDefault()
        const position = getPos()
        if (typeof position === 'number' && editor.isEditable) {
          editor.commands.setNodeSelection(position)
          handle.focus({ preventScroll: true })
        }
      })
      const commit = (x: number, y: number) => {
        const position = getPos()
        if (!editor.isEditable || typeof position !== 'number' || editor.view.composing) return
        const transaction = signatureMoveTransaction(editor.state, position, x, y)
        if (transaction) {
          editor.view.dispatch(transaction)
          editor.view.dispatch(closeHistory(editor.state.tr).setMeta('addToHistory', false))
        }
      }
      const finish = (save: boolean) => {
        if (!dragging) return
        dragging = false
        interaction = null
        dom.classList.remove('is-dragging')
        box.style.willChange = ''
        if (save) commit(offsetX, offsetY)
        update(currentNode)
      }
      handle.addEventListener('keydown', event => {
        if (event.key === 'Escape' && dragging) {
          event.preventDefault()
          const active = interaction
          finish(false)
          active?.stop()
          return
        }
        if (event.ctrlKey || event.metaKey) {
          if (event.key.toLowerCase() === 'z' || event.key.toLowerCase() === 'y') {
            event.preventDefault()
            if (event.shiftKey || event.key.toLowerCase() === 'y') editor.commands.redo()
            else editor.commands.undo()
          }
          return
        }
        const moves: Record<string, [number, number]> = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }
        const move = moves[event.key]
        if (move && !dragging && editor.isEditable) {
          event.preventDefault()
          const step = event.shiftKey ? 10 : event.altKey ? 0.25 : 1
          commit((Number(currentNode.attrs.offsetX) || 0) + move[0] * step, (Number(currentNode.attrs.offsetY) || 0) + move[1] * step)
        }
      })
      handle.addEventListener('pointercancel', () => {
        const active = interaction
        finish(false)
        active?.stop()
      })
      const draggable = interact(handle).draggable({ listeners: {
        start(event) {
          if (!editor.isEditable || editor.view.composing) { event.interaction.stop(); return }
          offsetX = Number(currentNode.attrs.offsetX) || 0
          offsetY = Number(currentNode.attrs.offsetY) || 0
          originalY = offsetY
          scale = editor.view.dom.getBoundingClientRect().width / editor.view.dom.offsetWidth || 1
          dragging = true
          interaction = event.interaction
          dom.classList.add('is-dragging')
          box.style.willChange = 'transform'
        },
        move(event) {
          if (!dragging) return
          offsetX = Math.max(-2400, Math.min(2400, offsetX + event.dx / scale))
          offsetY = Math.max(-2400, Math.min(2400, offsetY + event.dy / scale))
          box.style.transform = `translate(${offsetX}px,${offsetY - originalY}px)`
        },
        end: () => finish(true),
      } })
      return {
        dom, contentDOM, update,
        selectNode: () => dom.classList.add('ProseMirror-selectednode'),
        deselectNode: () => dom.classList.remove('ProseMirror-selectednode'),
        stopEvent: event => event.target instanceof Element && (event.target === handle || handle.contains(event.target)),
        ignoreMutation: mutation => mutation.type !== 'selection' && ((mutation.type === 'attributes' && ([dom, box, handle] as HTMLElement[]).includes(mutation.target as HTMLElement)) || handle.contains(mutation.target)),
        destroy: () => { finish(false); draggable.unset() },
      }
    }
  },
})

export const RecordGroup = Node.create({
  name: 'recordGroup', group: 'block', content: 'block+', defining: true,
  addAttributes() { return { spaceBefore: numericAttribute('spaceBefore', 0, 0, 240), offsetX: numericAttribute('offsetX', 0), offsetY: numericAttribute('offsetY', 0), borderTop: booleanAttribute('borderTop') } },
  parseHTML: () => [{ tag: 'div[data-record-node="group"]' }],
  renderHTML: ({ node, HTMLAttributes }) => ['div', mergeAttributes(HTMLAttributes, { 'data-record-node': 'group', class: `record-group${node.attrs.borderTop ? ' record-group--border' : ''}`, style: `margin-top:${(Number(node.attrs.spaceBefore) || 0) + (Number(node.attrs.offsetY) || 0)}px;transform:translateX(${Number(node.attrs.offsetX) || 0}px)` }), 0],
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

export const RecordTemplate = Extension.create({
  name: 'recordTemplate',
  addExtensions: () => [PageBreak, RecordInfoRow, RecordInfoCell, RecordSectionTitle, RecordBody, RecordSignature, RecordGroup],
})

export type FlowReservation = { id: string; position: number; nodeSize: number; side: 'left' | 'right'; width: number; height: number; offset: number; wrap: DocumentObject['wrap']; afterText?: boolean }

export function objectFlowAfterText(object: Pick<DocumentObject, 'id' | 'type' | 'anchor'>, paragraph: ProseMirrorNode | null, isRecordTemplate: boolean): boolean {
  return isRecordTemplate && object.type === 'image' && object.id.startsWith('record-image-')
    && object.anchor.kind === 'paragraph' && paragraph?.attrs.id === object.anchor.id && paragraph.attrs.role === 'recordTitle'
}
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
            let left = 0, right = 0, top = 0, bottom = 0
            for (const item of items) {
              if (item.afterText) bottom = Math.max(bottom, item.height + item.offset)
              else if (item.wrap === 'top-bottom') top = Math.max(top, item.height + item.offset)
              else if (item.side === 'left') left = Math.max(left, item.width)
              else right = Math.max(right, item.width)
            }
            return Decoration.node(position, position + items[0].nodeSize, {
              class: 'document-wrapped-paragraph',
              'data-wrap-objects': items.map(item => item.id).join(' '),
              style: `box-sizing:border-box;padding-left:${left}px;padding-right:${right}px;padding-top:${top}px;padding-bottom:${bottom}px`,
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
