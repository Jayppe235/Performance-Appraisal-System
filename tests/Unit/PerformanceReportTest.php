<?php
declare(strict_types=1);

namespace PMAS\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PerformanceReportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/performance_report.php';
    }

    public function testAssignmentTypeIsAuthoritativeForReportColumn(): void
    {
        self::assertSame('peer', \performance_report_source('peer', 'program_head'));
        self::assertSame('head', \performance_report_source('dean', 'teacher'));
        self::assertSame('head', \performance_report_source('vpaa', 'teacher'));
        self::assertSame('phsc', \performance_report_source('program_head', 'teacher'));
        self::assertSame('self', \performance_report_source('self', 'program_head'));
    }

    public function testLegacyUnknownAssignmentFallsBackToEvaluatorRole(): void
    {
        self::assertSame('phsc', \performance_report_source('', 'program_head'));
        self::assertSame('head', \performance_report_source('legacy', 'dean'));
        self::assertSame('peer', \performance_report_source('legacy', 'teacher'));
    }

    public function testSourceAnalysisUsesThresholdAndGrowthOpportunities(): void
    {
        $weak = \performance_report_source_analysis('form_b', 'PMAS Form B', [
            ['category'=>'Teaching Effectiveness','score'=>3.20,'factor_weight'=>30],
            ['category'=>'Communication Skills','score'=>3.40,'factor_weight'=>20],
            ['category'=>'Professionalism','score'=>4.60,'factor_weight'=>20],
        ], [3.2, 3.4, 4.6]);
        self::assertSame('Teaching Effectiveness', $weak['improvement_areas'][0]['title']);
        self::assertSame('weakness', $weak['improvement_areas'][0]['classification']);
        self::assertSame('Professionalism', $weak['strengths'][0]['title']);

        $strong = \performance_report_source_analysis('form_a', 'PMAS Form A', [
            ['category'=>'Leadership','score'=>4.20,'factor_weight'=>50],
            ['category'=>'Planning','score'=>4.50,'factor_weight'=>50],
        ], [4.2, 4.5]);
        self::assertSame('growth_opportunity', $strong['improvement_areas'][0]['classification']);
    }

    public function testDevelopmentRecommendationIsEvidenceDriven(): void
    {
        $activity = \performance_report_development_activity([
            ['category'=>'Teaching Effectiveness'], ['category'=>'Communication Skills'],
        ]);
        self::assertSame('Instructional Strategies and Effective Communication Workshop', $activity['title']);
        self::assertSame('Workshop', $activity['activity_type']);
    }

    public function testConsolidatedMeanUsesEqualSourceWeighting(): void
    {
        self::assertSame(3.5, \performance_report_average([3.0, 4.0, 3.5]));
        self::assertNull(\performance_report_average([]));
    }
}
