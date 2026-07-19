<?php
// Targeted fixer for evaluation_cards.php corruption patterns
$file = __DIR__ . '/includes/evaluation_cards.php';
$content = file_get_contents($file);

// Pattern 1: Fix `}\n    )` that closes admin_one( calls incorrectly
// admin_one(...) should end with `)` not `}`
// This regex matches `}\n    )` and changes it to `)\n    )`
$content = preg_replace(
    '/\}\n(\s*)\)/',
    ")\n\$1)",
    $content
);

// Pattern 2: Fix `}\n        )` within function calls
$content = preg_replace(
    '/\}\n(\s*)\)/',  
    ")\n\$1)",
    $content  
);

// Pattern 3: Fix `elsestr_contains` → `} elseif (str_contains`
$content = preg_replace(
    '/else(str_contains\(/',
    "} elseif (\$1",
    $content
);

// Pattern 4: Fix `else` followed immediately by keyword (no space)
$content = preg_replace_callback(
    '/else([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/',
    function($m) {
        return "} elseif ({$m[1]}(";
    },
    $content
);

// Pattern 5: Remove bare `}\n        }\n        ]` patterns where a closing brace appears before `]`
$content = preg_replace(
    '/\}\s*\n\s*\}\s*\n\s*\]/s',
    "}\n        ]",
    $content
);

// Pattern 6: Fix extra closure in foreach/function end
// Look for patterns where there's an extra `}` at end of constructs

// Write fixed content
file_put_contents($file, $content);
echo "Applied targeted fixes\n";

// Verify
$result = shell_exec("php -l " . escapeshellarg($file) . " 2>&1");
echo "Lint: " . $result;
