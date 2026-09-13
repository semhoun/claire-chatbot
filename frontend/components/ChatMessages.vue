<script setup lang="ts">
import { computed } from 'vue'
import type { ChatMessage } from '../types'
import ChatBubble from './ChatBubble.vue'

const props = defineProps<{
  messages: ChatMessage[]
  loading: boolean
  audioEnabled: boolean
  playing: string | null
  pending: Set<string>
  ready: Map<string, Blob>
  failed: Set<string>
}>()
const groupPositions = computed(() => props.messages.map((entry, index) => {
  if (entry.error) return 'single'
  const previous = props.messages[index - 1]
  const next = props.messages[index + 1]
  const joinsPrevious = previous && !previous.error && previous.sent === entry.sent
  const joinsNext = next && !next.error && next.sent === entry.sent
  return joinsPrevious ? (joinsNext ? 'middle' : 'last') : (joinsNext ? 'first' : 'single')
}))
</script>

<template>
  <ChatBubble v-for="(entry, index) in messages" :key="entry.id || `message-${index}`" :entry="entry" :group-position="groupPositions[index]" :audio-enabled="audioEnabled" :playing="playing" :pending="pending" :ready="ready" :failed="failed" />
  <article v-if="loading" class="claire-message claire-message--single" data-role="claire-assistant-loader"><div class="claire-message__bubble"><span class="claire-typing-indicator" role="status" aria-label="Réponse en cours"><span v-for="dot in 3" :key="dot" class="claire-typing-indicator__dot" /></span></div></article>
</template>
