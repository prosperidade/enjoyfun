// vite.config.js
import { defineConfig, loadEnv } from "file:///C:/Users/Administrador/Desktop/enjoyfun/frontend/node_modules/vite/dist/node/index.js";
import react from "file:///C:/Users/Administrador/Desktop/enjoyfun/frontend/node_modules/@vitejs/plugin-react/dist/index.js";
import tailwindcss from "file:///C:/Users/Administrador/Desktop/enjoyfun/frontend/node_modules/@tailwindcss/vite/dist/index.mjs";
import { VitePWA } from "file:///C:/Users/Administrador/Desktop/enjoyfun/frontend/node_modules/vite-plugin-pwa/dist/index.js";
var BUILD_AT = (/* @__PURE__ */ new Date()).toISOString();
var APP_VERSION = process.env.npm_package_version || "0.0.0";
var BUILD_ID = process.env.APP_BUILD_ID || `${APP_VERSION}-${BUILD_AT}`;
var DEFAULT_DEV_SERVER_PORT = 3003;
var DEFAULT_BACKEND_TARGET = "http://localhost:8080";
function resolveDevServerPort(rawPort) {
  const parsedPort = Number.parseInt(String(rawPort ?? ""), 10);
  return Number.isInteger(parsedPort) && parsedPort > 0 ? parsedPort : DEFAULT_DEV_SERVER_PORT;
}
function resolveBackendTarget(rawTarget) {
  const target = String(rawTarget ?? "").trim();
  return target || DEFAULT_BACKEND_TARGET;
}
function buildVersionManifestPlugin() {
  const payload = JSON.stringify(
    {
      buildId: BUILD_ID,
      version: APP_VERSION,
      builtAt: BUILD_AT
    },
    null,
    2
  );
  return {
    name: "enjoyfun-build-version-manifest",
    generateBundle() {
      this.emitFile({
        type: "asset",
        fileName: "app-version.json",
        source: payload
      });
    }
  };
}
var vite_config_default = defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), "");
  const devServerPort = resolveDevServerPort(env.FRONTEND_PORT || env.PORT || env.VITE_PORT);
  const backendTarget = resolveBackendTarget(
    env.VITE_BACKEND_URL || env.BACKEND_URL || env.API_PROXY_TARGET
  );
  return {
    define: {
      "import.meta.env.VITE_APP_BUILD_ID": JSON.stringify(BUILD_ID),
      "import.meta.env.VITE_APP_BUILD_AT": JSON.stringify(BUILD_AT),
      "import.meta.env.VITE_APP_VERSION": JSON.stringify(APP_VERSION)
    },
    plugins: [
      buildVersionManifestPlugin(),
      react(),
      tailwindcss(),
      VitePWA({
        registerType: "autoUpdate",
        injectRegister: "auto",
        workbox: {
          globPatterns: ["**/*.{js,css,html,ico,png,svg}"],
          cleanupOutdatedCaches: true,
          clientsClaim: true,
          skipWaiting: true
        },
        manifest: {
          name: "EnjoyFun",
          short_name: "EnjoyFun",
          description: "EnjoyFun Event Platform & POS",
          theme_color: "#030712",
          background_color: "#030712",
          display: "standalone",
          icons: [
            { src: "/vite.svg", sizes: "192x192", type: "image/svg+xml" }
          ]
        }
      })
    ],
    server: {
      port: devServerPort,
      strictPort: true,
      // M13 — CSP headers for the dev server
      headers: {
        "Content-Security-Policy": [
          "default-src 'self'",
          "script-src 'self' 'unsafe-inline'",
          "style-src 'self' 'unsafe-inline'",
          "img-src 'self' data: blob:",
          "connect-src 'self' http://localhost:* ws://localhost:*"
        ].join("; ")
      },
      proxy: {
        "/api": {
          target: backendTarget,
          changeOrigin: true
        }
      }
    }
  };
});
export {
  vite_config_default as default
};
//# sourceMappingURL=data:application/json;base64,ewogICJ2ZXJzaW9uIjogMywKICAic291cmNlcyI6IFsidml0ZS5jb25maWcuanMiXSwKICAic291cmNlc0NvbnRlbnQiOiBbImNvbnN0IF9fdml0ZV9pbmplY3RlZF9vcmlnaW5hbF9kaXJuYW1lID0gXCJDOlxcXFxVc2Vyc1xcXFxBZG1pbmlzdHJhZG9yXFxcXERlc2t0b3BcXFxcZW5qb3lmdW5cXFxcZnJvbnRlbmRcIjtjb25zdCBfX3ZpdGVfaW5qZWN0ZWRfb3JpZ2luYWxfZmlsZW5hbWUgPSBcIkM6XFxcXFVzZXJzXFxcXEFkbWluaXN0cmFkb3JcXFxcRGVza3RvcFxcXFxlbmpveWZ1blxcXFxmcm9udGVuZFxcXFx2aXRlLmNvbmZpZy5qc1wiO2NvbnN0IF9fdml0ZV9pbmplY3RlZF9vcmlnaW5hbF9pbXBvcnRfbWV0YV91cmwgPSBcImZpbGU6Ly8vQzovVXNlcnMvQWRtaW5pc3RyYWRvci9EZXNrdG9wL2Vuam95ZnVuL2Zyb250ZW5kL3ZpdGUuY29uZmlnLmpzXCI7aW1wb3J0IHsgZGVmaW5lQ29uZmlnLCBsb2FkRW52IH0gZnJvbSAndml0ZSdcbmltcG9ydCByZWFjdCBmcm9tICdAdml0ZWpzL3BsdWdpbi1yZWFjdCdcbmltcG9ydCB0YWlsd2luZGNzcyBmcm9tICdAdGFpbHdpbmRjc3Mvdml0ZSdcbmltcG9ydCB7IFZpdGVQV0EgfSBmcm9tICd2aXRlLXBsdWdpbi1wd2EnXG5cbmNvbnN0IEJVSUxEX0FUID0gbmV3IERhdGUoKS50b0lTT1N0cmluZygpXG5jb25zdCBBUFBfVkVSU0lPTiA9IHByb2Nlc3MuZW52Lm5wbV9wYWNrYWdlX3ZlcnNpb24gfHwgJzAuMC4wJ1xuY29uc3QgQlVJTERfSUQgPSBwcm9jZXNzLmVudi5BUFBfQlVJTERfSUQgfHwgYCR7QVBQX1ZFUlNJT059LSR7QlVJTERfQVR9YFxuY29uc3QgREVGQVVMVF9ERVZfU0VSVkVSX1BPUlQgPSAzMDAzXG5jb25zdCBERUZBVUxUX0JBQ0tFTkRfVEFSR0VUID0gJ2h0dHA6Ly9sb2NhbGhvc3Q6ODA4MCdcblxuZnVuY3Rpb24gcmVzb2x2ZURldlNlcnZlclBvcnQocmF3UG9ydCkge1xuICBjb25zdCBwYXJzZWRQb3J0ID0gTnVtYmVyLnBhcnNlSW50KFN0cmluZyhyYXdQb3J0ID8/ICcnKSwgMTApXG4gIHJldHVybiBOdW1iZXIuaXNJbnRlZ2VyKHBhcnNlZFBvcnQpICYmIHBhcnNlZFBvcnQgPiAwID8gcGFyc2VkUG9ydCA6IERFRkFVTFRfREVWX1NFUlZFUl9QT1JUXG59XG5cbmZ1bmN0aW9uIHJlc29sdmVCYWNrZW5kVGFyZ2V0KHJhd1RhcmdldCkge1xuICBjb25zdCB0YXJnZXQgPSBTdHJpbmcocmF3VGFyZ2V0ID8/ICcnKS50cmltKClcbiAgcmV0dXJuIHRhcmdldCB8fCBERUZBVUxUX0JBQ0tFTkRfVEFSR0VUXG59XG5cbmZ1bmN0aW9uIGJ1aWxkVmVyc2lvbk1hbmlmZXN0UGx1Z2luKCkge1xuICBjb25zdCBwYXlsb2FkID0gSlNPTi5zdHJpbmdpZnkoXG4gICAge1xuICAgICAgYnVpbGRJZDogQlVJTERfSUQsXG4gICAgICB2ZXJzaW9uOiBBUFBfVkVSU0lPTixcbiAgICAgIGJ1aWx0QXQ6IEJVSUxEX0FULFxuICAgIH0sXG4gICAgbnVsbCxcbiAgICAyLFxuICApXG5cbiAgcmV0dXJuIHtcbiAgICBuYW1lOiAnZW5qb3lmdW4tYnVpbGQtdmVyc2lvbi1tYW5pZmVzdCcsXG4gICAgZ2VuZXJhdGVCdW5kbGUoKSB7XG4gICAgICB0aGlzLmVtaXRGaWxlKHtcbiAgICAgICAgdHlwZTogJ2Fzc2V0JyxcbiAgICAgICAgZmlsZU5hbWU6ICdhcHAtdmVyc2lvbi5qc29uJyxcbiAgICAgICAgc291cmNlOiBwYXlsb2FkLFxuICAgICAgfSlcbiAgICB9LFxuICB9XG59XG5cbi8vIGh0dHBzOi8vdml0ZS5kZXYvY29uZmlnL1xuZXhwb3J0IGRlZmF1bHQgZGVmaW5lQ29uZmlnKCh7IG1vZGUgfSkgPT4ge1xuICBjb25zdCBlbnYgPSBsb2FkRW52KG1vZGUsIHByb2Nlc3MuY3dkKCksICcnKVxuICBjb25zdCBkZXZTZXJ2ZXJQb3J0ID0gcmVzb2x2ZURldlNlcnZlclBvcnQoZW52LkZST05URU5EX1BPUlQgfHwgZW52LlBPUlQgfHwgZW52LlZJVEVfUE9SVClcbiAgY29uc3QgYmFja2VuZFRhcmdldCA9IHJlc29sdmVCYWNrZW5kVGFyZ2V0KFxuICAgIGVudi5WSVRFX0JBQ0tFTkRfVVJMIHx8IGVudi5CQUNLRU5EX1VSTCB8fCBlbnYuQVBJX1BST1hZX1RBUkdFVFxuICApXG5cbiAgcmV0dXJuIHtcbiAgICBkZWZpbmU6IHtcbiAgICAgICdpbXBvcnQubWV0YS5lbnYuVklURV9BUFBfQlVJTERfSUQnOiBKU09OLnN0cmluZ2lmeShCVUlMRF9JRCksXG4gICAgICAnaW1wb3J0Lm1ldGEuZW52LlZJVEVfQVBQX0JVSUxEX0FUJzogSlNPTi5zdHJpbmdpZnkoQlVJTERfQVQpLFxuICAgICAgJ2ltcG9ydC5tZXRhLmVudi5WSVRFX0FQUF9WRVJTSU9OJzogSlNPTi5zdHJpbmdpZnkoQVBQX1ZFUlNJT04pLFxuICAgIH0sXG4gICAgcGx1Z2luczogW1xuICAgICAgYnVpbGRWZXJzaW9uTWFuaWZlc3RQbHVnaW4oKSxcbiAgICAgIHJlYWN0KCksXG4gICAgICB0YWlsd2luZGNzcygpLFxuICAgICAgVml0ZVBXQSh7XG4gICAgICAgIHJlZ2lzdGVyVHlwZTogJ2F1dG9VcGRhdGUnLFxuICAgICAgICBpbmplY3RSZWdpc3RlcjogJ2F1dG8nLFxuICAgICAgICB3b3JrYm94OiB7XG4gICAgICAgICAgZ2xvYlBhdHRlcm5zOiBbJyoqLyoue2pzLGNzcyxodG1sLGljbyxwbmcsc3ZnfSddLFxuICAgICAgICAgIGNsZWFudXBPdXRkYXRlZENhY2hlczogdHJ1ZSxcbiAgICAgICAgICBjbGllbnRzQ2xhaW06IHRydWUsXG4gICAgICAgICAgc2tpcFdhaXRpbmc6IHRydWVcbiAgICAgICAgfSxcbiAgICAgICAgbWFuaWZlc3Q6IHtcbiAgICAgICAgICBuYW1lOiAnRW5qb3lGdW4nLFxuICAgICAgICAgIHNob3J0X25hbWU6ICdFbmpveUZ1bicsXG4gICAgICAgICAgZGVzY3JpcHRpb246ICdFbmpveUZ1biBFdmVudCBQbGF0Zm9ybSAmIFBPUycsXG4gICAgICAgICAgdGhlbWVfY29sb3I6ICcjMDMwNzEyJyxcbiAgICAgICAgICBiYWNrZ3JvdW5kX2NvbG9yOiAnIzAzMDcxMicsXG4gICAgICAgICAgZGlzcGxheTogJ3N0YW5kYWxvbmUnLFxuICAgICAgICAgIGljb25zOiBbXG4gICAgICAgICAgICB7IHNyYzogJy92aXRlLnN2ZycsIHNpemVzOiAnMTkyeDE5MicsIHR5cGU6ICdpbWFnZS9zdmcreG1sJyB9XG4gICAgICAgICAgXVxuICAgICAgICB9XG4gICAgICB9KVxuICAgIF0sXG4gICAgc2VydmVyOiB7XG4gICAgICBwb3J0OiBkZXZTZXJ2ZXJQb3J0LFxuICAgICAgc3RyaWN0UG9ydDogdHJ1ZSxcbiAgICAgIC8vIE0xMyBcdTIwMTQgQ1NQIGhlYWRlcnMgZm9yIHRoZSBkZXYgc2VydmVyXG4gICAgICBoZWFkZXJzOiB7XG4gICAgICAgICdDb250ZW50LVNlY3VyaXR5LVBvbGljeSc6IFtcbiAgICAgICAgICBcImRlZmF1bHQtc3JjICdzZWxmJ1wiLFxuICAgICAgICAgIFwic2NyaXB0LXNyYyAnc2VsZicgJ3Vuc2FmZS1pbmxpbmUnXCIsXG4gICAgICAgICAgXCJzdHlsZS1zcmMgJ3NlbGYnICd1bnNhZmUtaW5saW5lJ1wiLFxuICAgICAgICAgIFwiaW1nLXNyYyAnc2VsZicgZGF0YTogYmxvYjpcIixcbiAgICAgICAgICBcImNvbm5lY3Qtc3JjICdzZWxmJyBodHRwOi8vbG9jYWxob3N0Oiogd3M6Ly9sb2NhbGhvc3Q6KlwiLFxuICAgICAgICBdLmpvaW4oJzsgJyksXG4gICAgICB9LFxuICAgICAgcHJveHk6IHtcbiAgICAgICAgJy9hcGknOiB7XG4gICAgICAgICAgdGFyZ2V0OiBiYWNrZW5kVGFyZ2V0LFxuICAgICAgICAgIGNoYW5nZU9yaWdpbjogdHJ1ZSxcbiAgICAgICAgfVxuICAgICAgfVxuICAgIH1cbiAgfVxufSlcbiJdLAogICJtYXBwaW5ncyI6ICI7QUFBOFUsU0FBUyxjQUFjLGVBQWU7QUFDcFgsT0FBTyxXQUFXO0FBQ2xCLE9BQU8saUJBQWlCO0FBQ3hCLFNBQVMsZUFBZTtBQUV4QixJQUFNLFlBQVcsb0JBQUksS0FBSyxHQUFFLFlBQVk7QUFDeEMsSUFBTSxjQUFjLFFBQVEsSUFBSSx1QkFBdUI7QUFDdkQsSUFBTSxXQUFXLFFBQVEsSUFBSSxnQkFBZ0IsR0FBRyxXQUFXLElBQUksUUFBUTtBQUN2RSxJQUFNLDBCQUEwQjtBQUNoQyxJQUFNLHlCQUF5QjtBQUUvQixTQUFTLHFCQUFxQixTQUFTO0FBQ3JDLFFBQU0sYUFBYSxPQUFPLFNBQVMsT0FBTyxXQUFXLEVBQUUsR0FBRyxFQUFFO0FBQzVELFNBQU8sT0FBTyxVQUFVLFVBQVUsS0FBSyxhQUFhLElBQUksYUFBYTtBQUN2RTtBQUVBLFNBQVMscUJBQXFCLFdBQVc7QUFDdkMsUUFBTSxTQUFTLE9BQU8sYUFBYSxFQUFFLEVBQUUsS0FBSztBQUM1QyxTQUFPLFVBQVU7QUFDbkI7QUFFQSxTQUFTLDZCQUE2QjtBQUNwQyxRQUFNLFVBQVUsS0FBSztBQUFBLElBQ25CO0FBQUEsTUFDRSxTQUFTO0FBQUEsTUFDVCxTQUFTO0FBQUEsTUFDVCxTQUFTO0FBQUEsSUFDWDtBQUFBLElBQ0E7QUFBQSxJQUNBO0FBQUEsRUFDRjtBQUVBLFNBQU87QUFBQSxJQUNMLE1BQU07QUFBQSxJQUNOLGlCQUFpQjtBQUNmLFdBQUssU0FBUztBQUFBLFFBQ1osTUFBTTtBQUFBLFFBQ04sVUFBVTtBQUFBLFFBQ1YsUUFBUTtBQUFBLE1BQ1YsQ0FBQztBQUFBLElBQ0g7QUFBQSxFQUNGO0FBQ0Y7QUFHQSxJQUFPLHNCQUFRLGFBQWEsQ0FBQyxFQUFFLEtBQUssTUFBTTtBQUN4QyxRQUFNLE1BQU0sUUFBUSxNQUFNLFFBQVEsSUFBSSxHQUFHLEVBQUU7QUFDM0MsUUFBTSxnQkFBZ0IscUJBQXFCLElBQUksaUJBQWlCLElBQUksUUFBUSxJQUFJLFNBQVM7QUFDekYsUUFBTSxnQkFBZ0I7QUFBQSxJQUNwQixJQUFJLG9CQUFvQixJQUFJLGVBQWUsSUFBSTtBQUFBLEVBQ2pEO0FBRUEsU0FBTztBQUFBLElBQ0wsUUFBUTtBQUFBLE1BQ04scUNBQXFDLEtBQUssVUFBVSxRQUFRO0FBQUEsTUFDNUQscUNBQXFDLEtBQUssVUFBVSxRQUFRO0FBQUEsTUFDNUQsb0NBQW9DLEtBQUssVUFBVSxXQUFXO0FBQUEsSUFDaEU7QUFBQSxJQUNBLFNBQVM7QUFBQSxNQUNQLDJCQUEyQjtBQUFBLE1BQzNCLE1BQU07QUFBQSxNQUNOLFlBQVk7QUFBQSxNQUNaLFFBQVE7QUFBQSxRQUNOLGNBQWM7QUFBQSxRQUNkLGdCQUFnQjtBQUFBLFFBQ2hCLFNBQVM7QUFBQSxVQUNQLGNBQWMsQ0FBQyxnQ0FBZ0M7QUFBQSxVQUMvQyx1QkFBdUI7QUFBQSxVQUN2QixjQUFjO0FBQUEsVUFDZCxhQUFhO0FBQUEsUUFDZjtBQUFBLFFBQ0EsVUFBVTtBQUFBLFVBQ1IsTUFBTTtBQUFBLFVBQ04sWUFBWTtBQUFBLFVBQ1osYUFBYTtBQUFBLFVBQ2IsYUFBYTtBQUFBLFVBQ2Isa0JBQWtCO0FBQUEsVUFDbEIsU0FBUztBQUFBLFVBQ1QsT0FBTztBQUFBLFlBQ0wsRUFBRSxLQUFLLGFBQWEsT0FBTyxXQUFXLE1BQU0sZ0JBQWdCO0FBQUEsVUFDOUQ7QUFBQSxRQUNGO0FBQUEsTUFDRixDQUFDO0FBQUEsSUFDSDtBQUFBLElBQ0EsUUFBUTtBQUFBLE1BQ04sTUFBTTtBQUFBLE1BQ04sWUFBWTtBQUFBO0FBQUEsTUFFWixTQUFTO0FBQUEsUUFDUCwyQkFBMkI7QUFBQSxVQUN6QjtBQUFBLFVBQ0E7QUFBQSxVQUNBO0FBQUEsVUFDQTtBQUFBLFVBQ0E7QUFBQSxRQUNGLEVBQUUsS0FBSyxJQUFJO0FBQUEsTUFDYjtBQUFBLE1BQ0EsT0FBTztBQUFBLFFBQ0wsUUFBUTtBQUFBLFVBQ04sUUFBUTtBQUFBLFVBQ1IsY0FBYztBQUFBLFFBQ2hCO0FBQUEsTUFDRjtBQUFBLElBQ0Y7QUFBQSxFQUNGO0FBQ0YsQ0FBQzsiLAogICJuYW1lcyI6IFtdCn0K
