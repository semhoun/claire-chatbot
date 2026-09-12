// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ClaireApp from './ClaireApp.vue'
import OptionUpload from './OptionUpload.vue'
import { SessionClient } from '../services/session-client'
import type { ClaireBootstrap, DisplayMode } from '../types'

const history = { threadId: 'thread/1', title: '<b>Conversation</b>', summary: 'Summary', updatedAt: '2026-09-12T12:00:00Z' }
const file = { fileId: 'file/1', filename: '<img src=x>.txt', mimeType: 'text/plain', sizeBytes: 1234, createdAt: '2026-09-12T12:00:00Z' }
const document = { documentId: 'doc/1', name: '<b>Document</b>', sourceType: 'text', isActive: true, chunkCount: 1, createdAt: '2026-09-12T12:00:00Z' }

function config(mode: DisplayMode): ClaireBootstrap {
  return {
    mode, baseUrl: 'https://claire.test/subpath', acceptedExt: '.txt', threadId: 'current', sessionId: 'session-1',
    brainInfo: { name: 'Claire', description: 'Assistant', avatar: '/avatar.png' }, currentBrain: 'claire', brains: [],
    comfyuiEnabled: false, workflows: [], currentWorkflow: '', longTermMemoryEnabled: false, layoutMode: 'full',
    audioAvailable: false, audioEnabled: false, audioAutoGenerate: false, audioDictationMode: 'review',
    audioVoice: '', audioVoices: [], audioTranscriptionModel: '', audioSpeechModel: '', audioMaxRecordingSeconds: 60,
    user: { id: 'user-1', displayName: 'User' }, refreshBeforeExpire: 120, refreshMinInterval: 30,
  }
}

function json(data: unknown, status = 200): Response {
  return new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } })
}

describe.each<DisplayMode>(['normal', 'embed'])('Vue option flows (%s)', mode => {
  let wrapper: ReturnType<typeof mount<typeof ClaireApp>>
  let telegramStatus: number
  let telegramId: string | null
  let requests: Array<{ path: string; init?: RequestInit }>

  beforeEach(async () => {
    requests = []
    telegramStatus = 200
    telegramId = '123'
    vi.spyOn(SessionClient.prototype, 'initialize').mockImplementation(() => {})
    vi.spyOn(SessionClient.prototype, 'resourceToken').mockImplementation(() => new Promise(() => {}))
    vi.spyOn(SessionClient.prototype, 'request').mockImplementation(async (path, init) => {
      requests.push({ path, init })
      if (path.endsWith('/count')) return new Response('1')
      if (path === '/history/list') return json({ histories: [history] })
      if (path.startsWith('/history/open/')) return json({ threadId: history.threadId })
      if (path.startsWith('/history/delete/')) return new Response('')
      if (path === '/files/list') return json({ files: [file], acceptedExt: '.txt' })
      if (path.startsWith('/files/delete/')) return json({ files: [], acceptedExt: '.txt' })
      if (path === '/rag/list') return json({ documents: [document], acceptedExt: '.txt' })
      if (path.startsWith('/rag/segments/')) return json({ document, segments: ['<script>alert(1)</script>'] })
      if (path.startsWith('/rag/toggle/')) return json({ documents: [{ ...document, isActive: false }], acceptedExt: '.txt' })
      if (path.startsWith('/rag/delete/')) return json({ documents: [], acceptedExt: '.txt' })
      if (path === '/rag/text' || path === '/rag/url' || path === '/rag/upload') return json({ documents: [document], acceptedExt: '.txt' })
      if (path === '/files/upload') return json({ files: [file], acceptedExt: '.txt' })
      if (path === '/config/telegram_form') return json({ telegramId, success: null, error: null })
      if (path === '/config/telegram') {
        if (telegramStatus === 200) telegramId = (init?.body as URLSearchParams).get('telegram_id') || null
        return json({ telegramId, success: telegramStatus === 200 ? 'Enregistré' : null, error: telegramStatus === 200 ? null : `Erreur ${telegramStatus}` }, telegramStatus)
      }
      throw new Error(`Unexpected request: ${path}`)
    })
    wrapper = mount(ClaireApp, { props: { config: config(mode) } })
    await flushPromises()
  })

  afterEach(() => {
    wrapper.unmount()
    vi.restoreAllMocks()
  })

  async function menu(name: string): Promise<void> {
    await wrapper.get(`#claire-${name}-toggle`).trigger('click')
    await flushPromises()
  }

  async function telegram(): Promise<void> {
    if (mode === 'embed') await wrapper.get('[aria-label="Compte"]').trigger('click')
    const button = wrapper.findAll('button').find(button => button.text() === 'Configuration Telegram')!
    await button.trigger('click')
    await flushPromises()
  }

  it('renders escaped history metadata and opens the selected thread with the tab session', async () => {
    await menu('history')
    expect(wrapper.get('.claire-history-item').text()).toContain(history.title)
    expect(wrapper.find('.claire-history-item b').exists()).toBe(false)
    await wrapper.get('[aria-label="Afficher la conversation"]').trigger('click')
    await flushPromises()
    expect(requests.some(request => request.path === '/history/open/thread%2F1?sessionId=session-1')).toBe(true)
    expect(wrapper.get('#claire-thread-id-input').attributes('value')).toBe(history.threadId)
  })

  it('deduplicates attachments and removes deleted files from list, badge and composer', async () => {
    await menu('files')
    expect(wrapper.find('.claire-file-item img').exists()).toBe(false)
    await wrapper.get('[aria-label="Ajouter ce fichier à la conversation"]').trigger('click')
    await wrapper.get('[aria-label="Ajouter ce fichier à la conversation"]').trigger('click')
    expect(wrapper.findAll('.claire-chat-chip')).toHaveLength(1)
    await wrapper.get('[aria-label="Supprimer ce fichier"]').trigger('click')
    await wrapper.get('.claire-modal__footer .claire-btn--primary').trigger('click')
    await flushPromises()
    expect(requests.some(request => request.path === '/files/delete/file%2F1' && request.init?.method === 'DELETE')).toBe(true)
    expect(wrapper.findAll('.claire-chat-chip')).toHaveLength(0)
    expect(wrapper.get('#claire-files-count-badge').text()).toBe('0')
    expect(wrapper.find('.claire-modal').exists()).toBe(false)
  })

  it('refreshes the file badge after deleting a history with cascaded files', async () => {
    await menu('history')
    expect(wrapper.get('#claire-files-count-badge').text()).toBe('1')
    vi.spyOn(SessionClient.prototype, 'request').mockImplementation(async (path, init) => {
      requests.push({ path, init })
      if (path === '/history/list') return json({ histories: [] })
      if (path === '/history/delete/thread%2F1') return new Response('')
      if (path.endsWith('/count')) return new Response('0')
      throw new Error(`Unexpected request: ${path}`)
    })
    await wrapper.get('[aria-label="Supprimer cette conversation"]').trigger('click')
    await wrapper.get('.claire-modal__footer .claire-btn--primary').trigger('click')
    await flushPromises()
    expect(wrapper.find('.claire-history-item').exists()).toBe(false)
    expect(wrapper.get('#claire-history-count-badge').text()).toBe('0')
    expect(wrapper.get('#claire-files-count-badge').text()).toBe('0')
    expect(requests.filter(request => request.path === '/files/count')).toHaveLength(2)
  })

  it('renders segments as text, toggles RAG state and deletes documents', async () => {
    await menu('rag')
    await wrapper.get('[aria-label="Voir les segments"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('.claire-rag-segments__item-content').text()).toBe('<script>alert(1)</script>')
    expect(wrapper.find('.claire-rag-segments script').exists()).toBe(false)
    await wrapper.get('.claire-modal__close').trigger('click')
    await wrapper.get('[aria-label="Désactiver ce document"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('.claire-rag-item').classes()).not.toContain('is-active')
    await wrapper.get('[aria-label="Supprimer ce document"]').trigger('click')
    await wrapper.get('.claire-modal__footer .claire-btn--primary').trigger('click')
    await flushPromises()
    expect(wrapper.get('#claire-rag-count-badge').text()).toBe('0')
  })

  it.each(['text', 'url'])('submits a Vue RAG %s form', async kind => {
    await menu('rag')
    await wrapper.findAll('.claire-rag-action-btn')[kind === 'text' ? 0 : 1]!.trigger('click')
    await wrapper.get('.claire-modal__field input[type="text"]').setValue('My document')
    await wrapper.get(kind === 'text' ? '.claire-modal textarea' : '.claire-modal input[type="url"]').setValue(kind === 'text' ? 'Content' : 'https://example.org')
    await wrapper.get('.claire-modal form').trigger('submit')
    await flushPromises()
    const request = requests.find(request => request.path === `/rag/${kind}`)!
    expect((request.init?.body as URLSearchParams).get('name')).toBe('My document')
    expect(wrapper.find('.claire-modal').exists()).toBe(false)
  })

  it.each([
    ['text', 'closed'], ['url', 'closed'],
    ['text', 'replaced'], ['url', 'replaced'],
    ['text', 'navigated'], ['url', 'navigated'],
  ])('handles a late RAG %s success when the modal was %s', async (kind, state) => {
    await menu('rag')
    await wrapper.findAll('.claire-rag-action-btn')[kind === 'text' ? 0 : 1]!.trigger('click')
    await wrapper.get('.claire-modal__field input[type="text"]').setValue('New document')
    await wrapper.get(kind === 'text' ? '.claire-modal textarea' : '.claire-modal input[type="url"]').setValue(kind === 'text' ? 'Content' : 'https://example.org')
    let complete!: (response: Response) => void
    const request = vi.spyOn(SessionClient.prototype, 'request')
    const fallback = request.getMockImplementation()!
    request.mockImplementation((path, init) => {
      if (path === `/rag/${kind}`) return new Promise(resolve => { complete = resolve })
      if (path === '/history/new') return Promise.resolve(json({ threadId: 'new-thread', sessionId: 'new-session' }))
      return fallback(path, init)
    })
    await wrapper.get('.claire-modal form').trigger('submit')
    await wrapper.get('.claire-modal__close').trigger('click')
    if (state === 'replaced') {
      await wrapper.findAll('.claire-rag-action-btn')[0]!.trigger('click')
    } else if (state === 'navigated') {
      const newConversation = wrapper.findAll('button').find(button =>
        button.attributes('aria-label') === 'Nouvelle conversation' || button.text() === 'Nouvelle conversation')!
      await newConversation.trigger('click')
      await flushPromises()
      await menu('rag')
    }
    complete(json({ documents: [document, { ...document, documentId: 'new-doc', name: 'New document' }], acceptedExt: '.txt' }))
    await flushPromises()
    expect(wrapper.findAll('.claire-rag-item')).toHaveLength(state === 'navigated' ? 1 : 2)
    expect(wrapper.get('#claire-rag-count-badge').text()).toBe(state === 'navigated' ? '1' : '2')
    expect(wrapper.find('.claire-modal').exists()).toBe(state === 'replaced')
    if (state === 'replaced') {
      expect((wrapper.get('.claire-modal__field input').element as HTMLInputElement).value).toBe('')
    }
    expect(wrapper.find('#claire-history-tooltip-banner').exists()).toBe(false)
  })

  it.each([409, 422])('preserves Telegram input and shows JSON %s errors, then saves and unlinks', async status => {
    await telegram()
    expect(wrapper.get('.claire-telegram-status').text()).toContain('Compte associé')
    telegramStatus = status
    await wrapper.get('#claire-telegram-id').setValue('456')
    await wrapper.get('#claire-telegram-config-form').trigger('submit')
    await flushPromises()
    expect(wrapper.get('.claire-telegram-alert--error').text()).toContain(`Erreur ${status}`)
    expect((wrapper.get('#claire-telegram-id').element as HTMLInputElement).value).toBe('456')
    expect(wrapper.get('.claire-telegram-status__meta-value').text()).toBe('123')
    telegramStatus = 200
    await wrapper.get('.claire-modal__footer .claire-btn--primary').trigger('click')
    await flushPromises()
    expect(wrapper.get('.claire-telegram-alert--success').text()).toContain('Enregistré')
    expect(wrapper.get('.claire-telegram-status__meta-value').text()).toBe('456')
    await wrapper.get('#claire-telegram-id').setValue('')
    await wrapper.get('#claire-telegram-config-form').trigger('submit')
    await flushPromises()
    expect(wrapper.get('.claire-telegram-status--unlinked').text()).toContain('Non associé')
  })

  it('does not reopen a closed Telegram modal after a late save response', async () => {
    await telegram()
    let complete!: (response: Response) => void
    vi.spyOn(SessionClient.prototype, 'request').mockImplementation(() => new Promise(resolve => { complete = resolve }))
    await wrapper.get('#claire-telegram-config-form').trigger('submit')
    await wrapper.get('.claire-modal__close').trigger('click')
    complete(json({ telegramId: '123', success: 'Saved', error: null }))
    await flushPromises()
    expect(wrapper.find('.claire-modal').exists()).toBe(false)
    expect(wrapper.find('#claire-history-tooltip-banner').exists()).toBe(false)
  })

  it('keeps the Telegram form and input on unexpected HTTP failure without a success message', async () => {
    await telegram()
    vi.spyOn(console, 'error').mockImplementation(() => {})
    telegramStatus = 500
    await wrapper.get('#claire-telegram-id').setValue('456')
    await wrapper.get('#claire-telegram-config-form').trigger('submit')
    await flushPromises()
    expect((wrapper.get('#claire-telegram-id').element as HTMLInputElement).value).toBe('456')
    expect(wrapper.find('.claire-telegram-alert--success').exists()).toBe(false)
    expect(wrapper.get('#claire-history-tooltip-banner').attributes('data-variant')).toBe('error')
    expect(wrapper.get('.claire-modal__footer .claire-btn--primary').attributes('disabled')).toBeUndefined()
  })

  it.each(['files', 'rag'])('uploads %s via SessionClient and updates its count', async kind => {
    await menu(kind)
    const input = wrapper.get('.claire-file-upload__input')
    const selected = new File(['content'], 'upload.txt', { type: 'text/plain' })
    Object.defineProperty(input.element, 'files', { value: [selected], configurable: true })
    await input.trigger('change')
    await wrapper.get('.claire-file-upload').trigger('submit')
    await flushPromises()
    const request = requests.find(request => request.path === `/${kind}/upload`)!
    expect((request.init?.body as FormData).get('file')).toBe(selected)
    expect(wrapper.get(`#claire-${kind}-count-badge`).text()).toBe('1')
    expect(wrapper.get('.claire-file-upload__name').text()).toContain('Aucun')
  })
})

describe('upload state', () => {
  it('shows pending progress, prevents duplicates and keeps selection on failure', async () => {
    let complete!: (success: boolean) => void
    const upload = vi.fn(() => new Promise<boolean>(resolve => { complete = resolve }))
    const wrapper = mount(OptionUpload, { props: { acceptedExt: '.txt', upload } })
    const input = wrapper.get('input')
    Object.defineProperty(input.element, 'files', { value: [new File(['data'], 'test.txt')] })
    await input.trigger('change')
    await wrapper.get('form').trigger('submit')
    await wrapper.get('form').trigger('submit')
    expect(upload).toHaveBeenCalledTimes(1)
    expect(wrapper.find('progress').exists()).toBe(true)
    complete(false)
    await flushPromises()
    expect(wrapper.find('progress').exists()).toBe(false)
    expect(wrapper.get('.claire-file-upload__name').text()).toBe('test.txt')
    expect(wrapper.get('button').attributes('disabled')).toBeUndefined()
    wrapper.unmount()
  })
})
