export function apiUrl(path) {
  const cleanPath = path.startsWith('/') ? path : `/${path}`;

  // Vite dev server proxies API requests to the standalone PHP server.
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

  // Production is same-origin under Vite's configured application base.
  const appBase = String(import.meta.env.BASE_URL || '/').replace(/\/+$/, '');
  return `${appBase}${cleanPath}`;
}

export function assetUrl(path) {
  const value = String(path || '').trim();
  if (value === '') return '';
  if (/^(https?:)?\/\//i.test(value) || value.startsWith('data:') || value.startsWith('blob:')) return value;

  const cleanPath = value.startsWith('/') ? value : `/${value}`;
  if (cleanPath.startsWith('/PMAS/')) return cleanPath;

  if (import.meta.env.DEV) {
    return cleanPath;
  }

  const configuredBase = (import.meta.env.VITE_API_URL || '').trim();
  if (configuredBase) {
    const base = configuredBase.replace(/\/+$/, '').replace(/\/api\/?$/i, '');
    return `${base}${cleanPath}`;
  }

  const appBase = String(import.meta.env.BASE_URL || '/').replace(/\/+$/, '');
  return `${appBase}${cleanPath}`;
}

/** Route report downloads through executable PHP instead of exposing a
 * reports/*.php file to static hosting. */
export function reportUrl(endpoint) {
  const cleanEndpoint = String(endpoint || '').split('/').pop();
  const url = new URL(apiUrl('/api/vercel.php'), window.location.origin);
  url.searchParams.set('_scope', 'reports');
  url.searchParams.set('_endpoint', cleanEndpoint);
  return url.toString();
}
