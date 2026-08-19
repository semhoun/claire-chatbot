import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

const projectRoot = fileURLToPath(new URL('.', import.meta.url))

export default defineConfig({
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  publicDir: false,
  plugins: [vue()],
  build: {
    emptyOutDir: false,
    outDir: 'public/js',
    lib: {
      entry: resolve(projectRoot, 'frontend/embed.ts'),
      formats: ['iife'],
      name: 'ClaireEmbed',
      fileName: () => 'embed.js',
    },
  },
})
