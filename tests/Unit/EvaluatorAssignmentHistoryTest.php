<?php

declare(strict_types=1);

namespace PMAS\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EvaluatorAssignmentHistoryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/evaluation_assignment_generator.php';
    }

    public function testSubmittedOldHeadRemainsOfficial(): void
    {
        self::assertSame('official_submitted', \dipascaf_program_head_transition_policy([
            ['evaluator_user_id' => 10, 'status' => 'submitted'],
        ], 20));
    }

    public function testPendingOldHeadIsReassigned(): void
    {
        self::assertSame('reassign_active', \dipascaf_program_head_transition_policy([
            ['evaluator_user_id' => 10, 'status' => 'pending'],
        ], 20));
    }

    public function testStartedOldHeadIsReassignedWithoutDeletingDraft(): void
    {
        self::assertSame('reassign_active', \dipascaf_program_head_transition_policy([
            ['evaluator_user_id' => 10, 'status' => 'in_progress'],
        ], 20));
    }

    public function testCurrentHeadDoesNotDuplicateOwnPendingAssignment(): void
    {
        self::assertSame('create', \dipascaf_program_head_transition_policy([
            ['evaluator_user_id' => 20, 'status' => 'pending'],
        ], 20));
    }

    public function testLatestSubmittedRecordWinsAfterMultipleHeadChanges(): void
    {
        self::assertSame('official_submitted', \dipascaf_program_head_transition_policy([
            ['evaluator_user_id' => 10, 'status' => 'reassigned'],
            ['evaluator_user_id' => 20, 'status' => 'submitted'],
            ['evaluator_user_id' => 30, 'status' => 'pending'],
        ], 30));
    }
}
