import { Eraser, FileText, Highlighter, ImageSquare, ListBullets, ListNumbers, TextAlignCenter, TextAlignJustify, TextAlignLeft, TextAlignRight, TextB, TextItalic, TextStrikethrough, TextUnderline } from '@phosphor-icons/react'
import type { ReactNode } from 'react'
import { Button } from '@/components/ui/button'
import { NativeSelect } from '@/components/ui/native-select'
import { cn } from '@/lib/utils'

export type DocumentSelection = { key: string; label: string; bold: boolean; italic: boolean; underline: boolean; strikeThrough: boolean; unorderedList: boolean; orderedList: boolean; color: string; backgroundColor: string; fontSize: number; fontFamily: string; align: string }

function colorInputValue(color: string | undefined, fallback: string) {
  if (color?.startsWith('#')) return color
  const channels = color?.match(/^rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)$/)
  return channels ? `#${channels.slice(1).map((channel) => Number(channel).toString(16).padStart(2, '0')).join('')}` : fallback
}

type Props = {
  selection: DocumentSelection | null
  disabled: boolean
  onCommand: (command: string, value?: string) => void
  onOpenTools: (tab: 'content' | 'paper' | 'elements') => void
}

export function DocumentRibbon({ selection, disabled, onCommand, onOpenTools }: Props) {
  const unavailable = disabled || !selection
  const tool = (command: string, label: string, icon: ReactNode, active = false) => <Button key={command} type="button" size="icon" variant={active ? 'secondary' : 'ghost'} disabled={unavailable} aria-label={label} title={label} aria-pressed={active} className="size-9 min-h-9 rounded-md" onMouseDown={(event) => event.preventDefault()} onClick={() => onCommand(command)}>{icon}</Button>
  return <div className="overflow-hidden rounded-xl border bg-card shadow-sm" aria-label="เครื่องมือจัดเอกสาร">
    <div className="flex items-center gap-1 border-b bg-muted/35 px-3 pt-1 text-xs">
      <span className="border-b-2 border-primary px-4 py-2 font-semibold text-primary">หน้าแรก</span>
      <button type="button" className="focus-ring rounded-t-md px-4 py-2 hover:bg-muted" onClick={() => onOpenTools('paper')}>เค้าโครงกระดาษ</button>
      <button type="button" className="focus-ring rounded-t-md px-4 py-2 hover:bg-muted" onClick={() => onOpenTools('elements')}>รูปภาพ / ลายเซ็น</button>
      <span className="ml-auto hidden truncate px-3 text-muted-foreground md:block">{selection ? `กำลังแก้ไข: ${selection.label}` : 'คลิกแล้วเลือกข้อความบนกระดาษเพื่อจัดรูปแบบ'}</span>
    </div>
    <div className="flex items-stretch overflow-x-auto p-2">
      <fieldset disabled={unavailable} className="grid shrink-0 gap-1 border-r px-2 pb-1">
        <legend className="sr-only">ตัวอักษร</legend>
        <div className="flex gap-2">
          <NativeSelect aria-label="แบบอักษรข้อความที่เลือก" className="h-9 min-h-9 w-36 text-xs" value={['THSarabunNew', 'Sarabun', 'Tahoma', 'Arial'].includes(selection?.fontFamily || '') ? selection?.fontFamily : 'THSarabunNew'} onChange={(event) => onCommand('fontName', event.target.value)}><option value="THSarabunNew">TH Sarabun New</option><option value="Sarabun">Sarabun</option><option value="Tahoma">Tahoma</option><option value="Arial">Arial</option></NativeSelect>
          <NativeSelect aria-label="ขนาดข้อความที่เลือก" className="h-9 min-h-9 w-20 text-xs" value={String(selection?.fontSize || 16)} onChange={(event) => onCommand('fontSize', event.target.value)}>{[...new Set([10, 12, 14, 16, 18, 20, 22, 24, 28, 32, selection?.fontSize || 16])].sort((a, b) => a - b).map((size) => <option key={size} value={size}>{size} pt</option>)}</NativeSelect>
        </div>
        <div className="flex items-center gap-0.5">
          {tool('bold', 'ตัวหนา (Ctrl/Cmd+B)', <TextB size={18} />, selection?.bold)}
          {tool('italic', 'ตัวเอียง (Ctrl/Cmd+I)', <TextItalic size={18} />, selection?.italic)}
          {tool('underline', 'ขีดเส้นใต้ (Ctrl/Cmd+U)', <TextUnderline size={18} />, selection?.underline)}
          {tool('strikeThrough', 'ขีดฆ่า', <TextStrikethrough size={18} />, selection?.strikeThrough)}
          <label title="สีตัวอักษร" className={cn('relative grid size-9 cursor-pointer place-items-center rounded-md text-base font-bold hover:bg-muted', unavailable && 'cursor-default opacity-40')}><span className="border-b-[3px] leading-5" style={{ borderColor: colorInputValue(selection?.color, '#172554') }}>A</span><input type="color" aria-label="สีตัวอักษร" value={colorInputValue(selection?.color, '#172554')} className="absolute inset-0 size-full cursor-pointer opacity-0" onChange={(event) => onCommand('foreColor', event.target.value)} /></label>
          <label title="สีเน้นข้อความ" className={cn('relative grid size-9 cursor-pointer place-items-center rounded-md hover:bg-muted', unavailable && 'cursor-default opacity-40')}><Highlighter size={18} className="border-b-[3px]" style={{ borderColor: colorInputValue(selection?.backgroundColor, '#fef08a') }} /><input type="color" aria-label="สีเน้นข้อความ" value={colorInputValue(selection?.backgroundColor, '#fef08a')} className="absolute inset-0 size-full cursor-pointer opacity-0" onChange={(event) => onCommand('hiliteColor', event.target.value)} /></label>
          {tool('removeFormat', 'ล้างรูปแบบข้อความที่เลือก', <Eraser size={18} />)}
        </div>
        <span className="text-center text-[10px] text-muted-foreground">ตัวอักษร</span>
      </fieldset>
      <fieldset disabled={unavailable} className="grid shrink-0 gap-1 border-r px-3 pb-1">
        <legend className="sr-only">ย่อหน้า</legend>
        <div className="flex items-center gap-1">{tool('insertUnorderedList', 'รายการหัวข้อย่อย', <ListBullets size={19} />, selection?.unorderedList)}{tool('insertOrderedList', 'รายการลำดับเลข', <ListNumbers size={19} />, selection?.orderedList)}<span className="px-2 text-[11px] text-muted-foreground">รายการ</span></div>
        <div className="flex items-center gap-0.5">{tool('justifyLeft', 'ชิดซ้าย', <TextAlignLeft size={18} />, selection?.align === 'left')}{tool('justifyCenter', 'กึ่งกลาง', <TextAlignCenter size={18} />, selection?.align === 'center')}{tool('justifyRight', 'ชิดขวา', <TextAlignRight size={18} />, selection?.align === 'right')}{tool('justifyFull', 'กระจายเต็มบรรทัด', <TextAlignJustify size={18} />, selection?.align === 'justify')}</div>
        <span className="text-center text-[10px] text-muted-foreground">ย่อหน้า</span>
      </fieldset>
      <div className="flex shrink-0 items-center gap-1 px-3">
        <Button type="button" variant="ghost" disabled={disabled} className="h-auto flex-col gap-1 px-3 py-2" onClick={() => onOpenTools('paper')}><FileText size={25} className="text-primary" /><span className="text-xs">ระยะขอบ / แนวกระดาษ</span></Button>
        <Button type="button" variant="ghost" disabled={disabled} className="h-auto flex-col gap-1 px-3 py-2" onClick={() => onOpenTools('elements')}><ImageSquare size={25} className="text-primary" /><span className="text-xs">จัดรูปและลายเซ็น</span></Button>
      </div>
    </div>
    <p className="border-t bg-muted/20 px-4 py-1.5 text-[11px] text-muted-foreground">เลือกคำหรือย่อหน้าบนกระดาษแล้วใช้เครื่องมือ · วางข้อความด้วย Ctrl/Cmd+V · บันทึกด้วย Ctrl/Cmd+S</p>
  </div>
}
