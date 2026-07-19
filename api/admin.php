<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/gemini.php';
require_once __DIR__ . '/../includes/openai.php';

require_role('admin_hr');

$type = $_GET['type'] ?? 'stats';
$assistantFallbackAnswer = 'I cannot answer this request because I am only designed to help with system analysis, dashboard guidance, evaluation support, and other PMAS-related functions inside the system.';

header('Content-Type: application/json');

function admin_chatbot_is_pmas_question(string $message): bool
{
    if ($message === '') {
        return true;
    }

    $keywords = [
        'admin', 'ai', 'analysis', 'appraisal', 'assignment', 'chatbot', 'dashboard',
        'dean', 'department', 'evaluation', 'faculty', 'feature', 'form',
        'idea', 'improve', 'improvement', 'insight', 'intervention', 'navigation',
        'pending', 'performance', 'pmas', 'program',
        'questionnaire', 'recommendation', 'report', 'role', 'seminar', 'setting',
        'strength', 'suggest', 'suggestion', 'teacher', 'training', 'user', 'weak',
    ];

    foreach ($keywords as $keyword) {
        if (str_contains($message, $keyword)) {
            return true;
        }
    }

    return false;
}

function admin_chatbot_static_pmas_answer(string $message): string
{
    if (str_contains($message, 'evaluation') || str_contains($message, 'questionnaire') || str_contains($message, 'form')) {
        return 'For evaluation work, use the Evaluation Assignment page to create assignments, manage questionnaires, preview forms, and monitor pending or completed submissions.';
    }

    if (str_contains($message, 'report')) {
        return 'For reports, open the Reports section and choose the report type, filters, and export format you need.';
    }

    if (str_contains($message, 'user') || str_contains($message, 'role') || str_contains($message, 'faculty')) {
        return 'For users and faculty records, use People Management to add accounts, update roles, assign departments or programs, and archive inactive records.';
    }

    if (str_contains($message, 'department') || str_contains($message, 'program')) {
        return 'For department and program concerns, use People Management or the department directory to review leadership assignments, faculty lists, and program details.';
    }

    if (str_contains($message, 'training') || str_contains($message, 'seminar') || str_contains($message, 'weak') || str_contains($message, 'recommendation')) {
        return 'For weak areas and training recommendations, check AI Actions for detected performance patterns and suggested development plans.';
    }

    if (str_contains($message, 'dashboard') || str_contains($message, 'pending')) {
        return 'Use the dashboard Action Center to review priority items such as pending evaluations, overdue tasks, missing assignments, and records that need attention.';
    }

    if (str_contains($message, 'idea') || str_contains($message, 'suggest') || str_contains($message, 'feature') || str_contains($message, 'improve')) {
        return 'One useful PMAS idea is a smart follow-up queue that groups overdue evaluations, missing evaluator assignments, and weak-area training recommendations into one daily action list for Admin/HR.';
    }

    return 'I can help with PMAS dashboard navigation, evaluation assignments, questionnaires, reports, faculty records, weak-area analysis, and training recommendations.';
}

try {
    if ($type === 'stats') {
        echo json_encode(['ok' => true, 'data' => admin_stats()]);
        exit;
    }

    if ($type === 'chatbot') {
        $rawQuestion = trim($_POST['message'] ?? '');
        $question = strtolower($rawQuestion);

        if (!admin_chatbot_is_pmas_question($question)) {
            echo json_encode(['ok' => true, 'answer' => $assistantFallbackAnswer]);
            exit;
        }

        $stats = admin_stats();
        $context = [
            'current_user' => current_user(),
            'dashboard_stats' => $stats,
            'department_weak_areas' => array_slice(admin_department_weak_areas(), 0, 8),
            'priority_interventions' => array_slice(admin_interventions(), 0, 8),
            'recent_evaluations' => $stats['recentEvaluations'] ?? [],
        ];
        // Period comparison query
        if (str_contains($question, 'period') && (str_contains($question, 'compare') || str_contains($question, 'over') || str_contains($question, 'trend') || str_contains($question, 'change'))) {
            $comparison = admin_period_comparison();
            if ($comparison === []) {
                $answer = 'No period comparison data is available yet.';
            } else {
                $lines = ['Period-over-Period System Comparison:'];
                foreach ($comparison as $p) {
                    $change = '';
                    if ($p['change_from_previous'] !== null) {
                        $change = $p['change_from_previous'] > 0 ? ' (+' . $p['change_from_previous'] . '% increase)' : ($p['change_from_previous'] < 0 ? ' (' . $p['change_from_previous'] . '% decline)' : ' (stable)');
                    }
                    $lines[] = '- ' . ($p['period_name'] ?? '') . ': ' . $p['completion_rate'] . '% completion, ' . $p['completed'] . '/' . $p['total_assignments'] . ' submitted, ' . $p['overdue'] . ' overdue' . $change;
                    if ($p['average_score'] !== null) {
                        $lines[] = '  Average score: ' . number_format($p['average_score'], 2) . '/5';
                    }
                    if ($p['weak_areas'] !== []) {
                        $areas = array_map(fn($w) => $w['area'] . ' (' . $w['count'] . 'x)', $p['weak_areas']);
                        $lines[] = '  Top weak areas: ' . implode(', ', $areas);
                    }
                }
                $answer = implode("\n", $lines);
            }
        } elseif (str_contains($question, 'weak') || str_contains($question, 'department')) {
            $weakAreas = admin_department_weak_areas();
            $answer = $weakAreas === []
                ? 'No department weak-area patterns are available yet.'
                : 'Detected weak areas: ' . implode(' | ', array_map(
                fn (array $row): string => $row['department'] . ' / ' . $row['program_code'] . ' - ' . $row['weak_area'] . ' (' . $row['weak_count'] . ')',
                array_slice($weakAreas, 0, 5)
            ));
        } else {
            preg_match('/\b(cite|coed|cba|computer studies|education|business administration)\b/i', $question, $departmentMatch);
            $department = $departmentMatch[1] ?? '';

            if ($question === '') {
                $answer = 'Please type a question about DIPASCAF users, faculty, evaluations, reports, or settings.';
            } elseif (str_contains($question, 'classroom management') || str_contains($question, 'communication') || str_contains($question, 'job commitment')) {
                $area = str_contains($question, 'classroom management') ? 'Classroom Management'
                    : (str_contains($question, 'communication') ? 'Communication Skills' : 'Job Commitment');
                $where = 'WHERE i.weak_area = :area';
                $params = ['area' => $area];
                if ($department !== '') {
                    $where .= ' AND (LOWER(f.department) LIKE :department OR LOWER(f.department) LIKE :department_code)';
                    $params['department'] = '%' . strtolower($department) . '%';
                    $params['department_code'] = strtolower($department) === 'cite' ? '%computer%' : '%' . strtolower($department) . '%';
                }
                $rows = admin_all(
                    "SELECT f.full_name, f.department, COALESCE(NULLIF(f.program_code, ''), 'Unassigned Program') AS program_code, p.recommendation
                     FROM ai_insights i
                     JOIN faculty f ON f.id = i.faculty_id
                     LEFT JOIN intervention_plans p ON p.faculty_id = f.id AND p.weak_area = i.weak_area
                     $where
                     LIMIT 5",
                    $params
                );
                if ($rows === []) {
                    $answer = 'No matching low-score pattern was found for ' . $area . '.';
                } else {
                    $parts = array_map(
                        fn (array $row): string => $row['full_name'] . ' (' . $row['department'] . ' / ' . $row['program_code'] . '): ' . ($row['recommendation'] ?? 'Recommend targeted coaching or training.'),
                        $rows
                    );
                    $answer = $area . ' analysis: ' . implode(' | ', $parts);
                }
            } elseif (str_contains($question, 'pending') || str_contains($question, 'evaluation')) {
                $answer = 'There are ' . $stats['pendingEvaluations'] . ' pending or in-progress evaluations and '
                    . $stats['overdueEvaluations'] . ' overdue evaluations.';
            } elseif (str_contains($question, 'faculty')) {
                $answer = 'DIPASCAF currently has ' . $stats['facultyCount'] . ' faculty records.';
            } elseif (str_contains($question, 'user') || str_contains($question, 'role')) {
                $answer = 'There are ' . $stats['totalUsers'] . ' total users, with ' . $stats['activeUsers']
                    . ' active accounts. User roles are controlled from the Users section.';
            } elseif (str_contains($question, 'report')) {
                $answer = 'Use the Reports section to filter by date range, faculty member, and evaluation type, then download CSV or Excel.';
            } else {
                $answer = 'I can help with DIPASCAF navigation, randomized peer assignments, user roles, faculty records, weak-area analysis, interventions, and report generation.';
            }
        }

        // Always attempt AI enhancement for any answered question
        $openAiAnswer = openai_answer($rawQuestion, $context);
        if ($openAiAnswer !== null) {
            $answer = $openAiAnswer;
        }

        $geminiAnswer = $openAiAnswer === null ? gemini_answer($rawQuestion, $context) : null;
        if ($geminiAnswer !== null) {
            $answer = $geminiAnswer;
        }

        echo json_encode(['ok' => true, 'answer' => $answer, 'stats' => $stats]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown API request.']);
} catch (Throwable $exception) {
    if ($type === 'chatbot') {
        $question = strtolower(trim($_POST['message'] ?? ''));
        $answer = admin_chatbot_is_pmas_question($question)
            ? admin_chatbot_static_pmas_answer($question)
            : $assistantFallbackAnswer;
        echo json_encode(['ok' => true, 'answer' => $answer]);
        exit;
    }

    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to load admin data right now.']);
}
