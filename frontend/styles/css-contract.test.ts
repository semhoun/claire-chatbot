import { readFileSync, readdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import postcss from 'postcss'
import { describe, expect, it } from 'vitest'

const directory = fileURLToPath(new URL('.', import.meta.url))
const files = readdirSync(directory).filter(file => file.endsWith('.css'))
const sources = new Map(files.map(file => [file, readFileSync(`${directory}/${file}`, 'utf8')]))
const roots = [...sources].map(([file, source]) => postcss.parse(source, { from: file }))
const themeDirectory = fileURLToPath(new URL('../../config/themes/', import.meta.url))
const contract = JSON.parse(readFileSync(`${themeDirectory}/contract.json`, 'utf8')) as {
  tokens: string[]
  variants: Record<string, string[]>
}
const references = (value: string) => [...value.matchAll(/var\(\s*(--[\w-]+)/g)].map(match => match[1]!)
const foundations = roots.find(root => root.source?.input.file?.endsWith('/foundations.css'))!
const defaults: Record<string, string> = {}
foundations.walkDecls(/^--/, declaration => { defaults[declaration.prop] = declaration.value })

// The catalog deliberately uses only two mappings and single-line scalar values.
// Validate this strict YAML subset rather than adding a YAML runtime dependency.
const presets = Object.fromEntries(readdirSync(themeDirectory).filter(file => file.endsWith('.yaml')).map(file => {
  const preset: { tokens: Record<string, string>; variants: Record<string, string> } = { tokens: {}, variants: {} }
  let section: 'tokens' | 'variants' | undefined
  for (const line of readFileSync(`${themeDirectory}/${file}`, 'utf8').split('\n')) {
    if (!line.trim() || line.startsWith('#')) continue
    if (line === 'tokens:' || line === 'variants:') {
      section = line === 'tokens:' ? 'tokens' : 'variants'
      continue
    }
    const match = section === 'tokens'
      ? /^  (--claire-[\w-]+): '((?:[^']|'')*)'$/.exec(line)
      : /^  (\w+): (\w+)$/.exec(line)
    if (!section || !match) throw new Error(`Unsupported catalog structure in ${file}: ${line}`)
    if (Object.hasOwn(preset[section], match[1]!)) throw new Error(`Duplicate key in ${file}: ${line}`)
    preset[section][match[1]!] = match[2]!.replace(/''/g, "'")
  }
  return [file.replace('.yaml', ''), preset]
}))

describe('common CSS contract', () => {
  it('keeps themed gutters viewport-wide and limits compact framing to the normal chat panel on desktop', () => {
    const declarations = (file: string, selector: string) => {
      const values: Record<string, string> = {}
      roots.find(root => root.source?.input.file?.endsWith(`/${file}`))!.walkRules(selector, rule => {
        rule.walkDecls(declaration => { values[declaration.prop] = declaration.value })
      })
      return values
    }
    expect(declarations('layout.css', '#claire-vue-app')).toEqual({ width: '100%', 'min-width': '0' })
    expect(declarations('layout.css', '.claire-app')).toMatchObject({ width: '100%', height: '100dvh', overflow: 'hidden' })
    expect(declarations('foundations.css', '.claire-app,\n.claire-app-base').background).toBe('var(--claire-body-background)')
    const selector = '.claire-app[data-mode="normal"][data-layout="compact"] > .claire-chat-panel'
    expect(declarations('responsive.css', selector)).toEqual({
      'max-width': '800px', margin: '24px auto', height: 'calc(100dvh - 48px)',
      border: 'var(--claire-border-width) solid var(--claire-border)',
      'border-radius': 'var(--claire-radius)', 'box-shadow': 'var(--claire-shadow)',
    })
    for (const root of roots) root.walkRules(rule => {
      expect(rule.selector).not.toContain('claire-compact')
      if (!rule.selector.includes('[data-layout=')) return
      expect(rule.selector).toBe(selector)
      expect(rule.parent?.type).toBe('atrule')
      expect((rule.parent as postcss.AtRule).params).toBe('(min-width: 681px)')
    })
    expect(declarations('layout.css', '.claire-chat-panel')).toMatchObject({ position: 'relative', height: '100%', overflow: 'hidden' })
    expect(declarations('feedback.css', '.claire-modal')).toMatchObject({ position: 'fixed', inset: '0' })
    expect(declarations('embed.css', '.claire-app[data-mode="embed"]')).toMatchObject({ background: 'transparent', 'pointer-events': 'none' })
  })

  it('keeps empty-composer upload and undo controls available at narrow widths', () => {
    const responsive = roots.find(root => root.source?.input.file?.endsWith('/responsive.css'))!
    responsive.walkRules(rule => {
      if (!/\.claire-chat-(?:input|icon-btn)/.test(rule.selector)) return
      rule.walkDecls(declaration => {
        expect(`${declaration.prop}: ${declaration.value}`).not.toMatch(/^(?:display: none|visibility: hidden|opacity: 0)$/)
      })
    })
    const chat = roots.find(root => root.source?.input.file?.endsWith('/chat.css'))!
    chat.walkRules(rule => {
      if (!rule.selector.includes('.claire-chat-input__toggleable')) return
      rule.walkDecls('display', declaration => {
        if (declaration.value !== 'none') return
        expect(rule.selector).toContain('.claire-chat-input__form--typing')
        expect(rule.selector).toContain(':not(.claire-chat-icon-btn--upload)')
      })
    })
  })

  it('truncates empty composer placeholders without disabling multiline input', () => {
    const chat = roots.find(root => root.source?.input.file?.endsWith('/chat.css'))!
    const declarations = new Map<string, string>()
    chat.walkRules('.claire-chat-input__field--multiline:placeholder-shown', rule => {
      rule.walkDecls(declaration => { declarations.set(declaration.prop, declaration.value) })
    })
    expect(declarations.get('white-space')).toBe('nowrap')
    expect(declarations.get('text-overflow')).toBe('ellipsis')
    chat.walkRules('.claire-chat-input__field--multiline', rule => {
      rule.walkDecls('white-space', declaration => { expect(declaration.value).not.toBe('nowrap') })
    })
  })

  it('imports every responsibility once through the shared entry point', () => {
    const imports: string[] = []
    roots.find(root => root.source?.input.file?.endsWith('/index.css'))!.walkAtRules('import', rule => {
      imports.push(rule.params.replace(/['"]/g, '').replace('./', ''))
    })
    expect(imports).toEqual([
      'foundations.css', 'layout.css', 'chat.css', 'options.css',
      'feedback.css', 'embed.css', 'variants.css', 'responsive.css',
    ])
    expect([...imports, 'index.css'].sort()).toEqual(files.sort())
  })

  it('defines every consumed custom property in the common foundations without cycles', () => {
    const definitions = new Map<string, string>()
    const foundations = roots.find(root => root.source?.input.file?.endsWith('/foundations.css'))!
    foundations.walkDecls(/^--/, declaration => { definitions.set(declaration.prop, declaration.value) })
    const missing = new Set<string>()
    for (const root of roots) root.walkDecls(declaration => {
      for (const name of references(declaration.value)) if (!definitions.has(name)) missing.add(name)
    })
    expect([...missing]).toEqual([])
    const visit = (name: string, parents: string[] = []) => {
      expect(parents, `Circular token alias: ${[...parents, name].join(' -> ')}`).not.toContain(name)
      for (const dependency of references(definitions.get(name) ?? '')) visit(dependency, [...parents, name])
    }
    for (const name of definitions.keys()) visit(name)
    expect(foundations.nodes.filter(node => node.type === 'rule').map(node => node.selector.replace(/\s+/g, ' ')))
      .toContain('.claire-app, .claire-app-base')
  })

  it('publishes a deliberate appearance API, not layout or legacy palette internals', () => {
    expect(new Set(contract.tokens).size).toBe(contract.tokens.length)
    expect(contract.variants).toEqual({ controls: ['solid', 'outline', 'soft'], effects: ['none', 'glow', 'satin'] })
    for (const token of contract.tokens) {
      expect(defaults, token).toHaveProperty(token)
      expect(token).not.toMatch(/(?:width|height|gutter|padding|gap|size|motion|hero|bg-main|ink-dark)$/)
    }
    expect(Object.keys(presets).sort()).toEqual(['cyberpunk', 'dark', 'energy', 'light', 'neon', 'romantic'])
    for (const [name, preset] of Object.entries(presets)) {
      expect(Object.keys(preset.tokens).length, name).toBeGreaterThan(25)
      expect(Object.keys(preset.variants).sort()).toEqual(Object.keys(contract.variants).sort())
      for (const [token, value] of Object.entries(preset.tokens)) {
        expect(contract.tokens, `${name}: ${token}`).toContain(token)
        expect(value).not.toMatch(/[{};]|@import|url\(/i)
        const parsed = postcss.parse(`.theme { ${token}: ${value}; }`)
        expect(parsed.nodes).toHaveLength(1)
        for (const reference of references(value)) expect(contract.tokens).toContain(reference)
      }
      for (const [variant, value] of Object.entries(preset.variants)) {
        expect(contract.variants[variant], `${name}: ${variant}`).toContain(value)
      }
      const values = { ...defaults, ...preset.tokens }
      const visit = (token: string, parents: string[] = []) => {
        expect(parents, `${name}: circular alias ${token}`).not.toContain(token)
        expect(values, `${name}: missing ${token}`).toHaveProperty(token)
        for (const dependency of references(values[token]!)) visit(dependency, [...parents, token])
      }
      for (const token of Object.keys(values)) visit(token)
    }
  })

  it('keeps the documented public token list synchronized with the contract', () => {
    const readme = readFileSync(new URL('../../README.md', import.meta.url), 'utf8')
    const section = readme.split('### API interne des thèmes')[1]!.split('\n## ')[0]!
    const documented = [...section.matchAll(/`(--claire-[a-z0-9-]+)`/g)].map(match => match[1]!)
    expect(documented.sort()).toEqual([...contract.tokens].sort())
  })

  it('matches every cyberpunk catalog value with the safe foundation fallback', () => {
    expect(Object.keys(presets.cyberpunk!.tokens).sort()).toEqual([...contract.tokens].sort())
    for (const [token, value] of Object.entries(presets.cyberpunk!.tokens)) expect(defaults[token], token).toBe(value)
    expect(presets.cyberpunk!.variants).toEqual({ controls: 'solid', effects: 'glow' })
  })

  it('isolates all defaults and aliases on the same app boundary as inline overrides', () => {
    foundations.walkDecls(/^--/, declaration => {
      expect((declaration.parent as postcss.Rule).selector.replace(/\s+/g, ' ')).toBe('.claire-app, .claire-app-base')
      expect(declaration.important).toBeFalsy()
    })
    for (const root of roots) root.walkRules(rule => {
      expect(rule.selector).not.toMatch(/\[data-theme\s*=/)
      if (rule.selector === '#claire-body') rule.walkDecls(declaration => {
        expect(references(declaration.value)).toEqual([])
        if (declaration.prop === 'background') expect(declaration.value).toBe('Canvas')
      })
    })
    const consumers = new Set<string>()
    for (const root of roots) root.walkDecls(declaration => {
      for (const token of references(declaration.value)) consumers.add(token)
    })
    for (const token of contract.tokens) expect(consumers, `Unused public token: ${token}`).toContain(token)
  })

  it('uses only enumerated structural variants without layout declarations', () => {
    const variants = roots.find(root => root.source?.input.file?.endsWith('/variants.css'))!
    const seen = new Set<string>()
    variants.walkRules(rule => {
      for (const [, variant, value] of rule.selector.matchAll(/\[data-theme-(\w+)="(\w+)"\]/g)) {
        expect(contract.variants[variant!]).toContain(value)
        seen.add(`${variant}:${value}`)
      }
      rule.walkDecls(declaration => {
        expect(['background', 'background-image', 'color', 'border-color', 'box-shadow']).toContain(declaration.prop)
      })
    })
    // Solid controls and no effects use the common rules, without reset overrides.
    expect([...seen, 'controls:solid', 'effects:none'].sort()).toEqual(Object.entries(contract.variants).flatMap(([key, values]) => values.map(value => `${key}:${value}`)).sort())
  })

  it('supports gradient page backgrounds while keeping dark and light solid', () => {
    for (const name of ['cyberpunk', 'neon', 'energy', 'romantic']) {
      expect(presets[name]!.tokens['--claire-body-background']).toMatch(/^linear-gradient\(/)
    }
    for (const name of ['dark', 'light']) {
      expect(presets[name]!.tokens['--claire-body-background']).toMatch(/^#[\da-f]{6}$/i)
    }
    const backgrounds: string[] = []
    foundations.walkDecls('background', declaration => { backgrounds.push(declaration.value) })
    expect(backgrounds).toContain('var(--claire-body-background)')
  })

  it('preserves cyan-blue neon bubbles, warm energy surfaces, and red romantic surfaces', () => {
    const colors = (preset: string, token: string) => {
      const value = presets[preset]!.tokens[`--claire-${token}`]!
      const stops = value.match(/#[\da-f]{6}/gi) ?? []
      expect(stops.length, `${preset}: ${token}`).toBeGreaterThan(0)
      return stops.map(hex => [1, 3, 5].map(offset => parseInt(hex.slice(offset, offset + 2), 16)))
    }
    for (const [r, g, b] of colors('neon', 'bubble-sent-background')) {
      expect(g!).toBeGreaterThan(r!)
      expect(b!).toBeGreaterThan(r!)
    }
    const surfaces = [
      'body-background', 'surface-chat', 'header-bg', 'input-bar-bg', 'surface-code',
      'surface-active', 'bubble-received-background', 'field-background', 'panel-background',
    ]
    for (const token of [...surfaces, 'field-focus-background']) {
      for (const [r, g, b] of colors('energy', token)) {
        expect(r!, token).toBeGreaterThanOrEqual(g!)
        expect(g!, token).toBeGreaterThanOrEqual(b!)
      }
    }
    for (const token of [...surfaces, 'bubble-sent-background']) {
      for (const [r, g, b] of colors('romantic', token)) {
        expect(r!, token).toBeGreaterThan(b! * 1.5)
        expect(Math.abs(b! - g!), token).toBeLessThanOrEqual(16)
      }
    }
  })

  it.each(Object.keys(presets))('%s provides basic AA text contrast on solid surfaces and gradient endpoints', name => {
    const values = { ...defaults, ...presets[name]!.tokens }
    const resolve = (value: string): string => value.replace(/var\((--[\w-]+)\)/g, (_, token: string) => resolve(values[token]!))
    const luminance = (hex: string) => {
      const rgb = [1, 3, 5].map(offset => {
        const channel = parseInt(hex.slice(offset, offset + 2), 16) / 255
        return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4
      })
      return rgb[0]! * 0.2126 + rgb[1]! * 0.7152 + rgb[2]! * 0.0722
    }
    const pairs = [
      ['text-primary', 'surface-chat'], ['text-secondary', 'surface-chat'],
      ['text-primary', 'panel-background'], ['text-secondary', 'field-background'],
      ['bubble-received-text', 'bubble-received-background'], ['bubble-received-meta', 'bubble-received-background'],
      ['bubble-sent-text', 'bubble-sent-background'], ['bubble-sent-meta', 'bubble-sent-background'],
      ['control-primary-text', 'control-primary-background'], ['control-send-text', 'control-send-background'],
      ['danger-on', 'danger'], ['danger-text', 'panel-background'], ['success-text', 'panel-background'],
      ['text-primary', 'surface-code'], ['text-secondary', 'surface-code'],
      ['accent-light', 'surface-code'], ['success-text', 'surface-code'], ['danger-text', 'surface-code'],
    ]
    for (const [foreground, background] of pairs) {
      const text = resolve(values[`--claire-${foreground}`]!)
      expect(text).toMatch(/^#[\da-f]{6}$/i)
      const stops = resolve(values[`--claire-${background}`]!).match(/#[\da-f]{6}/gi)!
      expect(stops.length).toBeGreaterThan(0)
      for (const stop of stops) {
        const a = luminance(text)
        const b = luminance(stop)
        expect((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05), `${name}: ${foreground} on ${background} (${stop})`).toBeGreaterThanOrEqual(4.5)
      }
    }
  })

  it('keeps theme colors in tokens and reserves important for reduced motion', () => {
    for (const root of roots) root.walkDecls(declaration => {
      if (!declaration.prop.startsWith('--')) {
        const value = declaration.value.replace(/url\([^)]*\)/g, '')
        expect(value, `${root.source?.input.file}: ${declaration.prop}`).not.toMatch(/#[\da-f]{3,8}\b|\b(?:rgba?|hsla?)\(/i)
      }
      if (declaration.important) {
        expect(declaration.parent?.parent?.type).toBe('atrule')
        expect((declaration.parent?.parent as postcss.AtRule).params).toBe('(prefers-reduced-motion: reduce)')
      }
    })
  })

  it('uses the same processed source in normal and Shadow DOM builds', () => {
    const main = readFileSync(new URL('../main.ts', import.meta.url), 'utf8')
    const embed = readFileSync(new URL('../embed.ts', import.meta.url), 'utf8')
    const shell = readFileSync(new URL('../shell.html', import.meta.url), 'utf8')
    expect(main).toContain("import './styles/index.css'")
    expect(embed).toContain("from './styles/index.css?inline'")
    expect(main).not.toContain('highlight.min.css')
    expect(embed).not.toContain('highlight.min.css')
    expect(shell).not.toContain('/css/style.css')
    expect(shell).toContain('\n  __APP_CSS__\n')
    expect(shell).not.toContain('href="__APP_CSS__"')
  })
})
