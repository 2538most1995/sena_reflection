import { Component, type ErrorInfo, type ReactNode } from 'react'
import { WarningCircle, ArrowClockwise, House } from '@phosphor-icons/react'

interface Props {
  children: ReactNode
}

interface State {
  hasError: boolean
  error: Error | null
}

export class ErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props)
    this.state = { hasError: false, error: null }
  }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error }
  }

  componentDidCatch(error: Error, errorInfo: ErrorInfo) {
    console.error('Unhandled React Error in ErrorBoundary:', error, errorInfo)
  }

  handleReload = () => {
    window.location.reload()
  }

  render() {
    if (this.state.hasError) {
      const errorMessage = this.state.error?.message || String(this.state.error || 'Unknown error')
      return (
        <div className="min-h-screen bg-muted/30 flex items-center justify-center p-4">
          <div className="max-w-md w-full bg-card rounded-2xl shadow-lg border border-border p-6 text-center">
            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-destructive/10 text-destructive mb-4">
              <WarningCircle size={32} weight="bold" />
            </div>
            <h2 className="text-lg font-bold text-foreground mb-2">
              เกิดข้อผิดพลาดในการแสดงผล
            </h2>
            <p className="text-sm text-muted-foreground mb-4">
              ระบบพบปัญหาชั่วคราวในการโหลดข้อมูล กรุณากดปุ่มเพื่อรีเฟรชหน้าเว็บ หรือกลับไปยังหน้าหลัก
            </p>
            <div className="text-left bg-muted/60 text-muted-foreground text-xs p-3 rounded-lg mb-5 overflow-auto max-h-28 font-mono border">
              {errorMessage}
            </div>
            <div className="flex flex-wrap gap-2 justify-center">
              <button
                type="button"
                onClick={this.handleReload}
                className="inline-flex items-center gap-1.5 px-4 py-2 bg-primary text-primary-foreground font-medium rounded-lg text-sm hover:opacity-90 transition-opacity cursor-pointer"
              >
                <ArrowClockwise size={16} />
                รีเฟรชหน้าเว็บ
              </button>
              <a
                href="/"
                className="inline-flex items-center gap-1.5 px-4 py-2 bg-secondary text-secondary-foreground font-medium rounded-lg text-sm hover:bg-secondary/80 transition-colors"
              >
                <House size={16} />
                หน้าหลัก
              </a>
            </div>
          </div>
        </div>
      )
    }

    return this.props.children
  }
}
