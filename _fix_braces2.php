<?php
/**
 * Rebuild PHP file with proper braces using token_get_all.
 * This creates a proper PHP file by tracking brace depth at the construct level.
 */
$path = __DIR__ . '/includes/evaluation_cards.php';
$content = file_get_contents($path);

// Use PHP's tokenizer to get structured tokens
$tokens = @token_get_all($content);
echo "Tokenizer got " . count($tokens) . " tokens\n";

// Read line by line for indentation tracking
$lines = explode("\n", $content);

// Strategy: Build a new file from tokens, adding } when needed
// We track the brace depth and the indent of each brace
// At each statement boundary, we check if we need to close blocks

$output = [];
$braceStack = []; // [indent, line_number]

// Process line by line
foreach ($lines as $lineIdx => $line) {
    $lineNum = $lineIdx + 1;
    $trimmed = trim($line);
    
    // Skip blank lines
    if ($trimmed === '') {
        $output[] = $line;
        continue;
    }
    
    // Get indent
    preg_match('/^(\s*)/', $line, $m);
    $indent = strlen($m[1] ?? 0);
    
    // Count braces
    $openCount = 0;
    $closeCount = 0;
    $inString = false;
    $stringChar = '';
    $escape = false;
    
    for ($i = 0; $i < strlen($line); $i++) {
        $ch = $line[$i];
        
        if ($escape) {
            $escape = false;
            continue;
        }
        
        if ($inString) {
            if ($ch === '\\') {
                $escape = true;
            } elseif ($ch === $stringChar) {
                $inString = false;
            }
            continue;
        }
        
        if ($ch === '"' || $ch === "'") {
            $inString = true;
            $stringChar = $ch;
            continue;
        }
        
        if ($ch === '{') $openCount++;
        if ($ch === '}') $closeCount++;
    }
    
    $netBraces = $openCount - $closeCount;
    
    // Check if this line is a DIRECT block closer candidate
    // i.e., it starts a new statement at the same indent as a block that was opened earlier
    
    // Check if this is a "statement" line (not a continuation)
    // Lines that end with , are continuations
    $stmtEnding = preg_match('/;\s*$/', $trimmed) || preg_match('/\)\s*;\s*$/', $trimmed) || preg_match('/]\s*;\s*$/', $trimmed);
    $isNewStmt = $stmtEnding || preg_match('/^\s*(function|if|elseif|foreach|for|while|switch|try|catch|else\b|return|throw|continue|break)\s/', $trimmed);
    
    // Also detect expression statement start (variable assignment, function call, etc.)
    $isExprStmt = preg_match('/^\s*\$/', $trimmed) || preg_match('/^\s*[a-zA-Z_\\\\]/', $trimmed);
    
    // Close blocks when:
    // 1. We see a function declaration at a lower indent than an open block
    // 2. We see a control structure at the same indent as an open block
    
    if (!empty($braceStack)) {
        $top = end($braceStack);
        $topIndent = $top['indent'];
        
        // Case 1: New function at same indent as function - close previous function
        if ($openCount === 0 && $closeCount === 0 && preg_match('/^\s*function\s/', $trimmed)) {
            while (!empty($braceStack)) {
                $t = end($braceStack);
                if ($t['indent'] < $indent) break;
                array_pop($braceStack);
                $output[] = str_repeat(' ', $t['indent']) . '}';
            }
        }
        
        // Case 2: Control structure at same indent as previous control structure
        if ($openCount === 0 && $closeCount === 0 && $isNewStmt && $indent <= $topIndent) {
            // But only close if the previous stack entry is a control structure
            // AND this is a NEW statement that could be a sibling
            // This is tricky - `return` at indent 8 might be inside an if at indent 8
            
            // Safer approach: only close if we're at the same or lower indent
            // and the previous line had a higher indent (block body)
            $prevLine = trim($lines[$lineIdx - 1] ?? '');
            if (!empty($prevLine)) {
                preg_match('/^(\s*)/', $lines[$lineIdx - 1], $pm);
                $prevIndent = strlen($pm[1] ?? 0);
                
                // If the previous line was at a higher indent than this line
                // AND this line isn't a continuation
                if ($prevIndent > $indent) {
                    // Close open blocks at indent >= this indent
                    while (!empty($braceStack)) {
                        $t = end($braceStack);
                        if ($t['indent'] < $indent) break;
                        array_pop($braceStack);
                        $output[] = str_repeat(' ', $t['indent']) . '}';
                    }
                }
            }
        }
    }
    
    // Add the current line
    $output[] = $line;
    
    // If this line opens braces, push to stack
    if ($netBraces > 0) {
        for ($i = 0; $i < $netBraces; $i++) {
            $braceStack[] = ['indent' => $indent, 'line' => $lineNum];
        }
    }
}

// Close remaining open blocks
while (!empty($braceStack)) {
    $t = array_pop($braceStack);
    $output[] = str_repeat(' ', $t['indent']) . '}';
}

$fixedContent = implode("\n", $output);
echo "Open braces: " . substr_count($fixedContent, '{') . "\n";
echo "Close braces: " . substr_count($fixedContent, '}') . "\n";

file_put_contents($path, $fixedContent);

$result = shell_exec("php -l " . escapeshellarg($path) . " 2>&1");
echo "Validation: " . $result;
