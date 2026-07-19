<?php
declare(strict_types=1);

/**
 * Form A Admin API
 * Handles PMAS Form A (Administrator Evaluation) categories and submissions.
 *
 * GET  ?action=categories  → Returns all Form A categories with questions
 * POST action=submit       → Submits a Form A evaluation
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';
require_once __DIR__ . '/../includes/evaluation_period.php';

// ── CORS ──────────────────────────────────────────────────────────────────────
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
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Vary: Origin');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// ── Auth ──────────────────────────────────────────────────────────────────────
$user = current_user();
if ($user === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthenticated.']);
    exit;
}

// ── Route ─────────────────────────────────────────────────────────────────────
try {
    $method = $_SERVER['REQUEST_METHOD'];

    $saveCategories = static function (array $categories): void {
        if ($categories === []) {
            throw new RuntimeException('At least one Form A category is required.');
        }

        db()->beginTransaction();
        try {
            $keptCategoryIds = [];
            foreach (array_values($categories) as $index => $category) {
                $categoryId = isset($category['id']) && is_numeric($category['id']) ? (int) $category['id'] : 0;
                $title = trim((string) ($category['title'] ?? ''));
                $description = trim((string) ($category['description'] ?? ''));
                $weight = (float) ($category['factor_weight'] ?? $category['weight'] ?? 0);
                $questions = is_array($category['questions'] ?? null) ? $category['questions'] : [];

                if ($title === '') {
                    throw new RuntimeException('Each Form A category needs a title.');
                }
                if ($weight < 0) {
                    throw new RuntimeException('Category weight cannot be negative.');
                }

                if ($categoryId > 0 && admin_one('SELECT id FROM pmas_form_a_categories WHERE id = :id', ['id' => $categoryId])) {
                    db()->prepare(
                        'UPDATE pmas_form_a_categories
                         SET title = :title, description = :description, factor_weight = :factor_weight, sort_order = :sort_order, is_active = 1
                         WHERE id = :id'
                    )->execute([
                        'id' => $categoryId,
                        'title' => $title,
                        'description' => $description,
                        'factor_weight' => $weight,
                        'sort_order' => $index + 1,
                    ]);
                } else {
                    db()->prepare(
                        'INSERT INTO pmas_form_a_categories (title, description, factor_weight, sort_order, is_active)
                         VALUES (:title, :description, :factor_weight, :sort_order, 1)'
                    )->execute([
                        'title' => $title,
                        'description' => $description,
                        'factor_weight' => $weight,
                        'sort_order' => $index + 1,
                    ]);
                    $categoryId = (int) db()->lastInsertId();
                }

                $keptCategoryIds[] = $categoryId;
                $keptQuestionIds = [];
                foreach (array_values($questions) as $questionIndex => $question) {
                    $questionId = isset($question['id']) && is_numeric($question['id']) ? (int) $question['id'] : 0;
                    $text = trim((string) ($question['question_text'] ?? $question['text'] ?? ''));
                    if ($text === '') {
                        continue;
                    }

                    if ($questionId > 0 && admin_one('SELECT id FROM pmas_form_a_questions WHERE id = :id AND category_id = :category_id', ['id' => $questionId, 'category_id' => $categoryId])) {
                        db()->prepare(
                            'UPDATE pmas_form_a_questions
                             SET question_text = :question_text, sort_order = :sort_order, is_active = 1
                             WHERE id = :id'
                        )->execute([
                            'id' => $questionId,
                            'question_text' => $text,
                            'sort_order' => $questionIndex + 1,
                        ]);
                    } else {
                        db()->prepare(
                            'INSERT INTO pmas_form_a_questions (category_id, question_text, sort_order, is_active)
                             VALUES (:category_id, :question_text, :sort_order, 1)'
                        )->execute([
                            'category_id' => $categoryId,
                            'question_text' => $text,
                            'sort_order' => $questionIndex + 1,
                        ]);
                        $questionId = (int) db()->lastInsertId();
                    }
                    $keptQuestionIds[] = $questionId;
                }

                if ($keptQuestionIds !== []) {
                    db()->prepare(
                        'UPDATE pmas_form_a_questions
                         SET is_active = 0
                         WHERE category_id = ? AND id NOT IN (' . implode(',', array_fill(0, count($keptQuestionIds), '?')) . ')'
                    )->execute(array_merge([$categoryId], $keptQuestionIds));
                } else {
                    db()->prepare('UPDATE pmas_form_a_questions SET is_active = 0 WHERE category_id = :category_id')
                        ->execute(['category_id' => $categoryId]);
                }
            }

            if ($keptCategoryIds !== []) {
                $oldCategories = admin_all(
                    'SELECT id FROM pmas_form_a_categories WHERE id NOT IN (' . implode(',', array_fill(0, count($keptCategoryIds), '?')) . ')',
                    $keptCategoryIds
                );
                foreach ($oldCategories as $oldCategory) {
                    $oldId = (int) $oldCategory['id'];
                    db()->prepare('UPDATE pmas_form_a_questions SET is_active = 0 WHERE category_id = :category_id')
                        ->execute(['category_id' => $oldId]);
                    db()->prepare('UPDATE pmas_form_a_categories SET is_active = 0 WHERE id = :id')->execute(['id' => $oldId]);
                }
            }

            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            throw $e;
        }
    };

    // ── GET: Fetch categories with questions ──────────────────────────────
    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        if ($action === 'categories') {
            $periodPayload = dipascaf_period_payload();
            if (($user['role'] ?? '') !== 'admin_hr' && !$periodPayload['is_open']) {
                http_response_code(423);
                echo json_encode(['ok' => false, 'message' => $periodPayload['message'], 'period' => $periodPayload]);
                exit;
            }

            $categories = dipascaf_form_a_categories();

            echo json_encode([
                'ok' => true,
                'categories' => $categories,
                'period' => $periodPayload,
                'data' => [
                    'categories' => $categories,
                ],
            ]);
            exit;
        }

        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Unknown action. Valid GET actions: categories']);
        exit;
    }

    // ── POST: Submit evaluation ──────────────────────────────────────────
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Invalid JSON payload.']);
            exit;
        }

        $action = $input['action'] ?? '';

        if ($action === 'save_categories') {
            if (($user['role'] ?? '') !== 'admin_hr') {
                http_response_code(403);
                echo json_encode(['ok' => false, 'message' => 'Only Admin/HR can save Form A questionnaire setup.']);
                exit;
            }

            $saveCategories(is_array($input['categories'] ?? null) ? $input['categories'] : []);
            $categories = dipascaf_form_a_categories();
            echo json_encode([
                'ok' => true,
                'message' => 'Form A questionnaire saved.',
                'categories' => $categories,
                'data' => ['categories' => $categories],
            ]);
            exit;
        }

        if ($action === 'archive_category') {
            if (($user['role'] ?? '') !== 'admin_hr') {
                http_response_code(403);
                echo json_encode(['ok' => false, 'message' => 'Only Admin/HR can archive categories.']);
                exit;
            }

            $categoryId = (int) ($input['category_id'] ?? 0);
            if ($categoryId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => 'Category ID is required.']);
                exit;
            }

            $category = admin_one('SELECT id, title FROM pmas_form_a_categories WHERE id = :id', ['id' => $categoryId]);
            if (!$category) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'message' => 'Category not found.']);
                exit;
            }

            db()->prepare('UPDATE pmas_form_a_categories SET is_active = 0 WHERE id = :id')
                ->execute(['id' => $categoryId]);
            db()->prepare('UPDATE pmas_form_a_questions SET is_active = 0 WHERE category_id = :category_id')
                ->execute(['category_id' => $categoryId]);

            echo json_encode(['ok' => true, 'message' => 'Category archived: ' . $category['title']]);
            exit;
        }

        if ($action === 'restore_category') {
            if (($user['role'] ?? '') !== 'admin_hr') {
                http_response_code(403);
                echo json_encode(['ok' => false, 'message' => 'Only Admin/HR can restore categories.']);
                exit;
            }

            $categoryId = (int) ($input['category_id'] ?? 0);
            if ($categoryId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => 'Category ID is required.']);
                exit;
            }

            $category = admin_one('SELECT id, title FROM pmas_form_a_categories WHERE id = :id', ['id' => $categoryId]);
            if (!$category) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'message' => 'Category not found.']);
                exit;
            }

            db()->prepare('UPDATE pmas_form_a_categories SET is_active = 1 WHERE id = :id')
                ->execute(['id' => $categoryId]);
            db()->prepare('UPDATE pmas_form_a_questions SET is_active = 1 WHERE category_id = :category_id')
                ->execute(['category_id' => $categoryId]);

            echo json_encode(['ok' => true, 'message' => 'Category restored: ' . $category['title']]);
            exit;
        }

        if ($action === 'list_archived') {
            if (($user['role'] ?? '') !== 'admin_hr') {
                http_response_code(403);
                echo json_encode(['ok' => false, 'message' => 'Only Admin/HR can view archived categories.']);
                exit;
            }

            $categories = admin_all(
                'SELECT * FROM pmas_form_a_categories WHERE is_active = 0 ORDER BY title'
            );
            foreach ($categories as &$cat) {
                $questionCount = admin_count(
                    'SELECT COUNT(*) FROM pmas_form_a_questions WHERE category_id = :category_id',
                    ['category_id' => (int) $cat['id']]
                );
                $cat['question_count'] = $questionCount;
            }
            unset($cat);

            echo json_encode(['ok' => true, 'categories' => $categories]);
            exit;
        }

        if ($action === 'submit') {
            $result = dipascaf_submit_form_a_evaluation($input);

            if (($result['success'] ?? false) === true) {
                echo json_encode([
                    'ok' => true,
                    'success' => true,
                    'assignment_id' => $result['assignment_id'] ?? null,
                    'total_weighted_score' => $result['total_weighted_score'] ?? null,
                    'message' => 'Form A evaluation submitted successfully.',
                ]);
            } else {
                echo json_encode([
                    'ok' => false,
                    'success' => false,
                    'error' => $result['message'] ?? 'Submission failed.',
                    'message' => $result['message'] ?? 'Submission failed.',
                ]);
            }
            exit;
        }

        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Unknown action. Valid POST action: submit']);
        exit;
    }

    // ── Unknown method ────────────────────────────────────────────────────
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed. Use GET or POST.']);

} catch (Throwable $exception) {
    error_log('[form_a_admin] ' . $exception->getMessage());
    http_response_code($exception instanceof RuntimeException ? 422 : 500);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
        'message' => $exception->getMessage(),
    ]);
}
