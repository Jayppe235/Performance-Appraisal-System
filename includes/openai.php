<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function openai_answer(string $question, array $context = []): ?string
{
    $question = trim($question);
    if ($question === '' || OPENAI_API_KEY === '') {
        return null;
    }

    $contextJson = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($contextJson === false) {
        $contextJson = '{}';
    }

    $instructions = <<<PROMPT
You are the DIPASCAF PMAS assistant.
Answer only questions related to this PMAS system: dashboard guidance, faculty evaluation, questionnaire setup, evaluation assignment, users, roles, departments, programs, reports, weak-area analysis, training recommendations, and system settings.
If the user asks for a new idea, generate a practical PMAS-related idea only.
If the question is outside PMAS, reply exactly: I cannot answer this request because I am only designed to help with system analysis, dashboard guidance, evaluation support, and other PMAS-related functions inside the system.
Do not invent private records that are not in the provided context.
Use the provided context first. If a record, count, score, or trend is not in the context, say that it is not available yet.
Answer as a Q&A assistant: start with a direct answer, then add 2-4 short action bullets when useful.
You may answer PMAS questions about Form A, Form B, behavioral evidence, period setup, assignment monitoring, dashboards, review summaries, evidence status, score interpretation, intervention plans, exports, and navigation.
Keep the answer concise, professional, and actionable.

System context:
$contextJson
PROMPT;

    $payload = [
        'model' => OPENAI_MODEL,
        'instructions' => $instructions,
        'input' => $question,
        'max_output_tokens' => 650,
        'temperature' => 0.35,
        'store' => false,
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
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

    $text = trim((string) ($data['output_text'] ?? ''));
    if ($text !== '') {
        return $text;
    }

    $output = $data['output'] ?? [];
    foreach ($output as $item) {
        foreach (($item['content'] ?? []) as $content) {
            if (isset($content['text']) && is_string($content['text'])) {
                $text .= $content['text'];
            }
        }
    }

    $text = trim($text);
    return $text !== '' ? $text : null;
}
