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
  tokenType?: string,
): Promise<{ session_token: string; mini_token?: string }> {
  const response = await window.fetch(`${baseUrl}/auth/embed/exchange`, {
    method: 'POST',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify({
      sso_token: token,
      ...(tokenType?.trim() ? { sso_token_type: tokenType.trim() } : {}),
    }),
  })
  if (!response.ok) throw new Error(`SSO exchange failed with status ${response.status}`)
  return response.json() as Promise<{ session_token: string; mini_token?: string }>
}

async function fetchBootstrap(baseUrl: string, authToken?: string): Promise<ClaireBootstrap> {
  const url = new URL(`${baseUrl}/embed`)
  if (authToken) url.searchParams.set('token', authToken)
  const response = await window.fetch(url, { headers: { Accept: 'text/html' } })
  if (!response.ok) throw new Error(`Embed page fetch failed with status ${response.status}`)
  const documentFragment = new DOMParser().parseFromString(await response.text(), 'text/html')
  const bootstrap = documentFragment.querySelector<HTMLElement>('.claire-embed-bootstrap')
  if (bootstrap === null) throw new Error('Embed bootstrap payload is missing')
  const config = parseBootstrap(bootstrap)
  config.sessionToken = response.headers.get('X-Claire-Token') ?? undefined
  config.miniToken = response.headers.get('X-Claire-Minitoken') ?? undefined
  return config
}

async function loadDynamicCss(config: ClaireBootstrap): Promise<void> {
  const parts = [config.brainInfo.cssInline ?? '']
  if (config.brainInfo.css) {
    const response = await window.fetch(`${config.baseUrl}/css/${config.brainInfo.css}`)
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
  currentElement?.remove()
  currentElement = null
  document.querySelector(`#${CONTAINER_ID}`)?.remove()
}

async function claireEmbed(options: ClaireEmbedConfig = {}): Promise<HTMLElement> {
  destroyClaireEmbed()
  const baseUrl = normalizedBaseUrl(options.baseUrl)
  const genericToken = options.token?.trim() ?? ''
  let sessionToken = options.sessionToken?.trim() ?? ''
  let miniToken = ''
  const audience = genericToken ? jwtAudience(genericToken) : null
  if (!sessionToken && audience === 'session') sessionToken = genericToken
  if (!miniToken && audience === 'minitoken') miniToken = genericToken
  const exchangeCandidate = options.ssoToken?.trim() || genericToken
  if (!sessionToken && exchangeCandidate && audience === null) {
    const exchange = await exchangeToken(baseUrl, exchangeCandidate, options.ssoTokenType)
    sessionToken = exchange.session_token
    miniToken = exchange.mini_token ?? ''
  }

  const config = await fetchBootstrap(baseUrl, sessionToken || miniToken)
  config.sessionToken ||= sessionToken || undefined
  config.miniToken ||= miniToken || undefined
  await loadDynamicCss(config)
  registerElement()

  const container = document.createElement('div')
  container.id = CONTAINER_ID
  const element = document.createElement(ELEMENT_NAME) as ClaireElement
  element.config = config
  element.addEventListener('claire:logout', destroyClaireEmbed)
  container.appendChild(element)
  resolveTarget(options.target).appendChild(container)
  currentElement = element
  return element
}

window.claireEmbed = claireEmbed
window.destroyClaireEmbed = destroyClaireEmbed
