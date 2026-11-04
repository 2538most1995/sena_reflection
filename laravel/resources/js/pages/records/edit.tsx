import { TeachingWorkspace } from '@/components/teaching-workspace/teaching-workspace'
import type { TeachingWorkspaceBootstrap, TeachingWorkspaceProps } from '@/components/teaching-workspace/types'
import type { RecordRow } from '@/types'

type Props = Omit<TeachingWorkspaceProps, 'mode' | 'sourceRecord'> & {
  record: RecordRow
  workspace?: TeachingWorkspaceBootstrap | null
}

export default function EditRecord({ record, ...props }: Props) {
  return <TeachingWorkspace key={`${record.id}:${props.workspace?.draft?.id || 'source'}`} {...props} mode="edit" sourceRecord={record} />
}
