// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { parseBootstrap, readTokensFromUrl } from './bootstrap'

describe('frontend bootstrap', () => {
  afterEach(() => vi.restoreAllMocks())

  it('parses server data and applies its base URL', () => {
    const element = document.createElement('div')
    element.dataset.baseUrl = 'https://claire.test/'
    element.dataset.bootstrap = JSON.stringify({ mode: 'embed', threadId: 'thread-1' })

    const config = parseBootstrap(element)

    expect(config.baseUrl).toBe('https://claire.test')
    expect(config.threadId).toBe('thread-1')
  })

  it('reads and removes authentication tokens from the URL', () => {
    window.history.replaceState({}, '', '/chat?token=session.jwt&minitoken=mini.jwt&foo=bar')

    expect(readTokensFromUrl()).toEqual({
      sessionToken: 'session.jwt',
      miniToken: 'mini.jwt',
    })
    expect(window.location.search).toBe('?foo=bar')
  })
})
