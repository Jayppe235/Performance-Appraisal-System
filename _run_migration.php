<?php
/**
 * Apply migration 011: Add deadline column to peer_assignments
 */
require_once __DIR__ . '/includes/db.php';

try {
    $db = db();
    $db->exec("ALTER TABLE peer_assignments ADD COLUMN deadline DATE NULL DEFAULT NULL AFTER assigned_at");
    echo "Migration 011 applied: deadline column added to peer_assignments.\n";
} catch (PDOException $e) {
    if ($e->getCode() == '42S21') { // Duplicate column
        echo "Migration 011 already applied (deadline column exists).\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
