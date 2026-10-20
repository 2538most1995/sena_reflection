import * as React from 'react'
import { cn } from '@/lib/utils'

export const Input = React.forwardRef<HTMLInputElement, React.ComponentProps<'input'>>(({ className, type, ...props }, ref) => (
  <input type={type} ref={ref} className={cn('flex h-11 w-full min-w-0 rounded-lg border border-input bg-background px-3.5 text-sm text-foreground shadow-[inset_0_1px_0_rgba(20,58,55,0.03)] outline-none transition placeholder:text-muted-foreground/80 focus-visible:border-primary/55 focus-visible:ring-2 focus-visible:ring-ring/35 disabled:cursor-not-allowed disabled:opacity-50', className)} {...props} />
))
Input.displayName = 'Input'
