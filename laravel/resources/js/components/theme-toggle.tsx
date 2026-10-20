import { Moon, Sun } from '@phosphor-icons/react'
import { useEffect, useState } from 'react'
import { Button } from '@/components/ui/button'

export function ThemeToggle() {
  const [dark, setDark] = useState(false)

  useEffect(() => {
    const saved = localStorage.getItem('sena-theme')
    const value = saved ? saved === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches
    document.documentElement.classList.toggle('dark', value)
    setDark(value)
  }, [])

  const toggle = () => {
    const next = !dark
    document.documentElement.classList.toggle('dark', next)
    localStorage.setItem('sena-theme', next ? 'dark' : 'light')
    setDark(next)
  }

  return <Button variant="ghost" size="icon" onClick={toggle} aria-label={dark ? 'ใช้โหมดสว่าง' : 'ใช้โหมดมืด'}>
    {dark ? <Sun size={20} /> : <Moon size={20} />}
  </Button>
}
