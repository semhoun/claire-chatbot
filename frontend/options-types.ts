export interface HistoryItem {
  threadId: string
  title: string
  summary: string
  updatedAt: string
}

export interface StoredFile {
  fileId: string
  filename: string
  mimeType: string
  sizeBytes: number
  createdAt: string
}

export interface RagDocument {
  documentId: string
  name: string
  sourceType: string
  isActive: boolean
  chunkCount: number
  createdAt: string
}

export interface HistoryList { histories: HistoryItem[] }
export interface FileList { files: StoredFile[]; acceptedExt: string }
export interface RagList { documents: RagDocument[]; acceptedExt: string }
export interface RagSegments {
  document: Pick<RagDocument, 'documentId' | 'name'>
  segments: string[]
}
export interface TelegramConfig {
  telegramId: string | null
  success: string | null
  error: string | null
}
