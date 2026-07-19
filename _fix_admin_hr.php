<?php

/**
 * Fix 6 corrupted INSERT statements in admin_hr.php
 * Each is missing its VALUES clause due to a previous bad script.
 * File uses Windows \r\n line endings.
 */

$file = __DIR__ . '/dashboards/admin_hr.php';
$content = file_get_contents($file);
if ($content === false) { echo "ERROR: Cannot read\n"; exit(1); }

$nl = "\r\n";
$sp24 = str_repeat(' ', 24);
$sp29 = str_repeat(' ', 29);
$sp25 = str_repeat(' ', 25);
$sp26 = str_repeat(' ', 26);

// Fix 1: Dean auto-create (save_person) - around line 535
// INSERT without VALUES, execute without deadline
$search = "'INSERT IGNORE INTO peer_assignments{$nl}"
        . "                             (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, status, deadline){$nl}"
        . "                        );{$nl}"
        . "{$nl}"
        . "                        \$created = 0;{$nl}"
        . "                        foreach (\$facultyMembers as \$faculty) {{$nl}"
        . "                            \$insertAssignment->execute([{$nl}"
        . "                                'cycle_name' => \$cycleName,{$nl}"
        . "                                'evaluator_user_id' => \$id,{$nl}"
        . "                                'evaluatee_faculty_id' => (int) \$faculty['id'],{$nl}"
        . "                            ]);";

$replace = "'INSERT IGNORE INTO peer_assignments{$nl}"
         . "                             (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, status, deadline){$nl}"
         . "                             VALUES (:cycle_name, :evaluator_user_id, :evaluatee_faculty_id, \"dean\", \"dean\", \"pending\", :deadline)'{$nl}"
         . "                        );{$nl}"
         . "{$nl}"
         . "                        \$created = 0;{$nl}"
         . "                        foreach (\$facultyMembers as \$faculty) {{$nl}"
         . "                            \$insertAssignment->execute([{$nl}"
         . "                                'cycle_name' => \$cycleName,{$nl}"
         . "                                'evaluator_user_id' => \$id,{$nl}"
         . "                                'evaluatee_faculty_id' => (int) \$faculty['id'],{$nl}"
         . "                                'deadline' => \$deadline,{$nl}"
         . "                            ]);";

$c = 0;
$content = str_replace($search, $replace, $content, $c);
echo "Fix 1 (dean auto-create): $c\n";
$count += $c;

// Fix 2: Program Head auto-create (save_person) - around line 569
$search = "'INSERT IGNORE INTO peer_assignments{$nl}"
        . "                             (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, status, deadline){$nl}"
        . "                        );{$nl}"
        . "{$nl}"
        . "                        \$created = 0;{$nl}"
        . "                        foreach (\$facultyMembers as \$faculty) {{$nl}"
        . "                            \$insertAssignment->execute([{$nl}"
        . "                                'cycle_name' => \$cycleName,{$nl}"
        . "                                'evaluator_user_id' => \$id,{$nl}"
        . "                                'evaluatee_faculty_id' => (int) \$faculty['id'],{$nl}"
        . "                            ]);{$nl}"
        . "{$nl}"
        . "                        if (\$created > 0) {";

$replace = "'INSERT IGNORE INTO peer_assignments{$nl}"
         . "                             (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, status, deadline){$nl}"
         . "                             VALUES (:cycle_name, :evaluator_user_id, :evaluatee_faculty_id, \"program_head\", \"program_head\", \"pending\", :deadline)'{$nl}"
         . "                        );{$nl}"
         . "{$nl}"
         . "                        \$created = 0;{$nl}"
         . "                        foreach (\$facultyMembers as \$faculty) {{$nl}"
         . "                            \$insertAssignment->execute([{$nl}"
         . "                                'cycle_name' => \$cycleName,{$nl}"
         . "                                'evaluator_user_id' => \$id,{$nl}"
         . "                                'evaluatee_faculty_id' => (int) \$faculty['id'],{$nl}"
         . "                                'deadline' => \$deadline,{$nl}"
         . "                            ]);{$nl}"
         . "{$nl}"
         . "                        if (\$created > 0) {";

$c = 0;
$content = str_replace($search, $replace, $content, $c);
echo "Fix 2 (ph auto-create): $c\n";
$count += $c;

// Fix 3: Teacher self (save_person) - around line 600
$search = "'INSERT IGNORE INTO peer_assignments{$nl}"
        . "                             (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, status, deadline){$nl}"
        . "                        )->execute([{$nl}"
        . "                            'cycle_name' => \$cycleName,{$nl}"
        . "                            'evaluator_user_id' => \$id,{$nl}"
        . "                            'evaluatee_faculty_id' => (int) \$facultyRow['id'],{$nl}"
        . "                        ]);{$nl}"
        . "                    }{$nl}"
        . "                }{$nl}"
        . "            }{$nl}"
        . "{$nl}"
        . "            admin_activity('Saved person profile: ' . \$email);";

$replace = "'INSERT IGNORE INTO peer_assignments{$nl}"
         . "                             (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, status, deadline){$nl}"
         . "                             VALUES (:cycle_name, :evaluator_user_id, :evaluatee_faculty_id, \"teacher\", \"self\", \"pending\", :deadline)'{$nl}"
         . "                        )->execute([{$nl}"
         . "                            'cycle_name' => \$cycleName,{$nl}"
         . "                            'evaluator_user_id' => \$id,{$nl}"
         . "                            'evaluatee_faculty_id' => (int) \$facultyRow['id'],{$nl}"
         . "                            'deadline' => \$deadline,{$nl}"
         . "                        ]);{$nl}"
         . "                    }{$nl}"
         . "                }{$nl}"
         . "            }{$nl}"
         . "{$nl}"
         . "            admin_activity('Saved person profile: ' . \$email);";

$c = 0;
$content = str_replace($search, $replace, $content, $c);
echo "Fix 3 (teacher self): $c\n";
$count += $c;

// Fix 4: assign_dean - around line 890
$search = "'INSERT IGNORE INTO peer_assignments{$nl}"
        . "                         (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, status, deadline){$nl}"
        . "                    );{$nl}"
        . "{$nl}"
        . "                    \$created = 0;{$nl}"
        . "                    foreach (\$facultyMembers as \$faculty) {{$nl}"
        . "                        \$insertAssignment->execute([{$nl}"
        . "                            'cycle_name' => \$cycleName,{$nl}"
        . "                            'evaluator_user_id' => \$deanUserId,{$nl}"
        . "                            'evaluatee_faculty_id' => (int) \$faculty['id'],{$nl}"
        . "                        ]);";

$replace = "'INSERT IGNORE INTO peer_assignments{$nl}"
         . "                         (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, status, deadline){$nl}"
         . "                         VALUES (:cycle_name, :evaluator_user_id, :evaluatee_faculty_id, \"dean\", \"dean\", \"pending\", :deadline)'{$nl}"
         . "                    );{$nl}"
         . "{$nl}"
         . "                    \$created = 0;{$nl}"
         . "                    foreach (\$facultyMembers as \$faculty) {{$nl}"
         . "                        \$insertAssignment->execute([{$nl}"
         . "                            'cycle_name' => \$cycleName,{$nl}"
         . "                            'evaluator_user_id' => \$deanUserId,{$nl}"
         . "                            'evaluatee_faculty_id' => (int) \$faculty['id'],{$nl}"
         . "                            'deadline' => \$deadline,{$nl}"
         . "                        ]);";

$c = 0;
$content = str_replace($search, $replace, $content, $c);
echo "Fix 4 (assign_dean): $c\n";
$count += $c;

// Fix 5: randomize_peers - around line 982
$search = "'INSERT IGNORE INTO peer_assignments{$nl}"
        . "                     (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, status, deadline){$nl}"
        . "                )->execute([{$nl}"
        . "                    'cycle_name' => \$cycle,{$nl}"
        . "                    'evaluator_user_id' => \$teacher['id'],{$nl}"
        . "                    'evaluatee_faculty_id' => \$faculty['id'],{$nl}"
        . "                ]);{$nl}"
        . "            }{$nl}"
        . "            admin_activity('Randomized confidential peer evaluators for ' . \$cycle . '.');";

$replace = "'INSERT IGNORE INTO peer_assignments{$nl}"
         . "                     (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, status, deadline){$nl}"
         . "                     VALUES (:cycle_name, :evaluator_user_id, :evaluatee_faculty_id, \"teacher\", \"peer\", \"pending\", :deadline)'{$nl}"
         . "                )->execute([{$nl}"
         . "                    'cycle_name' => \$cycle,{$nl}"
         . "                    'evaluator_user_id' => \$teacher['id'],{$nl}"
         . "                    'evaluatee_faculty_id' => \$faculty['id'],{$nl}"
         . "                    'deadline' => \$deadline,{$nl}"
         . "                ]);{$nl}"
         . "            }{$nl}"
         . "            admin_activity('Randomized confidential peer evaluators for ' . \$cycle . '.');";

$c = 0;
$content = str_replace($search, $replace, $content, $c);
echo "Fix 5 (randomize_peers): $c\n";
$count += $c;

// Fix 6: assign_leadership_evaluations - around line 1009
$search = "'INSERT IGNORE INTO peer_assignments{$nl}"
        . "                         (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, status, deadline){$nl}"
        . "                    );{$nl}"
        . "                    \$insertAssignment->execute([{$nl}"
        . "                        'cycle_name' => \$cycle,{$nl}"
        . "                        'evaluator_user_id' => (int) \$teacher['id'],{$nl}"
        . "                        'evaluatee_faculty_id' => (int) \$leader['faculty_id'],{$nl}"
        . "                        'assignment_type' => \$leader['role'] === 'dean' ? 'dean' : 'program_head',{$nl}"
        . "                    ]);{$nl}"
        . "{$nl}"
        . "                    \$created += \$insertAssignment->rowCount();{$nl}"
        . "                }{$nl}"
        . "            }{$nl}"
        . "{$nl}"
        . "            admin_activity('Assigned teacher evaluations for Deans and Program Heads for ' . \$cycle . '.');";

$replace = "'INSERT IGNORE INTO peer_assignments{$nl}"
         . "                         (cycle_name, evaluator_user_id, evaluatee_faculty_id, evaluator_role, assignment_type, status, deadline){$nl}"
         . "                         VALUES (:cycle_name, :evaluator_user_id, :evaluatee_faculty_id, \"teacher\", :assignment_type, \"pending\", :deadline)'{$nl}"
         . "                    );{$nl}"
         . "                    \$insertAssignment->execute([{$nl}"
         . "                        'cycle_name' => \$cycle,{$nl}"
         . "                        'evaluator_user_id' => (int) \$teacher['id'],{$nl}"
         . "                        'evaluatee_faculty_id' => (int) \$leader['faculty_id'],{$nl}"
         . "                        'assignment_type' => \$leader['role'] === 'dean' ? 'dean' : 'program_head',{$nl}"
         . "                        'deadline' => \$deadline,{$nl}"
         . "                    ]);{$nl}"
         . "{$nl}"
         . "                    \$created += \$insertAssignment->rowCount();{$nl}"
         . "                }{$nl}"
         . "            }{$nl}"
         . "{$nl}"
         . "            admin_activity('Assigned teacher evaluations for Deans and Program Heads for ' . \$cycle . '.');";

$c = 0;
$content = str_replace($search, $replace, $content, $c);
echo "Fix 6 (leadership): $c\n";
$count += $c;

// Fix 7: rogue deadline in save_user params
$search = "'deadline' => \$deadline,{$nl}"
        . "            ];{$nl}"
        . "{$nl}"
        . "            if (\$id === 0 && \$params['role'] === 'admin_hr') {";

$replace = "            ];{$nl}"
         . "{$nl}"
         . "            if (\$id === 0 && \$params['role'] === 'admin_hr') {";

$c = 0;
$content = str_replace($search, $replace, $content, $c);
echo "Fix 7 (rogue deadline): $c\n";
$count += $c;

file_put_contents($file, $content);
echo "Total fixes applied: $count\n";
