<script setup lang="ts">
import type { FileList, StoredFile } from '../options-types'
import ClaireIcon from './ClaireIcon.vue'
import OptionUpload from './OptionUpload.vue'
defineProps<{ data: FileList; busy: boolean; upload: (file: File) => Promise<boolean> }>()
defineEmits<{ add: [file: StoredFile]; delete: [file: StoredFile] }>()

function size(bytes: number): string {
  if (bytes < 1024) return `${bytes} o`
  const value = bytes < 1048576 ? bytes / 1024 : bytes / 1048576
  return `${value.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${bytes < 1048576 ? 'Ko' : 'Mo'}`
}
</script>

<template>
  <div class="claire-option-list claire-files-root">
    <div class="claire-option-list__actions"><OptionUpload :accepted-ext="data.acceptedExt" :upload="upload" /></div>
    <div v-if="!data.files.length" class="claire-option-list__empty">
      <div class="claire-option-list__empty-icon"><ClaireIcon name="file" /></div>
      <div class="claire-option-list__empty-text">Aucun fichier</div>
      <div class="claire-option-list__empty-help">Ajoutez un fichier à l’aide du bouton ci-dessus.</div>
    </div>
    <ul v-else class="claire-option-list__items">
      <li v-for="file in data.files" :id="`claire-file-${file.fileId}`" :key="file.fileId" class="claire-option-item claire-file-item">
        <div class="claire-option-item__icon"><ClaireIcon name="file" /></div>
        <div class="claire-option-item__content">
          <div class="claire-option-item__title">{{ file.filename }}</div>
          <div class="claire-option-item__meta">{{ file.mimeType }} · {{ size(file.sizeBytes) }} · Ajouté le {{ new Date(file.createdAt).toLocaleDateString('fr-FR') }}</div>
        </div>
        <div class="claire-option-item__actions">
          <button class="claire-option-item__button" type="button" aria-label="Ajouter ce fichier à la conversation" :disabled="busy" @click="$emit('add', file)"><ClaireIcon name="plus" /></button>
          <button class="claire-option-item__button claire-files-item__delete" type="button" aria-label="Supprimer ce fichier" :disabled="busy" @click="$emit('delete', file)"><ClaireIcon name="delete" /></button>
        </div>
      </li>
    </ul>
  </div>
</template>
