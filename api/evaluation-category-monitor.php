<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';
require_once __DIR__ . '/../includes/evaluation_period.php';
require_once __DIR__ . '/../includes/evaluation_participation.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = current_user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Unauthenticated.']);
        exit;
    }

    if (($user['role'] ?? '') !== 'admin_hr') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Only Admin/HR can monitor category explanations.']);
        exit;
    }

    dipascaf_ensure_form_a_schema();
    dipascaf_ensure_form_b_schema();
    admin_ensure_archive_schema();
    dipascaf_ensure_period_participation_schema();

    $limit = max(20, min(300, (int) ($_GET['limit'] ?? 150)));
    $selectedPeriod = dipascaf_selected_period_from_request($_GET, true);
    $selectedPeriodName = trim((string) ($selectedPeriod['period_name'] ?? ''));
    $selectedYear = trim((string) ($_GET['year'] ?? ''));
    if ($selectedYear === '' && $selectedPeriod !== null) {
        $selectedYear = dipascaf_period_year($selectedPeriod);
    }
    $periodWhere = '';
    $periodParams = [];
    if ($selectedPeriodName !== '') {
        $periodWhere = ' AND r.evaluation_period = ?';
        $periodParams[] = $selectedPeriodName;
    } elseif ($selectedYear !== '') {
        $periodWhere = ' AND (r.evaluation_period LIKE ? OR YEAR(r.submitted_at) = ?)';
        $periodParams[] = '%' . $selectedYear . '%';
        $periodParams[] = $selectedYear;
    }

    $formA = admin_all(
        "SELECT 'Form A' AS form_label, r.assignment_id, r.evaluator_user_id, r.evaluatee_faculty_id,
                f.full_name AS evaluatee_name, f.department, f.program_code, f.position_title,
                eu.role AS evaluatee_role,
                u.full_name AS evaluator_name, c.title AS category_title,
                r.total_rate, r.question_count, r.average_rating, r.factor_weight, r.weighted_score,
                r.behavioral_evidence, r.reason_for_rating, r.recommendation,
                r.required_explanation, r.explanation_complete, r.ai_decision, r.status,
                r.evaluation_period, r.submitted_at
         FROM pmas_form_a_category_results r
         JOIN pmas_form_a_categories c ON c.id = r.category_id
         JOIN peer_assignments pa ON pa.id = r.assignment_id
         JOIN faculty f ON f.id = r.evaluatee_faculty_id
         LEFT JOIN users eu ON eu.id = f.user_id OR (f.user_id IS NULL AND eu.email = f.email)
         LEFT JOIN users u ON u.id = r.evaluator_user_id
         WHERE COALESCE(r.is_archived, 0) = 0
           AND COALESCE(pa.is_archived, 0) = 0
           AND COALESCE(f.is_archived, 0) = 0
           AND NOT EXISTS (SELECT 1 FROM evaluation_period_participation epp JOIN appraisal_periods apx ON apx.id=epp.evaluation_period_id WHERE apx.period_name=r.evaluation_period AND epp.participation_status='excluded' AND (epp.user_id=pa.evaluator_user_id OR epp.user_id=eu.id))
         {$periodWhere}
         ORDER BY r.submitted_at DESC
         LIMIT {$limit}",
        $periodParams
    );

    $formB = admin_all(
        "SELECT 'Form B' AS form_label, r.assignment_id, r.evaluator_user_id, r.evaluatee_faculty_id,
                f.full_name AS evaluatee_name, f.department, f.program_code, f.position_title,
                eu.role AS evaluatee_role,
                u.full_name AS evaluator_name, c.title AS category_title,
                r.total_rate, r.question_count, r.average_rating, r.factor_weight, r.weighted_score,
                r.behavioral_evidence, r.reason_for_rating, r.recommendation,
                r.required_explanation, r.explanation_complete, r.ai_decision, r.status,
                r.evaluation_period, r.submitted_at
         FROM pmas_form_b_category_results r
         JOIN pmas_form_b_categories c ON c.id = r.category_id
         JOIN peer_assignments pa ON pa.id = r.assignment_id
         JOIN faculty f ON f.id = r.evaluatee_faculty_id
         LEFT JOIN users eu ON eu.id = f.user_id OR (f.user_id IS NULL AND eu.email = f.email)
         LEFT JOIN users u ON u.id = r.evaluator_user_id
         WHERE COALESCE(r.is_archived, 0) = 0
           AND COALESCE(pa.is_archived, 0) = 0
           AND COALESCE(f.is_archived, 0) = 0
           AND NOT EXISTS (SELECT 1 FROM evaluation_period_participation epp JOIN appraisal_periods apx ON apx.id=epp.evaluation_period_id WHERE apx.period_name=r.evaluation_period AND epp.participation_status='excluded' AND (epp.user_id=pa.evaluator_user_id OR epp.user_id=eu.id))
         {$periodWhere}
         ORDER BY r.submitted_at DESC
         LIMIT {$limit}",
        $periodParams
    );

    $rows = array_merge($formA, $formB);
    usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['submitted_at'] ?? ''), (string) ($a['submitted_at'] ?? '')));
    $rows = array_slice($rows, 0, $limit);

    $data = array_map(static function (array $row): array {
        return [
            'form' => (string) $row['form_label'],
            'assignmentId' => (int) $row['assignment_id'],
            'evaluateeName' => (string) $row['evaluatee_name'],
            'evaluatorName' => (string) ($row['evaluator_name'] ?? ''),
            'department' => (string) ($row['department'] ?? ''),
            'program' => (string) ($row['program_code'] ?? ''),
            'evaluateeRole' => (string) ($row['evaluatee_role'] ?? ''),
            'positionTitle' => (string) ($row['position_title'] ?? ''),
            'categoryTitle' => (string) $row['category_title'],
            'totalRating' => (float) $row['total_rate'],
            'questionCount' => (int) $row['question_count'],
            'averageRating' => (float) $row['average_rating'],
            'factorWeight' => (float) $row['factor_weight'],
            'weightedScore' => (float) $row['weighted_score'],
            'behavioralEvidence' => secure_decrypt_value($row['behavioral_evidence'] ?? ''),
            'reasonForRating' => secure_decrypt_value($row['reason_for_rating'] ?? ''),
            'recommendation' => secure_decrypt_value($row['recommendation'] ?? ''),
            'requiredExplanation' => (string) ($row['required_explanation'] ?? ''),
            'explanationComplete' => (int) ($row['explanation_complete'] ?? 0) === 1,
            'aiDecision' => (string) ($row['ai_decision'] ?? 'none'),
            'status' => (string) ($row['status'] ?? ''),
            'period' => (string) ($row['evaluation_period'] ?? ''),
            'submittedAt' => (string) ($row['submitted_at'] ?? ''),
        ];
    }, $rows);

    echo json_encode([
        'ok' => true,
        'data' => $data,
        'summary' => [
            'total' => count($data),
            'complete' => count(array_filter($data, static fn (array $row): bool => $row['explanationComplete'] === true)),
            'pendingReview' => count(array_filter($data, static fn (array $row): bool => $row['aiDecision'] === 'pending_review')),
            'period' => $selectedPeriod !== null ? dipascaf_period_payload($selectedPeriod) + ['year' => dipascaf_period_year($selectedPeriod)] : null,
            'year' => $selectedYear,
        ],
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
