import assert from 'node:assert/strict'
import test from 'node:test'
import { senaReadDocumentText } from '../../../assets/js/document-rich-editor.js'

function text(value) { return { nodeType: 3, textContent: value, nodeName: '#text' } }
function element(tagName, children = [], classes = []) {
  const node = { nodeType: 1, tagName, nodeName: tagName, childNodes: children, classList: { contains: value => classes.includes(value) } }
  children.forEach((child, index) => Object.assign(child, { parentElement: node, previousSibling: children[index - 1] || null, nextSibling: children[index + 1] || null }))
  return node
}

test('Enter adds exactly one newline, including when the next paragraph is empty', () => {
  assert.equal(senaReadDocumentText(element('DIV', [element('P', [text('บรรทัดแรก')]), element('P', [element('BR')])])), 'บรรทัดแรก\n')
  assert.equal(senaReadDocumentText(element('DIV', [element('P', [text('หนึ่ง')]), element('P', [element('BR')]), element('P', [text('สาม')])])), 'หนึ่ง\n\nสาม')
  assert.equal(senaReadDocumentText(element('DIV', [element('P', [element('BR')])])), '')
})

test('soft breaks, tabs and explicit blank lines survive canonical serialization', () => {
  assert.equal(senaReadDocumentText(element('DIV', [element('P', [text('หนึ่ง'), element('BR'), text('สอง'), element('BR'), element('BR')])])), 'หนึ่ง\nสอง\n')
  assert.equal(senaReadDocumentText(element('DIV', [text('\n  หนึ่ง\u2028\tสอง\u00a0\n\n')])), '\n  หนึ่ง\n\tสอง \n\n')
})

test('formatting leaves plain text unchanged and preserves page break markers and lists', () => {
  assert.equal(senaReadDocumentText(element('DIV', [element('P', [element('SPAN', [text('ข้อความ')]), element('B', [text('ตัวหนา')])])])), 'ข้อความตัวหนา')
  assert.equal(senaReadDocumentText(element('DIV', [text('หน้าแรก'), element('DIV', [], ['manual-page-break']), text('หน้าสอง')])), 'หน้าแรก[break]หน้าสอง')
  assert.equal(senaReadDocumentText(element('DIV', [element('UL', [element('LI', [text('หนึ่ง')]), element('LI', [text('สอง')])])])), 'หนึ่ง\nสอง')
})
