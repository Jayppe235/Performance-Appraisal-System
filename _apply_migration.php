<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';

try {
    $db = db();
    // Check if column already exists
    $check = $db->query("SHOW COLUMNS FROM peer_assignments LIKE 'deadline'");
    if ($check && $check->fetch()) {
        echo "Column 'deadline' already exists. Skipping.\n";
    } else {
        $db->exec("ALTER TABLE peer_assignments ADD COLUMN deadline DATE NULL DEFAULT NULL AFTER assigned_at");
        echo "Migration applied: added deadline column to peer_assignments.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
echo "Done.\n";
