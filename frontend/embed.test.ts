// @vitest-environment jsdom
import { readFileSync } from 'node:fs'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { BrowserAudio } from './services/browser-audio'
import { SessionClient } from './services/session-client'
import { flushPromises, mount } from '@vue/test-utils'
import ClaireApp from './components/ClaireApp.vue'
import type { ChatMessage, ClaireBootstrap, GeneratedFile, Theme } from './types'

function entry(id = 'a', message = 'Hello', files: GeneratedFile[] = []): ChatMessage {
  return { id, message: [message, ...files.map(file => file.id)].join('\n\n'), files, sent: false, time: '2026-09-12T12:00:00Z', toolsCall: [] }
}

function file(id = 'a', type = 'pdf', name = 'result.txt'): GeneratedFile {
  return { id: `@@GENERATED@@${id}@@`, type, name, url: `/files/serve/${id}` }
}

class FakeEventSource {
  public static instances: FakeEventSource[] = []
  public onerror: (() => void) | null = null
  public onopen: (() => void) | null = null
  public closed = false
  private readonly listeners = new Map<string, Array<(event: MessageEvent<string>) => void>>()

  public constructor(public readonly url: string | URL) {
    FakeEventSource.instances.push(this)
  }

  public addEventListener(type: string, listener: EventListener): void {
    const listeners = this.listeners.get(type) ?? []
    listeners.push(listener as (event: MessageEvent<string>) => void)
    this.listeners.set(type, listeners)
  }

  public emit(type: string, payload: unknown): void {
    const event = new MessageEvent(type, { data: JSON.stringify(payload) })
    for (const listener of this.listeners.get(type) ?? []) listener(event)
  }

  public close(): void {
    this.closed = true
  }
}

function jwt(audience: string): string {
  const payload = btoa(JSON.stringify({ aud: audience, exp: Math.floor(Date.now() / 1000) + 3600 }))
    .replace(/=/g, '')
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
  return `header.${payload}.signature`
}

function capability(): string {
  return JSON.stringify({ token: 'scoped-stream', expiresAt: Date.now() / 1000 + 60 })
}

function bootstrap(): ClaireBootstrap {
  return {
    mode: 'embed',
    baseUrl: '',
    acceptedExt: '.txt',
    threadId: 'thread-1',
    sessionId: 'session-1',
    brainInfo: { name: 'Claire', description: 'Assistant', avatar: '/avatar.png', theme: { preset: 'cyberpunk', tokens: {}, variants: {} } },
    currentBrain: 'claire',
    brains: [{
      slug: 'claire',
      name: 'Claire',
      description: 'Assistant',
      avatar: '/avatar.png',
      theme: { preset: 'cyberpunk', tokens: {}, variants: {} },
    }],
    comfyuiEnabled: false,
    workflows: [],
    currentWorkflow: '',
    longTermMemoryEnabled: false,
    layoutMode: 'full',
    audioAvailable: false,
    audioEnabled: false,
    audioAutoGenerate: false,
    audioDictationMode: 'review',
    audioVoice: '',
    audioVoices: [],
    audioTranscriptionModel: 'voxtral-mini-latest',
    audioSpeechModel: 'voxtral-mini-tts-2603',
    audioMaxRecordingSeconds: 300,
    user: null,
    refreshBeforeExpire: 120,
    refreshMinInterval: 30,
  }
}

describe('embed public API', () => {
  describe.each(['normal', 'embed'] as const)('submission rollback in %s', mode => {
    it('submits with secure random bytes when randomUUID is unavailable', async () => {
      const random = vi.fn(crypto.getRandomValues.bind(crypto))
      vi.stubGlobal('crypto', { getRandomValues: random })
      const ids: string[] = []
      vi.stubGlobal('fetch', vi.fn(async (input: string | URL, init?: RequestInit) => {
        const path = new URL(input).pathname
        if (path === '/auth/resource-token') return new Response(capability())
        if (path === '/brain/messages') {
          const submissionId = String((init!.body as FormData).get('submissionId'))
          ids.push(submissionId)
          return Response.json({ submissionId, messageId: `g-${ids.length}` }, { status: 202 })
        }
        return new Response('0')
      }))
      const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), mode, baseUrl: 'https://claire.test' } } })
      try {
        await flushPromises()
        for (let index = 0; index < 2; index++) {
          await wrapper.get('textarea').setValue('Question')
          await wrapper.get('form#claire-brain-chat').trigger('submit')
          await flushPromises()
          expect(ids[index]).toMatch(/^submission-[a-f0-9]{32}$/)
          FakeEventSource.instances.at(-1)!.emit('chat.snapshot', {
            submissionId: ids[index], generationMessageId: `g-${index + 1}`, turnStatus: 'succeeded',
            generationStatus: 'done', responding: false, messages: [],
          })
          await flushPromises()
          expect(wrapper.get<HTMLTextAreaElement>('textarea').element.disabled).toBe(false)
        }
        expect(random).toHaveBeenCalledTimes(2)
        expect(ids[0]).not.toBe(ids[1])
      } finally { wrapper.unmount() }
    })

    it('revives only a correlated running generation after a delayed pre-admission snapshot', async () => {
      let submissionId = ''
      vi.stubGlobal('fetch', vi.fn(async (input: string | URL, init?: RequestInit) => {
        const path = new URL(input).pathname
        if (path === '/auth/resource-token') return new Response(capability())
        if (path === '/brain/messages') {
          submissionId = String((init!.body as FormData).get('submissionId'))
          return Response.json({ submissionId, messageId: 'current' }, { status: 202 })
        }
        if (path.startsWith('/brain/turn/')) return new Response('', { status: 404 })
        return new Response('0')
      }))
      const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), mode, baseUrl: 'https://claire.test' } } })
      try {
        await flushPromises()
        await wrapper.get('textarea').setValue('Question')
        await wrapper.get('form#claire-brain-chat').trigger('submit')
        await flushPromises()
        const source = FakeEventSource.instances.at(-1)!
        source.emit('chat.snapshot', { submissionId: 'previous', generationMessageId: 'previous-generation',
          turnStatus: 'succeeded', generationStatus: 'done', responding: false, messages: [] })
        const running = { submissionId, generationMessageId: 'current', activeMessageId: 'current',
          turnStatus: 'running', generationStatus: 'running', responding: true, messages: [] }
        source.emit('chat.snapshot', { ...running, submissionId: 'unrelated' })
        source.emit('chat.assistant.update', { submissionId, messageId: 'current', message: 'Uncorrelated' })
        await flushPromises()
        expect(wrapper.find('#claire-current').exists()).toBe(false)
        source.emit('chat.snapshot', { ...running, generationMessageId: 'wrong', activeMessageId: 'wrong' })
        source.emit('chat.assistant.update', { submissionId, messageId: 'current', message: 'Wrong identity' })
        await flushPromises()
        expect(wrapper.find('#claire-current').exists()).toBe(false)
        source.emit('chat.snapshot', running)
        source.emit('chat.assistant.update', { submissionId, messageId: 'current', message: 'Live answer' })
        source.emit('chat.tool.update', { submissionId, messageId: 'current',
          toolsCall: [{ id: 'search', name: 'search', inputs: [], running: true, result: null }] })
        await flushPromises()
        expect(wrapper.get('#claire-current').text()).toContain('Live answer')
        expect(wrapper.find('.claire-tools-running-flag').exists()).toBe(true)
        source.emit('chat.assistant.done', { submissionId, messageId: 'current', turnStatus: 'succeeded' })
        await flushPromises()
        expect(wrapper.get<HTMLTextAreaElement>('textarea').element.disabled).toBe(false)
        expect(wrapper.find('[data-role="claire-assistant-loader"]').exists()).toBe(false)
      } finally { wrapper.unmount() }
    })

    it.each([403, 409, 422, 500, 'network'] as const)('distinguishes definitive refusal from ambiguity: %s', async status => {
      vi.spyOn(console, 'error').mockImplementation(() => {})
      const ids: string[] = []
      let release!: () => void
      const lookups: string[] = []
      vi.stubGlobal('fetch', vi.fn(async (input: string | URL, init?: RequestInit) => {
        const path = new URL(input).pathname
        if (path === '/auth/resource-token') return new Response(capability())
        if (path.startsWith('/brain/turn/')) {
          lookups.push(path.split('/').at(-1)!)
          return new Response('', { status: 404 })
        }
        if (path === '/brain/messages') {
          ids.push(String((init!.body as FormData).get('submissionId')))
          if (ids.length === 1) throw new TypeError('Lost first response')
          await new Promise<void>(resolve => { release = resolve })
          if (status === 'network') throw new TypeError('Lost second response')
          return new Response('', { status })
        }
        return new Response('0')
      }))
      const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), mode, baseUrl: 'https://claire.test' } } })
      try {
        await flushPromises()
        await wrapper.get('textarea').setValue('Earlier ambiguous submission')
        await wrapper.get('form#claire-brain-chat').trigger('submit')
        await flushPromises()
        const local = new File(['bytes'], 'keep.txt', { type: 'text/plain' })
        const upload = wrapper.get<HTMLInputElement>('#claire-chat-upload')
        Object.defineProperty(upload.element, 'files', { configurable: true, value: [local] })
        await upload.trigger('change')
        await wrapper.get('textarea').setValue('  Second draft\n')
        await wrapper.get('form#claire-brain-chat').trigger('submit')
        await flushPromises()
        const input = wrapper.get<HTMLTextAreaElement>('textarea')
        input.element.value = '  Changed while sending\n'
        input.element.dispatchEvent(new Event('input', { bubbles: true }))
        release()
        await flushPromises()
        FakeEventSource.instances.at(-1)!.emit('chat.snapshot', { messages: [], responding: false })
        await flushPromises()
        const rejected = typeof status === 'number' && status < 500
        expect(wrapper.find(`#claire-${ids[0]}`).exists()).toBe(true)
        expect(wrapper.find(`#claire-${ids[1]}`).exists()).toBe(!rejected)
        expect(lookups.includes(ids[1])).toBe(!rejected)
        expect(input.element.value).toBe('  Changed while sending\n')
        expect(input.element.disabled).toBe(false)
        expect(wrapper.get('#claire-chat-attached-files-chat').text()).toContain('keep.txt')
        expect(ids).toHaveLength(2)
      } finally { wrapper.unmount() }
    })

    it('keeps failed drafts accessible after another send and rejects snapshots reviving a rollback', async () => {
      let admit!: (response: Response) => void
      const posts: FormData[] = []
      vi.stubGlobal('fetch', vi.fn(async (input: string | URL, init?: RequestInit) => {
        const path = new URL(input).pathname
        if (path === '/auth/resource-token') return new Response(capability())
        if (path === '/brain/messages') {
          posts.push(init!.body as FormData)
          return new Promise<Response>(resolve => { admit = resolve })
        }
        if (path.startsWith('/brain/turn/')) return new Response('', { status: 404 })
        return new Response('0')
      }))
      const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), mode, baseUrl: 'https://claire.test' } } })
      try {
        await flushPromises()
        const local = new File(['original bytes'], 'original.txt', { type: 'text/plain' })
        const upload = wrapper.get<HTMLInputElement>('#claire-chat-upload')
        Object.defineProperty(upload.element, 'files', { configurable: true, value: [local] })
        await upload.trigger('change')
        const input = wrapper.get<HTMLTextAreaElement>('textarea')
        await input.setValue('  Original\n')
        await wrapper.get('form#claire-brain-chat').trigger('submit')
        await flushPromises()
        const submissionId = String(posts[0].get('submissionId'))
        input.element.value = 'New draft'
        input.element.dispatchEvent(new Event('input', { bubbles: true }))
        admit(Response.json({ submissionId, messageId: 'failed' }, { status: 202 }))
        await flushPromises()
        const source = FakeEventSource.instances.at(-1)!
        source.emit('chat.snapshot', { submissionId, generationMessageId: 'failed', turnStatus: 'rolled_back',
          rollbackConfirmed: true, generationStatus: 'error', responding: false, messages: [] })
        await flushPromises()
        for (const identity of [{ submissionId }, {}]) {
          source.emit('chat.snapshot', { ...identity, generationMessageId: 'failed', activeMessageId: 'failed',
            turnStatus: 'running', responding: true, messages: [entry('failed', 'Stale partial')] })
        }
        await flushPromises()
        expect(wrapper.find('#claire-failed').exists()).toBe(false)
        expect(input.element.disabled).toBe(false)
        await wrapper.get('[aria-label="Fermer la notification"]').trigger('click')
        expect(wrapper.get('[role="alert"] button').text()).toContain('Restaurer')
        await wrapper.get('form#claire-brain-chat').trigger('submit')
        await flushPromises()
        const nextId = String(posts[1].get('submissionId'))
        admit(Response.json({ submissionId: nextId, messageId: 'next' }, { status: 202 }))
        await flushPromises()
        source.emit('chat.snapshot', { submissionId: nextId, generationMessageId: 'next', turnStatus: 'succeeded',
          generationStatus: 'done', responding: false, messages: [entry('next', 'Success')] })
        await flushPromises()
        expect(input.element.value).toBe('')
        await wrapper.get('[role="alert"] button').trigger('click')
        expect(input.element.value).toBe('  Original\n')
        expect(wrapper.get('#claire-chat-attached-files-chat').text()).toContain('original.txt')
        expect(wrapper.find('[role="alert"]').exists()).toBe(false)
        await wrapper.get('form#claire-brain-chat').trigger('submit')
        await flushPromises()
        expect(posts[2].get('upload_files[]')).toBe(local)
        admit(Response.json({ submissionId: posts[2].get('submissionId'), messageId: 'restored' }, { status: 202 }))
        await flushPromises()
      } finally { wrapper.unmount() }
    })

    it.each([true, false])('restores exact draft/files once, including SSE before admission=%s', async beforeAdmission => {
      let admit!: (response: Response) => void
      let submissionId = ''
      const posts: FormData[] = []
      const fetcher = vi.fn(async (input: string | URL, init?: RequestInit) => {
        const path = new URL(input).pathname
        if (path === '/auth/resource-token') return new Response(capability())
        if (path === '/files/list') return Response.json({ acceptedExt: '.txt', files: [{
          fileId: 'stored-1', filename: 'stored.txt', mimeType: 'text/plain', sizeBytes: 1, createdAt: '2026-09-12',
        }] })
        if (path === '/brain/messages') {
          posts.push(init!.body as FormData)
          submissionId = String(posts.at(-1)!.get('submissionId'))
          return new Promise<Response>(resolve => { admit = resolve })
        }
        if (path.startsWith('/brain/turn/')) return new Response('', { status: 404 })
        return new Response('0')
      })
      vi.stubGlobal('fetch', fetcher)
      const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), mode, baseUrl: 'https://claire.test' } } })
      try {
        await flushPromises()
        await wrapper.get('#claire-files-toggle').trigger('click')
        await flushPromises()
        await wrapper.get('[aria-label="Ajouter ce fichier à la conversation"]').trigger('click')
        const local = new File(['exact file bytes'], 'local.txt', { type: 'text/plain' })
        const upload = wrapper.get<HTMLInputElement>('#claire-chat-upload')
        Object.defineProperty(upload.element, 'files', { configurable: true, value: [local] })
        await upload.trigger('change')
        const text = '  Repeat this\n\n  '
        const input = wrapper.get<HTMLTextAreaElement>('textarea')
        await input.setValue(text)
        await wrapper.get('form#claire-brain-chat').trigger('submit')
        await flushPromises()
        expect(submissionId).toMatch(/^[A-Za-z][A-Za-z0-9_.:-]{0,127}$/)
        const admission = () => admit(Response.json({ submissionId, messageId: 'generation-1' }, { status: 202 }))
        if (!beforeAdmission) { admission(); await flushPromises(); expect(input.element.value).toBe('') }
        const source = FakeEventSource.instances.at(-1)!
        const identity = { submissionId, messageId: 'generation-1', threadId: 'thread-1' }
        const previous = entry('previous', 'Previous exchange')
        source.emit('chat.assistant.start', { ...identity, turnStatus: 'running' })
        source.emit('chat.assistant.update', { ...identity, message: 'Partial answer' })
        source.emit('chat.tool.update', { ...identity, toolsCall: [{ id: 't', name: 'search', inputs: [], running: true, result: null }] })
        source.emit('chat.error', { ...identity, turnStatus: 'running', rollbackConfirmed: false, message: 'SECRET' })
        await flushPromises()
        expect(input.element.value).toBe(beforeAdmission ? text : '')
        expect(wrapper.get('[role="alert"]').text()).not.toContain('SECRET')
        expect(wrapper.get('[role="alert"]').element.closest('.claire-chat-input')).not.toBeNull()
        expect(wrapper.get('[role="alert"]').element.closest('form')).toBeNull()
        expect(wrapper.get('#claire-messages').text()).not.toContain('une erreur est survenue')
        const rollback = { ...identity, turnStatus: 'rolled_back', rollbackConfirmed: true }
        source.emit('chat.error', rollback)
        await flushPromises()
        const reconnected = FakeEventSource.instances.at(-1)!
        reconnected.emit('chat.snapshot', { ...rollback, generationStatus: 'error', messages: [previous], responding: false })
        await flushPromises()
        if (beforeAdmission) { admission(); await flushPromises() }
        expect(input.element.value).toBe(text)
        expect(wrapper.get('#claire-chat-attached-files-chat').text()).toContain('local.txt')
        expect(wrapper.get('#claire-chat-attached-files-chat').text()).toContain('stored.txt')
        expect(wrapper.findAll('.claire-message[id]')).toHaveLength(1)
        expect(wrapper.get('#claire-messages').text()).toBe('Previous exchange14:00')
        expect(wrapper.find('[data-role="claire-assistant-loader"]').exists()).toBe(false)
        await input.setValue('New draft')
        reconnected.emit('chat.error', rollback)
        reconnected.emit('chat.assistant.start', { ...identity, turnStatus: 'running' })
        reconnected.emit('chat.snapshot', { ...rollback, messages: [previous], responding: false })
        await flushPromises()
        expect(input.element.value).toBe('New draft')
        expect(posts).toHaveLength(1)
        await wrapper.get('form#claire-brain-chat').trigger('submit')
        await flushPromises()
        expect(posts).toHaveLength(2)
        expect(posts[1].get('upload_files[]')).toBe(local)
        expect(posts[1].getAll('file_ids[]')).toEqual(['stored-1'])
        expect(posts[1].get('submissionId')).not.toBe(posts[0].get('submissionId'))
        admission()
        await flushPromises()
      } finally { wrapper.unmount() }
    })

    it('preserves a changed composer and resolves an older turn behind a newer snapshot', async () => {
      vi.useFakeTimers()
      let admit!: (response: Response) => void
      let submissionId = ''
      let result: unknown = null
      const fetcher = vi.fn(async (input: string | URL, init?: RequestInit) => {
        const path = new URL(input).pathname
        if (path === '/auth/resource-token') return new Response(capability())
        if (path === '/brain/messages') {
          submissionId = String((init!.body as FormData).get('submissionId'))
          return new Promise<Response>(resolve => { admit = resolve })
        }
        if (path.startsWith('/brain/turn/')) return result ? Response.json(result) : new Response('', { status: 404 })
        return new Response('0')
      })
      vi.stubGlobal('fetch', fetcher)
      const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), mode, baseUrl: 'https://claire.test' } } })
      try {
        await flushPromises()
        const input = wrapper.get<HTMLTextAreaElement>('textarea')
        await input.setValue('  Original\n')
        await wrapper.get('form#claire-brain-chat').trigger('submit')
        await flushPromises()
        // A programmatic edit/dictation can race the admission even with a disabled textarea.
        input.element.value = 'Changed'
        input.element.dispatchEvent(new Event('input', { bubbles: true }))
        admit(Response.json({ submissionId, messageId: 'old-generation' }, { status: 202 }))
        await flushPromises()
        expect(input.element.value).toBe('Changed')
        let source = FakeEventSource.instances.at(-1)!
        source.onerror?.()
        await vi.advanceTimersByTimeAsync(2000)
        source = FakeEventSource.instances.at(-1)!
        const newer = { submissionId: 'other-tab', messageId: 'new-generation', activeMessageId: 'new-generation',
          turnStatus: 'running', generationStatus: 'running', responding: true, messages: [entry('new-generation', 'New answer')] }
        source.emit('chat.snapshot', newer)
        await flushPromises()
        expect(input.element.value).toBe('Changed')
        expect(wrapper.findAll('.claire-message[id]')).toHaveLength(1)
        expect(fetcher.mock.calls.some(([url]) => new URL(url).pathname === `/brain/turn/${submissionId}`)).toBe(true)
        result = { submissionId, messageId: 'old-generation', threadId: 'thread-1', turnStatus: 'rolled_back', rollbackConfirmed: true }
        source.emit('chat.snapshot', newer)
        await flushPromises()
        source = FakeEventSource.instances.at(-1)!
        source.emit('chat.snapshot', newer)
        source.emit('chat.error', { submissionId, messageId: 'old-generation', turnStatus: 'rolled_back', rollbackConfirmed: true })
        source.emit('chat.assistant.start', { submissionId, messageId: 'old-generation' })
        await flushPromises()
        expect(input.element.value).toBe('Changed')
        expect(wrapper.get('#claire-new-generation').text()).toContain('New answer')
        expect(wrapper.find('#claire-old-generation').exists()).toBe(false)
        expect(wrapper.find('[data-role="claire-assistant-loader"]').exists()).toBe(true)
        await wrapper.get('[role="alert"] button').trigger('click')
        expect(input.element.value).toBe('  Original\n')
        source.emit('chat.snapshot', newer)
        await flushPromises()
        expect(wrapper.find('[role="alert"] button').text()).toBe('Fermer')
        expect(fetcher.mock.calls.filter(([url]) => new URL(url).pathname === '/brain/messages')).toHaveLength(1)
      } finally { wrapper.unmount() }
    })

    it.each(['succeeded', 'rolled_back'] as const)('accepts a terminal %s snapshot before 202 without replay or duplicate restoration', async turnStatus => {
      let admit!: (response: Response) => void
      let submissionId = ''
      vi.stubGlobal('fetch', vi.fn(async (input: string | URL, init?: RequestInit) => {
        const path = new URL(input).pathname
        if (path === '/auth/resource-token') return new Response(capability())
        if (path === '/brain/messages') {
          submissionId = String((init!.body as FormData).get('submissionId'))
          return new Promise<Response>(resolve => { admit = resolve })
        }
        return new Response('0')
      }))
      const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), mode, baseUrl: 'https://claire.test' } } })
      try {
        await flushPromises()
        const input = wrapper.get<HTMLTextAreaElement>('textarea')
        await input.setValue('  Original\n')
        await wrapper.get('form#claire-brain-chat').trigger('submit')
        await flushPromises()
        const source = FakeEventSource.instances.at(-1)!
        const snapshot = { submissionId, generation: { messageId: 'g', status: turnStatus === 'succeeded' ? 'done' : 'error' },
          turnStatus, rollbackConfirmed: turnStatus === 'rolled_back', responding: false, activeMessageId: null,
          generationStatus: turnStatus === 'succeeded' ? 'done' : 'error',
          messages: turnStatus === 'succeeded' ? [{ ...entry(submissionId, 'Original'), sent: true }, entry('g', 'Success')] : [] }
        source.emit('chat.snapshot', { ...snapshot, threadId: 'another-conversation' })
        await flushPromises()
        expect(wrapper.find('[role="alert"]').exists()).toBe(false)
        source.emit('chat.snapshot', snapshot)
        admit(Response.json({ submissionId, messageId: 'g' }, { status: 202 }))
        await flushPromises()
        expect(input.element.value).toBe(turnStatus === 'succeeded' ? '' : '  Original\n')
        if (turnStatus === 'rolled_back') await wrapper.get('[aria-label="Fermer la notification"]').trigger('click')
        await input.setValue('New draft')
        source.emit('chat.error', { submissionId, messageId: 'g', turnStatus: 'rolled_back', rollbackConfirmed: true })
        source.emit('chat.snapshot', snapshot)
        source.emit('chat.audio.error', { submissionId, messageId: 'g' })
        await flushPromises()
        expect(input.element.value).toBe('New draft')
        expect(wrapper.find('[role="alert"]').exists()).toBe(false)
        expect(wrapper.findAll('.claire-message[id]')).toHaveLength(turnStatus === 'succeeded' ? 2 : 0)
      } finally { wrapper.unmount() }
    })

    it('ignores a late authenticated turn lookup after changing conversation', async () => {
      let resolve!: (response: Response) => void
      let submissionId = ''
      vi.stubGlobal('fetch', vi.fn(async (input: string | URL, init?: RequestInit) => {
        const path = new URL(input).pathname
        if (path === '/auth/resource-token') return new Response(capability())
        if (path === '/brain/messages') {
          submissionId = String((init!.body as FormData).get('submissionId'))
          return Response.json({ submissionId, messageId: 'old' }, { status: 202 })
        }
        if (path.startsWith('/brain/turn/')) return new Promise<Response>(release => { resolve = release })
        if (path === '/history/new') return Response.json({ threadId: 'new', sessionId: 'new-session' })
        return new Response('0')
      }))
      const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), mode, baseUrl: 'https://claire.test' } } })
      try {
        await flushPromises()
        await wrapper.get('textarea').setValue('Old draft')
        await wrapper.get('form#claire-brain-chat').trigger('submit')
        await flushPromises()
        const source = FakeEventSource.instances.at(-1)!
        source.emit('chat.snapshot', { messages: [], responding: false })
        await flushPromises()
        await wrapper.get(mode === 'normal' ? '.claire-options-item' : '[aria-label="Nouvelle conversation"]').trigger('click')
        await flushPromises()
        await wrapper.get('textarea').setValue('New conversation draft')
        resolve(Response.json({ submissionId, messageId: 'old', threadId: 'thread-1', turnStatus: 'rolled_back', rollbackConfirmed: true }))
        source.emit('chat.error', { submissionId, messageId: 'old', turnStatus: 'rolled_back', rollbackConfirmed: true })
        await flushPromises()
        expect(wrapper.get<HTMLTextAreaElement>('textarea').element.value).toBe('New conversation draft')
        expect(wrapper.find('[role="alert"]').exists()).toBe(false)
        expect(wrapper.findAll('.claire-message[id]')).toHaveLength(0)
      } finally { wrapper.unmount() }
    })
  })

  it.each(['normal', 'embed'] as const)('resizes the composer after edits, submission and restored messages in %s', async mode => {
    const longMessage = 'Long message\n'.repeat(20)
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => {
      const path = new URL(input).pathname
      if (path === '/auth/resource-token') return new Response(capability())
      if (path === '/history/exchange/last') return new Response(JSON.stringify({ messages: [], removedMessage: longMessage }))
      return new Response('0')
    }))
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), mode, baseUrl: 'https://claire.test' } } })
    try {
      await flushPromises()
      if (mode === 'embed') await wrapper.get('.claire-embed-toolbar__left').trigger('click')
      const input = wrapper.get<HTMLTextAreaElement>('textarea')
      Object.defineProperty(input.element, 'scrollHeight', { configurable: true, get: () => {
        expect(input.element.style.height).toBe('auto')
        return input.element.value.length > 100 ? 400 : 24
      } })
      await input.setValue(longMessage)
      expect(input.element.style.height).toBe('160px')
      await input.setValue('Short')
      expect(input.element.style.height).toBe('24px')
      await input.setValue(longMessage)
      await wrapper.get('form#claire-brain-chat').trigger('submit')
      await flushPromises()
      expect(input.element.value).toBe('')
      expect(input.element.style.height).toBe('24px')
      const source = FakeEventSource.instances[0]
      source.emit('chat.snapshot', { responding: false, messages: [] })
      await flushPromises()
      await wrapper.get('[aria-label="Annuler le dernier échange"]').trigger('click')
      await flushPromises()
      expect(input.element.value).toBe(longMessage)
      expect(input.element.style.height).toBe('160px')
      source.emit('chat.snapshot', { messages: [], restoredMessage: 'Short' })
      await flushPromises()
      expect(wrapper.findAll('.claire-message')).toHaveLength(0)
      expect(input.element.value).toBe(longMessage)
      await input.setValue('Short')
      expect(input.element.style.height).toBe('24px')
      await input.setValue(longMessage)
      expect(input.element.style.height).toBe('160px')
      await input.setValue('')
      expect(input.element.style.height).toBe('24px')
    } finally { wrapper.unmount() }
  })

  it.each(['normal', 'embed'] as const)('preserves the header avatar outside message groups in %s', async mode => {
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => new Response(
      new URL(input).pathname === '/auth/resource-token' ? capability() : '0',
    )))
    const config: ClaireBootstrap = { ...bootstrap(), mode, baseUrl: 'https://claire.test' }
    config.brains.push({ slug: 'other', name: 'Other', description: '', avatar: '/other.png', theme: { preset: 'light', tokens: {}, variants: {} } })
    const wrapper = mount(ClaireApp, { props: { config } })
    const avatarSelector = mode === 'embed' ? '.claire-embed-toolbar__avatar' : '.claire-chat-header__avatar'
    try {
      await flushPromises()
      expect(wrapper.get(avatarSelector).attributes('src')).toBe('/avatar.png')
      expect(wrapper.get('textarea.claire-chat-input__field').attributes()).toMatchObject({
        'aria-label': 'Votre message', placeholder: 'Message...',
      })
      if (mode === 'embed') expect(wrapper.get('.claire-embed').classes()).toContain('is-collapsed')
      FakeEventSource.instances[0].emit('chat.snapshot', { messages: [entry('a'), entry('b')] })
      await flushPromises()
      expect(wrapper.findAll('.claire-message')).toHaveLength(2)
      expect(wrapper.find('.claire-message img, .claire-message [class*="avatar"]').exists()).toBe(false)
      expect(wrapper.get(avatarSelector).attributes('src')).toBe('/avatar.png')
      if (mode === 'embed') {
        await wrapper.get('.claire-embed-toolbar__left').trigger('click')
        expect(wrapper.get('.claire-embed').classes()).not.toContain('is-collapsed')
        await wrapper.get('[aria-label="Préférences"]').trigger('click')
      }
      await wrapper.get('#claire-brain-selector').setValue('other')
      await flushPromises()
      expect(wrapper.get(avatarSelector).attributes('src')).toBe('/other.png')
      if (mode === 'embed') {
        await wrapper.get('.claire-embed-toolbar__left').trigger('click')
        expect(wrapper.get('.claire-embed').classes()).toContain('is-collapsed')
        expect(wrapper.get(avatarSelector).attributes('src')).toBe('/other.png')
      }
    } finally { wrapper.unmount() }
  })

  it('does not let a late image authorization overwrite a reused streaming img or retain a denied source', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(capability())))
    let finishOld!: (value: { url: string; renewAt: null }) => void
    const authorize = vi.spyOn(SessionClient.prototype, 'protectedResource').mockImplementation(async path => {
      if (path === '/files/serve/old') return new Promise(resolve => { finishOld = resolve })
      if (path === '/files/serve/denied') throw new Error('Forbidden')
      return { url: 'https://claire.test/files/serve/new?token=scoped', renewAt: null }
    })
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), baseUrl: 'https://claire.test' } } })
    try {
      await flushPromises()
      const source = FakeEventSource.instances[0]
      source.emit('chat.snapshot', { messages: [{ ...entry('a'), message: '![Image](/files/serve/old)' }], responding: true, activeMessageId: 'a' })
      await flushPromises()
      const image = wrapper.get<HTMLImageElement>('.claire-message__text img').element
      source.emit('chat.assistant.update', { messageId: 'a', message: '![Image](/files/serve/new)', files: [] })
      await flushPromises()
      expect(wrapper.get('.claire-message__text img').element).toBe(image)
      expect(image.src).toContain('/new?token=scoped')
      finishOld({ url: 'https://claire.test/files/serve/old?token=obsolete', renewAt: null })
      await flushPromises()
      expect(image.src).toContain('/new?token=scoped')
      source.emit('chat.assistant.update', { messageId: 'a', message: '![Image](/files/serve/denied)', files: [] })
      await flushPromises()
      expect(image.hasAttribute('src')).toBe(false)
    } finally { wrapper.unmount(); authorize.mockRestore() }
  })

  it.each(['normal', 'embed'] as const)('authorizes Markdown upload images and real generated IDs in snapshots and streaming in %s', async mode => {
    const requests: string[] = []
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL, init: RequestInit) => {
      if (new URL(input).pathname === '/auth/resource-token') {
        const resources = JSON.parse(init.body as string).resources
        requests.push(...resources.filter((resource: { type: string }) => resource.type === 'file')
          .map((resource: { fileId: string }) => resource.fileId))
        return new Response(capability())
      }
      return new Response('0')
    }))
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), mode, baseUrl: 'https://claire.test' } } })
    try {
      await flushPromises()
      const source = FakeEventSource.instances[0]
      const id = '@@GENERATED@@83b00160-e9d9-4cae-b334-09f496c1f028@@'
      const image = { id, type: 'image', name: 'image.png', url: `https://claire.test/files/serve/${encodeURIComponent(id)}` }
      source.emit('chat.snapshot', { messages: [{ ...entry('a'), message: `![Upload](/files/serve/upload)\n\n![Public](https://images.test/photo.png)\n\n${id}`, files: [image] }] })
      await flushPromises()
      expect(requests).toEqual(['upload', id])
      expect(wrapper.findAll('.claire-message__text img').map(image => image.attributes('src'))).toEqual([
        'https://claire.test/files/serve/upload?token=scoped-stream', 'https://images.test/photo.png',
        `${image.url}?token=scoped-stream`,
      ])
      source.emit('chat.assistant.start', { messageId: 'a' })
      source.emit('chat.assistant.update', { messageId: 'a', message: `![Generated](${id})`, files: [{ ...image, type: 'pending', url: null }] })
      await flushPromises()
      expect(wrapper.find('.claire-generated-image-placeholder').exists()).toBe(true)
      source.emit('chat.assistant.update', { messageId: 'a', message: `![Generated](${id})`, files: [image] })
      await flushPromises()
      expect(wrapper.get('.claire-message__text img').attributes('src')).toBe(`${image.url}?token=scoped-stream`)
      const renderedImage = wrapper.get<HTMLImageElement>('.claire-message__text img').element
      const sourceWrites = vi.spyOn(renderedImage, 'src', 'set')
      for (let index = 0; index < 5; index++) {
        source.emit('chat.assistant.update', { messageId: 'a', message: `![Generated](${id}) Suite ${index}`, files: [image] })
        await flushPromises()
      }
      expect(wrapper.get('.claire-message__text img').element).toBe(renderedImage)
      expect(sourceWrites).not.toHaveBeenCalled()
    } finally { wrapper.unmount() }
  })

  it.each(['full', 'compact'] as const)('initializes persisted %s layout and toggles only the normal app boundary', async layoutMode => {
    const fetchMock = vi.fn(async (input: string | URL, _init?: RequestInit) =>
      new Response(new URL(input).pathname === '/auth/resource-token' ? capability() : '0'))
    vi.stubGlobal('fetch', fetchMock)
    const bodyAttributes = document.body.outerHTML.split('>')[0]
    const config: ClaireBootstrap = { ...bootstrap(), mode: 'normal', layoutMode, baseUrl: 'https://claire.test' }
    const wrapper = mount(ClaireApp, { props: { config } })
    try {
      const root = wrapper.get('.claire-app')
      expect(root.attributes('data-layout')).toBe(layoutMode)
      expect(wrapper.find('.claire-chat-panel > .claire-options-panel').exists()).toBe(true)
      expect(wrapper.find('.claire-chat-panel > .claire-options-backdrop').exists()).toBe(true)
      await wrapper.get('.claire-options-toggle').trigger('click')
      for (const next of [layoutMode === 'compact' ? 'full' : 'compact', layoutMode]) {
        await wrapper.get('#claire-toggle-layout-mode').trigger('click')
        await flushPromises()
        expect(root.attributes('data-layout')).toBe(next)
        expect(wrapper.get('.claire-options-panel').classes()).toContain('claire-is-open')
        expect(wrapper.get('#claire-toggle-layout-mode').text()).toContain(next === 'compact' ? 'Largeur 800px' : 'Plein écran')
      }
      const saves = fetchMock.mock.calls.filter(([url]) => new URL(url).pathname === '/config/layout_mode')
      expect(saves.map(([, init]) => ({ method: init?.method, mode: (init?.body as URLSearchParams).get('mode') }))).toEqual([
        { method: 'POST', mode: layoutMode === 'compact' ? 'full' : 'compact' },
        { method: 'POST', mode: layoutMode },
      ])
      expect(document.body.outerHTML.split('>')[0]).toBe(bodyAttributes)
    } finally { wrapper.unmount() }
  })

  it.each(['normal', 'embed'] as const)('replaces theme bindings on the same compact root before settings resolve in %s', async mode => {
    const pending: Array<(response: Response) => void> = []
    const fetchMock = vi.fn(async (input: string | URL) => {
      const path = new URL(input).pathname
      if (path === '/config/brain_avatar') return new Promise<Response>(resolve => pending.push(resolve))
      return new Response(path === '/auth/resource-token' ? capability() : '0')
    })
    vi.stubGlobal('fetch', fetchMock)
    const bodyAttributes = document.body.outerHTML.split('>')[0]
    const config: ClaireBootstrap = { ...bootstrap(), mode, layoutMode: 'compact', baseUrl: 'https://claire.test' }
    config.brainInfo.theme = {
      preset: 'neon', tokens: { '--claire-accent': '#ff0000', '--claire-body-background': '#160b24', '--custom-old': '12px' },
      variants: { controls: 'outline', effects: 'glow', custom: 'old' },
    }
    config.brains.push({ slug: 'other', name: 'Other', description: '', avatar: '/other.png',
      theme: { preset: 'light', tokens: { '--claire-accent': '#005c9f', '--claire-body-background': '#ffffff' }, variants: { controls: 'solid' } },
    })
    config.brains.push({ slug: 'bare', name: 'Bare', description: '', avatar: '',
      theme: { preset: 'dark', tokens: {}, variants: {} },
    })
    const wrapper = mount(ClaireApp, { props: { config } })
    try {
      const root = wrapper.get<HTMLElement>('.claire-app').element
      expect(root.dataset.layout).toBe('compact')
      expect(root.style.getPropertyValue('--claire-body-background')).toBe('#160b24')
      expect(root.dataset.theme).toBe('neon')
      expect(root.dataset.themeControls).toBe('outline')
      expect(root.dataset.themeEffects).toBe('glow')
      expect(root.dataset.themeCustom).toBeUndefined()
      expect(root.style.getPropertyValue('--custom-old')).toBe('12px')
      await flushPromises()
      if (mode === 'embed') await wrapper.get('[aria-label="Préférences"]').trigger('click')
      await wrapper.get('#claire-brain-selector').setValue('other')
      expect(wrapper.get('.claire-app').element).toBe(root)
      expect(root.dataset.theme).toBe('light')
      expect(root.dataset.layout).toBe('compact')
      expect(root.style.getPropertyValue('--claire-body-background')).toBe('#ffffff')
      expect(root.dataset.themeControls).toBe('solid')
      expect(root.hasAttribute('data-theme-effects')).toBe(false)
      expect(root.style.getPropertyValue('--claire-accent')).toBe('#005c9f')
      expect(root.style.getPropertyValue('--custom-old')).toBe('')
      await wrapper.get('#claire-brain-selector').setValue('bare')
      expect(root.dataset.theme).toBe('dark')
      expect(root.style.length).toBe(0)
      expect(root.hasAttribute('data-theme-controls')).toBe(false)
      expect(root.hasAttribute('data-theme-effects')).toBe(false)
      await flushPromises()
      for (const resolve of pending.reverse()) resolve(new Response('0'))
      await flushPromises()
      expect(root.dataset.theme).toBe('dark')
      expect(root.style.length).toBe(0)
      expect(wrapper.find('style, link[rel="stylesheet"]').exists()).toBe(false)
      expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/css/'))).toBe(false)
      expect(document.body.outerHTML.split('>')[0]).toBe(bodyAttributes)
    } finally { wrapper.unmount() }
  })

  it.each([null, undefined, {}, { preset: '', tokens: {}, variants: {} },
    { preset: 'light', tokens: [], variants: {} },
    { preset: 'light', tokens: { color: 'red' }, variants: {} },
    { preset: 'light', tokens: {}, variants: { controls: null } },
  ])('falls back to common cyberpunk CSS for malformed theme %j', async invalid => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('0')))
    const config = bootstrap()
    config.baseUrl = 'https://claire.test'
    config.brainInfo.theme = invalid as unknown as Theme
    const wrapper = mount(ClaireApp, { props: { config } })
    try {
      const root = wrapper.get<HTMLElement>('.claire-app').element
      expect(root.dataset.theme).toBe('cyberpunk')
      expect(root.style.length).toBe(0)
      expect(root.hasAttribute('data-theme-controls')).toBe(false)
      expect(root.hasAttribute('data-theme-effects')).toBe(false)
      expect(wrapper.find('style, link[rel="stylesheet"]').exists()).toBe(false)
    } finally { wrapper.unmount() }
  })

  it('applies and clears themes inside the same Shadow DOM without adding stylesheets or touching the host', async () => {
    const config = bootstrap()
    config.brainInfo.theme = { preset: 'romantic', tokens: { '--claire-accent': '#aa3366' },
      variants: { controls: 'soft', effects: 'satin' } }
    config.brains.push({ slug: 'bare', name: 'Bare', description: '', avatar: '/other.png',
      theme: { preset: 'dark', tokens: {}, variants: {} } })
    const fetchMock = vi.fn(async (input: string | URL) => {
      const path = new URL(input).pathname
      if (path === '/embed') return new Response(JSON.stringify(config))
      if (path === '/config/brain_avatar') return new Promise<Response>(() => {})
      return new Response(path === '/auth/resource-token' ? capability() : '0')
    })
    vi.stubGlobal('fetch', fetchMock)
    const hostStyle = document.body.getAttribute('style')
    const element = await window.claireEmbed!({ baseUrl: 'https://claire.test', target: '#target' })
    await flushPromises()
    const shadow = element.shadowRoot!
    const root = shadow.querySelector<HTMLElement>('.claire-app')!
    const styles = Array.from(shadow.querySelectorAll('style, link[rel="stylesheet"]'))
    expect(styles).toHaveLength(1) // Syntax highlighting is part of the shared CSS bundle.
    expect(root.style.getPropertyValue('--claire-accent')).toBe('#aa3366')
    expect(root.dataset.theme).toBe('romantic')
    expect(root.dataset.themeControls).toBe('soft')
    expect(root.dataset.themeEffects).toBe('satin')
    shadow.querySelector<HTMLButtonElement>('[aria-label="Préférences"]')!.click()
    await Promise.resolve()
    const selector = shadow.querySelector<HTMLSelectElement>('#claire-brain-selector')!
    selector.value = 'bare'
    selector.dispatchEvent(new Event('change', { bubbles: true }))
    await Promise.resolve()
    expect(shadow.querySelector('.claire-app')).toBe(root)
    expect(root.style.length).toBe(0)
    expect(root.dataset.theme).toBe('dark')
    expect(root.hasAttribute('data-theme-controls')).toBe(false)
    expect(root.hasAttribute('data-theme-effects')).toBe(false)
    expect(Array.from(shadow.querySelectorAll('style, link[rel="stylesheet"]'))).toEqual(styles)
    expect(document.body.getAttribute('style')).toBe(hostStyle)
    expect(document.body.hasAttribute('data-theme')).toBe(false)
    expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/css/'))).toBe(false)
  })

  it.each(['normal', 'embed'] as const)('renews an unchanged keyed audio attachment across expiry/reconnect without interrupting playback in %s', async mode => {
    vi.useFakeTimers()
    let fileRequests = 0
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL, init: RequestInit) => {
      if (new URL(input).pathname === '/auth/resource-token') {
        const isFile = JSON.parse(init.body as string).resources[0].type === 'file'
        return new Response(JSON.stringify({ token: isFile ? `file-${++fileRequests}` : 'stream', expiresAt: Date.now() / 1000 + 300 }))
      }
      return new Response('0')
    }))
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), mode, baseUrl: 'https://claire.test' } } })
    try {
      await flushPromises()
      const messages = [entry('a', 'Audio', [file('audio', 'audio')])]
      FakeEventSource.instances[0].emit('chat.snapshot', { messages })
      await flushPromises()
      const audio = wrapper.get<HTMLAudioElement>('audio.claire-generated-audio').element
      expect(audio.src).toContain('token=file-1')
      await vi.advanceTimersByTimeAsync(295000)
      expect(fileRequests).toBe(2)
      expect(audio.src).toContain('token=file-2')
      await vi.advanceTimersByTimeAsync(6000)
      FakeEventSource.instances.at(-1)!.emit('chat.snapshot', { messages })
      await flushPromises()
      expect(wrapper.get('audio.claire-generated-audio').element).toBe(audio)
      expect(audio.src).toContain('token=file-2')
      let paused = false
      vi.spyOn(audio, 'paused', 'get').mockImplementation(() => paused)
      vi.spyOn(audio, 'pause').mockImplementation(() => { paused = true; audio.dispatchEvent(new Event('pause')) })
      audio.currentTime = 17
      audio.dispatchEvent(new Event('play'))
      const sourceWrites = vi.spyOn(audio, 'src', 'set')
      await vi.advanceTimersByTimeAsync(295000)
      FakeEventSource.instances.at(-1)!.emit('chat.snapshot', { messages })
      await flushPromises()
      expect(fileRequests).toBe(3)
      expect(sourceWrites).not.toHaveBeenCalled()
      expect(audio.src).toContain('token=file-2')
      expect(audio.currentTime).toBe(17)
      paused = true
      audio.dispatchEvent(new Event('pause'))
      await flushPromises()
      expect(audio.src).toContain('token=file-3')
      audio.dispatchEvent(new Event('loadedmetadata'))
      expect(audio.currentTime).toBe(17)
    } finally { wrapper.unmount() }
    const requests = fileRequests
    await vi.advanceTimersByTimeAsync(600000)
    expect(fileRequests).toBe(requests)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('reauthorizes audio before resuming when background timers missed expiry', async () => {
    vi.useFakeTimers()
    let fileRequests = 0
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL, init: RequestInit) => {
      if (new URL(input).pathname === '/auth/resource-token') {
        const isFile = JSON.parse(init.body as string).resources[0].type === 'file'
        return new Response(JSON.stringify({ token: isFile ? `file-${++fileRequests}` : 'stream', expiresAt: Date.now() / 1000 + 300 }))
      }
      return new Response('0')
    }))
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), baseUrl: 'https://claire.test' } } })
    try {
      await flushPromises()
      FakeEventSource.instances[0].emit('chat.snapshot', { messages: [entry('a', '', [file('audio', 'audio')])] })
      await flushPromises()
      const audio = wrapper.get<HTMLAudioElement>('audio').element
      let paused = false
      vi.spyOn(audio, 'paused', 'get').mockImplementation(() => paused)
      const pause = vi.spyOn(audio, 'pause').mockImplementation(() => { paused = true; audio.dispatchEvent(new Event('pause')) })
      const play = vi.spyOn(audio, 'play').mockImplementation(async () => {
        expect(audio.src).toContain('token=file-2')
        paused = false
        audio.dispatchEvent(new Event('play'))
      })
      vi.setSystemTime(Date.now() + 301000)
      audio.dispatchEvent(new Event('play'))
      expect(pause).toHaveBeenCalledOnce()
      expect(play).not.toHaveBeenCalled()
      await flushPromises()
      expect(fileRequests).toBe(2)
      expect(play).toHaveBeenCalledOnce()
    } finally { wrapper.unmount() }
  })

  it.each(['normal', 'embed'] as const)('renders structured stream tools and Markdown securely in %s mode', async mode => {
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => new Response(
      new URL(input).pathname === '/auth/resource-token' ? capability() : '0',
    )))
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), mode, baseUrl: 'https://claire.test' } } })
    try {
      await flushPromises()
      const source = FakeEventSource.instances[0]
      source.emit('chat.snapshot', { messages: [], responding: true, activeMessageId: 'a' })
      const tool = { id: 'tool', name: '<script>alert(1)</script>', inputs: [{ name: 'input', value: '<img onerror=alert(1)>' }], running: true, result: null }
      source.emit('chat.tool.update', { messageId: 'a', toolsCall: [tool] })
      await flushPromises()
      expect(wrapper.find('.claire-tools-running-flag').exists()).toBe(true)
      expect(wrapper.find('[data-role="claire-assistant-loader"]').exists()).toBe(true)
      expect(wrapper.get('#claire-a').classes()).toContain('claire-message--tools-only')
      expect(wrapper.find('#claire-a .claire-message__meta').exists()).toBe(false)
      source.emit('chat.assistant.placeholder', { messageId: 'a', entry: entry('a', '') })
      source.emit('chat.assistant.update', { messageId: 'a', message: '**Stream** <script>alert(1)</script>' })
      source.emit('chat.tool.update', { messageId: 'a', toolsCall: [{ ...tool, running: false, result: '<svg onload=alert(1)>' }] })
      await flushPromises()
      expect(wrapper.get('#claire-message-a strong').text()).toBe('Stream')
      expect(wrapper.find('.claire-tools-running-flag').exists()).toBe(false)
      expect(wrapper.find('script, [onerror], [onload]').exists()).toBe(false)
      expect(wrapper.get('.claire-toolcall__result').text()).toBe('<svg onload=alert(1)>')
      source.emit('chat.assistant.done', { messageId: 'a' })
      source.emit('chat.tool.update', { messageId: 'a', toolsCall: [tool] })
      source.emit('chat.assistant.placeholder', { messageId: 'obsolete', entry: entry('obsolete') })
      await flushPromises()
      expect(wrapper.find('.claire-tools-running-flag').exists()).toBe(false)
      expect(wrapper.find('#claire-obsolete').exists()).toBe(false)
      expect(wrapper.get<HTMLTextAreaElement>('textarea').element.disabled).toBe(false)
    } finally { wrapper.unmount() }
  })
  it.each(['normal', 'embed'] as const)('shows waiting dots between text chunks and throughout tools in %s mode', async mode => {
    vi.useFakeTimers()
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => new Response(
      new URL(input).pathname === '/auth/resource-token' ? capability() : '0',
    )))
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), mode, baseUrl: 'https://claire.test' } } })
    const loading = () => wrapper.find('[data-role="claire-assistant-loader"]').exists()
    try {
      await flushPromises()
      const source = FakeEventSource.instances[0]
      source.emit('chat.assistant.start', { messageId: 'a' })
      await flushPromises()
      expect(loading()).toBe(true)
      source.emit('chat.assistant.update', { messageId: 'a', message: 'Searching' })
      await flushPromises()
      expect(loading()).toBe(false)
      await vi.advanceTimersByTimeAsync(500)
      source.emit('chat.assistant.update', { messageId: 'a', message: 'Searching now' })
      await flushPromises()
      await vi.advanceTimersByTimeAsync(500)
      expect(loading()).toBe(false)
      await vi.advanceTimersByTimeAsync(500)
      expect(loading()).toBe(true)
      source.emit('chat.assistant.update', { messageId: 'a', message: 'Searching now.' })
      await flushPromises()
      expect(loading()).toBe(false)
      const tool = { id: 'search', name: 'search', inputs: [], running: true, result: null }
      source.emit('chat.tool.update', { messageId: 'a', toolsCall: [tool] })
      await flushPromises()
      expect(loading()).toBe(true)
      expect(wrapper.findAll('.claire-message')).toHaveLength(1)
      expect(wrapper.find('#claire-a .claire-message__bubble [data-role="claire-assistant-loader"]').exists()).toBe(true)
      source.emit('chat.tool.update', { messageId: 'a', toolsCall: [{ ...tool, running: false, result: 'Found' }] })
      // Repeated text or file-only updates do not indicate fresh text transmission.
      source.emit('chat.assistant.update', { messageId: 'a', message: 'Searching now.' })
      await flushPromises()
      expect(loading()).toBe(true)
      source.emit('chat.assistant.update', { messageId: 'a', message: 'Searching now. Answer' })
      await flushPromises()
      expect(loading()).toBe(false)
      source.emit('chat.assistant.done', { messageId: 'a' })
      await vi.advanceTimersByTimeAsync(2000)
      expect(loading()).toBe(false)
    } finally { wrapper.unmount() }
  })

  it.each(['normal', 'embed'] as const)('preserves pending user bubbles through stale snapshots and tool updates in %s mode', async mode => {
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => new Response(
      new URL(input).pathname === '/auth/resource-token' ? capability() : '0',
    )))
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), mode, baseUrl: 'https://claire.test' } } })
    try {
      await flushPromises()
      const source = FakeEventSource.instances[0]
      const previous = { ...entry('old-user', 'Repeat this'), sent: true }
      source.emit('chat.snapshot', { messages: [previous], responding: false })
      await flushPromises()
      await wrapper.get('textarea').setValue('Repeat this')
      await wrapper.get('form#claire-brain-chat').trigger('submit')
      await flushPromises()
      const bubble = wrapper.get('[id^="claire-submission-"]').element
      const pendingId = bubble.id
      const submissionId = pendingId.slice('claire-'.length)
      source.emit('chat.snapshot', { messages: [previous], responding: false })
      source.emit('chat.assistant.start', { messageId: 'a' })
      const tool = { id: 'tool', name: 'generate_pdf', inputs: [], running: true, result: null }
      source.emit('chat.tool.update', { messageId: 'a', toolsCall: [tool] })
      await flushPromises()
      expect(wrapper.get(`#${pendingId}`).element).toBe(bubble)
      expect(wrapper.get(`#${pendingId}`).text()).toContain('Repeat this')
      expect(wrapper.findAll('.claire-message[id]')).toHaveLength(3)

      const toolGroup = { ...entry('history-message-1', ''), toolsCall: [tool] }
      source.emit('chat.snapshot', { messages: [previous, toolGroup], responding: true, activeMessageId: 'a' })
      await flushPromises()
      expect(wrapper.findAll('.claire-message[id]').map(node => node.attributes('id')))
        .toEqual(['claire-old-user', pendingId, 'claire-a'])

      const persisted = { ...entry(submissionId, 'Repeat this'), submissionId, sent: true }
      source.emit('chat.snapshot', { messages: [previous, persisted, toolGroup], responding: true, activeMessageId: 'a' })
      await flushPromises()
      expect(wrapper.get(`#${pendingId}`).element).toBe(bubble)
      expect(wrapper.get(`#${pendingId}`).text()).toContain('Repeat this')
      expect(wrapper.findAll('.claire-message[id]')).toHaveLength(3)
      source.emit('chat.assistant.done', { messageId: 'a' })
      source.emit('chat.snapshot', { messages: [], responding: false })
      await flushPromises()
      expect(wrapper.findAll('.claire-message')).toHaveLength(0)
    } finally { wrapper.unmount() }
  })

  it.each(['normal', 'embed'] as const)('retains an ambiguous HTTP failure without resending in %s mode', async mode => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => {
      const path = new URL(input).pathname
      if (path === '/auth/resource-token') return new Response(capability())
      return new Response('0', { status: path === '/brain/messages' ? 500 : 200 })
    }))
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), mode, baseUrl: 'https://claire.test' } } })
    try {
      await flushPromises()
      await wrapper.get('textarea').setValue('Try again')
      await wrapper.get('form#claire-brain-chat').trigger('submit')
      await flushPromises()
      expect(wrapper.findAll('.claire-message')).toHaveLength(1)
      FakeEventSource.instances[0].emit('chat.snapshot', { messages: [], responding: false })
      await flushPromises()
      expect(wrapper.findAll('.claire-message')).toHaveLength(1)
      expect(wrapper.get<HTMLTextAreaElement>('textarea').element.value).toBe('Try again')
    } finally { wrapper.unmount() }
  })

  it.each(['normal', 'embed'] as const)('reconciles a running snapshot tool group with its active message in %s mode', async mode => {
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => new Response(
      new URL(input).pathname === '/auth/resource-token' ? capability() : '0',
    )))
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), mode, baseUrl: 'https://claire.test' } } })
    try {
      await flushPromises()
      const source = FakeEventSource.instances[0]
      const tool = { id: 'tool', name: 'generate_pdf', inputs: [], running: true, result: null }
      source.emit('chat.snapshot', {
        messages: [{ ...entry('history-message-1', ''), toolsCall: [tool] }],
        responding: true,
        activeMessageId: 'assistant-message-1',
        generationStatus: 'running',
      })
      await flushPromises()
      expect(wrapper.findAll('.claire-message[id]')).toHaveLength(1)
      expect(wrapper.get('#claire-assistant-message-1').classes()).toContain('claire-message--tools-only')
      expect(wrapper.find('[data-role="claire-assistant-loader"]').exists()).toBe(true)
      source.emit('chat.assistant.update', { messageId: 'assistant-message-1', message: 'Terminé' })
      await flushPromises()
      expect(wrapper.findAll('.claire-message')).toHaveLength(1)
      expect(wrapper.get('#claire-message-assistant-message-1').text()).toBe('Terminé')
      expect(wrapper.find('#claire-history-message-1').exists()).toBe(false)
    } finally { wrapper.unmount() }
  })
  it.each(['normal', 'embed'] as const)('keeps assistant narration through multiple tool rounds and running snapshots in %s', async mode => {
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => new Response(
      new URL(input).pathname === '/auth/resource-token' ? capability() : '0',
    )))
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), mode, baseUrl: 'https://claire.test' } } })
    try {
      await flushPromises()
      const source = FakeEventSource.instances[0]
      const user = { ...entry('user', 'Find two sources'), sent: true }
      source.emit('chat.snapshot', { messages: [user], responding: false })
      source.emit('chat.assistant.start', { messageId: 'a' })
      source.emit('chat.assistant.update', { messageId: 'a', message: 'First. ' })
      await flushPromises()
      const bubble = wrapper.get('#claire-a').element
      const first = { id: 'first', name: 'search', inputs: [], running: false, result: 'Source one' }
      const second = { id: 'second', name: 'search', inputs: [], running: true, result: null }
      source.emit('chat.tool.update', { messageId: 'a', toolsCall: [first] })
      source.emit('chat.assistant.update', { messageId: 'a', message: 'First. Second. ' })
      source.emit('chat.tool.update', { messageId: 'a', toolsCall: [first, second] })
      await flushPromises()
      expect(wrapper.get('#claire-message-a').text()).toBe('First. Second.')

      for (const text of ['', 'First. ', 'First. Second. ']) {
        source.emit('chat.snapshot', {
          messages: [user, { ...entry('history-message-1', text), toolsCall: [first, second] }],
          responding: true, activeMessageId: 'a', generationStatus: 'running',
        })
        await flushPromises()
        expect(wrapper.get('#claire-a').element).toBe(bubble)
        expect(wrapper.get('#claire-message-a').text()).toBe('First. Second.')
        expect(wrapper.get('#claire-a').classes()).not.toContain('claire-message--tools-only')
        expect(wrapper.findAll('.claire-message[id]')).toHaveLength(2)
      }

      const text = 'First. Second. Answer'
      source.emit('chat.assistant.update', { messageId: 'a', message: text })
      source.emit('chat.assistant.done', { messageId: 'a' })
      source.emit('chat.snapshot', {
        messages: [user, { ...entry('a', text), toolsCall: [first, { ...second, running: false, result: 'Source two' }] }],
        responding: false,
      })
      await flushPromises()
      expect(wrapper.get('#claire-a').element).toBe(bubble)
      expect(wrapper.get('#claire-message-a').text()).toBe(text)
      expect(wrapper.findAll('.claire-toolcall__result')).toHaveLength(2)
      source.emit('chat.snapshot', { messages: [user, entry('a', 'Authoritative correction')], responding: false })
      await flushPromises()
      expect(wrapper.get('#claire-message-a').text()).toBe('Authoritative correction')
    } finally { wrapper.unmount() }
  })

  it.each(['normal', 'embed'] as const)('keeps failed tools terminal and a safe error through snapshots and reconnect in %s', async mode => {
    vi.useFakeTimers()
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => new Response(
      new URL(input).pathname === '/auth/resource-token' ? capability() : '0',
    )))
    const props = { config: { ...bootstrap(), mode, baseUrl: 'https://claire.test' } }
    let wrapper = mount(ClaireApp, { props })
    const tool = { id: 'pdf', name: 'generate_pdf', inputs: [], running: true, result: null }
    const completed = { ...tool, id: 'completed', running: false, result: 'Success' }
    const snapshot = {
      responding: false, activeMessageId: null, generationStatus: 'error',
      messages: [{ ...entry('history-message-1', ''), toolsCall: [completed, { ...tool, running: false, interrupted: true }] }],
    }
    const assertTerminal = () => {
      expect(wrapper.find('.claire-tools-running-flag').exists()).toBe(false)
      expect(wrapper.find('.claire-toolcall__icon--done').exists()).toBe(false)
      expect(wrapper.find('.claire-tools-interrupted').exists()).toBe(true)
      expect(wrapper.text()).toContain('Success')
      expect(wrapper.text()).toContain('une erreur est survenue')
      expect(wrapper.text()).not.toContain('SECRET STACK')
      expect(wrapper.find('[data-role="claire-assistant-loader"]').exists()).toBe(false)
      expect(wrapper.get<HTMLTextAreaElement>('textarea').element.disabled).toBe(false)
    }
    try {
      await flushPromises()
      let source = FakeEventSource.instances.at(-1)!
      source.emit('chat.assistant.start', { messageId: 'a' })
      source.emit('chat.tool.update', { messageId: 'a', toolsCall: [completed, tool] })
      await flushPromises()
      expect(wrapper.find('.claire-tools-running-flag').exists()).toBe(true)
      source.emit('chat.error', { messageId: 'a', message: 'SECRET STACK' })
      await flushPromises()
      assertTerminal()
      source.emit('chat.snapshot', snapshot)
      await flushPromises()
      await vi.advanceTimersByTimeAsync(5000)
      assertTerminal()
      wrapper.unmount()
      wrapper = mount(ClaireApp, { props })
      await flushPromises()
      source = FakeEventSource.instances.at(-1)!
      source.emit('chat.snapshot', snapshot)
      await flushPromises()
      assertTerminal()
      source.emit('chat.snapshot', {
        generationStatus: 'done', responding: false, activeMessageId: null,
        messages: [{ ...entry('success', 'Finished'), toolsCall: [completed] }],
      })
      await flushPromises()
      expect(wrapper.text()).not.toContain('une erreur est survenue')
      expect(wrapper.find('.claire-tools-interrupted').exists()).toBe(false)
      expect(wrapper.find('.claire-toolcall__icon--done').exists()).toBe(true)
    } finally { wrapper.unmount() }
  })

  it.each(['normal', 'embed'] as const)('shares and renews 17 files of different media types in %s mode', async (mode) => {
    vi.useFakeTimers()
    let fileRequests = 0
    const open = vi.spyOn(window, 'open').mockImplementation(() => null)
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL, init: RequestInit) => {
      if (new URL(input).pathname === '/auth/resource-token') {
        const resources = JSON.parse(init.body as string).resources
        const resource = resources[0]
        if (resource.type === 'file') {
          expect(resource).toEqual({ type: 'file', fileId: 'a' })
          expect(resources).toHaveLength(17)
          return new Response(JSON.stringify({ token: `file-${++fileRequests}`, expiresAt: Date.now() / 1000 + 300 }))
        }
        return new Response(JSON.stringify({ token: 'stream', expiresAt: Date.now() / 1000 + 300 }))
      }
      return new Response('0')
    }))
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), mode, baseUrl: 'https://claire.test' } } })
    const files = [file(), ...Array.from({ length: 15 }, (_, index) => file(`image-${index}`, 'image')), file('audio', 'audio')]
    const messages = [entry('a', '[External](https://external.test/files/serve/b)', files)]
    try {
      await flushPromises()
      FakeEventSource.instances[0].emit('chat.snapshot', { messages, responding: true, activeMessageId: 'a' })
      await flushPromises()
      expect(fileRequests).toBe(1)
      expect(wrapper.findAll<HTMLImageElement>('img.claire-generated-image').every(image => image.element.src.includes('token=file-1'))).toBe(true)
      expect(wrapper.find<HTMLAudioElement>('audio.claire-generated-audio').element.src).toContain('token=file-1')
      // Replacing fragments during streaming must only reuse the cached capability.
      for (let index = 0; index < 20; index++) {
        FakeEventSource.instances[0].emit('chat.assistant.update', {
          messageId: 'a', message: entry('a', 'Streaming', files).message, files,
        })
        await flushPromises()
      }
      expect(fileRequests).toBe(1)
      await vi.advanceTimersByTimeAsync(294999)
      expect(fileRequests).toBe(1)
      await vi.advanceTimersByTimeAsync(1)
      expect(fileRequests).toBe(2)
      // Reproduce the SSE reconnect snapshot at 295s, then use the links after 300s.
      FakeEventSource.instances.at(-1)!.emit('chat.snapshot', { messages })
      await flushPromises()
      expect(fileRequests).toBe(2)
      await vi.advanceTimersByTimeAsync(6000)
      const links = wrapper.findAll<HTMLAnchorElement>('a.claire-generated-file')
      expect(links[0].element.href).toContain('token=file-2')
      expect(links[1].element.href).toContain('token=file-2')
      expect(wrapper.get<HTMLAnchorElement>('.claire-message__text a').element.href).toBe('https://external.test/files/serve/b')
      expect(links[0].attributes('target')).toBe('_blank')
      expect(links[0].attributes('rel')).toBe('noopener noreferrer')
      expect(links[1].attributes('download')).toBe('result.txt')
      const usedUrls: string[] = []
      wrapper.element.addEventListener('click', (event: Event) => {
        // Observe after Vue's handler, then suppress jsdom's actual navigation.
        expect(event.defaultPrevented).toBe(false)
        usedUrls.push((event.target as HTMLAnchorElement).href)
        event.preventDefault()
      })
      links[0].element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true }))
      links[1].element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
      expect(usedUrls).toHaveLength(2)
      expect(usedUrls.every(url => url.includes('token=file-2'))).toBe(true)
      expect(open).not.toHaveBeenCalled()
      await vi.advanceTimersByTimeAsync(295000)
      expect(fileRequests).toBe(3)
    } finally { wrapper.unmount() }
    const requestsAtDestroy = fileRequests
    await vi.advanceTimersByTimeAsync(600000)
    expect(fileRequests).toBe(requestsAtDestroy)
    expect(vi.getTimerCount()).toBe(0)
  })

  it.each(['navigate', 'destroy'] as const)('ignores a deferred file renewal after %s without navigation or retries', async (action) => {
    vi.useFakeTimers()
    let fileRequests = 0
    let release!: (value: unknown) => void
    const open = vi.spyOn(window, 'open').mockImplementation(() => null)
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL, init: RequestInit) => {
      const path = new URL(input).pathname
      if (path === '/auth/resource-token') {
        if (JSON.parse(init.body as string).resources[0].type === 'file') {
          if (++fileRequests === 2) {
            const response = new Response()
            vi.spyOn(response, 'json').mockImplementation(() => new Promise(resolve => { release = resolve }))
            return response
          }
          return new Response(JSON.stringify({ token: `file-${fileRequests}`, expiresAt: Date.now() / 1000 + 300 }))
        }
        return new Response(JSON.stringify({ token: 'stream', expiresAt: Date.now() / 1000 + 300 }))
      }
      if (path === '/history/new') return new Response(JSON.stringify({ threadId: 'new', sessionId: 'new' }))
      return new Response('0')
    }))
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), baseUrl: 'https://claire.test' } } })
    const messages = [entry('a', '', [file(), file('audio', 'audio')])]
    await flushPromises()
    FakeEventSource.instances[0].emit('chat.snapshot', { messages })
    await flushPromises()
    const oldLink = wrapper.get<HTMLAnchorElement>('a.claire-generated-file').element
    const oldAudio = wrapper.get<HTMLAudioElement>('audio.claire-generated-audio').element
    const oldHref = oldLink.href
    const oldSource = oldAudio.src
    const click = vi.spyOn(oldLink, 'click')
    await vi.advanceTimersByTimeAsync(295000)
    expect(fileRequests).toBe(2)
    if (action === 'navigate') {
      await wrapper.get('[aria-label="Nouvelle conversation"]').trigger('click')
      await flushPromises()
      FakeEventSource.instances.at(-1)!.emit('chat.snapshot', { messages })
      await flushPromises()
      expect(wrapper.get<HTMLAnchorElement>('a.claire-generated-file').element.href).toContain('token=file-3')
      expect(wrapper.get<HTMLAudioElement>('audio.claire-generated-audio').element.src).toContain('token=file-3')
    } else wrapper.unmount()
    release({ token: 'obsolete', expiresAt: Date.now() / 1000 + 300 })
    await flushPromises()
    expect(oldLink.href).toBe(oldHref)
    expect(oldAudio.src).toBe(oldSource)
    expect(open).not.toHaveBeenCalled()
    expect(click).not.toHaveBeenCalled()
    if (action === 'navigate') {
      expect(wrapper.get<HTMLAnchorElement>('a.claire-generated-file').element.href).toContain('token=file-3')
      wrapper.unmount()
    }
    const requestsAtDestroy = fileRequests
    await vi.advanceTimersByTimeAsync(600000)
    expect(fileRequests).toBe(requestsAtDestroy)
    expect(vi.getTimerCount()).toBe(0)
  })

  it.each(['token', 'sessionToken', 'ssoToken'] as const)('rejects legacy mini-tokens supplied through %s', async (key) => {
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
    await expect(window.claireEmbed!({ [key]: jwt('minitoken') })).rejects.toThrow('mini-token')
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('exchanges explicit SSO and authenticates embed only through the session header', async () => {
    const session = jwt('session')
    const refreshed = session.replace('signature', 'refreshed')
    const html = JSON.stringify(bootstrap())
    const fetchMock = vi.fn(async (input: string | URL, init: RequestInit) => {
      const url = new URL(input)
      expect(url.search).toBe('')
      if (url.pathname === '/auth/embed/exchange') {
        expect(JSON.parse(init.body as string)).toEqual({ sso_token: 'opaque-sso' })
        return new Response(JSON.stringify({ session_token: session }))
      }
      if (url.pathname === '/embed') {
        expect(new Headers(init.headers).get('X-Claire-Auth')).toBe(session)
        expect(init.redirect).toBe('error')
        return new Response(html, { headers: { 'X-Claire-Token': refreshed } })
      }
      expect(new Headers(init.headers).get('X-Claire-Auth')).toBe(refreshed)
      return new Response(url.pathname === '/auth/resource-token' ? capability() : '0')
    })
    vi.stubGlobal('fetch', fetchMock)
    await window.claireEmbed!({ baseUrl: 'https://claire.test', ssoToken: 'opaque-sso' })
    await flushPromises()
    expect(JSON.parse(sessionStorage.getItem('claire_session_token')!).token).toBe(refreshed)
    expect(sessionStorage.getItem('claire_mini_token')).toBeNull()
    expect(FakeEventSource.instances).toHaveLength(1)
  })

  it('keeps the new global playback when an old play promise and callback fail', async () => {
    let reject!: (error: Error) => void
    let oldCallback!: (error?: Error) => void
    vi.spyOn(BrowserAudio.prototype, 'playReady')
      .mockImplementationOnce((_audio, callback) => {
        oldCallback = callback
        return new Promise((_, fail) => { reject = fail })
      }).mockResolvedValue(undefined)
    const stop = vi.spyOn(BrowserAudio.prototype, 'stopPlayback').mockImplementation(() => {})
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => new Response(
      new URL(input).pathname === '/auth/resource-token' ? capability() : '0',
    )))
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), baseUrl: 'https://claire.test', audioEnabled: true } } })
    try {
      await flushPromises()
      const source = FakeEventSource.instances[0]
      source.emit('chat.snapshot', { messages: [entry('a'), entry('b')], audioRequestIds: { a: 'auto-a', b: 'auto-b' } })
      await flushPromises()
      source.emit('chat.audio.ready', { messageId: 'a', audioRequestId: 'auto-a', audioData: btoa('mp3') })
      source.emit('chat.audio.ready', { messageId: 'b', audioRequestId: 'auto-b', audioData: btoa('mp3') })
      reject(new DOMException('old playback', 'AbortError'))
      oldCallback(new Error('old decode'))
      await flushPromises()
      expect(stop).not.toHaveBeenCalled()
      expect(wrapper.get('#claire-b [data-audio-listen]').attributes('title')).toBe('Arrêter la lecture')
      expect(wrapper.text()).not.toContain('La synthèse vocale')
    } finally { wrapper.unmount() }
  })

  it('backs off failed stream authorization and cancels retry on unmount', async () => {
    vi.useFakeTimers()
    let requests = 0
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => {
      if (new URL(input).pathname === '/auth/resource-token') {
        requests++
        return new Response(null, { status: 503 })
      }
      return new Response('0')
    }))
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), baseUrl: 'https://claire.test' } } })
    await flushPromises()
    expect(requests).toBe(1)
    await vi.advanceTimersByTimeAsync(1500)
    expect(requests).toBe(2)
    await vi.advanceTimersByTimeAsync(2999)
    expect(requests).toBe(2)
    await vi.advanceTimersByTimeAsync(1)
    expect(requests).toBe(3)
    expect(FakeEventSource.instances).toHaveLength(0)
    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(60000)
    expect(requests).toBe(3)
  })

  it('reauthorizes on error and cancels reconnect backoff on navigation', async () => {
    vi.useFakeTimers()
    let requests = 0
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => {
      const path = new URL(input).pathname
      if (path === '/auth/resource-token') {
        requests++
        expect(FakeEventSource.instances.every(source => source.closed)).toBe(true)
        return new Response(JSON.stringify({ token: `stream-${requests}`, expiresAt: Date.now() / 1000 + 30 }))
      }
      if (path === '/history/new') return new Response(JSON.stringify({ threadId: 'new', sessionId: 'new' }))
      return new Response('0')
    }))
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), baseUrl: 'https://claire.test' } } })
    try {
      await flushPromises()
      await vi.advanceTimersByTimeAsync(24000)
      const oldSource = FakeEventSource.instances[0]
      oldSource.onerror?.()
      expect(oldSource.closed).toBe(true)
      await vi.advanceTimersByTimeAsync(1000)
      expect(requests).toBe(1)
      await vi.advanceTimersByTimeAsync(500)
      expect(requests).toBe(2)
      oldSource.onerror?.()
      FakeEventSource.instances[1].onerror?.()
      await wrapper.get('[aria-label="Nouvelle conversation"]').trigger('click')
      await flushPromises()
      expect(requests).toBe(3)
      expect(String(FakeEventSource.instances[2].url)).toContain('threadId=new')
      await vi.advanceTimersByTimeAsync(3000)
      expect(requests).toBe(3)
      expect(FakeEventSource.instances.filter(source => !source.closed)).toHaveLength(1)
    } finally { wrapper.unmount() }
    await vi.advanceTimersByTimeAsync(60000)
    expect(requests).toBe(3)
  })

  it.each(['resolve', 'reject'])('ignores a transcription that will %s after navigating', async (outcome) => {
    let finish!: (audio: Blob, type: string) => void | Promise<void>
    let release!: (value: unknown) => void
    let reject!: (error: Error) => void
    vi.spyOn(BrowserAudio.prototype, 'supported').mockReturnValue(true)
    vi.spyOn(BrowserAudio.prototype, 'startRecording').mockImplementation(async callback => { finish = callback })
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => {
      const path = new URL(input).pathname
      if (path === '/auth/resource-token') return new Response(capability())
      if (path === '/v1/audio/transcriptions') {
        const response = new Response()
        vi.spyOn(response, 'json').mockImplementation(() => new Promise((resolve, fail) => { release = resolve; reject = fail }))
        return response
      }
      if (path === '/history/new') return new Response(JSON.stringify({ threadId: 'new', sessionId: 'new' }))
      return new Response('0')
    }))
    const config = { ...bootstrap(), baseUrl: 'https://claire.test', audioEnabled: true, audioAvailable: true, audioDictationMode: 'auto_send' as const }
    const wrapper = mount(ClaireApp, { props: { config } })
    try {
      await flushPromises()
      await wrapper.get('[aria-label="Dicter un message"]').trigger('click')
      const pending = finish(new Blob(['audio']), 'audio/webm')
      await flushPromises()
      await wrapper.get('[aria-label="Nouvelle conversation"]').trigger('click')
      await flushPromises()
      await wrapper.get('textarea').setValue('new draft')
      if (outcome === 'resolve') release({ text: 'old transcription' })
      else reject(new Error('late transcription'))
      await pending
      await flushPromises()
      expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('new draft')
      expect((wrapper.get('textarea').element as HTMLTextAreaElement).disabled).toBe(false)
      expect(wrapper.text()).not.toContain('La transcription audio')
    } finally { wrapper.unmount() }
  })

  it.each(['resolve', 'reject'])('ignores a history-open JSON that will %s after a newer navigation', async (outcome) => {
    let release!: (value: unknown) => void
    let reject!: (error: Error) => void
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => {
      const path = new URL(input).pathname
      if (path === '/auth/resource-token') return new Response(capability())
      if (path === '/history/list') return new Response(JSON.stringify({ histories: [
        { threadId: 'old', title: 'Old', summary: '', updatedAt: '2026-09-12T12:00:00Z' },
      ] }))
      if (path === '/history/open/old') {
        const response = new Response()
        vi.spyOn(response, 'json').mockImplementation(() => new Promise((resolve, fail) => { release = resolve; reject = fail }))
        return response
      }
      if (path === '/history/new') return new Response(JSON.stringify({ threadId: 'new', sessionId: 'new' }))
      return new Response('0')
    }))
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), baseUrl: 'https://claire.test' } } })
    try {
      await flushPromises()
      await wrapper.get('#claire-history-toggle').trigger('click')
      await flushPromises()
      await wrapper.get('[aria-label="Afficher la conversation"]').trigger('click')
      await flushPromises()
      await wrapper.get('[aria-label="Nouvelle conversation"]').trigger('click')
      await flushPromises()
      if (outcome === 'resolve') release({ threadId: 'old' })
      else reject(new Error('late history'))
      await flushPromises()
      expect(wrapper.get('#claire-chat-stream').attributes('data-thread-id')).toBe('new')
      expect(wrapper.text()).not.toContain('Une erreur est survenue')
      expect((wrapper.get('textarea').element as HTMLTextAreaElement).disabled).toBe(false)
    } finally { wrapper.unmount() }
  })

  it.each(['minitoken', 'unknown'])('rejects generic %s credentials without fetching', async (audience) => {
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
    await expect(window.claireEmbed!({ token: jwt(audience) })).rejects.toThrow(/mini-token|audience/)
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it.each([
    ['normal', 'resolve'], ['normal', 'reject'], ['embed', 'resolve'], ['embed', 'reject'],
  ] as const)('isolates late submit in %s mode when it will %s', async (mode, outcome) => {
    let release!: (response: Response) => void
    let reject!: (error: Error) => void
    const config = { ...bootstrap(), mode, baseUrl: 'https://claire.test' }
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => {
      const path = new URL(input).pathname
      if (path === '/auth/resource-token') return new Response(capability())
      if (path === '/brain/messages') return new Promise<Response>((resolve, fail) => { release = resolve; reject = fail })
      if (path === '/history/new') return new Response(JSON.stringify({ threadId: 'new', sessionId: 'new-session' }))
      return new Response('0')
    }))
    const wrapper = mount(ClaireApp, { props: { config } })
    try {
      await flushPromises()
      await wrapper.get('textarea').setValue('old message')
      await wrapper.get('form#claire-brain-chat').trigger('submit')
      await wrapper.get(mode === 'normal' ? '.claire-options-item' : '[aria-label="Nouvelle conversation"]').trigger('click')
      await flushPromises()
      await wrapper.get('textarea').setValue('new draft')
      FakeEventSource.instances.at(-1)!.emit('chat.assistant.start', { threadId: 'new', messageId: 'new' })
      if (outcome === 'resolve') release(new Response(null, { status: 204 }))
      else reject(new Error('late submit'))
      await flushPromises()
      expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('new draft')
      expect((wrapper.get('textarea').element as HTMLTextAreaElement).disabled).toBe(true)
      expect(wrapper.text()).not.toContain('Le message n’a pas pu')
    } finally { wrapper.unmount() }
  })

  it.each(['normal', 'embed'] as const)('uses last navigation intent and ignores late delete JSON in %s mode', async (mode) => {
    let releaseDelete!: (value: unknown) => void
    const navigations: Array<(response: Response) => void> = []
    const config = { ...bootstrap(), mode, baseUrl: 'https://claire.test' }
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => {
      const path = new URL(input).pathname
      if (path === '/auth/resource-token') return new Response(capability())
      if (path === '/history/exchange/last') {
        const response = new Response()
        vi.spyOn(response, 'json').mockImplementation(() => new Promise(resolve => { releaseDelete = resolve }))
        return response
      }
      if (path === '/history/new') return new Promise<Response>(resolve => navigations.push(resolve))
      return new Response('0')
    }))
    const wrapper = mount(ClaireApp, { props: { config } })
    try {
      await flushPromises()
      await wrapper.get('[aria-label="Annuler le dernier échange"]').trigger('click')
      await flushPromises()
      await wrapper.get(mode === 'normal' ? '.claire-options-item' : '[aria-label="Nouvelle conversation"]').trigger('click')
      await wrapper.get(mode === 'normal' ? '.claire-options-item' : '[aria-label="Nouvelle conversation"]').trigger('click')
      navigations[1](new Response(JSON.stringify({ threadId: 'winner', sessionId: 'winner-session' })))
      await flushPromises()
      await wrapper.get('textarea').setValue('winner draft')
      navigations[0](new Response(JSON.stringify({ threadId: 'loser', sessionId: 'loser-session' })))
      releaseDelete({ messages: [entry('old', 'obsolete exchange')], removedMessage: 'obsolete draft' })
      await flushPromises()
      expect(wrapper.get('#claire-chat-stream').attributes('data-thread-id')).toBe('winner')
      expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('winner draft')
      expect(wrapper.text()).not.toContain('obsolete exchange')
    } finally { wrapper.unmount() }
  })

  it.each([['retry', true], ['disable', true], ['retry', false]] as const)(
    'correlates two audio generations across %s and stable snapshots (randomUUID: %s)', async (action, randomUuid) => {
    if (!randomUuid) vi.stubGlobal('crypto', { getRandomValues: crypto.getRandomValues.bind(crypto) })
    const requests: Array<{ id: string; reject: (error: Error) => void }> = []
    const play = vi.spyOn(BrowserAudio.prototype, 'playReady').mockResolvedValue()
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL, init: RequestInit) => {
      const path = new URL(input).pathname
      if (path === '/auth/resource-token') return new Response(capability())
      if (path === '/brain/audio') return new Promise<Response>((_, reject) => {
        requests.push({ id: (init.body as URLSearchParams).get('audioRequestId')!, reject })
      })
      return new Response('0')
    }))
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), baseUrl: 'https://claire.test', audioEnabled: true, audioAvailable: true } } })
    try {
      await flushPromises()
      const source = FakeEventSource.instances[0]
      const messages = [entry()]
      source.emit('chat.snapshot', { messages })
      await flushPromises()
      await wrapper.get('[data-audio-listen]').trigger('click')
      await flushPromises()
      const a = requests[0].id
      expect(a).toMatch(randomUuid
        ? /^[\da-f]{8}-[\da-f]{4}-4[\da-f]{3}-[89ab][\da-f]{3}-[\da-f]{12}$/i
        : /^[\da-f]{32}$/i)
      if (action === 'retry') source.emit('chat.audio.error', { messageId: 'a', audioRequestId: a })
      else {
        await wrapper.get('[aria-label="Préférences"]').trigger('click')
        const toggle = wrapper.findAll('input[type="checkbox"]').find(input => input.element.parentElement?.textContent?.trim() === 'Audio')!
        await toggle.setValue(false)
        await toggle.setValue(true)
        await flushPromises()
        source.emit('chat.snapshot', { messages, audioRequestIds: { a: 'auto-a' } })
        source.emit('chat.audio.ready', { messageId: 'a', audioRequestId: 'auto-a', audioData: btoa('obsolete') })
        expect(wrapper.get('[data-audio-listen]').attributes('title')).toBe('Générer l’audio')
        expect(play).not.toHaveBeenCalled()
      }
      await flushPromises()
      await wrapper.get('[data-audio-listen]').trigger('click')
      await flushPromises()
      const b = requests[1].id
      expect(b).not.toBe(a)
      source.emit('chat.snapshot', { messages, audioRequestIds: { a: 'auto-a' } })
      source.emit('chat.assistant.start', { messageId: 'a' })
      source.emit('chat.assistant.done', { messageId: 'a', audioRequestId: 'auto-a' })
      for (const audioRequestId of [undefined, a, 'auto-a']) {
        source.emit('chat.audio.ready', { messageId: 'a', audioRequestId, audioData: 'invalid base64!' })
        source.emit('chat.audio.error', { messageId: 'a', audioRequestId })
      }
      requests[0].reject(new Error('obsolete HTTP error'))
      await flushPromises()
      expect(wrapper.get('[data-audio-listen]').attributes('title')).toBe('Génération audio en cours')
      expect(wrapper.text()).not.toContain('La génération audio')
      expect(play).not.toHaveBeenCalled()
      source.emit('chat.audio.ready', { messageId: 'a', audioRequestId: b, audioData: btoa('new') })
      await flushPromises()
      const cached = play.mock.calls[0][0]
      source.emit('chat.audio.ready', { messageId: 'a', audioRequestId: a, audioData: btoa('old') })
      source.emit('chat.audio.error', { messageId: 'a', audioRequestId: a })
      source.emit('chat.snapshot', { messages })
      await wrapper.get('[data-audio-listen]').trigger('click')
      await wrapper.get('[data-audio-listen]').trigger('click')
      expect(play.mock.calls[1][0]).toBe(cached)
      source.emit('chat.snapshot', { messages: [] })
      source.emit('chat.snapshot', { messages, audioRequestIds: { a: 'auto-a' } })
      source.emit('chat.audio.ready', { messageId: 'a', audioRequestId: b, audioData: btoa('late') })
      await flushPromises()
      expect(wrapper.get('[data-audio-listen]').attributes('title')).toBe('Générer l’audio')
      expect(play).toHaveBeenCalledTimes(2)
    } finally { wrapper.unmount() }
  })

  it.each(['done', 'snapshot'] as const)('waits for explicit auto audio announcement through %s', async (announcement) => {
    const play = vi.spyOn(BrowserAudio.prototype, 'playReady').mockResolvedValue()
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => new Response(
      new URL(input).pathname === '/auth/resource-token' ? capability() : '0',
    )))
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), baseUrl: 'https://claire.test', audioEnabled: true, audioAutoGenerate: true } } })
    try {
      await flushPromises()
      const source = FakeEventSource.instances[0]
      const messages = [entry()]
      const ready = { messageId: 'a', audioRequestId: 'auto-a', audioData: btoa('mp3') }
      source.emit('chat.snapshot', { messages })
      source.emit('chat.audio.ready', ready)
      await flushPromises()
      expect(play).not.toHaveBeenCalled()
      expect(wrapper.get('[data-audio-listen]').attributes('title')).toBe('Générer l’audio')
      if (announcement === 'done') {
        source.emit('chat.assistant.start', { messageId: 'a' })
        source.emit('chat.assistant.done', { messageId: 'a', audioRequestId: 'auto-a' })
      } else source.emit('chat.snapshot', { messages, audioRequestIds: { a: 'auto-a' } })
      await flushPromises()
      expect(wrapper.get('[data-audio-listen]').attributes('title')).toBe(
        announcement === 'done' ? 'Génération audio en cours' : 'Générer l’audio',
      )
      source.emit('chat.audio.ready', ready)
      source.emit('chat.snapshot', { messages, audioRequestIds: { a: 'auto-a' } })
      source.emit('chat.audio.ready', ready)
      await flushPromises()
      expect(play).toHaveBeenCalledOnce()
    } finally { wrapper.unmount() }
  })

  it('restores an auto audio expectation in a new component from snapshot metadata only', async () => {
    const play = vi.spyOn(BrowserAudio.prototype, 'playReady').mockResolvedValue()
    const fetchMock = vi.fn(async (input: string | URL) => new Response(
      new URL(input).pathname === '/auth/resource-token' ? capability() : '0',
    ))
    vi.stubGlobal('fetch', fetchMock)
    const config = { ...bootstrap(), baseUrl: 'https://claire.test', audioEnabled: true, audioAutoGenerate: true }
    const previous = mount(ClaireApp, { props: { config } })
    await flushPromises()
    previous.unmount()
    const wrapper = mount(ClaireApp, { props: { config } })
    try {
      await flushPromises()
      const source = FakeEventSource.instances.at(-1)!
      source.emit('chat.snapshot', {
        threadId: config.threadId,
        responding: false,
        messages: [entry()],
        audioRequestIds: { a: 'auto-a' },
      })
      await flushPromises()
      expect(wrapper.get('[data-audio-listen]').attributes('title')).toBe('Générer l’audio')
      expect(wrapper.get<HTMLButtonElement>('[data-audio-listen]').element.disabled).toBe(false)
      expect(play).not.toHaveBeenCalled()
      expect(fetchMock.mock.calls.some(([input]) => new URL(input).pathname === '/brain/audio')).toBe(false)
      source.emit('chat.audio.ready', { messageId: 'a', audioRequestId: 'auto-a', audioData: btoa('mp3') })
      await flushPromises()
      expect(play).toHaveBeenCalledOnce()
      expect(wrapper.get('[data-audio-listen]').attributes('title')).toBe('Arrêter la lecture')
    } finally { wrapper.unmount() }
  })

  it.each(['disable', 'delete'] as const)('ignores audio ready/error and request failure after %s', async (action) => {
    let reject!: (error: Error) => void
    const config = { ...bootstrap(), baseUrl: 'https://claire.test', audioEnabled: true, audioAvailable: true }
    const play = vi.spyOn(BrowserAudio.prototype, 'playReady').mockResolvedValue()
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => {
      const path = new URL(input).pathname
      if (path === '/auth/resource-token') return new Response(capability())
      if (path === '/brain/audio') return new Promise<Response>((_, fail) => { reject = fail })
      return new Response('0')
    }))
    const wrapper = mount(ClaireApp, { props: { config } })
    try {
      await flushPromises()
      const source = FakeEventSource.instances[0]
      source.emit('chat.snapshot', { messages: [entry()] })
      await flushPromises()
      await wrapper.get('[data-audio-listen]').trigger('click')
      if (action === 'delete') source.emit('chat.snapshot', { messages: [] })
      else {
        await wrapper.get('[aria-label="Préférences"]').trigger('click')
        const audioSwitch = wrapper.findAll('input[type="checkbox"]').find(input => input.element.parentElement?.textContent?.trim() === 'Audio')!
        await audioSwitch.setValue(false)
      }
      source.emit('chat.audio.ready', { messageId: 'a', audioData: btoa('mp3') })
      source.emit('chat.audio.error', { messageId: 'a' })
      reject(new Error('late audio'))
      await flushPromises()
      expect(play).not.toHaveBeenCalled()
      expect(wrapper.find('[data-audio-listen]').exists()).toBe(false)
      expect(wrapper.text()).not.toContain('La génération audio')
    } finally { wrapper.unmount() }
  })

  it.each([1, 30, 600])('keeps a stream beyond its %ss token TTL and local cap, reauthorizing only on disconnect', async ttl => {
    vi.useFakeTimers()
    const config = { ...bootstrap(), baseUrl: 'https://claire.test' }
    let release!: (response: Response) => void
    let capabilities = 0
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => {
      if (new URL(input).pathname === '/auth/resource-token') {
        expect(FakeEventSource.instances.every(source => source.closed)).toBe(true)
        if (++capabilities === 3) return new Promise<Response>(resolve => { release = resolve })
        return new Response(JSON.stringify({ token: `stream-${capabilities}`, expiresAt: Date.now() / 1000 + ttl }))
      }
      return new Response('0')
    }))
    const wrapper = mount(ClaireApp, { props: { config } })
    await flushPromises()
    expect(String(FakeEventSource.instances[0].url)).toContain('token=stream-1')
    await vi.advanceTimersByTimeAsync(Math.max(301000, ttl * 1000 + 1000))
    expect(FakeEventSource.instances).toHaveLength(1)
    expect(FakeEventSource.instances[0].closed).toBe(false)
    expect(capabilities).toBe(1)
    FakeEventSource.instances[0].onerror?.()
    await vi.advanceTimersByTimeAsync(1500)
    expect(FakeEventSource.instances[0].closed).toBe(true)
    expect(String(FakeEventSource.instances[1].url)).toContain('token=stream-2')
    expect(FakeEventSource.instances.filter(source => !source.closed)).toHaveLength(1)
    FakeEventSource.instances[1].onerror?.()
    await vi.advanceTimersByTimeAsync(3000)
    wrapper.unmount()
    release(new Response(capability()))
    await flushPromises()
    expect(FakeEventSource.instances).toHaveLength(2)
    expect(vi.getTimerCount()).toBe(0)
  })

  it.each(['normal', 'embed'] as const)('abandons lost audio by request ID but retains received audio in %s', async mode => {
    vi.useFakeTimers()
    const play = vi.spyOn(BrowserAudio.prototype, 'playReady').mockResolvedValue()
    const requests: string[] = []
    let rejectOld!: (error: Error) => void
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL, init: RequestInit) => {
      const path = new URL(input).pathname
      if (path === '/auth/resource-token') return new Response(capability())
      if (path === '/brain/audio') {
        requests.push((init.body as URLSearchParams).get('audioRequestId')!)
        if (requests.length === 1) return new Promise<Response>((_, reject) => { rejectOld = reject })
      }
      return new Response('0')
    }))
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), mode, baseUrl: 'https://claire.test', audioEnabled: true } } })
    try {
      await flushPromises()
      const source = FakeEventSource.instances[0]
      const messages = [entry('a'), entry('b'), entry('c'), entry('d')]
      source.emit('chat.snapshot', { messages, audioRequestIds: { b: 'cached' } })
      source.emit('chat.audio.ready', { messageId: 'b', audioRequestId: 'cached', audioData: btoa('cached') })
      await flushPromises()
      const cached = play.mock.calls[0][0]
      source.emit('chat.assistant.start', { messageId: 'd' })
      source.emit('chat.assistant.done', { messageId: 'd', audioRequestId: 'auto-d' })
      await wrapper.get('#claire-a [data-audio-listen]').trigger('click')
      source.emit('chat.snapshot', { messages, responding: false, audioRequestIds: { a: requests[0], b: 'cached' } })
      await flushPromises()
      expect(wrapper.get('#claire-a [data-audio-listen]').attributes('disabled')).toBeDefined()
      expect(wrapper.get('#claire-d [data-audio-listen]').attributes('disabled')).toBeDefined()
      source.onerror?.()
      await flushPromises()
      expect(wrapper.get('#claire-a [data-audio-listen]').attributes('disabled')).toBeUndefined()
      expect(wrapper.get('#claire-d [data-audio-listen]').attributes('disabled')).toBeUndefined()
      await vi.advanceTimersByTimeAsync(1500)
      const recovered = FakeEventSource.instances[1]
      const snapshot = { messages, audioRequestIds: { a: requests[0], b: 'cached', c: 'missed-done', d: 'auto-d' } }
      recovered.emit('chat.snapshot', snapshot)
      recovered.emit('chat.snapshot', snapshot)
      recovered.emit('chat.audio.ready', { messageId: 'a', audioRequestId: requests[0], audioData: btoa('lost') })
      recovered.emit('chat.audio.ready', { messageId: 'c', audioRequestId: 'missed-done', audioData: btoa('lost') })
      recovered.emit('chat.audio.ready', { messageId: 'd', audioRequestId: 'auto-d', audioData: btoa('lost') })
      source.emit('chat.audio.ready', { messageId: 'a', audioRequestId: requests[0], audioData: btoa('stale') })
      await flushPromises()
      expect(play).toHaveBeenCalledTimes(1)
      await wrapper.get('#claire-b [data-audio-listen]').trigger('click')
      await wrapper.get('#claire-b [data-audio-listen]').trigger('click')
      expect(play.mock.calls[1][0]).toBe(cached)
      await wrapper.get('#claire-a [data-audio-listen]').trigger('click')
      expect(requests).toHaveLength(2)
      expect(requests[1]).not.toBe(requests[0])
      rejectOld(new Error('obsolete HTTP failure'))
      recovered.emit('chat.snapshot', snapshot)
      recovered.emit('chat.audio.error', { messageId: 'a', audioRequestId: requests[0] })
      await flushPromises()
      expect(wrapper.get('#claire-a [data-audio-listen]').attributes('disabled')).toBeDefined()
      recovered.emit('chat.audio.ready', { messageId: 'a', audioRequestId: requests[1], audioData: btoa('new') })
      await flushPromises()
      expect(play).toHaveBeenCalledTimes(3)
      expect(wrapper.get('#claire-a [data-audio-listen]').attributes('disabled')).toBeUndefined()
    } finally { wrapper.unmount() }
  })

  it.each(['normal', 'embed'] as const)('blocks audio retries during the reconnect gap until a snapshot in %s', async mode => {
    vi.useFakeTimers()
    const play = vi.spyOn(BrowserAudio.prototype, 'playReady').mockResolvedValue()
    const requests: string[] = []
    let capabilities = 0
    let release!: (response: Response) => void
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL, init: RequestInit) => {
      const path = new URL(input).pathname
      if (path === '/auth/resource-token') {
        if (++capabilities === 2) return new Promise<Response>(resolve => { release = resolve })
        return new Response(capability())
      }
      if (path === '/brain/audio') requests.push((init.body as URLSearchParams).get('audioRequestId')!)
      return new Response('0')
    }))
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), mode, baseUrl: 'https://claire.test', audioEnabled: true } } })
    try {
      await flushPromises()
      const source = FakeEventSource.instances[0]
      const messages = [entry('a')]
      source.emit('chat.snapshot', { messages })
      await flushPromises()
      const button = () => wrapper.get('#claire-a [data-audio-listen]')
      await button().trigger('click')
      await flushPromises()
      expect(requests).toHaveLength(1)
      source.onerror?.()
      await flushPromises()
      // The first result is lost. Retry clicks must not submit another exposed request.
      await button().trigger('click')
      await vi.advanceTimersByTimeAsync(1500)
      await button().trigger('click')
      await flushPromises()
      expect(requests).toHaveLength(1)
      expect(button().attributes('disabled')).toBeUndefined()
      release(new Response(capability()))
      await flushPromises()
      const recovered = FakeEventSource.instances[1]
      recovered.onopen?.()
      await button().trigger('click')
      // Neither a stale callback nor a snapshot for another context admits audio.
      source.emit('chat.snapshot', { messages })
      recovered.emit('chat.snapshot', { threadId: 'other', messages })
      await button().trigger('click')
      await flushPromises()
      expect(requests).toHaveLength(1)
      recovered.emit('chat.snapshot', { messages, audioRequestIds: { a: requests[0] } })
      await flushPromises()
      expect(button().attributes('disabled')).toBeUndefined()
      await button().trigger('click')
      await flushPromises()
      expect(requests).toHaveLength(2)
      expect(requests[1]).not.toBe(requests[0])
      recovered.emit('chat.snapshot', { messages, responding: false, audioRequestIds: { a: requests[1] } })
      await flushPromises()
      expect(button().attributes('disabled')).toBeDefined()
      recovered.emit('chat.audio.ready', { messageId: 'a', audioRequestId: requests[1], audioData: btoa('new') })
      await flushPromises()
      expect(play).toHaveBeenCalledOnce()
      expect(button().attributes('disabled')).toBeUndefined()
    } finally { wrapper.unmount() }
  })

  it.each([0, -1])('rejects a capability already expired by %ss and retries with a fresh token', async ttl => {
    vi.useFakeTimers()
    let requests = 0
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => {
      if (new URL(input).pathname !== '/auth/resource-token') return new Response('0')
      requests++
      return new Response(JSON.stringify({ token: `stream-${requests}`, expiresAt: Date.now() / 1000 + (requests === 1 ? ttl : 60) }))
    }))
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), baseUrl: 'https://claire.test' } } })
    try {
      await flushPromises()
      expect(FakeEventSource.instances).toHaveLength(0)
      await vi.advanceTimersByTimeAsync(1500)
      expect(String(FakeEventSource.instances[0].url)).toContain('token=stream-2')
    } finally { wrapper.unmount() }
  })

  it.each(['navigate', 'destroy'] as const)('ignores a deferred reconnect capability after %s', async action => {
    vi.useFakeTimers()
    let release!: (response: Response) => void
    let requests = 0
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => {
      const path = new URL(input).pathname
      if (path === '/auth/resource-token') {
        if (++requests === 2) return new Promise<Response>(resolve => { release = resolve })
        return new Response(capability())
      }
      if (path === '/history/new') return new Response(JSON.stringify({ threadId: 'new', sessionId: 'new' }))
      return new Response('0')
    }))
    const wrapper = mount(ClaireApp, { props: { config: { ...bootstrap(), baseUrl: 'https://claire.test' } } })
    await flushPromises()
    const old = FakeEventSource.instances[0]
    old.onerror?.()
    await vi.advanceTimersByTimeAsync(1500)
    if (action === 'navigate') {
      await wrapper.get('[aria-label="Nouvelle conversation"]').trigger('click')
      await flushPromises()
    } else wrapper.unmount()
    release(new Response(capability()))
    await flushPromises()
    old.onopen?.()
    old.onerror?.()
    old.emit('chat.snapshot', { messages: [entry('obsolete')] })
    await vi.advanceTimersByTimeAsync(60000)
    expect(FakeEventSource.instances).toHaveLength(action === 'navigate' ? 2 : 1)
    if (action === 'navigate') {
      expect(String(FakeEventSource.instances[1].url)).toContain('threadId=new')
      expect(wrapper.find('#claire-obsolete').exists()).toBe(false)
      wrapper.unmount()
    }
    expect(vi.getTimerCount()).toBe(0)
  })

  beforeEach(async () => {
    vi.spyOn(Math, 'random').mockReturnValue(0)
    document.body.innerHTML = '<div id="target"></div>'
    sessionStorage.clear()
    // Direct component mounts represent an already authenticated bootstrap.
    sessionStorage.setItem('claire_session_token', JSON.stringify({ token: jwt('session'), expiresAt: Date.now() + 3600_000 }))
    FakeEventSource.instances = []
    vi.stubGlobal('EventSource', FakeEventSource)
    vi.stubGlobal('CSS', { escape: (value: string) => value })
    await import('./embed')
  })

  afterEach(async () => {
    window.destroyClaireEmbed?.()
    await Promise.resolve()
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
    vi.useRealTimers()
  })

  it.each(['exchange', 'bootstrap', 'body'])('never remounts after destroy during %s', async (stage) => {
    const config = bootstrap()
    const html = JSON.stringify(config)
    let release!: () => void
    let reached!: () => void
    const waiting = new Promise<void>((resolve) => { reached = resolve })
    const deferred = new Promise<void>((resolve) => { release = resolve })
    let signal: AbortSignal | null | undefined
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL, init: RequestInit) => {
      const url = String(input)
      const current = url.includes('/exchange') ? 'exchange' : 'bootstrap'
      if (current === stage || (stage === 'body' && current === 'bootstrap')) {
        signal = init.signal
        reached()
        if (stage !== 'body') await deferred
      }
      if (current === 'exchange') return new Response(JSON.stringify({ session_token: jwt('session') }))
      const response = new Response(html)
      if (stage === 'body') vi.spyOn(response, 'json').mockImplementation(async () => { await deferred; return config })
      return response
    }))
    const foreign = document.createElement('div')
    foreign.id = 'claire-embed-root'
    document.body.append(foreign)
    const pending = window.claireEmbed!({ baseUrl: 'https://claire.test', ssoToken: 'opaque' })
    const rejected = expect(pending).rejects.toMatchObject({ name: 'AbortError' })
    await waiting
    window.destroyClaireEmbed!()
    expect(signal?.aborted).toBe(true)
    release()
    await rejected
    expect(document.querySelector('claire-chat-widget')).toBeNull()
    expect(foreign.isConnected).toBe(true)
  })

  it('lets only the last initialization mount, even when an old fetch ignores abort', async () => {
    const html = JSON.stringify(bootstrap())
    let resolve!: (response: Response) => void
    let oldSignal: AbortSignal | null | undefined
    let calls = 0
    vi.stubGlobal('fetch', vi.fn((input: string | URL, init: RequestInit) => {
      if (String(input).includes('/embed') && calls++ === 0) {
        oldSignal = init.signal
        return new Promise<Response>((done) => { resolve = done })
      }
      return Promise.resolve(new Response(String(input).includes('/embed') ? html : '0'))
    }))
    const first = window.claireEmbed!({ baseUrl: 'https://claire.test' })
    const rejected = expect(first).rejects.toMatchObject({ name: 'AbortError' })
    const second = await window.claireEmbed!({ baseUrl: 'https://claire.test', target: '#target' })
    expect(oldSignal?.aborted).toBe(true)
    resolve(new Response(html))
    await rejected
    expect(document.querySelectorAll('claire-chat-widget')).toHaveLength(1)
    expect(second.parentElement?.parentElement?.id).toBe('target')
    window.destroyClaireEmbed!()
    expect(second.isConnected).toBe(false)
  })

  it('reconciles authoritative response state on reconnect and ignores obsolete events', async () => {
    vi.useFakeTimers()
    const html = JSON.stringify(bootstrap())
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => new Response(
      String(input).includes('/embed') ? html : String(input).includes('/auth/resource-token') ? capability() : '0',
      { headers: { 'X-Claire-Minitoken': jwt('minitoken') } },
    )))
    const element = await window.claireEmbed!({ baseUrl: 'https://claire.test' })
    await vi.waitFor(() => expect(FakeEventSource.instances).toHaveLength(1))
    const input = element.shadowRoot!.querySelector('textarea')!
    const oldSource = FakeEventSource.instances[0]
    oldSource.emit('chat.assistant.start', { messageId: 'old' })
    oldSource.onerror?.()
    await vi.advanceTimersByTimeAsync(5000)
    const source = FakeEventSource.instances[1]
    source.emit('chat.snapshot', {
      responding: true, activeMessageId: 'new',
      messages: [entry('new', 'Partial'), entry('old', 'Old')],
    })
    await Promise.resolve()
    expect(input.disabled).toBe(true)
    oldSource.emit('chat.snapshot', { responding: false, activeMessageId: null, messages: [] })
    oldSource.onerror?.()
    source.emit('chat.assistant.update', { messageId: 'old', message: 'Stale' })
    source.emit('chat.assistant.done', { messageId: 'old' })
    source.emit('chat.error', { messageId: 'old', message: 'Obsolete failure' })
    await Promise.resolve()
    expect(input.disabled).toBe(true)
    expect(element.shadowRoot!.querySelector('#claire-message-old')?.textContent?.trim()).toBe('Old')
    expect(element.shadowRoot!.textContent).not.toContain('Obsolete failure')
    source.emit('chat.assistant.update', { messageId: 'new', message: '**Current**' })
    await Promise.resolve()
    expect(element.shadowRoot!.querySelector('#claire-message-new strong')?.textContent).toBe('Current')
    source.emit('chat.assistant.done', { messageId: 'new' })
    await Promise.resolve()
    expect(input.disabled).toBe(false)
    source.emit('chat.snapshot', { responding: false, activeMessageId: null, messages: [entry('new', 'Final')] })
    source.emit('chat.assistant.update', { messageId: 'new', message: 'Late' })
    await Promise.resolve()
    expect(element.shadowRoot!.querySelector('#claire-message-new')?.textContent?.trim()).toBe('Final')
    source.emit('chat.assistant.start', { messageId: 'another' })
    source.emit('chat.snapshot', { responding: false, activeMessageId: null, messages: [] })
    await Promise.resolve()
    expect(input.disabled).toBe(false)
    source.emit('chat.assistant.start', { messageId: 'failed' })
    source.emit('chat.error', { messageId: 'failed', message: 'Current failure' })
    source.emit('chat.snapshot', { responding: false, activeMessageId: null, generationStatus: 'error', messages: [] })
    await Promise.resolve()
    expect(element.shadowRoot!.textContent).toContain('une erreur est survenue')
    expect(element.shadowRoot!.textContent).not.toContain('Current failure')
  })

  it('allows a new dictation after stopping while microphone permission is pending', async () => {
    const config = bootstrap()
    config.audioAvailable = true
    config.audioEnabled = true
    const html = JSON.stringify(config)
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => new Response(
      String(input).includes('/embed') ? html : String(input).includes('/auth/resource-token') ? capability() : '0',
    )))
    vi.spyOn(BrowserAudio.prototype, 'supported').mockReturnValue(true)
    let release!: () => void
    const start = vi.spyOn(BrowserAudio.prototype, 'startRecording')
      .mockImplementationOnce(() => new Promise<void>((resolve) => { release = resolve }))
      .mockResolvedValue(undefined)
    const stop = vi.spyOn(BrowserAudio.prototype, 'stopRecording').mockImplementation(() => {})
    const element = await window.claireEmbed!({ baseUrl: 'https://claire.test' })
    const button = element.shadowRoot!.querySelector<HTMLButtonElement>('[aria-label="Dicter un message"]')!
    button.click()
    await Promise.resolve()
    expect(button.getAttribute('aria-label')).toBe('Arrêter l’enregistrement')
    button.click()
    await Promise.resolve()
    expect(stop).toHaveBeenCalledOnce()
    expect(button.getAttribute('aria-label')).toBe('Dicter un message')
    release()
    await Promise.resolve()
    button.click()
    await Promise.resolve()
    expect(start).toHaveBeenCalledTimes(2)
  })

  it('enhances snapshot audio once per article and streams only into the target article', async () => {
    const config = bootstrap()
    config.audioEnabled = true
    const html = JSON.stringify(config)
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => new Response(
      String(input).includes('/embed') ? html : String(input).includes('/auth/resource-token') ? capability() : '0',
      { headers: { 'X-Claire-Minitoken': jwt('minitoken') } },
    )))
    const element = await window.claireEmbed!({ baseUrl: 'https://claire.test' })
    await vi.waitFor(() => expect(FakeEventSource.instances).toHaveLength(1))
    const source = FakeEventSource.instances[0]
    const setAttribute = vi.spyOn(Element.prototype, 'setAttribute')
    const articles = Array.from({ length: 100 }, (_, index) => entry(String(index), 'Server Markdown'))
    source.emit('chat.snapshot', { messages: articles, responding: true, activeMessageId: '99' })
    await Promise.resolve()
    const audioWrites = () => setAttribute.mock.calls.filter(([name, value]) => name === 'aria-label' && value === 'Générer l’audio').length
    expect(audioWrites()).toBe(100)
    expect(element.shadowRoot!.querySelectorAll('[data-audio-listen]')).toHaveLength(100)
    setAttribute.mockClear()
    source.emit('chat.assistant.update', { messageId: '99', message: '*Stream Markdown*' })
    await Promise.resolve()
    expect(audioWrites()).toBe(0)
    expect(element.shadowRoot!.querySelector('#claire-message-99 em')?.textContent).toBe('Stream Markdown')
    expect(element.shadowRoot!.querySelector('#claire-message-0')?.textContent?.trim()).toBe('Server Markdown')
  })

  it('mounts in Shadow DOM and cleans up its SSE connection', async () => {
    const config = bootstrap()
    const html = JSON.stringify(config)
    const miniToken = jwt('minitoken')
    const promotedSessionToken = jwt('session')
    const fetchMock = vi.fn(async (input: string | URL | Request) => {
      const url = String(input)
      if (url.includes('/embed')) {
        return new Response(html, {
          status: 200,
          headers: {
            'X-Claire-Token': promotedSessionToken,
            'X-Claire-Minitoken': miniToken,
          },
        })
      }
      return new Response(url.includes('/auth/resource-token') ? capability() : '0', { status: 200 })
    })
    vi.stubGlobal('fetch', fetchMock)

    const element = await window.claireEmbed?.({
      baseUrl: 'https://claire.test',
      target: '#target',
      token: promotedSessionToken,
    })
    await vi.waitFor(() => expect(FakeEventSource.instances).toHaveLength(1))

    expect(String(fetchMock.mock.calls[0][0])).toBe('https://claire.test/embed')
    const [, init] = fetchMock.mock.calls[0] as unknown as [URL, RequestInit]
    expect(new Headers(init.headers).get('X-Claire-Auth')).toBe(promotedSessionToken)
    expect(sessionStorage.getItem('claire_mini_token')).toBeNull()
    expect((element as HTMLElement & { config: ClaireBootstrap }).config.sessionToken)
      .toBe(promotedSessionToken)
    expect(element?.shadowRoot?.querySelector('.claire-embed')).not.toBeNull()
    expect(FakeEventSource.instances).toHaveLength(1)
    window.destroyClaireEmbed?.()
    await Promise.resolve()
    expect(FakeEventSource.instances[0].closed).toBe(true)
    expect(document.querySelector('#claire-embed-root')).toBeNull()
  })

  it('builds an autonomous browser bundle', () => {
    const bundle = readFileSync('public/js/embed.js', 'utf8')

    expect(bundle).toContain('window.claireEmbed')
    expect(bundle).not.toContain('process.env.NODE_ENV')
  })

  it('disables the composer during an assistant response without showing an action indicator', async () => {
    const config = bootstrap()
    const html = JSON.stringify(config)
    const miniToken = jwt('minitoken')
    const fetchMock = vi.fn(async (input: string | URL | Request) => {
      if (String(input).includes('/embed')) {
        return new Response(html, {
          status: 200,
          headers: { 'X-Claire-Minitoken': miniToken },
        })
      }
      return new Response(String(input).includes('/auth/resource-token') ? capability() : '0', { status: 200 })
    })
    vi.stubGlobal('fetch', fetchMock)

    const element = await window.claireEmbed?.({
      baseUrl: 'https://claire.test',
      target: '#target',
      token: jwt('session'),
    })
    const input = element?.shadowRoot?.querySelector<HTMLTextAreaElement>(
      '.claire-chat-input__field',
    )
    const form = element?.shadowRoot?.querySelector<HTMLFormElement>('#claire-brain-chat')
    const upload = form?.querySelector<HTMLInputElement>('.claire-chat-input__file')
    const uploadButton = form?.querySelector<HTMLElement>('.claire-chat-icon-btn--upload')
    await vi.waitFor(() => expect(FakeEventSource.instances).toHaveLength(1))

    FakeEventSource.instances[0].emit('chat.assistant.start', { threadId: 'thread-1' })
    await Promise.resolve()

    expect(input?.disabled).toBe(true)
    expect(input?.placeholder).toBe('')
    expect(upload?.disabled).toBe(true)
    expect(uploadButton?.getAttribute('aria-disabled')).toBe('true')
    expect(Array.from(form?.querySelectorAll<HTMLButtonElement>('button') ?? [])
      .every((button) => button.disabled)).toBe(true)
    expect(element?.shadowRoot?.querySelector('.claire-global-action-indicator')).toBeNull()

    FakeEventSource.instances[0].emit('chat.assistant.done', {
      threadId: 'thread-1',
      messageId: 'assistant-1',
    })
    await Promise.resolve()

    expect(input?.disabled).toBe(false)
    expect(input?.placeholder).toBe('Message...')
    expect(input?.getAttribute('aria-label')).toBe('Votre message')
    expect(upload?.disabled).toBe(false)
    expect(uploadButton?.getAttribute('aria-disabled')).toBe('false')
  })

  it('requests assistant audio on demand and enables playback after SSE', async () => {
    const config = bootstrap()
    config.audioAvailable = true
    config.audioEnabled = true
    config.audioAutoGenerate = false
    config.audioVoice = 'fr_marie_neutral'
    config.audioVoices = [{ id: 'fr_marie_neutral', label: 'Marie — Neutre' }]
    const html = JSON.stringify(config)
    const miniToken = jwt('minitoken')
    const fetchMock = vi.fn(async (input: string | URL | Request, _init?: RequestInit) => {
      const url = String(input)
      if (url.includes('/embed')) {
        return new Response(html, {
          status: 200,
          headers: { 'X-Claire-Minitoken': miniToken },
        })
      }
      if (url.includes('/history/new')) return new Response(JSON.stringify({ threadId: 'thread-2', sessionId: 'session-2' }))
      return new Response(url.includes('/auth/resource-token') ? capability() : '0', { status: 200 })
    })
    let playCount = 0
    class AudioMock {
      public currentTime = 0
      public onended: (() => void) | null = null
      public onerror: (() => void) | null = null
      public src: string

      public constructor(src = '') { this.src = src }
      public play(): Promise<void> {
        playCount++
        return Promise.resolve()
      }
      public pause(): void {}
    }
    const NativeURL = URL
    class URLMock extends NativeURL {
      public static createObjectURL(): string { return 'blob:audio' }
      public static revokeObjectURL(): void {}
    }
    vi.stubGlobal('fetch', fetchMock)
    vi.stubGlobal('Audio', AudioMock)
    vi.stubGlobal('CSS', { escape: (value: string) => value })
    vi.stubGlobal('URL', URLMock)

    const element = await window.claireEmbed?.({
      baseUrl: 'https://claire.test',
      target: '#target',
      token: jwt('session'),
    })
    await vi.waitFor(() => expect(FakeEventSource.instances).toHaveLength(1))
    FakeEventSource.instances[0].emit('chat.snapshot', {
      threadId: 'thread-1',
      messages: [entry('history-1', 'Ancienne réponse')],
    })
    await Promise.resolve()
    await Promise.resolve()

    const historyButton = element?.shadowRoot?.querySelector<HTMLButtonElement>(
      '#claire-history-1 [data-audio-listen]',
    )
    expect(historyButton?.disabled).toBe(false)
    expect(historyButton?.title).toBe('Générer l’audio')
    expect(historyButton?.querySelector('svg')).not.toBeNull()

    FakeEventSource.instances[0].emit('chat.assistant.start', {
      threadId: 'thread-1', messageId: 'assistant-1',
    })
    FakeEventSource.instances[0].emit('chat.assistant.placeholder', {
      threadId: 'thread-1',
      messageId: 'assistant-1',
      entry: entry('assistant-1', 'Bonjour'),
    })
    await Promise.resolve()
    await Promise.resolve()

    const pendingButton = element?.shadowRoot?.querySelector<HTMLButtonElement>(
      '#claire-assistant-1 [data-audio-listen]',
    )
    expect(pendingButton).not.toBeNull()
    expect(pendingButton?.disabled).toBe(false)
    expect(pendingButton?.title).toBe('Générer l’audio')

    pendingButton?.click()
    await Promise.resolve()
    await Promise.resolve()

    expect(pendingButton?.disabled).toBe(true)
    expect(pendingButton?.title).toBe('Génération audio en cours')
    expect(fetchMock.mock.calls.some(([input]) => String(input).includes('/brain/audio')))
      .toBe(true)
    const audioRequest = fetchMock.mock.calls.find(([input]) => String(input).includes('/brain/audio'))!
    const audioRequestId = ((audioRequest[1] as RequestInit).body as URLSearchParams).get('audioRequestId')

    FakeEventSource.instances[0].emit('chat.assistant.done', {
      threadId: 'thread-1',
      messageId: 'assistant-1',
    })
    await Promise.resolve()
    await Promise.resolve()

    const finalMessages = [entry('assistant-1', 'Bonjour')]
    FakeEventSource.instances[0].emit('chat.snapshot', {
      threadId: 'thread-1', responding: false, activeMessageId: null, messages: finalMessages,
    })
    expect(element?.shadowRoot?.querySelector<HTMLButtonElement>('#claire-assistant-1 [data-audio-listen]')?.title)
      .toBe('Génération audio en cours')

    FakeEventSource.instances[0].emit('chat.audio.ready', {
      threadId: 'thread-1',
      messageId: 'assistant-1',
      audioRequestId,
      mimeType: 'audio/mpeg',
      audioData: btoa('mp3'),
    })
    await Promise.resolve()

    let button = element?.shadowRoot?.querySelector<HTMLButtonElement>(
      '#claire-assistant-1 [data-audio-listen]',
    )
    expect(button).not.toBeNull()
    expect(button?.disabled).toBe(false)
    expect(button?.title).toBe('Arrêter la lecture')
    expect(playCount).toBe(1)
    FakeEventSource.instances[0].emit('chat.snapshot', {
      threadId: 'thread-1', responding: false, activeMessageId: null, messages: finalMessages,
    })
    button = element?.shadowRoot?.querySelector<HTMLButtonElement>('#claire-assistant-1 [data-audio-listen]')
    expect(button?.title).toBe('Arrêter la lecture')
    FakeEventSource.instances[0].emit('chat.audio.ready', {
      threadId: 'thread-1', messageId: 'assistant-1', audioRequestId, mimeType: 'audio/mpeg', audioData: btoa('mp3'),
    })
    await Promise.resolve()
    expect(playCount).toBe(1)
    button?.click()
    await Promise.resolve()
    await Promise.resolve()
    button?.click()
    await Promise.resolve()
    await Promise.resolve()
    expect(playCount).toBe(2)
    expect(fetchMock.mock.calls.filter(([input]) => String(input).includes('/brain/audio'))).toHaveLength(1)

    expect(fetchMock.mock.calls.some(([input]) => String(input).includes('/v1/audio/speech')))
      .toBe(false)
    element?.shadowRoot?.querySelector<HTMLButtonElement>('[aria-label="Nouvelle conversation"]')?.click()
    await vi.waitFor(() => expect(FakeEventSource.instances).toHaveLength(2))
    FakeEventSource.instances[1].emit('chat.snapshot', {
      threadId: 'thread-2', responding: false, activeMessageId: null, messages: finalMessages,
    })
    await Promise.resolve()
    expect(element?.shadowRoot?.querySelector<HTMLButtonElement>('#claire-assistant-1 [data-audio-listen]')?.title)
      .toBe('Générer l’audio')
  })
})
