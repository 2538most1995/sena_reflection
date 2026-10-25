import assert from 'node:assert/strict'
import test from 'node:test'
import { browserExportStyles } from '../../resources/js/lib/browser-export.ts'

globalThis.CSSRule = { STYLE_RULE: 1, IMPORT_RULE: 3, FONT_FACE_RULE: 5, PAGE_RULE: 6 }
const source = rules => ({ baseURI: 'https://example.test/sena_reflection/', styleSheets: [{ href: 'https://example.test/sena_reflection/build/assets/print.css', cssRules: rules }] })
const style = (selectorText, cssText) => ({ type: 1, selectorText, style: { cssText }, cssRules: [] })

test('isolated document styles retain html/body classes, variables and nested rules', () => {
  const result = browserExportStyles(source([
    style(':root, html.html-preview-direct body', '--signature-font-size: 16pt; margin: 0;'),
    style('.body-copy, [data-body="body"]', 'color: black;'),
    { type: 4, cssText: '@media print { body {} }', cssRules: [style('body .page', 'padding: 0;')] },
  ]), 'record_1')
  assert.match(result.css, /\.browser-export-html, \.browser-export-html\.html-preview-direct \.browser-export-body/)
  assert.match(result.css, /--signature-font-size: 16pt/)
  assert.match(result.css, /\.body-copy, \[data-body="body"\]/)
  assert.match(result.css, /@media print \{\.browser-export-body \.page\{padding: 0;/)
  assert.equal(result.global, '')
})

test('each record gets named page dimensions and margins while fonts keep absolute asset URLs', () => {
  const rules = [
    { type: 6, selectorText: '', style: { cssText: 'size: 297mm 210mm; margin: 12px 0;' } },
    { type: 6, selectorText: ':first', style: { cssText: 'margin-top: 20px;' } },
    { type: 5, cssText: '@font-face { font-family: Sarabun; src: url("./Sarabun.woff2"); }' },
    style('.page', 'background: url("data:image/png;base64,AAAA");'),
  ]
  const first = browserExportStyles(source(rules), 'record_1')
  const second = browserExportStyles(source(rules), 'record_2')
  assert.match(first.global, /@page record_1\{size: 297mm 210mm; margin: 12px 0;/)
  assert.match(first.global, /@page record_1:first\{margin-top: 20px;/)
  assert.match(second.global, /@page record_2\{/)
  assert.doesNotMatch(first.css, /@page|@font-face/)
  assert.match(first.global, /https:\/\/example.test\/sena_reflection\/build\/assets\/Sarabun.woff2/)
  assert.match(first.css, /data:image\/png;base64,AAAA/)
})

test('unreadable imported styles fail instead of printing a partial layout', () => {
  assert.throws(() => browserExportStyles(source([{ type: 3, styleSheet: null }]), 'record_1'), /รูปแบบเอกสารไม่ครบ/)
})
