<script setup lang="ts">
import { computed } from 'vue'
import type { ChatMessage } from '../types'
import MarkdownContent from './MarkdownContent'
import ChatTools from './ChatTools.vue'
import ClaireIcon from './ClaireIcon.vue'

const props = defineProps<{
  entry: ChatMessage
  audioEnabled: boolean
  playing: string | null
  pending: Set<string>
  ready: Map<string, Blob>
  failed: Set<string>
}>()
const audioLabel = computed(() => props.playing === props.entry.id ? 'Arrêter la lecture'
  : props.pending.has(props.entry.id) ? 'Génération audio en cours' : props.failed.has(props.entry.id) ? 'Réessayer la génération audio'
    : props.ready.has(props.entry.id) ? 'Lire la réponse' : 'Générer l’audio')
const time = computed(() => {
  const date = new Date(props.entry.time)
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
})
</script>

<template>
  <article :id="entry.id ? `claire-${entry.id}` : undefined" :role="entry.error ? 'alert' : undefined" class="claire-message" :class="entry.sent ? 'claire-message--sent' : 'claire-message--received'">
    <div class="claire-message__bubble">
      <ChatTools :tools="entry.toolsCall" :message-id="entry.id" />
      <MarkdownContent :id="`claire-message-${entry.id}`" class="claire-message__text" :text="entry.message" :files="entry.files" />
    </div>
    <span class="claire-message__meta">{{ time }}{{ entry.sent ? ' • Vous' : '' }}
      <button v-if="audioEnabled && !entry.sent && !entry.error && entry.id" type="button" class="claire-message__audio-action" :class="{ 'is-playing': playing === entry.id, 'is-loading': pending.has(entry.id) }" data-audio-listen="true" :data-audio-message-id="entry.id" :disabled="pending.has(entry.id)" :aria-label="audioLabel" :title="audioLabel">
        <ClaireIcon :name="playing === entry.id ? 'stop' : pending.has(entry.id) ? 'refresh' : ready.has(entry.id) ? 'play' : 'volume'" />
      </button>
    </span>
  </article>
</template>
