<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { readTokensFromUrl } from '../bootstrap'
import { completeAuthCallback, loadNormalBootstrap } from '../services/page-bootstrap'
import type { ClaireBootstrap, PageData } from '../types'
import ClaireApp from './ClaireApp.vue'

const props = defineProps<{ data: PageData }>()
const config = ref<ClaireBootstrap | null>(null)
const loading = ref(props.data.page !== 'error')
const error = ref(props.data.page === 'error' ? props.data : null)
const base = new URL(props.data.baseUrl || window.location.origin, window.location.origin)
const safeBase = base.origin === window.location.origin ? base.toString().replace(/\/$/, '') : window.location.origin
let destroyed = false
onBeforeUnmount(() => { destroyed = true })

onMounted(async () => {
  if (error.value) return
  try {
    if (props.data.page === 'callback') {
      window.location.replace(completeAuthCallback(props.data))
      return
    }
    const loaded = await loadNormalBootstrap(safeBase, readTokensFromUrl().sessionToken)
    if (destroyed) return
    config.value = loaded
  } catch {
    if (!destroyed) error.value = { page: 'error', baseUrl: safeBase, code: 500, title: 'Impossible de charger Claire. Veuillez réessayer.' }
  } finally { if (!destroyed) loading.value = false }
})
</script>

<template>
  <ClaireApp v-if="config" :config="config" />
  <div v-else class="claire-app-base"><main class="welcome-page" :style="{ backgroundImage: `url(${safeBase}/image/background.png)` }">
    <section v-if="error" class="claire-error-panel">
      <h1 class="claire-error-title">{{ error.code }}</h1><p class="claire-error-subtitle">{{ error.title }}</p>
      <a :href="`${safeBase}/`" class="claire-error-btn">Retour à l’accueil</a>
      <div v-if="error.details" class="claire-error-debug"><h3>Détails</h3><dl><template v-for="(value, key) in error.details" :key="key"><dt>{{ key }}</dt><dd><pre>{{ value }}</pre></dd></template></dl></div>
    </section>
    <section v-else-if="loading" id="claire-auth-status" class="claire-panel claire-is-centered" role="status"><div class="claire-spinner" /><h1>Connexion en cours...</h1><p>Redirection automatique</p></section>
    <section v-else id="claire-sso-panel" class="claire-panel"><h2>Bienvenue</h2><p>Pour continuer, veuillez vous authentifier via notre SSO.</p><a :href="`${safeBase}/auth/sso`" class="claire-sso-btn">Se connecter</a></section>
  </main></div>
</template>
