<?php
declare(strict_types=1);

/** Allow credentialed cross-origin requests only from local Vite development. */
function allow_local_dev_cors(array $methods = ['GET', 'POST', 'OPTIONS']): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $isLocal = is_string($origin) && preg_match(
        '#^https?://(?:localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$#',
        $origin
    ) === 1;

    if ($isLocal) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Accept');
        header('Access-Control-Allow-Methods: ' . implode(', ', $methods));
        header('Vary: Origin');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code($isLocal ? 204 : 403);
        exit;
    }
}
