/** Count a browser preview only after its actual pages, fonts and images are ready. */
export async function waitForBrowserDocument(document: Document | null): Promise<void> {
  if (!document || !document.querySelector('.page') || document.querySelector('.missing-image-note')) {
    throw new Error('เอกสารหรือรูปภาพไม่ครบ กรุณาสร้างตัวอย่างใหม่')
  }

  let timer: ReturnType<typeof setTimeout> | undefined
  try {
    await Promise.race([
      (async () => {
        if (document.fonts) await document.fonts.ready
        if (document.fonts && Array.from(document.fonts).some(font => font.status === 'error')) {
          throw new Error('โหลดฟอนต์ไม่สำเร็จ กรุณาสร้างตัวอย่างใหม่')
        }
        await Promise.all(Array.from(document.images).map(async image => {
          if (!image.complete || !image.naturalWidth) throw new Error('โหลดรูปภาพไม่สำเร็จ กรุณาสร้างตัวอย่างใหม่')
          if (image.decode) await image.decode()
        }))
      })(),
      new Promise<never>((_, reject) => {
        timer = setTimeout(() => reject(new Error('โหลดเอกสารนานเกินไป กรุณาสร้างตัวอย่างใหม่')), 20000)
      }),
    ])
  } finally {
    clearTimeout(timer)
  }
}

export function draftRequestError(status: number): string {
  if (status === 503) return 'ระบบสร้างเอกสารยังไม่พร้อมใช้งาน ร่างยังอยู่ กรุณาลองใหม่ หรือเลือกตรวจเอกสารผ่านเบราว์เซอร์'
  if (status >= 500) return 'ระบบบันทึกขัดข้องชั่วคราว ข้อมูลในหน้านี้ยังอยู่ กรุณาลองอีกครั้ง หากยังไม่สำเร็จให้ติดต่อผู้ดูแลระบบ'
  if (status === 419) return 'เซสชันหมดอายุ กรุณาเปิดหน้านี้ใหม่แล้วเข้าสู่ระบบเพื่อทำต่อจากร่างที่บันทึกไว้'
  if (status === 413) return 'ไฟล์หรือข้อมูลมีขนาดเกินที่เซิร์ฟเวอร์รองรับ กรุณาลดขนาดแล้วลองใหม่'
  if (status === 429) return 'เรียกใช้งานถี่เกินไป กรุณารอสักครู่แล้วลองใหม่'
  return `ไม่สามารถดำเนินการได้ (${status}) กรุณาลองใหม่`
}

export function safeDraftResponseMessage(status: number, envelope: { message?: unknown; errors?: unknown }): string | undefined {
  if (status >= 500) return draftRequestError(status)
  const errors = envelope.errors && typeof envelope.errors === 'object' ? Object.values(envelope.errors).flat().filter((value): value is string => typeof value === 'string') : []
  if (errors.length) return errors.slice(0, 3).join(' · ')
  if (typeof envelope.message === 'string' && envelope.message.trim()) return envelope.message
  return status >= 400 ? draftRequestError(status) : undefined
}
