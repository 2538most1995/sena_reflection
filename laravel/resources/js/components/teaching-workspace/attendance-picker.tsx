import { Check, MagnifyingGlass, Student, UsersThree, WarningCircle } from '@phosphor-icons/react'
import { useMemo, useState } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { NativeSelect } from '@/components/ui/native-select'
import { cn } from '@/lib/utils'
import type { WorkspaceAttendanceRow, WorkspaceLoadState } from './types'

type Props = {
  rows: WorkspaceAttendanceRow[]
  reviewed: boolean
  state: WorkspaceLoadState
  onChange: (rows: WorkspaceAttendanceRow[], reviewed?: boolean) => void
}

export function AttendancePicker({ rows, reviewed, state, onChange }: Props) {
  const [search, setSearch] = useState('')
  const normalizedSearch = search.trim().toLocaleLowerCase('th')
  const visibleRows = useMemo(() => rows.map((row, index) => ({ row, index })).filter(({ row }) => {
    if (!normalizedSearch) return true
    return `${row.student_id} ${row.full_name}`.toLocaleLowerCase('th').includes(normalizedSearch)
  }), [normalizedSearch, rows])
  const selected = rows.filter((row) => row.present).length

  const updateRow = (index: number, patch: Partial<WorkspaceAttendanceRow>) => {
    onChange(rows.map((row, current) => current === index ? { ...row, ...patch } : row), false)
  }

  const setEveryonePresent = () => {
    if (!window.confirm(`ยืนยันว่าตรวจรายชื่อแล้ว และนักศึกษาทั้ง ${rows.length} คนมาเรียนทั้งหมด`)) return
    onChange(rows.map((row) => ({ ...row, present: true, learning_method: row.learning_method || 'พบกลุ่ม' })), true)
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
        <div className="flex items-center gap-2"><UsersThree size={19} className="text-primary" /><b className="text-sm">มาเรียน {selected} จาก {rows.length} คน</b><Badge variant={reviewed ? 'secondary' : 'outline'}>{reviewed ? 'ตรวจแล้ว' : 'รอตรวจ'}</Badge></div>
        <div className="flex gap-2"><Button type="button" variant="outline" size="sm" onClick={setEveryonePresent}>ยืนยันมาทั้งหมด</Button><Button type="button" variant="ghost" size="sm" onClick={() => onChange(rows.map((row) => ({ ...row, present: false, learning_method: null })), true)}>ทำเครื่องหมายขาดทั้งหมด</Button></div>
      </div>
      {!reviewed && <p className="rounded-lg bg-amber-500/10 px-3 py-2 text-xs font-medium text-amber-800 dark:text-amber-200">ระบบยังไม่เลือก “มาเรียน” ให้อัตโนมัติ กรุณาตรวจรายชื่อหรือกดยืนยันก่อนส่ง</p>}
      <div className="relative"><MagnifyingGlass size={17} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" /><Input value={search} onChange={(event) => setSearch(event.target.value)} className="h-10 pl-9" placeholder="ค้นหาชื่อหรือรหัสนักศึกษา" aria-label="ค้นหารายชื่อนักศึกษา" /></div>
    </div>
    <div className="max-h-[420px] overflow-y-auto p-2">
      {visibleRows.length ? visibleRows.map(({ row, index }) => <div key={row.student_id} className={cn('grid gap-2 rounded-lg px-2.5 py-2.5 sm:grid-cols-[minmax(0,1fr)_116px_112px] sm:items-center', row.present ? 'bg-primary/[.055]' : 'hover:bg-muted/35')}>
        <label className="focus-within:ring-ring flex min-w-0 cursor-pointer items-center gap-3 rounded-md focus-within:ring-2">
          <input type="checkbox" checked={row.present} onChange={(event) => updateRow(index, { present: event.target.checked, learning_method: event.target.checked ? (row.learning_method || 'พบกลุ่ม') : null })} className="size-4 accent-primary" />
          <span className="grid size-8 shrink-0 place-items-center rounded-md bg-secondary text-xs font-bold text-secondary-foreground">{index + 1}</span>
          <span className="min-w-0"><b className="block truncate text-sm">{row.full_name}</b><small className="block truncate text-muted-foreground">{row.student_id}</small></span>
          {row.present && <Check size={17} className="ml-auto shrink-0 text-primary sm:hidden" weight="bold" />}
        </label>
        <Badge className="w-fit" variant={row.present ? 'default' : 'outline'}>{row.present ? 'มาเรียน' : 'ขาดเรียน'}</Badge>
        <NativeSelect aria-label={`วิธีเรียนของ ${row.full_name}`} value={row.learning_method || 'พบกลุ่ม'} disabled={!row.present} onChange={(event) => updateRow(index, { learning_method: event.target.value as WorkspaceAttendanceRow['learning_method'] })} className="h-9 text-xs">
          <option value="พบกลุ่ม">พบกลุ่ม</option><option value="ออนไลน์">ออนไลน์</option>
        </NativeSelect>
      </div>) : <div className="grid min-h-24 place-items-center text-sm text-muted-foreground">ไม่พบรายชื่อที่ค้นหา</div>}
    </div>
    <div className="border-t p-3"><Button type="button" className="w-full" variant={reviewed ? 'secondary' : 'outline'} onClick={() => onChange(rows, !reviewed)}><Check size={17} weight="bold" />{reviewed ? `ตรวจรายชื่อแล้ว มาเรียน ${selected} คน` : `ยืนยันตรวจรายชื่อ มาเรียน ${selected} คน`}</Button></div>
  </div>
}
