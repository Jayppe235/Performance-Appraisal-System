<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

$db = db();
$periodId = 1;
$userId = 31;

$db->beginTransaction();
try {
    $dean = $db->prepare(
        'SELECT id FROM evaluation_period_deans
         WHERE evaluation_period_id = ? AND user_id = ?
         FOR UPDATE'
    );
    $dean->execute([$periodId, $userId]);
    if (!$dean->fetchColumn()) {
        throw new RuntimeException('The period-scoped Dean assignment does not exist; no changes were made.');
    }

    $participant = $db->prepare(
        "UPDATE evaluation_period_participation
         SET role_snapshot = 'dean',
             program_id = NULL,
             program_snapshot = NULL,
             program_head_slot = NULL,
             notes = 'Confirmed as Dean for the 2024 appraisal period; removed conflicting Program Head snapshot.'
         WHERE evaluation_period_id = ? AND user_id = ?"
    );
    $participant->execute([$periodId, $userId]);

    $programHead = $db->prepare(
        'DELETE FROM evaluation_period_program_heads
         WHERE evaluation_period_id = ? AND user_id = ?'
    );
    $programHead->execute([$periodId, $userId]);

    $db->commit();
    echo "Corrected Mark Bryan Tenebroso to Dean for period 2024.\n";
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
