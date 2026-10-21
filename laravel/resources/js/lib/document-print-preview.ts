/** Request a frozen copy of the sandboxed editor, after committing active text/drag edits. */
export function captureDocumentForPrint(frame: HTMLIFrameElement): Promise<string> {
  return new Promise((resolve, reject) => {
    const requestId = crypto.randomUUID()
    const timeout = window.setTimeout(() => finish(new Error('ตัวอย่างยังไม่พร้อม กรุณารอให้เอกสารโหลดเสร็จแล้วลองใหม่')), 8000)
    const finish = (result: string | Error) => {
      window.clearTimeout(timeout)
      window.removeEventListener('message', receive)
      if (result instanceof Error) reject(result)
      else resolve(result)
    }
    const receive = (event: MessageEvent) => {
      if (event.source !== frame.contentWindow || event.data?.source !== 'sena-document-print-result' || event.data.requestId !== requestId) return
      if (typeof event.data.html !== 'string' || !event.data.html.includes('<!doctype html>')) finish(new Error('อ่านหน้ากระดาษไม่สำเร็จ'))
      else finish(event.data.html)
    }
    window.addEventListener('message', receive)
    frame.contentWindow?.postMessage({ source: 'sena-document-print-request', requestId }, '*')
  })
}

/** The pages come from the live editor; only trusted controls come from the server. */
export function composePrintPreview(capturedHtml: string, serverHtml: string): string {
  const parser = new DOMParser()
  const captured = parser.parseFromString(capturedHtml, 'text/html')
  const server = parser.parseFromString(serverHtml, 'text/html')
  const style = server.getElementById('browser-print-styles')
  const toolbar = server.querySelector('.browser-print-toolbar')
  const script = server.getElementById('browser-print-script')
  if (!style || !toolbar || !script) throw new Error('โหลดเครื่องมือพิมพ์ไม่สำเร็จ กรุณาลองใหม่')
  captured.head.appendChild(captured.importNode(style, true))
  captured.body.prepend(captured.importNode(toolbar, true))
  captured.body.appendChild(captured.importNode(script, true))
  return '<!doctype html>' + captured.documentElement.outerHTML
}
