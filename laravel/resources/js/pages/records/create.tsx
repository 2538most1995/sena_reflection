import { TeachingWorkspace } from '@/components/teaching-workspace/teaching-workspace'
import type { TeachingWorkspaceBootstrap, TeachingWorkspaceProps } from '@/components/teaching-workspace/types'

type Props = Omit<TeachingWorkspaceProps, 'mode' | 'sourceRecord'> & {
  workspace?: TeachingWorkspaceBootstrap | null
}

export default function CreateRecord(props: Props) {
  return <TeachingWorkspace {...props} mode={props.workspace?.mode === 'edit' ? 'edit' : 'create'} sourceRecord={null} />
}
