import { defineCustomElement } from 'vue'
import ClaireApp from './components/ClaireApp.vue'
import { jwtAudience, parseBootstrap } from './bootstrap'
import type { ClaireBootstrap } from './types'
import claireCss from './styles/index.css?inline'

const ELEMENT_NAME = 'claire-chat-widget'
const CONTAINER_ID = 'claire-embed-root'

interface ClaireElement extends HTMLElement {
  config: ClaireBootstrap
}

let currentElement: ClaireElement | null = null
let currentContainer: HTMLElement | null = null
let initialization: AbortController | null = null
let generation = 0

function normalizedBaseUrl(value?: string): string {
  const baseUrl = value?.trim() || window.location.origin
  return baseUrl.replace(/\/$/, '')
}

function resolveTarget(target?: string | Element): Element {
  if (target instanceof Element) return target
  if (typeof target === 'string') return document.querySelector(target) ?? document.body
  return document.body
}

async function exchangeToken(
  baseUrl: string,
  token: string,
  signal: AbortSignal,
  tokenType?: string,
): Promise<{ session_token: string }> {
  const response = await window.fetch(`${baseUrl}/auth/embed/exchange`, {
    method: 'POST',
    signal,
    redirect: 'error',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify({
      sso_token: token,
      ...(tokenType?.trim() ? { sso_token_type: tokenType.trim() } : {}),
    }),
  })
  if (!response.ok) throw new Error(`SSO exchange failed with status ${response.status}`)
  return response.json() as Promise<{ session_token: string }>
}

async function fetchBootstrap(baseUrl: string, signal: AbortSignal, authToken?: string): Promise<ClaireBootstrap> {
  const url = new URL(`${baseUrl}/embed`)
  const headers = new Headers({ Accept: 'application/json' })
  if (authToken) headers.set('X-Claire-Auth', authToken)
  const response = await window.fetch(url, { signal, headers, redirect: 'error' })
  if (!response.ok) throw new Error(`Embed page fetch failed with status ${response.status}`)
  const config = parseBootstrap(await response.json(), baseUrl)
  config.sessionToken = response.headers.get('X-Claire-Token') ?? undefined
  return config
}

function registerElement(): void {
  if (customElements.get(ELEMENT_NAME)) return
  const ClaireElementConstructor = defineCustomElement(ClaireApp, {
    styles: [claireCss],
  })
  customElements.define(ELEMENT_NAME, ClaireElementConstructor)
}

function destroyClaireEmbed(): void {
  generation++
  initialization?.abort()
  initialization = null
  currentElement?.remove()
  currentElement = null
  currentContainer?.remove()
  currentContainer = null
}

async function claireEmbed(options: ClaireEmbedConfig = {}): Promise<HTMLElement> {
  destroyClaireEmbed()
  const ownGeneration = generation
  const controller = new AbortController()
  initialization = controller
  const assertCurrent = () => {
    if (ownGeneration !== generation) throw new DOMException('Embed initialization superseded', 'AbortError')
    controller.signal.throwIfAborted()
  }
  try {
    const baseUrl = normalizedBaseUrl(options.baseUrl)
    const genericToken = options.token?.trim() ?? ''
    let sessionToken = options.sessionToken?.trim() ?? ''
    const audience = genericToken ? jwtAudience(genericToken) : null
    if (!sessionToken && audience === 'session') sessionToken = genericToken
    if (audience === 'minitoken' || jwtAudience(sessionToken) === 'minitoken'
      || jwtAudience(options.ssoToken?.trim() ?? '') === 'minitoken') {
      throw new Error('A mini-token cannot authenticate the widget. Use a session token or explicit ssoToken.')
    }
    if (genericToken && audience !== 'session' && !options.ssoToken) {
      throw new Error('Unrecognized token audience. Supply SSO credentials through ssoToken explicitly.')
    }
    const exchangeCandidate = options.ssoToken?.trim()
    if (!sessionToken && exchangeCandidate) {
      const exchange = await exchangeToken(baseUrl, exchangeCandidate, controller.signal, options.ssoTokenType)
      assertCurrent()
      sessionToken = exchange.session_token
    }
    if (jwtAudience(sessionToken) === 'minitoken') throw new Error('SSO exchange returned a mini-token instead of a session token')

    const config = await fetchBootstrap(baseUrl, controller.signal, sessionToken)
    assertCurrent()
    config.sessionToken ||= sessionToken || undefined
    registerElement()

    const container = document.createElement('div')
    container.id = CONTAINER_ID
    const element = document.createElement(ELEMENT_NAME) as ClaireElement
    element.config = config
    element.addEventListener('claire:logout', () => {
      if (currentElement === element) destroyClaireEmbed()
    })
    container.appendChild(element)
    resolveTarget(options.target).appendChild(container)
    currentElement = element
    currentContainer = container
    return element
  } finally {
    if (initialization === controller) initialization = null
  }
}

window.claireEmbed = claireEmbed
window.destroyClaireEmbed = destroyClaireEmbed
