<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if ($user === null) {
    http_response_code(401);
    echo json_encode(['ok'=>false, 'message'=>'Authentication required.']);
    exit;
}
if (($user['role'] ?? '') !== 'admin_hr') {
    http_response_code(403);
    echo json_encode(['ok'=>false, 'message'=>'Audit logs are available to Admin/HR accounts only.']);
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = min(100, max(10, (int)($_GET['page_size'] ?? 25)));
$offset = ($page - 1) * $pageSize;
$search = trim((string)($_GET['search'] ?? ''));
$params = [];
$where = '';
if ($search !== '') {
    $where = 'WHERE al.description LIKE :search OR u.full_name LIKE :search OR u.email LIKE :search';
    $params['search'] = '%' . $search . '%';
}

$count = db()->prepare("SELECT COUNT(*) FROM activity_logs al LEFT JOIN users u ON u.id=al.user_id {$where}");
$count->execute($params);
$total = (int)$count->fetchColumn();
$query = db()->prepare(
    "SELECT al.id,al.user_id,al.description,al.created_at,u.full_name,u.email,u.role
     FROM activity_logs al LEFT JOIN users u ON u.id=al.user_id {$where}
     ORDER BY al.created_at DESC,al.id DESC LIMIT {$pageSize} OFFSET {$offset}"
);
$query->execute($params);

echo json_encode([
    'ok'=>true,
    'data'=>array_map(static fn(array $row): array => [
        'id'=>(int)$row['id'],
        'userId'=>$row['user_id'] === null ? null : (int)$row['user_id'],
        'userName'=>(string)($row['full_name'] ?: 'System'),
        'email'=>(string)($row['email'] ?? ''),
        'role'=>(string)($row['role'] ?? 'system'),
        'description'=>(string)$row['description'],
        'createdAt'=>(string)$row['created_at'],
    ], $query->fetchAll()),
    'pagination'=>[
        'page'=>$page,'pageSize'=>$pageSize,'total'=>$total,
        'pages'=>max(1, (int)ceil($total / $pageSize)),
    ],
]);
