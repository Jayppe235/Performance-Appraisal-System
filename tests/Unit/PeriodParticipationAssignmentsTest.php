<?php
declare(strict_types=1);

namespace PMAS\Tests\Unit;

use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;

final class PeriodParticipationAssignmentsTest extends TestCase
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
        require_once __DIR__ . '/../../includes/evaluation_participation.php';
        \dipascaf_ensure_period_participation_schema();
    }

    protected function setUp(): void
    {
        $db = self::db();
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ([
            'evaluation_period_deans','evaluation_period_program_heads','evaluation_period_participation','peer_evaluation_assignments','peer_assignments',
            'faculty','programs','departments','appraisal_periods','users','activity_logs',
        ] as $table) {
            $db->exec("TRUNCATE TABLE `{$table}`");
        }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function user(string $name, string $role, string $department, string $program): array
    {
        $db = self::db();
        $db->prepare(
            'INSERT INTO users (full_name,email,password_hash,role,department,program,is_active)
             VALUES (?,?,?,?,?,?,1)'
        )->execute([$name, strtolower(str_replace(' ','-',$name)).'@test.local', password_hash('Password1', PASSWORD_DEFAULT), $role, $department, $program]);
        $userId = (int)$db->lastInsertId();
        $db->prepare(
            'INSERT INTO faculty (user_id,full_name,email,department,program_code,position_title)
             VALUES (?,?,?,?,?,?)'
        )->execute([$userId,$name,strtolower(str_replace(' ','-',$name)).'@test.local',$department,$program,$role === 'program_head' ? 'Program Head' : 'Faculty']);
        return ['user_id'=>$userId,'faculty_id'=>(int)$db->lastInsertId()];
    }

    public function testPeriodSnapshotOverridesMasterWithoutChangingIt(): void
    {
        $db = self::db();
        $db->exec("INSERT INTO departments (department_code,department_name,is_active) VALUES ('CITE','CITE',1)");
        $departmentId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO programs (department_id,program_code,program_name,is_active) VALUES (?,'BSIT','BSIT',1)")->execute([$departmentId]);
        $programId = (int)$db->lastInsertId();
        $db->exec("INSERT INTO appraisal_periods (period_name,school_year,date_start,date_end,status) VALUES ('Period A','2026-2027','2026-01-01','2026-12-31','locked')");
        $periodId = (int)$db->lastInsertId();
        $member = $this->user('Snapshot User','program_head','CITE','BSIT');
        $db->prepare(
            "INSERT INTO evaluation_period_participation
             (evaluation_period_id,user_id,faculty_id,role_snapshot,department_id,program_id,
              department_snapshot,program_snapshot,assignment_source,participation_status)
             VALUES (?,?,?,?,?,?,?,?,'admin','included')"
        )->execute([$periodId,$member['user_id'],$member['faculty_id'],'teacher',$departmentId,$programId,'CITE','BSIT']);

        $context = \dipascaf_period_user_context($periodId, $member['user_id']);
        self::assertSame('teacher', $context['role']);
        self::assertSame('program_head', $db->query("SELECT role FROM users WHERE id={$member['user_id']}")->fetchColumn());
    }

    public function testSecondProgramHeadIsRejectedWithinSamePeriod(): void
    {
        $db = self::db();
        $db->exec("INSERT INTO departments (department_code,department_name,is_active) VALUES ('CITE','CITE',1)");
        $departmentId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO programs (department_id,program_code,program_name,is_active) VALUES (?,'BSIT','BSIT',1)")->execute([$departmentId]);
        $programId = (int)$db->lastInsertId();
        $db->exec("INSERT INTO appraisal_periods (period_name,school_year,date_start,date_end,status) VALUES ('Period B','2026-2027','2026-01-01','2026-12-31','locked')");
        $periodId = (int)$db->lastInsertId();
        $existing = $this->user('Existing Head','program_head','CITE','BSIT');
        $candidate = $this->user('Candidate Head','teacher','CITE','BSIT');
        $db->prepare(
            "INSERT INTO evaluation_period_program_heads
             (evaluation_period_id,user_id,department_id,program_id,is_primary,is_lead_evaluator)
             VALUES (?,?,?,?,1,1)"
        )->execute([$periodId,$existing['user_id'],$departmentId,$programId]);

        $this->expectException(\PeriodProgramHeadConflictException::class);
        \dipascaf_set_period_assignment(
            $periodId,$candidate['user_id'],'program_head',$departmentId,
            [$programId],$programId,[$programId],false,'',$existing['user_id']
        );
    }

    public function testOneProgramHeadCanOwnMultipleProgramsWithinPeriod(): void
    {
        $db = self::db();
        $db->exec("INSERT INTO departments (department_code,department_name,is_active) VALUES ('CITE','CITE',1)");
        $departmentId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO programs (department_id,program_code,program_name,is_active) VALUES (?,'BSFS','Forensic Science',1),(?,'BSISM','Industrial Security Management',1)")
            ->execute([$departmentId,$departmentId]);
        $programIds = array_map('intval',$db->query("SELECT id FROM programs ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
        $db->exec("INSERT INTO appraisal_periods (period_name,school_year,date_start,date_end,status) VALUES ('Period Multi','2026-2027','2026-01-01','2026-12-31','locked')");
        $periodId = (int)$db->lastInsertId();
        $head = $this->user('Multi Head','program_head','CITE','BSFS');

        \dipascaf_set_period_assignment(
            $periodId,$head['user_id'],'program_head',$departmentId,
            $programIds,$programIds[0],$programIds,false,'',$head['user_id']
        );

        $scope = \dipascaf_period_program_head_scope($periodId,$head['user_id']);
        self::assertSame($programIds,$scope['program_ids']);
        self::assertCount(2,$scope['programs']);
    }

    public function testAuthorizedCoHeadKeepsExistingLead(): void
    {
        $db = self::db();
        $db->exec("INSERT INTO departments (department_code,department_name,is_active) VALUES ('CITE','CITE',1)");
        $departmentId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO programs (department_id,program_code,program_name,is_active) VALUES (?,'BSFS','Forensic Science',1)")->execute([$departmentId]);
        $programId = (int)$db->lastInsertId();
        $db->exec("INSERT INTO appraisal_periods (period_name,school_year,date_start,date_end,status) VALUES ('Period Cohead','2026-2027','2026-01-01','2026-12-31','locked')");
        $periodId = (int)$db->lastInsertId();
        $lead = $this->user('Lead Head','program_head','CITE','BSFS');
        $cohead = $this->user('Co Head','program_head','CITE','BSFS');
        $db->prepare("INSERT INTO evaluation_period_program_heads (evaluation_period_id,user_id,department_id,program_id,is_primary,is_lead_evaluator) VALUES (?,?,?,?,1,1)")
            ->execute([$periodId,$lead['user_id'],$departmentId,$programId]);

        \dipascaf_set_period_assignment(
            $periodId,$cohead['user_id'],'program_head',$departmentId,
            [$programId],$programId,[],true,'Temporary shared leadership',$lead['user_id']
        );

        $rows = $db->query("SELECT user_id,is_lead_evaluator,co_head_authorized FROM evaluation_period_program_heads ORDER BY user_id")->fetchAll();
        self::assertCount(2,$rows);
        self::assertSame(1,array_sum(array_map(static fn(array $row): int => (int)$row['is_lead_evaluator'],$rows)));
        self::assertSame(1,(int)$rows[1]['co_head_authorized']);
    }

    public function testActingDeanConflictRequiresExplicitReplacementConfirmation(): void
    {
        $db=self::db();
        $permanent=$this->user('Permanent Dean','dean','CCJE','');
        $candidate=$this->user('Acting Candidate','program_head','CCJE','BSFS');
        $db->prepare("INSERT INTO departments(department_code,department_name,dean_user_id,is_active) VALUES('CCJE','Criminal Justice',?,1)")->execute([$permanent['user_id']]);
        $departmentId=(int)$db->lastInsertId();
        $db->exec("INSERT INTO appraisal_periods(period_name,school_year,date_start,date_end,status) VALUES('Dean Period','2026-2027','2026-01-01','2026-12-31','locked')");
        $periodId=(int)$db->lastInsertId();

        $this->expectException(\PeriodDeanConflictException::class);
        \dipascaf_set_period_dean_assignment($periodId,$candidate['user_id'],$departmentId,'Temporary appointment','faculty',$permanent['user_id'],false);
    }

    public function testActingDeanIsScopedToPeriodAndMasterRoleIsPreserved(): void
    {
        $db=self::db();
        $permanent=$this->user('Old Dean','dean','CCJE','');
        $candidate=$this->user('Promoted Head','program_head','CCJE','BSFS');
        $db->prepare("INSERT INTO departments(department_code,department_name,dean_user_id,is_active) VALUES('CCJE','Criminal Justice',?,1)")->execute([$permanent['user_id']]);
        $departmentId=(int)$db->lastInsertId();
        $db->exec("INSERT INTO appraisal_periods(period_name,school_year,date_start,date_end,status) VALUES('Acting Period','2026-2027','2026-01-01','2026-12-31','locked')");
        $periodId=(int)$db->lastInsertId();

        \dipascaf_set_period_dean_assignment($periodId,$candidate['user_id'],$departmentId,'Temporary appointment','no_assignments',$permanent['user_id'],true);

        self::assertSame('program_head',$db->query("SELECT role FROM users WHERE id={$candidate['user_id']}")->fetchColumn());
        self::assertSame('dean',\dipascaf_period_user_context($periodId,$candidate['user_id'])['role']);
        self::assertSame('no_assignments',\dipascaf_period_user_context($periodId,$permanent['user_id'])['work_status']);
        self::assertSame($candidate['user_id'],(int)\dipascaf_period_dean_scope($periodId)[0]['user_id']);
    }

    public function testUserWithoutExplicitSnapshotCannotAccessPeriod(): void
    {
        $db=self::db();
        $member=$this->user('Unconfigured User','teacher','CITE','BSIT');
        $db->exec("INSERT INTO appraisal_periods(period_name,school_year,date_start,date_end,status) VALUES('Private Period','2026-2027','2026-01-01','2026-12-31','draft')");
        $periodId=(int)$db->lastInsertId();

        self::assertNull(\dipascaf_period_user_context($periodId,$member['user_id']));
        self::assertFalse(\dipascaf_user_can_access_period($member['user_id'],$periodId));
    }

    public function testStartPeriodSeedsNewlyAddedAndEarlierPeriodAsNotYetEmployed(): void
    {
        $db=self::db();
        $db->exec("INSERT INTO departments(department_code,department_name,is_active) VALUES('CITE','CITE',1)");
        $departmentId=(int)$db->lastInsertId();
        $db->prepare("INSERT INTO programs(department_id,program_code,program_name,is_active) VALUES(?,'BSIT','BSIT',1)")->execute([$departmentId]);
        $db->exec("INSERT INTO appraisal_periods(period_name,school_year,date_start,date_end,status) VALUES
          ('2025 Appraisal','2025-2026','2025-01-01','2025-12-31','draft'),
          ('2026 Appraisal','2026-2027','2026-01-01','2026-12-31','draft')");
        $periodIds=array_map('intval',$db->query('SELECT id FROM appraisal_periods ORDER BY date_start')->fetchAll(PDO::FETCH_COLUMN));
        $member=$this->user('New Faculty','teacher','CITE','BSIT');
        $db->prepare('UPDATE users SET start_evaluation_period_id=? WHERE id=?')->execute([$periodIds[1],$member['user_id']]);

        \dipascaf_seed_period_participants($periodIds[0],$member['user_id']);
        \dipascaf_seed_period_participants($periodIds[1],$member['user_id']);

        $earlier=\dipascaf_period_user_context($periodIds[0],$member['user_id']);
        $start=\dipascaf_period_user_context($periodIds[1],$member['user_id']);
        self::assertSame('not_yet_employed',$earlier['employment_status']);
        self::assertSame('excluded',$earlier['participation_status']);
        self::assertSame('newly_added',$start['employment_status']);
        self::assertSame('included',$start['participation_status']);
    }

    public function testUserStartPeriodSyncExplicitlyExcludesOlderYears(): void
    {
        $db=self::db();
        $db->exec("INSERT INTO departments(department_code,department_name,is_active) VALUES('CITE','CITE',1)");
        $departmentId=(int)$db->lastInsertId();
        $db->prepare("INSERT INTO programs(department_id,program_code,program_name,is_active) VALUES(?,'BSIT','BSIT',1)")->execute([$departmentId]);
        $db->exec("INSERT INTO appraisal_periods(period_name,school_year,date_start,date_end,status) VALUES
          ('2024 Appraisal','2024-2025','2026-07-01','2026-08-01','open'),
          ('2026 Appraisal','2026-2027','2026-01-01','2026-12-31','locked')");
        $periods=$db->query('SELECT id,period_name FROM appraisal_periods ORDER BY id')->fetchAll();
        $member=$this->user('Later Faculty','teacher','CITE','BSIT');
        $db->prepare('UPDATE users SET start_evaluation_period_id=? WHERE id=?')->execute([(int)$periods[1]['id'],$member['user_id']]);
        \dipascaf_sync_user_start_period($member['user_id'],$member['user_id']);

        $earlier=\dipascaf_period_user_context((int)$periods[0]['id'],$member['user_id']);
        $start=\dipascaf_period_user_context((int)$periods[1]['id'],$member['user_id']);
        self::assertSame('not_yet_employed',$earlier['employment_status']);
        self::assertSame('excluded',$earlier['participation_status']);
        self::assertSame('no_assignments',$earlier['work_status']);
        self::assertSame('newly_added',$start['employment_status']);

        // Correcting the employment start to an earlier academic year must
        // remove the system-generated pre-employment exclusion.
        $db->prepare('UPDATE users SET start_evaluation_period_id=? WHERE id=?')->execute([(int)$periods[0]['id'],$member['user_id']]);
        \dipascaf_sync_user_start_period($member['user_id'],$member['user_id']);
        $corrected=\dipascaf_period_user_context((int)$periods[0]['id'],$member['user_id']);
        self::assertSame('included',$corrected['participation_status']);
        self::assertSame('active',$corrected['work_status']);
        self::assertSame('newly_added',$corrected['employment_status']);
    }

    public function testSubmittedHistoryDoesNotOverrideExplicitEmploymentStart(): void
    {
        $db=self::db();
        $db->exec("INSERT INTO departments(department_code,department_name,is_active) VALUES('CITE','CITE',1)");
        $departmentId=(int)$db->lastInsertId();
        $db->prepare("INSERT INTO programs(department_id,program_code,program_name,is_active) VALUES(?,'BSIT','BSIT',1)")->execute([$departmentId]);
        $db->exec("INSERT INTO appraisal_periods(period_name,school_year,date_start,date_end,status) VALUES
          ('Historical 2024','2024-2025','2024-01-01','2024-12-31','locked'),
          ('Configured 2026','2026-2027','2026-01-01','2026-12-31','locked')");
        $periodIds=array_map('intval',$db->query('SELECT id FROM appraisal_periods ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        $member=$this->user('Historical Faculty','teacher','CITE','BSIT');
        $db->prepare('UPDATE users SET start_evaluation_period_id=? WHERE id=?')->execute([$periodIds[1],$member['user_id']]);
        $db->prepare("INSERT INTO peer_assignments
          (cycle_name,evaluator_user_id,evaluatee_faculty_id,evaluator_role,assignment_type,questionnaire_type,status,assigned_at,deadline)
          VALUES ('Historical 2024',?,?,'teacher','self','faculty','submitted',NOW(),'2024-12-31')")
          ->execute([$member['user_id'],$member['faculty_id']]);

        \dipascaf_sync_user_start_period($member['user_id'],$member['user_id']);

        $historical=\dipascaf_period_user_context($periodIds[0],$member['user_id']);
        self::assertSame('excluded',$historical['participation_status']);
        self::assertSame('no_assignments',$historical['work_status']);
        self::assertSame('not_yet_employed',$historical['employment_status']);
    }

    public function testFinalizedParticipantsCannotBeEditedUntilReopened(): void
    {
        $db=self::db();
        $db->exec("INSERT INTO departments(department_code,department_name,is_active) VALUES('CITE','CITE',1)");
        $departmentId=(int)$db->lastInsertId();
        $db->prepare("INSERT INTO programs(department_id,program_code,program_name,is_active) VALUES(?,'BSIT','BSIT',1)")->execute([$departmentId]);
        $db->exec("INSERT INTO appraisal_periods(period_name,school_year,date_start,date_end,status) VALUES('Final Period','2026-2027','2026-01-01','2026-12-31','draft')");
        $periodId=(int)$db->lastInsertId();
        $member=$this->user('Final Faculty','teacher','CITE','BSIT');
        $db->prepare('UPDATE users SET start_evaluation_period_id=? WHERE id=?')->execute([$periodId,$member['user_id']]);
        \dipascaf_seed_period_participants($periodId,$member['user_id']);
        \dipascaf_finalize_period_participants($periodId,$member['user_id']);

        $this->expectException(DomainException::class);
        \dipascaf_set_period_participation($periodId,$member['user_id'],'excluded','leave','', $member['user_id']);
    }
}
