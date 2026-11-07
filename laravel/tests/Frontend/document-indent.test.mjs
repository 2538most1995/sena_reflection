import assert from 'node:assert/strict'
import test from 'node:test'
import { Editor, getSchema } from '@tiptap/core'
import { EditorState, TextSelection } from '@tiptap/pm/state'
import StarterKit from '@tiptap/starter-kit'
import { moveParagraphIndent, paragraphIndentTargets } from '../../resources/js/lib/document-indent.ts'
import { ParagraphFormatting, RecordTemplate, paragraphIndentPreviewKey } from '../../resources/js/components/editor/document-extensions.ts'

const px = 96 / 25.4
const base = { indent: 20, rightIndent: 10, firstIndent: 10 * px }
test('first line, hanging, left and right handles move their own boundaries', () => {
  assert.deepEqual(moveParagraphIndent(base, 'first', 5, 170), { ...base, firstIndent: 15 * px })
  const hanging = moveParagraphIndent(base, 'hanging', 15, 170)
  assert.equal(hanging.indent, 35)
  assert.equal(hanging.firstIndent, -5 * px)
  assert.deepEqual(moveParagraphIndent(base, 'left', 5, 170), { ...base, indent: 25 })
  assert.deepEqual(moveParagraphIndent(base, 'right', -5, 170), { ...base, rightIndent: 15 })
})
test('handles cannot cross the opposite indent or leave less than ten millimeters of text', () => {
  const first = moveParagraphIndent(base, 'first', 1000, 100)
  assert.ok(Math.abs(first.firstIndent / px - 60) < 0.00001)
  assert.equal(moveParagraphIndent(base, 'left', 1000, 100).indent, 70)
  assert.equal(moveParagraphIndent(base, 'right', -1000, 100).rightIndent, 60)
  assert.equal(moveParagraphIndent({ ...base, firstIndent: -10 * px }, 'left', -1000, 100).indent, 10)
})
test('a collapsed selection inherits only the first record paragraph indent and retains explicit zero', () => {
  const schema = getSchema([StarterKit, ParagraphFormatting, RecordTemplate])
  const doc = schema.nodeFromJSON({ type: 'doc', content: [{ type: 'recordBody', attrs: { firstIndent: 42 }, content: [{ type: 'paragraph' }, { type: 'paragraph', attrs: { firstIndent: 0 } }, { type: 'paragraph' }] }] })
  for (const [position, expected] of [[2, 42], [4, 0], [6, 0]]) {
    const state = EditorState.create({ schema, doc, selection: TextSelection.create(doc, position) })
    assert.equal(paragraphIndentTargets(state)[0].firstIndent, expected)
  }
})
test('ruler preview changes neither content nor anchors and is removed by a real edit', () => {
  const editor = new Editor({ element: null, extensions: [StarterKit, ParagraphFormatting, RecordTemplate], content: { type: 'doc', content: [{ type: 'paragraph', attrs: { id: 'fixed-anchor', indent: 12, firstIndent: -20, rightIndent: 8 }, content: [{ type: 'text', text: 'ข้อความไทย' }] }] } })
  for (const plugin of ParagraphFormatting.config.addProseMirrorPlugins.call({})) editor.registerPlugin(plugin)
  const before = editor.getJSON()
  editor.view.dispatch(editor.state.tr.setMeta(paragraphIndentPreviewKey, [{ position: 0, indent: 30, firstIndent: -40, rightIndent: 20 }]))
  assert.deepEqual(editor.getJSON(), before)
  assert.equal(paragraphIndentPreviewKey.getState(editor.state).length, 1)
  editor.commands.insertContent({ type: 'text', text: 'เพิ่ม' })
  assert.deepEqual(paragraphIndentPreviewKey.getState(editor.state), [])
  assert.equal(editor.getJSON().content[0].attrs.id, 'fixed-anchor')
  editor.destroy()
})
test('multi-paragraph selection updates only selected paragraphs, including a heading', () => {
  const schema = getSchema([StarterKit, ParagraphFormatting, RecordTemplate])
  const doc = schema.nodeFromJSON({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'หนึ่ง' }] }, { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: 'สอง' }] }, { type: 'paragraph', content: [{ type: 'text', text: 'สาม' }] }] })
  const end = doc.child(0).nodeSize + doc.child(1).nodeSize
  const state = EditorState.create({ schema, doc, selection: TextSelection.create(doc, 1, end - 1) })
  assert.deepEqual(paragraphIndentTargets(state).map(target => target.position), [0, doc.child(0).nodeSize])
})
