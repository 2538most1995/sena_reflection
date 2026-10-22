import assert from 'node:assert/strict'
import { registerHooks } from 'node:module'
import test from 'node:test'
import JSZip from 'jszip'
import { Packer } from 'docx'

// The app's bundler resolves extensionless TS imports; Node's native TS runner needs the suffix.
registerHooks({
  resolve(specifier, context, nextResolve) {
    if (['./document-model', './document-print'].includes(specifier) && /\/document-(export|print)\.ts$/.test(context.parentURL || '')) return nextResolve(`${specifier}.ts`, context)
    return nextResolve(specifier, context)
  },
})
const { buildDocumentDocx, documentPrintPageGeometry, resolveDocumentAssetUrl } = await import('../../resources/js/lib/document-export.ts')
const { defaultPageSettings } = await import('../../resources/js/lib/document-model.ts')

const paragraph = (text, attrs = {}) => ({ type: 'paragraph', attrs, content: [{ type: 'text', text }] })
function fixture(overrides = {}) {
  return {
    id: 'document-one', title: 'เอกสารภาษาไทย <script>ปลอดภัย</script>', revision: 3, schemaVersion: 1,
    updatedAt: '2026-10-22T10:00:00Z', assets: [], objects: [],
    pageSettings: structuredClone(defaultPageSettings), content: { type: 'doc', content: [paragraph('เอกสารภาษาไทย')] },
    ...overrides,
  }
}
async function archive(model, options) {
  const result = await buildDocumentDocx(model, options)
  const zip = await JSZip.loadAsync(await Packer.toBuffer(result.document))
  return { ...result, zip, xml: await zip.file('word/document.xml').async('string') }
}
const png = Uint8Array.from(Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jhx0AAAAASUVORK5CYII=', 'base64'))
const options = { baseUrl: 'https://school.test/reflection/documents/one', fetch: async () => new Response(png, { headers: { 'Content-Type': 'image/png' } }) }
function object(type, overrides = {}) {
  return { id: `${type}-one`, type, page: 1, x: 25, y: 35, width: 60, height: 35, rotation: 15, zIndex: 2, opacity: 1, wrap: 'square', anchor: { kind: 'page', page: 1 }, fill: '#dbeafe', border: '#2563eb', borderWidth: 1, padding: 3, shape: 'rect', ...overrides }
}

test('DOCX contains editable Thai runs, marks, headings, links, spacing and default Sarabun typography', async () => {
  const model = fixture({ content: { type: 'doc', content: [
    { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: 'หัวเรื่อง' }] },
    { type: 'paragraph', attrs: { textAlign: 'justify', lineHeight: 2, spaceBefore: 6, spaceAfter: 8, indent: 10 }, content: [
      { type: 'text', text: 'เลือกคำภาษาไทย', marks: [
        { type: 'bold' }, { type: 'italic' }, { type: 'underline' }, { type: 'strike' },
        { type: 'textStyle', attrs: { fontFamily: 'Sarabun', fontSize: '20pt', color: '#123abc' } },
        { type: 'highlight', attrs: { color: '#ffdd11' } },
      ] },
      { type: 'hardBreak' },
      { type: 'text', text: 'ยก', marks: [{ type: 'superscript' }] },
      { type: 'text', text: 'ห้อย', marks: [{ type: 'subscript' }] },
      { type: 'text', text: 'เว็บไซต์', marks: [{ type: 'link', attrs: { href: 'https://school.test/about' } }] },
    ] },
  ] } })
  const { xml, zip, warnings } = await archive(model)
  assert.match(xml, /หัวเรื่อง/)
  assert.match(xml, /w:pStyle w:val="Heading2"/)
  assert.match(xml, /เลือกคำภาษาไทย/)
  assert.match(xml, /<w:b\/>/)
  assert.match(xml, /<w:bCs\/>/)
  assert.match(xml, /<w:i\/>/)
  assert.match(xml, /<w:u w:val="single"\/>/)
  assert.match(xml, /<w:strike\/>/)
  assert.match(xml, /w:color w:val="123ABC"/)
  assert.match(xml, /w:fill="FFDD11"/)
  assert.match(xml, /w:sz w:val="40"/)
  assert.match(xml, /w:vertAlign w:val="superscript"/)
  assert.match(xml, /w:vertAlign w:val="subscript"/)
  assert.match(xml, /w:jc w:val="both"/)
  assert.match(xml, /w:before="120"/)
  assert.match(xml, /w:after="160"/)
  assert.match(xml, /w:line="480"/)
  assert.match(xml, /w:ind w:left="567"/)
  assert.match(xml, /<w:br\/>/)
  assert.match(xml, /<w:hyperlink /)
  const styles = await zip.file('word/styles.xml').async('string')
  assert.match(styles, /w:ascii="Sarabun"/)
  assert.match(styles, /w:sz w:val="32"/)
  const properties = await zip.file('docProps/core.xml').async('string')
  assert.match(properties, /&lt;script&gt;ปลอดภัย&lt;\/script&gt;/)
  assert.deepEqual(warnings, [])
})

test('landscape dimensions, physical margins, header/footer, page numbers and explicit page breaks survive export', async () => {
  const model = fixture()
  Object.assign(model.pageSettings, { orientation: 'landscape', header: 'ส่วนหัวภาษาไทย', footer: 'ส่วนท้าย', border: '#123abc' })
  model.content.content.push({ type: 'pageBreak' }, paragraph('หน้าถัดไป'))
  const { xml, zip } = await archive(model)
  assert.match(xml, /w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/)
  assert.match(xml, /w:pgMar[^>]*w:top="850"[^>]*w:right="1134"[^>]*w:bottom="850"[^>]*w:left="1134"/)
  assert.match(xml, /w:br w:type="page"/)
  assert.match(xml, /w:color="123ABC"/)
  const header = await zip.file('word/header1.xml').async('string')
  const footer = await zip.file('word/footer1.xml').async('string')
  assert.match(header, /ส่วนหัวภาษาไทย/)
  assert.match(footer, /ส่วนท้าย/)
  assert.match(footer, /PAGE/)
  assert.match(footer, /NUMPAGES/)
})

test('lists, continuation paragraphs, nested levels, editable tables and merged cells use Word structures', async () => {
  const model = fixture({ content: { type: 'doc', content: [
    { type: 'orderedList', attrs: { start: 4 }, content: [
      { type: 'listItem', content: [paragraph('รายการหนึ่ง'), paragraph('รายละเอียดรายการหนึ่ง'), { type: 'bulletList', content: [{ type: 'listItem', content: [paragraph('รายการย่อย')] }] }] },
      { type: 'listItem', content: [paragraph('รายการสอง')] },
    ] },
    { type: 'table', content: [
      { type: 'tableRow', content: [{ type: 'tableHeader', attrs: { colspan: 2, backgroundColor: '#ffdd11' }, content: [paragraph('หัวตาราง')] }] },
      { type: 'tableRow', content: [{ type: 'tableCell', attrs: { rowspan: 2 }, content: [paragraph('รวมแถว')] }, { type: 'tableCell', content: [paragraph('ข้อมูล')] }] },
      { type: 'tableRow', content: [{ type: 'tableCell', content: [paragraph('แถวถัดไป')] }] },
    ] },
  ] } })
  const { xml, zip } = await archive(model)
  assert.equal((xml.match(/<w:numPr>/g) || []).length, 3, 'a list continuation paragraph must not get a second marker')
  assert.match(xml, /w:ilvl w:val="1"/)
  assert.match(xml, /<w:tbl>/)
  assert.match(xml, /w:gridSpan w:val="2"/)
  assert.match(xml, /w:vMerge w:val="restart"/)
  assert.match(xml, /w:fill="FFDD11"/)
  assert.match(xml, /หัวตาราง/)
  assert.match(xml, /<w:b\/>/)
  assert.match(xml, /แถวถัดไป/)
  assert.match(await zip.file('word/numbering.xml').async('string'), /w:start w:val="4"/)
})

test('authenticated image fetches are cached and floating images have real page offsets and wrap modes', async () => {
  const requests = []
  const source = '/reflection/api/documents/one/assets/photo'
  const model = fixture({ objects: [object('image', { src: source, wrap: 'top-bottom' }), object('image', { id: 'image-two', src: source, wrap: 'inline' })] })
  const { xml, zip, warnings } = await archive(model, { ...options, fetch: async (url, init) => { requests.push({ url, init }); return new Response(png) } })
  assert.equal(requests.length, 1)
  assert.equal(requests[0].init.credentials, 'same-origin')
  assert.equal(requests[0].init.redirect, 'error')
  assert.equal(requests[0].url, 'https://school.test/reflection/api/documents/one/assets/photo')
  assert.match(xml, /<wp:anchor /)
  assert.match(xml, /<wp:inline /)
  assert.match(xml, /<wp:positionH relativeFrom="page"><wp:posOffset>900000<\/wp:posOffset>/)
  assert.match(xml, /<wp:positionV relativeFrom="page"><wp:posOffset>1260000<\/wp:posOffset>/)
  assert.match(xml, /wp:wrapTopAndBottom/)
  assert.ok(Object.keys(zip.files).some(path => path.startsWith('word/media/') && path.endsWith('.png')))
  assert.ok(warnings.some(warning => warning.includes('ตำแหน่งวัตถุอาจต่าง')))
})

test('TablePlus row groups preserve every row and percentage-based resized columns in Word', async () => {
  const model = fixture({ content: { type: 'doc', content: [{ type: 'table', attrs: { columnSize: '30,70' }, content: [
    { type: 'tableRowGroup', content: [
      { type: 'tableRow', content: [{ type: 'tableHeader', content: [paragraph('หัวข้อ')] }, { type: 'tableHeader', content: [paragraph('รายละเอียด')] }] },
      { type: 'tableRow', content: [{ type: 'tableCell', content: [paragraph('แถวแรก')] }, { type: 'tableCell', content: [paragraph('ข้อมูลแรก')] }] },
    ] },
    { type: 'tableRowGroup', content: [{ type: 'tableRow', content: [{ type: 'tableCell', content: [paragraph('แถวสอง')] }, { type: 'tableCell', content: [paragraph('ข้อมูลสอง')] }] }] },
  ] }] } })
  const { xml, warnings } = await archive(model)
  assert.equal((xml.match(/<w:tr>/g) || []).length, 3)
  assert.equal((xml.match(/<w:tc>/g) || []).length, 6)
  for (const text of ['หัวข้อ', 'รายละเอียด', 'แถวแรก', 'ข้อมูลแรก', 'แถวสอง', 'ข้อมูลสอง']) assert.match(xml, new RegExp(text))
  assert.match(xml, /w:gridCol w:w="2891"/)
  assert.match(xml, /w:gridCol w:w="6747"/)
  assert.match(xml, /w:tblLayout w:type="fixed"/)
  assert.deepEqual(warnings, [])
})

test('native shapes and rich text boxes remain editable; unsupported automatic-page anchors keep all content', async () => {
  const model = fixture({ content: { type: 'doc', content: [paragraph('เนื้อหา', { id: 'anchor-paragraph' })] }, objects: [
    object('textbox', { anchor: { kind: 'paragraph', id: 'anchor-paragraph', offsetY: 4 }, content: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'กล่องข้อความแก้ไขได้', marks: [{ type: 'bold' }] }] }] } }),
    object('shape', { id: 'ellipse', shape: 'ellipse', opacity: 0.4 }),
    object('shape', { id: 'triangle', shape: 'triangle' }),
    object('line'),
    object('textbox', { id: 'later-page', page: 3, anchor: { kind: 'page', page: 3 }, content: { type: 'doc', content: [paragraph('ข้อความหน้าสามต้องไม่หาย')] } }),
  ] })
  const { xml, warnings } = await archive(model)
  assert.match(xml, /wps:txbx/)
  assert.match(xml, /กล่องข้อความแก้ไขได้/)
  assert.match(xml, /ข้อความหน้าสามต้องไม่หาย/)
  assert.match(xml, /a:prstGeom prst="rect"/)
  assert.match(xml, /a:prstGeom prst="ellipse"/)
  assert.match(xml, /a:prstGeom prst="triangle"/)
  assert.match(xml, /a:prstGeom prst="line"/)
  assert.match(xml, /relativeFrom="paragraph"/)
  assert.match(xml, /<wp:posOffset>144000<\/wp:posOffset>/)
  assert.ok(warnings.some(warning => warning.includes('วัตถุหน้า 3') && warning.includes('ท้ายเอกสาร')))
})

test('images that cannot be loaded reject export instead of silently generating incomplete documents', async () => {
  const model = fixture({ objects: [object('image', { src: '/assets/photo' })] })
  await assert.rejects(buildDocumentDocx(model, { ...options, fetch: async () => new Response('denied', { status: 403 }) }), /403/)
  await assert.rejects(buildDocumentDocx(model, { ...options, fetch: async () => new Response('<html>login</html>') }), /ชนิดภาพไม่รองรับ/)
  await assert.rejects(buildDocumentDocx(model, { ...options, fetch: async () => new Response(png, { headers: { 'Content-Length': '16777216' } }) }), /15 MB/)
  await assert.rejects(buildDocumentDocx(fixture({ objects: [object('image')] }), options), /ไม่มีไฟล์ต้นฉบับ/)
})

test('asset URLs reject cross-origin, executable, inline-data and credential URLs before any fetch', async () => {
  for (const source of ['https://attacker.test/photo.png', 'javascript:alert(1)', 'data:image/png;base64,abc', 'blob:https://school.test/123', 'https://user:password@school.test/photo']) {
    assert.throws(() => resolveDocumentAssetUrl(source, options.baseUrl), /เฉพาะไฟล์ภาพ/)
  }
  let calls = 0
  await assert.rejects(buildDocumentDocx(fixture({ objects: [object('image', { src: '//attacker.test/pixel' })] }), { ...options, fetch: async () => { calls++; return new Response(png) } }), /เฉพาะไฟล์ภาพ/)
  assert.equal(calls, 0)
  const unsafeLink = fixture({ content: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'ข้อความปลอดภัย', marks: [{ type: 'link', attrs: { href: 'javascript:alert(1)' } }] }] }] } })
  const { xml, warnings } = await archive(unsafeLink)
  assert.match(xml, /ข้อความปลอดภัย/)
  assert.doesNotMatch(xml, /<w:hyperlink/)
  assert.equal(warnings.length, 1)
})

test('WebP is converted to PNG for Word rather than putting unsupported WebP bytes into a DOCX', async () => {
  let conversions = 0
  const webp = Uint8Array.from([...Buffer.from('RIFF'), 0, 0, 0, 0, ...Buffer.from('WEBP')])
  const { zip } = await archive(fixture({ objects: [object('image', { src: '/assets/photo.webp' })] }), {
    ...options, fetch: async () => new Response(webp), convertWebp: async () => { conversions++; return png },
  })
  assert.equal(conversions, 1)
  assert.ok(Object.keys(zip.files).some(path => path.startsWith('word/media/') && path.endsWith('.png')))
  assert.ok(!Object.keys(zip.files).some(path => path.endsWith('.webp')))
})

test('print page viewports use physical dimensions and remove screen gaps without depending on zoom', () => {
  const model = fixture()
  const pages = documentPrintPageGeometry(model, 3)
  assert.equal(pages[0].widthMm, 210)
  assert.equal(pages[0].heightMm, 297)
  assert.equal(pages[0].sourceTopPx, 0)
  assert.equal(pages[1].sourceTopPx, 297 * 96 / 25.4 + 28)
  assert.equal(pages[2].sourceTopPx, pages[1].sourceTopPx * 2)
  model.pageSettings.orientation = 'landscape'
  assert.deepEqual(documentPrintPageGeometry(model, 0), [{ page: 1, widthMm: 297, heightMm: 210, sourceTopPx: 0 }])
  model.pageSettings.size = 'Letter'
  assert.equal(documentPrintPageGeometry(model, 1)[0].widthMm, 279.4)
})

test('DOCX explains line-height formatting that Word cannot represent on individual text runs', async () => {
  const model = fixture({ content: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'ข้อความยังอยู่', marks: [{ type: 'textStyle', attrs: { lineHeight: '2' } }] }] }] } })
  const { xml, warnings } = await archive(model)
  assert.match(xml, /ข้อความยังอยู่/)
  assert.ok(warnings.some(warning => warning.includes('ระยะบรรทัดเฉพาะบางคำ')))
})
