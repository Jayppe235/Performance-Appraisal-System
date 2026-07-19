export function apiUrl(path) {
  const cleanPath = path.startsWith('/') ? path : `/${path}`;

  // Vite dev server — proxy handles /api/ → /PMAS/api/ rewriting
  // env vars (VITE_API_URL) are ignored in dev mode so requests go through the proxy,
  // preventing CORS issues with direct Apache requests.
  if (import.meta.env.DEV) {
    return cleanPath;
  }

  // Environment variable override (for production builds)
  const configuredBase = (import.meta.env.VITE_API_URL || '').trim();
  if (configuredBase) {
    const base = configuredBase.replace(/\/+$/, '').replace(/\/api\/?$/i, '');
    return `${base}${cleanPath}`;
  }

  // Production is same-origin by default (for example https://pmas.example.com/api/...).
  return cleanPath;
}

export function assetUrl(path) {
  const value = String(path || '').trim();
  if (value === '') return '';
  if (/^(https?:)?\/\//i.test(value) || value.startsWith('data:') || value.startsWith('blob:')) return value;

  const cleanPath = value.startsWith('/') ? value : `/${value}`;
  if (cleanPath.startsWith('/PMAS/')) return cleanPath;

  if (import.meta.env.DEV) {
    const basePath = `/${(import.meta.env.VITE_DEV_PHP_BASE_PATH || 'PMAS').replace(/^\/+|\/+$/g, '')}`;
    return `${basePath}${cleanPath}`;
  }

  const configuredBase = (import.meta.env.VITE_API_URL || '').trim();
  if (configuredBase) {
    const base = configuredBase.replace(/\/+$/, '').replace(/\/api\/?$/i, '');
    return `${base}${cleanPath}`;
  }

  return cleanPath;
}
