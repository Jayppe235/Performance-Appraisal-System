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

// Most hosted MySQL providers expose one connection URL. Prefer it when set,
// while retaining the individual PMAS_DB_* variables used by XAMPP and
// traditional shared hosting.
$databaseUrl = pmas_env('PMAS_DATABASE_URL', pmas_env('DATABASE_URL'));
$databaseConfig = $databaseUrl !== '' ? parse_url($databaseUrl) : false;
if ($databaseUrl !== '' && (!is_array($databaseConfig) || !in_array(strtolower((string)($databaseConfig['scheme'] ?? '')), ['mysql', 'mariadb'], true))) {
    throw new RuntimeException('PMAS_DATABASE_URL must be a valid mysql:// or mariadb:// URL.');
}
$databaseNameFromUrl = is_array($databaseConfig) ? ltrim((string)($databaseConfig['path'] ?? ''), '/') : '';
define('DB_HOST', is_array($databaseConfig) ? rawurldecode((string)($databaseConfig['host'] ?? '')) : pmas_env('PMAS_DB_HOST', 'localhost'));
define('DB_PORT', is_array($databaseConfig) ? (string)($databaseConfig['port'] ?? 3306) : pmas_env('PMAS_DB_PORT', '3306'));
define('DB_NAME', $databaseNameFromUrl !== '' ? rawurldecode($databaseNameFromUrl) : pmas_env('PMAS_DB_NAME', 'pmas_db_clean'));
define('DB_USER', is_array($databaseConfig) ? rawurldecode((string)($databaseConfig['user'] ?? '')) : pmas_env('PMAS_DB_USER', 'root'));
define('DB_PASS', is_array($databaseConfig) ? rawurldecode((string)($databaseConfig['pass'] ?? '')) : pmas_env('PMAS_DB_PASS'));
define('DB_SSL_CA', pmas_env('PMAS_DB_SSL_CA'));

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
