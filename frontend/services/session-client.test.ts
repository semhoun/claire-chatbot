// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { SessionClient } from './session-client'

function token(audience = 'session'): string {
  const payload = btoa(JSON.stringify({ aud: audience, exp: Math.floor(Date.now() / 1000) + 3600 }))
    .replace(/=/g, '')
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
  return `header.${payload}.signature`
}

describe('SessionClient', () => {
  beforeEach(() => sessionStorage.clear())
  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
  })

  it.each(['clear', 'destroy'] as const)('invalidates deferred HTTP on %s without restoring tokens or timers', async (action) => {
    vi.useFakeTimers()
    let resolve!: (response: Response) => void
    const fetchMock = vi.fn(() => new Promise<Response>((done) => { resolve = done }))
    vi.stubGlobal('fetch', fetchMock)
    const client = new SessionClient('https://claire.test', 120, 30)
    client.initialize(token())
    const pending = client.request('/slow')
    const rejected = expect(pending).rejects.toMatchObject({ name: 'AbortError' })
    client[action]()
    const stored = sessionStorage.getItem('claire_session_token')
    expect((fetchMock.mock.calls[0] as unknown as [string, RequestInit])[1].signal?.aborted).toBe(true)
    resolve(new Response(null, { headers: { 'X-Claire-Token': token(), 'X-Claire-Minitoken': token('minitoken') } }))
    await rejected
    // Flush jsdom's zero-delay storage events, not session refresh timers.
    await vi.advanceTimersByTimeAsync(0)
    expect(sessionStorage.getItem('claire_session_token')).toBe(stored)
    expect(sessionStorage.getItem('claire_mini_token')).toBeNull()
    expect(vi.getTimerCount()).toBe(0)
    if (action === 'destroy') {
      client.initialize(token())
      await expect(client.request('/later')).rejects.toMatchObject({ name: 'AbortError' })
      expect(client.getMiniToken()).toBeNull()
      expect(vi.getTimerCount()).toBe(0)
    } else {
      client.initialize(token())
      await vi.advanceTimersByTimeAsync(0)
      expect(vi.getTimerCount()).toBe(1)
    }
    client.destroy()
  })

  it.each(['clear', 'destroy'] as const)('does not retry a deferred refresh after %s', async (action) => {
    vi.useFakeTimers()
    let reject!: (error: Error) => void
    vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>((_, fail) => { reject = fail })))
    const client = new SessionClient('https://claire.test', 3600, 1)
    client.initialize(token())
    await vi.advanceTimersByTimeAsync(1000)
    client[action]()
    reject(new Error('network'))
    await vi.advanceTimersByTimeAsync(10000)
    expect(vi.getTimerCount()).toBe(0)
    client.destroy()
  })

  it('forwards caller cancellation and removes its listener after completion', async () => {
    const caller = new AbortController()
    const remove = vi.spyOn(caller.signal, 'removeEventListener')
    let resolve!: (response: Response) => void
    const fetchMock = vi.fn((_url: string, _init: RequestInit) => new Promise<Response>((done) => { resolve = done }))
    vi.stubGlobal('fetch', fetchMock)
    const client = new SessionClient('https://claire.test', 120, 30)
    const pending = client.request('/slow', { signal: caller.signal })
    const rejected = expect(pending).rejects.toMatchObject({ name: 'AbortError' })
    caller.abort()
    expect(fetchMock.mock.calls[0][1].signal?.aborted).toBe(true)
    resolve(new Response())
    await rejected
    expect(remove).toHaveBeenCalledWith('abort', expect.any(Function))
    client.destroy()
  })

  it('keeps only one refresh timer when another response arrives during a failed refresh', async () => {
    vi.useFakeTimers()
    let reject!: (error: Error) => void
    vi.stubGlobal('fetch', vi.fn((url: string) => url.endsWith('/auth/refresh')
      ? new Promise<Response>((_, fail) => { reject = fail })
      : Promise.resolve(new Response())))
    const client = new SessionClient('https://claire.test', 3600, 1)
    client.initialize(token())
    await vi.advanceTimersByTimeAsync(1000)
    await client.request('/other')
    reject(new Error('offline'))
    await vi.advanceTimersByTimeAsync(0)
    expect(vi.getTimerCount()).toBe(1)
    client.destroy()
    expect(vi.getTimerCount()).toBe(0)
  })

  it('adds the session header only to its own requests', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }))
    vi.stubGlobal('fetch', fetchMock)
    const client = new SessionClient('https://claire.test', 120, 30)
    client.initialize(token())

    await client.request('/history/count')

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(new Headers(init.headers).get('X-Claire-Auth')).toContain('header.')
    client.destroy()
  })

  it('adds the mini token only to protected file URLs', () => {
    const client = new SessionClient('https://claire.test', 120, 30)
    client.initialize(undefined, token('minitoken'))

    expect(client.protectedUrl('/files/serve/file-1')).toContain('token=')
    expect(client.protectedUrl('/history/list')).not.toContain('token=')
    client.destroy()
  })

  it('clears an expired server session after a rejected refresh', async () => {
    vi.useFakeTimers()
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 401 }))
    vi.stubGlobal('fetch', fetchMock)
    const client = new SessionClient('https://claire.test', 3590, 1)
    client.initialize(token())

    await vi.advanceTimersByTimeAsync(11000)

    expect(fetchMock).toHaveBeenCalledWith(
      'https://claire.test/auth/refresh',
      expect.objectContaining({
        headers: expect.any(Headers),
      }),
    )
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(new Headers(init.headers).get('Accept')).toBe('application/json')
    expect(sessionStorage.getItem('claire_session_token')).toBeNull()
    expect(sessionStorage.getItem('claire_mini_token')).toBeNull()
    client.destroy()
  })
})
