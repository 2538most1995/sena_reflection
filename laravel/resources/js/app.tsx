import '../css/app.css'

import { createInertiaApp } from '@inertiajs/react'
import type { ComponentType } from 'react'
import { createRoot } from 'react-dom/client'
import { ActionAlertHost } from '@/components/action-alert'

createInertiaApp({
  title: (title) => (title ? `${title} | SENA Reflection` : 'SENA Reflection'),
  resolve: (name) => {
    const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx')
    const page = pages[`./pages/${name}.tsx`]
    if (!page) throw new Error(`ไม่พบหน้า Inertia: ${name}`)
    return page().then((module) => module.default)
  },
  setup({ el, App, props }) {
    if (!el) throw new Error('ไม่พบ Inertia root element')
    createRoot(el).render(<><App {...props} /><ActionAlertHost /></>)
  },
  progress: {
    color: '#17766e',
    showSpinner: false,
  },
})
