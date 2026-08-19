import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  publicDir: false,
  plugins: [vue()],
  build: {
    emptyOutDir: true,
    manifest: true,
    outDir: 'public/build',
    rollupOptions: {
      input: {
        app: resolve(fileURLToPath(new URL('.', import.meta.url)), 'frontend/main.ts'),
      },
      output: {
        entryFileNames: 'js/[name].js',
        chunkFileNames: 'js/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash][extname]',
      },
    },
  },
})
