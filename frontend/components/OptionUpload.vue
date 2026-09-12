<script setup lang="ts">
import { ref, useId } from 'vue'
import ClaireIcon from './ClaireIcon.vue'

const props = defineProps<{
  acceptedExt: string
  document?: boolean
  upload: (file: File) => Promise<boolean>
}>()
const id = useId()
const input = ref<HTMLInputElement | null>(null)
const file = ref<File | null>(null)
const uploading = ref(false)

function select(event: Event): void {
  file.value = (event.target as HTMLInputElement).files?.[0] ?? null
}

async function submit(): Promise<void> {
  if (file.value === null || uploading.value) return
  uploading.value = true
  try {
    if (await props.upload(file.value)) {
      file.value = null
      if (input.value !== null) input.value.value = ''
    }
  } finally {
    uploading.value = false
  }
}
</script>

<template>
  <form class="claire-file-upload" :class="{ 'claire-rag-upload': document }" :aria-busy="uploading" @submit.prevent="submit">
    <input :id="id" ref="input" class="claire-file-upload__input" type="file" :accept="acceptedExt" required :disabled="uploading" @change="select">
    <div class="claire-file-upload__icons">
      <label class="claire-btn claire-btn--secondary claire-file-upload__label" :for="id" aria-label="Parcourir"><ClaireIcon name="upload" /></label>
      <button type="submit" class="claire-btn claire-btn--primary" aria-label="Envoyer" :disabled="!file || uploading"><ClaireIcon name="send" /></button>
    </div>
    <span class="claire-file-upload__name" :title="file?.name" aria-live="polite">
      {{ file?.name ?? (document ? 'Aucun document' : 'Aucun fichier') }}
      <progress v-if="uploading" aria-label="Envoi en cours" style="display: block; width: 100%"></progress>
    </span>
  </form>
</template>
