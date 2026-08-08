/// <reference types="vitest" />
import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import { readFileSync } from 'node:fs';

function cssDiagnosticCompatibility() {
  return {
    name: 'css-diagnostic-compatibility',
    generateBundle(_, bundle) {
      for (const asset of Object.values(bundle)) {
        if (asset.type !== 'asset' || !asset.fileName.endsWith('.css')) {
          continue;
        }

        let css = String(asset.source);

        css = css
          .replace(/(?<!appearance:button;)-webkit-appearance:button;/g, 'appearance:button;-webkit-appearance:button;')
          .replace(/(?<!appearance:textfield;)-webkit-appearance:textfield;/g, 'appearance:textfield;-webkit-appearance:textfield;')
          .replace(/(?<!appearance:none;)-webkit-appearance:none;/g, 'appearance:none;-webkit-appearance:none;')
          .replace(/(?<!appearance:none;)-webkit-appearance:none(?=})/g, 'appearance:none;-webkit-appearance:none')
          .replace(/-webkit-line-clamp:([^;]+);(?!line-clamp:\1;)/g, '-webkit-line-clamp:$1;line-clamp:$1;')
          .replace(/-moz-column-gap:([^;]+);(?!column-gap:\1;)/g, '-moz-column-gap:$1;column-gap:$1;')
          .replace('vertical-align:middle;display:block', 'display:block');

        asset.source = css;
      }
    },
  };
}

function developmentIndex() {
  return {
    name: 'development-index',
    apply: 'serve',
    enforce: 'pre',
    transformIndexHtml() {
      return readFileSync('index.dev.html', 'utf8');
    },
  };
}

function legacyDevelopmentBasePath() {
  return {
    name: 'legacy-development-base-path',
    apply: 'serve',
    enforce: 'pre',
    configureServer(server) {
      server.middlewares.use((request, _response, next) => {
        if (request.url === '/PMAS' || request.url?.startsWith('/PMAS/')) {
          request.url = request.url.slice('/PMAS'.length) || '/';
        }
        next();
      });
    },
  };
}

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), 'VITE_');
  const phpOrigin = env.VITE_DEV_PHP_ORIGIN || 'http://127.0.0.1:8080';
  const configuredPhpBasePath = (env.VITE_DEV_PHP_BASE_PATH || '').replace(/^\/+|\/+$/g, '');
  const phpBasePath = configuredPhpBasePath ? `/${configuredPhpBasePath}` : '';
  const useHttps = env.VITE_DEV_HTTPS === 'true';
  const https = useHttps
    ? {
        cert: readFileSync(env.VITE_DEV_SSL_CERT),
        key: readFileSync(env.VITE_DEV_SSL_KEY),
      }
    : undefined;

  return ({
  base: mode === 'production' ? '/PMAS/' : '/',
  plugins: [legacyDevelopmentBasePath(), developmentIndex(), react(), cssDiagnosticCompatibility()],
  server: {
    https,
    proxy: {
      '/api': {
        target: phpOrigin,
        changeOrigin: true,
        rewrite: (path) => `${phpBasePath}${path}`,
      },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    include: ['src/**/*.test.js', 'src/**/*.test.jsx'],
  },
  });
});
