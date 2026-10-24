export const EXPORT_POLL_INTERVAL = 3000

export function exportRetrySeconds(value: string | null, now = Date.now()): number {
  if (value && /^\d+$/.test(value.trim())) return Math.max(1, Number(value))
  const date = value ? Date.parse(value) : Number.NaN
  return Number.isFinite(date) ? Math.max(1, Math.ceil((date - now) / 1000)) : 10
}

/** Keep one status request in flight and stop polling settled or hidden exports. */
export function createExportPoller(options: {
  load: () => Promise<boolean>
  visible: () => boolean
  schedule: (callback: () => void, delay: number) => unknown
  cancel: (timer: unknown) => void
}) {
  let timer: unknown
  let loading = false
  let disposed = false
  let refreshRequested = false
  let retryDelay = EXPORT_POLL_INTERVAL

  const clear = () => {
    if (timer !== undefined) options.cancel(timer)
    timer = undefined
  }
  const refresh = async () => {
    clear()
    if (disposed || !options.visible()) return
    if (loading) { refreshRequested = true; return }
    loading = true
    let active = true
    try {
      active = await options.load()
      retryDelay = EXPORT_POLL_INTERVAL
    } catch {
      retryDelay = Math.min(retryDelay * 2, 30000)
    } finally {
      loading = false
      if (!disposed && options.visible() && (active || refreshRequested)) {
        const delay = refreshRequested ? 0 : retryDelay
        refreshRequested = false
        timer = options.schedule(() => { void refresh() }, delay)
      }
    }
  }
  return {
    refresh: () => { void refresh() },
    pause: clear,
    dispose: () => { disposed = true; clear() },
  }
}
