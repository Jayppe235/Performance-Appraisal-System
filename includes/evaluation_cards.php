<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_data.php';
require_once __DIR__ . '/evaluation_period.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/evaluation_participation.php';

function dipascaf_notify_admin_if_faculty_evaluations_complete(int $facultyId, string $periodName = ''): void
{
    if ($facultyId <= 0) {
        return;
    }

    try {
        $params = ['faculty_id' => $facultyId];
        $periodSql = '';
        if (trim($periodName) !== '') {
            $periodSql = ' AND cycle_name = :period_name';
            $params['period_name'] = $periodName;
        }

        $total = admin_count(
            "SELECT COUNT(*) FROM peer_assignments
             WHERE evaluatee_faculty_id = :faculty_id
               AND COALESCE(is_archived, 0) = 0{$periodSql}",
            $params
        );
        if ($total <= 0) {
            return;
        }

        $pending = admin_count(
            "SELECT COUNT(*) FROM peer_assignments
             WHERE evaluatee_faculty_id = :faculty_id
               AND COALESCE(is_archived, 0) = 0
               AND status <> 'submitted'{$periodSql}",
            $params
        );
        if ($pending > 0) {
            return;
        }

        $faculty = admin_one('SELECT full_name FROM faculty WHERE id = :id LIMIT 1', ['id' => $facultyId]);
        $name = trim((string) ($faculty['full_name'] ?? 'A faculty member'));
        notify_role(
            'admin_hr',
            'success',
            'All Required Evaluations Completed',
            $name . ' has completed all required evaluation submissions.',
            '/admin/ai-actions',
            'evaluation_summary',
            $facultyId
        );
    } catch (Throwable $exception) {
        error_log('[evaluation-submit] completion notification failed: ' . $exception->getMessage());
    }
}

function dipascaf_evaluation_deadline(): string
{
    $period = dipascaf_open_evaluation_period();
    return (string) ($period['date_end'] ?? date('Y-m-d', strtotime('+14 days')));
}

function dipascaf_current_cycle_name(): string
{
    $period = dipascaf_current_evaluation_period();
    if ($period !== null && trim((string) ($period['period_name'] ?? '')) !== '') {
        return trim((string) $period['period_name']);
    }

    return admin_setting('current_appraisal_cycle', date('Y') . ' Appraisal Cycle');
}

function dipascaf_assignment_rows(int $evaluatorUserId, string $evaluatorRole): array
{
    admin_ensure_archive_schema();
    admin_ensure_profile_image_column();
    admin_ensure_faculty_program_schema();
    dipascaf_ensure_peer_lifecycle_schema();
    dipascaf_ensure_period_participation_schema();

    $sql = <<<'SQL'
        SELECT pa.*,
                f.id AS faculty_id,
                f.full_name,
                f.full_name AS evaluatee_name,
                f.email,
                f.department,
                f.program_code,
                f.position_title,
                f.progress_percent,
                u.profile_image,
                u.id AS evaluatee_user_id,
                pea_scope.id AS official_peer_assignment_id,
                pea_scope.evaluation_period_id AS official_peer_period_id,
                u.role AS evaluatee_user_role,
                CASE
                    WHEN pa.assignment_type = 'self' THEN "self"
                    WHEN pa.assignment_type = 'peer' THEN "peer"
                    WHEN u.role = "vpaa" OR LOWER(f.position_title) LIKE "%vpaa%" THEN "vpaa"
                    WHEN u.role = "dean" OR LOWER(f.position_title) LIKE "%dean%" THEN "dean"
                    WHEN u.role = "program_head" OR LOWER(f.position_title) LIKE "%program head%" THEN "program_head"
                    ELSE "faculty"
                END AS section_key,
                CASE
                    WHEN pa.assignment_type = 'self' THEN "Self Evaluation"
                    WHEN pa.assignment_type = 'peer' THEN "Peer"
                    WHEN u.role = "vpaa" OR LOWER(f.position_title) LIKE "%vpaa%" THEN "VPAA"
                    WHEN u.role = "dean" OR LOWER(f.position_title) LIKE "%dean%" THEN "Dean"
                    WHEN u.role = "program_head" OR LOWER(f.position_title) LIKE "%program head%" THEN "Program Head"
                    ELSE "Faculty"
                END AS role_label,
                CASE
                    WHEN pa.assignment_type = 'peer' THEN "Official peer assignment"
                    WHEN pa.assignment_type = 'self' THEN "Self-Evaluation"
                    WHEN pa.evaluator_role = 'teacher' AND pa.assignment_type = 'dean' THEN CONCAT("Assigned department Dean: ", COALESCE(NULLIF(f.department, ''), "Unassigned"))
                    WHEN pa.evaluator_role = 'teacher' AND pa.assignment_type = 'program_head' THEN CONCAT("Assigned program head: ", COALESCE(NULLIF(f.program_code, ''), "Unassigned"))
                    WHEN pa.evaluator_role = 'program_head' AND pa.assignment_type = 'dean' THEN CONCAT("Assigned department Dean: ", COALESCE(NULLIF(f.department, ''), "Unassigned"))
                    WHEN pa.evaluator_role = 'program_head' THEN CONCAT("Assigned program: ", COALESCE(NULLIF(f.program_code, ''), "Unassigned"))
                    WHEN pa.evaluator_role = 'dean' THEN CONCAT("Department scope: ", COALESCE(NULLIF(f.department, ''), "Unassigned"))
                    WHEN pa.evaluator_role = 'vpaa' THEN CONCAT("VPAA department scope: ", COALESCE(NULLIF(f.department, ''), "Unassigned"))
                    ELSE ""
                END AS relationship_tag
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         LEFT JOIN users u ON u.id = f.user_id OR u.email = f.email
         LEFT JOIN peer_evaluation_assignments pea_scope
            ON pea_scope.peer_assignment_id = pa.id
            AND pea_scope.evaluator_id = pa.evaluator_user_id
            AND pea_scope.evaluatee_faculty_id = pa.evaluatee_faculty_id
            AND COALESCE(pea_scope.is_archived, 0) = 0
         LEFT JOIN peer_evaluation_locks pel
            ON pel.evaluation_period_id = pea_scope.evaluation_period_id
         LEFT JOIN appraisal_periods ap_scope ON ap_scope.period_name = pa.cycle_name
         LEFT JOIN evaluation_period_participation epp_evaluator
            ON epp_evaluator.evaluation_period_id = ap_scope.id
           AND epp_evaluator.user_id = pa.evaluator_user_id
           AND epp_evaluator.participation_status = 'excluded'
         LEFT JOIN evaluation_period_participation epp_evaluatee
            ON epp_evaluatee.evaluation_period_id = ap_scope.id
           AND epp_evaluatee.user_id = u.id
           AND epp_evaluatee.participation_status = 'excluded'
         WHERE pa.evaluator_user_id = :evaluator_user_id
           AND pa.evaluator_role = :evaluator_role
           AND COALESCE(pa.is_archived, 0) = 0
           AND COALESCE(f.is_archived, 0) = 0
           AND pa.status <> 'not_required'
           AND epp_evaluator.id IS NULL
           AND epp_evaluatee.id IS NULL
           AND (u.role IS NULL OR u.role IN ('teacher', 'program_head', 'dean', 'vpaa'))
           AND (
                pa.assignment_type <> 'peer'
                OR (pea_scope.id IS NOT NULL AND COALESCE(pel.status, 'unlocked') = 'locked')
           )
         ORDER BY FIELD(pa.status, 'pending', 'submitted'), pa.deadline ASC, pa.assigned_at DESC
SQL;

    $rows = admin_all(
        $sql,
        [
            'evaluator_user_id' => $evaluatorUserId,
            'evaluator_role' => $evaluatorRole,
        ]
    );

    return array_values(array_filter(
        $rows,
        static fn (array $row): bool => dipascaf_assignment_relationship_allowed($row, $evaluatorUserId, $evaluatorRole)
    ));
}

function dipascaf_ensure_form_a_schema(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS pmas_form_a_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(220) NOT NULL UNIQUE,
            description TEXT NULL,
            factor_weight DECIMAL(5,2) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );

    db()->exec(
        'CREATE TABLE IF NOT EXISTS pmas_form_a_questions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_id INT NOT NULL,
            question_text TEXT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_form_a_question (category_id, question_text(180))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );

    db()->exec(
        'CREATE TABLE IF NOT EXISTS pmas_form_a_category_results (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assignment_id INT NOT NULL,
            evaluator_user_id INT NOT NULL,
            evaluatee_faculty_id INT NOT NULL,
            category_id INT NOT NULL,
            total_rate DECIMAL(6,2) NOT NULL,
            question_count INT NOT NULL,
            average_rating DECIMAL(4,2) NOT NULL,
            factor_weight DECIMAL(5,2) NOT NULL,
            weighted_score DECIMAL(6,4) NOT NULL,
            questionnaire_answers JSON NULL,
            questionnaire_evidence JSON NULL,
            form_b_payload JSON NULL,
            behavioral_evidence TEXT NULL,
            reason_for_rating TEXT NULL,
            recommendation TEXT NULL,
            ai_suggestion TEXT NULL,
            ai_decision ENUM("none","pending_review","accepted","edited","rejected") NOT NULL DEFAULT "none",
            required_explanation ENUM("behavioral_evidence","reason_for_rating","behavioral_evidence_recommendation") NOT NULL DEFAULT "reason_for_rating",
            explanation_complete TINYINT(1) NOT NULL DEFAULT 0,
            evaluation_period VARCHAR(120) NOT NULL,
            status ENUM("draft","completed") NOT NULL DEFAULT "completed",
            submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_form_a_assignment_category (assignment_id, category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );

    foreach ([
        'is_archived' => 'ALTER TABLE pmas_form_a_category_results ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0',
        'archived_at' => 'ALTER TABLE pmas_form_a_category_results ADD COLUMN archived_at DATETIME NULL',
    ] as $column => $sql) {
        if (admin_one("SHOW COLUMNS FROM pmas_form_a_category_results LIKE '{$column}'") === null) {
            db()->exec($sql);
        }
    }
}

function dipascaf_ensure_form_b_schema(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS pmas_form_b_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(220) NOT NULL UNIQUE,
            factor_weight DECIMAL(5,2) NOT NULL DEFAULT 0,
            description TEXT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );

    db()->exec(
        'CREATE TABLE IF NOT EXISTS pmas_form_b_questions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_id INT NOT NULL,
            question_text TEXT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_form_b_question (category_id, question_text(180))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );

    db()->exec(
        'CREATE TABLE IF NOT EXISTS pmas_form_b_category_results (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assignment_id INT NOT NULL,
            evaluator_user_id INT NOT NULL,
            evaluatee_faculty_id INT NOT NULL,
            category_id INT NOT NULL,
            total_rate DECIMAL(6,2) NOT NULL,
            question_count INT NOT NULL,
            average_rating DECIMAL(4,2) NOT NULL,
            factor_weight DECIMAL(5,2) NOT NULL,
            weighted_score DECIMAL(6,4) NOT NULL,
            questionnaire_answers JSON NULL,
            questionnaire_evidence JSON NULL,
            behavioral_evidence TEXT NULL,
            reason_for_rating TEXT NULL,
            recommendation TEXT NULL,
            ai_suggestion TEXT NULL,
            ai_decision ENUM("none","pending_review","accepted","edited","rejected") NOT NULL DEFAULT "none",
            required_explanation ENUM("behavioral_evidence","reason_for_rating","behavioral_evidence_recommendation") NOT NULL DEFAULT "reason_for_rating",
            explanation_complete TINYINT(1) NOT NULL DEFAULT 0,
            evaluation_period VARCHAR(120) NOT NULL,
            status ENUM("draft","completed") NOT NULL DEFAULT "completed",
            submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_form_b_assignment_category (assignment_id, category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );

    foreach ([
        'is_archived' => 'ALTER TABLE pmas_form_b_category_results ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0',
        'archived_at' => 'ALTER TABLE pmas_form_b_category_results ADD COLUMN archived_at DATETIME NULL',
        'form_b_payload' => 'ALTER TABLE pmas_form_b_category_results ADD COLUMN form_b_payload JSON NULL AFTER questionnaire_evidence',
    ] as $column => $sql) {
        if (admin_one("SHOW COLUMNS FROM pmas_form_b_category_results LIKE '{$column}'") === null) {
            db()->exec($sql);
        }
    }
}

function dipascaf_categories(string $form): array
{
    $prefix = $form === 'a' ? 'pmas_form_a' : 'pmas_form_b';
    $form === 'a' ? dipascaf_ensure_form_a_schema() : dipascaf_ensure_form_b_schema();

    $categories = admin_all(
        "SELECT * FROM {$prefix}_categories WHERE is_active = 1 ORDER BY sort_order, id"
    );

    foreach ($categories as &$category) {
        $questions = admin_all(
            "SELECT id, question_text, sort_order
             FROM {$prefix}_questions
             WHERE category_id = :category_id AND is_active = 1
             ORDER BY sort_order, id",
            ['category_id' => (int) $category['id']]
        );
        $category['sourceId'] = (int) $category['id'];
        $category['weight'] = (float) ($category['factor_weight'] ?? 0);
        $category['questions'] = array_map(static fn (array $question): array => [
            'id' => (int) $question['id'],
            'sourceId' => (int) $question['id'],
            'text' => (string) $question['question_text'],
            'question_text' => (string) $question['question_text'],
            'sort_order' => (int) ($question['sort_order'] ?? 0),
        ], $questions);
    }
    unset($category);

    return $categories;
}

function dipascaf_form_a_categories(): array
{
    return dipascaf_categories('a');
}

function dipascaf_form_b_categories(): array
{
    return dipascaf_categories('b');
}

function dipascaf_result_rows(string $form, array $assignments): array
{
    admin_ensure_archive_schema();
    $ids = array_values(array_unique(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $assignments)));
    $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    if ($ids === []) {
        return [];
    }

    $table = $form === 'a' ? 'pmas_form_a_category_results' : 'pmas_form_b_category_results';
    $categoryTable = $form === 'a' ? 'pmas_form_a_categories' : 'pmas_form_b_categories';
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = admin_all(
        "SELECT r.*, c.title AS category_title, c.title
         FROM {$table} r
         JOIN {$categoryTable} c ON c.id = r.category_id
         JOIN peer_assignments pa ON pa.id = r.assignment_id
         WHERE r.assignment_id IN ({$placeholders})
           AND COALESCE(r.is_archived, 0) = 0
           AND COALESCE(pa.is_archived, 0) = 0
         ORDER BY r.assignment_id, c.sort_order, c.id",
        $ids
    );

    $grouped = [];
    foreach ($rows as $row) {
        $assignmentId = (string) ($row['assignment_id'] ?? 0);
        foreach (['questionnaire_answers', 'questionnaire_evidence'] as $jsonField) {
            $decoded = json_decode((string) ($row[$jsonField] ?? ''), true);
            $row[$jsonField] = is_array($decoded) ? $decoded : [];
        }
        $grouped[$assignmentId][] = $row;
    }

    return $grouped;
}

function dipascaf_form_a_records(array $assignments): array
{
    dipascaf_ensure_form_a_schema();
    return dipascaf_result_rows('a', $assignments);
}

function dipascaf_form_b_records(array $assignments): array
{
    dipascaf_ensure_form_b_schema();
    return dipascaf_result_rows('b', $assignments);
}

function dipascaf_submit_category_results(array $assignment, int $evaluatorUserId, string $form, array $payload, string $periodName): array
{
    $expectedForm = (string) ($assignment['questionnaire_type'] ?? '') === 'admin' ? 'a' : 'b';
    if ($form !== $expectedForm) {
        throw new RuntimeException(sprintf(
            'This evaluation requires Form %s. Refresh the assignment and submit the correct questionnaire.',
            strtoupper($expectedForm)
        ));
    }

    $table = $form === 'a' ? 'pmas_form_a_category_results' : 'pmas_form_b_category_results';
    $categories = $form === 'a' ? dipascaf_form_a_categories() : dipascaf_form_b_categories();
    $byId = [];
    foreach ($categories as $category) {
        $byId[(string) $category['id']] = $category;
    }

    $items = $form === 'a'
        ? array_map(static fn ($categoryId, $row): array => ['category_id' => (int) $categoryId] + (is_array($row) ? $row : []), array_keys($payload), $payload)
        : (array) ($payload['categories'] ?? []);

    if ($items === []) {
        throw new RuntimeException('No category ratings were submitted.');
    }

    $payloadJson = null;
    if ($form === 'b') {
        try {
            $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            error_log('[evaluation-submit] Invalid Form B payload JSON: ' . $exception->getMessage());
            throw new RuntimeException('The submitted Form B payload is not valid JSON.');
        }
    }

    $hasFormBPayload = $form === 'b';
    $sql = $hasFormBPayload ? "INSERT INTO {$table}
            (assignment_id, evaluator_user_id, evaluatee_faculty_id, category_id, total_rate, question_count, average_rating, factor_weight, weighted_score, questionnaire_answers, questionnaire_evidence, form_b_payload, behavioral_evidence, reason_for_rating, evaluation_period, status)
            VALUES (:assignment_id, :evaluator_user_id, :evaluatee_faculty_id, :category_id, :total_rate, :question_count, :average_rating, :factor_weight, :weighted_score, :questionnaire_answers, :questionnaire_evidence, :form_b_payload, :behavioral_evidence, :reason_for_rating, :evaluation_period, 'completed')
            ON DUPLICATE KEY UPDATE
                total_rate = VALUES(total_rate),
                question_count = VALUES(question_count),
                average_rating = VALUES(average_rating),
                factor_weight = VALUES(factor_weight),
                weighted_score = VALUES(weighted_score),
                questionnaire_answers = VALUES(questionnaire_answers),
                questionnaire_evidence = VALUES(questionnaire_evidence),
                form_b_payload = VALUES(form_b_payload),
                behavioral_evidence = VALUES(behavioral_evidence),
                reason_for_rating = VALUES(reason_for_rating),
                evaluation_period = VALUES(evaluation_period),
                status = 'completed',
                submitted_at = CURRENT_TIMESTAMP"
        : "INSERT INTO {$table}
            (assignment_id, evaluator_user_id, evaluatee_faculty_id, category_id, total_rate, question_count, average_rating, factor_weight, weighted_score, questionnaire_answers, questionnaire_evidence, behavioral_evidence, reason_for_rating, evaluation_period, status)
            VALUES (:assignment_id, :evaluator_user_id, :evaluatee_faculty_id, :category_id, :total_rate, :question_count, :average_rating, :factor_weight, :weighted_score, :questionnaire_answers, :questionnaire_evidence, :behavioral_evidence, :reason_for_rating, :evaluation_period, 'completed')
            ON DUPLICATE KEY UPDATE
                total_rate = VALUES(total_rate),
                question_count = VALUES(question_count),
                average_rating = VALUES(average_rating),
                factor_weight = VALUES(factor_weight),
                weighted_score = VALUES(weighted_score),
                questionnaire_answers = VALUES(questionnaire_answers),
                questionnaire_evidence = VALUES(questionnaire_evidence),
                behavioral_evidence = VALUES(behavioral_evidence),
                reason_for_rating = VALUES(reason_for_rating),
                evaluation_period = VALUES(evaluation_period),
                status = 'completed',
                submitted_at = CURRENT_TIMESTAMP";
    $stmt = db()->prepare($sql);
    $totalWeighted = 0.0;
    $savedRows = 0;

    db()->beginTransaction();
    try {
        foreach ($items as $item) {
            $categoryId = (int) ($item['category_id'] ?? $item['id'] ?? 0);
            $category = $byId[(string) $categoryId] ?? null;
            if ($category === null) {
                throw new RuntimeException('Submitted category does not match the active questionnaire.');
            }

            $answers = is_array($item['answers'] ?? null) ? $item['answers'] : [];
            $questionIds = array_map(static fn (array $question): string => (string) ($question['id'] ?? ''), is_array($category['questions'] ?? null) ? $category['questions'] : []);
            $ratings = [];
            foreach ($questionIds as $questionId) {
                $rating = (int) ($answers[$questionId] ?? 0);
                if ($rating < 1 || $rating > 5) {
                    throw new RuntimeException('All category questions must have ratings from 1 to 5.');
                }
                $ratings[] = $rating;
            }
            $questionCount = count($ratings);
            if ($questionCount === 0) {
                throw new RuntimeException('Submitted category has no active questions.');
            }

            $totalRate = array_sum($ratings);
            $average = $totalRate / $questionCount;
            $factorWeight = (float) ($category['factor_weight'] ?? 0);
            $weightedScore = $average * ($factorWeight / 100);
            $totalWeighted += $weightedScore;

            $params = [
                'assignment_id' => (int) $assignment['id'],
                'evaluator_user_id' => $evaluatorUserId,
                'evaluatee_faculty_id' => (int) $assignment['evaluatee_faculty_id'],
                'category_id' => $categoryId,
                'total_rate' => $totalRate,
                'question_count' => $questionCount,
                'average_rating' => $average,
                'factor_weight' => $factorWeight,
                'weighted_score' => $weightedScore,
                'questionnaire_answers' => json_encode($answers, JSON_THROW_ON_ERROR),
                'questionnaire_evidence' => json_encode(is_array($item['evidence'] ?? null) ? $item['evidence'] : [], JSON_THROW_ON_ERROR),
                'behavioral_evidence' => trim((string) ($item['behavioral_evidence'] ?? '')),
                'reason_for_rating' => trim((string) ($item['reason_for_rating'] ?? '')),
                'evaluation_period' => $periodName,
            ];
            if ($hasFormBPayload) {
                $params['form_b_payload'] = $payloadJson;
            }
            $stmt->execute($params);
            $savedRows++;
        }

        if ($savedRows !== count($items)) {
            throw new RuntimeException('Some category ratings could not be saved.');
        }

        db()->prepare(
            'UPDATE peer_assignments pa
             JOIN users u ON u.id = pa.evaluator_user_id
             SET pa.status = "submitted", pa.submitted_at = NOW(),
                 pa.evaluator_name_snapshot = COALESCE(pa.evaluator_name_snapshot, u.full_name),
                 pa.evaluator_role_snapshot = COALESCE(pa.evaluator_role_snapshot, pa.evaluator_role),
                 pa.effective_from = COALESCE(pa.effective_from, pa.assigned_at)
             WHERE pa.id = :id'
        )->execute(['id' => (int) $assignment['id']]);
        try {
            db()->prepare(
                'UPDATE peer_evaluation_assignments
                 SET status = "completed"
                 WHERE peer_assignment_id = :id
                   AND COALESCE(is_archived, 0) = 0'
            )->execute(['id' => (int) $assignment['id']]);
        } catch (Throwable) {
            // Keep legacy installs working when the official peer mapping table is absent.
        }
        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        error_log('[evaluation-submit] Category submission failed: ' . $exception->getMessage() . ' assignment=' . (int) ($assignment['id'] ?? 0));
        throw $exception;
    }

    // Recalculate progress for the evaluated faculty member in real time
    if (function_exists('admin_recalculate_faculty_progress')) {
        admin_recalculate_faculty_progress((int) $assignment['evaluatee_faculty_id']);
    }

    return [
        'assignment_id' => (int) $assignment['id'],
        'total_weighted_score' => round($totalWeighted, 4),
    ];
}

function dipascaf_submit_form_a_evaluation(array $input): array
{
    dipascaf_ensure_form_a_schema();
    $user = current_user();
    $assignmentId = (int) ($input['assignment_id'] ?? 0);
    $assignment = admin_one('SELECT * FROM peer_assignments WHERE id = :id AND COALESCE(is_archived, 0) = 0', ['id' => $assignmentId]);
    if ($user === null || $assignment === null) {
        return ['success' => false, 'message' => 'Evaluation assignment was not found.'];
    }

    $period = dipascaf_assert_assignment_allowed($assignment, (int) $user['id'], 'form_a');
    $result = dipascaf_submit_category_results($assignment, (int) $user['id'], 'a', (array) ($input['form_a_payload'] ?? []), (string) $period['period_name']);
    admin_activity('Submitted PMAS Form A evaluation.');
    $evaluateeFaculty = admin_one('SELECT full_name, email FROM faculty WHERE id = :id', ['id' => (int) ($assignment['evaluatee_faculty_id'] ?? 0)]);
    if ($evaluateeFaculty !== null) {
        notify_role('admin_hr', 'evaluation', 'Form A Evaluation Submitted',
            $evaluateeFaculty['full_name'] . ' has been evaluated via Form A.',
            '/admin/ai-actions', 'evaluation', (int) $assignmentId);
        $evaluateeUser = admin_one('SELECT id FROM users WHERE email = :email AND is_active = 1', ['email' => $evaluateeFaculty['email']]);
        if ($evaluateeUser !== null) {
            notify_create((int) $evaluateeUser['id'], 'success', 'You Have Been Evaluated (Form A)',
                'Your Form A evaluation has been submitted by ' . ($user['full_name'] ?? 'an evaluator') . '. You can view the results in your dashboard.',
                '/faculty/results', 'evaluation', (int) $assignmentId);
        }
    }
    dipascaf_notify_admin_if_faculty_evaluations_complete((int) ($assignment['evaluatee_faculty_id'] ?? 0), (string) ($period['period_name'] ?? ''));
    return ['success' => true] + $result;
}

function dipascaf_submit_form_b_evaluation(array $assignment, int $evaluatorUserId, string $activityDescription): array
{
    dipascaf_ensure_form_b_schema();
    $period = dipascaf_assert_assignment_allowed($assignment, $evaluatorUserId, 'form_b');
    $rawPayload = (string) ($_POST['form_b_payload'] ?? '{}');
    $payload = json_decode($rawPayload, true);
    if (!is_array($payload)) {
        error_log('[evaluation-submit] Invalid form_b_payload JSON: ' . json_last_error_msg() . ' assignment=' . (int) ($assignment['id'] ?? 0));
        throw new RuntimeException('Invalid Form B JSON payload: ' . json_last_error_msg());
    }
    $result = dipascaf_submit_category_results($assignment, $evaluatorUserId, 'b', $payload, (string) $period['period_name']);
    admin_activity($activityDescription);
    $evaluateeFaculty = admin_one('SELECT full_name, email FROM faculty WHERE id = :id', ['id' => (int) ($assignment['evaluatee_faculty_id'] ?? 0)]);
    if ($evaluateeFaculty !== null) {
        notify_role('admin_hr', 'evaluation', 'Form B Evaluation Submitted',
            $evaluateeFaculty['full_name'] . ' has been evaluated via Form B.',
            '/admin/ai-actions', 'evaluation', (int) ($assignment['id'] ?? 0));
        $evaluateeUser = admin_one('SELECT id FROM users WHERE email = :email AND is_active = 1', ['email' => $evaluateeFaculty['email']]);
        if ($evaluateeUser !== null) {
            notify_create((int) $evaluateeUser['id'], 'success', 'You Have Been Evaluated (Form B)',
                'Your Form B evaluation has been submitted by ' . $activityDescription . '. You can view the results in your dashboard.',
                '/faculty/results', 'evaluation', (int) ($assignment['id'] ?? 0));
        }
    }
    dipascaf_notify_admin_if_faculty_evaluations_complete((int) ($assignment['evaluatee_faculty_id'] ?? 0), (string) ($period['period_name'] ?? ''));
    return $result;
}

function dipascaf_submit_evaluation(int $evaluatorUserId, string $evaluatorRole, string $activityDescription): array
{
    $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
    $assignment = admin_one(
        'SELECT * FROM peer_assignments WHERE id = :id AND evaluator_user_id = :evaluator_user_id AND evaluator_role = :evaluator_role AND COALESCE(is_archived, 0) = 0',
        ['id' => $assignmentId, 'evaluator_user_id' => $evaluatorUserId, 'evaluator_role' => $evaluatorRole]
    );

    if ($assignment === null) {
        throw new RuntimeException('Evaluation assignment was not found.');
    }

    $formType = (string) ($assignment['questionnaire_type'] ?? '') === 'admin' ? 'form_a' : 'form_b';
    dipascaf_assert_assignment_allowed($assignment, $evaluatorUserId, $formType);
    db()->prepare(
        'UPDATE peer_assignments pa
         JOIN users u ON u.id = pa.evaluator_user_id
         SET pa.status = "submitted", pa.submitted_at = NOW(),
             pa.evaluator_name_snapshot = COALESCE(pa.evaluator_name_snapshot, u.full_name),
             pa.evaluator_role_snapshot = COALESCE(pa.evaluator_role_snapshot, pa.evaluator_role),
             pa.effective_from = COALESCE(pa.effective_from, pa.assigned_at)
         WHERE pa.id = :id'
    )->execute(['id' => $assignmentId]);
    try {
        db()->prepare(
            'UPDATE peer_evaluation_assignments
             SET status = "completed"
             WHERE peer_assignment_id = :id
               AND COALESCE(is_archived, 0) = 0'
        )->execute(['id' => $assignmentId]);
    } catch (Throwable) {
        // Older databases may not have the official peer mapping table yet.
    }

    // Recalculate progress in real time
    if (function_exists('admin_recalculate_faculty_progress')) {
        admin_recalculate_faculty_progress((int) ($assignment['evaluatee_faculty_id'] ?? 0));
    }

    admin_activity($activityDescription);
    $assignmentInfo = admin_one('SELECT f.full_name, f.email FROM peer_assignments pa JOIN faculty f ON f.id = pa.evaluatee_faculty_id WHERE pa.id = :id AND COALESCE(pa.is_archived, 0) = 0', ['id' => $assignmentId]);
    if ($assignmentInfo !== null) {
        notify_role('admin_hr', 'evaluation', 'Evaluation Submitted',
            $assignmentInfo['full_name'] . ' has been evaluated.',
            '/admin/ai-actions', 'evaluation', $assignmentId);
        $evaluateeUser = admin_one('SELECT id FROM users WHERE email = :email AND is_active = 1', ['email' => $assignmentInfo['email']]);
        if ($evaluateeUser !== null) {
            notify_create((int) $evaluateeUser['id'], 'success', 'You Have Been Evaluated',
                'An evaluation has been submitted about you. You can view the results in your dashboard.',
                '/faculty/results', 'evaluation', $assignmentId);
        }
    }
    dipascaf_notify_admin_if_faculty_evaluations_complete((int) ($assignment['evaluatee_faculty_id'] ?? 0), (string) ($assignment['cycle_name'] ?? ''));

    return ['assignment_id' => $assignmentId, 'message' => 'Evaluation submitted.'];
}

function dipascaf_render_evaluation_dashboard(array $options): void
{
    $assignments = is_array($options['assignments'] ?? null) ? $options['assignments'] : [];
    $user = current_user();
    $userId = $user !== null ? (int) $user['id'] : 0;

    // Group by section_key
    $groups = [];
    foreach ($assignments as $assignment) {
        $sectionKey = (string) ($assignment['section_key'] ?? 'faculty');
        if (!isset($groups[$sectionKey])) {
            $groups[$sectionKey] = [
                'label' => (string) ($assignment['role_label'] ?? 'Faculty'),
                'assignments' => [],
            ];
        }
        $groups[$sectionKey]['assignments'][] = $assignment;
    }

    // Order groups: dean first, then program_head, then faculty, then peer
    $groupOrder = ['dean', 'program_head', 'faculty', 'peer'];
    $sortedGroups = [];
    foreach ($groupOrder as $key) {
        if (isset($groups[$key])) {
            $sortedGroups[$key] = $groups[$key];
        }
    }
    foreach ($groups as $key => $group) {
        if (!isset($sortedGroups[$key])) {
            $sortedGroups[$key] = $group;
        }
    }

    $eyebrow = e((string) ($options['eyebrow'] ?? 'Evaluation'));
    $title = e((string) ($options['title'] ?? 'Assigned Evaluations'));
    $subtitle = e((string) ($options['subtitle'] ?? ''));

    echo '<div class="dipascaf-evaluation-dashboard">';

    echo '<div class="box-title" style="margin-bottom:1rem;">
        <h2>' . $title . '</h2>
        <span>' . $eyebrow . '</span>
    </div>';
    if ($subtitle !== '') {
        echo '<p class="muted" style="margin-bottom:1.5rem;">' . $subtitle . '</p>';
    }

    if ($assignments === []) {
        echo '<div class="notice info"><p>No evaluation assignments are currently available for your account.</p></div>';
        echo '</div>';
        return;
    }

    echo '<div class="eval-tab-bar">
        <button type="button" class="eval-tab-btn active" data-eval-tab="all">All (' . e((string) count($assignments)) . ')</button>
        <button type="button" class="eval-tab-btn" data-eval-tab="pending">Pending</button>
        <button type="button" class="eval-tab-btn" data-eval-tab="submitted">Submitted</button>
    </div>';

    foreach ($sortedGroups as $sectionKey => $group) {
        $pendingCount = count(array_filter($group['assignments'], fn(array $a): bool => (string) ($a['status'] ?? '') === 'pending'));
        echo '<section class="eval-assignment-group">
            <div class="eval-group-header">
                <h3 class="eval-group-title">' . e($group['label']) . ' (' . e((string) count($group['assignments'])) . ')</h3>
                <span class="eval-group-badge ' . ($pendingCount > 0 ? 'has-pending' : 'all-done') . '">' . e((string) $pendingCount) . ' pending</span>
            </div>
            <div class="eval-card-grid">';

        foreach ($group['assignments'] as $assignment) {
            $assignmentId = (int) ($assignment['id'] ?? 0);
            $status = (string) ($assignment['status'] ?? 'pending');
            $evaluateeName = e((string) ($assignment['evaluatee_name'] ?? $assignment['full_name'] ?? 'Unknown'));
            $department = e((string) ($assignment['department'] ?? ''));
            $programCode = e((string) ($assignment['program_code'] ?? ''));
            $positionTitle = e((string) ($assignment['position_title'] ?? ''));
            $deadline = e((string) ($assignment['deadline'] ?? ''));
            $isSubmitted = $status === 'submitted';
            $questionnaireType = (string) ($assignment['questionnaire_type'] ?? '');

            echo '<article class="eval-assignment-card" data-eval-status="' . e($status) . '" data-assignment-id="' . e((string) $assignmentId) . '">
                <div class="eval-card-info">
                    <strong class="eval-card-name">' . $evaluateeName . '</strong>
                    <span class="eval-card-detail">' . ($positionTitle ?: 'Faculty') . ($department ? ' &middot; ' . $department : '') . ($programCode ? ' &middot; ' . $programCode : '') . '</span>
                </div>
                <div class="eval-card-meta">
                    <span class="eval-status-badge ' . ($isSubmitted ? 'submitted' : 'pending') . '">' . e($isSubmitted ? 'Submitted' : 'Pending') . '</span>';

            if ($deadline !== '') {
                echo '<span class="eval-due-date">Due: ' . $deadline . '</span>';
            }

            if (!$isSubmitted) {
                echo '<button type="button" class="eval-action-btn eval-open-btn primary" data-assignment-id="' . e((string) $assignmentId) . '">Evaluate</button>';
            } else {
                echo '<button type="button" class="eval-action-btn eval-open-btn secondary" data-assignment-id="' . e((string) $assignmentId) . '">View</button>';
            }

            echo '</div></article>';

            // Evaluation form/preview (hidden until opened)
            echo '<div class="eval-form-panel" id="evalForm_' . e((string) $assignmentId) . '" hidden>
                <div class="eval-form-inner"' . ($isSubmitted ? ' data-loaded="1"' : '') . '>';
            if ($isSubmitted) {
                dipascaf_render_evaluation_results($assignment);
            }
            echo '</div></div>';
        }

        echo '</div></section>';
    }

    echo '</div>';

    // ── JavaScript ──────────────────────────────────────────────────────
    ?><script>
(function() {
    const baseUrl = "<?= BASE_URL ?>";

    // Tab filter (uses CSS .active class for light/dark mode)
    document.querySelectorAll(".eval-tab-btn").forEach(function(btn) {
        btn.addEventListener("click", function() {
            document.querySelectorAll(".eval-tab-btn").forEach(function(b) {
                b.classList.remove('active');
            });
            this.classList.add('active');
            var tab = this.getAttribute("data-eval-tab");
            document.querySelectorAll(".eval-assignment-card").forEach(function(card) {
                if (tab === "all") {
                    card.hidden = false;
                } else {
                    card.hidden = card.getAttribute("data-eval-status") !== tab;
                }
            });
        });
    });
    // Ensure first tab (All) starts active
    var firstTab = document.querySelector('.eval-tab-btn');
    if (firstTab) firstTab.classList.add('active');

    function centerEvaluationPanel(panel) {
        if (!panel) return;
        window.requestAnimationFrame(function() {
            panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }

    // Load and render evaluation form
    var loadingForms = {};
    document.querySelectorAll(".eval-open-btn").forEach(function(btn) {
        btn.addEventListener("click", function() {
            var aid = this.getAttribute("data-assignment-id");
            var panel = document.getElementById("evalForm_" + aid);
            if (!panel) return;
            var inner = panel.querySelector(".eval-form-inner");
            if (!inner) return;

            if (panel.hidden === false) {
                panel.hidden = true;
                return;
            }

            // Close all other panels
            document.querySelectorAll(".eval-form-panel").forEach(function(p) { p.hidden = true; });

            panel.hidden = false;
            centerEvaluationPanel(panel);

            if (inner.getAttribute("data-loaded") === "1") return;
            inner.setAttribute("data-loaded", "1");
            inner.innerHTML = '<div class="eval-skeleton" role="status" aria-label="Loading evaluation form...">' +
                '<div class="eval-skel-progress">' +
                    '<div class="skel-block skel-label"></div>' +
                    '<div class="skel-block skel-stat"></div>' +
                    '<div class="skel-block skel-bar"></div>' +
                    '<div class="skel-block skel-pct"></div>' +
                '</div>' +
                '<div class="eval-skel-nav">' +
                    '<div class="skel-block skel-tab"></div>' +
                    '<div class="skel-block skel-tab-wide"></div>' +
                    '<div class="skel-block skel-tab-mid"></div>' +
                    '<div class="skel-block skel-tab"></div>' +
                '</div>' +
                '<div class="eval-skel-section">' +
                    '<div class="skel-header">' +
                        '<div class="skel-block skel-title"></div>' +
                        '<div class="skel-block skel-badge"></div>' +
                        '<div class="skel-block skel-status"></div>' +
                    '</div>' +
                    '<div class="skel-body">' +
                        '<div class="skel-row">' +
                            '<div class="skel-block skel-qtext"></div>' +
                            '<div class="skel-block skel-rating-group"></div>' +
                        '</div>' +
                        '<div class="skel-row">' +
                            '<div class="skel-block skel-qtext-short"></div>' +
                            '<div class="skel-block skel-rating-group"></div>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="eval-skel-section">' +
                    '<div class="skel-header">' +
                        '<div class="skel-block skel-title"></div>' +
                        '<div class="skel-block skel-badge"></div>' +
                        '<div class="skel-block skel-status"></div>' +
                    '</div>' +
                    '<div class="skel-body">' +
                        '<div class="skel-row">' +
                            '<div class="skel-block skel-qtext"></div>' +
                            '<div class="skel-block skel-rating-group"></div>' +
                        '</div>' +
                        '<div class="skel-row">' +
                            '<div class="skel-block skel-qtext-short"></div>' +
                            '<div class="skel-block skel-rating-group"></div>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="eval-skel-summary">' +
                    '<div class="skel-block skel-score"></div>' +
                    '<div class="skel-block skel-btn"></div>' +
                '</div>' +
            '</div>';

            // Fetch categories via AJAX
            var formType = this.getAttribute("data-form-type") || "";
            var fetchUrl = baseUrl + "/api/evaluations.php?action=categories&assignment_id=" + aid;

            fetch(fetchUrl, { credentials: "same-origin" })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.ok) {
                        inner.innerHTML = "<div class=\"notice error\"><p>Error: " + escapeHtml(data.message || data.error || "Could not load form") + "</p></div>";
                        return;
                    }
                    renderEvalForm(inner, aid, data);
                    centerEvaluationPanel(panel);
                })
                .catch(function(err) {
                    inner.innerHTML = "<div class=\"notice error\"><p>Network error loading evaluation form.</p></div>";
                });
        });
    });

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, function(c) {
            return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#039;"}[c];
        });
    }

    function renderEvalForm(container, assignmentId, data) {
        var categories = data.categories || [];
        var formType = data.form_type || "form_b";
        var isFormA = formType === "form_a";
        var role = data.role || "faculty";

        if (categories.length === 0) {
            container.innerHTML = "<div class=\"notice info\"><p>No evaluation criteria are configured yet. Please ask HR/Admin to set up the questionnaire.</p></div>";
            return;
        }

        var html = "";

        // Keyboard shortcut indicator (auto-dismisses after 5s)
        html += '<div class="eval-kbd-indicator" id="evalKbd_'+assignmentId+'">' +
            '<span class="eval-kbd-icon">⌨</span>' +
            '<span class="eval-kbd-text">Use <kbd>↑</kbd> <kbd>↓</kbd> to navigate questions, <kbd>←</kbd> <kbd>→</kbd> to change rating</span>' +
            '<button type="button" class="eval-kbd-dismiss" data-kbd-dismiss="'+assignmentId+'">✕</button>' +
        '</div>';

        // Rating scale
        html += '<div class="eval-scale-legend">' +
            '<span>5 — Highly Evident</span>' +
            '<span>4 — Evident</span>' +
            '<span>3 — Moderately Evident</span>' +
            '<span>2 — Slightly Evident</span>' +
            '<span>1 — Not Evident</span>' +
            '</div>';

        html += '<div class="eval-progress-tracker" id="evalProgress_'+assignmentId+'">' +
            '<span class="eval-progress-label">Progress</span>' +
            '<div class="eval-progress-stats">' +
                '<span class="eval-progress-stat">Total: <strong id="evalTotalQ_'+assignmentId+'">0</strong></span>' +
                '<span class="eval-progress-stat">Answered: <strong id="evalAnsweredQ_'+assignmentId+'">0</strong></span>' +
                '<span class="eval-progress-stat">Remaining: <strong id="evalRemainingQ_'+assignmentId+'">0</strong></span>' +
            '</div>' +
            '<div class="eval-progress-bar-wrap">' +
                '<div class="eval-progress-bar"><div class="eval-progress-fill" id="evalProgressFill_'+assignmentId+'" style="width:0%"></div></div>' +
                '<span class="eval-progress-pct" id="evalProgressPct_'+assignmentId+'">0%</span>' +
            '</div>' +
        '</div>';

        // Validation alert (hidden by default)
        html += '<div class="eval-validation-alert" id="evalValidation_'+assignmentId+'" hidden>' +
            '<span class="eval-validation-icon">!</span>' +
            '<div class="eval-validation-content">' +
                '<strong>Incomplete Fields</strong>' +
                '<p id="evalValidationMsg_'+assignmentId+'">Please complete the missing required fields before proceeding.</p>' +
            '</div>' +
            '<button type="button" class="eval-validation-dismiss" onclick="this.parentElement.hidden=true">✕</button>' +
        '</div>';

        // Category navigation tabs
        html += '<div class="eval-category-nav" id="evalCatNav_'+assignmentId+'"></div>';

        html += '<form class="eval-form" data-aid="' + assignmentId + '" data-form-type="' + formType + '">';
        html += "<input type=\"hidden\" name=\"csrf_token\" value=\"" + escapeHtml(data.csrf_token || "") + "\">";
        html += "<input type=\"hidden\" name=\"assignment_id\" value=\"" + assignmentId + "\">";
        html += "<input type=\"hidden\" name=\"action\" value=\"submit_evaluation\">";

        categories.forEach(function(cat, ci) {
            var catId = cat.id || cat.sourceId || ci;
            var weight = parseFloat(cat.factor_weight || cat.weight || 0) || 0;
            var questions = cat.questions || [];
            if (questions.length === 0) return;

            html += '<details class="eval-section" data-cid="' + catId + '" data-weight="' + weight + '">';
            html += '<summary class="eval-section-header">' +
                '<span class="eval-section-header-icon">▶</span>' +
                '<strong class="eval-section-title">' + escapeHtml(cat.title || '') + '</strong>' +
                '<span class="eval-section-badge">' + weight.toFixed(0) + '% weight</span>' +
                '<span class="eval-section-status" id="evalStatus_' + catId + '">0/' + questions.length + ' answered</span>' +
            '</summary>';
            html += '<div class="eval-section-body">';

            questions.forEach(function(q, qi) {
                var qid = q.id || q.sourceId || qi;
                var text = q.text || q.question_text || "";
                html += '<div class="eval-question" data-qidx="' + qi + '" data-qid="' + qid + '">';
                html += '<div class="eval-question-text">' + escapeHtml(text) + '<div class="eval-question-error">Please rate this question</div></div>';
                html += '<div class="eval-rating-group" data-qid="' + qid + '">';
                for (var r = 1; r <= 5; r++) {
                    html += '<label class="eval-rating-btn">';
                    html += '<input type="radio" name="q_' + catId + '_' + qid + '" value="' + r + '" class="eval-rating-radio" required>';
                    html += '<span>' + r + '</span>';
                    html += '</label>';
                }
                html += '</div></div>';
            });

            // Live category calculation (display only; the server recomputes it on submit).
            html += '<div class="eval-category-calculation" aria-live="polite">';
            html += '<div class="eval-calc-part"><span>Average Rating</span><output id="evalAvg_' + catId + '">—</output></div>';
            html += '<span class="eval-calc-operator" aria-hidden="true">×</span>';
            html += '<div class="eval-calc-part"><span>Factor Weight</span><output>' + weight.toFixed(0) + '%</output></div>';
            html += '<span class="eval-calc-operator" aria-hidden="true">=</span>';
            html += '<div class="eval-calc-part eval-calc-result"><span>Weighted Score</span><output id="evalWeighted_' + catId + '">—</output></div>';
            html += '</div>';

            // Conditional fields
            html += '<div class="eval-conditional" data-cid="' + catId + '">';
            html += '<div class="eval-cond-field eval-cond-reason" hidden><label>Reason for Rating<span class="eval-cond-required-tag">* Required</span></label><textarea class="eval-reason-input" data-cid="' + catId + '" placeholder="Explain why this rating was given."></textarea></div>';
            html += '<div class="eval-cond-field eval-cond-evidence" hidden><label>Behavioral Evidence<span class="eval-cond-required-tag">* Required</span></label><textarea class="eval-evidence-input" data-cid="' + catId + '" placeholder="Describe specific observable behaviors that support this rating."></textarea></div>';
            html += '<div class="eval-cond-field eval-cond-recommendation" hidden><label>Recommendation<span class="eval-cond-required-tag">* Required</span></label><textarea class="eval-recommendation-input" data-cid="' + catId + '" placeholder="Suggest specific actions for improvement."></textarea></div>';
            html += '</div>';

            html += '</div></details>';
        });

        // Submit area
        html += '<div class="eval-summary">' +
            '<div class="eval-summary-score">' +
                '<span class="eval-summary-score-label">Total Weighted Score</span>' +
                '<strong class="eval-summary-score-value" id="evalFinalScore">0.00 / 5.00</strong>' +
                '<small class="eval-summary-score-status" id="evalFinalStatus">Complete all categories to submit.</small>' +
            '</div>' +
            '<button type="submit" class="eval-submit-btn" disabled>Submit Evaluation</button>' +
        '</div></form>';

        // Success state placeholder
        html += '<div class="eval-success" hidden>' +
            '<span class="eval-success-icon">✅</span>' +
            '<h3>Evaluation Submitted!</h3>' +
            '<p>Your evaluation has been recorded successfully.</p>' +
        '</div>';

        container.innerHTML = html;

        // Wire up form logic
        setupEvalForm(container, assignmentId, formType);
    }

    function setupEvalForm(container, assignmentId, formType) {
        var form = container.querySelector(".eval-form");
        if (!form) return;

        var sections = form.querySelectorAll(".eval-section");
        var finalScore = document.getElementById("evalFinalScore");
        var finalStatus = document.getElementById("evalFinalStatus");
        var submitBtn = container.querySelector(".eval-submit-btn");
        var progressFill = document.getElementById("evalProgressFill_" + assignmentId);
        var progressPct = document.getElementById("evalProgressPct_" + assignmentId);
        var totalQEl = document.getElementById("evalTotalQ_" + assignmentId);
        var answeredQEl = document.getElementById("evalAnsweredQ_" + assignmentId);
        var remainingQEl = document.getElementById("evalRemainingQ_" + assignmentId);
        var validationAlert = document.getElementById("evalValidation_" + assignmentId);
        var validationMsg = document.getElementById("evalValidationMsg_" + assignmentId);
        var catNav = document.getElementById("evalCatNav_" + assignmentId);

        // Mirrored from src/utils/evalKeyboardNav.js (tested via vitest) — pure computation
        function computeCategoryState(inputs) {
            var cid = inputs.cid, weight = inputs.weight || 0, answers = inputs.answers || {};
            var totalQuestions = inputs.totalQuestions || 0;
            var evidence = (inputs.evidence || "").trim();
            var reason = (inputs.reason || "").trim();
            var recommendation = (inputs.recommendation || "").trim();
            var answered = Object.keys(answers).length;
            var total = Object.values(answers).reduce(function(s, v) { return s + Number(v); }, 0);
            var avg = answered === totalQuestions && totalQuestions > 0 ? total / totalQuestions : 0;
            var weighted = avg * (Number(weight) / 100);
            var requiredType = "none";
            if (avg >= 4.51) requiredType = "high";
            else if (avg <= 3) requiredType = "low";
            else if (avg > 0) requiredType = "reason";
            var complete = false;
            if (answered === totalQuestions && totalQuestions > 0) {
                if (avg >= 4.51) complete = evidence.length > 0;
                else if (avg <= 3) complete = evidence.length > 0 && recommendation.length > 0;
                else complete = reason.length > 0;
            }
            return { cid: String(cid), answers: answers, avg: avg, weighted: weighted, evidence: evidence, reason: reason, recommendation: recommendation, requiredType: requiredType, answered: answered, totalQuestions: Number(totalQuestions), complete: complete, weight: Number(weight) };
        }

        // Mirrored from src/utils/evalKeyboardNav.js (tested via vitest) — pure aggregation
        function computeProgressSummary(states) {
            if (!states || states.length === 0) {
                return { totalWeighted: 0, allComplete: false, anyAnswered: false, totalQuestionsAll: 0, totalAnsweredAll: 0, remaining: 0, pctComplete: 0, pending: 0 };
            }
            var totalWeighted = 0, allComplete = true, anyAnswered = false;
            var totalQuestionsAll = 0, totalAnsweredAll = 0, pending = 0;
            states.forEach(function(s) {
                totalWeighted += s.weighted || 0;
                if (!s.complete && (s.totalQuestions || 0) > 0) allComplete = false;
                if ((s.answered || 0) > 0) anyAnswered = true;
                totalQuestionsAll += s.totalQuestions || 0;
                totalAnsweredAll += s.answered || 0;
                if (!s.complete && (s.totalQuestions || 0) > 0) pending++;
            });
            var remaining = totalQuestionsAll - totalAnsweredAll;
            var pct = totalQuestionsAll > 0 ? Math.round((totalAnsweredAll / totalQuestionsAll) * 100) : 0;
            return { totalWeighted: totalWeighted, allComplete: totalQuestionsAll > 0 ? allComplete : false, anyAnswered: anyAnswered, totalQuestionsAll: totalQuestionsAll, totalAnsweredAll: totalAnsweredAll, remaining: Math.max(0, remaining), pctComplete: pct, pending: pending };
        }

        // Extract category data from DOM, then compute state via pure function
        function getCategoryState(section) {
            var cid = section.getAttribute("data-cid");
            var weight = parseFloat(section.getAttribute("data-weight")) || 0;
            var radios = section.querySelectorAll(".eval-rating-radio:checked");
            var answers = {};
            radios.forEach(function(r) {
                var qid = r.closest(".eval-rating-group").getAttribute("data-qid");
                answers[qid] = parseInt(r.value, 10);
            });
            var totalQuestions = section.querySelectorAll(".eval-question").length;
            var evidence = section.querySelector(".eval-evidence-input") ? (section.querySelector(".eval-evidence-input").value || "").trim() : "";
            var reason = section.querySelector(".eval-reason-input") ? (section.querySelector(".eval-reason-input").value || "").trim() : "";
            var recommendation = section.querySelector(".eval-recommendation-input") ? (section.querySelector(".eval-recommendation-input").value || "").trim() : "";
            return computeCategoryState({ cid: cid, weight: weight, answers: answers, totalQuestions: totalQuestions, evidence: evidence, reason: reason, recommendation: recommendation });
        }

        function updateConditionalFields(section) {
            var state = getCategoryState(section);
            var cond = section.querySelector(".eval-conditional");
            if (!cond) return;
            var reasonField = cond.querySelector(".eval-cond-reason");
            var evidenceField = cond.querySelector(".eval-cond-evidence");
            var recommendationField = cond.querySelector(".eval-cond-recommendation");
            if (reasonField) reasonField.hidden = state.avg === 0 || state.requiredType !== "reason";
            if (evidenceField) evidenceField.hidden = state.avg === 0 || (state.requiredType !== "high" && state.requiredType !== "low");
            if (recommendationField) recommendationField.hidden = state.avg === 0 || state.requiredType !== "low";
        }

        function updateMissingQuestionMarks(section, state) {
            var shouldShowQuestionErrors = state.answered > 0 && state.answered < state.totalQuestions;
            section.querySelectorAll(".eval-question").forEach(function(question) {
                var qid = question.getAttribute("data-qid");
                var isMissing = shouldShowQuestionErrors && qid && !Object.prototype.hasOwnProperty.call(state.answers, qid);
                question.classList.toggle("has-error", Boolean(isMissing));
            });

            section.querySelectorAll(".eval-cond-field").forEach(function(field) {
                var textarea = field.querySelector("textarea");
                var isVisible = !field.hidden;
                var isMissing = isVisible && textarea && (textarea.value || "").trim() === "";
                field.classList.toggle("has-error", Boolean(isMissing));
                if (textarea) textarea.classList.toggle("has-error", Boolean(isMissing));
            });
        }

        function updateStatuses() {
            // Collect all category states once (avoids redundant DOM reads)
            var states = [];
            sections.forEach(function(section) {
                states.push(getCategoryState(section));
            });

            // Use pure aggregation function (mirrored from evalKeyboardNav.js)
            var summary = computeProgressSummary(states);

            // Build category nav HTML and update per-section DOM
            var navHtml = '';
            sections.forEach(function(section, i) {
                var state = states[i];

                // Update section CSS classes
                section.classList.remove('completed', 'missing-required');
                if (state.complete) {
                    section.classList.add('completed');
                } else if (state.answered > 0 && state.answered < state.totalQuestions) {
                    section.classList.add('missing-required');
                }

                // Build nav tab
                var indicatorClass = 'not-started';
                if (state.complete) indicatorClass = 'completed';
                else if (state.answered > 0) indicatorClass = 'in-progress';

                var catTitle = section.querySelector('.eval-section-title');
                var titleText = catTitle ? catTitle.textContent : '';
                var pct = state.totalQuestions > 0 ? Math.round((state.answered / state.totalQuestions) * 100) : 0;
                navHtml += '<button type="button" class="eval-cat-tab" data-cnav-cid="' + state.cid + '">' +
                    '<span class="cat-indicator ' + indicatorClass + '"></span>' +
                    '<span class="cat-label">' + titleText.substring(0, 20) + '</span>' +
                    '<span class="cat-progress-text">' + state.answered + '/' + state.totalQuestions + '</span>' +
                    '<div class="cat-progress-bar"><span style="width:' + pct + '%" class="' + (state.complete ? 'completed-progress' : 'partial-progress') + '"></span></div>' +
                '</button>';

                var statusEl = document.getElementById("evalStatus_" + state.cid);
                var avgEl = document.getElementById("evalAvg_" + state.cid);
                var weightedEl = document.getElementById("evalWeighted_" + state.cid);
                if (statusEl) {
                    statusEl.textContent = state.answered + "/" + state.totalQuestions + " answered";
                    statusEl.classList.remove('completed', 'partial', 'neutral');
                    statusEl.classList.add(state.complete ? 'completed' : state.answered > 0 ? 'partial' : 'neutral');
                }
                if (avgEl) {
                    avgEl.textContent = state.avg > 0 ? state.avg.toFixed(2) : "—";
                }
                if (weightedEl) weightedEl.textContent = state.avg > 0 ? state.weighted.toFixed(2) : "—";
                updateConditionalFields(section);
                updateMissingQuestionMarks(section, state);
            });

            // Update category nav
            if (catNav) {
                catNav.innerHTML = navHtml;
                catNav.querySelectorAll('.eval-cat-tab').forEach(function(tab) {
                    tab.addEventListener('click', function() {
                        var cid = this.getAttribute('data-cnav-cid');
                        sections.forEach(function(s) {
                            if (s.getAttribute('data-cid') === cid) {
                                s.open = !s.open;
                            }
                        });
                    });
                });
            }

            // Update progress tracker (from pure summary)
            if (totalQEl) totalQEl.textContent = summary.totalQuestionsAll;
            if (answeredQEl) answeredQEl.textContent = summary.totalAnsweredAll;
            if (remainingQEl) remainingQEl.textContent = summary.remaining;
            if (progressFill) progressFill.style.width = summary.pctComplete + '%';
            if (progressPct) progressPct.textContent = summary.pctComplete + '%';

            // Show/hide validation alert
            if (validationAlert) {
                var wasHidden = validationAlert.hidden;
                if (!summary.allComplete && summary.anyAnswered) {
                    validationAlert.hidden = false;
                    if (wasHidden) {
                        var firstIncomplete = null;
                        sections.forEach(function(s) {
                            if (!firstIncomplete) {
                                var st = getCategoryState(s);
                                if (!st.complete && st.totalQuestions > 0) {
                                    firstIncomplete = s;
                                }
                            }
                        });
                        if (firstIncomplete && firstIncomplete.scrollIntoView) {
                            firstIncomplete.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }
                } else {
                    validationAlert.hidden = true;
                }
            }

            // Summary
            finalScore.textContent = summary.totalWeighted.toFixed(2) + " / 5.00";
            if (summary.allComplete && summary.anyAnswered) {
                finalStatus.textContent = "All categories complete. Ready to submit.";
                finalStatus.className = 'eval-summary-score-status eval-final-status ready';
                submitBtn.disabled = false;
                // Trigger animations on first transition to all-complete
                if (!wasAllComplete) {
                    wasAllComplete = true;
                    // Progress bar pulse
                    if (progressFill) {
                        progressFill.classList.remove('pulse');
                        // Force reflow so the animation restarts
                        void progressFill.offsetWidth;
                        progressFill.classList.add('pulse');
                    }
                    // Score bounce
                    if (finalScore) {
                        finalScore.classList.remove('bounce');
                        void finalScore.offsetWidth;
                        finalScore.classList.add('bounce');
                    }
                    // Subtle confetti
                    triggerConfetti();
                }
            } else {
                wasAllComplete = false;
                finalStatus.textContent = summary.pending + " incomplete categor" + (summary.pending === 1 ? "y" : "ies") + " remaining.";
                finalStatus.className = 'eval-summary-score-status eval-final-status waiting';
                submitBtn.disabled = true;
            }
        }

        // Rating change listeners
        form.querySelectorAll(".eval-rating-radio").forEach(function(radio) {
            radio.addEventListener("change", function() {
                updateStatuses();
                // Style the selected label using CSS class
                var group = this.closest(".eval-rating-group");
                group.querySelectorAll(".eval-rating-btn").forEach(function(lbl) {
                    var inp = lbl.querySelector("input");
                    lbl.classList.toggle("selected", inp && inp.checked);
                });
            });
        });

        // Textarea listeners
        form.querySelectorAll(".eval-evidence-input, .eval-reason-input, .eval-recommendation-input").forEach(function(ta) {
            ta.addEventListener("input", updateStatuses);
        });

        // Keyboard indicator dismiss
        var kbdDismiss = container.querySelector('[data-kbd-dismiss="'+assignmentId+'"]');
        if (kbdDismiss) {
            kbdDismiss.addEventListener('click', function() {
                var indicator = document.getElementById('evalKbd_'+assignmentId);
                if (indicator) indicator.remove();
            });
            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                var indicator = document.getElementById('evalKbd_'+assignmentId);
                if (indicator) indicator.remove();
            }, 5000);
        }

        // ── Animation state trackers ──
        var wasAllComplete = false;

        function triggerConfetti() {
            var container = form.querySelector('.eval-summary');
            if (!container) return;
            // Remove any existing confetti
            var existing = container.querySelector('.eval-confetti-container');
            if (existing) existing.remove();
            var frag = document.createElement('div');
            frag.className = 'eval-confetti-container';
            frag.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:0;overflow:visible;pointer-events:none;z-index:100;';
            for (var c = 0; c < 15; c++) {
                var p = document.createElement('div');
                p.className = 'eval-confetti';
                frag.appendChild(p);
            }
            container.appendChild(frag);
            // Clean up after animation
            setTimeout(function() {
                if (frag.parentNode) frag.remove();
            }, 2500);
        }

        // ── Keyboard Navigation ──
        var allQuestions = form.querySelectorAll('.eval-question');
        var focusedIdx = -1;

        function kbdClearFocus() {
            allQuestions.forEach(function(q) { q.classList.remove('focused'); });
            focusedIdx = -1;
        }

        function kbdFocusQuestion(idx) {
            kbdClearFocus();
            if (idx < 0) idx = 0;
            if (idx >= allQuestions.length) idx = allQuestions.length - 1;
            focusedIdx = idx;
            var q = allQuestions[idx];
            // Open parent details section so the question is visible
            var parentSection = q.closest('.eval-section');
            if (parentSection) parentSection.open = true;
            q.classList.add('focused');
            q.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function kbdRateFocused(delta) {
            if (focusedIdx < 0 || focusedIdx >= allQuestions.length) return;
            var q = allQuestions[focusedIdx];
            var group = q.querySelector('.eval-rating-group');
            if (!group) return;
            var radios = group.querySelectorAll('.eval-rating-radio');
            var checked = group.querySelector('.eval-rating-radio:checked');
            var currentVal = checked ? parseInt(checked.value, 10) : 0;
            var newVal = Math.max(1, Math.min(5, currentVal + delta));
            radios.forEach(function(r) {
                if (parseInt(r.value, 10) === newVal) {
                    r.checked = true;
                    r.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        }

        form.addEventListener('keydown', function(e) {
            // Ignore if user is typing in textarea
            if (e.target.tagName === 'TEXTAREA') return;
            var key = e.key;
            if (key === 'ArrowDown' || key === 'ArrowUp' || key === 'ArrowRight' || key === 'ArrowLeft') {
                e.preventDefault();
                if (key === 'ArrowDown') {
                    if (focusedIdx < 0) kbdFocusQuestion(0);
                    else kbdFocusQuestion(focusedIdx + 1);
                } else if (key === 'ArrowUp') {
                    if (focusedIdx < 0) kbdFocusQuestion(allQuestions.length - 1);
                    else kbdFocusQuestion(focusedIdx - 1);
                } else if (key === 'ArrowRight') {
                    kbdRateFocused(1);
                } else if (key === 'ArrowLeft') {
                    kbdRateFocused(-1);
                }
            }
        });

        // Clear focus when clicking outside rating areas
        form.addEventListener('click', function() {
            setTimeout(kbdClearFocus, 150);
        });

        // Initial render
        updateStatuses();

        // Submit handler
        form.addEventListener("submit", function(e) {
            e.preventDefault();
            if (submitBtn.disabled) return;

            submitBtn.disabled = true;
            submitBtn.textContent = "Submitting...";
            submitBtn.classList.add('loading');

            // Build payload
            var payload = { categories: [] };
            sections.forEach(function(section) {
                var state = getCategoryState(section);
                if (state.answered === 0) return;
                var catObj = {
                    category_id: Number(state.cid),
                    answers: Object.fromEntries(
                        Object.entries(state.answers).map(function(e) { return [e[0], Number(e[1])]; })
                    ),
                    total_rate: Number(Object.values(state.answers).reduce(function(a, b) { return a + b; }, 0).toFixed(2)),
                    question_count: state.totalQuestions,
                    average_rating: Number(state.avg.toFixed(2)),
                    factor_weight: Number(state.weight),
                    weighted_score: Number(state.weighted.toFixed(4)),
                    behavioral_evidence: state.evidence,
                    reason_for_rating: state.reason,
                    recommendation: state.recommendation
                };
                payload.categories.push(catObj);
            });

            if (formType === "form_a") {
                // Form A: send as form_a_payload (object keyed by category_id)
                var formAPayload = {};
                payload.categories.forEach(function(c) {
                    formAPayload[String(c.category_id)] = c;
                });
            }

            fetch(baseUrl + "/api/evaluations.php?action=submit", {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" },
                body: JSON.stringify({
                    action: "submit",
                    assignment_id: Number(assignmentId),
                    csrf_token: form.querySelector("input[name=\"csrf_token\"]")?.value || "",
                    form_a_payload: formType === "form_a" ? formAPayload : undefined,
                    form_b_payload: formType === "form_b" ? payload : undefined
                }),
                credentials: "same-origin"
            })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                if (result.success || result.ok) {
                    form.hidden = true;
                    var success = container.querySelector(".eval-success");
                    if (success) success.hidden = false;
                    // Update card status
                    var card = document.querySelector(".eval-assignment-card[data-assignment-id]");
                    // Actually we need to update the card
                    var cards = document.querySelectorAll(".eval-assignment-card");
                    cards.forEach(function(c) {
                        var btn = c.querySelector("[data-assignment-id=\"" + assignmentId + "\"]");
                        if (btn || c.querySelector("[data-assignment-id=\"" + assignmentId + "\"]")) {
                            c.setAttribute("data-eval-status", "submitted");
                            var badge = c.querySelector(".eval-status-badge");
                            if (badge) {
                                badge.textContent = "Submitted";
                                badge.className = 'eval-status-badge submitted';
                            }
                            var evalBtn = c.querySelector(".eval-action-btn");
                            if (evalBtn) {
                                evalBtn.textContent = "Submitted";
                                evalBtn.className = 'eval-action-btn secondary';
                                evalBtn.disabled = true;
                            }
                        }
                    });
                } else {
                    alert("Error: " + (result.error || result.message || "Submission failed."));
                    submitBtn.disabled = false;
                    submitBtn.textContent = "Submit Evaluation";
                    submitBtn.classList.remove('loading');
                }
            })
            .catch(function(err) {
                alert("Network error. Please try again.");
                submitBtn.disabled = false;
                submitBtn.textContent = "Submit Evaluation";
                submitBtn.classList.remove('loading');
            });
        });
    }
})();
</script>
<?php
}

function dipascaf_render_evaluation_results(array $assignment): void
{
    $assignmentId = (int) ($assignment['id'] ?? 0);
    $formType = (string) ($assignment['questionnaire_type'] ?? '') === 'admin' ? 'form_a' : 'form_b';
    $results = $formType === 'form_a'
        ? dipascaf_form_a_records([$assignment])
        : dipascaf_form_b_records([$assignment]);

    $grouped = $results[(string) $assignmentId] ?? [];
    if ($grouped === []) {
        echo '<div class="eval-preview-empty notice info"><p>Evaluation was submitted but no category results were found.</p></div>';
        return;
    }

    $totalWeighted = 0.0;
    $totalAverage = 0.0;
    $strengths = [];
    $improvements = [];
    $categoryCount = count($grouped);
    foreach ($grouped as $row) {
        $averageRating = (float) ($row['average_rating'] ?? 0);
        $weightedScore = (float) ($row['weighted_score'] ?? 0);
        $categoryTitle = (string) ($row['category_title'] ?? 'Category');
        $totalWeighted += $weightedScore;
        $totalAverage += $averageRating;

        if ($averageRating >= 4.5) {
            $strengths[] = [
                'title' => $categoryTitle,
                'score' => $averageRating,
            ];
        } elseif ($averageRating <= 3.0) {
            $improvements[] = [
                'title' => $categoryTitle,
                'score' => $averageRating,
            ];
        }
    }
    $meanAverage = $categoryCount > 0 ? $totalAverage / $categoryCount : 0.0;
    $submittedAt = trim((string) ($assignment['submitted_at'] ?? ''));
    $evaluateeName = (string) ($assignment['evaluatee_name'] ?? $assignment['full_name'] ?? 'Evaluation');
    $department = trim((string) ($assignment['department'] ?? ''));
    $program = trim((string) ($assignment['program_code'] ?? ''));
    $position = trim((string) ($assignment['position_title'] ?? ''));

    echo '<section class="eval-preview-card" aria-label="Evaluation preview">';
    echo '<div class="eval-preview-header">
        <div class="eval-preview-title-block">
            <span class="eval-preview-eyebrow">Evaluation Preview</span>
            <h4>' . e($evaluateeName) . '</h4>
            <p>' . e(implode(' · ', array_values(array_filter([$position, $department, $program])))) . '</p>
        </div>
        <div class="eval-preview-score-card">
            <span>Total Weighted Score</span>
            <strong>' . e(number_format($totalWeighted, 2)) . '</strong>
            <small>out of 5.00</small>
        </div>
    </div>';

    echo '<div class="eval-preview-metrics">
        <article><span>Categories</span><strong>' . e((string) $categoryCount) . '</strong></article>
        <article><span>Average Rating</span><strong>' . e(number_format($meanAverage, 2)) . '</strong></article>
        <article><span>Status</span><strong>Submitted</strong></article>';
    if ($submittedAt !== '') {
        echo '<article><span>Submitted At</span><strong>' . e($submittedAt) . '</strong></article>';
    }
    echo '</div>';

    echo '<div class="eval-preview-insights">';
    echo '<article class="eval-preview-insight strength">
        <span class="eval-preview-insight-label">Strengths</span>';
    if ($strengths !== []) {
        echo '<ul>';
        foreach (array_slice($strengths, 0, 3) as $item) {
            echo '<li><strong>' . e($item['title']) . '</strong><span>' . e(number_format((float) $item['score'], 2)) . '</span></li>';
        }
        echo '</ul>';
    } else {
        echo '<p>No category reached the strength threshold yet.</p>';
    }
    echo '</article>';

    echo '<article class="eval-preview-insight improvement">
        <span class="eval-preview-insight-label">Improvement Areas</span>';
    if ($improvements !== []) {
        echo '<ul>';
        foreach (array_slice($improvements, 0, 3) as $item) {
            echo '<li><strong>' . e($item['title']) . '</strong><span>' . e(number_format((float) $item['score'], 2)) . '</span></li>';
        }
        echo '</ul>';
    } else {
        echo '<p>No category is currently flagged for improvement.</p>';
    }
    echo '</article></div>';

    echo '<div class="eval-preview-table-wrap">';
    echo '<table class="eval-preview-table">';
    echo '<thead><tr>
        <th>Category</th>
        <th>Average</th>
        <th>Weight</th>
        <th>Weighted Score</th>
    </tr></thead><tbody>';

    foreach ($grouped as $row) {
        $avg = (float) ($row['average_rating'] ?? 0);
        $weight = (float) ($row['factor_weight'] ?? 0);
        $weighted = (float) ($row['weighted_score'] ?? 0);
        $scoreClass = $avg >= 4.5 ? 'excellent' : ($avg >= 3.5 ? 'good' : ($avg >= 3 ? 'fair' : 'needs-work'));
        $scorePct = max(0, min(100, ($avg / 5) * 100));
        echo '<tr>
            <td data-label="Category">
                <div class="eval-preview-category-name">' . e((string) ($row['category_title'] ?? '')) . '</div>
                <div class="eval-preview-score-bar ' . e($scoreClass) . '" style="--score-pct:' . e(number_format($scorePct, 2, '.', '')) . '%;"><span></span></div>
            </td>
            <td data-label="Average"><span class="eval-preview-rating ' . e($scoreClass) . '">' . e(number_format($avg, 2)) . '</span></td>
            <td data-label="Weight">' . e(number_format($weight, 0)) . '%</td>
            <td data-label="Weighted Score">' . e(number_format($weighted, 4)) . '</td>
        </tr>';
    }

    echo '<tr class="eval-preview-total-row">
        <td data-label="Summary">Total Weighted Score</td>
        <td colspan="2"></td>
        <td data-label="Weighted Score">' . e(number_format($totalWeighted, 4)) . '</td>
    </tr>';
    echo '</tbody></table></div></section>';
}

function dipascaf_render_evaluation_results_summary(array $assignment): void
{
    echo '<div class="eval-results-summary">
        <span>Submitted</span>
        <small>' . e((string) ($assignment['submitted_at'] ?? ($assignment['updated_at'] ?? ''))) . '</small>
    </div>';
}

function dipascaf_ensure_leadership_faculty_record(int $userId, string $positionTitle): int
{
    $user = admin_one(
        'SELECT id, full_name, email, phone, department, program, role FROM users WHERE id = :id AND is_active = 1 LIMIT 1',
        ['id' => $userId]
    );

    if ($user === null) {
        return 0;
    }

    $email = trim((string) ($user['email'] ?? ''));
    if ($email === '') {
        return 0;
    }

    $existingFaculty = admin_one(
        'SELECT id FROM faculty WHERE user_id = :user_id OR email = :email LIMIT 1',
        ['user_id' => $userId, 'email' => $email]
    );

    $department = trim((string) ($user['department'] ?? ''));
    $program = trim((string) ($user['program'] ?? ''));

    if ($existingFaculty === null) {
        try {
            $stmt = db()->prepare(
                "INSERT IGNORE INTO faculty
                    (user_id, full_name, email, phone, department, program_code, position_title)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $userId,
                trim((string) ($user['full_name'] ?? '')),
                $email,
                trim((string) ($user['phone'] ?? '')) ?: null,
                $department,
                $program ?: null,
                $positionTitle,
            ]);
        } catch (Throwable) {
            return 0;
        }

        $existingFaculty = admin_one(
            'SELECT id FROM faculty WHERE user_id = :user_id OR email = :email LIMIT 1',
            ['user_id' => $userId, 'email' => $email]
        );
    } else {
        try {
            db()->prepare(
                "UPDATE faculty
                 SET user_id = ?, full_name = ?, email = ?, phone = ?, department = ?, program_code = ?, position_title = ?
                 WHERE id = ?"
            )->execute([
                $userId,
                trim((string) ($user['full_name'] ?? '')),
                $email,
                trim((string) ($user['phone'] ?? '')) ?: null,
                $department,
                $program ?: null,
                $positionTitle,
                (int) $existingFaculty['id'],
            ]);
        } catch (Throwable) {
            // Keep the existing row usable even if optional metadata cannot be refreshed.
        }
    }

    return (int) ($existingFaculty['id'] ?? 0);
}

function dipascaf_questionnaire_type_from_position(string $positionTitle): string
{
    $position = strtolower($positionTitle);
    return str_contains($position, 'vpaa') || str_contains($position, 'dean') || str_contains($position, 'program head') ? 'admin' : 'faculty';
}

function dipascaf_self_position_title_for_role(string $role): string
{
    return match ($role) {
        'vpaa' => 'VPAA',
        'dean' => 'Dean',
        'program_head' => 'Program Head',
        default => 'Faculty',
    };
}

function dipascaf_ensure_self_assignment(int $userId, string $role, ?array $period = null): void
{
    if (!in_array($role, ['vpaa', 'dean', 'program_head', 'teacher'], true)) {
        error_log('[self-assignment] Unsupported role for self assignment: ' . $role . ' user=' . $userId);
        return;
    }

    admin_ensure_archive_schema();
    admin_ensure_faculty_program_schema();

    $positionTitle = dipascaf_self_position_title_for_role($role);
    $facultyId = dipascaf_ensure_leadership_faculty_record($userId, $positionTitle);
    if ($facultyId <= 0) {
        error_log('[self-assignment] Could not resolve faculty record for user=' . $userId . ' role=' . $role);
        return;
    }

    $cycleName = trim((string) ($period['period_name'] ?? '')) ?: dipascaf_current_cycle_name();
    $deadline = trim((string) ($period['date_end'] ?? '')) ?: dipascaf_evaluation_deadline();
    $participationPeriodId = (int)($period['id'] ?? 0);
    if ($participationPeriodId <= 0) {
        $participationPeriod = admin_one('SELECT id FROM appraisal_periods WHERE period_name=:name LIMIT 1', ['name'=>$cycleName]);
        $participationPeriodId = (int)($participationPeriod['id'] ?? 0);
    }
    if ($participationPeriodId > 0 && dipascaf_period_user_is_excluded($participationPeriodId, $userId)) return;
    $questionnaireType = $role === 'teacher' ? 'faculty' : 'admin';

    $existing = admin_one(
        "SELECT id FROM peer_assignments
         WHERE cycle_name = :cycle_name
           AND evaluator_user_id = :evaluator_user_id
           AND evaluatee_faculty_id = :evaluatee_faculty_id
           AND evaluator_role = :evaluator_role
           AND assignment_type = 'self'
           AND COALESCE(is_archived, 0) = 0
         ORDER BY id ASC
         LIMIT 1",
        [
            'cycle_name' => $cycleName,
            'evaluator_user_id' => $userId,
            'evaluatee_faculty_id' => $facultyId,
            'evaluator_role' => $role,
        ]
    );

    if ($existing !== null) {
        db()->prepare(
            "UPDATE peer_assignments
             SET questionnaire_type = :questionnaire_type,
                 deadline = :deadline
             WHERE id = :id"
        )->execute([
            'id' => (int) $existing['id'],
            'questionnaire_type' => $questionnaireType,
            'deadline' => $deadline,
        ]);
        return;
    }

    db()->prepare(
        "INSERT INTO peer_assignments
            (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, questionnaire_type, status, assigned_at, deadline)
         VALUES
            (:cycle_name, :evaluator_user_id, :evaluatee_faculty_id, :evaluator_role, 'self', :questionnaire_type, 'pending', NOW(), :deadline)
         ON DUPLICATE KEY UPDATE
            evaluator_role = VALUES(evaluator_role),
            questionnaire_type = VALUES(questionnaire_type),
            deadline = VALUES(deadline),
            is_archived = 0,
            archived_at = NULL,
            archived_by = NULL"
    )->execute([
        'cycle_name' => $cycleName,
        'evaluator_user_id' => $userId,
        'evaluatee_faculty_id' => $facultyId,
        'evaluator_role' => $role,
        'questionnaire_type' => $questionnaireType,
        'deadline' => $deadline,
    ]);

    $created = admin_one(
        "SELECT id FROM peer_assignments
         WHERE cycle_name = :cycle_name
           AND evaluator_user_id = :evaluator_user_id
           AND evaluatee_faculty_id = :evaluatee_faculty_id
           AND evaluator_role = :evaluator_role
           AND assignment_type = 'self'
           AND COALESCE(is_archived, 0) = 0
         LIMIT 1",
        [
            'cycle_name' => $cycleName,
            'evaluator_user_id' => $userId,
            'evaluatee_faculty_id' => $facultyId,
            'evaluator_role' => $role,
        ]
    );
    if ($created === null) {
        error_log('[self-assignment] Insert/upsert completed but no self assignment row was found user=' . $userId . ' role=' . $role . ' cycle=' . $cycleName);
    }
}

function dipascaf_department_aliases_for_dean(int $deanUserId): array
{
    $departments = admin_all(
        'SELECT department_code, department_name FROM departments WHERE dean_user_id = :dean_user_id AND is_active = 1',
        ['dean_user_id' => $deanUserId]
    );

    if ($departments !== []) {
        $aliases = [];
        foreach ($departments as $department) {
            $aliases = array_merge($aliases, admin_department_aliases($department));
        }
        return array_values(array_unique(array_filter($aliases)));
    }

    $deanUser = admin_one(
        'SELECT department FROM users WHERE id = :id',
        ['id' => $deanUserId]
    );

    $department = trim((string) ($deanUser['department'] ?? ''));
    if ($department === '') {
        return [];
    }

    $departmentRow = admin_one(
        'SELECT department_code, department_name FROM departments WHERE department_name = :department OR department_code = :department LIMIT 1',
        ['department' => $department]
    );

    if ($departmentRow !== null) {
        return array_values(array_unique(array_filter(admin_department_aliases($departmentRow))));
    }

    $departmentNormalized = strtolower(admin_normalize_department_name($department));
    foreach (admin_departments() as $dept) {
        $aliases = array_map('strtolower', admin_department_aliases($dept));
        if (in_array($departmentNormalized, $aliases, true) || in_array(strtolower($department), $aliases, true)) {
            return array_values(array_unique(array_filter(admin_department_aliases($dept))));
        }
    }

    return [$department];
}

function dipascaf_sync_department_faculty_records(array $departments): void
{
    $departments = array_values(array_unique(array_filter($departments, fn ($value): bool => is_string($value) && trim($value) !== '')));
    if ($departments === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($departments), '?'));
    $users = admin_all(
        "SELECT id, full_name, email, phone, department, program, role
         FROM users
         WHERE is_active = 1
           AND role IN ('teacher', 'program_head')
           AND department IN ($placeholders)",
        $departments
    );

    foreach ($users as $user) {
        $email = trim((string) ($user['email'] ?? ''));
        $userId = (int) ($user['id'] ?? 0);
        if ($email === '' || $userId === 0) {
            continue;
        }

        $positionTitle = $user['role'] === 'program_head' ? 'Program Head' : 'Faculty';
        $programCode = trim((string) ($user['program'] ?? ''));
        $departmentValue = trim((string) ($user['department'] ?? ''));

        $existingFaculty = admin_one(
            'SELECT id FROM faculty WHERE user_id = :user_id OR email = :email LIMIT 1',
            ['user_id' => $userId, 'email' => $email]
        );

        if ($existingFaculty === null) {
            try {
                $stmt = db()->prepare(
                    "INSERT IGNORE INTO faculty
                        (user_id, full_name, email, phone, department, program_code, position_title)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $userId,
                    trim((string) ($user['full_name'] ?? '')),
                    $email,
                    trim((string) ($user['phone'] ?? '')) ?: null,
                    $departmentValue,
                    $programCode ?: null,
                    $positionTitle,
                ]);
            } catch (Throwable) {
                // ignore insert failures
            }
        } else {
            try {
                $stmt = db()->prepare(
                    "UPDATE faculty
                     SET full_name = ?, email = ?, phone = ?, department = ?, program_code = ?, position_title = ?
                     WHERE id = ?"
                );
                $stmt->execute([
                    trim((string) ($user['full_name'] ?? '')),
                    $email,
                    trim((string) ($user['phone'] ?? '')) ?: null,
                    $departmentValue,
                    $programCode ?: null,
                    $positionTitle,
                    (int) $existingFaculty['id'],
                ]);
            } catch (Throwable) {
                // ignore update failures
            }
        }
    }
}

function dipascaf_program_head_scope(int $programHeadUserId): array
{
    $programs = admin_all(
        'SELECT p.program_code, d.department_code, d.department_name
         FROM programs p
         JOIN departments d ON d.id = p.department_id
         WHERE p.program_head_user_id = :program_head_user_id AND p.is_active = 1',
        ['program_head_user_id' => $programHeadUserId]
    );

    $departments = [];
    $programCodes = [];
    foreach ($programs as $program) {
        $programCodes[] = trim((string) ($program['program_code'] ?? ''));
        $departments = array_merge($departments, admin_department_aliases($program));
    }

    if ($programs === []) {
        $user = admin_one(
            'SELECT department, program FROM users WHERE id = :id AND role = "program_head" LIMIT 1',
            ['id' => $programHeadUserId]
        );

        $department = trim((string) ($user['department'] ?? ''));
        if ($department !== '') {
            $departments = admin_matching_department_aliases($department);
        }

        $program = trim((string) ($user['program'] ?? ''));
        if ($program !== '') {
            $programCodes[] = $program;
        }
    }

    return [
        'departments' => array_values(array_unique(array_filter($departments))),
        'program_codes' => array_values(array_unique(array_filter($programCodes))),
    ];
}

function dipascaf_ensure_program_head_assignments(int $programHeadUserId): void
{
    admin_ensure_faculty_program_schema();

    $scope = dipascaf_program_head_scope($programHeadUserId);
    $departments = $scope['departments'];
    $programCodes = $scope['program_codes'];

    if ($programCodes === [] && $departments === []) {
        return;
    }

    $filters = [];
    $params = [];

    if ($departments !== []) {
        dipascaf_sync_department_faculty_records($departments);
    }

    $programHeadUser = admin_one(
        'SELECT email FROM users WHERE id = :id',
        ['id' => $programHeadUserId]
    );
    $programHeadEmail = strtolower(trim((string) ($programHeadUser['email'] ?? '')));
    $cycleName = dipascaf_current_cycle_name();
    $deadline = dipascaf_evaluation_deadline();

    if ($departments !== []) {
        $departmentPlaceholders = implode(',', array_fill(0, count($departments), '?'));
        $deanRows = admin_all(
            "SELECT DISTINCT u.id, u.email
             FROM departments d
             JOIN users u ON u.id = d.dean_user_id
             WHERE d.is_active = 1
               AND u.role = 'dean'
               AND u.is_active = 1
               AND (d.department_code IN ($departmentPlaceholders) OR d.department_name IN ($departmentPlaceholders) OR u.department IN ($departmentPlaceholders))",
            array_merge($departments, $departments, $departments)
        );

        $insertDeanAssignment = db()->prepare(
            "INSERT IGNORE INTO peer_assignments
                (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, questionnaire_type, status, assigned_at, deadline)
             VALUES (?, ?, ?, 'program_head', 'dean', 'admin', 'pending', NOW(), ?)"
        );

        foreach ($deanRows as $dean) {
            $deanUserId = (int) ($dean['id'] ?? 0);
            $deanEmail = strtolower(trim((string) ($dean['email'] ?? '')));
            if (
                $deanUserId === 0
                || $deanUserId === $programHeadUserId
                || ($programHeadEmail !== '' && $deanEmail !== '' && $deanEmail === $programHeadEmail)
            ) {
                continue;
            }

            $deanFacultyId = dipascaf_ensure_leadership_faculty_record($deanUserId, 'Dean');
            if ($deanFacultyId > 0) {
                $insertDeanAssignment->execute([
                    $cycleName,
                    $programHeadUserId,
                    $deanFacultyId,
                    $deadline,
                ]);
            }
        }
    }

    if ($programCodes === []) {
        return;
    }

    $programPlaceholders = implode(',', array_fill(0, count($programCodes), '?'));
    $filters[] = "(f.program_code IN ($programPlaceholders) OR u.program IN ($programPlaceholders))";
    $params = array_merge($params, $programCodes, $programCodes);

    // Create faculty evaluation assignments
    $facultyRows = admin_all(
        "SELECT f.id, f.user_id, f.email, f.position_title, u.role AS user_role
         FROM faculty f
         LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
         WHERE f.is_active = 1 AND f.is_archived = 0
           AND (" . implode(' OR ', $filters) . ")
           AND (u.role IS NULL OR u.role = 'teacher')
           AND LOWER(COALESCE(f.position_title, '')) NOT LIKE '%program head%'
           AND LOWER(COALESCE(f.position_title, '')) NOT LIKE '%dean%'
         ORDER BY f.full_name",
        $params
    );

    if ($facultyRows === []) {
        return;
    }

    $insertAssignment = db()->prepare(
        "INSERT IGNORE INTO peer_assignments
            (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, questionnaire_type, status, assigned_at, deadline)
         VALUES (?, ?, ?, 'program_head', 'program_head', 'faculty', 'pending', NOW(), ?)"
    );

    foreach ($facultyRows as $faculty) {
        $facultyUserId = (int) ($faculty['user_id'] ?? 0);
        $facultyEmail = strtolower(trim((string) ($faculty['email'] ?? '')));

        if (
            $facultyUserId === $programHeadUserId
            || ($programHeadEmail !== '' && $facultyEmail !== '' && $facultyEmail === $programHeadEmail)
        ) {
            continue;
        }

        $insertAssignment->execute([
            $cycleName,
            $programHeadUserId,
            (int) $faculty['id'],
            $deadline,
        ]);
    }
}

function dipascaf_ensure_dean_assignments(int $deanUserId): void
{
    $departments = dipascaf_department_aliases_for_dean($deanUserId);
    if ($departments === []) {
        return;
    }

    dipascaf_sync_department_faculty_records($departments);

    $deanUser = admin_one(
        'SELECT email FROM users WHERE id = :id',
        ['id' => $deanUserId]
    );
    $deanEmail = strtolower(trim((string) ($deanUser['email'] ?? '')));

    $placeholders = implode(',', array_fill(0, count($departments), '?'));
    $facultyRows = admin_all(
        "SELECT f.id, f.user_id, f.email, f.position_title, u.role AS user_role
         FROM faculty f
         LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
         WHERE f.is_active = 1 AND f.is_archived = 0
           AND f.department IN ($placeholders)
           AND (u.role IS NULL OR u.role <> 'admin_hr')
         ORDER BY f.full_name",
        $departments
    );

    if ($facultyRows === []) {
        return;
    }

    $cycleName = dipascaf_current_cycle_name();
    $insertAssignment = db()->prepare(
        "INSERT IGNORE INTO peer_assignments
            (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, questionnaire_type, status, assigned_at, deadline)
         VALUES (?, ?, ?, 'dean', 'dean', ?, 'pending', NOW(), ?)"
    );

    foreach ($facultyRows as $faculty) {
        $facultyUserId = (int) ($faculty['user_id'] ?? 0);
        $facultyEmail = strtolower(trim((string) ($faculty['email'] ?? '')));
        $facultyRole = strtolower(trim((string) ($faculty['user_role'] ?? '')));

        if (
            $facultyUserId === $deanUserId
            || $facultyRole === 'admin_hr'
            || ($deanEmail !== '' && $facultyEmail !== '' && $facultyEmail === $deanEmail)
        ) {
            continue;
        }

        $deadline = dipascaf_evaluation_deadline();
        $insertAssignment->execute([
            $cycleName,
            $deanUserId,
            (int) $faculty['id'],
            dipascaf_questionnaire_type_from_position((string) ($faculty['position_title'] ?? '')),
            $deadline,
        ]);
    }
}

function dipascaf_ensure_dean_evaluator_assignments(?int $deanUserId = null): void
{
    admin_ensure_faculty_program_schema();
    admin_ensure_archive_schema();

    $deans = $deanUserId !== null
        ? admin_all(
            'SELECT id FROM users WHERE id = :id AND role = "dean" AND is_active = 1',
            ['id' => $deanUserId]
        )
        : admin_all('SELECT id FROM users WHERE role = "dean" AND is_active = 1 ORDER BY full_name');

    if ($deans === []) {
        return;
    }

    $cycleName = dipascaf_current_cycle_name();
    $deadline = dipascaf_evaluation_deadline();
    $insertAssignment = db()->prepare(
        "INSERT IGNORE INTO peer_assignments
            (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, questionnaire_type, status, assigned_at, deadline)
         VALUES (?, ?, ?, ?, 'dean', 'admin', 'pending', NOW(), ?)"
    );

    foreach ($deans as $dean) {
        $targetDeanUserId = (int) ($dean['id'] ?? 0);
        if ($targetDeanUserId === 0) {
            continue;
        }

        $departments = dipascaf_department_aliases_for_dean($targetDeanUserId);
        if ($departments === []) {
            continue;
        }

        dipascaf_sync_department_faculty_records($departments);
        $deanFacultyId = dipascaf_ensure_leadership_faculty_record($targetDeanUserId, 'Dean');
        if ($deanFacultyId === 0) {
            continue;
        }

        $placeholders = implode(',', array_fill(0, count($departments), '?'));
        $evaluators = [];

        $vpaaRows = [];
        try {
            require_once __DIR__ . '/vpaa_data.php';
            if (function_exists('vpaa_ensure_schema')) {
                vpaa_ensure_schema();
            }
            $vpaaRows = admin_all(
                "SELECT DISTINCT u.id, u.role
                 FROM users u
                 LEFT JOIN vpaa_departments vd ON vd.vpaa_user_id = u.id
                 WHERE u.role = 'vpaa'
                   AND u.is_active = 1
                   AND (
                        vd.department_code IS NULL
                        OR vd.department_code IN ($placeholders)
                   )
                 ORDER BY u.full_name",
                $departments
            );
        } catch (Throwable) {
            $vpaaRows = admin_all(
                "SELECT DISTINCT u.id, u.role
                 FROM users u
                 WHERE u.role = 'vpaa' AND u.is_active = 1
                 ORDER BY u.full_name"
            );
        }
        foreach ($vpaaRows as $row) {
            $evaluators[(int) $row['id']] = ['id' => (int) $row['id'], 'role' => 'vpaa', 'rank' => 1];
        }

        $programHeadRows = admin_all(
            "SELECT DISTINCT u.id, u.role
             FROM users u
             LEFT JOIN faculty f ON f.user_id = u.id OR (f.user_id IS NULL AND f.email = u.email)
             LEFT JOIN programs p ON p.program_head_user_id = u.id AND p.is_active = 1
             LEFT JOIN departments d ON d.id = p.department_id AND d.is_active = 1
             WHERE u.role = 'program_head'
               AND u.is_active = 1
               AND (
                    u.department IN ($placeholders)
                    OR f.department IN ($placeholders)
                    OR d.department_code IN ($placeholders)
                    OR d.department_name IN ($placeholders)
               )
             ORDER BY u.full_name",
            array_merge($departments, $departments, $departments, $departments)
        );
        foreach ($programHeadRows as $row) {
            $evaluators[(int) $row['id']] = ['id' => (int) $row['id'], 'role' => 'program_head', 'rank' => 2];
        }

        $facultyRows = admin_all(
            "SELECT DISTINCT u.id, u.role
             FROM users u
             JOIN faculty f ON f.user_id = u.id OR (f.user_id IS NULL AND f.email = u.email)
             WHERE u.role = 'teacher'
               AND u.is_active = 1
               AND f.is_active = 1
               AND f.is_archived = 0
               AND f.department IN ($placeholders)
             ORDER BY u.full_name",
            $departments
        );
        foreach ($facultyRows as $row) {
            $evaluators[(int) $row['id']] = ['id' => (int) $row['id'], 'role' => 'teacher', 'rank' => 3];
        }

        uasort($evaluators, static fn (array $a, array $b): int => ($a['rank'] <=> $b['rank']) ?: ($a['id'] <=> $b['id']));

        foreach ($evaluators as $evaluator) {
            $evaluatorUserId = (int) ($evaluator['id'] ?? 0);
            $evaluatorRole = (string) ($evaluator['role'] ?? '');
            if ($evaluatorUserId === 0 || $evaluatorUserId === $targetDeanUserId || $evaluatorRole === '') {
                continue;
            }

            $insertAssignment->execute([
                $cycleName,
                $evaluatorUserId,
                $deanFacultyId,
                $evaluatorRole,
                $deadline,
            ]);
        }
    }
}

function dipascaf_ensure_teacher_leadership_assignments(int $teacherUserId): void
{
    admin_ensure_faculty_program_schema();

    $teacher = admin_one(
        'SELECT u.id, u.email, u.department, u.program, f.id AS faculty_id, f.department AS faculty_department, f.program_code
         FROM users u
         LEFT JOIN faculty f ON f.user_id = u.id OR (f.user_id IS NULL AND f.email = u.email)
         WHERE u.id = :id AND u.role = "teacher" AND u.is_active = 1
         LIMIT 1',
        ['id' => $teacherUserId]
    );

    if ($teacher === null) {
        return;
    }

    $department = trim((string) ($teacher['faculty_department'] ?? $teacher['department'] ?? ''));
    $programCode = trim((string) ($teacher['program_code'] ?? $teacher['program'] ?? ''));
    if ($department === '' && $programCode === '') {
        return;
    }

    $departmentAliases = $department !== '' ? admin_matching_department_aliases($department) : [];
    if ($departmentAliases === [] && $department !== '') {
        $departmentAliases = [$department];
    }

    $leaders = [];

    // Find Dean(s) for this teacher's department
    if ($departmentAliases !== []) {
        $placeholders = implode(',', array_fill(0, count($departmentAliases), '?'));
        $deans = admin_all(
            "SELECT DISTINCT u.id, u.role
             FROM departments d
             JOIN users u ON u.id = d.dean_user_id
             WHERE d.is_active = 1
               AND u.role = 'dean'
               AND u.is_active = 1
               AND (d.department_code IN ($placeholders) OR d.department_name IN ($placeholders) OR u.department IN ($placeholders))",
            array_merge($departmentAliases, $departmentAliases, $departmentAliases)
        );

        foreach ($deans as $dean) {
            $leaders[(int) $dean['id']] = ['user_id' => (int) $dean['id'], 'role' => 'dean'];
        }
    }

    // Find Program Head for this teacher's program
    if ($programCode !== '') {
        $programHead = admin_one(
            "SELECT u.id, u.role
             FROM programs p
             JOIN users u ON u.id = p.program_head_user_id
             WHERE p.is_active = 1
               AND p.program_code = :program_code
               AND u.role = 'program_head'
               AND u.is_active = 1
             LIMIT 1",
            ['program_code' => $programCode]
        );

        if ($programHead !== null) {
            $leaders[(int) $programHead['id']] = ['user_id' => (int) $programHead['id'], 'role' => 'program_head'];
        } else {
            $programHeadUser = admin_one(
                "SELECT id, role
                 FROM users
                 WHERE role = 'program_head'
                   AND is_active = 1
                   AND program = :program_code
                 LIMIT 1",
                ['program_code' => $programCode]
            );

            if ($programHeadUser !== null) {
                $leaders[(int) $programHeadUser['id']] = ['user_id' => (int) $programHeadUser['id'], 'role' => 'program_head'];
            }
        }
    }

    if ($leaders === []) {
        return;
    }

    $cycleName = dipascaf_current_cycle_name();
    $hasReplacementReason = admin_one("SHOW COLUMNS FROM peer_assignments LIKE 'replacement_reason'") !== null;
    $hasIsCurrent = admin_one("SHOW COLUMNS FROM peer_assignments LIKE 'is_current'") !== null;
    $insertAssignment = db()->prepare(
        "INSERT INTO peer_assignments
            (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, questionnaire_type, status, assigned_at, deadline)
         VALUES (?, ?, ?, 'teacher', ?, 'admin', 'pending', NOW(), ?)
         ON DUPLICATE KEY UPDATE
            deadline = VALUES(deadline),
            evaluator_role = 'teacher',
            questionnaire_type = 'admin',
            status = IF(status = 'submitted', status, 'pending'),
            is_archived = 0,
            archived_at = NULL,
            archived_by = NULL"
            . ($hasReplacementReason
                ? ", replacement_reason = IF(status = 'submitted', replacement_reason, NULL)"
                : '')
            . ($hasIsCurrent ? ', is_current = 1' : '')
    );

    foreach ($leaders as $leader) {
        $leaderUserId = (int) $leader['user_id'];
        if ($leaderUserId === $teacherUserId) {
            continue;
        }

        $assignmentType = $leader['role'] === 'dean' ? 'dean' : 'program_head';
        $positionTitle = $assignmentType === 'dean' ? 'Dean' : 'Program Head';
        $facultyId = dipascaf_ensure_leadership_faculty_record($leaderUserId, $positionTitle);
        if ($facultyId === 0 || $facultyId === (int) ($teacher['faculty_id'] ?? 0)) {
            continue;
        }

        $deadline = dipascaf_evaluation_deadline();
        $insertAssignment->execute([
            $cycleName,
            $teacherUserId,
            $facultyId,
            $assignmentType,
            $deadline,
        ]);
    }
}

function dipascaf_ensure_vpaa_assignments(int $vpaaUserId): void
{
    require_once __DIR__ . '/vpaa_data.php';
    vpaa_ensure_dean_assignments($vpaaUserId);
}

function dipascaf_init_evaluation_assignments(int $userId, string $role): void
{
    dipascaf_ensure_self_assignment($userId, $role);

    if ($role === 'teacher') {
        dipascaf_ensure_teacher_leadership_assignments($userId);
        // Peer assignments are generated from the official peer setup only.
        // Do not auto-pick ad hoc faculty here; stale or unrelated peers must not appear.
    } elseif ($role === 'program_head') {
        dipascaf_ensure_program_head_assignments($userId);
    } elseif ($role === 'dean') {
        dipascaf_ensure_dean_assignments($userId);
    } elseif ($role === 'vpaa') {
        dipascaf_ensure_vpaa_assignments($userId);
    }
}

function dipascaf_ensure_teacher_peer_assignment(int $teacherUserId): void
{
    admin_ensure_faculty_program_schema();

    $teacher = admin_one(
        'SELECT u.id, u.email, u.department, u.program, f.id AS faculty_id, f.department AS faculty_department, f.program_code
         FROM users u
         LEFT JOIN faculty f ON f.user_id = u.id OR (f.user_id IS NULL AND f.email = u.email)
         WHERE u.id = :id AND u.role = "teacher" AND u.is_active = 1
         LIMIT 1',
        ['id' => $teacherUserId]
    );

    if ($teacher === null) {
        return;
    }

    $teacherFacultyId = (int) ($teacher['faculty_id'] ?? 0);
    $department = trim((string) ($teacher['faculty_department'] ?? $teacher['department'] ?? ''));
    $programCode = trim((string) ($teacher['program_code'] ?? $teacher['program'] ?? ''));
    if ($teacherFacultyId === 0 || ($department === '' && $programCode === '')) {
        return;
    }

    $cycleName = dipascaf_current_cycle_name();

    // Check if already has a peer assignment
    $existingPeer = admin_one(
        'SELECT id FROM peer_assignments
         WHERE cycle_name = :cycle_name
           AND evaluator_user_id = :evaluator_user_id
           AND evaluator_role = "teacher"
           AND assignment_type = "peer"
           AND COALESCE(is_archived, 0) = 0
         LIMIT 1',
        ['cycle_name' => $cycleName, 'evaluator_user_id' => $teacherUserId]
    );

    if ($existingPeer !== null) {
        return;
    }

    $filters = ['f.id <> :faculty_id', 'u.id <> :teacher_user_id', 'u.role = "teacher"', 'u.is_active = 1'];
    $params = [
        'faculty_id' => $teacherFacultyId,
        'teacher_user_id' => $teacherUserId,
    ];

    if ($programCode !== '') {
        $filters[] = '(f.program_code = :faculty_program_code OR u.program = :user_program_code)';
        $params['faculty_program_code'] = $programCode;
        $params['user_program_code'] = $programCode;
    } elseif ($department !== '') {
        $departmentAliases = admin_matching_department_aliases($department);
        if ($departmentAliases === []) {
            $departmentAliases = [$department];
        }

        $departmentPlaceholders = [];
        foreach ($departmentAliases as $index => $alias) {
            $key = 'department_' . $index;
            $departmentPlaceholders[] = ':' . $key;
            $params[$key] = $alias;
        }
        $filters[] = 'f.department IN (' . implode(',', $departmentPlaceholders) . ')';
    }

    $peer = admin_one(
        'SELECT f.id, u.id AS user_id
         FROM faculty f
         JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
         WHERE f.is_active = 1
           AND f.is_archived = 0
           AND ' . implode(' AND ', $filters) . '
           AND LOWER(COALESCE(f.position_title, "")) NOT LIKE "%dean%"
           AND LOWER(COALESCE(f.position_title, "")) NOT LIKE "%program head%"
         ORDER BY RAND()
         LIMIT 1',
        $params
    );

    if ($peer === null) {
        return;
    }

    db()->prepare(
        "INSERT IGNORE INTO peer_assignments
            (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, questionnaire_type, status, assigned_at, deadline)
         VALUES (?, ?, ?, 'teacher', 'peer', 'faculty', 'pending', NOW(), ?)"
    )->execute([
        $cycleName,
        $teacherUserId,
        (int) $peer['id'],
        dipascaf_evaluation_deadline(),
    ]);
}
