import { readFileSync } from 'node:fs'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const sessionKey = 'claire_session_token'
const origin = window.location.origin
const token = (revision: number) => `e30.${btoa(JSON.stringify({ aud: 'session', exp: Math.floor(Date.now() / 1000) + 3600, revision }))}.test`

function storeToken(value: string): void {
  sessionStorage.setItem(sessionKey, JSON.stringify({ token: value, expiresAt: Date.now() + 3600_000 }))
}

async function runScript(template: 'welcome.twig' | 'auth_callback.twig', fetchMock: ReturnType<typeof vi.fn>, sessionToken = '') {
  const source = readFileSync(`tmpl/${template}`, 'utf8')
  const script = source.match(/<script>([\s\S]*?)<\/script>/)![1]
    .replace('{{ (base_url ~ \'/\')|json_encode|raw }}', JSON.stringify(`${origin}/`))
    .replace('{{ session_token|json_encode|raw }}', JSON.stringify(sessionToken))
    .replace('{{ redirect_url|json_encode|raw }}', JSON.stringify('/'))
  const locationMock = { origin, replace: vi.fn() }
  const historyMock = { replaceState: vi.fn() }
  const execute = new Function('fetch', 'document', 'sessionStorage', 'location', 'history', 'DOMParser', `return ${script.trim()}`)
  await execute(fetchMock, document, sessionStorage, locationMock, historyMock, DOMParser)
  return { locationMock, historyMock }
}

beforeEach(() => {
  sessionStorage.clear()
  document.body.innerHTML = '<div id="claire-auth-status" class="claire-is-hidden"></div><div id="claire-sso-panel"></div>'
  vi.spyOn(document, 'open').mockReturnValue(window)
  vi.spyOn(document, 'write').mockImplementation(() => {})
  vi.spyOn(document, 'close').mockImplementation(() => {})
})

afterEach(() => {
  vi.restoreAllMocks()
  sessionStorage.clear()
})

describe('normal authentication bootstrap', () => {
  it('loads authenticated HTML with a header and retains refreshed credentials without a token URL', async () => {
    const original = token(1)
    const refreshed = token(2)
    storeToken(original)
    sessionStorage.setItem('claire_mini_token', 'legacy')
    const html = '<!doctype html><div id="claire-vue-app"></div>'
    const fetchMock = vi.fn().mockResolvedValue(new Response(html, { headers: { 'X-Claire-Token': refreshed } }))

    const { locationMock, historyMock } = await runScript('welcome.twig', fetchMock)

    expect(fetchMock).toHaveBeenCalledOnce()
    const [url, init] = fetchMock.mock.calls[0]
    expect(String(url)).toBe(`${origin}/`)
    expect(init).toEqual({ headers: { 'X-Claire-Auth': original, Accept: 'text/html' }, redirect: 'error', cache: 'no-store' })
    expect(document.write).toHaveBeenCalledWith(html)
    expect(historyMock.replaceState).toHaveBeenCalledWith(null, '', '/')
    expect(locationMock.replace).not.toHaveBeenCalled()
    expect(JSON.parse(sessionStorage.getItem(sessionKey)!)).toMatchObject({ token: refreshed })
    expect(sessionStorage.getItem('claire_mini_token')).toBeNull()
  })

  it('does not bootstrap with expired credentials', async () => {
    sessionStorage.setItem(sessionKey, JSON.stringify({ token: token(1), expiresAt: Date.now() - 1 }))
    const fetchMock = vi.fn()
    await runScript('welcome.twig', fetchMock)
    expect(fetchMock).not.toHaveBeenCalled()
    expect(document.write).not.toHaveBeenCalled()
    expect(document.getElementById('claire-sso-panel')!.classList.contains('claire-is-hidden')).toBe(false)
    expect(sessionStorage.getItem(sessionKey)).toBeNull()
  })

  it.each([401, 200])('returns to SSO without a redirect loop for an unauthenticated response (%s)', async status => {
    storeToken(token(1))
    const fetchMock = vi.fn().mockResolvedValue(new Response('<p>Unauthenticated</p>', { status }))
    const { locationMock } = await runScript('welcome.twig', fetchMock)
    expect(document.write).not.toHaveBeenCalled()
    expect(locationMock.replace).not.toHaveBeenCalled()
    expect(sessionStorage.getItem(sessionKey)).toBeNull()
    expect(document.getElementById('claire-auth-status')!.classList.contains('claire-is-hidden')).toBe(true)
    expect(document.getElementById('claire-sso-panel')!.classList.contains('claire-is-hidden')).toBe(false)
  })

  it('stores only the session credential after the OIDC callback', async () => {
    sessionStorage.setItem('claire_mini_token', 'legacy')
    const sessionToken = token(1)
    const { locationMock } = await runScript('auth_callback.twig', vi.fn(), sessionToken)
    expect(JSON.parse(sessionStorage.getItem(sessionKey)!)).toMatchObject({ token: sessionToken })
    expect(sessionStorage.getItem('claire_mini_token')).toBeNull()
    expect(locationMock.replace).toHaveBeenCalledWith('/')
  })
})
// @vitest-environment jsdom
