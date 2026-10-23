import TextAlign from '@tiptap/extension-text-align'

export const documentAlignments = ['left', 'center', 'right', 'justify', 'thai-distributed']

/** Keep the semantic Word alignment in JSON and emit valid, language-aware CSS. */
export const DocumentTextAlign = TextAlign.extend({
  addOptions() {
    return { ...this.parent?.(), types: [], defaultAlignment: null, alignments: documentAlignments }
  },
  addGlobalAttributes() {
    return [{ types: this.options.types, attributes: {
      textAlign: {
        default: this.options.defaultAlignment,
        parseHTML: element => {
          const alignment = element.getAttribute('data-document-alignment') || element.style.textAlign
          return this.options.alignments.includes(alignment) ? alignment : this.options.defaultAlignment
        },
        renderHTML: attributes => {
          const alignment = attributes.textAlign
          if (!this.options.alignments.includes(alignment)) return {}
          return {
            'data-document-alignment': alignment,
            style: alignment === 'thai-distributed'
              ? 'text-align:justify;text-align-last:left;text-justify:inter-character'
              : `text-align:${alignment};text-align-last:auto;text-justify:auto`,
          }
        },
      },
    } }]
  },
  addKeyboardShortcuts() {
    return { ...this.parent?.(), 'Mod-Shift-j': () => this.editor.commands.setTextAlign('thai-distributed') }
  },
})
