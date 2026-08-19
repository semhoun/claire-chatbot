import type { ClaireBootstrap } from './types'

interface BootstrapElement extends HTMLElement {
  dataset: DOMStringMap & {
    baseUrl?: string
    bootstrap?: string
  }
}

export function parseBootstrap(element: BootstrapElement): ClaireBootstrap {
  const raw = element.dataset.bootstrap
  if (!raw) throw new Error('Configuration frontend Claire absente')
  const config = JSON.parse(raw) as ClaireBootstrap
  config.baseUrl = (element.dataset.baseUrl ?? '').replace(/\/$/, '')
  return config
}

export function readTokensFromUrl(): { sessionToken?: string; miniToken?: string } {
  const url = new URL(window.location.href)
  const sessionToken = url.searchParams.get('token') || undefined
  const miniToken = url.searchParams.get('minitoken') || undefined
  if (sessionToken || miniToken) {
    url.searchParams.delete('token')
    url.searchParams.delete('minitoken')
    window.history.replaceState({}, document.title, `${url.pathname}${url.search}${url.hash}`)
  }
  return { sessionToken, miniToken }
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
