import { existsSync, renameSync } from 'node:fs';
import { resolve } from 'node:path';
import { defineConfig } from 'vite';

// The runtime dev shell (public/app/index.html) is served raw by
// serveAppShell() under /app/, so it must keep absolute /app/src/... asset
// paths — which Vite cannot resolve as a build input (root is public/app).
// Build instead from a dedicated public/app/index.build.html whose paths are
// root-relative (/src/...); Vite resolves those against root and rewrites them
// with the /app/ base. Vite names the emitted HTML after the input file, so
// this plugin renames dist/index.build.html to dist/index.html — the file
// serveAppShell() prefers.
const emitIndexHtml = (outDir) => ({
  name: 'emit-index-html',
  closeBundle() {
    const built = resolve(outDir, 'index.build.html');
    if (existsSync(built)) {
      renameSync(built, resolve(outDir, 'index.html'));
    }
  },
});

const outDir = resolve('public/app/dist');

export default defineConfig({
  root: 'public/app',
  // Built assets land in public/app/dist/assets and are served by
  // tryStaticFile() as public/ + request-path, so the built shell must
  // reference them under /app/dist/ (not /app/, which would map to the
  // non-existent public/app/assets). serveAppShell() serves dist/index.html
  // for /app/, and the hashed .js/.css under /app/dist/assets fall through to
  // tryStaticFile().
  base: '/app/dist/',
  plugins: [emitIndexHtml(outDir)],
  build: {
    outDir,
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: { index: 'public/app/index.build.html' },
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
  },
  preview: {
    host: '0.0.0.0',
    port: 4173,
    strictPort: true,
  },
});
