<?php
/**
 * Fix INSERT statements in admin_hr.php to include deadline column
 * This script uses line-by-line processing for reliability with large files.
 */
$file = __DIR__ . '/dashboards/admin_hr.php';
$lines = file($file);
if ($lines === false) { die("Failed to read file\n"); }

$output = [];
$i = 0;
$totalLines = count($lines);
$changes = 0;
$inInsert = false;
$insertColLine = null;
$insertValLine = null;
$inExecute = false;
$executeBraceCount = 0;
$blockType = null; // 'save_person', 'assign_dean', 'randomize', 'leadership'

while ($i < $totalLines) {
    $line = $lines[$i];
    $trimmed = trim($line);
    
    // Detect INSERT INTO peer_assignments
    if (preg_match("/INSERT IGNORE INTO peer_assignments/", $line)) {
        $inInsert = true;
        // The column line is the next one(s) with column list
        // Look at the next line for "cycle_name"
        if ($i + 1 < $totalLines && strpos($lines[$i+1], 'cycle_name') !== false) {
            $insertColLine = $i + 1;
        }
        $output[] = $line;
        $i++;
        continue;
    }
    
    if ($inInsert && $insertColLine !== null && $i === $insertColLine) {
        // Check if this is the column line (has cycle_name)
        if (strpos($line, 'cycle_name') !== false && strpos($line, 'deadline') === false) {
            // Add deadline to columns
            $line = str_replace('assignment_type, status)', 'assignment_type, status, deadline)', $line);
            $changes++;
            echo "Fixed INSERT columns at line " . ($i+1) . "\n";
        }
        $output[] = $line;
        $i++;
        
        // Next line should be VALUES
        if ($i < $totalLines) {
            $insertValLine = $i;
            // Check if it has VALUES
            if (strpos($lines[$i], 'VALUES') !== false && strpos($lines[$i], ':deadline') === false) {
                // Add :deadline to VALUES
                // Need to find the last pending" or similar before the closing )
                $valLine = $lines[$i];
                // Add :deadline before the closing bracket
                $valLine = preg_replace('/("pending"\)\s*)/', '"pending", :deadline)', $valLine);
                $valLine = preg_replace('/("pending"\)\s*\')/', '"pending", :deadline)\'', $valLine);
                $valLine = preg_replace('/("pending"\)\s*\'\s*\)/', '"pending", :deadline)\')', $valLine);
                // For the dynamic one: "teacher", :assignment_type, "pending")
                $valLine = preg_replace('/("pending"\)\s*)/', '"pending", :deadline)', $valLine);
                $valLine = preg_replace('/("pending"\)\s*\')/', '"pending", :deadline)\'', $valLine);
                $valLine = preg_replace('/("pending"\)\s*\'\s*\)/', '"pending", :deadline)\')', $valLine);
                $lines[$i] = $valLine;
                $output[] = $lines[$i];
                $i++;
            }
        }
        
        $inInsert = false;
        $insertColLine = null;
        continue;
    }
    
    // Detect execute() arrays that need deadline param
    if (preg_match('/->execute\(\[/', $line)) {
        $inExecute = true;
        $executeBraceCount = 1;
        $output[] = $line;
        $i++;
        continue;
    }
    
    if ($inExecute) {
        // Count braces
        $openBraces = substr_count($line, '[');
        $closeBraces = substr_count($line, ']');
        $executeBraceCount += $openBraces - $closeBraces;
        
        // If this is the last line of the execute array and has no deadline
        if ($executeBraceCount <= 0) {
            // Check if deadline was already added in a previous line
            $inExecute = false;
        }
        $output[] = $line;
        $i++;
        continue;
    }
    
    $output[] = $line;
    $i++;
}

// Second pass: add $deadline variable and deadline execute params
$output2 = [];
$i = 0;
$totalOut = count($output);

while ($i < $totalOut) {
    $line = $output[$i];
    
    // After INSERT column lines with deadline, add $deadline before the prepare
    if (preg_match('/\$insertAssignment = db\(\)->prepare\(/', $line) || 
        preg_match('/db\(\)->prepare\(\s*$/', $line)) {
        
        // Check if next line has INSER IGNORE
        $nextIdx = $i + 1;
        while ($nextIdx < $totalOut && trim($output[$nextIdx]) === '') $nextIdx++;
        
        if ($nextIdx < $totalOut && strpos($output[$nextIdx], 'INSERT IGNORE INTO peer_assignments') !== false) {
            // Check if this is in a function that doesn't already have $deadline
            // Scan backwards to find if $deadline was already set
            $hasDeadline = false;
            for ($j = max(0, $i - 30); $j < $i; $j++) {
                if (strpos($output[$j], '\$deadline =') !== false) {
                    $hasDeadline = true;
                    break;
                }
            }
            
            if (!$hasDeadline) {
                // Insert $deadline variable before this line
                $indent = '';
                if (preg_match('/^(\s+)/', $line, $m)) {
                    $indent = $m[1];
                }
                $output2[] = $indent . "\$deadline = date('Y-m-d', strtotime('+14 days'));\n";
                $changes++;
                echo "Added \$deadline before prepare at line " . ($i+1) . "\n";
            }
        }
        $output2[] = $line;
        $i++;
        continue;
    }
    
    $output2[] = $line;
    $i++;
}

// Third pass: add deadline to execute arrays
$output3 = [];
$i = 0;
$totalOut2 = count($output2);
$inExecute = false;
$executeArrayEnd = null;

while ($i < $totalOut2) {
    $line = $output2[$i];
    $output3[] = $line;
    
    // Detect execute arrays after peer_assignment inserts
    if (preg_match('/\$insertAssignment->execute\(\[/', $line) || 
        preg_match('/->execute\(\[/', $line)) {
        // Check if we're near a peer_assignment INSERT
        $inExecute = true;
    }
    
    if ($inExecute && trim($line) === '];') {
        // Check if deadline is already in this array
        $hasDeadline = false;
        for ($j = max(0, count($output3) - 20); $j < count($output3) - 1; $j++) {
            if (strpos($output3[$j], "'deadline'") !== false) {
                $hasDeadline = true;
                break;
            }
        }
        
        if (!$hasDeadline) {
            // Check if this execute is associated with a peer_assignments INSERT
            $nearInsert = false;
            for ($j = max(0, count($output3) - 30); $j < count($output3); $j++) {
                if (strpos($output3[$j], 'INSERT IGNORE INTO peer_assignments') !== false) {
                    $nearInsert = true;
                    break;
                }
            }
            
            if ($nearInsert) {
                // This execute array is associated with peer_assignments
                // Add 'deadline' => $deadline before the closing ];
                $lines_before = array_slice($output3, 0, -1);
                $lines_after = [];
                
                // Determine indentation
                preg_match('/^(\s+)/', $line, $m);
                $indent = $m[1] ?? '                        ';
                
                $lines_before[] = $indent . "'deadline' => \$deadline,\n";
                $lines_before[] = $line;
                $output3 = $lines_before;
                $changes++;
                echo "Added deadline param to execute at line ~" . ($i+1) . "\n";
            }
        }
        $inExecute = false;
    }
    
    $i++;
}

$newContent = implode('', $output3);
file_put_contents($file, $newContent);
echo "\nTotal changes: $changes\nDone.\n";
