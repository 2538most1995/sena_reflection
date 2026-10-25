import * as React from 'react'
import { CaretDown } from '@phosphor-icons/react'
import { cn } from '@/lib/utils'

export const NativeSelect = React.forwardRef<HTMLSelectElement, React.ComponentProps<'select'>>(({ className, children, ...props }, ref) => <div className="relative">
  <select ref={ref} className={cn('h-11 w-full min-w-0 appearance-none rounded-lg border border-input bg-card px-3.5 pr-9 text-sm text-foreground outline-none transition-[border-color,box-shadow] duration-150 focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-ring/20 disabled:cursor-not-allowed disabled:bg-muted disabled:opacity-50', className)} {...props}>{children}</select>
  <CaretDown size={15} className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
</div>)
NativeSelect.displayName = 'NativeSelect'
