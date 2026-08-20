// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { BrowserAudio } from './browser-audio'
import { SessionClient } from './session-client'

describe('BrowserAudio', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
  })

  it('sends an OpenAI-compatible multipart transcription request', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(
      JSON.stringify({ text: 'Bonjour Claire' }),
      { status: 200, headers: { 'Content-Type': 'application/json' } },
    ))
    vi.stubGlobal('fetch', fetchMock)
    const client = new SessionClient('https://claire.test', 120, 30)
    const audio = new BrowserAudio(client, 300)

    const text = await audio.transcribe(
      new Blob(['audio'], { type: 'audio/webm' }),
      'audio/webm',
      'voxtral-mini-latest',
    )

    expect(text).toBe('Bonjour Claire')
    expect(fetchMock.mock.calls[0][0]).toBe('https://claire.test/v1/audio/transcriptions')
    const body = (fetchMock.mock.calls[0][1] as RequestInit).body as FormData
    expect(body.get('model')).toBe('voxtral-mini-latest')
    expect(body.get('response_format')).toBe('json')
    expect(body.get('file')).toBeInstanceOf(File)
    audio.destroy()
    client.destroy()
  })

  it('reports a rejected microphone permission', async () => {
    Object.defineProperty(navigator, 'mediaDevices', {
      configurable: true,
      value: { getUserMedia: vi.fn().mockRejectedValue(new Error('denied')) },
    })
    vi.stubGlobal('MediaRecorder', class {
      public static isTypeSupported(): boolean { return true }
    })
    const client = new SessionClient('', 120, 30)
    const audio = new BrowserAudio(client, 300)

    await expect(audio.startRecording(() => {})).rejects.toThrow('denied')
    audio.destroy()
    client.destroy()
  })

  it('unlocks playback before waiting for speech generation', async () => {
    const events: string[] = []
    const play = vi.fn()
      .mockImplementationOnce(() => {
        events.push('play')
        return Promise.reject(new Error('unlock rejected'))
      })
      .mockImplementation(() => {
        events.push('play')
        return Promise.resolve()
      })
    const pause = vi.fn()
    class AudioMock {
      public currentTime = 0
      public onended: (() => void) | null = null
      public onerror: (() => void) | null = null
      public src: string

      public constructor(src = '') {
        this.src = src
        events.push(`audio:${src.startsWith('data:audio/wav') ? 'unlock' : 'other'}`)
      }

      public play = play
      public pause = pause
    }
    vi.stubGlobal('Audio', AudioMock)
    vi.stubGlobal('fetch', vi.fn().mockImplementation(async () => {
      events.push('fetch')
      return new Response(new Blob(['mp3']), {
        status: 200,
        headers: { 'Content-Type': 'audio/mpeg' },
      })
    }))
    vi.stubGlobal('URL', {
      createObjectURL: vi.fn().mockReturnValue('blob:audio'),
      revokeObjectURL: vi.fn(),
    })
    const client = new SessionClient('https://claire.test', 120, 30)
    const audio = new BrowserAudio(client, 300)

    await audio.play('Bonjour', 'voxtral-mini-tts-latest', 'fr_marie_neutral', () => {})

    expect(events.slice(0, 3)).toEqual(['audio:unlock', 'play', 'fetch'])
    expect(play).toHaveBeenCalledTimes(2)
    audio.destroy()
    client.destroy()
  })

  it('does not report an error when playback ends or is stopped', async () => {
    const players: AudioMock[] = []
    class AudioMock {
      public currentTime = 0
      public onended: (() => void) | null = null
      public onerror: (() => void) | null = null
      private source: string

      public constructor(src = '') {
        this.source = src
        players.push(this)
      }

      public get src(): string { return this.source }

      public set src(value: string) {
        this.source = value
        if (value === '') this.onerror?.()
      }

      public play(): Promise<void> { return Promise.resolve() }
      public pause(): void {}
    }
    vi.stubGlobal('Audio', AudioMock)
    vi.stubGlobal('URL', {
      createObjectURL: vi.fn().mockReturnValue('blob:audio'),
      revokeObjectURL: vi.fn(),
    })
    const client = new SessionClient('', 120, 30)
    const audio = new BrowserAudio(client, 300)
    const onEnded = vi.fn()

    await audio.playReady(new Blob(['mp3']), onEnded)
    players[0].onended?.()

    expect(onEnded).toHaveBeenCalledOnce()
    expect(onEnded).toHaveBeenCalledWith()

    await audio.playReady(new Blob(['mp3']), onEnded)
    audio.stopPlayback()

    expect(onEnded).toHaveBeenCalledOnce()
    audio.destroy()
    client.destroy()
  })
})
