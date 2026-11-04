/** Resolve application URLs without leaking navigation into other apps on the host. */
export function resolveAppUrl(value?: string | null, configuredBasePath = ''): string {
  if (!value) return ''
  const basePath = normalizeAppBasePath(configuredBasePath)
  if (/^(?:[a-z][a-z\d+.-]*:|\/\/|#|\?)/i.test(value)) return value
  const path = value.startsWith('/') ? value : `/${value}`
  if (!basePath || path === basePath || path.startsWith(`${basePath}/`) || path.startsWith(`${basePath}?`) || path.startsWith(`${basePath}#`)) return path
  return `${basePath}${path}`
}

export function normalizeAppBasePath(value?: string | null): string {
  const trimmed = (value || '').trim().replace(/^\/+|\/+$/g, '')
  if (!trimmed) return ''
  if (/[:?#\\]/.test(trimmed) || trimmed.split('/').some((segment) => segment === '.' || segment === '..')) throw new Error('VITE_APP_BASE_PATH must be an application path, such as /sena_reflection')
  return `/${trimmed}`
}

export const appBasePath = normalizeAppBasePath(import.meta.env?.VITE_APP_BASE_PATH || '')

export function appUrl(value?: string | null): string {
  return resolveAppUrl(value || '', appBasePath)
}

export function resolveAppPathname(value?: string | null, configuredBasePath = ''): string {
  if (!value) return '/'
  const basePath = normalizeAppBasePath(configuredBasePath)
  const path = (/^[a-z][a-z\d+.-]*:/i.test(value) ? new URL(value).pathname : value).split(/[?#]/, 1)[0]
  if (basePath && (path === basePath || path.startsWith(`${basePath}/`))) return path.slice(basePath.length) || '/'
  return path || '/'
}

export function appPathname(value?: string | null): string {
  return resolveAppPathname(value || '', appBasePath)
}

export function appFetch(input: RequestInfo | URL, init?: RequestInit): Promise<Response> {
  return globalThis.fetch(typeof input === 'string' ? appUrl(input) : input, init)
}

/** Only return to a real previous entry in this application, never another site or login. */
export function safeAppHistoryUrl(candidate: string | null | undefined, current: string, basePath = ''): string | null {
  if (!candidate) return null
  try {
    const here = new URL(current)
    const previous = new URL(candidate, here)
    const mount = basePath.replace(/\/$/, '')
    if (previous.origin !== here.origin || previous.href === here.href) return null
    if (mount && previous.pathname !== mount && !previous.pathname.startsWith(`${mount}/`)) return null
    const path = mount ? previous.pathname.slice(mount.length) : previous.pathname
    if (/^\/(?:login|logout)(?:\/|$)/.test(path)) return null
    return previous.pathname + previous.search + previous.hash
  } catch { return null }
}
