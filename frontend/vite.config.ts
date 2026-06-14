import path from 'node:path';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  base: './',
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
    },
  },
  build: {
    outDir: path.resolve(__dirname, '../dist-storefront'),
    emptyOutDir: true,
  },
  publicDir: false,
  server: {
    port: 5173,
    fs: { allow: ['..'] },
    proxy: {
      '/api': { target: 'http://localhost:8080', changeOrigin: true },
      '/main.js': { target: 'http://localhost:8080', changeOrigin: true },
      '/js': { target: 'http://localhost:8080', changeOrigin: true },
      '/images': { target: 'http://localhost:8080', changeOrigin: true },
      '/main-security.js': { target: 'http://localhost:8080', changeOrigin: true },
      '/logger.js': { target: 'http://localhost:8080', changeOrigin: true },
      '/css': { target: 'http://localhost:8080', changeOrigin: true },
    },
  },
});
