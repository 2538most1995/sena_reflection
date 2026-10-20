import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'
import { cloneElement, isValidElement, useId, type ReactElement } from 'react'

export function FormField({ label, htmlFor, error, hint, className, children }: { label: string; htmlFor?: string; error?: string; hint?: string; className?: string; children: React.ReactNode }) {
  const generatedId = useId()
  const control = isValidElement(children) && children.type !== 'label' && children.type !== 'div' ? children as ReactElement<{ id?: string; 'aria-describedby'?: string; 'aria-invalid'?: boolean }> : null
  const id = htmlFor || control?.props.id || generatedId
  const descriptionId = `${id}-description`
  return <div className={cn('grid min-w-0 gap-2', className)}>
    <Label htmlFor={control ? id : htmlFor}>{label}</Label>
    {control ? cloneElement(control, { id, 'aria-invalid': Boolean(error), 'aria-describedby': [control.props['aria-describedby'], error || hint ? descriptionId : null].filter(Boolean).join(' ') || undefined }) : children}
    {hint && !error && <p id={descriptionId} className="text-xs leading-5 text-muted-foreground">{hint}</p>}
    {error && <p id={descriptionId} className="text-xs font-medium text-destructive" role="alert">{error}</p>}
  </div>
}
