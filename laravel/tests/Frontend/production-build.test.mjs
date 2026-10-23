import assert from 'node:assert/strict'
import { readFileSync, existsSync } from 'node:fs'
import { resolve } from 'node:path'
import test from 'node:test'
import { frontendSourceHash, root } from '../../scripts/build-production.mjs'

const build = resolve(root, 'public/build')
const manifest = JSON.parse(readFileSync(resolve(build, 'manifest.json'), 'utf8'))

test('the committed production build matches the frontend source and the hosting mount', () => {
  const version = JSON.parse(readFileSync(resolve(build, 'source-version.json'), 'utf8'))
  assert.equal(version.basePath, '/sena_reflection')
  assert.equal(version.sourceHash, frontendSourceHash(), 'Frontend changed: run npm run build:production and commit public/build with the source')
})

test('all manifest imports, styles and font assets are shipped together', () => {
  for (const entry of Object.values(manifest)) {
    assert.ok(existsSync(resolve(build, entry.file)), `Missing built asset: ${entry.file}`)
    for (const css of entry.css || []) assert.ok(existsSync(resolve(build, css)), `Missing stylesheet: ${css}`)
    for (const key of [...entry.imports || [], ...entry.dynamicImports || []]) assert.ok(manifest[key], `Missing manifest import: ${key}`)
  }
})

test('the production editor includes the image placement fixes instead of the stale deployed screen', () => {
  const editor = manifest['resources/js/pages/records/document.tsx']
  assert.ok(editor)
  const source = readFileSync(resolve(build, editor.file), 'utf8')
  for (const feature of ['จัดรูปไม่ให้ซ้อนกัน', 'sena-pdf-layout-select', 'image_layout_by_id', 'sena-document-format', 'document_rich_text', 'เครื่องมือจัดเอกสาร']) assert.ok(source.includes(feature), `Missing editor feature: ${feature}`)
  const app = readFileSync(resolve(build, manifest['resources/js/app.tsx'].file), 'utf8')
  assert.ok(app.includes('/sena_reflection/build/'), 'Vite imports must stay inside this application mount')
})

test('the production build ships the advanced document editor and its export engine', () => {
  for (const page of ['resources/js/pages/documents/index.tsx', 'resources/js/pages/documents/edit.tsx']) {
    assert.ok(manifest[page], `Missing document page: ${page}`)
  }
  const seen = new Set()
  const chunks = key => {
    if (seen.has(key)) return ''
    seen.add(key)
    const entry = manifest[key]
    return readFileSync(resolve(build, entry.file), 'utf8') + [...entry.imports || [], ...entry.dynamicImports || []].map(chunks).join('\n')
  }
  const source = chunks('resources/js/pages/documents/edit.tsx')
  for (const feature of ['data-document-canvas', 'document-body-editor', 'schemaVersion', 'พิมพ์ / PDF', 'DOCX', '/versions', '/duplicate']) {
    assert.ok(source.includes(feature), `Missing advanced editor feature: ${feature}`)
  }
})

test('the canonical print renderer ships with Thai font subsets', () => {
  assert.ok(manifest['resources/js/document-snapshot.tsx'], 'Missing standalone snapshot renderer')
  const fonts = JSON.parse(readFileSync(resolve(build, 'fonts-manifest.json'), 'utf8'))
  const css = readFileSync(resolve(build, fonts.style.file), 'utf8')
  for (const family of ['Noto Sans Thai', 'Anuphan', 'Sarabun']) {
    const faces = css.split('@font-face').filter(face => new RegExp(`font-family:\\s*["']${family}["']`).test(face))
    assert.ok(faces.some(face => /U\+0E0[01]-0E[57][BF]/i.test(face)), `Missing Thai font subset: ${family}`)
  }
})
