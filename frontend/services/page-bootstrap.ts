import { jwtAudience, parseBootstrap } from '../bootstrap'
import type { ClaireBootstrap, PageData } from '../types'
import { SessionClient } from './session-client'

export async function loadNormalBootstrap(baseUrl: string, sessionToken?: string): Promise<ClaireBootstrap | null> {
  const target = new URL(`${baseUrl}/`, window.location.origin)
  if (target.origin !== window.location.origin) throw new Error('Invalid bootstrap origin')
  const client = new SessionClient(baseUrl, 120, 30)
  try {
    client.initialize(sessionToken)
    const response = await client.request('/', { headers: { Accept: 'application/json' }, cache: 'no-store' })
    if ([401, 403].includes(response.status)) { client.clear(); return null }
    if (!response.ok) throw new Error(`HTTP ${response.status}`)
    return parseBootstrap(await response.json(), baseUrl)
  } finally {
    client.destroy()
  }
}

export function completeAuthCallback(data: PageData): string {
  const target = new URL(data.redirectUrl || '/', window.location.origin)
  if (target.origin !== window.location.origin) throw new Error('Invalid redirect origin')
  if (!data.sessionToken || jwtAudience(data.sessionToken) !== 'session') throw new Error('Invalid callback session')
  const client = new SessionClient(window.location.origin, 120, 30)
  try {
    client.initialize(data.sessionToken)
    const stored = JSON.parse(sessionStorage.getItem('claire_session_token') ?? 'null')
    if (stored?.token !== data.sessionToken || !(stored.expiresAt > Date.now())) {
      client.clear()
      throw new Error('Expired callback session')
    }
  } finally { client.destroy() }
  return target.pathname + target.search + target.hash
}
