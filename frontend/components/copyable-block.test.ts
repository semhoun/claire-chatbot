// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import MarkdownContent from './MarkdownContent'

afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals() })

describe('Markdown block copying', () => {
  it.each(['```js\n  const x = 1;  \n\n```', '```text\n  const x = 1;  \n\n```', '    const x = 1;  \n']) (
    'copies literal preformatted content: %s', async text => {
      const writeText = vi.fn().mockResolvedValue(undefined)
      vi.stubGlobal('navigator', { clipboard: { writeText } })
      const wrapper = mount(MarkdownContent, { props: { text, files: [] } })
      const code = wrapper.get('code').element.textContent
      await wrapper.get('button').trigger('click')
      await flushPromises()
      expect(writeText).toHaveBeenCalledExactlyOnceWith(code)
      expect(wrapper.get('[role="status"]').text()).toBe('Copié !')
      expect(wrapper.get('button').attributes('aria-label')).toBe('Copier le bloc de texte')
      wrapper.unmount()
    },
  )

  it('copies readable quotes without Markdown markers or nested button feedback', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { clipboard: { writeText } })
    const wrapper = mount(MarkdownContent, { props: {
      text: '> **Bonjour** [monde](https://example.com)\n> suite\n>\n> Deuxième paragraphe.\n>\n> > Citation interne.', files: [],
    } })
    const buttons = wrapper.findAll('button')
    await buttons[1].trigger('click')
    await flushPromises()
    await buttons[0].trigger('click')
    await flushPromises()
    expect(writeText.mock.calls).toEqual([
      ['Citation interne.'], ['Bonjour monde\nsuite\nDeuxième paragraphe.\nCitation interne.'],
    ])
    wrapper.unmount()
  })

  it.each(['```text\ninitial', '> initial'])('copies the latest streamed content for %s', async text => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { clipboard: { writeText } })
    const wrapper = mount(MarkdownContent, { props: { text, files: [] } })
    await wrapper.get('button').trigger('click')
    await flushPromises()
    await wrapper.setProps({ text: text + ' updated' })
    expect(wrapper.get('[role="status"]').text()).toBe('')
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(writeText).toHaveBeenLastCalledWith('initial updated')
    wrapper.unmount()
  })

  it('reports rejection and permits retry without an unhandled error', async () => {
    const writeText = vi.fn().mockRejectedValueOnce(new Error('Denied')).mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { clipboard: { writeText } })
    const wrapper = mount(MarkdownContent, { props: { text: '> quote', files: [] } })
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(wrapper.get('[role="status"]').text()).toBe('Copie impossible. Réessayez.')
    expect(wrapper.get('button').element.disabled).toBe(false)
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(wrapper.get('[role="status"]').text()).toBe('Copié !')
    wrapper.unmount()
  })

  it.each([true, false])('keeps the clipboard fallback scoped in Shadow DOM (success=%s)', async success => {
    vi.stubGlobal('navigator', {})
    const host = document.createElement('div')
    document.body.append(host)
    const shadow = host.attachShadow({ mode: 'open' })
    const target = document.createElement('div')
    shadow.append(target)
    const execCommand = vi.fn(() => {
      expect(shadow.querySelector('textarea')?.value).toBe('quote')
      expect(document.querySelector('textarea')).toBeNull()
      return success
    })
    Object.defineProperty(document, 'execCommand', { configurable: true, value: execCommand })
    const wrapper = mount(MarkdownContent, { attachTo: target, props: { text: '> quote', files: [] } })
    try {
      wrapper.get('button').element.focus()
      await wrapper.get('button').trigger('click')
      await flushPromises()
      expect(execCommand).toHaveBeenCalledWith('copy')
      expect(shadow.querySelector('textarea')).toBeNull()
      expect(shadow.activeElement).toBe(wrapper.get('button').element)
      expect(wrapper.get('[role="status"]').text()).toBe(success ? 'Copié !' : 'Copie impossible. Réessayez.')
    } finally {
      wrapper.unmount()
      host.remove()
      Reflect.deleteProperty(document, 'execCommand')
    }
  })

  it('does not add controls to ordinary paragraphs or inline code', () => {
    const wrapper = mount(MarkdownContent, { props: { text: 'Text with `inline code`.', files: [] } })
    expect(wrapper.find('button').exists()).toBe(false)
    wrapper.unmount()
  })
})
