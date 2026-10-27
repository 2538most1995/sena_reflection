type Transport = (path: string, init?: RequestInit) => Promise<unknown>
type Session = { id: string; chunk_size: number; parts: number; uploaded_parts: number }
const base = '/admin/semester-backups/uploads'

/** One bounded chunk at a time; the server verifies the complete signed ZIP before cataloguing it. */
export async function uploadSemesterZip(file: File, request: Transport, progress: (completed: number, total: number) => void, signal?: AbortSignal) {
  let session: Session | undefined
  try {
    session = await request(base, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name: file.name, size: file.size }), signal }) as Session
    if (!/^[a-f0-9-]{36}$/i.test(session.id) || !Number.isSafeInteger(session.chunk_size) || session.chunk_size < 64 * 1024 || session.chunk_size > 16 * 1024 * 1024
      || session.parts !== Math.ceil(file.size / session.chunk_size) || !Number.isSafeInteger(session.uploaded_parts) || session.uploaded_parts < 0 || session.uploaded_parts > session.parts) {
      throw new Error('ข้อมูลแบ่งส่วนอัปโหลดไม่ถูกต้อง กรุณาลองใหม่')
    }
    const path = `${base}/${encodeURIComponent(session.id)}`
    progress(session.uploaded_parts, session.parts)
    for (let index = session.uploaded_parts; index < session.parts; index++) {
      if (signal?.aborted) throw new DOMException('ยกเลิกอัปโหลด', 'AbortError')
      const chunk = new FormData()
      chunk.append('chunk', file.slice(index * session.chunk_size, Math.min(file.size, (index + 1) * session.chunk_size)), 'archive.part')
      for (let attempt = 0; ; attempt++) {
        try {
          await request(`${path}/${index}`, { method: 'POST', body: chunk, signal })
          break
        } catch (reason) {
          const status = (reason as { status?: number })?.status
          if (signal?.aborted || attempt >= 2 || (status !== undefined && status !== 429 && status < 500)) throw reason
          await new Promise(resolve => setTimeout(resolve, (attempt + 1) * 1000))
        }
      }
      progress(index + 1, session.parts)
    }
    return await request(`${path}/finish`, { method: 'POST', signal })
  } catch (reason) {
    if (session && /^[a-f0-9-]{36}$/i.test(session.id)) await request(`${base}/${encodeURIComponent(session.id)}`, { method: 'DELETE' }).catch(() => {})
    throw reason
  }
}
