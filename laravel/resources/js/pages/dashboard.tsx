import { AppLink as Link } from '@/components/app-link'
import { appRouter as router } from '@/lib/app-router'
import { appFetch as fetch } from '@/lib/app-url'
import { Head, usePage } from '@inertiajs/react'
import { ArrowRight, Books, CalendarBlank, CaretDown, CaretUp, CheckCircle, ClockCounterClockwise, Funnel, MagnifyingGlass, Plus, Printer, Trash, UsersThree, X } from '@phosphor-icons/react'
import { FormEvent, Fragment, useEffect, useMemo, useState } from 'react'
import { AppShell, PageHeader } from '@/components/app-shell'
import { AcademicTermNotice, type ActiveAcademicTerm } from '@/components/academic-term-notice'
import { FlashMessages } from '@/components/flash-messages'
import { ExportPanel } from '@/components/export-panel'
import { FormField } from '@/components/form-field'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { NativeSelect } from '@/components/ui/native-select'
import { cn, number, thaiDate } from '@/lib/utils'
import { groupRecordsByWeek, toRecordArray, type WeekGroup } from '@/lib/dashboard-records'
import type { Paginated, RecordRow, SharedProps, Tambon } from '@/types'
import type { LevelCode, TeachingDraftPayload } from '@/components/teaching-workspace/types'
import { selectedTeachingLevels } from '@/components/teaching-workspace/types'
import { teachingLevelReady } from '@/components/teaching-workspace/review-state'

type DraftSummary = {
  id: string
  last_saved_at: string | null
  payload?: TeachingDraftPayload
  scope?: TeachingDraftPayload['scope']
  progress?: { completed_levels: number; total_levels: number }
  active_level_code?: LevelCode
  images_count?: number
}
type Props = {
  records: Paginated<RecordRow>
  summary: { record_count: number; week_count: number; total_students: number; total_present: number; unique_present_students: number }
  availableWeeks: number[]
  tambonOptions: Tambon[]
  filters: Partial<Record<'year' | 'semester' | 'week' | 'tambon' | 'search' | 'scope', string>>
  weeklyDefaults: { year: string; semester: string; date: string }
  drafts?: DraftSummary[]
  activeTerm: ActiveAcademicTerm
  olderRecordCount: number
}

function Metric({ label, value, unit, icon: Icon, emphasized = false, detail }: { label: string; value: string; unit: string; icon: React.ElementType; emphasized?: boolean; detail?: string }) {
  return <article className="min-h-36 min-w-0 rounded-2xl border bg-card p-5 sm:p-6">
    <div className="flex items-start justify-between gap-3"><span className="text-sm font-medium text-muted-foreground">{label}</span><Icon size={20} weight="regular" className="shrink-0 text-primary/75" /></div>
    <div className="mt-5 flex flex-wrap items-baseline gap-x-2"><strong className={cn('text-3xl font-semibold tracking-[-0.04em] tabular-nums', emphasized && 'text-primary')}>{value}</strong><small className="text-muted-foreground">{unit}</small></div>
    {detail && <p className="mt-2 text-xs leading-5 text-muted-foreground">{detail}</p>}
  </article>
}

function DraftWorkList({ drafts }: { drafts?: Props['drafts'] }) {
  const [items, setItems] = useState<DraftSummary[]>(drafts || [])

  useEffect(() => {
    if (drafts !== undefined) {
      setItems(drafts)
      return
    }
    const controller = new AbortController()
    void fetch('/teaching-drafts', { headers: { Accept: 'application/json' }, signal: controller.signal })
      .then(async (response) => ({ response, envelope: await response.json() as { data?: { drafts?: DraftSummary[] } } }))
      .then(({ response, envelope }) => { if (response.ok && envelope.data?.drafts) setItems(envelope.data.drafts) })
      .catch(() => undefined)
    return () => controller.abort()
  }, [drafts])

  if (!items.length) return null

  const completedLevels = (payload: TeachingDraftPayload) => selectedTeachingLevels(payload).filter(({ code }) => teachingLevelReady(payload.levels[code])).length

  return <section className="mb-6 rounded-2xl border bg-card p-5 sm:p-6" aria-labelledby="draft-work-heading">
    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h2 id="draft-work-heading" className="flex items-center gap-2 font-semibold"><ClockCounterClockwise size={20} className="text-primary" />งานที่ทำค้างไว้</h2><p className="mt-1 text-xs text-muted-foreground">ร่างบันทึกบนเซิร์ฟเวอร์ กลับมาทำต่อจากอุปกรณ์เครื่องเดิมหรือเครื่องอื่นได้</p></div><Badge variant="outline">{items.length} ฉบับร่าง</Badge></div>
    <div className="mt-4 grid gap-3 lg:grid-cols-3">{items.slice(0, 6).map((draft) => {
      const scope = draft.scope || draft.payload?.scope || { tambon: '', year: '', semester: '', week: '', date: '' }
      const completed = draft.progress?.completed_levels ?? (draft.payload ? completedLevels(draft.payload) : 0)
      const totalLevels = draft.progress?.total_levels || (draft.payload ? selectedTeachingLevels(draft.payload).length : 3)
      return <article key={draft.id} className="flex flex-col rounded-xl border bg-background p-4">
        <div className="flex items-start justify-between gap-3"><div><b className="text-sm">สัปดาห์ {scope.week || 'ยังไม่เลือก'}</b><p className="mt-0.5 text-xs text-muted-foreground">ปี {scope.year} · ภาคเรียน {scope.semester}</p></div><Badge variant={completed === totalLevels ? 'secondary' : 'outline'}>{completed}/{totalLevels} ระดับ</Badge></div>
        <div className="mt-4 h-1.5 overflow-hidden rounded-full bg-muted" aria-label={`ทำเสร็จ ${completed} จาก ${totalLevels} ระดับ`}><div className="h-full rounded-full bg-primary transition-[width] duration-200" style={{ width: `${(completed / totalLevels) * 100}%` }} /></div>
        <div className="mt-3 flex items-center justify-between gap-3 text-[11px] text-muted-foreground"><span>{scope.date || 'ยังไม่เลือกวันที่'}</span><span>{draft.images_count || 0} รูป</span></div>
        <Button asChild variant="outline" size="sm" className="mt-4 w-full"><Link href={`/records/create?draft=${encodeURIComponent(draft.id)}`}>ทำต่อจากร่างนี้<ArrowRight size={16} /></Link></Button>
      </article>
    })}</div>
  </section>
}

function Filters({ filters, weeks, tambons, district, current }: { filters: Props['filters']; weeks: number[]; tambons: Tambon[]; district: boolean; current: ActiveAcademicTerm }) {
  const [data, setData] = useState({ year: filters.year || '', semester: filters.semester || '', week: filters.week || '', tambon: filters.tambon || '', search: filters.search || '', scope: filters.scope || 'current' })
  useEffect(() => setData({ year: filters.year || '', semester: filters.semester || '', week: filters.week || '', tambon: filters.tambon || '', search: filters.search || '', scope: filters.scope || 'current' }), [filters.year, filters.semester, filters.week, filters.tambon, filters.search, filters.scope])
  const active = Object.values(data).some(Boolean)
  const apply = (event: FormEvent) => { event.preventDefault(); router.get('/', data, { preserveState: true, replace: true }) }
  return <form onSubmit={apply} className="grid gap-4 rounded-xl bg-muted/45 p-4 md:grid-cols-2 xl:grid-cols-6">
    <FormField label="ช่วงข้อมูล"><NativeSelect value={data.scope} onChange={event => { const scope = event.target.value; setData({ ...data, scope, ...(scope === 'current' ? { year: current.year, semester: current.semester } : scope === 'all' ? { year: '', semester: '' } : {}) }) }}><option value="current">ภาคเรียนปัจจุบัน</option><option value="selected">เลือกปี / ภาคเรียน</option><option value="all">ทุกภาคเรียน</option></NativeSelect></FormField>
    <FormField label="ปีการศึกษา"><Input inputMode="numeric" disabled={data.scope === 'all'} value={data.year} onChange={(event) => setData({ ...data, year: event.target.value, scope: 'selected' })} placeholder="เช่น 2569" /></FormField>
    <FormField label="ภาคเรียน"><NativeSelect value={data.semester} disabled={data.scope === 'all'} onChange={(event) => setData({ ...data, semester: event.target.value, scope: 'selected' })}><option value="">ทุกภาคเรียน</option>{['1', '2', '3'].map((term) => <option key={term} value={term}>ภาคเรียน {term}</option>)}</NativeSelect></FormField>
    <FormField label="สัปดาห์"><NativeSelect value={data.week} onChange={(event) => setData({ ...data, week: event.target.value })}><option value="">ทุกสัปดาห์</option>{weeks.map((week) => <option key={week} value={week}>สัปดาห์ {week}</option>)}</NativeSelect></FormField>
    {district && <FormField label="ตำบล"><NativeSelect value={data.tambon} onChange={(event) => setData({ ...data, tambon: event.target.value })}><option value="">ทุกตำบล</option>{tambons.map((tambon) => <option key={tambon.id} value={tambon.id}>{tambon.name}</option>)}</NativeSelect></FormField>}
    <FormField label="ค้นหารายวิชา" className={district ? 'xl:col-span-2' : 'xl:col-span-3'}><div className="relative"><MagnifyingGlass size={18} className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-muted-foreground" /><Input value={data.search} onChange={(event) => setData({ ...data, search: event.target.value })} className="pl-10" placeholder="ชื่อหรือรหัสวิชา" /></div></FormField>
    <div className="flex items-end gap-2 md:col-span-2 xl:col-span-full xl:justify-end">{active && <Button type="button" variant="ghost" onClick={() => router.get('/')}><X size={17} />ล้างค่า</Button>}<Button type="submit" variant="secondary"><Funnel size={17} />แสดงผล</Button></div>
  </form>
}

function RecordsList({ records }: { records?: Paginated<RecordRow> | null }) {
  const { auth } = usePage<SharedProps>().props
  const district = auth?.user?.role === 'district'
  const [deletingId, setDeletingId] = useState<string | null>(null)
  const [expandedKeys, setExpandedKeys] = useState<Record<string, boolean>>({})

  const recordList = useMemo(() => toRecordArray(records?.data), [records?.data])
  const groups = useMemo(() => groupRecordsByWeek(recordList), [recordList])

  const toggleExpand = (key: string) => {
    setExpandedKeys(prev => ({ ...prev, [key]: !prev[key] }))
  }

  const allExpanded = groups.length > 0 && groups.every(g => expandedKeys[g.key])
  const toggleAll = () => {
    if (allExpanded) {
      setExpandedKeys({})
    } else {
      const next: Record<string, boolean> = {}
      for (const g of groups) next[g.key] = true
      setExpandedKeys(next)
    }
  }

  const handleDelete = (record: RecordRow) => {
    const label = record.subject ? `"${record.subject}" (สัปดาห์ที่ ${record.week})` : `สัปดาห์ที่ ${record.week}`
    if (!window.confirm(`ยืนยันการลบบันทึกหลังสอน ${label}?\n\nข้อมูลการสอน การเข้าเรียน และรูปภาพของบันทึกนี้จะถูกลบออกทั้งหมด`)) {
      return
    }
    setDeletingId(record.id)
    router.delete(`/records/${record.id}`, {
      preserveScroll: true,
      onFinish: () => setDeletingId(null),
    })
  }

  if (!recordList.length) {
    return (
      <div className="grid min-h-52 place-items-center rounded-xl border border-dashed bg-muted/20 px-6 text-center">
        <div>
          <Books size={34} className="mx-auto text-muted-foreground" />
          <b className="mt-3 block">ไม่พบรายการบันทึก</b>
          <p className="mt-1 text-sm text-muted-foreground">เปลี่ยนตัวกรองหรือสร้างบันทึกรายสัปดาห์ใหม่</p>
        </div>
      </div>
    )
  }

  return <>
    <div className="mb-3 flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
      <div>
        แสดงรายการรายสัปดาห์: <b className="text-foreground">{groups.length}</b> สัปดาห์ (บันทึกรวม <b className="text-foreground">{recordList.length}</b> ระดับชั้น)
      </div>
      <Button
        type="button"
        variant="ghost"
        size="sm"
        className="h-8 gap-1.5 text-xs text-muted-foreground hover:text-foreground"
        onClick={toggleAll}
      >
        {allExpanded ? <><CaretUp size={15} /> หุบระดับชั้นทั้งหมด</> : <><CaretDown size={15} /> คลี่ดูระดับชั้นทั้งหมด</>}
      </Button>
    </div>

    <div className="hidden overflow-x-auto lg:block">
      <table className="w-full min-w-[980px] text-left text-sm">
        <thead>
          <tr className="border-b bg-muted/35 text-xs text-muted-foreground">
            <th className="px-4 py-3 font-medium">สัปดาห์</th>
            <th className="px-4 py-3 font-medium">วันที่สอน</th>
            <th className="px-4 py-3 font-medium">ปี / ภาค</th>
            <th className="px-4 py-3 font-medium">ตำบล</th>
            <th className="px-4 py-3 font-medium">ระดับชั้นที่บันทึก</th>
            <th className="px-4 py-3 font-medium">การเข้าเรียนรวม</th>
            <th className="px-4 py-3 font-medium">รูปภาพรวม</th>
            <th className="px-4 py-3 text-right font-medium">คลิกดูระดับชั้น</th>
          </tr>
        </thead>
        <tbody>
          {groups.map((group) => {
            const isExpanded = !!expandedKeys[group.key]
            return (
              <Fragment key={group.key}>
                <tr
                  className="border-b hover:bg-muted/30 cursor-pointer transition-colors"
                  onClick={() => toggleExpand(group.key)}
                >
                  <td className="px-4 py-3.5 whitespace-nowrap">
                    <Badge className="text-sm font-semibold">{`สัปดาห์ที่ ${group.week}`}</Badge>
                  </td>
                  <td className="px-4 py-3.5 whitespace-nowrap">{group.date ? thaiDate(group.date) : '-'}</td>
                  <td className="px-4 py-3.5 whitespace-nowrap">
                    <b>{group.year}</b>
                    <small className="block text-muted-foreground">ภาคเรียน {group.semester}</small>
                  </td>
                  <td className="px-4 py-3.5">{group.tambonName}</td>
                  <td className="px-4 py-3.5">
                    <div className="flex flex-wrap items-center gap-1">
                      {group.records.map((r) => (
                        <Badge key={r.id} variant="secondary" className="text-xs">
                          {r.level || '-'}
                        </Badge>
                      ))}
                      <span className="text-xs text-muted-foreground ml-1">
                        ({group.records.length} ระดับชั้น)
                      </span>
                    </div>
                  </td>
                  <td className="px-4 py-3.5 whitespace-nowrap">
                    <b>{number(group.totalPresent)}/{number(group.totalStudents)}</b>
                    <small className="block text-muted-foreground">มาเรียน / ทั้งหมด</small>
                  </td>
                  <td className="px-4 py-3.5 whitespace-nowrap">{group.totalImages} รูป</td>
                  <td className="px-4 py-3.5 text-right">
                    <Button
                      type="button"
                      variant={isExpanded ? 'secondary' : 'outline'}
                      size="sm"
                      className="gap-1.5"
                      onClick={(e) => {
                        e.stopPropagation()
                        toggleExpand(group.key)
                      }}
                    >
                      {isExpanded ? (
                        <>
                          <CaretUp size={16} /> ซ่อนระดับชั้น
                        </>
                      ) : (
                        <>
                          <CaretDown size={16} /> ดูระดับชั้น ({group.records.length})
                        </>
                      )}
                    </Button>
                  </td>
                </tr>

                {isExpanded && (
                  <tr className="border-b bg-muted/10">
                    <td colSpan={8} className="p-3 sm:p-4">
                      <div className="rounded-xl border bg-card p-3 sm:p-4 shadow-xs">
                        <div className="flex flex-wrap items-center justify-between gap-2 mb-3">
                          <div className="flex items-center gap-2">
                            <Badge variant="outline" className="font-medium text-xs">
                              สัปดาห์ที่ {group.week}
                            </Badge>
                            <span className="text-xs font-semibold text-foreground">
                              ระดับชั้นที่บันทึกไว้ ({group.records.length} ระดับชั้น)
                            </span>
                          </div>
                          <span className="text-xs text-muted-foreground">
                            {group.tambonName} {group.date ? `· วันที่ ${thaiDate(group.date)}` : ''}
                          </span>
                        </div>
                        <div className="overflow-x-auto rounded-lg border bg-background">
                          <table className="w-full text-left text-sm">
                            <thead>
                              <tr className="border-b bg-muted/40 text-xs text-muted-foreground">
                                <th className="px-3.5 py-2.5 font-medium">ระดับชั้น</th>
                                <th className="px-3.5 py-2.5 font-medium">รายวิชา</th>
                                <th className="px-3.5 py-2.5 font-medium">การเข้าเรียน</th>
                                <th className="px-3.5 py-2.5 font-medium">รูปภาพ</th>
                                <th className="px-3.5 py-2.5 text-right font-medium"><span className="sr-only">จัดการ</span></th>
                              </tr>
                            </thead>
                            <tbody>
                              {group.records.map((record) => {
                                const canDelete = district || (record.tambon && record.tambon === auth?.user?.tambon)
                                return (
                                  <tr key={record.id} className="border-b last:border-0 hover:bg-muted/20">
                                    <td className="px-3.5 py-2.5 whitespace-nowrap">
                                      <Badge variant="secondary" className="font-medium">
                                        {record.level || '-'}
                                      </Badge>
                                    </td>
                                    <td className="max-w-64 px-3.5 py-2.5">
                                      <b className="block truncate">{record.subject}</b>
                                      <small className="block truncate text-muted-foreground">
                                        {record.subject_code || 'ไม่ระบุรหัสวิชา'}
                                      </small>
                                    </td>
                                    <td className="px-3.5 py-2.5 whitespace-nowrap">
                                      <b>{number(record.present)}/{number(record.total)}</b>
                                      <span className="text-xs text-muted-foreground ml-1">คน</span>
                                    </td>
                                    <td className="px-3.5 py-2.5 whitespace-nowrap text-xs">
                                      {record.images_count || 0} รูป
                                    </td>
                                    <td className="px-3.5 py-2.5">
                                      <div className="flex justify-end gap-1">
                                        <Button asChild variant="ghost" size="sm">
                                          <Link href={`/records/${record.id}`}>ดู</Link>
                                        </Button>
                                        <Button asChild size="sm">
                                          <Link href={`/records/${record.id}/document`}>
                                            <Printer size={16} />จัดเอกสาร
                                          </Link>
                                        </Button>
                                        <Button asChild variant="outline" size="sm">
                                          <Link href={`/records/${record.id}/edit`}>
                                            แก้ไข
                                          </Link>
                                        </Button>
                                        {canDelete && (
                                          <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="text-destructive hover:bg-destructive hover:text-destructive-foreground hover:border-destructive"
                                            disabled={deletingId === record.id}
                                            onClick={(e) => {
                                              e.stopPropagation()
                                              handleDelete(record)
                                            }}
                                            title="ลบบันทึกหลังสอน"
                                          >
                                            <Trash size={15} />ลบ
                                          </Button>
                                        )}
                                      </div>
                                    </td>
                                  </tr>
                                )
                              })}
                            </tbody>
                          </table>
                        </div>
                      </div>
                    </td>
                  </tr>
                )}
              </Fragment>
            )
          })}
        </tbody>
      </table>
    </div>

    <div className="grid gap-3 lg:hidden">
      {groups.map((group) => {
        const isExpanded = !!expandedKeys[group.key]
        return (
          <article key={group.key} className="rounded-xl border bg-card p-4 shadow-2xs">
            <div
              className="flex items-start justify-between gap-3 cursor-pointer"
              onClick={() => toggleExpand(group.key)}
            >
              <div className="min-w-0">
                <div className="flex items-center gap-2">
                  <Badge className="font-semibold">{`สัปดาห์ที่ ${group.week}`}</Badge>
                  <span className="text-xs text-muted-foreground">ปี {group.year} · ภาค {group.semester}</span>
                </div>
                <p className="mt-1 text-xs text-muted-foreground">
                  {group.tambonName} {group.date ? `· ${thaiDate(group.date)}` : ''}
                </p>
              </div>
              <Button
                type="button"
                variant={isExpanded ? 'secondary' : 'outline'}
                size="sm"
                className="shrink-0 gap-1 text-xs"
                onClick={(e) => {
                  e.stopPropagation()
                  toggleExpand(group.key)
                }}
              >
                {isExpanded ? <CaretUp size={15} /> : <CaretDown size={15} />}
                {isExpanded ? 'ซ่อน' : `ดูระดับชั้น (${group.records.length})`}
              </Button>
            </div>

            <div className="mt-3 flex flex-wrap gap-1.5">
              {group.records.map((r) => (
                <Badge key={r.id} variant="secondary" className="text-xs">
                  {r.level}
                </Badge>
              ))}
            </div>

            <div className="mt-3 grid grid-cols-2 gap-2 text-xs text-muted-foreground border-t pt-2.5">
              <div>มาเรียนรวม: <b className="text-foreground">{number(group.totalPresent)}/{number(group.totalStudents)} คน</b></div>
              <div>รูปภาพรวม: <b className="text-foreground">{group.totalImages} รูป</b></div>
            </div>

            {isExpanded && (
              <div className="mt-4 space-y-3 border-t pt-3">
                <h4 className="text-xs font-semibold text-muted-foreground">
                  ระดับชั้นที่บันทึก ({group.records.length} ระดับชั้น)
                </h4>
                {group.records.map((record) => {
                  const canDelete = district || (record.tambon && record.tambon === auth?.user?.tambon)
                  return (
                    <div key={record.id} className="rounded-lg border bg-muted/20 p-3">
                      <div className="flex items-start justify-between gap-2">
                        <div>
                          <Badge variant="outline" className="mb-1 text-xs">{record.level}</Badge>
                          <b className="block text-sm">{record.subject}</b>
                          <small className="text-xs text-muted-foreground">{record.subject_code || 'ไม่ระบุรหัสวิชา'}</small>
                        </div>
                      </div>
                      <div className="mt-2 grid grid-cols-2 gap-2 text-xs text-muted-foreground">
                        <div>มาเรียน: <b className="text-foreground">{number(record.present)}/{number(record.total)} คน</b></div>
                        <div>รูปภาพ: <b className="text-foreground">{record.images_count || 0} รูป</b></div>
                      </div>
                      <div className="mt-3 flex flex-wrap gap-1.5 border-t pt-2">
                        <Button asChild variant="outline" size="sm" className="flex-1">
                          <Link href={`/records/${record.id}`}>ดูข้อมูล</Link>
                        </Button>
                        <Button asChild size="sm">
                          <Link href={`/records/${record.id}/document`}><Printer size={15} />จัดเอกสาร</Link>
                        </Button>
                        <Button asChild variant="outline" size="sm">
                          <Link href={`/records/${record.id}/edit`}>แก้ไข</Link>
                        </Button>
                        {canDelete && (
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="text-destructive hover:bg-destructive hover:text-destructive-foreground hover:border-destructive"
                            disabled={deletingId === record.id}
                            onClick={() => handleDelete(record)}
                            title="ลบบันทึกหลังสอน"
                          >
                            <Trash size={15} />ลบ
                          </Button>
                        )}
                      </div>
                    </div>
                  )
                })}
              </div>
            )}
          </article>
        )
      })}
    </div>

    {Boolean(records?.last_page && records.last_page > 1 && Array.isArray(records?.links)) && (
      <nav className="mt-5 flex flex-wrap justify-center gap-1" aria-label="แบ่งหน้า">
        {records!.links.map((link, index) =>
          link.url ? (
            <Button key={index} asChild variant={link.active ? 'default' : 'outline'} size="sm">
              <Link href={link.url} preserveScroll dangerouslySetInnerHTML={{ __html: link.label }} />
            </Button>
          ) : (
            <Button key={index} variant="outline" size="sm" disabled dangerouslySetInnerHTML={{ __html: link.label }} />
          )
        )}
      </nav>
    )}
  </>
}

export default function Dashboard(props: Props) {
  const { auth } = usePage<SharedProps>().props
  const district = auth?.user?.role === 'district'
  const summary = props.summary || {
    record_count: 0,
    week_count: 0,
    total_students: 0,
    total_present: 0,
    unique_present_students: 0,
  }
  const totalStudents = Number(summary.total_students) || 0
  const totalPresent = Number(summary.total_present) || 0
  const rate = totalStudents > 0 ? (totalPresent / totalStudents) * 100 : 0
  const totalRecords = Number(props.records?.total) || 0

  return (
    <AppShell>
      <Head title="ภาพรวม" />
      <PageHeader
        title="บันทึกหลังการสอน"
        description="ติดตามรายการ ตรวจสอบการเข้าเรียน และส่งออกเอกสาร"
        action={
          <Button asChild size="lg">
            <Link href="/records/create">
              <Plus size={19} />
              สร้างบันทึกหลังสอน
            </Link>
          </Button>
        }
      />
      <FlashMessages />
      <AcademicTermNotice
        active={props.activeTerm}
        filters={props.filters}
        district={district}
        olderCount={props.olderRecordCount}
      />
      <DraftWorkList drafts={props.drafts} />
      <section className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4" aria-label="สรุปข้อมูล">
        <Metric label="รายการที่แสดง" value={number(summary.record_count)} unit="บันทึก" icon={Books} />
        <Metric label="จำนวนสัปดาห์" value={number(summary.week_count)} unit="สัปดาห์" icon={CalendarBlank} />
        <Metric
          label="ผู้มาเรียน (ไม่ซ้ำ)"
          value={number(summary.unique_present_students)}
          unit="คน"
          icon={UsersThree}
          detail="นับรหัสผู้เรียนคนละ 1 ครั้ง"
        />
        <Metric
          emphasized
          label="อัตรามาเรียน"
          value={`${rate.toFixed(1)}%`}
          unit="ของผู้เรียน"
          icon={CheckCircle}
          detail={`${number(totalPresent)} คน จาก ${number(totalStudents)} คน`}
        />
      </section>
      <Card>
        <CardHeader className="gap-4">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <CardTitle>รายการบันทึก</CardTitle>
              <p className="mt-1 text-sm text-muted-foreground">ค้นหา ตรวจสอบ แก้ไข และพิมพ์ข้อมูล</p>
            </div>
            <Badge variant="outline">{number(totalRecords)} รายการ</Badge>
          </div>
          <Filters
            filters={props.filters}
            weeks={props.availableWeeks || []}
            tambons={props.tambonOptions || []}
            district={district}
            current={props.activeTerm}
          />
        </CardHeader>
        <CardContent>
          <ExportPanel filters={props.filters} />
          <RecordsList records={props.records} />
        </CardContent>
      </Card>
    </AppShell>
  )
}
