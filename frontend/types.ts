export interface BrainOption {
  slug: string
  name: string
  description: string
  avatar: string
  css?: string
  cssInline?: string
}

export interface WorkflowOption {
  slug: string
  label: string
}

export interface BrainInfo {
  name: string
  description: string
  avatar: string
  css?: string
  cssInline?: string
}

export interface UserInfo {
  id: string
  displayName: string
}

export type DisplayMode = 'normal' | 'embed'
export type LayoutMode = 'full' | 'compact'

export interface ClaireBootstrap {
  mode: DisplayMode
  baseUrl: string
  acceptedExt: string
  threadId: string
  sessionId: string
  brainInfo: BrainInfo
  currentBrain: string
  brains: BrainOption[]
  comfyuiEnabled: boolean
  workflows: WorkflowOption[]
  currentWorkflow: string
  longTermMemoryEnabled: boolean
  layoutMode: LayoutMode
  user: UserInfo | null
  refreshBeforeExpire: number
  refreshMinInterval: number
  sessionToken?: string
  miniToken?: string
  dynamicCss?: string
}

export interface SseUpdate {
  html?: string
  message?: string
  messageId?: string
  restoredMessage?: string | null
  sessionId?: string
  threadId?: string
}
