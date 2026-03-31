import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'

const BUILD_AT = new Date().toISOString()
const APP_VERSION = process.env.npm_package_version || '0.0.0'
const BUILD_ID = process.env.APP_BUILD_ID || `${APP_VERSION}-${BUILD_AT}`

function buildVersionManifestPlugin() {
  const payload = JSON.stringify(
    {
      buildId: BUILD_ID,
      version: APP_VERSION,
      builtAt: BUILD_AT,
    },
    null,
    2,
  )

  return {
    name: 'enjoyfun-build-version-manifest',
    generateBundle() {
      this.emitFile({
        type: 'asset',
        fileName: 'app-version.json',
        source: payload,
      })
    },
  }
}

// https://vite.dev/config/
export default defineConfig({
  define: {
    'import.meta.env.VITE_APP_BUILD_ID': JSON.stringify(BUILD_ID),
    'import.meta.env.VITE_APP_BUILD_AT': JSON.stringify(BUILD_AT),
    'import.meta.env.VITE_APP_VERSION': JSON.stringify(APP_VERSION),
  },
  plugins: [
    buildVersionManifestPlugin(),
    react(), 
    tailwindcss(),
    VitePWA({
      registerType: 'autoUpdate',
      injectRegister: 'auto',
      workbox: {
        globPatterns: ['**/*.{js,css,html,ico,png,svg}'],
        cleanupOutdatedCaches: true,
        clientsClaim: true,
        skipWaiting: true
      },
      manifest: {
        name: 'EnjoyFun',
        short_name: 'EnjoyFun',
        description: 'EnjoyFun Event Platform & POS',
        theme_color: '#030712',
        background_color: '#030712',
        display: 'standalone',
        icons: [
          { src: '/vite.svg', sizes: '192x192', type: 'image/svg+xml' }
        ]
      }
    })
  ],
  server: {
    port: 3000,
    strictPort: true,
    proxy: {
      '/api': {
        target: 'http://localhost:8080',
        changeOrigin: true,
      }
    }
  }
})
