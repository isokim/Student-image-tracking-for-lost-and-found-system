import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite'
import path from 'path'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    // IPv4 loopback, so both http://localhost:5173 and
    // http://127.0.0.1:5173 work. The browser keeps separate cookies for
    // each, which lets you be signed in as two accounts at once while
    // testing (e.g. a student in one tab, staff in the other).
    host: '127.0.0.1',
    port: 5173,
  },
})
