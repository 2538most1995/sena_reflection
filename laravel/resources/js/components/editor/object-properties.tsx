import { ArrowDown, ArrowUp, Copy, LockKeyhole, Trash2, UnlockKeyhole, X } from 'lucide-react'
import type { Editor } from '@tiptap/react'
import type { DocumentObject, WebDocument } from '@/lib/document-model'
import { MM_TO_PX } from '@/lib/document-model'
import { canvasPositionToPage, objectAnchorAfterMove, objectCanvasTop } from '@/lib/document-layout'
import { Button } from '@/components/ui/button'

type Props = { object: DocumentObject | null; document: WebDocument; editor: Editor | null; onChange: (object: DocumentObject) => void; onDuplicate: () => void; onDelete: () => void; onClose: () => void; onReplaceImage: () => void }
function renderedPosition(object: DocumentObject, settings: WebDocument['pageSettings']) {
  const element = typeof window === 'undefined' ? null : window.document.querySelector<HTMLElement>(`[data-object-id="${CSS.escape(object.id)}"]`)
  const top = element ? Number.parseFloat(element.style.top) : objectCanvasTop(object, settings)
  return { x: element ? Number.parseFloat(element.style.left) / MM_TO_PX : object.x, ...canvasPositionToPage(top, settings) }
}
export function ObjectProperties({ object, document, editor, onChange, onDuplicate, onDelete, onClose, onReplaceImage }: Props) {
  if (!object) return <aside className="de-properties" aria-label="คุณสมบัติข้อความ"><div className="de-sidebar-heading"><h2>คุณสมบัติข้อความ</h2><button aria-label="ปิดคุณสมบัติข้อความ" onClick={onClose}><X size={16} /></button></div><p className="de-muted">เลือกข้อความเพื่อจัดรูปแบบจากแถบเครื่องมือ หรือคลิกวัตถุเพื่อปรับตำแหน่ง</p><div className="de-property-section"><h3>เอกสาร</h3><p>{document.pageSettings.size} · {document.pageSettings.orientation === 'portrait' ? 'แนวตั้ง' : 'แนวนอน'}</p><p>หน่วยตำแหน่ง: มิลลิเมตร</p><p>ต้นฉบับรุ่นที่ {document.revision}</p></div><div className="de-property-section"><h3>ข้อความที่เลือก</h3><p>แบบอักษร: {editor?.getAttributes('textStyle').fontFamily || document.pageSettings.fontFamily || 'Sarabun'}</p><p>ขนาด: {editor?.getAttributes('textStyle').fontSize || `${document.pageSettings.fontSize || 16}pt`}</p><p>จัดแนว: {({ left: 'ชิดซ้าย', center: 'กึ่งกลาง', right: 'ชิดขวา', justify: 'ชิดขอบทั้งสอง', 'thai-distributed': 'กระจายแบบไทย' } as Record<string, string>)[editor?.getAttributes('paragraph').textAlign || 'left']}</p></div><div className="de-property-section"><h3>แป้นพิมพ์</h3><p>Ctrl/Cmd+B · ตัวหนา<br />Ctrl/Cmd+I · ตัวเอียง<br />Ctrl/Cmd+U · ขีดเส้นใต้<br />Ctrl/Cmd+S · บันทึก<br />Ctrl/Cmd+Z · ย้อนกลับ</p></div></aside>
  const position = renderedPosition(object, document.pageSettings)
  const patch = (changes: Partial<DocumentObject>) => onChange({ ...object, ...changes })
  const paragraphId = editor?.getAttributes('paragraph').id || editor?.getAttributes('heading').id || (object.anchor.kind === 'paragraph' ? object.anchor.id : null)
  const setAnchor = (kind: string) => {
    if (kind === 'page' || !paragraphId) { patch({ ...position, anchor: { kind: 'page', page: position.page } }); return }
    const paragraph = window.document.querySelector<HTMLElement>(`[data-paragraph-id="${CSS.escape(paragraphId)}"]`)
    const canvas = paragraph?.closest<HTMLElement>('[data-document-canvas]')
    const rectangle = canvas?.getBoundingClientRect()
    const scale = rectangle && canvas ? rectangle.width / Number(canvas.dataset.pageWidth) : 1
    const top = paragraph && rectangle ? (paragraph.getBoundingClientRect().top - rectangle.top) / scale : objectCanvasTop(position, document.pageSettings)
    patch({ ...position, anchor: { kind: 'paragraph', id: paragraphId, offsetY: (objectCanvasTop(position, document.pageSettings) - top) / MM_TO_PX } })
  }
  const move = (changes: Partial<typeof position>) => {
    const geometry = { ...object, ...position, ...changes }
    patch({ ...geometry, anchor: objectAnchorAfterMove(object, objectCanvasTop(position, document.pageSettings), geometry, document.pageSettings) })
  }
  const dimension = (key: 'width' | 'height', value: number) => {
    const size = Math.max(key === 'height' && object.type === 'line' ? 0.1 : 2, Math.min(420, value))
    const other = key === 'width' ? 'height' : 'width'
    patch({ [key]: size, ...(object.lockAspectRatio ? { [other]: Number((size * object[other] / object[key]).toFixed(2)) } : {}) })
  }
  const number = (label: string, value: number, change: (value: number) => void, min = 0, max = 420, step = 0.5) => <label className="de-property-field">{label}<input type="number" aria-label={label} min={min} max={max} step={step} value={Number(value.toFixed(2))} onChange={(event) => change(Number(event.target.value))} /></label>
  return <aside className="de-properties" aria-label="คุณสมบัติวัตถุ"><div className="de-sidebar-heading"><h2>{({ image: 'รูปภาพ', textbox: 'กล่องข้อความ', shape: 'รูปทรง', line: 'เส้น' })[object.type]}</h2><button aria-label="ปิดคุณสมบัติวัตถุ" onClick={onClose}><X size={16} /></button></div>
    {object.type === 'image' && <Button type="button" variant="outline" size="sm" onClick={onReplaceImage}>เปลี่ยนรูปภาพ</Button>}
    <div className="de-property-section"><h3>ตำแหน่งและขนาด (มม.)</h3><p className="de-muted">ลากจากตัววัตถุเพื่อย้าย · ลูกศรขยับ 1 px · Shift 10 px</p><div className="de-property-grid">{number('ตำแหน่ง X', position.x, (x) => move({ x }), 0)}{number('ตำแหน่ง Y', position.y, (y) => move({ y }), 0)}{number('ความกว้าง', object.width, (width) => dimension('width', width), 2)}{number('ความสูง', object.height, (height) => dimension('height', height), object.type === 'line' ? 0.1 : 2)}{number('หน้ากระดาษ', position.page, (page) => move({ page: Math.max(1, Math.round(page)) }), 1, 999, 1)}{number('มุมหมุน (องศา)', object.rotation, (rotation) => patch({ rotation }), -360, 360, 1)}</div><label className="de-check"><input type="checkbox" checked={!!object.lockAspectRatio} onChange={(event) => patch({ lockAspectRatio: event.target.checked })} />{object.lockAspectRatio ? <LockKeyhole size={14} /> : <UnlockKeyhole size={14} />}ล็อกสัดส่วน</label></div>
    <div className="de-property-section"><h3>การล้อมข้อความ</h3><label className="de-property-field">รูปแบบ<select aria-label="การล้อมข้อความ" value={object.wrap} onChange={(event) => patch({ wrap: event.target.value as DocumentObject['wrap'] })}><option value="square">ล้อมรอบกรอบ (Square)</option><option value="top-bottom">ด้านบนและล่าง</option>{object.type === 'image' && <option value="inline">ในบรรทัด</option>}</select></label><p className="de-muted">ล้อมข้อความระดับย่อหน้าตามขนาดจริง · Square คืนความกว้างเมื่อขึ้นย่อหน้าใหม่</p><label className="de-property-field">การย้ายวัตถุ<select aria-label="จุดยึดวัตถุ" value={object.anchor.kind} onChange={(event) => setAnchor(event.target.value)}><option value="page">ตำแหน่งคงที่บนหน้า</option><option value="paragraph" disabled={!paragraphId}>ย้ายตามย่อหน้าที่เลือก</option></select></label><p className="de-muted">คงที่บนหน้า: ตำแหน่งไม่เลื่อนตามข้อความ · ย้ายตามย่อหน้า: ขยับเมื่อข้อความก่อนหน้าเปลี่ยน</p></div>
    <div className="de-property-section"><h3>ลักษณะ</h3><div className="de-property-grid"><label className="de-property-field">สีพื้น<input type="color" aria-label="สีพื้นวัตถุ" value={object.fill || '#ffffff'} onChange={(event) => patch({ fill: event.target.value })} /></label><label className="de-property-field">สีเส้นขอบ<input type="color" aria-label="สีเส้นขอบวัตถุ" value={object.border || '#2563eb'} onChange={(event) => patch({ border: event.target.value })} /></label>{number('ความหนาเส้น (px)', object.borderWidth ?? 1, (borderWidth) => patch({ borderWidth }), 0, 16, 0.5)}{object.type === 'textbox' && number('ระยะในกรอบ (มม.)', object.padding ?? 3, (padding) => patch({ padding }), 0, 20, 0.5)}</div><label className="de-property-field">ความโปร่งใส {Math.round((1 - object.opacity) * 100)}%<input aria-label="ความโปร่งใสวัตถุ" type="range" min={0} max={1} step={0.05} value={object.opacity} onChange={(event) => patch({ opacity: Number(event.target.value) })} /></label></div>
    <div className="de-property-section"><h3>ลำดับชั้น</h3><div className="de-property-grid"><Button type="button" variant="outline" size="sm" onClick={() => patch({ zIndex: Math.max(...document.objects.map((item) => item.zIndex), 0) + 1 })}><ArrowUp size={15} />ไว้ด้านหน้า</Button><Button type="button" variant="outline" size="sm" onClick={() => patch({ zIndex: Math.min(...document.objects.map((item) => item.zIndex), 1) - 1 })}><ArrowDown size={15} />ไว้ด้านหลัง</Button><Button type="button" variant="outline" size="sm" onClick={onDuplicate}><Copy size={15} />ทำสำเนา</Button><Button type="button" variant="outline" size="sm" onClick={onDelete}><Trash2 size={15} />ลบ</Button></div></div>
  </aside>
}
