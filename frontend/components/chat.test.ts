// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { nextTick, reactive } from 'vue'
import { mount } from '@vue/test-utils'
import ChatMessages from './ChatMessages.vue'
import MarkdownContent from './MarkdownContent'
import * as markdown from '../markdown'
import type { ChatMessage } from '../types'

describe('safe Vue chat rendering', () => {
  it('defers Markdown image URLs to resource authorization without losing labels or titles', () => {
    const wrapper = mount(MarkdownContent, { props: {
      text: '![Photo](/files/serve/upload "Original")\n\n![Public](https://images.test/photo.png)', files: [],
    } })
    expect(wrapper.findAll('img').map(image => image.attributes('data-protected-src')))
      .toEqual(['/files/serve/upload', 'https://images.test/photo.png'])
    expect(wrapper.findAll('img[src]')).toHaveLength(0)
    expect(wrapper.get('img').attributes()).toMatchObject({ alt: 'Photo', title: 'Original' })
    wrapper.unmount()
  })

  it('renders the generated img syntax advertised by the brain without activating HTML attributes', () => {
    const id = '@@GENERATED@@83b00160-e9d9-4cae-b334-09f496c1f028@@'
    const wrapper = mount(MarkdownContent, { props: {
      text: `Avant <img alt="Vue &amp; profil" src="${id}" onerror="alert(1)"> apres\n\n\`<img src="${id}">\``,
      files: [{ id, name: 'image.png', type: 'image', url: `/files/serve/${encodeURIComponent(id)}` }],
    } })
    expect(wrapper.get('p').text()).toBe('Avant  apres')
    expect(wrapper.get('img').attributes('alt')).toBe('Vue & profil')
    expect(wrapper.find('[onerror]').exists()).toBe(false)
    expect(wrapper.get('code').text()).toBe(`<img src="${id}">`)
    wrapper.unmount()
  })

  it('only parses the changing bubble during streaming', async () => {
    const render = vi.spyOn(markdown, 'renderMarkdown')
    const messages = reactive(Array.from({ length: 100 }, (_, index): ChatMessage => ({
      id: String(index), message: `Message ${index}`, sent: false, time: '', toolsCall: [], files: [],
    })))
    const wrapper = mount(ChatMessages, { props: { messages, loading: false, audioEnabled: false, playing: null, pending: new Set<string>(), ready: new Map<string, Blob>(), failed: new Set<string>() } })
    try {
      expect(render).toHaveBeenCalledTimes(100)
      render.mockClear()
      messages[99].message = '**Streaming**'
      await nextTick()
      expect(render).toHaveBeenCalledExactlyOnceWith('**Streaming**')
      render.mockClear()
      messages[99].toolsCall = [{ id: 'tool', name: 'tool', inputs: [], running: true, result: null }]
      await nextTick()
      expect(render).not.toHaveBeenCalled()
    } finally { wrapper.unmount(); render.mockRestore() }
  })

  it.each([
    '<script>alert(1)</script><img src=x onerror=alert(1)>',
    '[click](javascript:alert(1))',
    '[click](jav&#x61;script:alert(1))',
    '![image](data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+) ',
    '<svg><a xlink:href="javascript:alert(1)">click</a></svg>',
    '[click](vbscript:msgbox(1))',
    '```html\n<img src=x onerror=alert(1)>\n```',
    '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
  ])('does not create active markup from %s', text => {
    const wrapper = mount(MarkdownContent, { props: { text, files: [] } })
    const root = wrapper.element
    expect(root.querySelector('script, iframe, svg:not(.claire-icon), [onerror], [onload]')).toBeNull()
    for (const element of root.querySelectorAll('[href], [src]')) {
      expect(element.getAttribute('href') || element.getAttribute('src')).not.toMatch(/^(javascript|vbscript|data|file):/i)
    }
    wrapper.unmount()
  })

  it('keeps generated images in table cells and labeled links in ordered steps', () => {
    const image = '@@GENERATED@@picture@@'
    const pdf = '@@GENERATED@@document@@'
    const wrapper = mount(MarkdownContent, { props: {
      text: `Avant\n\n| Gauche | Droite |\n| - | - |\n| ![Vue de face](${image}) | ![Vue de profil](${image}) |\n\n1. Avant [**Lire le rapport**](${pdf}) après.\n2. Ensuite [le même rapport](${pdf}).\n\nAprès`,
      files: [{ id: image, name: 'picture.png', type: 'image', url: '/files/serve/picture' }, { id: pdf, name: 'document.pdf', type: 'pdf', url: '/files/serve/document' }],
    } })
    expect(wrapper.findAll('td img').map(image => image.attributes('alt'))).toEqual(['Vue de face', 'Vue de profil'])
    expect(wrapper.findAll('td img').every(image => !image.attributes('src'))).toBe(true)
    const steps = wrapper.findAll('li')
    expect(steps[0].get('a strong').text()).toBe('Lire le rapport')
    expect(steps[0].text()).toMatch(/^Avant Lire le rapport Télécharger après\.$/)
    expect(steps[1].get('a').text()).toBe('le même rapport')
    expect(wrapper.findAll('a[download]')).toHaveLength(2)
    expect(Array.from(wrapper.element.children as HTMLCollectionOf<Element>).map(element => element.tagName)).toEqual(['P', 'TABLE', 'OL', 'P'])
    wrapper.unmount()
  })

  it('preserves inline, fenced and indented code literals without creating attachments', () => {
    const id = '@@GENERATED@@file@@'
    const literal = `[Exemple](${id}) ![Image](${id}) ${id}`
    const wrapper = mount(MarkdownContent, { props: {
      text: `\`${literal}\`\n\n\`\`\`markdown\n${literal}\n\`\`\`\n\n    ${literal}`,
      files: [{ id, name: 'file.png', type: 'image', url: '/files/serve/file' }],
    } })
    expect(wrapper.findAll('code').map(code => code.text())).toEqual([literal, literal, literal])
    expect(wrapper.find('img, a, audio, .claire-generated-resource').exists()).toBe(false)
    wrapper.unmount()
  })

  it('keeps unresolved references and labels visible, and resolves them in place later', async () => {
    const id = '@@GENERATED@@missing@@'
    const wrapper = mount(MarkdownContent, { props: { text: `Avant [Lire ici](${id}) puis ![Aperçu](${id}) et ${id} après`, files: [] } })
    expect(wrapper.text()).toBe(`Avant Lire ici (${id}) puis Aperçu (${id}) et ${id} après`)
    expect(wrapper.find('a, img, audio').exists()).toBe(false)
    await wrapper.setProps({ files: [{ id, name: 'image.png', type: 'image', url: '/files/serve/image' }] })
    expect(wrapper.get('a').text()).toBe('Lire ici')
    expect(wrapper.findAll('img').map(image => image.attributes('alt'))).toEqual(['Aperçu', 'image.png'])
    expect(wrapper.find('.claire-generated-unresolved').exists()).toBe(false)
    wrapper.unmount()
  })

  it('does not present a returned tool error as success or offer speech for terminal errors', async () => {
    const entry: ChatMessage = { id: 'answer', sent: false, time: '', message: '', files: [],
      toolsCall: [{ id: 'tool', name: 'generate_pdf', inputs: [], running: false,
        result: JSON.stringify({ status: 'error', message: 'PDF unavailable' }) }],
    }
    const wrapper = mount(ChatMessages, { props: { messages: [entry], loading: false, audioEnabled: true,
      playing: null, pending: new Set<string>(), ready: new Map<string, Blob>(), failed: new Set<string>() } })
    expect(wrapper.find('.claire-tools-failed').exists()).toBe(true)
    expect(wrapper.find('.claire-toolcall__icon--done').exists()).toBe(false)
    expect(wrapper.find('.claire-tools-running-flag').exists()).toBe(false)
    await wrapper.setProps({ messages: [{ ...entry, id: 'generation-error', error: true, toolsCall: [], message: 'Failure' }] })
    expect(wrapper.get('[role="alert"]').text()).toContain('Failure')
    expect(wrapper.find('[data-audio-listen]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('renders Markdown, escaped tools, timestamps, pending resources and final attachments in Vue', async () => {
    const entry: ChatMessage = { id: 'answer', sent: false, time: '2026-09-12T12:30:00Z',
      message: '**Bonjour**\n\n| A | B |\n| - | - |\n| 1 | 2 |\n\n@@GENERATED@@file@@',
      files: [{ id: '@@GENERATED@@file@@', type: 'pending', name: 'Génération', url: null }],
      toolsCall: [{ id: 'tool', name: '<script>tool</script>', inputs: [{ name: 'x', value: { html: '<img onerror=alert(1)>' } }], running: true, result: null }],
    }
    const wrapper = mount(ChatMessages, { props: { messages: [entry], loading: true, audioEnabled: true, playing: null, pending: new Set<string>(), ready: new Map<string, Blob>(), failed: new Set<string>() } })
    expect(wrapper.get('strong').text()).toBe('Bonjour')
    expect(wrapper.find('table').exists()).toBe(true)
    expect(wrapper.find('script').exists()).toBe(false)
    expect(wrapper.find('[onerror]').exists()).toBe(false)
    expect(wrapper.find('.claire-tools-running-flag').exists()).toBe(true)
    expect(wrapper.find('.claire-generated-image-placeholder').exists()).toBe(true)
    expect(wrapper.find('a.claire-generated-file').exists()).toBe(false)
    expect(wrapper.get('.claire-message__meta').text()).toMatch(/\d{2}:\d{2}/)
    wrapper.get<HTMLDetailsElement>('details').element.open = true
    await wrapper.setProps({ loading: false, messages: [{ ...entry, toolsCall: [{ ...entry.toolsCall[0], running: false, result: '<img src=x onerror=alert(1)>' }], files: [{ id: '@@GENERATED@@file@@', type: 'pdf', name: 'result.pdf', url: '/files/serve/file' }] }] })
    expect(wrapper.find('.claire-tools-running-flag').exists()).toBe(false)
    expect(wrapper.get<HTMLDetailsElement>('details').element.open).toBe(true)
    expect(wrapper.get('pre.claire-toolcall__result').text()).toBe('<img src=x onerror=alert(1)>')
    expect(wrapper.get('a[download]').attributes('download')).toBe('result.pdf')
    expect(wrapper.find('.claire-typing-indicator').exists()).toBe(false)
    wrapper.unmount()
  })
})
