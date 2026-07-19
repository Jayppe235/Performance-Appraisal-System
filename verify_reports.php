<?php
// Quick verification script for report generation setup
declare(strict_types=1);

// Check if autoloader exists
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    die('Composer autoloader not found. Please run: composer install');
}

require_once __DIR__ . '/vendor/autoload.php';

// Check if libraries are loaded
$checks = [
    'PhpSpreadsheet' => 'PhpOffice\\PhpSpreadsheet\\Spreadsheet',
    'mPDF' => 'Mpdf\\Mpdf',
];

echo "Report Generation System - Verification\n";
echo "=======================================\n\n";

$allGood = true;
foreach ($checks as $name => $class) {
    if (class_exists($class)) {
        echo "✓ $name - OK\n";
    } else {
        echo "✗ $name - FAILED\n";
        $allGood = false;
    }
}

echo "\n";
if ($allGood) {
    echo "✓ All systems ready! Report generation is configured.\n";
    echo "\nSupported export formats:\n";
    echo "  • CSV (Comma-separated values)\n";
    echo "  • Excel (XLSX with formatting, styling, and auto-sized columns)\n";
    echo "  • PDF (Professional PDFs with headers and page numbers)\n";
} else {
    echo "✗ Some issues detected. Please run: composer install --ignore-platform-req=ext-gd\n";
}
