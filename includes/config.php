<?php
declare(strict_types=1);

define('APP_NAME', 'DIPASCAF');

function pmas_env(string $name, string $localDefault = ''): string
{
    $value = getenv($name);
    return $value === false ? $localDefault : trim((string) $value);
}

function pmas_base_path(string $path): string
{
    $path = trim($path);
    if ($path === '' || $path === '/') {
        return '';
    }
    return '/' . trim($path, '/');
}

$environment = strtolower(pmas_env('PMAS_ENV', 'local'));
if (!in_array($environment, ['local', 'production'], true)) {
    throw new RuntimeException('PMAS_ENV must be either local or production.');
}
define('PMAS_ENV', $environment);
define('IS_PRODUCTION', PMAS_ENV === 'production');

define('APP_URL', rtrim(pmas_env('PMAS_APP_URL', 'http://localhost/PMAS'), '/'));
define('BASE_URL', pmas_base_path(pmas_env('PMAS_BASE_PATH', '/PMAS')));
define('DB_HOST', pmas_env('PMAS_DB_HOST', 'localhost'));
define('DB_PORT', pmas_env('PMAS_DB_PORT', '3306'));
define('DB_NAME', pmas_env('PMAS_DB_NAME', 'pmas_db_clean'));
define('DB_USER', pmas_env('PMAS_DB_USER', 'root'));
define('DB_PASS', pmas_env('PMAS_DB_PASS'));

$configuredDataKey = pmas_env('PMAS_DATA_KEY');
if (IS_PRODUCTION) {
    if (!str_starts_with(strtolower(APP_URL), 'https://')) {
        throw new RuntimeException('PMAS_APP_URL must use HTTPS in production.');
    }
    if (strtolower(DB_USER) === 'root' || DB_PASS === '') {
        throw new RuntimeException('Production requires a dedicated database user and password.');
    }
    if (strlen($configuredDataKey) < 32) {
        throw new RuntimeException('PMAS_DATA_KEY must contain at least 32 characters in production.');
    }
}
define('DATA_ENCRYPTION_KEY', $configuredDataKey !== '' ? $configuredDataKey : hash('sha256', DB_NAME . DB_USER . APP_NAME));

define('GEMINI_API_KEY', pmas_env('GEMINI_API_KEY'));
define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash');
define('OPENAI_API_KEY', pmas_env('OPENAI_API_KEY'));
define('OPENAI_MODEL', getenv('OPENAI_MODEL') ?: 'gpt-4o-mini');
define('PMAS_REACT_URL', rtrim(pmas_env('PMAS_REACT_URL', APP_URL), '/'));
define('RESEND_API_KEY', pmas_env('RESEND_API_KEY'));
define('RESEND_FROM_EMAIL', pmas_env('RESEND_FROM_EMAIL'));
define('RESEND_FROM_NAME', pmas_env('RESEND_FROM_NAME', APP_NAME));

const ROLES = [
    'admin_hr' => 'Admin/HR',
    'vpaa' => 'VPAA',
    'dean' => 'Dean',
    'program_head' => 'Program Head',
    'teacher' => 'Teacher',
];
