import { Extension } from '@tiptap/core'
import { Plugin, TextSelection } from '@tiptap/pm/state'
import type { EditorView } from '@tiptap/pm/view'

const selecting = new WeakSet<EditorView>()
export const nativeTextSelectionActive = (view: EditorView) => selecting.has(view)

/** Defer layout work while the browser is extending a native text selection. */
export const NativeTextSelection = Extension.create({
  name: 'documentNativeTextSelection',
  addProseMirrorPlugins() {
    return [new Plugin({ view(view) {
      const ownerWindow = view.dom.ownerDocument.defaultView!
      let frame = 0
      let destroyed = false
      let pointerType = 'mouse'
      let mouseAnchor: { position: number; doc: EditorView['state']['doc'] } | null = null
      let selectionFrame = 0
      const positionAt = (x: number, y: number) => {
        const document = view.dom.ownerDocument as Document & { caretPositionFromPoint?: (x: number, y: number) => { offsetNode: Node; offset: number } | null; caretRangeFromPoint?: (x: number, y: number) => Range | null }
        const caret = document.caretPositionFromPoint?.(x, y)
        const range = caret ? null : document.caretRangeFromPoint?.(x, y)
        const node = caret?.offsetNode || range?.startContainer
        const offset = caret?.offset ?? range?.startOffset
        if (!node || offset === undefined || !view.dom.contains(node)) return null
        return view.posAtDOM(node, offset)
      }
      const selectAt = (x: number, y: number) => {
        if (!mouseAnchor || view.state.doc !== mouseAnchor.doc) { mouseAnchor = null; return }
        const position = positionAt(x, y)
        if (position !== null) view.dispatch(view.state.tr.setSelection(TextSelection.between(view.state.doc.resolve(mouseAnchor.position), view.state.doc.resolve(position))).setMeta('addToHistory', false))
      }
      const mouseDown = (event: MouseEvent) => {
        mouseAnchor = null
        if (pointerType !== 'mouse' || event.button !== 0 || event.detail !== 1 || event.ctrlKey || event.metaKey || !view.editable) return
        const target = event.target instanceof Element ? event.target : null
        if (!target?.closest('.record-signature-box') || target.closest('button,[data-print-hidden]')) return
        const position = positionAt(event.clientX, event.clientY)
        if (position === null) return
        // Keep the original browser caret hit as the anchor inside the nested signature node view.
        event.preventDefault()
        view.focus()
        mouseAnchor = { position: event.shiftKey ? view.state.selection.anchor : position, doc: view.state.doc }
        selectAt(event.clientX, event.clientY)
      }
      const move = (event: PointerEvent) => {
        if (!mouseAnchor || !(event.buttons & 1)) return
        ownerWindow.cancelAnimationFrame(selectionFrame)
        const { clientX, clientY } = event
        selectionFrame = ownerWindow.requestAnimationFrame(() => selectAt(clientX, clientY))
      }
      const start = (event: PointerEvent) => {
        if (event.button !== 0 || !event.isPrimary || view.dom.contentEditable !== 'true') return
        if (event.target instanceof Element && event.target.closest('button,[data-print-hidden]')) return
        ownerWindow.cancelAnimationFrame(frame)
        pointerType = event.pointerType
        selecting.add(view)
      }
      const finish = (event?: Event) => {
        if (!selecting.has(view)) return
        ownerWindow.cancelAnimationFrame(selectionFrame)
        if (event?.type === 'pointerup' && mouseAnchor) selectAt((event as PointerEvent).clientX, (event as PointerEvent).clientY)
        else if (event?.type === 'pointerup' && pointerType === 'mouse' && event.target instanceof Element && event.target.closest('.record-body')) {
          const selection = view.dom.ownerDocument.getSelection()
          if (selection?.anchorNode && selection.focusNode && view.dom.contains(selection.anchorNode) && view.dom.contains(selection.focusNode)) {
            // Commit the browser's range before focusing a toolbar can hide it from selectionchange.
            const anchor = view.posAtDOM(selection.anchorNode, selection.anchorOffset)
            const head = view.posAtDOM(selection.focusNode, selection.focusOffset)
            const next = TextSelection.between(view.state.doc.resolve(anchor), view.state.doc.resolve(head))
            if (!next.eq(view.state.selection)) view.dispatch(view.state.tr.setSelection(next).setMeta('addToHistory', false))
          }
        }
        mouseAnchor = null
        ownerWindow.cancelAnimationFrame(frame)
        frame = ownerWindow.requestAnimationFrame(() => {
          selecting.delete(view)
          if (!destroyed) view.dispatch(view.state.tr.setMeta('document-native-selection-finished', true).setMeta('addToHistory', false))
        })
      }
      view.dom.addEventListener('pointerdown', start, true)
      view.dom.addEventListener('mousedown', mouseDown, true)
      ownerWindow.addEventListener('pointermove', move)
      ownerWindow.addEventListener('pointerup', finish, true)
      ownerWindow.addEventListener('pointercancel', finish, true)
      ownerWindow.addEventListener('blur', finish)
      return { destroy() {
        destroyed = true
        selecting.delete(view)
        ownerWindow.cancelAnimationFrame(frame)
        ownerWindow.cancelAnimationFrame(selectionFrame)
        view.dom.removeEventListener('pointerdown', start, true)
        view.dom.removeEventListener('mousedown', mouseDown, true)
        ownerWindow.removeEventListener('pointermove', move)
        ownerWindow.removeEventListener('pointerup', finish, true)
        ownerWindow.removeEventListener('pointercancel', finish, true)
        ownerWindow.removeEventListener('blur', finish)
      } }
    } })]
  },
})
