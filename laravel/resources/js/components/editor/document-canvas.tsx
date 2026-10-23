import { useCallback, useEffect, useMemo, useRef, useState, type CSSProperties, type KeyboardEvent as ReactKeyboardEvent } from 'react'
import { EditorContent, useEditor, type Editor } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'
import { TextStyleKit } from '@tiptap/extension-text-style'
import Highlight from '@tiptap/extension-highlight'
import Subscript from '@tiptap/extension-subscript'
import Superscript from '@tiptap/extension-superscript'
import { DocumentTextAlign as TextAlign } from './document-text-align'
import Image from '@tiptap/extension-image'
import { PaginationPlus } from 'tiptap-pagination-plus'
import { TableKitPlus } from 'tiptap-table-plus'
import interact from 'interactjs'
import { Copy, Grip, RotateCw, Trash2 } from 'lucide-react'
import { MM_TO_PX, PAGE_GAP, pageDimensions, type DocumentObject, type WebDocument } from '@/lib/document-model'
import { canvasPageDimensions, canvasPages, canvasPositionToPage, constrainObject, escapeHeaderText, objectAnchorAfterMove, objectCanvasTop, snapMillimeters, type CanvasPage } from '@/lib/document-layout'
import { hasDocumentContent, ObjectTextFlow, objectFlowKey, ParagraphFormatting, RecordTemplate, type FlowReservation } from './document-extensions'

export type { CanvasPage } from '@/lib/document-layout'

type DocumentCanvasProps = {
  document: WebDocument
  onChange: (changes: Partial<WebDocument>, options?: { historyBoundary?: boolean }) => void
  onEditor: (editor: Editor | null) => void
  selectedObjectId: string | null
  onSelectObject: (id: string | null) => void
  zoom: number
  showRuler: boolean
  showGrid: boolean
  showMarks: boolean
  onPages: (pages: CanvasPage[]) => void
  readOnly?: boolean
}

const canvasStyles = `
.document-canvas{position:relative;isolation:isolate;color:#172033;font-family:Sarabun,"Noto Sans Thai",sans-serif;font-size:16pt;line-height:1.5;text-wrap:wrap}
.document-page{position:absolute;left:0;pointer-events:none;box-shadow:0 3px 16px #0f172a18;box-sizing:border-box;z-index:-1}
.document-editor-surface{position:relative;z-index:1}
.document-body-editor{outline:none;box-sizing:border-box!important;border:0!important;background:transparent;white-space:pre-wrap;overflow-wrap:anywhere;word-break:normal;caret-color:#2563eb}
.document-body-editor p,.document-textbox-editor p{margin:0 0 8pt;min-height:1.5em;word-break:normal;line-break:auto}
.document-body-editor h1,.document-textbox-editor h1{font-size:30pt;font-weight:700;line-height:1.3;margin:14pt 0 10pt}
.document-body-editor h2,.document-textbox-editor h2{font-size:23pt;font-weight:700;line-height:1.35;margin:12pt 0 8pt}
.document-body-editor h3,.document-textbox-editor h3{font-size:19pt;font-weight:700;line-height:1.4;margin:10pt 0 6pt}
.document-body-editor ul,.document-textbox-editor ul{list-style:disc;padding-left:1.5em}.document-body-editor ol,.document-textbox-editor ol{list-style:decimal;padding-left:1.5em}
.document-body-editor blockquote{border-left:3px solid #cbd5e1;margin:10pt 0;padding-left:12pt;color:#64748b}
.document-body-editor a,.document-textbox-editor a{color:#2563eb;text-decoration:underline;cursor:pointer}
.document-body-editor img{max-width:100%;height:auto;display:inline-block;vertical-align:middle}
.document-body-editor img.ProseMirror-selectednode{outline:2px solid #2563eb}
.document-body-editor .table-plus{width:100%;border-collapse:collapse;table-layout:fixed;margin:8pt 0}
.document-body-editor .table-plus td,.document-body-editor .table-plus th,.document-body-editor td,.document-body-editor th{border:1px solid #94a3b8;padding:5pt 7pt;vertical-align:top;position:relative;word-break:normal!important;min-width:40px}
.document-body-editor th{background:#f1f5f9;font-weight:700}.document-body-editor .selectedCell:after{background:#2563eb18;content:"";inset:0;pointer-events:none;position:absolute;z-index:2}
.document-body-editor .column-resize-handle{position:absolute;right:-2px;top:0;bottom:-2px;width:4px;background:#2563eb;pointer-events:none}
.document-body-editor .rm-page-header,.document-body-editor .rm-page-footer{display:flex!important;font-size:10pt;line-height:17px;color:#64748b;box-sizing:content-box}
.document-body-editor .rm-page-break:last-child .rm-page-header{display:none!important}
.document-body-editor .rm-pagination-gap{box-sizing:border-box;border:0!important}
.document-body-editor .rm-pages-wrapper{pointer-events:none;user-select:none}.document-body-editor .rm-first-page-header{pointer-events:none;user-select:none}
.document-body-editor .document-manual-page-break{min-height:2px;position:relative;pointer-events:none}
.document-canvas.show-marks .document-body-editor p:after{content:"¶";color:#94a3b8;font-size:.8em;pointer-events:none}
.document-canvas.show-marks .document-manual-page-break:after{content:"ตัวแบ่งหน้า";position:absolute;top:0;left:0;width:100%;border-top:1px dashed #94a3b8;font-family:inherit;font-size:10px;color:#94a3b8}
.document-object-layer{position:absolute;inset:0;pointer-events:none;z-index:3}.document-object{position:absolute;box-sizing:border-box;pointer-events:auto;outline:none;touch-action:none;transform-origin:center}
.document-object.is-selected:after{content:"";position:absolute;inset:-1px;border:1.5px solid #2563eb;pointer-events:none}
.document-object-body{width:100%;height:100%;box-sizing:border-box;overflow:hidden}.document-object img{width:100%;height:100%;object-fit:cover;pointer-events:none;user-select:none}
.document-object[data-read-only=false] .document-object-body{cursor:grab;user-select:none}.document-object.is-editing .document-object-body{cursor:text;user-select:text}.document-object.is-interacting{z-index:100000!important}.document-object.is-interacting .document-object-body,.document-object.is-interacting .document-object-move{cursor:grabbing}
.document-object-toolbar{position:absolute;top:-64px;left:0;display:flex;align-items:center;gap:2px;white-space:nowrap;height:29px;padding:2px 3px;border:1px solid #dbe5f1;background:white;box-shadow:0 2px 6px #0f172a12;border-radius:6px;color:#475569;font-family:inherit;font-size:10px;z-index:5}
.document-object-toolbar button{border:0;background:transparent;padding:3px;cursor:pointer;display:flex;align-items:center;border-radius:3px}.document-object-toolbar button:hover{background:#eff6ff}.document-object-move{cursor:move;display:flex;align-items:center;gap:3px;padding:2px 5px}
.document-resize-handle{position:absolute;width:8px;height:8px;border:1.5px solid #2563eb;background:white;border-radius:1px;z-index:8;pointer-events:auto;touch-action:none}
.document-rotation-handle{position:absolute;left:calc(50% - 8px);top:-28px;width:16px;height:16px;border:1.5px solid #2563eb;background:white;border-radius:50%;color:#2563eb;display:flex;align-items:center;justify-content:center;z-index:9;cursor:grab;touch-action:none}
.document-rotation-handle:after{content:"";position:absolute;left:6px;top:14px;height:11px;border-left:1px solid #2563eb;pointer-events:none}
.document-textbox-editor{outline:none;width:100%;min-height:100%;overflow-wrap:anywhere;white-space:pre-wrap;cursor:text;touch-action:auto}.document-textbox-editor p:last-child{margin-bottom:0}.document-textbox-editor[contenteditable=false]{cursor:move}
.document-page-grid{position:absolute;inset:0;pointer-events:none;background-image:linear-gradient(to right,#2563eb0d 1px,transparent 1px),linear-gradient(to bottom,#2563eb0d 1px,transparent 1px);background-size:${5 * MM_TO_PX}px ${5 * MM_TO_PX}px}
.document-alignment-guide{position:absolute;top:0;bottom:0;border-left:1px dashed #f43f5e;z-index:10;pointer-events:none}
.document-ruler{position:relative;height:24px;background:#fbfdff;color:#7c8da6;font-size:9px;font-family:Arial,sans-serif;border-bottom:1px solid #dbe3ed;user-select:none}.document-ruler span{position:absolute;bottom:0;border-left:1px solid #cbd5e1;height:7px;padding-left:3px;line-height:8px}.document-ruler span:nth-child(even){height:12px}
.document-vertical-ruler{position:absolute;left:-28px;top:0;width:23px;height:100%;background:#f8fafc;border-right:1px solid #dbe3ed;color:#94a3b8;font-size:9px;font-family:Arial,sans-serif;user-select:none;pointer-events:none}.document-vertical-ruler span{position:absolute;left:0;width:100%;border-top:1px solid #cbd5e1;padding-top:2px;padding-left:4px}
.document-canvas.is-record-template{color:#000;font-synthesis:style;letter-spacing:0;text-rendering:optimizeLegibility;-webkit-font-smoothing:antialiased}
.document-canvas.is-record-template .document-body-editor{white-space:break-spaces;overflow-wrap:break-word;word-break:break-word}
.document-canvas.is-record-template .document-body-editor>p:last-child:has(>br.ProseMirror-trailingBreak:only-child){display:block!important;min-height:0!important;height:0;margin:0!important;padding:0!important;line-height:0!important}
.document-body-editor p[data-record-role="recordTitle"]{font-size:var(--record-title-size,20pt);line-height:1.1;font-weight:700;text-align:center;margin:0 0 8px;min-height:0}
.document-body-editor .record-section-title{font-size:var(--record-section-size,17pt);font-weight:700;line-height:1.2;margin:8px 0 2px;break-after:avoid;page-break-after:avoid}
.document-body-editor table.record-info-row{display:table!important;width:100%;table-layout:fixed;border-collapse:collapse;border:0;margin:0 0 6px;font-size:inherit;line-height:1.2}
.document-body-editor table.record-info-row>tbody{display:table-row-group!important;max-height:none!important;overflow:visible!important}
.document-body-editor table.record-info-row>tbody>tr{display:table-row!important}
.document-body-editor table.record-info-row td.record-info-cell{display:table-cell!important;border:0;padding:0 4px 2px;font-size:inherit;line-height:1.2;vertical-align:bottom;min-width:0;overflow:visible;white-space:break-spaces;word-break:normal!important;text-align:center}
.document-body-editor table.record-info-row td.record-info-cell--label{padding-right:6px;white-space:nowrap;text-align:left}
.document-body-editor table.record-info-row td.record-info-cell:has(>br.ProseMirror-trailingBreak:only-child){width:auto}
.document-body-editor table.record-info-row td.record-info-cell>br.ProseMirror-trailingBreak:only-child{display:none}
.document-body-editor .record-info-cell--dotted{background-image:radial-gradient(circle at .75px 1.25px,#000 .55px,transparent .65px);background-size:4px 2px;background-position:left bottom;background-repeat:repeat-x;min-height:26px;padding:0 5px 2px;text-align:center}
.document-body-editor table.record-info-row--compact{width:auto!important;max-width:100%;table-layout:auto;margin:0 auto 1px;font-size:inherit;line-height:1.1}
.document-body-editor table.record-info-row--compact td.record-info-cell{font-size:inherit;line-height:1.1;padding:0;white-space:nowrap;min-height:0}
.document-body-editor table.record-info-row--compact td.record-info-cell--dotted{height:18px;padding:0}
.document-body-editor .record-body{margin:0;padding:0;font-size:inherit;line-height:var(--record-body-line,26px);background-image:radial-gradient(circle at .75px calc(var(--record-body-line,26px) - .85px),#000 .55px,transparent .65px);background-size:4px var(--record-body-line,26px);background-position:left top;background-repeat:repeat;white-space:break-spaces;tab-size:4}
.document-body-editor .record-body p{display:block!important;margin:0;padding:0;min-height:var(--record-body-line,26px);font-size:inherit;line-height:var(--record-body-line,26px);text-align:var(--record-body-align,justify);text-align-last:left;text-justify:inter-character;white-space:break-spaces;tab-size:4;overflow-wrap:break-word;word-break:break-word;width:100%;box-sizing:border-box}
.document-body-editor .record-body>p:first-child{ text-indent:var(--record-first-indent,42px)}
.document-body-editor .record-body ul,.document-body-editor .record-body ol{margin:0;padding-left:32px;text-indent:0}
.document-body-editor .record-body li,.document-body-editor .record-body li p{text-indent:0}
.document-body-editor .record-signature{display:flex;width:100%;padding:0;break-inside:avoid;page-break-inside:avoid}
.document-body-editor .record-signature-content{position:relative;max-width:100%;padding:4px 8px 6px;box-sizing:border-box;text-align:center;line-height:1.2}
.document-body-editor .record-signature-box{display:inline-block;max-width:100%;font-size:inherit;line-height:1.1;text-align:center}
.document-body-editor .record-signature p{margin:0 0 1px;padding:0;min-height:0;font-size:inherit;line-height:1.1;text-align:center;white-space:break-spaces}
.document-body-editor .record-signature p[data-record-role="signatureName"],.document-body-editor .record-signature p[data-record-role="signatureLine"]{white-space:nowrap}
.document-body-editor .record-signature-move-handle{position:absolute;left:0;top:4px;min-width:68px;min-height:34px;display:flex;align-items:center;justify-content:center;border:1px solid #93c5fd;border-radius:6px;padding:5px 9px;background:#eff6ff;color:#1d4ed8;font:12px/20px Anuphan,sans-serif;cursor:grab;touch-action:none;white-space:nowrap;z-index:3;transform:translateX(calc(-100% - 8px)) scale(var(--document-ui-scale,1));transform-origin:right top;user-select:none}
.document-body-editor .record-signature-move-handle:hover,.document-body-editor .record-signature-move-handle:focus-visible{background:#dbeafe;border-color:#2563eb;outline:2px solid #bfdbfe;outline-offset:2px}.document-body-editor .record-signature.is-dragging .record-signature-move-handle{cursor:grabbing;background:#2563eb;color:white}
.document-body-editor[contenteditable=false] .record-signature-move-handle{display:none}
.document-body-editor .record-signature.ProseMirror-selectednode .record-signature-content,.document-body-editor .record-signature.is-dragging .record-signature-content{outline:1px dashed #2563eb}
.document-body-editor .record-group{width:100%;padding:0;box-sizing:border-box}
.document-body-editor .record-group--border:before{content:"";display:block;border-top:1px solid #000;margin:4px 0}
.document-body-editor p[data-record-line="1"]{display:block!important;background-image:radial-gradient(circle at .75px 1.25px,#000 .55px,transparent .65px);background-size:4px 2px;background-position:left bottom;background-repeat:repeat-x;min-height:22px;line-height:22px}
@media print{.document-canvas [data-print-hidden],.document-object-toolbar,.document-resize-handle,.document-rotation-handle{display:none!important}.document-object.is-selected:after,.document-body-editor img.ProseMirror-selectednode,.document-body-editor .record-signature.ProseMirror-selectednode .record-signature-content{border:0!important;outline:0!important}.document-page{box-shadow:none!important}.document-body-editor{caret-color:transparent}.document-canvas.show-marks .document-body-editor p:after,.document-canvas.show-marks .document-manual-page-break:after{display:none!important}}
`

function commonExtensions() {
  return [StarterKit.configure({ heading: { levels: [1, 2, 3] }, link: { openOnClick: false } }), TextStyleKit, Highlight.configure({ multicolor: true }), Subscript, Superscript, TextAlign.configure({ types: ['heading', 'paragraph', 'recordSectionTitle', 'recordInfoCell'] }), ParagraphFormatting, RecordTemplate]
}

type ParagraphLocation = { id: string; position: number; nodeSize: number; top: number; bottom: number }
type ResolvedObject = DocumentObject & { canvasTop: number }

export function DocumentCanvas(props: DocumentCanvasProps) {
  const { document: model, selectedObjectId, zoom, showRuler, showGrid, showMarks, readOnly = false } = props
  const callbacks = useRef(props)
  callbacks.current = props
  const canvasRef = useRef<HTMLDivElement>(null)
  const paragraphLocations = useRef<ParagraphLocation[]>([])
  const [pageCount, setPageCount] = useState(1)
  const [paragraphVersion, setParagraphVersion] = useState(0)
  const [centerGuide, setCenterGuide] = useState(false)
  const layoutRequest = useRef<number>(0)
  const layoutTick = useRef<() => void>(() => {})
  const lastContent = useRef(JSON.stringify(model.content))
  const lastFlow = useRef('')
  const lastGeometry = useRef('')
  const settings = model.pageSettings
  const templateSettings = settings as typeof settings & { template?: string; fontFamily?: string; fontSize?: number; lineHeight?: number }
  const isRecordTemplate = templateSettings.template === 'weekly-record'
  const dimensions = pageDimensions(settings)
  const { width: physicalWidth, height: physicalHeight } = canvasPageDimensions(settings)
  const marginPx = useMemo(() => Object.fromEntries(Object.entries(settings.margins).map(([side, value]) => [side, isRecordTemplate ? Math.round(value * MM_TO_PX) : value * MM_TO_PX])) as Record<'top' | 'right' | 'bottom' | 'left', number>, [settings.margins, isRecordTemplate])
  const scheduleLayout = useCallback(() => {
    cancelAnimationFrame(layoutRequest.current)
    layoutRequest.current = requestAnimationFrame(() => layoutTick.current())
  }, [])
  const extensions = useMemo(() => [...commonExtensions(), Image.configure({ inline: true, allowBase64: false }), TableKitPlus.configure({ table: { resizable: true, HTMLAttributes: { class: 'table-plus' } } }), ObjectTextFlow, PaginationPlus.configure({
    pageWidth: physicalWidth, pageHeight: physicalHeight, pageGap: PAGE_GAP, pageGapBorderSize: 0,
    pageBreakBackground: '#eaf0f6', marginTop: marginPx.top, marginBottom: marginPx.bottom,
    marginLeft: marginPx.left, marginRight: marginPx.right,
    contentMarginTop: 0, contentMarginBottom: 0, headerLeft: escapeHeaderText(settings.header), headerRight: '',
    footerLeft: escapeHeaderText(settings.footer), footerRight: settings.pageNumbers ? '{page}' : '',
  })], [model.id]) // Extensions are configured dynamically below without resetting the document or IME selection.

  const editor = useEditor({
    extensions,
    content: hasDocumentContent(model.content),
    editable: !readOnly,
    immediatelyRender: false,
    shouldRerenderOnTransaction: false,
    editorProps: { attributes: { class: 'document-body-editor', lang: 'th', 'aria-label': 'เนื้อหาเอกสาร', spellcheck: 'true' } },
    onUpdate: ({ editor: current, transaction }) => {
      const content = current.getJSON()
      lastContent.current = JSON.stringify(content)
      callbacks.current.onChange({ content }, transaction.getMeta('documentGestureBoundary') ? { historyBoundary: true } : undefined)
      scheduleLayout()
    },
    onSelectionUpdate: () => scheduleLayout(),
    onFocus: ({ editor: current }) => {
      callbacks.current.onEditor(current)
      callbacks.current.onSelectObject(null)
    },
    onCreate: () => scheduleLayout(),
    onTransaction: () => scheduleLayout(),
  }, [model.id])

  useEffect(() => {
    if (!editor) return
    callbacks.current.onEditor(editor)
    return () => { callbacks.current.onEditor(null) }
  }, [editor])

  useEffect(() => { editor?.setEditable(!readOnly) }, [editor, readOnly])
  useEffect(() => {
    if (!editor) return
    const incoming = JSON.stringify(model.content)
    if (incoming === lastContent.current) return
    const replace = () => {
      lastContent.current = incoming
      editor.commands.setContent(hasDocumentContent(model.content), { emitUpdate: false })
      scheduleLayout()
    }
    if (editor.view.composing) {
      editor.view.dom.addEventListener('compositionend', replace, { once: true })
      return () => editor.view.dom.removeEventListener('compositionend', replace)
    }
    replace()
  }, [editor, model.content, scheduleLayout])

  useEffect(() => {
    if (!editor) return
    editor.chain().updatePageWidth(physicalWidth).updatePageHeight(physicalHeight)
      .updateMargins(marginPx)
      .updateHeaderContent(escapeHeaderText(settings.header), '')
      .updateFooterContent(escapeHeaderText(settings.footer), settings.pageNumbers ? '{page}' : '').run()
    scheduleLayout()
  }, [editor, settings, physicalWidth, physicalHeight, marginPx, scheduleLayout])

  const resolvedObjects = useMemo(() => model.objects.map(object => {
    let canvasTop = objectCanvasTop(object, settings)
    if (object.anchor.kind === 'paragraph') {
      const anchor = object.anchor
      const paragraph = paragraphLocations.current.find(location => location.id === anchor.id)
      if (paragraph) canvasTop = paragraph.top + (object.anchor.offsetY ?? 0) * MM_TO_PX
    }
    const position = canvasPositionToPage(canvasTop, settings)
    return { ...object, ...position, canvasTop }
  }), [model.objects, settings, paragraphVersion])
  const actualPageCount = Math.max(pageCount, ...resolvedObjects.map(object => object.page), 1)
  const pages = useMemo(() => canvasPages(settings, actualPageCount), [settings, actualPageCount])
  const canvasHeight = actualPageCount * physicalHeight + (actualPageCount - 1) * PAGE_GAP

  layoutTick.current = () => {
    if (!editor || editor.isDestroyed || !canvasRef.current) return
    const root = editor.view.dom
    const rootRect = root.getBoundingClientRect()
    const factor = rootRect.width / physicalWidth || 1
    const locations: ParagraphLocation[] = []
    editor.state.doc.descendants((node, position) => {
      if (!['paragraph', 'heading'].includes(node.type.name)) return
      const element = editor.view.nodeDOM(position)
      if (!(element instanceof HTMLElement)) return
      const rectangle = element.getBoundingClientRect()
      const top = (rectangle.top - rootRect.top) / factor
      locations.push({ id: node.attrs.id || '', position, nodeSize: node.nodeSize, top, bottom: (rectangle.bottom - rootRect.top) / factor })
    })
    const previous = paragraphLocations.current
    if (JSON.stringify(previous) !== JSON.stringify(locations)) {
      paragraphLocations.current = locations
      setParagraphVersion(version => version + 1)
    }
    const breaks = root.querySelectorAll('[data-rm-pagination] > .rm-page-break')
    setPageCount(Math.max(1, breaks.length))
    let changedBreak = false
    root.querySelectorAll<HTMLElement>('[data-page-break]').forEach(element => {
      const top = (element.getBoundingClientRect().top - rootRect.top) / factor
      const page = Math.floor((top + 1) / (physicalHeight + PAGE_GAP))
      const header = root.querySelector<HTMLElement>('.rm-first-page-header')
      const headerHeight = header?.offsetHeight || marginPx.top
      const height = Math.max(2, (page + 1) * (physicalHeight + PAGE_GAP) + headerHeight - top)
      if (Math.abs((parseFloat(element.style.height) || 0) - height) > 1) {
        element.style.height = `${height}px`
        changedBreak = true
      }
    })
    if (changedBreak) editor.view.dispatch(editor.state.tr.setMeta('document-layout', true).setMeta('addToHistory', false))
    const contentWidth = physicalWidth - marginPx.left - marginPx.right
    const flow: FlowReservation[] = []
    for (const object of resolvedObjects) {
      if (object.wrap === 'inline') continue
      const anchor = object.anchor
      const candidate = anchor.kind === 'paragraph'
        ? locations.find(location => location.id === anchor.id)
        : [...locations].reverse().find(location => location.top <= object.canvasTop + 1)
      const paragraph = candidate || locations[0]
      if (!paragraph) continue
      const relativeX = object.x * MM_TO_PX - marginPx.left
      const side = relativeX + object.width * MM_TO_PX / 2 <= contentWidth / 2 ? 'left' : 'right'
      const width = side === 'left' ? relativeX + object.width * MM_TO_PX + 8 : contentWidth - relativeX + 8
      flow.push({ id: object.id, position: paragraph.position, nodeSize: paragraph.nodeSize, side, width: Math.min(contentWidth, Math.max(1, width)), height: object.height * MM_TO_PX + 6, offset: Math.max(0, object.canvasTop - paragraph.top), wrap: width > contentWidth * 0.75 ? 'top-bottom' : object.wrap })
    }
    for (const reservation of flow) {
      const siblings = flow.filter(item => item.position === reservation.position && item.wrap === 'square')
      const left = Math.max(0, ...siblings.filter(item => item.side === 'left').map(item => item.width))
      const right = Math.max(0, ...siblings.filter(item => item.side === 'right').map(item => item.width))
      if (left + right > contentWidth * 0.8) for (const sibling of siblings) sibling.wrap = 'top-bottom'
    }
    const flowFingerprint = JSON.stringify(flow.map(item => ({ ...item, offset: Math.round(item.offset) })))
    if (!editor.view.composing && lastFlow.current !== flowFingerprint) {
      lastFlow.current = flowFingerprint
      editor.view.dispatch(editor.state.tr.setMeta(objectFlowKey, flow).setMeta('addToHistory', false))
    }
    const lastChild = root.lastElementChild
    const geometry = `${root.offsetWidth}:${root.scrollHeight}:${lastChild instanceof HTMLElement ? lastChild.offsetTop + lastChild.offsetHeight : 0}:${breaks.length}:${physicalHeight}:${physicalWidth}`
    if (!editor.view.composing && geometry !== lastGeometry.current) {
      lastGeometry.current = geometry
      editor.view.dispatch(editor.state.tr.setMeta('document-measured-layout', true).setMeta('addToHistory', false))
    }
  }

  useEffect(() => {
    if (!editor) return
    const observer = new ResizeObserver(scheduleLayout)
    observer.observe(editor.view.dom)
    editor.view.dom.addEventListener('load', scheduleLayout, true)
    editor.view.dom.addEventListener('compositionend', scheduleLayout)
    document.fonts.ready.then(scheduleLayout)
    scheduleLayout()
    return () => {
      observer.disconnect()
      editor.view.dom.removeEventListener('load', scheduleLayout, true)
      editor.view.dom.removeEventListener('compositionend', scheduleLayout)
      cancelAnimationFrame(layoutRequest.current)
    }
  }, [editor, scheduleLayout])
  useEffect(scheduleLayout, [model.objects, settings, zoom, paragraphVersion, scheduleLayout])
  useEffect(() => { callbacks.current.onPages(pages) }, [pages])

  const changeObject = useCallback((id: string, changes: Partial<DocumentObject>, options?: { historyBoundary?: boolean }) => {
    const current = callbacks.current.document
    callbacks.current.onChange({ objects: current.objects.map(object => object.id === id ? { ...object, ...changes } : object) }, options)
  }, [])
  const deleteObject = useCallback((id: string) => {
    callbacks.current.onChange({ objects: callbacks.current.document.objects.filter(object => object.id !== id) })
    callbacks.current.onSelectObject(null)
    callbacks.current.onEditor(editor)
  }, [editor])
  const duplicateObject = useCallback((id: string) => {
    const current = callbacks.current.document
    const object = current.objects.find(candidate => candidate.id === id)
    if (!object) return
    const duplicate = { ...structuredClone(object), id: crypto.randomUUID(), x: object.x + 5, y: object.y + 5, anchor: object.anchor.kind === 'paragraph' ? { ...object.anchor, offsetY: (object.anchor.offsetY || 0) + 5 } : object.anchor, zIndex: Math.max(1, ...current.objects.map(candidate => candidate.zIndex)) + 1 }
    callbacks.current.onChange({ objects: [...current.objects, duplicate] })
    callbacks.current.onSelectObject(duplicate.id)
  }, [])
  useEffect(() => {
    if (readOnly) return
    const isTextInput = (target: EventTarget | null) => target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || (target instanceof Element && Boolean(target.closest('[contenteditable=true]')))
    const copy = (event: ClipboardEvent) => {
      if (isTextInput(event.target) || !event.clipboardData) return
      const object = callbacks.current.document.objects.find(candidate => candidate.id === callbacks.current.selectedObjectId)
      if (!object) return
      event.preventDefault()
      event.clipboardData.setData('application/x-sena-document-object', JSON.stringify(object))
      event.clipboardData.setData('text/plain', object.type === 'textbox' ? textboxPlainText(object.content) : 'วัตถุในเอกสาร')
      if (event.type === 'cut') deleteObject(object.id)
    }
    const paste = (event: ClipboardEvent) => {
      if (isTextInput(event.target)) return
      const json = event.clipboardData?.getData('application/x-sena-document-object')
      if (!json || json.length > 2_000_000) return
      try {
        const object = JSON.parse(json) as DocumentObject
        if (!['textbox', 'image', 'shape', 'line'].includes(object.type) || ![object.x, object.y, object.width, object.height, object.page].every(Number.isFinite)) return
        if (object.src && !/^(https?:\/\/|\/)/i.test(object.src)) return
        event.preventDefault()
        const current = callbacks.current.document
        const geometry = constrainObject({ ...object, x: object.x + 5, y: object.y + 5 }, current.pageSettings)
        const duplicate: DocumentObject = { ...object, ...geometry, id: crypto.randomUUID(), anchor: { kind: 'page', page: geometry.page }, zIndex: Math.max(1, ...current.objects.map(candidate => candidate.zIndex)) + 1 }
        callbacks.current.onChange({ objects: [...current.objects, duplicate] })
        callbacks.current.onSelectObject(duplicate.id)
      } catch { /* The ordinary browser paste remains available for unrelated clipboard data. */ }
    }
    document.addEventListener('copy', copy)
    document.addEventListener('cut', copy)
    document.addEventListener('paste', paste)
    return () => { document.removeEventListener('copy', copy); document.removeEventListener('cut', copy); document.removeEventListener('paste', paste) }
  }, [readOnly, deleteObject])
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (readOnly || !callbacks.current.selectedObjectId || event.target instanceof HTMLInputElement || event.target instanceof HTMLTextAreaElement || event.target instanceof HTMLSelectElement || (event.target instanceof Element && event.target.closest('[contenteditable=true]'))) return
      const object = callbacks.current.document.objects.find(candidate => candidate.id === callbacks.current.selectedObjectId)
      if (!object) return
      if (event.key === 'Delete' || event.key === 'Backspace') { event.preventDefault(); deleteObject(object.id) }
      if (event.key === 'Escape') { callbacks.current.onSelectObject(null); callbacks.current.onEditor(editor) }
      const moves: Record<string, [number, number]> = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }
      if (moves[event.key]) {
        event.preventDefault()
        const [x, y] = moves[event.key]
        const step = (event.shiftKey ? 10 : event.altKey ? 0.25 : 1) / MM_TO_PX
        const settings = callbacks.current.document.pageSettings
        const anchor = object.anchor
        const paragraph = anchor.kind === 'paragraph' ? paragraphLocations.current.find(location => location.id === anchor.id) : null
        const top = paragraph && anchor.kind === 'paragraph' ? paragraph.top + (anchor.offsetY || 0) * MM_TO_PX : objectCanvasTop(object, settings)
        const position = canvasPositionToPage(top + y * step * MM_TO_PX, settings)
        const geometry = constrainObject({ ...object, x: object.x + x * step, ...position }, settings)
        changeObject(object.id, { ...geometry, anchor: objectAnchorAfterMove(object, top, geometry, settings) }, { historyBoundary: true })
      }
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'd') { event.preventDefault(); duplicateObject(object.id) }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [readOnly, changeObject, deleteObject, duplicateObject, editor])

  return <div className="document-canvas-zoom-space" style={{ width: physicalWidth * zoom, minWidth: physicalWidth * zoom, height: (canvasHeight + (showRuler ? 24 : 0)) * zoom, position: 'relative', margin: '0 auto' }}>
    <div className="document-canvas-scale" style={{ width: physicalWidth, transform: `scale(${zoom})`, transformOrigin: 'top left' }}>
      {showRuler && <div className="document-ruler" data-print-hidden>{Array.from({ length: Math.floor(dimensions.width / 5) + 1 }, (_, index) => <span key={index} style={{ left: index * 5 * MM_TO_PX }}>{index % 2 === 0 ? index / 2 : ''}</span>)}</div>}
      <div ref={canvasRef} data-document-canvas data-page-width={physicalWidth} data-page-height={physicalHeight} data-page-gap={PAGE_GAP} data-document-template={templateSettings.template || undefined} className={`document-canvas${isRecordTemplate ? ' is-record-template' : ''}${showMarks ? ' show-marks' : ''}`} style={{ width: physicalWidth, minHeight: canvasHeight, height: canvasHeight, fontFamily: templateSettings.fontFamily || undefined, fontSize: templateSettings.fontSize ? `${templateSettings.fontSize}pt` : undefined, lineHeight: templateSettings.lineHeight || undefined, '--document-ui-scale': 1 / zoom, '--record-title-size': `${20 + ((templateSettings.fontSize || 16) - 16)}pt`, '--record-section-size': `${17 + ((templateSettings.fontSize || 16) - 16)}pt` } as CSSProperties} onPointerDown={event => {
        if (!(event.target instanceof Element) || event.target.closest('.document-object')) return
        callbacks.current.onSelectObject(null)
        if (editor) callbacks.current.onEditor(editor)
      }}>
        <style>{canvasStyles}</style>
        {pages.map(page => <div key={page.index} className="document-page" data-page-number={page.index + 1} style={{ top: page.top, width: page.width, height: page.height, background: settings.background, border: settings.border === 'none' ? undefined : `1px solid ${settings.border}` }}>
          {showGrid && <div className="document-page-grid" data-print-hidden />}
          {showRuler && <div className="document-vertical-ruler" data-print-hidden>{Array.from({ length: Math.floor(dimensions.height / 10) }, (_, index) => <span key={index} style={{ top: index * 10 * MM_TO_PX }}>{index}</span>)}</div>}
        </div>)}
        <div className="document-editor-surface"><EditorContent editor={editor} /></div>
        <div className="document-object-layer">
          {resolvedObjects.map(object => <FloatingObject key={object.id} object={object} selected={selectedObjectId === object.id} readOnly={readOnly} zoom={zoom} grid={showGrid} pageSettings={settings} onChange={(changes, options) => changeObject(object.id, changes, options)} onSelect={() => callbacks.current.onSelectObject(object.id)} onEditor={active => callbacks.current.onEditor(active || editor)} onDelete={() => deleteObject(object.id)} onDuplicate={() => duplicateObject(object.id)} onGuide={setCenterGuide} />)}
        </div>
        {centerGuide && <div className="document-alignment-guide" data-print-hidden style={{ left: physicalWidth / 2 }} />}
      </div>
    </div>
  </div>
}

function textboxPlainText(content: DocumentObject['content']): string {
  if (!content) return ''
  if (content.type === 'text') return content.text || ''
  return (content.content || []).map(node => textboxPlainText(node)).join(content.type === 'doc' ? '\n' : '')
}

type FloatingObjectProps = {
  object: ResolvedObject; selected: boolean; readOnly: boolean; zoom: number; grid: boolean; pageSettings: WebDocument['pageSettings']
  onChange: (changes: Partial<DocumentObject>, options?: { historyBoundary?: boolean }) => void; onSelect: () => void; onEditor: (editor: Editor | null) => void; onDelete: () => void; onDuplicate: () => void; onGuide: (visible: boolean) => void
}

function FloatingObject(props: FloatingObjectProps) {
  const { object, selected, readOnly, pageSettings } = props
  const latest = useRef(props)
  latest.current = props
  const elementRef = useRef<HTMLDivElement>(null)
  const rotationRef = useRef<HTMLButtonElement>(null)
  const [editing, setEditing] = useState(false)
  const [interacting, setInteracting] = useState(false)
  const lastContent = useRef(JSON.stringify(object.content))
  const textbox = useEditor({
    extensions: commonExtensions(), content: hasDocumentContent(object.content), editable: false,
    immediatelyRender: false, shouldRerenderOnTransaction: false,
    editorProps: { attributes: { class: 'document-textbox-editor', lang: 'th', 'aria-label': 'ข้อความในกล่องข้อความ' } },
    onUpdate: ({ editor }) => { const content = editor.getJSON(); lastContent.current = JSON.stringify(content); latest.current.onChange({ content }) },
    onFocus: ({ editor }) => latest.current.onEditor(editor),
  }, [object.id])
  useEffect(() => {
    if (!textbox) return
    textbox.setEditable(editing && !readOnly)
    if (editing && !readOnly) { textbox.commands.focus(); latest.current.onEditor(textbox) }
  }, [textbox, editing, readOnly])
  useEffect(() => { if (!selected) setEditing(false) }, [selected])
  useEffect(() => {
    const next = JSON.stringify(object.content)
    if (textbox && next !== lastContent.current && !textbox.view.composing) {
      lastContent.current = next
      textbox.commands.setContent(hasDocumentContent(object.content), { emitUpdate: false })
    }
  }, [textbox, object.content])

  useEffect(() => {
    if (!elementRef.current || readOnly) return
    const element = elementRef.current
    let gesture = { x: object.x, top: object.canvasTop, width: object.width, height: object.height }
    let active = false, scale = 1, originalTop = object.canvasTop
    let interaction: { stop: () => void } | null = null
    const restorePreview = () => {
      const current = latest.current.object
      Object.assign(element.style, { left: `${current.x * MM_TO_PX}px`, top: `${current.canvasTop}px`, width: `${current.width * MM_TO_PX}px`, height: `${current.height * MM_TO_PX}px` })
    }
    const start = () => {
      const current = latest.current.object
      gesture = { x: current.x, top: current.canvasTop, width: current.width, height: current.height }
      originalTop = current.canvasTop
      scale = latest.current.zoom
      active = true
      setEditing(false)
      setInteracting(true)
      latest.current.onSelect()
    }
    const geometry = () => {
      const current = latest.current
      const size = pageDimensions(current.pageSettings)
      let x = snapMillimeters(gesture.x, current.grid)
      const center = Math.abs(x + gesture.width / 2 - size.width / 2) < 1.5
      if (center) x = (size.width - gesture.width) / 2
      current.onGuide(center)
      const position = canvasPositionToPage(gesture.top, current.pageSettings)
      return constrainObject({ x, y: snapMillimeters(position.y, current.grid), page: position.page, width: gesture.width, height: gesture.height }, current.pageSettings)
    }
    const preview = () => {
      const value = geometry()
      Object.assign(element.style, { left: `${value.x * MM_TO_PX}px`, top: `${objectCanvasTop(value, latest.current.pageSettings)}px`, width: `${value.width * MM_TO_PX}px`, height: `${value.height * MM_TO_PX}px` })
    }
    const finish = (save: boolean) => {
      if (!active) return
      active = false
      interaction = null
      setInteracting(false)
      if (save) {
        const current = latest.current
        const value = geometry()
        if (value.x !== current.object.x || objectCanvasTop(value, current.pageSettings) !== originalTop || value.width !== current.object.width || value.height !== current.object.height) {
          current.onChange({ ...value, anchor: objectAnchorAfterMove(current.object, originalTop, value, current.pageSettings) }, { historyBoundary: true })
        }
      } else restorePreview()
      latest.current.onGuide(false)
    }
    const cancel = () => {
      const running = interaction
      finish(false)
      running?.stop()
    }
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && active) { event.preventDefault(); cancel() }
    }
    const target = interact(element).draggable({
      allowFrom: '.document-object-move, .document-object-body', ignoreFrom: '[contenteditable=true], button, .document-resize-handle, .document-rotation-handle',
      listeners: {
        start(event) { start(); interaction = event.interaction },
        move(event) { if (active) { gesture.x += event.dx / scale / MM_TO_PX; gesture.top += event.dy / scale; preview() } },
        end() { finish(true) },
      },
    }).resizable({
      edges: { left: '.document-resize-w', right: '.document-resize-e', top: '.document-resize-n', bottom: '.document-resize-s' },
      listeners: {
        start(event) { start(); interaction = event.interaction },
        move(event) {
          if (!active) return
          const current = latest.current
          const radians = current.object.rotation * Math.PI / 180
          const cosine = Math.cos(radians), sine = Math.sin(radians)
          const localX = (event.dx * cosine + event.dy * sine) / scale / MM_TO_PX
          const localY = (-event.dx * sine + event.dy * cosine) / scale / MM_TO_PX
          const previousWidth = gesture.width, previousHeight = gesture.height
          let width = previousWidth + (event.edges.right ? localX : 0) - (event.edges.left ? localX : 0)
          let height = previousHeight + (event.edges.bottom ? localY : 0) - (event.edges.top ? localY : 0)
          if (current.object.lockAspectRatio) {
            const ratio = current.object.width / current.object.height
            if (event.edges.left || event.edges.right) height = width / ratio
            else width = height * ratio
          }
          gesture.width = Math.max(2, width)
          gesture.height = Math.max(0.5, height)
          const widthChange = gesture.width - previousWidth, heightChange = gesture.height - previousHeight
          const localCenterX = event.edges.left ? -widthChange / 2 : event.edges.right ? widthChange / 2 : 0
          const localCenterY = event.edges.top ? -heightChange / 2 : event.edges.bottom ? heightChange / 2 : 0
          gesture.x += localCenterX * cosine - localCenterY * sine - widthChange / 2
          gesture.top += (localCenterX * sine + localCenterY * cosine - heightChange / 2) * MM_TO_PX
          preview()
        },
        end() { finish(true) },
      },
    })
    element.addEventListener('pointercancel', cancel)
    window.addEventListener('keydown', onKey)
    return () => { if (active) restorePreview(); active = false; target.unset(); element.removeEventListener('pointercancel', cancel); window.removeEventListener('keydown', onKey) }
  }, [object.id, readOnly])

  useEffect(() => {
    if (!rotationRef.current || readOnly || !selected) return
    let active = false, angle = object.rotation, initialAngle = 0, originalAngle = object.rotation
    let center = { x: 0, y: 0 }
    const finish = (save: boolean) => {
      if (!active) return
      active = false
      setInteracting(false)
      if (save && angle !== originalAngle) latest.current.onChange({ rotation: angle }, { historyBoundary: true })
      else if (elementRef.current) elementRef.current.style.transform = `rotate(${latest.current.object.rotation}deg)`
    }
    const rotate = interact(rotationRef.current).draggable({ listeners: {
      start(event) {
        const rectangle = elementRef.current?.getBoundingClientRect()
        if (!rectangle) { event.interaction.stop(); return }
        center = { x: rectangle.left + rectangle.width / 2, y: rectangle.top + rectangle.height / 2 }
        initialAngle = Math.atan2(event.clientY - center.y, event.clientX - center.x) * 180 / Math.PI
        originalAngle = latest.current.object.rotation
        angle = originalAngle
        active = true
        setInteracting(true)
        latest.current.onSelect()
      },
      move(event) {
        if (!active || !elementRef.current) return
        const pointerAngle = Math.atan2(event.clientY - center.y, event.clientX - center.x) * 180 / Math.PI
        const delta = ((pointerAngle - initialAngle + 540) % 360) - 180
        angle = event.shiftKey ? Math.round((originalAngle + delta) / 15) * 15 : Math.round(originalAngle + delta)
        elementRef.current.style.transform = `rotate(${angle}deg)`
      },
      end() { finish(true) },
    } })
    const cancel = () => finish(false)
    const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape' && active) { event.preventDefault(); finish(false) } }
    rotationRef.current.addEventListener('pointercancel', cancel)
    window.addEventListener('keydown', onKey)
    const handle = rotationRef.current
    return () => { if (active && elementRef.current) elementRef.current.style.transform = `rotate(${latest.current.object.rotation}deg)`; active = false; rotate.unset(); handle.removeEventListener('pointercancel', cancel); window.removeEventListener('keydown', onKey) }
  }, [selected, readOnly])

  const border = `${object.borderWidth ?? 1}px solid ${object.border || 'transparent'}`
  const bodyStyle: CSSProperties = { background: object.type === 'image' || object.type === 'line' ? undefined : object.fill || 'transparent', border: object.type === 'line' ? undefined : border, padding: object.type === 'textbox' ? (object.padding ?? 3) * MM_TO_PX : 0, opacity: object.opacity }
  if (object.type === 'shape' && object.shape === 'ellipse') bodyStyle.borderRadius = '50%'
  if (object.type === 'shape' && object.shape === 'triangle') bodyStyle.clipPath = 'polygon(50% 0,100% 100%,0 100%)'
  if (object.type === 'line') { bodyStyle.background = object.border || '#2563eb'; bodyStyle.height = Math.max(1, object.borderWidth || 1); bodyStyle.position = 'absolute'; bodyStyle.top = '50%' }
  const handles = [
    { key: 'nw', left: '-4px', top: '-4px', cursor: 'nwse-resize', classes: 'document-resize-n document-resize-w' },
    { key: 'n', left: 'calc(50% - 4px)', top: '-4px', cursor: 'ns-resize', classes: 'document-resize-n' },
    { key: 'ne', left: 'calc(100% - 4px)', top: '-4px', cursor: 'nesw-resize', classes: 'document-resize-n document-resize-e' },
    { key: 'e', left: 'calc(100% - 4px)', top: 'calc(50% - 4px)', cursor: 'ew-resize', classes: 'document-resize-e' },
    { key: 'se', left: 'calc(100% - 4px)', top: 'calc(100% - 4px)', cursor: 'nwse-resize', classes: 'document-resize-s document-resize-e' },
    { key: 's', left: 'calc(50% - 4px)', top: 'calc(100% - 4px)', cursor: 'ns-resize', classes: 'document-resize-s' },
    { key: 'sw', left: '-4px', top: 'calc(100% - 4px)', cursor: 'nesw-resize', classes: 'document-resize-s document-resize-w' },
    { key: 'w', left: '-4px', top: 'calc(50% - 4px)', cursor: 'ew-resize', classes: 'document-resize-w' },
  ]
  return <div ref={elementRef} tabIndex={readOnly ? -1 : 0} role="group" aria-label={object.type === 'textbox' ? 'กล่องข้อความ' : object.type === 'image' ? 'รูปภาพ' : object.type === 'line' ? 'เส้น' : 'รูปทรง'} title={readOnly ? undefined : object.type === 'textbox' ? 'ลากเพื่อย้าย · ดับเบิลคลิกเพื่อแก้ข้อความ · ลูกศรขยับ 1 px' : 'ลากจากตัววัตถุเพื่อย้าย · ลูกศรขยับ 1 px · Shift 10 px'} data-object-id={object.id} data-page-number={object.page} data-read-only={readOnly} className={`document-object${selected && !readOnly ? ' is-selected' : ''}${editing ? ' is-editing' : ''}${interacting ? ' is-interacting' : ''}`} style={{ left: object.x * MM_TO_PX, top: object.canvasTop, width: object.width * MM_TO_PX, height: object.height * MM_TO_PX, transform: `rotate(${object.rotation}deg)`, zIndex: Math.max(0, object.zIndex) }} onPointerDown={event => {
    if (readOnly) return
    props.onSelect()
    const typing = editing && event.target instanceof Element && event.target.closest('[contenteditable=true]')
    if (!typing) { event.preventDefault(); elementRef.current?.focus({ preventScroll: true }); props.onEditor(null) }
  }} onDoubleClick={event => { if (object.type === 'textbox' && !readOnly) { event.stopPropagation(); setEditing(true) } }} onKeyDown={(event: ReactKeyboardEvent) => { if (event.key === 'Escape') { setEditing(false); elementRef.current?.focus(); props.onEditor(null) } }}>
    <div className="document-object-body" style={bodyStyle}>
      {object.type === 'image' && <img src={object.src} alt="รูปภาพในเอกสาร" draggable={false} />}
      {object.type === 'textbox' && <EditorContent editor={textbox} />}
    </div>
    {selected && !readOnly && <>
      <div className="document-object-toolbar" data-print-hidden>
        <span className="document-object-move"><Grip size={12} />{Math.round(object.x * 10) / 10}, {Math.round(object.y * 10) / 10} มม.</span>
        <button type="button" title="ทำสำเนาวัตถุ (Ctrl+D)" onClick={props.onDuplicate}><Copy size={13} /></button>
        <button type="button" title="ลบวัตถุ" onClick={props.onDelete}><Trash2 size={13} /></button>
      </div>
      {handles.map(handle => <span key={handle.key} data-print-hidden className={`document-resize-handle ${handle.classes}`} style={{ left: handle.left, top: handle.top, cursor: handle.cursor }} />)}
      <button ref={rotationRef} data-print-hidden className="document-rotation-handle" title="ลากเพื่อหมุน · Shift ล็อกทุก 15°" type="button"><RotateCw size={10} /></button>
    </>}
  </div>
}
