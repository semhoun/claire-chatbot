const SESSION_KEY = 'claire_session_token'
const MINI_TOKEN_KEY = 'claire_mini_token'
const AUTH_HEADER = 'X-Claire-Auth'
const TOKEN_HEADER = 'X-Claire-Token'
const MINI_TOKEN_HEADER = 'X-Claire-Minitoken'

interface StoredToken {
  token: string
  expiresAt: number
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
  private miniToken = loadToken(MINI_TOKEN_KEY)
  private refreshTimer: number | null = null
  private generation = 0
  private destroyed = false
  private readonly requests = new Set<AbortController>()

  public constructor(
    private readonly baseUrl: string,
    private readonly refreshBeforeExpire: number,
    private readonly refreshMinInterval: number,
  ) {}

  public initialize(sessionToken?: string, miniToken?: string): void {
    if (this.destroyed) return
    if (sessionToken) this.setToken(SESSION_KEY, sessionToken)
    if (miniToken) this.setToken(MINI_TOKEN_KEY, miniToken)
    this.session = loadToken(SESSION_KEY)
    this.miniToken = loadToken(MINI_TOKEN_KEY)
    this.scheduleRefresh()
  }

  public async request(path: string, init: RequestInit = {}): Promise<Response> {
    if (this.destroyed) throw new DOMException('Session destroyed', 'AbortError')
    const generation = this.generation
    const controller = new AbortController()
    const abort = () => controller.abort(init.signal?.reason)
    if (init.signal?.aborted) abort()
    else init.signal?.addEventListener('abort', abort, { once: true })
    this.requests.add(controller)
    try {
      const headers = new Headers(init.headers)
      const token = this.getSessionToken()
      if (token !== null) headers.set(AUTH_HEADER, token)
      controller.signal.throwIfAborted()
      const response = await window.fetch(this.absolute(path), { ...init, headers, signal: controller.signal })
      controller.signal.throwIfAborted()
      if (generation !== this.generation) throw new DOMException('Session invalidated', 'AbortError')
      this.captureTokens(response)
      return response
    } finally {
      this.requests.delete(controller)
      init.signal?.removeEventListener('abort', abort)
    }
  }

  public getMiniToken(): string | null {
    if (this.destroyed) return null
    this.miniToken = loadToken(MINI_TOKEN_KEY)
    return this.miniToken?.token ?? null
  }

  public protectedUrl(path: string): string {
    const url = new URL(this.absolute(path))
    const token = this.getMiniToken()
    if (token !== null && url.pathname.includes('/files/serve/')) {
      url.searchParams.set('token', token)
    }
    return url.toString()
  }

  public clear(): void {
    this.invalidate()
    this.session = null
    this.miniToken = null
    sessionStorage.removeItem(SESSION_KEY)
    sessionStorage.removeItem(MINI_TOKEN_KEY)
  }

  public destroy(): void {
    this.destroyed = true
    this.invalidate()
    this.session = null
    this.miniToken = null
  }

  private invalidate(): void {
    this.generation++
    for (const controller of this.requests) controller.abort()
    this.requests.clear()
    if (this.refreshTimer !== null) window.clearTimeout(this.refreshTimer)
    this.refreshTimer = null
  }

  private absolute(path: string): string {
    if (/^https?:\/\//i.test(path)) return path
    return `${this.baseUrl}${path.startsWith('/') ? path : `/${path}`}`
  }

  private getSessionToken(): string | null {
    this.session = loadToken(SESSION_KEY)
    return this.session?.token ?? null
  }

  private setToken(key: string, token: string): void {
    const expiresAt = jwtExpiration(token)
    if (expiresAt === null) return
    sessionStorage.setItem(key, JSON.stringify({ token, expiresAt }))
  }

  private captureTokens(response: Response): void {
    const sessionToken = response.headers.get(TOKEN_HEADER)
    const miniToken = response.headers.get(MINI_TOKEN_HEADER)
    if (sessionToken !== null) this.setToken(SESSION_KEY, sessionToken)
    if (miniToken !== null) this.setToken(MINI_TOKEN_KEY, miniToken)
    this.session = loadToken(SESSION_KEY)
    this.miniToken = loadToken(MINI_TOKEN_KEY)
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
      const response = await this.request('/auth/refresh', {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      })
      if (generation === this.generation && (response.status === 401 || response.status === 403)) this.clear()
    } catch {
      if (this.destroyed || generation !== this.generation || this.refreshTimer !== null) return
      this.refreshTimer = window.setTimeout(
        () => void this.refresh(),
        this.refreshMinInterval * 1000,
      )
    }
  }
}
