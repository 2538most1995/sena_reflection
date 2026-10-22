import assert from 'node:assert/strict'
import test from 'node:test'
import { getSchema } from '@tiptap/core'
import { EditorState } from '@tiptap/pm/state'
import StarterKit from '@tiptap/starter-kit'
import { ObjectTextFlow, objectFlowKey, PageBreak, ParagraphFormatting } from '../../resources/js/components/editor/document-extensions.ts'

const schema = getSchema([StarterKit, ParagraphFormatting, PageBreak])

test('Thai content, stable paragraph anchors and paragraph formatting survive JSON round trips', () => {
  const text = 'ผู้เรียนได้เรียนรู้เรื่องสิ่งแวดล้อม น้ำ และการอนุรักษ์'
  const node = schema.nodeFromJSON({ type: 'doc', content: [
    { type: 'paragraph', attrs: { id: 'paragraph-stable', lineHeight: 1.75, spaceBefore: 6, spaceAfter: 12, indent: 10 }, content: [{ type: 'text', text, marks: [{ type: 'bold' }] }] },
    { type: 'pageBreak' },
    { type: 'paragraph', content: [{ type: 'text', text: 'หน้าถัดไป' }] },
  ] })
  const restored = schema.nodeFromJSON(JSON.parse(JSON.stringify(node.toJSON())))
  assert.equal(restored.textContent, `${text}หน้าถัดไป`)
  assert.deepEqual({ ...restored.firstChild.attrs }, { id: 'paragraph-stable', lineHeight: 1.75, spaceBefore: 6, spaceAfter: 12, indent: 10 })
  assert.equal(restored.firstChild.firstChild.marks[0].type.name, 'bold')
  assert.equal(restored.child(1).type.name, 'pageBreak')
  assert.equal(restored.child(1).isAtom, true)
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
