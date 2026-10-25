import * as React from 'react'
import { cn } from '@/lib/utils'

export const Card = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(({ className, ...props }, ref) => <div ref={ref} className={cn('rounded-xl border border-border bg-card text-card-foreground', className)} {...props} />)
Card.displayName = 'Card'
export const CardHeader = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => <div className={cn('flex flex-col gap-1.5 p-5 sm:p-6', className)} {...props} />
export const CardTitle = ({ className, ...props }: React.HTMLAttributes<HTMLHeadingElement>) => <h3 className={cn('text-lg font-semibold tracking-[-0.02em]', className)} {...props} />
export const CardDescription = ({ className, ...props }: React.HTMLAttributes<HTMLParagraphElement>) => <p className={cn('text-sm leading-6 text-muted-foreground', className)} {...props} />
export const CardContent = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => <div className={cn('p-5 pt-0 sm:p-6 sm:pt-0', className)} {...props} />
