<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/react_redirect.php';
redirect_to_react('/admin/dashboard');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$user = current_user();
$userId = (int) $user['id'];
$role = $user['role'];

// Redirect based on role
$analyticsPath = match ($role) {
    'admin' => 'admin_analytics',
    'dean' => 'dean_analytics',
    'program_head' => 'program_head_analytics',
    'teacher' => 'teacher_analytics',
    'hr' => 'hr_analytics',
    default => null,
};

if (!$analyticsPath || !file_exists(__DIR__ . '/' . $analyticsPath . '.php')) {
    redirect('/dashboards/' . ($role === 'teacher' ? 'teacher' : $role) . '.php');
}

redirect('/dashboards/' . $analyticsPath . '.php');
?>
