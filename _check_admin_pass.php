<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

$emails = ['admin@dipascaf.edu', 'Mark@dipascaf.edu'];
$db = db();

$passwords = array_merge(
    ['password', 'admin', 'Admin', 'Admin1', 'administrator', 'Administrator'],
    ['mark', 'Mark', 'tenebroso', 'Tenebroso', 'Mark123', 'mark123'],
    ['dipascaf', 'Dipascaf', 'DIPASCAF', 'pmas2024', 'PMAS2024'],
    ['letmein', 'welcome', 'test', '123456', '12345678', 'password123'],
    ['qwerty', 'abc123', '111111', 'passw0rd', 'Pass1234', 'P@ssw0rd'],
    ['Admin123', 'admin123', 'admin@123', 'Master', 'master']
);

foreach ($emails as $email) {
    $stmt = $db->prepare('SELECT id, email, role, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if (!$row) { echo "$email: NOT FOUND\n"; continue; }
    
    $hash = $row['password_hash'];
    echo "Hash for {$row['email']}: {$hash}\n";
    
    foreach ($passwords as $pw) {
        if (password_verify($pw, $hash)) {
            echo "  >>> MATCHED: '{$pw}'\n";
            break 2;
        }
    }
    echo "  >>> No match found\n";
}
