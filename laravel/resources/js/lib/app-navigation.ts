import { router } from '@inertiajs/react'
import { appBasePath, appUrl, safeAppHistoryUrl } from './app-url'

type NavigationEntry = { index: number; url: string | null }
type BrowserNavigation = { currentEntry: NavigationEntry | null; entries: () => NavigationEntry[] }
let installed = false
let trail: string[] = []
let position = 0
let traversing = false
let refreshNextHistory = false

export function installAppNavigation(): void {
  if (installed || typeof window === 'undefined') return
  installed = true
  trail = [window.location.href]
  window.addEventListener('popstate', () => {
    traversing = true
    const target = window.location.href
    if (trail[position - 1] === target) position -= 1
    else if (trail[position + 1] === target) position += 1
    else { trail = [target]; position = 0 }
  })
  router.on('navigate', () => {
    const target = window.location.href
    if (traversing) {
      traversing = false
      if (refreshNextHistory) { refreshNextHistory = false; router.visit(window.location.href, { replace: true, preserveState: false, preserveScroll: true, fresh: true }) }
      return
    }
    if (trail[position] === target) return
    trail = [...trail.slice(0, position + 1), target]
    position = trail.length - 1
  })
}

export function goBackInApp(fallback = '/', refresh = false): void {
  const navigation = (window as Window & { navigation?: BrowserNavigation }).navigation
  const previous = navigation?.currentEntry
    ? navigation.entries().find(entry => entry.index === navigation.currentEntry!.index - 1)?.url
    : position > 0 ? trail[position - 1] : null
  if (safeAppHistoryUrl(previous, window.location.href, appBasePath)) {
    refreshNextHistory = refresh
    window.history.back()
    return
  }
  // A full HTML print page may have reloaded the JS application on return.
  if (!navigation && window.history.length > 1 && safeAppHistoryUrl(document.referrer, window.location.href, appBasePath)) {
    refreshNextHistory = refresh
    window.history.back()
    return
  }
  router.visit(appUrl(fallback), { replace: true })
}
