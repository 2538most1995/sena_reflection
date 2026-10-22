import { appFetch } from '@/lib/app-url'

export class DocumentApiError extends Error {
  constructor(message: string, public status: number) { super(message) }
}
export async function documentRequest<T>(url: string, init: RequestInit = {}): Promise<T> {
  const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || ''
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  headers.set('X-CSRF-TOKEN', csrf)
  if (init.body && !(init.body instanceof FormData)) headers.set('Content-Type', 'application/json')
  const response = await appFetch(url, { ...init, headers })
  const payload = await response.json().catch(() => null)
  if (!response.ok) {
    const errors = Object.values(payload?.errors || {}).flat().filter((value): value is string => typeof value === 'string')
    throw new DocumentApiError(errors[0] || payload?.message || (response.status === 419 ? 'หมดเวลาเข้าสู่ระบบ กรุณาบันทึกสำเนาแล้วเข้าสู่ระบบใหม่' : 'เชื่อมต่อระบบเอกสารไม่สำเร็จ'), response.status)
  }
  return payload as T
}
