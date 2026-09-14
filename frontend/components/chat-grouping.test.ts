// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick, reactive, toRaw } from 'vue'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import ChatBubble from './ChatBubble.vue'
import ChatMessages from './ChatMessages.vue'
import type { ChatMessage } from '../types'

enableAutoUnmount(afterEach)

function message(id: string, sent = false, error = false): ChatMessage {
  return { id, sent, error, message: `Message ${id}`, time: '2026-09-12T12:30:00Z', toolsCall: [], files: [] }
}

function audioProps() {
  return { audioEnabled: true, playing: null, pending: new Set<string>(), ready: new Map<string, Blob>(), failed: new Set<string>() }
}

function mountMessages(messages: ChatMessage[], loading = false) {
  return mount(ChatMessages, { props: { messages, loading, ...audioProps() } })
}

function positions(wrapper: ReturnType<typeof mountMessages>) {
  return wrapper.findAllComponents(ChatBubble).map(bubble => {
    const position = bubble.props('groupPosition')
    expect(bubble.classes().filter(name => /^claire-message--(single|first|middle|last)$/.test(name)))
      .toEqual([`claire-message--${position}`])
    return position
  })
}

describe('adjacent message groups', () => {
  it.each([
    ['', []],
    ['r', ['single']],
    ['s', ['single']],
    ['rr', ['first', 'last']],
    ['ss', ['first', 'last']],
    ['rrr', ['first', 'middle', 'last']],
    ['ssss', ['first', 'middle', 'middle', 'last']],
    ['rsrs', ['single', 'single', 'single', 'single']],
    ['rrsssr', ['first', 'last', 'first', 'middle', 'last', 'single']],
    ['rer', ['single', 'single', 'single']],
    ['sEs', ['single', 'single', 'single']],
    ['erre', ['single', 'first', 'last', 'single']],
    ['rreerr', ['first', 'last', 'single', 'single', 'first', 'last']],
  ])('groups sequence %s without crossing errors or authors', (sequence, expected) => {
    const entries = [...sequence].map((kind, index) => message(String(index), kind === 's' || kind === 'E', kind.toLowerCase() === 'e'))
    const wrapper = mountMessages(entries)
    expect(positions(wrapper)).toEqual(expected)
    wrapper.findAllComponents(ChatBubble).forEach((bubble, index) => {
      expect(toRaw(bubble.props('entry'))).toBe(entries[index])
      expect(bubble.classes()).toContain(entries[index].sent ? 'claire-message--sent' : 'claire-message--received')
      expect(bubble.attributes('role')).toBe(entries[index].error ? 'alert' : undefined)
    })
    expect(wrapper.find('img, [class*="avatar"]').exists()).toBe(false)
  })

  it.each([false, true])('keeps the loader separate from sent=%s groups', async sent => {
    const wrapper = mountMessages([message('a', sent), message('b', sent)], true)
    expect(positions(wrapper)).toEqual(['first', 'last'])
    const loader = wrapper.get('[data-role="claire-assistant-loader"]')
    expect(loader.classes()).toContain('claire-message--single')
    expect(loader.get('[role="status"]').attributes('aria-label')).toBe('Réponse en cours')
    expect(loader.find('time, button, img').exists()).toBe(false)
    expect(wrapper.findAll('article').at(-1)?.element).toBe(loader.element)
    await wrapper.setProps({ loading: false })
    expect(wrapper.find('[data-role="claire-assistant-loader"]').exists()).toBe(false)
    expect(positions(wrapper)).toEqual(['first', 'last'])
    await wrapper.setProps({ messages: [], loading: true })
    expect(positions(wrapper)).toEqual([])
    expect(wrapper.findAll('article')).toHaveLength(1)
  })

  it('reacts to appends, author changes, errors, removals and array replacement', async () => {
    const messages = reactive([message('a')])
    const wrapper = mountMessages(messages)
    expect(positions(wrapper)).toEqual(['single'])
    messages.push(message('b'), message('c'))
    await nextTick()
    expect(positions(wrapper)).toEqual(['first', 'middle', 'last'])
    messages[1].sent = true
    await nextTick()
    expect(positions(wrapper)).toEqual(['single', 'single', 'single'])
    messages[1].sent = false
    messages[1].error = true
    await nextTick()
    expect(positions(wrapper)).toEqual(['single', 'single', 'single'])
    messages[1].error = false
    await nextTick()
    expect(positions(wrapper)).toEqual(['first', 'middle', 'last'])
    messages.splice(1, 1)
    await nextTick()
    expect(positions(wrapper)).toEqual(['first', 'last'])
    await wrapper.setProps({ messages: [message('replacement', true)] })
    expect(positions(wrapper)).toEqual(['single'])
  })

  it('never mutates the input array or message objects', async () => {
    const messages = [message('a'), message('b'), message('error', false, true), message('c', true)]
    const snapshot = JSON.stringify(messages)
    messages.forEach(entry => {
      Object.freeze(entry.files)
      Object.freeze(entry.toolsCall)
      Object.freeze(entry)
    })
    Object.freeze(messages)
    const wrapper = mountMessages(messages)
    expect(positions(wrapper)).toEqual(['first', 'last', 'single', 'single'])
    await wrapper.setProps({ loading: true })
    expect(JSON.stringify(messages)).toBe(snapshot)
    expect(wrapper.props('messages')).toBe(messages)
  })
})

describe('bubble metadata and audio', () => {
  it.each([false, true])('places the timestamp inside the bubble for sent=%s without author labels or avatars', async sent => {
    const entry = message('a', sent)
    const wrapper = mount(ChatBubble, { props: { entry, groupPosition: 'single', ...audioProps() } })
    const metadata = wrapper.get('.claire-message__bubble > .claire-message__meta')
    expect(metadata.get('time').attributes('datetime')).toBe(entry.time)
    expect(metadata.get('time').text()).toBe(new Date(entry.time).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }))
    expect(wrapper.text()).not.toContain('Vous')
    expect(wrapper.find('img, [class*="avatar"]').exists()).toBe(false)
    expect(metadata.element.children.length).toBe(sent ? 1 : 2)
    await wrapper.setProps({ entry: { ...entry, time: '2026-09-12T13:45:00Z' } })
    expect(metadata.get('time').text()).toBe(new Date('2026-09-12T13:45:00Z').toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }))
    await wrapper.setProps({ entry: { ...entry, time: 'invalid' } })
    expect(metadata.find('time').exists()).toBe(false)
    expect(metadata.text()).toBe('')
    await wrapper.setProps({ entry: { ...entry, time: '' } })
    expect(metadata.find('time').exists()).toBe(false)
  })

  it('preserves accessible audio states and delegated action hooks inside metadata', async () => {
    const wrapper = mount(ChatBubble, { props: { entry: message('a'), groupPosition: 'single', ...audioProps() } })
    const button = wrapper.get<HTMLButtonElement>('.claire-message__bubble .claire-message__meta button')
    expect(button.attributes()).toMatchObject({ type: 'button', 'data-audio-listen': 'true', 'data-audio-message-id': 'a' })
    for (const [props, label, disabled, stateClass] of [
      [{}, 'Générer l’audio', false, null],
      [{ ready: new Map([['a', new Blob()]]) }, 'Lire la réponse', false, null],
      [{ playing: 'a' }, 'Arrêter la lecture', false, 'is-playing'],
      [{ playing: null, pending: new Set(['a']) }, 'Génération audio en cours', true, 'is-loading'],
      [{ pending: new Set<string>(), failed: new Set(['a']) }, 'Réessayer la génération audio', false, null],
    ] as const) {
      await wrapper.setProps(props)
      expect(button.attributes('aria-label')).toBe(label)
      expect(button.attributes('title')).toBe(label)
      expect(button.element.disabled).toBe(disabled)
      expect(button.classes().includes('is-playing')).toBe(stateClass === 'is-playing')
      expect(button.classes().includes('is-loading')).toBe(stateClass === 'is-loading')
    }
  })

  it.each([
    { audioEnabled: false },
    { entry: message('a', true) },
    { entry: message('a', false, true) },
    { entry: message('') },
  ])('does not offer audio for ineligible messages: %j', overrides => {
    const wrapper = mount(ChatBubble, { props: { entry: message('a'), groupPosition: 'single', ...audioProps(), ...overrides } })
    expect(wrapper.find('[data-audio-listen]').exists()).toBe(false)
    expect(wrapper.find('.claire-message__bubble time').exists()).toBe(true)
  })

  it('renders tool-only updates as compact status bubbles without empty metadata', () => {
    const entry = {
      ...message('tool'),
      message: '',
      toolsCall: [{ id: 'call', name: 'generate_image', inputs: [], running: true, result: null }],
    }
    const wrapper = mount(ChatBubble, { props: { entry, groupPosition: 'single', ...audioProps() } })
    expect(wrapper.classes()).toContain('claire-message--tools-only')
    expect(wrapper.find('.claire-message__text').exists()).toBe(false)
    expect(wrapper.find('.claire-message__meta').exists()).toBe(false)
    expect(wrapper.find('[data-audio-listen]').exists()).toBe(false)
  })
})
