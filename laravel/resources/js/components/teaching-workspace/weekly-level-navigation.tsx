import { Check, Circle, Warning } from '@phosphor-icons/react'
import { cn } from '@/lib/utils'
import type { LevelCode, TeachingLevelDraft } from './types'
import { teachingLevels } from './types'
import { teachingLevelReady } from './review-state'

type Props = {
  activeLevel: LevelCode
  levels: Record<LevelCode, TeachingLevelDraft>
  enabledLevelCodes: LevelCode[]
  onChange: (level: LevelCode) => void
}

function levelState(level: TeachingLevelDraft): 'complete' | 'started' | 'empty' {
  if (teachingLevelReady(level)) {
    return 'complete'
  }

  if (level.class_name || level.subject || level.attendance.length || level.result || level.image_ids.length) {
    return 'started'
  }

  return 'empty'
}

export function WeeklyLevelNavigation({ activeLevel, levels, enabledLevelCodes, onChange }: Props) {
  const selected = teachingLevels.filter((level) => enabledLevelCodes.includes(level.code))
  return <nav aria-label="ระดับชั้นในชุดงาน" className={cn('grid gap-2 xl:grid-cols-1', selected.length === 1 ? 'grid-cols-1' : selected.length === 2 ? 'grid-cols-2' : 'grid-cols-3')}>
    {selected.map((level, index) => {
      const state = levelState(levels[level.code])
      const active = activeLevel === level.code

      return <button
        key={level.code}
        type="button"
        onClick={() => onChange(level.code)}
        aria-current={active ? 'step' : undefined}
        title={level.name}
        className={cn(
          'focus-ring group flex min-h-14 w-full items-center gap-2.5 rounded-xl border px-3 py-2.5 text-left transition-[background-color,border-color,color] duration-150',
          active ? 'border-primary/40 bg-primary text-primary-foreground shadow-[0_8px_24px_rgba(20,58,55,.16)]' : 'border-transparent bg-background/60 hover:border-border hover:bg-muted/40',
        )}
      >
        <span className={cn('hidden size-7 shrink-0 place-items-center rounded-lg text-xs font-bold xl:grid', active ? 'bg-primary-foreground/15 text-primary-foreground' : 'bg-muted text-muted-foreground')}>{index + 1}</span>
        <span className="min-w-0 flex-1">
          <b className="block whitespace-nowrap text-xs font-semibold sm:text-sm">{level.shortName}</b>
          <small className={cn('block whitespace-nowrap text-[11px] leading-tight mt-0.5', active ? 'text-primary-foreground/80' : 'text-muted-foreground')}>
            {state === 'complete' ? 'พร้อมบันทึก' : state === 'started' ? 'กำลังทำ' : 'ยังไม่เริ่ม'}
          </small>
        </span>
        <span className="hidden shrink-0 xl:block">{state === 'complete' ? <Check size={16} weight="bold" aria-label="พร้อมบันทึก" className={active ? 'text-primary-foreground' : 'text-emerald-600'} /> : state === 'started' ? <Warning size={16} weight="fill" aria-label="กำลังทำ" className={active ? 'text-amber-200' : 'text-amber-500'} /> : <Circle size={13} aria-label="ยังไม่เริ่ม" className={active ? 'text-primary-foreground/50' : 'text-muted-foreground/60'} />}</span>
      </button>
    })}
  </nav>
}
