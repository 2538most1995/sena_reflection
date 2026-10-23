import assert from 'node:assert/strict'
import test from 'node:test'
import { getSchema } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import { DocumentTextAlign } from '../../resources/js/components/editor/document-text-align.ts'

const alignment = DocumentTextAlign.configure({ types: ['paragraph', 'heading'] })
const attribute = alignment.config.addGlobalAttributes.call({ options: alignment.options })[0].attributes.textAlign

test('Thai distributed alignment keeps Thai text and marks intact through canonical JSON', () => {
  const schema = getSchema([StarterKit, alignment])
  const text = 'ผู้เรียนกำลังฝึกทักษะภาษาไทย น้ำ และสิ่งแวดล้อม'
  const original = schema.nodeFromJSON({ type: 'doc', content: [
    { type: 'paragraph', attrs: { textAlign: 'thai-distributed' }, content: [{ type: 'text', text, marks: [{ type: 'bold' }] }] },
  ] })
  const restored = schema.nodeFromJSON(JSON.parse(JSON.stringify(original.toJSON())))
  assert.equal(restored.textContent, text)
  assert.equal(restored.firstChild.attrs.textAlign, 'thai-distributed')
  assert.equal(restored.firstChild.firstChild.marks[0].type.name, 'bold')
})

test('Thai distribution uses valid cluster-aware CSS, leaves the final line left and resets on another alignment', () => {
  const thai = attribute.renderHTML({ textAlign: 'thai-distributed' })
  assert.equal(thai['data-document-alignment'], 'thai-distributed')
  assert.match(thai.style, /text-align:justify/)
  assert.match(thai.style, /text-align-last:left/)
  assert.match(thai.style, /text-justify:inter-character/)
  const normal = attribute.renderHTML({ textAlign: 'justify' })
  assert.match(normal.style, /text-justify:auto/)
  assert.match(normal.style, /text-align-last:auto/)
  assert.equal(attribute.parseHTML({ getAttribute: () => thai['data-document-alignment'], style: { textAlign: 'justify' } }), 'thai-distributed')
  assert.deepEqual(attribute.renderHTML({ textAlign: 'justify;position:fixed' }), {})
})
