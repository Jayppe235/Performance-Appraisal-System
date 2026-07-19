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

    public function testDraftResponseIsExplicitlyNonMutating(): void
    {
        $payload = \assistant_copilot_payload('Draft plan', 'Draft an action plan', 'draft', ['id' => 4, 'role' => 'program_head', 'department' => 'CITE', 'program' => 'BSIT']);
        self::assertStringContainsString('Draft only', $payload['draft']);
        self::assertSame('draft', $payload['intent']);
    }
}
