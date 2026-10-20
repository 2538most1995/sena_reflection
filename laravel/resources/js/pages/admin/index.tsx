import { appFetch as fetch, appUrl } from '@/lib/app-url'
import { appRouter as router } from '@/lib/app-router'
import { Head, useForm, usePage } from '@inertiajs/react'
import * as Tabs from '@radix-ui/react-tabs'
import { ArrowDown, ArrowLeft, ArrowRight, ArrowUp, ArrowsOutCardinal, Buildings, CornersOut, FileText, FloppyDisk, ImageSquare, ListChecks, Minus, MouseSimple, Plus, Trash, UsersThree, WarningCircle } from '@phosphor-icons/react'
import { FormEvent, useEffect, useRef, useState } from 'react'
import { AppShell, PageHeader } from '@/components/app-shell'
import { FlashMessages } from '@/components/flash-messages'
import { FormField } from '@/components/form-field'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { NativeSelect } from '@/components/ui/native-select'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import type { SharedProps, Tambon, User } from '@/types'

type Props = { users: User[]; tambons: Tambon[]; settings: Record<string, string> }

function NewUser({ tambons }: { tambons: Tambon[] }) {
  const form = useForm({ name: '', username: '', password: '', role: 'tambon', tambon: '' })
  const submit = (event: FormEvent) => { event.preventDefault(); form.post(appUrl('/admin/users'), { preserveScroll: true, onSuccess: () => form.reset() }) }
  return <form onSubmit={submit} className="grid gap-3 rounded-xl bg-muted/35 p-4 md:grid-cols-2 xl:grid-cols-[1.2fr_1fr_1fr_160px_1fr_auto]"><FormField label="ชื่อ-สกุล" error={form.errors.name}><Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required /></FormField><FormField label="ชื่อผู้ใช้" error={form.errors.username}><Input value={form.data.username} onChange={(event) => form.setData('username', event.target.value)} required /></FormField><FormField label="รหัสผ่าน" error={form.errors.password}><Input type="password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} placeholder="อย่างน้อย 8 ตัว" required /></FormField><FormField label="สิทธิ์"><NativeSelect value={form.data.role} onChange={(event) => form.setData('role', event.target.value)}><option value="tambon">ระดับตำบล</option><option value="district">ระดับอำเภอ</option></NativeSelect></FormField><FormField label="ตำบล" error={form.errors.tambon}><NativeSelect value={form.data.tambon} onChange={(event) => form.setData('tambon', event.target.value)} disabled={form.data.role === 'district'}><option value="">เลือกตำบล</option>{tambons.map((tambon) => <option key={tambon.id} value={tambon.id}>{tambon.name}</option>)}</NativeSelect></FormField><div className="flex items-end"><Button type="submit" disabled={form.processing}><Plus size={17} />เพิ่มผู้ใช้</Button></div></form>
}

function UserRow({ user, tambons, self }: { user: User; tambons: Tambon[]; self: boolean }) {
  const form = useForm({ _method: 'put', name: user.name, username: user.username, password: '', role: user.role, tambon: user.tambon || '' })
  const submit = (event: FormEvent) => { event.preventDefault(); form.post(appUrl(`/admin/users/${user.id}`), { preserveScroll: true, onSuccess: () => form.reset('password') }) }
  const destroy = () => { if (window.confirm(`ยืนยันลบบัญชี ${user.name}`)) router.delete(`/admin/users/${user.id}`, { preserveScroll: true }) }
  return <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4 md:grid-cols-2 xl:grid-cols-[1.2fr_1fr_1fr_160px_1fr_auto] xl:items-end"><FormField label="ชื่อ-สกุล"><Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required /></FormField><FormField label="ชื่อผู้ใช้"><Input value={form.data.username} onChange={(event) => form.setData('username', event.target.value)} required /></FormField><FormField label="รหัสผ่านใหม่"><Input type="password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} placeholder="เว้นว่างหากไม่เปลี่ยน" /></FormField><FormField label="สิทธิ์"><NativeSelect value={form.data.role} onChange={(event) => form.setData('role', event.target.value as User['role'])}><option value="tambon">ระดับตำบล</option><option value="district">ระดับอำเภอ</option></NativeSelect></FormField><FormField label="ตำบล"><NativeSelect value={form.data.tambon} onChange={(event) => form.setData('tambon', event.target.value)} disabled={form.data.role === 'district'}><option value="">ไม่ระบุ</option>{tambons.map((tambon) => <option key={tambon.id} value={tambon.id}>{tambon.name}</option>)}</NativeSelect></FormField><div className="flex gap-1"><Button type="submit" variant="outline" size="icon" aria-label={`บันทึก ${user.name}`}><FloppyDisk size={17} /></Button>{user.role !== 'district' && !self && <Button type="button" variant="ghost" size="icon" className="text-destructive hover:bg-destructive/10 hover:text-destructive" onClick={destroy} aria-label={`ลบ ${user.name}`}><Trash size={17} /></Button>}</div></form>
}

function UsersPanel({ users, tambons, selfId }: { users: User[]; tambons: Tambon[]; selfId: string }) {
  return <Card><CardHeader><div className="flex items-center justify-between"><CardTitle>บัญชีผู้ใช้งาน</CardTitle><Badge variant="outline">{users.length} บัญชี</Badge></div><p className="text-sm text-muted-foreground">เพิ่มบัญชีและกำหนดขอบเขตข้อมูลระดับอำเภอหรือตำบล</p></CardHeader><CardContent className="grid gap-4"><NewUser tambons={tambons} /><div className="grid gap-3">{users.map((user) => <UserRow key={user.id} user={user} tambons={tambons} self={user.id === selfId} />)}</div></CardContent></Card>
}

function TambonsPanel({ tambons }: { tambons: Tambon[] }) {
  const create = useForm({ id: '', name: '', icon: '' })
  const submit = (event: FormEvent) => { event.preventDefault(); create.post(appUrl('/admin/tambons'), { preserveScroll: true, onSuccess: () => create.reset() }) }
  return <Card><CardHeader><div className="flex items-center justify-between"><CardTitle>ตำบลและกลุ่มเรียน</CardTitle><Badge variant="outline">{tambons.length} รายการ</Badge></div></CardHeader><CardContent className="grid gap-4"><form onSubmit={submit} className="grid gap-3 rounded-xl bg-muted/35 p-4 md:grid-cols-[1fr_1.5fr_1fr_auto]"><FormField label="รหัสตำบล" error={create.errors.id}><Input value={create.data.id} onChange={(event) => create.setData('id', event.target.value)} required /></FormField><FormField label="ชื่อตำบล" error={create.errors.name}><Input value={create.data.name} onChange={(event) => create.setData('name', event.target.value)} required /></FormField><FormField label="สัญลักษณ์" error={create.errors.icon}><Input value={create.data.icon} onChange={(event) => create.setData('icon', event.target.value)} placeholder="ไม่บังคับ" /></FormField><div className="flex items-end"><Button type="submit"><Plus size={17} />เพิ่มตำบล</Button></div></form><div className="grid gap-3 md:grid-cols-2">{tambons.map((tambon) => <TambonRow key={tambon.id} tambon={tambon} />)}</div></CardContent></Card>
}

function TambonRow({ tambon }: { tambon: Tambon }) {
  const form = useForm({ _method: 'put', name: tambon.name, icon: tambon.icon || '' })
  const submit = (event: FormEvent) => { event.preventDefault(); form.post(appUrl(`/admin/tambons/${tambon.id}`), { preserveScroll: true }) }
  const destroy = () => { if (window.confirm(`ยืนยันลบตำบล ${tambon.name}`)) router.delete(`/admin/tambons/${tambon.id}`, { preserveScroll: true }) }
  return <form onSubmit={submit} className="rounded-xl border p-4"><div className="mb-3 flex items-center justify-between"><code className="rounded-md bg-muted px-2 py-1 text-xs">{tambon.id}</code><Button type="button" variant="ghost" size="icon" className="text-destructive hover:bg-destructive/10 hover:text-destructive" onClick={destroy} aria-label={`ลบ ${tambon.name}`}><Trash size={16} /></Button></div><div className="grid gap-3 sm:grid-cols-[1fr_100px_auto]"><Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} aria-label="ชื่อตำบล" /><Input value={form.data.icon} onChange={(event) => form.setData('icon', event.target.value)} aria-label="สัญลักษณ์" /><Button type="submit" variant="outline" size="icon" aria-label={`บันทึก ${tambon.name}`}><FloppyDisk size={17} /></Button></div></form>
}

const settingDefaults: Record<string, string> = {
  sig1_name: '', sig1_pos: 'ครูผู้สอน', sig1_align: 'flex-end', sig1_offset: '0', sig1_offset_y: '0',
  sig2_title: 'ความคิดเห็นของหัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ', sig2_name: '', sig2_pos: 'หัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ', sig2_align: 'flex-end', sig2_offset: '0', sig2_offset_y: '0',
  sig3_title: 'ข้อคิดเห็นของผู้อำนวยการสถานศึกษา', sig3_name: '', sig3_pos: 'ผู้อำนวยการสถานศึกษา', sig3_align: 'center', sig3_offset: '0', sig3_offset_y: '0',
  pdf_font_size_pt: '16', pdf_line_spacing: '26', pdf_margin_top_px: '57', pdf_margin_right_px: '76', pdf_margin_bottom_px: '38', pdf_margin_left_px: '94',
  show_image_page: '1', show_attendance_page: '1',
  image_width_px: '300', image_height_px: '225', image_offset_x: '0', image_offset_y: '0', image_gap_px: '16',
  image_1_width_px: '300', image_1_height_px: '225', image_1_offset_x: '0', image_1_offset_y: '0',
  image_2_width_px: '300', image_2_height_px: '225', image_2_offset_x: '0', image_2_offset_y: '0',
  image_3_width_px: '300', image_3_height_px: '225', image_3_offset_x: '0', image_3_offset_y: '0',
  image_4_width_px: '300', image_4_height_px: '225', image_4_offset_x: '0', image_4_offset_y: '0',
}

const layoutTargets = {
  sig1: { kind: 'signature', label: 'ผู้บันทึก', x: 'sig1_offset', y: 'sig1_offset_y', align: 'sig1_align' },
  sig2: { kind: 'signature', label: 'หัวหน้างาน', x: 'sig2_offset', y: 'sig2_offset_y', align: 'sig2_align' },
  sig3: { kind: 'signature', label: 'ผู้อำนวยการ', x: 'sig3_offset', y: 'sig3_offset_y', align: 'sig3_align' },
  image1: { kind: 'image', label: 'รูปที่ 1', x: 'image_1_offset_x', y: 'image_1_offset_y', width: 'image_1_width_px', height: 'image_1_height_px' },
  image2: { kind: 'image', label: 'รูปที่ 2', x: 'image_2_offset_x', y: 'image_2_offset_y', width: 'image_2_width_px', height: 'image_2_height_px' },
  image3: { kind: 'image', label: 'รูปที่ 3', x: 'image_3_offset_x', y: 'image_3_offset_y', width: 'image_3_width_px', height: 'image_3_height_px' },
  image4: { kind: 'image', label: 'รูปที่ 4', x: 'image_4_offset_x', y: 'image_4_offset_y', width: 'image_4_width_px', height: 'image_4_height_px' },
} as const

type LayoutTarget = keyof typeof layoutTargets

function PageToggle({ checked, icon, label, description, onCheckedChange }: { checked: boolean; icon: React.ReactNode; label: string; description: string; onCheckedChange: (checked: boolean) => void }) {
  return <label className="flex cursor-pointer items-center gap-3 rounded-xl border bg-background p-3 transition-colors hover:bg-muted/35">
    <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-secondary text-secondary-foreground">{icon}</span>
    <span className="min-w-0 flex-1"><b className="block text-sm">{label}</b><small className="block text-muted-foreground">{description}</small></span>
    <Switch checked={checked} onCheckedChange={onCheckedChange} aria-label={`${checked ? 'ปิด' : 'เปิด'}${label}`} />
  </label>
}

function SettingsPanel({ settings }: { settings: Record<string, string> }) {
  const initial = Object.fromEntries(Object.entries(settingDefaults).map(([key, fallback]) => [key, settings[key] ?? fallback])) as Record<string, string>
  const form = useForm<{ _method: string; settings: Record<string, string> }>({ _method: 'put', settings: initial })
  const [target, setTarget] = useState<LayoutTarget>('sig1')
  const [step, setStep] = useState(5)
  const [preview, setPreview] = useState('')
  const [previewError, setPreviewError] = useState<string | null>(null)
  const [previewing, setPreviewing] = useState(true)
  const [zoom, setZoom] = useState(0.82)
  const iframeRef = useRef<HTMLIFrameElement>(null)
  const position = layoutTargets[target]

  const setSetting = (key: string, value: string) => form.setData('settings', { ...form.data.settings, [key]: value })
  const nudge = (key: string, amount: number) => setSetting(key, String((Number(form.data.settings[key]) || 0) + amount))
  const resetTarget = () => {
    const changes: Record<string, string> = { [position.x]: '0', [position.y]: '0' }
    if (position.kind === 'image') {
      changes[position.width] = settingDefaults[position.width]
      changes[position.height] = settingDefaults[position.height]
    }
    form.setData('settings', { ...form.data.settings, ...changes })
  }
  const submit = (event: FormEvent) => { event.preventDefault(); form.post(appUrl('/admin/settings'), { preserveScroll: true }) }

  useEffect(() => {
    const controller = new AbortController()
    const timer = window.setTimeout(async () => {
      setPreviewing(true)
      setPreviewError(null)
      try {
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || ''
        const response = await fetch('/admin/pdf-preview', {
          method: 'POST',
          signal: controller.signal,
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
          body: JSON.stringify({ settings: form.data.settings }),
        })
        if (!response.ok) {
          const payload = await response.json().catch(() => null)
          throw new Error(payload?.message || 'สร้างตัวอย่าง PDF ไม่สำเร็จ')
        }
        setPreview(await response.text())
      } catch (error) {
        if (!controller.signal.aborted) setPreviewError(error instanceof Error ? error.message : 'สร้างตัวอย่าง PDF ไม่สำเร็จ')
      } finally {
        if (!controller.signal.aborted) setPreviewing(false)
      }
    }, 650)
    return () => { window.clearTimeout(timer); controller.abort() }
  }, [form.data.settings])

  useEffect(() => {
    const receiveLayoutChange = (event: MessageEvent) => {
      if (event.source !== iframeRef.current?.contentWindow || event.data?.source !== 'sena-pdf-layout') return
      const matchingTarget = Object.entries(layoutTargets).find(([, value]) => value.label === event.data?.target?.label)?.[0] as LayoutTarget | undefined
      if (matchingTarget) setTarget(matchingTarget)
      const changes = Object.fromEntries(Object.entries(event.data?.changes || {}).filter(([key, value]) => key in settingDefaults && typeof value === 'string')) as Record<string, string>
      if (Object.keys(changes).length) form.setData('settings', { ...form.data.settings, ...changes })
    }
    window.addEventListener('message', receiveLayoutChange)
    return () => window.removeEventListener('message', receiveLayoutChange)
  }, [form, form.data.settings])

  return <form onSubmit={submit} className="grid gap-5">
    <div className="flex flex-col gap-4 rounded-xl border bg-card p-5 sm:flex-row sm:items-center sm:justify-between">
      <div><h2 className="text-lg font-semibold">จัดหน้าเอกสารก่อนสร้าง PDF</h2><p className="mt-1 text-sm text-muted-foreground">ลากองค์ประกอบในตัวอย่าง ปรับขนาดรูปจากจุดมุม แล้วบันทึกเมื่อจัดหน้าเรียบร้อย</p></div>
      <div className="flex flex-wrap items-center gap-2"><Badge variant="secondary" className={previewError ? 'bg-destructive/10 text-destructive' : undefined}>{previewing ? 'กำลังอัปเดต' : previewError ? 'ตัวอย่างมีปัญหา' : 'พร้อมจัดหน้า'}</Badge><Button type="submit" size="lg" disabled={form.processing}><FloppyDisk size={18} />{form.processing ? 'กำลังบันทึก...' : 'บันทึกรูปแบบ PDF'}</Button></div>
    </div>

    <div className="grid gap-5 xl:grid-cols-[370px_minmax(0,1fr)]">
      <div className="grid content-start gap-4">
        <Card>
          <CardHeader className="pb-4"><CardTitle className="flex items-center gap-2 text-base"><MouseSimple size={19} className="text-primary" />เปิดหรือปิดหน้าเอกสาร</CardTitle><p className="text-sm text-muted-foreground">หน้าที่ปิดจะไม่รวมใน PDF ที่ดาวน์โหลด</p></CardHeader>
          <CardContent className="grid gap-2">
            <PageToggle checked={form.data.settings.show_image_page === '1'} icon={<ImageSquare size={19} />} label="หน้ารูปภาพ" description="ภาพประกอบการจัดการเรียนการสอน" onCheckedChange={(checked) => setSetting('show_image_page', checked ? '1' : '0')} />
            <PageToggle checked={form.data.settings.show_attendance_page === '1'} icon={<ListChecks size={19} />} label="หน้ารายชื่อ" description="บัญชีรายชื่อและลายมือชื่อผู้เรียน" onCheckedChange={(checked) => setSetting('show_attendance_page', checked ? '1' : '0')} />
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-4"><CardTitle className="flex items-center gap-2 text-base"><ArrowsOutCardinal size={19} className="text-primary" />องค์ประกอบที่เลือก</CardTitle><p className="text-sm text-muted-foreground">คลิกองค์ประกอบในกระดาษ หรือเลือกจากรายการนี้</p></CardHeader>
          <CardContent className="grid gap-4">
            <FormField label="เลือกองค์ประกอบ"><NativeSelect value={target} onChange={(event) => setTarget(event.target.value as LayoutTarget)}><optgroup label="ลายเซ็น">{Object.entries(layoutTargets).filter(([, value]) => value.kind === 'signature').map(([key, value]) => <option key={key} value={key}>{value.label}</option>)}</optgroup><optgroup label="รูปภาพ">{Object.entries(layoutTargets).filter(([, value]) => value.kind === 'image').map(([key, value]) => <option key={key} value={key}>{value.label}</option>)}</optgroup></NativeSelect></FormField>
            <div className="grid grid-cols-2 gap-3"><FormField label="ตำแหน่ง X" error={form.errors[`settings.${position.x}`]}><Input type="number" min={position.kind === 'image' ? -220 : -400} max={position.kind === 'image' ? 220 : 400} value={form.data.settings[position.x]} onChange={(event) => setSetting(position.x, event.target.value)} /></FormField><FormField label="ตำแหน่ง Y" error={form.errors[`settings.${position.y}`]}><Input type="number" min={position.kind === 'image' ? -220 : -400} max={position.kind === 'image' ? 220 : 400} value={form.data.settings[position.y]} onChange={(event) => setSetting(position.y, event.target.value)} /></FormField></div>
            {position.kind === 'image' && <div className="grid grid-cols-2 gap-3"><FormField label="ความกว้าง" error={form.errors[`settings.${position.width}`]}><Input type="number" min={180} max={620} value={form.data.settings[position.width]} onChange={(event) => setSetting(position.width, event.target.value)} /></FormField><FormField label="ความสูง" error={form.errors[`settings.${position.height}`]}><Input type="number" min={120} max={560} value={form.data.settings[position.height]} onChange={(event) => setSetting(position.height, event.target.value)} /></FormField></div>}
            {position.kind === 'signature' && <FormField label="การจัดแนว"><NativeSelect value={form.data.settings[position.align]} onChange={(event) => setSetting(position.align, event.target.value)}><option value="flex-start">ชิดซ้าย</option><option value="center">กึ่งกลาง</option><option value="flex-end">ชิดขวา</option></NativeSelect></FormField>}
            <div className="grid grid-cols-[1fr_auto] gap-3"><div className="grid grid-cols-3 gap-2"><span /><Button type="button" variant="outline" size="icon" onClick={() => nudge(position.y, -step)} aria-label="เลื่อนขึ้น"><ArrowUp size={18} /></Button><span /><Button type="button" variant="outline" size="icon" onClick={() => nudge(position.x, -step)} aria-label="เลื่อนซ้าย"><ArrowLeft size={18} /></Button><Button type="button" variant="secondary" size="icon" onClick={resetTarget} aria-label="รีเซ็ตองค์ประกอบ">0</Button><Button type="button" variant="outline" size="icon" onClick={() => nudge(position.x, step)} aria-label="เลื่อนขวา"><ArrowRight size={18} /></Button><span /><Button type="button" variant="outline" size="icon" onClick={() => nudge(position.y, step)} aria-label="เลื่อนลง"><ArrowDown size={18} /></Button><span /></div><FormField label="ระยะ"><NativeSelect value={String(step)} onChange={(event) => setStep(Number(event.target.value))}><option value="1">1 px</option><option value="5">5 px</option><option value="10">10 px</option></NativeSelect></FormField></div>
          </CardContent>
        </Card>

        <details open className="rounded-xl border bg-card p-4"><summary className="focus-ring cursor-pointer rounded-md font-semibold">รูปแบบหน้ากระดาษ</summary><div className="mt-4 grid grid-cols-2 gap-3"><FormField label="ฟอนต์ (pt)" error={form.errors['settings.pdf_font_size_pt']}><Input type="number" min={10} max={20} value={form.data.settings.pdf_font_size_pt} onChange={(event) => setSetting('pdf_font_size_pt', event.target.value)} /></FormField><FormField label="ระยะบรรทัด" error={form.errors['settings.pdf_line_spacing']}><Input type="number" min={16} max={44} value={form.data.settings.pdf_line_spacing} onChange={(event) => setSetting('pdf_line_spacing', event.target.value)} /></FormField>{[['pdf_margin_top_px', 'ขอบบน'], ['pdf_margin_right_px', 'ขอบขวา'], ['pdf_margin_bottom_px', 'ขอบล่าง'], ['pdf_margin_left_px', 'ขอบซ้าย']].map(([key, label]) => <FormField key={key} label={`${label} (px)`} error={form.errors[`settings.${key}`]}><Input type="number" min={0} max={180} value={form.data.settings[key]} onChange={(event) => setSetting(key, event.target.value)} /></FormField>)}</div></details>

        <details className="rounded-xl border bg-card p-4"><summary className="focus-ring cursor-pointer rounded-md font-semibold">ชื่อและตำแหน่งผู้ลงนาม</summary><div className="mt-4 grid gap-4">{[
          ['sig1', 'ผู้บันทึก'], ['sig2', 'หัวหน้างาน'], ['sig3', 'ผู้อำนวยการ'],
        ].map(([prefix, label]) => <div key={prefix} className="grid gap-3 rounded-xl bg-muted/35 p-3"><b className="text-sm">{label}</b>{prefix !== 'sig1' && <FormField label="หัวข้อ"><Input value={form.data.settings[`${prefix}_title`]} onChange={(event) => setSetting(`${prefix}_title`, event.target.value)} /></FormField>}<FormField label="ชื่อ"><Input value={form.data.settings[`${prefix}_name`]} onChange={(event) => setSetting(`${prefix}_name`, event.target.value)} /></FormField><FormField label="ตำแหน่ง"><Textarea rows={2} value={form.data.settings[`${prefix}_pos`]} onChange={(event) => setSetting(`${prefix}_pos`, event.target.value)} /></FormField></div>)}</div></details>

        <details className="rounded-xl border bg-card p-4"><summary className="focus-ring cursor-pointer rounded-md font-semibold">ระยะห่างของรูปภาพ</summary><div className="mt-4"><FormField label="ระยะห่างระหว่างรูป (px)" error={form.errors['settings.image_gap_px']}><Input type="number" min={0} max={72} value={form.data.settings.image_gap_px} onChange={(event) => setSetting('image_gap_px', event.target.value)} /></FormField></div></details>
      </div>

      <Card className="min-w-0 xl:sticky xl:top-20 xl:self-start">
        <CardHeader className="gap-3 border-b py-3 sm:flex-row sm:items-center sm:justify-between"><div><CardTitle className="flex items-center gap-2 text-base"><CornersOut size={19} className="text-primary" />กระดาษแบบลากวาง</CardTitle><p className="text-xs text-muted-foreground">ลากกรอบเส้นประเพื่อย้ายรูปหรือลายเซ็น ลากจุดมุมของรูปเพื่อย่อ ขยาย หรือยืด</p></div><div className="flex items-center gap-1"><Button type="button" variant="ghost" size="icon" onClick={() => setZoom(Math.max(0.5, zoom - 0.1))} aria-label="ซูมออก"><Minus size={17} /></Button><button type="button" className="focus-ring min-w-14 rounded-md px-2 py-1 text-xs font-semibold" onClick={() => setZoom(0.82)}>{Math.round(zoom * 100)}%</button><Button type="button" variant="ghost" size="icon" onClick={() => setZoom(Math.min(1.2, zoom + 0.1))} aria-label="ซูมเข้า"><Plus size={17} /></Button></div></CardHeader>
        <CardContent className="p-0">
          {previewError && <div className="m-4 flex items-start gap-2 rounded-xl border border-destructive/25 bg-destructive/5 p-4 text-sm text-destructive"><WarningCircle size={19} className="mt-0.5 shrink-0" />{previewError}</div>}
          <div className="relative h-[76vh] min-h-[640px] overflow-auto bg-muted/55 p-3 sm:p-4">
            {previewing && <div className="absolute inset-x-4 top-4 z-10 h-1.5 overflow-hidden rounded-full bg-muted"><span className="block h-full w-1/2 animate-pulse rounded-full bg-primary" /></div>}
            {preview && <iframe ref={iframeRef} title="ตัวจัดหน้า PDF แบบลากวาง" srcDoc={preview} sandbox="allow-scripts" className="border-0 bg-background shadow-[0_18px_50px_rgba(20,58,55,0.12)]" style={{ width: `${100 / zoom}%`, height: `${980 / zoom}px`, transform: `scale(${zoom})`, transformOrigin: 'top left' }} />}
          </div>
        </CardContent>
      </Card>
    </div>
  </form>
}

export default function Admin({ users, tambons, settings }: Props) {
  const selfId = usePage<SharedProps>().props.auth.user?.id || ''
  return <AppShell><Head title="จัดการระบบ" /><PageHeader title="จัดการระบบ" description="ดูแลบัญชี ตำบล และรูปแบบเอกสารจากจุดเดียว" /><FlashMessages /><Tabs.Root defaultValue="users"><Tabs.List className="mb-5 grid grid-cols-3 gap-2 rounded-xl bg-muted p-1.5"><Tabs.Trigger value="users" className="focus-ring flex items-center justify-center gap-2 rounded-lg px-3 py-2.5 text-sm font-semibold text-muted-foreground data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow-sm"><UsersThree size={18} />ผู้ใช้งาน</Tabs.Trigger><Tabs.Trigger value="tambons" className="focus-ring flex items-center justify-center gap-2 rounded-lg px-3 py-2.5 text-sm font-semibold text-muted-foreground data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow-sm"><Buildings size={18} />ตำบล</Tabs.Trigger><Tabs.Trigger value="document" className="focus-ring flex items-center justify-center gap-2 rounded-lg px-3 py-2.5 text-sm font-semibold text-muted-foreground data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow-sm"><FileText size={18} />เอกสาร</Tabs.Trigger></Tabs.List><Tabs.Content value="users" className="outline-none"><UsersPanel users={users} tambons={tambons} selfId={selfId} /></Tabs.Content><Tabs.Content value="tambons" className="outline-none"><TambonsPanel tambons={tambons} /></Tabs.Content><Tabs.Content value="document" className="outline-none"><SettingsPanel settings={settings} /></Tabs.Content></Tabs.Root></AppShell>
}
