import { type ClassValue, clsx } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

export function thaiDate(value: string) {
  if (!value) return '-'
  return new Intl.DateTimeFormat('th-TH', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(value.includes('T') ? value : `${value}T00:00:00`))
}

export function number(value: number | string) {
  return new Intl.NumberFormat('th-TH').format(Number(value || 0))
}
