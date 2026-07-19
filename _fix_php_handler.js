const fs = require('fs');
const path = require('path');

const filePath = path.join(__dirname, 'dashboards', 'admin_hr.php');
let content = fs.readFileSync(filePath, 'utf8');

// The bug: generate_peer_to_peer handler is inside assign_leadership_evaluations block
// after admin_redirect() which calls exit(). It's dead code.
//
// Current structure:
//   if ($action === 'assign_leadership_evaluations') {
//       ...
//       admin_redirect(...);
//
//       if ($action === 'generate_peer_to_peer') {
//           ... (dead code - never reached)
//       }
//       }     <-- closes assign_leadership_evaluations
//
// Target structure:
//   if ($action === 'assign_leadership_evaluations') {
//       ...
//       admin_redirect(...);
//   }
//
//   if ($action === 'generate_peer_to_peer') {
//       ... (now reachable)
//   }

// Find the exact string pattern we need to fix
const oldPattern = `            admin_redirect('assignments', 'Leadership evaluation tasks prepared. New task count: ' . $created . '.');

        if ($action === 'generate_peer_to_peer') {`;

const newPattern = `            admin_redirect('assignments', 'Leadership evaluation tasks prepared. New task count: ' . $created . '.');
        }

        if ($action === 'generate_peer_to_peer') {`;

// Check if the pattern matches
if (content.includes(oldPattern)) {
    content = content.replace(oldPattern, newPattern);
    console.log('Fix 1 applied: Added closing brace for assign_leadership_evaluations block before generate_peer_to_peer');
} else {
    console.log('Fix 1: Pattern not found - checking for alternatives...');
    
    // Try with \n line endings
    const oldPattern2 = `            admin_redirect('assignments', 'Leadership evaluation tasks prepared. New task count: ' . $created . '.');\n\n        if ($action === 'generate_peer_to_peer') {`;
    const newPattern2 = `            admin_redirect('assignments', 'Leadership evaluation tasks prepared. New task count: ' . $created . '.');\n        }\n\n        if ($action === 'generate_peer_to_peer') {`;
    
    if (content.includes(oldPattern2)) {
        content = content.replace(oldPattern2, newPattern2);
        console.log('Fix 1 applied (alt)');
    } else {
        // Try reading raw bytes to see the actual line endings
        const buf = fs.readFileSync(filePath);
        const matchPos = content.indexOf("admin_redirect('assignments', 'Leadership evaluation tasks prepared");
        if (matchPos >= 0) {
            console.log(`Found redirect at position ${matchPos}`);
            const snippet = content.substring(matchPos - 10, matchPos + 200);
            console.log('Snippet:', JSON.stringify(snippet));
        } else {
            console.log('Could not find the redirect string at all');
        }
    }
}

// Now remove the extra closing brace at the end of generate_peer_to_peer handler
// Find: "        }\r\n        }\r\n\r\n        if ($action === 'update_intervention')"
// Change to: "        }\r\n\r\n        if ($action === 'update_intervention')"

const extraBracePattern = `        }\n        }\n\n        if ($action === 'update_intervention') {`;
const extraBraceReplacement = `        }\n\n        if ($action === 'update_intervention') {`;

if (content.includes(extraBracePattern)) {
    content = content.replace(extraBracePattern, extraBraceReplacement);
    console.log('Fix 2 applied: Removed extra closing brace');
} else {
    // Try with \r\n
    const extraBracePattern2 = `        }\r\n        }\r\n\r\n        if ($action === 'update_intervention') {`;
    const extraBraceReplacement2 = `        }\r\n\r\n        if ($action === 'update_intervention') {`;
    
    if (content.includes(extraBracePattern2)) {
        content = content.replace(extraBracePattern2, extraBraceReplacement2);
        console.log('Fix 2 applied (with CRLF)');
    } else {
        console.log('Fix 2: Pattern not found');
        // Show what's around update_intervention
        const pos = content.indexOf("if ($action === 'update_intervention')");
        if (pos >= 0) {
            const snippet = content.substring(pos - 40, pos + 50);
            console.log('Snippet before update_intervention:', JSON.stringify(snippet));
        }
    }
}

fs.writeFileSync(filePath, content, 'utf8');
console.log('File saved');
