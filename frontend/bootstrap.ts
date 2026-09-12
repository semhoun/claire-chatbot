import type { ClaireBootstrap } from './types'

export function parseBootstrap(value: unknown, baseUrl: string): ClaireBootstrap {
  if (!value || typeof value !== 'object') throw new Error('Configuration frontend Claire absente')
  const config = value as ClaireBootstrap
  if (!['normal', 'embed'].includes(config.mode) || typeof config.threadId !== 'string'
    || typeof config.sessionId !== 'string' || !config.brainInfo || !Array.isArray(config.brains)) {
    throw new Error('Configuration frontend Claire invalide')
  }
  config.baseUrl = baseUrl.replace(/\/$/, '')
  return config
}

export function readTokensFromUrl(): { sessionToken?: string } {
  const url = new URL(window.location.href)
  const sessionToken = url.searchParams.get('token') || undefined
  const miniToken = url.searchParams.get('minitoken') || undefined
  if (sessionToken || miniToken) {
    url.searchParams.delete('token')
    url.searchParams.delete('minitoken')
    window.history.replaceState({}, document.title, `${url.pathname}${url.search}${url.hash}`)
  }
  if (miniToken || (sessionToken && jwtAudience(sessionToken) === 'minitoken')) {
    throw new Error('Legacy mini-tokens are no longer supported. Authenticate with a session token.')
  }
  return sessionToken ? { sessionToken } : {}
}

export function jwtAudience(token: string): 'session' | 'minitoken' | null {
  try {
    const part = token.split('.')[1]
    if (!part) return null
    const base64 = part.replace(/-/g, '+').replace(/_/g, '/')
    const payload = JSON.parse(atob(base64 + '==='.slice((base64.length + 3) % 4))) as {
      aud?: unknown
    }
    const audiences = Array.isArray(payload.aud) ? payload.aud : [payload.aud]
    if (audiences.includes('session')) return 'session'
    if (audiences.includes('minitoken')) return 'minitoken'
    return null
  } catch {
    return null
  }
}
