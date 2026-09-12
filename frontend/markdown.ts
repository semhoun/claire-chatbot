import MarkdownIt from 'markdown-it'

const markdown = new MarkdownIt({ html: false, linkify: true, breaks: true })
// No active protocols or inline data, even for images. Raw HTML remains text.
markdown.validateLink = url => !/^[\s\u0000-\u0020]*(?:javascript|vbscript|file|data):/i.test(url)

export const generatedReference = /^@@GENERATED@@[a-zA-Z0-9_@.\-]*@@$/

// The image tool also emits this syntax. Recognize only generated img references,
// not arbitrary HTML; attributes are rebuilt from an explicit passive allowlist.
markdown.inline.ruler.before('html_inline', 'generated_image', (state, silent) => {
  const match = /^<img\b((?:\s+[a-zA-Z][\w:-]*(?:\s*=\s*(?:"[^"]*"|'[^']*'|[^\s"'=<>`]+))?)*\s*)\/?>/i.exec(state.src.slice(state.pos))
  if (!match) return false
  const attributes = new Map<string, string>()
  for (const attribute of match[1].matchAll(/([a-zA-Z][\w:-]*)\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+))/g)) {
    attributes.set(attribute[1].toLowerCase(), markdown.utils.unescapeAll(attribute[2] ?? attribute[3] ?? attribute[4]))
  }
  const src = attributes.get('src') ?? ''
  if (!generatedReference.test(src)) return false
  if (!silent) {
    const token = state.push('image', 'img', 0)
    token.attrSet('src', src)
    token.content = attributes.get('alt') ?? ''
    if (attributes.has('title')) token.attrSet('title', attributes.get('title')!)
  }
  state.pos += match[0].length
  return true
})

// Only ordinary inline text is split: code literals and link/image labels stay intact.
markdown.core.ruler.after('linkify', 'generated_file', state => {
  for (const block of state.tokens) {
    if (block.type !== 'inline' || !block.children) continue
    let linkLevel = 0
    block.children = block.children.flatMap(token => {
      if (token.type === 'link_open') linkLevel++
      if (token.type === 'link_close') linkLevel--
      if (token.type !== 'text' || linkLevel > 0) return [token]
      const result = []
      let offset = 0
      for (const match of token.content.matchAll(/@@GENERATED@@[a-zA-Z0-9_@.\-]*@@/g)) {
        const text = new state.Token('text', '', 0)
        text.content = token.content.slice(offset, match.index)
        const reference = new state.Token('generated_file', '', 0)
        reference.content = match[0]
        result.push(text, reference)
        offset = match.index + match[0].length
      }
      const rest = new state.Token('text', '', 0)
      rest.content = token.content.slice(offset)
      return [...result, rest]
    })
  }
})

export function renderMarkdown(text: string) {
  return markdown.parse(text, {})
}
