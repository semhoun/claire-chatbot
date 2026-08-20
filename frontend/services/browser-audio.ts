import { SessionClient } from './session-client'

type RecordingCallback = (audio: Blob, mediaType: string) => void | Promise<void>
type PlaybackCallback = (error?: Error) => void

const PLAYBACK_UNLOCK_AUDIO =
  'data:audio/wav;base64,UklGRigAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQQAAACAgICA'

export class BrowserAudio {
  private recorder: MediaRecorder | null = null
  private stream: MediaStream | null = null
  private chunks: Blob[] = []
  private recordingTimer: number | null = null
  private cancelled = false
  private player: HTMLAudioElement | null = null
  private objectUrl: string | null = null

  public constructor(
    private readonly client: SessionClient,
    private readonly maxRecordingSeconds: number,
  ) {}

  public supported(): boolean {
    return typeof navigator.mediaDevices?.getUserMedia === 'function'
      && typeof MediaRecorder !== 'undefined'
  }

  public async startRecording(onFinished: RecordingCallback): Promise<void> {
    if (!this.supported()) throw new Error('Audio recording is not supported')
    this.cancelRecording()
    this.stream = await navigator.mediaDevices.getUserMedia({ audio: true })
    const mediaType = this.preferredMediaType()
    this.recorder = mediaType === ''
      ? new MediaRecorder(this.stream)
      : new MediaRecorder(this.stream, { mimeType: mediaType })
    this.chunks = []
    this.cancelled = false
    this.recorder.ondataavailable = (event) => {
      if (event.data.size > 0) this.chunks.push(event.data)
    }
    this.recorder.onstop = () => {
      const actualType = this.recorder?.mimeType || mediaType || 'audio/webm'
      const audio = new Blob(this.chunks, { type: actualType })
      const cancelled = this.cancelled
      this.cleanupRecording()
      if (!cancelled && audio.size > 0) void onFinished(audio, actualType)
    }
    this.recorder.start()
    this.recordingTimer = window.setTimeout(
      () => this.stopRecording(),
      Math.max(1, this.maxRecordingSeconds) * 1000,
    )
  }

  public stopRecording(): void {
    if (this.recorder?.state === 'recording') this.recorder.stop()
  }

  public cancelRecording(): void {
    this.cancelled = true
    if (this.recorder?.state === 'recording') this.recorder.stop()
    else this.cleanupRecording()
  }

  public async transcribe(audio: Blob, mediaType: string, model: string): Promise<string> {
    const extension = mediaType.includes('ogg') ? 'ogg' : 'webm'
    const data = new FormData()
    data.set('file', audio, `recording.${extension}`)
    data.set('model', model)
    data.set('response_format', 'json')
    const response = await this.client.request('/v1/audio/transcriptions', {
      method: 'POST',
      body: data,
    })
    if (!response.ok) throw new Error(`Audio transcription failed with status ${response.status}`)
    const payload = await response.json() as { text?: unknown }
    if (typeof payload.text !== 'string' || payload.text.trim() === '') {
      throw new Error('Audio transcription is empty')
    }
    return payload.text.trim()
  }

  public async play(
    input: string,
    model: string,
    voice: string,
    onEnded: PlaybackCallback,
  ): Promise<void> {
    this.stopPlayback()
    const player = new Audio(PLAYBACK_UNLOCK_AUDIO)
    this.player = player

    void player.play()
      .then(() => {
        if (this.player === player && player.src === PLAYBACK_UNLOCK_AUDIO) {
          player.pause()
          player.currentTime = 0
        }
      })
      .catch(() => {
        // Some browsers reject data-URI playback. The real playback below may
        // still be allowed, so this optimization must never block the request.
      })

    const response = await this.client.request('/v1/audio/speech', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ input, model, voice, response_format: 'mp3' }),
    })
    if (!response.ok) throw new Error(`Speech generation failed with status ${response.status}`)

    const audio = await response.blob()
    if (this.player !== player) return

    this.objectUrl = URL.createObjectURL(audio)
    player.src = this.objectUrl
    player.onended = () => {
      this.stopPlayback()
      onEnded()
    }
    player.onerror = () => {
      this.stopPlayback()
      onEnded(new Error('The browser could not decode the generated audio'))
    }
    await player.play()
  }

  public async playReady(audio: Blob, onEnded: PlaybackCallback): Promise<void> {
    this.stopPlayback()
    this.objectUrl = URL.createObjectURL(audio)
    const player = new Audio(this.objectUrl)
    this.player = player
    player.onended = () => {
      this.stopPlayback()
      onEnded()
    }
    player.onerror = () => {
      this.stopPlayback()
      onEnded(new Error('The browser could not decode the generated audio'))
    }
    await player.play()
  }

  public stopPlayback(): void {
    if (this.player !== null) {
      const player = this.player
      this.player = null
      player.onended = null
      player.onerror = null
      player.pause()
      player.src = ''
    }
    if (this.objectUrl !== null) {
      URL.revokeObjectURL(this.objectUrl)
      this.objectUrl = null
    }
  }

  public destroy(): void {
    this.cancelRecording()
    this.stopPlayback()
  }

  private preferredMediaType(): string {
    for (const mediaType of ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/webm']) {
      if (MediaRecorder.isTypeSupported(mediaType)) return mediaType
    }
    return ''
  }

  private cleanupRecording(): void {
    if (this.recordingTimer !== null) window.clearTimeout(this.recordingTimer)
    this.recordingTimer = null
    for (const track of this.stream?.getTracks() ?? []) track.stop()
    this.stream = null
    this.recorder = null
    this.chunks = []
  }
}
