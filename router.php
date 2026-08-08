<?php
declare(strict_types=1);

// Router for PHP's built-in development server. Production web servers should
// continue to use their native routing and access-control configuration.
$documentRoot = realpath(__DIR__);
$requestPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$basePath = '/' . trim((string) (getenv('PMAS_BASE_PATH') ?: ''), '/');

if ($basePath !== '/' && ($requestPath === $basePath || str_starts_with($requestPath, $basePath . '/'))) {
    $requestPath = substr($requestPath, strlen($basePath)) ?: '/';
}

$relativePath = ltrim(str_replace('\\', '/', $requestPath), '/');
$segments = array_values(array_filter(explode('/', $relativePath), static fn (string $part): bool => $part !== ''));

if (in_array('..', $segments, true)) {
    http_response_code(400);
    exit('Invalid path.');
}

$blocked = preg_match(
    '~(^|/)(?:\.env(?:\..*)?|composer\.(?:json|lock)|package(?:-lock)?\.json|phpunit[^/]*|setup\.php|_[^/]*|database|private-backups|tests|vendor|node_modules)(?:/|$)|\.(?:sql|log|bak|dump|ini|ps1)$~i',
    $relativePath
) === 1;

if ($blocked) {
    http_response_code(404);
    exit('Not found.');
}

$candidate = $documentRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
$resolved = realpath($candidate);

if ($resolved !== false && str_starts_with($resolved, $documentRoot . DIRECTORY_SEPARATOR) && is_file($resolved)) {
    if (strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) === 'php') {
        require $resolved;
        return true;
    }
    return false;
}

if (str_starts_with($requestPath, '/api/') || str_starts_with($requestPath, '/reports/')) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Endpoint not found.']);
    return true;
}

require __DIR__ . '/index.html';
return true;
