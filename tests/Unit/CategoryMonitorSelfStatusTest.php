<?php

declare(strict_types=1);

namespace PMAS\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CategoryMonitorSelfStatusTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/self_evaluation_status.php';
    }

    public function testSubmittedRecordIsSubmitted(): void
    {
        self::assertSame('submitted', \evaluator_monitor_normalize_self_status(['status' => 'submitted'], ['status' => 'submitted']));
    }

    public function testReopenedRecordIsReopened(): void
    {
        self::assertSame('reopened', \evaluator_monitor_normalize_self_status(['status' => 'reopened'], ['status' => 'pending']));
    }

    public function testOnlySubmittedStatusQualifiesForViewing(): void
    {
        foreach (['pending', 'in_progress', 'reopened', 'overdue', 'not_required'] as $status) {
            self::assertNotSame('submitted', $status);
        }
        self::assertSame('submitted', \evaluator_monitor_normalize_self_status(['status' => 'submitted'], ['status' => 'submitted']));
    }

    public function testDraftWithActivityIsInProgress(): void
    {
        self::assertSame('in_progress', \evaluator_monitor_normalize_self_status(['status' => 'draft', 'updated_at' => '2026-07-11 01:00:00'], ['status' => 'pending']));
    }

    public function testMissingAssignmentIsNotRequired(): void
    {
        self::assertSame('not_required', \evaluator_monitor_normalize_self_status(null, null));
    }
}
