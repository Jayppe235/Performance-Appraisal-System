<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/react_redirect.php';
redirect_to_react('/dean/evaluate');

require_once __DIR__ . '/../includes/dean_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';

require_role('dean');

$user = current_user();
$deanId = (int) $user['id'];
$departments = dean_departments($deanId);
dipascaf_init_evaluation_assignments($deanId, 'dean');
$dipascafAssignments = dipascaf_assignment_rows($deanId, 'dean');

// Fetch all assignments for the dean
$allAssignments = dean_assignments($deanId);

// Organize assignments and compute submission ids
$evaluatedAssignmentIds = [];
$assignmentIds = [];

foreach ($allAssignments as $assignment) {
    $assignmentIds[] = (int) $assignment['id'];

    if ($assignment['status'] === 'submitted') {
        $evaluatedAssignmentIds[] = (int) $assignment['id'];
    }
}

// Load existing submissions for these assignments (score/date/comments)
$submissionsByAssignmentId = [];
if ($assignmentIds !== []) {
    $placeholders = implode(',', array_fill(0, count($assignmentIds), '?'));
    $rows = db()->prepare(
        "SELECT es.*
         FROM evaluation_submissions es
         WHERE es.assignment_id IN ($placeholders)"
    );
    $rows->execute($assignmentIds);
    foreach ($rows->fetchAll() as $row) {
        $submissionsByAssignmentId[(int) $row['assignment_id']] = $row;
    }
}

// Separate faculty by assignment type
$faculty = [];
$programHeads = [];
$peerEvaluators = [];

foreach ($allAssignments as $assignment) {
    $person = $assignment;
    $assignmentId = (int) $assignment['id'];

    $isEvaluated = in_array($assignmentId, $evaluatedAssignmentIds, true);
    $person['assignment_id'] = $assignmentId;
    $person['assignment_type'] = $assignment['assignment_type'];
    $person['assignment_status'] = $assignment['status'];
    $person['is_evaluated'] = $isEvaluated;

    // Fetch evaluation submission stats if exists
    $submission = $submissionsByAssignmentId[$assignmentId] ?? null;
    $person['evaluation_score'] = null;
    $person['date_evaluated'] = null;
    $person['progress_percent'] = null;

    if ($submission) {
        $communication = (int) ($submission['communication_score'] ?? 0);
        $teaching = (int) ($submission['teaching_score'] ?? 0);
        $classroomManagement = (int) ($submission['classroom_management_score'] ?? 0);
        $jobKnowledge = (int) ($submission['job_knowledge_score'] ?? 0);
        $avg = ($communication + $teaching + $classroomManagement + $jobKnowledge) / 4;
        $person['evaluation_score'] = round($avg, 2);
        $person['date_evaluated'] = $submission['submitted_at'] ?? null;
        $person['progress_percent'] = 100;
    } else {
        $person['progress_percent'] = 0;
    }
    $person['role_label'] = match ($assignment['assignment_type']) {
        'program_head' => 'Program Head',
        'peer' => 'Peer Evaluator',
        default => 'Faculty',
    };
    $person['relationship_tag'] = $assignment['assignment_type'] === 'peer' ? 'Peer evaluation under Dean' : '';
    $person['date_evaluated_display'] = $person['date_evaluated']
        ? date('M j, Y', strtotime((string) $person['date_evaluated']))
        : '';

    switch ($assignment['assignment_type']) {
        case 'program_head':
            $programHeads[] = $person;
            break;
        case 'peer':
            $peerEvaluators[] = $person;
            break;
        case 'dean':
        default:
            $faculty[] = $person;
    }
}

// Sort each category alphabetically by full_name
usort($faculty, fn($a, $b) => strcmp($a['full_name'], $b['full_name']));
usort($programHeads, fn($a, $b) => strcmp($a['full_name'], $b['full_name']));
usort($peerEvaluators, fn($a, $b) => strcmp($a['full_name'], $b['full_name']));

$totalEvaluations = count($allAssignments);
$completedEvaluations = count($evaluatedAssignmentIds);
$pendingEvaluations = max(0, $totalEvaluations - $completedEvaluations);
$completionPercent = $totalEvaluations > 0 ? round(($completedEvaluations / $totalEvaluations) * 100) : 0;
$evaluationSections = [
    [
        'title' => 'Faculty Members',
        'label' => 'Faculty',
        'people' => $faculty,
        'empty' => 'No faculty evaluations assigned yet.',
    ],
    [
        'title' => 'Program Heads',
        'label' => 'Leadership',
        'people' => $programHeads,
        'empty' => 'No program head evaluations assigned yet.',
    ],
    [
        'title' => 'Peer Evaluations',
        'label' => 'Peer',
        'people' => $peerEvaluators,
        'empty' => 'No peer evaluations assigned yet.',
    ],
];
$evaluationRecords = [];
foreach ($submissionsByAssignmentId as $assignmentId => $submission) {
    $evaluationRecords[(string) $assignmentId] = [
        'communication_score' => (int) ($submission['communication_score'] ?? 3),
        'teaching_score' => (int) ($submission['teaching_score'] ?? 3),
        'classroom_management_score' => (int) ($submission['classroom_management_score'] ?? 3),
        'job_knowledge_score' => (int) ($submission['job_knowledge_score'] ?? 3),
        'behavioral_evidence' => secure_decrypt_value($submission['behavioral_evidence'] ?? ''),
        'overall_comments' => secure_decrypt_value($submission['overall_comments'] ?? ''),
    ];
}


// Handle AJAX form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_evaluation') {
    header('Content-Type: application/json');

    try {
        $result = dipascaf_submit_evaluation($deanId, 'dean', 'Dean submitted an evaluation.');
        echo json_encode(['success' => true] + $result);
        exit;
    } catch (Throwable $exception) {
        echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Evaluation | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=tailwind-8">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/evaluation-form.css">
</head>
<body class="admin-body dean-body">
    <button class="sidebar-overlay" type="button" aria-label="Close menu"></button>
    <aside class="admin-sidebar dean-sidebar" aria-label="Dean navigation">
        <div class="sidebar-brand">
            <span class="brand-icon">D</span>
            <span class="sidebar-brand-copy">
                <strong><?= e(APP_NAME) ?></strong>
                <small>Dean Portal</small>
            </span>
            <button class="sidebar-collapse" type="button" aria-label="Collapse sidebar"></button>
        </div>

        <nav class="sidebar-menu">
            <a href="<?= BASE_URL ?>/dashboards/dean.php?section=overview">
                <span class="menu-icon" data-icon="dashboard" aria-hidden="true"></span>
                <span class="sidebar-item-label">Overview</span>
            </a>
            <a href="<?= BASE_URL ?>/dashboards/dean.php?section=tasks">
                <span class="menu-icon" data-icon="tasks" aria-hidden="true"></span>
                <span class="sidebar-item-label">Tasks</span>
            </a>
            <a class="active" href="<?= BASE_URL ?>/dashboards/dean_evaluation.php">
                <span class="menu-icon" data-icon="evaluations" aria-hidden="true"></span>
                <span class="sidebar-item-label">Evaluate</span>
            </a>
            <a href="<?= BASE_URL ?>/dashboards/dean.php?section=summary">
                <span class="menu-icon" data-icon="summary" aria-hidden="true"></span>
                <span class="sidebar-item-label">Summary</span>
            </a>
            <a href="<?= BASE_URL ?>/dashboards/dean.php?section=insights">
                <span class="menu-icon" data-icon="insights" aria-hidden="true"></span>
                <span class="sidebar-item-label">Insights</span>
            </a>
            <a href="<?= BASE_URL ?>/dashboards/dean.php?section=training">
                <span class="menu-icon" data-icon="plans" aria-hidden="true"></span>
                <span class="sidebar-item-label">Plans</span>
            </a>
            <a href="<?= BASE_URL ?>/dashboards/dean.php?section=report">
                <span class="menu-icon" data-icon="reports" aria-hidden="true"></span>
                <span class="sidebar-item-label">Reports</span>
            </a>
        </nav>

        <div class="sidebar-bottom">
            <a class="sidebar-logout" href="<?= BASE_URL ?>/logout.php">
                <span class="menu-icon" data-icon="logout" aria-hidden="true"></span>
                <span class="sidebar-item-label">Logout</span>
            </a>
            <label class="dark-mode-switch">
                <span class="menu-icon" data-icon="moon" aria-hidden="true"></span>
                <span class="sidebar-item-label">Dark Mode</span>
                <input class="dark-mode-input" type="checkbox" aria-label="Toggle dark mode">
                <span class="toggle-track" aria-hidden="true"></span>
            </label>
        </div>
    </aside>

    <main class="admin-main">
        <header class="admin-header">
            <button class="menu-toggle" type="button" aria-label="Open menu" aria-expanded="false">
                <span></span>
                <span></span>
                <span></span>
            </button>
            <div class="admin-header-info">
                <h1>Faculty Evaluation</h1>
                <p class="admin-header-note">Dean for <?= e(implode(', ', $departments)) ?></p>
            </div>
            <div class="admin-search dean-header-context">
                <span><?= e(implode(', ', $departments)) ?></span>
            </div>
            <div class="admin-actions" aria-label="Dean metrics and profile">
                <span class="action-dot"><?= e((string) $pendingEvaluations) ?></span>
                <span class="action-dot yellow"><?= e((string) $completedEvaluations) ?></span>
                <div class="admin-avatar"><?= e(strtoupper(substr((string) ($user['full_name'] ?? 'D'), 0, 1))) ?></div>
            </div>
        </header>

        <section class="admin-content admin-module dean-content dean-evaluation-content">
            <?php dipascaf_render_evaluation_dashboard([
                'assignments' => $dipascafAssignments,
                'eyebrow' => 'Dean Evaluation',
                'title' => 'Evaluate Program Heads and Faculty',
                'subtitle' => 'Use focused evaluation menus to review Program Head, Faculty, and Peer appraisal cards assigned under your department.',
                'defaultSection' => 'all',
                'hideRoleStatusFilters' => true,
            ]); ?>
            <div hidden>
            <div class="evaluation-header">
                <div class="evaluation-title">
                    <h1>Evaluate Faculty & Leadership</h1>
                    <p>Rate faculty members, program heads, and peer evaluators in a professional appraisal framework</p>
                </div>
                <div class="evaluation-progress">
                    <div class="progress-stat">
                        <strong><?= e((string) $completedEvaluations) ?></strong>
                        <span>Completed</span>
                    </div>
                    <div class="progress-bar-container">
                        <div class="progress-bar" data-progress="<?= e((string) $completionPercent) ?>"></div>
                    </div>
                    <div class="progress-stat">
                        <strong><?= e((string) $totalEvaluations) ?></strong>
                        <span>Total</span>
                    </div>
                </div>
            </div>

            <div class="evaluation-sections">
                <?php foreach ($evaluationSections as $section): ?>
                    <?php if (count($section['people']) > 0): ?>
                        <div class="evaluation-section">
                            <div class="section-header">
                                <div>
                                    <span class="section-kicker"><?= e($section['label']) ?></span>
                                    <h2><?= e($section['title']) ?></h2>
                                </div>
                                <span class="section-badge"><?= e((string) count($section['people'])) ?> Evaluations</span>
                            </div>
                            <div class="cards-grid">
                                <?php foreach ($section['people'] as $person): ?>
                                    <?php
                                        $isEvaluated = (bool) $person['is_evaluated'];
                                        $statusClass = $isEvaluated ? 'evaluated' : 'pending';
                                        $avatarPath = trim((string) ($person['profile_image'] ?? ''));
                                        $avatarUrl = $avatarPath !== '' ? BASE_URL . '/' . ltrim($avatarPath, '/') : '';
                                        $personName = (string) $person['full_name'];
                                        $jsName = htmlspecialchars(json_encode($personName, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <article class="evaluation-card <?= e($statusClass) ?>">
                                        <div class="card-badge <?= e($statusClass) ?>">
                                            <?= $isEvaluated ? '&#10003; Done' : 'Pending' ?>
                                        </div>

                                        <div class="card-header">
                                            <div class="card-avatar">
                                                <?php if ($avatarUrl !== ''): ?>
                                                    <img src="<?= e($avatarUrl) ?>" alt="<?= e($personName) ?> profile picture">
                                                <?php else: ?>
                                                    <?= e(strtoupper(substr($personName, 0, 1))) ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="card-header-info">
                                                <h3 class="card-name"><?= e($personName) ?></h3>
                                                <p class="card-position"><?= e((string) $person['position_title']) ?></p>
                                            </div>
                                        </div>

                                        <div class="role-chip-row">
                                            <span class="card-label"><?= e((string) $person['role_label']) ?></span>
                                            <?php if ((string) $person['relationship_tag'] !== ''): ?>
                                                <span class="card-label peer-tag"><?= e((string) $person['relationship_tag']) ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="card-content">
                                            <div class="card-row">
                                                <span>Program</span>
                                                <strong><?= e((string) $person['department']) ?></strong>
                                            </div>
                                            <div class="card-row">
                                                <span>Status</span>
                                                <strong><?= e($isEvaluated ? 'Evaluation Complete' : 'Pending Evaluation') ?></strong>
                                            </div>
                                            <?php if ($person['evaluation_score'] !== null): ?>
                                                <div class="card-row">
                                                    <span>Score</span>
                                                    <strong><?= e((string) $person['evaluation_score']) ?>/5</strong>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ((string) $person['date_evaluated_display'] !== ''): ?>
                                                <div class="card-row">
                                                    <span>Date</span>
                                                    <strong><?= e((string) $person['date_evaluated_display']) ?></strong>
                                                </div>
                                            <?php endif; ?>
                                            <div class="card-row">
                                                <span>Progress</span>
                                                <strong><?= e((string) $person['progress_percent']) ?>%</strong>
                                            </div>
                                        </div>

                                        <div class="card-footer <?= $isEvaluated ? 'evaluated-actions' : '' ?>">
                                            <?php if ($isEvaluated): ?>
                                                <button type="button" class="card-action-btn done" disabled>&#10003; Done</button>
                                                <button type="button" class="card-secondary-btn" onclick="openEvaluationModal(<?= e((string) $person['assignment_id']) ?>, <?= $jsName ?>, true)">
                                                    View Evaluation
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="card-action-btn" onclick="openEvaluationModal(<?= e((string) $person['assignment_id']) ?>, <?= $jsName ?>)">
                                                    Tap to Rate
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>

                <!-- Empty State -->
                <?php if (count($faculty) === 0 && count($programHeads) === 0 && count($peerEvaluators) === 0): ?>
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                        <polyline points="9 22 9 12 15 12 15 22"></polyline>
                    </svg>
                    <p>No evaluations assigned yet. Check back later or contact your administrator.</p>
                </div>
                <?php endif; ?>
            </div>
            </div>
        </section>
    </main>

    <!-- Evaluation Modal -->
    <div class="modal-overlay" id="evaluationModal">
        <div class="modal">
            <button type="button" class="modal-close" onclick="closeEvaluationModal()">×</button>
            
            <div class="modal-header">
                <h2 class="modal-title">Faculty Evaluation</h2>
                <p class="modal-subtitle" id="modalSubtitle">Rate this person's performance</p>
            </div>

            <div class="modal-error" id="modalError"></div>

            <form class="evaluation-form" id="evaluationForm">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="submit_evaluation">
                <input type="hidden" name="assignment_id" id="assignmentId">

                <div class="form-group-row">
                    <div class="form-group">
                        <label class="form-label">Communication Skills</label>
                        <div class="form-hint">1 = Poor, 5 = Excellent</div>
                        <div class="rating-group mt-2">
                            <input type="number" name="communication_score" id="communicationScore" min="1" max="5" value="3" class="rating-input" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Classroom Management</label>
                        <div class="form-hint">1 = Poor, 5 = Excellent</div>
                        <div class="rating-group mt-2">
                            <input type="number" name="classroom_management_score" id="classroomManagementScore" min="1" max="5" value="3" class="rating-input" required>
                        </div>
                    </div>
                </div>

                <div class="form-group-row">
                    <div class="form-group">
                        <label class="form-label">Teaching Effectiveness</label>
                        <div class="form-hint">1 = Poor, 5 = Excellent</div>
                        <div class="rating-group mt-2">
                            <input type="number" name="teaching_score" id="teachingScore" min="1" max="5" value="3" class="rating-input" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Job Knowledge</label>
                        <div class="form-hint">1 = Poor, 5 = Excellent</div>
                        <div class="rating-group mt-2">
                            <input type="number" name="job_knowledge_score" id="jobKnowledgeScore" min="1" max="5" value="3" class="rating-input" required>
                        </div>
                    </div>
                </div>

                <div class="form-group full-field">
                    <label class="form-label">Behavioral Evidence</label>
                    <textarea name="behavioral_evidence" id="behavioralEvidence" class="form-textarea" placeholder="Required for ratings of 1 or 5. Document observed behavior, artifacts, or specific examples."></textarea>
                </div>

                <div class="form-group full-field">
                    <label class="form-label">Overall Comments</label>
                    <textarea name="overall_comments" id="overallComments" class="form-textarea" placeholder="Provide interpretation of results, strengths, and suggestions for improvement."></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="modal-btn modal-btn-secondary" onclick="closeEvaluationModal()">
                        Cancel
                    </button>
                    <button type="submit" class="modal-btn modal-btn-primary" id="submitBtn">
                        Submit Evaluation
                    </button>
                </div>

                <div class="modal-loading" id="modalLoading">
                    <div class="spinner"></div>
                    <p>Submitting evaluation...</p>
                </div>
            </form>
        </div>
    </div>

    <script>
        const menuToggle = document.querySelector('.menu-toggle');
        const sidebarOverlay = document.querySelector('.sidebar-overlay');
        const sidebarCollapse = document.querySelector('.sidebar-collapse');
        const darkModeInput = document.querySelector('.dark-mode-input');
        const darkModeLabel = document.querySelector('.dark-mode-switch .sidebar-item-label');
        const evaluationModal = document.getElementById('evaluationModal');
        const evaluationForm = document.getElementById('evaluationForm');
        const modalError = document.getElementById('modalError');
        const modalLoading = document.getElementById('modalLoading');
        const submitBtn = document.getElementById('submitBtn');
        const existingEvaluations = <?= json_encode($evaluationRecords, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const evaluationFields = evaluationForm.querySelectorAll('input[type="number"], textarea');

        document.querySelectorAll('[data-progress]').forEach((bar) => {
            bar.style.width = `${Math.max(0, Math.min(100, Number(bar.dataset.progress || 0)))}%`;
        });

        if (localStorage.getItem('pmas-sidebar-collapsed') === '1') {
            document.body.classList.add('sidebar-collapsed');
        }

        function syncThemeMode(enabled) {
            document.body.classList.toggle('dark-mode', enabled);
            if (darkModeInput) darkModeInput.checked = enabled;
            if (darkModeLabel) darkModeLabel.textContent = enabled ? 'Light Mode' : 'Dark Mode';
        }

        syncThemeMode(localStorage.getItem('pmas-dark-mode') === '1');

        if (sidebarCollapse) {
            sidebarCollapse.addEventListener('click', () => {
                const collapsed = document.body.classList.toggle('sidebar-collapsed');
                localStorage.setItem('pmas-sidebar-collapsed', collapsed ? '1' : '0');
                sidebarCollapse.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
            });
        }

        if (darkModeInput) {
            darkModeInput.addEventListener('change', () => {
                syncThemeMode(darkModeInput.checked);
                localStorage.setItem('pmas-dark-mode', darkModeInput.checked ? '1' : '0');
            });
        }

        function setSidebar(open) {
            document.body.classList.toggle('sidebar-open', open);
            if (menuToggle) {
                menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                menuToggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
            }
        }

        if (menuToggle) {
            menuToggle.addEventListener('click', () => {
                setSidebar(!document.body.classList.contains('sidebar-open'));
            });
        }

        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', () => setSidebar(false));
        }

        document.querySelectorAll('.sidebar-menu a, .sidebar-logout').forEach((link) => {
            link.addEventListener('click', () => setSidebar(false));
        });

        function openEvaluationModal(assignmentId, personName, isViewOnly = false) {
            evaluationForm.reset();
            document.getElementById('assignmentId').value = assignmentId;
            document.getElementById('modalSubtitle').textContent = isViewOnly
                ? `Review ${personName}'s completed evaluation`
                : `Rate ${personName}'s performance`;
            modalError.classList.remove('active');
            modalLoading.classList.remove('active');
            evaluationFields.forEach((field) => {
                field.disabled = false;
                field.readOnly = false;
            });
            
            if (isViewOnly) {
                const record = existingEvaluations[String(assignmentId)];
                if (record) {
                    document.getElementById('communicationScore').value = record.communication_score;
                    document.getElementById('teachingScore').value = record.teaching_score;
                    document.getElementById('classroomManagementScore').value = record.classroom_management_score;
                    document.getElementById('jobKnowledgeScore').value = record.job_knowledge_score;
                    document.getElementById('behavioralEvidence').value = record.behavioral_evidence || '';
                    document.getElementById('overallComments').value = record.overall_comments || '';
                }
                evaluationFields.forEach((field) => {
                    field.disabled = true;
                    field.readOnly = true;
                });
                submitBtn.disabled = true;
                submitBtn.style.display = 'none';
            } else {
                submitBtn.textContent = 'Submit Evaluation';
                submitBtn.disabled = false;
                submitBtn.style.display = '';
            }
            
            evaluationModal.classList.add('active');
        }

        function closeEvaluationModal() {
            evaluationModal.classList.remove('active');
            modalError.classList.remove('active');
            modalLoading.classList.remove('active');
        }

        evaluationForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            // Validate behavioral evidence for extreme ratings
            const scores = [
                parseInt(document.getElementById('communicationScore').value),
                parseInt(document.getElementById('teachingScore').value),
                parseInt(document.getElementById('classroomManagementScore').value),
                parseInt(document.getElementById('jobKnowledgeScore').value)
            ];
            
            const behavioralEvidence = document.getElementById('behavioralEvidence').value.trim();
            const hasExtremeScore = scores.some(s => s === 1 || s === 5);
            
            if (hasExtremeScore && !behavioralEvidence) {
                modalError.textContent = 'Behavioral evidence is required for ratings of 1 or 5.';
                modalError.classList.add('active');
                return;
            }
            
            // Submit form
            modalLoading.classList.add('active');
            submitBtn.disabled = true;
            
            try {
                const formData = new FormData(evaluationForm);
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Update card status
                    const cards = document.querySelectorAll('.evaluation-card');
                    cards.forEach(card => {
                        const btn = card.querySelector('[onclick*="' + data.assignment_id + '"]');
                        if (btn) {
                            card.classList.remove('pending');
                            card.classList.add('evaluated');
                            const badge = card.querySelector('.card-badge');
                            badge.classList.remove('pending');
                            badge.classList.add('evaluated');
                            badge.textContent = '✓ Done';
                        }
                    });
                    
                    // Close modal and show success
                    modalLoading.classList.remove('active');
                    alert(data.message);
                    closeEvaluationModal();
                    
                    // Optional: Refresh page to update progress
                    setTimeout(() => location.reload(), 500);
                } else {
                    throw new Error(data.error || 'An error occurred');
                }
            } catch (error) {
                modalError.textContent = error.message;
                modalError.classList.add('active');
            } finally {
                modalLoading.classList.remove('active');
                submitBtn.disabled = false;
            }
        });

        // Close modal on overlay click
        evaluationModal.addEventListener('click', (e) => {
            if (e.target === evaluationModal) {
                closeEvaluationModal();
            }
        });

        // Close modal on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeEvaluationModal();
            }
        });
    </script>
</body>
</html>


