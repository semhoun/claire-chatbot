<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import ClaireIcon from './ClaireIcon.vue'

const props = defineProps<{ text: string; label: string }>()
const root = ref<HTMLElement>()
const status = ref('')
const pending = ref(false)
let revision = 0
let timer: ReturnType<typeof setTimeout> | undefined
function reset() {
  revision++
  clearTimeout(timer)
  status.value = ''
}
watch(() => props.text, reset)
onBeforeUnmount(reset)

async function copy() {
  if (pending.value || !root.value) return
  reset()
  const current = revision
  const text = props.text
  const container = root.value
  pending.value = true
  try {
    const doc = container.ownerDocument
    if (doc.defaultView?.navigator.clipboard?.writeText) {
      await doc.defaultView.navigator.clipboard.writeText(text)
    } else {
      // Keep the legacy selection inside this component, including in Shadow DOM.
      const field = doc.createElement('textarea')
      field.value = text
      field.readOnly = true
      field.style.cssText = 'position:fixed;opacity:0;pointer-events:none;width:1px;height:1px;'
      const tree = container.getRootNode() as Document | ShadowRoot
      const focused = tree.activeElement
      container.append(field)
      try {
        field.select()
        if (!doc.execCommand('copy')) throw new Error('Copy failed')
      } finally {
        field.remove()
        if (focused instanceof HTMLElement) focused.focus({ preventScroll: true })
      }
    }
    if (current === revision) status.value = 'Copié !'
  } catch {
    if (current === revision) status.value = 'Copie impossible. Réessayez.'
  } finally {
    pending.value = false
    if (current === revision) timer = setTimeout(() => { status.value = '' }, 4000)
  }
}
</script>

<template>
  <div ref="root" class="claire-copyable-block">
    <div class="claire-copyable-block__toolbar">
      <span role="status" aria-live="polite" class="claire-copyable-block__status">{{ status }}</span>
      <button type="button" class="claire-copyable-block__copy" :aria-label="label" :title="status || label" :disabled="pending" @click="copy">
        <ClaireIcon :name="status === 'Copié !' ? 'check' : 'copy'" />
      </button>
    </div>
    <slot />
  </div>
</template>
