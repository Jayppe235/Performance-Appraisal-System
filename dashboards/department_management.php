<?php
declare(strict_types=1);

// Department Management Page (included in admin_hr.php when section === 'dept_management')

// Get all departments for the selector
$managementDepartments = admin_all(
    'SELECT d.id, d.department_code, d.department_name, u.full_name AS dean_name, COUNT(DISTINCT f.id) AS faculty_count
     FROM departments d
     LEFT JOIN users u ON u.id = d.dean_user_id
     LEFT JOIN faculty f ON f.department = d.department_name
     WHERE d.is_active = 1
     GROUP BY d.id, d.department_code, d.department_name, u.full_name
     ORDER BY d.department_name'
);

// Get selected department
$deptManagementId = (int) ($_GET['dept_mgmt_id'] ?? 0);
if ($deptManagementId === 0 && !empty($managementDepartments)) {
    $deptManagementId = (int) $managementDepartments[0]['id'];
}

$managementSelectedDept = null;
$managementDean = null;
$managementFaculty = [];
$availableUsers = [];

if ($deptManagementId > 0) {
    // Get department details
    $managementSelectedDept = admin_one(
        'SELECT d.*, u.full_name AS dean_name, u.email AS dean_email, u.phone AS dean_phone
         FROM departments d
         LEFT JOIN users u ON u.id = d.dean_user_id
         WHERE d.id = :department_id',
        ['department_id' => $deptManagementId]
    );

    if ($managementSelectedDept) {
        // Get dean info
        if ($managementSelectedDept['dean_user_id']) {
            $managementDean = admin_one(
                'SELECT id, full_name, email, phone FROM users WHERE id = :user_id',
                ['user_id' => (int) $managementSelectedDept['dean_user_id']]
            );
        }

        // Get all faculty in this department with evaluation completion stats
        $managementFaculty = admin_all(
            'SELECT f.id, f.full_name, f.email, f.phone, f.program_code, f.position_title, f.progress_percent,
                    COALESCE(eval_stats.total_assignments, 0) AS total_eval_assignments,
                    COALESCE(eval_stats.completed_assignments, 0) AS completed_evaluations
             FROM faculty f
             LEFT JOIN (
                 SELECT pa.evaluatee_faculty_id,
                        COUNT(*) AS total_assignments,
                        SUM(CASE WHEN pa.status = \'submitted\' THEN 1 ELSE 0 END) AS completed_assignments
                 FROM peer_assignments pa
                 GROUP BY pa.evaluatee_faculty_id
             ) eval_stats ON eval_stats.evaluatee_faculty_id = f.id
             WHERE f.department = :department_name AND f.is_archived = 0
             ORDER BY f.program_code, f.full_name',
            ['department_name' => $managementSelectedDept['department_name']]
        );

        // Calculate department-wide evaluation stats from per-faculty data
        $deptTotalAssignments = array_sum(array_column($managementFaculty, 'total_eval_assignments'));
        $deptCompletedAssignments = array_sum(array_column($managementFaculty, 'completed_evaluations'));
        $deptEvalPercent = $deptTotalAssignments > 0 ? round(($deptCompletedAssignments / $deptTotalAssignments) * 100) : 0;

        // Get available users for dean assignment (users with admin or dean role not assigned elsewhere)
        $availableUsers = admin_all(
            'SELECT u.id, u.full_name, u.email, u.role
             FROM users u
             WHERE u.role IN ("admin_hr", "dean") AND u.is_active = 1 AND (u.id != :current_dean_id OR :current_dean_check IS NULL)
             ORDER BY u.full_name',
            [
                'current_dean_id' => $managementSelectedDept['dean_user_id'] ?? null,
                'current_dean_check' => $managementSelectedDept['dean_user_id'] ?? null,
            ]
        );
    }
}
?>

<!-- Department Management Header -->
<div class="admin-header">
    <h1>Department Management</h1>
    <p>Manage departments, assign deans, view program heads and faculty</p>
</div>

<!-- Department Selector -->
<section class="admin-box module-form">
    <div class="box-title"><h2>Select Department</h2></div>
    <form method="get" action="<?= BASE_URL ?>/dashboards/admin_hr.php" class="admin-form">
        <input type="hidden" name="section" value="dept_management">
        <label>
            Department
            <select name="dept_mgmt_id" onchange="this.form.submit()">
                <option value="">-- Select Department --</option>
                <?php foreach ($managementDepartments as $dept): ?>
                    <option value="<?= e((string) $dept['id']) ?>" <?= (int) $deptManagementId === (int) $dept['id'] ? 'selected' : '' ?>>
                        <?= e($dept['department_name']) ?> (<?= e($dept['department_code']) ?>) - <?= e((string) $dept['faculty_count']) ?> faculty
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
</section>

<?php if ($managementSelectedDept): ?>
    <!-- Department Info Card -->
    <section class="admin-box module-wide">
        <div class="box-title">
            <h2><?= e($managementSelectedDept['department_name']) ?></h2>
            <span><?= e($managementSelectedDept['department_code']) ?></span>
        </div>
        <div class="stat-grid">
            <article>
                <span>Department Code</span>
                <strong><?= e($managementSelectedDept['department_code']) ?></strong>
            </article>
            <article>
                <span>Dean Assigned</span>
                <strong><?= $managementSelectedDept['dean_user_id'] ? 'Yes' : 'No' ?></strong>
            </article>
            <article>
                <span>Faculty Members</span>
                <strong><?= count($managementFaculty) ?></strong>
            </article>
            <article>
                <span>Total Evaluations</span>
                <strong><?= e((string) $deptTotalAssignments) ?></strong>
            </article>
            <article>
                <span>Completed</span>
                <strong><?= e((string) $deptCompletedAssignments) ?></strong>
            </article>
            <article>
                <span>Completion</span>
                <strong><?= e((string) $deptEvalPercent) ?>%</strong>
            </article>
        </div>

        <?php if ($deptTotalAssignments > 0): ?>
        <div style="margin-top: 20px; background: #f8faff; border: 1px solid #e0e7ff; border-radius: 8px; padding: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <span style="font-weight: 600; color: #366092; font-size: 14px;">Evaluation Completion Progress</span>
                <span style="font-weight: 700; color: #366092; font-size: 18px;"><?= e((string) $deptCompletedAssignments) ?> / <?= e((string) $deptTotalAssignments) ?> (<?= e((string) $deptEvalPercent) ?>%)</span>
            </div>
            <div style="height: 12px; background: #e8ecf4; border-radius: 6px; overflow: hidden;">
                <div style="height: 100%; width: <?= e((string) $deptEvalPercent) ?>%; background: linear-gradient(90deg, #4caf50, #45a049); border-radius: 6px; transition: width 0.5s ease;"></div>
            </div>
            <div style="display: flex; justify-content: space-between; margin-top: 6px; font-size: 12px; color: #888;">
                <span>0%</span>
                <span><?= $deptEvalPercent >= 50 ? '✓ On track' : '⚠ Needs attention' ?></span>
                <span>100%</span>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <!-- Dean Management -->
    <section class="admin-box module-form">
        <div class="box-title">
            <h2>Department Dean</h2>
            <span>Assign or change the dean</span>
        </div>
        
        <?php if ($managementDean): ?>
            <div style="background: #f0f8ff; border-left: 4px solid #366092; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
                <p style="margin: 0;"><strong>Current Dean:</strong> <?= e($managementDean['full_name']) ?></p>
                <p style="margin: 5px 0 0 0; font-size: 14px; color: #666;">
                    Email: <?= e($managementDean['email']) ?>
                    <?php if ($managementDean['phone']): ?>
                        | Phone: <?= e($managementDean['phone']) ?>
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
                <p style="margin: 0;"><strong>⚠ No Dean Assigned</strong></p>
                <p style="margin: 5px 0 0 0; font-size: 14px;">Assign a dean to manage this department.</p>
            </div>
        <?php endif; ?>

        <form method="post" class="admin-form">
            <?= csrf_token_input() ?>
            <input type="hidden" name="action" value="assign_dean">
            <input type="hidden" name="department_id" value="<?= e((string) $managementSelectedDept['id']) ?>">
            <label>
                Assign Dean
                <select name="dean_user_id">
                    <option value="">-- Select User --</option>
                    <?php foreach ($availableUsers as $user): ?>
                        <option value="<?= e((string) $user['id']) ?>" <?= (int) $managementSelectedDept['dean_user_id'] === (int) $user['id'] ? 'selected' : '' ?>>
                            <?= e($user['full_name']) ?> (<?= e($user['email']) ?>) - <?= e($user['role']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit">Update Dean</button>
        </form>
    </section>

    <!-- Faculty Section -->
    <?php if (!empty($managementFaculty)): ?>
        <section class="admin-box module-wide">
            <div class="box-title">
                <h2>Faculty Members (<?= count($managementFaculty) ?>)</h2>
                <span>All faculty assigned to this department</span>
            </div>
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Faculty Name</th>
                            <th>Program</th>
                            <th>Position</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Progress</th>
                            <th>Evaluations Done</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($managementFaculty as $fac): ?>
                            <tr>
                                <td><strong><?= e($fac['full_name']) ?></strong></td>
                                <td><?= e($fac['program_code']) ?></td>
                                <td><?= e($fac['position_title']) ?></td>
                                <td><a href="mailto:<?= e($fac['email']) ?>"><?= e($fac['email']) ?></a></td>
                                <td><?= e($fac['phone'] ?? '-') ?></td>
                                <td>
                                    <div style="display: flex; align-items: center; height: 24px; background: #f0f0f0; border-radius: 4px; overflow: hidden; position: relative; width: 80px;">
                                        <div style="background: #4caf50; height: 100%; width: <?= e((string) $fac['progress_percent']) ?>%; transition: width 0.3s ease;"></div>
                                        <span style="position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); font-size: 11px; font-weight: 600; color: #333; white-space: nowrap;"><?= e((string) $fac['progress_percent']) ?>%</span>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                        $facTotalEval = (int) ($fac['total_eval_assignments'] ?? 0);
                                        $facCompletedEval = (int) ($fac['completed_evaluations'] ?? 0);
                                        $facEvalPercent = $facTotalEval > 0 ? round(($facCompletedEval / $facTotalEval) * 100) : 0;
                                    ?>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="display: flex; align-items: center; height: 24px; background: #f0f0f0; border-radius: 4px; overflow: hidden; position: relative; width: 80px;">
                                            <div style="background: <?= $facEvalPercent >= 100 ? '#4caf50' : ($facEvalPercent >= 50 ? '#ff9800' : '#f44336') ?>; height: 100%; width: <?= e((string) $facEvalPercent) ?>%; transition: width 0.3s ease;"></div>
                                            <span style="position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); font-size: 11px; font-weight: 600; color: <?= $facEvalPercent > 50 ? '#fff' : '#666' ?>; white-space: nowrap;"><?= e((string) $facEvalPercent) ?>%</span>
                                        </div>
                                        <span style="font-size: 12px; color: #666; white-space: nowrap;"><?= e((string) $facCompletedEval) ?>/<?= e((string) $facTotalEval) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <a href="<?= BASE_URL ?>/dashboards/admin_hr.php?section=people&search=<?= urlencode($fac['full_name']) ?>" style="color: #366092; text-decoration: none; font-size: 13px;">Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php else: ?>
        <div class="notice info" style="margin-top: 20px;">
            <p>No faculty members assigned to this department yet.</p>
        </div>
    <?php endif; ?>

<?php else: ?>
    <div class="notice info">
        <p>Select a department to manage its dean, program heads, and faculty.</p>
    </div>
<?php endif; ?>

<style>
    .admin-table-wrapper {
        overflow-x: auto;
        margin-top: 15px;
    }

    .admin-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }

    .admin-table thead {
        background-color: #f5f5f5;
        border-bottom: 2px solid #ddd;
    }

    .admin-table th {
        padding: 12px;
        text-align: left;
        font-weight: 600;
        color: #333;
    }

    .admin-table td {
        padding: 12px;
        border-bottom: 1px solid #eee;
    }

    .admin-table tbody tr:hover {
        background-color: #f9f9f9;
    }

    .stat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 15px;
        margin-top: 20px;
    }

    .stat-grid article {
        background: white;
        border: 1px solid #e0e0e0;
        border-radius: 6px;
        padding: 15px;
        text-align: center;
    }

    .stat-grid span {
        display: block;
        font-size: 12px;
        color: #666;
        margin-bottom: 8px;
        font-weight: 500;
    }

    .stat-grid strong {
        display: block;
        font-size: 24px;
        color: #366092;
        font-weight: 700;
    }
</style>
