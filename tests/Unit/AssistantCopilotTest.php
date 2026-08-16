<?php

declare(strict_types=1);

namespace PMAS\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AssistantCopilotTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/assistant_copilot.php';
    }

    public function testCompoundQuestionDetectsMultipleIntents(): void
    {
        $intents = \assistant_copilot_intents('Compare completion with last period, identify risks, and draft an action plan.');
        self::assertContains('compare', $intents);
        self::assertContains('risk', $intents);
        self::assertContains('draft', $intents);
    }

    public function testDeanScopeComesFromAuthenticatedUser(): void
    {
        $scope = \assistant_copilot_scope(['id' => 7, 'role' => 'dean', 'department' => 'CITE', 'program' => 'BSIT']);
        self::assertSame('department', $scope['kind']);
        self::assertSame('CITE', $scope['department']);
        self::assertSame('', $scope['program']);
    }

    public function testFacultyNavigationNeverTargetsAdminWorkspace(): void
    {
        $navigation = \assistant_copilot_navigation('teacher', ['performance']);
        self::assertStringStartsWith('/faculty/', $navigation['path']);
    }

    /** @dataProvider roleReportNavigationProvider */
    public function testOverallReportRequestRoutesEveryRoleWithinItsOwnScope(string $role, string $expectedPath): void
    {
        $intents = \assistant_copilot_intents('Give an overall report in the 2024 period.');
        self::assertContains('report', $intents);
        self::assertSame($expectedPath, \assistant_copilot_navigation($role, $intents)['path']);
    }

    public static function roleReportNavigationProvider(): array
    {
        return [
            'Admin/HR' => ['admin_hr', '/admin/reports'],
            'VPAA' => ['vpaa', '/vpaa/reports'],
            'Dean' => ['dean', '/dean/report'],
            'Program Head' => ['program_head', '/program-head/report'],
            'Faculty' => ['teacher', '/faculty/results'],
        ];
    }

    public function testDraftResponseIsExplicitlyNonMutating(): void
    {
        $payload = \assistant_copilot_payload('Draft plan', 'Draft an action plan', 'draft', ['id' => 4, 'role' => 'program_head', 'department' => 'CITE', 'program' => 'BSIT']);
        self::assertStringContainsString('Draft only', $payload['draft']);
        self::assertSame('draft', $payload['intent']);
    }

    /** @dataProvider languageProvider */
    public function testPhilippineLanguageDetection(string $message, string $expected): void
    {
        self::assertSame($expected, \assistant_copilot_language($message)['code']);
    }

    public static function languageProvider(): array
    {
        return [
            'english' => ['What is my evaluation status?', 'en'],
            'filipino' => ['Ano ang katayuan ng aking evaluation?', 'fil'],
            'filipino assignment count' => ['Ilan ang i-evaluate ko ngayon?', 'fil'],
            'cebuano' => ['Unsa ang kahimtang sa akong evaluation, palihug?', 'ceb'],
            'cebuano assignment count' => ['Pila akong i-evaluate karon?', 'ceb'],
            'hiligaynon' => ['Diin makita ang akon evaluation, palihog buligi ako?', 'hil'],
        ];
    }

    public function testMultilingualIntentDetection(): void
    {
        self::assertContains('compare', \assistant_copilot_intents('Palihug itandi ang status sang last period.'));
        self::assertContains('explain', \assistant_copilot_intents('Paki ipaliwanag kung bakit kulang ang evidence.'));
    }

    /** @dataProvider pmasTopicProvider */
    public function testExplicitPmasTopicClassification(string $message, string $expected): void
    {
        self::assertSame($expected, \assistant_copilot_topic_intent($message));
    }

    public static function pmasTopicProvider(): array
    {
        return [
            'assignment' => ['List my pending evaluation assignments', 'assignments'],
            'status Filipino' => ['Ano ang katayuan ng aking evaluation?', 'status'],
            'status Cebuano' => ['Unsa ang kahimtang sa akong evaluation?', 'status'],
            'navigation Hiligaynon' => ['Diin makita ang evaluation report?', 'reports'],
            'overall period report' => ['Give an overall report in the 2024 period', 'reports'],
            'overall report Filipino' => ['Ibigay ang pangkalahatang ulat para sa 2024 period', 'reports'],
            'overall report Cebuano' => ['Ihatag ang kinatibuk-ang report sa 2024 period', 'reports'],
            'overall report Hiligaynon' => ['Ihatag ang kabilugan nga report sa 2024 period', 'reports'],
            'implicit period comparison' => ['Compare my 2026 and 2025 what is the highest and lowest', 'periods'],
            'questionnaire' => ['Why is behavioral evidence required in Form B?', 'questionnaires'],
            'system' => ['What is PMAS?', 'system_guidance'],
        ];
    }

    /** @dataProvider offTopicProvider */
    public function testOffTopicQuestionsAreNotClassifiedAsPmas(string $message): void
    {
        self::assertNull(\assistant_copilot_topic_intent($message));
    }

    public function testRepeatedDigitInKnownAcademicYearIsCorrected(): void
    {
        $result = \assistant_copilot_correct_year_typos(
            'Compare 20225 and 2026, including the highest and lowest scores.',
            ['2024', '2025', '2026']
        );

        self::assertSame('Compare 2025 and 2026, including the highest and lowest scores.', $result['message']);
        self::assertSame('20225', $result['corrections'][0]['original']);
        self::assertSame('2025', $result['corrections'][0]['corrected']);
        self::assertSame('periods', \assistant_copilot_topic_intent($result['message']));
    }

    public function testAmbiguousFiveDigitNumberIsNotBlindlyChanged(): void
    {
        $result = \assistant_copilot_correct_year_typos('Show record 12345.', ['2024', '2025']);
        self::assertSame('Show record 12345.', $result['message']);
        self::assertSame([], $result['corrections']);
    }

    public static function offTopicProvider(): array
    {
        return [
            'weather' => ['What is the weather today?'],
            'politics' => ['Who is the president of France?'],
            'coding' => ['How do I write a Python web server?'],
            'general knowledge' => ['Why is the sky blue?'],
            'Filipino off topic' => ['Ano ang masarap na ulam ngayon?'],
            'Cebuano off topic' => ['Unsa ang panahon karon?'],
        ];
    }

    /** @dataProvider refusalProvider */
    public function testRefusalIsLocalizedAndRedirectsToPmas(string $language, string $expected): void
    {
        $answer = \assistant_copilot_refusal($language);
        self::assertStringContainsString('PMAS', $answer);
        self::assertStringContainsString($expected, $answer);
    }

    public static function refusalProvider(): array
    {
        return [
            'English' => ['en', 'evaluation status'],
            'Filipino' => ['fil', 'Hindi'],
            'Cebuano' => ['ceb', 'Dili'],
            'Hiligaynon' => ['hil', 'Indi'],
        ];
    }

    public function testUnmappedProgramHeadFilterDeniesAllRows(): void
    {
        require_once __DIR__ . '/../../includes/program_head_data.php';
        [$where, $params] = \program_head_filter_sql([]);
        self::assertSame('1=0', $where);
        self::assertSame([], $params);
    }

    public function testUnmappedDeanFilterDeniesAllRows(): void
    {
        require_once __DIR__ . '/../../includes/dean_data.php';
        [$where, $params] = \dean_department_filter_sql([]);
        self::assertSame('1=0', $where);
        self::assertSame([], $params);
    }

    public function testMissingDataPayloadIsStructuredAsUnavailable(): void
    {
        $answer = \assistant_copilot_missing_data('fil', 'evaluation records', 'Faculty > Evaluate');
        $payload = \assistant_copilot_payload($answer, 'Ano ang evaluation status?', 'overview', ['id' => 5, 'role' => 'teacher']);
        self::assertFalse($payload['data_available']);
        self::assertNotEmpty($payload['warnings']);
        self::assertSame('fil', $payload['language']['code']);
    }

    public function testSpecificComparisonSubjectTakesPriorityOverPeriodKeyword(): void
    {
        self::assertSame(
            'weak_areas',
            \assistant_copilot_query_focus('Which weak areas repeat across faculty and periods in my department?')
        );
        self::assertSame('completion', \assistant_copilot_query_focus('Compare completion across periods.'));
        self::assertSame('performance', \assistant_copilot_query_focus('Compare average scores across periods.'));
    }

    public function testPayloadIncludesLanguageAndFreshnessMetadata(): void
    {
        $payload = \assistant_copilot_payload('Sagot', 'Ano ang aking katayuan?', 'overview', ['id' => 9, 'role' => 'teacher']);
        self::assertSame('fil', $payload['language']['code']);
        self::assertSame('database', $payload['context_freshness']['source']);
        self::assertNotEmpty($payload['context_freshness']['read_at']);
    }

    public function testPeriodComparisonHighlightsExactLatestChangeAndPriority(): void
    {
        $answer = \assistant_copilot_period_comparison([
            ['period_name' => 'First', 'completion_rate' => 80, 'completed' => 8, 'total' => 10, 'average_score' => 4.10, 'weak_areas' => []],
            ['period_name' => 'Second', 'completion_rate' => 70, 'completed' => 7, 'total' => 10, 'average_score' => 3.90, 'weak_areas' => [['area' => 'Communication', 'count' => 3]]],
        ], 'the test scope');

        self::assertStringContainsString('completion -10 percentage points', $answer);
        self::assertStringContainsString('average score -0.2 points', $answer);
        self::assertStringContainsString('Communication (3)', $answer);
        self::assertStringContainsString('recover the completion decline', $answer);
    }

    public function testPeriodComparisonExplainsWhenOnlyOnePeriodExists(): void
    {
        $answer = \assistant_copilot_period_comparison([
            ['period_name' => 'Only', 'completion_rate' => 100, 'completed' => 4, 'total' => 4, 'average_score' => 4.5],
        ], 'the test scope');

        self::assertStringContainsString('at least two periods', $answer);
    }

    /** @dataProvider smallTalkProvider */
    public function testNaturalSmallTalk(string $message, string $expectedFragment): void
    {
        $answer = \assistant_copilot_small_talk($message);
        self::assertNotNull($answer);
        self::assertStringContainsString($expectedFragment, $answer);
    }

    public static function smallTalkProvider(): array
    {
        return [
            'hello' => ['hi', 'Hello'],
            'thanks' => ['thank you, I appreciate it', 'welcome'],
            'tagalog' => ['Maraming salamat', 'Walang anuman'],
            'bisaya' => ['Daghang salamat', 'Walay sapayan'],
            'ilonggo' => ['Madamo nga salamat', 'Wala sing anuman'],
            'farewell' => ['bye', 'Goodbye'],
        ];
    }
}
