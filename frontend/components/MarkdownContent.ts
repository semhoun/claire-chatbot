import { computed, defineComponent, h, type PropType, type VNodeChild } from 'vue'
import type { Token } from 'markdown-it'
import { generatedReference, generatedReferencePrefix, renderMarkdown } from '../markdown'
import type { GeneratedFile } from '../types'
import GeneratedAttachment from './GeneratedAttachment.vue'
import CodeBlock from './CodeBlock'
import CopyableBlock from './CopyableBlock.vue'

function quoteText(tokens: Token[]): string {
  return tokens.map(token => {
    if (token.children) return quoteText(token.children)
    if (token.type === 'softbreak' || token.type === 'hardbreak') return '\n'
    if (token.nesting === -1 && token.block) return '\n'
    if (token.nesting !== 0) return ''
    return token.content
  }).join('')
}

export default defineComponent({
  props: {
    text: { type: String, required: true },
    files: { type: Array as PropType<GeneratedFile[]>, required: true },
  },
  setup(props) {
    const tokens = computed(() => renderMarkdown(props.text))
    function render(tokens: Token[]): VNodeChild[] {
      let index = 0
      function children(): VNodeChild[] {
        const nodes: VNodeChild[] = []
        while (index < tokens.length) {
          const token = tokens[index++]
          if (token.nesting === -1) break
          const start = index
          const content = token.nesting === 1 ? children() : token.children ? render(token.children) : []
          if (token.hidden || token.type === 'inline') { nodes.push(...content); continue }
          const reference = token.type === 'generated_file' ? token.content
            : token.type === 'image' ? token.attrGet('src') : token.type === 'link_open' ? token.attrGet('href') : null
          // Streaming can expose a generated URL before its final @@ arrives. Keep
          // every such candidate away from src/href until the file is resolved.
          if (typeof reference === 'string'
            && (generatedReference.test(reference) || reference.startsWith(generatedReferencePrefix))) {
            nodes.push(h(GeneratedAttachment, {
              key: `${token.type}:${reference}:${index}`,
              reference,
              file: props.files.find(file => file.id === reference),
              label: token.type === 'image' ? token.content : token.type === 'link_open'
                ? tokens.slice(start, index - 1).map(child => child.content).join('') || reference : undefined,
              presentation: token.type === 'image' ? 'image' : token.type === 'link_open' ? 'link' : 'resource',
            }, token.type === 'link_open' ? { default: () => content } : undefined))
          } else if (token.type === 'text' || token.type === 'code_inline') {
            nodes.push(token.type === 'text' ? token.content : h('code', token.content))
          } else if (token.type === 'fence' || token.type === 'code_block') {
            const language = token.info.trim().split(/\s+/)[0]
            nodes.push(h(CodeBlock, { code: token.content, language }))
          } else if (token.type === 'softbreak' || token.type === 'hardbreak') {
            nodes.push(h('br'))
          } else if (token.type === 'image') {
            nodes.push(h('img', {
              'data-protected-src': token.attrGet('src'),
              alt: token.content,
              title: token.attrGet('title'),
              class: 'claire-generated-image',
              role: 'button',
              tabindex: 0,
              'aria-label': token.content ? `Agrandir l’image : ${token.content}` : 'Agrandir l’image',
            }))
          } else if (token.type === 'blockquote_open') {
            nodes.push(h(CopyableBlock, {
              text: quoteText(tokens.slice(start, index - 1)).trim(),
              label: 'Copier la citation',
            }, () => h('blockquote', content)))
          } else if (token.tag) {
            // Tags and attributes come only from markdown-it's core rules (raw HTML disabled).
            nodes.push(h(token.tag, Object.fromEntries(token.attrs ?? []), content))
          } else {
            nodes.push(token.content)
          }
        }
        return nodes
      }
      return children()
    }
    return () => h('div', render(tokens.value))
  },
})
