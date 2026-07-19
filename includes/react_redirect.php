<?php
declare(strict_types=1);

function react_app_url(string $path = '/login'): string
{
    require_once __DIR__ . '/config.php';
    $baseUrl = getenv('PMAS_REACT_URL') ?: APP_URL;

    return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
}

function redirect_to_react(string $path = '/login'): never
{
    header('Location: ' . react_app_url($path));
    exit;
}
