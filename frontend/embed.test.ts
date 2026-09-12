// @vitest-environment jsdom
import { readFileSync } from 'node:fs'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { BrowserAudio } from './services/browser-audio'
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
      String(input).includes('/embed') ? html : '0',
      { headers: { 'X-Claire-Minitoken': jwt('minitoken') } },
    )))
    const element = await window.claireEmbed!({ baseUrl: 'https://claire.test' })
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
      String(input).includes('/embed') ? html : '0',
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
      String(input).includes('/embed') ? html : '0',
      { headers: { 'X-Claire-Minitoken': jwt('minitoken') } },
    )))
    const element = await window.claireEmbed!({ baseUrl: 'https://claire.test' })
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
      return new Response('0', { status: 200 })
    })
    vi.stubGlobal('fetch', fetchMock)

    const element = await window.claireEmbed?.({
      baseUrl: 'https://claire.test',
      target: '#target',
      token: miniToken,
    })
    await Promise.resolve()

    expect(String(fetchMock.mock.calls[0][0])).toContain(
      `/embed?token=${encodeURIComponent(miniToken)}`,
    )
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
      return new Response('0', { status: 200 })
    })
    vi.stubGlobal('fetch', fetchMock)

    const element = await window.claireEmbed?.({
      baseUrl: 'https://claire.test',
      target: '#target',
      token: miniToken,
    })
    const input = element?.shadowRoot?.querySelector<HTMLTextAreaElement>(
      '.claire-chat-input__field',
    )
    const form = element?.shadowRoot?.querySelector<HTMLFormElement>('#claire-brain-chat')
    const upload = form?.querySelector<HTMLInputElement>('.claire-chat-input__file')
    const uploadButton = form?.querySelector<HTMLElement>('.claire-chat-icon-btn--upload')

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
    const fetchMock = vi.fn(async (input: string | URL | Request) => {
      const url = String(input)
      if (url.includes('/embed')) {
        return new Response(html, {
          status: 200,
          headers: { 'X-Claire-Minitoken': miniToken },
        })
      }
      if (url.includes('/history/new')) return new Response(JSON.stringify({ threadId: 'thread-2', sessionId: 'session-2' }))
      return new Response('0', { status: 200 })
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
      token: miniToken,
    })
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
      threadId: 'thread-1', messageId: 'assistant-1', mimeType: 'audio/mpeg', audioData: btoa('mp3'),
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
