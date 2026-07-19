<?php
declare(strict_types=1);

/**
 * AI Recommendation API
 * Generates professional evaluation recommendations using OpenAI or Gemini.
 *
 * POST /
 *   assignment_id        : int
 *   form_type            : "form_a" | "form_b"
 *   categories           : array of { title, average_rating, factor_weight, questions, evaluator_notes? }
 *
 * For each category, generates:
 *   - behavioral_evidence (required for avg ≥ 4.51)
 *   - reason_for_rating   (required for avg ≤ 3.00)
 *   - recommendation      (professional wording based on rating threshold)
 *   - action_plan         (for low ratings)
 *   - target_period       (timeline for improvement)
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/openai.php';
require_once __DIR__ . '/../includes/gemini.php';

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

function ai_recommendation_status_from_counts(int $submitted, int $total, array $pendingEvaluators = []): array
{
    $total = max(0, $total);
    $submitted = max(0, min($submitted, $total));
    $pending = max(0, $total - $submitted);
    $pct = $total > 0 ? round(($submitted / $total) * 100, 1) : 0.0;
    $status = $pct >= 100 ? 'final' : ($pct >= 50 ? 'interim' : 'preliminary');
    $completionStatus = $pct >= 100 ? 'complete' : ($pct > 0 ? 'partial' : 'incomplete');
    $caveat = match ($status) {
        'final' => "FINAL RECOMMENDATION - Based on complete evaluation data from all {$total} evaluators.",
        'interim' => "INTERIM RECOMMENDATION (Based on {$pct}% of evaluations) - This recommendation may change as remaining {$pending} evaluations are received.",
        default => "PRELIMINARY RECOMMENDATION (Based on {$pct}% of evaluations) - Final recommendation will be provided once all evaluations are submitted.",
    };
    return [
        'recommendation_status' => $status,
        'completion_status' => $completionStatus,
        'submitted_count' => $submitted,
        'pending_count' => $pending,
        'total_assigned' => $total,
        'completion_percentage' => $pct,
        'pending_evaluators' => $pendingEvaluators,
        'warning_flag' => $pct < 100,
        'caveat_text' => $caveat,
    ];
}

function ai_recommendation_pending_rows(string $whereSql, array $params): array
{
    return admin_all(
        "SELECT pa.id, pa.assignment_type, pa.evaluator_role, pa.deadline, u.full_name AS evaluator_name
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         LEFT JOIN users u ON u.id = pa.evaluator_user_id
         WHERE {$whereSql}
           AND pa.status != 'submitted'
           AND COALESCE(pa.is_archived, 0) = 0
           AND COALESCE(f.is_archived, 0) = 0
         ORDER BY pa.deadline, u.full_name",
        $params
    );
}

function ai_recommendation_count_payload(string $whereSql, array $params): array
{
    $counts = admin_one(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN pa.status = 'submitted' THEN 1 ELSE 0 END) AS submitted
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         WHERE {$whereSql}
           AND COALESCE(pa.is_archived, 0) = 0
           AND COALESCE(f.is_archived, 0) = 0",
        $params
    ) ?? ['total' => 0, 'submitted' => 0];
    $pendingRows = ai_recommendation_pending_rows($whereSql, $params);
    $pending = array_map(static fn (array $row): array => [
        'id' => (int) ($row['id'] ?? 0),
        'name' => (string) ($row['evaluator_name'] ?? 'Evaluator'),
        'role' => (string) ($row['assignment_type'] ?? $row['evaluator_role'] ?? ''),
        'deadline' => (string) ($row['deadline'] ?? ''),
    ], $pendingRows);
    return ai_recommendation_status_from_counts((int) ($counts['submitted'] ?? 0), (int) ($counts['total'] ?? 0), $pending);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = trim((string) ($_GET['action'] ?? ''));
    $period = trim((string) ($_GET['period'] ?? ''));
    $periodSql = $period !== '' ? ' AND pa.cycle_name = :period' : '';

    if ($action === 'faculty_recommendation') {
        $facultyId = (int) ($_GET['faculty_id'] ?? 0);
        $params = ['faculty_id' => $facultyId];
        if ($period !== '') $params['period'] = $period;
        echo json_encode(['ok' => true, 'completion_summary' => ai_recommendation_count_payload('pa.evaluatee_faculty_id = :faculty_id' . $periodSql, $params)]);
        exit;
    }

    if ($action === 'program_recommendation') {
        $program = trim((string) ($_GET['program'] ?? ''));
        $params = ['program' => $program];
        if ($period !== '') $params['period'] = $period;
        echo json_encode(['ok' => true, 'completion_summary' => ai_recommendation_count_payload('f.program_code = :program' . $periodSql, $params)]);
        exit;
    }

    if ($action === 'department_recommendation') {
        $department = trim((string) ($_GET['department'] ?? ''));
        $params = ['department' => $department];
        if ($period !== '') $params['period'] = $period;
        echo json_encode(['ok' => true, 'completion_summary' => ai_recommendation_count_payload('f.department = :department' . $periodSql, $params)]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid recommendation action.']);
    exit;
}

// ── Only allow POST for text generation ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || empty($input['categories'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid request. Provide categories array.']);
        exit;
    }

    $formType = $input['form_type'] ?? 'form_a';
    $roleLabel = $formType === 'form_a' ? 'administrator' : 'faculty member';
    $completionSummary = is_array($input['completion_summary'] ?? null)
        ? $input['completion_summary']
        : null;
    $recommendationCaveat = trim((string) ($completionSummary['caveat_text'] ?? ''));

    $recommendations = [];

    foreach ($input['categories'] as $cat) {
        $title = $cat['title'] ?? 'Category';
        $avgRating = (float) ($cat['average_rating'] ?? 0);
        $factorWeight = (float) ($cat['factor_weight'] ?? 0);
        $questions = $cat['questions'] ?? [];
        $evaluatorNotes = $cat['evaluator_notes'] ?? '';

        // Identify strong and weak areas from questions
        $lowRated = [];
        $highRated = [];
        foreach ($questions as $q) {
            $rating = (int) ($q['rating'] ?? 0);
            if ($rating >= 1 && $rating <= 3) {
                $lowRated[] = $q['text'] ?? '';
            } elseif ($rating >= 5) {
                $highRated[] = $q['text'] ?? '';
            }
        }

        $strongAreas = !empty($highRated) ? '- ' . implode("\n- ", array_slice($highRated, 0, 3)) : 'No specific strong areas identified.';
        $weakAreas = !empty($lowRated) ? '- ' . implode("\n- ", array_slice($lowRated, 0, 3)) : 'No specific weak areas identified.';

        // Build AI prompt
        if ($avgRating >= 4.51) {
            $promptType = 'positive';
            $prompt = <<<PROMPT
You are a professional HR evaluator for an academic institution.

Generate a POSITIVE evaluation recommendation for the category "{$title}" (Factor Weight: {$factorWeight}%).

The {$roleLabel} received an Average Rating of {$avgRating} out of 5.00 — this is an EXCELLENT rating.

Strong areas observed:
{$strongAreas}

Evaluator notes: {$evaluatorNotes}

Write a professional recommendation (2-3 sentences) that:
1. Acknowledges and commends the excellent performance
2. Encourages the {$roleLabel} to sustain and maintain this high level of performance
3. Suggests sharing their effective practices with colleagues when appropriate

Also provide 1-2 sentences of behavioral evidence supporting this high rating.

Do NOT create or change any rating. Only suggest professional wording.
PROMPT;
        } elseif ($avgRating <= 3.00) {
            $promptType = 'improvement';
            $prompt = <<<PROMPT
You are a professional HR evaluator for an academic institution.

Generate an IMPROVEMENT-FOCUSED evaluation recommendation for the category "{$title}" (Factor Weight: {$factorWeight}%).

The {$roleLabel} received an Average Rating of {$avgRating} out of 5.00 — this indicates a need for improvement.

Weak areas identified:
{$weakAreas}

Evaluator notes: {$evaluatorNotes}

Write a professional recommendation (3-4 sentences) that:
1. Clearly and constructively states the areas needing improvement
2. Suggests specific support measures: coaching, mentoring, monitoring, or training
3. Proposes a realistic action plan with a target timeline (30-60 days or next evaluation period)
4. Maintains a supportive and developmental tone

Also provide 1-2 sentences explaining the reason for this rating.

Do NOT create or change any rating. Only suggest professional wording.
PROMPT;
        } else {
            $promptType = 'balanced';
            $prompt = <<<PROMPT
You are a professional HR evaluator for an academic institution.

Generate a BALANCED evaluation recommendation for the category "{$title}" (Factor Weight: {$factorWeight}%).

The {$roleLabel} received an Average Rating of {$avgRating} out of 5.00 — this indicates acceptable/satisfactory performance with room for growth.

Strong areas observed:
{$strongAreas}

Weak areas identified:
{$weakAreas}

Evaluator notes: {$evaluatorNotes}

Write a professional recommendation (2-3 sentences) that:
1. Recognizes the acceptable performance achieved
2. Gently suggests areas where further improvement could be made
3. Encourages continued professional development

Do NOT create or change any rating. Only suggest professional wording.
PROMPT;
        }

        // Try OpenAI first, fall back to Gemini, then to static fallback
        $aiResponse = openai_answer($prompt);
        if ($aiResponse === null || trim($aiResponse) === '') {
            $aiResponse = gemini_answer($prompt);
        }

        // If AI failed, use static rule-based fallback
        if ($aiResponse === null || trim($aiResponse) === '') {
            $aiResponse = generateStaticRecommendation($title, $avgRating, $roleLabel, $promptType, $strongAreas, $weakAreas, $evaluatorNotes);
        }

        $recommendations[] = [
            'category_title' => $title,
            'average_rating' => $avgRating,
            'factor_weight' => $factorWeight,
            'prompt_type' => $promptType,
            'recommendation_text' => $recommendationCaveat !== '' ? $recommendationCaveat . ' ' . trim($aiResponse) : trim($aiResponse),
            'strong_areas' => array_slice($highRated, 0, 3),
            'weak_areas' => array_slice($lowRated, 0, 3),
            'completion_summary' => $completionSummary,
        ];
    }

    echo json_encode([
        'ok' => true,
        'recommendations' => $recommendations,
        'completion_summary' => $completionSummary,
    ]);

} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Internal server error: ' . $exception->getMessage(),
    ]);
}

// ── Static fallback recommendation generator ──────────────────────────────────
function generateStaticRecommendation(
    string $title,
    float $avgRating,
    string $roleLabel,
    string $promptType,
    string $strongAreas,
    string $weakAreas,
    string $evaluatorNotes
): string {
    $titleLower = strtolower($title);

    if ($promptType === 'positive') {
        return "The {$roleLabel} has demonstrated consistently excellent performance in {$titleLower}. "
             . "This rating reflects a strong commitment to the standards expected in this category. "
             . "It is recommended that the {$roleLabel} continues to sustain this high level of performance "
             . "and consider sharing effective practices with colleagues to contribute to institutional excellence.";
    }

    if ($promptType === 'improvement') {
        return "The {$roleLabel} is encouraged to focus on improving performance in {$titleLower}. "
             . "Targeted support through coaching, mentoring, or training interventions may be beneficial "
             . "to address the identified areas. A structured improvement plan with regular monitoring "
             . "and feedback from the immediate supervisor is recommended. "
             . "Progress should be reviewed within 30 to 60 days or during the next evaluation period.";
    }

    // Balanced
    return "The {$roleLabel} generally meets expectations in {$titleLower}. "
         . "Continued professional development and focused effort on identified growth areas "
         . "will help enhance performance further. Regular self-assessment and supervisory feedback "
         . "are encouraged to support ongoing improvement.";
}
