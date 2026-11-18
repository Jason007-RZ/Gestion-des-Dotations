import { fileURLToPath, URL } from 'node:url'

import { defineConfig, loadEnv, type Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'

// Content-Security-Policy ajoutée UNIQUEMENT au build de production (npm run build) :
// le serveur de développement a besoin de scripts inline pour le rechargement à chaud.
// Seuls l'application elle-même et l'adresse de l'API (VITE_API_URL) sont autorisées : un script
// injecté ne pourrait ni se charger depuis un autre site, ni envoyer de données ailleurs.
function politiqueDeSecurite(origineApi: string): Plugin {
  return {
    name: 'csp-production',
    apply: 'build',
    transformIndexHtml(html) {
      const csp = [
        "default-src 'self'",
        "script-src 'self'",
        "style-src 'self' 'unsafe-inline'", // styles dynamiques de Vue
        "img-src 'self' data: blob:",
        "font-src 'self' data:",
        `connect-src 'self' ${origineApi}`,
        "object-src 'self' blob:", // visionneuse PDF du navigateur (documents générés par l'API)
        "frame-src 'self' blob:",
        "base-uri 'self'",
        "form-action 'self'"
      ].join('; ')

      return html.replace(
        '</title>',
        `</title>\n    <meta http-equiv="Content-Security-Policy" content="${csp}">\n    <meta name="referrer" content="no-referrer">`
      )
    }
  }
}

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const origineApi = new URL(env.VITE_API_URL || 'http://127.0.0.1:8000/api').origin

  return {
    plugins: [vue(), vueDevTools(), politiqueDeSecurite(origineApi)],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url))
      }
    }
  }
})
