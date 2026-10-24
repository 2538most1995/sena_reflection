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
        className={cn(
          'focus-ring group flex min-h-14 w-full items-center gap-2 rounded-xl border px-2 py-2.5 text-left transition-[background-color,border-color,color] duration-150 xl:gap-3 xl:px-3',
          active ? 'border-primary/35 bg-primary text-primary-foreground shadow-[0_10px_28px_rgba(20,58,55,.16)]' : 'border-transparent bg-transparent hover:border-border hover:bg-background',
        )}
      >
        <span className={cn('hidden size-8 shrink-0 place-items-center rounded-lg text-xs font-bold xl:grid', active ? 'bg-primary-foreground/10 text-primary-foreground' : 'bg-muted text-muted-foreground')}>{index + 1}</span>
        <span className="min-w-0 flex-1">
          <b className="block truncate text-xs sm:text-sm">{level.shortName}</b>
          <small className={cn('block truncate text-[11px]', active ? 'text-primary-foreground/80' : 'text-muted-foreground')}>
            {state === 'complete' ? 'พร้อมบันทึก' : state === 'started' ? 'กำลังทำ' : 'ยังไม่เริ่ม'}
          </small>
        </span>
        <span className="hidden xl:block">{state === 'complete' ? <Check size={17} weight="bold" aria-label="พร้อมบันทึก" /> : state === 'started' ? <Warning size={17} weight="fill" aria-label="กำลังทำ" /> : <Circle size={14} aria-label="ยังไม่เริ่ม" />}</span>
      </button>
    })}
  </nav>
}
