// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { completeAuthCallback, loadNormalBootstrap } from './services/page-bootstrap'
import PublicApp from './components/PublicApp.vue'

const sessionKey = 'claire_session_token'
const token = (revision: number) => `e30.${btoa(JSON.stringify({ aud: 'session', exp: Math.floor(Date.now() / 1000) + 3600, revision }))}.test`
const config = { mode: 'normal', threadId: 'thread', sessionId: 'tab', brainInfo: {}, brains: [] }

beforeEach(() => { sessionStorage.clear(); window.history.replaceState({}, '', '/') })
afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals(); sessionStorage.clear() })

describe('normal JSON authentication bootstrap', () => {
  it.each(['rejected', 'redirect', '404', 'stalled'] as const)('mounts the chat with inline CSS when the optional theme is %s', async failure => {
    let themeSignal: AbortSignal | undefined
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL, init: RequestInit) => {
      if (new URL(input).pathname.startsWith('/css/')) {
        themeSignal = init.signal as AbortSignal
        expect(init.redirect).toBe('error')
        if (failure === 'stalled') return new Promise<Response>(() => {})
        if (failure === '404') return new Response('', { status: 404 })
        throw new TypeError(failure === 'redirect' ? 'Redirect disallowed' : 'Network unavailable')
      }
      return new Response(JSON.stringify({ ...config, currentBrain: 'claire', brainInfo: { css: 'theme.css', cssInline: '.inline{}' } }))
    }))
    const wrapper = mount(PublicApp, { props: { data: { page: 'app', baseUrl: '' } }, global: {
      stubs: { ClaireApp: { name: 'ClaireApp', props: ['config'], template: '<div data-chat>Chat</div>' } },
    } })
    await flushPromises()
    expect(wrapper.find('[data-chat]').exists()).toBe(true)
    expect(wrapper.find('.claire-error-panel').exists()).toBe(false)
    expect(wrapper.getComponent({ name: 'ClaireApp' }).props('config').dynamicCss).toBe('.inline{}')
    wrapper.unmount()
    expect(themeSignal?.aborted).toBe(true)
  })

  it.each([false, true])('applies a late theme only while its page remains mounted (unmounted: %s)', async unmount => {
    let release!: (response: Response) => void
    vi.stubGlobal('fetch', vi.fn(async (input: string | URL) => {
      if (new URL(input).pathname.startsWith('/css/')) return new Promise<Response>(resolve => { release = resolve })
      return new Response(JSON.stringify({ ...config, currentBrain: 'claire', brainInfo: { css: 'theme.css', cssInline: '.inline{}' } }))
    }))
    const wrapper = mount(PublicApp, { props: { data: { page: 'app', baseUrl: '' } }, global: {
      stubs: { ClaireApp: { name: 'ClaireApp', props: ['config'], template: '<div data-chat>Chat</div>' } },
    } })
    await flushPromises()
    const loaded = wrapper.getComponent({ name: 'ClaireApp' }).props('config')
    expect(loaded.dynamicCss).toBe('.inline{}')
    if (unmount) wrapper.unmount()
    release(new Response('.external{}'))
    await flushPromises()
    expect(loaded.dynamicCss).toBe(unmount ? '.inline{}' : '.external{}\n.inline{}')
    if (!unmount) wrapper.unmount()
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
