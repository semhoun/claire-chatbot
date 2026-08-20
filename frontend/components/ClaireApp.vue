<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import hljs from 'highlight.js/lib/core'
import bash from 'highlight.js/lib/languages/bash'
import css from 'highlight.js/lib/languages/css'
import javascript from 'highlight.js/lib/languages/javascript'
import json from 'highlight.js/lib/languages/json'
import markdown from 'highlight.js/lib/languages/markdown'
import php from 'highlight.js/lib/languages/php'
import python from 'highlight.js/lib/languages/python'
import sql from 'highlight.js/lib/languages/sql'
import typescript from 'highlight.js/lib/languages/typescript'
import xml from 'highlight.js/lib/languages/xml'
import yaml from 'highlight.js/lib/languages/yaml'
import { SessionClient } from '../services/session-client'
import { BrowserAudio } from '../services/browser-audio'
import type { AudioDictationMode, ClaireBootstrap, SseUpdate } from '../types'
import ClaireIcon from './ClaireIcon.vue'

const props = defineProps<{ config: ClaireBootstrap }>()

hljs.registerLanguage('bash', bash)
hljs.registerLanguage('css', css)
hljs.registerLanguage('javascript', javascript)
hljs.registerLanguage('json', json)
hljs.registerLanguage('markdown', markdown)
hljs.registerLanguage('php', php)
hljs.registerLanguage('python', python)
hljs.registerLanguage('sql', sql)
hljs.registerLanguage('typescript', typescript)
hljs.registerLanguage('xml', xml)
hljs.registerLanguage('yaml', yaml)

const client = new SessionClient(
  props.config.baseUrl,
  props.config.refreshBeforeExpire,
  props.config.refreshMinInterval,
)
client.initialize(props.config.sessionToken, props.config.miniToken)
const browserAudio = new BrowserAudio(client, props.config.audioMaxRecordingSeconds)

const rootElement = ref<HTMLElement | null>(null)
const messagesElement = ref<HTMLElement | null>(null)
const chatBodyElement = ref<HTMLElement | null>(null)
const messageInput = ref<HTMLTextAreaElement | null>(null)
const chatFileInput = ref<HTMLInputElement | null>(null)
const threadId = ref(props.config.threadId)
const sessionId = ref(props.config.sessionId)
const collapsed = ref(props.config.mode === 'embed')
const optionsOpen = ref(false)
const openMenu = ref<string | null>(null)
const historyHtml = ref('')
const filesHtml = ref('')
const ragHtml = ref('')
const historyCount = ref(0)
const filesCount = ref(0)
const ragCount = ref(0)
const busy = ref(false)
const message = ref('')
const currentBrain = ref(props.config.currentBrain)
const brainInfo = ref({ ...props.config.brainInfo })
const dynamicCss = ref(props.config.dynamicCss ?? '')
const currentWorkflow = ref(props.config.currentWorkflow)
const longTermMemory = ref(props.config.longTermMemoryEnabled)
const layoutMode = ref(props.config.layoutMode)
const audioEnabled = ref(props.config.audioEnabled)
const audioAutoGenerate = ref(props.config.audioAutoGenerate)
const audioDictationMode = ref<AudioDictationMode>(props.config.audioDictationMode)
const audioVoice = ref(props.config.audioVoice)
const recording = ref(false)
const transcribing = ref(false)
const playingMessageId = ref<string | null>(null)
const readyAudio = new Map<string, Blob>()
const pendingAudio = new Set<string>()
const failedAudio = new Set<string>()
const autoPlayedAudio = new Set<string>()
const localFiles = ref<File[]>([])
const storedFiles = ref<Array<{ id: string; name: string }>>([])
const notification = ref<{ text: string; variant: string } | null>(null)
const modal = ref<{
  title: string
  body: string
  confirmLabel: string
  variant: string
  action: (() => Promise<void>) | null
} | null>(null)
const lightboxUrl = ref<string | null>(null)

let eventSource: EventSource | null = null
let reconnectTimer: number | null = null
let notificationTimer: number | null = null

const brainName = computed(() => {
  return props.config.brains.find((brain) => brain.slug === currentBrain.value)?.name
    ?? brainInfo.value.name
})

const layoutLabel = computed(() => {
  return layoutMode.value === 'compact' ? 'Largeur 800px' : 'Plein écran'
})

function endpoint(path: string): string {
  return `${props.config.baseUrl}${path}`
}

async function checkedRequest(path: string, init: RequestInit = {}): Promise<Response> {
  const response = await client.request(path, init)
  if (!response.ok) throw new Error(`HTTP ${response.status}`)
  return response
}

async function withBusy(action: () => Promise<void>): Promise<void> {
  busy.value = true
  try {
    await action()
  } catch (error) {
    console.error(error)
    notify('Une erreur est survenue.', 'error')
  } finally {
    busy.value = false
  }
}

function notify(text: string, variant = 'success'): void {
  if (notificationTimer !== null) window.clearTimeout(notificationTimer)
  notification.value = { text, variant }
  notificationTimer = window.setTimeout(() => {
    notification.value = null
    notificationTimer = null
  }, 4000)
}

function closeMenus(): void {
  openMenu.value = null
  optionsOpen.value = false
}

function toggleMenu(name: string): void {
  openMenu.value = openMenu.value === name ? null : name
  if (openMenu.value === 'history') void loadHistory()
  if (openMenu.value === 'files') void loadFiles()
  if (openMenu.value === 'rag') void loadRag()
}

async function refreshCounters(): Promise<void> {
  const [history, files, rag] = await Promise.allSettled([
    client.request('/history/count'),
    client.request('/files/count'),
    client.request('/rag/count'),
  ])
  if (history.status === 'fulfilled' && history.value.ok) {
    historyCount.value = Number(await history.value.text()) || 0
  }
  if (files.status === 'fulfilled' && files.value.ok) {
    filesCount.value = Number(await files.value.text()) || 0
  }
  if (rag.status === 'fulfilled' && rag.value.ok) {
    ragCount.value = Number(await rag.value.text()) || 0
  }
}

async function loadHistory(): Promise<void> {
  await withBusy(async () => {
    historyHtml.value = await (await checkedRequest('/history/list')).text()
  })
}

async function loadFiles(): Promise<void> {
  await withBusy(async () => {
    filesHtml.value = await (await checkedRequest('/files/list')).text()
  })
}

async function loadRag(): Promise<void> {
  await withBusy(async () => {
    ragHtml.value = await (await checkedRequest('/rag/list')).text()
  })
}

function connectStream(): void {
  eventSource?.close()
  eventSource = null
  if (reconnectTimer !== null) window.clearTimeout(reconnectTimer)
  const token = client.getMiniToken()
  if (token === null) {
    reconnectTimer = window.setTimeout(connectStream, 1500)
    return
  }
  const url = new URL(endpoint('/brain/stream'))
  url.searchParams.set('sessionId', sessionId.value)
  url.searchParams.set('threadId', threadId.value)
  url.searchParams.set('token', token)
  eventSource = new EventSource(url)
  const events = [
    'chat.error',
    'chat.snapshot',
    'chat.assistant.start',
    'chat.assistant.placeholder',
    'chat.assistant.update',
    'chat.assistant.done',
    'chat.audio.ready',
    'chat.audio.error',
    'chat.tool.update',
  ]
  for (const type of events) {
    eventSource.addEventListener(type, (event) => {
      try {
        handleStreamUpdate(type, JSON.parse((event as MessageEvent<string>).data) as SseUpdate)
      } catch (error) {
        console.error(error)
      }
    })
  }
  eventSource.onerror = () => {
    eventSource?.close()
    eventSource = null
    reconnectTimer = window.setTimeout(connectStream, 5000)
  }
}

function handleStreamUpdate(type: string, update: SseUpdate): void {
  const messages = messagesElement.value
  if (messages === null) return
  if (update.threadId && update.threadId !== threadId.value) return

  if (type === 'chat.error') {
    finishResponse()
    const article = document.createElement('article')
    article.className = 'claire-message claire-message--received'
    const bubble = document.createElement('div')
    bubble.className = 'claire-message__bubble'
    bubble.textContent = update.message ?? 'Une erreur est survenue.'
    article.appendChild(bubble)
    messages.appendChild(article)
  } else if (type === 'chat.snapshot') {
    readyAudio.clear()
    pendingAudio.clear()
    failedAudio.clear()
    autoPlayedAudio.clear()
    messages.innerHTML = update.html ?? ''
    if (typeof update.restoredMessage === 'string') message.value = update.restoredMessage
    finishResponse()
    void nextTick(enhanceRenderedMessages)
  } else if (type === 'chat.assistant.placeholder') {
    const existing = findMessage(update.messageId)
    if (existing === null) {
      const loader = messages.querySelector('[data-role="claire-assistant-loader"]')
      if (loader instanceof HTMLElement) loader.outerHTML = update.html ?? ''
      else messages.insertAdjacentHTML('beforeend', update.html ?? '')
    }
    void nextTick(() => ensureAudioAction(
      update.messageId,
      audioAutoGenerate.value,
    ))
  } else if (type === 'chat.assistant.update') {
    const element = rootElement.value?.querySelector(`#claire-message-${CSS.escape(update.messageId ?? '')}`)
    if (element instanceof HTMLElement) element.innerHTML = update.html ?? ''
    ensureAudioAction(update.messageId, audioAutoGenerate.value)
  } else if (type === 'chat.tool.update') {
    const element = rootElement.value?.querySelector(`#claire-toolscall-${CSS.escape(update.messageId ?? '')}`)
    if (element instanceof HTMLElement) element.innerHTML = update.html ?? ''
  } else if (type === 'chat.assistant.done') {
    finishResponse()
    void nextTick(() => ensureAudioAction(
      update.messageId,
      audioAutoGenerate.value,
    ))
  } else if (type === 'chat.audio.ready') {
    receiveReadyAudio(update)
  } else if (type === 'chat.audio.error' && update.messageId) {
    pendingAudio.delete(update.messageId)
    failedAudio.add(update.messageId)
    ensureAudioAction(update.messageId)
    updateAudioActionStates()
  }
  void nextTick(enhanceRenderedMessages)
  scrollToBottom()
}

function findMessage(messageId?: string): Element | null {
  if (!messageId) return null
  return rootElement.value?.querySelector(`#claire-${CSS.escape(messageId)}, #${CSS.escape(messageId)}`) ?? null
}

function finishResponse(): void {
  messagesElement.value
    ?.querySelector('[data-role="claire-assistant-loader"]')
    ?.remove()
  busy.value = false
}

function enhanceRenderedMessages(): void {
  if (rootElement.value === null) return
  for (const link of rootElement.value.querySelectorAll<HTMLAnchorElement>('a.claire-generated-file[href]')) {
    link.href = client.protectedUrl(link.getAttribute('href') ?? '')
  }
  for (const image of rootElement.value.querySelectorAll<HTMLImageElement>('img.claire-generated-image')) {
    const src = image.dataset.protectedSrc ?? image.getAttribute('src') ?? ''
    image.src = client.protectedUrl(src)
  }
  for (const audio of rootElement.value.querySelectorAll<HTMLAudioElement>('audio.claire-generated-audio')) {
    const src = audio.dataset.protectedSrc ?? ''
    if (src !== '' && audio.src === '') audio.src = client.protectedUrl(src)
  }
  for (const code of rootElement.value.querySelectorAll<HTMLElement>('pre code:not(.hljs)')) {
    hljs.highlightElement(code)
  }
  if (!audioEnabled.value) {
    for (const action of rootElement.value.querySelectorAll('[data-audio-listen]')) action.remove()
    return
  }
  for (const article of rootElement.value.querySelectorAll<HTMLElement>(
    '.claire-message--received[id^="claire-"]',
  )) {
    ensureAudioAction(article.id.slice('claire-'.length))
  }
}

function ensureAudioAction(messageId?: string, autoPending = false): void {
  if (!audioEnabled.value || !messageId || rootElement.value === null) return
  const article = rootElement.value.querySelector<HTMLElement>(
    `#claire-${CSS.escape(messageId)}`,
  )
  if (article === null || !article.classList.contains('claire-message--received')) return
  if (article.querySelector('[data-audio-listen]') !== null) {
    updateAudioActionStates()
    return
  }
  const meta = article.querySelector<HTMLElement>('.claire-message__meta')
  if (meta === null) return
  const button = document.createElement('button')
  button.type = 'button'
  button.className = 'claire-message__audio-action'
  button.dataset.audioListen = 'true'
  button.dataset.audioMessageId = messageId
  button.addEventListener('click', (event) => {
    event.preventDefault()
    event.stopPropagation()
    void toggleSpeech(button)
  })
  meta.appendChild(button)
  if (autoPending
    && !readyAudio.has(messageId)
    && !failedAudio.has(messageId)) {
    pendingAudio.add(messageId)
  }
  updateAudioActionStates()
}

function updateAudioActionStates(): void {
  for (const action of rootElement.value?.querySelectorAll<HTMLButtonElement>('[data-audio-listen]') ?? []) {
    const messageId = action.dataset.audioMessageId ?? ''
    const active = messageId === playingMessageId.value
    const ready = readyAudio.has(messageId)
    const pending = pendingAudio.has(messageId)
    const failed = failedAudio.has(messageId)
    action.disabled = pending
    if (active) setAudioActionIcon(action, 'stop', 'Arrêter la lecture')
    else if (ready) setAudioActionIcon(action, 'play', 'Lire la réponse')
    else if (pending) setAudioActionIcon(action, 'pending', 'Génération audio en cours')
    else if (failed) setAudioActionIcon(action, 'retry', 'Réessayer la génération audio')
    else setAudioActionIcon(action, 'generate', 'Générer l’audio')
    action.classList.toggle('is-playing', active)
  }
}

function setAudioActionIcon(
  action: HTMLButtonElement,
  icon: 'generate' | 'pending' | 'play' | 'retry' | 'stop',
  label: string,
): void {
  const paths = {
    generate: '<path d="M5 10v4h3l4 4V6L8 10H5zm10-1a4 4 0 0 1 0 6m3-8a7 7 0 0 1 0 10"/>',
    pending: '<path d="M20 12a8 8 0 1 1-2.34-5.66M20 4v5h-5"/>',
    play: '<path class="claire-icon__fill" d="M8 5v14l11-7z"/>',
    retry: '<path d="M20 12a8 8 0 1 1-2.34-5.66M20 4v5h-5"/>',
    stop: '<path class="claire-icon__fill" d="M7 7h10v10H7z"/>',
  }
  action.title = label
  action.setAttribute('aria-label', label)
  action.classList.toggle('is-loading', icon === 'pending')
  action.innerHTML = `<svg class="claire-icon" viewBox="0 0 24 24" aria-hidden="true">${paths[icon]}</svg>`
}

function receiveReadyAudio(update: SseUpdate): void {
  if (!update.messageId || !update.audioData) return
  let audio: Blob | null = null
  try {
    const binary = atob(update.audioData)
    const bytes = Uint8Array.from(binary, character => character.charCodeAt(0))
    audio = new Blob(
      [bytes],
      { type: update.mimeType || 'audio/mpeg' },
    )
    readyAudio.set(update.messageId, audio)
    pendingAudio.delete(update.messageId)
    failedAudio.delete(update.messageId)
  } catch (error) {
    console.error(error)
    pendingAudio.delete(update.messageId)
    failedAudio.add(update.messageId)
  }
  ensureAudioAction(update.messageId)
  updateAudioActionStates()
  if (audio !== null && !autoPlayedAudio.has(update.messageId)) {
    autoPlayedAudio.add(update.messageId)
    void playSpeech(update.messageId, audio)
  }
}

function scrollToBottom(): void {
  void nextTick(() => {
    const body = chatBodyElement.value
    if (body !== null) body.scrollTop = body.scrollHeight
  })
}

function optimisticMessage(text: string): void {
  const messages = messagesElement.value
  if (messages === null) return
  const article = document.createElement('article')
  article.className = 'claire-message claire-message--sent'
  const bubble = document.createElement('div')
  bubble.className = 'claire-message__bubble'
  bubble.textContent = text
  const meta = document.createElement('span')
  meta.className = 'claire-message__meta'
  meta.textContent = `${new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} • Vous`
  article.append(bubble, meta)
  const loader = document.createElement('article')
  loader.className = 'claire-message'
  loader.dataset.role = 'claire-assistant-loader'
  loader.innerHTML = '<div class="claire-message__bubble"><span class="claire-typing-indicator" aria-hidden="true"><span class="claire-typing-indicator__dot"></span><span class="claire-typing-indicator__dot"></span><span class="claire-typing-indicator__dot"></span></span></div>'
  messages.append(article, loader)
  scrollToBottom()
}

async function submitMessage(): Promise<void> {
  const text = message.value.trim()
  if (text === '' || busy.value) return
  busy.value = true
  optimisticMessage(text)
  const data = new FormData()
  data.set('message', text)
  data.set('threadId', threadId.value)
  data.set('sessionId', sessionId.value)
  for (const file of localFiles.value) data.append('upload_files[]', file)
  for (const file of storedFiles.value) data.append('file_ids[]', file.id)
  try {
    await checkedRequest('/brain/messages', { method: 'POST', body: data })
    message.value = ''
    localFiles.value = []
    storedFiles.value = []
    if (chatFileInput.value !== null) chatFileInput.value.value = ''
  } catch (error) {
    console.error(error)
    finishResponse()
    notify('Le message n’a pas pu être envoyé.', 'error')
  }
}

async function toggleRecording(): Promise<void> {
  if (recording.value) {
    browserAudio.stopRecording()
    return
  }
  if (!browserAudio.supported()) {
    notify('L’enregistrement audio n’est pas pris en charge par ce navigateur.', 'error')
    return
  }

  try {
    recording.value = true
    await browserAudio.startRecording(async (audio, mediaType) => {
      recording.value = false
      transcribing.value = true
      try {
        const transcription = await browserAudio.transcribe(
          audio,
          mediaType,
          props.config.audioTranscriptionModel,
        )
        message.value = transcription
        await nextTick()
        messageInput.value?.focus()
        if (audioDictationMode.value === 'auto_send') await submitMessage()
      } catch (error) {
        console.error(error)
        notify('La transcription audio a échoué.', 'error')
      } finally {
        transcribing.value = false
      }
    })
  } catch (error) {
    recording.value = false
    console.error(error)
    notify('L’accès au microphone a été refusé ou a échoué.', 'error')
  }
}

async function toggleSpeech(action: HTMLElement): Promise<void> {
  const article = action.closest<HTMLElement>('.claire-message')
  const text = article?.querySelector<HTMLElement>('.claire-message__text')?.textContent?.trim() ?? ''
  const messageId = action.dataset.audioMessageId ?? ''
  const audio = readyAudio.get(messageId)
  if (text === '' || messageId === '') return

  if (playingMessageId.value === messageId) {
    browserAudio.stopPlayback()
    playingMessageId.value = null
    updateAudioActionStates()
    return
  }

  if (audio === undefined) {
    await requestSpeech(messageId, text)
    return
  }

  await playSpeech(messageId, audio)
}

async function playSpeech(messageId: string, audio: Blob): Promise<void> {
  try {
    playingMessageId.value = messageId
    updateAudioActionStates()
    await browserAudio.playReady(
      audio,
      (error) => {
        playingMessageId.value = null
        updateAudioActionStates()
        if (error !== undefined) {
          console.error(error)
          notify('Le navigateur n’a pas pu lire le fichier audio généré.', 'error')
        }
      },
    )
  } catch (error) {
    console.error(error)
    browserAudio.stopPlayback()
    playingMessageId.value = null
    updateAudioActionStates()
    notify('La synthèse vocale a échoué.', 'error')
  }
}

async function requestSpeech(messageId: string, text: string): Promise<void> {
  pendingAudio.add(messageId)
  failedAudio.delete(messageId)
  updateAudioActionStates()
  try {
    await checkedRequest('/brain/audio', {
      method: 'POST',
      body: new URLSearchParams({
        threadId: threadId.value,
        sessionId: sessionId.value,
        messageId,
        text,
      }),
    })
  } catch (error) {
    console.error(error)
    pendingAudio.delete(messageId)
    failedAudio.add(messageId)
    updateAudioActionStates()
    notify('La génération audio n’a pas pu démarrer.', 'error')
  }
}

function handleComposerKeydown(event: KeyboardEvent): void {
  if (event.key === 'Enter' && !event.shiftKey) {
    event.preventDefault()
    void submitMessage()
  }
}

function resizeComposer(event: Event): void {
  const input = event.target as HTMLTextAreaElement
  input.style.height = 'auto'
  input.style.height = `${Math.min(input.scrollHeight, 160)}px`
}

function selectLocalFiles(event: Event): void {
  const input = event.target as HTMLInputElement
  localFiles.value = Array.from(input.files ?? [])
}

function removeLocalFile(index: number): void {
  localFiles.value.splice(index, 1)
}

function removeStoredFile(id: string): void {
  storedFiles.value = storedFiles.value.filter((file) => file.id !== id)
}

async function createConversation(): Promise<void> {
  await withBusy(async () => {
    const data = new URLSearchParams({ sessionId: sessionId.value })
    const response = await checkedRequest('/history/new', { method: 'POST', body: data })
    const payload = await response.json() as { threadId: string; sessionId: string }
    threadId.value = payload.threadId
    sessionId.value = payload.sessionId
    messagesElement.value?.replaceChildren()
    connectStream()
    closeMenus()
    await refreshCounters()
  })
}

async function deleteLastExchange(): Promise<void> {
  await withBusy(async () => {
    const data = new URLSearchParams({ threadId: threadId.value, sessionId: sessionId.value })
    const response = await checkedRequest('/history/exchange/last', { method: 'DELETE', body: data })
    const payload = await response.json() as { html?: string; removedMessage?: string }
    if (messagesElement.value !== null && typeof payload.html === 'string') {
      messagesElement.value.innerHTML = payload.html
    }
    if (typeof payload.removedMessage === 'string') message.value = payload.removedMessage
    enhanceRenderedMessages()
    scrollToBottom()
  })
}

async function onHistoryClick(event: MouseEvent): Promise<void> {
  const target = event.target as Element
  const open = target.closest<HTMLElement>('[data-history-open]')
  if (open !== null) {
    const path = open.dataset.historyOpen ?? ''
    await withBusy(async () => {
      const separator = path.includes('?') ? '&' : '?'
      const response = await checkedRequest(`${path}${separator}sessionId=${encodeURIComponent(sessionId.value)}`)
      const payload = await response.json() as { threadId: string }
      threadId.value = payload.threadId
      connectStream()
      historyHtml.value = ''
      closeMenus()
    })
    return
  }
  const remove = target.closest<HTMLElement>('[data-history-delete]')
  if (remove !== null) {
    confirmAction('Confirmer la suppression', 'Supprimer cette conversation ? Cette action est irréversible.', 'Supprimer', async () => {
      await checkedRequest(remove.dataset.historyDelete ?? '', { method: 'DELETE' })
      await Promise.all([loadHistory(), refreshCounters()])
    })
  }
}

async function onFilesClick(event: MouseEvent): Promise<void> {
  const target = event.target as Element
  const add = target.closest<HTMLElement>('[data-add-file-id]')
  if (add !== null) {
    const id = add.dataset.addFileId ?? ''
    const name = add.dataset.addFileName ?? 'Fichier'
    if (!storedFiles.value.some((file) => file.id === id)) storedFiles.value.push({ id, name })
    notify('Fichier ajouté à la conversation.')
    return
  }
  const remove = target.closest<HTMLElement>('[data-file-delete]')
  if (remove !== null) {
    confirmAction('Confirmer la suppression', 'Supprimer ce fichier ? Cette action est irréversible.', 'Supprimer', async () => {
      await checkedRequest(remove.dataset.fileDelete ?? '', { method: 'DELETE' })
      await Promise.all([loadFiles(), refreshCounters()])
    })
  }
}

async function onFilesSubmit(event: SubmitEvent): Promise<void> {
  event.preventDefault()
  const form = event.target as HTMLFormElement
  await withBusy(async () => {
    await checkedRequest('/files/upload', { method: 'POST', body: new FormData(form) })
    form.reset()
    await Promise.all([loadFiles(), refreshCounters()])
  })
}

function onFilesChange(event: Event): void {
  const input = event.target
  if (!(input instanceof HTMLInputElement) || input.type !== 'file') return
  const form = input.closest('form')
  const filename = input.files?.[0]?.name ?? 'Aucun fichier'
  const name = form?.querySelector<HTMLElement>('.claire-file-upload__name')
  const submit = form?.querySelector<HTMLButtonElement>('button[type="submit"]')
  if (name !== null && name !== undefined) {
    name.textContent = filename
    name.title = filename
  }
  if (submit !== null && submit !== undefined) submit.disabled = !input.files?.length
}

function confirmAction(
  title: string,
  body: string,
  confirmLabel: string,
  action: () => Promise<void>,
): void {
  modal.value = { title, body, confirmLabel, variant: 'danger', action }
}

async function confirmModal(): Promise<void> {
  const action = modal.value?.action
  if (action === null || action === undefined) return
  busy.value = true
  try {
    await action()
    modal.value = null
  } catch (error) {
    console.error(error)
    notify('L’action a échoué.', 'error')
  } finally {
    busy.value = false
  }
}

async function onRagClick(event: MouseEvent): Promise<void> {
  const target = event.target as Element
  const toggle = target.closest<HTMLElement>('[data-rag-toggle]')
  if (toggle !== null) {
    await withBusy(async () => {
      await checkedRequest(toggle.dataset.ragToggle ?? '', { method: 'POST' })
      await Promise.all([loadRag(), refreshCounters()])
    })
    return
  }
  const remove = target.closest<HTMLElement>('[data-rag-delete]')
  if (remove !== null) {
    confirmAction('Confirmer la suppression', 'Supprimer ce document RAG ? Cette action est irréversible.', 'Supprimer', async () => {
      await checkedRequest(remove.dataset.ragDelete ?? '', { method: 'DELETE' })
      await Promise.all([loadRag(), refreshCounters()])
    })
    return
  }
  const segments = target.closest<HTMLElement>('[data-rag-segments]')
  if (segments !== null) {
    await openRagSegmentsModal(segments.dataset.ragSegments ?? '')
    return
  }
  const addText = target.closest<HTMLElement>('[data-rag-add-text]')
  if (addText !== null) {
    openRagTextModal()
    return
  }
  const addUrl = target.closest<HTMLElement>('[data-rag-add-url]')
  if (addUrl !== null) {
    openRagUrlModal()
  }
}

async function onRagSubmit(event: SubmitEvent): Promise<void> {
  event.preventDefault()
  const form = event.target as HTMLFormElement
  await withBusy(async () => {
    await checkedRequest(form.action, { method: 'POST', body: new FormData(form) })
    form.reset()
    const nameSpan = form.querySelector<HTMLElement>('.claire-file-upload__name')
    const submitBtn = form.querySelector<HTMLButtonElement>('button[type="submit"]')
    if (nameSpan !== null && nameSpan !== undefined) {
      nameSpan.textContent = 'Aucun document'
      nameSpan.title = ''
    }
    if (submitBtn !== null && submitBtn !== undefined) submitBtn.disabled = true
    await Promise.all([loadRag(), refreshCounters()])
  })
}

function onRagChange(event: Event): void {
  const input = event.target
  if (!(input instanceof HTMLInputElement) || input.type !== 'file') return
  const form = input.closest('form')
  const filename = input.files?.[0]?.name ?? 'Aucun document'
  const name = form?.querySelector<HTMLElement>('.claire-file-upload__name')
  const submit = form?.querySelector<HTMLButtonElement>('button[type="submit"]')
  if (name !== null && name !== undefined) {
    name.textContent = filename
    name.title = filename
  }
  if (submit !== null && submit !== undefined) submit.disabled = !input.files?.length
}

async function openRagSegmentsModal(url: string): Promise<void> {
  await withBusy(async () => {
    const body = await (await checkedRequest(url)).text()
    modal.value = {
      title: 'Segments du document',
      body,
      confirmLabel: 'Fermer',
      variant: 'default',
      action: async () => {},
    }
  })
}

function openRagTextModal(): void {
  modal.value = {
    title: 'Ajouter un document texte',
    body: `<form id="claire-rag-text-form"><label class="claire-modal__field"><span>Nom</span><input type="text" name="name" required maxlength="255"></label><label class="claire-modal__field"><span>Contenu</span><textarea name="content" rows="6" required></textarea></label></form>`,
    confirmLabel: 'Ajouter',
    variant: 'default',
    action: async () => {
      const form = rootElement.value?.querySelector<HTMLFormElement>('#claire-rag-text-form')
      if (form === null || form === undefined || !form.reportValidity()) throw new Error('Invalid form')
      await checkedRequest('/rag/text', { method: 'POST', body: new URLSearchParams(new FormData(form) as unknown as Record<string, string>) })
      await Promise.all([loadRag(), refreshCounters()])
      notify('Document ajouté au RAG.')
    },
  }
}

function openRagUrlModal(): void {
  modal.value = {
    title: 'Ajouter un document URL',
    body: `<form id="claire-rag-url-form"><label class="claire-modal__field"><span>Nom</span><input type="text" name="name" required maxlength="255"></label><label class="claire-modal__field"><span>URL</span><input type="url" name="url" required></label></form>`,
    confirmLabel: 'Ajouter',
    variant: 'default',
    action: async () => {
      const form = rootElement.value?.querySelector<HTMLFormElement>('#claire-rag-url-form')
      if (form === null || form === undefined || !form.reportValidity()) throw new Error('Invalid form')
      await checkedRequest('/rag/url', { method: 'POST', body: new URLSearchParams(new FormData(form) as unknown as Record<string, string>) })
      await Promise.all([loadRag(), refreshCounters()])
      notify('Document ajouté au RAG.')
    },
  }
}

async function openTelegram(): Promise<void> {
  await withBusy(async () => {
    const body = await (await checkedRequest('/config/telegram_form')).text()
    modal.value = {
      title: 'Configuration Telegram',
      body,
      confirmLabel: 'Enregistrer',
      variant: 'default',
      action: async () => {
        const form = rootElement.value?.querySelector<HTMLFormElement>('#claire-telegram-config-form')
        if (form === null || form === undefined) throw new Error('Telegram form not found')
        const response = await checkedRequest('/config/telegram', {
          method: 'POST',
          body: new URLSearchParams(new FormData(form) as unknown as Record<string, string>),
        })
        if (modal.value !== null) modal.value.body = await response.text()
        notify('Configuration Telegram enregistrée.')
      },
    }
  })
}

async function postSetting(path: string, values: Record<string, string>): Promise<void> {
  await withBusy(async () => {
    await checkedRequest(path, { method: 'POST', body: new URLSearchParams(values) })
  })
}

async function changeBrain(): Promise<void> {
  await postSetting('/config/brain_avatar', { avatar: currentBrain.value })
  const selectedBrain = props.config.brains.find(
    (brain) => brain.slug === currentBrain.value,
  )
  if (selectedBrain !== undefined) {
    brainInfo.value = {
      name: selectedBrain.name,
      description: selectedBrain.description,
      avatar: selectedBrain.avatar,
      css: selectedBrain.css,
      cssInline: selectedBrain.cssInline,
    }

    const styles = [selectedBrain.cssInline ?? '']
    if (selectedBrain.css) {
      const response = await client.request(`/css/${selectedBrain.css}`)
      if (response.ok) styles.unshift(await response.text())
    }
    dynamicCss.value = styles.filter(Boolean).join('\n')
  }
  notify('Assistant sélectionné.')
}

async function changeWorkflow(): Promise<void> {
  await postSetting('/config/comfyui_workflow', { workflow: currentWorkflow.value })
}

async function changeMemory(): Promise<void> {
  await postSetting('/config/long_term_memory', { enabled: String(longTermMemory.value) })
}

async function changeAudioEnabled(): Promise<void> {
  await postSetting('/config/audio', { enabled: String(audioEnabled.value) })
  if (!audioEnabled.value) {
    recording.value = false
    browserAudio.destroy()
  }
  enhanceRenderedMessages()
}

async function changeAudioAutoGenerate(): Promise<void> {
  await postSetting('/config/audio', {
    auto_generate: String(audioAutoGenerate.value),
  })
}

async function changeAudioDictationMode(): Promise<void> {
  await postSetting('/config/audio', { dictation_mode: audioDictationMode.value })
}

async function changeAudioVoice(): Promise<void> {
  browserAudio.stopPlayback()
  playingMessageId.value = null
  updateAudioActionStates()
  await postSetting('/config/audio', { voice: audioVoice.value })
}

function rebuildMemory(): void {
  if (!longTermMemory.value) {
    notify('Activez d’abord la mémoire longue durée.', 'error')
    return
  }
  confirmAction('Reconstruire la mémoire', 'Reconstruire la mémoire depuis tout l’historique ?', 'Reconstruire', async () => {
    await checkedRequest('/config/long_term_memory/rebuild', { method: 'POST' })
    notify('Mémoire reconstruite.')
  })
}

async function toggleLayout(): Promise<void> {
  layoutMode.value = layoutMode.value === 'full' ? 'compact' : 'full'
  document.body.classList.toggle('claire-compact', layoutMode.value === 'compact')
  await postSetting('/config/layout_mode', { mode: layoutMode.value })
}

function logout(): void {
  client.clear()
  sessionStorage.removeItem('claireStreamSessionId')
  if (props.config.mode === 'normal') {
    window.location.assign(endpoint('/logout'))
  } else {
    rootElement.value?.dispatchEvent(new CustomEvent('claire:logout', { bubbles: true, composed: true }))
  }
}

function onRootClick(event: MouseEvent): void {
  const target = event.target as Element
  const audioAction = target.closest<HTMLElement>('[data-audio-listen]')
  if (audioAction !== null) {
    event.preventDefault()
    void toggleSpeech(audioAction)
    return
  }
  const image = target.closest<HTMLImageElement>('.claire-generated-image')
  if (image !== null) {
    event.preventDefault()
    lightboxUrl.value = image.src
  }
}

function handleEscape(event: KeyboardEvent): void {
  if (event.key !== 'Escape') return
  if (lightboxUrl.value !== null) lightboxUrl.value = null
  else if (modal.value !== null) modal.value = null
  else closeMenus()
}

onMounted(() => {
  document.addEventListener('keydown', handleEscape)
  connectStream()
  void refreshCounters()
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', handleEscape)
  eventSource?.close()
  if (reconnectTimer !== null) window.clearTimeout(reconnectTimer)
  if (notificationTimer !== null) window.clearTimeout(notificationTimer)
  browserAudio.destroy()
  readyAudio.clear()
  pendingAudio.clear()
  failedAudio.clear()
  autoPlayedAudio.clear()
  client.destroy()
})
</script>

<template>
  <div
    id="claire-embed-container"
    ref="rootElement"
    class="claire-app"
    :data-mode="config.mode"
    @click="onRootClick"
  >
    <component :is="'style'" v-if="dynamicCss">{{ dynamicCss }}</component>

    <div v-if="config.mode === 'embed'" class="claire-embed-wrapper">
      <div class="claire-embed" :class="{ 'is-collapsed': collapsed }">
        <nav class="claire-embed-toolbar" aria-label="Menu embed" @click.self="collapsed = !collapsed">
          <button class="claire-embed-toolbar__left" type="button" @click="collapsed = !collapsed">
            <img class="claire-embed-toolbar__avatar" :src="brainInfo.avatar" alt="" width="32" height="32">
            <span class="claire-embed-toolbar__title">{{ brainName }}</span>
          </button>
          <div class="claire-embed-toolbar__right">
            <button class="claire-embed-toolbar__item" type="button" title="Nouvelle conversation" aria-label="Nouvelle conversation" @click="createConversation"><ClaireIcon name="plus" /></button>
                <button v-if="config.user" class="claire-embed-toolbar__item" type="button" title="Compte" aria-label="Compte" @click="toggleMenu('account')"><ClaireIcon name="user" /></button>
                <div class="claire-embed-toolbar__dropdown">
                  <button id="claire-rag-toggle" class="claire-embed-toolbar__item" type="button" title="Documents RAG" aria-label="Documents RAG" @click="toggleMenu('rag')"><ClaireIcon name="rag" /><span id="claire-rag-count-badge" class="claire-embed-toolbar__badge">{{ ragCount }}</span></button>
                  <div v-if="openMenu === 'rag'" id="claire-rag-list" class="claire-embed-toolbar__subpanel is-visible claire-rag-root" @click="onRagClick" @change="onRagChange" @submit="onRagSubmit" v-html="ragHtml"></div>
                </div>
                <div class="claire-embed-toolbar__dropdown">
              <button id="claire-files-toggle" class="claire-embed-toolbar__item" type="button" title="Fichiers" aria-label="Fichiers" @click="toggleMenu('files')"><ClaireIcon name="file" /><span id="claire-files-count-badge" class="claire-embed-toolbar__badge">{{ filesCount }}</span></button>
              <div v-if="openMenu === 'files'" id="claire-files-list" class="claire-embed-toolbar__subpanel is-visible claire-files-root" @click="onFilesClick" @change="onFilesChange" @submit="onFilesSubmit" v-html="filesHtml"></div>
            </div>
            <div class="claire-embed-toolbar__dropdown">
              <button id="claire-history-toggle" class="claire-embed-toolbar__item" type="button" title="Historique" aria-label="Historique" @click="toggleMenu('history')"><ClaireIcon name="history" /><span id="claire-history-count-badge" class="claire-embed-toolbar__badge">{{ historyCount }}</span></button>
              <div v-if="openMenu === 'history'" id="claire-history-list" class="claire-embed-toolbar__subpanel is-visible" @click="onHistoryClick" v-html="historyHtml"></div>
            </div>
            <div class="claire-embed-toolbar__dropdown">
              <button class="claire-embed-toolbar__item" type="button" title="Préférences" aria-label="Préférences" @click="toggleMenu('preferences')"><ClaireIcon name="settings" /></button>
              <div v-if="openMenu === 'preferences'" class="claire-embed-toolbar__subpanel claire-embed-toolbar__subpanel--prefs is-visible">
                <label class="claire-embed-toolbar__subpanel-section">Assistant
                  <select id="claire-brain-selector" v-model="currentBrain" @change="changeBrain"><option v-for="brain in config.brains" :key="brain.slug" :value="brain.slug">{{ brain.name }}</option></select>
                </label>
                <label v-if="config.comfyuiEnabled" class="claire-embed-toolbar__subpanel-section">Workflow
                  <select id="claire-comfyui-workflow-selector" v-model="currentWorkflow" @change="changeWorkflow"><option v-for="workflow in config.workflows" :key="workflow.slug" :value="workflow.slug">{{ workflow.label }}</option></select>
                </label>
                <label class="claire-embed-toolbar__subpanel-section">Mémoire longue durée <input id="claire-long-term-memory" v-model="longTermMemory" type="checkbox" role="switch" @change="changeMemory"></label>
                <button id="claire-rebuild-long-term-memory" class="claire-embed-toolbar__subpanel-section claire-memory-rebuild" type="button" @click="rebuildMemory">Reconstruire depuis l’historique</button>
                <label v-if="config.audioAvailable" class="claire-embed-toolbar__subpanel-section">Audio <input v-model="audioEnabled" type="checkbox" role="switch" @change="changeAudioEnabled"></label>
                <label v-if="config.audioAvailable && audioEnabled" class="claire-embed-toolbar__subpanel-section">Voix
                  <select v-model="audioVoice" @change="changeAudioVoice"><option v-for="voice in config.audioVoices" :key="voice.id" :value="voice.id">{{ voice.label }}</option></select>
                </label>
                <label v-if="config.audioAvailable && audioEnabled" class="claire-embed-toolbar__subpanel-section">Génération automatique <input v-model="audioAutoGenerate" type="checkbox" role="switch" @change="changeAudioAutoGenerate"></label>
                <label v-if="config.audioAvailable && audioEnabled" class="claire-embed-toolbar__subpanel-section">Après dictée
                  <select v-model="audioDictationMode" @change="changeAudioDictationMode"><option value="review">Relire avant envoi</option><option value="auto_send">Envoyer automatiquement</option></select>
                </label>
              </div>
            </div>
            <div v-if="openMenu === 'account' && config.user" class="claire-embed-toolbar__subpanel claire-embed-toolbar__subpanel--account is-visible">
              <div class="claire-embed-toolbar__subpanel-section">{{ config.user.displayName }}</div>
              <button class="claire-embed-toolbar__subpanel-section" type="button" @click="openTelegram">Configuration Telegram</button>
              <button class="claire-embed-toolbar__subpanel-section" type="button" @click="logout">Se déconnecter</button>
            </div>
          </div>
        </nav>
        <section class="claire-chat-panel"><div class="claire-chat-shell">
          <main ref="chatBodyElement" class="claire-chat-body"><div id="claire-chat-stream" :data-thread-id="threadId" :data-stream-session-id="sessionId" style="display: contents"><div id="claire-messages" ref="messagesElement" class="claire-messages"></div></div><button id="claire-scroll-down-btn" class="claire-scroll-down-button" type="button" aria-label="Descendre au dernier message" @click="scrollToBottom"><ClaireIcon name="arrow-down" /></button></main>
          <footer class="claire-chat-input"><form id="claire-brain-chat" class="claire-chat-input__form" :class="{ 'claire-chat-input__form--typing': message.trim() !== '' }" @submit.prevent="submitMessage">
            <label class="claire-chat-icon-btn claire-chat-icon-btn--upload claire-chat-input__toggleable" for="claire-chat-upload" aria-label="Joindre un fichier">
              <ClaireIcon name="paperclip" />
            </label>
            <input id="claire-thread-id-input" type="hidden" :value="threadId">
            <input id="claire-session-id-input" type="hidden" :value="sessionId">
            <input id="claire-chat-upload" ref="chatFileInput" class="claire-chat-input__file" type="file" multiple :accept="config.acceptedExt" @change="selectLocalFiles">
            <span id="claire-chat-attached-files-chat" class="claire-chat-attached"><span v-for="(file, index) in localFiles" :key="`${file.name}-${file.lastModified}`" class="claire-chat-chip">{{ file.name }}<button type="button" aria-label="Retirer le fichier" @click="removeLocalFile(index)"><ClaireIcon name="close" /></button></span><span v-for="file in storedFiles" :key="file.id" class="claire-chat-chip">{{ file.name }}<button type="button" aria-label="Retirer le fichier" @click="removeStoredFile(file.id)"><ClaireIcon name="close" /></button></span></span>
            <textarea ref="messageInput" v-model="message" class="claire-chat-input__field claire-chat-input__field--multiline" placeholder="Écrivez votre message..." rows="1" required :disabled="busy" @input="resizeComposer" @keydown="handleComposerKeydown"></textarea>
            <button v-if="config.audioAvailable && audioEnabled" class="claire-chat-icon-btn claire-chat-input__toggleable" :class="{ 'claire-is-recording': recording }" type="button" :aria-label="recording ? 'Arrêter l’enregistrement' : 'Dicter un message'" :disabled="busy || transcribing" @click="toggleRecording"><ClaireIcon name="microphone" /></button>
            <div class="claire-chat-input__actions"><button class="claire-chat-icon-btn claire-chat-input__toggleable" type="button" aria-label="Annuler le dernier échange" :disabled="busy" @click="deleteLastExchange"><ClaireIcon name="undo" /></button><button class="claire-chat-icon-btn" type="submit" aria-label="Envoyer" :disabled="busy || message.trim() === ''"><ClaireIcon name="send" /></button></div>
          </form></footer>
        </div></section>
      </div>
    </div>

    <template v-else>
      <section class="claire-chat-panel">
        <div class="claire-chat-shell">
          <header class="claire-chat-header">
            <div class="claire-chat-header__main">
              <img class="claire-chat-header__avatar" :src="brainInfo.avatar" alt="">
              <div class="claire-chat-header__info"><span class="claire-chat-header__title">{{ brainName }}</span><span class="claire-chat-header__subtitle">{{ brainInfo.description }}</span></div>
            </div>
            <button class="claire-options-toggle" :class="{ 'claire-is-active': optionsOpen }" type="button" aria-label="Ouvrir le menu" :aria-expanded="optionsOpen" @click="optionsOpen = !optionsOpen">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 7h16M4 12h16M4 17h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
              </svg>
            </button>
          </header>
          <main ref="chatBodyElement" class="claire-chat-body"><div id="claire-chat-stream" :data-thread-id="threadId" :data-stream-session-id="sessionId" style="display: contents"><div id="claire-messages" ref="messagesElement" class="claire-messages"></div></div><button id="claire-scroll-down-btn" class="claire-scroll-down-button" type="button" aria-label="Descendre au dernier message" @click="scrollToBottom"><ClaireIcon name="arrow-down" /></button></main>
          <footer class="claire-chat-input"><form id="claire-brain-chat" class="claire-chat-input__form" :class="{ 'claire-chat-input__form--typing': message.trim() !== '' }" @submit.prevent="submitMessage">
            <label class="claire-chat-icon-btn claire-chat-icon-btn--upload claire-chat-input__toggleable" for="claire-chat-upload" aria-label="Joindre un fichier">
              <ClaireIcon name="paperclip" />
            </label>
            <input id="claire-thread-id-input" type="hidden" :value="threadId">
            <input id="claire-session-id-input" type="hidden" :value="sessionId">
            <input id="claire-chat-upload" ref="chatFileInput" class="claire-chat-input__file" type="file" multiple :accept="config.acceptedExt" @change="selectLocalFiles">
            <span id="claire-chat-attached-files-chat" class="claire-chat-attached"><span v-for="(file, index) in localFiles" :key="`${file.name}-${file.lastModified}`" class="claire-chat-chip">{{ file.name }}<button type="button" aria-label="Retirer le fichier" @click="removeLocalFile(index)"><ClaireIcon name="close" /></button></span><span v-for="file in storedFiles" :key="file.id" class="claire-chat-chip">{{ file.name }}<button type="button" aria-label="Retirer le fichier" @click="removeStoredFile(file.id)"><ClaireIcon name="close" /></button></span></span>
            <textarea ref="messageInput" v-model="message" class="claire-chat-input__field claire-chat-input__field--multiline" placeholder="Écrivez votre message..." rows="1" required :disabled="busy" @input="resizeComposer" @keydown="handleComposerKeydown"></textarea>
            <button v-if="config.audioAvailable && audioEnabled" class="claire-chat-icon-btn claire-chat-input__toggleable" :class="{ 'claire-is-recording': recording }" type="button" :aria-label="recording ? 'Arrêter l’enregistrement' : 'Dicter un message'" :disabled="busy || transcribing" @click="toggleRecording"><ClaireIcon name="microphone" /></button>
            <div class="claire-chat-input__actions"><button class="claire-chat-icon-btn claire-chat-input__toggleable" type="button" aria-label="Annuler le dernier échange" :disabled="busy" @click="deleteLastExchange"><ClaireIcon name="undo" /></button><button class="claire-chat-icon-btn" type="submit" aria-label="Envoyer" :disabled="busy || message.trim() === ''"><ClaireIcon name="send" /></button></div>
          </form></footer>
        </div>
      </section>
      <div class="claire-options-backdrop" :class="{ 'claire-is-visible': optionsOpen }" @click="closeMenus"></div>
      <aside class="claire-options-panel" :class="{ 'claire-is-open': optionsOpen }">
        <button class="claire-options-close" type="button" aria-label="Fermer" @click="closeMenus"><ClaireIcon name="close" /></button>
        <section class="claire-options-panel__section"><span class="claire-options-panel__title">Conversations</span>
          <button class="claire-options-item" type="button" @click="createConversation"><span class="claire-options-item__label">Nouvelle conversation</span></button>
          <button id="claire-history-toggle" class="claire-options-item" type="button" @click="toggleMenu('history')"><span class="claire-options-item__label">Historique des conversations</span><span id="claire-history-count-badge" class="claire-options-item__badge">{{ historyCount }}</span></button>
          <div v-if="openMenu === 'history'" id="claire-history-list" class="claire-options-subpanel" @click="onHistoryClick" v-html="historyHtml"></div>
        </section>
        <section class="claire-options-panel__section"><span class="claire-options-panel__title">Données</span>
          <button id="claire-files-toggle" class="claire-options-item" type="button" @click="toggleMenu('files')"><span class="claire-options-item__label">Fichiers</span><span id="claire-files-count-badge" class="claire-options-item__badge">{{ filesCount }}</span></button>
          <div v-if="openMenu === 'files'" id="claire-files-list" class="claire-options-subpanel claire-files-root" @click="onFilesClick" @change="onFilesChange" @submit="onFilesSubmit" v-html="filesHtml"></div>
          <button id="claire-rag-toggle" class="claire-options-item" type="button" @click="toggleMenu('rag')"><span class="claire-options-item__label">Documents RAG</span><span id="claire-rag-count-badge" class="claire-options-item__badge">{{ ragCount }}</span></button>
          <div v-if="openMenu === 'rag'" id="claire-rag-list" class="claire-options-subpanel claire-rag-root" @click="onRagClick" @change="onRagChange" @submit="onRagSubmit" v-html="ragHtml"></div>
        </section>
        <section class="claire-options-panel__section"><span class="claire-options-panel__title">Préférences</span>
          <label class="claire-options-item"><span class="claire-options-item__label">Assistant</span><select id="claire-brain-selector" v-model="currentBrain" @change="changeBrain"><option v-for="brain in config.brains" :key="brain.slug" :value="brain.slug">{{ brain.name }}</option></select></label>
          <label v-if="config.comfyuiEnabled" class="claire-options-item"><span class="claire-options-item__label">Workflow ComfyUI</span><select id="claire-comfyui-workflow-selector" v-model="currentWorkflow" @change="changeWorkflow"><option v-for="workflow in config.workflows" :key="workflow.slug" :value="workflow.slug">{{ workflow.label }}</option></select></label>
          <label class="claire-options-item"><span class="claire-options-item__label">Mémoire longue durée</span><input id="claire-long-term-memory" v-model="longTermMemory" type="checkbox" role="switch" @change="changeMemory"></label>
          <button id="claire-rebuild-long-term-memory" class="claire-options-item" type="button" @click="rebuildMemory"><span class="claire-options-item__label">Reconstruire la mémoire depuis l’historique</span></button>
          <label v-if="config.audioAvailable" class="claire-options-item"><span class="claire-options-item__label">Audio</span><input v-model="audioEnabled" type="checkbox" role="switch" @change="changeAudioEnabled"></label>
          <label v-if="config.audioAvailable && audioEnabled" class="claire-options-item"><span class="claire-options-item__label">Voix</span><select v-model="audioVoice" @change="changeAudioVoice"><option v-for="voice in config.audioVoices" :key="voice.id" :value="voice.id">{{ voice.label }}</option></select></label>
          <label v-if="config.audioAvailable && audioEnabled" class="claire-options-item"><span class="claire-options-item__label">Génération audio automatique</span><input v-model="audioAutoGenerate" type="checkbox" role="switch" @change="changeAudioAutoGenerate"></label>
          <label v-if="config.audioAvailable && audioEnabled" class="claire-options-item"><span class="claire-options-item__label">Après dictée</span><select v-model="audioDictationMode" @change="changeAudioDictationMode"><option value="review">Relire avant envoi</option><option value="auto_send">Envoyer automatiquement</option></select></label>
          <button id="claire-toggle-layout-mode" class="claire-options-item" type="button" @click="toggleLayout"><span class="claire-options-item__label">Mode largeur</span><span class="claire-options-item__badge">{{ layoutLabel }}</span></button>
        </section>
        <section v-if="config.user" class="claire-options-panel__section"><span class="claire-options-panel__title">Compte</span>
          <div class="claire-options-item"><span class="claire-options-item__label">{{ config.user.displayName }}</span></div>
          <button class="claire-options-item" type="button" @click="openTelegram"><span class="claire-options-item__label">Configuration Telegram</span></button>
          <button class="claire-options-item" type="button" @click="logout"><span class="claire-options-item__label">Se déconnecter</span></button>
        </section>
      </aside>
    </template>

    <div v-if="modal" class="claire-modal-backdrop claire-is-visible" @click="modal = null"></div>
    <div v-if="modal" class="claire-modal claire-is-open" role="dialog" aria-modal="true" :data-variant="modal.variant">
      <div class="claire-modal__container">
        <div class="claire-modal__header"><h2 class="claire-modal__title">{{ modal.title }}</h2><button class="claire-modal__close" type="button" aria-label="Fermer" @click="modal = null"><ClaireIcon name="close" /></button></div>
        <div class="claire-modal__body" v-html="modal.body"></div>
        <div class="claire-modal__footer"><button class="claire-btn claire-btn--secondary" type="button" @click="modal = null">Annuler</button><button class="claire-btn claire-btn--primary" type="button" :disabled="busy" @click="confirmModal">{{ modal.confirmLabel }}</button></div>
      </div>
    </div>
    <div v-if="busy || transcribing" class="claire-global-action-indicator claire-is-requesting" role="status"><div class="claire-global-action-indicator__pill"><span class="claire-global-action-indicator__spinner"></span><span>{{ transcribing ? 'Transcription en cours...' : 'Action en cours...' }}</span></div></div>
    <div v-if="notification" class="claire-is-visible" id="claire-history-tooltip-banner" :data-variant="notification.variant">{{ notification.text }}</div>
    <div v-if="lightboxUrl" class="claire-image-lightbox claire-is-open" role="dialog" aria-modal="true" @click="lightboxUrl = null"><div class="claire-image-lightbox__backdrop"></div><div class="claire-image-lightbox__content"><img class="claire-image-lightbox__img" :src="lightboxUrl" alt="Image agrandie"></div></div>
  </div>
</template>
