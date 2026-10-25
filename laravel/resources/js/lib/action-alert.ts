export type ActionAlertStatus = 'loading' | 'success' | 'error' | 'info'

export type ActionAlert = Readonly<{
  id: string
  status: ActionAlertStatus
  title: string
  description?: string
}>

type Listener = () => void

export function createActionAlertStore() {
  let sequence = 0
  let alerts: readonly ActionAlert[] = []
  const listeners = new Set<Listener>()

  const notify = () => listeners.forEach(listener => listener())
  const show = (status: ActionAlertStatus, title: string, description?: string, id?: string): string => {
    if (id) {
      if (!alerts.some(alert => alert.id === id)) return id
      alerts = alerts.map(alert => alert.id === id ? { id, status, title, description } : alert)
    } else {
      id = `action-${++sequence}`
      alerts = [...alerts, { id, status, title, description }]
    }
    notify()
    return id
  }

  return {
    subscribe(listener: Listener) {
      listeners.add(listener)
      return () => { listeners.delete(listener) }
    },
    getSnapshot: () => alerts,
    loading: (title: string, description?: string) => show('loading', title, description),
    success: (title: string, description?: string, id?: string) => show('success', title, description, id),
    error: (title: string, description?: string, id?: string) => show('error', title, description, id),
    info: (title: string, description?: string, id?: string) => show('info', title, description, id),
    dismiss(id?: string) {
      const dismissId = id ?? alerts[0]?.id
      const remaining = alerts.filter(alert => alert.id !== dismissId)
      if (remaining.length === alerts.length) return
      alerts = remaining
      notify()
    },
  }
}

export const actionAlert = createActionAlertStore()
