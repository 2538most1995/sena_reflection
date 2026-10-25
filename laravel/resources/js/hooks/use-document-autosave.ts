import { useCallback, useEffect, useRef, useState } from 'react'
import { DocumentApiError, documentRequest } from '@/lib/document-api'
import type { WebDocument } from '@/lib/document-model'

function fingerprint(document: WebDocument) {
  return JSON.stringify({ title: document.title, content: document.content, pageSettings: document.pageSettings, objects: document.objects, schemaVersion: document.schemaVersion })
}
export function useDocumentAutosave(initial: WebDocument) {
  const [document, setDocument] = useState(initial)
  const [status, setStatus] = useState<'saved' | 'pending' | 'saving' | 'error' | 'conflict'>('saved')
  const [error, setError] = useState('')
  const latest = useRef(initial)
  const revision = useRef(initial.revision)
  const saved = useRef(fingerprint(initial))
  const inFlight = useRef<Promise<boolean> | null>(null)
  const saveFailure = useRef<{ message: string; conflict: boolean } | null>(null)
  const dirty = fingerprint(document) !== saved.current
  const update = useCallback((changes: Partial<WebDocument>) => {
    const next = { ...latest.current, ...changes }
    latest.current = next
    setDocument(next)
    setStatus((current) => current === 'conflict' ? 'conflict' : inFlight.current ? 'saving' : fingerprint(next) === saved.current ? 'saved' : 'pending')
    setError('')
    saveFailure.current = null
  }, [])
  const replace = useCallback((next: WebDocument) => {
    latest.current = next; revision.current = next.revision; saved.current = fingerprint(next)
    saveFailure.current = null
    setDocument(next); setStatus('saved'); setError('')
  }, [])
  const save = useCallback(async (): Promise<boolean> => {
    if (inFlight.current) {
      const completed = await inFlight.current
      return completed ? save() : false
    }
    const snapshot = latest.current
    if (fingerprint(snapshot) === saved.current) { setStatus('saved'); return true }
    setStatus('saving'); setError('')
    saveFailure.current = null
    const pending = (async () => {
      try {
        const result = await documentRequest<{ document: WebDocument }>(`/api/documents/${snapshot.id}`, { method: 'PUT', body: JSON.stringify({ ...snapshot, revision: revision.current }) })
        revision.current = result.document.revision
        saved.current = fingerprint(snapshot)
        const next = { ...latest.current, revision: result.document.revision, updatedAt: result.document.updatedAt, editorContext: result.document.editorContext }
        latest.current = next; setDocument(next)
        setStatus(fingerprint(next) === saved.current ? 'saved' : 'pending')
        return true
      } catch (error) {
        const conflict = error instanceof DocumentApiError && error.status === 409
        const message = error instanceof Error ? error.message : 'บันทึกเอกสารไม่สำเร็จ'
        saveFailure.current = { message, conflict }
        setStatus(conflict ? 'conflict' : 'error')
        setError(message)
        return false
      } finally { inFlight.current = null }
    })()
    inFlight.current = pending
    const completed = await pending
    return completed && fingerprint(latest.current) !== saved.current ? save() : completed
  }, [])
  useEffect(() => {
    if (!dirty || status !== 'pending') return
    const timer = window.setTimeout(() => { void save() }, 1200)
    return () => window.clearTimeout(timer)
  }, [document, dirty, status, save])
  useEffect(() => {
    if (!dirty && status !== 'saving') return
    const warn = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [dirty, status])
  return { document, status, error, dirty, update, replace, save, latest, saveFailure, clearError: () => setError('') }
}
