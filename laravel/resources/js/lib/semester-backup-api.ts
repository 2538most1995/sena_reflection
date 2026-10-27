import { appFetch } from '@/lib/app-url'

export type BackupPreflight = {
  counts: { records: number; attendance: number; images: number; documents: number; revisions: number }
  available_files: number; missing_files: { disk: string; path: string; reason: string }[]
  total_bytes: number; fits_limit: boolean; fingerprint: string
}

export async function backupRequest<T>(path: string, init: RequestInit = {}): Promise<T> {
  const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || ''
  const response = await appFetch(path, { ...init, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...init.headers } })
  const payload = await response.json().catch(() => null)
  if (!response.ok) throw Object.assign(new Error(Object.values(payload?.errors || {}).flat().join('\n') || payload?.message || (response.status === 413 ? 'ไฟล์ใหญ่เกินขนาดที่เซิร์ฟเวอร์อนุญาต' : 'ติดต่อระบบสำรองข้อมูลไม่สำเร็จ กรุณาลองใหม่')), { status: response.status })
  return payload as T
}
