/** Resolve application URLs without leaking navigation into other apps on the host. */
export function resolveAppUrl(value: string, configuredBasePath = ''): string {
  const basePath = normalizeAppBasePath(configuredBasePath)
  if (!value || /^(?:[a-z][a-z\d+.-]*:|\/\/|#|\?)/i.test(value)) return value
  const path = value.startsWith('/') ? value : `/${value}`
  if (!basePath || path === basePath || path.startsWith(`${basePath}/`) || path.startsWith(`${basePath}?`) || path.startsWith(`${basePath}#`)) return path
  return `${basePath}${path}`
}

export function normalizeAppBasePath(value: string): string {
  const trimmed = value.trim().replace(/^\/+|\/+$/g, '')
  if (!trimmed) return ''
  if (/[:?#\\]/.test(trimmed) || trimmed.split('/').some((segment) => segment === '.' || segment === '..')) throw new Error('VITE_APP_BASE_PATH must be an application path, such as /sena_reflection')
  return `/${trimmed}`
}

export const appBasePath = normalizeAppBasePath(import.meta.env?.VITE_APP_BASE_PATH || '')

export function appUrl(value: string): string {
  return resolveAppUrl(value, appBasePath)
}

export function resolveAppPathname(value: string, configuredBasePath = ''): string {
  const basePath = normalizeAppBasePath(configuredBasePath)
  const path = (/^[a-z][a-z\d+.-]*:/i.test(value) ? new URL(value).pathname : value).split(/[?#]/, 1)[0]
  if (basePath && (path === basePath || path.startsWith(`${basePath}/`))) return path.slice(basePath.length) || '/'
  return path || '/'
}

export function appPathname(value: string): string {
  return resolveAppPathname(value, appBasePath)
}

export function appFetch(input: RequestInfo | URL, init?: RequestInit): Promise<Response> {
  return globalThis.fetch(typeof input === 'string' ? appUrl(input) : input, init)
}
