import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// The SPA talks to the Laravel API. In dev, /api is proxied to the backend so
// there's no CORS to configure. Point this at wherever your backend runs:
//   - Herd/Valet: http://potandleaf-backend.test  (default below)
//   - php artisan serve: http://localhost:8000
// Override without editing this file via a .env: VITE_API_PROXY=http://localhost:8000
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const target = env.VITE_API_PROXY || 'http://potandleaf-backend.test';

  return {
    plugins: [react(), tailwindcss()],
    server: {
      port: 5173,
      proxy: {
        '/api': { target, changeOrigin: true },
        '/storage': { target, changeOrigin: true },
      },
    },
  };
});
