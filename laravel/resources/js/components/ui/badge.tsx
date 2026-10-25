import { cva, type VariantProps } from 'class-variance-authority'
import { cn } from '@/lib/utils'

const variants = cva('inline-flex items-center rounded-md px-2 py-1 text-xs font-medium', { variants: { variant: { default: 'bg-accent text-accent-foreground', secondary: 'bg-secondary text-secondary-foreground', outline: 'border border-border text-muted-foreground', success: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200' } }, defaultVariants: { variant: 'default' } })
export function Badge({ className, variant, ...props }: React.HTMLAttributes<HTMLSpanElement> & VariantProps<typeof variants>) { return <span className={cn(variants({ variant }), className)} {...props} /> }
