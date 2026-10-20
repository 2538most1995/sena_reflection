import { Head, useForm } from '@inertiajs/react'
import { Camera, FloppyDisk, Key, UserCircle } from '@phosphor-icons/react'
import { FormEvent } from 'react'
import { AppShell, PageHeader } from '@/components/app-shell'
import { FlashMessages } from '@/components/flash-messages'
import { FormField } from '@/components/form-field'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import type { User } from '@/types'

type ProfileData = { _method: 'put'; name: string; avatar: File | null; current_password: string; password: string; password_confirmation: string }

export default function Profile({ user }: { user: User }) {
  const form = useForm<ProfileData>({ _method: 'put', name: user.name, avatar: null, current_password: '', password: '', password_confirmation: '' })
  const submit = (event: FormEvent) => { event.preventDefault(); form.post('/profile', { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset('current_password', 'password', 'password_confirmation') }) }
  return <AppShell>
    <Head title="โปรไฟล์" />
    <PageHeader title="โปรไฟล์และรหัสผ่าน" description="แก้ไขชื่อ รูปโปรไฟล์ หรือเปลี่ยนรหัสผ่านของคุณ" />
    <FlashMessages />
    <form onSubmit={submit} className="grid gap-6 lg:grid-cols-[300px_minmax(0,1fr)]">
      <Card className="h-fit"><CardHeader><CardTitle>รูปโปรไฟล์</CardTitle></CardHeader><CardContent><div className="mx-auto grid size-36 place-items-center overflow-hidden rounded-xl bg-secondary text-5xl font-bold text-secondary-foreground">{user.avatar_path ? <img src={`/${user.avatar_path}`} alt={`รูปโปรไฟล์ ${user.name}`} className="size-full object-cover" /> : user.name.slice(0, 1)}</div><label className="mt-5 flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-lg border bg-background px-4 text-sm font-semibold hover:bg-accent"><Camera size={18} />{form.data.avatar ? form.data.avatar.name : 'เลือกรูปใหม่'}<input type="file" className="sr-only" accept="image/jpeg,image/png,image/webp" onChange={(event) => form.setData('avatar', event.target.files?.[0] || null)} /></label>{form.errors.avatar && <p className="mt-2 text-xs text-destructive">{form.errors.avatar}</p>}<p className="mt-3 text-center text-xs leading-5 text-muted-foreground">JPG, PNG หรือ WEBP ขนาดไม่เกิน 3 MB</p></CardContent></Card>
      <div className="grid gap-6"><Card><CardHeader className="flex-row items-center gap-3"><UserCircle size={24} className="text-primary" /><div><CardTitle>ข้อมูลบัญชี</CardTitle><p className="mt-1 text-sm text-muted-foreground">ชื่อที่แสดงในระบบและเอกสาร</p></div></CardHeader><CardContent className="grid gap-4 md:grid-cols-2"><FormField label="ชื่อ-สกุล *" error={form.errors.name}><Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required /></FormField><FormField label="ชื่อผู้ใช้"><Input value={user.username} disabled /></FormField></CardContent></Card>
        <Card><CardHeader className="flex-row items-center gap-3"><Key size={24} className="text-primary" /><div><CardTitle>เปลี่ยนรหัสผ่าน</CardTitle><p className="mt-1 text-sm text-muted-foreground">เว้นว่างไว้หากยังไม่ต้องการเปลี่ยน</p></div></CardHeader><CardContent className="grid gap-4"><FormField label="รหัสผ่านปัจจุบัน" error={form.errors.current_password}><Input type="password" value={form.data.current_password} onChange={(event) => form.setData('current_password', event.target.value)} autoComplete="current-password" /></FormField><div className="grid gap-4 md:grid-cols-2"><FormField label="รหัสผ่านใหม่" error={form.errors.password}><Input type="password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} autoComplete="new-password" /></FormField><FormField label="ยืนยันรหัสผ่านใหม่" error={form.errors.password_confirmation}><Input type="password" value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} autoComplete="new-password" /></FormField></div></CardContent></Card>
        <div className="flex justify-end"><Button type="submit" size="lg" disabled={form.processing}><FloppyDisk size={18} />{form.processing ? 'กำลังบันทึก...' : 'บันทึกโปรไฟล์'}</Button></div>
      </div>
    </form>
  </AppShell>
}
