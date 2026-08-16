<?php
declare(strict_types=1);

function assistant_copilot_normalize_text(string $text): string
{
    $text = function_exists('mb_strtolower') ? mb_strtolower(trim($text), 'UTF-8') : strtolower(trim($text));
    return preg_replace('/\s+/u', ' ', $text) ?? $text;
}

/**
 * Correct an accidentally repeated digit in a five-digit academic year.
 * Known database years win; otherwise only plausible 1900-2100 years are used.
 */
function assistant_copilot_correct_year_typos(string $message, array $knownYears = []): array
{
    $known = array_fill_keys(array_map('strval', $knownYears), true);
    $corrections = [];
    $corrected = preg_replace_callback('/(?<!\d)\d{5}(?!\d)/', static function (array $match) use ($known, &$corrections): string {
        $token = $match[0];
        $candidates = [];
        for ($index = 0; $index < 5; $index++) {
            $candidate = substr($token, 0, $index) . substr($token, $index + 1);
            $year = (int) $candidate;
            if ($year >= 1900 && $year <= 2100) $candidates[$candidate] = true;
        }
        if ($candidates === []) return $token;
        $choices = array_keys($candidates);
        $replacement = null;
        foreach ($choices as $choice) {
            if (isset($known[$choice])) {
                $replacement = $choice;
                break;
            }
        }
        if ($replacement === null && count($choices) === 1) $replacement = $choices[0];
        if ($replacement === null) return $token;
        $replacement = (string) $replacement;
        $corrections[] = ['original' => $token, 'corrected' => $replacement, 'type' => 'year'];
        return $replacement;
    }, $message);

    return ['message' => $corrected ?? $message, 'corrections' => $corrections];
}

function assistant_copilot_language(string $message): array
{
    $text = assistant_copilot_normalize_text($message);
    $signals = [
        'hiligaynon' => ['diin', 'pila ang', 'sin-o', 'ngaa', 'palihog', 'buligi', 'akon', 'sang ', 'mga rekord', 'madamo nga salamat', 'halong'],
        'cebuano' => ['unsa', 'asa', 'pila', 'kinsa', 'ngano', 'palihug', 'tabangi', 'akong', 'nako', 'ko karon', 'karon', 'mga rekord', 'kumusta', 'daghang salamat', 'amping'],
        'filipino' => ['ano ang', 'nasaan', 'saan', 'ilan', 'sino', 'bakit', 'paki', 'tulungan', 'aking', 'ako ang', 'ko ngayon', 'ngayon', 'mga tala', 'katayuan', 'maraming salamat', 'salamat', 'paalam'],
    ];
    $scores = array_fill_keys(array_keys($signals), 0);
    foreach ($signals as $language => $needles) {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                $scores[$language]++;
            }
        }
    }
    arsort($scores);
    $language = (string) array_key_first($scores);
    $confidence = (int) ($scores[$language] ?? 0);
    if ($confidence === 0) {
        $language = 'english';
    }
    return [
        'code' => match ($language) {
            'filipino' => 'fil', 'cebuano' => 'ceb', 'hiligaynon' => 'hil', default => 'en',
        },
        'name' => $language,
        'confidence' => $confidence >= 2 ? 'high' : ($confidence === 1 ? 'medium' : 'default'),
        'mixed' => count(array_filter($scores, static fn (int $score): bool => $score > 0)) > 1,
    ];
}

/**
 * Classify only questions that are explicitly about PMAS. Generic question words
 * are intentionally excluded so requests such as weather or general knowledge do
 * not reach a model or a database handler.
 */
function assistant_copilot_topic_intent(string $message): ?string
{
    $text = assistant_copilot_normalize_text($message);
    if ($text === '') return 'system_guidance';

    // A comparison containing two academic years is an explicit PMAS period
    // request even when the user omits words such as evaluation or appraisal.
    preg_match_all('/(?<!\d)(?:19|20)\d{2,3}(?!\d)/', $text, $yearMatches);
    if (count(array_unique($yearMatches[0] ?? [])) >= 2
        && preg_match('/\b(compare|comparison|versus|vs|between|itandi|ikumpara|komparar)\b/u', $text) === 1) {
        return 'periods';
    }

    $topics = [
        'assignments' => ['assignment', 'assigned', 'evaluator', 'evaluatee', 'peer evaluation', 'self evaluation', 'self-evaluation', 'i-evaluate', 'e-evaluate', 'susuriin', 'timbangon', 'timbang'],
        'status' => ['evaluation status', 'appraisal status', 'pending evaluation', 'submitted evaluation', 'completed evaluation', 'overdue evaluation', 'completion', 'progress', 'deadline', 'due date', 'katayuan', 'kahimtang', 'estado', 'natapos', 'nahuman', 'naulahi'],
        'performance' => ['performance', 'score', 'rating', 'strength', 'weak area', 'weakness', 'lowest-rated', 'highest-rated', 'category result', 'weighted total', 'resulta', 'kalig-on', 'kahuyang'],
        'interventions' => ['intervention', 'training', 'seminar', 'coaching', 'development plan', 'action plan', 'recommendation', 'rekomendasyon', 'pagbansay'],
        'reports' => [
            'pmas report', 'evaluation report', 'appraisal report', 'overall report',
            'period report', 'report for', 'report in the', 'report during', 'report of the',
            'export', 'download report', 'summary report', 'performance report',
            'pangkalahatang ulat', 'kabuuang ulat', 'ulat ng', 'ulat para',
            'kinatibuk-ang report', 'report sa', 'report para sa',
            'kabilugan nga report', 'ulat',
        ],
        'navigation' => ['dashboard', 'action center', 'people management', 'ai analysis', 'ai actions', 'where can i find', 'where can i view', 'where can i check', 'nasaan', 'saan makikita', 'asa makita', 'diin makita'],
        'periods' => ['appraisal period', 'evaluation period', 'cycle name', 'current period', 'previous period', 'latest period', 'panahon sa evaluation'],
        'questionnaires' => ['questionnaire', 'form a', 'form b', 'behavioral evidence', 'evaluation form', 'rating scale', 'pangutana sa evaluation'],
        'system_guidance' => ['pmas', 'appraisia', 'chatbot', 'performance appraisal system', 'system administrator', 'admin/hr', 'vpaa', 'program head', 'dean dashboard', 'faculty dashboard'],
    ];
    foreach ($topics as $intent => $needles) {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) return $intent;
        }
    }
    return null;
}

function assistant_copilot_refusal(string $language): string
{
    return match ($language) {
        'fil' => 'Hindi ko masasagot ang kahilingang ito dahil para lamang ako sa PMAS. Maaari kang magtanong tungkol sa evaluation status, assignments, scores, reports, questionnaires, o system navigation.',
        'ceb' => 'Dili nako matubag kini kay para lamang ako sa PMAS. Mahimo kang mangutana bahin sa evaluation status, assignments, scores, reports, questionnaires, o system navigation.',
        'hil' => 'Indi ko ini masabat kay para lamang ako sa PMAS. Mahimo ka mamangkot parte sa evaluation status, assignments, scores, reports, questionnaires, ukon system navigation.',
        default => 'I cannot answer that because I only assist with PMAS. Ask me about evaluation status, assignments, scores, reports, questionnaires, or system navigation.',
    };
}

function assistant_copilot_missing_data(string $language, string $subject, string $path): string
{
    return match ($language) {
        'fil' => "Wala pang available na {$subject} sa iyong awtorisadong PMAS scope. Tingnan ang {$path} o makipag-ugnayan sa Admin/HR kung dapat mayroon nang record.",
        'ceb' => "Wala pay available nga {$subject} sa imong gitugot nga PMAS scope. Tan-awa ang {$path} o kontaka ang Admin/HR kon kinahanglan adunay rekord.",
        'hil' => "Wala pa sang available nga {$subject} sa imo ginpasugtan nga PMAS scope. Lantawa ang {$path} ukon kontaka ang Admin/HR kon dapat may rekord na.",
        default => "No {$subject} is available in your authorized PMAS scope yet. Check {$path}, or contact Admin/HR if a record should already exist.",
    };
}

function assistant_copilot_terms(string $language): array
{
    return match ($language) {
        'fil' => ['unavailable' => 'Hindi pa available ang hiniling na data.', 'scope' => 'Awtorisadong saklaw', 'fresh' => 'Huling binasa mula sa database'],
        'ceb' => ['unavailable' => 'Wala pa ang gipangayo nga datos.', 'scope' => 'Gitugot nga sakop', 'fresh' => 'Katapusang gibasa gikan sa database'],
        'hil' => ['unavailable' => 'Wala pa ang ginpangayo nga datos.', 'scope' => 'Ginpasugtan nga sakop', 'fresh' => 'Katapusan nga pagbasa halin sa database'],
        default => ['unavailable' => 'The requested data is not available yet.', 'scope' => 'Authorized scope', 'fresh' => 'Last read from database'],
    };
}

function assistant_copilot_small_talk(string $message): ?string
{
    $text = trim(assistant_copilot_normalize_text($message), " \t\n\r\0\x0B!?.,");
    if ($text === '') return null;

    $language = assistant_copilot_language($message)['code'];
    $patterns = [
        'thanks' => '/\b(thanks|thank you|thankyou|salamat|maraming salamat|daghang salamat|madamo nga salamat|appreciate|appreciated)\b/u',
        'wellbeing' => '/\b(how are you|how are u|kumusta|kamusta|musta|maayo ka|kamusta ka)\b/u',
        'farewell' => '/\b(bye|goodbye|see you|paalam|babay|amping|halong)\b/u',
        'greeting' => '/\b(hi|hello|hey|good morning|good afternoon|good evening|magandang umaga|magandang hapon|magandang gabi|maayong buntag|maayong hapon|maayong gabii|maupay|kumusta|kamusta)\b/u',
    ];
    $intent = null;
    foreach ($patterns as $name => $pattern) {
        if (preg_match($pattern, $text) === 1) {
            $intent = $name;
            break;
        }
    }
    if ($intent === null) return null;

    $responses = [
        'en' => [
            'greeting' => 'Hello! I’m APPRAISIA. How can I help you with PMAS today?',
            'thanks' => 'You’re welcome! I’m glad I could help. Is there anything else you’d like to know about PMAS?',
            'wellbeing' => 'I’m doing well and ready to help! What would you like to check in PMAS?',
            'farewell' => 'Goodbye! Feel free to ask APPRAISIA whenever you need help with PMAS.',
        ],
        'fil' => [
            'greeting' => 'Kumusta! Ako si APPRAISIA. Paano kita matutulungan sa PMAS ngayon?',
            'thanks' => 'Walang anuman! Masaya akong makatulong. May iba ka pa bang gustong malaman tungkol sa PMAS?',
            'wellbeing' => 'Mabuti naman at handa akong tumulong! Ano ang gusto mong tingnan sa PMAS?',
            'farewell' => 'Paalam! Maaari kang magtanong ulit kay APPRAISIA kapag kailangan mo ng tulong sa PMAS.',
        ],
        'ceb' => [
            'greeting' => 'Kumusta! Ako si APPRAISIA. Unsaon tika pagtabang sa PMAS karon?',
            'thanks' => 'Walay sapayan! Nalipay ko nga nakatabang. Aduna pa kay pangutana bahin sa PMAS?',
            'wellbeing' => 'Maayo ra ko ug andam motabang! Unsa ang gusto nimong susihon sa PMAS?',
            'farewell' => 'Amping! Pangutana lang usab kang APPRAISIA kon kinahanglan ka og tabang sa PMAS.',
        ],
        'hil' => [
            'greeting' => 'Kamusta! Ako si APPRAISIA. Paano ta ikaw mabuligan sa PMAS subong?',
            'thanks' => 'Wala sing anuman! Nalipay ako nga nakabulig. May pamangkot ka pa parte sa PMAS?',
            'wellbeing' => 'Maayo man ako kag handa magbulig! Ano ang gusto mo lantawon sa PMAS?',
            'farewell' => 'Halong! Pamangkot lang liwat kay APPRAISIA kon kinahanglan mo sang bulig sa PMAS.',
        ],
    ];
    return $responses[$language][$intent] ?? $responses['en'][$intent];
}

function assistant_copilot_module_context(array $user): array
{
    if (!function_exists('admin_one')) {
        return [];
    }
    $role = (string) ($user['role'] ?? 'teacher');
    $userId = (int) ($user['id'] ?? 0);
    $department = trim((string) ($user['department'] ?? ''));
    $program = trim((string) ($user['program'] ?? ''));
    $result = [];

    try {
        if (admin_one("SHOW TABLES LIKE 'pmas_goals_records'") !== null) {
            $where = '1=1';
            $params = [];
            if ($role === 'teacher') {
                $where = 'g.user_id = :user_id';
                $params['user_id'] = $userId;
            } elseif ($role === 'dean') {
                $where = 'g.department = :department';
                $params['department'] = $department;
            } elseif ($role === 'program_head') {
                $where = "g.department = :department AND EXISTS (SELECT 1 FROM users gu WHERE gu.id=g.user_id AND gu.program=:program)";
                $params = ['department' => $department, 'program' => $program];
            } elseif ($role === 'vpaa') {
                // VPAA assignment history is period-scoped and is provided by vpaa_data.php;
                // do not broaden optional module access when no equivalent scoped join exists.
                $where = '1=0';
            }
            $result['goals_records'] = admin_all(
                'SELECT g.status, COUNT(*) AS total FROM pmas_goals_records g WHERE ' . $where . ' GROUP BY g.status',
                $params
            );
        }
        if (admin_one("SHOW TABLES LIKE 'faculty_subject_assignments'") !== null) {
            $where = '1=1';
            $params = [];
            if ($role === 'teacher') {
                $where = 'f.user_id = :user_id';
                $params['user_id'] = $userId;
            } elseif (in_array($role, ['dean', 'program_head'], true)) {
                $where = 'f.department = :department';
                $params['department'] = $department;
            }
            $row = admin_one(
                'SELECT COUNT(*) AS total FROM faculty_subject_assignments fsa JOIN faculty f ON f.id=fsa.faculty_id WHERE ' . $where,
                $params
            );
            $result['subject_assignments'] = (int) ($row['total'] ?? 0);
        }
    } catch (Throwable $exception) {
        error_log('[assistant-copilot] Optional module context unavailable: ' . $exception->getMessage());
        $result['availability_warning'] = 'One or more optional PMAS modules could not be read.';
    }
    return $result;
}

function assistant_copilot_intents(string $message, string $mode = 'overview'): array
{
    $text = assistant_copilot_normalize_text($message);
    $rules = [
        'compare' => ['compare', 'comparison', 'versus', 'vs ', 'trend', 'changed', 'last period', 'ikumpara', 'itandi', 'komparar'],
        'risk' => ['risk', 'overdue', 'blocked', 'delay', 'missing', 'duplicate', 'below', 'decline', 'peligro', 'kulang', 'naulahi', 'nalangan'],
        'explain' => ['explain', 'why', 'how was', 'evidence', 'reason', 'meaning', 'ipaliwanag', 'ngano', 'ngaa', 'paathag'],
        'draft' => ['draft', 'propose', 'action plan', 'recommend', 'prioritize', 'goal', 'agenda', 'plano', 'rekomenda', 'prayoridad'],
        'status' => ['status', 'pending', 'submitted', 'complete', 'progress', 'deadline', 'how many', 'assigned to me', 'ilan', 'pila', 'katayuan', 'kahimtang', 'estado', 'natapos', 'nahuman'],
        'intervention' => ['intervention', 'training', 'coaching', 'development', 'seminar'],
        'performance' => ['score', 'rating', 'strength', 'weak', 'performance', 'category'],
        'self_evaluation' => ['self evaluation', 'self-evaluation', 'self status'],
        'report' => ['report', 'export', 'summary'],
        'navigation' => ['where', 'open', 'find', 'page', 'nasaan', 'saan', 'asa', 'diin'],
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

function assistant_copilot_query_focus(string $message): string
{
    $text = assistant_copilot_normalize_text($message);
    $containsAny = static function (array $needles) use ($text): bool {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) return true;
        }
        return false;
    };

    // Subject-specific targets take precedence over generic comparison words
    // such as "period", "compare", and "trend".
    if ($containsAny(['weak area', 'weakness', 'lowest area', 'area repeat', 'repeated area', 'recurring area', 'pattern'])) return 'weak_areas';
    if ($containsAny(['completion', 'complete', 'submitted', 'pending', 'overdue', 'progress'])) return 'completion';
    if ($containsAny(['assignment', 'assigned', 'evaluator', 'evaluate', 'peer'])) return 'assignments';
    if ($containsAny(['score', 'rating', 'performance', 'strength', 'highest', 'lowest'])) return 'performance';
    if ($containsAny(['training', 'seminar', 'intervention', 'development plan'])) return 'interventions';
    return 'general';
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

/**
 * Turn period rows into a decision-friendly comparison instead of a history dump.
 * Rows must be ordered oldest to newest and use the normalized keys below.
 */
function assistant_copilot_period_comparison(array $rows, string $scopeLabel): string
{
    if ($rows === []) {
        return 'No period comparison data is available yet for ' . $scopeLabel . '.';
    }

    $rows = array_values($rows);
    $latest = $rows[count($rows) - 1];
    $previous = count($rows) > 1 ? $rows[count($rows) - 2] : null;
    $formatScore = static fn ($value): string => $value === null ? 'N/A' : number_format((float) $value, 2) . '/5';
    $formatChange = static function ($value, string $suffix = ''): string {
        $number = round((float) $value, 2);
        return ($number > 0 ? '+' : '') . rtrim(rtrim(number_format($number, 2), '0'), '.') . $suffix;
    };

    $lines = ['Period comparison — ' . $scopeLabel];
    $latestName = (string) ($latest['period_name'] ?? 'Latest period');
    $lines[] = '- Latest (' . $latestName . '): ' . ($latest['completion_rate'] ?? 0) . '% complete ('
        . (int) ($latest['completed'] ?? 0) . '/' . (int) ($latest['total'] ?? 0) . '), average score ' . $formatScore($latest['average_score'] ?? null) . '.';

    if ($previous === null) {
        $lines[] = '- Comparison unavailable: at least two periods with assignments are required.';
        return implode("\n", $lines);
    }

    $previousName = (string) ($previous['period_name'] ?? 'Previous period');
    $completionDelta = round((float) ($latest['completion_rate'] ?? 0) - (float) ($previous['completion_rate'] ?? 0), 1);
    $latestScore = $latest['average_score'] ?? null;
    $previousScore = $previous['average_score'] ?? null;
    $lines[] = '- Previous (' . $previousName . '): ' . ($previous['completion_rate'] ?? 0) . '% complete ('
        . (int) ($previous['completed'] ?? 0) . '/' . (int) ($previous['total'] ?? 0) . '), average score ' . $formatScore($previousScore) . '.';
    $lines[] = '- Change: completion ' . $formatChange($completionDelta, ' percentage points') . '; average score '
        . ($latestScore !== null && $previousScore !== null ? $formatChange((float) $latestScore - (float) $previousScore, ' points') : 'not comparable') . '.';

    $weakAreas = array_slice((array) ($latest['weak_areas'] ?? []), 0, 3);
    if ($weakAreas !== []) {
        $lines[] = '- Latest weak-area signals: ' . implode(', ', array_map(
            static fn (array $area): string => (string) ($area['area'] ?? 'Unspecified') . ' (' . (int) ($area['count'] ?? 0) . ')',
            $weakAreas
        )) . '.';
    }
    $lines[] = '- Priority: ' . ($completionDelta < 0
        ? 'recover the completion decline and follow up on pending evaluations.'
        : ($latestScore !== null && $previousScore !== null && (float) $latestScore < (float) $previousScore
            ? 'review the score decline and the latest weak-area evidence.'
            : 'sustain the result and address the latest weak-area signals.'));

    return implode("\n", $lines);
}

function assistant_copilot_payload(string $answer, string $message, string $mode, array $user, array $context = [], string $period = '', string $source = 'role_scoped_database'): array
{
    $language = assistant_copilot_language($message);
    $terms = assistant_copilot_terms($language['code']);
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
    $freshAt = gmdate('c');
    $evidence[] = $terms['scope'] . ': ' . $scopeLabel;
    $evidence[] = $terms['fresh'] . ': ' . $freshAt;
    $missingData = str_starts_with($answer, 'No ') || str_starts_with($answer, 'Wala pa') || str_starts_with($answer, 'Wala pang');
    $warnings = [];
    if ($missingData) $warnings[] = $terms['unavailable'];
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
        'language' => $language,
        'context_freshness' => ['read_at' => $freshAt, 'source' => 'database', 'period' => $period],
        'data_available' => trim($answer) !== '' && !$missingData && $source !== 'refusal',
        'unavailable_message' => $terms['unavailable'],
    ];
}
