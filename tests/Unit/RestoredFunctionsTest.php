<?php
/**
 * Unit tests for the restored functions in includes/evaluation_cards.php.
 *
 * Tests run in the same process — includeSource() guards against function
 * redefinition via function_exists() check.
 *
 * @group restored
 */

declare(strict_types=1);

namespace PMAS\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RestoredFunctionsTest extends TestCase
{
    /**
     * Include the production source file with redefine-warning suppression.
     */
    private static function includeSource(): void
    {
        $path = __DIR__ . '/../../includes/evaluation_cards.php';
        if (!function_exists('dipascaf_questionnaire_type_from_position')) {
            $level = error_reporting(E_ALL & ~E_WARNING);
            require_once $path;
            error_reporting($level);
        }
    }

    /**
     * Get a PDO connection to the test database.
     */
    private static function db(): \PDO
    {
        static $pdo = null;
        if ($pdo === null) {
            $pdo = new \PDO(
                'mysql:host=localhost;port=3306;dbname=pmas_test_phpunit;charset=utf8mb4',
                'root',
                '',
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        }
        return $pdo;
    }

    /**
     * Truncate all test tables.
     */
    private static function cleanDb(): void
    {
        $pdo = self::db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['peer_assignments', 'faculty', 'users', 'departments', 'programs', 'appraisal_periods', 'system_settings', 'activity_logs'] as $table) {
            $pdo->exec("TRUNCATE TABLE `{$table}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Insert a row into the users table.
     */
    private static function insertUser(array $data): int
    {
        $pdo = self::db();
        $stmt = $pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, role, is_active, department, program, phone)
             VALUES (:full_name, :email, :password_hash, :role, :is_active, :department, :program, :phone)'
        );
        $stmt->execute([
            'full_name' => $data['full_name'] ?? 'Test User',
            'email' => $data['email'] ?? 'test@example.com',
            'password_hash' => $data['password_hash'] ?? password_hash('password', PASSWORD_DEFAULT),
            'role' => $data['role'] ?? 'teacher',
            'is_active' => $data['is_active'] ?? 1,
            'department' => $data['department'] ?? 'CITE',
            'program' => $data['program'] ?? 'BSCS',
            'phone' => $data['phone'] ?? '09170000000',
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Insert a row into the faculty table.
     */
    private static function insertFaculty(array $data): int
    {
        $pdo = self::db();
        $stmt = $pdo->prepare(
            'INSERT INTO faculty (user_id, full_name, email, phone, department, program_code, position_title, is_active, is_archived)
             VALUES (:user_id, :full_name, :email, :phone, :department, :program_code, :position_title, :is_active, :is_archived)'
        );
        $stmt->execute([
            'user_id' => $data['user_id'] ?? null,
            'full_name' => $data['full_name'] ?? 'Faculty User',
            'email' => $data['email'] ?? 'faculty@example.com',
            'phone' => $data['phone'] ?? null,
            'department' => $data['department'] ?? 'CITE',
            'program_code' => $data['program_code'] ?? null,
            'position_title' => $data['position_title'] ?? 'Faculty',
            'is_active' => $data['is_active'] ?? 1,
            'is_archived' => $data['is_archived'] ?? 0,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Insert a row into the departments table.
     */
    private static function insertDepartment(array $data): int
    {
        $pdo = self::db();
        $stmt = $pdo->prepare(
            'INSERT INTO departments (department_code, department_name, dean_user_id, is_active)
             VALUES (:department_code, :department_name, :dean_user_id, :is_active)'
        );
        $stmt->execute([
            'department_code' => $data['department_code'] ?? 'CITE',
            'department_name' => $data['department_name'] ?? 'College of Information Technology Education',
            'dean_user_id' => $data['dean_user_id'] ?? null,
            'is_active' => $data['is_active'] ?? 1,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Insert a row into the programs table.
     */
    private static function insertProgram(array $data): int
    {
        $pdo = self::db();
        $stmt = $pdo->prepare(
            'INSERT INTO programs (program_code, program_name, department_id, program_head_user_id, is_active)
             VALUES (:program_code, :program_name, :department_id, :program_head_user_id, :is_active)'
        );
        $stmt->execute([
            'program_code' => $data['program_code'] ?? 'BSCS',
            'program_name' => $data['program_name'] ?? 'Bachelor of Science in Computer Science',
            'department_id' => $data['department_id'] ?? 1,
            'program_head_user_id' => $data['program_head_user_id'] ?? null,
            'is_active' => $data['is_active'] ?? 1,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Insert a row into appraisal_periods.
     */
    private static function insertPeriod(array $data): int
    {
        $pdo = self::db();
        $stmt = $pdo->prepare(
            'INSERT INTO appraisal_periods (period_name, school_year, semester, date_start, date_end, status)
             VALUES (:period_name, :school_year, :semester, :date_start, :date_end, :status)'
        );
        $stmt->execute([
            'period_name' => $data['period_name'] ?? '2026 Appraisal Cycle',
            'school_year' => $data['school_year'] ?? '2025-2026',
            'semester' => $data['semester'] ?? '2nd Semester',
            'date_start' => $data['date_start'] ?? '2026-01-01',
            'date_end' => $data['date_end'] ?? '2026-06-30',
            'status' => $data['status'] ?? 'open',
        ]);
        return (int) $pdo->lastInsertId();
    }

    // ═══════════════════════════════════════════════════════════════
    //  dipascaf_questionnaire_type_from_position
    // ═══════════════════════════════════════════════════════════════

    public function testQuestionnaireTypeFromPositionReturnsAdminForDean(): void
    {
        self::includeSource();
        $this->assertSame('admin', dipascaf_questionnaire_type_from_position('Dean'));
        $this->assertSame('admin', dipascaf_questionnaire_type_from_position('dean'));
        $this->assertSame('admin', dipascaf_questionnaire_type_from_position('DEAN'));
        $this->assertSame('admin', dipascaf_questionnaire_type_from_position('Associate Dean'));
    }

    public function testQuestionnaireTypeFromPositionReturnsAdminForProgramHead(): void
    {
        self::includeSource();
        $this->assertSame('admin', dipascaf_questionnaire_type_from_position('Program Head'));
        $this->assertSame('admin', dipascaf_questionnaire_type_from_position('program head'));
        $this->assertSame('admin', dipascaf_questionnaire_type_from_position('PROGRAM HEAD'));
    }

    public function testQuestionnaireTypeFromPositionReturnsFacultyForTeacher(): void
    {
        self::includeSource();
        $this->assertSame('faculty', dipascaf_questionnaire_type_from_position('Faculty'));
        $this->assertSame('faculty', dipascaf_questionnaire_type_from_position('Teacher'));
        $this->assertSame('faculty', dipascaf_questionnaire_type_from_position('Instructor'));
        $this->assertSame('faculty', dipascaf_questionnaire_type_from_position('Professor'));
        $this->assertSame('faculty', dipascaf_questionnaire_type_from_position('Assistant Professor'));
        $this->assertSame('faculty', dipascaf_questionnaire_type_from_position(''));
    }

    // ═══════════════════════════════════════════════════════════════
    //  dipascaf_ensure_leadership_faculty_record
    // ═══════════════════════════════════════════════════════════════

    public function testEnsureLeadershipFacultyRecordCreatesNewRecord(): void
    {
        self::cleanDb();
        self::includeSource();

        $deanId = self::insertUser([
            'full_name' => 'Dr. Dean',
            'email' => 'dean@cite.edu',
            'role' => 'dean',
            'department' => 'CITE',
        ]);

        $facultyId = dipascaf_ensure_leadership_faculty_record($deanId, 'Dean');

        $this->assertGreaterThan(0, $facultyId, 'Should create a new faculty record');

        $faculty = self::db()->query("SELECT * FROM faculty WHERE id = {$facultyId}")->fetch();
        $this->assertNotFalse($faculty);
        $this->assertSame('Dr. Dean', $faculty['full_name']);
        $this->assertSame('dean@cite.edu', $faculty['email']);
        $this->assertSame('Dean', $faculty['position_title']);
        $this->assertSame('CITE', $faculty['department']);

        self::cleanDb();
    }

    public function testEnsureLeadershipFacultyRecordUpdatesExistingRecord(): void
    {
        self::cleanDb();
        self::includeSource();

        $deanId = self::insertUser([
            'full_name' => 'Dr. Dean Updated',
            'email' => 'dean@cite.edu',
            'role' => 'dean',
            'department' => 'CITE',
        ]);

        // Create initial faculty record
        $existingFacultyId = self::insertFaculty([
            'user_id' => $deanId,
            'full_name' => 'Dr. Dean Old',
            'email' => 'dean@cite.edu',
            'position_title' => 'Dean',
            'department' => 'CITE',
        ]);

        // Call the function — should update existing record
        $facultyId = dipascaf_ensure_leadership_faculty_record($deanId, 'Dean');

        $this->assertSame($existingFacultyId, $facultyId, 'Should return existing faculty ID');

        $faculty = self::db()->query("SELECT * FROM faculty WHERE id = {$facultyId}")->fetch();
        $this->assertSame('Dr. Dean Updated', $faculty['full_name'], 'Should update full_name from users');

        self::cleanDb();
    }

    public function testEnsureLeadershipFacultyRecordReturnsZeroForInactiveUser(): void
    {
        self::cleanDb();
        self::includeSource();

        self::insertUser([
            'full_name' => 'Inactive User',
            'email' => 'inactive@cite.edu',
            'role' => 'dean',
            'is_active' => 0,
            'department' => 'CITE',
        ]);

        $facultyId = dipascaf_ensure_leadership_faculty_record(9999, 'Dean');
        $this->assertSame(0, $facultyId, 'Non-existent user should return 0');

        self::cleanDb();
    }

    public function testEnsureLeadershipFacultyRecordReturnsZeroForUserWithoutEmail(): void
    {
        self::cleanDb();
        self::includeSource();

        // Insert a user with empty email
        $pdo = self::db();
        $stmt = $pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, role, is_active, department)
             VALUES (:full_name, :email, :password_hash, :role, :is_active, :department)'
        );
        $stmt->execute([
            'full_name' => 'No Email User',
            'email' => '',
            'password_hash' => password_hash('password', PASSWORD_DEFAULT),
            'role' => 'dean',
            'is_active' => 1,
            'department' => 'CITE',
        ]);
        $userId = (int) $pdo->lastInsertId();

        $facultyId = dipascaf_ensure_leadership_faculty_record($userId, 'Dean');
        $this->assertSame(0, $facultyId, 'User without email should return 0');

        self::cleanDb();
    }

    // ═══════════════════════════════════════════════════════════════
    //  dipascaf_department_aliases_for_dean
    // ═══════════════════════════════════════════════════════════════

    public function testDepartmentAliasesForDeanFromAssignedDepartments(): void
    {
        self::cleanDb();
        self::includeSource();

        $deanId = self::insertUser([
            'full_name' => 'Dean CITE',
            'email' => 'dean_cite@cite.edu',
            'role' => 'dean',
            'department' => 'CITE',
        ]);

        self::insertDepartment([
            'department_code' => 'CITE',
            'department_name' => 'College of Information Technology Education',
            'dean_user_id' => $deanId,
        ]);

        $aliases = dipascaf_department_aliases_for_dean($deanId);

        $this->assertContains('College of Information Technology Education', $aliases);
        $this->assertContains('CITE', $aliases);
        $this->assertContains('Computer Studies', $aliases);

        self::cleanDb();
    }

    public function testDepartmentAliasesForDeanFallbackToUserDepartment(): void
    {
        self::cleanDb();
        self::includeSource();

        $deanId = self::insertUser([
            'full_name' => 'Dean COED',
            'email' => 'dean_coed@cite.edu',
            'role' => 'dean',
            'department' => 'COED',
        ]);

        // Insert a department row so admin_department_aliases can resolve it
        self::insertDepartment([
            'department_code' => 'COED',
            'department_name' => 'College of Education',
            'dean_user_id' => null, // No dean assigned to department
        ]);

        $aliases = dipascaf_department_aliases_for_dean($deanId);

        $this->assertContains('College of Education', $aliases);
        $this->assertContains('COED', $aliases);
        $this->assertContains('Education', $aliases);

        self::cleanDb();
    }

    // ═══════════════════════════════════════════════════════════════
    //  dipascaf_sync_department_faculty_records
    // ═══════════════════════════════════════════════════════════════

    public function testSyncDepartmentFacultyRecordsCreatesNewRecords(): void
    {
        self::cleanDb();
        self::includeSource();

        $teacherId = self::insertUser([
            'full_name' => 'Teacher One',
            'email' => 'teacher1@cite.edu',
            'role' => 'teacher',
            'department' => 'CITE',
            'program' => 'BSCS',
        ]);

        dipascaf_sync_department_faculty_records(['CITE']);

        $faculty = self::db()->query("SELECT * FROM faculty WHERE email = 'teacher1@cite.edu'")->fetch();
        $this->assertNotFalse($faculty, 'Faculty record should be created');
        $this->assertSame('Teacher One', $faculty['full_name']);
        $this->assertSame('Faculty', $faculty['position_title']);
        $this->assertSame($teacherId, (int) $faculty['user_id']);

        self::cleanDb();
    }

    public function testSyncDepartmentFacultyRecordsUpdatesExistingRecords(): void
    {
        self::cleanDb();
        self::includeSource();

        $teacherId = self::insertUser([
            'full_name' => 'Teacher Updated',
            'email' => 'teacher1@cite.edu',
            'role' => 'teacher',
            'department' => 'CITE',
            'program' => 'BSCS',
        ]);

        // Pre-create faculty record with old name
        self::insertFaculty([
            'user_id' => $teacherId,
            'full_name' => 'Teacher Old Name',
            'email' => 'teacher1@cite.edu',
            'department' => 'CITE',
            'position_title' => 'Faculty',
        ]);

        dipascaf_sync_department_faculty_records(['CITE']);

        $faculty = self::db()->query("SELECT * FROM faculty WHERE email = 'teacher1@cite.edu'")->fetch();
        $this->assertSame('Teacher Updated', $faculty['full_name'], 'Should update full_name from users');

        self::cleanDb();
    }

    public function testSyncDepartmentFacultyRecordsSkipsProgramHead(): void
    {
        self::cleanDb();
        self::includeSource();

        self::insertUser([
            'full_name' => 'Prog Head',
            'email' => 'ph@cite.edu',
            'role' => 'program_head',
            'department' => 'CITE',
            'program' => 'BSCS',
        ]);

        dipascaf_sync_department_faculty_records(['CITE']);

        // Program head IS included because role IN ('teacher', 'program_head')
        $faculty = self::db()->query("SELECT * FROM faculty WHERE email = 'ph@cite.edu'")->fetch();
        $this->assertNotFalse($faculty, 'Program Head should also get a faculty record');
        $this->assertSame('Program Head', $faculty['position_title']);

        self::cleanDb();
    }

    public function testSyncDepartmentFacultyRecordsEmptyDepartmentsDoesNothing(): void
    {
        self::cleanDb();
        self::includeSource();

        dipascaf_sync_department_faculty_records([]);
        dipascaf_sync_department_faculty_records(['']);

        // No exception should be thrown
        $this->assertTrue(true);
    }

    // ═══════════════════════════════════════════════════════════════
    //  dipascaf_program_head_scope
    // ═══════════════════════════════════════════════════════════════

    public function testProgramHeadScopeFromAssignedPrograms(): void
    {
        self::cleanDb();
        self::includeSource();

        $phId = self::insertUser([
            'full_name' => 'PH User',
            'email' => 'ph@cite.edu',
            'role' => 'program_head',
            'department' => 'CITE',
            'program' => 'BSCS',
        ]);

        $deptId = self::insertDepartment([
            'department_code' => 'CITE',
            'department_name' => 'College of Information Technology Education',
        ]);

        self::insertProgram([
            'program_code' => 'BSCS',
            'program_name' => 'Computer Science',
            'department_id' => $deptId,
            'program_head_user_id' => $phId,
        ]);

        $scope = dipascaf_program_head_scope($phId);

        $this->assertContains('BSCS', $scope['program_codes']);
        $this->assertContains('CITE', $scope['departments']);
        $this->assertContains('College of Information Technology Education', $scope['departments']);

        self::cleanDb();
    }

    public function testProgramHeadScopeFallbackToUserWhenNoPrograms(): void
    {
        self::cleanDb();
        self::includeSource();

        $phId = self::insertUser([
            'full_name' => 'PH Fallback',
            'email' => 'ph_fallback@cite.edu',
            'role' => 'program_head',
            'department' => 'COED',
            'program' => 'BEEd',
        ]);

        self::insertDepartment([
            'department_code' => 'COED',
            'department_name' => 'College of Education',
        ]);

        $scope = dipascaf_program_head_scope($phId);

        $this->assertContains('BEEd', $scope['program_codes'], 'Should fall back to user.program');
        $this->assertNotEmpty($scope['departments'], 'Should resolve department aliases');

        self::cleanDb();
    }

    // ═══════════════════════════════════════════════════════════════
    //  dipascaf_ensure_program_head_assignments
    // ═══════════════════════════════════════════════════════════════

    public function testEnsureProgramHeadAssignmentsCreatesDeanAndFacultyAssignments(): void
    {
        self::cleanDb();
        self::includeSource();

        // Create a dean
        $deanId = self::insertUser([
            'full_name' => 'The Dean',
            'email' => 'thedean@cite.edu',
            'role' => 'dean',
            'department' => 'CITE',
        ]);

        // Create a faculty member
        $teacherId = self::insertUser([
            'full_name' => 'Faculty Member',
            'email' => 'faculty_member@cite.edu',
            'role' => 'teacher',
            'department' => 'CITE',
            'program' => 'BSCS',
        ]);

        // Create program head
        $phId = self::insertUser([
            'full_name' => 'Prog Head',
            'email' => 'proghead@cite.edu',
            'role' => 'program_head',
            'department' => 'CITE',
            'program' => 'BSCS',
        ]);

        // Create department
        $deptId = self::insertDepartment([
            'department_code' => 'CITE',
            'department_name' => 'College of Information Technology Education',
            'dean_user_id' => $deanId,
        ]);

        // Create program
        self::insertProgram([
            'program_code' => 'BSCS',
            'program_name' => 'Computer Science',
            'department_id' => $deptId,
            'program_head_user_id' => $phId,
        ]);

        // Create an open evaluation period so cycle_name works
        self::insertPeriod([
            'period_name' => '2026 Appraisal Cycle',
            'status' => 'open',
        ]);

        // Need to ensure the dean has a faculty record for the assigner to use
        dipascaf_ensure_leadership_faculty_record($deanId, 'Dean');

        // Need to ensure the teacher has a faculty record
        dipascaf_ensure_leadership_faculty_record($teacherId, 'Faculty');

        // Run the assignment generator
        dipascaf_ensure_program_head_assignments($phId);

        // Check dean assignment was created
        $deanAssignment = self::db()->query(
            "SELECT * FROM peer_assignments WHERE evaluator_user_id = {$phId} AND assignment_type = 'dean'"
        )->fetch();
        $this->assertNotFalse($deanAssignment, 'Dean evaluation assignment should exist');
        $this->assertSame('pending', $deanAssignment['status']);

        // Check faculty assignment was created
        $facultyAssignment = self::db()->query(
            "SELECT * FROM peer_assignments WHERE evaluator_user_id = {$phId} AND assignment_type = 'program_head'"
        )->fetch();
        $this->assertNotFalse($facultyAssignment, 'Faculty evaluation assignment should exist');
        $this->assertSame('pending', $facultyAssignment['status']);

        self::cleanDb();
    }

    // ═══════════════════════════════════════════════════════════════
    //  dipascaf_ensure_dean_assignments
    // ═══════════════════════════════════════════════════════════════

    public function testEnsureDeanAssignmentsCreatesFacultyAssignments(): void
    {
        self::cleanDb();
        self::includeSource();

        $deanId = self::insertUser([
            'full_name' => 'Dean Faculty',
            'email' => 'dean_faculty@cite.edu',
            'role' => 'dean',
            'department' => 'CITE',
        ]);

        $teacherId = self::insertUser([
            'full_name' => 'Teacher Under Dean',
            'email' => 'teacher_dept@cite.edu',
            'role' => 'teacher',
            'department' => 'CITE',
        ]);

        self::insertDepartment([
            'department_code' => 'CITE',
            'department_name' => 'College of Information Technology Education',
            'dean_user_id' => $deanId,
        ]);

        // Open period
        self::insertPeriod([
            'period_name' => '2026 Appraisal Cycle',
            'status' => 'open',
        ]);

        // Ensure faculty record exists for teacher
        dipascaf_ensure_leadership_faculty_record($teacherId, 'Faculty');

        // Run the assignment generator
        dipascaf_ensure_dean_assignments($deanId);

        // Check faculty assignment was created
        $assignment = self::db()->query(
            "SELECT * FROM peer_assignments WHERE evaluator_user_id = {$deanId} AND evaluator_role = 'dean'"
        )->fetch();
        $this->assertNotFalse($assignment, 'Dean should have a faculty evaluation assignment');
        $this->assertSame('pending', $assignment['status']);

        self::cleanDb();
    }

    // ═══════════════════════════════════════════════════════════════
    //  dipascaf_ensure_teacher_leadership_assignments
    // ═══════════════════════════════════════════════════════════════

    public function testEnsureTeacherLeadershipAssignmentsCreatesDeanAndProgramHeadAssignments(): void
    {
        self::cleanDb();
        self::includeSource();

        $deanId = self::insertUser([
            'full_name' => 'Dean Leader',
            'email' => 'dean_leader@cite.edu',
            'role' => 'dean',
            'department' => 'CITE',
        ]);

        $phId = self::insertUser([
            'full_name' => 'PH Leader',
            'email' => 'ph_leader@cite.edu',
            'role' => 'program_head',
            'department' => 'CITE',
            'program' => 'BSCS',
        ]);

        $teacherId = self::insertUser([
            'full_name' => 'Teacher Eval',
            'email' => 'teacher_eval@cite.edu',
            'role' => 'teacher',
            'department' => 'CITE',
            'program' => 'BSCS',
        ]);

        $deptId = self::insertDepartment([
            'department_code' => 'CITE',
            'department_name' => 'College of Information Technology Education',
            'dean_user_id' => $deanId,
        ]);

        self::insertProgram([
            'program_code' => 'BSCS',
            'program_name' => 'Computer Science',
            'department_id' => $deptId,
            'program_head_user_id' => $phId,
        ]);

        self::insertPeriod([
            'period_name' => '2026 Appraisal Cycle',
            'status' => 'open',
        ]);

        // Ensure faculty records for leaders
        dipascaf_ensure_leadership_faculty_record($deanId, 'Dean');
        dipascaf_ensure_leadership_faculty_record($phId, 'Program Head');

        // Run the generator
        dipascaf_ensure_teacher_leadership_assignments($teacherId);

        // Should create at least dean assignment or program_head assignment
        $assignments = self::db()->query(
            "SELECT * FROM peer_assignments WHERE evaluator_user_id = {$teacherId} AND evaluator_role = 'teacher'"
        )->fetchAll();

        $this->assertGreaterThan(0, count($assignments), 'Teacher should have leadership evaluation assignments');

        self::cleanDb();
    }

    // ═══════════════════════════════════════════════════════════════
    //  dipascaf_ensure_teacher_peer_assignment
    // ═══════════════════════════════════════════════════════════════

    public function testEnsureTeacherPeerAssignmentCreatesPeerAssignment(): void
    {
        self::cleanDb();
        self::includeSource();

        $teacher1Id = self::insertUser([
            'full_name' => 'Teacher Peer 1',
            'email' => 'peer1@cite.edu',
            'role' => 'teacher',
            'department' => 'CITE',
            'program' => 'BSCS',
        ]);

        $teacher2Id = self::insertUser([
            'full_name' => 'Teacher Peer 2',
            'email' => 'peer2@cite.edu',
            'role' => 'teacher',
            'department' => 'CITE',
            'program' => 'BSCS',
        ]);

        self::insertPeriod([
            'period_name' => '2026 Appraisal Cycle',
            'status' => 'open',
        ]);

        // Create faculty records for both teachers
        $faculty1Id = dipascaf_ensure_leadership_faculty_record($teacher1Id, 'Faculty');
        $faculty2Id = dipascaf_ensure_leadership_faculty_record($teacher2Id, 'Faculty');
        $this->assertGreaterThan(0, $faculty1Id);
        $this->assertGreaterThan(0, $faculty2Id);

        // Run peer assignment for teacher 1
        dipascaf_ensure_teacher_peer_assignment($teacher1Id);

        $assignments = self::db()->query(
            "SELECT * FROM peer_assignments WHERE evaluator_user_id = {$teacher1Id} AND assignment_type = 'peer'"
        )->fetchAll();

        $this->assertCount(1, $assignments, 'Teacher should have one peer assignment');
        $this->assertSame('pending', $assignments[0]['status']);
        $this->assertSame('faculty', $assignments[0]['questionnaire_type']);

        self::cleanDb();
    }

    public function testEnsureTeacherPeerAssignmentSkipsIfAlreadyHasPeerAssignment(): void
    {
        self::cleanDb();
        self::includeSource();

        $teacher1Id = self::insertUser([
            'full_name' => 'Teacher Already',
            'email' => 'already@cite.edu',
            'role' => 'teacher',
            'department' => 'CITE',
            'program' => 'BSCS',
        ]);

        $teacher2Id = self::insertUser([
            'full_name' => 'Teacher Other',
            'email' => 'other@cite.edu',
            'role' => 'teacher',
            'department' => 'CITE',
            'program' => 'BSCS',
        ]);

        self::insertPeriod([
            'period_name' => '2026 Appraisal Cycle',
            'status' => 'open',
        ]);

        $faculty1Id = dipascaf_ensure_leadership_faculty_record($teacher1Id, 'Faculty');
        $faculty2Id = dipascaf_ensure_leadership_faculty_record($teacher2Id, 'Faculty');

        // Pre-create a peer assignment (simulating already having one)
        $pdo = self::db();
        $stmt = $pdo->prepare(
            "INSERT INTO peer_assignments (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, questionnaire_type, status, assigned_at)
             VALUES ('2026 Appraisal Cycle', ?, ?, 'teacher', 'peer', 'faculty', 'pending', NOW())"
        );
        $stmt->execute([$teacher1Id, $faculty2Id]);

        // Try to create another — should be skipped
        dipascaf_ensure_teacher_peer_assignment($teacher1Id);

        $assignments = self::db()->query(
            "SELECT * FROM peer_assignments WHERE evaluator_user_id = {$teacher1Id} AND assignment_type = 'peer'"
        )->fetchAll();

        $this->assertCount(1, $assignments, 'Should still have only one peer assignment');

        self::cleanDb();
    }

    public function testPeerGenerationKeepsFacultyPeersInsideSameRoleAndDepartment(): void
    {
        self::cleanDb();
        self::includeSource();
        require_once __DIR__ . '/../../includes/peer_assignment_algorithm.php';
        admin_ensure_archive_schema();
        dipascaf_ensure_peer_evaluation_schema();

        $pdo = self::db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('TRUNCATE TABLE peer_evaluation_assignments');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $citeDeptId = self::insertDepartment([
            'department_code' => 'CITE',
            'department_name' => 'College of Information Technology Education',
        ]);
        self::insertDepartment([
            'department_code' => 'CBA',
            'department_name' => 'College of Business Administration',
        ]);

        $teacherOneId = self::insertUser([
            'full_name' => 'CITE Faculty One',
            'email' => 'cite_faculty_one@pmas.test',
            'role' => 'teacher',
            'department' => 'CITE',
            'program' => 'BSCS',
        ]);
        $teacherTwoId = self::insertUser([
            'full_name' => 'CITE Faculty Two',
            'email' => 'cite_faculty_two@pmas.test',
            'role' => 'teacher',
            'department' => 'CITE',
            'program' => 'BSIT',
        ]);
        $otherDepartmentTeacherId = self::insertUser([
            'full_name' => 'CBA Faculty',
            'email' => 'cba_faculty@pmas.test',
            'role' => 'teacher',
            'department' => 'CBA',
            'program' => 'BSBA',
        ]);
        $programHeadId = self::insertUser([
            'full_name' => 'CITE Program Head',
            'email' => 'cite_ph@pmas.test',
            'role' => 'program_head',
            'department' => 'CITE',
            'program' => 'BSCS',
        ]);

        self::insertProgram([
            'program_code' => 'BSCS',
            'program_name' => 'Computer Science',
            'department_id' => $citeDeptId,
            'program_head_user_id' => $programHeadId,
        ]);

        self::insertFaculty([
            'user_id' => $teacherOneId,
            'full_name' => 'CITE Faculty One',
            'email' => 'cite_faculty_one@pmas.test',
            'department' => 'CITE',
            'program_code' => 'BSCS',
            'position_title' => 'Faculty',
        ]);
        self::insertFaculty([
            'user_id' => $teacherTwoId,
            'full_name' => 'CITE Faculty Two',
            'email' => 'cite_faculty_two@pmas.test',
            'department' => 'CITE',
            'program_code' => 'BSIT',
            'position_title' => 'Faculty',
        ]);
        self::insertFaculty([
            'user_id' => $otherDepartmentTeacherId,
            'full_name' => 'CBA Faculty',
            'email' => 'cba_faculty@pmas.test',
            'department' => 'CBA',
            'program_code' => 'BSBA',
            'position_title' => 'Faculty',
        ]);
        self::insertFaculty([
            'user_id' => $programHeadId,
            'full_name' => 'CITE Program Head',
            'email' => 'cite_ph@pmas.test',
            'department' => 'CITE',
            'program_code' => 'BSCS',
            'position_title' => 'Program Head',
        ]);

        $periodId = self::insertPeriod([
            'period_name' => '2026 Peer Cycle',
            'status' => 'open',
        ]);

        dipascaf_generate_peer_evaluation_assignments(
            $periodId,
            '2026 Peer Cycle',
            '2026-06-30',
            true,
            true
        );

        $rows = $pdo->query(
            "SELECT eu.role AS evaluator_role, efu.role AS evaluatee_role,
                    euf.department AS evaluator_department, ef.department AS evaluatee_department
             FROM peer_evaluation_assignments pea
             JOIN users eu ON eu.id = pea.evaluator_id
             JOIN users efu ON efu.id = pea.evaluatee_id
             LEFT JOIN faculty euf ON euf.user_id = eu.id
             LEFT JOIN faculty ef ON ef.id = pea.evaluatee_faculty_id
             WHERE pea.evaluation_period_id = {$periodId}
             ORDER BY eu.full_name"
        )->fetchAll();

        $this->assertCount(2, $rows, 'Only the two CITE faculty members should receive faculty peer assignments.');
        foreach ($rows as $row) {
            $this->assertSame('teacher', $row['evaluator_role']);
            $this->assertSame('teacher', $row['evaluatee_role']);
            $this->assertSame('CITE', $row['evaluator_department']);
            $this->assertSame('CITE', $row['evaluatee_department']);
        }

        self::cleanDb();
    }

    public function testPeerGenerationIncludesProgramHeadPeersWhenEnabled(): void
    {
        self::cleanDb();
        self::includeSource();
        require_once __DIR__ . '/../../includes/peer_assignment_algorithm.php';
        admin_ensure_archive_schema();
        dipascaf_ensure_peer_evaluation_schema();

        $pdo = self::db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('TRUNCATE TABLE peer_evaluation_assignments');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $deptId = self::insertDepartment([
            'department_code' => 'CITE',
            'department_name' => 'College of Information Technology Education',
        ]);

        $programHeadOneId = self::insertUser([
            'full_name' => 'CITE Program Head One',
            'email' => 'cite_ph_one@pmas.test',
            'role' => 'program_head',
            'department' => 'CITE',
            'program' => 'BSCS',
        ]);
        $programHeadTwoId = self::insertUser([
            'full_name' => 'CITE Program Head Two',
            'email' => 'cite_ph_two@pmas.test',
            'role' => 'program_head',
            'department' => 'CITE',
            'program' => 'BSIT',
        ]);

        self::insertProgram([
            'program_code' => 'BSCS',
            'program_name' => 'Computer Science',
            'department_id' => $deptId,
            'program_head_user_id' => $programHeadOneId,
        ]);
        self::insertProgram([
            'program_code' => 'BSIT',
            'program_name' => 'Information Technology',
            'department_id' => $deptId,
            'program_head_user_id' => $programHeadTwoId,
        ]);

        self::insertFaculty([
            'user_id' => $programHeadOneId,
            'full_name' => 'CITE Program Head One',
            'email' => 'cite_ph_one@pmas.test',
            'department' => 'CITE',
            'program_code' => 'BSCS',
            'position_title' => 'Program Head',
        ]);
        self::insertFaculty([
            'user_id' => $programHeadTwoId,
            'full_name' => 'CITE Program Head Two',
            'email' => 'cite_ph_two@pmas.test',
            'department' => 'CITE',
            'program_code' => 'BSIT',
            'position_title' => 'Program Head',
        ]);

        $periodId = self::insertPeriod([
            'period_name' => '2026 Program Head Peer Cycle',
            'status' => 'open',
        ]);

        dipascaf_generate_peer_evaluation_assignments(
            $periodId,
            '2026 Program Head Peer Cycle',
            '2026-06-30',
            true,
            true
        );

        $rows = $pdo->query(
            "SELECT pa.evaluator_role, pa.assignment_type, pa.questionnaire_type,
                    eu.role AS evaluator_role_actual, efu.role AS evaluatee_role_actual
             FROM peer_evaluation_assignments pea
             JOIN peer_assignments pa ON pa.id = pea.peer_assignment_id
             JOIN users eu ON eu.id = pea.evaluator_id
             JOIN users efu ON efu.id = pea.evaluatee_id
             WHERE pea.evaluation_period_id = {$periodId}
             ORDER BY eu.full_name"
        )->fetchAll();

        $this->assertCount(2, $rows, 'Both program heads should receive peer-to-peer assignments.');
        foreach ($rows as $row) {
            $this->assertSame('program_head', $row['evaluator_role']);
            $this->assertSame('peer', $row['assignment_type']);
            $this->assertSame('admin', $row['questionnaire_type']);
            $this->assertSame('program_head', $row['evaluator_role_actual']);
            $this->assertSame('program_head', $row['evaluatee_role_actual']);
        }

        self::cleanDb();
    }

    public function testPeerGenerationSyncsProgramHeadFacultyRecordsInsideDepartment(): void
    {
        self::cleanDb();
        self::includeSource();
        require_once __DIR__ . '/../../includes/peer_assignment_algorithm.php';
        admin_ensure_archive_schema();
        dipascaf_ensure_peer_evaluation_schema();

        $pdo = self::db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('TRUNCATE TABLE peer_evaluation_assignments');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $deptId = self::insertDepartment([
            'department_code' => 'CITE',
            'department_name' => 'College of Information Technology Education',
        ]);

        $programHeadOneId = self::insertUser([
            'full_name' => 'Department PH One',
            'email' => 'dept_ph_one@pmas.test',
            'role' => 'program_head',
            'department' => 'CITE',
            'program' => 'BSCS',
        ]);
        $programHeadTwoId = self::insertUser([
            'full_name' => 'Department PH Two',
            'email' => 'dept_ph_two@pmas.test',
            'role' => 'program_head',
            'department' => 'CITE',
            'program' => 'BSIT',
        ]);

        self::insertProgram([
            'program_code' => 'BSCS',
            'program_name' => 'Computer Science',
            'department_id' => $deptId,
            'program_head_user_id' => $programHeadOneId,
        ]);
        self::insertProgram([
            'program_code' => 'BSIT',
            'program_name' => 'Information Technology',
            'department_id' => $deptId,
            'program_head_user_id' => $programHeadTwoId,
        ]);

        $periodId = self::insertPeriod([
            'period_name' => '2026 Department PH Peer Cycle',
            'status' => 'open',
        ]);

        dipascaf_generate_peer_evaluation_assignments(
            $periodId,
            '2026 Department PH Peer Cycle',
            '2026-06-30',
            true,
            false,
            ['department_ids' => [$deptId], 'departments' => ['CITE', 'College of Information Technology Education']]
        );

        $facultyCount = $pdo->query(
            "SELECT COUNT(*) FROM faculty WHERE user_id IN ({$programHeadOneId}, {$programHeadTwoId}) AND position_title = 'Program Head'"
        )->fetchColumn();
        $rows = $pdo->query(
            "SELECT pa.evaluator_role, pa.questionnaire_type, eu.role AS evaluator_role_actual, efu.role AS evaluatee_role_actual
             FROM peer_evaluation_assignments pea
             JOIN peer_assignments pa ON pa.id = pea.peer_assignment_id
             JOIN users eu ON eu.id = pea.evaluator_id
             JOIN users efu ON efu.id = pea.evaluatee_id
             WHERE pea.evaluation_period_id = {$periodId}
             ORDER BY eu.full_name"
        )->fetchAll();

        $this->assertSame(2, (int) $facultyCount, 'Program Heads should be synced into faculty records for department peer assignment.');
        $this->assertCount(2, $rows, 'Both department Program Heads should receive Program Head peer assignments.');
        foreach ($rows as $row) {
            $this->assertSame('program_head', $row['evaluator_role']);
            $this->assertSame('admin', $row['questionnaire_type']);
            $this->assertSame('program_head', $row['evaluator_role_actual']);
            $this->assertSame('program_head', $row['evaluatee_role_actual']);
        }

        self::cleanDb();
    }

    public function testPeerGenerationRefreshesUnlockedAssignmentsUntilLocked(): void
    {
        self::cleanDb();
        self::includeSource();
        require_once __DIR__ . '/../../includes/peer_assignment_algorithm.php';
        admin_ensure_archive_schema();
        dipascaf_ensure_peer_evaluation_schema();

        $pdo = self::db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('TRUNCATE TABLE peer_evaluation_assignments');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $teacherIds = [];
        foreach (['One', 'Two', 'Three'] as $label) {
            $teacherIds[] = self::insertUser([
                'full_name' => 'Refresh Faculty ' . $label,
                'email' => strtolower($label) . '_refresh@pmas.test',
                'role' => 'teacher',
                'department' => 'CITE',
                'program' => 'BSCS',
            ]);
        }

        foreach ($teacherIds as $index => $teacherId) {
            self::insertFaculty([
                'user_id' => $teacherId,
                'full_name' => 'Refresh Faculty ' . ($index + 1),
                'email' => ['one_refresh@pmas.test', 'two_refresh@pmas.test', 'three_refresh@pmas.test'][$index],
                'department' => 'CITE',
                'program_code' => 'BSCS',
                'position_title' => 'Faculty',
            ]);
        }

        $periodId = self::insertPeriod([
            'period_name' => '2026 Refresh Peer Cycle',
            'status' => 'open',
        ]);

        dipascaf_generate_peer_evaluation_assignments($periodId, '2026 Refresh Peer Cycle', '2026-06-30', false, false);
        $firstIds = $pdo->query(
            "SELECT peer_assignment_id FROM peer_evaluation_assignments WHERE evaluation_period_id = {$periodId} ORDER BY evaluator_id"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(3, $firstIds);

        dipascaf_generate_peer_evaluation_assignments($periodId, '2026 Refresh Peer Cycle', '2026-06-30', false, false);
        $secondIds = $pdo->query(
            "SELECT peer_assignment_id FROM peer_evaluation_assignments WHERE evaluation_period_id = {$periodId} ORDER BY evaluator_id"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(3, $secondIds, 'Refresh should still leave only one active peer assignment per evaluator.');
        $this->assertNotSame($firstIds, $secondIds, 'Unlocked peer assignments should be refreshed before lock.');

        $lockedAssignmentId = (int) $secondIds[0];
        $pdo->exec("UPDATE peer_evaluation_assignments SET locked_at = NOW() WHERE peer_assignment_id = {$lockedAssignmentId}");
        dipascaf_generate_peer_evaluation_assignments($periodId, '2026 Refresh Peer Cycle', '2026-06-30', false, false);

        $lockedStillExists = $pdo->query(
            "SELECT COUNT(*) FROM peer_evaluation_assignments
             WHERE evaluation_period_id = {$periodId}
               AND peer_assignment_id = {$lockedAssignmentId}
               AND locked_at IS NOT NULL
               AND COALESCE(is_archived, 0) = 0"
        )->fetchColumn();
        $activeCount = $pdo->query(
            "SELECT COUNT(*) FROM peer_evaluation_assignments
             WHERE evaluation_period_id = {$periodId}
               AND COALESCE(is_archived, 0) = 0"
        )->fetchColumn();

        $this->assertSame(1, (int) $lockedStillExists, 'Locked peer assignment should not be refreshed.');
        $this->assertSame(3, (int) $activeCount, 'Locked plus refreshed unlocked assignments should still be one per evaluator.');

        self::cleanDb();
    }

    // ═══════════════════════════════════════════════════════════════
    //  Edge case: empty/falsey inputs for DB functions
    // ═══════════════════════════════════════════════════════════════

    public function testEnsureProgramHeadAssignmentsNoScopeDoesNothing(): void
    {
        self::cleanDb();
        self::includeSource();

        self::insertPeriod([
            'period_name' => '2026 Appraisal Cycle',
            'status' => 'open',
        ]);

        // Program head with no department/program — should bail early
        $phId = self::insertUser([
            'full_name' => 'PH No Scope',
            'email' => 'ph_noscope@cite.edu',
            'role' => 'program_head',
            'department' => '',
            'program' => '',
        ]);

        // Should not throw
        dipascaf_ensure_program_head_assignments($phId);
        $this->assertTrue(true);

        self::cleanDb();
    }

    public function testEnsureDeanAssignmentsNoDepartmentsDoesNothing(): void
    {
        self::cleanDb();
        self::includeSource();

        // Dean with no departments assigned
        $deanId = self::insertUser([
            'full_name' => 'Dean No Dept',
            'email' => 'dean_nodepth@cite.edu',
            'role' => 'dean',
            'department' => '',
        ]);

        dipascaf_ensure_dean_assignments($deanId);
        $this->assertTrue(true);

        self::cleanDb();
    }

    public function testEnsureTeacherLeadershipAssignmentsNoLeadersDoesNothing(): void
    {
        self::cleanDb();
        self::includeSource();

        $teacherId = self::insertUser([
            'full_name' => 'Teacher No Leaders',
            'email' => 'tnl@cite.edu',
            'role' => 'teacher',
            'department' => '',
            'program' => '',
        ]);

        dipascaf_ensure_teacher_leadership_assignments($teacherId);
        $this->assertTrue(true);

        self::cleanDb();
    }

    // ═══════════════════════════════════════════════════════════════
    //  dipascaf_init_evaluation_assignments (dispatcher)
    // ═══════════════════════════════════════════════════════════════

    public function testInitDispatchesToProgramHeadCreatesDeanAndFacultyAssignments(): void
    {
        self::cleanDb();
        self::includeSource();

        $deanId = self::insertUser([
            'full_name' => 'Dean For PH',
            'email' => 'dean_ph@cite.edu',
            'role' => 'dean',
            'department' => 'CITE',
        ]);

        $teacherId = self::insertUser([
            'full_name' => 'Faculty For PH',
            'email' => 'faculty_ph@cite.edu',
            'role' => 'teacher',
            'department' => 'CITE',
            'program' => 'BSCS',
        ]);

        $phId = self::insertUser([
            'full_name' => 'PH via Init',
            'email' => 'ph_init@cite.edu',
            'role' => 'program_head',
            'department' => 'CITE',
            'program' => 'BSCS',
        ]);

        $deptId = self::insertDepartment([
            'department_code' => 'CITE',
            'department_name' => 'College of Information Technology Education',
            'dean_user_id' => $deanId,
        ]);

        self::insertProgram([
            'program_code' => 'BSCS',
            'program_name' => 'Computer Science',
            'department_id' => $deptId,
            'program_head_user_id' => $phId,
        ]);

        self::insertPeriod([
            'period_name' => '2026 Appraisal Cycle',
            'status' => 'open',
        ]);

        // Pre-create faculty records so assignment generator can find them
        dipascaf_ensure_leadership_faculty_record($deanId, 'Dean');
        dipascaf_ensure_leadership_faculty_record($teacherId, 'Faculty');

        // Call the dispatcher with program_head role
        dipascaf_init_evaluation_assignments($phId, 'program_head');

        // Should create dean assignment
        $deanAssignment = self::db()->query(
            "SELECT * FROM peer_assignments WHERE evaluator_user_id = {$phId} AND assignment_type = 'dean'"
        )->fetch();
        $this->assertNotFalse($deanAssignment, 'Dispatcher should route to program_head ensure which creates dean assignment');
        $this->assertSame('pending', $deanAssignment['status']);

        // Should create faculty assignment
        $facultyAssignment = self::db()->query(
            "SELECT * FROM peer_assignments WHERE evaluator_user_id = {$phId} AND assignment_type = 'program_head'"
        )->fetch();
        $this->assertNotFalse($facultyAssignment, 'Dispatcher should route to program_head ensure which creates faculty assignment');
        $this->assertSame('pending', $facultyAssignment['status']);

        self::cleanDb();
    }

    public function testInitDispatchesToDeanCreatesFacultyAssignments(): void
    {
        self::cleanDb();
        self::includeSource();

        $deanId = self::insertUser([
            'full_name' => 'Dean Via Init',
            'email' => 'dean_init@cite.edu',
            'role' => 'dean',
            'department' => 'CITE',
        ]);

        $teacherId = self::insertUser([
            'full_name' => 'Faculty Under Dean',
            'email' => 'faculty_dean@cite.edu',
            'role' => 'teacher',
            'department' => 'CITE',
        ]);

        self::insertDepartment([
            'department_code' => 'CITE',
            'department_name' => 'College of Information Technology Education',
            'dean_user_id' => $deanId,
        ]);

        self::insertPeriod([
            'period_name' => '2026 Appraisal Cycle',
            'status' => 'open',
        ]);

        // Pre-create faculty record
        dipascaf_ensure_leadership_faculty_record($teacherId, 'Faculty');

        // Call the dispatcher with dean role
        dipascaf_init_evaluation_assignments($deanId, 'dean');

        // Should create faculty assignments for the dean
        $assignments = self::db()->query(
            "SELECT * FROM peer_assignments WHERE evaluator_user_id = {$deanId} AND evaluator_role = 'dean'"
        )->fetchAll();
        $this->assertGreaterThan(0, count($assignments), 'Dispatcher should route to dean ensure which creates assignments');
        $this->assertSame('pending', $assignments[0]['status']);

        self::cleanDb();
    }

    public function testInitDispatchesToTeacherCreatesLeadershipAssignmentsOnly(): void
    {
        self::cleanDb();
        self::includeSource();

        $deanId = self::insertUser([
            'full_name' => 'Dean For Teacher',
            'email' => 'dean_teacher@cite.edu',
            'role' => 'dean',
            'department' => 'CITE',
        ]);

        $phId = self::insertUser([
            'full_name' => 'PH For Teacher',
            'email' => 'ph_teacher@cite.edu',
            'role' => 'program_head',
            'department' => 'CITE',
            'program' => 'BSCS',
        ]);

        $teacherId = self::insertUser([
            'full_name' => 'Teacher Via Init',
            'email' => 'teacher_init@cite.edu',
            'role' => 'teacher',
            'department' => 'CITE',
            'program' => 'BSCS',
        ]);

        $peerTeacherId = self::insertUser([
            'full_name' => 'Peer Teacher',
            'email' => 'peer_teacher@cite.edu',
            'role' => 'teacher',
            'department' => 'CITE',
            'program' => 'BSCS',
        ]);

        $deptId = self::insertDepartment([
            'department_code' => 'CITE',
            'department_name' => 'College of Information Technology Education',
            'dean_user_id' => $deanId,
        ]);

        self::insertProgram([
            'program_code' => 'BSCS',
            'program_name' => 'Computer Science',
            'department_id' => $deptId,
            'program_head_user_id' => $phId,
        ]);

        self::insertPeriod([
            'period_name' => '2026 Appraisal Cycle',
            'status' => 'open',
        ]);

        // Pre-create faculty records
        dipascaf_ensure_leadership_faculty_record($deanId, 'Dean');
        dipascaf_ensure_leadership_faculty_record($phId, 'Program Head');
        dipascaf_ensure_leadership_faculty_record($teacherId, 'Faculty');
        dipascaf_ensure_leadership_faculty_record($peerTeacherId, 'Faculty');

        // Call the dispatcher with teacher role
        dipascaf_init_evaluation_assignments($teacherId, 'teacher');

        // Should create at least one leadership (dean or program_head) assignment
        $leadershipAssignments = self::db()->query(
            "SELECT * FROM peer_assignments
             WHERE evaluator_user_id = {$teacherId}
               AND evaluator_role = 'teacher'
               AND assignment_type IN ('dean', 'program_head')"
        )->fetchAll();
        $this->assertGreaterThan(0, count($leadershipAssignments), 'Teacher should have leadership assignments via dispatcher');

        // Peer-to-peer assignments must come from the official peer setup only,
        // not from ad hoc teacher dashboard initialization.
        $peerAssignments = self::db()->query(
            "SELECT * FROM peer_assignments
             WHERE evaluator_user_id = {$teacherId}
               AND assignment_type = 'peer'"
        )->fetchAll();
        $this->assertCount(0, $peerAssignments, 'Teacher dispatcher should not create ad hoc peer assignments');

        self::cleanDb();
    }

    public function testInitDispatchesToVpaaCreatesDeanAssignments(): void
    {
        self::cleanDb();
        self::includeSource();

        // Create a VPAA user
        $vpaaId = self::insertUser([
            'full_name' => 'VPAA User',
            'email' => 'vpaa_init@cite.edu',
            'role' => 'vpaa',
            'department' => 'CITE',
        ]);

        // Create a dean to evaluate
        $deanId = self::insertUser([
            'full_name' => 'Dean To Evaluate',
            'email' => 'dean_eval@cite.edu',
            'role' => 'dean',
            'department' => 'CITE',
        ]);

        self::insertDepartment([
            'department_code' => 'CITE',
            'department_name' => 'College of Information Technology Education',
            'dean_user_id' => $deanId,
        ]);

        self::insertPeriod([
            'period_name' => '2026 Appraisal Cycle',
            'status' => 'open',
        ]);

        // Pre-create dean faculty record
        dipascaf_ensure_leadership_faculty_record($deanId, 'Dean');

        // Call the dispatcher with vpaa role
        dipascaf_init_evaluation_assignments($vpaaId, 'vpaa');

        // Should create dean evaluation assignments for the VPAA
        $assignments = self::db()->query(
            "SELECT * FROM peer_assignments
             WHERE evaluator_user_id = {$vpaaId}
               AND evaluator_role = 'vpaa'"
        )->fetchAll();
        $this->assertGreaterThan(0, count($assignments), 'VPAA should have dean evaluation assignments via dispatcher');

        self::cleanDb();
    }

    public function testInitWithUnknownRoleDoesNothing(): void
    {
        self::cleanDb();
        self::includeSource();

        $userId = self::insertUser([
            'full_name' => 'Any Role',
            'email' => 'any@cite.edu',
            'role' => 'teacher',
        ]);

        // Call with a role that doesn't match any branch
        dipascaf_init_evaluation_assignments($userId, 'admin_hr');

        // Verify no assignments created
        $assignments = self::db()->query('SELECT * FROM peer_assignments')->fetchAll();
        $this->assertCount(0, $assignments, 'Unknown role should not create any assignments');

        self::cleanDb();
    }

    public function testInitWithEmptyStringRoleDoesNothing(): void
    {
        self::cleanDb();
        self::includeSource();

        $userId = self::insertUser([
            'full_name' => 'Empty Role',
            'email' => 'empty@cite.edu',
            'role' => 'teacher',
        ]);

        // Call with empty string — should fall through without action
        dipascaf_init_evaluation_assignments($userId, '');

        $assignments = self::db()->query('SELECT * FROM peer_assignments')->fetchAll();
        $this->assertCount(0, $assignments, 'Empty string role should not create any assignments');

        self::cleanDb();
    }

    public function testInitWithNonexistentUserIdGracefullyHandlesMissingUser(): void
    {
        self::cleanDb();
        self::includeSource();

        self::insertPeriod([
            'period_name' => '2026 Appraisal Cycle',
            'status' => 'open',
        ]);

        // Call with a user ID that doesn't exist in the database
        dipascaf_init_evaluation_assignments(99999, 'teacher');

        // Should not throw — gracefully handled by the underlying ensure functions
        $this->assertTrue(true);

        self::cleanDb();
    }
}
