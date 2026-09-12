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
  it('shares one bounded capability across 17 files and a stream, including renewal', async () => {
    vi.useFakeTimers()
    const fetchMock = vi.fn(async (_url: string, _init: RequestInit) => new Response(JSON.stringify({
      token: `batch-${fetchMock.mock.calls.length}`, expiresAt: Date.now() / 1000 + 10,
    })))
    vi.stubGlobal('fetch', fetchMock)
    const client = new SessionClient('https://claire.test', 120, 30)
    try {
      const files = Array.from({ length: 17 }, (_, index) => `/files/serve/file-${index}`)
      const stream = { type: 'stream' as const, threadId: 'thread', sessionId: 'tab' }
      const [urls, capability] = await Promise.all([
        Promise.all(files.map(path => client.protectedResource(path))), client.resourceToken(stream),
      ])
      expect(fetchMock).toHaveBeenCalledOnce()
      expect(JSON.parse(fetchMock.mock.calls[0][1].body as string).resources).toHaveLength(18)
      expect(urls.every(({ url }) => new URL(url).searchParams.get('token') === capability.token)).toBe(true)
      expect((await client.resourceToken(stream)).token).toBe(capability.token)
      await vi.advanceTimersByTimeAsync(5000)
      const renewed = await client.protectedResource(files[0])
      expect(fetchMock).toHaveBeenCalledTimes(2)
      for (const path of files) expect((await client.protectedResource(path)).url).toContain('token=batch-2')
      expect(renewed.url).toContain('token=batch-2')
      expect((await client.resourceToken(stream)).token).toBe('batch-2')
      expect(fetchMock).toHaveBeenCalledTimes(2)
    } finally { client.destroy() }
  })

  it('splits large batches and retries failed batches without retaining rejected promises', async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ token: 'batch', expiresAt: Date.now() / 1000 + 60 })))
    vi.stubGlobal('fetch', fetchMock)
    const client = new SessionClient('https://claire.test', 120, 30)
    try {
      await Promise.all(Array.from({ length: 33 }, (_, index) => client.protectedResource(`/files/serve/${index}`)))
      expect(fetchMock).toHaveBeenCalledTimes(2)
      client.invalidateResources()
      fetchMock.mockResolvedValueOnce(new Response(null, { status: 404 }))
      const failed = await Promise.allSettled(['a', 'b'].map(id => client.protectedResource(`/files/serve/${id}`)))
      expect(failed.every(result => result.status === 'rejected')).toBe(true)
      await client.protectedResource('/files/serve/a')
      expect(fetchMock).toHaveBeenCalledTimes(4)
    } finally { client.destroy() }
  })

  it('bounds serialized claims even for long identifiers', async () => {
    const fetchMock = vi.fn(async (_url: string, init: RequestInit) => {
      expect(JSON.stringify(JSON.parse(init.body as string).resources).length).toBeLessThanOrEqual(4000)
      return new Response(JSON.stringify({ token: 'batch', expiresAt: Date.now() / 1000 + 60 }))
    })
    vi.stubGlobal('fetch', fetchMock)
    const client = new SessionClient('https://claire.test', 120, 30)
    try {
      await Promise.all(Array.from({ length: 17 }, (_, index) =>
        client.protectedResource(`/files/serve/${'a'.repeat(250)}${index}`)))
      expect(fetchMock).toHaveBeenCalledTimes(2)
    } finally { client.destroy() }
  })

  it('discards queued scopes before dispatch when the context changes', async () => {
    const fetchMock = vi.fn(async (_url: string, _init: RequestInit) => new Response(JSON.stringify({
      token: 'new', expiresAt: Date.now() / 1000 + 60,
    })))
    vi.stubGlobal('fetch', fetchMock)
    const client = new SessionClient('https://claire.test', 120, 30)
    try {
      const old = client.protectedResource('/files/serve/old')
      const rejected = expect(old).rejects.toMatchObject({ name: 'AbortError' })
      client.invalidateResources()
      await client.protectedResource('/files/serve/new')
      await rejected
      expect(fetchMock).toHaveBeenCalledOnce()
      expect(JSON.parse(fetchMock.mock.calls[0][1].body as string)).toEqual({
        resources: [{ type: 'file', fileId: 'new' }],
      })
    } finally { client.destroy() }
  })

  it.each(['@@GENERATED@@artifact@@', '%40%40GENERATED%40%40artifact%40%40'])(
    'canonicalizes generated file identifiers for resource authorization: %s', async encodedId => {
      const fetchMock = vi.fn(async (_input: string, _init: RequestInit) => new Response(JSON.stringify({
        token: 'file-only', expiresAt: Math.floor(Date.now() / 1000) + 60,
      })))
      vi.stubGlobal('fetch', fetchMock)
      const client = new SessionClient('https://claire.test', 120, 30)
      client.initialize(token())
      try {
        const resource = await client.protectedResource(`/files/serve/${encodedId}`)
        const url = new URL(resource.url)
        expect(url.pathname).toBe('/files/serve/%40%40GENERATED%40%40artifact%40%40')
        expect(url.searchParams.get('token')).toBe('file-only')
        expect(JSON.parse(fetchMock.mock.calls[0][1].body as string))
          .toEqual({ resources: [{ type: 'file', fileId: '@@GENERATED@@artifact@@' }] })
      } finally { client.destroy() }
    },
  )

  it('does not mint a capability for additional path segments', async () => {
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
    const client = new SessionClient('https://claire.test', 120, 30)
    try {
      expect(await client.protectedResource('/files/serve/file-1/unrelated'))
        .toEqual({ url: 'https://claire.test/files/serve/file-1/unrelated', renewAt: null })
      expect(fetchMock).not.toHaveBeenCalled()
    } finally { client.destroy() }
  })

  it('purges stored legacy mini-tokens and ignores legacy response headers', async () => {
    sessionStorage.setItem('claire_mini_token', JSON.stringify({ token: token('minitoken'), expiresAt: Date.now() + 60000 }))
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { headers: { 'X-Claire-Minitoken': token('minitoken') } })))
    const client = new SessionClient('https://claire.test', 120, 30)
    client.initialize(token())
    expect(sessionStorage.getItem('claire_mini_token')).toBeNull()
    await client.request('/history/count')
    expect(sessionStorage.getItem('claire_mini_token')).toBeNull()
    client.destroy()
  })

  it('does not capture token headers from a request started before a setting mutation', async () => {
    let release!: (response: Response) => void
    const updated = token().replace('signature', 'setting')
    vi.stubGlobal('fetch', vi.fn(async (url: string) => url.endsWith('/slow')
      ? new Promise<Response>(resolve => { release = resolve })
      : new Response(null, { headers: { 'X-Claire-Token': updated } })))
    const client = new SessionClient('https://claire.test', 120, 30)
    client.initialize(token())
    const pending = client.request('/slow')
    await client.request('/config/audio', { method: 'POST' })
    release(new Response(null, { headers: { 'X-Claire-Token': token() } }))
    await pending
    expect(JSON.parse(sessionStorage.getItem('claire_session_token')!).token).toBe(updated)
    client.destroy()
  })

  it.each([
    { token: '', expiresAt: 9999999999 },
    { token: 'scoped', expiresAt: 0 },
    { token: 'scoped', expiresAt: '9999999999' },
    { token: 'scoped', expiresAt: null },
  ])('rejects malformed or expired resource capabilities: %j', async (payload) => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify(payload))))
    const client = new SessionClient('https://claire.test', 120, 30)
    await expect(client.protectedResource('/files/serve/a')).rejects.toThrow('Invalid resource capability')
    client.destroy()
  })

  it.each([200, 401, 403])('ignores obsolete refresh status %s after session replacement', async (status) => {
    let resolve!: (value: Response) => void
    vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>(done => { resolve = done })))
    const client = new SessionClient('https://claire.test', 120, 30)
    client.initialize(token())
    const pending = client.request('/auth/refresh')
    await Promise.resolve()
    const replacement = token().replace('signature', 'replacement')
    client.initialize(replacement)
    resolve(new Response(null, { status, headers: { 'X-Claire-Token': token() } }))
    await pending
    expect(JSON.parse(sessionStorage.getItem('claire_session_token')!).token).toBe(replacement)
    client.destroy()
  })

  it('serializes settings with refresh and sends the latest JWT to each mutation', async () => {
    const releases: Array<(value: Response) => void> = []
    const fetchMock = vi.fn(() => new Promise<Response>(resolve => releases.push(resolve)))
    vi.stubGlobal('fetch', fetchMock)
    const client = new SessionClient('https://claire.test', 120, 30)
    client.initialize(token())
    const refresh = client.request('/auth/refresh')
    const first = client.request('/config/audio', { method: 'POST' })
    const second = client.request('/config/layout_mode', { method: 'POST' })
    await Promise.resolve()
    expect(fetchMock).toHaveBeenCalledTimes(1)
    const refreshed = token().replace('signature', 'refreshed')
    releases[0](new Response(null, { headers: { 'X-Claire-Token': refreshed } }))
    await refresh
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))
    const audioToken = token().replace('signature', 'audio')
    releases[1](new Response(null, { headers: { 'X-Claire-Token': audioToken } }))
    await first
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3))
    const calls = fetchMock.mock.calls as unknown as Array<[string, RequestInit]>
    expect(new Headers(calls[1][1].headers).get('X-Claire-Auth')).toBe(refreshed)
    expect(new Headers(calls[2][1].headers).get('X-Claire-Auth')).toBe(audioToken)
    releases[2](new Response())
    await second
    client.destroy()
  })

  it('rejects external authenticated requests and mini-token login', async () => {
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
    const client = new SessionClient('https://claire.test', 120, 30)
    expect(() => client.initialize(token('minitoken'))).toThrow('mini-token')
    await expect(client.request('//external.test/private')).rejects.toThrow('Claire origin')
    expect(fetchMock).not.toHaveBeenCalled()
    client.destroy()
  })

  it('shares early file renewal and invalidates cached capabilities on context changes', async () => {
    vi.useFakeTimers()
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ token: `scoped-${fetchMock.mock.calls.length}`, expiresAt: Date.now() / 1000 + 10 })))
    vi.stubGlobal('fetch', fetchMock)
    const client = new SessionClient('https://claire.test', 120, 30)
    const [first, concurrent] = await Promise.all([client.protectedResource('/files/serve/a'), client.protectedResource('/files/serve/a')])
    expect(first).toEqual(concurrent)
    expect(fetchMock).toHaveBeenCalledOnce()
    expect(first.renewAt).toBe(Date.now() + 5000)
    await vi.advanceTimersByTimeAsync(4999)
    expect(await client.protectedResource('/files/serve/a')).toEqual(first)
    expect(fetchMock).toHaveBeenCalledOnce()
    await vi.advanceTimersByTimeAsync(1)
    const [renewed, shared] = await Promise.all([client.protectedResource('/files/serve/a'), client.protectedResource('/files/serve/a')])
    expect(renewed.url).not.toBe(first.url)
    expect(shared).toEqual(renewed)
    expect(fetchMock).toHaveBeenCalledTimes(2)
    client.invalidateResources()
    await client.protectedResource('/files/serve/a')
    expect(fetchMock).toHaveBeenCalledTimes(3)
    client.destroy()
  })

  it.each(['invalidateResources', 'clear', 'destroy'] as const)('rejects a resource body completed after %s', async (action) => {
    let resolve!: (value: unknown) => void
    const response = new Response()
    vi.spyOn(response, 'json').mockImplementation(() => new Promise(done => { resolve = done }))
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response))
    const client = new SessionClient('https://claire.test', 120, 30)
    const pending = client.protectedResource('/files/serve/a')
    const rejected = expect(pending).rejects.toMatchObject({ name: 'AbortError' })
    await vi.waitFor(() => expect(resolve).toBeTypeOf('function'))
    client[action]()
    resolve({ token: 'late', expiresAt: Date.now() / 1000 + 60 })
    await rejected
    client.destroy()
  })

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

  it('mints a scoped token only for protected local file URLs', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ token: 'file-capability', expiresAt: Date.now() / 1000 + 60 })))
    vi.stubGlobal('fetch', fetchMock)
    const client = new SessionClient('https://claire.test', 120, 30)
    client.initialize(token())

    expect((await client.protectedResource('/files/serve/file-1')).url).toContain('token=file-capability')
    expect((await client.protectedResource('/history/list')).url).not.toContain('token=')
    expect((await client.protectedResource('https://external.test/files/serve/file-1')).url).not.toContain('token=')
    expect((await client.protectedResource('//external.test/files/serve/file-1')).url).not.toContain('token=')
    expect(fetchMock).toHaveBeenCalledOnce()
    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ resources: [{ type: 'file', fileId: 'file-1' }] })
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
