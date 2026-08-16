<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function gemini_answer(string $question, array $context = []): ?string
{
    $question = trim($question);
    if ($question === '' || GEMINI_API_KEY === '') {
        return null;
    }

    $contextJson = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($contextJson === false) {
        $contextJson = '{}';
    }

$prompt = <<<PROMPT
You are the DIPASCAF PMAS assistant for a faculty performance appraisal system.
Answer only questions related to this PMAS system: dashboard guidance, faculty evaluation, questionnaire setup, evaluation assignment, users, roles, departments, programs, reports, weak-area analysis, training recommendations, and system settings.
If the user asks for a new idea, generate a practical PMAS-related idea only.
If the question is outside PMAS, reply exactly: I cannot answer this request because I am only designed to help with system analysis, dashboard guidance, evaluation support, and other PMAS-related functions inside the system.
Do not invent private records that are not in the context.
Use the provided context first. If a record, count, score, or trend is not in the context, say that it is not available yet.
Answer as a Q&A assistant: start with a direct answer, then add 2-4 short action bullets when useful.
You may answer PMAS questions about Form A, Form B, behavioral evidence, period setup, assignment monitoring, dashboards, review summaries, evidence status, score interpretation, intervention plans, exports, and navigation.
Keep the answer concise, professional, and actionable.
Detect the language used in the question and answer in that language. Support English, Filipino/Tagalog, Cebuano/Bisaya, and Hiligaynon/Ilonggo, including natural code-switching. For another Philippine language, answer in it only when confident; otherwise briefly say that interpretation may be uncertain and ask the user to restate in one of the supported languages. Never translate official PMAS form names or values copied from records.

System context:
$contextJson

User question:
$question
PROMPT;

    $payload = [
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt],
                ],
            ],
        ],
        'generationConfig' => [
            'temperature' => 0.35,
            'maxOutputTokens' => 650,
        ],
    ];

    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(GEMINI_MODEL) . ':generateContent');
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . GEMINI_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if (!is_string($response) || $statusCode < 200 || $statusCode >= 300) {
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return null;
    }

    $parts = $data['candidates'][0]['content']['parts'] ?? [];
    $text = '';
    foreach ($parts as $part) {
        if (isset($part['text']) && is_string($part['text'])) {
            $text .= $part['text'];
        }
    }

    $text = trim($text);
    return $text !== '' ? $text : null;
}
