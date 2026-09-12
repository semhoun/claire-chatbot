// @vitest-environment jsdom
import { readFileSync } from 'node:fs'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { BrowserAudio } from './services/browser-audio'
import { flushPromises, mount } from '@vue/test-utils'
import ClaireApp from './components/ClaireApp.vue'
import type { ClaireBootstrap } from './types'

class FakeEventSource {
  public static instances: FakeEventSource[] = []
  public onerror: (() => void) | null = null
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
    brainInfo: { name: 'Claire', description: 'Assistant', avatar: '/avatar.png' },
    currentBrain: 'claire',
    brains: [{
      slug: 'claire',
      name: 'Claire',
      description: 'Assistant',
      avatar: '/avatar.png',
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
    const html = '<article id="claire-a"><span id="claire-message-a"><a class="claire-generated-file" href="/files/serve/a" target="_blank" rel="noopener">Open</a><a class="claire-generated-file" href="/files/serve/a" download="result.txt">Download</a><a class="claire-generated-file" href="https://external.test/files/serve/b">External</a></span></article>'
      + Array.from({ length: 15 }, (_, index) => `<img class="claire-generated-image" data-protected-src="/files/serve/image-${index}">`).join('')
      + '<audio class="claire-generated-audio" data-protected-src="/files/serve/audio"></audio>'
    try {
      await flushPromises()
      FakeEventSource.instances[0].emit('chat.snapshot', { html, responding: true, activeMessageId: 'a' })
      await flushPromises()
      expect(fileRequests).toBe(1)
      expect(wrapper.findAll<HTMLImageElement>('img.claire-generated-image').every(image => image.element.src.includes('token=file-1'))).toBe(true)
      expect(wrapper.find<HTMLAudioElement>('audio.claire-generated-audio').element.src).toContain('token=file-1')
      // Replacing fragments during streaming must only reuse the cached capability.
      for (let index = 0; index < 20; index++) {
        FakeEventSource.instances[0].emit('chat.assistant.update', {
          messageId: 'a', html: '<a class="claire-generated-file" href="/files/serve/a" target="_blank" rel="noopener">Open</a><a class="claire-generated-file" href="/files/serve/a" download="result.txt">Download</a>',
        })
        await flushPromises()
      }
      expect(fileRequests).toBe(1)
      await vi.advanceTimersByTimeAsync(294999)
      expect(fileRequests).toBe(1)
      await vi.advanceTimersByTimeAsync(1)
      expect(fileRequests).toBe(2)
      // Reproduce the SSE reconnect snapshot at 295s, then use the links after 300s.
      FakeEventSource.instances.at(-1)!.emit('chat.snapshot', { html })
      await flushPromises()
      expect(fileRequests).toBe(2)
      await vi.advanceTimersByTimeAsync(6000)
      const links = wrapper.findAll<HTMLAnchorElement>('a.claire-generated-file')
      expect(links[0].element.href).toContain('token=file-2')
      expect(links[1].element.href).toContain('token=file-2')
      expect(links[2].element.href).toBe('https://external.test/files/serve/b')
      expect(links[0].attributes('target')).toBe('_blank')
      expect(links[0].attributes('rel')).toBe('noopener')
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
    const html = '<a class="claire-generated-file" href="/files/serve/a" target="_blank">Open</a>'
    await flushPromises()
    FakeEventSource.instances[0].emit('chat.snapshot', { html })
    await flushPromises()
    const oldLink = wrapper.get<HTMLAnchorElement>('a.claire-generated-file').element
    const oldHref = oldLink.href
    const click = vi.spyOn(oldLink, 'click')
    await vi.advanceTimersByTimeAsync(295000)
    expect(fileRequests).toBe(2)
    if (action === 'navigate') {
      await wrapper.get('[aria-label="Nouvelle conversation"]').trigger('click')
      await flushPromises()
      FakeEventSource.instances.at(-1)!.emit('chat.snapshot', { html })
      await flushPromises()
      expect(wrapper.get<HTMLAnchorElement>('a.claire-generated-file').element.href).toContain('token=file-3')
    } else wrapper.unmount()
    release({ token: 'obsolete', expiresAt: Date.now() / 1000 + 300 })
    await flushPromises()
    expect(oldLink.href).toBe(oldHref)
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
    const html = `<div class="claire-embed-bootstrap" data-base-url="https://claire.test" data-bootstrap='${JSON.stringify(bootstrap())}'></div>`
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
      source.emit('chat.snapshot', { html: ['a', 'b'].map(id => `<article id="claire-${id}" class="claire-message claire-message--received"><span class="claire-message__text">Hello</span><span class="claire-message__meta"></span></article>`).join('') })
      source.emit('chat.snapshot', { audioRequestIds: { a: 'auto-a', b: 'auto-b' }, html: wrapper.get('#claire-a').element.outerHTML + wrapper.get('#claire-b').element.outerHTML })
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

  it('cancels renewal on error and cancels reconnect backoff on navigation', async () => {
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
      if (path === '/history/list') return new Response('<button data-history-open="/history/open/old">Old</button>')
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
      await wrapper.get('[data-history-open]').trigger('click')
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
      releaseDelete({ html: '<p>obsolete exchange</p>', removedMessage: 'obsolete draft' })
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
      const html = '<article id="claire-a" class="claire-message claire-message--received"><span class="claire-message__text">Hello</span><span class="claire-message__meta"></span></article>'
      source.emit('chat.snapshot', { html })
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
        source.emit('chat.snapshot', { html, audioRequestIds: { a: 'auto-a' } })
        source.emit('chat.audio.ready', { messageId: 'a', audioRequestId: 'auto-a', audioData: btoa('obsolete') })
        expect(wrapper.get('[data-audio-listen]').attributes('title')).toBe('Générer l’audio')
        expect(play).not.toHaveBeenCalled()
      }
      await wrapper.get('[data-audio-listen]').trigger('click')
      await flushPromises()
      const b = requests[1].id
      expect(b).not.toBe(a)
      source.emit('chat.snapshot', { html, audioRequestIds: { a: 'auto-a' } })
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
      source.emit('chat.snapshot', { html })
      await wrapper.get('[data-audio-listen]').trigger('click')
      await wrapper.get('[data-audio-listen]').trigger('click')
      expect(play.mock.calls[1][0]).toBe(cached)
      source.emit('chat.snapshot', { html: '' })
      source.emit('chat.snapshot', { html, audioRequestIds: { a: 'auto-a' } })
      source.emit('chat.audio.ready', { messageId: 'a', audioRequestId: b, audioData: btoa('late') })
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
      const html = '<article id="claire-a" class="claire-message claire-message--received"><span class="claire-message__text">Hello</span><span class="claire-message__meta"></span></article>'
      const ready = { messageId: 'a', audioRequestId: 'auto-a', audioData: btoa('mp3') }
      source.emit('chat.snapshot', { html })
      source.emit('chat.audio.ready', ready)
      expect(play).not.toHaveBeenCalled()
      expect(wrapper.get('[data-audio-listen]').attributes('title')).toBe('Générer l’audio')
      if (announcement === 'done') {
        source.emit('chat.assistant.start', { messageId: 'a' })
        source.emit('chat.assistant.done', { messageId: 'a', audioRequestId: 'auto-a' })
      } else source.emit('chat.snapshot', { html, audioRequestIds: { a: 'auto-a' } })
      expect(wrapper.get('[data-audio-listen]').attributes('title')).toBe(
        announcement === 'done' ? 'Génération audio en cours' : 'Générer l’audio',
      )
      source.emit('chat.audio.ready', ready)
      source.emit('chat.snapshot', { html, audioRequestIds: { a: 'auto-a' } })
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
        html: '<article id="claire-a" class="claire-message claire-message--received"><span class="claire-message__text">Hello</span><span class="claire-message__meta"></span></article>',
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
      source.emit('chat.snapshot', { html: '<article id="claire-a" class="claire-message claire-message--received"><span class="claire-message__text">Hello</span><span class="claire-message__meta"></span></article>' })
      await wrapper.get('[data-audio-listen]').trigger('click')
      if (action === 'delete') source.emit('chat.snapshot', { html: '' })
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

  it.each([[30, 25000], [600, 295000], [1, 500]])('renews a %ss capability after %sms without overlapping sources', async (ttl, renewalDelay) => {
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
    await vi.advanceTimersByTimeAsync(renewalDelay - 1)
    expect(FakeEventSource.instances).toHaveLength(1)
    expect(FakeEventSource.instances[0].closed).toBe(false)
    await vi.advanceTimersByTimeAsync(1)
    expect(FakeEventSource.instances[0].closed).toBe(true)
    expect(String(FakeEventSource.instances[1].url)).toContain('token=stream-2')
    expect(FakeEventSource.instances.filter(source => !source.closed)).toHaveLength(1)
    await vi.advanceTimersByTimeAsync(renewalDelay)
    wrapper.unmount()
    release(new Response(capability()))
    await flushPromises()
    expect(FakeEventSource.instances).toHaveLength(2)
    expect(vi.getTimerCount()).toBe(0)
  })

  beforeEach(async () => {
    document.body.innerHTML = '<div id="target"></div>'
    sessionStorage.clear()
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

  it.each(['exchange', 'bootstrap', 'body', 'css'])('never remounts after destroy during %s', async (stage) => {
    const config = bootstrap()
    config.brainInfo.css = 'agent.css'
    const html = `<div class="claire-embed-bootstrap" data-base-url="https://claire.test" data-bootstrap='${JSON.stringify(config)}'></div>`
    let release!: () => void
    let reached!: () => void
    const waiting = new Promise<void>((resolve) => { reached = resolve })
    const deferred = new Promise<void>((resolve) => { release = resolve })
    let signal: AbortSignal | null | undefined
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL, init: RequestInit) => {
      const url = String(input)
      const current = url.includes('/exchange') ? 'exchange' : url.includes('/css/') ? 'css' : 'bootstrap'
      if (current === stage || (stage === 'body' && current === 'bootstrap')) {
        signal = init.signal
        reached()
        if (stage !== 'body') await deferred
      }
      if (current === 'exchange') return new Response(JSON.stringify({ session_token: jwt('session') }))
      if (current === 'css') return new Response('.agent {}')
      const response = new Response(html)
      if (stage === 'body') vi.spyOn(response, 'text').mockImplementation(async () => { await deferred; return html })
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
    const html = `<div class="claire-embed-bootstrap" data-base-url="https://claire.test" data-bootstrap='${JSON.stringify(bootstrap())}'></div>`
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
    const html = `<div class="claire-embed-bootstrap" data-base-url="https://claire.test" data-bootstrap='${JSON.stringify(bootstrap())}'></div>`
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
      html: '<article id="claire-new"><span id="claire-message-new">Partial</span></article><article id="claire-old"><span id="claire-message-old">Old</span></article>',
    })
    await Promise.resolve()
    expect(input.disabled).toBe(true)
    oldSource.emit('chat.snapshot', { responding: false, activeMessageId: null, html: '' })
    oldSource.onerror?.()
    source.emit('chat.assistant.update', { messageId: 'old', html: 'Stale' })
    source.emit('chat.assistant.done', { messageId: 'old' })
    source.emit('chat.error', { messageId: 'old', message: 'Obsolete failure' })
    await Promise.resolve()
    expect(input.disabled).toBe(true)
    expect(element.shadowRoot!.querySelector('#claire-message-old')?.textContent).toBe('Old')
    expect(element.shadowRoot!.textContent).not.toContain('Obsolete failure')
    source.emit('chat.assistant.update', { messageId: 'new', html: '<strong>Current</strong>' })
    expect(element.shadowRoot!.querySelector('#claire-message-new strong')?.textContent).toBe('Current')
    source.emit('chat.assistant.done', { messageId: 'new' })
    await Promise.resolve()
    expect(input.disabled).toBe(false)
    source.emit('chat.snapshot', { responding: false, activeMessageId: null, html: '<span id="claire-message-new">Final</span>' })
    source.emit('chat.assistant.update', { messageId: 'new', html: 'Late' })
    expect(element.shadowRoot!.querySelector('#claire-message-new')?.textContent).toBe('Final')
    source.emit('chat.assistant.start', { messageId: 'another' })
    source.emit('chat.snapshot', { responding: false, activeMessageId: null, html: '' })
    await Promise.resolve()
    expect(input.disabled).toBe(false)
    source.emit('chat.assistant.start', { messageId: 'failed' })
    source.emit('chat.error', { messageId: 'failed', message: 'Current failure' })
    source.emit('chat.snapshot', { responding: false, activeMessageId: null, html: '' })
    await Promise.resolve()
    expect(element.shadowRoot!.textContent).toContain('Current failure')
  })

  it('allows a new dictation after stopping while microphone permission is pending', async () => {
    const config = bootstrap()
    config.audioAvailable = true
    config.audioEnabled = true
    const html = `<div class="claire-embed-bootstrap" data-base-url="https://claire.test" data-bootstrap='${JSON.stringify(config)}'></div>`
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
    const html = `<div class="claire-embed-bootstrap" data-base-url="https://claire.test" data-bootstrap='${JSON.stringify(config)}'></div>`
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => new Response(
      String(input).includes('/embed') ? html : String(input).includes('/auth/resource-token') ? capability() : '0',
      { headers: { 'X-Claire-Minitoken': jwt('minitoken') } },
    )))
    const element = await window.claireEmbed!({ baseUrl: 'https://claire.test' })
    await vi.waitFor(() => expect(FakeEventSource.instances).toHaveLength(1))
    const source = FakeEventSource.instances[0]
    const setAttribute = vi.spyOn(Element.prototype, 'setAttribute')
    const articles = Array.from({ length: 100 }, (_, index) => `<article id="claire-${index}" class="claire-message--received"><span id="claire-message-${index}">Server HTML</span><span class="claire-message__meta"></span></article>`).join('')
    source.emit('chat.snapshot', { html: articles, responding: true, activeMessageId: '99' })
    await Promise.resolve()
    const audioWrites = () => setAttribute.mock.calls.filter(([name, value]) => name === 'aria-label' && value === 'Générer l’audio').length
    expect(audioWrites()).toBe(100)
    expect(element.shadowRoot!.querySelectorAll('[data-audio-listen]')).toHaveLength(100)
    setAttribute.mockClear()
    source.emit('chat.assistant.update', { messageId: '99', html: '<em>Stream HTML</em>' })
    await Promise.resolve()
    expect(audioWrites()).toBe(1)
    expect(element.shadowRoot!.querySelector('#claire-message-99 em')?.textContent).toBe('Stream HTML')
    expect(element.shadowRoot!.querySelector('#claire-message-0')?.textContent).toBe('Server HTML')
  })

  it('mounts in Shadow DOM and cleans up its SSE connection', async () => {
    const config = bootstrap()
    const html = `<div class="claire-embed-bootstrap" data-base-url="https://claire.test" data-bootstrap='${JSON.stringify(config)}'></div>`
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
    const html = `<div class="claire-embed-bootstrap" data-base-url="https://claire.test" data-bootstrap='${JSON.stringify(config)}'></div>`
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
    expect(input?.placeholder).toBe('Écrivez votre message...')
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
    const html = `<div class="claire-embed-bootstrap" data-base-url="https://claire.test" data-bootstrap='${JSON.stringify(config)}'></div>`
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
      html: '<article id="claire-history-1" class="claire-message claire-message--received"><div class="claire-message__bubble"><span class="claire-message__text">Ancienne réponse</span></div><span class="claire-message__meta"></span></article>',
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
      html: '<article id="claire-assistant-1" class="claire-message claire-message--received"><div class="claire-message__bubble"><span id="claire-message-assistant-1" class="claire-message__text">Bonjour</span></div><span class="claire-message__meta"></span></article>',
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

    const finalHtml = '<article id="claire-assistant-1" class="claire-message claire-message--received"><div class="claire-message__bubble"><span id="claire-message-assistant-1" class="claire-message__text">Bonjour</span></div><span class="claire-message__meta"></span></article>'
    FakeEventSource.instances[0].emit('chat.snapshot', {
      threadId: 'thread-1', responding: false, activeMessageId: null, html: finalHtml,
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
      threadId: 'thread-1', responding: false, activeMessageId: null, html: finalHtml,
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
      threadId: 'thread-2', responding: false, activeMessageId: null, html: finalHtml,
    })
    expect(element?.shadowRoot?.querySelector<HTMLButtonElement>('#claire-assistant-1 [data-audio-listen]')?.title)
      .toBe('Générer l’audio')
  })
})
