<?php

declare(strict_types=1);

namespace PMAS\Tests\Unit;

use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;

final class PeopleAssignmentsTest extends TestCase
{
    private static function db(): PDO
    {
        static $pdo;
        if (!$pdo) {
            $pdo = new PDO('mysql:host=localhost;port=3306;dbname=pmas_test_phpunit;charset=utf8mb4', 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return $pdo;
    }

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/people_assignments.php';
    }

    protected function setUp(): void
    {
        $db = self::db();
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['peer_assignments', 'faculty', 'programs', 'departments', 'users', 'activity_logs'] as $table) {
            $db->exec("TRUNCATE TABLE `{$table}`");
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function department(string $code, string $name): int
    {
        $stmt = self::db()->prepare('INSERT INTO departments (department_code, department_name, is_active) VALUES (?, ?, 1)');
        $stmt->execute([$code, $name]);
        return (int) self::db()->lastInsertId();
    }

    private function user(string $role, string $department, ?string $program = null): int
    {
        $id = random_int(1000, 999999);
        $stmt = self::db()->prepare('INSERT INTO users (full_name, email, password_hash, role, department, program, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');
        $stmt->execute([ucwords(str_replace('_', ' ', $role)), "user{$id}@test.local", password_hash('Password1', PASSWORD_DEFAULT), $role, $department, $program]);
        return (int) self::db()->lastInsertId();
    }

    private function program(int $departmentId, string $code, ?int $headId = null): int
    {
        $stmt = self::db()->prepare('INSERT INTO programs (department_id, program_code, program_name, program_head_user_id, is_active) VALUES (?, ?, ?, ?, 1)');
        $stmt->execute([$departmentId, $code, "Program {$code}", $headId]);
        return (int) self::db()->lastInsertId();
    }

    private function saveAssignment(int $userId, string $role, string $department, string $program): void
    {
        $db = self::db();
        $db->beginTransaction();
        try {
            $assignment = \people_validate_assignment($db, $role, $department, $program, $userId);
            $db->prepare('UPDATE users SET role = ?, department = ?, program = ? WHERE id = ?')->execute([
                $role,
                $assignment['department'],
                $assignment['program'] ?: null,
                $userId,
            ]);
            \people_sync_leadership_assignments($db, $userId, $role, $assignment['department_id'], $assignment['program_id']);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public function testAdminCanViewCurrentDeanProgramAssignment(): void
    {
        $departmentId = $this->department('CITE', 'College of Information Technology and Engineering');
        $this->program($departmentId, 'BSCPE');
        $deanId = $this->user('dean', 'College of Information Technology and Engineering', 'BSCPE');
        $row = self::db()->query("SELECT department, program FROM users WHERE id = {$deanId}")->fetch();
        self::assertSame('BSCPE', $row['program']);
        self::assertSame('College of Information Technology and Engineering', $row['department']);
    }

    public function testAdminCanRemoveStaleProgramFromDeanWithoutRemovingDepartment(): void
    {
        $department = 'College of Information Technology and Engineering';
        $departmentId = $this->department('CITE', $department);
        $deanId = $this->user('dean', $department, 'BSCPE');
        $programId = $this->program($departmentId, 'BSCPE', $deanId);
        $this->saveAssignment($deanId, 'dean', $department, '');
        $user = self::db()->query("SELECT department, program FROM users WHERE id = {$deanId}")->fetch();
        self::assertSame($department, $user['department']);
        self::assertNull($user['program']);
        self::assertNull(self::db()->query("SELECT program_head_user_id FROM programs WHERE id = {$programId}")->fetchColumn());
    }

    public function testChangingProgramHeadToDeanClearsActiveHeadRelationship(): void
    {
        $department = 'College of Information Technology and Engineering';
        $departmentId = $this->department('CITE', $department);
        $userId = $this->user('program_head', $department, 'BSCPE');
        $programId = $this->program($departmentId, 'BSCPE', $userId);
        $this->saveAssignment($userId, 'dean', $department, '');
        self::assertSame('dean', self::db()->query("SELECT role FROM users WHERE id = {$userId}")->fetchColumn());
        self::assertNull(self::db()->query("SELECT program_head_user_id FROM programs WHERE id = {$programId}")->fetchColumn());
    }

    public function testDeanCanBeSavedWithoutProgram(): void
    {
        $department = 'College of Business';
        $this->department('CBA', $department);
        $assignment = \people_validate_assignment(self::db(), 'dean', $department, '');
        self::assertSame('', $assignment['program']);
        self::assertSame($department, $assignment['department']);
    }

    public function testProgramHeadCannotBeSavedWithoutProgram(): void
    {
        $department = 'College of Business';
        $this->department('CBA', $department);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Select a program/course');
        \people_validate_assignment(self::db(), 'program_head', $department, '');
    }

    public function testProgramFromAnotherDepartmentCannotBeAssigned(): void
    {
        $cite = $this->department('CITE', 'College of Information Technology and Engineering');
        $this->department('CBA', 'College of Business');
        $this->program($cite, 'BSCPE');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('does not belong');
        \people_validate_assignment(self::db(), 'dean', 'College of Business', 'BSCPE');
    }

    public function testProgramWithAnotherHeadCannotBeReassignedSilently(): void
    {
        $department = 'College of Business';
        $departmentId = $this->department('CBA', $department);
        $existingHead = $this->user('program_head', $department, 'BSBA');
        $this->program($departmentId, 'BSBA', $existingHead);
        $candidate = $this->user('program_head', $department, null);
        $this->expectException(DomainException::class);
        \people_validate_assignment(self::db(), 'program_head', $department, 'BSBA', $candidate);
    }

    public function testDeanNoLongerAppearsAsProgramHeadAfterSave(): void
    {
        $department = 'College of Business';
        $departmentId = $this->department('CBA', $department);
        $userId = $this->user('program_head', $department, 'BSBA');
        $this->program($departmentId, 'BSBA', $userId);
        $this->saveAssignment($userId, 'dean', $department, '');
        $count = self::db()->query("SELECT COUNT(*) FROM programs WHERE program_head_user_id = {$userId}")->fetchColumn();
        self::assertSame(0, (int) $count);
    }

    public function testUnauthorizedUsersCannotManageAssignments(): void
    {
        self::assertFalse(\people_assignments_admin_authorized(null));
        self::assertFalse(\people_assignments_admin_authorized(['role' => 'dean']));
        self::assertTrue(\people_assignments_admin_authorized(['role' => 'admin_hr']));
    }

    public function testAssignmentChangePreservesEvaluationRecords(): void
    {
        $department = 'College of Business';
        $departmentId = $this->department('CBA', $department);
        $userId = $this->user('program_head', $department, 'BSBA');
        $programId = $this->program($departmentId, 'BSBA', $userId);
        $facultyStmt = self::db()->prepare('INSERT INTO faculty (user_id, full_name, email, department, program_code) VALUES (?, ?, ?, ?, ?)');
        $facultyStmt->execute([$userId, 'Program Head', 'history@test.local', $department, 'BSBA']);
        $facultyId = (int) self::db()->lastInsertId();
        self::db()->prepare("INSERT INTO peer_assignments (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, status) VALUES ('Historical Cycle', ?, ?, 'program_head', 'self', 'submitted')")->execute([$userId, $facultyId]);
        $historyId = (int) self::db()->lastInsertId();
        $this->saveAssignment($userId, 'dean', $department, '');
        self::assertSame(1, (int) self::db()->query("SELECT COUNT(*) FROM peer_assignments WHERE id = {$historyId}")->fetchColumn());
        self::assertNull(self::db()->query("SELECT program_head_user_id FROM programs WHERE id = {$programId}")->fetchColumn());
    }
}

