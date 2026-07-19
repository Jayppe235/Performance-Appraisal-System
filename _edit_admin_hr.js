const fs = require('fs');
const path = require('path');

const filePath = path.resolve('dashboards/admin_hr.php');
let content = fs.readFileSync(filePath, 'utf8');

let modified = content;

// 1. Add $peerAssignmentsFull data variable after $leadershipEvaluatees line
modified = modified.replace(
  '$leadershipEvaluatees = admin_leadership_evaluatees();',
  '$leadershipEvaluatees = admin_leadership_evaluatees();\n$peerAssignmentsFull = admin_peer_assignments_full();'
);

// 2. Add POST handler after assign_leadership_evaluations redirect
const handlerCode = `

        if ($action === 'generate_peer_to_peer') {
            $cycle = trim($_POST['cycle_name'] ?? 'Current Appraisal Cycle');
            $deadline = date('Y-m-d', strtotime('+14 days'));

            $result = dipascaf_generate_peer_to_peer_assignments($cycle, $deadline);

            $created = $result['created'];
            $skipped = $result['skipped_existing'];
            $groups = $result['groups_processed'];
            $invalid = $result['invalid_groups'];

            $invalidMessages = [];
            foreach ($invalid as $group) {
                $invalidMessages[] = $group['scope'] . ' (' . $group['eligible'] . ' eligible)';
            }

            $message = 'Peer-to-peer evaluations generated. Created: ' . $created . ', Skipped: ' . $skipped . ', Groups: ' . $groups;
            if ($invalidMessages !== []) {
                $message .= '. Insufficient members: ' . implode('; ', $invalidMessages);
            }
            $message .= '.';

            if ($created > 0 || $skipped > 0) {
                admin_activity($message);
                admin_redirect('assignments', $message);
            } else {
                $_SESSION['flash_error'] = 'Could not generate peer-to-peer evaluations. ' . ($invalidMessages !== [] ? 'Insufficient members in: ' . implode('; ', $invalidMessages) : 'No eligible groups found.');
                redirect('/dashboards/admin_hr.php?section=assignments');
            }
        }`;

modified = modified.replace(
  "admin_redirect('assignments', 'Leadership evaluation tasks prepared. New task count: ' . $created . '.');",
  "admin_redirect('assignments', 'Leadership evaluation tasks prepared. New task count: ' . $created . '.');" + handlerCode
);

// 3. Add Generate Peer Evaluation button and Peer Assignment Monitor table
// Find the section around "Assign Leadership Reviews" button
const uiCode = `                <section class="admin-box module-form">
                    <div class="box-title"><h2>Peer-to-Peer Evaluation Assignment</h2><span>Group-based peer matching</span></div>
                    <form method="post" class="admin-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="generate_peer_to_peer">
                        <label>Cycle Name<input name="cycle_name" value="<?= e($periods[0]['period_name'] ?? 'Current Appraisal Cycle') ?>" required></label>
                        <button type="submit">Generate Peer Evaluation Assignment</button>
                    </form>
                </section>

                <?php if ($visibleLeadershipEvaluatees !== []): ?>
                    <section class="admin-box module-table">
                        <div class="box-title"><h2>Peer-to-Peer Assignment Summary</h2><span>Eligibility check</span></div>
                        <table class="data-table">
                            <thead><tr><th>Group</th><th>Eligible Members</th></tr></thead>
                            <tbody>
                            <?php
                                $deans = admin_all("SELECT id FROM users WHERE role = 'dean' AND is_active = 1");
                                $programHeads = admin_all("SELECT COALESCE(NULLIF(u.department, ''), 'Unassigned') AS dept FROM users u WHERE u.role = 'program_head' AND u.is_active = 1 GROUP BY dept");
                                $facultyGroups = admin_all("SELECT COALESCE(NULLIF(f.program_code, ''), 'Unassigned') AS prog FROM faculty f JOIN users u ON (u.id = f.user_id OR u.email = f.email) WHERE u.role = 'teacher' AND u.is_active = 1 AND f.is_archived = 0 GROUP BY prog");
                            ?>
                                <tr><td data-label="Group">Deans</td><td data-label="Eligible Members"><?= count($deans) ?> members</td></tr>
                                <tr><td data-label="Group">Program Heads</td><td data-label="Eligible Members"><?= count($programHeads) ?> departments</td></tr>
                                <tr><td data-label="Group">Faculty</td><td data-label="Eligible Members"><?= count($facultyGroups) ?> programs</td></tr>
                            </tbody>
                        </table>
                    </section>
                <?php endif; ?>

                <section class="admin-box module-table">
                    <div class="box-title"><h2>Peer Assignment Monitor</h2><span><?= count($peerAssignmentsFull) ?> total</span></div>
                    <div class="table-scroll">
                    <table class="data-table">
                        <thead><tr><th>Evaluator</th><th>Role</th><th>Evaluatee</th><th>Position</th><th>Department</th><th>Program</th><th>Type</th><th>Status</th><th>Deadline</th><th>Assigned</th></tr></thead>
                        <tbody>
                        <?php foreach ($peerAssignmentsFull as $pa): ?>
                            <tr>
                                <td data-label="Evaluator"><?= e($pa['evaluator_name']) ?></td>
                                <td data-label="Role"><?= e(admin_role_label($pa['evaluator_role_name'])) ?></td>
                                <td data-label="Evaluatee"><?= e($pa['evaluatee_name']) ?></td>
                                <td data-label="Position"><?= e($pa['position_title'] ?: 'N/A') ?></td>
                                <td data-label="Department"><?= e($pa['department'] ?: 'N/A') ?></td>
                                <td data-label="Program"><?= e($pa['program_code']) ?></td>
                                <td data-label="Type"><?= e($pa['assignment_type'] === 'peer' ? 'Peer' : ($pa['assignment_type'] === 'dean' ? 'Dean' : ($pa['assignment_type'] === 'self' ? 'Self' : admin_status_label($pa['assignment_type'])))) ?></td>
                                <td data-label="Status"><span class="status-badge status-<?= e($pa['display_status']) ?>"><?= e(admin_status_label($pa['status'])) ?></span></td>
                                <td data-label="Deadline"><?= e($pa['deadline'] ?? 'N/A') ?></td>
                                <td data-label="Assigned"><?= e(date('M j, Y', strtotime((string) $pa['assigned_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($peerAssignmentsFull === []): ?>
                            <tr><td colspan="10">No peer assignments found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </section>

                `;

// Insert the UI code right before the "Assign Leadership Reviews" button
modified = modified.replace(
  '<button type="submit">Assign Leadership Reviews</button>',
  '<button type="submit">Assign Leadership Reviews</button>\n' + uiCode
);

// Check if the edits look reasonable
const handlerCount = (modified.match(/generate_peer_to_peer/g) || []).length;
const dataVarCount = (modified.match(/peerAssignmentsFull/g) || []).length;
const uiButtonCount = (modified.match(/Generate Peer Evaluation Assignment/g) || []).length;

console.log('POST handler occurrences:', handlerCount);
console.log('peerAssignmentsFull occurrences:', dataVarCount);
console.log('Generate Peer Evaluation Assignment button:', uiButtonCount);

fs.writeFileSync(filePath, modified, 'utf8');
console.log('File written successfully');
