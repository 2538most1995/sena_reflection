import { Head } from '@inertiajs/react'
import { ArrowLeft, ArrowsClockwise, CheckCircle, Database, FloppyDisk, Key, ShieldCheck, WarningCircle } from '@phosphor-icons/react'
import { type FormEvent, useEffect, useRef, useState } from 'react'
import { AppShell, PageHeader } from '@/components/app-shell'
import { AppLink } from '@/components/app-link'
import { FormField } from '@/components/form-field'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { actionAlert } from '@/lib/action-alert'
import { appFetch } from '@/lib/app-url'

type Integration = { driver: 'legacy' | 'sdl'; url: string; token_configured: boolean; latest_term: string | null; source_label: string }
type ConnectionResult = { latest_term: string | null; student_count: number }
type ResponsePayload = { status?: string; message?: string; data?: ConnectionResult; errors?: Record<string, string[]> }
type ConnectionCheck = ConnectionResult & { saved: boolean }

export default function StudentApi({ integration: initial }: { integration: Integration }) {
  const [integration, setIntegration] = useState(initial)
  const [token, setToken] = useState('')
  const [busy, setBusy] = useState<'test' | 'save' | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [tokenError, setTokenError] = useState<string | undefined>()
  const [check, setCheck] = useState<ConnectionCheck | null>(null)
  const operation = useRef<{ controller: AbortController; alert: string } | null>(null)

  useEffect(() => () => {
    if (!operation.current) return
    operation.current.controller.abort()
    actionAlert.dismiss(operation.current.alert)
  }, [])

  const connect = async (mode: 'test' | 'save') => {
    if (operation.current) return
    setError(null); setTokenError(undefined); setCheck(null)
    if (!integration.token_configured && !token.trim()) {
      const message = 'กรุณากรอก API token ของ SDL School ก่อนทดสอบหรือบันทึก'
      setTokenError(message)
      actionAlert.error('ยังไม่ได้ระบุ API token', message)
      return
    }
    const controller = new AbortController()
    const alert = actionAlert.loading(mode === 'save' ? 'กำลังตรวจสอบและบันทึก' : 'กำลังทดสอบการเชื่อมต่อ', 'กำลังตรวจสิทธิ์ API และภาคเรียนล่าสุดจาก SDL School')
    operation.current = { controller, alert }; setBusy(mode)
    try {
      const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || ''
      const response = await appFetch(`/admin/student-api${mode === 'test' ? '/test' : ''}`, {
        method: 'POST', signal: controller.signal,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(token.trim() ? { token: token.trim() } : {}),
      })
      const payload: ResponsePayload | null = await response.json().catch(() => null)
      if (!response.ok || !payload?.data) {
        setTokenError(payload?.errors?.token?.[0])
        throw new Error(Object.values(payload?.errors || {}).flat().join('\n') || payload?.message || (response.status === 419 ? 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่ก่อนลองอีกครั้ง' : 'ติดต่อระบบนักศึกษาไม่สำเร็จ กรุณาลองอีกครั้ง'))
      }
      if (controller.signal.aborted) return
      setCheck({ ...payload.data, saved: mode === 'save' })
      if (mode === 'save') {
        setIntegration(current => ({ ...current, driver: 'sdl', source_label: 'SDL School', token_configured: true, latest_term: payload.data!.latest_term, url: 'https://krumost.com/SDL_school' }))
        setToken('')
      }
      actionAlert.success(mode === 'save' ? 'บันทึกการเชื่อมต่อแล้ว' : 'เชื่อมต่อสำเร็จ', payload.message || (mode === 'save' ? 'ระบบพร้อมใช้ข้อมูลจาก SDL School' : 'ตรวจสอบภาคเรียนล่าสุดแล้ว กดบันทึกเพื่อนำการตั้งค่านี้ไปใช้'), alert)
    } catch (reason) {
      if (controller.signal.aborted) return
      const message = reason instanceof Error ? reason.message : 'ติดต่อระบบนักศึกษาไม่สำเร็จ กรุณาลองอีกครั้ง'
      setError(message)
      actionAlert.error(mode === 'save' ? 'ยังบันทึกการเชื่อมต่อไม่ได้' : 'ทดสอบการเชื่อมต่อไม่สำเร็จ', message, alert)
    } finally {
      if (!controller.signal.aborted) { operation.current = null; setBusy(null) }
    }
  }
  const submit = (event: FormEvent) => { event.preventDefault(); void connect('save') }

  return <AppShell>
    <Head title="เชื่อมต่อข้อมูลนักศึกษา" />
    <PageHeader title="เชื่อมต่อข้อมูลนักศึกษา" description="ตั้งค่า SDL School เพื่อใช้รายชื่อและภาคเรียนล่าสุดในบันทึกหลังสอน" action={<Button asChild variant="outline"><AppLink href="/admin"><ArrowLeft size={17} />จัดการระบบ</AppLink></Button>} />
    <div className="grid gap-5 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
      <Card>
        <CardHeader><div className="flex flex-wrap items-center justify-between gap-3"><CardTitle className="flex items-center gap-2"><Database size={21} className="text-primary" />แหล่งข้อมูลปัจจุบัน</CardTitle><Badge variant={integration.driver === 'sdl' ? 'secondary' : 'outline'}>{integration.driver === 'sdl' ? 'ใช้ SDL School' : 'ใช้แหล่งข้อมูลเดิม'}</Badge></div></CardHeader>
        <CardContent className="grid gap-5">
          <dl className="grid gap-4 rounded-lg border bg-muted/25 p-4 text-sm sm:grid-cols-2">
            <div><dt className="text-xs text-muted-foreground">ระบบต้นทาง</dt><dd className="mt-1 font-medium">{integration.source_label}</dd></div>
            <div><dt className="text-xs text-muted-foreground">ภาคเรียนล่าสุดที่ตรวจพบ</dt><dd className="mt-1 font-semibold tabular-nums">{integration.latest_term || 'ยังไม่พบข้อมูลภาคเรียน'}</dd></div>
            <div className="sm:col-span-2"><dt className="text-xs text-muted-foreground">ที่อยู่ระบบต้นทาง</dt><dd className="mt-1 break-all text-muted-foreground">{integration.url}</dd></div>
          </dl>
          <form onSubmit={submit} className="grid gap-5">
            <FormField label={integration.token_configured ? 'API token ใหม่' : 'API token ของ SDL School'} error={tokenError} hint={integration.token_configured ? 'มี token ที่บันทึกไว้แล้ว เว้นว่างเพื่อใช้ค่าเดิม' : 'คัดลอก token ที่มีสิทธิ์อ่านข้อมูลนักศึกษาและข้อมูลครูจาก SDL School'}>
              <Input type="password" name="student_api_token" autoComplete="off" spellCheck={false} maxLength={4096} value={token} disabled={Boolean(busy)} onChange={event => { setToken(event.target.value); setCheck(null); setTokenError(undefined); setError(null) }} placeholder={integration.token_configured ? 'เว้นว่างเพื่อใช้ token เดิม' : 'วาง API token ที่นี่'} />
            </FormField>
            {error && <div role="alert" className="flex items-start gap-2 rounded-lg border border-destructive/25 bg-destructive/5 p-3 text-sm leading-6 text-destructive"><WarningCircle size={20} className="mt-0.5 shrink-0" /><span className="whitespace-pre-line">{error}</span></div>}
            {check && <div role="status" className="flex items-start gap-3 rounded-lg border border-primary/20 bg-accent/60 p-4"><CheckCircle size={22} className="mt-0.5 shrink-0 text-primary" /><div><b className="block text-sm">{check.saved ? 'บันทึกการเชื่อมต่อแล้ว' : 'ทดสอบผ่าน — ยังไม่ได้บันทึกการตั้งค่า'}</b><p className="mt-1 text-sm leading-6 text-muted-foreground">ภาคเรียน {check.latest_term || 'ไม่ระบุ'} · นักศึกษา {check.student_count.toLocaleString('th-TH')} คน</p>{!check.saved && <p className="mt-1 text-xs leading-5 text-muted-foreground">กดตรวจสอบและบันทึกเพื่อนำการตั้งค่านี้ไปใช้</p>}</div></div>}
            <div className="flex flex-col gap-2 border-t pt-4 sm:flex-row sm:justify-end">
              <Button type="button" variant="outline" disabled={Boolean(busy)} onClick={() => { void connect('test') }}><ArrowsClockwise size={18} />{busy === 'test' ? 'กำลังทดสอบ...' : 'ทดสอบการเชื่อมต่อ'}</Button>
              <Button type="submit" disabled={Boolean(busy)}><FloppyDisk size={18} />{busy === 'save' ? 'กำลังบันทึก...' : 'ตรวจสอบและบันทึก'}</Button>
            </div>
          </form>
        </CardContent>
      </Card>
      <div className="grid content-start gap-5">
        <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><Key size={20} className="text-primary" />เตรียมการเชื่อมต่อ</CardTitle></CardHeader><CardContent><ol className="grid list-decimal gap-3 pl-5 text-sm leading-6 text-muted-foreground"><li>สร้าง API token ใน SDL School ให้มีสิทธิ์อ่านข้อมูลนักศึกษาและข้อมูลครู</li><li>วาง token แล้วทดสอบการเชื่อมต่อ ระบบจะแสดงภาคเรียนล่าสุดที่พบ</li><li>กดตรวจสอบและบันทึก แล้วเปิดหน้าสร้างบันทึกหรือข้อมูลนักศึกษาใหม่เพื่อใช้ข้อมูลล่าสุด</li></ol></CardContent></Card>
        <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><ShieldCheck size={20} className="text-primary" />ข้อมูลที่บันทึกไว้</CardTitle></CardHeader><CardContent className="grid gap-3 text-sm leading-6 text-muted-foreground"><p>token เก็บอย่างเข้ารหัสในระบบ และไม่แสดงกลับบนหน้านี้</p><p>การเปลี่ยนแหล่งข้อมูลมีผลกับการดึงรายชื่อครั้งถัดไป บันทึกและรายชื่อเข้าเรียนที่ยืนยันไว้แล้วจะคงตามภาคเรียนเดิม</p><p>หากระบบต้นทางปฏิเสธ token ให้ตรวจสิทธิ์หรือสร้าง token ใหม่ แล้วทดสอบอีกครั้งก่อนบันทึก</p></CardContent></Card>
      </div>
    </div>
  </AppShell>
}
