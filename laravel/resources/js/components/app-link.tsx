import { Link } from '@inertiajs/react'
import { forwardRef, type ComponentProps } from 'react'
import { appUrl } from '@/lib/app-url'

export const AppLink = forwardRef<unknown, ComponentProps<typeof Link>>(({ href, ...props }, ref) => {
  const destination = typeof href === 'string' ? appUrl(href) : href ? { ...href, url: appUrl(href.url) } : href
  return <Link {...props} href={destination} ref={ref} />
})
AppLink.displayName = 'AppLink'
