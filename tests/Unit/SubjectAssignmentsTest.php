<?php
declare(strict_types=1);

namespace PMAS\Tests\Unit;

use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;

final class SubjectAssignmentsTest extends TestCase
{
    private static PDO $db;

    public static function setUpBeforeClass(): void
    {
        include_source_silently(dirname(__DIR__, 2) . '/includes/subject_assignments.php');
        self::$db = \db();
        \subject_assignments_ensure_schema();
    }

    protected function setUp(): void
    {
        self::$db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (self::$db->inTransaction()) {
            self::$db->rollBack();
        }
    }

    public function testMultipleSubjectsRequireAnExplicitAssignedPrimary(): void
    {
        $departmentId = $this->department();
        $subjectIds = $this->subjects($departmentId);

        $validated = \subject_assignments_validate(self::$db, $departmentId, $subjectIds, $subjectIds[1]);
        self::assertSame($subjectIds, $validated);

        $this->expectException(DomainException::class);
        \subject_assignments_validate(self::$db, $departmentId, $subjectIds, 999999);
    }

    public function testCoordinatorDesignationDoesNotChangeFacultyRole(): void
    {
        $departmentId = $this->department();
        $subjectIds = $this->subjects($departmentId);
        [$userId, $facultyId] = $this->faculty($departmentId);

        \subject_assignments_sync_faculty(self::$db, $facultyId, $subjectIds, $subjectIds[0]);
        \subject_assignments_sync_coordinator_designations(self::$db, $facultyId, $subjectIds, [$subjectIds[1]]);

        $assignments = \subject_assignments_for_faculty(self::$db, $facultyId);
        self::assertCount(2, $assignments);
        self::assertSame($subjectIds[0], (int)$assignments[0]['id']);
        self::assertSame(1, (int)$assignments[0]['is_primary']);
        self::assertSame(1, (int)array_values(array_filter($assignments, static fn(array $row): bool => (int)$row['id'] === $subjectIds[1]))[0]['is_coordinator']);
        self::assertSame('teacher', self::$db->query("SELECT role FROM users WHERE id={$userId}")->fetchColumn());
    }

    private function department(): int
    {
        self::$db->exec("INSERT INTO departments(department_code,department_name,is_active) VALUES('SUBJ','Subject Test Department',1)");
        return (int)self::$db->lastInsertId();
    }

    private function subjects(int $departmentId): array
    {
        $stmt = self::$db->prepare('INSERT INTO subject_areas(department_id,subject_code,subject_name) VALUES (?,?,?)');
        $ids = [];
        foreach ([['ONE','Subject One'],['TWO','Subject Two']] as [$code,$name]) {
            $stmt->execute([$departmentId,$code,$name]);
            $ids[] = (int)self::$db->lastInsertId();
        }
        return $ids;
    }

    private function faculty(int $departmentId): array
    {
        self::$db->exec("INSERT INTO users(full_name,email,password_hash,role,is_active,department) VALUES('Subject Faculty','subject-faculty@test.local','x','teacher',1,'Subject Test Department')");
        $userId = (int)self::$db->lastInsertId();
        self::$db->prepare("INSERT INTO faculty(user_id,full_name,email,department,position_title,is_active) VALUES(?, 'Subject Faculty','subject-faculty@test.local','Subject Test Department','Faculty',1)")->execute([$userId]);
        return [$userId, (int)self::$db->lastInsertId()];
    }
}
