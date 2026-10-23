import assert from 'node:assert/strict'
import test from 'node:test'
import { getSchema } from '@tiptap/core'
import { EditorState } from '@tiptap/pm/state'
import { history, undo, redo, closeHistory } from '@tiptap/pm/history'
import StarterKit from '@tiptap/starter-kit'
import { TextStyleKit } from '@tiptap/extension-text-style'
import { ObjectTextFlow, objectFlowKey, ParagraphFormatting, RecordTemplate, signatureMoveTransaction } from '../../resources/js/components/editor/document-extensions.ts'

const schema = getSchema([StarterKit, TextStyleKit, ParagraphFormatting, RecordTemplate])

test('the shared record extension provides a complete schema for floating object editors', () => {
  const objectSchema = getSchema([StarterKit, ParagraphFormatting, RecordTemplate])
  const document = objectSchema.nodeFromJSON({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'กล่องข้อความภาษาไทย' }] }] })
  document.check()
  assert.equal(document.textContent, 'กล่องข้อความภาษาไทย')
  assert.ok(objectSchema.nodes.pageBreak)
  objectSchema.nodeFromJSON({ type: 'recordBody', content: [{ type: 'paragraph' }, { type: 'pageBreak' }] }).check()
})

test('each signature movement is one independent undo item and leaves text untouched', () => {
  let state = EditorState.create({ schema, plugins: [history()], doc: schema.nodeFromJSON({ type: 'doc', content: [{ type: 'recordSignature', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'ครูผู้สอน ภาษาไทย' }] }] }] }) })
  const apply = transaction => { state = state.apply(transaction) }
  apply(signatureMoveTransaction(state, 0, 80, 30))
  apply(closeHistory(state.tr).setMeta('addToHistory', false))
  apply(signatureMoveTransaction(state, 0, 81, 30))
  assert.equal(state.doc.firstChild.attrs.offsetX, 81)
  assert.equal(state.doc.textContent, 'ครูผู้สอน ภาษาไทย')
  assert.equal(undo(state, apply), true)
  assert.equal(state.doc.firstChild.attrs.offsetX, 80)
  assert.equal(state.doc.firstChild.attrs.offsetY, 30)
  assert.equal(undo(state, apply), true)
  assert.equal(state.doc.firstChild.attrs.offsetX, 0)
  assert.equal(redo(state, apply), true)
  assert.equal(state.doc.firstChild.attrs.offsetX, 80)
  assert.equal(signatureMoveTransaction(state, 0, 80, 30), null)
})

test('Thai content, stable paragraph anchors and paragraph formatting survive JSON round trips', () => {
  const text = 'ผู้เรียนได้เรียนรู้เรื่องสิ่งแวดล้อม น้ำ และการอนุรักษ์'
  const node = schema.nodeFromJSON({ type: 'doc', content: [
    { type: 'paragraph', attrs: { id: 'paragraph-stable', lineHeight: 1.75, spaceBefore: 6, spaceAfter: 12, indent: 10 }, content: [{ type: 'text', text, marks: [{ type: 'bold' }] }] },
    { type: 'pageBreak' },
    { type: 'paragraph', content: [{ type: 'text', text: 'หน้าถัดไป' }] },
  ] })
  const restored = schema.nodeFromJSON(JSON.parse(JSON.stringify(node.toJSON())))
  assert.equal(restored.textContent, `${text}หน้าถัดไป`)
  assert.deepEqual({ ...restored.firstChild.attrs }, { id: 'paragraph-stable', field: null, role: null, firstIndent: null, recordLine: false, lineHeight: 1.75, spaceBefore: 6, spaceAfter: 12, indent: 10, offsetX: 0, offsetY: 0 })
  assert.equal(restored.firstChild.firstChild.marks[0].type.name, 'bold')
  assert.equal(restored.child(1).type.name, 'pageBreak')
  assert.equal(restored.child(1).isAtom, true)
})

test('weekly record semantic nodes retain native fields, dimensions and signature offsets in canonical JSON', () => {
  const paragraph = (text, attrs = {}) => ({ type: 'paragraph', attrs, content: [{ type: 'text', text }] })
  const document = schema.nodeFromJSON({ type: 'doc', content: [
    { ...paragraph('บันทึกหลังการสอนการเรียนรู้รายสัปดาห์', { role: 'recordTitle' }), content: [{ type: 'text', text: 'บันทึกหลังการสอนการเรียนรู้รายสัปดาห์', marks: [{ type: 'bold' }, { type: 'textStyle', attrs: { fontSize: '20pt' } }] }] },
    { type: 'recordInfoRow', content: [
      { type: 'recordInfoCell', attrs: { label: true, width: 10 }, content: [{ type: 'text', text: 'รายวิชา :' }] },
      { type: 'recordInfoCell', attrs: { field: 'record_subject', dotted: true, width: 36 }, content: [{ type: 'text', text: 'ภาษาไทย' }] },
    ] },
    { type: 'recordSectionTitle', attrs: { field: 'record_section1_title' }, content: [{ type: 'text', text: '๑. ผลการจัดการเรียนการสอน' }] },
    { type: 'recordBody', attrs: { field: 'record_result' }, content: [{ type: 'paragraph', content: [{ type: 'text', text: 'ย่อหน้าแรก' }, { type: 'hardBreak' }, { type: 'text', text: 'บรรทัดถัดไป ไม่เยื้องใหม่' }] }] },
    { type: 'recordGroup', attrs: { spaceBefore: 18, borderTop: true }, content: [
      { type: 'recordSignature', attrs: { index: 2, width: 430, marginTop: 40, offsetX: 12, offsetY: -3 }, content: [
        { type: 'recordInfoRow', attrs: { compact: true, offsetX: 4, offsetY: 2 }, content: [
          { type: 'recordInfoCell', attrs: { field: 'sig2_sign_label', unit: 'px', label: true }, content: [{ type: 'text', text: '(ลงชื่อ)' }] },
          { type: 'recordInfoCell', attrs: { unit: 'px', width: 180, dotted: true } },
        ] },
        paragraph('( ครูภาษาไทย )', { field: 'sig2_name', role: 'signatureName', offsetY: 1 }),
        paragraph('หัวหน้างาน', { field: 'sig2_pos', role: 'signaturePosition' }),
      ] },
    ] },
  ] })
  document.check()
  const restored = schema.nodeFromJSON(JSON.parse(JSON.stringify(document.toJSON())))
  assert.equal(restored.child(1).firstChild.attrs.width, 10)
  assert.equal(restored.child(1).child(1).attrs.field, 'record_subject')
  assert.equal(restored.child(3).attrs.lineSpacing, 26)
  assert.equal(restored.child(3).attrs.firstIndent, 42)
  assert.equal(restored.child(3).firstChild.child(1).type.name, 'hardBreak')
  assert.equal(restored.child(3).firstChild.attrs.spaceAfter, null)
  const signature = restored.child(4).firstChild
  assert.equal(signature.attrs.width, 430)
  assert.equal(signature.attrs.offsetX, 12)
  assert.equal(signature.firstChild.attrs.compact, true)
  assert.equal(signature.firstChild.child(1).attrs.unit, 'px')
  assert.equal(signature.firstChild.child(1).attrs.width, 180)
  assert.equal(signature.child(1).attrs.field, 'sig2_name')
  assert.equal(signature.child(1).attrs.offsetY, 1)
  assert.equal(restored.firstChild.firstChild.marks.find(mark => mark.type.name === 'textStyle').attrs.fontSize, '20pt')
})

test('record body accepts native paragraphs and lists without accepting arbitrary record cells', () => {
  const body = schema.nodeFromJSON({ type: 'recordBody', content: [
    { type: 'paragraph' },
    { type: 'bulletList', content: [{ type: 'listItem', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'หัวข้อภาษาไทย' }] }] }] },
    { type: 'pageBreak' },
  ] })
  body.check()
  assert.equal(body.childCount, 3)
  const invalid = schema.nodeFromJSON({ type: 'recordBody', content: [{ type: 'recordInfoCell', content: [{ type: 'text', text: 'ไม่ใช่เนื้อหาย่อหน้า' }] }] })
  assert.throws(() => invalid.check(), /Invalid content for node recordBody/)
})

test('page breaks remain separate block nodes rather than splitting or rewriting text', () => {
  const node = schema.nodeFromJSON({ type: 'doc', content: [
    { type: 'paragraph', content: [{ type: 'text', text: 'เก้าอี้ กำลัง ศึกษา' }] },
    { type: 'pageBreak' }, { type: 'paragraph' }, { type: 'pageBreak' }, { type: 'paragraph' },
  ] })
  assert.equal(node.childCount, 5)
  assert.equal(node.textContent, 'เก้าอี้ กำลัง ศึกษา')
  assert.equal(node.toJSON().content.filter(child => child.type === 'pageBreak').length, 2)
})

test('wrapping reserves real paragraph space without adding layout objects to document JSON', () => {
  const document = schema.nodeFromJSON({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'ข้อความภาษาไทยที่ต้องเว้นพื้นที่ให้วัตถุ' }] }] })
  const plugins = ObjectTextFlow.config.addProseMirrorPlugins.call({})
  const state = EditorState.create({ schema, doc: document, plugins })
  const reservation = { id: 'object-image', position: 0, nodeSize: document.firstChild.nodeSize, side: 'left', width: 240, height: 130, offset: 60, wrap: 'square' }
  const square = state.apply(state.tr.setMeta(objectFlowKey, [reservation]))
  const squareDecoration = objectFlowKey.getState(square).find()[0]
  assert.equal(squareDecoration.from, 0)
  assert.equal(squareDecoration.to, document.firstChild.nodeSize)
  assert.match(squareDecoration.type.attrs.style, /padding-left:240px/)
  assert.match(squareDecoration.type.attrs.style, /padding-top:0px/)
  assert.deepEqual(square.doc.toJSON(), document.toJSON())
  const topBottom = state.apply(state.tr.setMeta(objectFlowKey, [{ ...reservation, wrap: 'top-bottom' }]))
  assert.match(objectFlowKey.getState(topBottom).find()[0].type.attrs.style, /padding-top:190px/)
  assert.match(objectFlowKey.getState(topBottom).find()[0].type.attrs.style, /padding-left:0px/)
  assert.deepEqual(topBottom.doc.toJSON(), document.toJSON())
})
