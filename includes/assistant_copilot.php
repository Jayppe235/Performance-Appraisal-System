<?php
declare(strict_types=1);

function assistant_copilot_intents(string $message, string $mode = 'overview'): array
{
    $text = strtolower(trim($message));
    $rules = [
        'compare' => ['compare', 'comparison', 'versus', 'vs ', 'trend', 'changed', 'last period'],
        'risk' => ['risk', 'overdue', 'blocked', 'delay', 'missing', 'duplicate', 'below', 'decline'],
        'explain' => ['explain', 'why', 'how was', 'evidence', 'reason', 'meaning'],
        'draft' => ['draft', 'propose', 'action plan', 'recommend', 'prioritize', 'goal', 'agenda'],
        'status' => ['status', 'pending', 'submitted', 'complete', 'progress', 'deadline'],
        'intervention' => ['intervention', 'training', 'coaching', 'development', 'seminar'],
        'performance' => ['score', 'rating', 'strength', 'weak', 'performance', 'category'],
        'self_evaluation' => ['self evaluation', 'self-evaluation', 'self status'],
        'report' => ['report', 'export', 'summary'],
        'navigation' => ['where', 'open', 'find', 'page'],
    ];
    $detected = [];
    foreach ($rules as $intent => $needles) {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                $detected[] = $intent;
                break;
            }
        }
    }
    $modeIntent = match ($mode) {
        'compare' => 'compare', 'explain' => 'explain', 'risk' => 'risk',
        'draft', 'actions' => 'draft', default => null,
    };
    if ($modeIntent !== null) array_unshift($detected, $modeIntent);
    return array_values(array_unique($detected ?: ['overview']));
}

function assistant_copilot_scope(array $user): array
{
    $role = (string) ($user['role'] ?? '');
    return [
        'kind' => match ($role) {
            'admin_hr' => 'institution', 'vpaa' => 'academic_departments',
            'dean' => 'department', 'program_head' => 'program', default => 'self',
        },
        'department' => in_array($role, ['dean', 'program_head'], true) ? trim((string) ($user['department'] ?? '')) : '',
        'program' => $role === 'program_head' ? trim((string) ($user['program'] ?? '')) : '',
        'user_id' => (int) ($user['id'] ?? 0),
    ];
}

function assistant_copilot_navigation(string $role, array $intents): array
{
    $intent = $intents[0] ?? 'overview';
    $base = match ($role) {
        'admin_hr' => '/admin', 'vpaa' => '/vpaa', 'dean' => '/dean',
        'program_head' => '/program-head', default => '/faculty',
    };
    $path = match (true) {
        $role === 'admin_hr' && in_array($intent, ['status', 'self_evaluation', 'risk'], true) => '/admin/assignments',
        $role === 'admin_hr' && in_array($intent, ['performance', 'intervention', 'compare'], true) => '/admin/ai-actions',
        $role === 'admin_hr' && $intent === 'report' => '/admin/reports',
        $role === 'faculty' || $role === 'teacher' => in_array($intent, ['status', 'self_evaluation'], true) ? '/faculty/evaluate' : '/faculty/results',
        in_array($intent, ['status', 'self_evaluation', 'risk'], true) => $base . '/evaluate',
        $intent === 'report' => $base . (in_array($role, ['vpaa'], true) ? '/reports' : '/report'),
        default => $base . (in_array($role, ['dean', 'program_head'], true) ? '/summary' : '/analytics'),
    };
    return ['label' => 'Open relevant workspace', 'path' => $path];
}

function assistant_copilot_followups(string $role, array $intents): array
{
    $roleQuestions = match ($role) {
        'admin_hr' => [
            'Which departments need immediate follow-up?',
            'Compare completion with the previous period.',
            'Draft a prioritized institution-wide action plan.',
        ],
        'vpaa' => [
            'Which assigned department has the highest academic risk?',
            'Compare Dean evaluation progress across periods.',
            'Draft VPAA development priorities.',
        ],
        'dean' => [
            'Which faculty submissions still need reviewer confirmation?',
            'What weak areas repeat across my department?',
            'Draft my department review checklist.',
        ],
        'program_head' => [
            'Which faculty in my program need coaching first?',
            'Compare category changes across the latest periods.',
            'Draft a program coaching agenda.',
        ],
        default => [
            'Compare my latest two appraisal periods.',
            'Explain my three largest category changes.',
            'Draft measurable development goals for me.',
        ],
    };
    if (in_array('risk', $intents, true)) array_unshift($roleQuestions, 'What evidence supports the highest-priority risk?');
    return array_slice(array_values(array_unique($roleQuestions)), 0, 4);
}

function assistant_copilot_payload(string $answer, string $message, string $mode, array $user, array $context = [], string $period = '', string $source = 'role_scoped_database'): array
{
    $role = (string) ($user['role'] ?? 'teacher');
    $intents = assistant_copilot_intents($message, $mode);
    $progress = is_array($context['evaluation_progress'] ?? null) ? $context['evaluation_progress'] : [];
    $metrics = [];
    foreach ($role === 'admin_hr' ? [
        'completionRate' => 'Completion rate', 'completedEvaluations' => 'Completed',
        'pendingEvaluations' => 'Pending', 'totalEvaluations' => 'Total evaluations',
    ] : [] as $key => $label) {
        if (isset($progress[$key])) $metrics[] = ['label' => $label, 'value' => $progress[$key]];
    }
    $evidence = [];
    if ($period !== '') $evidence[] = 'Evaluation period: ' . $period;
    $scope = assistant_copilot_scope($user);
    $scopeLabel = trim(implode(' / ', array_filter([$scope['department'], $scope['program']]))) ?: ucfirst(str_replace('_', ' ', $scope['kind']));
    $evidence[] = 'Authorized scope: ' . $scopeLabel;
    $warnings = [];
    if ($metrics === [] && $role !== 'teacher') $warnings[] = 'No aggregate metrics were available for this response.';
    $draftRequested = in_array('draft', $intents, true);
    return [
        'answer' => trim($answer),
        'intent' => $intents[0],
        'intents' => $intents,
        'metrics' => array_slice($metrics, 0, 4),
        'evidence' => $evidence,
        'warnings' => $warnings,
        'draft' => $draftRequested ? 'Draft only—review the recommendation and complete any workflow through the authorized PMAS screen.' : '',
        'follow_ups' => assistant_copilot_followups($role, $intents),
        'navigation' => assistant_copilot_navigation($role, $intents),
        'scope' => $scopeLabel,
        'source' => $source,
    ];
}
