<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // Find user IDs for Karen Ericq Booc and The Dean
    $stmt = $pdo->prepare("
        SELECT id, name, email FROM users 
        WHERE name LIKE :name OR email LIKE :email
        LIMIT 10
    ");

    echo "=== Finding Users ===\n";
    
    // Search for Karen Ericq Booc
    $stmt->execute([':name' => '%Karen%', ':email' => '%Karen%']);
    $karenResults = $stmt->fetchAll();
    echo "\nUsers matching 'Karen':\n";
    foreach ($karenResults as $user) {
        echo "  ID: {$user['id']}, Name: {$user['name']}, Email: {$user['email']}\n";
    }

    // Search for The Dean
    $stmt->execute([':name' => '%Dean%', ':email' => '%Dean%']);
    $deanResults = $stmt->fetchAll();
    echo "\nUsers matching 'Dean':\n";
    foreach ($deanResults as $user) {
        echo "  ID: {$user['id']}, Name: {$user['name']}, Email: {$user['email']}\n";
    }

    // Now find their evaluations
    echo "\n=== Finding Evaluation Records ===\n";
    
    $userIds = array_column(array_merge($karenResults, $deanResults), 'id');
    
    if (count($userIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        
        // Find evaluations submitted by or assigned to these users
        $stmt = $pdo->prepare("
            SELECT id, evaluator_id, evaluatee_id, assignment_id, status, created_at 
            FROM evaluations 
            WHERE evaluator_id IN ($placeholders) OR evaluatee_id IN ($placeholders)
            ORDER BY created_at DESC
        ");
        
        $bindings = array_merge($userIds, $userIds);
        $stmt->execute($bindings);
        $evaluations = $stmt->fetchAll();
        
        echo "\nFound " . count($evaluations) . " evaluation records:\n";
        foreach ($evaluations as $eval) {
            echo "  ID: {$eval['id']}, Status: {$eval['status']}, Created: {$eval['created_at']}\n";
        }

        if (count($evaluations) > 0) {
            echo "\n=== Deleting Records ===\n";
            
            $evalIds = array_column($evaluations, 'id');
            $evalPlaceholders = implode(',', array_fill(0, count($evalIds), '?'));
            
            // Delete related data first (evaluation_responses, etc.)
            $stmt = $pdo->prepare("DELETE FROM evaluation_responses WHERE evaluation_id IN ($evalPlaceholders)");
            $stmt->execute($evalIds);
            $deletedResponses = $stmt->rowCount();
            echo "Deleted $deletedResponses evaluation responses\n";

            // Delete evaluations
            $stmt = $pdo->prepare("DELETE FROM evaluations WHERE id IN ($evalPlaceholders)");
            $stmt->execute($evalIds);
            $deletedEvals = $stmt->rowCount();
            echo "Deleted $deletedEvals evaluations\n";

            echo "\n✅ Deletion complete!\n";
        } else {
            echo "\nNo evaluations found to delete.\n";
        }
    }

} catch (PDOException $e) {
    echo "❌ Database Error: " . $e->getMessage() . "\n";
    exit(1);
}
