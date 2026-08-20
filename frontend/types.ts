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
  miniToken?: string
  dynamicCss?: string
}

export interface SseUpdate {
  audioData?: string
  html?: string
  message?: string
  messageId?: string
  mimeType?: string
  restoredMessage?: string | null
  sessionId?: string
  threadId?: string
}
