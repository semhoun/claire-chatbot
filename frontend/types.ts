export interface Theme {
  preset: string
  tokens: Record<string, string>
  variants: Record<string, string>
}

export interface BrainOption {
  slug: string
  name: string
  description: string
  avatar: string
  theme: Theme
}

export interface PageData {
  page: 'app' | 'callback' | 'error'
  baseUrl: string
  sessionToken?: string
  redirectUrl?: string
  code?: number
  title?: string
  details?: Record<string, unknown> | null
}

export interface WorkflowOption {
  slug: string
  label: string
}

export interface BrainInfo {
  name: string
  description: string
  avatar: string
  theme: Theme
}

export interface UserInfo {
  id: string
  displayName: string
}

export type DisplayMode = 'normal' | 'embed'
export type LayoutMode = 'full' | 'compact'
export type AudioDictationMode = 'review' | 'auto_send'

export interface AudioVoice {
  id: string
  label: string
}

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
  audioAvailable: boolean
  audioEnabled: boolean
  audioAutoGenerate: boolean
  audioDictationMode: AudioDictationMode
  audioVoice: string
  audioVoices: AudioVoice[]
  audioTranscriptionModel: string
  audioSpeechModel: string
  audioMaxRecordingSeconds: number
  user: UserInfo | null
  refreshBeforeExpire: number
  refreshMinInterval: number
  sessionToken?: string
}

export interface GeneratedFile {
  id: string
  name: string
  type: string
  url: string | null
}

export interface ToolCall {
  id: string
  name: string
  inputs: Array<{ name: string; value: unknown }>
  running: boolean
  interrupted?: boolean
  result: unknown
}

export interface ChatMessage {
  submissionId?: string
  error?: boolean
  id: string
  message: string
  sent: boolean
  time: string
  toolsCall: ToolCall[]
  files: GeneratedFile[]
}

export interface SseUpdate {
  generation?: { messageId: string; status: 'queued' | 'running' | 'done' | 'error' | '' }
  generationMessageId?: string | null
  submissionId?: string
  turnStatus?: 'running' | 'succeeded' | 'rolled_back'
  rollbackConfirmed?: boolean
  generationStatus?: 'queued' | 'running' | 'done' | 'error' | null
  audioRequestId?: string | null
  audioRequestIds?: Record<string, string>
  responding?: boolean
  activeMessageId?: string | null
  audioData?: string
  messages?: ChatMessage[]
  entry?: ChatMessage
  toolsCall?: ToolCall[]
  files?: GeneratedFile[]
  message?: string
  messageId?: string
  mimeType?: string
  restoredMessage?: string | null
  sessionId?: string
  threadId?: string
}
