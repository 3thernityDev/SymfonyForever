import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
  base: '/build/',
  publicDir: false,
  build: {
    outDir: resolve(__dirname, 'public/build'),
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: {
        game: resolve(__dirname, 'assets/game/main.ts'),
        lobby: resolve(__dirname, 'assets/lobby/main.ts'),
      },
    },
  },
  server: {
    port: 5173,
    strictPort: true,
    cors: true,
  },
});
