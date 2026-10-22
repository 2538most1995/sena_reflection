function senaReadDocumentText(item) {
    function read(node) {
        if (node.nodeType === 3) return node.textContent;
        if (node.nodeType !== 1) return '';
        if (node.classList.contains('manual-page-break')) return '[break]';
        if (node.tagName === 'BR') {
            var placeholder = node.parentElement && /^(P|DIV|LI)$/.test(node.parentElement.tagName) && node.parentElement.childNodes.length === 1;
            var trailingBreak = !node.nextSibling && node.previousSibling && node.previousSibling.nodeName === 'BR';
            return placeholder || trailingBreak ? '' : '\n';
        }
        var text = Array.from(node.childNodes).map(read).join('');
        if (/^(P|DIV|LI|UL|OL)$/.test(node.tagName) && node.nextSibling) text += '\n';
        return text;
    }
    return Array.from(item.childNodes).map(read).join('').replace(/\u00a0/g, ' ').replace(/\r\n?|[\u2028\u2029]/g, '\n');
}

if (typeof module !== 'undefined' && module.exports) module.exports = { senaReadDocumentText };

if (typeof document !== 'undefined') (function () {
    'use strict';
    var activeText = null;
    var savedRange = null;
    var composing = false;
    var lastHtml = new WeakMap();
    var labels = {
        record_subject: 'รายวิชา', record_subject_code: 'รหัสวิชา', record_class_group_code: 'รหัสกลุ่ม', record_class_name: 'ชื่อกลุ่มเรียน',
        record_result: 'ผลการจัดการเรียนรู้', record_problem: 'ปัญหาและอุปสรรค', record_suggestion: 'ข้อเสนอแนะ', record_supervisor_comment: 'ความคิดเห็นหัวหน้างาน'
    };
    function send(data) { window.parent.postMessage(data, '*'); }
    function notifyText(item) {
        if (composing) return;
        if (lastHtml.get(item) === item.innerHTML) return;
        lastHtml.set(item, item.innerHTML);
        var key = item.dataset.preprintSetting;
        var text = senaReadDocumentText(item);
        var clone = item.cloneNode(true);
        clone.querySelectorAll('.manual-page-break').forEach(function (marker) { marker.replaceWith(document.createTextNode('[break]')); });
        item.dataset.preprintText = text;
        send({ source: 'sena-pdf-layout', textChanges: { [key]: text }, richTextChanges: { [key]: { text: text, html: clone.innerHTML } }, target: { kind: 'text', key: key, label: labels[key] || 'ข้อความลายเซ็น' } });
    }
    function rememberSelection() {
        var selection = window.getSelection();
        if (!selection || !selection.rangeCount) return;
        var range = selection.getRangeAt(0);
        var start = range.startContainer.nodeType === 1 ? range.startContainer : range.startContainer.parentElement;
        var item = start.closest('[data-preprint-setting]');
        if (!item || !item.contains(range.endContainer)) return;
        activeText = item;
        savedRange = range.cloneRange();
        var element = selection.anchorNode.nodeType === 1 ? selection.anchorNode : selection.anchorNode.parentElement;
        var style = window.getComputedStyle(element);
        send({ source: 'sena-document-selection', key: item.dataset.preprintSetting, label: labels[item.dataset.preprintSetting] || 'ข้อความลายเซ็น',
            bold: document.queryCommandState('bold'), italic: document.queryCommandState('italic'), underline: document.queryCommandState('underline'),
            strikeThrough: document.queryCommandState('strikeThrough'), unorderedList: document.queryCommandState('insertUnorderedList'), orderedList: document.queryCommandState('insertOrderedList'),
            color: style.color, backgroundColor: style.backgroundColor,
            fontSize: Math.round(parseFloat(style.fontSize) * 0.75), fontFamily: style.fontFamily.split(',')[0].replace(/["']/g, ''), align: style.textAlign });
    }
    document.querySelectorAll('[data-preprint-setting]').forEach(function (item) {
        lastHtml.set(item, item.innerHTML);
        item.contentEditable = 'true';
        item.setAttribute('role', 'textbox');
        item.setAttribute('aria-label', labels[item.dataset.preprintSetting] || 'ข้อความลายเซ็น');
        item.setAttribute('aria-multiline', item.dataset.preprintAllowBr ? 'true' : 'false');
        item.setAttribute('spellcheck', 'false');
        item.setAttribute('title', 'เลือกข้อความแล้วใช้แถบเครื่องมือจัดรูปแบบ · Ctrl/Cmd+B ตัวหนา');
        item.addEventListener('click', function (event) { event.stopPropagation(); rememberSelection(); });
        item.addEventListener('focus', function () {
            activeText = item;
            item.dataset.originalHtml = item.innerHTML;
            item.classList.add('is-editing');
        });
        item.addEventListener('compositionstart', function () { composing = true; });
        item.addEventListener('compositionend', function () { composing = false; notifyText(item); rememberSelection(); });
        item.addEventListener('input', function () { notifyText(item); rememberSelection(); });
        item.addEventListener('blur', function () { notifyText(item); item.classList.remove('is-editing'); });
        item.addEventListener('paste', function (event) {
            if (!event.clipboardData) return;
            event.preventDefault();
            var text = event.clipboardData.getData('text/plain').replace(/\r\n?/g, '\n');
            if (!item.dataset.preprintAllowBr) text = text.replace(/\n/g, ' ');
            document.execCommand('insertText', false, text);
            notifyText(item);
        });
        item.addEventListener('drop', function (event) { event.preventDefault(); });
        item.addEventListener('keydown', function (event) {
            if (event.isComposing) return;
            event.stopPropagation();
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') { event.preventDefault(); send({ source: 'sena-document-save' }); return; }
            if (event.key === 'Escape') { event.preventDefault(); item.innerHTML = item.dataset.originalHtml || ''; notifyText(item); item.blur(); return; }
            if (event.key === 'Enter' && (!item.dataset.preprintAllowBr || event.metaKey || event.ctrlKey)) { event.preventDefault(); item.blur(); return; }
            if (event.key === 'Tab') { event.preventDefault(); document.execCommand('insertText', false, '\t'); }
        });
    });
    document.addEventListener('selectionchange', rememberSelection);
    window.addEventListener('message', function (event) {
        if (event.source !== window.parent || event.data?.source !== 'sena-document-format' || !activeText || composing) return;
        var command = event.data.command;
        var value = String(event.data.value || '');
        var allowed = ['bold', 'italic', 'underline', 'strikeThrough', 'foreColor', 'hiliteColor', 'fontName', 'fontSize', 'justifyLeft', 'justifyCenter', 'justifyRight', 'justifyFull', 'insertUnorderedList', 'insertOrderedList', 'removeFormat'];
        if (!allowed.includes(command)) return;
        activeText.focus({ preventScroll: true });
        var selection = window.getSelection();
        if (savedRange && activeText.contains(savedRange.commonAncestorContainer)) { selection.removeAllRanges(); selection.addRange(savedRange); }
        if (command === 'fontSize') {
            var size = Number(value);
            if (!Number.isFinite(size) || size < 10 || size > 32 || selection.isCollapsed) return;
            document.execCommand('styleWithCSS', false, 'false');
            document.execCommand('fontSize', false, '7');
            activeText.querySelectorAll('font[size="7"]').forEach(function (font) { font.removeAttribute('size'); font.style.fontSize = size + 'pt'; });
        } else {
            document.execCommand('styleWithCSS', false, 'true');
            document.execCommand(command, false, value);
        }
        notifyText(activeText);
        rememberSelection();
    });
}());
