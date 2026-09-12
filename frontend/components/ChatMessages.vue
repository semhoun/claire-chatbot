<script setup lang="ts">
import type { ChatMessage } from '../types'
import ChatBubble from './ChatBubble.vue'

defineProps<{
  messages: ChatMessage[]
  loading: boolean
  audioEnabled: boolean
  playing: string | null
  pending: Set<string>
  ready: Map<string, Blob>
  failed: Set<string>
}>()
</script>

<template>
  <ChatBubble v-for="(entry, index) in messages" :key="entry.id || `message-${index}`" :entry="entry" :audio-enabled="audioEnabled" :playing="playing" :pending="pending" :ready="ready" :failed="failed" />
  <article v-if="loading" class="claire-message" data-role="claire-assistant-loader"><div class="claire-message__bubble"><span class="claire-typing-indicator" role="status" aria-label="Réponse en cours"><span v-for="dot in 3" :key="dot" class="claire-typing-indicator__dot" /></span></div></article>
</template>
