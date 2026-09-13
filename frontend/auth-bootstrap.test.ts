// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { completeAuthCallback, loadNormalBootstrap } from './services/page-bootstrap'
import PublicApp from './components/PublicApp.vue'

const sessionKey = 'claire_session_token'
const token = (revision: number) => `e30.${btoa(JSON.stringify({ aud: 'session', exp: Math.floor(Date.now() / 1000) + 3600, revision }))}.test`
const theme = { preset: 'light', tokens: { '--claire-accent': '#005c9f' }, variants: { controls: 'solid', effects: 'none' } }
const config = { mode: 'normal', threadId: 'thread', sessionId: 'tab', brainInfo: { name: 'Claire', description: '', avatar: '', theme }, brains: [] }

beforeEach(() => { sessionStorage.clear(); window.history.replaceState({}, '', '/') })
afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals(); sessionStorage.clear() })

describe('normal JSON authentication bootstrap', () => {
  it('mounts with the bootstrap theme without fetching a stylesheet', async () => {
    sessionStorage.setItem(sessionKey, JSON.stringify({ token: token(1), expiresAt: Date.now() + 3600_000 }))
    const fetchMock = vi.fn(async (input: string | URL) => {
      expect(new URL(input).pathname).toBe('/')
      return new Response(JSON.stringify({ ...config, layoutMode: 'compact' }))
    })
    vi.stubGlobal('fetch', fetchMock)
    const bodyAttributes = document.body.outerHTML.split('>')[0]
    const wrapper = mount(PublicApp, { props: { data: { page: 'app', baseUrl: '' } }, global: {
      stubs: { ClaireApp: { name: 'ClaireApp', props: ['config'], template: '<div data-chat>Chat</div>' } },
    } })
    await flushPromises()
    expect(wrapper.find('[data-chat]').exists()).toBe(true)
    expect(wrapper.find('.claire-error-panel').exists()).toBe(false)
    expect(wrapper.getComponent({ name: 'ClaireApp' }).props('config').brainInfo.theme).toEqual(theme)
    expect(wrapper.getComponent({ name: 'ClaireApp' }).props('config').layoutMode).toBe('compact')
    expect(document.body.outerHTML.split('>')[0]).toBe(bodyAttributes)
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(String(fetchMock.mock.calls[0]?.[0])).not.toContain('/css/')
    wrapper.unmount()
  })

  it('does not mount a late bootstrap after unmount', async () => {
    sessionStorage.setItem(sessionKey, JSON.stringify({ token: token(1), expiresAt: Date.now() + 3600_000 }))
    let release!: (response: Response) => void
    const fetchMock = vi.fn(() => new Promise<Response>(resolve => { release = resolve }))
    vi.stubGlobal('fetch', fetchMock)
    const mounted = vi.fn()
    const wrapper = mount(PublicApp, { props: { data: { page: 'app', baseUrl: '' } }, global: {
      stubs: { ClaireApp: { name: 'ClaireApp', props: ['config'], mounted, template: '<div data-chat>Chat</div>' } },
    } })
    await flushPromises()
    wrapper.unmount()
    release(new Response(JSON.stringify(config)))
    await flushPromises()
    expect(mounted).not.toHaveBeenCalled()
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('stores the callback session without putting credentials in the redirect URL', async () => {
    sessionStorage.setItem('claire_mini_token', 'legacy')
    const sessionToken = token(3)
    expect(await completeAuthCallback({ page: 'callback', baseUrl: '', sessionToken, redirectUrl: '/' })).toBe('/')
    expect(JSON.parse(sessionStorage.getItem(sessionKey)!)).toMatchObject({ token: sessionToken })
    expect(sessionStorage.getItem('claire_mini_token')).toBeNull()
  })

  it('refuses foreign callback redirects before storing the token', async () => {
    expect(() => completeAuthCallback({ page: 'callback', baseUrl: '', sessionToken: token(1), redirectUrl: '//evil.test' })).toThrow('origin')
    expect(sessionStorage.getItem(sessionKey)).toBeNull()
  })

  it('restores through a header and retains refreshed credentials without document replacement', async () => {
    const original = token(1)
    const refreshed = token(2)
    sessionStorage.setItem(sessionKey, JSON.stringify({ token: original, expiresAt: Date.now() + 3600_000 }))
    sessionStorage.setItem('claire_mini_token', 'legacy')
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify(config), { headers: { 'X-Claire-Token': refreshed } }))
    vi.stubGlobal('fetch', fetchMock)
    expect(await loadNormalBootstrap(window.location.origin)).toMatchObject(config)
    const [url, init] = fetchMock.mock.calls[0]
    expect(String(url)).toBe(`${window.location.origin}/`)
    expect(new Headers(init.headers).get('X-Claire-Auth')).toBe(original)
    expect(new Headers(init.headers).get('Accept')).toBe('application/json')
    expect(init.redirect).toBe('error')
    expect(JSON.parse(sessionStorage.getItem(sessionKey)!)).toMatchObject({ token: refreshed })
    expect(sessionStorage.getItem('claire_mini_token')).toBeNull()
  })

  it('does not send expired credentials and shows SSO on 401', async () => {
    sessionStorage.setItem(sessionKey, JSON.stringify({ token: token(1), expiresAt: Date.now() - 1 }))
    const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 401 }))
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mount(PublicApp, { props: { data: { page: 'app', baseUrl: '' } } })
    await flushPromises()
    expect(new Headers(fetchMock.mock.calls[0][1].headers).has('X-Claire-Auth')).toBe(false)
    expect(wrapper.get('#claire-sso-panel').text()).toContain('Bienvenue')
    expect(sessionStorage.getItem(sessionKey)).toBeNull()
    wrapper.unmount()
  })

  it('rejects cross-origin restoration before sending credentials', async () => {
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
    await expect(loadNormalBootstrap('https://evil.test')).rejects.toThrow('origin')
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('does not accept HTML as authenticated bootstrap', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('<html>login</html>')))
    await expect(loadNormalBootstrap('')).rejects.toThrow()
  })

  it('restores a closed PWA using the cookie endpoint then header-only bootstrap', async () => {
    const restored = token(4)
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(null, { status: 204, headers: { 'X-Claire-Token': restored } }))
      .mockResolvedValueOnce(new Response(JSON.stringify(config)))
    vi.stubGlobal('fetch', fetchMock)
    expect(await loadNormalBootstrap('')).toMatchObject(config)
    const [url, init] = fetchMock.mock.calls[0]
    expect(new URL(url).pathname).toBe('/auth/remember')
    expect(init).toMatchObject({ method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error' })
    expect(new Headers(init.headers).get('X-Claire-Remember')).toBe('1')
    expect(new Headers(init.headers).has('X-Claire-Auth')).toBe(false)
    expect(fetchMock.mock.calls[1][1].credentials).toBe('omit')
    expect(new Headers(fetchMock.mock.calls[1][1].headers).get('X-Claire-Auth')).toBe(restored)
    expect(localStorage.getItem(sessionKey)).toBeNull()
  })

  it('recovers a server-rejected cached JWT only once', async () => {
    sessionStorage.setItem(sessionKey, JSON.stringify({ token: token(1), expiresAt: Date.now() + 3600_000 }))
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(null, { status: 401 }))
      .mockResolvedValueOnce(new Response(null, { status: 204, headers: { 'X-Claire-Token': token(2) } }))
      .mockResolvedValueOnce(new Response(JSON.stringify(config)))
    vi.stubGlobal('fetch', fetchMock)
    expect(await loadNormalBootstrap('')).toMatchObject(config)
    expect(fetchMock.mock.calls.map(call => new URL(call[0]).pathname)).toEqual(['/', '/auth/remember', '/'])
  })

  it('does not mistake unavailable remember storage for a logged-out browser', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(null, { status: 503 })))
    await expect(loadNormalBootstrap('')).rejects.toThrow('503')
  })

  it('renders error details as text and never attempts authentication on error pages', async () => {
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mount(PublicApp, { props: { data: {
      page: 'error', baseUrl: '', code: 403, title: '<img src=x onerror=alert(1)>', details: { message: '<script>alert(1)</script>' },
    } } })
    await flushPromises()
    expect(wrapper.find('script').exists()).toBe(false)
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.text()).toContain('<script>alert(1)</script>')
    expect(fetchMock).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})
