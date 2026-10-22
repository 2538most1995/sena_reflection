import { AppLink as Link } from '@/components/app-link'
import { appRouter as router } from '@/lib/app-router'
import { usePage } from '@inertiajs/react'
import * as DropdownMenu from '@radix-ui/react-dropdown-menu'
import * as Dialog from '@radix-ui/react-dialog'
import { Buildings, CaretDown, ChartBar, FileText, List, Plus, SignOut, Student, UserCircle, UserList, X } from '@phosphor-icons/react'
import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { ThemeToggle } from '@/components/theme-toggle'
import { cn } from '@/lib/utils'
import { appPathname } from '@/lib/app-url'
import type { SharedProps } from '@/types'

const items = [
  { href: '/', label: 'ภาพรวม', icon: ChartBar, match: (url: string) => url === '/' || url.startsWith('/records') },
  { href: '/documents', label: 'เอกสาร', icon: FileText, match: (url: string) => url.startsWith('/documents') },
  { href: '/students', label: 'ข้อมูลนักศึกษา', icon: Student, match: (url: string) => url.startsWith('/students') },
  { href: '/attendance-analysis', label: 'การเข้าเรียน', icon: UserList, match: (url: string) => url.startsWith('/attendance-analysis') },
]

function Brand() {
  return <Link href="/" className="focus-ring flex min-w-0 items-center gap-3 rounded-lg" aria-label="SENA Reflection หน้าหลัก">
    <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-primary text-sm font-bold tracking-tight text-primary-foreground shadow-[0_8px_24px_rgba(20,58,55,0.18)]">SR</span>
    <span className="hidden min-w-0 leading-tight sm:block"><b className="block truncate text-sm tracking-[-0.02em]">SENA Reflection</b><small className="block truncate text-[11px] text-muted-foreground">ระบบบันทึกหลังการสอน</small></span>
  </Link>
}

function NavLinks({ mobile = false, close }: { mobile?: boolean; close?: () => void }) {
  const { url, props } = usePage<SharedProps>()
  const pathname = appPathname(url)
  const links = props.auth.user?.role === 'district' ? [...items, { href: '/admin', label: 'จัดการระบบ', icon: Buildings, match: (value: string) => value.startsWith('/admin') }] : items
  return <nav className={cn(mobile ? 'grid gap-1' : 'hidden items-center gap-1 md:flex')} aria-label="เมนูหลัก">
    {mobile && <Link href="/records/create" onClick={close} className="focus-ring mb-3 flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-3 text-sm font-semibold text-primary-foreground"><Plus size={18} weight="bold" />สร้างบันทึกหลังสอน</Link>}
    {links.map(({ href, label, icon: Icon, match }) => <Link key={href} href={href} onClick={close} className={cn('focus-ring flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors', match(pathname) ? 'bg-accent text-accent-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground')}>
      <Icon size={18} weight={match(pathname) ? 'fill' : 'regular'} />{label}
    </Link>)}
  </nav>
}

export function AppShell({ children, wide = false }: { children: React.ReactNode; wide?: boolean }) {
  const { props } = usePage<SharedProps>()
  const user = props.auth.user
  const [open, setOpen] = useState(false)
  const logout = () => router.post('/logout')

  return <div className="min-h-[100dvh] bg-background">
    <a href="#main-content" className="fixed left-3 top-3 -translate-y-20 rounded-lg bg-foreground px-4 py-2 text-sm font-semibold text-background focus:translate-y-0">ข้ามไปยังเนื้อหา</a>
    <header className="sticky top-0 z-30 border-b border-border/75 bg-background/94 backdrop-blur-md">
      <div className="mx-auto flex h-16 max-w-[1440px] items-center gap-3 px-4 sm:px-6 lg:px-8">
        <Dialog.Root open={open} onOpenChange={setOpen}>
          <Dialog.Trigger asChild><Button variant="ghost" size="icon" className="md:hidden" aria-label="เปิดเมนู"><List size={22} /></Button></Dialog.Trigger>
          <Dialog.Portal>
            <Dialog.Overlay className="fixed inset-0 z-40 bg-foreground/30 backdrop-blur-sm" />
            <Dialog.Content className="fixed inset-y-0 left-0 z-50 w-[min(86vw,340px)] border-r bg-background p-5 shadow-2xl outline-none">
              <div className="mb-8 flex items-center justify-between"><Brand /><Dialog.Close asChild><Button variant="ghost" size="icon" aria-label="ปิดเมนู"><X size={20} /></Button></Dialog.Close></div>
              <NavLinks mobile close={() => setOpen(false)} />
            </Dialog.Content>
          </Dialog.Portal>
        </Dialog.Root>
        <Brand />
        <div className="h-7 w-px bg-border md:mx-2" />
        <NavLinks />
        <div className="ml-auto flex items-center gap-1">
          <Button asChild size="sm" className="hidden lg:inline-flex"><Link href="/records/create"><Plus size={17} weight="bold" />สร้างบันทึก</Link></Button>
          <Button asChild size="icon" className="lg:hidden"><Link href="/records/create" aria-label="สร้างบันทึกหลังสอน"><Plus size={19} weight="bold" /></Link></Button>
          <ThemeToggle />
          <DropdownMenu.Root>
            <DropdownMenu.Trigger asChild>
              <button className="focus-ring flex items-center gap-2 rounded-lg p-1.5 pr-2 text-left transition hover:bg-muted" aria-label="เมนูบัญชี">
                <span className="grid size-8 place-items-center rounded-md bg-secondary text-sm font-bold text-secondary-foreground">{user?.name?.slice(0, 1) || 'U'}</span>
                <span className="hidden max-w-36 leading-tight lg:block"><b className="block truncate text-xs">{user?.name}</b><small className="text-[10px] text-muted-foreground">{user?.role === 'district' ? 'ผู้ดูแลระดับอำเภอ' : 'ผู้ใช้ระดับตำบล'}</small></span>
                <CaretDown size={14} className="text-muted-foreground" />
              </button>
            </DropdownMenu.Trigger>
            <DropdownMenu.Portal>
              <DropdownMenu.Content align="end" sideOffset={8} className="z-50 min-w-56 rounded-xl border bg-popover p-1.5 text-popover-foreground shadow-xl">
                <DropdownMenu.Label className="px-2.5 py-2 text-xs text-muted-foreground">{user?.username}</DropdownMenu.Label>
                <DropdownMenu.Separator className="my-1 h-px bg-border" />
                <DropdownMenu.Item asChild><Link href="/profile" className="flex cursor-pointer items-center gap-2 rounded-lg px-2.5 py-2 text-sm outline-none hover:bg-accent focus:bg-accent"><UserCircle size={18} />โปรไฟล์และรหัสผ่าน</Link></DropdownMenu.Item>
                <DropdownMenu.Item onSelect={logout} className="flex cursor-pointer items-center gap-2 rounded-lg px-2.5 py-2 text-sm text-destructive outline-none hover:bg-destructive/10 focus:bg-destructive/10"><SignOut size={18} />ออกจากระบบ</DropdownMenu.Item>
              </DropdownMenu.Content>
            </DropdownMenu.Portal>
          </DropdownMenu.Root>
        </div>
      </div>
    </header>
    <main id="main-content" className={cn('mx-auto px-4 py-6 sm:px-6 sm:py-8 lg:px-8', wide ? 'max-w-none' : 'max-w-[1440px]')}>{children}</main>
  </div>
}

export function PageHeader({ title, description, action }: { title: string; description?: string; action?: React.ReactNode }) {
  return <div className="mb-6 flex flex-col gap-4 border-b pb-6 sm:flex-row sm:items-end sm:justify-between">
    <div><h1 className="text-2xl font-bold tracking-[-0.04em] sm:text-3xl">{title}</h1>{description && <p className="mt-1.5 max-w-[65ch] text-sm leading-6 text-muted-foreground">{description}</p>}</div>
    {action}
  </div>
}
