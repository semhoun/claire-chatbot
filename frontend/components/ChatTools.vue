<script setup lang="ts">
import type { ToolCall } from '../types'
import ClaireIcon from './ClaireIcon.vue'
defineProps<{ tools: ToolCall[]; messageId: string }>()
const display = (value: unknown): string => value == null ? '' : typeof value === 'object' ? JSON.stringify(value, null, 2) : String(value)
</script>

<template>
  <div v-if="tools.length" class="claire-message__subbubble claire-message__subbubble--toolcall">
    <details class="claire-toolcall">
      <summary class="claire-toolcall__summary" aria-label="Appels d’outils">
        <ClaireIcon name="settings" class="claire-toolcall__icon" />
        <svg class="claire-toolcall__icon claire-toolcall__icon--spinner" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="42 16" /></svg>
        <ClaireIcon name="check" class="claire-toolcall__icon claire-toolcall__icon--done" />
        <ClaireIcon name="chevron-down" class="claire-toolcall__chevron" />
        <span class="claire-visually-hidden">Appels d’outils</span>
      </summary>
      <div :id="`claire-toolscall-${messageId}`" class="claire-toolscall-data">
        <div v-for="tool in tools" :id="`claire-tool-${tool.id}`" :key="tool.id" class="claire-toolcall__text">
          <span v-if="tool.running" class="claire-tools-running-flag" hidden />
          Utilisation de l’outil : {{ tool.name }}<br>Paramètres :
          <ul><li v-for="(input, index) in tool.inputs" :key="index">{{ input.name }} : {{ display(input.value) }}</li></ul>
          Réponse :<pre v-if="tool.result != null && tool.result !== ''" class="claire-toolcall__result">{{ display(tool.result) }}</pre>
        </div>
      </div>
    </details>
  </div>
</template>
