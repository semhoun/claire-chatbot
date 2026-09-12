import type { SessionClient } from './session-client'

export function protectAudio(audio: HTMLAudioElement, client: SessionClient, isCurrent: () => boolean) {
  const path = audio.dataset.protectedSrc ?? ''
  let disposed = false
  let timer: number | null = null
  let pending: Promise<boolean> | null = null
  let renewAt = 0
  let playVersion = 0
  let wanted = false
  let renewingPause = false
  let restorePosition: (() => void) | null = null
  const current = () => !disposed && isCurrent() && audio.dataset.protectedSrc === path
  const schedule = (delay: number) => {
    if (timer !== null) window.clearTimeout(timer)
    if (current()) timer = window.setTimeout(() => { timer = null; void refresh() }, Math.max(1, delay))
  }

  function refresh(): Promise<boolean> {
    if (pending) return pending
    if (!current()) return Promise.resolve(false)
    pending = client.protectedResource(path).then(resource => {
      if (!current()) return false
      // Renew the capability while playing, but defer replacing src until playback stops.
      if (audio.paused || audio.ended) {
        if (audio.src !== resource.url) {
          const position = audio.ended ? 0 : audio.currentTime
          if (restorePosition) audio.removeEventListener('loadedmetadata', restorePosition)
          restorePosition = () => {
            if (!current()) return
            audio.currentTime = Number.isFinite(audio.duration) ? Math.min(position, audio.duration) : position
          }
          audio.addEventListener('loadedmetadata', restorePosition, { once: true })
          audio.src = resource.url
        }
        renewAt = resource.renewAt ?? Infinity
      }
      if (resource.renewAt !== null) schedule(resource.renewAt - Date.now())
      return true
    }).catch(() => {
      if (current()) schedule(5000)
      return false
    }).finally(() => { pending = null })
    return pending
  }

  function play(): void {
    wanted = true
    if (renewAt > Date.now()) return
    const version = ++playVersion
    // Timers may have been throttled in a background tab. Reauthorize a new play
    // attempt before resuming, rather than asking the browser to use an expired URL.
    renewingPause = !audio.paused
    audio.pause()
    void refresh().then(ok => {
      if (ok && current() && wanted && version === playVersion) void audio.play().catch(() => {})
    })
  }
  function pause(): void {
    if (renewingPause) { renewingPause = false; return }
    wanted = false
    playVersion++
    void refresh()
  }
  function failed(): void {
    // A range request may fail after expiry during a long playback. Only then recover it.
    if (renewAt <= Date.now()) {
      if (wanted) play()
      else void refresh()
    }
  }
  audio.addEventListener('play', play)
  audio.addEventListener('pause', pause)
  audio.addEventListener('ended', pause)
  audio.addEventListener('error', failed)
  return {
    path,
    refresh,
    dispose() {
      disposed = true
      playVersion++
      if (timer !== null) window.clearTimeout(timer)
      if (restorePosition) audio.removeEventListener('loadedmetadata', restorePosition)
      audio.removeEventListener('play', play)
      audio.removeEventListener('pause', pause)
      audio.removeEventListener('ended', pause)
      audio.removeEventListener('error', failed)
      if (!audio.paused) audio.pause()
    },
  }
}
