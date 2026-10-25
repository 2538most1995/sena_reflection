import { appUrl } from '@/lib/app-url'
import { Head, useForm } from '@inertiajs/react'
import { ArrowRight, CircleNotch, Eye, EyeSlash, LockKey, ShieldCheck, User } from '@phosphor-icons/react'
import { FormEvent, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { FormField } from '@/components/form-field'
import { ThemeToggle } from '@/components/theme-toggle'
import '../../../css/login.css'

export default function Login() {
  const [showPassword, setShowPassword] = useState(false)
  const form = useForm({ username: '', password: '' })
  const submit = (event: FormEvent) => {
    event.preventDefault()
    form.post(appUrl('/login'), { onFinish: () => form.reset('password') })
  }

  return <>
    <Head title="เข้าสู่ระบบ" />
    <main className="login-page">
      <div className="login-theme"><ThemeToggle /></div>
      <div className="login-container">
        <section className="login-card" aria-labelledby="login-title">
          <div className="login-brand"><span className="login-brand-mark" aria-hidden="true">SR</span><span><b>SENA Reflection</b><small>ระบบบันทึกหลังการสอน</small></span></div>
          <header className="login-heading">
            <h1 id="login-title">เข้าสู่ระบบ</h1>
            <p>ยินดีต้อนรับกลับมา เริ่มต้นงานของคุณได้ที่นี่</p>
          </header>
          <form onSubmit={submit} className="login-form" aria-busy={form.processing}>
            <FormField label="ชื่อผู้ใช้" htmlFor="username" error={form.errors.username}>
              <div className="login-input-wrap">
                <User size={20} className="login-field-icon" aria-hidden="true" />
                <Input id="username" name="username" value={form.data.username} onChange={(event) => form.setData('username', event.target.value)} autoComplete="username" autoFocus required className="login-input" placeholder="กรอกชื่อผู้ใช้" aria-invalid={Boolean(form.errors.username)} aria-describedby={form.errors.username ? 'username-description' : undefined} />
              </div>
            </FormField>
            <FormField label="รหัสผ่าน" htmlFor="password" error={form.errors.password}>
              <div className="login-input-wrap">
                <LockKey size={20} className="login-field-icon" aria-hidden="true" />
                <Input id="password" name="password" type={showPassword ? 'text' : 'password'} value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} autoComplete="current-password" required className="login-input login-password" placeholder="กรอกรหัสผ่าน" aria-invalid={Boolean(form.errors.password)} aria-describedby={form.errors.password ? 'password-description' : undefined} />
                <button type="button" onClick={() => setShowPassword(!showPassword)} className="login-password-toggle focus-ring" aria-label={showPassword ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน'} aria-pressed={showPassword} aria-controls="password">{showPassword ? <EyeSlash size={20} aria-hidden="true" /> : <Eye size={20} aria-hidden="true" />}</button>
              </div>
            </FormField>
            <Button type="submit" size="lg" disabled={form.processing} className="login-submit">{form.processing ? <CircleNotch size={20} className="animate-spin" aria-hidden="true" /> : null}<span>{form.processing ? 'กำลังเข้าสู่ระบบ...' : 'เข้าสู่ระบบ'}</span>{!form.processing && <ArrowRight size={19} aria-hidden="true" />}</Button>
          </form>
          <p className="login-help">หากเข้าใช้งานไม่ได้<br />กรุณาติดต่อผู้ดูแลระบบระดับอำเภอ</p>
        </section>
        <p className="login-footer"><ShieldCheck size={16} aria-hidden="true" /><span>ศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอเสนา</span></p>
      </div>
    </main>
  </>
}
