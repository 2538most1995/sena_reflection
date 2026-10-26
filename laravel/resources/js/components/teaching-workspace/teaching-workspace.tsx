import { appFetch as fetch, appUrl } from '@/lib/app-url'
import { AppLink as Link } from '@/components/app-link'
import { appRouter as router } from '@/lib/app-router'
import { Head, usePage } from '@inertiajs/react'
import { ArrowLeft, CheckCircle, CloudArrowUp, CloudCheck, FloppyDisk, WarningCircle } from '@phosphor-icons/react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { AppShell, PageHeader } from '@/components/app-shell'
import { FlashMessages } from '@/components/flash-messages'
import { FormField } from '@/components/form-field'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { NativeSelect } from '@/components/ui/native-select'
import { Switch } from '@/components/ui/switch'
import { cn } from '@/lib/utils'
import { actionAlert } from '@/lib/action-alert'
import { draftRequestError, safeDraftResponseMessage, waitForBrowserDocument } from '@/lib/browser-document-review'
import { normalizeTeachingAttendance } from './review-state'
import type { SharedProps } from '@/types'
import { RecordReview, RecordSaveConfirmation, WorkspaceIssues, WorkspaceSummary, reviewIssues } from './record-review'
import { TeachingDetailsForm } from './teaching-details-form'
import type {
  DraftSaveState,
  GroupOption,
  LevelCode,
  SubjectOption,
  TeachingDraft,
  TeachingDraftImage,
  TeachingDraftPayload,
  TeachingWorkspaceProps,
  WorkspaceAttendanceRow,
  WorkspaceLoadState,
} from './types'
import { emptyTeachingDraft, selectedTeachingLevels, teachingLevels } from './types'
import { WeeklyLevelNavigation } from './weekly-level-navigation'

type DraftEnvelope = { message?: string; code?: string; errors?: Record<string, string[]>; data?: { draft?: TeachingDraft; image?: TeachingDraftImage; images?: TeachingDraftImage[]; lock_version?: number; last_saved_at?: string; redirect_url?: string } }

class DraftConflictError extends Error {}

function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || ''
}

function rememberDraftUrl(id: string): void {
  const url = new URL(window.location.href)
  url.searchParams.set('draft', id)
  window.history.replaceState(window.history.state, '', url)
}

function normalizeDraftPayload(payload: Partial<TeachingDraftPayload> | null | undefined, fallback: TeachingDraftPayload): TeachingDraftPayload {
  const normalizeLevel = (code: LevelCode) => {
    const value = { ...fallback.levels[code], ...(payload?.levels?.[code] || {}) }
    return {
      ...value,
      subject: value.subject || '', subject_code: value.subject_code || '', class_group_code: String(value.class_group_code || '').trim(), class_name: String(value.class_name || '').trim(),
      result: value.result || '', problem: value.problem || '', suggestion: value.suggestion || '',
      total: Number(value.total) || 0, present: Number(value.present) || 0,
      attendance: normalizeTeachingAttendance(value.attendance), image_ids: Array.isArray(value.image_ids) ? value.image_ids : [],
      attendance_term: payload?.levels?.[code]?.attendance_term !== undefined ? value.attendance_term : value.attendance?.length ? `${payload?.scope?.semester || fallback.scope.semester}/${payload?.scope?.year || fallback.scope.year}` : null,
    }
  }
  const enabledCodes = Array.isArray(payload?.enabled_level_codes) && payload.enabled_level_codes.length
    ? payload.enabled_level_codes.filter((c): c is LevelCode => ['1', '2', '3'].includes(c))
    : (fallback.enabled_level_codes || ['1', '2', '3'])
  const activeLevelCode = payload?.active_level_code && enabledCodes.includes(payload.active_level_code)
    ? payload.active_level_code
    : (enabledCodes[0] || '1')

  return {
    ...fallback,
    ...payload,
    schema_version: 1,
    scope: { ...fallback.scope, ...(payload?.scope || {}) },
    levels: {
      '1': normalizeLevel('1'),
      '2': normalizeLevel('2'),
      '3': normalizeLevel('3'),
    },
    enabled_level_codes: enabledCodes,
    active_level_code: activeLevelCode,
  }
}

function savedAtLabel(value: string | null): string {
  if (!value) return 'ยังไม่เคยบันทึก'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return 'บันทึกแล้ว'
  return `บันทึกล่าสุด ${date.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' })} น.`
}

function sourceLabel(source: string): string {
  return source === 'student_api' ? 'ข้อมูลนักศึกษาจากระบบกลาง' : source === 'history' ? 'ประวัติในระบบ' : 'ข้อมูลสำรองในระบบ'
}

function fingerprint(value: TeachingDraftPayload, images: TeachingDraftImage[]): string {
  return JSON.stringify({ scope: value.scope, enabled_level_codes: value.enabled_level_codes, levels: value.levels, document: value.document, images: images.map(({ id, level_code, sort_order }) => ({ id, level_code, sort_order })).sort((a, b) => a.level_code.localeCompare(b.level_code) || a.sort_order - b.sort_order || a.id.localeCompare(b.id)) })
}

export function TeachingWorkspace({ mode, groups, subjects, tambonOptions, studentSource, studentError, subjectSource, subjectError, subjectTerm, defaults, workspace, sourceRecord }: TeachingWorkspaceProps) {
  const { auth } = usePage<SharedProps>().props
  const district = auth.user?.role === 'district'
  const fallbackPayload = useMemo(() => {
    const value = emptyTeachingDraft(defaults)
    if (!district) value.scope.tambon = auth.user?.tambon || ''
    return value
  }, [auth.user?.tambon, defaults, district])
  const initialDraft = workspace?.draft || null
  const [payload, setPayload] = useState<TeachingDraftPayload>(() => normalizeDraftPayload(initialDraft?.payload, fallbackPayload))
  const [draft, setDraft] = useState<TeachingDraft | null>(initialDraft)
  const [images, setImages] = useState<TeachingDraftImage[]>(initialDraft?.images || [])
  const [activeLevel, setActiveLevelState] = useState<LevelCode>(initialDraft?.payload.active_level_code || '1')
  const [editorMode, setEditorMode] = useState<'edit' | 'review'>(() => typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('review') === '1' ? 'review' : 'edit')
  const [loadStates, setLoadStates] = useState<Record<LevelCode, WorkspaceLoadState>>({
    '1': { loading: false, error: null, source: studentSource },
    '2': { loading: false, error: null, source: studentSource },
    '3': { loading: false, error: null, source: studentSource },
  })
  const [availableGroups, setAvailableGroups] = useState(groups)
  const [availableSubjects, setAvailableSubjects] = useState(subjects)
  const optionsContext = `${payload.scope.tambon}|${payload.scope.year}|${payload.scope.semester}`
  const loadedSubjectContext = useRef(optionsContext)
  const [subjectOptionsContext, setSubjectOptionsContext] = useState(optionsContext)
  const [subjectsLoading, setSubjectsLoading] = useState(false)
  const [subjectCatalog, setSubjectCatalog] = useState({ source: subjectSource || (subjects.some(subject => subject.source === 'sdl') ? 'sdl' : 'history'), error: subjectError || null, term: subjectTerm || null })
  const [saveState, setSaveState] = useState<DraftSaveState>(initialDraft ? 'saved' : 'idle')
  const [lastSavedAt, setLastSavedAt] = useState<string | null>(initialDraft?.last_saved_at || null)
  const [pageError, setPageError] = useState<string | null>(studentError)
  const [conflictDraft, setConflictDraft] = useState<TeachingDraft | null>(null)
  const [sourceConflict, setSourceConflict] = useState(false)
  const [imageBusy, setImageBusy] = useState(false)
  const [bootstrapping, setBootstrapping] = useState(mode === 'edit' && !initialDraft)
  const [finalizing, setFinalizing] = useState(false)
  const [confirmationOpen, setConfirmationOpen] = useState(false)
  const [showIssues, setShowIssues] = useState(false)
  const [focusRequest, setFocusRequest] = useState(0)
  const [previewing, setPreviewing] = useState(false)
  const [previewLevel, setPreviewLevel] = useState<LevelCode>(activeLevel)
  const [previewUrls, setPreviewUrls] = useState<Record<LevelCode, string | null>>({ '1': null, '2': null, '3': null })
  const [previewRevisions, setPreviewRevisions] = useState<Record<LevelCode, string | null>>({ '1': null, '2': null, '3': null })
  const [previewVersions, setPreviewVersions] = useState<Record<LevelCode, number | null>>({ '1': null, '2': null, '3': null })
  const [previewFormats, setPreviewFormats] = useState<Record<LevelCode, 'pdf' | 'browser'>>({ '1': 'browser', '2': 'browser', '3': 'browser' })
  const [rendererUnavailable, setRendererUnavailable] = useState(false)
  const browserPreviewRequests = useRef<Record<string, string>>({})
  const [changeVersion, setChangeVersion] = useState(0)
  const payloadRef = useRef(payload)
  const draftRef = useRef(draft)
  const imagesRef = useRef(images)
  const changeVersionRef = useRef(0)
  const savedVersionRef = useRef(initialDraft ? 0 : -1)
  const savedFingerprintRef = useRef(initialDraft ? fingerprint(normalizeDraftPayload(initialDraft.payload, fallbackPayload), initialDraft.images || []) : '')
  const requestSerial = useRef<Record<LevelCode, number>>({ '1': 0, '2': 0, '3': 0 })
  const mutationQueue = useRef<Promise<unknown>>(Promise.resolve())
  const createPromise = useRef<Promise<TeachingDraft> | null>(null)
  const previewUrlsRef = useRef(previewUrls)
  const idempotencyKey = useRef(globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}`)
  const conflicted = useRef(false)
  const finalizingRef = useRef(false)
  const manualSaveRef = useRef(false)
  const confirmationOrigin = useRef<HTMLElement | null>(null)
  const focusAfterReview = useRef<{ section: string; field?: string } | null>(null)

  const applyDraftMeta = useCallback((next: TeachingDraft, hydrate = false) => {
    draftRef.current = next
    setDraft(next)
    imagesRef.current = next.images || []
    setImages(next.images || [])
    setLastSavedAt(next.last_saved_at)
    if (hydrate) {
      const nextPayload = normalizeDraftPayload(next.payload, fallbackPayload)
      payloadRef.current = nextPayload
      setPayload(nextPayload)
      setActiveLevelState(nextPayload.active_level_code)
      changeVersionRef.current = 0
      savedVersionRef.current = 0
      setChangeVersion(0)
      savedFingerprintRef.current = fingerprint(nextPayload, next.images || [])
    }
  }, [fallbackPayload])

  const markChanged = useCallback((updater: TeachingDraftPayload | ((current: TeachingDraftPayload) => TeachingDraftPayload)) => {
    const next = typeof updater === 'function' ? updater(payloadRef.current) : updater
    payloadRef.current = next
    setPayload(next)
    changeVersionRef.current += 1
    setChangeVersion(changeVersionRef.current)
    setSaveState('idle')
  }, [])

  const setActiveLevel = (level: LevelCode) => {
    setActiveLevelState(level)
    markChanged((current) => ({ ...current, active_level_code: level }))
  }

  const toggleLevel = (code: LevelCode) => {
    if (draftRef.current?.source_level_codes?.includes(code)) return
    const currentEnabled = payloadRef.current.enabled_level_codes ?? ['1', '2', '3']
    if (currentEnabled.includes(code) && currentEnabled.length <= 1) return
    const nextEnabled = currentEnabled.includes(code)
      ? currentEnabled.filter((c) => c !== code)
      : (['1', '2', '3'] as LevelCode[]).filter((c) => currentEnabled.includes(c) || c === code)
    const nextActive = !nextEnabled.includes(activeLevel) ? nextEnabled[0] : activeLevel
    setActiveLevelState(nextActive)
    if (!nextEnabled.includes(previewLevel)) {
      setPreviewLevel(nextActive)
    }
    markChanged((current) => ({
      ...current,
      enabled_level_codes: nextEnabled,
      active_level_code: nextActive,
    }))
  }

  const enqueue = useCallback(<T,>(task: () => Promise<T>): Promise<T> => {
    const result = mutationQueue.current.then(task, task)
    mutationQueue.current = result.catch(() => undefined)
    return result
  }, [])

  const parseError = async (response: Response): Promise<DraftEnvelope> => {
    try {
      const envelope = await response.json() as DraftEnvelope
      envelope.message = safeDraftResponseMessage(response.status, envelope)
      return envelope
    } catch {
      return { message: draftRequestError(response.status) }
    }
  }

  const handleConflict = useCallback((envelope: DraftEnvelope) => {
    conflicted.current = true
    setSourceConflict(envelope.code === 'source_record_conflict')
    const latest = envelope.data?.draft
    if (latest) setConflictDraft(latest)
    setSaveState('conflict')
    setPageError(envelope.message || 'ร่างนี้มีการแก้ไขจากอีกหน้าต่าง')
  }, [])

  const createDraft = useCallback(async (snapshot: TeachingDraftPayload, fromRecord = false): Promise<TeachingDraft> => {
    if (draftRef.current) return draftRef.current
    if (createPromise.current) return createPromise.current

    createPromise.current = (async () => {
      const response = await fetch('/teaching-drafts', {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        body: JSON.stringify(fromRecord && sourceRecord ? { source_record_id: sourceRecord.id } : { payload: snapshot }),
      })
      const envelope = await parseError(response)
      const created = envelope.data?.draft
      if (!response.ok || !created) throw new Error(envelope.message || 'สร้างฉบับร่างไม่สำเร็จ')
      applyDraftMeta(created, fromRecord)
      rememberDraftUrl(created.id)
      return created
    })().finally(() => { createPromise.current = null })

    return createPromise.current
  }, [applyDraftMeta, sourceRecord])

  const persistSnapshot = useCallback(async (): Promise<TeachingDraft> => enqueue(async () => {
    if (conflicted.current) throw new DraftConflictError('กรุณาตรวจและโหลดร่างล่าสุดก่อนแก้ไขต่อ')
    const snapshot = payloadRef.current
    const version = changeVersionRef.current
    if (draftRef.current && version <= savedVersionRef.current) return draftRef.current
    setSaveState('saving')
    setPageError(null)
    let currentDraft = draftRef.current
    if (!currentDraft) {
      currentDraft = await createDraft(snapshot)
      savedFingerprintRef.current = fingerprint(snapshot, currentDraft.images || [])
      savedVersionRef.current = Math.max(savedVersionRef.current, version)
      setSaveState(changeVersionRef.current === version ? 'saved' : 'idle')
      return currentDraft
    }

    const response = await fetch(`/teaching-drafts/${currentDraft.id}`, {
      method: 'PATCH',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
      body: JSON.stringify({ lock_version: currentDraft.lock_version, payload: snapshot }),
    })
    const envelope = await parseError(response)
    if (response.status === 409) {
      handleConflict(envelope)
      throw new DraftConflictError(envelope.message || 'ร่างมีการแก้ไขจากอีกหน้าต่าง')
    }
    const updated = envelope.data?.draft
    if (!response.ok || !updated) throw new Error(envelope.message || 'บันทึกร่างไม่สำเร็จ')
    applyDraftMeta(updated)
    savedFingerprintRef.current = fingerprint(snapshot, updated.images || [])
    savedVersionRef.current = Math.max(savedVersionRef.current, version)
    setSaveState(changeVersionRef.current === version ? 'saved' : 'idle')
    return updated
  }), [applyDraftMeta, createDraft, enqueue, handleConflict])

  const saveNow = useCallback(async (): Promise<TeachingDraft> => {
    try {
      return await persistSnapshot()
    } catch (error) {
      if (!(error instanceof DraftConflictError)) setSaveState('error')
      setPageError(error instanceof Error ? error.message : 'บันทึกร่างไม่สำเร็จ')
      throw error
    }
  }, [persistSnapshot])

  const saveDraftManually = async () => {
    if (manualSaveRef.current) return
    manualSaveRef.current = true
    const alertId = actionAlert.loading('กำลังเก็บฉบับร่าง', 'กรุณารอสักครู่ ระบบกำลังบันทึกข้อมูลของคุณ')
    try {
      await saveNow()
      actionAlert.success('เก็บฉบับร่างแล้ว', 'คุณสามารถกลับมาแก้ไขและทำต่อได้', alertId)
    } catch (error) {
      actionAlert.error('เก็บฉบับร่างไม่สำเร็จ', error instanceof Error ? error.message : 'กรุณาลองบันทึกอีกครั้ง', alertId)
    } finally {
      manualSaveRef.current = false
    }
  }

  useEffect(() => {
    if (mode !== 'edit' || draftRef.current || !sourceRecord) return
    let cancelled = false
    setBootstrapping(true)
    void createDraft(payloadRef.current, true).catch((error) => {
      if (!cancelled) setPageError(error instanceof Error ? error.message : 'เตรียมข้อมูลสำหรับแก้ไขไม่สำเร็จ')
    }).finally(() => { if (!cancelled) setBootstrapping(false) })
    return () => { cancelled = true }
  }, [createDraft, mode, sourceRecord])

  useEffect(() => {
    if (changeVersion <= savedVersionRef.current || conflicted.current || bootstrapping || finalizingRef.current) return
    const scope = payload.scope
    if (!scope.year || !scope.semester || !scope.week || !scope.date || (district && !scope.tambon)) return
    const timer = window.setTimeout(() => { void saveNow().catch(() => undefined) }, 900)
    return () => window.clearTimeout(timer)
  }, [bootstrapping, changeVersion, district, payload.scope, saveNow])

  useEffect(() => {
    const warnUnsaved = (event: BeforeUnloadEvent) => {
      if (!finalizingRef.current && (imageBusy || changeVersionRef.current > Math.max(0, savedVersionRef.current))) {
        event.preventDefault()
        event.returnValue = ''
      }
    }
    window.addEventListener('beforeunload', warnUnsaved)
    return () => window.removeEventListener('beforeunload', warnUnsaved)
  }, [imageBusy])

  useEffect(() => router.on('before', (event) => {
    if (!finalizingRef.current && (imageBusy || changeVersionRef.current > Math.max(0, savedVersionRef.current)) && !window.confirm('ยังมีข้อมูลที่บันทึกไม่เสร็จ ต้องการออกจากหน้านี้หรือไม่')) event.preventDefault()
  }), [imageBusy])

  useEffect(() => {
    if (editorMode !== 'edit' || !focusAfterReview.current) return
    const requested = focusAfterReview.current
    focusAfterReview.current = null
    const frame = requestAnimationFrame(() => {
      const section = document.getElementById(requested.section)
      const container = section?.closest('section, fieldset') || section
      const exact = requested.field ? container?.querySelector<HTMLElement>(`[data-workspace-field="${CSS.escape(requested.field)}"]`) : null
      const target = exact || container?.querySelector<HTMLElement>('input, select, textarea, button')
      section?.scrollIntoView({ block: 'start' })
      target?.focus({ preventScroll: true })
    })
    return () => cancelAnimationFrame(frame)
  }, [activeLevel, editorMode, focusRequest])

  const fixReviewIssue = (level: LevelCode, field?: string) => {
    focusAfterReview.current = { section: field?.startsWith('scope.') ? 'workspace-scope' : field?.startsWith('attendance') ? `teaching-attendance-${level}` : field === 'result' ? `teaching-content-${level}` : field === 'images' ? 'workspace-images-heading' : `teaching-basics-${level}`, field }
    setActiveLevel(level)
    setEditorMode('edit')
    setFocusRequest(request => request + 1)
    if (field?.startsWith('attendance')) window.dispatchEvent(new CustomEvent('sena-workspace-attendance-focus', { detail: { level } }))
  }

  const requestConfirmation = () => {
    if (imageBusy || finalizingRef.current || conflicted.current) return
    const currentIssues = reviewIssues(payloadRef.current, imagesRef.current)
    setShowIssues(true)
    if (currentIssues.length) { fixReviewIssue(currentIssues[0].levelCode, currentIssues[0].field); return }
    confirmationOrigin.current = document.activeElement instanceof HTMLElement ? document.activeElement : null
    setPageError(null)
    setConfirmationOpen(true)
  }

  useEffect(() => () => { Object.values(previewUrlsRef.current).forEach((url) => { if (url) URL.revokeObjectURL(url) }) }, [])

  useEffect(() => {
    const url = previewUrls[previewLevel]
    if (url && browserPreviewRequests.current[url] && browserPreviewRequests.current[url] !== fingerprint(payload, images)) {
      document.querySelector<HTMLIFrameElement>('#workspace-document-preview')?.contentWindow?.postMessage({ source: 'sena-print-invalidated' }, '*')
    }
  }, [payload, images, previewLevel, previewUrls])

  useEffect(() => {
    const receive = (event: MessageEvent) => {
      const frame = document.querySelector<HTMLIFrameElement>('#workspace-document-preview')
      if (!frame?.contentWindow || event.source !== frame.contentWindow || event.data?.source !== 'sena-print-edit-request') return
      setActiveLevelState(previewLevel)
      markChanged((current) => ({ ...current, active_level_code: previewLevel }))
      setEditorMode('edit')
    }
    window.addEventListener('message', receive)
    return () => window.removeEventListener('message', receive)
  }, [previewLevel, markChanged])

  useEffect(() => {
    if ((!district && !payload.scope.tambon) || !/^25\d{2}$/.test(payload.scope.year) || !payload.scope.semester) {
      setSubjectsLoading(false)
      if (optionsContext !== loadedSubjectContext.current) {
        setAvailableSubjects([])
        loadedSubjectContext.current = optionsContext
        setSubjectOptionsContext(optionsContext)
        setSubjectCatalog({ source: 'unavailable', error: 'เลือกตำบล ปีการศึกษา และภาคเรียนเพื่อโหลดรายวิชา', term: null })
      }
      return
    }
    const controller = new AbortController()
    setSubjectsLoading(true)
    setAvailableSubjects([])
    setSubjectCatalog(current => ({ ...current, error: null }))
    void fetch(`/record-options/workspace?${new URLSearchParams({ tambon: payload.scope.tambon, year: payload.scope.year, semester: payload.scope.semester })}`, { headers: { Accept: 'application/json' }, signal: controller.signal })
      .then(async (response) => ({ response, envelope: await response.json() as { data?: { groups?: GroupOption[]; subjects?: SubjectOption[]; source?: string; error_code?: string | null; subject_source?: string; subject_error?: string | null; subject_term?: string | null }; status?: string; message?: string } }))
      .then(({ response, envelope }) => {
        if (controller.signal.aborted) return
        if (!response.ok || !envelope.data) throw new Error(envelope.message || 'โหลดตัวเลือกไม่สำเร็จ')
        if (envelope.data?.groups) setAvailableGroups(envelope.data.groups)
        setAvailableSubjects(envelope.data.subjects || [])
        loadedSubjectContext.current = optionsContext
        setSubjectOptionsContext(optionsContext)
        setSubjectCatalog({ source: envelope.data.subject_source || 'history', error: envelope.data.subject_error || null, term: envelope.data.subject_term || null })
        if (envelope.data?.source) setLoadStates((current) => Object.fromEntries(teachingLevels.map(({ code }) => [code, { ...current[code], source: payloadRef.current.levels[code].attendance.length ? current[code].source : envelope.data!.source! }])) as Record<LevelCode, WorkspaceLoadState>)
        if (envelope.status === 'error') setPageError(envelope.message || 'โหลดตัวเลือกสำหรับภาคเรียนนี้ไม่สำเร็จ')
      }).catch((error) => {
        if (controller.signal.aborted) return
        setAvailableSubjects([])
        loadedSubjectContext.current = optionsContext
        setSubjectOptionsContext(optionsContext)
        setSubjectCatalog({ source: 'unavailable', error: 'โหลดรายวิชาไม่สำเร็จ สามารถกรอกชื่อและรหัสวิชาเองได้', term: null })
        if (error instanceof Error && error.name !== 'AbortError') setPageError(error.message)
      }).finally(() => { if (!controller.signal.aborted) setSubjectsLoading(false) })
    return () => controller.abort()
  }, [district, payload.scope.tambon, payload.scope.year, payload.scope.semester])

  useEffect(() => {
    teachingLevels.forEach(({ code }) => { requestSerial.current[code] += 1 })
    setLoadStates((states) => ({ '1': { ...states['1'], loading: false }, '2': { ...states['2'], loading: false }, '3': { ...states['3'], loading: false } }))
  }, [payload.scope.tambon, payload.scope.year, payload.scope.semester])

  const updateLevel = (levelCode: LevelCode, value: TeachingDraftPayload['levels'][LevelCode]) => markChanged((current) => ({ ...current, levels: { ...current.levels, [levelCode]: value } }))

  const editLevel = (levelCode: LevelCode, value: TeachingDraftPayload['levels'][LevelCode]) => {
    const before = payloadRef.current.levels[levelCode]
    if (before.class_group_code !== value.class_group_code || before.class_name !== value.class_name || (before.attendance_mode !== value.attendance_mode && value.attendance_mode === 'counts')) {
      requestSerial.current[levelCode] += 1
      setLoadStates((states) => ({ ...states, [levelCode]: { ...states[levelCode], loading: false, error: null } }))
    }
    updateLevel(levelCode, value)
  }

  const changeTambon = (tambon: string) => {
    const clearGroup = (value: TeachingDraftPayload['levels'][LevelCode]) => ({ ...value, class_group_code: '', class_name: '', attendance: [], total: 0, present: 0, attendance_reviewed: false })
    teachingLevels.forEach(({ code }) => { requestSerial.current[code] += 1 })
    setAvailableGroups([])
    setAvailableSubjects([])
    setLoadStates({ '1': { loading: false, error: null, source: '' }, '2': { loading: false, error: null, source: '' }, '3': { loading: false, error: null, source: '' } })
    markChanged((current) => ({ ...current, scope: { ...current.scope, tambon }, levels: { '1': clearGroup(current.levels['1']), '2': clearGroup(current.levels['2']), '3': clearGroup(current.levels['3']) } }))
  }

  const loadStudents = async (levelCode: LevelCode, group: GroupOption) => {
    const serial = ++requestSerial.current[levelCode]
    const current = payloadRef.current.levels[levelCode]
    const sameGroup = current.class_group_code === group.group_code
    updateLevel(levelCode, { ...current, class_group_code: group.group_code, class_name: group.class_name, attendance: sameGroup ? current.attendance : [], attendance_mode: 'roster', attendance_reviewed: false, total: sameGroup ? current.total : 0, present: sameGroup ? current.present : 0 })
    setLoadStates((values) => ({ ...values, [levelCode]: { ...values[levelCode], loading: true, error: null } }))
    try {
      const query = new URLSearchParams({ group_code: group.group_code, level: levelCode, year: payloadRef.current.scope.year, semester: payloadRef.current.scope.semester, ...(sameGroup ? { refresh: '1' } : {}), ...(district && payloadRef.current.scope.tambon ? { tambon: payloadRef.current.scope.tambon } : {}) })
      const response = await fetch(`/record-options/students?${query}`, { headers: { Accept: 'application/json' } })
      const envelope = await response.json() as { status?: string; message?: string; data?: { items?: WorkspaceAttendanceRow[]; source?: string } }
      if (!response.ok || envelope.status !== 'success') throw new Error(envelope.message || 'โหลดรายชื่อนักศึกษาไม่สำเร็จ')
      if (serial !== requestSerial.current[levelCode]) return
      const existingRows = sameGroup ? new Map(payloadRef.current.levels[levelCode].attendance.map((row) => [row.student_id, row])) : new Map<string, WorkspaceAttendanceRow>()
      const attendance = (envelope.data?.items || []).map((student) => {
        const existing = existingRows.get(student.student_id)
        return { ...student, present: existing?.present || false, learning_method: existing?.present ? existing.learning_method : null }
      })
      const latest = payloadRef.current.levels[levelCode]
      updateLevel(levelCode, { ...latest, class_group_code: group.group_code, class_name: group.class_name, attendance, attendance_term: `${payloadRef.current.scope.semester}/${payloadRef.current.scope.year}`, attendance_mode: 'roster', attendance_reviewed: false, total: attendance.length, present: attendance.filter((row) => row.present).length })
      setLoadStates((values) => ({ ...values, [levelCode]: { loading: false, error: null, source: envelope.data?.source || values[levelCode].source } }))
    } catch (error) {
      if (serial !== requestSerial.current[levelCode]) return
      setLoadStates((values) => ({ ...values, [levelCode]: { ...values[levelCode], loading: false, error: error instanceof Error ? error.message : 'โหลดรายชื่อนักศึกษาไม่สำเร็จ' } }))
    }
  }

  const uploadImages = async (levelCode: LevelCode, files: File[]) => {
    if (!files.length) return
    setImageBusy(true)
    setPageError(null)
    const alertId = actionAlert.loading('กำลังอัปโหลดภาพ', `กำลังเพิ่มภาพ ${files.length} รูปในฉบับร่าง`)
    let uploadedCount = 0
    try {
      await saveNow()
      for (const file of files) {
        await enqueue(async () => {
          const currentDraft = draftRef.current
          if (!currentDraft) throw new Error('ยังสร้างฉบับร่างไม่สำเร็จ')
          const body = new FormData()
          body.append('lock_version', String(currentDraft.lock_version))
          body.append('level_code', levelCode)
          body.append('image', file)
          const response = await fetch(`/teaching-drafts/${currentDraft.id}/images`, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() }, body })
          const envelope = await parseError(response)
          if (response.status === 409) { handleConflict(envelope); throw new Error(envelope.message || 'ร่างมีการแก้ไขจากอีกหน้าต่าง') }
          const image = envelope.data?.image
          const lockVersion = envelope.data?.lock_version
          if (!response.ok || !image || lockVersion === undefined) throw new Error(envelope.message || 'อัปโหลดรูปไม่สำเร็จ')
          const nextImages = [...imagesRef.current, image].sort((a, b) => a.sort_order - b.sort_order)
          imagesRef.current = nextImages
          setImages(nextImages)
          const currentLevel = payloadRef.current.levels[levelCode]
          const nextPayload = { ...payloadRef.current, levels: { ...payloadRef.current.levels, [levelCode]: { ...currentLevel, image_ids: nextImages.filter((item) => item.level_code === levelCode).map((item) => item.id) } } }
          payloadRef.current = nextPayload
          setPayload(nextPayload)
          draftRef.current = { ...currentDraft, lock_version: lockVersion, last_saved_at: envelope.data?.last_saved_at || currentDraft.last_saved_at, images: nextImages, payload: nextPayload }
          setDraft(draftRef.current)
          setLastSavedAt(envelope.data?.last_saved_at || currentDraft.last_saved_at)
          const wasClean = changeVersionRef.current <= savedVersionRef.current
          changeVersionRef.current += 1
          if (wasClean) savedVersionRef.current = changeVersionRef.current
          if (wasClean) savedFingerprintRef.current = fingerprint(nextPayload, nextImages)
          setChangeVersion(changeVersionRef.current)
          setSaveState(wasClean ? 'saved' : 'idle')
        })
        uploadedCount += 1
      }
      actionAlert.success('อัปโหลดภาพแล้ว', `เพิ่มภาพ ${uploadedCount} รูปในฉบับร่างเรียบร้อย`, alertId)
    } catch (error) {
      const message = error instanceof Error ? error.message : 'อัปโหลดรูปไม่สำเร็จ'
      setPageError(message)
      actionAlert.error('อัปโหลดภาพไม่ครบ', uploadedCount ? `เพิ่มภาพแล้ว ${uploadedCount} จาก ${files.length} รูป · ${message}` : message, alertId)
    } finally {
      setImageBusy(false)
    }
  }

  const mutateImages = async (method: 'DELETE' | 'PATCH', levelCode: LevelCode, imageId?: string, imageIds?: string[]) => {
    setImageBusy(true)
    setPageError(null)
    try {
      await saveNow()
      await enqueue(async () => {
        const currentDraft = draftRef.current
        if (!currentDraft) throw new Error('ยังสร้างฉบับร่างไม่สำเร็จ')
        const url = method === 'DELETE' ? `/teaching-drafts/${currentDraft.id}/images/${imageId}` : `/teaching-drafts/${currentDraft.id}/images/reorder`
        const response = await fetch(url, {
          method,
          headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
          body: JSON.stringify(method === 'DELETE' ? { lock_version: currentDraft.lock_version } : { lock_version: currentDraft.lock_version, level_code: levelCode, image_ids: imageIds }),
        })
        const envelope = await parseError(response)
        if (response.status === 409) { handleConflict(envelope); throw new Error(envelope.message || 'ร่างมีการแก้ไขจากอีกหน้าต่าง') }
        const nextImages = envelope.data?.images
        const lockVersion = envelope.data?.lock_version
        if (!response.ok || !nextImages || lockVersion === undefined) throw new Error(envelope.message || 'จัดการรูปไม่สำเร็จ')
        imagesRef.current = nextImages
        setImages(nextImages)
        const currentLevel = payloadRef.current.levels[levelCode]
        const nextPayload = { ...payloadRef.current, levels: { ...payloadRef.current.levels, [levelCode]: { ...currentLevel, image_ids: nextImages.filter((item) => item.level_code === levelCode).sort((a, b) => a.sort_order - b.sort_order).map((item) => item.id) } } }
        payloadRef.current = nextPayload
        setPayload(nextPayload)
        draftRef.current = { ...currentDraft, lock_version: lockVersion, last_saved_at: envelope.data?.last_saved_at || currentDraft.last_saved_at, images: nextImages, payload: nextPayload }
        setDraft(draftRef.current)
        setLastSavedAt(envelope.data?.last_saved_at || currentDraft.last_saved_at)
        const wasClean = changeVersionRef.current <= savedVersionRef.current
        changeVersionRef.current += 1
        if (wasClean) savedVersionRef.current = changeVersionRef.current
        if (wasClean) savedFingerprintRef.current = fingerprint(nextPayload, nextImages)
        setChangeVersion(changeVersionRef.current)
        setSaveState(wasClean ? 'saved' : 'idle')
      })
    } catch (error) {
      setPageError(error instanceof Error ? error.message : 'จัดการรูปไม่สำเร็จ')
    } finally {
      setImageBusy(false)
    }
  }

  const loadLatestConflict = () => {
    if (!conflictDraft || sourceConflict) return
    applyDraftMeta(conflictDraft, true)
    conflicted.current = false
    setConflictDraft(null)
    setPageError(null)
    setSaveState('saved')
  }

  const refreshFromSource = async () => {
    const sourceId = sourceRecord?.id || conflictDraft?.source_record_id
    if (!sourceId) return
    setBootstrapping(true)
    try {
      const response = await fetch('/teaching-drafts', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() }, body: JSON.stringify({ source_record_id: sourceId, refresh_source: true }) })
      const envelope = await parseError(response)
      if (!response.ok || !envelope.data?.draft) throw new Error(envelope.message || 'เตรียมร่างจากฉบับล่าสุดไม่สำเร็จ')
      applyDraftMeta(envelope.data.draft, true)
      rememberDraftUrl(envelope.data.draft.id)
      conflicted.current = false
      setSourceConflict(false)
      setConflictDraft(null)
      setPageError(null)
      setSaveState('saved')
      setEditorMode('edit')
    } catch (error) {
      setPageError(error instanceof Error ? error.message : 'เตรียมร่างจากฉบับล่าสุดไม่สำเร็จ')
    } finally {
      setBootstrapping(false)
    }
  }

  const editDocument = async (levelCode: LevelCode) => {
    try {
      const currentDraft = await saveNow()
      window.location.assign(appUrl(`/teaching-drafts/${currentDraft.id}/document/${levelCode}`))
    } catch {
      // The draft save reports its error without navigating away from unsaved work.
    }
  }

  const previewPdf = async (format: 'pdf' | 'browser' = 'browser') => {
    setPreviewing(true)
    setPageError(null)
    try {
      const currentDraft = await saveNow()
      const requestedFingerprint = savedFingerprintRef.current
      const response = await fetch(`/teaching-drafts/${currentDraft.id}/preview`, {
        method: 'POST',
        headers: { Accept: 'text/html, application/json, application/pdf', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        body: JSON.stringify({ lock_version: currentDraft.lock_version, level_code: previewLevel, document: currentDraft.payload.document || {}, format }),
      })
      if (!response.ok) {
        const envelope = await parseError(response)
        if (response.status === 409) handleConflict(envelope)
        if (response.status === 503 && format === 'pdf') setRendererUnavailable(true)
        throw new Error(envelope.message || 'สร้างตัวอย่าง PDF ไม่สำเร็จ')
      }
      const expectedType = format === 'browser' ? 'text/html' : 'application/pdf'
      if (!response.headers.get('Content-Type')?.includes(expectedType)) throw new Error('เซิร์ฟเวอร์ตอบกลับเป็นเอกสารไม่ถูกต้อง กรุณาเข้าสู่ระบบแล้วลองใหม่')
      const blob = await response.blob()
      const objectUrl = URL.createObjectURL(blob)
      if (format === 'browser') browserPreviewRequests.current[objectUrl] = requestedFingerprint
      else setRendererUnavailable(false)
      setPreviewFormats((current) => ({ ...current, [previewLevel]: format }))
      setPreviewVersions((current) => ({ ...current, [previewLevel]: currentDraft.lock_version }))
      setPreviewUrls((current) => {
        if (current[previewLevel]) {
          delete browserPreviewRequests.current[current[previewLevel]!]
          URL.revokeObjectURL(current[previewLevel]!)
        }
        const next = { ...current, [previewLevel]: objectUrl }
        previewUrlsRef.current = next
        return next
      })
      setPreviewRevisions((current) => ({ ...current, [previewLevel]: format === 'pdf' ? requestedFingerprint : null }))
    } catch (error) {
      setPageError(error instanceof Error ? error.message : 'สร้างตัวอย่าง PDF ไม่สำเร็จ')
    } finally {
      setPreviewing(false)
    }
  }

  const browserPreviewLoaded = async (frame: HTMLIFrameElement, level: LevelCode, url: string) => {
    const requestedFingerprint = browserPreviewRequests.current[url]
    if (!requestedFingerprint) return
    try {
      await waitForBrowserDocument(frame.contentDocument)
      if (previewUrlsRef.current[level] !== url) return
      if (fingerprint(payloadRef.current, imagesRef.current) !== requestedFingerprint) {
        frame.contentWindow?.postMessage({ source: 'sena-print-invalidated' }, '*')
        return
      }
      setPreviewRevisions((current) => ({ ...current, [level]: requestedFingerprint }))
    } catch (error) {
      if (previewUrlsRef.current[level] === url) setPageError(error instanceof Error ? error.message : 'โหลดเอกสารไม่สำเร็จ กรุณาสร้างตัวอย่างใหม่')
    }
  }

  const finalize = async () => {
    if (!confirmationOpen || finalizingRef.current || imageBusy || conflicted.current) return
    const currentIssues = reviewIssues(payloadRef.current, imagesRef.current)
    if (currentIssues.length) {
      setConfirmationOpen(false)
      setShowIssues(true)
      fixReviewIssue(currentIssues[0].levelCode, currentIssues[0].field)
      return
    }
    setFinalizing(true)
    finalizingRef.current = true
    setPageError(null)
    const alertId = actionAlert.loading('กำลังบันทึกหลังสอน', 'ระบบกำลังบันทึกข้อมูลระดับชั้นที่คุณยืนยัน กรุณารอสักครู่')
    try {
      const currentDraft = await saveNow()
      const response = await fetch(`/teaching-drafts/${currentDraft.id}/finalize`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        body: JSON.stringify({ lock_version: currentDraft.lock_version, idempotency_key: idempotencyKey.current, confirmed: true }),
      })
      const envelope = await parseError(response)
      if (response.status === 409) handleConflict(envelope)
      if (!response.ok) throw new Error(envelope.message || 'ยืนยันบันทึกไม่สำเร็จ')
      actionAlert.success('บันทึกหลังสอนแล้ว', 'บันทึกข้อมูลระดับชั้นที่คุณยืนยันเรียบร้อย', alertId)
      router.visit(envelope.data?.redirect_url || '/')
    } catch (error) {
      const message = error instanceof Error ? error.message : 'ยืนยันบันทึกไม่สำเร็จ'
      setPageError(message)
      actionAlert.error('บันทึกหลังสอนไม่สำเร็จ', message, alertId)
      finalizingRef.current = false
      setFinalizing(false)
    }
  }

  const issues = reviewIssues(payload, images)
  const currentFingerprint = fingerprint(payload, images)
  const selectedLevels = selectedTeachingLevels(payload)
  const totalSelectedLevels = selectedLevels.length
  const previewUrl = previewUrls[previewLevel]
  const previewStale = previewRevisions[previewLevel] === null || previewRevisions[previewLevel] !== currentFingerprint || previewVersions[previewLevel] !== draft?.lock_version
  const title = mode === 'edit' ? 'แก้ไขบันทึกประจำสัปดาห์' : 'สร้างบันทึกประจำสัปดาห์'

  if (bootstrapping) {
    return <AppShell><Head title={title} /><PageHeader title={title} description="กำลังเตรียมข้อมูลเดิมให้แก้ไขต่อใน workspace เดียวกัน" /><div className="grid min-h-[55vh] place-items-center rounded-xl border bg-card"><div className="text-center"><CloudArrowUp size={36} className="mx-auto animate-pulse text-primary" /><b className="mt-3 block">กำลังเตรียมฉบับร่าง</b><p className="mt-1 text-sm text-muted-foreground">ข้อมูล รูป และรายชื่อเดิมจะไม่ถูกเปลี่ยนจนกว่าจะยืนยัน</p></div></div></AppShell>
  }

  if (mode === 'edit' && !draft) {
    return <AppShell><Head title={title} /><PageHeader title={title} description="ยังเตรียมข้อมูลเดิมไม่สำเร็จ" /><Card><CardContent className="grid gap-4 p-6"><p role="alert" className="text-sm text-destructive">{pageError || 'ไม่สามารถเตรียมฉบับร่างสำหรับแก้ไขได้'}</p><div className="flex flex-wrap gap-3"><Button type="button" onClick={() => window.location.reload()}>ลองโหลดข้อมูลอีกครั้ง</Button><Button asChild variant="outline"><Link href={sourceRecord ? `/records/${sourceRecord.id}` : '/'}>กลับไปดูบันทึกเดิม</Link></Button></div></CardContent></Card></AppShell>
  }

  return <AppShell>
    <Head title={title} />
    <PageHeader title={title} description="ระบบเก็บร่างอัตโนมัติ บันทึกเมื่อข้อมูลพร้อม หรือเปิดดูตัวอย่าง PDF ได้ตามต้องการ" action={<Button asChild variant="outline"><Link href={sourceRecord ? `/records/${sourceRecord.id}` : '/'}><ArrowLeft size={17} />{sourceRecord ? 'ดูบันทึกเดิม' : 'กลับหน้าภาพรวม'}</Link></Button>} />
    <FlashMessages />

    {pageError && <div className="mb-5 flex flex-col gap-3 rounded-xl border border-destructive/25 bg-destructive/5 p-4 text-sm text-destructive sm:flex-row sm:items-center sm:justify-between" role="alert"><div className="flex items-start gap-2"><WarningCircle size={20} className="mt-0.5 shrink-0" /><span>{pageError}{sourceConflict && <small className="mt-1 block">ร่างที่กำลังทำยังถูกเก็บไว้ คุณสามารถเริ่มร่างใหม่จากบันทึกต้นฉบับล่าสุดได้</small>}</span></div>{conflictDraft && (sourceConflict ? <Button type="button" variant="outline" size="sm" onClick={() => void refreshFromSource()}>เริ่มร่างจากฉบับล่าสุด</Button> : <Button type="button" variant="outline" size="sm" onClick={() => { if (window.confirm('โหลดร่างล่าสุดจากเซิร์ฟเวอร์แทนข้อมูลในหน้านี้หรือไม่')) loadLatestConflict() }}>โหลดร่างล่าสุดจากเซิร์ฟเวอร์</Button>)}</div>}

    <Card className="mb-5">
      <CardHeader className="gap-3 pb-4 sm:flex-row sm:items-center sm:justify-between"><div><CardTitle>ข้อมูลร่วมของสัปดาห์</CardTitle><p className="mt-1 text-sm text-muted-foreground">ใช้กับบันทึกระดับชั้นที่เลือก</p></div><div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground" role="status" aria-live="polite">{saveState === 'saving' ? <CloudArrowUp size={18} className="animate-pulse text-primary" /> : saveState === 'saved' ? <CloudCheck size={18} className="text-primary" weight="fill" /> : <FloppyDisk size={17} />}<span>{saveState === 'saving' ? 'กำลังบันทึก...' : saveState === 'error' ? 'บันทึกไม่สำเร็จ กรุณาลองใหม่' : saveState === 'conflict' ? 'พบการแก้ไขจากอีกหน้าต่าง' : !draft && !payload.scope.week ? 'เลือกสัปดาห์เพื่อเริ่มเก็บร่าง' : saveState === 'idle' && changeVersion > savedVersionRef.current ? 'มีการแก้ไข รอบันทึก' : savedAtLabel(lastSavedAt)}</span>{saveState !== 'saving' && saveState !== 'conflict' && <Button type="button" variant="ghost" size="sm" disabled={finalizing || imageBusy} onClick={() => void saveDraftManually()}>เก็บร่างตอนนี้</Button>}</div></CardHeader>
      <CardContent><fieldset id="workspace-scope" disabled={finalizing} className={`grid min-w-0 scroll-mt-28 grid-cols-2 gap-4 ${district ? 'lg:grid-cols-5' : 'lg:grid-cols-4'}`}>
        {district && <FormField label="ตำบล *"><NativeSelect data-workspace-field="scope.tambon" disabled={finalizing} value={payload.scope.tambon} onChange={(event) => changeTambon(event.target.value)}><option value="">เลือกตำบล</option>{tambonOptions.map((tambon) => <option key={tambon.id} value={tambon.id}>{tambon.name}</option>)}</NativeSelect></FormField>}
        <FormField label="ปีการศึกษา *"><Input data-workspace-field="scope.year" inputMode="numeric" value={payload.scope.year} onChange={(event) => markChanged((current) => ({ ...current, scope: { ...current.scope, year: event.target.value } }))} /></FormField>
        <FormField label="ภาคเรียน *"><NativeSelect data-workspace-field="scope.semester" value={payload.scope.semester} onChange={(event) => markChanged((current) => ({ ...current, scope: { ...current.scope, semester: event.target.value } }))}>{['1', '2', '3'].map((term) => <option key={term} value={term}>ภาคเรียน {term}</option>)}</NativeSelect></FormField>
        <FormField label="สัปดาห์ *"><NativeSelect data-workspace-field="scope.week" value={payload.scope.week} onChange={(event) => markChanged((current) => ({ ...current, scope: { ...current.scope, week: event.target.value } }))}><option value="">เลือกสัปดาห์</option>{Array.from({ length: 20 }, (_, index) => index + 1).map((week) => <option key={week} value={week}>สัปดาห์ {week}</option>)}</NativeSelect></FormField>
        <FormField label="วันที่สอน *"><Input data-workspace-field="scope.date" type="date" value={payload.scope.date} onChange={(event) => markChanged((current) => ({ ...current, scope: { ...current.scope, date: event.target.value } }))} /></FormField>
      </fieldset>
      <div className="mt-4 border-t pt-4">
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <span className="text-xs font-semibold text-foreground">ระดับชั้นที่เปิดสอนในสัปดาห์นี้ *</span>
            <p className="text-[11px] text-muted-foreground">เลือกเฉพาะระดับชั้นที่มีการเรียนการสอน (ระบบจะบันทึกและตรวจเฉพาะระดับที่เลือก)</p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            {teachingLevels.map((lvl) => {
              const currentEnabled = payload.enabled_level_codes ?? ['1', '2', '3']
              const isChecked = currentEnabled.includes(lvl.code)
              const isOnlyOne = isChecked && currentEnabled.length <= 1
              return (
                <label
                  key={lvl.code}
                  className={cn(
                    'focus-ring flex cursor-pointer select-none items-center gap-2 rounded-lg border px-3 py-1.5 text-xs transition-colors',
                    isChecked ? 'border-primary/40 bg-primary/10 font-medium text-primary' : 'border-border text-muted-foreground hover:bg-muted/50',
                    (isOnlyOne || draft?.source_level_codes?.includes(lvl.code)) && 'cursor-not-allowed opacity-75',
                  )}
                >
                  <Switch
                    data-workspace-field="scope.levels" aria-label={`สอนระดับ${lvl.name}`}
                    checked={isChecked}
                    disabled={finalizing || isOnlyOne || draft?.source_level_codes?.includes(lvl.code)}
                    onCheckedChange={() => toggleLevel(lvl.code)}
                  />
                  <span>{lvl.name}</span>
                </label>
              )
            })}
          </div>
        </div>
      </div>
      </CardContent>
    </Card>

    {showIssues && <WorkspaceIssues issues={issues} onFix={fixReviewIssue} />}
    <RecordSaveConfirmation open={confirmationOpen} onOpenChange={setConfirmationOpen} payload={payload} images={images} finalizing={finalizing} busy={imageBusy || saveState === 'conflict'} error={pageError} onConfirm={finalize} onReturnFocus={() => { if (confirmationOrigin.current?.isConnected) confirmationOrigin.current.focus({ preventScroll: true }) }} />
    {editorMode === 'review' ? <RecordReview payload={payload} previewLevel={previewLevel} previewUrl={previewUrl} previewing={previewing} previewStale={previewStale} previewFormat={previewFormats[previewLevel]} rendererUnavailable={rendererUnavailable} onBrowserPreview={() => previewPdf('browser')} onBrowserPreviewLoaded={browserPreviewLoaded} finalizing={finalizing} busy={imageBusy || saveState === 'conflict'} onBack={() => setEditorMode('edit')} onPreviewLevel={setPreviewLevel} onPreview={previewPdf} onEditDocument={editDocument} onSave={requestConfirmation} /> : <div className="grid grid-cols-[minmax(0,1fr)] items-start gap-5 xl:grid-cols-[180px_minmax(0,1fr)] 2xl:grid-cols-[180px_minmax(0,1fr)_260px]">
      <aside className="rounded-xl border bg-card p-3 xl:sticky xl:top-24"><p className="mb-3 px-2 text-xs font-semibold text-muted-foreground">เลือกระดับชั้น</p><WeeklyLevelNavigation activeLevel={activeLevel} levels={payload.levels} enabledLevelCodes={payload.enabled_level_codes ?? ['1', '2', '3']} onChange={setActiveLevel} /><Button type="button" className="mt-3 w-full" disabled={finalizing || imageBusy || saveState === 'conflict'} onClick={requestConfirmation}>บันทึก<CheckCircle size={18} /></Button></aside>
      <Card className="min-w-0"><CardContent className="p-4 sm:p-6"><TeachingDetailsForm key={activeLevel} levelCode={activeLevel} value={payload.levels[activeLevel]} groups={availableGroups} subjects={availableSubjects} subjectsLoading={subjectsLoading || subjectOptionsContext !== optionsContext} subjectSource={subjectCatalog.source} subjectError={subjectCatalog.error} subjectTerm={subjectCatalog.term} subjectContext={optionsContext} loadState={loadStates[activeLevel]} images={images.filter((image) => image.level_code === activeLevel).sort((a, b) => a.sort_order - b.sort_order)} imageBusy={imageBusy} term={`${payload.scope.semester}/${payload.scope.year}`} sourceLabel={sourceLabel(loadStates[activeLevel].source)} onChange={(value) => editLevel(activeLevel, value)} onSelectGroup={(group) => loadStudents(activeLevel, group)} onUploadImages={(files) => uploadImages(activeLevel, files)} onDeleteImage={(image) => mutateImages('DELETE', activeLevel, image.id)} onReorderImages={(imageIds) => mutateImages('PATCH', activeLevel, undefined, imageIds)} /></CardContent></Card>
      <aside className="grid gap-4 rounded-xl border bg-card p-4 xl:col-span-2 2xl:sticky 2xl:top-24 2xl:col-span-1"><WorkspaceSummary payload={payload} images={images} activeLevel={activeLevel} onSelectLevel={setActiveLevel} /><div className="border-t pt-4"><Button type="button" size="lg" className="w-full" disabled={finalizing || imageBusy || saveState === 'conflict'} onClick={requestConfirmation}>บันทึก {totalSelectedLevels} ระดับ<CheckCircle size={19} /></Button><p className="mt-2 text-center text-[11px] leading-5 text-muted-foreground">{issues.length ? `ยังมี ${issues.length} จุดให้ตรวจ กดบันทึกเพื่อไปแก้ไข` : 'ข้อมูลพร้อมบันทึก'}</p><Button type="button" variant="outline" className="mt-3 w-full" disabled={finalizing || imageBusy || saveState === 'conflict'} onClick={() => { setPreviewLevel(activeLevel); setEditorMode('review') }}>ดูตัวอย่าง PDF</Button></div></aside>
    </div>}
  </AppShell>
}
