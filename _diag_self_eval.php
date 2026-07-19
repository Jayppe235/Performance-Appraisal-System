<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/evaluation_cards.php';
require_once __DIR__ . '/includes/evaluation_period.php';

session_start();

// Check if logged in
$user = current_user();
if (!$user) {
    echo "<h1>Not logged in</h1>";
    echo '<p>Please <a href="login.php">log in</a> first.</p>';
    exit;
}

$userId = (int) $user['id'];
$userEmail = $user['email'] ?? 'unknown';
$userRole = $user['role'] ?? 'unknown';

echo "<h1>Self-Evaluation Diagnostic</h1>";
echo "<p>User: <strong>" . htmlspecialchars($userEmail) . "</strong> (ID: $userId, Role: $userRole)</p>";
echo "<hr>";

// 1. Check peer_assignments for self type
$pdo = db();
$selfAssignments = $pdo->prepare(
    "SELECT id, assignment_type, status, questionnaire_type, cycle_name, evaluator_user_id, evaluatee_faculty_id, is_archived
     FROM peer_assignments
     WHERE evaluator_user_id = :uid
       AND assignment_type = 'self'
       AND COALESCE(is_archived, 0) = 0"
);
$selfAssignments->execute(['uid' => $userId]);
$selfRows = $selfAssignments->fetchAll(PDO::FETCH_ASSOC);

echo "<h2>1. Self-evaluation assignments in DB (peer_assignments)</h2>";
if (count($selfRows) === 0) {
    echo "<p style='color:red;font-weight:bold;'>NO self-evaluation assignments found for this user!</p>";
} else {
    echo "<table border='1' cellpadding='6' cellspacing='0' style='border-collapse:collapse;'>";
    echo "<tr><th>ID</th><th>Type</th><th>Status</th><th>Questionnaire</th><th>Cycle</th><th>Evaluator</th><th>Faculty</th><th>Archived</th></tr>";
    foreach ($selfRows as $r) {
        echo "<tr>";
        echo "<td>" . $r['id'] . "</td>";
        echo "<td>" . $r['assignment_type'] . "</td>";
        echo "<td><strong>" . $r['status'] . "</strong></td>";
        echo "<td>" . $r['questionnaire_type'] . "</td>";
        echo "<td>" . $r['cycle_name'] . "</td>";
        echo "<td>" . $r['evaluator_user_id'] . "</td>";
        echo "<td>" . $r['evaluatee_faculty_id'] . "</td>";
        echo "<td>" . $r['is_archived'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<hr>";

// 2. Test dipascaf_assignment_rows
echo "<h2>2. dipascaf_assignment_rows() output</h2>";
$rows = dipascaf_assignment_rows($userId, $userRole);
echo "<p>Total rows returned: <strong>" . count($rows) . "</strong></p>";

if (count($rows) > 0) {
    echo "<table border='1' cellpadding='6' cellspacing='0' style='border-collapse:collapse;'>";
    echo "<tr><th>ID</th><th>Type</th><th>Evaluatee</th><th>Section</th><th>Role Label</th><th>Relationship Tag</th><th>Status</th></tr>";
    foreach ($rows as $r) {
        $isSelf = $r['assignment_type'] === 'self';
        echo "<tr style='" . ($isSelf ? "background:#d4edda;font-weight:bold;" : "") . "'>";
        echo "<td>" . $r['id'] . "</td>";
        echo "<td>" . $r['assignment_type'] . "</td>";
        echo "<td>" . htmlspecialchars($r['evaluatee_name'] ?? $r['full_name'] ?? 'Unknown') . "</td>";
        echo "<td>" . $r['section_key'] . "</td>";
        echo "<td>" . $r['role_label'] . "</td>";
        echo "<td>" . htmlspecialchars($r['relationship_tag'] ?? '') . "</td>";
        echo "<td><strong>" . $r['status'] . "</strong></td>";
        echo "</tr>";
    }
    echo "</table>";
    
    $hasSelf = false;
    foreach ($rows as $r) {
        if ($r['assignment_type'] === 'self') {
            $hasSelf = true;
            break;
        }
    }
    echo "<p style='color:" . ($hasSelf ? "green" : "red") . ";font-weight:bold;font-size:18px;'>";
    echo "Self-evaluation " . ($hasSelf ? "IS ✅" : "is NOT ❌") . " included in the results.</p>";
} else {
    echo "<p style='color:red;font-weight:bold;'>No assignments returned at all!</p>";
}

echo "<hr>";

// 3. Check teacher_data.php functions
require_once __DIR__ . '/includes/teacher_data.php';
echo "<h2>3. Teacher Data Functions</h2>";

$ownFaculty = teacher_user_faculty($userId);
echo "<p>teacher_user_faculty(): " . ($ownFaculty ? "Faculty ID: " . $ownFaculty['id'] . " - " . htmlspecialchars($ownFaculty['full_name']) : "<strong style='color:red;'>NULL/not found</strong>") . "</p>";

$selfEvalEnabled = admin_setting('self_evaluation_enabled', '1') === '1';
echo "<p>Self-evaluation enabled setting: <strong>" . ($selfEvalEnabled ? "YES" : "NO") . "</strong></p>";

if ($ownFaculty) {
    $selfAssignment = teacher_self_assignment($userId, (int) $ownFaculty['id']);
    echo "<p>teacher_self_assignment(): ";
    if ($selfAssignment) {
        echo "Found - ID: " . $selfAssignment['id'] . ", Status: <strong>" . $selfAssignment['status'] . "</strong></p>";
    } else {
        echo "<strong style='color:red;'>NULL/not found!</strong></p>";
    }
}

echo "<hr>";
echo "<p><a href='dashboards/teacher.php?section=evaluate'>Go to Teacher Dashboard →</a></p>";
