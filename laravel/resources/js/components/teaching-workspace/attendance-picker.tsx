import { Check, MagnifyingGlass, Student, UsersThree, WarningCircle } from '@phosphor-icons/react'
import { useEffect, useMemo, useState } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { NativeSelect } from '@/components/ui/native-select'
import { cn } from '@/lib/utils'
import type { LevelCode, WorkspaceAttendanceRow, WorkspaceLoadState } from './types'
import { attendanceRowIssues } from './review-state'

type Props = {
  rows: WorkspaceAttendanceRow[]
  levelCode: LevelCode
  groupCode: string
  state: WorkspaceLoadState
  onReload: () => void
  onChange: (rows: WorkspaceAttendanceRow[]) => void
}

export function AttendancePicker({ rows, levelCode, groupCode, state, onChange, onReload }: Props) {
  const [search, setSearch] = useState('')
  const [editingRows, setEditingRows] = useState<Set<number>>(new Set())
  const issues = attendanceRowIssues(rows, groupCode)
  useEffect(() => {
    const focus = (event: Event) => {
      if ((event as CustomEvent<{ level: LevelCode }>).detail?.level === levelCode) setSearch('')
    }
    window.addEventListener('sena-workspace-attendance-focus', focus)
    return () => window.removeEventListener('sena-workspace-attendance-focus', focus)
  }, [levelCode])
  const normalizedSearch = search.trim().toLocaleLowerCase('th')
  const visibleRows = useMemo(() => rows.map((row, index) => ({ row, index })).filter(({ row }) => {
    if (!normalizedSearch) return true
    return `${row.student_id} ${row.full_name}`.toLocaleLowerCase('th').includes(normalizedSearch)
  }), [normalizedSearch, rows])
  const selected = rows.filter((row) => row.present).length

  const updateRow = (index: number, patch: Partial<WorkspaceAttendanceRow>) => {
    onChange(rows.map((row, current) => current === index ? { ...row, ...patch } : row))
  }

  const setEveryonePresent = () => {
    if (!window.confirm(`ยืนยันว่าตรวจรายชื่อแล้ว และนักศึกษาทั้ง ${rows.length} คนมาเรียนทั้งหมด`)) return
    onChange(rows.map((row) => ({ ...row, present: true, learning_method: row.learning_method || 'พบกลุ่ม' })))
  }

  if (state.loading) {
    return <div className="grid gap-2" aria-label="กำลังโหลดรายชื่อนักศึกษา">
      {[0, 1, 2, 3].map((item) => <div key={item} className="h-12 animate-pulse rounded-lg bg-muted" />)}
    </div>
  }

  if (state.error && !rows.length) {
    return <div className="rounded-xl border border-destructive/25 bg-destructive/5 p-4 text-sm text-destructive">
      <div className="flex items-start gap-2"><WarningCircle size={19} className="mt-0.5 shrink-0" /><span>{state.error}</span></div>
    </div>
  }

  if (!rows.length) {
    return <div className="grid min-h-40 place-items-center rounded-xl border border-dashed bg-muted/20 px-5 text-center">
      <div><Student size={30} className="mx-auto text-muted-foreground" /><p className="mt-2 text-sm font-medium">ยังไม่มีรายชื่อ</p><p className="mt-1 text-xs text-muted-foreground">เลือกกลุ่มเรียนเพื่อโหลดรายชื่อจากระบบกลาง</p></div>
    </div>
  }

  return <div className="overflow-hidden rounded-xl border bg-background">
    {state.error && <div role="alert" className="border-b bg-amber-500/10 p-3 text-xs text-amber-800 dark:text-amber-200">{state.error} ใช้รายชื่อที่บันทึกไว้ด้านล่างต่อได้</div>}
    <div className="grid gap-3 border-b bg-muted/25 p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2"><UsersThree size={19} className="text-primary" /><b className="text-sm">มาเรียน {selected} จาก {rows.length} คน</b></div>
        <div className="flex gap-2"><Button type="button" variant="outline" size="sm" onClick={setEveryonePresent}>เลือกมาเรียนทั้งหมด</Button><Button type="button" variant="ghost" size="sm" onClick={() => onChange(rows.map((row) => ({ ...row, present: false, learning_method: null })))}>ทำเครื่องหมายขาดทั้งหมด</Button></div>
      </div>
      <p className="text-xs text-muted-foreground">ทำเครื่องหมายเฉพาะผู้มาเรียน ระบบเก็บการเปลี่ยนแปลงให้อัตโนมัติ</p>
      <div className="relative"><MagnifyingGlass size={17} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" /><Input value={search} onChange={(event) => setSearch(event.target.value)} className="h-10 pl-9" placeholder="ค้นหาชื่อหรือรหัสนักศึกษา" aria-label="ค้นหารายชื่อนักศึกษา" /></div>
    </div>
    <div className="max-h-[420px] overflow-y-auto p-2">
      {visibleRows.length ? visibleRows.map(({ row, index }) => {
        const rowIssues = issues.filter(issue => issue.index === index)
        const identityIssue = rowIssues.some(issue => issue.field === 'student_id' || issue.field === 'full_name')
        const editing = identityIssue || editingRows.has(index)
        const description = `attendance-${levelCode}-${index}-issues`
        return <div key={index} className={cn('grid gap-2 rounded-lg px-2.5 py-2.5 sm:grid-cols-[minmax(0,1fr)_116px_112px] sm:items-center', rowIssues.length ? 'border border-destructive/30 bg-destructive/5' : row.present ? 'bg-primary/[.055]' : 'hover:bg-muted/35')}>
        <label className="focus-within:ring-ring flex min-w-0 cursor-pointer items-center gap-3 rounded-md focus-within:ring-2">
          <input data-workspace-field={`attendance.${index}.present`} type="checkbox" checked={row.present} onChange={(event) => updateRow(index, { present: event.target.checked, learning_method: event.target.checked ? (row.learning_method || 'พบกลุ่ม') : null })} className="size-4 accent-primary" />
          <span className="grid size-8 shrink-0 place-items-center rounded-md bg-secondary text-xs font-bold text-secondary-foreground">{index + 1}</span>
          <span className="min-w-0"><b className="block truncate text-sm">{row.full_name || 'ยังไม่มีชื่อผู้เรียน'}</b><small className="block truncate text-muted-foreground">{row.student_id || 'ยังไม่มีรหัสนักศึกษา'}</small></span>
          {row.present && <Check size={17} className="ml-auto shrink-0 text-primary sm:hidden" weight="bold" />}
        </label>
        <Badge className="w-fit" variant={row.present ? 'default' : 'outline'}>{row.present ? 'มาเรียน' : 'ขาดเรียน'}</Badge>
        <NativeSelect data-workspace-field={`attendance.${index}.learning_method`} aria-label={`วิธีเรียนของ ${row.full_name || `ลำดับ ${index + 1}`}`} aria-invalid={rowIssues.some(issue => issue.field === 'learning_method')} aria-describedby={rowIssues.length ? description : undefined} value={row.learning_method || 'พบกลุ่ม'} disabled={!row.present} onChange={(event) => updateRow(index, { learning_method: event.target.value })} className="h-9 text-xs">
          {row.learning_method && !['พบกลุ่ม', 'ออนไลน์'].includes(row.learning_method) && <option value={row.learning_method} disabled>ค่าเดิม: {row.learning_method}</option>}
          <option value="พบกลุ่ม">พบกลุ่ม</option><option value="ออนไลน์">ออนไลน์</option>
        </NativeSelect>
        {editing && <div className="grid gap-2 sm:col-span-3 sm:grid-cols-2">
          <label className="grid gap-1 text-xs">รหัสนักศึกษา ลำดับ {index + 1}<Input data-workspace-field={`attendance.${index}.student_id`} aria-invalid={rowIssues.some(issue => issue.field === 'student_id')} aria-describedby={rowIssues.length ? description : undefined} maxLength={50} value={row.student_id} onFocus={() => setEditingRows(current => new Set(current).add(index))} onChange={event => updateRow(index, { student_id: event.target.value })} /></label>
          <label className="grid gap-1 text-xs">ชื่อผู้เรียน ลำดับ {index + 1}<Input data-workspace-field={`attendance.${index}.full_name`} aria-invalid={rowIssues.some(issue => issue.field === 'full_name')} aria-describedby={rowIssues.length ? description : undefined} maxLength={255} value={row.full_name} onFocus={() => setEditingRows(current => new Set(current).add(index))} onChange={event => updateRow(index, { full_name: event.target.value })} /></label>
        </div>}
        {rowIssues.length > 0 && <div id={description} className="grid gap-1 text-xs text-destructive sm:col-span-3"><ul className="grid gap-1">{rowIssues.map(issue => <li key={issue.field}>{issue.message}</li>)}</ul>{rowIssues.some(issue => issue.field === 'group_code') && <Button data-workspace-field={`attendance.${index}.group_code`} type="button" variant="outline" size="sm" className="w-fit" onClick={onReload}>อัปเดตรายชื่อจากกลุ่มที่เลือก</Button>}</div>}
      </div>}) : <div className="grid min-h-24 place-items-center text-sm text-muted-foreground">ไม่พบรายชื่อที่ค้นหา</div>}
    </div>

  </div>
}
