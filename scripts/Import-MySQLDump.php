<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This importer may only be run from the command line.\n");
    exit(1);
}

$dumpPath = $argv[1] ?? '';
if ($dumpPath === '' || !is_file($dumpPath)) {
    fwrite(STDERR, "Usage: php Import-MySQLDump.php <dump.sql>\n");
    exit(1);
}

$required = ['PMAS_IMPORT_HOST', 'PMAS_IMPORT_PORT', 'PMAS_IMPORT_DB', 'PMAS_IMPORT_USER', 'PMAS_IMPORT_PASS'];
foreach ($required as $name) {
    if (getenv($name) === false || ($name !== 'PMAS_IMPORT_PASS' && getenv($name) === '')) {
        fwrite(STDERR, "Missing required environment variable: {$name}\n");
        exit(1);
    }
}

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('PMAS_IMPORT_HOST'),
        getenv('PMAS_IMPORT_PORT'),
        getenv('PMAS_IMPORT_DB')
    ),
    (string)getenv('PMAS_IMPORT_USER'),
    (string)getenv('PMAS_IMPORT_PASS'),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]
);

$handle = fopen($dumpPath, 'rb');
if ($handle === false) {
    throw new RuntimeException('Unable to open the SQL dump.');
}

$delimiter = ';';
$statement = '';
$executed = 0;
$deferredForeignKeys = 0;

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
try {
    while (($line = fgets($handle)) !== false) {
        $trimmedLine = trim($line);
        if (preg_match('/^DELIMITER\s+(.+)$/i', $trimmedLine, $matches) === 1) {
            if (trim($statement) !== '') {
                throw new RuntimeException('Unexpected DELIMITER directive inside a pending SQL statement.');
            }
            $delimiter = trim($matches[1]);
            continue;
        }

        $statement .= $line;
        $trimmedStatement = rtrim($statement);
        if ($trimmedStatement === '' || !str_ends_with($trimmedStatement, $delimiter)) {
            continue;
        }

        $sql = trim(substr($trimmedStatement, 0, -strlen($delimiter)));
        $statement = '';
        if ($sql === '' || preg_match('/^(--|#)/', $sql) === 1 && !str_contains($sql, "\n")) {
            continue;
        }

        // Local dumps commonly pin triggers/routines to root@localhost. That
        // account does not exist on hosted MySQL, so let Railway assign the
        // authenticated importing account as the definer instead.
        $sql = preg_replace('/\bDEFINER=`[^`]+`@`[^`]+`\s*/i', '', $sql) ?? $sql;

        // MariaDB permits some foreign keys to be declared before their
        // referenced tables exist. MySQL 9 validates those declarations more
        // strictly even while FOREIGN_KEY_CHECKS is disabled. Create every
        // table first; the caller restores and validates constraints after all
        // rows (including generated-column tables) have been copied.
        if (preg_match('/^CREATE TABLE\s+`/i', ltrim($sql)) === 1) {
            $lines = preg_split('/\R/', $sql) ?: [$sql];
            $lines = array_values(array_filter($lines, static function (string $line) use (&$deferredForeignKeys): bool {
                if (preg_match('/^\s*CONSTRAINT\s+/i', $line) === 1) {
                    $deferredForeignKeys++;
                    return false;
                }
                return true;
            }));
            $sql = implode("\n", $lines);
            $sql = preg_replace('/,(\s*\)\s*ENGINE)/i', '$1', $sql) ?? $sql;
        }
        try {
            $pdo->exec($sql);
        } catch (PDOException $exception) {
            $preview = preg_replace('/\s+/', ' ', substr($sql, 0, 240)) ?? substr($sql, 0, 240);
            throw new RuntimeException("Import failed near: {$preview}", 0, $exception);
        }
        $executed++;
    }

    $nonCommentRemainder = preg_replace('/^\s*(?:--|#).*$/m', '', $statement) ?? $statement;
    if (trim($nonCommentRemainder) !== '') {
        throw new RuntimeException('The SQL dump ended with an incomplete statement.');
    }
} finally {
    fclose($handle);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

fwrite(STDOUT, "Imported {$executed} SQL statements.\n");
fwrite(STDOUT, "Deferred {$deferredForeignKeys} foreign-key constraints.\n");
