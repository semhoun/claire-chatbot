import { computed, defineComponent, h, type VNodeChild } from 'vue'
import { refractor } from 'refractor/core'
import bash from 'refractor/bash'
import css from 'refractor/css'
import javascript from 'refractor/javascript'
import json from 'refractor/json'
import markdown from 'refractor/markdown'
import markup from 'refractor/markup'
import php from 'refractor/php'
import python from 'refractor/python'
import sql from 'refractor/sql'
import typescript from 'refractor/typescript'
import yaml from 'refractor/yaml'
import CopyableBlock from './CopyableBlock.vue'

for (const syntax of [bash, css, javascript, json, markup, markdown, php, python, sql, typescript, yaml]) {
  refractor.register(syntax)
}
refractor.alias({
  bash: ['sh', 'zsh'],
  javascript: ['js', 'jsx', 'mjs', 'cjs'],
  json: ['jsonc', 'json5'],
  markdown: ['md', 'mkdown', 'mkd'],
  markup: ['xml', 'html', 'xhtml', 'rss', 'atom', 'xjb', 'xsd', 'xsl', 'plist', 'wsf', 'svg'],
  python: ['py', 'gyp', 'ipython'],
  typescript: ['ts', 'tsx', 'mts', 'cts'],
  yaml: ['yml'],
})
const languages = new Set(refractor.listLanguages())

export default defineComponent({
  props: {
    code: { type: String, required: true },
    language: { type: String, default: '' },
  },
  setup(props) {
    const language = computed(() => props.language.toLowerCase())
    // Keep completed blocks cached while the rest of their message streams.
    const tokens = computed(() => languages.has(language.value)
      ? refractor.highlight(props.code, language.value).children : null)

    function render(token: ReturnType<typeof refractor.highlight>['children'][number]): VNodeChild {
      if (token.type === 'text') return token.value
      // Only spans and text enter Vue, never tokenizer tags, attributes or HTML.
      if (token.type === 'element') return h('span', { class: token.properties.className }, token.children.map(render))
      return ''
    }

    return () => h(CopyableBlock, { text: props.code, label: 'Copier le bloc de texte' }, () => h('pre', [h('code', {
      class: ['claire-code', language.value ? `language-${language.value}` : undefined],
    }, tokens.value ? tokens.value.map(render) : props.code)]))
  },
})
