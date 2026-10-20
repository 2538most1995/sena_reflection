import { router } from '@inertiajs/react'
import { appUrl } from './app-url'

const urlMethods = new Set(['visit', 'get', 'post', 'put', 'patch', 'delete', 'prefetch'])

/** Keep Inertia's request lifecycle while applying the same mount path as fetch. */
export const appRouter: typeof router = new Proxy(router, {
  get(target, key) {
    const value = Reflect.get(target, key)
    if (typeof value !== 'function') return value
    return (...args: unknown[]) => {
      if (urlMethods.has(String(key))) {
        const destination = args[0]
        if (typeof destination === 'string') args[0] = appUrl(destination)
        else if (destination && typeof destination === 'object' && 'url' in destination && typeof destination.url === 'string') args[0] = { ...destination, url: appUrl(destination.url) }
      }
      return Reflect.apply(value, target, args)
    }
  },
})
