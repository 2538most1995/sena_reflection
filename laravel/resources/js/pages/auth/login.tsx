import { appUrl } from '@/lib/app-url'
import { Head, useForm } from '@inertiajs/react'
import { ArrowRight, Eye, EyeSlash, LockKey, ShieldCheck, User } from '@phosphor-icons/react'
import { FormEvent, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { FormField } from '@/components/form-field'
import { ThemeToggle } from '@/components/theme-toggle'

export default function Login() {
  const [showPassword, setShowPassword] = useState(false)
  const form = useForm({ username: '', password: '' })
  const submit = (event: FormEvent) => {
    event.preventDefault()
    form.post(appUrl('/login'), { onFinish: () => form.reset('password') })
  }

  return <>
    <Head title="เข้าสู่ระบบ" />
    <main className="relative grid min-h-[100dvh] bg-background lg:grid-cols-[minmax(0,1.06fr)_minmax(440px,.94fr)]">
      <div className="absolute right-4 top-4 z-20"><ThemeToggle /></div>
      <section className="surface-grid relative hidden overflow-hidden border-r bg-primary px-12 py-10 text-primary-foreground lg:flex lg:flex-col lg:justify-between xl:px-20">
        <div className="absolute inset-0 bg-[radial-gradient(circle_at_76%_20%,rgba(255,255,255,.12),transparent_34%)]" />
        <div className="relative flex items-center gap-3"><span className="grid size-11 place-items-center rounded-lg border border-white/30 bg-white/10 text-sm font-bold">SR</span><span><b className="block">SENA Reflection</b><small className="text-white/72">ระบบบันทึกหลังการสอน</small></span></div>
        <div className="relative max-w-2xl pb-10">
          <h1 className="max-w-xl text-4xl font-bold leading-[1.18] tracking-[-0.05em] xl:text-5xl">จัดการบันทึกรายสัปดาห์ให้ครบและตรวจสอบได้</h1>
          <p className="mt-5 max-w-xl text-base leading-8 text-white/78">บันทึกเฉพาะระดับชั้นที่สอน ติดตามการเข้าเรียน และดาวน์โหลดเอกสารรูปแบบราชการจากระบบเดียว</p>
          <div className="mt-10 grid max-w-xl grid-cols-2 gap-3">
            <div className="rounded-xl border border-white/20 bg-white/8 p-4"><ShieldCheck size={24} weight="duotone" /><b className="mt-6 block text-sm">ข้อมูลตามสิทธิ์</b><p className="mt-1 text-xs leading-5 text-white/70">แยกการเข้าถึงระดับอำเภอและตำบล</p></div>
            <div className="rounded-xl border border-white/20 bg-white/8 p-4"><LockKey size={24} weight="duotone" /><b className="mt-6 block text-sm">เอกสารพร้อมใช้</b><p className="mt-1 text-xs leading-5 text-white/70">สร้าง PDF ตามรูปแบบบันทึกเดิม</p></div>
          </div>
        </div>
        <p className="relative text-xs text-white/55">ศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอเสนา</p>
      </section>

      <section className="flex items-center justify-center px-4 py-10 sm:px-8 lg:px-12">
        <div className="w-full max-w-md">
          <div className="mb-8 flex items-center gap-3 lg:hidden"><span className="grid size-10 place-items-center rounded-lg bg-primary text-sm font-bold text-primary-foreground">SR</span><span><b className="block text-sm">SENA Reflection</b><small className="text-xs text-muted-foreground">ระบบบันทึกหลังการสอน</small></span></div>
          <Card className="border-border/90 shadow-[0_24px_80px_rgba(20,58,55,0.09)]">
            <CardHeader className="pb-4">
              <CardTitle className="text-2xl">เข้าสู่ระบบ</CardTitle>
              <CardDescription>กรอกบัญชีผู้ใช้งานเพื่อเริ่มจัดการข้อมูล</CardDescription>
            </CardHeader>
            <CardContent>
              <form onSubmit={submit} className="grid gap-5">
                <FormField label="ชื่อผู้ใช้" htmlFor="username" error={form.errors.username}>
                  <div className="relative"><User size={18} className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-muted-foreground" /><Input id="username" name="username" value={form.data.username} onChange={(event) => form.setData('username', event.target.value)} autoComplete="username" autoFocus required className="pl-10" placeholder="กรอกชื่อผู้ใช้" /></div>
                </FormField>
                <FormField label="รหัสผ่าน" htmlFor="password" error={form.errors.password}>
                  <div className="relative"><LockKey size={18} className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-muted-foreground" /><Input id="password" name="password" type={showPassword ? 'text' : 'password'} value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} autoComplete="current-password" required className="px-10" placeholder="กรอกรหัสผ่าน" /><button type="button" onClick={() => setShowPassword(!showPassword)} className="focus-ring absolute right-2 top-1/2 grid size-8 -translate-y-1/2 place-items-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground" aria-label={showPassword ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน'}>{showPassword ? <EyeSlash size={18} /> : <Eye size={18} />}</button></div>
                </FormField>
                <Button type="submit" size="lg" disabled={form.processing} className="mt-1 w-full">{form.processing ? 'กำลังเข้าสู่ระบบ...' : 'เข้าสู่ระบบ'}<ArrowRight size={18} /></Button>
              </form>
            </CardContent>
          </Card>
          <p className="mt-5 text-center text-xs leading-5 text-muted-foreground">หากเข้าใช้งานไม่ได้ กรุณาติดต่อผู้ดูแลระบบระดับอำเภอ</p>
        </div>
      </section>
    </main>
  </>
}
