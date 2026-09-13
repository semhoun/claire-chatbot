// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { refractor } from 'refractor/core'
import CodeBlock from './CodeBlock'
import MarkdownContent from './MarkdownContent'

describe('reactive syntax highlighting', () => {
  it('renders initial code with nested token spans and exact literal text', () => {
    const code = 'const message = `Hello ${name}`;\n'
    const wrapper = mount(CodeBlock, { props: { code, language: 'javascript' } })
    expect(wrapper.get('code').element.textContent).toBe(code)
    expect(wrapper.get('.token.keyword').text()).toBe('const')
    expect(wrapper.find('.token.template-string .token.interpolation').exists()).toBe(true)
    expect(wrapper.findAll('code *').every(node => node.element.tagName === 'SPAN')).toBe(true)
    wrapper.unmount()
  })

  it('updates partial fences and replaced code, then caches completed blocks during streaming', async () => {
    const highlight = vi.spyOn(refractor, 'highlight')
    const first = '```js\nconst first = 1;\n```\n\n'
    const wrapper = mount(MarkdownContent, { props: { text: first + '```js\nconst value = "par', files: [] } })
    try {
      expect(highlight).toHaveBeenCalledTimes(2)
      const blocks = wrapper.findAllComponents(CodeBlock)
      expect(wrapper.findAll('code')[1].element.textContent).toBe('const value = "par')
      highlight.mockClear()
      await wrapper.setProps({ text: first + '```js\nconst value = "partial";\n' })
      expect(highlight).toHaveBeenCalledExactlyOnceWith('const value = "partial";\n', 'js')
      expect(wrapper.findAll('code')[1].get('.token.string').text()).toBe('"partial"')
      expect(wrapper.findAllComponents(CodeBlock)[0].vm).toBe(blocks[0].vm)
      highlight.mockClear()
      await wrapper.setProps({ text: first + '```js\nconst value = "partial";\n```\n\nFinished.' })
      expect(highlight).not.toHaveBeenCalled()
      expect(wrapper.findAllComponents(CodeBlock)[1].vm).toBe(blocks[1].vm)
      await wrapper.setProps({ text: first + '```js\nlet changed = false;\n```\n\nFinished. More text.' })
      expect(highlight).toHaveBeenCalledExactlyOnceWith('let changed = false;\n', 'js')
      expect(wrapper.findAll('code')[1].element.textContent).toBe('let changed = false;\n')
      expect(wrapper.findAll('code')[1].get('.token.boolean').text()).toBe('false')
    } finally { wrapper.unmount(); highlight.mockRestore() }
  })

  it('retokenizes when the fence language changes', async () => {
    const wrapper = mount(MarkdownContent, { props: { text: '```unknown\nconst x = 1;\n```', files: [] } })
    expect(wrapper.find('.token').exists()).toBe(false)
    await wrapper.setProps({ text: '```JS title\nconst x = 1;\n```' })
    expect(wrapper.get('code').classes()).toContain('language-js')
    expect(wrapper.get('.token.keyword').text()).toBe('const')
    await wrapper.setProps({ text: '```unknown\nconst x = 1;\n```' })
    expect(wrapper.find('.token').exists()).toBe(false)
    expect(wrapper.get('code').element.textContent).toBe('const x = 1;\n')
    wrapper.unmount()
  })

  const samples = [
    { languages: ['bash', 'sh', 'zsh'], code: 'echo "$HOME"' },
    { languages: ['css'], code: 'a { color: red; }' },
    { languages: ['javascript', 'js', 'jsx', 'mjs', 'cjs'], code: 'const x = true;' },
    { languages: ['json', 'jsonc', 'json5'], code: '{"x": true}' },
    { languages: ['markdown', 'md', 'mkdown', 'mkd'], code: '**bold**' },
    { languages: ['php'], code: '<?php echo "hello";' },
    { languages: ['python', 'py', 'gyp', 'ipython'], code: 'return True' },
    { languages: ['sql'], code: 'SELECT * FROM users;' },
    { languages: ['typescript', 'ts', 'tsx', 'mts', 'cts'], code: 'const x: number = 1;' },
    { languages: ['xml', 'html', 'xhtml', 'rss', 'atom', 'xjb', 'xsd', 'xsl', 'plist', 'wsf', 'svg'], code: '<tag attr="value" />' },
    { languages: ['yaml', 'yml'], code: 'key: true' },
  ]
  it.each(samples.flatMap(({ languages, code }) => languages.map(language => ({ language, code }))))(
    'supports the existing $language language or alias', ({ language, code }) => {
      const wrapper = mount(CodeBlock, { props: { language, code } })
      expect(wrapper.find('.token').exists()).toBe(true)
      expect(wrapper.get('code').element.textContent).toBe(code)
      wrapper.unmount()
    },
  )

  it.each(['unknown', '', 'text', 'plaintext', 'nohighlight', '__proto__', 'constructor', 'extend', 'insertBefore'])(
    'renders %s code as plain text without guessing a language', language => {
      const wrapper = mount(CodeBlock, { props: { language, code: 'const x = "<img onerror=alert(1)>";\n' } })
      expect(wrapper.find('code span, img').exists()).toBe(false)
      expect(wrapper.get('code').element.textContent).toBe('const x = "<img onerror=alert(1)>";\n')
      wrapper.unmount()
    },
  )

  it.each(['html', 'javascript', 'markdown', 'unknown'])('escapes markup and entities in %s tokens', async language => {
    const code = '<script>alert("&amp;")</script><img src=x onerror=alert(1)> & < > " \'\n'
    const wrapper = mount(CodeBlock, { props: { code, language } })
    expect(wrapper.get('code').element.textContent).toBe(code)
    expect(wrapper.find('script, img, [onerror], a').exists()).toBe(false)
    await wrapper.setProps({ code: code + '<svg onload=alert(1)>&#60;</svg>' })
    expect(wrapper.get('code').element.textContent).toBe(code + '<svg onload=alert(1)>&#60;</svg>')
    expect(wrapper.find('code script, code img, code svg, [onerror], [onload]').exists()).toBe(false)
    wrapper.unmount()
  })
})
