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
