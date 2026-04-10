import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'

const BUILD_AT = new Date().toISOString()
const APP_VERSION = process.env.npm_package_version || '0.0.0'
const BUILD_ID = process.env.APP_BUILD_ID || `${APP_VERSION}-${BUILD_AT}`
const DEFAULT_DEV_SERVER_PORT = 3003
const DEFAULT_BACKEND_TARGET = 'http://localhost:8080'

function resolveDevServerPort(rawPort) {
  const parsedPort = Number.parseInt(String(rawPort ?? ''), 10)
  return Number.isInteger(parsedPort) && parsedPort > 0 ? parsedPort : DEFAULT_DEV_SERVER_PORT
}

function resolveBackendTarget(rawTarget) {
  const target = String(rawTarget ?? '').trim()
  return target || DEFAULT_BACKEND_TARGET
}

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
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const devServerPort = resolveDevServerPort(env.FRONTEND_PORT || env.PORT || env.VITE_PORT)
  const backendTarget = resolveBackendTarget(
    env.VITE_BACKEND_URL || env.BACKEND_URL || env.API_PROXY_TARGET
  )

  return {
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
    build: {
      sourcemap: false,
      rollupOptions: {
        output: {
          manualChunks: {
            'vendor-react': ['react', 'react-dom', 'react-router-dom'],
            'vendor-ui': ['react-hot-toast', 'lucide-react'],
            'vendor-data': ['axios', 'dexie'],
          },
        },
      },
    },
    server: {
      port: devServerPort,
      strictPort: true,
      // M13 — CSP headers for the dev server
      headers: {
        'Content-Security-Policy': [
          "default-src 'self'",
          "script-src 'self' 'unsafe-inline'",
          "style-src 'self' 'unsafe-inline'",
          "img-src 'self' data: blob:",
          "connect-src 'self' http://localhost:* ws://localhost:*",
        ].join('; '),
      },
      proxy: {
        '/api': {
          target: backendTarget,
          changeOrigin: true,
        }
      }
    }
  }
})
