import { CheckCircle, WarningCircle } from '@phosphor-icons/react'
import { usePage } from '@inertiajs/react'
import type { SharedProps } from '@/types'

export function FlashMessages() {
  const { props } = usePage<SharedProps>()
  const errorList = Object.values(props.errors || {})
  if (!props.flash.success && !props.flash.error && errorList.length === 0) return null
  return <div className="mb-5 grid gap-3" aria-live="polite">
    {props.flash.success && <div className="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-100"><CheckCircle size={20} weight="fill" className="mt-0.5 shrink-0" /><span>{props.flash.success}</span></div>}
    {(props.flash.error || errorList.length > 0) && <div className="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/50 dark:text-red-100"><WarningCircle size={20} weight="fill" className="mt-0.5 shrink-0" /><div><b>ยังดำเนินการไม่ได้ กรุณาตรวจสอบข้อมูล</b>{props.flash.error && <p>{props.flash.error}</p>}{errorList.length > 0 && <ul className="mt-1 list-disc pl-5">{errorList.map((error, index) => <li key={`${error}-${index}`}>{error}</li>)}</ul>}</div></div>}
  </div>
}
