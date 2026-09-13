// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ClaireApp from './ClaireApp.vue'
import OptionUpload from './OptionUpload.vue'
import MarkdownContent from './MarkdownContent'
import { SessionClient } from '../services/session-client'
import type { ClaireBootstrap, DisplayMode } from '../types'

const history = { threadId: 'thread/1', title: '<b>Conversation</b>', summary: 'Summary', updatedAt: '2026-09-12T12:00:00Z' }
const file = { fileId: 'file/1', filename: '<img src=x>.txt', mimeType: 'text/plain', sizeBytes: 1234, createdAt: '2026-09-12T12:00:00Z' }
const document = { documentId: 'doc/1', name: '<b>Document</b>', sourceType: 'text', isActive: true, chunkCount: 1, createdAt: '2026-09-12T12:00:00Z' }

function config(mode: DisplayMode): ClaireBootstrap {
  return {
    mode, baseUrl: 'https://claire.test/subpath', acceptedExt: '.txt', threadId: 'current', sessionId: 'session-1',
    brainInfo: { name: 'Claire', description: 'Assistant', avatar: '/avatar.png', theme: { preset: 'cyberpunk', tokens: {}, variants: {} } }, currentBrain: 'claire', brains: [],
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
    wrapper = mount(ClaireApp, { attachTo: globalThis.document.body, props: { config: config(mode) } })
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

  if (mode === 'normal') {
    it.each(['Escape', 'close', 'new conversation'])('returns focus to the menu toggle after %s', async exit => {
      const toggle = wrapper.get<HTMLButtonElement>('.claire-options-toggle')
      await toggle.trigger('click')
      await menu('history')
      const trigger = wrapper.get<HTMLButtonElement>('[aria-label="Supprimer cette conversation"]')
      trigger.element.focus()
      await trigger.trigger('click')
      await flushPromises()
      await wrapper.get('.claire-modal__close').trigger('keydown', { key: 'Escape' })
      await flushPromises()
      expect(globalThis.document.activeElement).toBe(trigger.element)

      if (exit === 'Escape') await trigger.trigger('keydown', { key: 'Escape' })
      else if (exit === 'close') await wrapper.get('.claire-options-close').trigger('click')
      else {
        const request = vi.spyOn(SessionClient.prototype, 'request')
        const fallback = request.getMockImplementation()!
        request.mockImplementation((path, init) => path === '/history/new'
          ? Promise.resolve(json({ threadId: 'new-thread', sessionId: 'new-session' })) : fallback(path, init))
        const create = wrapper.findAll('button').find(button => button.text() === 'Nouvelle conversation')!
        ;(create.element as HTMLButtonElement).focus()
        await create.trigger('click')
      }
      await flushPromises()
      expect(wrapper.get('.claire-options-panel').classes()).not.toContain('claire-is-open')
      expect(globalThis.document.activeElement).toBe(toggle.element)
    })

    it('does not move focus when closing a menu that does not own it', async () => {
      await wrapper.get('.claire-options-toggle').trigger('click')
      const input = wrapper.get<HTMLTextAreaElement>('[aria-label="Votre message"]')
      input.element.focus()
      await input.trigger('keydown', { key: 'Escape' })
      await flushPromises()
      expect(wrapper.get('.claire-options-panel').classes()).not.toContain('claire-is-open')
      expect(globalThis.document.activeElement).toBe(input.element)
    })
  }

  it.each(['cancel', 'action', 'Escape'])('contains confirmation focus and restores it after %s', async exit => {
    await menu('history')
    const trigger = wrapper.get<HTMLButtonElement>('[aria-label="Supprimer cette conversation"]')
    trigger.element.focus()
    await trigger.trigger('click')
    await flushPromises()
    const first = wrapper.get<HTMLButtonElement>('.claire-modal__close')
    const last = wrapper.get<HTMLButtonElement>('.claire-modal__footer .claire-btn--primary')
    expect(globalThis.document.activeElement).toBe(first.element)
    await first.trigger('keydown', { key: 'Tab', shiftKey: true })
    expect(globalThis.document.activeElement).toBe(last.element)
    await last.trigger('keydown', { key: 'Tab' })
    expect(globalThis.document.activeElement).toBe(first.element)
    if (exit === 'Escape') await first.trigger('keydown', { key: 'Escape' })
    else await wrapper.get(exit === 'action' ? '.claire-modal__footer .claire-btn--primary' : '.claire-modal__footer .claire-btn--secondary').trigger('click')
    await flushPromises()
    expect(wrapper.find('.claire-modal').exists()).toBe(false)
    expect(globalThis.document.activeElement).toBe(trigger.element)
    expect(requests.some(request => request.path.startsWith('/history/delete/'))).toBe(exit === 'action')
  })

  it.each(['text', 'url', 'segments', 'telegram'])('manages the generic %s modal lifecycle', async kind => {
    let trigger
    if (kind === 'telegram') {
      if (mode === 'embed') await wrapper.get('[aria-label="Compte"]').trigger('click')
      trigger = wrapper.findAll('button').find(button => button.text() === 'Configuration Telegram')!
    } else {
      await menu('rag')
      trigger = kind === 'segments' ? wrapper.get('[aria-label="Voir les segments"]')
        : wrapper.findAll('.claire-rag-action-btn')[kind === 'text' ? 0 : 1]!
    }
    ;(trigger.element as HTMLElement).focus()
    await trigger.trigger('click')
    await flushPromises()
    const close = wrapper.get('.claire-modal__close')
    expect(globalThis.document.activeElement).toBe(close.element)
    await close.trigger('keydown', { key: 'Tab', shiftKey: true })
    expect(globalThis.document.activeElement).toBe(wrapper.get('.claire-modal__footer .claire-btn--primary').element)
    await wrapper.get('.claire-modal__footer .claire-btn--primary').trigger('keydown', { key: 'Tab' })
    expect(globalThis.document.activeElement).toBe(close.element)
    await close.trigger('keydown', { key: 'Escape' })
    await flushPromises()
    expect(globalThis.document.activeElement).toBe(trigger.element)
  })

  it.each(['generated', 'markdown'])('opens %s images with Enter/Space and restores lightbox focus', async kind => {
    const id = '@@GENERATED@@picture@@'
    const content = mount(MarkdownContent, { attachTo: wrapper.element, props: {
      text: `![Photo](${kind === 'generated' ? id : '/files/serve/picture'})`,
      files: [{ id, name: 'picture.png', type: 'image', url: '/files/serve/picture' }],
    } })
    try {
      const image = content.get<HTMLImageElement>('img')
      expect(image.attributes()).toMatchObject({ role: 'button', tabindex: '0', 'aria-label': 'Agrandir l’image : Photo' })
      await image.trigger('keydown', { key: 'Enter' })
      expect(wrapper.find('.claire-image-lightbox').exists()).toBe(false)
      // Simulate the already-authorized resource assigned by enhanceRenderedMessages.
      image.element.dataset.authorizedSrc = image.element.dataset.protectedSrc
      image.element.src = 'https://claire.test/files/serve/picture?token=validated'
      for (const key of ['Enter', ' ']) {
        image.element.focus()
        await image.trigger('keydown', { key })
        await flushPromises()
        const dialog = wrapper.get('.claire-image-lightbox')
        const close = dialog.get('.claire-image-lightbox__close')
        expect(dialog.attributes('aria-label')).toBe('Image agrandie')
        expect(close.attributes('aria-label')).toBe('Fermer l’image agrandie')
        expect(globalThis.document.activeElement).toBe(close.element)
        for (const shiftKey of [false, true]) {
          await close.trigger('keydown', { key: 'Tab', shiftKey })
          expect(globalThis.document.activeElement).toBe(close.element)
        }
        await close.trigger(key === 'Enter' ? 'keydown' : 'click', key === 'Enter' ? { key: 'Escape' } : {})
        await flushPromises()
        expect(wrapper.find('.claire-image-lightbox').exists()).toBe(false)
        expect(globalThis.document.activeElement).toBe(image.element)
      }
    } finally { content.unmount() }
  })

  it('restores focus to a stable control when deletion removes the opener', async () => {
    await menu('files')
    const trigger = wrapper.get<HTMLButtonElement>('[aria-label="Supprimer ce fichier"]')
    trigger.element.focus()
    await trigger.trigger('click')
    await flushPromises()
    await wrapper.get('.claire-modal__footer .claire-btn--primary').trigger('click')
    await flushPromises()
    expect(globalThis.document.activeElement).toBe(wrapper.get(mode === 'embed'
      ? '.claire-embed-toolbar__left' : '[aria-label="Votre message"]').element)
  })

  it('keeps dialog keyboard handling inside its own Shadow DOM instance', async () => {
    const host = globalThis.document.createElement('div')
    globalThis.document.body.append(host)
    const shadow = host.attachShadow({ mode: 'open' })
    shadow.append(wrapper.element)
    try {
      await menu('history')
      const trigger = wrapper.get<HTMLButtonElement>('[aria-label="Supprimer cette conversation"]')
      trigger.element.focus()
      await trigger.trigger('click')
      await flushPromises()
      const close = wrapper.get('.claire-modal__close')
      expect(shadow.activeElement).toBe(close.element)
      globalThis.document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
      await flushPromises()
      expect(wrapper.find('.claire-modal').exists()).toBe(true)
      await close.trigger('keydown', { key: 'Tab', shiftKey: true })
      const last = wrapper.get('.claire-modal__footer .claire-btn--primary')
      expect(shadow.activeElement).toBe(last.element)
      await last.trigger('keydown', { key: 'Tab' })
      expect(shadow.activeElement).toBe(close.element)
      await close.trigger('keydown', { key: 'Escape' })
      await flushPromises()
      expect(shadow.activeElement).toBe(trigger.element)
    } finally {
      globalThis.document.body.append(wrapper.element)
      host.remove()
    }
  })

  it('updates the current assistant and the embed launcher accessible state', async () => {
    const data = config(mode)
    data.brains = ['Claire', 'Einstein'].map(name => ({ ...data.brainInfo, name, slug: name.toLowerCase() }))
    await wrapper.setProps({ config: data })
    if (mode === 'embed') {
      const launcher = wrapper.get('.claire-embed-toolbar__left')
      expect(launcher.attributes()).toMatchObject({ 'aria-label': 'Ouvrir la conversation avec Claire', 'aria-expanded': 'false' })
      await launcher.trigger('click')
      expect(launcher.attributes()).toMatchObject({ 'aria-label': 'Réduire la conversation avec Claire', 'aria-expanded': 'true' })
      await wrapper.get('[aria-label="Préférences"]').trigger('click')
    }
    const request = vi.spyOn(SessionClient.prototype, 'request')
    const fallback = request.getMockImplementation()!
    request.mockImplementation((path, init) => path === '/config/brain_avatar'
      ? Promise.resolve(new Response('')) : fallback(path, init))
    await wrapper.get('#claire-brain-selector').setValue('einstein')
    await flushPromises()
    if (mode === 'embed') {
      const launcher = wrapper.get('.claire-embed-toolbar__left')
      expect(launcher.attributes('aria-label')).toBe('Réduire la conversation avec Einstein')
      await launcher.trigger('click')
      expect(launcher.attributes()).toMatchObject({ 'aria-label': 'Ouvrir la conversation avec Einstein', 'aria-expanded': 'false' })
    } else {
      expect((wrapper.get('#claire-brain-selector').element as HTMLSelectElement).value).toBe('einstein')
    }
  })

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
