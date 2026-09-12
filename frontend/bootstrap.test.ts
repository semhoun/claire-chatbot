// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { parseBootstrap, readTokensFromUrl } from './bootstrap'

describe('frontend bootstrap', () => {
  afterEach(() => vi.restoreAllMocks())

  it('parses server data and applies its base URL', () => {
    const config = parseBootstrap({ mode: 'embed', threadId: 'thread-1', sessionId: 'tab', brainInfo: {}, brains: [] }, 'https://claire.test/')

    expect(config.baseUrl).toBe('https://claire.test')
    expect(config.threadId).toBe('thread-1')
  })

  it('reads and removes the initial session token from the URL', () => {
    window.history.replaceState({}, '', '/chat?token=session.jwt&foo=bar')

    expect(readTokensFromUrl()).toEqual({
      sessionToken: 'session.jwt',
    })
    expect(window.location.search).toBe('?foo=bar')
  })

  it('rejects and removes legacy mini-tokens from the URL', () => {
    window.history.replaceState({}, '', '/chat?minitoken=mini.jwt&foo=bar')
    expect(() => readTokensFromUrl()).toThrow('mini-tokens')
    expect(window.location.search).toBe('?foo=bar')
  })

  it('does not overwrite the bootstrap session when no URL token exists', () => {
    window.history.replaceState({}, '', '/chat')
    expect(readTokensFromUrl()).toEqual({})
  })
})
