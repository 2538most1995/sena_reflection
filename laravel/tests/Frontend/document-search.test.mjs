import assert from 'node:assert/strict'
import test from 'node:test'
import { Schema } from '@tiptap/pm/model'
import { findDocumentText } from '../../resources/js/lib/document-search.ts'

const schema = new Schema({ nodes: { doc: { content: 'paragraph+' }, paragraph: { content: 'inline*', group: 'block' }, text: { group: 'inline' }, hardBreak: { group: 'inline', inline: true } }, marks: { bold: {} } })
test('search matches Thai phrases across formatting boundaries without losing ProseMirror positions', () => {
  const doc = schema.nodeFromJSON({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'การเรียน', marks: [{ type: 'bold' }] }, { type: 'text', text: 'รู้ภาษาไทย การเรียนรู้' }] }] })
  const matches = findDocumentText(doc, 'การเรียนรู้')
  assert.equal(matches.length, 2)
  for (const match of matches) assert.equal(doc.textBetween(match.from, match.to), 'การเรียนรู้')
})
test('search keeps paragraphs separate and supports explicit soft breaks and case-insensitive Latin text', () => {
  const doc = schema.nodeFromJSON({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Word' }, { type: 'hardBreak' }, { type: 'text', text: 'ไทย' }] }, { type: 'paragraph', content: [{ type: 'text', text: 'Word' }] }] })
  assert.equal(findDocumentText(doc, 'word').length, 2)
  assert.equal(findDocumentText(doc, 'Wordไทย').length, 0)
  assert.equal(findDocumentText(doc, '').length, 0)
  const match = findDocumentText(doc, 'Word\nไทย')[0]
  assert.equal(doc.textBetween(match.from, match.to, '\n', '\n'), 'Word\nไทย')
})
