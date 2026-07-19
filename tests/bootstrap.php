<?php
/**
 * PHPUnit bootstrap — defines test database constants before config.php loads.
 *
 * In each @runInSeparateProcess test, this bootstrap runs first.
 * The define() calls here pre-define constants so that config.php's
 * define() calls produce only harmless E_WARNING (suppressed below).
 */

declare(strict_types=1);

// Prevent config.php redefine warnings by pre-defining everything
foreach ([
    'APP_NAME' => 'DIPASCAF',
    'BASE_URL' => '',
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'pmas_test_phpunit',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'DATA_ENCRYPTION_KEY' => 'test-key-dipascaf-unit-tests-2026',
    'GEMINI_API_KEY' => '',
    'GEMINI_MODEL' => 'gemini-2.5-flash',
    'OPENAI_API_KEY' => '',
    'OPENAI_MODEL' => 'gpt-4o-mini',
] as $key => $value) {
    if (!defined($key)) {
        define($key, $value);
    }
}

// ── Helper: include a source file while suppressing redefine warnings ──
if (!function_exists('include_source_silently')) {
    function include_source_silently(string $path): void
    {
        $level = error_reporting(E_ALL & ~E_WARNING);
        require_once $path;
        error_reporting($level);
    }
}

// Suppress constant-redefinition warnings when config.php is loaded
// as part of the require chain (our bootstrap defines them first).
set_error_handler(function (int $severity, string $message): bool {
    // Suppress "Constant X already defined" warnings from config.php
    if (str_contains($message, 'already defined')) {
        return true;
    }
    // Let all other errors/warnings pass through
    return false;
}, E_WARNING);


