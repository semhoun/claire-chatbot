import { createApp } from 'vue'
import PublicApp from './components/PublicApp.vue'
import type { PageData } from './types'

const root = document.querySelector<HTMLElement>('#claire-vue-app')
const payload = document.getElementById('claire-page-data')
if (!root || !payload) throw new Error('Point de montage Claire introuvable')
const data = JSON.parse(payload.textContent ?? '{}') as PageData
payload.remove()
createApp(PublicApp, { data }).mount(root)
