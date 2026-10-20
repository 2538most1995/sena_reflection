<?php
require_once __DIR__ . '/../config.php';

$user = checkAuth();

const DOCX_PAGE_WIDTH_TWIP = 11906;
const DOCX_PAGE_HEIGHT_TWIP = 16838;
const DOCX_PX_TO_TWIP = 15;
const DOCX_PX_TO_EMU = 9525;

function exportDocError($message, $statusCode = 500)
{
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

function docxXml($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function docxTwipFromPx($px)
{
    return (int) round(((float) $px) * DOCX_PX_TO_TWIP);
}

function docxEmuFromPx($px)
{
    return (int) round(((float) $px) * DOCX_PX_TO_EMU);
}

function docxSettingInt(array $settings, $key, $default, $min, $max)
{
    $value = isset($settings[$key]) && $settings[$key] !== ''
        ? (int) round((float) $settings[$key])
        : (int) $default;
    return max((int) $min, min((int) $max, $value));
}

function docxSanitizeFilename($filename, $extension = 'docx')
{
    $filename = trim(html_entity_decode(strip_tags((string) $filename), ENT_QUOTES, 'UTF-8'));
    $filename = preg_replace('/[\\\\\/:*?"<>|]+/u', '_', $filename);
    $filename = preg_replace('/\s+/u', ' ', $filename);
    $filename = trim($filename, " \t\n\r\0\x0B._");
    if ($filename === '') {
        $filename = 'บันทึกหลังสอน';
    }
    $filename = preg_replace('/\.[^.]+$/u', '', $filename);
    return $filename . '.' . ltrim((string) $extension, '.');
}

function docxTableColumnExists(PDO $pdo, $table, $column)
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        return (bool) $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function docxNormalizeSignatureLabel($label)
{
    $label = trim((string) $label);
    if ($label === '' || $label === 'ลงชื่อ') {
        return '(ลงชื่อ)';
    }
    return $label;
}

function docxSignatureAlign($align, $default = 'right')
{
    $align = trim((string) $align);
    if ($align === 'flex-start' || $align === 'left') {
        return 'left';
    }
    if ($align === 'center') {
        return 'center';
    }
    if ($align === 'flex-end' || $align === 'right') {
        return 'right';
    }
    return $default;
}

function docxLoadSettings(PDO $pdo, array $record, array $user)
{
    $settings = [];
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Throwable $e) {
        return [];
    }

    $settings = applyTambonScopedOverrides($settings, $record['tambon'] ?? '', getSig1SettingKeys());
    if (($user['role'] ?? '') === 'tambon') {
        $settings = applyUserScopedOverrides($settings, $user['id'] ?? '', getSig1SettingKeys());
    }
    return $settings;
}

function docxLoadRecord(PDO $pdo, array $user, $recordId)
{
    $selects = ['r.*'];
    $joins = [];

    if (docxTableColumnExists($pdo, 'records', 'tambon') && docxTableColumnExists($pdo, 'tambons', 'id') && docxTableColumnExists($pdo, 'tambons', 'name')) {
        $selects[] = 't.name as tambon_name';
        $joins[] = 'LEFT JOIN tambons t ON r.tambon COLLATE utf8mb4_unicode_ci = t.id COLLATE utf8mb4_unicode_ci';
    } else {
        $selects[] = "'' as tambon_name";
    }

    if (docxTableColumnExists($pdo, 'records', 'created_by') && docxTableColumnExists($pdo, 'users', 'id') && docxTableColumnExists($pdo, 'users', 'name')) {
        $selects[] = 'u.name as teacher_name';
        $joins[] = 'LEFT JOIN users u ON r.created_by COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci';
    } else {
        $selects[] = "'' as teacher_name";
    }

    $stmt = $pdo->prepare("
        SELECT " . implode(', ', $selects) . "
        FROM records r
        " . implode("\n        ", $joins) . "
        WHERE r.id = ?
        LIMIT 1
    ");
    $stmt->execute([$recordId]);
    $record = $stmt->fetch();
    if (!$record) {
        exportDocError('ไม่พบรายการบันทึก', 404);
    }
    if (($user['role'] ?? '') !== 'district' && ($record['tambon'] ?? '') !== ($user['tambon'] ?? '')) {
        exportDocError('ไม่พบรายการที่มีสิทธิ์ส่งออก', 403);
    }

    $images = [];
    if (docxTableColumnExists($pdo, 'record_images', 'file_path') && docxTableColumnExists($pdo, 'record_images', 'record_id')) {
        $imgStmt = $pdo->prepare("SELECT file_path FROM record_images WHERE record_id = ? ORDER BY id ASC LIMIT 4");
        $imgStmt->execute([$recordId]);
        $images = $imgStmt->fetchAll();
    }

    $attendanceRows = [];
    if (
        docxTableColumnExists($pdo, 'record_attendance', 'record_id') &&
        docxTableColumnExists($pdo, 'record_attendance', 'student_id') &&
        docxTableColumnExists($pdo, 'record_attendance', 'full_name') &&
        docxTableColumnExists($pdo, 'record_attendance', 'present')
    ) {
        $attendanceSelects = ['student_id', 'full_name', 'present'];
        $attendanceSelects[] = docxTableColumnExists($pdo, 'record_attendance', 'learning_method')
            ? 'learning_method'
            : "'' AS learning_method";
        $attendanceStmt = $pdo->prepare("
            SELECT " . implode(', ', $attendanceSelects) . "
            FROM record_attendance
            WHERE record_id COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
              AND present = 1
            ORDER BY id ASC
        ");
        $attendanceStmt->execute([$recordId]);
        $attendanceRows = $attendanceStmt->fetchAll();
    }

    return [$record, $images, $attendanceRows];
}

function docxRunProperties(array $options = [])
{
    $props = [];
    $font = $options['font'] ?? 'TH Sarabun New';
    $sizePt = (float) ($options['size'] ?? 16);
    $sizeHalf = (int) round($sizePt * 2);
    $props[] = '<w:rFonts w:ascii="' . docxXml($font) . '" w:hAnsi="' . docxXml($font) . '" w:cs="' . docxXml($font) . '"/>';
    $props[] = '<w:sz w:val="' . $sizeHalf . '"/><w:szCs w:val="' . $sizeHalf . '"/>';
    if (!empty($options['bold'])) {
        $props[] = '<w:b/><w:bCs/>';
    }
    if (!empty($options['underline'])) {
        $props[] = '<w:u w:val="' . docxXml($options['underline']) . '"/>';
    }
    return '<w:rPr>' . implode('', $props) . '</w:rPr>';
}

function docxRun($text, array $options = [])
{
    $xml = '<w:r>' . docxRunProperties($options);
    $parts = preg_split("/\r\n|\r|\n/u", (string) $text);
    foreach ($parts as $index => $part) {
        if ($index > 0) {
            $xml .= '<w:br/>';
        }
        $xml .= '<w:t xml:space="preserve">' . docxXml($part) . '</w:t>';
    }
    return $xml . '</w:r>';
}

function docxTabRun()
{
    return '<w:r><w:tab/></w:r>';
}

function docxPageBreak()
{
    return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
}

function docxParagraph($runs, array $options = [])
{
    $pPr = [];
    if (!empty($options['align'])) {
        $pPr[] = '<w:jc w:val="' . docxXml($options['align']) . '"/>';
    }
    $before = (int) ($options['before'] ?? 0);
    $after = (int) ($options['after'] ?? 0);
    $line = (int) ($options['line'] ?? 312);
    $pPr[] = '<w:spacing w:before="' . $before . '" w:after="' . $after . '" w:line="' . $line . '" w:lineRule="exact"/>';
    if (!empty($options['indent'])) {
        $pPr[] = '<w:ind ' . $options['indent'] . '/>';
    }
    if (!empty($options['tabs']) && is_array($options['tabs'])) {
        $tabs = '';
        foreach ($options['tabs'] as $tab) {
            $leader = !empty($tab['leader']) ? ' w:leader="' . docxXml($tab['leader']) . '"' : '';
            $tabs .= '<w:tab w:val="' . docxXml($tab['val'] ?? 'left') . '" w:pos="' . (int) $tab['pos'] . '"' . $leader . '/>';
        }
        $pPr[] = '<w:tabs>' . $tabs . '</w:tabs>';
    }
    $borders = [];
    if (!empty($options['borderTop'])) {
        $borderType = is_string($options['borderTop']) ? $options['borderTop'] : 'single';
        $borders[] = '<w:top w:val="' . docxXml($borderType) . '" w:sz="8" w:space="1" w:color="000000"/>';
    }
    if (!empty($options['borderBottom'])) {
        $borderType = is_string($options['borderBottom']) ? $options['borderBottom'] : 'dotted';
        $borders[] = '<w:bottom w:val="' . docxXml($borderType) . '" w:sz="4" w:space="1" w:color="000000"/>';
    }
    if ($borders) {
        $pPr[] = '<w:pBdr>' . implode('', $borders) . '</w:pBdr>';
    }

    return '<w:p><w:pPr>' . implode('', $pPr) . '</w:pPr>' . (is_array($runs) ? implode('', $runs) : $runs) . '</w:p>';
}

function docxFieldLine(array $items, array $tabs, $fontPt, $lineTwip)
{
    $runs = [];
    foreach ($items as $index => $item) {
        if ($index > 0) {
            $runs[] = docxTabRun();
        }
        $runs[] = docxRun($item['label'] ?? '', ['size' => $fontPt]);
        if (array_key_exists('value', $item)) {
            $runs[] = docxTabRun();
            $runs[] = docxRun(' ' . (string) $item['value'] . ' ', [
                'size' => $fontPt,
                'underline' => 'dotted',
            ]);
        }
    }
    return docxParagraph($runs, [
        'tabs' => $tabs,
        'line' => $lineTwip,
        'after' => 0,
    ]);
}

function docxDottedTextBlock($text, $minLines, $fontPt, $lineTwip, $firstIndentTwip = 720)
{
    $text = trim((string) $text);
    $lines = preg_split("/\r\n|\r|\n/u", $text);
    $lines = array_values(array_filter($lines, static function ($line) {
        return trim((string) $line) !== '';
    }));
    if (!$lines) {
        $lines = [''];
    }
    while (count($lines) < $minLines) {
        $lines[] = '';
    }

    $xml = '';
    foreach ($lines as $index => $line) {
        $indent = $index === 0 ? 'w:firstLine="' . (int) $firstIndentTwip . '"' : '';
        $xml .= docxParagraph([docxRun($line, ['size' => $fontPt])], [
            'line' => $lineTwip,
            'after' => 0,
            'indent' => $indent,
            'borderBottom' => true,
        ]);
    }
    return $xml;
}

function docxImageRun($rid, $widthPx, $heightPx, $docPrId)
{
    $cx = docxEmuFromPx($widthPx);
    $cy = docxEmuFromPx($heightPx);
    return '<w:r><w:drawing>
        <wp:inline distT="0" distB="0" distL="0" distR="0">
          <wp:extent cx="' . $cx . '" cy="' . $cy . '"/>
          <wp:effectExtent l="0" t="0" r="0" b="0"/>
          <wp:docPr id="' . (int) $docPrId . '" name="Picture ' . (int) $docPrId . '"/>
          <wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="0"/></wp:cNvGraphicFramePr>
          <a:graphic>
            <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
              <pic:pic>
                <pic:nvPicPr><pic:cNvPr id="' . (int) $docPrId . '" name="image"/><pic:cNvPicPr/></pic:nvPicPr>
                <pic:blipFill><a:blip r:embed="' . docxXml($rid) . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>
                <pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>
              </pic:pic>
            </a:graphicData>
          </a:graphic>
        </wp:inline>
      </w:drawing></w:r>';
}

function docxResolveLocalImage($path)
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path) || strpos($path, 'data:') === 0) {
        return '';
    }
    $path = ltrim($path, '/');
    $candidate = realpath(__DIR__ . '/../' . $path);
    $root = realpath(__DIR__ . '/..');
    if (!$candidate || !$root || strpos($candidate, $root) !== 0 || !is_file($candidate)) {
        return '';
    }
    return $candidate;
}

function docxMimeExtension($path)
{
    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string) finfo_file($finfo, $path);
            finfo_close($finfo);
        }
    }
    if ($mime === '') {
        $mime = (string) @mime_content_type($path);
    }
    if ($mime === 'image/png') return ['png', 'image/png'];
    if ($mime === 'image/gif') return ['gif', 'image/gif'];
    return ['jpg', 'image/jpeg'];
}

function docxRelationshipXml(array $imageRels)
{
    $rels = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($imageRels as $rel) {
        $rels .= '<Relationship Id="' . docxXml($rel['rid']) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . docxXml($rel['name']) . '"/>';
    }
    return $rels . '</Relationships>';
}

function docxContentTypesXml(array $imageRels)
{
    $defaults = [
        'rels' => 'application/vnd.openxmlformats-package.relationships+xml',
        'xml' => 'application/xml',
    ];
    foreach ($imageRels as $rel) {
        $defaults[$rel['ext']] = $rel['mime'];
    }
    $xml = '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
    foreach ($defaults as $ext => $mime) {
        $xml .= '<Default Extension="' . docxXml($ext) . '" ContentType="' . docxXml($mime) . '"/>';
    }
    $xml .= '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>';
    $xml .= '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>';
    $xml .= '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>';
    return $xml . '</Types>';
}

function docxStylesXml()
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:docDefaults>
    <w:rPrDefault><w:rPr><w:rFonts w:ascii="TH Sarabun New" w:hAnsi="TH Sarabun New" w:cs="TH Sarabun New"/><w:sz w:val="32"/><w:szCs w:val="32"/></w:rPr></w:rPrDefault>
    <w:pPrDefault><w:pPr><w:spacing w:before="0" w:after="0" w:line="312" w:lineRule="exact"/></w:pPr></w:pPrDefault>
  </w:docDefaults>
</w:styles>';
}

function docxSettingsXml()
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:zoom w:percent="100"/>
  <w:defaultTabStop w:val="720"/>
</w:settings>';
}

function docxPackageRelsXml()
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>';
}

function docxBuildDocument(array $record, array $images, array $attendanceRows, array $settings, array &$imageRels)
{
    $timestamp = strtotime($record['date'] ?? '') ?: time();
    $thaiMonths = [
        1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
        5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
        9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม',
    ];
    $day = date('j', $timestamp);
    $month = $thaiMonths[(int) date('n', $timestamp)];
    $year = (int) date('Y', $timestamp) + 543;
    $absent = max(0, (int) ($record['total'] ?? 0) - (int) ($record['present'] ?? 0));

    $fontPt = docxSettingInt($settings, 'pdf_font_size_pt', 16, 10, 20);
    $linePx = docxSettingInt($settings, 'pdf_line_spacing', 26, 20, 40);
    $lineTwip = docxTwipFromPx($linePx);
    $marginTop = docxTwipFromPx(docxSettingInt($settings, 'pdf_margin_top_px', 57, 20, 140));
    $marginRight = docxTwipFromPx(docxSettingInt($settings, 'pdf_margin_right_px', 76, 20, 160));
    $marginBottom = docxTwipFromPx(docxSettingInt($settings, 'pdf_margin_bottom_px', 38, 20, 140));
    $marginLeft = docxTwipFromPx(docxSettingInt($settings, 'pdf_margin_left_px', 94, 20, 160));

    $contentWidth = DOCX_PAGE_WIDTH_TWIP - $marginLeft - $marginRight;

    $recordSection1Title = $settings['record_section1_title'] ?? '๑. ผลการจัดการเรียนการสอน';
    $recordSection2Title = $settings['record_section2_title'] ?? '๒. สภาพปัญหาและอุปสรรค';
    $recordSection3Title = $settings['record_section3_title'] ?? '๓. ข้อเสนอแนะ / แนวทางแก้ไขปัญหา';
    $sig1Name = trim((string) ($settings['sig1_name'] ?? '')) ?: ($record['teacher_name'] ?? '');
    $sig1Pos = $settings['sig1_pos'] ?? 'ครูผู้สอน';
    $sig1Prefix = docxNormalizeSignatureLabel($settings['sig1_sign_prefix'] ?? 'ลงชื่อ');
    $sig1Suffix = $settings['sig1_sign_suffix'] ?? 'ผู้บันทึก';
    $sig1Align = docxSignatureAlign($settings['sig1_align'] ?? 'flex-end', 'right');
    $sig2Title = $settings['sig2_title'] ?? 'ความคิดเห็นของหัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ';
    $sig2Label = $settings['sig2_sign_label'] ?? '(ลงชื่อ)';
    $sig2Name = $settings['sig2_name'] ?? '';
    $sig2Pos = preg_replace('/<br\s*\/?>/i', "\n", (string) ($settings['sig2_pos'] ?? 'หัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ'));
    $sig2Align = docxSignatureAlign($settings['sig2_align'] ?? 'flex-end', 'right');
    $sig3Title = $settings['sig3_title'] ?? 'ข้อคิดเห็นของผู้อำนวยการสถานศึกษา';
    $sig3Label = $settings['sig3_sign_label'] ?? '(ลงชื่อ)';
    $sig3Name = $settings['sig3_name'] ?? 'นางมุจรินทร์ ชมวิชา';
    $sig3Pos = preg_replace('/<br\s*\/?>/i', "\n", (string) ($settings['sig3_pos'] ?? 'ครู รักษาการในตำแหน่ง' . "\n" . 'ผู้อำนวยการศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอเสนา'));
    $sig3Align = docxSignatureAlign($settings['sig3_align'] ?? 'center', 'center');

    $body = '';
    $body .= docxParagraph([docxRun('บันทึกหลังการสอนการเรียนรู้รายสัปดาห์', ['size' => $fontPt + 4, 'bold' => true])], [
        'align' => 'center',
        'line' => docxTwipFromPx(34),
        'after' => 140,
    ]);

    $body .= docxFieldLine([
        ['label' => 'ศูนย์การเรียนรู้', 'value' => 'ตำบล' . ($record['tambon_name'] ?? '')],
        ['label' => 'ศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอ', 'value' => 'เสนา'],
    ], [
        ['pos' => (int) round($contentWidth * 0.18)],
        ['pos' => (int) round($contentWidth * 0.42)],
        ['pos' => (int) round($contentWidth * 0.78)],
    ], $fontPt, $lineTwip);

    $body .= docxFieldLine([
        ['label' => 'สัปดาห์ที่', 'value' => $record['week'] ?? ''],
        ['label' => 'วันที่', 'value' => $day],
        ['label' => 'เดือน', 'value' => $month],
        ['label' => 'พ.ศ.', 'value' => $year],
    ], [
        ['pos' => (int) round($contentWidth * 0.12)],
        ['pos' => (int) round($contentWidth * 0.23)],
        ['pos' => (int) round($contentWidth * 0.34)],
        ['pos' => (int) round($contentWidth * 0.46)],
        ['pos' => (int) round($contentWidth * 0.57)],
        ['pos' => (int) round($contentWidth * 0.76)],
        ['pos' => (int) round($contentWidth * 0.86)],
    ], $fontPt, $lineTwip);

    $body .= docxFieldLine([
        ['label' => 'ระดับ', 'value' => $record['level'] ?? ''],
        ['label' => 'ภาคเรียนที่', 'value' => $record['semester'] ?? ''],
        ['label' => 'ปีการศึกษา', 'value' => $record['year'] ?? ''],
    ], [
        ['pos' => (int) round($contentWidth * 0.12)],
        ['pos' => (int) round($contentWidth * 0.42)],
        ['pos' => (int) round($contentWidth * 0.58)],
        ['pos' => (int) round($contentWidth * 0.66)],
        ['pos' => (int) round($contentWidth * 0.82)],
    ], $fontPt, $lineTwip);

    $groupName = trim((string) ($record['class_name'] ?? '')) ?: (string) ($record['class_group_code'] ?? '');
    if ($groupName !== '') {
        $body .= docxFieldLine([
            ['label' => 'กลุ่มเรียน', 'value' => $groupName],
        ], [
            ['pos' => (int) round($contentWidth * 0.16)],
        ], $fontPt, $lineTwip);
    }

    $body .= docxFieldLine([
        ['label' => 'รายวิชา :', 'value' => $record['subject'] ?? ''],
        ['label' => 'รหัสวิชา :', 'value' => $record['subject_code'] ?? ''],
    ], [
        ['pos' => (int) round($contentWidth * 0.15)],
        ['pos' => (int) round($contentWidth * 0.50)],
        ['pos' => (int) round($contentWidth * 0.65)],
    ], $fontPt, $lineTwip);

    $body .= docxFieldLine([
        ['label' => 'ผู้เรียนทั้งหมด', 'value' => $record['total'] ?? ''],
        ['label' => 'คน เข้าเรียน', 'value' => $record['present'] ?? ''],
        ['label' => 'คน ไม่มาเรียน', 'value' => $absent],
        ['label' => 'คน'],
    ], [
        ['pos' => (int) round($contentWidth * 0.21)],
        ['pos' => (int) round($contentWidth * 0.30)],
        ['pos' => (int) round($contentWidth * 0.46)],
        ['pos' => (int) round($contentWidth * 0.55)],
        ['pos' => (int) round($contentWidth * 0.73)],
        ['pos' => (int) round($contentWidth * 0.82)],
    ], $fontPt, $lineTwip);

    foreach ([
        [$recordSection1Title, $record['result'] ?? ''],
        [$recordSection2Title, $record['problem'] ?? ''],
        [$recordSection3Title, $record['suggestion'] ?? ''],
    ] as $section) {
        $body .= docxParagraph([docxRun($section[0], ['size' => $fontPt + 1, 'bold' => true])], [
            'line' => $lineTwip,
            'before' => 80,
            'after' => 30,
        ]);
        $body .= docxDottedTextBlock($section[1], 1, $fontPt, $lineTwip, 720);
    }

    $body .= docxParagraph([
        docxRun($sig1Prefix . '........................................' . $sig1Suffix, ['size' => $fontPt]),
    ], ['align' => $sig1Align, 'line' => $lineTwip, 'before' => 220]);
    $body .= docxParagraph([docxRun('( ' . $sig1Name . ' )', ['size' => $fontPt])], ['align' => $sig1Align, 'line' => $lineTwip]);
    $body .= docxParagraph([docxRun($sig1Pos, ['size' => $fontPt])], ['align' => $sig1Align, 'line' => $lineTwip]);

    $body .= docxParagraph([docxRun('', ['size' => $fontPt])], [
        'line' => 80,
        'before' => 220,
        'after' => 80,
        'borderTop' => 'single',
    ]);
    $body .= docxParagraph([docxRun($sig2Title, ['size' => $fontPt + 1, 'bold' => true])], [
        'line' => $lineTwip,
        'before' => 0,
        'after' => 30,
    ]);
    $body .= docxDottedTextBlock($record['supervisor_comment'] ?? '', 1, $fontPt, $lineTwip, 720);
    $body .= docxParagraph([docxRun($sig2Label . '........................................', ['size' => $fontPt])], ['align' => $sig2Align, 'line' => $lineTwip, 'before' => 220]);
    $body .= docxParagraph([docxRun('( ' . $sig2Name . ' )', ['size' => $fontPt])], ['align' => $sig2Align, 'line' => $lineTwip]);
    $body .= docxParagraph([docxRun($sig2Pos, ['size' => $fontPt])], ['align' => $sig2Align, 'line' => $lineTwip]);

    $body .= docxParagraph([docxRun($sig3Title, ['size' => $fontPt + 1, 'bold' => true])], [
        'line' => $lineTwip,
        'before' => 160,
        'after' => 30,
    ]);
    $body .= docxDottedTextBlock('', 1, $fontPt, $lineTwip, 720);
    $body .= docxParagraph([docxRun($sig3Label . '........................................', ['size' => $fontPt])], ['align' => $sig3Align, 'line' => $lineTwip, 'before' => 180]);
    $body .= docxParagraph([docxRun('( ' . $sig3Name . ' )', ['size' => $fontPt])], ['align' => $sig3Align, 'line' => $lineTwip]);
    $body .= docxParagraph([docxRun($sig3Pos, ['size' => $fontPt])], ['align' => $sig3Align, 'line' => $lineTwip]);

    $docPrId = 1;
    if (!empty($images)) {
        $body .= docxPageBreak();
        $body .= docxParagraph([docxRun('ภาพประกอบการจัดการเรียนการสอน', ['size' => $fontPt + 4, 'bold' => true])], [
            'align' => 'center',
            'line' => docxTwipFromPx(34),
            'after' => 180,
        ]);

        $imageWidthPx = docxSettingInt($settings, 'image_width_px', 300, 180, 620);
        $imageHeightPx = docxSettingInt($settings, 'image_height_px', 225, 120, 560);
        $imageGapTwip = docxTwipFromPx(docxSettingInt($settings, 'image_gap_px', 16, 0, 72));
        foreach ($images as $imageIndex => $image) {
            $localPath = docxResolveLocalImage($image['file_path'] ?? '');
            if ($localPath === '') {
                continue;
            }
            [$ext, $mime] = docxMimeExtension($localPath);
            $rid = 'rIdImage' . (count($imageRels) + 1);
            $name = 'image' . (count($imageRels) + 1) . '.' . $ext;
            $imageRels[] = [
                'rid' => $rid,
                'name' => $name,
                'path' => $localPath,
                'ext' => $ext,
                'mime' => $mime,
            ];
            $imageNumber = $imageIndex + 1;
            $widthPx = docxSettingInt($settings, 'image_' . $imageNumber . '_width_px', $imageWidthPx, 180, 620);
            $heightPx = docxSettingInt($settings, 'image_' . $imageNumber . '_height_px', $imageHeightPx, 120, 560);
            if (count($images) > 2) {
                $widthPx = min($widthPx, 300);
                $heightPx = min($heightPx, 240);
            }
            $body .= docxParagraph([docxImageRun($rid, $widthPx, $heightPx, $docPrId++)], [
                'align' => 'center',
                'line' => max($lineTwip, docxTwipFromPx($heightPx + 8)),
                'after' => $imageGapTwip,
            ]);
        }
    }

    if (!empty($attendanceRows)) {
        $body .= docxPageBreak();
        $body .= docxParagraph([
            docxRun('บัญชีรายชื่อผู้เรียน', ['size' => $fontPt + 4, 'bold' => true]),
            docxTabRun(),
            docxRun('ครั้งที่ ' . ($record['week'] ?? '') . ' วันที่ ' . $day . ' เดือน ' . $month . ' พ.ศ. ' . $year, ['size' => $fontPt]),
        ], [
            'tabs' => [['pos' => (int) round($contentWidth * 0.42)]],
            'line' => docxTwipFromPx(34),
            'after' => 120,
        ]);
        $body .= docxParagraph([
            docxRun('ลำดับที่', ['size' => $fontPt, 'bold' => true]),
            docxTabRun(),
            docxRun('รหัสนักศึกษา', ['size' => $fontPt, 'bold' => true]),
            docxTabRun(),
            docxRun('ชื่อ - สกุล', ['size' => $fontPt, 'bold' => true]),
            docxTabRun(),
            docxRun('วิธีเรียน', ['size' => $fontPt, 'bold' => true]),
            docxTabRun(),
            docxRun('ลายมือชื่อ', ['size' => $fontPt, 'bold' => true]),
        ], [
            'tabs' => [
                ['pos' => 900], ['pos' => 2600], ['pos' => 6100], ['pos' => 7600],
            ],
            'line' => $lineTwip,
            'borderBottom' => true,
        ]);
        foreach ($attendanceRows as $idx => $student) {
            $body .= docxParagraph([
                docxRun((string) ($idx + 1), ['size' => $fontPt]),
                docxTabRun(),
                docxRun($student['student_id'] ?? '', ['size' => $fontPt]),
                docxTabRun(),
                docxRun($student['full_name'] ?? '', ['size' => $fontPt]),
                docxTabRun(),
                docxRun($student['learning_method'] ?? '', ['size' => $fontPt]),
                docxTabRun(),
                docxRun('................................', ['size' => $fontPt]),
            ], [
                'tabs' => [
                    ['pos' => 900], ['pos' => 2600], ['pos' => 6100], ['pos' => 7600],
                ],
                'line' => $lineTwip,
                'borderBottom' => true,
            ]);
        }
    }

    $sectPr = '<w:sectPr>
      <w:pgSz w:w="' . DOCX_PAGE_WIDTH_TWIP . '" w:h="' . DOCX_PAGE_HEIGHT_TWIP . '"/>
      <w:pgMar w:top="' . $marginTop . '" w:right="' . $marginRight . '" w:bottom="' . $marginBottom . '" w:left="' . $marginLeft . '" w:header="720" w:footer="720" w:gutter="0"/>
      <w:cols w:space="720"/>
      <w:docGrid w:linePitch="360"/>
    </w:sectPr>';

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document
  xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas"
  xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"
  xmlns:o="urn:schemas-microsoft-com:office:office"
  xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
  xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"
  xmlns:v="urn:schemas-microsoft-com:vml"
  xmlns:wp14="http://schemas.microsoft.com/office/word/2010/wordprocessingDrawing"
  xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
  xmlns:w10="urn:schemas-microsoft-com:office:word"
  xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
  xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml"
  xmlns:wpg="http://schemas.microsoft.com/office/word/2010/wordprocessingGroup"
  xmlns:wpi="http://schemas.microsoft.com/office/word/2010/wordprocessingInk"
  xmlns:wne="http://schemas.microsoft.com/office/word/2006/wordml"
  xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"
  xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
  xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"
  mc:Ignorable="w14 wp14">
  <w:body>' . $body . $sectPr . '</w:body>
</w:document>';
}

function docxCreatePackage($documentXml, array $imageRels)
{
    if (!class_exists('ZipArchive')) {
        exportDocError('server นี้ยังไม่เปิดใช้งาน ZipArchive สำหรับสร้าง DOCX', 501);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'sena_docx_');
    if ($tmp === false) {
        exportDocError('สร้างไฟล์ DOCX ชั่วคราวไม่สำเร็จ', 500);
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        exportDocError('เปิดไฟล์ DOCX ไม่สำเร็จ', 500);
    }

    $zip->addFromString('[Content_Types].xml', docxContentTypesXml($imageRels));
    $zip->addFromString('_rels/.rels', docxPackageRelsXml());
    $zip->addFromString('word/document.xml', $documentXml);
    $zip->addFromString('word/styles.xml', docxStylesXml());
    $zip->addFromString('word/settings.xml', docxSettingsXml());
    $zip->addFromString('word/_rels/document.xml.rels', docxRelationshipXml($imageRels));
    foreach ($imageRels as $rel) {
        $zip->addFile($rel['path'], 'word/media/' . $rel['name']);
    }
    $zip->close();
    return $tmp;
}

$idsParam = trim((string) ($_GET['ids'] ?? $_POST['ids'] ?? $_GET['id'] ?? $_POST['id'] ?? ''));
$ids = array_values(array_unique(array_filter(array_map(static function ($id) {
    $id = trim((string) $id);
    return preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) ? $id : '';
}, explode(',', $idsParam)))));

if (!$ids) {
    exportDocError('ไม่พบรายการบันทึกสำหรับส่งออก', 400);
}

$recordId = $ids[0];
[$record, $images, $attendanceRows] = docxLoadRecord($pdo, $user, $recordId);
$settings = docxLoadSettings($pdo, $record, $user);
$imageRels = [];
$documentXml = docxBuildDocument($record, $images, $attendanceRows, $settings, $imageRels);
$docxPath = docxCreatePackage($documentXml, $imageRels);

$filenameParts = ['บันทึกหลังสอน'];
if (!empty($record['week'])) {
    $filenameParts[] = 'สัปดาห์ที่' . $record['week'];
}
if (!empty($record['level'])) {
    $filenameParts[] = $record['level'];
}
if (!empty($record['class_name'])) {
    $filenameParts[] = $record['class_name'];
} elseif (!empty($record['class_group_code'])) {
    $filenameParts[] = 'กลุ่ม' . $record['class_group_code'];
}
$filename = docxSanitizeFilename(implode('_', $filenameParts), 'docx');

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Content-Length: ' . filesize($docxPath));
header('Cache-Control: no-store, no-cache, must-revalidate');
readfile($docxPath);
@unlink($docxPath);
