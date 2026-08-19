import { createApp } from 'vue'
import ClaireApp from './components/ClaireApp.vue'
import { parseBootstrap, readTokensFromUrl } from './bootstrap'

const root = document.querySelector<HTMLElement>('#claire-vue-app')
if (root === null) throw new Error('Point de montage Claire introuvable')

const config = parseBootstrap(root)
Object.assign(config, readTokensFromUrl())
createApp(ClaireApp, { config }).mount(root)
