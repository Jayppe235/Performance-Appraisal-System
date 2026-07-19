const fs = require('fs');
const path = require('path');
const filePath = path.join(__dirname, 'dashboards', 'admin_hr.php');
let content = fs.readFileSync(filePath, 'utf-8');

// === FIX 1: PHP Handler — move generate_peer_to_peer outside assign_leadership_evaluations block ===
// Current:
//   admin_redirect('assignments', 'Leadership evaluation tasks prepared...');
//
//   if ($action === 'generate_peer_to_peer') {
//     ...
//   }
//   }
//   }
//
// Need to close the leadership block before generate_peer_to_peer:

const leadershipRedirect = "admin_redirect('assignments', 'Leadership evaluation tasks prepared. New task count: ' . $created . '.');";

const oldPHPSection = 
`            admin_redirect('assignments', 'Leadership evaluation tasks prepared. New task count: ' . $created . '.');

        if ($action === 'generate_peer_to_peer') {
            $cycle = trim(\$_POST['cycle_name'] ?? 'Current Appraisal Cycle');
            $deadline = date('Y-m-d', strtotime('+14 days'));

            \$result = dipascaf_generate_peer_to_peer_assignments(\$cycle, \$deadline);

            \$created = \$result['created'];
            \$skipped = \$result['skipped_existing'];
            \$groups = \$result['groups_processed'];
            \$invalid = \$result['invalid_groups'];

            \$invalidMessages = [];
            foreach (\$invalid as \$group) {
                \$invalidMessages[] = \$group['scope'] . ' (' . \$group['eligible'] . ' eligible)';
            }

            \$message = 'Peer-to-peer evaluations generated. Created: ' . \$created . ', Skipped: ' . \$skipped . ', Groups: ' . \$groups;
            if (\$invalidMessages !== []) {
                \$message .= '. Insufficient members: ' . implode('; ', \$invalidMessages);
            }
            \$message .= '.';

            if (\$created > 0 || \$skipped > 0) {
                admin_activity(\$message);
                admin_redirect('assignments', \$message);
            } else {
                \$_SESSION['flash_error'] = 'Could not generate peer-to-peer evaluations. ' . (\$invalidMessages !== [] ? 'Insufficient members in: ' . implode('; ', \$invalidMessages) : 'No eligible groups found.');
                redirect('/dashboards/admin_hr.php?section=assignments');
            }
        }
        }`;

const newPHPSection =
`            admin_redirect('assignments', 'Leadership evaluation tasks prepared. New task count: ' . \$created . '.');
        }

        if (\$action === 'generate_peer_to_peer') {
            \$cycle = trim(\$_POST['cycle_name'] ?? 'Current Appraisal Cycle');
            \$deadline = date('Y-m-d', strtotime('+14 days'));

            \$result = dipascaf_generate_peer_to_peer_assignments(\$cycle, \$deadline);

            \$created = \$result['created'];
            \$skipped = \$result['skipped_existing'];
            \$groups = \$result['groups_processed'];
            \$invalid = \$result['invalid_groups'];

            \$invalidMessages = [];
            foreach (\$invalid as \$group) {
                \$invalidMessages[] = \$group['scope'] . ' (' . \$group['eligible'] . ' eligible)';
            }

            \$message = 'Peer-to-peer evaluations generated. Created: ' . \$created . ', Skipped: ' . \$skipped . ', Groups: ' . \$groups;
            if (\$invalidMessages !== []) {
                \$message .= '. Insufficient members: ' . implode('; ', \$invalidMessages);
            }
            \$message .= '.';

            if (\$created > 0 || \$skipped > 0) {
                admin_activity(\$message);
                admin_redirect('assignments', \$message);
            } else {
                \$_SESSION['flash_error'] = 'Could not generate peer-to-peer evaluations. ' . (\$invalidMessages !== [] ? 'Insufficient members in: ' . implode('; ', \$invalidMessages) : 'No eligible groups found.');
                redirect('/dashboards/admin_hr.php?section=assignments');
            }
        }`;

if (content.includes(oldPHPSection)) {
    content = content.replace(oldPHPSection, newPHPSection);
    console.log('FIX 1: PHP handler nesting fixed successfully.');
} else {
    console.log('FIX 1: Could not find the exact PHP section to replace. Trying alternative approach...');
    // Try with \r\n line endings
    const oldWithCRLF = oldPHPSection.replace(/\n/g, '\r\n');
    const newWithCRLF = newPHPSection.replace(/\n/g, '\r\n');
    if (content.includes(oldWithCRLF)) {
        content = content.replace(oldWithCRLF, newWithCRLF);
        console.log('FIX 1: PHP handler nesting fixed (with CRLF).');
    } else {
        console.log('FIX 1: Still could not find the match. Checking file state...');
        // Try a more direct approach - just fix the specific problematic lines
        const lines = content.split(/\r?\n/);
        for (let i = 0; i < lines.length; i++) {
            if (lines[i].includes("admin_redirect('assignments', 'Leadership evaluation tasks prepared")) {
                console.log(`Found leadership redirect at line ${i+1}`);
                // Replace "admin_redirect...\n\n        if ($action === 'generate_peer_to_peer')" with "admin_redirect...\n        }\n\n        if ($action === 'generate_peer_to_peer')"
                let searchStr = lines[i] + '\n';
                if (i+1 < lines.length) searchStr += lines[i+1] + '\n';
                if (i+2 < lines.length) searchStr += lines[i+2];
                
                console.log(`Context: "${lines[i].substring(0, 80)}..."`);
                break;
            }
        }
    }
}

// === FIX 2: HTML nesting — close the Leadership Reviews form/section before new sections ===
// Insert </form></section> after the Assign Leadership Reviews button
// Then remove the stray </form></section> that was left at the end

const buttonLine = '                        <button type=\"submit\">Assign Leadership Reviews</button>';

// The stray closing tags (with leading whitespace preserved)
const strayClose = '\n                \n                    </form>\n                </section>\n';

if (content.includes(buttonLine)) {
    // Insert </form>\n</section> right after the button
    const closingTags = '\n                    </form>\n                </section>\n';
    content = content.replace(buttonLine, buttonLine + closingTags);
    console.log('FIX 2a: Added form/section closing tags after Assign Leadership Reviews button.');
} else {
    console.log('FIX 2a: Could not find the button line.');
}

// Remove the stray closing tags that were originally placed after the inserted sections
// The stray tags appear as blank line + whitespace + </form> + whitespace + </section>
const strayPattern1 = /\n\s*\n\s*<\/form>\s*\n\s*<\/section>\s*\n/;
if (strayPattern1.test(content)) {
    content = content.replace(strayPattern1, '\n');
    console.log('FIX 2b: Removed stray form/section closing tags.');
} else {
    console.log('FIX 2b: Could not find stray closing tags pattern.');
    // Try with more precise matching
    const strayExact = '\n\n                    </form>\n                </section>\n';
    if (content.includes(strayExact)) {
        content = content.replace(strayExact, '\n');
        console.log('FIX 2b: Removed stray closing tags (exact match).');
    }
}

fs.writeFileSync(filePath, content, 'utf-8');
console.log('All fixes applied. File saved.');
