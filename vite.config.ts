import { defineConfig } from 'vite'
import path from 'path'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/test/**/*.{test,spec}.?(c|m)[jt]s?(x)'],
  },
  build: {
    lib: {
      entry: path.resolve(__dirname, 'src/main.tsx'),
      name: 'acms-ai',
      fileName: 'build',
      formats: ['umd'],
    },
    outDir: 'app/bundle/',
    rollupOptions: {
      output: {
        entryFileNames: `acms-ai.js`,
        assetFileNames: ({ name }) => {
          if (name && name.endsWith('.css')) {
            return 'acms-ai.css'; // 固定ファイル名
          }
          return '[name].[ext]';
        },
      }
    }
  },
  server: {
    watch: {
      usePolling: true,
    },
  },
  define: {
    'process.env.NODE_ENV': JSON.stringify(process.env.NODE_ENV || 'production'),
    ...(process.env.NODE_ENV !== 'test' ? { 'process.env': JSON.stringify({}) } : {}),
  }
})

