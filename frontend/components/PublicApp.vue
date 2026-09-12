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
const themeRequest = new AbortController()
let destroyed = false
onBeforeUnmount(() => { destroyed = true; themeRequest.abort() })

onMounted(async () => {
  if (error.value) return
  try {
    if (props.data.page === 'callback') {
      window.location.replace(completeAuthCallback(props.data))
      return
    }
    const loaded = await loadNormalBootstrap(safeBase, readTokensFromUrl().sessionToken)
    if (destroyed) return
    if (loaded) {
      document.body.classList.toggle('claire-compact', loaded.layoutMode === 'compact')
      loaded.dynamicCss = loaded.brainInfo.cssInline ?? ''
      if (loaded.brainInfo.css) {
        void Promise.resolve()
          .then(() => fetch(`${safeBase}/css/${loaded.brainInfo.css}`, { redirect: 'error', signal: themeRequest.signal }))
          .then(async response => {
            if (!response.ok) return
            const css = await response.text()
            if (!destroyed && config.value?.currentBrain === loaded.currentBrain) {
              config.value.dynamicCss = [css, loaded.brainInfo.cssInline ?? ''].filter(Boolean).join('\n')
            }
          }).catch(() => { /* Optional theme failures must not prevent opening the chat. */ })
      }
    }
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

<style>
.claire-app-base,.welcome-page{width:100%;min-height:100vh}
.welcome-page{position:relative;background:#000 center/cover no-repeat;display:grid;place-items:center;padding:24px;box-sizing:border-box}
.claire-panel,.claire-error-panel{width:min(90vw,520px);box-sizing:border-box;text-align:center;background:rgba(0,0,0,.55);color:#fff;border-radius:16px;padding:40px}
.claire-spinner{width:48px;height:48px;border:3px solid #ffffff33;border-top-color:#0d6efd;border-radius:50%;animation:public-spin 1s linear infinite;margin:0 auto 20px}
@keyframes public-spin{to{transform:rotate(360deg)}}
.claire-sso-btn,.claire-error-btn{display:inline-flex;padding:12px 24px;border-radius:8px;background:#0d6efd;color:#fff;text-decoration:none}
.claire-error-title{font-size:72px;margin:0}
.claire-error-debug{max-width:960px;margin:40px auto;background:#000c;color:#fff;padding:20px;border-radius:12px;text-align:left}
.claire-error-debug pre{overflow:auto;max-height:50vh;white-space:pre-wrap;overflow-wrap:anywhere}
</style>
