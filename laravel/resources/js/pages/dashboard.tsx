import { AppLink as Link } from '@/components/app-link'
import { appRouter as router } from '@/lib/app-router'
import { appFetch as fetch, appUrl } from '@/lib/app-url'
import { actionAlert } from '@/lib/action-alert'
import { Head, usePage } from '@inertiajs/react'
import { ArrowRight, Books, CalendarBlank, CaretDown, CaretUp, CheckCircle, ClockCounterClockwise, Eye, FilePdf, Funnel, MagnifyingGlass, NotePencil, Plus, Printer, Trash, UsersThree, X } from '@phosphor-icons/react'
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
  return (
    <article className="min-w-0 rounded-xl border border-border bg-card p-4 transition-colors sm:p-5 shadow-none">
      <div className="flex items-center justify-between gap-2">
        <span className="truncate text-xs font-medium text-muted-foreground sm:text-sm">{label}</span>
        <span
          className={cn(
            'grid size-8 shrink-0 place-items-center rounded-lg border',
            emphasized
              ? 'border-blue-200 bg-blue-50 text-blue-600 dark:border-blue-900/60 dark:bg-blue-950/50 dark:text-blue-400'
              : 'border-border/60 bg-muted/60 text-muted-foreground'
          )}
        >
          <Icon size={18} weight="regular" />
        </span>
      </div>
      <div className="mt-3 flex flex-wrap items-baseline gap-1.5 sm:mt-4 sm:gap-2">
        <strong
          className={cn(
            'text-2xl font-bold tracking-tight tabular-nums sm:text-3xl',
            emphasized ? 'text-primary' : 'text-foreground'
          )}
        >
          {value}
        </strong>
        <span className="text-xs font-normal text-muted-foreground sm:text-sm">{unit}</span>
      </div>
      {detail ? (
        <p className="mt-1.5 truncate text-[11px] text-muted-foreground sm:mt-2">{detail}</p>
      ) : (
        <div className="mt-1.5 h-4 sm:mt-2" aria-hidden="true" />
      )}
    </article>
  )
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

  const completedLevels = (payload?: TeachingDraftPayload | null) => {
    if (!payload || !payload.levels || typeof payload.levels !== 'object') return 0
    try {
      return selectedTeachingLevels(payload).filter(({ code }) => {
        const level = payload.levels?.[code]
        return level ? teachingLevelReady(level) : false
      }).length
    } catch {
      return 0
    }
  }

  return <section className="mb-6 rounded-xl border border-border bg-card p-4 sm:p-5 shadow-none" aria-labelledby="draft-work-heading">
    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h2 id="draft-work-heading" className="flex items-center gap-2 text-sm font-semibold text-foreground">
          <ClockCounterClockwise size={18} className="text-primary" weight="regular" />
          งานที่ทำค้างไว้
        </h2>
        <p className="mt-0.5 text-xs text-muted-foreground">ร่างบันทึกบนเซิร์ฟเวอร์ กลับมาทำต่อจากอุปกรณ์เครื่องเดิมหรือเครื่องอื่นได้</p>
      </div>
      <Badge variant="outline" className="self-start rounded-md px-2 py-0.5 text-xs font-medium sm:self-auto">
        {items.length} ฉบับร่าง
      </Badge>
    </div>
    <div className="mt-4 grid gap-3 lg:grid-cols-3">{items.slice(0, 6).map((draft) => {
      const scope = draft.scope || draft.payload?.scope || { tambon: '', year: '', semester: '', week: '', date: '' }
      let completed = 0
      try {
        completed = draft.progress?.completed_levels ?? (draft.payload ? completedLevels(draft.payload) : 0)
      } catch {
        completed = 0
      }
      let totalLevels = 3
      try {
        totalLevels = draft.progress?.total_levels || (draft.payload ? selectedTeachingLevels(draft.payload).length : 3) || 3
      } catch {
        totalLevels = 3
      }
      return <article key={draft.id} className="flex flex-col rounded-lg border border-border bg-background p-3.5">
        <div className="flex items-start justify-between gap-3">
          <div>
            <b className="text-xs font-semibold text-foreground sm:text-sm">สัปดาห์ {scope.week || 'ยังไม่เลือก'}</b>
            <p className="mt-0.5 text-[11px] text-muted-foreground">ปี {scope.year} · ภาคเรียน {scope.semester}</p>
          </div>
          <Badge variant={completed === totalLevels ? 'secondary' : 'outline'} className="text-[11px]">
            {completed}/{totalLevels} ระดับ
          </Badge>
        </div>
        <div className="mt-3.5 h-1.5 overflow-hidden rounded-full bg-muted" aria-label={`ทำเสร็จ ${completed} จาก ${totalLevels} ระดับ`}>
          <div className="h-full rounded-full bg-primary transition-[width] duration-200" style={{ width: `${(completed / totalLevels) * 100}%` }} />
        </div>
        <div className="mt-2.5 flex items-center justify-between gap-3 text-[11px] text-muted-foreground">
          <span>{scope.date || 'ยังไม่เลือกวันที่'}</span>
          <span>{draft.images_count || 0} รูป</span>
        </div>
        <Button asChild variant="outline" size="sm" className="mt-3.5 h-9 w-full rounded-lg text-xs font-medium">
          <Link href={`/records/create?draft=${encodeURIComponent(draft.id)}`}>
            ทำต่อจากร่างนี้<ArrowRight size={15} />
          </Link>
        </Button>
      </article>
    })}</div>
  </section>
}

function Filters({ filters, weeks, tambons, district, current }: { filters: Props['filters']; weeks: number[]; tambons: Tambon[]; district: boolean; current: ActiveAcademicTerm }) {
  const [data, setData] = useState({ year: filters.year || '', semester: filters.semester || '', week: filters.week || '', tambon: filters.tambon || '', search: filters.search || '', scope: filters.scope || 'current' })
  const [mobileOpen, setMobileOpen] = useState(false)

  useEffect(() => setData({ year: filters.year || '', semester: filters.semester || '', week: filters.week || '', tambon: filters.tambon || '', search: filters.search || '', scope: filters.scope || 'current' }), [filters.year, filters.semester, filters.week, filters.tambon, filters.search, filters.scope])

  const activeCount = useMemo(() => {
    let count = 0
    if (data.scope !== 'current') count++
    if (data.year && data.scope !== 'current') count++
    if (data.semester && data.scope !== 'current') count++
    if (data.week) count++
    if (data.tambon) count++
    if (data.search) count++
    return count
  }, [data])

  const hasAnyFilter = Object.values(data).some(Boolean)
  const apply = (event: FormEvent) => { event.preventDefault(); router.get('/', data, { preserveState: true, replace: true }) }
  const reset = () => { router.get('/') }

  return (
    <div className="rounded-xl border border-border bg-muted/30 p-3 sm:p-4">
      {/* Mobile Collapsible Header */}
      <div className="flex items-center justify-between sm:hidden">
        <button
          type="button"
          onClick={() => setMobileOpen(prev => !prev)}
          className="flex items-center gap-2 text-xs font-semibold text-foreground focus-visible:outline-none"
          aria-expanded={mobileOpen}
        >
          <Funnel size={15} className="text-primary" weight="regular" />
          <span>ตัวกรองข้อมูล</span>
          {activeCount > 0 && (
            <Badge variant="secondary" className="px-1.5 py-0 text-[10px]">
              {activeCount} ตัวกรอง
            </Badge>
          )}
        </button>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="h-7 px-2 text-xs text-muted-foreground hover:text-foreground"
          onClick={() => setMobileOpen(prev => !prev)}
        >
          {mobileOpen ? (
            <span className="flex items-center gap-1">
              ซ่อน <CaretUp size={13} />
            </span>
          ) : (
            <span className="flex items-center gap-1">
              เปิดตัวกรอง <CaretDown size={13} />
            </span>
          )}
        </Button>
      </div>

      {/* Filter Form - 2-3 col grid on tablet/desktop, collapsible on mobile */}
      <form
        onSubmit={apply}
        className={cn(
          'pt-3 sm:pt-0',
          !mobileOpen && 'hidden sm:block'
        )}
      >
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-6">
          <FormField label="ช่วงข้อมูล">
            <NativeSelect
              value={data.scope}
              onChange={(event) => {
                const scope = event.target.value
                setData({
                  ...data,
                  scope,
                  ...(scope === 'current'
                    ? { year: current.year, semester: current.semester }
                    : scope === 'all'
                      ? { year: '', semester: '' }
                      : {}),
                })
              }}
            >
              <option value="current">ภาคเรียนปัจจุบัน</option>
              <option value="selected">เลือกปี / ภาคเรียน</option>
              <option value="all">ทุกภาคเรียน</option>
            </NativeSelect>
          </FormField>

          <FormField label="ปีการศึกษา">
            <Input
              inputMode="numeric"
              disabled={data.scope === 'all'}
              value={data.year}
              onChange={(event) => setData({ ...data, year: event.target.value, scope: 'selected' })}
              placeholder="เช่น 2569"
            />
          </FormField>

          <FormField label="ภาคเรียน">
            <NativeSelect
              value={data.semester}
              disabled={data.scope === 'all'}
              onChange={(event) => setData({ ...data, semester: event.target.value, scope: 'selected' })}
            >
              <option value="">ทุกภาคเรียน</option>
              {['1', '2', '3'].map((term) => (
                <option key={term} value={term}>
                  ภาคเรียน {term}
                </option>
              ))}
            </NativeSelect>
          </FormField>

          <FormField label="สัปดาห์">
            <NativeSelect
              value={data.week}
              onChange={(event) => setData({ ...data, week: event.target.value })}
            >
              <option value="">ทุกสัปดาห์</option>
              {weeks.map((week) => (
                <option key={week} value={week}>
                  สัปดาห์ {week}
                </option>
              ))}
            </NativeSelect>
          </FormField>

          {district && (
            <FormField label="ตำบล">
              <NativeSelect
                value={data.tambon}
                onChange={(event) => setData({ ...data, tambon: event.target.value })}
              >
                <option value="">ทุกตำบล</option>
                {tambons.map((tambon) => (
                  <option key={tambon.id} value={tambon.id}>
                    {tambon.name}
                  </option>
                ))}
              </NativeSelect>
            </FormField>
          )}

          <FormField
            label="ค้นหารายวิชา"
            className={district ? 'sm:col-span-2 md:col-span-1 xl:col-span-1' : 'sm:col-span-2 md:col-span-2 xl:col-span-2'}
          >
            <div className="relative">
              <MagnifyingGlass
                size={16}
                className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"
              />
              <Input
                value={data.search}
                onChange={(event) => setData({ ...data, search: event.target.value })}
                className="pl-9"
                placeholder="ชื่อหรือรหัสวิชา"
              />
            </div>
          </FormField>
        </div>

        <div className="mt-3.5 flex items-center justify-end gap-2 border-t border-border/50 pt-3">
          {hasAnyFilter && (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={reset}
              className="h-9 gap-1 text-xs text-muted-foreground hover:text-foreground"
            >
              <X size={15} />
              ล้างค่า
            </Button>
          )}
          <Button type="submit" variant="secondary" size="sm" className="h-9 gap-1.5 px-4 text-xs font-semibold">
            <Funnel size={15} weight="regular" />
            แสดงผล
          </Button>
        </div>
      </form>
    </div>
  )
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
      <div className="grid min-h-52 place-items-center rounded-xl border border-dashed border-border bg-muted/20 px-6 text-center">
        <div>
          <Books size={32} className="mx-auto text-muted-foreground/80" weight="regular" />
          <b className="mt-3 block text-sm font-semibold text-foreground">ไม่พบรายการบันทึก</b>
          <p className="mt-1 text-xs text-muted-foreground">เปลี่ยนตัวกรองหรือสร้างบันทึกรายสัปดาห์ใหม่</p>
        </div>
      </div>
    )
  }

  return (
    <>
      <div className="mb-3.5 flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
        <div>
          แสดงรายการรายสัปดาห์: <b className="font-semibold text-foreground">{groups.length}</b> สัปดาห์ (บันทึกรวม <b className="font-semibold text-foreground">{recordList.length}</b> ระดับชั้น)
        </div>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="h-8 gap-1.5 text-xs text-muted-foreground hover:text-foreground"
          onClick={toggleAll}
        >
          {allExpanded ? (
            <>
              <CaretUp size={14} /> หุบระดับชั้นทั้งหมด
            </>
          ) : (
            <>
              <CaretDown size={14} /> คลี่ดูระดับชั้นทั้งหมด
            </>
          )}
        </Button>
      </div>

      {/* Desktop/Tablet Minimal Data Table */}
      <div className="hidden overflow-x-auto rounded-xl border border-border bg-card lg:block">
        <table className="w-full min-w-[980px] text-left text-sm border-collapse">
          <thead>
            <tr className="border-b border-border bg-slate-50/80 text-xs font-semibold text-muted-foreground dark:bg-slate-800/60">
              <th className="px-4 py-3">สัปดาห์</th>
              <th className="px-4 py-3">วันที่สอน</th>
              <th className="px-4 py-3">ปี / ภาค</th>
              <th className="px-4 py-3">ตำบล</th>
              <th className="px-4 py-3">ระดับชั้นที่บันทึก</th>
              <th className="px-4 py-3">การเข้าเรียนรวม</th>
              <th className="px-4 py-3">รูปภาพรวม</th>
              <th className="px-4 py-3 text-right">ระดับชั้น</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border/60">
            {groups.map((group) => {
              const isExpanded = !!expandedKeys[group.key]
              return (
                <Fragment key={group.key}>
                  <tr
                    className="cursor-pointer transition-colors hover:bg-slate-50/80 dark:hover:bg-slate-800/40"
                    onClick={() => toggleExpand(group.key)}
                  >
                    <td className="px-4 py-3.5 whitespace-nowrap">
                      <span className="inline-flex items-center rounded-md border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700 dark:border-blue-900/60 dark:bg-blue-950/50 dark:text-blue-300">
                        {`สัปดาห์ที่ ${group.week}`}
                      </span>
                    </td>
                    <td className="px-4 py-3.5 whitespace-nowrap text-xs text-foreground font-medium">
                      {group.date ? thaiDate(group.date) : '-'}
                    </td>
                    <td className="px-4 py-3.5 whitespace-nowrap">
                      <b className="text-xs font-semibold text-foreground">{group.year}</b>
                      <small className="block text-[11px] text-muted-foreground">ภาคเรียน {group.semester}</small>
                    </td>
                    <td className="px-4 py-3.5 text-xs text-foreground font-medium">{group.tambonName}</td>
                    <td className="px-4 py-3.5">
                      <div className="flex flex-wrap items-center gap-1">
                        {group.records.map((r) => (
                          <Badge key={r.id} variant="secondary" className="px-2 py-0.5 text-xs font-normal">
                            {r.level || '-'}
                          </Badge>
                        ))}
                        <span className="ml-1 text-[11px] text-muted-foreground">
                          ({group.records.length} ระดับ)
                        </span>
                      </div>
                    </td>
                    <td className="px-4 py-3.5 whitespace-nowrap">
                      <b className="text-xs font-semibold text-foreground">{number(group.totalPresent)}/{number(group.totalStudents)}</b>
                      <small className="block text-[11px] text-muted-foreground">มาเรียน / ทั้งหมด</small>
                    </td>
                    <td className="px-4 py-3.5 whitespace-nowrap text-xs text-muted-foreground">
                      {group.totalImages} รูป
                    </td>
                    <td className="px-4 py-3.5 text-right">
                      <div className="flex items-center justify-end gap-1.5">
                        <Button
                          asChild
                          variant="outline"
                          size="sm"
                          className="h-8 gap-1.5 rounded-lg px-2.5 text-xs font-medium border-border hover:bg-muted/70 hover:border-primary/40"
                          onClick={(e) => {
                            e.stopPropagation()
                            actionAlert.info('เปิดหน้าเตรียม PDF', 'รอจนเอกสารพร้อม แล้วกด “พิมพ์ / บันทึก PDF” ในแท็บใหม่')
                          }}
                          title={`พิมพ์ PDF สัปดาห์ที่ ${group.week}`}
                        >
                          <a
                            href={appUrl(`/exports/browser-print?scope=week&year=${group.year}&semester=${group.semester}&week=${group.week}${group.records[0]?.tambon ? `&tambon=${group.records[0].tambon}` : ''}`)}
                            target="_blank"
                            rel="noopener"
                          >
                            <FilePdf size={14} weight="regular" />
                            PDF สัปดาห์นี้
                          </a>
                        </Button>
                        <Button
                          type="button"
                          variant={isExpanded ? 'secondary' : 'outline'}
                          size="sm"
                          className="h-8 gap-1.5 rounded-lg text-xs"
                          onClick={(e) => {
                            e.stopPropagation()
                            toggleExpand(group.key)
                          }}
                        >
                          {isExpanded ? (
                            <>
                              <CaretUp size={14} /> ซ่อนระดับชั้น
                            </>
                          ) : (
                            <>
                              <CaretDown size={14} /> ดูระดับชั้น ({group.records.length})
                            </>
                          )}
                        </Button>
                      </div>
                    </td>
                  </tr>

                  {/* Accordion Sub-table */}
                  {isExpanded && (
                    <tr className="bg-slate-50/40 dark:bg-slate-900/40">
                      <td colSpan={8} className="p-3.5 sm:p-4">
                        <div className="rounded-xl border border-border bg-card p-3.5 shadow-none sm:p-4">
                          <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <div className="flex items-center gap-2">
                              <span className="inline-flex items-center rounded-md border border-border bg-muted/60 px-2 py-0.5 text-xs font-semibold text-foreground">
                                สัปดาห์ที่ {group.week}
                              </span>
                              <span className="text-xs font-semibold text-foreground">
                                รายละเอียดระดับชั้น ({group.records.length} ระดับชั้น)
                              </span>
                            </div>
                            <span className="text-xs text-muted-foreground">
                              {group.tambonName} {group.date ? `· วันที่ ${thaiDate(group.date)}` : ''}
                            </span>
                          </div>
                          <div className="overflow-x-auto rounded-lg border border-border bg-background">
                            <table className="w-full text-left text-sm border-collapse">
                              <thead>
                                <tr className="border-b border-border bg-slate-50/70 text-xs font-semibold text-muted-foreground dark:bg-slate-800/50">
                                  <th className="px-3.5 py-2.5">ระดับชั้น</th>
                                  <th className="px-3.5 py-2.5">รายวิชา</th>
                                  <th className="px-3.5 py-2.5">การเข้าเรียน</th>
                                  <th className="px-3.5 py-2.5">รูปภาพ</th>
                                  <th className="px-3.5 py-2.5 text-right font-medium">
                                    <span className="sr-only">จัดการ</span>
                                  </th>
                                </tr>
                              </thead>
                              <tbody className="divide-y divide-border/60">
                                {group.records.map((record) => {
                                  const canDelete = district || (record.tambon && record.tambon === auth?.user?.tambon)
                                  return (
                                    <tr key={record.id} className="transition-colors hover:bg-muted/30">
                                      <td className="px-3.5 py-2.5 whitespace-nowrap">
                                        <Badge variant="secondary" className="px-2 py-0.5 text-xs font-medium">
                                          {record.level || '-'}
                                        </Badge>
                                      </td>
                                      <td className="max-w-64 px-3.5 py-2.5">
                                        <b className="block truncate text-xs font-semibold text-foreground">{record.subject}</b>
                                        <small className="block truncate text-[11px] text-muted-foreground">
                                          {record.subject_code || 'ไม่ระบุรหัสวิชา'}
                                        </small>
                                      </td>
                                      <td className="px-3.5 py-2.5 whitespace-nowrap text-xs">
                                        <b className="font-semibold text-foreground">{number(record.present)}/{number(record.total)}</b>
                                        <span className="ml-1 text-[11px] text-muted-foreground">คน</span>
                                      </td>
                                      <td className="px-3.5 py-2.5 whitespace-nowrap text-xs text-muted-foreground">
                                        {record.images_count || 0} รูป
                                      </td>
                                      <td className="px-3.5 py-2.5 text-right">
                                        <div className="flex items-center justify-end gap-1.5">
                                          <Button
                                            asChild
                                            variant="ghost"
                                            size="sm"
                                            className="h-8 w-8.5 p-0 rounded-lg bg-teal-50 border border-teal-200/90 text-teal-700 hover:bg-teal-100 hover:text-teal-800 dark:bg-teal-950/60 dark:border-teal-800 dark:text-teal-300 shadow-xs transition-colors"
                                            title="ดูรายละเอียดบันทึก"
                                          >
                                            <Link href={`/records/${record.id}`}>
                                              <Eye size={17} weight="bold" />
                                              <span className="sr-only">ดูรายละเอียด</span>
                                            </Link>
                                          </Button>

                                          <div className="h-4 w-px bg-slate-300 dark:bg-slate-700 mx-0.5" aria-hidden="true" />

                                          <Button
                                            asChild
                                            size="sm"
                                            className="h-8 gap-1.5 px-3 rounded-lg text-xs font-semibold bg-[#6366F1] hover:bg-[#4F46E5] text-white border-0 shadow-xs transition-colors"
                                            title={`พิมพ์ PDF ${record.level || ''}`}
                                          >
                                            <a
                                              href={appUrl(`/records/${record.id}/browser-print`)}
                                              target="_blank"
                                              rel="noopener"
                                              onClick={() => actionAlert.info('เปิดหน้าเตรียม PDF', 'กำลังเปิดหน้าพิมพ์ของระดับชั้นนี้ในแท็บใหม่')}
                                            >
                                              <FilePdf size={15} weight="bold" />
                                              พิมพ์ PDF
                                            </a>
                                          </Button>

                                          <Button
                                            asChild
                                            size="sm"
                                            className="h-8 gap-1.5 px-3 rounded-lg text-xs font-semibold bg-[#2563EB] hover:bg-[#1D4ED8] text-white border-0 shadow-xs transition-colors"
                                            title="จัดเอกสารก่อนพิมพ์"
                                          >
                                            <Link href={`/records/${record.id}/document`}>
                                              <Printer size={15} weight="bold" />
                                              จัดเอกสาร
                                            </Link>
                                          </Button>

                                          <Button
                                            asChild
                                            size="sm"
                                            className="h-8 gap-1.5 px-3 rounded-lg text-xs font-semibold bg-[#16A34A] hover:bg-[#15803D] text-white border-0 shadow-xs transition-colors"
                                            title="แก้ไขบันทึก"
                                          >
                                            <Link href={`/records/${record.id}/edit`}>
                                              <NotePencil size={15} weight="bold" />
                                              แก้ไข
                                            </Link>
                                          </Button>

                                          {canDelete && (
                                            <Button
                                              type="button"
                                              size="sm"
                                              className="h-8 gap-1.5 px-3 rounded-lg text-xs font-semibold bg-[#EF4444] hover:bg-[#DC2626] text-white border-0 shadow-xs transition-colors"
                                              disabled={deletingId === record.id}
                                              onClick={(e) => {
                                                e.stopPropagation()
                                                handleDelete(record)
                                              }}
                                              title="ลบบันทึกหลังสอน"
                                            >
                                              <Trash size={15} weight="bold" />
                                              ลบ
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

      {/* Mobile Card List with Level Accordion */}
      <div className="grid gap-3 lg:hidden">
        {groups.map((group) => {
          const isExpanded = !!expandedKeys[group.key]
          return (
            <article key={group.key} className="rounded-xl border border-border bg-card p-4 shadow-none">
              <div
                className="flex items-start justify-between gap-3 cursor-pointer"
                onClick={() => toggleExpand(group.key)}
              >
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-1.5">
                    <span className="inline-flex items-center rounded-md border border-blue-200 bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700 dark:border-blue-900/60 dark:bg-blue-950/50 dark:text-blue-300">
                      {`สัปดาห์ที่ ${group.week}`}
                    </span>
                    <span className="text-xs text-muted-foreground">ปี {group.year} · ภาค {group.semester}</span>
                  </div>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {group.tambonName} {group.date ? `· ${thaiDate(group.date)}` : ''}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-1.5">
                  <Button
                    asChild
                    variant="outline"
                    size="sm"
                    className="h-8 gap-1 rounded-lg px-2 text-xs font-medium border-border"
                    onClick={(e) => {
                      e.stopPropagation()
                      actionAlert.info('เปิดหน้าเตรียม PDF', 'รอจนเอกสารพร้อม แล้วกด “พิมพ์ / บันทึก PDF” ในแท็บใหม่')
                    }}
                    title={`พิมพ์ PDF สัปดาห์ที่ ${group.week}`}
                  >
                    <a
                      href={appUrl(`/exports/browser-print?scope=week&year=${group.year}&semester=${group.semester}&week=${group.week}${group.records[0]?.tambon ? `&tambon=${group.records[0].tambon}` : ''}`)}
                      target="_blank"
                      rel="noopener"
                    >
                      <FilePdf size={14} weight="regular" />
                      PDF
                    </a>
                  </Button>
                  <Button
                    type="button"
                    variant={isExpanded ? 'secondary' : 'outline'}
                    size="sm"
                    className="shrink-0 gap-1 h-8 px-2 text-xs"
                    onClick={(e) => {
                      e.stopPropagation()
                      toggleExpand(group.key)
                    }}
                  >
                    {isExpanded ? <CaretUp size={14} /> : <CaretDown size={14} />}
                    {isExpanded ? 'ซ่อน' : `ระดับชั้น (${group.records.length})`}
                  </Button>
                </div>
              </div>

              <div className="mt-3 flex flex-wrap gap-1">
                {group.records.map((r) => (
                  <Badge key={r.id} variant="secondary" className="px-2 py-0.5 text-xs font-normal">
                    {r.level}
                  </Badge>
                ))}
              </div>

              <div className="mt-3 grid grid-cols-2 gap-2 text-xs text-muted-foreground border-t border-border/60 pt-2.5">
                <div>มาเรียนรวม: <b className="font-semibold text-foreground">{number(group.totalPresent)}/{number(group.totalStudents)} คน</b></div>
                <div>รูปภาพรวม: <b className="font-semibold text-foreground">{group.totalImages} รูป</b></div>
              </div>

              {isExpanded && (
                <div className="mt-3.5 space-y-2.5 border-t border-border/60 pt-3">
                  <h4 className="text-xs font-semibold text-muted-foreground">
                    ระดับชั้นที่บันทึกไว้ ({group.records.length} ระดับชั้น)
                  </h4>
                  {group.records.map((record) => {
                    const canDelete = district || (record.tambon && record.tambon === auth?.user?.tambon)
                    return (
                      <div key={record.id} className="rounded-lg border border-border bg-muted/20 p-3">
                        <div className="flex items-start justify-between gap-2">
                          <div className="min-w-0">
                            <Badge variant="outline" className="mb-1 text-[11px] font-medium">{record.level}</Badge>
                            <b className="block truncate text-sm font-semibold text-foreground">{record.subject}</b>
                            <small className="block truncate text-xs text-muted-foreground">{record.subject_code || 'ไม่ระบุรหัสวิชา'}</small>
                          </div>
                        </div>
                        <div className="mt-2 grid grid-cols-2 gap-2 text-xs text-muted-foreground">
                          <div>มาเรียน: <b className="font-semibold text-foreground">{number(record.present)}/{number(record.total)} คน</b></div>
                          <div>รูปภาพ: <b className="font-semibold text-foreground">{record.images_count || 0} รูป</b></div>
                        </div>
                        <div className="mt-3 flex flex-wrap items-center gap-1.5 border-t border-border/50 pt-2.5">
                          <Button
                            asChild
                            variant="ghost"
                            size="sm"
                            className="h-8 w-8.5 p-0 rounded-lg bg-teal-50 border border-teal-200/90 text-teal-700 hover:bg-teal-100 dark:bg-teal-950/60 dark:border-teal-800 dark:text-teal-300 shadow-xs"
                            title="ดูรายละเอียดบันทึก"
                          >
                            <Link href={`/records/${record.id}`}>
                              <Eye size={16} weight="bold" />
                              <span className="sr-only">ดูรายละเอียด</span>
                            </Link>
                          </Button>

                          <div className="h-4 w-px bg-slate-300 dark:bg-slate-700 mx-0.5" aria-hidden="true" />

                          <Button
                            asChild
                            size="sm"
                            className="h-8 flex-1 gap-1 px-2.5 rounded-lg text-xs font-semibold bg-[#6366F1] hover:bg-[#4F46E5] text-white shadow-xs"
                          >
                            <a
                              href={appUrl(`/records/${record.id}/browser-print`)}
                              target="_blank"
                              rel="noopener"
                              onClick={() => actionAlert.info('เปิดหน้าเตรียม PDF', 'กำลังเปิดหน้าพิมพ์ของระดับชั้นนี้ในแท็บใหม่')}
                            >
                              <FilePdf size={14} weight="bold" />
                              พิมพ์ PDF
                            </a>
                          </Button>

                          <Button
                            asChild
                            size="sm"
                            className="h-8 flex-1 gap-1 px-2.5 rounded-lg text-xs font-semibold bg-[#2563EB] hover:bg-[#1D4ED8] text-white shadow-xs"
                          >
                            <Link href={`/records/${record.id}/document`}>
                              <Printer size={14} weight="bold" />
                              จัดเอกสาร
                            </Link>
                          </Button>

                          <Button
                            asChild
                            size="sm"
                            className="h-8 gap-1 px-2.5 rounded-lg text-xs font-semibold bg-[#16A34A] hover:bg-[#15803D] text-white shadow-xs"
                          >
                            <Link href={`/records/${record.id}/edit`}>
                              <NotePencil size={14} weight="bold" />
                              แก้ไข
                            </Link>
                          </Button>

                          {canDelete && (
                            <Button
                              type="button"
                              size="sm"
                              className="h-8 gap-1 px-2.5 rounded-lg text-xs font-semibold bg-[#EF4444] hover:bg-[#DC2626] text-white shadow-xs"
                              disabled={deletingId === record.id}
                              onClick={() => handleDelete(record)}
                              title="ลบบันทึกหลังสอน"
                            >
                              <Trash size={14} weight="bold" />
                              ลบ
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
              <Button key={index} asChild variant={link.active ? 'default' : 'outline'} size="sm" className="h-9 rounded-lg">
                <Link href={link.url} preserveScroll dangerouslySetInnerHTML={{ __html: link.label }} />
              </Button>
            ) : (
              <Button key={index} variant="outline" size="sm" disabled className="h-9 rounded-lg" dangerouslySetInnerHTML={{ __html: link.label }} />
            )
          )}
        </nav>
      )}
    </>
  )
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
      <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2.5">
            <h1 className="text-2xl font-bold tracking-tight text-foreground sm:text-[28px]">บันทึกหลังการสอน</h1>
            {props.activeTerm?.term && (
              <span className="inline-flex items-center gap-1.5 rounded-md border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700 dark:border-blue-900/60 dark:bg-blue-950/50 dark:text-blue-300">
                <CalendarBlank size={14} weight="bold" /> ภาคเรียนปัจจุบัน {props.activeTerm.term}
              </span>
            )}
          </div>
          <p className="mt-1 text-sm text-muted-foreground">ติดตามรายการ ตรวจสอบการเข้าเรียน และส่งออกเอกสาร</p>
        </div>
        <Button asChild size="lg" className="h-11 rounded-lg px-5 font-semibold shadow-none shrink-0">
          <Link href="/records/create">
            <Plus size={18} weight="bold" />
            สร้างบันทึกหลังสอน
          </Link>
        </Button>
      </div>

      <FlashMessages />
      <AcademicTermNotice
        active={props.activeTerm}
        filters={props.filters}
        district={district}
        olderCount={props.olderRecordCount}
      />
      <DraftWorkList drafts={props.drafts} />
      <section className="mb-6 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4" aria-label="สรุปข้อมูล">
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
      <Card className="rounded-xl border border-border bg-card shadow-none">
        <CardHeader className="gap-4 p-4 sm:p-6">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <CardTitle className="text-base font-bold sm:text-lg">รายการบันทึก</CardTitle>
              <p className="mt-1 text-xs text-muted-foreground sm:text-sm">ค้นหา ตรวจสอบ แก้ไข และพิมพ์ข้อมูล</p>
            </div>
            <Badge variant="outline" className="self-start rounded-md px-2.5 py-1 text-xs font-semibold sm:self-auto">
              {number(totalRecords)} รายการ
            </Badge>
          </div>
          <Filters
            filters={props.filters}
            weeks={props.availableWeeks || []}
            tambons={props.tambonOptions || []}
            district={district}
            current={props.activeTerm}
          />
        </CardHeader>
        <CardContent className="p-4 pt-0 sm:p-6 sm:pt-0">
          <ExportPanel filters={props.filters} availableWeeks={props.availableWeeks} />
          <RecordsList records={props.records} />
        </CardContent>
      </Card>
    </AppShell>
  )
}
