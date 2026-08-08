<?php
declare(strict_types=1);

namespace PMAS\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

final class CrossRoleAccountSynchronizationTest extends TestCase
{
    private static PDO $db;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/evaluation_assignment_generator.php';
        require_once __DIR__ . '/../../includes/peer_assignment_algorithm.php';
        self::$db = new PDO('mysql:host=localhost;port=3306;dbname=pmas_test_phpunit;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$db->exec("CREATE TABLE IF NOT EXISTS evaluation_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            evaluator_role ENUM('vpaa','dean','program_head','teacher') NOT NULL,
            evaluatee_role ENUM('dean','program_head','teacher') NOT NULL,
            assignment_type ENUM('peer','program_head','dean','self') NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB");
        \dipascaf_ensure_period_participation_schema();
        \dipascaf_ensure_peer_evaluation_schema();
    }

    protected function setUp(): void
    {
        self::$db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['evaluation_period_deans','evaluation_period_program_heads','evaluation_period_participation',
            'peer_evaluation_assignments','peer_assignments','evaluation_rules','faculty','programs','departments',
            'appraisal_periods','users','activity_logs'] as $table) {
            self::$db->exec("TRUNCATE TABLE `{$table}`");
        }
        self::$db->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->exec('DROP TABLE IF EXISTS evaluation_rules');
    }

    private function user(string $name, string $role, string $department = '', ?int $startPeriodId = null): array
    {
        self::$db->prepare(
            'INSERT INTO users (full_name,email,password_hash,role,department,start_evaluation_period_id,is_active) VALUES (?,?,?,?,?,?,1)'
        )->execute([$name, strtolower(str_replace(' ', '-', $name)).'@test.local', password_hash('Password1', PASSWORD_DEFAULT), $role, $department ?: null, $startPeriodId]);
        $userId = (int)self::$db->lastInsertId();
        self::$db->prepare(
            'INSERT INTO faculty (user_id,full_name,email,department,position_title,is_active,is_archived) VALUES (?,?,?,?,?,1,0)'
        )->execute([$userId, $name, strtolower(str_replace(' ', '-', $name)).'@test.local', $department, $role === 'dean' ? 'Dean' : 'VPAA']);
        return ['user_id' => $userId, 'faculty_id' => (int)self::$db->lastInsertId()];
    }

    public function testNewDeanIsAddedToFinalizedOpenPeriodAndAssignedToVpaaIdempotently(): void
    {
        self::$db->exec("INSERT INTO appraisal_periods (period_name,school_year,date_start,date_end,status,participants_finalized_at)
            VALUES ('Active Period','2026-2027','2026-01-01','2026-12-31','open',NOW())");
        $periodId = (int)self::$db->lastInsertId();
        $vpaa = $this->user('Test VPAA', 'vpaa');
        $dean = $this->user('New Dean', 'dean', 'CITE', $periodId);
        self::$db->prepare("INSERT INTO departments (department_code,department_name,dean_user_id,is_active) VALUES ('CITE','CITE',?,1)")
            ->execute([$dean['user_id']]);
        self::$db->exec("INSERT INTO evaluation_rules (evaluator_role,evaluatee_role,assignment_type,is_active)
            VALUES ('vpaa','dean','dean',1)");

        $first = \dipascaf_sync_account_evaluation_periods($dean['user_id'], $vpaa['user_id']);
        $second = \dipascaf_sync_account_evaluation_periods($dean['user_id'], $vpaa['user_id']);

        self::assertSame(1, (int)self::$db->query("SELECT COUNT(*) FROM evaluation_period_participation WHERE evaluation_period_id={$periodId} AND user_id={$dean['user_id']} AND participation_status='included'")->fetchColumn());
        self::assertSame(1, (int)self::$db->query("SELECT COUNT(*) FROM peer_assignments WHERE cycle_name='Active Period' AND evaluator_user_id={$vpaa['user_id']} AND evaluatee_faculty_id={$dean['faculty_id']} AND assignment_type='dean'")->fetchColumn());
        self::assertSame(1, (int)self::$db->query("SELECT COUNT(*) FROM peer_assignments WHERE cycle_name='Active Period' AND evaluator_user_id={$dean['user_id']} AND evaluatee_faculty_id={$dean['faculty_id']} AND assignment_type='self'")->fetchColumn());
        self::assertSame(1, $first['periods']);
        self::assertSame(1, $second['periods']);
    }
}
