<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/teacher_data.php';
require_once __DIR__ . '/../includes/vpaa_data.php';
require_once __DIR__ . '/../includes/dean_data.php';
require_once __DIR__ . '/../includes/program_head_data.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/gemini.php';
require_once __DIR__ . '/../includes/openai.php';
require_once __DIR__ . '/../includes/assistant_copilot.php';

$assistantFallbackAnswer = 'I cannot answer this request because I am only designed to help with system analysis, dashboard guidance, evaluation support, and other PMAS-related functions inside the system.';

function assistant_is_pmas_question(string $message): bool
{
    if ($message === '') {
        return true;
    }

    $keywords = [
        'admin', 'ai', 'analysis', 'analytics', 'appraisal', 'assignment', 'chatbot', 'compare', 'comparison',
        'dashboard', 'dean', 'department',
        'evidence', 'evaluation', 'export',
        'faculty', 'feature', 'feedback', 'form', 'form a', 'form b',
        'growth', 'history', 'how',
        'idea', 'improve', 'improvement', 'insight', 'intervention',
        'leadership',
        'navigation',
        'overdue', 'overview',
        'peer', 'pending', 'performance', 'period', 'plan', 'pmas', 'priority', 'program', 'progress',
        'qa', 'q&a', 'question', 'questionnaire',
        'rate', 'rating', 'recommendation', 'report', 'role',
        'score', 'seminar', 'setting', 'status', 'strength', 'submit', 'suggest', 'suggestion', 'summary',
        'teacher', 'top', 'training', 'trend',
        'urgent', 'user',
        'vpaa', 'vice president',
        'weak', 'what', 'why',
    ];

    foreach ($keywords as $keyword) {
        if (str_contains($message, $keyword)) {
            return true;
        }
    }

    return false;
}

function assistant_static_pmas_answer(string $message): string
{
    $lower = strtolower($message);

    if (str_contains($lower, 'weak') || str_contains($lower, 'insight') || str_contains($lower, 'analysis') || str_contains($lower, 'recommendation') || str_contains($lower, 'training') || str_contains($lower, 'seminar')) {
        return 'I can answer that from the AI Analysis data. Ask from your Dean or Program Head dashboard and I will list the weak areas, AI insights, and recommendations within your assigned department or program scope.';
    }

    if (str_contains($lower, 'form a') || str_contains($lower, 'form b')) {
        return "Form A is used for leadership/admin evaluation records, while Form B is used for faculty questionnaire evaluation records. Both forms use categories, question ratings, evidence, computed weighted scores, and a final review summary before submission.";
    }

    if (str_contains($lower, 'behavioral evidence') || str_contains($lower, 'evidence')) {
        return "Behavioral Evidence documents the reason behind a very high or low category score. In PMAS, it helps evaluators justify ratings before moving to the next category or submitting the final review.";
    }

    if (str_contains($lower, 'submit') || str_contains($lower, 'before submission') || str_contains($lower, 'check before')) {
        return "Before submission, check that every question is rated, required behavioral evidence is provided, category weighted scores are reviewed, and the final equivalent rating looks correct.";
    }

    if (str_contains($lower, 'export')) {
        return 'Use the report or monitor export controls to download evaluation summaries for review, documentation, and academic planning.';
    }

    if (str_contains($lower, 'evaluation') || str_contains($lower, 'questionnaire') || str_contains($lower, 'form')) {
        return 'For evaluation work, use the Evaluation Assignment page to create assignments, manage questionnaires, preview forms, and monitor pending or completed submissions.';
    }

    if (str_contains($lower, 'report')) {
        return 'For reports, open the Reports section and choose the report type, filters, and export format you need.';
    }

    if (str_contains($lower, 'user') || str_contains($lower, 'role') || str_contains($lower, 'faculty') || str_contains($lower, 'people')) {
        return 'For users and faculty records, use People Management to add accounts, update roles, assign departments or programs, and archive inactive records.';
    }

    if (str_contains($lower, 'department') || str_contains($lower, 'program') || str_contains($lower, 'leadership')) {
        return 'For department and program concerns, use People Management or the department directory to review leadership assignments, faculty lists, and program details.';
    }

    if (str_contains($lower, 'dashboard') || str_contains($lower, 'pending') || str_contains($lower, 'overdue') || str_contains($lower, 'status')) {
        return 'Use the dashboard Action Center to review priority items such as pending evaluations, overdue tasks, missing assignments, and records that need attention.';
    }

    if (str_contains($lower, 'idea') || str_contains($lower, 'suggest') || str_contains($lower, 'feature') || str_contains($lower, 'improve')) {
        return 'One useful PMAS idea is a smart follow-up queue that groups overdue evaluations, missing evaluator assignments, and weak-area training recommendations into one daily action list for Admin/HR.';
    }

    if (str_contains($lower, 'overview') || str_contains($lower, 'summary') || str_contains($lower, 'analytics') || str_contains($lower, 'insight')) {
        return 'I can pull live analytics from the database - completion rates by department, weak area patterns, evaluation progress, and leadership gaps. Try asking a specific question like "Show completion rates by department" or "What are the top weak areas?"';
    }

    if (str_contains($lower, 'compare') || str_contains($lower, 'top') || str_contains($lower, 'best') || str_contains($lower, 'worst')) {
        return 'I can compare department performance based on evaluation completion rates. Try asking "Compare department completion rates" or "Which department is performing best?"';
    }

    if (str_contains($lower, 'trend') || str_contains($lower, 'progress') || str_contains($lower, 'rate')) {
        return 'I can show evaluation progress and trends. Try asking "What is the overall evaluation progress?" or "Show completion statistics."';
    }

    return 'I can help with PMAS dashboard navigation, evaluation assignments, questionnaires, reports, faculty records, weak-area analysis, and training recommendations. Try asking about analytics, comparisons, or progress.';
}

function assistant_questionnaire_answer(): string
{
    if (!function_exists('dipascaf_ensure_form_a_schema')) {
        require_once __DIR__ . '/../includes/evaluation_cards.php';
    }

    dipascaf_ensure_form_a_schema();
    dipascaf_ensure_form_b_schema();

    $formACategories = admin_count('SELECT COUNT(*) FROM pmas_form_a_categories WHERE is_active = 1');
    $formAQuestions = admin_count('SELECT COUNT(*) FROM pmas_form_a_questions q JOIN pmas_form_a_categories c ON c.id = q.category_id WHERE q.is_active = 1 AND c.is_active = 1');
    $formBCategories = admin_count('SELECT COUNT(*) FROM pmas_form_b_categories WHERE is_active = 1');
    $formBQuestions = admin_count('SELECT COUNT(*) FROM pmas_form_b_questions q JOIN pmas_form_b_categories c ON c.id = q.category_id WHERE q.is_active = 1 AND c.is_active = 1');

    return "Active PMAS questionnaire setup:\n"
        . "- Form A: {$formACategories} active categories, {$formAQuestions} active questions\n"
        . "- Form B: {$formBCategories} active categories, {$formBQuestions} active questions\n"
        . "- Review tip: keep weights balanced, make questions observable, and require evidence when ratings are unusually high or low.";
}

function assistant_mode_label(string $mode): string
{
    return match ($mode) {
        'insights' => 'Insights',
        'actions' => 'Action Plan',
        default => 'Overview',
    };
}

function assistant_enhance_answer(string $answer, string $mode, string $role, string $pagePath, string $userScope): string
{
    $mode = $mode === 'actions' || $mode === 'insights' ? $mode : 'overview';
    $prefix = 'Mode: ' . assistant_mode_label($mode);
    if ($userScope !== '') {
        $prefix .= ' | Scope: ' . $userScope;
    }
    if ($pagePath !== '') {
        $prefix .= ' | Page: ' . $pagePath;
    }

    if ($mode === 'actions') {
        return $prefix . "\n\n" . $answer . "\n\nPrioritized next steps:\n"
            . "- High priority: review overdue, pending, or lowest-scoring records first.\n"
            . "- Medium priority: create or update intervention plans for repeated weak areas.\n"
            . "- Low priority: document strengths and export summaries for coaching records.\n"
            . "- Review checkpoint: confirm evidence, evaluator coverage, and period status before final action.";
    }

    if ($mode === 'insights') {
        return $prefix . "\n\n" . $answer . "\n\nInsight checklist:\n"
            . "- Weak-area pattern: look for categories repeated across multiple faculty or periods.\n"
            . "- Strength signal: preserve high-performing categories as mentoring examples.\n"
            . "- Confidence: stronger when several submitted evaluations point to the same pattern.\n"
            . "- Follow-up: ask for an action plan to convert this into interventions.";
    }

    return $prefix . "\n\n" . $answer;
}

function assistant_analytics_answer(string $message, string $role): string
{
    $lower = strtolower($message);

    if (str_contains($lower, 'question') || str_contains($lower, 'questionnaire') || str_contains($lower, 'form a') || str_contains($lower, 'form b')) {
        return assistant_questionnaire_answer();
    }

    if (str_contains($lower, 'behavioral evidence') || (str_contains($lower, 'evidence') && str_contains($lower, 'required'))) {
        return "Behavioral Evidence is required when a category rating needs justification.\n"
            . "- It explains what behavior, output, or incident supports the score.\n"
            . "- It helps reviewers confirm that high and low ratings are fair.\n"
            . "- Evaluators should complete it before moving to the next category when the form flags it.";
    }

    if (str_contains($lower, 'before closing') || str_contains($lower, 'before submission') || str_contains($lower, 'check before') || str_contains($lower, 'close a period')) {
        $progress = admin_evaluation_progress_summary();
        $leadership = admin_unassigned_leadership();
        return "Before closing or submitting PMAS records, check these items:\n"
            . "- Completion: {$progress['completedEvaluations']} completed, {$progress['pendingEvaluations']} pending, {$progress['completionRate']}% complete\n"
            . "- Leadership: {$leadership['unassigned_deans_count']} departments need dean, {$leadership['unassigned_heads_count']} programs need head\n"
            . "- Forms: all required ratings and behavioral evidence must be complete\n"
            . "- Review: confirm final scores, equivalent ratings, and evidence status in the summary.";
    }

    if (str_contains($lower, 'overdue') || str_contains($lower, 'due')) {
        $completion = admin_completion_by_department();
        usort($completion, static fn (array $a, array $b): int => ((int) ($b['overdue'] ?? 0)) <=> ((int) ($a['overdue'] ?? 0)));
        $lines = ['Departments with overdue or due attention:'];
        foreach (array_slice($completion, 0, 5) as $dept) {
            $lines[] = '- ' . ($dept['department'] ?? 'Unknown') . ': ' . (int) ($dept['overdue'] ?? 0) . ' overdue, ' . (int) ($dept['pending'] ?? 0) . ' pending, ' . ($dept['completion_pct'] ?? 0) . '% complete';
        }
        return implode("\n", $lines);
    }

    if (str_contains($lower, 'compare') || (str_contains($lower, 'period') && (str_contains($lower, 'over') || str_contains($lower, 'change') || str_contains($lower, 'across') || str_contains($lower, 'trend')))) {
        $comparison = admin_period_comparison();
        if ($comparison === []) {
            return 'No period comparison data is available yet.';
        }
        $lines = ['📊 Period-over-Period System Comparison:'];
        foreach ($comparison as $p) {
            $change = '';
            if ($p['change_from_previous'] !== null) {
                $change = $p['change_from_previous'] > 0 ? ' (▲ +' . $p['change_from_previous'] . '%)' : ($p['change_from_previous'] < 0 ? ' (▼ ' . $p['change_from_previous'] . '%)' : ' (stable)');
            }
            $lines[] = '- ' . ($p['period_name'] ?? '') . ': ' . $p['completion_rate'] . '% complete (' . $p['completed'] . '/' . $p['total_assignments'] . '), overdue: ' . $p['overdue'] . ', avg score: ' . ($p['average_score'] !== null ? number_format($p['average_score'], 2) : 'N/A') . $change;
            if ($p['weak_areas'] !== []) {
                $areas = array_map(fn($w) => $w['area'] . ' (' . $w['count'] . 'x)', $p['weak_areas']);
                $lines[] = '  Weak areas: ' . implode(', ', $areas);
            }
        }
        return implode("\n", $lines);
    }

    if (str_contains($lower, 'compar') || (str_contains($lower, 'department') && (str_contains($lower, 'best') || str_contains($lower, 'worst') || str_contains($lower, 'top') || str_contains($lower, 'rank')))) {
        $comparison = admin_department_comparison();
        $lines = ['Department Comparison:'];
        foreach ($comparison['departments'] as $dept) {
            $lines[] = '- ' . ($dept['department'] ?? 'Unknown') . ' - ' . ($dept['completion_pct'] ?? '0') . '% complete (' . ($dept['submitted'] ?? 0) . '/' . ($dept['total_assignments'] ?? 0) . ' submitted, ' . ($dept['overdue'] ?? 0) . ' overdue)';
        }
        if ($comparison['bestPerforming'] && $comparison['worstPerforming']) {
            $lines[] = '';
            $lines[] = 'Best: ' . $comparison['bestPerforming']['department'] . ' at ' . $comparison['bestPerforming']['completion_pct'] . '%';
            $lines[] = 'Needs attention: ' . $comparison['worstPerforming']['department'] . ' at ' . $comparison['worstPerforming']['completion_pct'] . '%';
        }
        $lines[] = 'Average across all departments: ' . $comparison['averageCompletionRate'] . '%';
        return implode("\n", $lines);
    }

    if (str_contains($lower, 'complete') || str_contains($lower, 'rate') || str_contains($lower, 'progress') || (str_contains($lower, 'department') && str_contains($lower, 'status'))) {
        $completion = admin_completion_by_department();
        $progress = admin_evaluation_progress_summary();
        $lines = ['Evaluation Progress Overview:'];
        $lines[] = '- Overall completion rate: ' . $progress['completionRate'] . '% (' . $progress['completedEvaluations'] . '/' . $progress['totalEvaluations'] . ' evaluations completed)';
        $lines[] = '- Pending: ' . $progress['pendingEvaluations'] . ' | Open appraisal periods: ' . $progress['openAppraisalPeriods'];
        $lines[] = '- Faculty with completed evaluations: ' . $progress['facultyWithCompletedEvaluations'] . '/' . $progress['totalFaculty'] . ' (' . $progress['facultyEvaluationRate'] . '%)';
        $lines[] = '';
        $lines[] = 'By Department:';
        foreach ($completion as $dept) {
            $lines[] = '- ' . ($dept['department'] ?? 'Unknown') . ' - ' . ($dept['completion_pct'] ?? '0') . '% (' . ($dept['submitted'] ?? 0) . '/' . ($dept['total_assignments'] ?? 0) . ')';
        }
        $totalOverdue = array_sum(array_map(fn ($d) => (int) ($d['overdue'] ?? 0), $completion));
        if ($totalOverdue > 0) {
            $lines[] = '';
            $lines[] = 'Warning: ' . $totalOverdue . ' overdue evaluations need immediate attention.';
        }
        return implode("\n", $lines);
    }

    if (str_contains($lower, 'weak') || str_contains($lower, 'pattern') || (str_contains($lower, 'area') && str_contains($lower, 'top'))) {
        $patterns = admin_weak_area_patterns();
        $areas = admin_department_weak_areas();
        if ($patterns === []) {
            return 'No weak-area patterns are available yet. Weak areas are generated after evaluations are completed and AI insights are processed.';
        }
        $lines = ['System-wide Weak Area Patterns:'];
        foreach ($patterns as $i => $p) {
            if ($i >= 5) break;
            $lines[] = '- ' . ($p['weak_area'] ?? 'Unknown') . ' - affected ' . ($p['affected_faculty_count'] ?? 0) . ' faculty across ' . ($p['affected_departments'] ?? 0) . ' departments';
        }
        if ($areas !== []) {
            $lines[] = '';
            $lines[] = 'By department / program:';
            foreach (array_slice($areas, 0, 5) as $a) {
                $lines[] = '- ' . ($a['department'] ?? '') . '/' . ($a['program_code'] ?? '') . ' - ' . ($a['weak_area'] ?? '') . ' (' . ($a['weak_count'] ?? 0) . ' occurrences)';
            }
        }
        return implode("\n", $lines);
    }

    if (str_contains($lower, 'leadership') || str_contains($lower, 'unassigned') || str_contains($lower, 'dean') || str_contains($lower, 'head') || (str_contains($lower, 'missing') && str_contains($lower, 'assign'))) {
        $leadership = admin_unassigned_leadership();
        $lines = ['Leadership Status:'];
        if ($leadership['unassigned_deans_count'] > 0) {
            $lines[] = '- Departments without a dean (' . $leadership['unassigned_deans_count'] . '):';
            foreach ($leadership['departments_without_dean'] as $d) {
                $lines[] = '  - ' . ($d['department_name'] ?? '') . ' (' . ($d['department_code'] ?? '') . ')';
            }
        } else {
            $lines[] = '- All departments have a dean assigned.';
        }
        if ($leadership['unassigned_heads_count'] > 0) {
            $lines[] = '- Programs without a head (' . $leadership['unassigned_heads_count'] . '):';
            foreach ($leadership['programs_without_head'] as $p) {
                $lines[] = '  - ' . ($p['program_name'] ?? '') . ' (' . ($p['program_code'] ?? '') . ') - ' . ($p['department_name'] ?? '');
            }
        } else {
            $lines[] = '- All programs have a head assigned.';
        }
        return implode("\n", $lines);
    }

    if (str_contains($lower, 'insight') || (str_contains($lower, 'ai') && (str_contains($lower, 'action') || str_contains($lower, 'detect') || str_contains($lower, 'summar')))) {
        $insights = admin_ai_insights();
        if ($insights === []) {
            return 'No AI insights have been generated yet. Insights appear after evaluation results are processed by the AI analysis engine.';
        }
        $lines = ['Recent AI Insights (' . count($insights) . ' total):'];
        foreach ($insights as $insight) {
            $lines[] = '- ' . ($insight['faculty_name'] ?? 'Unknown') . ' - ' . ($insight['weak_area'] ?? '') . ' (' . ($insight['department'] ?? '') . ')';
        }
        return implode("\n", $lines);
    }

    if (str_contains($lower, 'training') || str_contains($lower, 'seminar') || str_contains($lower, 'intervention') || str_contains($lower, 'development')) {
        $plans = admin_interventions();
        if ($plans === []) {
            return 'No intervention plans are currently assigned. Faculty development plans are created after weak areas are identified through AI analysis.';
        }
        $pending = array_filter($plans, fn ($p) => in_array($p['status'] ?? '', ['planned', 'assigned'], true));
        $completed = array_filter($plans, fn ($p) => ($p['status'] ?? '') === 'completed');
        $lines = ['Intervention Plans:'];
        $lines[] = '- Active plans: ' . count($pending) . ' | Completed: ' . count($completed);
        $lines[] = '';
        foreach (array_slice($pending, 0, 5) as $plan) {
            $lines[] = '- ' . ($plan['faculty_name'] ?? '') . ' - ' . ($plan['recommendation'] ?? '') . ' (' . admin_status_label($plan['status'] ?? '') . ')';
        }
        return implode("\n", $lines);
    }

    $stats = admin_stats();
    $progress = admin_evaluation_progress_summary();
    $leadership = admin_unassigned_leadership();
    $lines = ['System Analytics Overview'];
    $lines[] = '--------------------';
    $lines[] = '- Users: ' . $stats['totalUsers'] . ' total (' . $stats['activeUsers'] . ' active)';
    $lines[] = '- Faculty: ' . $stats['facultyCount'] . ' records';
    $lines[] = '- Evaluations: ' . $stats['completedEvaluations'] . ' completed / ' . $stats['pendingEvaluations'] . ' pending / ' . $stats['overdueEvaluations'] . ' overdue';
    $lines[] = '- Completion rate: ' . $progress['completionRate'] . '%';
    $lines[] = '- AI Insights: ' . $stats['aiInsightCount'] . ' recorded';
    $lines[] = '- Active interventions: ' . $stats['activeInterventions'];
    if ($leadership['unassigned_deans_count'] > 0 || $leadership['unassigned_heads_count'] > 0) {
        $lines[] = '- Leadership gaps: ' . $leadership['unassigned_deans_count'] . ' depts need dean, ' . $leadership['unassigned_heads_count'] . ' programs need head';
    }
    $lines[] = '';
    $lines[] = 'Try asking: "Compare departments", "Show weak areas", "Completion by department", or "Leadership status".';
    return implode("\n", $lines);
}

function assistant_dean_analytics(int $deanUserId, string $message): string
{
    $departments = dean_departments($deanUserId);
    $lower = strtolower($message);

    if (str_contains($lower, 'compare') || str_contains($lower, 'period') || (str_contains($lower, 'trend') && str_contains($lower, 'department'))) {
        $comparison = dean_period_comparison($departments);
        if ($comparison === []) {
            return 'No period comparison data is available yet for your department(s).';
        }
        $lines = ['📊 Period-over-Period Department Comparison (' . implode(', ', $departments) . '):'];
        foreach ($comparison as $p) {
            $change = '';
            if ($p['score_change'] !== null) {
                $change = $p['score_change'] > 0 ? ' (▲ +' . $p['score_change'] . ')' : ($p['score_change'] < 0 ? ' (▼ ' . $p['score_change'] . ')' : ' (stable)');
            }
            $lines[] = '- ' . ($p['period_name'] ?? '') . ': ' . $p['completion_rate'] . '% complete (' . $p['submitted'] . '/' . $p['total'] . '), avg score: ' . ($p['average_score'] ?? 'N/A') . $change;
            if ($p['weak_areas'] !== []) {
                $areas = array_map(fn($w) => $w['area'] . ' (' . $w['count'] . 'x)', $p['weak_areas']);
                $lines[] = '  Weak areas: ' . implode(', ', $areas);
            }
        }
        return implode("\n", $lines);
    }

    if (str_contains($lower, 'summary') || str_contains($lower, 'overview') || str_contains($lower, 'analytics')) {
        $summary = dean_summary($departments);
        $lines = ['Your Department Overview (' . implode(', ', $departments) . '):'];
        $lines[] = '- Faculty count: ' . $summary['facultyCount'];
        $lines[] = '- Evaluations submitted: ' . $summary['submitted'];
        $lines[] = '- Evaluations pending: ' . $summary['pending'];
        $total = $summary['submitted'] + $summary['pending'];
        $rate = $total > 0 ? round(($summary['submitted'] / $total) * 100) : 0;
        $lines[] = '- Completion rate: ' . $rate . '%';
        if ($summary['weakAreas'] !== []) {
            $lines[] = '';
            $lines[] = 'Weak areas detected:';
            foreach (array_slice($summary['weakAreas'], 0, 5) as $wa) {
                $lines[] = '- ' . ($wa['program_code'] ?? 'N/A') . ' - ' . ($wa['weak_area'] ?? '') . ' (' . ($wa['weak_count'] ?? 0) . ')';
            }
        }
        return implode("\n", $lines);
    }

    if (str_contains($lower, 'weak') || str_contains($lower, 'area') || str_contains($lower, 'pattern')) {
        $summary = dean_summary($departments);
        if ($summary['weakAreas'] === []) {
            return 'No weak areas detected for your departments yet.';
        }
        $lines = ['Weak Areas in Your Department(s):'];
        foreach ($summary['weakAreas'] as $wa) {
            $lines[] = '- ' . ($wa['program_code'] ?? '') . ' - ' . ($wa['weak_area'] ?? '') . ' (' . ($wa['weak_count'] ?? 0) . ' faculty affected)';
        }
        return implode("\n", $lines);
    }

    if (str_contains($lower, 'insight') || str_contains($lower, 'ai')) {
        $insights = dean_ai_insights($departments);
        if ($insights === []) {
            return 'No AI insights available for your departments yet.';
        }
        $lines = ['AI Insights for Your Departments:'];
        foreach (array_slice($insights, 0, 5) as $insight) {
            $lines[] = '- ' . ($insight['faculty_name'] ?? '') . ' (' . ($insight['program_code'] ?? '') . ') - ' . ($insight['weak_area'] ?? '');
        }
        return implode("\n", $lines);
    }

    if (str_contains($lower, 'training') || str_contains($lower, 'intervention') || str_contains($lower, 'seminar')) {
        $interventions = dean_interventions($departments);
        if ($interventions === []) {
            return 'No intervention plans for your departments yet.';
        }
        $lines = ['Department Intervention Plans:'];
        foreach (array_slice($interventions, 0, 5) as $plan) {
            $lines[] = '- ' . ($plan['faculty_name'] ?? '') . ' - ' . ($plan['recommendation'] ?? '');
        }
        return implode("\n", $lines);
    }

    $summary = dean_summary($departments);
    $total = $summary['submitted'] + $summary['pending'];
    $rate = $total > 0 ? round(($summary['submitted'] / $total) * 100) : 0;
    return 'Your departments have ' . $summary['facultyCount'] . ' faculty members. Evaluation progress: ' . $summary['submitted'] . ' submitted, ' . $summary['pending'] . ' pending (' . $rate . '% completion). Try "Show weak areas", "Department insights", or "Training plans".';
}

function assistant_program_head_analytics(int $programHeadUserId, string $message): string
{
    $programs = program_head_programs($programHeadUserId);
    $departments = program_head_departments($programHeadUserId);
    $lower = strtolower($message);

    if (str_contains($lower, 'compare') || str_contains($lower, 'trend') || (str_contains($lower, 'period') && (str_contains($lower, 'over') || str_contains($lower, 'change') || str_contains($lower, 'across')))) {
        $comparison = program_head_period_comparison($departments, $programs);
        if ($comparison === []) {
            return 'No period comparison data is available yet for your assigned program.';
        }
        $lines = ['Program period-over-period comparison:'];
        foreach ($comparison as $p) {
            $change = '';
            if ($p['score_change'] !== null) {
                $change = $p['score_change'] > 0 ? ' (up +' . $p['score_change'] . ')' : ($p['score_change'] < 0 ? ' (down ' . $p['score_change'] . ')' : ' (stable)');
            }
            $lines[] = '- ' . ($p['period_name'] ?? '') . ': ' . $p['completion_rate'] . '% complete (' . $p['submitted'] . '/' . $p['total'] . '), avg score: ' . ($p['average_score'] ?? 'N/A') . $change;
            if ($p['weak_areas'] !== []) {
                $areas = array_map(fn ($w) => $w['area'] . ' (' . $w['count'] . 'x)', $p['weak_areas']);
                $lines[] = '  Weak areas: ' . implode(', ', $areas);
            }
        }
        return implode("\n", $lines);
    }

    if (str_contains($lower, 'summary') || str_contains($lower, 'overview') || str_contains($lower, 'analytics')) {
        $summary = program_head_summary($programHeadUserId, $departments, $programs);
        $programNames = array_map(fn ($p) => $p['program_name'] ?? '', $programs);
        $lines = ['Your Program Overview (' . implode(', ', $programNames) . '):'];
        $lines[] = '- Faculty count: ' . $summary['facultyCount'];
        $lines[] = '- Evaluations submitted: ' . $summary['submitted'];
        $lines[] = '- Evaluations pending: ' . $summary['pending'];
        $total = $summary['submitted'] + $summary['pending'];
        $rate = $total > 0 ? round(($summary['submitted'] / $total) * 100) : 0;
        $lines[] = '- Completion rate: ' . $rate . '%';
        if ($summary['weakAreas'] !== []) {
            $lines[] = '';
            $lines[] = 'Weak areas detected:';
            foreach (array_slice($summary['weakAreas'], 0, 5) as $wa) {
                $lines[] = '- ' . ($wa['program_code'] ?? '') . ' - ' . ($wa['weak_area'] ?? '') . ' (' . ($wa['weak_count'] ?? 0) . ')';
            }
        }
        return implode("\n", $lines);
    }

    if (str_contains($lower, 'weak') || str_contains($lower, 'area')) {
        $summary = program_head_summary($programHeadUserId, $departments, $programs);
        if ($summary['weakAreas'] === []) {
            return 'No weak areas detected for your programs yet.';
        }
        $lines = ['Weak Areas in Your Program(s):'];
        foreach ($summary['weakAreas'] as $wa) {
            $lines[] = '- ' . ($wa['program_code'] ?? '') . ' - ' . ($wa['weak_area'] ?? '') . ' (' . ($wa['weak_count'] ?? 0) . ' faculty affected)';
        }
        return implode("\n", $lines);
    }

    $summary = program_head_summary($programHeadUserId, $departments, $programs);
    $total = $summary['submitted'] + $summary['pending'];
    $rate = $total > 0 ? round(($summary['submitted'] / $total) * 100) : 0;
    return 'Your program(s) have ' . $summary['facultyCount'] . ' faculty. Evaluation progress: ' . $summary['submitted'] . ' submitted, ' . $summary['pending'] . ' pending (' . $rate . '% completion). Try "Show weak areas" or "Program overview".';
}

function assistant_vpaa_analytics(int $vpaaUserId, string $message): string
{
    $departments = vpaa_departments($vpaaUserId);
    $summary = vpaa_summary($departments);
    $performance = vpaa_department_performance($departments);
    $assignments = vpaa_assignments($departments);
    $weakAreas = vpaa_weak_areas($departments);
    $interventions = vpaa_interventions($departments);
    $lower = strtolower($message);

    if (str_contains($lower, 'compare') || str_contains($lower, 'trend') || (str_contains($lower, 'period') && (str_contains($lower, 'over') || str_contains($lower, 'change') || str_contains($lower, 'across')))) {
        $comparison = vpaa_period_comparison($departments);
        if ($comparison === []) {
            return 'No period comparison data is available yet for your assigned departments.';
        }
        $lines = ['📊 Period-over-Period VPAA Comparison:'];
        foreach ($comparison as $p) {
            $change = '';
            if ($p['score_change'] !== null) {
                $change = $p['score_change'] > 0 ? ' (▲ +' . $p['score_change'] . ')' : ($p['score_change'] < 0 ? ' (▼ ' . $p['score_change'] . ')' : ' (stable)');
            }
            $lines[] = '- ' . ($p['period_name'] ?? '') . ': ' . $p['completion_rate'] . '% complete (' . $p['completed'] . '/' . $p['total_assignments'] . '), avg score: ' . ($p['average_score'] ?? 'N/A') . $change;
            if ($p['weak_areas'] !== []) {
                $areas = array_map(fn($w) => $w['area'] . ' (' . $w['count'] . 'x)', $p['weak_areas']);
                $lines[] = '  Weak areas: ' . implode(', ', $areas);
            }
        }
        return implode("\n", $lines);
    }

    if (str_contains($lower, 'lowest') || str_contains($lower, 'weak department')) {
        usort($performance, static fn (array $a, array $b): int => ($a['averageRating'] ?? 999) <=> ($b['averageRating'] ?? 999));
        $row = $performance[0] ?? null;
        return $row
            ? 'The lowest scoring department is ' . $row['department'] . ' with an average rating of ' . ($row['averageRating'] ?? 'N/A') . ' and ' . $row['completion'] . '% completion.'
            : 'No completed department ratings are available yet.';
    }

    if (str_contains($lower, 'pending')) {
        $pending = array_values(array_filter($assignments, static fn (array $row): bool => (string) ($row['status'] ?? '') !== 'submitted'));
        $lines = ['Pending evaluations: ' . count($pending)];
        foreach (array_slice($pending, 0, 8) as $row) {
            $lines[] = '- ' . ($row['faculty_name'] ?? 'Faculty') . ' in ' . ($row['department'] ?? '') . ' assigned to ' . ($row['evaluator_name'] ?? 'Unassigned');
        }
        return implode("\n", $lines);
    }

    if (str_contains($lower, 'intervention') || str_contains($lower, 'need')) {
        $active = array_values(array_filter($interventions, static fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['planned', 'assigned'], true)));
        if ($active === []) {
            return 'No active intervention plans are currently assigned in your VPAA department scope.';
        }
        $lines = ['Faculty needing intervention:'];
        foreach (array_slice($active, 0, 8) as $plan) {
            $lines[] = '- ' . ($plan['faculty_name'] ?? '') . ' (' . ($plan['department'] ?? '') . ') - ' . ($plan['recommendation'] ?? '');
        }
        return implode("\n", $lines);
    }

    if (str_contains($lower, 'weak')) {
        if ($weakAreas === []) {
            return 'No weak areas have been detected in your VPAA department scope yet.';
        }
        $counts = [];
        foreach ($weakAreas as $weakArea) {
            $key = (string) ($weakArea['weak_area'] ?? 'Unspecified');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);
        $lines = ['Most common weak categories:'];
        foreach (array_slice($counts, 0, 6, true) as $area => $count) {
            $lines[] = '- ' . $area . ': ' . $count . ' occurrence(s)';
        }
        return implode("\n", $lines);
    }

    $lines = ['VPAA Summary for ' . implode(', ', $departments) . ':'];
    $lines[] = '- Total evaluations: ' . $summary['totalEvaluations'];
    $lines[] = '- Completed: ' . $summary['completedEvaluations'] . ' | Pending: ' . $summary['pendingEvaluations'] . ' | Overdue: ' . $summary['overdueEvaluations'];
    $lines[] = '- Completion rate: ' . $summary['completionRate'] . '%';
    $lines[] = '- Average faculty rating: ' . ($summary['averageFacultyRating'] === null ? 'N/A' : $summary['averageFacultyRating'] . '/5');
    $lines[] = '- Active intervention records: ' . $summary['interventionPlans'];
    return implode("\n", $lines);
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$isAllowedDevOrigin = (bool) preg_match('#^http://(localhost|127\.0\.0\.1):\d+$#', $origin);

if ($isAllowedDevOrigin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Vary: Origin');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$user = current_user();
if ($user === null) {
    $previewRoles = [
        'admin' => 'admin_hr',
        'vpaa' => 'vpaa',
        'dean' => 'dean',
        'programHead' => 'program_head',
        'program_head' => 'program_head',
        'program-head' => 'program_head',
        'faculty' => 'teacher',
        'teacher' => 'teacher',
    ];
    $requestedRole = $_POST['role_key'] ?? '';
    $isLocalhost = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true);

    if ($isLocalhost && isset($previewRoles[$requestedRole])) {
        $user = [
            'id' => 0,
            'full_name' => $_POST['role_name'] ?? 'DIPASCAF User',
            'email' => '',
            'role' => $previewRoles[$requestedRole],
            'profile_image' => null,
        ];
    } else {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'answer' => 'Please log in to use the DIPASCAF assistant.']);
        exit;
    }
}

header('Content-Type: application/json');

try {
    $rawMessage = trim($_POST['message'] ?? '');
    $message = strtolower($rawMessage);
    $role = $user['role'] ?? '';
    $assistantMode = strtolower(trim((string) ($_POST['assistant_mode'] ?? 'overview')));
    if (!in_array($assistantMode, ['overview', 'compare', 'explain', 'risk', 'draft', 'insights', 'actions'], true)) {
        $assistantMode = 'overview';
    }
    $pagePath = trim((string) ($_POST['page_path'] ?? ''));
    $selectedPeriod = trim((string) ($_POST['selected_period'] ?? ''));
    if ($selectedPeriod !== '' && admin_one('SELECT id FROM appraisal_periods WHERE period_name = :period LIMIT 1', ['period' => $selectedPeriod]) === null) {
        $selectedPeriod = '';
    }
    $serverScope = assistant_copilot_scope($user);
    $userScope = trim(implode(' / ', array_filter([$serverScope['department'], $serverScope['program']]))) ?: $serverScope['kind'];
    $recentMessages = json_decode((string) ($_POST['recent_messages'] ?? '[]'), true);
    if (!is_array($recentMessages)) {
        $recentMessages = [];
    }

    if (!assistant_is_pmas_question($message)) {
        echo json_encode(['ok' => true, 'answer' => $assistantFallbackAnswer]);
        exit;
    }

    $answer = 'I can help with evaluation tasks, low-rated areas, strengths, training recommendations, reports, and appraisal progress.';
    $answerFromLiveData = false;
    $context = [
        'current_user' => [
            'name' => $user['full_name'] ?? '',
            'role' => $role,
        ],
        'assistant_mode' => $assistantMode,
        'page_path' => $pagePath,
        'user_scope' => $userScope,
        'recent_conversation' => array_slice($recentMessages, -6),
    ];

    if ($role === 'teacher') {
        $faculty = teacher_user_faculty((int) $user['id']);
        $answerFromLiveData = true;
        if (!$faculty) {
            $answer = 'Your teacher account is not linked to a faculty record yet. Ask Admin/HR to match your account email with your faculty profile.';
            $context['teacher_profile'] = 'not linked to a faculty record';
        } else {
            $scores = teacher_factor_scores((int) $faculty['id']);
            $weightedTotal = (float) ($scores['_weightedTotal'] ?? 0);
            unset($scores['_weightedTotal']);
            $feedback = teacher_generated_feedback($scores);
            $context['teacher_profile'] = [
                'faculty_name' => $faculty['full_name'] ?? '',
                'department' => $faculty['department'] ?? '',
                'position' => $faculty['position_title'] ?? '',
                'weighted_total_percent' => $weightedTotal,
                'factor_scores' => array_values(array_filter($scores, 'is_array')),
                'generated_feedback' => $feedback,
                'recommendations' => teacher_recommendations((int) $faculty['id']),
                'trend' => teacher_trend((int) $faculty['id']),
            ];

            if (str_contains($message, 'strength') || str_contains($message, 'strong')) {
                $answer = 'Your strongest area is ' . $feedback['strength'] . '. Keep documenting effective practices so they can be recognized in future appraisals.';
            } elseif (str_contains($message, 'weak') || str_contains($message, 'low') || str_contains($message, 'improve')) {
                $answer = 'Your lowest-rated area is ' . $feedback['weakness'] . '. In simple terms, this is the area where evaluators saw the most room for improvement.';
            } elseif (str_contains($message, 'seminar') || str_contains($message, 'training') || str_contains($message, 'recommend') || str_contains($message, 'develop')) {
                $plans = teacher_recommendations((int) $faculty['id']);
                $answer = $plans === []
                    ? 'Recommended development activities include lesson planning workshops, classroom management seminars, communication skills training, institutional participation, and teamwork coaching.'
                    : 'Recommended activity: ' . $plans[0]['recommendation'] . ' (' . admin_status_label($plans[0]['action_type']) . ').';
            } elseif (str_contains($message, 'compare') || str_contains($message, 'improve') || str_contains($message, 'growth') || str_contains($message, 'change')) {
                $trend = teacher_trend((int) $faculty['id']);
                $categoryComp = teacher_category_comparison((int) $faculty['id']);

                if (count($trend) >= 2) {
                    $parts = ['📊 Period-over-Period Comparison:'];
                    $previousScore = null;
                    foreach ($trend as $t) {
                        $score = (float) ($t['average_score'] ?? 0);
                        $change = '';
                        if ($previousScore !== null) {
                            $diff = round($score - $previousScore, 2);
                            $change = $diff > 0 ? ' (+' . $diff . ' ▲)' : ($diff < 0 ? ' (' . $diff . ' ▼)' : ' (no change)');
                        }
                        $parts[] = '- ' . ($t['cycle_name'] ?? 'Unknown') . ': ' . number_format($score, 2) . '/5' . $change . ' (' . ($t['submission_count'] ?? 0) . ' submissions)';
                        $previousScore = $score;
                    }
                    $parts[] = '';

                    // Category-level detail
                    $comp = $categoryComp['comparison'] ?? [];
                    if ($comp !== [] && $comp['categories'] !== []) {
                        $summary = $comp['summary'];
                        $parts[] = '📋 Category-Level Breakdown (' . ($comp['periodA'] ?? 'Earlier') . ' → ' . ($comp['periodB'] ?? 'Latest') . '):';
                        $parts[] = 'Improved: ' . $summary['improved'] . ' | Declined: ' . $summary['declined'] . ' | Stable: ' . $summary['stable'] . ' | New: ' . $summary['new'];
                        $parts[] = '';

                        // Show top improvements (up to 3)
                        $improved = array_filter($comp['categories'], fn($c) => $c['direction'] === 'improved');
                        if (count($improved) > 0) {
                            $parts[] = '✅ Where you improved:';
                            $count = 0;
                            foreach ($improved as $cat) {
                                if ($count >= 3) break;
                                $parts[] = '  - ' . $cat['category'] . ' (' . number_format($cat['periodA'], 2) . ' → ' . number_format($cat['periodB'], 2) . ', +' . number_format($cat['change'], 2) . ')';
                                $count++;
                            }
                        }

                        // Show declines
                        $declined = array_filter($comp['categories'], fn($c) => $c['direction'] === 'declined');
                        if (count($declined) > 0) {
                            $parts[] = '⚠️ Areas needing attention:';
                            $count = 0;
                            foreach ($declined as $cat) {
                                if ($count >= 3) break;
                                $parts[] = '  - ' . $cat['category'] . ' (' . number_format($cat['periodA'], 2) . ' → ' . number_format($cat['periodB'], 2) . ', ' . number_format($cat['change'], 2) . ')';
                                $count++;
                            }
                        }
                        $parts[] = '';
                    }

                    $parts[] = 'Overall weighted score: ' . number_format($weightedTotal, 2) . '%.';
                    $answer = implode("\n", $parts);
                } elseif (count($trend) === 1) {
                    $t = $trend[0];
                    $answer = 'You have data for one period so far (' . ($t['cycle_name'] ?? '') . ': ' . number_format((float) ($t['average_score'] ?? 0), 2) . '/5). More periods are needed to show improvement trends. Overall weighted score: ' . number_format($weightedTotal, 2) . '%.';
                } else {
                    $answer = 'Trend analysis will appear after you receive evaluations across multiple appraisal periods.';
                }
            } elseif (str_contains($message, 'trend') || str_contains($message, 'progress') || str_contains($message, 'history')) {
                $trend = teacher_trend((int) $faculty['id']);
                $latest = end($trend);
                if ($latest && count($trend) >= 2) {
                    $earliest = $trend[0];
                    $latestScore = (float) ($latest['average_score'] ?? 0);
                    $earliestScore = (float) ($earliest['average_score'] ?? 0);
                    $improvement = round($latestScore - $earliestScore, 2);
                    $direction = $improvement > 0 ? 'improved by ' . $improvement . ' points' : ($improvement < 0 ? 'declined by ' . abs($improvement) . ' points' : 'remained stable');
                    $answer = 'Over ' . count($trend) . ' period(s), your average rating has ' . $direction
                        . '. Latest period: ' . $latest['cycle_name'] . ' (' . $latestScore . '/5).'
                        . ' Overall weighted score: ' . number_format($weightedTotal, 2) . '%'
                        . '. Use "compare periods" for a detailed breakdown.';
                } elseif ($latest) {
                    $answer = 'Your latest appraisal period: ' . $latest['cycle_name'] . ' with an average rating of ' . $latest['average_score'] . '. Overall weighted score: ' . number_format($weightedTotal, 2) . '%.';
                } else {
                    $answer = 'Trend analysis will appear after you receive evaluations across appraisal periods.';
                }
            } elseif (str_contains($message, 'overview') || str_contains($message, 'summary') || str_contains($message, 'analytics') || str_contains($message, 'feedback') || str_contains($message, 'score') || str_contains($message, 'rating')) {
                $trend = teacher_trend((int) $faculty['id']);
                $latest = end($trend);
                $parts = ['Your Performance Summary:'];
                $parts[] = '- Weighted total: ' . number_format($weightedTotal, 2) . '%';
                if ($latest) {
                    $parts[] = '- Latest period: ' . $latest['cycle_name'] . ' - avg rating: ' . $latest['average_score'];
                }
                $parts[] = '- Strength: ' . $feedback['strength'];
                $parts[] = '- Growth area: ' . $feedback['weakness'];
                if ($feedback['suggestions'] !== []) {
                    $parts[] = '- Suggestions: ' . implode(', ', $feedback['suggestions']);
                }
                $answer = implode("\n", $parts);
            } else {
                // Default includes a period comparison hint
                $trend = teacher_trend((int) $faculty['id']);
                $periodHint = '';
                if (count($trend) >= 2) {
                    $latest = end($trend);
                    $earliest = $trend[0];
                    $diff = round((float) ($latest['average_score'] ?? 0) - (float) ($earliest['average_score'] ?? 0), 2);
                    $periodHint = ' Over ' . count($trend) . ' period(s), your rating has ' . ($diff > 0 ? 'improved by ' . $diff : ($diff < 0 ? 'changed by ' . $diff : 'remained stable')) . '. Try "compare periods" for details.';
                }
                $answer = $feedback['summary'] . ' Suggested next steps: ' . implode(', ', $feedback['suggestions']) . '.' . $periodHint;
            }
        }
    } elseif (in_array($role, ['admin_hr', 'vpaa', 'dean', 'program_head'], true)) {
        $answerFromLiveData = true;
        $context['dashboard_stats'] = admin_stats();
        $context['department_weak_areas'] = array_slice(admin_department_weak_areas(), 0, 8);
        $context['priority_interventions'] = array_slice(admin_interventions(), 0, 8);
        $context['completion_by_department'] = array_slice(admin_completion_by_department(), 0, 10);
        $context['weak_area_patterns'] = array_slice(admin_weak_area_patterns(), 0, 5);
        $context['unassigned_leadership'] = admin_unassigned_leadership();
        $context['evaluation_progress'] = admin_evaluation_progress_summary();
        $context['period_comparison'] = array_slice(admin_period_comparison(), 0, 8);
        $context['current_period'] = dipascaf_period_payload();

        if ($role === 'admin_hr') {
            $answer = assistant_analytics_answer($message, $role);
        } elseif ($role === 'vpaa') {
            $answer = assistant_vpaa_analytics((int) $user['id'], $message);
        } elseif ($role === 'dean') {
            $answer = assistant_dean_analytics((int) $user['id'], $message);
        } elseif ($role === 'program_head') {
            $answer = assistant_program_head_analytics((int) $user['id'], $message);
        }
    }

    $responseSource = $answerFromLiveData ? 'role_scoped_database' : 'deterministic_fallback';
    if (!$answerFromLiveData) {
        $openAiAnswer = openai_answer($rawMessage, $context);
        if ($openAiAnswer !== null) {
            $answer = $openAiAnswer;
            $responseSource = 'openai';
        }

        $geminiAnswer = $openAiAnswer === null ? gemini_answer($rawMessage, $context) : null;
        if ($geminiAnswer !== null) {
            $answer = $geminiAnswer;
            $responseSource = 'gemini';
        }
    } else {
        $detectedIntents = assistant_copilot_intents($rawMessage, $assistantMode);
        $needsSynthesis = count($detectedIntents) > 1 || in_array($assistantMode, ['compare', 'explain', 'risk', 'draft'], true);
        if ($needsSynthesis) {
            $safeSynthesisContext = [
                'authoritative_database_answer' => $answer,
                'authorized_scope' => $userScope,
                'role' => $role,
                'mode' => $assistantMode,
                'instruction' => 'Summarize only the authoritative answer. Do not add people, scores, facts, or actions not present in it. Keep the response short.',
            ];
            $synthesized = openai_answer($rawMessage, $safeSynthesisContext);
            if ($synthesized !== null) {
                $answer = $synthesized;
                $responseSource = 'role_scoped_database+openai';
            } else {
                $synthesized = gemini_answer($rawMessage, $safeSynthesisContext);
                if ($synthesized !== null) {
                    $answer = $synthesized;
                    $responseSource = 'role_scoped_database+gemini';
                }
            }
        }
    }

    $answer = assistant_enhance_answer($answer, $assistantMode, $role, $pagePath, $userScope);

    $structured = assistant_copilot_payload($answer, $rawMessage, $assistantMode, $user, $context, $selectedPeriod, $responseSource);
    error_log('[assistant-copilot] ' . json_encode([
        'user_id' => (int) ($user['id'] ?? 0), 'role' => $role,
        'intent' => $structured['intent'], 'scope' => $structured['scope'], 'source' => $structured['source'],
    ], JSON_UNESCAPED_SLASHES));
    echo json_encode([
        'ok' => true,
        ...$structured,
        'mode' => $assistantMode,
    ]);
} catch (Throwable $exception) {
    $message = strtolower(trim($_POST['message'] ?? ''));
    $role = $user['role'] ?? '';
    if ($role === 'admin_hr') {
        try {
            $answer = assistant_analytics_answer($message, $role);
            echo json_encode(['ok' => true, 'answer' => $answer]);
            exit;
        } catch (Throwable) {
        }
    }
    $answer = assistant_is_pmas_question($message)
        ? assistant_static_pmas_answer($message)
        : $assistantFallbackAnswer;
    echo json_encode(['ok' => true, 'answer' => $answer]);
}
