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

  public constructor(
    private readonly baseUrl: string,
    private readonly refreshBeforeExpire: number,
    private readonly refreshMinInterval: number,
  ) {}

  public initialize(sessionToken?: string, miniToken?: string): void {
    if (sessionToken) this.setToken(SESSION_KEY, sessionToken)
    if (miniToken) this.setToken(MINI_TOKEN_KEY, miniToken)
    this.session = loadToken(SESSION_KEY)
    this.miniToken = loadToken(MINI_TOKEN_KEY)
    this.scheduleRefresh()
  }

  public async request(path: string, init: RequestInit = {}): Promise<Response> {
    const headers = new Headers(init.headers)
    const token = this.getSessionToken()
    if (token !== null) headers.set(AUTH_HEADER, token)
    const response = await window.fetch(this.absolute(path), { ...init, headers })
    this.captureTokens(response)
    return response
  }

  public getMiniToken(): string | null {
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
    if (this.refreshTimer !== null) window.clearTimeout(this.refreshTimer)
    this.refreshTimer = null
    this.session = null
    this.miniToken = null
    sessionStorage.removeItem(SESSION_KEY)
    sessionStorage.removeItem(MINI_TOKEN_KEY)
  }

  public destroy(): void {
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
    const expiresAt = this.session?.expiresAt
    if (expiresAt === undefined) return
    const delay = Math.max(
      this.refreshMinInterval * 1000,
      expiresAt - Date.now() - this.refreshBeforeExpire * 1000,
    )
    this.refreshTimer = window.setTimeout(() => void this.refresh(), delay)
  }

  private async refresh(): Promise<void> {
    try {
      const response = await this.request('/auth/refresh', {
        headers: { Accept: 'text/plain, */*', 'X-Requested-With': 'XMLHttpRequest' },
      })
      if (response.status === 401 || response.status === 403) this.clear()
    } catch {
      this.refreshTimer = window.setTimeout(
        () => void this.refresh(),
        this.refreshMinInterval * 1000,
      )
    }
  }
}
