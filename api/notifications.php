<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/notifications.php';

notify_ensure_schema();

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedDevOrigins = [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:5174',
    'http://127.0.0.1:5174',
    'http://localhost:5175',
    'http://127.0.0.1:5175',
];

if (in_array($origin, $allowedDevOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Vary: Origin');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user = current_user();
if ($user === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'notifications' => [], 'unread_count' => 0, 'message' => 'Please log in to view notifications.']);
    exit;
}

$userId = (int) $user['id'];
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$action = (string) ($_GET['action'] ?? $input['action'] ?? 'list');

try {
    switch ($action) {
        case 'list':
            $limit = min(50, max(1, (int) ($_GET['limit'] ?? $input['limit'] ?? 20)));
            $filter = (string) ($_GET['filter'] ?? $_GET['tab'] ?? $input['filter'] ?? $input['tab'] ?? 'all');
            $unreadOnly = ($_GET['unread_only'] ?? $input['unread_only'] ?? '') === '1' || $filter === 'unread';
            $raw = notify_fetch($userId, $limit, $unreadOnly, ['filter' => $filter]);
            $notifications = array_map('notify_format', $raw);
            $unreadCount = notify_unread_count($userId);

            echo json_encode([
                'ok' => true,
                'notifications' => $notifications,
                'unread_count' => $unreadCount,
                'latest_id' => (int) ($notifications[0]['id'] ?? 0),
                'refreshed_at' => gmdate('c'),
            ]);
            break;

        case 'mark_read':
            $notificationId = (int) ($_POST['id'] ?? $_GET['id'] ?? $input['id'] ?? 0);
            if ($notificationId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => 'Notification ID is required.', 'unread_count' => notify_unread_count($userId)]);
                break;
            }

            $success = notify_mark_read($notificationId, $userId);
            echo json_encode([
                'ok' => $success,
                'message' => $success ? 'Marked as read.' : 'Notification not found.',
                'unread_count' => notify_unread_count($userId),
            ]);
            break;

        case 'mark_all_read':
            $success = notify_mark_all_read($userId);
            echo json_encode([
                'ok' => $success,
                'message' => $success ? 'All notifications marked as read.' : 'Unable to mark notifications as read.',
                'unread_count' => notify_unread_count($userId),
            ]);
            break;

        case 'delete':
            $notificationId = (int) ($_POST['id'] ?? $_GET['id'] ?? $input['id'] ?? 0);
            if ($notificationId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => 'Notification ID is required.', 'unread_count' => notify_unread_count($userId)]);
                break;
            }

            $success = notify_delete($notificationId, $userId);
            echo json_encode([
                'ok' => $success,
                'message' => $success ? 'Notification deleted.' : 'Notification not found.',
                'unread_count' => notify_unread_count($userId),
            ]);
            break;

        case 'unread_count':
            echo json_encode(['ok' => true, 'unread_count' => notify_unread_count($userId)]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'notifications' => [], 'unread_count' => notify_unread_count($userId), 'message' => 'Unknown action: ' . $action]);
            break;
    }
} catch (Throwable $exception) {
    notify_log('API action failed (' . $action . ') for user ' . $userId . ': ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'notifications' => [], 'unread_count' => 0, 'message' => 'Unable to complete the notification request.']);
}
