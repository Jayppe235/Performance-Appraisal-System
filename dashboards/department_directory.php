<?php
declare(strict_types=1);

// This file is included from admin_hr.php when section === 'directory'
// It should not have its own HTML structure, just content logic

// Get all departments
$allDepartments = admin_all(
    'SELECT d.id, d.department_code, d.department_name, u.full_name AS dean_name, u.email AS dean_email
     FROM departments d
     LEFT JOIN users u ON u.id = d.dean_user_id
     WHERE d.is_active = 1
     ORDER BY d.department_name'
);

// Get department selected from URL or default to first
$directoryDepartmentId = (int) ($_GET['dept_id'] ?? 0);
if ($directoryDepartmentId === 0 && !empty($allDepartments)) {
    $directoryDepartmentId = (int) $allDepartments[0]['id'];
}

$directorySelectedDept = null;
$directoryDean = null;
$directoryFaculty = [];
$directoryUsers = [];

if ($directoryDepartmentId > 0) {
    // Get department details
    $directorySelectedDept = admin_one(
        'SELECT d.*, u.full_name AS dean_name, u.email AS dean_email, u.phone AS dean_phone
         FROM departments d
         LEFT JOIN users u ON u.id = d.dean_user_id
         WHERE d.id = :department_id',
        ['department_id' => $directoryDepartmentId]
    );

    if ($directorySelectedDept) {
        $departmentAliases = admin_department_aliases($directorySelectedDept);

        // Get dean info if exists
        if ($directorySelectedDept['dean_user_id']) {
            $directoryDean = admin_one(
                'SELECT id, full_name, email, phone, profile_image FROM users WHERE id = :user_id',
                ['user_id' => (int) $directorySelectedDept['dean_user_id']]
            );
        }

        // Get all faculty in this department
        $directoryFaculty = admin_all(
            'SELECT f.id, f.full_name, f.email, f.phone, f.program_code, f.position_title, f.progress_percent
             FROM faculty f
             WHERE f.department = :department_name AND f.is_archived = 0
             ORDER BY f.program_code, f.full_name',
            ['department_name' => $directorySelectedDept['department_name']]
        );

        if ($departmentAliases !== []) {
            $placeholders = implode(',', array_fill(0, count($departmentAliases), '?'));
            $directoryUsers = admin_all(
                "SELECT id, full_name, email, phone, role, profile_image, department
                 FROM users
                 WHERE is_active = 1 AND department IN ($placeholders)
                 ORDER BY CASE role WHEN 'dean' THEN 1 WHEN 'program_head' THEN 2 WHEN 'teacher' THEN 3 ELSE 4 END, full_name",
                $departmentAliases
            );
        }
    }
}
?>

<!-- Department Selector -->
<section class="admin-box module-form">
    <div class="box-title"><h2>Select Department</h2></div>
    <form method="get" action="<?= BASE_URL ?>/dashboards/admin_hr.php" class="admin-form">
        <input type="hidden" name="section" value="directory">
        <label>
            Department
            <select name="dept_id" onchange="this.form.submit()">
                <option value="">-- Select Department --</option>
                <?php foreach ($allDepartments as $dept): ?>
                    <option value="<?= e((string) $dept['id']) ?>" <?= (int) $directoryDepartmentId === (int) $dept['id'] ? 'selected' : '' ?>>
                        <?= e($dept['department_name']) ?> (<?= e($dept['department_code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
</section>

<?php if ($directorySelectedDept): ?>
    <!-- Department Overview -->
    <section class="admin-box module-wide">
        <div class="box-title">
            <h2><?= e($directorySelectedDept['department_name']) ?></h2>
            <span><?= e($directorySelectedDept['department_code']) ?></span>
        </div>
        <div class="stat-grid">
            <article>
                <span>Department Accounts</span>
                <strong><?= count($directoryUsers) ?></strong>
            </article>
            <article>
                <span>Faculty Members</span>
                <strong><?= count($directoryFaculty) ?></strong>
            </article>
            <article>
                <span>Total Users</span>
                <strong><?= (int) ($directoryDean ? 1 : 0) + count($directoryFaculty) + count($directoryUsers) ?></strong>
            </article>
        </div>
    </section>

    <?php if (!empty($directoryUsers)): ?>
        <section class="admin-box module-wide">
            <div class="box-title"><h2>Department Accounts (<?= count($directoryUsers) ?>)</h2></div>
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Email</th>
                            <th>Phone</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($directoryUsers as $userRow): ?>
                            <tr>
                                <td><?= e($userRow['full_name']) ?></td>
                                <td><?= e(admin_role_label($userRow['role'])) ?></td>
                                <td><a href="mailto:<?= e($userRow['email']) ?>"><?= e($userRow['email']) ?></a></td>
                                <td><?= e($userRow['phone'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <!-- Dean Section -->
    <?php if ($directoryDean): ?>
        <section class="admin-box module-wide">
            <div class="box-title"><h2>Department Dean</h2></div>
            <div style="background: linear-gradient(135deg, #366092 0%, #2d4f7a 100%); color: white; border-radius: 8px; padding: 20px;">
                <div style="display: flex; gap: 15px; align-items: center;">
                    <div style="width: 80px; height: 80px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-size: 32px; font-weight: bold; flex-shrink: 0;">
                        <?php if ($directoryDean['profile_image']): ?>
                            <img src="<?= BASE_URL . '/' . e($directoryDean['profile_image']) ?>" alt="<?= e($directoryDean['full_name']) ?>" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                        <?php else: ?>
                            <?= strtoupper(substr($directoryDean['full_name'], 0, 1)) ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h3 style="margin: 0 0 10px 0;"><?= e($directoryDean['full_name']) ?></h3>
                        <p style="margin: 5px 0;"><strong>Email:</strong> <a href="mailto:<?= e($directoryDean['email']) ?>" style="color: white; text-decoration: underline;"><?= e($directoryDean['email']) ?></a></p>
                        <?php if ($directoryDean['phone']): ?>
                            <p style="margin: 5px 0;"><strong>Phone:</strong> <?= e($directoryDean['phone']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- Faculty Section -->
    <?php if (!empty($directoryFaculty)): ?>
        <section class="admin-box module-wide">
            <div class="box-title"><h2>Faculty Members (<?= count($directoryFaculty) ?>)</h2></div>
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Program</th>
                            <th>Position</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Progress</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($directoryFaculty as $fac): ?>
                            <tr>
                                <td><?= e($fac['full_name']) ?></td>
                                <td><?= e($fac['program_code']) ?></td>
                                <td><?= e($fac['position_title']) ?></td>
                                <td><a href="mailto:<?= e($fac['email']) ?>"><?= e($fac['email']) ?></a></td>
                                <td><?= e($fac['phone'] ?? '-') ?></td>
                                <td>
                                    <div style="display: flex; align-items: center; height: 24px; background: #f0f0f0; border-radius: 4px; overflow: hidden; position: relative;">
                                        <div style="background: #4caf50; height: 100%; width: <?= e((string) $fac['progress_percent']) ?>%; transition: width 0.3s ease;"></div>
                                        <span style="position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); font-size: 12px; font-weight: 600; color: #333;"><?= e((string) $fac['progress_percent']) ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
<?php else: ?>
    <div class="notice info">
        <p>Select a department to view its directory.</p>
    </div>
<?php endif; ?>
