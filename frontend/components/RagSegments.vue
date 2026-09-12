<script setup lang="ts">
import type { RagSegments } from '../options-types'
defineProps<{ data: RagSegments }>()
</script>

<template>
  <div class="claire-rag-segments">
    <div class="claire-rag-segments__header">
      <span class="claire-rag-segments__title">{{ data.document.name }}</span>
      <span class="claire-rag-segments__count">{{ data.segments.length }} segment{{ data.segments.length > 1 ? 's' : '' }}</span>
    </div>
    <div v-if="!data.segments.length" class="claire-option-list__empty"><div class="claire-option-list__empty-text">Aucun segment indexé</div></div>
    <ol v-else class="claire-rag-segments__list">
      <li v-for="(segment, index) in data.segments" :key="index" class="claire-rag-segments__item">
        <div class="claire-rag-segments__item-index">{{ index + 1 }}</div>
        <div class="claire-rag-segments__item-content">{{ segment }}</div>
      </li>
    </ol>
  </div>
</template>
