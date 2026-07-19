<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/evaluation_cards.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/evaluation_period.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/program_head_data.php';

notify_ensure_schema();

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (is_string($origin) && preg_match('#^https?://(localhost|127\.0\.0\.1|\[::1\]|192\.168\.\d{1,3}\.\d{1,3})(:\d+)?$#', $origin)) {
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

function self_eval_log(string $message, array $context = []): void
{
    $suffix = $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
    error_log('[self-evaluations] ' . $message . $suffix);
}

function self_eval_role_key(string $role): string
{
    return match ($role) {
        'teacher', 'faculty' => 'faculty',
        'program_head', 'programHead', 'programhead', 'program-head' => 'program_head',
        'dean' => 'dean',
        'vpaa' => 'vpaa',
        'admin_hr', 'admin' => 'admin',
        default => '',
    };
}

function self_eval_form_type(string $role): string
{
    return $role === 'faculty' ? 'form_b_faculty' : match ($role) {
        'dean' => 'form_a_dean',
        'vpaa' => 'form_a_vpaa',
        'program_head' => 'form_a_program_head',
        default => 'form_b_faculty',
    };
}

function self_eval_level(?float $score): string
{
    if ($score === null) return '';
    if ($score >= 4.51) return 'Exceptional';
    if ($score >= 3.76) return 'Exceeds Expectations';
    if ($score >= 3.01) return 'Meets Expectations';
    if ($score >= 2.26) return 'Meets Most Expectations';
    return 'Does Not Meet Expectations';
}

function self_eval_rating_value(string $rating): int
{
    return match ($rating) {
        'E' => 5,
        'EE' => 4,
        'ME' => 3,
        'MM' => 2,
        'DE' => 1,
        default => 0,
    };
}

function self_eval_compute(array $answers, array $categories = []): array
{
    $rows = is_array($answers['performanceOutputs'] ?? null) ? $answers['performanceOutputs'] : [];
    $totalWeight = 0.0;
    $weightedSum = 0.0;
    foreach ($rows as $row) {
        $weight = max(0.0, (float) ($row['weight'] ?? 0));
        $rating = self_eval_rating_value((string) ($row['rating'] ?? ''));
        if ($weight > 0 && $rating > 0) {
            $totalWeight += $weight;
            $weightedSum += ($weight / 100) * $rating;
        }
    }

    $performanceOutputsScore = $weightedSum;
    if ($totalWeight > 0 && abs($totalWeight - 100.0) > 0.001) {
        $performanceOutputsScore = $weightedSum / ($totalWeight / 100);
    }

    $categoryScores = [];
    foreach ($categories as $category) {
        $questions = is_array($category['questions'] ?? null) ? $category['questions'] : [];
        if ($questions === []) {
            continue;
        }
        $ratings = [];
        foreach ($questions as $question) {
            $questionId = (string) ($question['id'] ?? '');
            $rating = (int) ($answers['selfRatings'][$questionId] ?? 0);
            if ($rating < 1 || $rating > 5) {
                $ratings = [];
                break;
            }
            $ratings[] = $rating;
        }
        if ($ratings !== []) {
            $average = array_sum($ratings) / count($ratings);
            $categoryScores[] = [
                'average' => $average,
                'weight' => (float) ($category['factor_weight'] ?? $category['weight'] ?? 0),
            ];
        }
    }

    $categoryFactorScore = null;
    if ($categories !== [] && count($categoryScores) === count($categories)) {
        $weightTotal = array_reduce($categoryScores, static fn (float $sum, array $item): float => $sum + (float) $item['weight'], 0.0);
        $weighted = array_reduce($categoryScores, static fn (float $sum, array $item): float => $sum + ((float) $item['average'] * ((float) $item['weight'] / 100)), 0.0);
        $categoryFactorScore = $weightTotal > 0
            ? $weighted / ($weightTotal / 100)
            : array_reduce($categoryScores, static fn (float $sum, array $item): float => $sum + (float) $item['average'], 0.0) / count($categoryScores);
    }

    $performanceFactorsScore = $categoryFactorScore !== null
        ? round($categoryFactorScore, 4)
        : (isset($answers['performanceFactorsScore']) && $answers['performanceFactorsScore'] !== ''
        ? (float) $answers['performanceFactorsScore']
        : null);

    $overall = $performanceFactorsScore === null
        ? null
        : round(($performanceOutputsScore * 0.70) + ($performanceFactorsScore * 0.30), 4);

    return [
        'performance_outputs_score' => round($performanceOutputsScore, 4),
        'performance_factors_score' => $performanceFactorsScore,
        'overall_rating' => $overall,
        'performance_level' => self_eval_level($overall),
    ];
}

function self_eval_default_title(string $role): string
{
    if ($role === 'faculty') {
        return 'Faculty Self Evaluation Questionnaire';
    }

    $audience = $role === 'dean'
        ? 'ADMINISTRATIVE'
        : strtoupper(str_replace('_', ' ', $role));

    return 'Leadership Self Evaluation Questionnaire for ' . $audience;
}

function self_eval_uid(string $prefix, string $seed): string
{
    return $prefix . substr(hash('sha256', $seed), 0, 14);
}

function self_eval_default_definition(string $role, array $legacy = []): array
{
    $scaleId = 'scale_standard_5';
    $questions = [
        ['question1', 'long_text', true], ['question2', 'long_text', false],
        ['question3', 'long_text', true], ['question4', 'rating', true],
        ['question5', 'long_text', false], ['strengthsQuestion', 'long_text', false],
        ['improvementInstruction', 'long_text', false],
    ];
    $fallback = [
        'question1' => 'List down goals you have achieved and other significant accomplishments you have met during the appraisal period.',
        'question2' => 'List also goals that did not meet mutually agreed standards of performance and specify reasons why they were not met.',
        'question3' => 'What personal strengths do you have that contributed to your performance level during the appraisal period under review? How did they contribute to your performance level?',
        'question4' => 'How would you evaluate your overall performance considering performance outputs and work behaviors during this period in review?',
        'question5' => 'How can you further contribute your talents, knowledge, and skills to the organization to help improve its overall performance?',
        'strengthsQuestion' => "What favorable qualities or attitudes other than those covered by the performance factors does the appraisee have which can help him/her excel in the performance of his/her job?",
        'improvementInstruction' => "List areas in which the appraisee's qualities, attitudes, skills, and performance can be improved in relation to the present position.",
    ];
    $items = [];
    foreach ($questions as [$key, $type, $required]) {
        $items[] = [
            'id' => self_eval_uid('q_', $role . ':' . $key), 'legacyKey' => $key,
            'text' => (string) ($legacy[$key] ?? $fallback[$key]), 'type' => $type,
            'category' => 'Self Evaluation', 'instructions' => '', 'required' => $required,
            'ratingScaleId' => $type === 'rating' ? $scaleId : null,
        ];
    }
    return [
        'schemaVersion' => 2,
        'description' => 'Complete the self-evaluation honestly and provide supporting details where requested.',
        'instructions' => 'Required questions are marked with an asterisk.',
        'scales' => [[
            'id' => $scaleId, 'name' => 'Standard Performance Scale',
            'options' => [
                ['value' => 1, 'label' => 'Does Not Meet Expectations'],
                ['value' => 2, 'label' => 'Meets Most Expectations'],
                ['value' => 3, 'label' => 'Meets Expectations'],
                ['value' => 4, 'label' => 'Exceeds Expectations'],
                ['value' => 5, 'label' => 'Exceptional'],
            ],
        ]],
        'sections' => [
            ['id' => self_eval_uid('sec_', $role . ':questions'), 'type' => 'questions', 'title' => 'Self-Evaluation Questions', 'instructions' => '', 'category' => 'Self Evaluation', 'visible' => true, 'required' => true, 'protected' => false, 'weight' => 100, 'questions' => $items],
            ['id' => 'system_performance_outputs', 'type' => 'outputs', 'title' => 'Performance Outputs', 'instructions' => '', 'visible' => true, 'required' => true, 'protected' => true, 'questions' => []],
            ['id' => 'system_summary', 'type' => 'summary', 'title' => 'Summary and Rating', 'instructions' => '', 'visible' => true, 'required' => true, 'protected' => true, 'questions' => []],
            ['id' => 'system_confirmation', 'type' => 'confirmation', 'title' => 'Comments and Confirmation', 'instructions' => '', 'visible' => true, 'required' => true, 'protected' => true, 'questions' => []],
            ['id' => 'system_career', 'type' => 'career', 'title' => 'Career Development', 'instructions' => '', 'visible' => $role !== 'faculty', 'required' => false, 'protected' => true, 'questions' => []],
        ],
    ];
}

function self_eval_normalize_definition(string $role, mixed $value): array
{
    $legacy = is_array($value) ? $value : [];
    if (($legacy['schemaVersion'] ?? 0) !== 2 || !is_array($legacy['sections'] ?? null)) {
        return self_eval_default_definition($role, $legacy);
    }
    return $legacy;
}

function self_eval_validate_definition(array $definition): array
{
    $errors = [];
    $scales = [];
    foreach (($definition['scales'] ?? []) as $scale) {
        $id = trim((string) ($scale['id'] ?? ''));
        $options = is_array($scale['options'] ?? null) ? $scale['options'] : [];
        if ($id === '' || trim((string) ($scale['name'] ?? '')) === '') $errors[] = 'Every rating scale needs a name.';
        $values = array_map(static fn ($option) => (string) ($option['value'] ?? ''), $options);
        if (count($options) < 2 || count($values) !== count(array_unique($values))) $errors[] = 'Rating scales need at least two unique numeric values.';
        foreach ($options as $option) {
            $numericValue = (float) ($option['value'] ?? 0);
            if ($numericValue < 1 || $numericValue > 5) $errors[] = 'Rating scale values must be between 1 and 5 for PMAS score computation.';
            if (trim((string) ($option['label'] ?? '')) === '') $errors[] = 'Every rating option needs a label.';
        }
        $scales[$id] = true;
    }
    $usable = 0;
    $ratingWeights = 0.0;
    foreach (($definition['sections'] ?? []) as $section) {
        if (empty($section['visible'])) continue;
        if (trim((string) ($section['title'] ?? '')) === '') $errors[] = 'Every visible section needs a title.';
        if (($section['type'] ?? '') === 'questions') {
            $hasRating = false;
            foreach (($section['questions'] ?? []) as $question) {
                $usable++;
                if (trim((string) ($question['text'] ?? '')) === '') $errors[] = 'Question text cannot be empty.';
                if (($question['type'] ?? '') === 'rating') {
                    $hasRating = true;
                    if (!isset($scales[(string) ($question['ratingScaleId'] ?? '')])) $errors[] = 'Every rating question must use an existing rating scale.';
                }
            }
            if ($hasRating) $ratingWeights += (float) ($section['weight'] ?? 0);
        }
    }
    if ($usable === 0) $errors[] = 'The questionnaire must contain at least one visible question.';
    if ($ratingWeights > 0 && abs($ratingWeights - 100.0) > 0.001) $errors[] = 'Rating section weights must total 100%.';
    return array_values(array_unique($errors));
}

function self_eval_display_title(string $title, string $role): string
{
    $normalized = strtolower($title);
    if (str_contains($normalized, 'pmas form a self evaluation') || str_contains($normalized, 'pmas form b self evaluation')) {
        return self_eval_default_title($role);
    }
    if ($role === 'dean' && $normalized === 'leadership self evaluation questionnaire for dean') {
        return self_eval_default_title($role);
    }

    return $title !== '' ? $title : self_eval_default_title($role);
}

function self_eval_categories_for_role(string $role): array
{
    $definition = self_eval_template(self_eval_form_type($role), $role)['definition'] ?? [];
    $categories = [];
    foreach (($definition['sections'] ?? []) as $section) {
        if (($section['type'] ?? '') !== 'questions' || empty($section['visible'])) continue;
        $ratingQuestions = array_values(array_filter(($section['questions'] ?? []), static fn ($question) => ($question['type'] ?? '') === 'rating'));
        if ($ratingQuestions === []) continue;
        $categories[] = [
            'id' => (string) ($section['id'] ?? ''), 'title' => (string) ($section['title'] ?? ''),
            'description' => (string) ($section['instructions'] ?? ''),
            'weight' => (float) ($section['weight'] ?? 0), 'factor_weight' => (float) ($section['weight'] ?? 0),
            'questions' => $ratingQuestions,
        ];
    }
    return $categories;
}

function self_eval_payload_json(mixed $payload, string $field): ?string
{
    if ($payload === null || $payload === '') {
        return null;
    }

    if (is_string($payload)) {
        json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException($field . ' must be valid JSON: ' . json_last_error_msg());
        }
        return $payload;
    }

    if (!is_array($payload)) {
        throw new InvalidArgumentException($field . ' must be a JSON object.');
    }

    return json_encode($payload, JSON_THROW_ON_ERROR);
}

function self_eval_form_payload_from_input(array $input): mixed
{
    if (array_key_exists('form_payload', $input)) {
        return $input['form_payload'];
    }
    if (array_key_exists('form_b_payload', $input)) {
        return $input['form_b_payload'];
    }
    return null;
}

function self_eval_audit(int $userId, string $description): void
{
    try {
        db()->prepare('INSERT INTO activity_logs (user_id, description) VALUES (:user_id, :description)')
            ->execute([
                'user_id' => $userId > 0 ? $userId : null,
                'description' => substr($description, 0, 255),
            ]);
    } catch (Throwable) {
        // Audit logging should not prevent the self-evaluation action.
    }
}

function self_eval_review_label(?string $status): string
{
    return match ((string) $status) {
        'approved' => 'Approved by Dean',
        'reopened' => 'Reopened for Revision',
        'submitted_to_admin' => 'Submitted to Admin',
        default => 'Pending Dean Review',
    };
}

function self_eval_reviewer_config(string $role): ?array
{
    return match ($role) {
        'dean' => ['target' => 'faculty', 'prefix' => 'dean', 'label' => 'Dean'],
        'program_head' => ['target' => 'faculty', 'prefix' => 'program_head', 'label' => 'Program Head'],
        'vpaa' => ['target' => 'dean', 'prefix' => 'vpaa', 'label' => 'VPAA'],
        default => null,
    };
}

function self_eval_can_review_role(string $reviewerRole, string $targetRole): bool
{
    return match ($reviewerRole) {
        'dean' => in_array($targetRole, ['faculty', 'program_head'], true),
        'program_head' => $targetRole === 'faculty',
        'vpaa' => $targetRole === 'dean',
        default => false,
    };
}

function self_eval_managed_role_sql(string $reviewerRole): string
{
    return match ($reviewerRole) {
        // Admin has a global review scope. Visibility rules for Admin (for
        // example, submitted records only) are enforced by the endpoint after
        // the record is loaded.
        'admin' => '1 = 1',
        'dean' => "(se.role = 'program_head' OR (se.role = 'faculty' AND se.program_head_review_status = 'approved'))",
        'program_head' => "se.role = 'faculty'",
        'vpaa' => "se.role = 'dean'",
        default => '1 = 0',
    };
}

function self_eval_review_status_label(string $status, string $reviewerLabel): string
{
    return match ($status) {
        'approved' => "Approved by {$reviewerLabel}",
        'reopened' => "Reopened by {$reviewerLabel}",
        default => "Pending {$reviewerLabel} Review",
    };
}

function self_eval_audit_detail(
    int $recordId,
    int $userId,
    string $userRole,
    string $actionType,
    ?string $oldValue = null,
    ?string $newValue = null,
    ?string $remarks = null
): void {
    try {
        db()->prepare(
            'INSERT INTO pmas_self_evaluation_audit_logs
                (self_evaluation_id, user_id, user_role, action_type, old_value, new_value, remarks)
             VALUES
                (:self_evaluation_id, :user_id, :user_role, :action_type, :old_value, :new_value, :remarks)'
        )->execute([
            'self_evaluation_id' => $recordId,
            'user_id' => $userId > 0 ? $userId : null,
            'user_role' => $userRole,
            'action_type' => $actionType,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'remarks' => $remarks,
        ]);
    } catch (Throwable $exception) {
        self_eval_log('Detailed audit logging failed', ['message' => $exception->getMessage()]);
    }
}

function self_eval_logs_for_record(int $recordId, int $limit = 50): array
{
    return admin_all(
        "SELECT l.*, u.full_name AS actor_name
         FROM pmas_self_evaluation_audit_logs l
         LEFT JOIN users u ON u.id = l.user_id
         WHERE l.self_evaluation_id = :record_id
         ORDER BY l.created_at DESC, l.id DESC
         LIMIT " . max(1, min(100, $limit)),
        ['record_id' => $recordId]
    );
}

function self_eval_dean_user_ids_for_department(string $department): array
{
    $aliases = admin_matching_department_aliases($department);
    if ($aliases === []) {
        $aliases = [$department];
    }
    $params = [];
    $placeholders = [];
    foreach (array_values(array_unique(array_filter($aliases))) as $index => $alias) {
        $key = 'department_' . $index;
        $params[$key] = $alias;
        $placeholders[] = ':' . $key;
    }
    if ($placeholders === []) {
        return [];
    }

    $rows = admin_all(
        "SELECT DISTINCT u.id
         FROM users u
         LEFT JOIN departments d ON d.dean_user_id = u.id
         WHERE u.is_active = 1
           AND u.role = 'dean'
           AND (u.department IN (" . implode(',', $placeholders) . ")
                OR d.department_code IN (" . implode(',', $placeholders) . ")
                OR d.department_name IN (" . implode(',', $placeholders) . "))",
        $params
    );
    return array_values(array_unique(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows)));
}

function self_eval_dean_department_aliases(array $user): array
{
    $departments = array_filter(array_map('trim', preg_split('/[,;|]/', (string) ($user['department'] ?? '')) ?: []));
    $aliases = [];
    foreach ($departments as $department) {
        $matches = admin_matching_department_aliases($department);
        $aliases = array_merge($aliases, $matches !== [] ? $matches : [$department]);
    }

    return array_values(array_unique(array_filter($aliases)));
}

function self_eval_department_filter_sql(array $departments, string $alias, string $prefix): array
{
    $placeholders = [];
    $params = [];
    foreach (array_values($departments) as $index => $department) {
        $key = $prefix . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $department;
    }

    if ($placeholders === []) {
        return ['1 = 0', []];
    }

    return [$alias . '.department IN (' . implode(', ', $placeholders) . ')', $params];
}

function self_eval_managed_record(array $manager, int $recordId = 0, int $assignmentId = 0): ?array
{
    $role = self_eval_role_key((string) ($manager['role'] ?? ''));
    $config = self_eval_reviewer_config($role);
    $params = [];
    $scopeSql = '1 = 0';

    if ($role === 'dean') {
        $departments = self_eval_dean_department_aliases($manager);
        [$scopeSql, $params] = self_eval_department_filter_sql($departments, 'f', 'dean_department_');
    } elseif ($role === 'program_head') {
        $programs = program_head_programs((int) ($manager['id'] ?? 0));
        if ($programs === []) return null;
        [$scopeSql, $params] = program_head_program_filter_sql($programs, [], 'f');
    } elseif ($role === 'vpaa') {
        $scopeSql = "se.role = 'dean'";
    } elseif ($role !== 'admin') {
        return null;
    } else {
        $scopeSql = '1 = 1';
    }

    if ($recordId > 0) {
        $params['record_id'] = $recordId;
        $targetSql = 'se.id = :record_id';
    } elseif ($assignmentId > 0) {
        $params['assignment_id'] = $assignmentId;
        $targetSql = 'pa.id = :assignment_id';
    } else {
        return null;
    }

    $prefix = (string) ($config['prefix'] ?? 'dean');
    $managedRoleSql = self_eval_managed_role_sql($role);
    return admin_one(
        "SELECT se.*, se.{$prefix}_review_status AS review_status,
                se.{$prefix}_reviewed_by AS reviewed_by, se.{$prefix}_reviewed_at AS reviewed_at,
                se.{$prefix}_review_notes AS review_notes, reviewer.full_name AS reviewer_name,
                pa.id AS managed_assignment_id, pa.status AS assignment_status, pa.deadline,
                pa.cycle_name, f.full_name, f.department AS faculty_department, f.program_code,
                f.position_title, u.full_name AS user_full_name
         FROM pmas_self_evaluations se
         JOIN peer_assignments pa ON pa.id = se.assignment_id
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         JOIN users u ON u.id = se.user_id
         LEFT JOIN users reviewer ON reviewer.id = se.{$prefix}_reviewed_by
         WHERE {$targetSql}
           AND {$managedRoleSql}
           AND pa.assignment_type = 'self'
           AND COALESCE(pa.is_archived, 0) = 0
           AND COALESCE(f.is_archived, 0) = 0
           AND {$scopeSql}
         LIMIT 1",
        $params
    );
}

function self_eval_managed_records(array $manager, int $periodId = 0): array
{
    $role = self_eval_role_key((string) ($manager['role'] ?? ''));
    $config = self_eval_reviewer_config($role);
    if ($config === null) return [];
    $params = [];
    if ($role === 'dean') {
        [$scopeSql, $scopeParams] = self_eval_department_filter_sql(self_eval_dean_department_aliases($manager), 'f', 'review_department_');
    } elseif ($role === 'program_head') {
        $programs = program_head_programs((int) ($manager['id'] ?? 0));
        if ($programs === []) return [];
        [$scopeSql, $scopeParams] = program_head_program_filter_sql($programs, [], 'f');
    } else {
        $scopeSql = "se.role = 'dean'";
        $scopeParams = [];
    }
    $params = array_merge($params, $scopeParams);
    $periodSql = '';
    if ($periodId > 0) {
        $period = admin_one('SELECT period_name FROM appraisal_periods WHERE id = :period_id LIMIT 1', ['period_id' => $periodId]);
        if ($period === null) {
            return [];
        }
        $params['selected_period_name'] = (string) ($period['period_name'] ?? '');
        $periodSql = ' AND pa.cycle_name = :selected_period_name';
    }
    $prefix = $config['prefix'];
    $managedRoleSql = self_eval_managed_role_sql($role);
    return admin_all(
        "SELECT se.id, se.user_id, u.full_name, se.role, se.department, se.evaluation_period, se.form_type,
                se.performance_outputs_score, se.performance_factors_score, se.overall_rating, se.performance_level,
                se.status, se.submitted_at, se.reopened_at, se.reopened_by, se.updated_at,
                se.{$prefix}_review_status AS review_status, se.{$prefix}_reviewed_by AS reviewed_by,
                se.{$prefix}_reviewed_at AS reviewed_at, se.{$prefix}_review_notes AS review_notes,
                reviewer.full_name AS reviewer_name, se.reopened_reason, se.revision_count,
                pa.id AS assignment_id, f.department AS faculty_department, f.program_code, f.position_title
         FROM pmas_self_evaluations se
         JOIN peer_assignments pa ON pa.id = se.assignment_id
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         JOIN users u ON u.id = se.user_id
         LEFT JOIN users reviewer ON reviewer.id = se.{$prefix}_reviewed_by
         WHERE {$managedRoleSql} AND pa.assignment_type = 'self'
           AND COALESCE(pa.is_archived, 0) = 0 AND COALESCE(f.is_archived, 0) = 0
           AND {$scopeSql}
           {$periodSql}
           AND (se.status = 'submitted' OR se.{$prefix}_review_status IN ('approved', 'reopened'))
         ORDER BY se.updated_at DESC LIMIT 200",
        $params
    );
}

function self_eval_validate_submission(array $answers, string $requestedRole, string $action, array $categories): array
{
    $errors = [];

    if (!is_array($answers['achievedGoals'] ?? null)) {
        $errors[] = 'Achieved goals must be an array.';
    }
    if (!is_array($answers['performanceOutputs'] ?? null)) {
        $errors[] = 'Performance outputs must be an array.';
    }
    if (!is_array($answers['selfRatings'] ?? null)) {
        $errors[] = 'Category self-ratings must be a JSON object.';
    }
    if (!is_array($answers['selfEvidence'] ?? null)) {
        $errors[] = 'Category evidence must be a JSON object.';
    }
    if (!is_array($answers['confirmations'] ?? null)) {
        $errors[] = 'Confirmation data must be a JSON object.';
    }

    if ($action !== 'submit') {
        return $errors;
    }

    $definition = self_eval_template(self_eval_form_type($requestedRole), $requestedRole)['definition'] ?? [];
    $dynamicResponses = is_array($answers['dynamicResponses'] ?? null) ? $answers['dynamicResponses'] : [];
    foreach (($definition['sections'] ?? []) as $section) {
        if (($section['type'] ?? '') !== 'questions' || empty($section['visible'])) continue;
        foreach (($section['questions'] ?? []) as $question) {
            if (empty($question['required'])) continue;
            $questionId = (string) ($question['id'] ?? '');
            $value = $dynamicResponses[$questionId] ?? ($answers['selfRatings'][$questionId] ?? '');
            if (trim((string) $value) === '') $errors[] = 'Complete all required self-evaluation questionnaire items.';
        }
    }

    $hasOutput = false;
    $outputWeightTotal = 0.0;
    foreach (($answers['performanceOutputs'] ?? []) as $row) {
        $weight = (float) ($row['weight'] ?? 0);
        $outputWeightTotal += max(0.0, $weight);
        if (trim((string) ($row['goals'] ?? '')) !== '' && $weight > 0 && trim((string) ($row['rating'] ?? '')) !== '') {
            $hasOutput = true;
        }
    }
    if (!$hasOutput) $errors[] = 'Add at least one performance output with a goal, weight, and rating.';
    if ($hasOutput && abs($outputWeightTotal - 100.0) > 0.001) {
        $errors[] = 'Performance Output weights must total exactly 100% before submitting.';
    }

    $manualFactors = $answers['performanceFactorsScore'] ?? '';
    if ($manualFactors !== '' && ((float) $manualFactors < 1 || (float) $manualFactors > 5)) {
        $errors[] = 'Performance Factors score must be between 1 and 5.';
    }
    if (trim((string) ($answers['confirmations']['appraisee'] ?? '')) === '') {
        $errors[] = 'Typed name confirmation for the appraisee is required.';
    }
    if (trim((string) ($answers['confirmations']['appraiseeSignature'] ?? '')) === '') {
        $errors[] = 'Upload the appraisee virtual signature.';
    }

    return array_values(array_unique($errors));
}

function self_eval_ensure_schema(): void
{
    admin_ensure_archive_schema();
    admin_ensure_faculty_program_schema();

    db()->exec(
        "CREATE TABLE IF NOT EXISTS pmas_self_evaluation_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            form_type VARCHAR(60) NOT NULL UNIQUE,
            title VARCHAR(180) NOT NULL,
            target_role VARCHAR(40) NOT NULL,
            template_json JSON NULL,
            revision INT NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            updated_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    foreach ([
        'revision' => 'ALTER TABLE pmas_self_evaluation_templates ADD COLUMN revision INT NOT NULL DEFAULT 1 AFTER template_json',
    ] as $column => $sql) {
        if (admin_one("SHOW COLUMNS FROM pmas_self_evaluation_templates LIKE '{$column}'") === null) db()->exec($sql);
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS pmas_self_evaluations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assignment_id INT NOT NULL UNIQUE,
            user_id INT NOT NULL,
            role VARCHAR(40) NOT NULL,
            department VARCHAR(120) NULL,
            evaluation_period VARCHAR(120) NOT NULL,
            form_type VARCHAR(60) NOT NULL,
            questionnaire_revision INT NULL,
            employee_info JSON NULL,
            answers_json JSON NULL,
            raw_payload_json JSON NULL,
            form_payload_json JSON NULL,
            questionnaire_snapshot JSON NULL,
            performance_outputs_score DECIMAL(6,4) NULL,
            performance_factors_score DECIMAL(6,4) NULL,
            overall_rating DECIMAL(6,4) NULL,
            performance_level VARCHAR(80) NULL,
            status ENUM('draft','submitted','reopened') NOT NULL DEFAULT 'draft',
            submitted_at DATETIME NULL,
            reopened_at DATETIME NULL,
            reopened_by INT NULL,
            dean_review_status ENUM('pending','approved','reopened','submitted_to_admin') NOT NULL DEFAULT 'pending',
            dean_reviewed_by INT NULL,
            dean_reviewed_at DATETIME NULL,
            dean_review_notes TEXT NULL,
            program_head_review_status ENUM('pending','approved','reopened') NOT NULL DEFAULT 'pending',
            program_head_reviewed_by INT NULL,
            program_head_reviewed_at DATETIME NULL,
            program_head_review_notes TEXT NULL,
            vpaa_review_status ENUM('pending','approved','reopened') NOT NULL DEFAULT 'pending',
            vpaa_reviewed_by INT NULL,
            vpaa_reviewed_at DATETIME NULL,
            vpaa_review_notes TEXT NULL,
            reopened_reason TEXT NULL,
            revision_count INT NOT NULL DEFAULT 0,
            final_admin_submission_status ENUM('not_ready','ready_for_admin','submitted_to_admin') NOT NULL DEFAULT 'not_ready',
            admin_review_status ENUM('none','pending','reviewed','returned_to_dean') NOT NULL DEFAULT 'none',
            admin_reviewed_by INT NULL,
            admin_reviewed_at DATETIME NULL,
            admin_return_reason TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_self_eval_user_period (user_id, evaluation_period),
            KEY idx_self_eval_form_type (form_type),
            CONSTRAINT fk_self_eval_assignment FOREIGN KEY (assignment_id) REFERENCES peer_assignments(id) ON DELETE CASCADE,
            CONSTRAINT fk_self_eval_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    foreach ([
        'raw_payload_json' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN raw_payload_json JSON NULL AFTER answers_json',
        'form_payload_json' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN form_payload_json JSON NULL AFTER raw_payload_json',
        'questionnaire_revision' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN questionnaire_revision INT NULL AFTER form_type',
        'questionnaire_snapshot' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN questionnaire_snapshot JSON NULL AFTER form_payload_json',
        'reopened_at' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN reopened_at DATETIME NULL AFTER submitted_at',
        'reopened_by' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN reopened_by INT NULL AFTER reopened_at',
        'dean_review_status' => "ALTER TABLE pmas_self_evaluations ADD COLUMN dean_review_status ENUM('pending','approved','reopened','submitted_to_admin') NOT NULL DEFAULT 'pending' AFTER reopened_by",
        'dean_reviewed_by' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN dean_reviewed_by INT NULL AFTER dean_review_status',
        'dean_reviewed_at' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN dean_reviewed_at DATETIME NULL AFTER dean_reviewed_by',
        'dean_review_notes' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN dean_review_notes TEXT NULL AFTER dean_reviewed_at',
        'program_head_review_status' => "ALTER TABLE pmas_self_evaluations ADD COLUMN program_head_review_status ENUM('pending','approved','reopened') NOT NULL DEFAULT 'pending' AFTER dean_review_notes",
        'program_head_reviewed_by' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN program_head_reviewed_by INT NULL AFTER program_head_review_status',
        'program_head_reviewed_at' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN program_head_reviewed_at DATETIME NULL AFTER program_head_reviewed_by',
        'program_head_review_notes' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN program_head_review_notes TEXT NULL AFTER program_head_reviewed_at',
        'vpaa_review_status' => "ALTER TABLE pmas_self_evaluations ADD COLUMN vpaa_review_status ENUM('pending','approved','reopened') NOT NULL DEFAULT 'pending' AFTER program_head_review_notes",
        'vpaa_reviewed_by' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN vpaa_reviewed_by INT NULL AFTER vpaa_review_status',
        'vpaa_reviewed_at' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN vpaa_reviewed_at DATETIME NULL AFTER vpaa_reviewed_by',
        'vpaa_review_notes' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN vpaa_review_notes TEXT NULL AFTER vpaa_reviewed_at',
        'reopened_reason' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN reopened_reason TEXT NULL AFTER dean_review_notes',
        'revision_count' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN revision_count INT NOT NULL DEFAULT 0 AFTER reopened_reason',
        'final_admin_submission_status' => "ALTER TABLE pmas_self_evaluations ADD COLUMN final_admin_submission_status ENUM('not_ready','ready_for_admin','submitted_to_admin') NOT NULL DEFAULT 'not_ready' AFTER revision_count",
        'admin_review_status' => "ALTER TABLE pmas_self_evaluations ADD COLUMN admin_review_status ENUM('none','pending','reviewed','returned_to_dean') NOT NULL DEFAULT 'none' AFTER final_admin_submission_status",
        'admin_reviewed_by' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN admin_reviewed_by INT NULL AFTER admin_review_status',
        'admin_reviewed_at' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN admin_reviewed_at DATETIME NULL AFTER admin_reviewed_by',
        'admin_return_reason' => 'ALTER TABLE pmas_self_evaluations ADD COLUMN admin_return_reason TEXT NULL AFTER admin_reviewed_at',
    ] as $column => $sql) {
        if (admin_one("SHOW COLUMNS FROM pmas_self_evaluations LIKE '{$column}'") === null) {
            db()->exec($sql);
        }
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS pmas_self_evaluation_audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            self_evaluation_id INT NOT NULL,
            user_id INT NULL,
            user_role VARCHAR(40) NOT NULL,
            action_type VARCHAR(60) NOT NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            remarks TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_self_eval_audit_record (self_evaluation_id, created_at),
            KEY idx_self_eval_audit_user (user_id),
            CONSTRAINT fk_self_eval_audit_record FOREIGN KEY (self_evaluation_id) REFERENCES pmas_self_evaluations(id) ON DELETE CASCADE,
            CONSTRAINT fk_self_eval_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    try {
        $statusColumn = admin_one("SHOW COLUMNS FROM pmas_self_evaluations LIKE 'status'");
        if ($statusColumn !== null && !str_contains((string) ($statusColumn['Type'] ?? ''), 'reopened')) {
            db()->exec("ALTER TABLE pmas_self_evaluations MODIFY status ENUM('draft','submitted','reopened') NOT NULL DEFAULT 'draft'");
        }
    } catch (Throwable) {
        // Older database engines may not need or allow the enum adjustment here.
    }
}

function self_eval_template(string $formType, string $role): array
{
    $existing = admin_one(
        'SELECT * FROM pmas_self_evaluation_templates WHERE form_type = :form_type AND is_active = 1 LIMIT 1',
        ['form_type' => $formType]
    );
    if ($existing !== null) {
        $json = json_decode((string) ($existing['template_json'] ?? ''), true);
        $legacy = is_array($json) ? $json : [];
        return [
            'formType' => $formType,
            'title' => self_eval_display_title((string) $existing['title'], $role),
            'targetRole' => (string) $existing['target_role'],
            'template' => $legacy,
            'definition' => self_eval_normalize_definition($role, $legacy),
            'revision' => max(1, (int) ($existing['revision'] ?? 1)),
        ];
    }

    $title = self_eval_default_title($role);

    $template = [
        'question1' => 'List down goals you have achieved and other significant accomplishments you have met during the appraisal period.',
        'question2' => 'List also goals that did not meet mutually agreed standards of performance and specify reasons why they were not met.',
        'question3' => 'What personal strengths do you have that contributed to your performance level during the appraisal period under review? How did they contribute to your performance level?',
        'question4' => 'How would you evaluate your overall performance considering performance outputs and work behaviors during this period in review?',
        'question5' => 'How can you further contribute your talents, knowledge, and skills to the organization to help improve its overall performance?',
        'strengthsQuestion' => "What favorable qualities or attitudes other than those covered by the performance factors does the appraisee have which can help him/her excel in the performance of his/her job?",
        'improvementInstruction' => "List areas in which the appraisee's qualities, attitudes, skills, and performance can be improved in relation to the present position. Itemize action plan to be undertaken in this regard.",
    ];

    db()->prepare(
        'INSERT IGNORE INTO pmas_self_evaluation_templates (form_type, title, target_role, template_json)
         VALUES (:form_type, :title, :target_role, :template_json)'
    )->execute([
        'form_type' => $formType,
        'title' => $title,
        'target_role' => $role,
        'template_json' => json_encode($template, JSON_THROW_ON_ERROR),
    ]);

    return ['formType' => $formType, 'title' => $title, 'targetRole' => $role, 'template' => $template, 'definition' => self_eval_default_definition($role, $template), 'revision' => 1];
}

function self_eval_assignment(array $user, string $role, int $assignmentId = 0, ?array $period = null): ?array
{
    if (!in_array($role, ['faculty', 'dean', 'vpaa', 'program_head'], true)) {
        return null;
    }

    $dbRole = $role === 'faculty' ? 'teacher' : $role;
    dipascaf_ensure_self_assignment((int) $user['id'], $dbRole, $period);

    if ($assignmentId > 0) {
        $matchedAssignment = admin_one(
            "SELECT pa.*, f.full_name, f.department, f.program_code, f.position_title
             FROM peer_assignments pa
             JOIN faculty f ON f.id = pa.evaluatee_faculty_id
             WHERE pa.id = :assignment_id
               AND pa.evaluator_user_id = :user_id
               AND pa.evaluator_role = :evaluator_role
               AND pa.assignment_type = 'self'
               AND COALESCE(pa.is_archived, 0) = 0
             LIMIT 1",
            [
                'assignment_id' => $assignmentId,
                'user_id' => (int) $user['id'],
                'evaluator_role' => $dbRole,
            ]
        );
        if ($matchedAssignment !== null) {
            return $matchedAssignment;
        }
    }

    $cycleName = trim((string) ($period['period_name'] ?? '')) ?: dipascaf_current_cycle_name();

    return admin_one(
        "SELECT pa.*, f.full_name, f.department, f.program_code, f.position_title
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         WHERE pa.evaluator_user_id = :user_id
           AND pa.evaluator_role = :evaluator_role
           AND pa.assignment_type = 'self'
           AND pa.cycle_name = :cycle_name
           AND COALESCE(pa.is_archived, 0) = 0
         ORDER BY pa.id DESC
         LIMIT 1",
        ['user_id' => (int) $user['id'], 'evaluator_role' => $dbRole, 'cycle_name' => $cycleName]
    );
}

try {
    $user = current_user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Unauthenticated.']);
        exit;
    }

    self_eval_ensure_schema();
    $role = self_eval_role_key((string) ($user['role'] ?? ''));
    $requestedRole = self_eval_role_key((string) ($_GET['role'] ?? $role));
    $reviewerConfig = self_eval_reviewer_config($role);
    $canManageSelfEvaluations = $reviewerConfig !== null && self_eval_can_review_role($role, $requestedRole);
    if ($role === 'admin') {
        $requestedRole = self_eval_role_key((string) ($_GET['role'] ?? 'faculty')) ?: 'faculty';
    } elseif (!$canManageSelfEvaluations && $requestedRole !== $role) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'You can only access your own self evaluation form.']);
        exit;
    }

    $formType = self_eval_form_type($requestedRole);
    $template = self_eval_template($formType, $requestedRole);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($role === 'admin' || $canManageSelfEvaluations) {
            $recordId = (int) ($_GET['record_id'] ?? 0);
            $assignmentId = (int) ($_GET['assignment_id'] ?? 0);
            $canViewManagedRecord = $canManageSelfEvaluations || $role === 'admin';
            if (($recordId > 0 || $assignmentId > 0) && $canViewManagedRecord && (string) ($_GET['action'] ?? '') !== 'audit_logs') {
                $record = self_eval_managed_record($user, $recordId, $assignmentId);
                if ($record === null) {
                    http_response_code(404);
                    echo json_encode(['ok' => false, 'message' => 'Self evaluation was not found in your assigned review scope.']);
                    exit;
                }
                if ($role === 'admin' && (string) ($record['status'] ?? '') !== 'submitted') {
                    http_response_code(403);
                    echo json_encode(['ok' => false, 'message' => 'Only submitted Self Evaluations can be viewed by Admin.']);
                    exit;
                }
                $record['employee_info'] = json_decode((string) ($record['employee_info'] ?? ''), true) ?: [];
                $record['answers_json'] = json_decode((string) ($record['answers_json'] ?? ''), true) ?: [];
                $record['questionnaire_snapshot'] = json_decode((string) ($record['questionnaire_snapshot'] ?? ''), true) ?: null;
                self_eval_audit_detail(
                    (int) $record['id'],
                    (int) $user['id'],
                    $role,
                    'viewed',
                    null,
                    null,
                    ($reviewerConfig['label'] ?? 'Admin') . ' viewed self evaluation details.'
                );
                echo json_encode([
                    'ok' => true,
                    'mode' => 'managed',
                    'template' => $template,
                    'assignment' => [
                        'id' => (int) $record['managed_assignment_id'],
                        'status' => (string) $record['assignment_status'],
                        'deadline' => (string) ($record['deadline'] ?? ''),
                    ],
                    'employee' => [
                        'name' => (string) ($record['full_name'] ?? $record['user_full_name'] ?? ''),
                        'positionTitle' => (string) ($record['position_title'] ?? ''),
                        'department' => (string) ($record['faculty_department'] ?? $record['department'] ?? ''),
                        'appraisalPeriod' => (string) ($record['cycle_name'] ?? $record['evaluation_period'] ?? ''),
                    ],
                    'record' => $record,
                    'auditLogs' => self_eval_logs_for_record((int) $record['id']),
                    'permissions' => [
                        'canReview' => $role !== 'admin',
                        'canReopen' => $role !== 'admin',
                        'canEditSubmitted' => $role !== 'admin' && (string) ($record['status'] ?? '') === 'submitted'
                            && !in_array((string) ($record['review_status'] ?? 'pending'), ['approved'], true),
                    ],
                ]);
                exit;
            }

            if ($canManageSelfEvaluations && (string) ($_GET['action'] ?? '') === 'audit_logs') {
                $targetRecordId = (int) ($_GET['record_id'] ?? 0);
                $record = self_eval_managed_record($user, $targetRecordId, 0);
                if ($record === null) {
                    http_response_code(404);
                    echo json_encode(['ok' => false, 'message' => 'Faculty self evaluation was not found in your department scope.']);
                    exit;
                }
                echo json_encode(['ok' => true, 'logs' => self_eval_logs_for_record($targetRecordId)]);
                exit;
            }

            if ($canManageSelfEvaluations) {
                $records = self_eval_managed_records($user, (int) ($_GET['period_id'] ?? 0));
            } else {
                $params = [];
                $records = admin_all(
                "SELECT se.id, se.user_id, u.full_name, se.role, se.department, se.evaluation_period, se.form_type,
                        se.performance_outputs_score, se.performance_factors_score, se.overall_rating, se.performance_level,
                        se.status, se.submitted_at, se.reopened_at, se.reopened_by, se.updated_at,
                        se.dean_review_status, se.dean_reviewed_by, se.dean_reviewed_at, se.dean_review_notes,
                        se.reopened_reason, se.revision_count, se.final_admin_submission_status,
                        pa.id AS assignment_id, f.department AS faculty_department, f.program_code, f.position_title
                 FROM pmas_self_evaluations se
                 JOIN peer_assignments pa ON pa.id = se.assignment_id
                 JOIN faculty f ON f.id = pa.evaluatee_faculty_id
                 JOIN users u ON u.id = se.user_id
                 WHERE 1 = 1
                 ORDER BY se.updated_at DESC
                 LIMIT 200",
                $params
            );
            }
            echo json_encode([
                'ok' => true,
                'mode' => $canManageSelfEvaluations ? $role . '_manage' : 'admin',
                'template' => $template,
                'records' => $records,
                'permissions' => ['canReview' => $canManageSelfEvaluations, 'canReopen' => $canManageSelfEvaluations],
            ]);
            exit;
        }

        $assignmentId = (int) ($_GET['assignment_id'] ?? 0);
        $assignment = self_eval_assignment($user, $requestedRole, $assignmentId);
        if ($assignment === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'No self evaluation assignment is available for this account.']);
            exit;
        }

        $record = admin_one('SELECT * FROM pmas_self_evaluations WHERE assignment_id = :assignment_id LIMIT 1', ['assignment_id' => (int) $assignment['id']]);
        if ($record !== null) {
            $record['employee_info'] = json_decode((string) ($record['employee_info'] ?? ''), true) ?: [];
            $record['answers_json'] = json_decode((string) ($record['answers_json'] ?? ''), true) ?: [];
            $record['questionnaire_snapshot'] = json_decode((string) ($record['questionnaire_snapshot'] ?? ''), true) ?: null;
        }

        echo json_encode([
            'ok' => true,
            'mode' => 'self',
            'template' => $template,
            'assignment' => [
                'id' => (int) $assignment['id'],
                'status' => (string) $assignment['status'],
                'deadline' => (string) ($assignment['deadline'] ?? ''),
            ],
            'employee' => [
                'name' => (string) ($assignment['full_name'] ?? $user['full_name'] ?? ''),
                'positionTitle' => (string) ($assignment['position_title'] ?? dipascaf_self_position_title_for_role($user['role'] ?? 'teacher')),
                'department' => (string) ($assignment['department'] ?? $user['department'] ?? ''),
                'appraisalPeriod' => (string) ($assignment['cycle_name'] ?? dipascaf_current_cycle_name()),
            ],
            'record' => $record,
        ]);
        exit;
    }

    $rawInput = file_get_contents('php://input') ?: '';
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        self_eval_log('Invalid JSON request body', [
            'user_id' => (int) ($user['id'] ?? 0),
            'json_error' => json_last_error_msg(),
        ]);
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Invalid JSON payload: ' . json_last_error_msg()]);
        exit;
    }

    $action = (string) ($input['action'] ?? '');
    $bodyRole = self_eval_role_key((string) ($input['role'] ?? ''));
    if ($bodyRole !== '') {
        $requestedRole = $bodyRole;
    }
    if (in_array($action, ['submit_to_admin', 'admin_submit', 'submit_admin'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'This self evaluation must be reviewed and approved by the Department Dean before submission to Admin.']);
        exit;
    }
    if ($action === 'init_assignment') {
        if ($role === 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Admin manages self evaluations but cannot create a personal self-evaluation assignment.']);
            exit;
        }

        $targetPeriod = dipascaf_selected_period_from_request($input, true);
        $assignment = self_eval_assignment($user, $requestedRole, 0, $targetPeriod);
        if ($assignment === null) {
            self_eval_log('Self assignment initialization failed', [
                'user_id' => (int) ($user['id'] ?? 0),
                'role' => $requestedRole,
            ]);
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Unable to create a self-evaluation assignment. Please make sure this account has an active faculty/profile record.']);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'message' => 'Self-evaluation assignment is ready.',
            'assignment' => [
                'id' => (int) $assignment['id'],
                'status' => (string) $assignment['status'],
                'deadline' => (string) ($assignment['deadline'] ?? ''),
            ],
        ]);
        exit;
    }

    if ($action === 'save_template') {
        if ($role !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Only HRDM/Admin can edit self evaluation templates.']);
            exit;
        }
        $targetRole = self_eval_role_key((string) ($input['target_role'] ?? 'faculty')) ?: 'faculty';
        $targetFormType = self_eval_form_type($targetRole);
        $nextTemplate = is_array($input['definition'] ?? null)
            ? $input['definition']
            : (is_array($input['template'] ?? null) ? $input['template'] : []);
        $nextTemplate = self_eval_normalize_definition($targetRole, $nextTemplate);
        $definitionErrors = self_eval_validate_definition($nextTemplate);
        if ($definitionErrors !== []) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => $definitionErrors[0], 'errors' => $definitionErrors]);
            exit;
        }
        $title = trim((string) ($input['title'] ?? '')) ?: self_eval_template($targetFormType, $targetRole)['title'];
        $expectedRevision = max(1, (int) ($input['expected_revision'] ?? 1));
        $current = admin_one('SELECT id, revision FROM pmas_self_evaluation_templates WHERE form_type = :form_type LIMIT 1', ['form_type' => $targetFormType]);
        if ($current !== null && (int) ($current['revision'] ?? 1) !== $expectedRevision) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'message' => 'This questionnaire was updated by another administrator. Reload it before saving.', 'code' => 'REVISION_CONFLICT', 'current_revision' => (int) $current['revision']]);
            exit;
        }
        if ($current === null) {
            db()->prepare('INSERT INTO pmas_self_evaluation_templates (form_type,title,target_role,template_json,revision,updated_by) VALUES (:form_type,:title,:target_role,:template_json,1,:updated_by)')->execute([
                'form_type'=>$targetFormType,'title'=>$title,'target_role'=>$targetRole,'template_json'=>json_encode($nextTemplate, JSON_THROW_ON_ERROR),'updated_by'=>(int)$user['id'],
            ]);
        } else {
            db()->prepare('UPDATE pmas_self_evaluation_templates SET title=:title,target_role=:target_role,template_json=:template_json,revision=revision+1,updated_by=:updated_by,is_active=1 WHERE id=:id AND revision=:expected_revision')->execute([
                'title'=>$title,'target_role'=>$targetRole,'template_json'=>json_encode($nextTemplate, JSON_THROW_ON_ERROR),'updated_by'=>(int)$user['id'],'id'=>(int)$current['id'],'expected_revision'=>$expectedRevision,
            ]);
        }
        self_eval_audit((int) $user['id'], 'Published self-evaluation questionnaire for ' . $targetRole . '.');
        echo json_encode(['ok' => true, 'message' => 'Self evaluation questionnaire template saved.', 'template' => self_eval_template($targetFormType, $targetRole)]);
        exit;
    }

    if (in_array($action, ['update_dean_notes', 'update_review_notes'], true)) {
        $config = self_eval_reviewer_config($role);
        if ($config === null || !self_eval_can_review_role($role, $requestedRole)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'You cannot update notes for this self evaluation.']);
            exit;
        }
        $recordId = (int) ($input['record_id'] ?? 0);
        $managedRecord = self_eval_managed_record($user, $recordId, 0);
        if ($managedRecord === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Faculty self evaluation was not found in your department scope.']);
            exit;
        }
        if ((string) ($managedRecord['review_status'] ?? 'pending') === 'approved') {
            http_response_code(423);
            echo json_encode(['ok' => false, 'message' => 'Approved evaluations are locked from Dean note changes.']);
            exit;
        }

        $notes = trim((string) ($input['review_notes'] ?? $input['dean_review_notes'] ?? ''));
        $oldNotes = (string) ($managedRecord['review_notes'] ?? '');
        $notesColumn = $config['prefix'] . '_review_notes';
        db()->prepare("UPDATE pmas_self_evaluations SET {$notesColumn} = :notes WHERE id = :record_id")
            ->execute(['notes' => $notes, 'record_id' => $recordId]);
        self_eval_audit_detail($recordId, (int) $user['id'], $role, 'notes_updated', $oldNotes, $notes, $config['label'] . ' review notes updated.');
        self_eval_audit((int) $user['id'], $config['label'] . " updated review notes for self evaluation record #{$recordId}.");
        echo json_encode(['ok' => true, 'message' => $config['label'] . ' review notes saved.']);
        exit;
    }

    if ($action === 'update_review_signature') {
        $config = self_eval_reviewer_config($role);
        if ($config === null || !self_eval_can_review_role($role, $requestedRole)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'You cannot update the appraiser signature for this self evaluation.']);
            exit;
        }
        $recordId = (int) ($input['record_id'] ?? 0);
        $managedRecord = self_eval_managed_record($user, $recordId, 0);
        if ($managedRecord === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Faculty self evaluation was not found in your review scope.']);
            exit;
        }
        if ((string) ($managedRecord['review_status'] ?? 'pending') === 'approved') {
            http_response_code(423);
            echo json_encode(['ok' => false, 'message' => 'Approved evaluations are locked from signature changes.']);
            exit;
        }

        $signature = trim((string) ($input['appraiser_signature'] ?? ''));
        if ($signature !== '' && (!str_starts_with($signature, 'data:image/') || strlen($signature) > 800000)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Upload a valid appraiser signature image under 800KB.']);
            exit;
        }

        $answers = json_decode((string) ($managedRecord['answers_json'] ?? ''), true) ?: [];
        if (!is_array($answers['confirmations'] ?? null)) {
            $answers['confirmations'] = [];
        }
        $signatureNameKey = match ($role) {
            'vpaa' => 'vpaaReviewer',
            'dean' => 'deanReviewer',
            default => 'appraiser',
        };
        $signatureImageKey = match ($role) {
            'vpaa' => 'vpaaReviewerSignature',
            'dean' => 'deanReviewerSignature',
            default => 'appraiserSignature',
        };
        $signatureFileNameKey = match ($role) {
            'vpaa' => 'vpaaReviewerSignatureName',
            'dean' => 'deanReviewerSignatureName',
            default => 'appraiserSignatureName',
        };
        $oldSignature = (string) ($answers['confirmations'][$signatureImageKey] ?? '');
        $answers['confirmations'][$signatureNameKey] = trim((string) ($input['appraiser_name'] ?? '')) ?: (string) ($user['full_name'] ?? $config['label']);
        $answers['confirmations'][$signatureImageKey] = $signature;
        $answers['confirmations'][$signatureFileNameKey] = trim((string) ($input['appraiser_signature_name'] ?? ''));

        db()->prepare('UPDATE pmas_self_evaluations SET answers_json = :answers_json WHERE id = :record_id')
            ->execute([
                'answers_json' => json_encode($answers, JSON_THROW_ON_ERROR),
                'record_id' => $recordId,
            ]);
        self_eval_audit_detail($recordId, (int) $user['id'], $role, 'signature_updated', $oldSignature !== '' ? 'Signature uploaded' : 'No signature', $signature !== '' ? 'Signature uploaded' : 'Signature removed', $config['label'] . ' appraiser signature updated.');
        self_eval_audit((int) $user['id'], $config['label'] . " updated appraiser signature for self evaluation record #{$recordId}.");
        echo json_encode(['ok' => true, 'message' => $signature !== '' ? $config['label'] . ' appraiser signature saved.' : $config['label'] . ' appraiser signature removed.']);
        exit;
    }

    if ($action === 'approve') {
        $config = self_eval_reviewer_config($role);
        if ($config === null || !self_eval_can_review_role($role, $requestedRole)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Only the assigned reviewer can approve this self evaluation.']);
            exit;
        }

        $recordId = (int) ($input['record_id'] ?? 0);
        $managedRecord = self_eval_managed_record($user, $recordId, 0);
        if ($managedRecord === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Faculty self evaluation was not found in your department scope.']);
            exit;
        }
        if ((string) ($managedRecord['status'] ?? '') !== 'submitted') {
            http_response_code(409);
            echo json_encode(['ok' => false, 'message' => 'Only completed submitted self evaluations can be approved.']);
            exit;
        }
        if ((string) ($managedRecord['review_status'] ?? 'pending') === 'approved') {
            http_response_code(409);
            echo json_encode(['ok' => false, 'message' => 'This self evaluation has already been approved by the ' . $config['label'] . '.']);
            exit;
        }

        $answers = json_decode((string) ($managedRecord['answers_json'] ?? ''), true) ?: [];
        $managedTargetRole = self_eval_role_key((string) ($managedRecord['role'] ?? $config['target'])) ?: $config['target'];
        $validationErrors = self_eval_validate_submission($answers, $managedTargetRole, 'submit', self_eval_categories_for_role($managedTargetRole));
        if ($validationErrors !== []) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => $validationErrors[0], 'errors' => $validationErrors]);
            exit;
        }
        $requiredReviewerSignatureKey = match ($role) {
            'vpaa' => 'vpaaReviewerSignature',
            'dean' => 'deanReviewerSignature',
            default => 'appraiserSignature',
        };
        if (in_array($role, ['program_head', 'dean', 'vpaa'], true) && trim((string) ($answers['confirmations'][$requiredReviewerSignatureKey] ?? '')) === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Upload the ' . $config['label'] . ' reviewer signature before approving this self evaluation.']);
            exit;
        }

        $notes = trim((string) ($input['review_notes'] ?? $input['dean_review_notes'] ?? $managedRecord['review_notes'] ?? ''));
        $oldStatus = (string) ($managedRecord['review_status'] ?? 'pending');
        $prefix = $config['prefix'];
        $adminQueueUpdate = $role === 'dean'
            ? ", final_admin_submission_status = 'ready_for_admin', admin_review_status = 'pending'"
            : '';
        db()->beginTransaction();
        try {
            db()->prepare(
                "UPDATE pmas_self_evaluations
                 SET {$prefix}_review_status = 'approved',
                     {$prefix}_reviewed_by = :user_id,
                     {$prefix}_reviewed_at = NOW(),
                     {$prefix}_review_notes = :notes
                     {$adminQueueUpdate}
                 WHERE id = :record_id
                   AND status = 'submitted'
                   AND {$prefix}_review_status <> 'approved'"
            )->execute(['record_id' => $recordId, 'user_id' => (int) $user['id'], 'notes' => $notes]);
            $updated = admin_one("SELECT {$prefix}_review_status AS review_status FROM pmas_self_evaluations WHERE id = :record_id", ['record_id' => $recordId]);
            if ((string) ($updated['review_status'] ?? '') !== 'approved') {
                throw new RuntimeException('This self evaluation could not be approved. It may have already been processed.');
            }
            self_eval_audit_detail($recordId, (int) $user['id'], $role, 'approved', self_eval_review_status_label($oldStatus, $config['label']), 'Approved by ' . $config['label'], $notes);
            self_eval_audit((int) $user['id'], $config['label'] . " approved self evaluation record #{$recordId}.");
            notify_create(
                (int) ($managedRecord['user_id'] ?? 0),
                'approval',
                'Self Evaluation Approved by ' . $config['label'],
                'Your self evaluation has been approved by the ' . $config['label'] . '.',
                $managedTargetRole === 'dean' ? '/dean/evaluate' : ($managedTargetRole === 'program_head' ? '/program-head/evaluate' : '/faculty/evaluate'),
                'self_evaluation',
                $recordId
            );
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            throw $e;
        }

        echo json_encode(['ok' => true, 'message' => 'Self evaluation approved by ' . $config['label'] . '.']);
        exit;
    }

    if ($action === 'reopen') {
        $recordId = (int) ($input['record_id'] ?? 0);
        $config = self_eval_reviewer_config($role);
        $managedRecord = $config !== null ? self_eval_managed_record($user, $recordId, 0) : null;
        if ($config === null || !self_eval_can_review_role($role, $requestedRole) || $managedRecord === null) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'This self evaluation is outside your assigned review scope.']);
            exit;
        }
        if (trim((string) ($input['reason'] ?? '')) === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Revision Reason is required before reopening a self evaluation.']);
            exit;
        }
        if ($managedRecord !== null && (string) ($managedRecord['status'] ?? '') !== 'submitted') {
            http_response_code(409);
            echo json_encode(['ok' => false, 'message' => 'Only submitted self evaluations can be reopened for faculty revision.']);
            exit;
        }
        $reason = trim((string) ($input['reason'] ?? ''));
        $oldStatus = (string) ($managedRecord['review_status'] ?? 'pending');
        $prefix = $config['prefix'];
        db()->prepare(
            "UPDATE pmas_self_evaluations se
             JOIN peer_assignments pa ON pa.id = se.assignment_id
             SET se.status = 'reopened',
                 se.reopened_at = NOW(),
                 se.reopened_by = :user_id,
                 se.{$prefix}_review_status = 'reopened',
                 se.reopened_reason = :reason,
                 se.revision_count = se.revision_count + 1,
                 se.final_admin_submission_status = 'not_ready',
                 pa.status = 'pending',
                 pa.submitted_at = NULL
             WHERE se.id = :record_id"
        )->execute(['record_id' => $recordId, 'user_id' => (int) $user['id'], 'reason' => $reason]);
        notify_create(
                (int) ($managedRecord['user_id'] ?? 0),
                'revision',
                'Self Evaluation Reopened',
                'Your self evaluation has been reopened for revision. Please review the ' . $config['label'] . ' remarks.',
                (string) ($managedRecord['role'] ?? '') === 'dean' ? '/dean/evaluate' : ((string) ($managedRecord['role'] ?? '') === 'program_head' ? '/program-head/evaluate' : '/faculty/evaluate'),
                'self_evaluation',
                $recordId
            );
        self_eval_audit_detail($recordId, (int) $user['id'], $role, 'reopened', self_eval_review_status_label($oldStatus, $config['label']), 'Reopened by ' . $config['label'], $reason);
        self_eval_audit((int) $user['id'], $config['label'] . " reopened self evaluation record #{$recordId}.");
        echo json_encode(['ok' => true, 'message' => 'Self evaluation reopened for editing.']);
        exit;
    }

    if (in_array($action, ['reviewer_update', 'dean_update', 'dean_edit', 'update'], true)) {
        $directEditor = self_eval_reviewer_config($role);
        if (!in_array($role, ['dean', 'program_head'], true) || !self_eval_can_review_role($role, $requestedRole) || $directEditor === null) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Only an authorized Dean or Program Head can directly update faculty self evaluations.']);
            exit;
        }
        $directEditorLabel = (string) $directEditor['label'];

        $recordId = (int) ($input['record_id'] ?? 0);
        $managedRecord = self_eval_managed_record($user, $recordId, 0);
        if ($managedRecord === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Faculty self evaluation was not found in your department scope.']);
            exit;
        }
        if ((string) ($managedRecord['status'] ?? '') !== 'submitted') {
            http_response_code(409);
            echo json_encode(['ok' => false, 'message' => "Only submitted faculty self evaluations can be updated directly by the {$directEditorLabel}."]);
            exit;
        }

        $answers = is_array($input['answers'] ?? null) ? $input['answers'] : [];
        $employeeInfo = is_array($input['employee'] ?? null) ? $input['employee'] : [];
        $nextStatus = 'submitted';
        $validationAction = 'submit';
        try {
            $formPayloadJson = self_eval_payload_json(self_eval_form_payload_from_input($input), 'form_payload');
        } catch (InvalidArgumentException $exception) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
            exit;
        }

        $managedTargetRole = self_eval_role_key((string) ($managedRecord['role'] ?? $requestedRole)) ?: $requestedRole;
        $categories = self_eval_categories_for_role($managedTargetRole);
        $validationErrors = self_eval_validate_submission($answers, $managedTargetRole, $validationAction, $categories);
        if ($validationErrors !== []) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => $validationErrors[0], 'errors' => $validationErrors]);
            exit;
        }

        $computed = self_eval_compute($answers, $categories);
        db()->beginTransaction();
        try {
            db()->prepare(
                "UPDATE pmas_self_evaluations
                 SET department = :department,
                     employee_info = :employee_info,
                     answers_json = :answers_json,
                     raw_payload_json = :raw_payload_json,
                     form_payload_json = :form_payload_json,
                     performance_outputs_score = :performance_outputs_score,
                     performance_factors_score = :performance_factors_score,
                     overall_rating = :overall_rating,
                     performance_level = :performance_level,
                     status = :status,
                     submitted_at = IF(:submitted_status = 'submitted', COALESCE(submitted_at, NOW()), submitted_at)
                 WHERE id = :record_id"
            )->execute([
                'record_id' => $recordId,
                'department' => (string) ($employeeInfo['department'] ?? $managedRecord['faculty_department'] ?? $managedRecord['department'] ?? ''),
                'employee_info' => json_encode($employeeInfo, JSON_THROW_ON_ERROR),
                'answers_json' => json_encode($answers, JSON_THROW_ON_ERROR),
                'raw_payload_json' => $rawInput,
                'form_payload_json' => $formPayloadJson,
                'performance_outputs_score' => $computed['performance_outputs_score'],
                'performance_factors_score' => $computed['performance_factors_score'],
                'overall_rating' => $computed['overall_rating'],
                'performance_level' => $computed['performance_level'],
                'status' => $nextStatus,
                'submitted_status' => $nextStatus,
            ]);
            db()->prepare(
                "UPDATE peer_assignments
                 SET status = :assignment_status,
                     submitted_at = IF(:assignment_submitted_status = 'submitted', COALESCE(submitted_at, NOW()), NULL)
                 WHERE id = :assignment_id"
            )->execute([
                'assignment_id' => (int) $managedRecord['managed_assignment_id'],
                'assignment_status' => $nextStatus === 'submitted' ? 'submitted' : 'pending',
                'assignment_submitted_status' => $nextStatus === 'submitted' ? 'submitted' : 'pending',
            ]);
            $facultyName = trim((string) ($managedRecord['full_name'] ?? $managedRecord['user_full_name'] ?? 'Faculty'));
            $facultyDepartment = trim((string) ($managedRecord['faculty_department'] ?? $managedRecord['department'] ?? ''));
            $auditDepartment = $facultyDepartment !== '' ? " in {$facultyDepartment}" : '';
            self_eval_audit((int) $user['id'], "{$directEditorLabel} updated submitted faculty self evaluation for {$facultyName}{$auditDepartment} (record #{$recordId}).");
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            throw $e;
        }

        echo json_encode(['ok' => true, 'message' => "Faculty self evaluation updated by {$directEditorLabel}.", 'computed' => $computed, 'status' => $nextStatus]);
        exit;
    }

    if (!in_array($action, ['save_draft', 'submit'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
        exit;
    }

    if ($role === 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Admin manages self evaluations but cannot submit one from this endpoint.']);
        exit;
    }

    $assignmentId = (int) ($input['assignment_id'] ?? 0);
    $assignment = self_eval_assignment($user, $requestedRole, $assignmentId);
    if ($assignment === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Self evaluation assignment was not found.']);
        exit;
    }

    $openPeriod = dipascaf_open_evaluation_period();
    if ($openPeriod === null || !dipascaf_assignment_matches_open_period($assignment, $openPeriod)) {
        http_response_code(423);
        echo json_encode(['ok' => false, 'message' => 'This self evaluation can only be edited while its evaluation period is open.']);
        exit;
    }

    $existing = admin_one('SELECT status, dean_review_status FROM pmas_self_evaluations WHERE assignment_id = :assignment_id', ['assignment_id' => (int) $assignment['id']]);
    if ($existing !== null && (string) $existing['status'] === 'submitted') {
        http_response_code(423);
        echo json_encode(['ok' => false, 'message' => 'This self evaluation has already been submitted. Ask the Dean or HRDM/Admin to reopen it.']);
        exit;
    }

    $answers = is_array($input['answers'] ?? null) ? $input['answers'] : [];
    $employeeInfo = is_array($input['employee'] ?? null) ? $input['employee'] : [];
    try {
        $formPayloadJson = self_eval_payload_json(self_eval_form_payload_from_input($input), 'form_payload');
    } catch (InvalidArgumentException $exception) {
        self_eval_log('Invalid form payload', [
            'user_id' => (int) ($user['id'] ?? 0),
            'message' => $exception->getMessage(),
        ]);
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
        exit;
    }
    $categories = self_eval_categories_for_role($requestedRole);
    $validationErrors = self_eval_validate_submission($answers, $requestedRole, $action, $categories);
    if ($validationErrors !== []) {
        self_eval_log('Self evaluation validation failed', [
            'user_id' => (int) ($user['id'] ?? 0),
            'role' => $requestedRole,
            'action' => $action,
            'errors' => $validationErrors,
        ]);
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'message' => $validationErrors[0],
            'errors' => $validationErrors,
        ]);
        exit;
    }

    $computed = self_eval_compute($answers, $categories);
    $status = $action === 'submit' ? 'submitted' : 'draft';

    db()->beginTransaction();
    try {
        db()->prepare(
            "INSERT INTO pmas_self_evaluations
                (assignment_id, user_id, role, department, evaluation_period, form_type, questionnaire_revision, employee_info, answers_json, raw_payload_json, form_payload_json, questionnaire_snapshot,
                 performance_outputs_score, performance_factors_score, overall_rating, performance_level, status, submitted_at)
             VALUES
                (:assignment_id, :user_id, :role, :department, :evaluation_period, :form_type, :questionnaire_revision, :employee_info, :answers_json, :raw_payload_json, :form_payload_json, :questionnaire_snapshot,
                 :performance_outputs_score, :performance_factors_score, :overall_rating, :performance_level, :status, :submitted_at)
             ON DUPLICATE KEY UPDATE
                department = VALUES(department),
                employee_info = VALUES(employee_info),
                answers_json = VALUES(answers_json),
                raw_payload_json = VALUES(raw_payload_json),
                form_payload_json = VALUES(form_payload_json),
                questionnaire_revision = VALUES(questionnaire_revision),
                questionnaire_snapshot = VALUES(questionnaire_snapshot),
                performance_outputs_score = VALUES(performance_outputs_score),
                performance_factors_score = VALUES(performance_factors_score),
                overall_rating = VALUES(overall_rating),
                performance_level = VALUES(performance_level),
                status = VALUES(status),
                dean_review_status = IF(VALUES(status) = 'submitted', 'pending', dean_review_status),
                dean_reviewed_by = IF(VALUES(status) = 'submitted', NULL, dean_reviewed_by),
                dean_reviewed_at = IF(VALUES(status) = 'submitted', NULL, dean_reviewed_at),
                program_head_review_status = IF(VALUES(status) = 'submitted', 'pending', program_head_review_status),
                program_head_reviewed_by = IF(VALUES(status) = 'submitted', NULL, program_head_reviewed_by),
                program_head_reviewed_at = IF(VALUES(status) = 'submitted', NULL, program_head_reviewed_at),
                vpaa_review_status = IF(VALUES(status) = 'submitted', 'pending', vpaa_review_status),
                vpaa_reviewed_by = IF(VALUES(status) = 'submitted', NULL, vpaa_reviewed_by),
                vpaa_reviewed_at = IF(VALUES(status) = 'submitted', NULL, vpaa_reviewed_at),
                final_admin_submission_status = IF(VALUES(status) = 'submitted', 'not_ready', final_admin_submission_status),
                submitted_at = IF(VALUES(status) = 'submitted', NOW(), submitted_at)"
        )->execute([
            'assignment_id' => (int) $assignment['id'],
            'user_id' => (int) $user['id'],
            'role' => $requestedRole,
            'department' => (string) ($employeeInfo['department'] ?? $assignment['department'] ?? ''),
            'evaluation_period' => (string) ($employeeInfo['appraisalPeriod'] ?? $assignment['cycle_name'] ?? dipascaf_current_cycle_name()),
            'form_type' => $formType,
            'questionnaire_revision' => max(1, (int) ($input['questionnaire_revision'] ?? 1)),
            'employee_info' => json_encode($employeeInfo, JSON_THROW_ON_ERROR),
            'answers_json' => json_encode($answers, JSON_THROW_ON_ERROR),
            'raw_payload_json' => $rawInput,
            'form_payload_json' => $formPayloadJson,
            'questionnaire_snapshot' => self_eval_payload_json($input['questionnaire_snapshot'] ?? null, 'questionnaire_snapshot'),
            'performance_outputs_score' => $computed['performance_outputs_score'],
            'performance_factors_score' => $computed['performance_factors_score'],
            'overall_rating' => $computed['overall_rating'],
            'performance_level' => $computed['performance_level'],
            'status' => $status,
            'submitted_at' => $status === 'submitted' ? date('Y-m-d H:i:s') : null,
        ]);

        db()->prepare(
            "UPDATE peer_assignments
             SET status = :assignment_status,
                 submitted_at = IF(:submitted_status = 'submitted', COALESCE(submitted_at, NOW()), NULL)
             WHERE id = :id"
        )->execute([
            'id' => (int) $assignment['id'],
            'assignment_status' => $status === 'submitted' ? 'submitted' : 'pending',
            'submitted_status' => $status,
        ]);

        $savedRecord = admin_one(
            'SELECT id FROM pmas_self_evaluations WHERE assignment_id = :assignment_id LIMIT 1',
            ['assignment_id' => (int) $assignment['id']]
        );
        if ($savedRecord !== null) {
            $recordId = (int) $savedRecord['id'];
            self_eval_audit_detail(
                $recordId,
                (int) $user['id'],
                $role,
                $status === 'submitted' ? 'submitted' : 'draft_saved',
                $existing !== null ? (string) ($existing['status'] ?? '') : null,
                $status,
                $status === 'submitted' ? 'Submitted by ' . ucfirst(str_replace('_', ' ', $requestedRole)) . '.' : 'Self evaluation draft saved.'
            );
            if ($status === 'submitted') {
                $nextReviewer = $requestedRole === 'dean' ? 'VPAA' : 'Dean and Program Head';
                notify_create(
                    (int) $user['id'],
                    'evaluation',
                    'Self Evaluation Submitted',
                    'Your self evaluation has been submitted and is now waiting for ' . $nextReviewer . ' review.',
                    $requestedRole === 'dean' ? '/dean/evaluate' : '/faculty/evaluate',
                    'self_evaluation',
                    $recordId
                );
                if ($requestedRole === 'faculty') {
                  foreach (self_eval_dean_user_ids_for_department((string) ($assignment['department'] ?? $employeeInfo['department'] ?? '')) as $deanUserId) {
                    notify_create(
                        $deanUserId,
                        'evaluation',
                        'Self Evaluation Pending Dean Review',
                        'A faculty self evaluation is waiting for your review and approval.',
                        '/dean/self-evaluation-review',
                        'self_evaluation',
                        $recordId
                    );
                  }
                  $programCode = trim((string) ($assignment['program_code'] ?? $employeeInfo['program'] ?? $user['program'] ?? ''));
                  if ($programCode !== '') {
                    foreach (admin_all("SELECT DISTINCT program_head_user_id AS id FROM programs WHERE is_active = 1 AND program_code = :program AND program_head_user_id IS NOT NULL", ['program' => $programCode]) as $head) {
                      notify_create((int) $head['id'], 'evaluation', 'Self Evaluation Pending Program Head Review', 'A faculty self evaluation under your program is waiting for review.', '/program-head/self-evaluation-review', 'self_evaluation', $recordId);
                    }
                  }
                } elseif ($requestedRole === 'dean') {
                  foreach (admin_all("SELECT id FROM users WHERE role = 'vpaa' AND is_active = 1") as $vpaa) {
                    notify_create((int) $vpaa['id'], 'evaluation', 'Dean Self Evaluation Pending VPAA Review', 'A Dean self evaluation is waiting for your review.', '/vpaa/self-evaluation-review', 'self_evaluation', $recordId);
                  }
                }
            }
        }

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        self_eval_log('Self evaluation save transaction failed', [
            'user_id' => (int) ($user['id'] ?? 0),
            'role' => $requestedRole,
            'action' => $action,
            'message' => $e->getMessage(),
        ]);
        throw $e;
    }

    echo json_encode(['ok' => true, 'message' => $status === 'submitted' ? 'Your self evaluation has been submitted and is now waiting for Dean review.' : 'Draft saved.', 'computed' => $computed, 'status' => $status]);
} catch (Throwable $exception) {
    self_eval_log('Unhandled self evaluation API error', [
        'message' => $exception->getMessage(),
    ]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
