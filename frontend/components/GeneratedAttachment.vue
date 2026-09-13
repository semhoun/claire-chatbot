<script setup lang="ts">
import type { GeneratedFile } from '../types'
defineProps<{ file?: GeneratedFile; reference: string; label?: string; presentation: 'link' | 'image' | 'resource' }>()
</script>

<template>
  <span v-if="!file" class="claire-generated-unresolved"><slot>{{ label }}</slot>{{ label ? ` (${reference})` : reference }}</span>
  <span v-else-if="!file.url" class="claire-generated-image-placeholder" role="status"><slot>{{ label || file.name }}</slot></span>
  <img v-else-if="file.type === 'image' && presentation !== 'link'" :data-protected-src="file.url" :alt="label || file.name" :aria-label="`Agrandir l’image : ${label || file.name}`" role="button" tabindex="0" class="claire-generated-image">
  <span v-else-if="file.type === 'audio'" class="claire-generated-resource"><slot>{{ label }}</slot><audio controls preload="none" :aria-label="label || file.name" :data-protected-src="file.url" class="claire-generated-audio" /></span>
  <span v-else class="claire-generated-resource"><a :href="file.url" class="claire-generated-file" target="_blank" rel="noopener noreferrer"><slot>{{ label || file.name }}</slot></a> <a :href="file.url" :download="file.name" class="claire-generated-file">Télécharger</a></span>
</template>
