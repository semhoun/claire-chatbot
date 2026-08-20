// @vitest-environment jsdom
import { readFileSync } from 'node:fs'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
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
    await import('./embed')
  })

  afterEach(async () => {
    window.destroyClaireEmbed?.()
    await Promise.resolve()
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
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

    pendingButton?.remove()

    FakeEventSource.instances[0].emit('chat.audio.ready', {
      threadId: 'thread-1',
      messageId: 'assistant-1',
      mimeType: 'audio/mpeg',
      audioData: btoa('mp3'),
    })
    await Promise.resolve()

    const button = element?.shadowRoot?.querySelector<HTMLButtonElement>(
      '#claire-assistant-1 [data-audio-listen]',
    )
    expect(button).not.toBeNull()
    expect(button?.disabled).toBe(false)
    expect(button?.title).toBe('Arrêter la lecture')
    expect(playCount).toBe(1)
    button?.click()
    await Promise.resolve()
    await Promise.resolve()

    expect(fetchMock.mock.calls.some(([input]) => String(input).includes('/v1/audio/speech')))
      .toBe(false)
  })
})
