import { defineCustomElement } from 'vue'
import ClaireApp from './components/ClaireApp.vue'
import { jwtAudience, parseBootstrap } from './bootstrap'
import type { ClaireBootstrap } from './types'
import claireCss from '../public/css/style.css?inline'
import highlightCss from '../public/css/highlight.min.css?inline'

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
): Promise<{ session_token: string; mini_token?: string }> {
  const response = await window.fetch(`${baseUrl}/auth/embed/exchange`, {
    method: 'POST',
    signal,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify({
      sso_token: token,
      ...(tokenType?.trim() ? { sso_token_type: tokenType.trim() } : {}),
    }),
  })
  if (!response.ok) throw new Error(`SSO exchange failed with status ${response.status}`)
  return response.json() as Promise<{ session_token: string; mini_token?: string }>
}

async function fetchBootstrap(baseUrl: string, signal: AbortSignal, authToken?: string): Promise<ClaireBootstrap> {
  const url = new URL(`${baseUrl}/embed`)
  if (authToken) url.searchParams.set('token', authToken)
  const response = await window.fetch(url, { signal, headers: { Accept: 'text/html' } })
  if (!response.ok) throw new Error(`Embed page fetch failed with status ${response.status}`)
  const documentFragment = new DOMParser().parseFromString(await response.text(), 'text/html')
  const bootstrap = documentFragment.querySelector<HTMLElement>('.claire-embed-bootstrap')
  if (bootstrap === null) throw new Error('Embed bootstrap payload is missing')
  const config = parseBootstrap(bootstrap)
  config.sessionToken = response.headers.get('X-Claire-Token') ?? undefined
  config.miniToken = response.headers.get('X-Claire-Minitoken') ?? undefined
  return config
}

async function loadDynamicCss(config: ClaireBootstrap, signal: AbortSignal): Promise<void> {
  const parts = [config.brainInfo.cssInline ?? '']
  if (config.brainInfo.css) {
    const response = await window.fetch(`${config.baseUrl}/css/${config.brainInfo.css}`, { signal })
    if (response.ok) parts.unshift(await response.text())
  }
  config.dynamicCss = parts.filter(Boolean).join('\n')
}

function registerElement(): void {
  if (customElements.get(ELEMENT_NAME)) return
  const scopedCss = claireCss.replace(/^:root\s*\{/m, ':host, :root {')
  const ClaireElementConstructor = defineCustomElement(ClaireApp, {
    styles: [scopedCss, highlightCss],
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
    let miniToken = ''
    const audience = genericToken ? jwtAudience(genericToken) : null
    if (!sessionToken && audience === 'session') sessionToken = genericToken
    if (!miniToken && audience === 'minitoken') miniToken = genericToken
    const exchangeCandidate = options.ssoToken?.trim() || genericToken
    if (!sessionToken && exchangeCandidate && audience === null) {
      const exchange = await exchangeToken(baseUrl, exchangeCandidate, controller.signal, options.ssoTokenType)
      assertCurrent()
      sessionToken = exchange.session_token
      miniToken = exchange.mini_token ?? ''
    }

    const config = await fetchBootstrap(baseUrl, controller.signal, sessionToken || miniToken)
    assertCurrent()
    config.sessionToken ||= sessionToken || undefined
    config.miniToken ||= miniToken || undefined
    await loadDynamicCss(config, controller.signal)
    assertCurrent()
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
