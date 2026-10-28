import { AppLink } from '@/components/app-link'
import { Button } from '@/components/ui/button'

export type ActiveAcademicTerm = { term: string; year: string; semester: string; source: string; notice: string | null }

export function AcademicTermNotice({ active, filters, district = false, olderCount = 0, currentUrl = '/' }: {
  active: ActiveAcademicTerm; filters: { year?: string; semester?: string; scope?: string }; district?: boolean; olderCount?: number; currentUrl?: string
}) {
  const history = filters.scope === 'all' || filters.year !== active.year || filters.semester !== active.semester
  return <section aria-label="ภาคเรียนที่กำลังใช้งาน" className="mb-6 rounded-xl border bg-card p-4 sm:p-5">
    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h2 className="font-semibold">{history ? 'กำลังดูข้อมูลย้อนหลังตามตัวกรอง' : `ภาคเรียนปัจจุบัน ${active.term}`}</h2><p className="mt-1 text-sm text-muted-foreground">{active.source === 'sdl' ? `ภาคเรียนล่าสุดที่ตรวจพบจาก SDL School: ${active.term}` : active.notice}</p></div><div className="flex flex-wrap gap-2">{history && <Button asChild size="sm" variant="outline"><AppLink href={currentUrl}>กลับภาคเรียนปัจจุบัน</AppLink></Button>}<Button asChild size="sm" variant="outline"><AppLink href="/teaching-drafts/manage">จัดการร่างของฉัน</AppLink></Button></div></div>
    {district && olderCount > 0 && <div className="mt-4 flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between"><p className="text-sm text-muted-foreground">ยังมีข้อมูลภาคเรียนก่อน {olderCount.toLocaleString('th-TH')} บันทึกอยู่บนเซิร์ฟเวอร์ สำรองและดาวน์โหลด ZIP แล้วล้างข้อมูลเพื่อคืนพื้นที่ได้</p><Button asChild size="sm" className="shrink-0"><AppLink href="/admin/semester-backups">สำรองและปิดภาคเรียนเก่า</AppLink></Button></div>}
  </section>
}
