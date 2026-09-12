<script setup lang="ts">
import type { RagList, RagDocument } from '../options-types'
import ClaireIcon from './ClaireIcon.vue'
import OptionUpload from './OptionUpload.vue'
defineProps<{ data: RagList; busy: boolean; upload: (file: File) => Promise<boolean> }>()
defineEmits<{
  segments: [document: RagDocument]
  toggle: [document: RagDocument]
  delete: [document: RagDocument]
  text: []
  url: []
}>()
</script>

<template>
  <div class="claire-option-list claire-rag-root">
    <div class="claire-option-list__actions">
      <OptionUpload :accepted-ext="data.acceptedExt" :upload="upload" document />
      <div class="claire-rag-actions">
        <button type="button" class="claire-btn claire-btn--secondary claire-rag-action-btn" :disabled="busy" @click="$emit('text')"><ClaireIcon name="plus" /><span>Texte</span></button>
        <button type="button" class="claire-btn claire-btn--secondary claire-rag-action-btn" :disabled="busy" @click="$emit('url')"><ClaireIcon name="link" /><span>URL</span></button>
      </div>
    </div>
    <div v-if="!data.documents.length" class="claire-option-list__empty">
      <div class="claire-option-list__empty-icon"><ClaireIcon name="rag" /></div>
      <div class="claire-option-list__empty-text">Aucun document RAG</div>
      <div class="claire-option-list__empty-help">Ajoutez un fichier, du texte ou une URL.</div>
    </div>
    <ul v-else class="claire-option-list__items">
      <li v-for="document in data.documents" :id="`claire-rag-${document.documentId}`" :key="document.documentId" class="claire-option-item claire-rag-item" :class="{ 'is-active': document.isActive }">
        <div class="claire-rag-item__header">
          <div class="claire-option-item__icon"><ClaireIcon name="rag" /></div>
          <div class="claire-option-item__title">{{ document.name }}</div>
          <div class="claire-option-item__actions">
            <button class="claire-option-item__button" type="button" aria-label="Voir les segments" :disabled="busy" @click="$emit('segments', document)"><ClaireIcon name="eye" /></button>
            <button class="claire-option-item__button" type="button" :aria-label="`${document.isActive ? 'Désactiver' : 'Activer'} ce document`" :disabled="busy" @click="$emit('toggle', document)"><ClaireIcon :name="document.isActive ? 'disable' : 'enable'" /></button>
            <button class="claire-option-item__button claire-rag-item__delete" type="button" aria-label="Supprimer ce document" :disabled="busy" @click="$emit('delete', document)"><ClaireIcon name="delete" /></button>
          </div>
        </div>
        <div class="claire-option-item__meta">{{ document.sourceType }} · {{ document.chunkCount }} segment{{ document.chunkCount > 1 ? 's' : '' }} · {{ document.isActive ? 'Actif' : 'Inactif' }} · Ajouté le {{ new Date(document.createdAt).toLocaleDateString('fr-FR') }}</div>
      </li>
    </ul>
  </div>
</template>
