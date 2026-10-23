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

function recordTemplateFixture() {
  const inline = text => text ? [{ type: 'text', text }] : []
  const cell = (text, width, attrs = {}) => ({ type: 'recordInfoCell', attrs: { width, unit: 'percent', ...attrs }, content: inline(text) })
  const info = cells => ({ type: 'recordInfoRow', content: cells })
  const section = (text, field) => ({ type: 'recordSectionTitle', attrs: { field, spaceBefore: 8, offsetX: 0, offsetY: 0 }, content: inline(text) })
  const body = (text, field, minLines) => ({ type: 'recordBody', attrs: { field, minLines, lineSpacing: 26, firstIndent: 42, align: 'justify', spaceAfter: 0 }, content: [paragraph(text, { id: field, field })] })
  const signature = (index, name, position) => ({
    type: 'recordSignature', attrs: { index, align: 'right', width: 430, marginTop: 16, offsetX: 0, offsetY: 0 }, content: [
      { type: 'recordInfoRow', attrs: { compact: true }, content: [
        cell('(ลงชื่อ)', 0, { unit: 'px', nowrap: true, label: true }),
        cell('', 180, { unit: 'px', dotted: true, field: `sig${index}_line` }),
        cell(index === 1 ? 'ผู้บันทึก' : '', 0, { unit: 'px', nowrap: true, label: true }),
      ] },
      paragraph(`( ${name} )`, { role: 'signatureName', field: `sig${index}_name`, offsetX: 0, offsetY: 0 }),
      paragraph(position, { role: 'signaturePosition', field: `sig${index}_pos` }),
    ],
  })
  return fixture({ content: { type: 'doc', content: [
    paragraph('บันทึกหลังการจัดการเรียนรู้', { role: 'recordTitle', field: 'record_header_title' }),
    info([cell('รายวิชา', 10, { label: true, nowrap: true }), cell('ภาษาไทยเพื่อการสื่อสาร', 50, { dotted: true, field: 'course_name' }), cell('รหัสวิชา', 12, { label: true, nowrap: true }), cell('พท11001', 28, { dotted: true, field: 'course_code' })]),
    info([cell('กลุ่มเรียน', 12, { label: true }), cell('กลุ่มตำบลเสนา', 38, { dotted: true, field: 'group_name' }), cell('ระดับ', 10, { label: true }), cell('ประถมศึกษา', 40, { dotted: true, field: 'level_name' })]),
    info([cell('สัปดาห์ที่', 13, { label: true }), cell('4', 12, { dotted: true, field: 'week_no' }), cell('วันที่', 10, { label: true }), cell('23 ตุลาคม 2569', 30, { dotted: true, field: 'teach_date' }), cell('ภาคเรียน', 15, { label: true }), cell('1/2569', 20, { dotted: true, field: 'semester' })]),
    info([cell('ตำบล', 10, { label: true }), cell('เสนา', 40, { dotted: true, field: 'tambon' }), cell('ผู้เรียน', 10, { label: true }), cell('3 คน มาเรียน 2 คน', 40, { dotted: true, field: 'attendance' })]),
    section('๑. ผลการจัดการเรียนการสอน', 'record_section1_title'),
    body('ผู้เรียนสามารถอ่านและเขียนภาษาไทยได้ถูกต้อง', 'learning_result', 3),
    section('๒. สภาพปัญหาและอุปสรรค', 'record_section2_title'),
    body('ผู้เรียนบางส่วนยังอ่านคำควบกล้ำไม่คล่อง', 'problem', 2),
    section('๓. ข้อเสนอแนะ / แนวทางแก้ไขปัญหา', 'record_section3_title'),
    body('เพิ่มการฝึกอ่านออกเสียงเป็นรายบุคคล', 'recommendation', 2),
    signature(1, 'นายครูทดสอบ ใจดี', 'ครูผู้สอน'),
    { type: 'recordGroup', attrs: { spaceBefore: 16, offsetX: 0, offsetY: 0 }, content: [
      section('ความคิดเห็นของหัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ', 'supervisor_title'),
      body('จัดกิจกรรมได้เหมาะสมกับผู้เรียน', 'supervisor_comment', 2),
      signature(2, 'นางหัวหน้างาน เรียนรู้', 'หัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ'),
    ] },
    { type: 'recordGroup', attrs: { spaceBefore: 16, borderTop: true }, content: [
      section('ข้อคิดเห็นของผู้อำนวยการสถานศึกษา', 'director_title'),
      body('เห็นชอบแนวทางการจัดการเรียนรู้', 'director_comment', 2),
      signature(3, 'นายผู้อำนวยการ พัฒนา', 'ผู้อำนวยการสถานศึกษา'),
    ] },
  ] } })
}

function paragraphWith(xml, text) {
  const found = (xml.match(/<w:p(?:\s[^>]*)?>[\s\S]*?<\/w:p>/g) || []).find(paragraph => paragraph.includes(text))
  assert.ok(found, `Expected an editable Word paragraph containing ${text}`)
  return found
}

test('the complete record template exports native header fields, ruled bodies and all three signature blocks', async () => {
  const model = recordTemplateFixture()
  const { xml, zip, warnings } = await archive(model)
  const styles = await zip.file('word/styles.xml').async('string')
  assert.match(styles, /w:ascii="THSarabunNew"/)
  assert.match(styles, /w:sz w:val="32"/)
  const title = paragraphWith(xml, 'บันทึกหลังการจัดการเรียนรู้')
  assert.match(title, /w:jc w:val="center"/)
  assert.match(title, /w:sz w:val="40"/)
  assert.match(title, /<w:b\/>/)
  assert.match(title, /w:after="120"/)
  const heading = paragraphWith(xml, '๑. ผลการจัดการเรียนการสอน')
  assert.match(heading, /w:sz w:val="34"/)
  assert.match(heading, /<w:keepNext\/>/)
  assert.match(heading, /w:before="120"/)
  const body = paragraphWith(xml, 'ผู้เรียนสามารถอ่านและเขียนภาษาไทยได้ถูกต้อง')
  assert.match(body, /w:jc w:val="both"/)
  assert.match(body, /w:firstLine="630"/)
  assert.match(body, /w:line="390" w:lineRule="atLeast"/)
  assert.match(body, /w:after="0"/)
  assert.match(body, /w:bottom w:val="dotted"/)
  assert.match(xml, /w:trHeight w:val="1170" w:hRule="atLeast"/)
  for (const value of ['ภาษาไทยเพื่อการสื่อสาร', 'พท11001', 'กลุ่มตำบลเสนา', 'ประถมศึกษา', '23 ตุลาคม 2569', '1/2569', '3 คน มาเรียน 2 คน']) assert.ok(xml.includes(value))
  const tableBorders = xml.match(/<w:tblBorders>[\s\S]*?<\/w:tblBorders>/g) || []
  assert.ok(tableBorders.length >= 15)
  assert.ok(tableBorders.every(borders => !borders.includes('w:val="single"') && !borders.includes('w:val="dotted"')), 'the native layout tables must have no visible grid')
  assert.equal((xml.match(/w:tblW w:type="dxa" w:w="6450"/g) || []).length, 3, 'each signature is an editable 430 px block')
  assert.equal((xml.match(/w:tblLayout w:type="autofit"/g) || []).length, 3, 'signature line labels stay in compact nested tables')
  assert.equal((xml.match(/w:tcW w:type="dxa" w:w="2700"/g) || []).length, 3, 'the signature dot widths remain 180 px')
  for (const name of ['นายครูทดสอบ ใจดี', 'นางหัวหน้างาน เรียนรู้', 'นายผู้อำนวยการ พัฒนา']) {
    const paragraph = paragraphWith(xml, name)
    assert.match(paragraph, /w:jc w:val="center"/)
    assert.equal((xml.match(new RegExp(name, 'g')) || []).length, 1, 'signature names must not be duplicated or omitted')
  }
  assert.equal((xml.match(/\(ลงชื่อ\)/g) || []).length, 3)
  assert.match(xml, /w:top w:val="single" w:color="000000" w:sz="4"/)
  assert.ok(warnings.some(warning => warning.includes('เส้นบรรทัดซ้ำ')))
  assert.ok(!warnings.some(warning => warning.includes('รูปแบบ record')), 'every canonical template node has a native mapping')
  assert.deepEqual(model, recordTemplateFixture(), 'export must not mutate the canonical document JSON')
})

test('record paragraph overrides and shifted signature parts keep all text and disclose positioning limits', async () => {
  const model = recordTemplateFixture()
  const body = model.content.content.find(node => node.type === 'recordBody')
  body.content[0].attrs = { ...body.content[0].attrs, firstIndent: 20, lineHeight: 2, spaceAfter: 4, textAlign: 'left' }
  body.content[0].content[0].marks = [{ type: 'textStyle', attrs: { fontSize: '18pt', color: '#123abc' } }]
  const signature = model.content.content.find(node => node.type === 'recordSignature')
  signature.attrs.offsetX = 12
  signature.content[1].attrs.offsetY = -4
  const { xml, warnings } = await archive(model)
  const bodyParagraph = paragraphWith(xml, 'ผู้เรียนสามารถอ่านและเขียนภาษาไทยได้ถูกต้อง')
  assert.match(bodyParagraph, /w:firstLine="300"/)
  assert.match(bodyParagraph, /w:line="480" w:lineRule="auto"/)
  assert.match(bodyParagraph, /w:after="80"/)
  assert.match(bodyParagraph, /w:jc w:val="left"/)
  assert.match(bodyParagraph, /w:sz w:val="36"/)
  assert.match(bodyParagraph, /w:color w:val="123ABC"/)
  assert.ok(xml.includes('นายครูทดสอบ ใจดี'))
  assert.equal(warnings.filter(warning => warning.includes('ตำแหน่งเลื่อนเฉพาะส่วน')).length, 1)
})

test('page breaks inside semantic record groups retain subsequent page-based image anchors', async () => {
  const model = fixture({
    content: { type: 'doc', content: [{ type: 'recordGroup', content: [paragraph('หน้าแรก'), { type: 'pageBreak' }, paragraph('ภาพกิจกรรมหน้าถัดไป')] }] },
    objects: [object('image', { page: 2, anchor: { kind: 'page', page: 2 }, src: '/assets/photo.png' })],
  })
  const { xml, warnings } = await archive(model, options)
  assert.match(xml, /w:br w:type="page"/)
  assert.match(xml, /<wp:anchor /)
  assert.ok(!warnings.some(warning => warning.includes('ท้ายเอกสาร')))
})

test('a record template pasted into a floating text box retains header values, bodies and all signatures', async () => {
  const model = fixture({ objects: [object('textbox', { content: recordTemplateFixture().content })] })
  const { xml, warnings } = await archive(model)
  for (const value of ['ภาษาไทยเพื่อการสื่อสาร', 'พท11001', 'ผู้เรียนสามารถอ่านและเขียนภาษาไทยได้ถูกต้อง', 'นายครูทดสอบ ใจดี', 'นางหัวหน้างาน เรียนรู้', 'นายผู้อำนวยการ พัฒนา']) {
    assert.ok(xml.includes(value), `Text boxes must retain ${value}`)
    assert.equal((xml.match(new RegExp(value, 'g')) || []).length, 2, 'text is present once in editable text and once in its accessibility description')
  }
  assert.ok(warnings.some(warning => warning.includes('แม่แบบภายในกล่องข้อความ')))
})

test('manual page breaks within a record body remain outside native Word tables', async () => {
  const model = fixture({ content: { type: 'doc', content: [
    { type: 'recordBody', attrs: { lineSpacing: 26, minLines: 2 }, content: [paragraph('เนื้อหาก่อนขึ้นหน้า'), { type: 'pageBreak' }, paragraph('เนื้อหาหลังขึ้นหน้า')] },
  ] } })
  const { xml } = await archive(model)
  assert.ok(xml.indexOf('เนื้อหาก่อนขึ้นหน้า') < xml.indexOf('w:br w:type="page"'))
  assert.ok(xml.indexOf('เนื้อหาหลังขึ้นหน้า') > xml.indexOf('w:br w:type="page"'))
  const tables = xml.match(/<w:tbl>[\s\S]*?<\/w:tbl>/g) || []
  assert.equal(tables.length, 2)
  assert.ok(tables.every(table => !table.includes('w:br w:type="page"')), 'Word may ignore page breaks nested in a cell')
  assert.match(tables[0], /w:trHeight w:val="780"/)
  assert.match(tables[1], /w:trHeight w:val="390"/)
})
