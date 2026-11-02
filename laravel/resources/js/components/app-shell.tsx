import { AppLink as Link } from '@/components/app-link'
import { appRouter as router } from '@/lib/app-router'
import { usePage } from '@inertiajs/react'
import * as DropdownMenu from '@radix-ui/react-dropdown-menu'
import { Buildings, CaretDown, ChartBar, FileText, Plus, SignOut, Student, UserCircle, UserList } from '@phosphor-icons/react'
import { Button } from '@/components/ui/button'
import { ThemeToggle } from '@/components/theme-toggle'
import { FlashAlertBridge } from '@/components/flash-messages'
import { cn } from '@/lib/utils'
import { appPathname } from '@/lib/app-url'
import type { SharedProps } from '@/types'

const items = [
  { href: '/', label: 'ภาพรวม', icon: ChartBar, match: (url: string) => url === '/' || (url.startsWith('/records') && !url.startsWith('/records/create')) },
  { href: '/documents', label: 'เอกสาร', icon: FileText, match: (url: string) => url.startsWith('/documents') },
  { href: '/students', label: 'ข้อมูลนักศึกษา', icon: Student, match: (url: string) => url.startsWith('/students') },
  { href: '/attendance-analysis', label: 'การเข้าเรียน', icon: UserList, match: (url: string) => url.startsWith('/attendance-analysis') },
]

function Brand() {
  return <Link href="/" className="focus-ring flex shrink-0 items-center gap-2.5 rounded-lg" aria-label="SENA Reflection หน้าหลัก">
    <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-primary text-[12px] font-bold tracking-tight text-white">SR</span>
    <span className="min-w-0 leading-tight">
      <b className="block text-[13px] font-semibold tracking-[-0.01em] text-foreground">SENA Reflection</b>
      <small className="hidden text-[11px] text-muted-foreground sm:block">ระบบบันทึกหลังการสอน</small>
    </span>
  </Link>
}

function NavLinks({ className }: { className?: string }) {
  const { url, props } = usePage<SharedProps>()
  const pathname = appPathname(url)
  const links = props?.auth?.user?.role === 'district' ? [...items, { href: '/admin', label: 'จัดการระบบ', icon: Buildings, match: (value: string) => value.startsWith('/admin') }] : items
  return <nav className={cn('min-w-0 items-center gap-1.5', className)} aria-label="เมนูหลัก">
    {links.map(({ href, label, icon: Icon, match }) => {
      const active = match(pathname)
      return <Link
        key={href}
        href={href}
        aria-current={active ? 'page' : undefined}
        className={cn(
          'focus-ring flex shrink-0 items-center gap-2 whitespace-nowrap rounded-lg px-3 py-1.5 text-[13px] transition-colors duration-150',
          active
            ? 'bg-blue-50 text-blue-600 font-semibold dark:bg-blue-950/60 dark:text-blue-400'
            : 'text-muted-foreground font-medium hover:bg-muted hover:text-foreground'
        )}
      >
        <Icon size={16} weight={active ? 'bold' : 'regular'} />
        {label}
      </Link>
    })}
  </nav>
}

export function AppShell({ children, wide = false }: { children: React.ReactNode; wide?: boolean }) {
  const { url, props } = usePage<SharedProps>()
  const pathname = appPathname(url)
  const user = props?.auth?.user
  const logout = () => router.post('/logout')
  const isCreatePage = pathname === '/records/create'

  return <div className="min-h-[100dvh] bg-background">
    <FlashAlertBridge />
    <a href="#main-content" className="fixed left-3 top-3 z-50 -translate-y-20 rounded-lg bg-foreground px-4 py-2 text-sm font-semibold text-background focus:translate-y-0">ข้ามไปยังเนื้อหา</a>
    <header className="sticky top-0 z-30 border-b border-border bg-card shadow-xs">
      <div className="mx-auto flex h-14 max-w-[1440px] items-center gap-3 px-4 sm:px-6 lg:h-16 lg:px-8">
        <Brand />
        <div className="hidden h-5 w-px bg-border lg:block" />
        <NavLinks className="hidden lg:flex" />
        <div className="ml-auto flex shrink-0 items-center gap-1.5 sm:gap-2">
          {!isCreatePage && (
            <Button asChild size="sm" className="h-8 gap-1.5 px-3 text-xs font-semibold shadow-xs">
              <Link href="/records/create">
                <Plus size={15} weight="bold" />
                <span className="hidden sm:inline">สร้างบันทึก</span>
                <span className="sm:hidden">สร้าง</span>
              </Link>
            </Button>
          )}
          <ThemeToggle />
          <DropdownMenu.Root>
            <DropdownMenu.Trigger asChild>
              <button className="focus-ring flex items-center gap-2 rounded-lg p-1.5 pr-2 text-left transition-colors duration-150 hover:bg-muted" aria-label="เมนูบัญชี">
                <span className="grid size-8 place-items-center rounded-full bg-muted text-xs font-medium text-muted-foreground">{user?.name?.slice(0, 1) || 'U'}</span>
                <span className="hidden max-w-36 leading-tight 2xl:block"><b className="block truncate text-xs font-medium">{user?.name}</b><small className="text-[10px] text-muted-foreground">{user?.role === 'district' ? 'ผู้ดูแลระดับอำเภอ' : 'ผู้ใช้ระดับตำบล'}</small></span>
                <CaretDown size={14} className="text-muted-foreground" />
              </button>
            </DropdownMenu.Trigger>
            <DropdownMenu.Portal>
              <DropdownMenu.Content align="end" sideOffset={8} className="z-50 min-w-56 rounded-xl border bg-popover p-1.5 text-popover-foreground shadow-lg">
                <DropdownMenu.Label className="px-2.5 py-2 text-xs text-muted-foreground">{user?.username}</DropdownMenu.Label>
                <DropdownMenu.Separator className="my-1 h-px bg-border" />
                <DropdownMenu.Item asChild><Link href="/profile" className="flex cursor-pointer items-center gap-2 rounded-lg px-2.5 py-2 text-sm outline-none hover:bg-accent focus:bg-accent"><UserCircle size={18} />โปรไฟล์และรหัสผ่าน</Link></DropdownMenu.Item>
                <DropdownMenu.Item onSelect={logout} className="flex cursor-pointer items-center gap-2 rounded-lg px-2.5 py-2 text-sm text-destructive outline-none hover:bg-destructive/10 focus:bg-destructive/10"><SignOut size={18} />ออกจากระบบ</DropdownMenu.Item>
              </DropdownMenu.Content>
            </DropdownMenu.Portal>
          </DropdownMenu.Root>
        </div>
      </div>
      <div className="border-t border-border/40 bg-card/95 lg:hidden">
        <div className="mx-auto max-w-[1440px] px-2 sm:px-4">
          <NavLinks className="flex overflow-x-auto no-scrollbar py-1.5" />
        </div>
      </div>
    </header>
    <main id="main-content" className={cn('mx-auto px-4 py-6 sm:px-6 sm:py-8 lg:px-8', wide ? 'max-w-none' : 'max-w-[1440px]')}>{children}</main>
  </div>
}

export function PageHeader({ title, description, action }: { title: string; description?: string; action?: React.ReactNode }) {
  return <div className="mb-7 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div className="min-w-0"><h1 className="text-2xl font-semibold tracking-[-0.035em] sm:text-[28px]">{title}</h1>{description && <p className="mt-1.5 max-w-[65ch] text-sm leading-6 text-muted-foreground">{description}</p>}</div>
    {action}
  </div>
}
