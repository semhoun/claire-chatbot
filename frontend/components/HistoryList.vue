<script setup lang="ts">
import type { HistoryItem } from '../options-types'
import ClaireIcon from './ClaireIcon.vue'
defineProps<{ histories: HistoryItem[]; busy: boolean }>()
defineEmits<{ open: [item: HistoryItem]; delete: [item: HistoryItem] }>()
</script>

<template>
  <div class="claire-option-list">
    <div v-if="!histories.length" class="claire-option-list__empty">
      <div class="claire-option-list__empty-icon"><ClaireIcon name="history" /></div>
      <div class="claire-option-list__empty-text">Aucune conversation pour le moment</div>
      <div class="claire-option-list__empty-help">Envoyez un message pour créer une nouvelle conversation.</div>
    </div>
    <ul v-else class="claire-option-list__items">
      <li v-for="history in histories" :key="history.threadId" class="claire-option-item claire-history-item">
        <div class="claire-option-item__icon"><ClaireIcon name="history" /></div>
        <div class="claire-option-item__content claire-history-item__content" :data-tooltip="history.summary || undefined" :title="history.summary || undefined">
          <div class="claire-option-item__title" :aria-label="history.summary || undefined">{{ history.title }}</div>
          <div class="claire-option-item__meta">Modifiée le {{ new Date(history.updatedAt).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) }}</div>
        </div>
        <div class="claire-option-item__actions">
          <button class="claire-option-item__button" type="button" aria-label="Afficher la conversation" :disabled="busy" @click="$emit('open', history)"><ClaireIcon name="eye" /></button>
          <button class="claire-option-item__button claire-history-item__delete" type="button" aria-label="Supprimer cette conversation" :disabled="busy" @click="$emit('delete', history)"><ClaireIcon name="delete" /></button>
        </div>
      </li>
    </ul>
  </div>
</template>
