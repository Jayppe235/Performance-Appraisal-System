$file = 'dashboards/admin_hr.php'
$lines = Get-Content $file
$newLines = [System.Collections.ArrayList]@()

# First pass: add POST handler after assign_leadership_evaluations redirect
$handlerInserted = $false
$dataVarInserted = $false

for ($i = 0; $i -lt $lines.Count; $i++) {
    $line = $lines[$i]
    
    # Add POST handler after the assign_leadership_evaluations redirect
    if (-not $handlerInserted -and $line -match "admin_redirect\('assignments', 'Leadership evaluation tasks prepared") {
        $null = $newLines.Add($line)
        
        # Insert the handler
        $null = $newLines.Add("")
        $null = $newLines.Add("        if (`$action === 'generate_peer_to_peer') {")
        $null = $newLines.Add("            `$cycle = trim(`$_POST['cycle_name'] ?? 'Current Appraisal Cycle');")
        $null = $newLines.Add("            `$deadline = date('Y-m-d', strtotime('+14 days'));")
        $null = $newLines.Add("")
        $null = $newLines.Add("            `$result = dipascaf_generate_peer_to_peer_assignments(`$cycle, `$deadline);")
        $null = $newLines.Add("")
        $null = $newLines.Add("            `$created = `$result['created'];")
        $null = $newLines.Add("            `$skipped = `$result['skipped_existing'];")
        $null = $newLines.Add("            `$groups = `$result['groups_processed'];")
        $null = $newLines.Add("            `$invalid = `$result['invalid_groups'];")
        $null = $newLines.Add("")
        $null = $newLines.Add("            `$invalidMessages = [];")
        $null = $newLines.Add("            foreach (`$invalid as `$group) {")
        $null = $newLines.Add("                `$invalidMessages[] = `$group['scope'] . ' (' . `$group['eligible'] . ' eligible)';")
        $null = $newLines.Add("            }")
        $null = $newLines.Add("")
        $null = $newLines.Add("            `$message = 'Peer-to-peer evaluations generated. Created: ' . `$created . ', Skipped: ' . `$skipped . ', Groups: ' . `$groups;")
        $null = $newLines.Add("            if (`$invalidMessages -ne []) {")
        $null = $newLines.Add("                `$message .= '. Insufficient members: ' . implode('; ', `$invalidMessages);")
        $null = $newLines.Add("            }")
        $null = $newLines.Add("            `$message .= '.';")
        $null = $newLines.Add("")
        $null = $newLines.Add("            if (`$created -gt 0 -or `$skipped -gt 0) {")
        $null = $newLines.Add("                admin_activity(`$message);")
        $null = $newLines.Add("                admin_redirect('assignments', `$message);")
        $null = $newLines.Add("            } else {")
        $null = $newLines.Add("                `$_SESSION['flash_error'] = 'Could not generate peer-to-peer evaluations. ' . (`$invalidMessages -ne [] ? 'Insufficient members in: ' . implode('; ', `$invalidMessages) : 'No eligible groups found.');")
        $null = $newLines.Add("                redirect('/dashboards/admin_hr.php?section=assignments');")
        $null = $newLines.Add("            }")
        $null = $newLines.Add("        }")
        
        $handlerInserted = $true
        continue
    }
    
    # Add $peerAssignmentsFull data variable after $leadershipEvaluatees
    if (-not $dataVarInserted -and $line -match "`$leadershipEvaluatees = admin_leadership_evaluatees\(\);") {
        $null = $newLines.Add($line)
        $null = $newLines.Add("`$peerAssignmentsFull = admin_peer_assignments_full();")
        $dataVarInserted = $true
        continue
    }
    
    # Add Generate Peer Evaluation button/form before the closing div of assignment setup
    if ($line -match "Assign Leadership Reviews") {
        # Add the peer-to-peer button right before this
        $null = $newLines.Add("                <section class=`"admin-box module-form`">")
        $null = $newLines.Add("                    <div class=`"box-title`"><h2>Peer-to-Peer Evaluation Assignment</h2><span>Group-based peer matching</span></div>")
        $null = $newLines.Add("                    <form method=`"post`" class=`"admin-form`">")
        $null = $newLines.Add("                        <input type=`"hidden`" name=`"csrf_token`" value=`"<?= e(csrf_token()) ?>`">")
        $null = $newLines.Add("                        <input type=`"hidden`" name=`"action`" value=`"generate_peer_to_peer`">")
        $null = $newLines.Add("                        <label>Cycle Name<input name=`"cycle_name`" value=`"<?= e(`$periods[0]['period_name'] ?? 'Current Appraisal Cycle') ?>`" required></label>")
        $null = $newLines.Add("                        <button type=`"submit`">Generate Peer Evaluation Assignment</button>")
        $null = $newLines.Add("                    </form>")
        $null = $newLines.Add("                </section>")
        $null = $newLines.Add("")
        $null = $newLines.Add("                <?php if (`$visibleLeadershipEvaluatees !== []): ?>")
        $null = $newLines.Add("                    <section class=`"admin-box module-table`">")
        $null = $newLines.Add("                        <div class=`"box-title`"><h2>Peer-to-Peer Assignment Summary</h2><span>Eligibility check</span></div>")
        $null = $newLines.Add("                        <table class=`"data-table`">")
        $null = $newLines.Add("                            <thead><tr><th>Group</th><th>Eligible Members</th></tr></thead>")
        $null = $newLines.Add("                            <tbody>")
        $null = $newLines.Add("                            <?php")
        $null = $newLines.Add("                                `$deans = admin_all(\"SELECT id FROM users WHERE role = 'dean' AND is_active = 1\");")
        $null = $newLines.Add("                                `$programHeads = admin_all(\"SELECT COALESCE(NULLIF(u.department, ''), 'Unassigned') AS dept FROM users u WHERE u.role = 'program_head' AND u.is_active = 1 GROUP BY dept\");")
        $null = $newLines.Add("                                `$facultyGroups = admin_all(\"SELECT COALESCE(NULLIF(f.program_code, ''), 'Unassigned') AS prog FROM faculty f JOIN users u ON (u.id = f.user_id OR u.email = f.email) WHERE u.role = 'teacher' AND u.is_active = 1 AND f.is_archived = 0 GROUP BY prog\");")
        $null = $newLines.Add("                            ?>")
        $null = $newLines.Add("                                <tr><td data-label=`"Group`">Deans</td><td data-label=`"Eligible Members`"><?= count(`$deans) ?> members</td></tr>")
        $null = $newLines.Add("                                <tr><td data-label=`"Group`">Program Heads</td><td data-label=`"Eligible Members`"><?= count(`$programHeads) ?> departments</td></tr>")
        $null = $newLines.Add("                                <tr><td data-label=`"Group`">Faculty</td><td data-label=`"Eligible Members`"><?= count(`$facultyGroups) ?> programs</td></tr>")
        $null = $newLines.Add("                            </tbody>")
        $null = $newLines.Add("                        </table>")
        $null = $newLines.Add("                    </section>")
        $null = $newLines.Add("                <?php endif; ?>")
        $null = $newLines.Add("")
        $null = $newLines.Add("                <section class=`"admin-box module-table`">")
        $null = $newLines.Add("                    <div class=`"box-title`"><h2>Peer Assignment Monitor</h2><span><?= count(`$peerAssignmentsFull) ?> total</span></div>")
        $null = $newLines.Add("                    <div class=`"table-scroll`">")
        $null = $newLines.Add("                    <table class=`"data-table`">")
        $null = $newLines.Add("                        <thead><tr><th>Evaluator</th><th>Role</th><th>Evaluatee</th><th>Position</th><th>Department</th><th>Program</th><th>Type</th><th>Status</th><th>Deadline</th><th>Assigned</th></tr></thead>")
        $null = $newLines.Add("                        <tbody>")
        $null = $newLines.Add("                        <?php foreach (`$peerAssignmentsFull as `$pa): ?>")
        $null = $newLines.Add("                            <tr>")
        $null = $newLines.Add("                                <td data-label=`"Evaluator`"><?= e(`$pa['evaluator_name']) ?></td>")
        $null = $newLines.Add("                                <td data-label=`"Role`"><?= e(admin_role_label(`$pa['evaluator_role_name'])) ?></td>")
        $null = $newLines.Add("                                <td data-label=`"Evaluatee`"><?= e(`$pa['evaluatee_name']) ?></td>")
        $null = $newLines.Add("                                <td data-label=`"Position`"><?= e(`$pa['position_title'] ?: 'N/A') ?></td>")
        $null = $newLines.Add("                                <td data-label=`"Department`"><?= e(`$pa['department'] ?: 'N/A') ?></td>")
        $null = $newLines.Add("                                <td data-label=`"Program`"><?= e(`$pa['program_code']) ?></td>")
        $null = $newLines.Add("                                <td data-label=`"Type`"><?= e(`$pa['assignment_type'] === 'peer' ? 'Peer' : (`$pa['assignment_type'] === 'dean' ? 'Dean' : (`$pa['assignment_type'] === 'self' ? 'Self' : admin_status_label(`$pa['assignment_type'])))) ?></td>")
        $null = $newLines.Add("                                <td data-label=`"Status`"><span class=`"status-badge status-<?= e(`$pa['display_status']) ?>`"><?= e(admin_status_label(`$pa['status'])) ?></span></td>")
        $null = $newLines.Add("                                <td data-label=`"Deadline`"><?= e(`$pa['deadline'] ?? 'N/A') ?></td>")
        $null = $newLines.Add("                                <td data-label=`"Assigned`"><?= e(date('M j, Y', strtotime((string) `$pa['assigned_at']))) ?></td>")
        $null = $newLines.Add("                            </tr>")
        $null = $newLines.Add("                        <?php endforeach; ?>")
        $null = $newLines.Add("                        <?php if (`$peerAssignmentsFull === []): ?>")
        $null = $newLines.Add("                            <tr><td colspan=`"10`">No peer assignments found.</td></tr>")
        $null = $newLines.Add("                        <?php endif; ?>")
        $null = $newLines.Add("                        </tbody>")
        $null = $newLines.Add("                    </table>")
        $null = $newLines.Add("                    </div>")
        $null = $newLines.Add("                </section>")
        
        $null = $newLines.Add($line)
        continue
    }
    
    $null = $newLines.Add($line)
}

Set-Content -Path $file -Value $newLines.ToArray() -Encoding UTF8
Write-Host "Done editing admin_hr.php"
