import { jwtAudience } from '../bootstrap'

const SESSION_KEY = 'claire_session_token'
const MINI_TOKEN_KEY = 'claire_mini_token'
const AUTH_HEADER = 'X-Claire-Auth'
const TOKEN_HEADER = 'X-Claire-Token'

interface StoredToken {
  token: string
  expiresAt: number
}

interface ResourceToken extends StoredToken {
  renewAt: number
}

function jwtExpiration(token: string): number | null {
  try {
    const parts = token.split('.')
    if (parts.length !== 3) return null
    const base64 = parts[1].replace(/-/g, '+').replace(/_/g, '/')
    const payload = JSON.parse(atob(base64 + '==='.slice((base64.length + 3) % 4))) as {
      exp?: unknown
    }
    return typeof payload.exp === 'number' ? payload.exp * 1000 : null
  } catch {
    return null
  }
}

function loadToken(key: string): StoredToken | null {
  try {
    const raw = sessionStorage.getItem(key)
    if (raw === null) return null
    const value = JSON.parse(raw) as Partial<StoredToken>
    if (
      typeof value.token !== 'string'
      || typeof value.expiresAt !== 'number'
      || value.expiresAt <= Date.now()
    ) {
      sessionStorage.removeItem(key)
      return null
    }
    return { token: value.token, expiresAt: value.expiresAt }
  } catch {
    sessionStorage.removeItem(key)
    return null
  }
}

export class SessionClient {
  private session = loadToken(SESSION_KEY)
  private refreshTimer: number | null = null
  private generation = 0
  private destroyed = false
  private tokenRevision = 0
  private authUpdating = false
  private authQueue: Promise<unknown> = Promise.resolve()
  private resourceGeneration = 0
  private readonly resources = new Map<string, Promise<ResourceToken>>()
  private readonly requests = new Set<AbortController>()

  public constructor(
    private readonly baseUrl: string,
    private readonly refreshBeforeExpire: number,
    private readonly refreshMinInterval: number,
  ) {}

  public initialize(sessionToken?: string): void {
    if (this.destroyed) return
    sessionStorage.removeItem(MINI_TOKEN_KEY)
    if (sessionToken && jwtAudience(sessionToken) === 'minitoken') {
      throw new Error('A mini-token cannot authenticate a session')
    }
    this.tokenRevision++
    this.invalidateResources()
    if (sessionToken) this.setToken(SESSION_KEY, sessionToken)
    this.session = loadToken(SESSION_KEY)
    this.scheduleRefresh()
  }

  public async request(path: string, init: RequestInit = {}): Promise<Response> {
    if (path === '/auth/refresh' || (path.startsWith('/config/') && init.method === 'POST')) {
      const generation = this.generation
      const pending = this.authQueue.then(async () => {
        if (generation !== this.generation) throw new DOMException('Session invalidated', 'AbortError')
        this.tokenRevision++
        this.authUpdating = true
        try {
          return await this.performRequest(path, init, true)
        } finally {
          this.authUpdating = false
        }
      })
      this.authQueue = pending.catch(() => {})
      return pending
    }
    return this.performRequest(path, init)
  }

  private async performRequest(path: string, init: RequestInit, authUpdate = false): Promise<Response> {
    if (this.destroyed) throw new DOMException('Session destroyed', 'AbortError')
    const generation = this.generation
    const revision = this.tokenRevision
    const controller = new AbortController()
    const abort = () => controller.abort(init.signal?.reason)
    if (init.signal?.aborted) abort()
    else init.signal?.addEventListener('abort', abort, { once: true })
    this.requests.add(controller)
    try {
      const headers = new Headers(init.headers)
      const token = this.getSessionToken()
      const url = new URL(this.absolute(path))
      if (url.origin !== new URL(this.baseUrl || window.location.origin, window.location.href).origin) {
        throw new Error('Authenticated requests must target the Claire origin')
      }
      if (token !== null) headers.set(AUTH_HEADER, token)
      controller.signal.throwIfAborted()
      const response = await window.fetch(url.toString(), { ...init, headers, signal: controller.signal, redirect: 'error' })
      controller.signal.throwIfAborted()
      if (generation !== this.generation) throw new DOMException('Session invalidated', 'AbortError')
      if (revision === this.tokenRevision && token === this.getSessionToken() && (authUpdate || !this.authUpdating)) {
        if (path === '/auth/refresh' && [401, 403].includes(response.status)) this.clear()
        else this.captureTokens(response)
      }
      return response
    } finally {
      this.requests.delete(controller)
      init.signal?.removeEventListener('abort', abort)
    }
  }

  public async protectedResource(path: string): Promise<{ url: string; renewAt: number | null }> {
    const generation = this.resourceGeneration
    const url = new URL(this.absolute(path))
    const base = new URL(this.baseUrl || window.location.origin, window.location.href)
    const prefix = `${base.pathname.replace(/\/$/, '')}/files/serve/`
    if (url.origin !== base.origin || !url.pathname.startsWith(prefix)) return { url: url.toString(), renewAt: null }
    const fileId = decodeURIComponent(url.pathname.slice(prefix.length).split('/')[0])
    if (!fileId) return { url: url.toString(), renewAt: null }
    const capability = await this.resourceToken({ type: 'file', fileId })
    if (generation !== this.resourceGeneration || this.destroyed) throw new DOMException('Resource invalidated', 'AbortError')
    url.searchParams.set('token', capability.token)
    return { url: url.toString(), renewAt: capability.renewAt }
  }

  public invalidateResources(): void {
    this.resourceGeneration++
    this.resources.clear()
  }

  public async resourceToken(resource: { type: 'file'; fileId: string } | {
    type: 'stream'; threadId: string; sessionId: string
  }): Promise<ResourceToken> {
    const generation = this.resourceGeneration
    const key = JSON.stringify(resource)
    const cached = this.resources.get(key)
    if (cached) {
      const value = await cached
      if (generation !== this.resourceGeneration || this.destroyed) throw new DOMException('Resource invalidated', 'AbortError')
      if (value.renewAt > Date.now()) return value
      if (this.resources.get(key) !== cached) return this.resourceToken(resource)
      this.resources.delete(key)
    }
    const pending = (async () => {
      const response = await this.request('/auth/resource-token', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: key,
      })
      if (generation !== this.resourceGeneration || this.destroyed) throw new DOMException('Resource invalidated', 'AbortError')
      if (!response.ok) throw new Error(`Resource authorization failed: HTTP ${response.status}`)
      const value = await response.json() as StoredToken
      if (generation !== this.resourceGeneration || this.destroyed) throw new DOMException('Resource invalidated', 'AbortError')
      if (typeof value.token !== 'string' || !value.token || !Number.isFinite(value.expiresAt)
        || value.expiresAt * 1000 <= Date.now()) throw new Error('Invalid resource capability')
      const expiresAt = value.expiresAt * 1000
      const renewAt = expiresAt - Math.min(5000, (expiresAt - Date.now()) / 2)
      return { token: value.token, expiresAt, renewAt }
    })()
    // Stream reconnects always mint a fresh capability.
    if (resource.type === 'file') this.resources.set(key, pending)
    try {
      const value = await pending
      if (generation !== this.resourceGeneration || this.destroyed) throw new DOMException('Resource invalidated', 'AbortError')
      return value
    } catch (error) {
      if (this.resources.get(key) === pending) this.resources.delete(key)
      throw error
    }
  }

  public clear(): void {
    this.invalidate()
    this.session = null
    sessionStorage.removeItem(SESSION_KEY)
    sessionStorage.removeItem(MINI_TOKEN_KEY)
  }

  public destroy(): void {
    this.destroyed = true
    this.invalidate()
    this.session = null
  }

  private invalidate(): void {
    this.generation++
    this.tokenRevision++
    this.invalidateResources()
    for (const controller of this.requests) controller.abort()
    this.requests.clear()
    if (this.refreshTimer !== null) window.clearTimeout(this.refreshTimer)
    this.refreshTimer = null
  }

  private absolute(path: string): string {
    const base = new URL(this.baseUrl || window.location.origin, window.location.href)
    if (/^(?:[a-z][a-z\d+.-]*:|\/\/)/i.test(path)) return new URL(path, base).toString()
    return new URL(`${base.toString().replace(/\/$/, '')}${path.startsWith('/') ? path : `/${path}`}`).toString()
  }

  private getSessionToken(): string | null {
    this.session = loadToken(SESSION_KEY)
    if (this.session && jwtAudience(this.session.token) === 'minitoken') return null
    return this.session?.token ?? null
  }

  private setToken(key: string, token: string): void {
    const expiresAt = jwtExpiration(token)
    if (expiresAt === null) return
    sessionStorage.setItem(key, JSON.stringify({ token, expiresAt }))
  }

  private captureTokens(response: Response): void {
    const sessionToken = response.headers.get(TOKEN_HEADER)
    if (sessionToken !== null && jwtAudience(sessionToken) !== 'minitoken') {
      this.setToken(SESSION_KEY, sessionToken)
      this.tokenRevision++
    }
    this.session = loadToken(SESSION_KEY)
    this.scheduleRefresh()
  }

  private scheduleRefresh(): void {
    if (this.refreshTimer !== null) window.clearTimeout(this.refreshTimer)
    this.refreshTimer = null
    if (this.destroyed) return
    const expiresAt = this.session?.expiresAt
    if (expiresAt === undefined) return
    const delay = Math.max(
      this.refreshMinInterval * 1000,
      expiresAt - Date.now() - this.refreshBeforeExpire * 1000,
    )
    this.refreshTimer = window.setTimeout(() => void this.refresh(), delay)
  }

  private async refresh(): Promise<void> {
    const generation = this.generation
    this.refreshTimer = null
    try {
      await this.request('/auth/refresh', {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      })
    } catch {
      if (this.destroyed || generation !== this.generation || this.refreshTimer !== null) return
      this.refreshTimer = window.setTimeout(
        () => void this.refresh(),
        this.refreshMinInterval * 1000,
      )
    }
  }
}
