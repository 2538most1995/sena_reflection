import type { Node } from '@tiptap/pm/model'

export function findDocumentText(document: Node, query: string): { from: number; to: number }[] {
  if (!query) return []
  const matches: { from: number; to: number }[] = []
  const needle = query.toLocaleLowerCase()
  document.descendants((block, blockPosition) => {
    if (!block.isTextblock) return
    let text = ''
    const positions: number[] = []
    block.descendants((node, position) => {
      if (node.isText && node.text) {
        for (let index = 0; index < node.text.length; index++) positions.push(blockPosition + 1 + position + index)
        text += node.text
      } else if (node.type.name === 'hardBreak') { positions.push(blockPosition + 1 + position); text += '\n' }
    })
    const haystack = text.toLocaleLowerCase()
    let start = 0
    while (start < haystack.length) {
      const index = haystack.indexOf(needle, start)
      if (index < 0) break
      matches.push({ from: positions[index], to: positions[index + query.length - 1] + 1 })
      start = index + query.length
    }
    return false
  })
  return matches
}
