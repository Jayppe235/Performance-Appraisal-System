<?php
declare(strict_types=1);

/**
 * Form B Admin API
 * GET  -> returns PMAS Form B categories/questions from the real database tables
 * POST action=save_categories -> saves Form B questionnaire setup
 * POST action=submit -> submits a Form B evaluation
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';
require_once __DIR__ . '/../includes/evaluation_period.php';

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

function form_b_api_payload(): array
{
    $categories = dipascaf_form_b_categories();
    $questions = [];

    foreach ($categories as $category) {
        foreach ($category['questions'] as $question) {
            $questions[] = [
                'id' => (int) $question['id'],
                'category_id' => (int) $category['id'],
                'question_text' => (string) ($question['text'] ?? $question['question_text'] ?? ''),
                'text' => (string) ($question['text'] ?? $question['question_text'] ?? ''),
            ];
        }
    }

    return [
        'categories' => $categories,
        'questions' => $questions,
    ];
}

function form_b_save_categories(array $categories): void
{
    if ($categories === []) {
        throw new RuntimeException('At least one Form B category is required.');
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
                throw new RuntimeException('Each Form B category needs a title.');
            }
            if ($weight < 0) {
                throw new RuntimeException('Category weight cannot be negative.');
            }

            if ($categoryId > 0 && admin_one('SELECT id FROM pmas_form_b_categories WHERE id = :id', ['id' => $categoryId])) {
                db()->prepare(
                    'UPDATE pmas_form_b_categories
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
                    'INSERT INTO pmas_form_b_categories (title, description, factor_weight, sort_order, is_active)
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

                if ($questionId > 0 && admin_one('SELECT id FROM pmas_form_b_questions WHERE id = :id AND category_id = :category_id', ['id' => $questionId, 'category_id' => $categoryId])) {
                    db()->prepare(
                        'UPDATE pmas_form_b_questions
                         SET question_text = :question_text, sort_order = :sort_order, is_active = 1
                         WHERE id = :id'
                    )->execute([
                        'id' => $questionId,
                        'question_text' => $text,
                        'sort_order' => $questionIndex + 1,
                    ]);
                } else {
                    db()->prepare(
                        'INSERT INTO pmas_form_b_questions (category_id, question_text, sort_order, is_active)
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
                    'UPDATE pmas_form_b_questions
                     SET is_active = 0
                     WHERE category_id = ? AND id NOT IN (' . implode(',', array_fill(0, count($keptQuestionIds), '?')) . ')'
                )->execute(array_merge([$categoryId], $keptQuestionIds));
            } else {
                db()->prepare('UPDATE pmas_form_b_questions SET is_active = 0 WHERE category_id = :category_id')
                    ->execute(['category_id' => $categoryId]);
            }
        }

        if ($keptCategoryIds !== []) {
            $oldCategories = admin_all(
                'SELECT id FROM pmas_form_b_categories WHERE id NOT IN (' . implode(',', array_fill(0, count($keptCategoryIds), '?')) . ')',
                $keptCategoryIds
            );
            foreach ($oldCategories as $oldCategory) {
                $oldId = (int) $oldCategory['id'];
                db()->prepare('UPDATE pmas_form_b_categories SET is_active = 0 WHERE id = :id')->execute(['id' => $oldId]);
                db()->prepare('UPDATE pmas_form_b_questions SET is_active = 0 WHERE category_id = :category_id')->execute(['category_id' => $oldId]);
            }
        }

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

try {
    $user = current_user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Unauthenticated.']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $periodPayload = dipascaf_period_payload();
        if (($user['role'] ?? '') !== 'admin_hr' && !$periodPayload['is_open']) {
            http_response_code(423);
            echo json_encode(['ok' => false, 'message' => $periodPayload['message'], 'period' => $periodPayload]);
            exit;
        }

        $payload = form_b_api_payload();
        echo json_encode([
            'ok' => true,
            'categories' => $payload['categories'],
            'questions' => $payload['questions'],
            'period' => $periodPayload,
            'data' => $payload,
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Invalid JSON payload.']);
            exit;
        }

        $action = (string) ($input['action'] ?? '');

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

            $category = admin_one('SELECT id, title FROM pmas_form_b_categories WHERE id = :id', ['id' => $categoryId]);
            if (!$category) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'message' => 'Category not found.']);
                exit;
            }

            db()->prepare('UPDATE pmas_form_b_categories SET is_active = 0 WHERE id = :id')
                ->execute(['id' => $categoryId]);
            db()->prepare('UPDATE pmas_form_b_questions SET is_active = 0 WHERE category_id = :category_id')
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

            $category = admin_one('SELECT id, title FROM pmas_form_b_categories WHERE id = :id', ['id' => $categoryId]);
            if (!$category) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'message' => 'Category not found.']);
                exit;
            }

            db()->prepare('UPDATE pmas_form_b_categories SET is_active = 1 WHERE id = :id')
                ->execute(['id' => $categoryId]);
            db()->prepare('UPDATE pmas_form_b_questions SET is_active = 1 WHERE category_id = :category_id')
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
                'SELECT * FROM pmas_form_b_categories WHERE is_active = 0 ORDER BY title'
            );
            foreach ($categories as &$cat) {
                $questionCount = admin_count(
                    'SELECT COUNT(*) FROM pmas_form_b_questions WHERE category_id = :category_id',
                    ['category_id' => (int) $cat['id']]
                );
                $cat['question_count'] = $questionCount;
            }
            unset($cat);

            echo json_encode(['ok' => true, 'categories' => $categories]);
            exit;
        }

        if ($action === 'save_categories') {
            if (($user['role'] ?? '') !== 'admin_hr') {
                http_response_code(403);
                echo json_encode(['ok' => false, 'message' => 'Only Admin/HR can save Form B questionnaire setup.']);
                exit;
            }

            form_b_save_categories(is_array($input['categories'] ?? null) ? $input['categories'] : []);
            $payload = form_b_api_payload();
            echo json_encode([
                'ok' => true,
                'message' => 'Form B questionnaire saved.',
                'categories' => $payload['categories'],
                'questions' => $payload['questions'],
                'data' => $payload,
            ]);
            exit;
        }

        if ($action === 'submit') {
            $assignmentId = (int) ($input['assignment_id'] ?? 0);
            $assignment = admin_one(
                'SELECT * FROM peer_assignments WHERE id = :id AND evaluator_user_id = :evaluator_user_id AND COALESCE(is_archived, 0) = 0',
                ['id' => $assignmentId, 'evaluator_user_id' => (int) $user['id']]
            );

            if (!$assignment) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'message' => 'Evaluation assignment was not found.']);
                exit;
            }

            $_POST['form_b_payload'] = json_encode($input['form_b_payload'] ?? [], JSON_THROW_ON_ERROR);
            $result = dipascaf_submit_form_b_evaluation($assignment, (int) $user['id'], 'Submitted PMAS Form B evaluation.');

            echo json_encode(['ok' => true, 'success' => true] + $result);
            exit;
        }

        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
} catch (Throwable $exception) {
    error_log('[form_b_admin] ' . $exception->getMessage());
    http_response_code($exception instanceof RuntimeException ? 422 : 500);
    echo json_encode([
        'ok' => false,
        'message' => $exception->getMessage(),
    ]);
}
