import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  // Not VITE_-prefixed on purpose — this is a dev-server-only setting
  // (used here in Node, never bundled into client code), unlike
  // VITE_API_URL which the browser actually fetches against.
  const env = loadEnv(mode, process.cwd(), '')
  const backendTarget = env.BACKEND_URL ?? 'https://localhost:8543'

  return {
    plugins: [react(), tailwindcss()],
    server: {
      proxy: {
        // Frontend calls VITE_API_URL=/api (see .env) so the browser only
        // ever talks to its own origin — the refresh_token cookie is then
        // genuinely first-party instead of cross-site. Set-Cookie from a
        // cross-origin SameSite=None response is exactly what browsers'
        // increasingly strict third-party cookie policies silently drop
        // (login succeeds, but the cookie never gets attached to the next
        // request, so the silent refresh-on-reload always 401s) — this
        // proxy sidesteps the problem instead of relying on cookie
        // attributes alone. The proxy runs in this Node process, so the
        // target is always reachable at localhost regardless of which
        // host/IP a browser (including a phone on the LAN) used to load
        // the frontend — no more hardcoding a LAN IP for device testing.
        '/api': {
          target: backendTarget,
          changeOrigin: true,
          secure: false, // backend uses Caddy's self-signed dev certificate
        },
        // roadmap Phase 15.2: gym logos are served straight off disk by
        // Caddy (frankenphp/Caddyfile's `root /app/public` + "serve if
        // file exists" rule) at this same path — no rewrite needed, and
        // no dedicated PHP download route to go with it.
        '/uploads': {
          target: backendTarget,
          changeOrigin: true,
          secure: false,
        },
        // setly-phase-exercise-media.md §6: same straight-off-disk Caddy
        // serving as /uploads above, for ImportExercisesCommand's WebP
        // catalog media (immutable long-cache headers set in the
        // Caddyfile) — a different path prefix so imported reference data
        // stays visually distinct from user-uploaded content.
        '/media': {
          target: backendTarget,
          changeOrigin: true,
          secure: false,
        },
      },
    },
  }
})
