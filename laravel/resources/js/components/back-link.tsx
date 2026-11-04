import { forwardRef, type ComponentProps } from 'react'
import { AppLink } from './app-link'
import { goBackInApp } from '@/lib/app-navigation'

export const BackLink = forwardRef<unknown, ComponentProps<typeof AppLink>>(({ href = '/', onClick, ...props }, ref) => (
  <AppLink {...props} ref={ref} href={href} onClick={event => {
    onClick?.(event)
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
    event.preventDefault()
    goBackInApp(typeof href === 'string' ? href : '/')
  }} />
))
BackLink.displayName = 'BackLink'
