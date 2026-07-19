<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_data.php';

function teacher_assignments(int $teacherUserId): array
{
    admin_ensure_archive_schema();
    return admin_all(
        'SELECT p.*, f.full_name AS evaluatee_name, f.department, f.position_title, f.email AS evaluatee_email, p.cycle_name
         FROM peer_assignments p
         JOIN faculty f ON f.id = p.evaluatee_faculty_id
         WHERE p.evaluator_user_id = :teacher_user_id AND p.evaluator_role = "teacher"
           AND COALESCE(p.is_archived, 0) = 0
           AND COALESCE(f.is_archived, 0) = 0
         ORDER BY FIELD(p.status, "pending", "submitted"), p.assigned_at DESC',
        ['teacher_user_id' => $teacherUserId]
    );
}

function teacher_pending_assignments(int $teacherUserId): array
{
    admin_ensure_archive_schema();
    return admin_all(
        'SELECT p.id, f.full_name AS evaluatee_name, p.status, p.assignment_type
         FROM peer_assignments p
         JOIN faculty f ON f.id = p.evaluatee_faculty_id
         WHERE p.evaluator_user_id = :teacher_user_id AND p.evaluator_role = "teacher" AND p.status = "pending"
           AND COALESCE(p.is_archived, 0) = 0
           AND COALESCE(f.is_archived, 0) = 0
           AND p.assignment_type != "self"
         ORDER BY p.assigned_at DESC',
        ['teacher_user_id' => $teacherUserId]
    );
}

function teacher_user_faculty(int $teacherUserId): ?array
{
    return admin_one(
        'SELECT f.*
         FROM faculty f
         JOIN users u ON u.email = f.email
         WHERE u.id = :teacher_user_id AND u.role = "teacher"
         LIMIT 1',
        ['teacher_user_id' => $teacherUserId]
    );
}

function teacher_personal_results(int $facultyId): array
{
    $summary = admin_one(
        'SELECT COUNT(*) AS submission_count,
                AVG((communication_score + teaching_score + classroom_management_score + job_knowledge_score) / 4) AS average_score
         FROM evaluation_submissions
         WHERE evaluatee_faculty_id = :faculty_id',
        ['faculty_id' => $facultyId]
    );

    $history = teacher_evaluation_history($facultyId);

    return [
        'submissionCount' => (int) ($summary['submission_count'] ?? 0),
        'averageScore' => $summary['average_score'] !== null ? round((float) $summary['average_score'], 2) : null,
        'history' => $history,
    ];
}

function teacher_self_assignment(int $teacherUserId, int $facultyId): ?array
{
    $cycleName = admin_setting('current_appraisal_cycle', '2026 Midyear Appraisal');
    $deadline = dipascaf_evaluation_deadline();
    db()->prepare(
        'INSERT IGNORE INTO peer_assignments
         (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, status, deadline)
         VALUES (:cycle_name, :evaluator_user_id, :evaluatee_faculty_id, "teacher", "self", "pending", :deadline)'
    )->execute([
        'cycle_name' => $cycleName,
        'evaluator_user_id' => $teacherUserId,
        'evaluatee_faculty_id' => $facultyId,
        'deadline' => $deadline,
    ]);

    return admin_one(
        'SELECT p.*, f.full_name AS evaluatee_name
         FROM peer_assignments p
         JOIN faculty f ON f.id = p.evaluatee_faculty_id
         WHERE p.evaluator_user_id = :teacher_user_id
           AND p.evaluatee_faculty_id = :faculty_id
           AND p.evaluator_role = "teacher"
           AND p.assignment_type = "self"
           AND COALESCE(p.is_archived, 0) = 0
         ORDER BY p.assigned_at DESC
         LIMIT 1',
        ['teacher_user_id' => $teacherUserId, 'faculty_id' => $facultyId]
    );
}

function teacher_evaluation_history(int $facultyId): array
{
    return admin_all(
        'SELECT p.cycle_name, p.assignment_type, p.status,
                AVG((es.communication_score + es.teaching_score + es.classroom_management_score + es.job_knowledge_score) / 4) AS average_score,
                MIN(es.submitted_at) AS submitted_at
         FROM evaluation_submissions es
         JOIN peer_assignments p ON p.id = es.assignment_id
         WHERE es.evaluatee_faculty_id = :faculty_id
           AND COALESCE(p.is_archived, 0) = 0
         GROUP BY p.cycle_name, p.assignment_type, p.status
         ORDER BY submitted_at DESC',
        ['faculty_id' => $facultyId]
    );
}

function teacher_personal_insight(int $facultyId): ?array
{
    return admin_one(
        'SELECT * FROM ai_insights WHERE faculty_id = :faculty_id ORDER BY created_at DESC LIMIT 1',
        ['faculty_id' => $facultyId]
    );
}

function teacher_recommendations(int $facultyId): array
{
    return admin_all(
        'SELECT *
         FROM intervention_plans
         WHERE faculty_id = :faculty_id
         ORDER BY FIELD(status, "assigned", "planned", "completed"), target_date ASC',
        ['faculty_id' => $facultyId]
    );
}

function teacher_factor_scores(int $facultyId, ?string $cycleName = null): array
{
    $sql = 'SELECT AVG(communication_score) AS communication,
                   AVG(teaching_score) AS teaching,
                   AVG(classroom_management_score) AS classroom_management,
                   AVG(job_knowledge_score) AS job_commitment
            FROM evaluation_submissions es';
    $params = ['faculty_id' => $facultyId];

    if ($cycleName !== null) {
        $sql .= ' JOIN peer_assignments p ON p.id = es.assignment_id
                  WHERE es.evaluatee_faculty_id = :faculty_id AND p.cycle_name = :cycle_name';
        $params['cycle_name'] = $cycleName;
    } else {
        $sql .= ' WHERE es.evaluatee_faculty_id = :faculty_id';
    }

    $scores = admin_one($sql, $params);

    if (!$scores || $scores['communication'] === null) {
        return [];
    }

    $factors = admin_factors();
    $weights = [];
    foreach ($factors as $factor) {
        $weights[$factor['factor_name']] = (float) $factor['weight_percent'];
    }

    $rows = [
        ['factor' => 'Communication Skills', 'score' => (float) $scores['communication'], 'suggestion' => 'improve communication skills'],
        ['factor' => 'Teaching Effectiveness', 'score' => (float) $scores['teaching'], 'suggestion' => 'improve lesson planning'],
        ['factor' => 'Classroom Management', 'score' => (float) $scores['classroom_management'], 'suggestion' => 'enhance classroom management'],
        ['factor' => 'Job Commitment', 'score' => (float) $scores['job_commitment'], 'suggestion' => 'participate more in institutional activities'],
    ];

    $weightedTotal = 0.0;
    foreach ($rows as &$row) {
        $row['score'] = round($row['score'], 2);
        $row['weight'] = $weights[$row['factor']] ?? 0.0;
        $row['weighted_score'] = round(($row['score'] / 5) * $row['weight'], 2);
        $weightedTotal += $row['weighted_score'];
    }
    unset($row);

    usort($rows, fn (array $a, array $b): int => $a['score'] <=> $b['score']);
    $rows['_weightedTotal'] = round($weightedTotal, 2);

    return $rows;
}

/**
 * Returns aggregated factor scores across ALL completed evaluations, optionally filtered by period and department scope.
 * Used by Admin/VPAA dashboards for the radar chart.
 */
function teacher_factor_scores_aggregate(?string $cycleName = null, ?array $departments = null): array
{
    $sql = 'SELECT AVG(es.communication_score) AS communication,
                   AVG(es.teaching_score) AS teaching,
                   AVG(es.classroom_management_score) AS classroom_management,
                   AVG(es.job_knowledge_score) AS job_commitment
            FROM evaluation_submissions es
            JOIN peer_assignments p ON p.id = es.assignment_id
            JOIN faculty f ON f.id = es.evaluatee_faculty_id';

    $conditions = ['COALESCE(f.is_archived, 0) = 0', 'COALESCE(p.is_archived, 0) = 0', "p.status = 'submitted'"];
    $params = [];

    if ($cycleName !== null) {
        $conditions[] = 'p.cycle_name = :cycle_name';
        $params['cycle_name'] = $cycleName;
    }

    if ($departments !== null && $departments !== []) {
        $placeholders = [];
        $departmentAliases = [];
        foreach ($departments as $dept) {
            $aliases = admin_matching_department_aliases((string) $dept);
            $departmentAliases = array_merge($departmentAliases, $aliases !== [] ? $aliases : [(string) $dept]);
        }
        $departmentAliases = array_values(array_unique(array_filter($departmentAliases)));
        foreach ($departmentAliases as $i => $alias) {
            $key = 'dept_' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $alias;
        }
        $conditions[] = 'f.department IN (' . implode(',', $placeholders) . ')';
    }

    $sql .= ' WHERE ' . implode(' AND ', $conditions);

    $scores = admin_one($sql, $params);

    if (!$scores || $scores['communication'] === null) {
        return [];
    }

    $rows = [
        ['factor' => 'Communication Skills', 'score' => (float) $scores['communication'], 'suggestion' => 'improve communication skills'],
        ['factor' => 'Teaching Effectiveness', 'score' => (float) $scores['teaching'], 'suggestion' => 'improve lesson planning'],
        ['factor' => 'Classroom Management', 'score' => (float) $scores['classroom_management'], 'suggestion' => 'enhance classroom management'],
        ['factor' => 'Job Commitment', 'score' => (float) $scores['job_commitment'], 'suggestion' => 'participate more in institutional activities'],
    ];

    foreach ($rows as &$row) {
        $row['score'] = round($row['score'], 2);
    }
    unset($row);

    usort($rows, fn (array $a, array $b): int => $a['score'] <=> $b['score']);

    return $rows;
}

function teacher_trend(int $facultyId): array
{
    return admin_all(
        'SELECT p.cycle_name,
                ROUND(AVG((es.communication_score + es.teaching_score + es.classroom_management_score + es.job_knowledge_score) / 4), 2) AS average_score,
                COUNT(*) AS submission_count
         FROM evaluation_submissions es
         JOIN peer_assignments p ON p.id = es.assignment_id
         WHERE es.evaluatee_faculty_id = :faculty_id
         GROUP BY p.cycle_name
         ORDER BY MIN(es.submitted_at) ASC',
        ['faculty_id' => $facultyId]
    );
}

/**
 * Returns per-category scores grouped by evaluation period (cycle_name) for a faculty member.
 * Queries both Form A and Form B category results to provide detailed period-over-period comparison.
 */
function teacher_category_comparison(int $facultyId, ?string $periodA = null, ?string $periodB = null): array
{
    $results = array_merge(
        admin_all(
            "SELECT r.evaluation_period AS period_name, c.title AS category_title, 'Form A' AS form_type,
                    ROUND(AVG(r.average_rating), 2) AS average_score,
                    COUNT(*) AS submission_count
             FROM pmas_form_a_category_results r
             JOIN pmas_form_a_categories c ON c.id = r.category_id
             WHERE r.evaluatee_faculty_id = :fac_a AND r.status = 'completed'
             GROUP BY r.evaluation_period, c.title",
            ['fac_a' => $facultyId]
        ),
        admin_all(
            "SELECT r.evaluation_period AS period_name, c.title AS category_title, 'Form B' AS form_type,
                    ROUND(AVG(r.average_rating), 2) AS average_score,
                    COUNT(*) AS submission_count
             FROM pmas_form_b_category_results r
             JOIN pmas_form_b_categories c ON c.id = r.category_id
             WHERE r.evaluatee_faculty_id = :fac_b AND r.status = 'completed'
             GROUP BY r.evaluation_period, c.title",
            ['fac_b' => $facultyId]
        )
    );

    // Group by period → category
    $grouped = [];
    foreach ($results as $row) {
        $period = (string) ($row['period_name'] ?? 'Unknown Period');
        $category = (string) ($row['category_title'] ?? 'Uncategorized');
        if (!isset($grouped[$period])) {
            $grouped[$period] = ['period_name' => $period, 'categories' => []];
        }
        $grouped[$period]['categories'][$category] = [
            'category' => $category,
            'formType' => (string) ($row['form_type'] ?? ''),
            'averageScore' => (float) ($row['average_score'] ?? 0),
            'submissionCount' => (int) ($row['submission_count'] ?? 0),
        ];
    }

    // Compute deltas between two selected periods
    $periodNames = array_keys($grouped);
    sort($periodNames);

    $comparison = [];
    if (count($periodNames) >= 2) {
        // Use provided periods, or fall back to the last two
        $periodB = $periodB !== null ? $periodB : $periodNames[count($periodNames) - 1];
        $periodA = $periodA !== null ? $periodA : $periodNames[count($periodNames) - 2];

        // Validate that both periods exist in data
        if (!isset($grouped[$periodA]) || !isset($grouped[$periodB])) {
            $periodB = $periodNames[count($periodNames) - 1];
            $periodA = $periodNames[count($periodNames) - 2];
        }

        $allCategories = [];
        foreach ([$periodA, $periodB] as $p) {
            if (!isset($grouped[$p])) continue;
            foreach ($grouped[$p]['categories'] as $cat => $data) {
                if (!isset($allCategories[$cat])) {
                    $allCategories[$cat] = [
                        'category' => $cat,
                        'formType' => $data['formType'],
                        'periodA' => null,
                        'periodAScore' => null,
                        'periodB' => $p === $periodB ? $data['averageScore'] : null,
                        'periodBScore' => $p === $periodB ? $data['averageScore'] : null,
                    ];
                }
                if ($p === $periodA) {
                    $allCategories[$cat]['periodA'] = $data['averageScore'];
                    $allCategories[$cat]['periodAScore'] = $data['averageScore'];
                }
                if ($p === $periodB) {
                    $allCategories[$cat]['periodB'] = $data['averageScore'];
                    $allCategories[$cat]['periodBScore'] = $data['averageScore'];
                }
            }
        }

        foreach ($allCategories as &$cat) {
            $a = $cat['periodA'];
            $b = $cat['periodB'];
            if ($a !== null && $b !== null) {
                $cat['change'] = round($b - $a, 2);
                $cat['direction'] = $cat['change'] > 0 ? 'improved' : ($cat['change'] < 0 ? 'declined' : 'stable');
            } else {
                $cat['change'] = null;
                $cat['direction'] = $a === null ? 'new' : 'missing';
            }
        }
        unset($cat);

        // Sort: improved first (by magnitude), then stable, then declined, then new
        usort($allCategories, static function (array $a, array $b): int {
            $order = ['improved' => 0, 'declined' => 1, 'stable' => 2, 'new' => 3, 'missing' => 4];
            $aOrder = $order[$a['direction']] ?? 5;
            $bOrder = $order[$b['direction']] ?? 5;
            if ($aOrder !== $bOrder) return $aOrder <=> $bOrder;
            $aChange = abs((float) ($a['change'] ?? 0));
            $bChange = abs((float) ($b['change'] ?? 0));
            return $bChange <=> $aChange;
        });

        $comparison = [
            'periodA' => $periodA,
            'periodB' => $periodB,
            'categories' => $allCategories,
            'summary' => [
                'improved' => count(array_filter($allCategories, fn($c) => $c['direction'] === 'improved')),
                'declined' => count(array_filter($allCategories, fn($c) => $c['direction'] === 'declined')),
                'stable' => count(array_filter($allCategories, fn($c) => $c['direction'] === 'stable')),
                'new' => count(array_filter($allCategories, fn($c) => $c['direction'] === 'new')),
                'totalCategories' => count($allCategories),
            ],
        ];
    }

    return [
        'periods' => $periodNames,
        'comparison' => $comparison,
        'grouped' => $grouped,
    ];
}

function teacher_sentiment_summary(int $facultyId): array
{
    $rows = admin_all(
        'SELECT behavioral_evidence, overall_comments
         FROM evaluation_submissions
         WHERE evaluatee_faculty_id = :faculty_id',
        ['faculty_id' => $facultyId]
    );

    $positiveWords = ['strong', 'excellent', 'consistent', 'effective', 'good', 'engagement', 'leadership', 'clear'];
    $negativeWords = ['low', 'late', 'weak', 'needs', 'difficulty', 'poor', 'concern', 'incomplete'];
    $score = 0;

    foreach ($rows as $row) {
        $text = strtolower(secure_decrypt_value($row['behavioral_evidence'] ?? '') . ' ' . secure_decrypt_value($row['overall_comments'] ?? ''));
        foreach ($positiveWords as $word) {
            if (str_contains($text, $word)) {
                $score++;
            }
        }
        foreach ($negativeWords as $word) {
            if (str_contains($text, $word)) {
                $score--;
            }
        }
    }

    $label = $score > 1 ? 'Positive' : ($score < 0 ? 'Needs Attention' : 'Balanced');

    return [
        'label' => $label,
        'score' => $score,
        'summary' => match ($label) {
            'Positive' => 'Evaluator comments are mostly supportive and describe observable strengths.',
            'Needs Attention' => 'Comments include development signals that should be addressed through coaching or training.',
            default => 'Comments show both strengths and improvement opportunities.',
        },
    ];
}

function teacher_generated_feedback(array $factorScores): array
{
    if ($factorScores === []) {
        return [
            'summary' => 'AI feedback will appear once released evaluation scores are available.',
            'strength' => 'Not available',
            'weakness' => 'Not available',
            'suggestions' => [],
        ];
    }

    $rows = array_filter($factorScores, 'is_array');
    $weakest = reset($rows);
    $strongest = end($rows);

    return [
        'summary' => 'Your scores suggest strongest performance in ' . $strongest['factor'] . ' and the clearest growth opportunity in ' . $weakest['factor'] . '.',
        'strength' => $strongest['factor'],
        'weakness' => $weakest['factor'],
        'suggestions' => array_values(array_unique(array_map(
            fn (array $row): string => $row['score'] < 4.0 ? $row['suggestion'] : 'strengthen teamwork and collaboration',
            $rows
        ))),
    ];
}

function teacher_summary(int $teacherUserId): array
{
    $assigned = admin_count(
        'SELECT COUNT(*) FROM peer_assignments WHERE evaluator_user_id = :teacher_user_id AND evaluator_role = "teacher" AND COALESCE(is_archived, 0) = 0',
        ['teacher_user_id' => $teacherUserId]
    );
    $submitted = admin_count(
        'SELECT COUNT(*) FROM peer_assignments WHERE evaluator_user_id = :teacher_user_id AND evaluator_role = "teacher" AND status = "submitted" AND COALESCE(is_archived, 0) = 0',
        ['teacher_user_id' => $teacherUserId]
    );
    $pending = admin_count(
        'SELECT COUNT(*) FROM peer_assignments WHERE evaluator_user_id = :teacher_user_id AND evaluator_role = "teacher" AND status = "pending" AND COALESCE(is_archived, 0) = 0',
        ['teacher_user_id' => $teacherUserId]
    );

    return [
        'assigned' => $assigned,
        'submitted' => $submitted,
        'pending' => $pending,
    ];
}
