import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useLocation } from 'react-router-dom';
import {
    AlertTriangle,
    ArrowDown,
    ArrowUp,
    BarChart3,
    Calendar,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    ClipboardList,
    Edit3,
    FileText,
    Globe,
    Loader2,
    Lock,
    Pencil,
    Plus,
    RotateCcw,
    Save,
    Search,
    Settings,
    ShieldCheck,
    Trash2,
    UnlockKeyhole,
    UserCheck,
    X,
    Eye,
    MessageSquare,
    Clock,
    ListChecks,
} from 'lucide-react';
import apiFetch from '../../data/api.js';
import { useEvaluationPeriod } from '../../contexts/EvaluationPeriodContext.jsx';
import ModernDatePicker from '../common/ModernDatePicker.jsx';
import { addToast } from '../common/Toast.jsx';
import { confirmDeleteData, confirmProceed, confirmSaveChanges } from '../common/ConfirmationModal.jsx';
import PeerAssignmentsPanel from './PeerAssignmentsPanel.jsx';
import PeriodParticipantsPanel from './PeriodParticipantsPanel.jsx';
import SelfEvaluationModule from './SelfEvaluationModule.jsx';
import { GoalsRecordTemplateManager } from './GoalsRecordSheet.jsx';
import EvaluationModal from './EvaluationModal.jsx';
import useLiveRefresh from '../../hooks/useLiveRefresh.js';

const tabs = [
    { key: 'assignment', label: 'Evaluation Assignment', icon: ClipboardList },
    { key: 'peer', label: 'Peer Assignments', icon: ShieldCheck },
    { key: 'questionnaires', label: 'Questionnaires', icon: FileText },
    { key: 'monitor', label: 'Category Monitor', icon: BarChart3 },
];

const uid = (prefix = '') => prefix + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);

function formatDateLabel(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

function toDateInputValue(date) {
    return date.toISOString().slice(0, 10);
}

function defaultScheduleForm() {
    const today = new Date();
    const dueDate = new Date(today);
    dueDate.setDate(today.getDate() + 30);
    const currentYear = today.getFullYear();

    return {
        school_year: `${currentYear}-${currentYear + 1}`,
        period_name: `${currentYear} Midyear Appraisal`,
        date_start: toDateInputValue(today),
        due_date: toDateInputValue(dueDate),
    };
}

// --------------- Helper Components ---------------

function ControlPill({ label, variant = 'default' }) {
    const variants = {
        default: 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300',
        success: 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-200',
        warning: 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300',
        danger: 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300',
        info: 'bg-blue-100 text-blue-700 dark:text-blue-300',
    };
    return (
        <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium ${variants[variant] || variants.default}`}>
            {label}
        </span>
    );
}

function MetricCard({ icon: Icon, label, value, variant = 'default' }) {
    const variants = {
        default: 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700',
        primary: 'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800',
        success: 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800',
        warning: 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800',
    };
    return (
        <div className={`assignment-workbench-metric metric-${variant} rounded-xl border p-4 flex items-center gap-3 ${variants[variant]}`}>
            <div className="assignment-workbench-metric-icon p-2.5 rounded-lg bg-white dark:bg-gray-800/80 backdrop-blur shadow-sm dark:shadow-gray-900/30">
                <Icon size={20} className="text-gray-700 dark:text-gray-300" />
            </div>
            <div className="assignment-workbench-metric-copy">
                <p className="text-xs text-gray-500 dark:text-gray-400 font-medium">{label}</p>
                <p className="text-xl font-bold text-gray-900 dark:text-gray-100">{value}</p>
            </div>
        </div>
    );
}

function CategoryNotes({ row }) {
    const notes = [['Evidence', row.behavioralEvidence]];
    const visibleNotes = notes.filter(([, value]) => String(value || '').trim() !== '');

    if (visibleNotes.length === 0) {
        return <ControlPill label={row.explanationComplete ? 'Complete' : 'Incomplete'} variant={row.explanationComplete ? 'success' : 'danger'} />;
    }

    return (
        <div className="min-w-[22rem] max-w-xl space-y-2">
            {visibleNotes.map(([label, value]) => (
                <div key={label}>
                    <div className="text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">{label}</div>
                    <p className="text-xs leading-5 text-gray-700 dark:text-gray-300 whitespace-pre-wrap line-clamp-3">{value}</p>
                </div>
            ))}
        </div>
    );
}

function monitorRoleLabel(row) {
    const role = String(row.evaluateeRole || '').toLowerCase();
    const position = String(row.positionTitle || '').toLowerCase();
    if (role === 'program_head' || position.includes('program head')) return 'Program Head';
    if (role === 'dean' || position.includes('dean')) return 'Dean';
    return 'Faculty';
}

function hasMeaningfulMonitorRow(row) {
    if (!row || typeof row !== 'object') return false;
    const hasCompletedStatus = ['completed', 'submitted'].includes(String(row.status || '').toLowerCase());
    const hasRating = Number(row.averageRating || 0) > 0 || Number(row.totalRating || 0) > 0 || Number(row.weightedScore || 0) > 0;
    const hasExplanation = [row.behavioralEvidence, row.reasonForRating, row.recommendation]
        .some((value) => String(value || '').trim() !== '');
    const hasResultIdentity = String(row.categoryTitle || '').trim() !== '' && String(row.evaluateeName || '').trim() !== '';
    return hasResultIdentity && (hasCompletedStatus || hasRating || hasExplanation || row.explanationComplete === true);
}

function buildDepartmentMonitorGroups(rows) {
    const groups = new Map();

    rows.filter(hasMeaningfulMonitorRow).forEach((row) => {
        const department = String(row.department || '').trim() || 'Unassigned Department';
        if (!groups.has(department)) {
            groups.set(department, {
                name: department,
                total: 0,
                complete: 0,
                pendingReview: 0,
                roles: {
                    'Program Head': [],
                    Faculty: [],
                    Dean: [],
                },
            });
        }

        const group = groups.get(department);
        const role = monitorRoleLabel(row);
        if (!group.roles[role]) group.roles[role] = [];
        group.roles[role].push(row);
        group.total += 1;
        if (row.explanationComplete) group.complete += 1;
        if (row.aiDecision === 'pending_review') group.pendingReview += 1;
    });

    return Array.from(groups.values())
        .filter((group) => group.total > 0)
        .sort((a, b) => a.name.localeCompare(b.name));
}

function formatScore(value) {
    const score = Number(value || 0);
    return Number.isFinite(score) && score > 0 ? score.toFixed(2) : '--';
}

function selfEvalStatusVariant(status) {
    const normalized = String(status || '').toLowerCase();
    if (normalized === 'submitted') return 'success';
    if (normalized === 'reopened') return 'warning';
    if (normalized === 'draft') return 'info';
    return 'default';
}

function buildSelfEvaluationGroups(rows) {
    const groups = new Map();
    rows.forEach((row) => {
        const department = String(row.faculty_department || row.department || '').trim() || 'Unassigned Department';
        if (!groups.has(department)) {
            groups.set(department, { name: department, total: 0, submitted: 0, reopened: 0, rows: [] });
        }
        const group = groups.get(department);
        const status = String(row.status || '').toLowerCase();
        group.total += 1;
        if (status === 'submitted') group.submitted += 1;
        if (status === 'reopened') group.reopened += 1;
        group.rows.push(row);
    });
    return Array.from(groups.values()).sort((a, b) => a.name.localeCompare(b.name));
}

function SelfEvaluationMonitorRowsTable({ rows, onView, viewingId }) {
    return (
        <div className="peer-monitor-list">
            {rows.map((row) => {
                const score = Number(row.overall_rating || 0);
                const scoreWidth = Math.max(0, Math.min(100, score));
                const status = String(row.status || 'draft').toLowerCase();
                return (
                    <article className="peer-monitor-row self-eval-monitor-row" key={`self-${row.id || row.assignment_id}`}>
                        <div className="peer-monitor-person">
                            <span>Faculty</span>
                            <strong>{row.full_name || 'Unnamed faculty'}</strong>
                            <small>{row.program_code || row.faculty_department || row.department || 'No program set'}</small>
                        </div>

                        <div className="peer-monitor-category">
                            <span>Self Evaluation</span>
                            <strong>{row.evaluation_period || 'Current period'}</strong>
                            <small>{row.form_type || row.role || 'Faculty self evaluation'}</small>
                        </div>

                        <div className="peer-monitor-score">
                            <div>
                                <strong>{formatScore(row.overall_rating)}</strong>
                                <span>/100</span>
                            </div>
                            <div className="peer-monitor-scorebar" aria-hidden="true">
                                <span style={{ width: `${scoreWidth}%` }} />
                            </div>
                            <small>Outputs {formatScore(row.performance_outputs_score)} | Factors {formatScore(row.performance_factors_score)}</small>
                        </div>

                        <div className="peer-monitor-explanation self-eval-monitor-details">
                            <ControlPill label={row.performance_level || 'Pending rating'} variant={score > 0 ? 'success' : 'default'} />
                            <p>Submitted: {formatDateLabel(row.submitted_at) || 'Not submitted'}</p>
                            {row.reopened_at && <p>Reopened: {formatDateLabel(row.reopened_at)}</p>}
                        </div>

                        <div className="peer-monitor-ai self-eval-monitor-actions">
                            <span>Status</span>
                            <ControlPill label={status.replaceAll('_', ' ')} variant={selfEvalStatusVariant(status)} />
                            <button
                                type="button"
                                className="self-eval-edit-button"
                                onClick={() => onView(row)}
                                disabled={viewingId === row.id}
                                title="View submitted self-evaluation"
                                aria-label={`View ${row.full_name || 'faculty'} submitted self-evaluation`}
                            >
                                <Eye size={15} />
                                <span>View</span>
                            </button>
                        </div>
                    </article>
                );
            })}
        </div>
    );
}

function scoreTone(score) {
    const value = Number(score || 0);
    if (value >= 4) return 'strong';
    if (value >= 3) return 'mid';
    if (value > 0) return 'low';
    return 'empty';
}

function EvaluatorRoleBadge({ label }) {
    const normalized = String(label || '').toLowerCase().replaceAll(' ', '-');
    return <span className={`evaluator-role-badge role-${normalized}`}>{label || 'Evaluator'}</span>;
}

function EvaluatorStatusBadge({ status }) {
    const normalized = String(status || 'pending').toLowerCase();
    return <span className={`evaluator-status-badge status-${normalized}`}>{normalized.replaceAll('_', ' ')}</span>;
}

function EvaluatorBreakdownTable({ rows, sort, onSort, onDetail }) {
    const columns = [
        ['evaluatorName', 'Evaluator Name'],
        ['roleLabel', 'Role'],
        ['submissionStatus', 'Submission Status'],
        ['submittedAt', 'Submitted Date'],
        ['overallRating', 'Overall Rating'],
        ['performanceLevel', 'Performance Level'],
        ['completionPercentage', 'Completion %'],
        ['categoryCount', 'Categories'],
        ['highestRatedCategory', 'Highest Category'],
        ['lowestRatedCategory', 'Lowest Category'],
        ['evidenceIncluded', 'Evidence'],
    ];
    const sortMark = (key) => sort.key === key ? (sort.direction === 'asc' ? ' ASC' : ' DESC') : '';
    return (
        <div className="evaluator-monitor-table-wrap">
            <table className="evaluator-monitor-table">
                <thead>
                    <tr>
                        {columns.map(([key, label]) => (
                            <th key={key}>
                                <button type="button" onClick={() => onSort(key)}>{label}{sortMark(key)}</button>
                            </th>
                        ))}
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.assignmentId} onClick={() => onDetail(row.assignmentId)}>
                            <td>
                                <strong>{row.evaluatorName}</strong>
                                {row.isOutlier && <span className="evaluator-outlier" title={`Score is ${row.zScore} standard deviations from the mean.`}>!</span>}
                                <small>{row.evaluatorEmail || 'No email'}</small>
                            </td>
                            <td><EvaluatorRoleBadge label={row.roleLabel} /></td>
                            <td><EvaluatorStatusBadge status={row.submissionStatus} /></td>
                            <td>{formatDateLabel(row.submittedAt) || 'Not submitted'}</td>
                            <td>
                                <span className={`evaluator-score-cell score-${scoreTone(row.overallRating)}`}>
                                    {Number(row.overallRating || 0) > 0 ? Number(row.overallRating).toFixed(2) : '--'}/5.00
                                </span>
                            </td>
                            <td>{row.performanceLevel || 'Pending'}</td>
                            <td>
                                <div className="evaluator-progress">
                                    <span style={{ width: `${Math.max(0, Math.min(100, Number(row.completionPercentage || 0)))}%` }} />
                                </div>
                                <small>{Number(row.completionPercentage || 0).toFixed(0)}% - {row.questionsAnswered}/{row.totalQuestions || row.questionsAnswered || 0}</small>
                            </td>
                            <td>{row.categoryCount || 0}</td>
                            <td>{row.highestRatedCategory || '--'}</td>
                            <td>{row.lowestRatedCategory || '--'}</td>
                            <td>{row.evidenceIncluded ? 'Yes' : '--'}</td>
                            <td>
                                <button type="button" className="evaluator-action-button" onClick={(event) => { event.stopPropagation(); onDetail(row.assignmentId); }}>
                                    <Eye size={14} />
                                    <span>Detail</span>
                                </button>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function EvaluatorDetailModal({ detail, statistics, onClose }) {
    const [openCategoryResults, setOpenCategoryResults] = useState({});
    if (!detail) return null;
    const average = Number(statistics?.averageScore || 0);
    const score = Number(detail.overallRating || 0);
    const delta = score && average ? score - average : 0;
    const categories = detail.categories || [];

    function categoryKey(category) {
        return `${category.formType || 'form'}-${category.categoryId || category.categoryTitle}`;
    }

    function formLabel(formType) {
        const formName = String(formType || '')
            .replace(/^form[\s_-]*/i, '')
            .replace(/[\s_-]+/g, ' ')
            .trim()
            .toUpperCase();
        return formName ? `Form ${formName}` : 'Category';
    }

    function toggleCategoryResult(category) {
        const key = categoryKey(category);
        setOpenCategoryResults((current) => ({
            ...current,
            [key]: !current[key],
        }));
    }

    return (
        <div className="evaluator-modal-backdrop" role="presentation" onClick={onClose}>
            <section className="evaluator-detail-modal" role="dialog" aria-modal="true" aria-label="Evaluator detail" onClick={(event) => event.stopPropagation()}>
                <div className="evaluator-modal-head">
                    <div>
                        <p className="peer-monitor-eyebrow">Evaluator Detail</p>
                        <h3>{detail.evaluatorName}</h3>
                        <span>{detail.roleLabel} - {detail.evaluatorEmail || 'No email'}</span>
                    </div>
                    <button type="button" onClick={onClose} aria-label="Close evaluator detail"><X size={18} /></button>
                </div>
                <div className="evaluator-detail-grid">
                    <aside className="evaluator-detail-sidebar">
                        <EvaluatorStatusBadge status={detail.submissionStatus} />
                        <div><span>Overall Rating</span><strong>{score ? score.toFixed(2) : '--'}/5.00</strong></div>
                        <div><span>Performance Level</span><strong>{detail.performanceLevel || 'Pending'}</strong></div>
                        <div><span>Compared to Average</span><strong>{delta ? `${delta > 0 ? '+' : ''}${delta.toFixed(2)}` : '--'}</strong></div>
                        <div><span>Submitted</span><strong>{formatDateLabel(detail.submittedAt) || 'Not submitted'}</strong></div>
                    </aside>
                    <div className="evaluator-detail-categories">
                        {categories.length === 0 && (
                            <div className="evaluator-category-empty">No category results are available for this evaluator yet.</div>
                        )}
                        {categories.map((category) => {
                            const key = categoryKey(category);
                            const isOpen = Boolean(openCategoryResults[key]);
                            const questions = category.questions || [];
                            const averageRating = Number(category.averageRating || 0);
                            return (
                            <article className="evaluator-category-card" key={key}>
                                <div className="evaluator-category-summary">
                                    <div>
                                        <span>{formLabel(category.formType)}</span>
                                        <h4>{category.categoryTitle}</h4>
                                    </div>
                                    <strong>{averageRating ? averageRating.toFixed(2) : '--'}/5</strong>
                                    <button type="button" onClick={() => toggleCategoryResult(category)}>
                                        {isOpen ? 'Hide Numbers' : 'Result'}
                                    </button>
                                </div>
                                <div className="evaluator-category-notes">
                                    <p>{category.behavioralEvidence || category.reasonForRating || category.recommendation || 'No evidence text provided.'}</p>
                                    <small>{questions.length} question number{questions.length === 1 ? '' : 's'} available</small>
                                </div>
                                {isOpen && (
                                    <table>
                                        <thead><tr><th>No.</th><th>Question</th><th>Rating</th><th>Evidence Note</th></tr></thead>
                                        <tbody>
                                            {questions.length === 0 && (
                                                <tr><td colSpan={4}>No question numbers recorded for this category.</td></tr>
                                            )}
                                            {questions.map((question, questionIndex) => (
                                                <tr key={question.id || `${key}-${questionIndex}`}>
                                                    <td>{questionIndex + 1}</td>
                                                    <td>{question.text}</td>
                                                    <td>{question.rating ?? '--'}</td>
                                                    <td>{question.evidence || '--'}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                )}
                            </article>
                            );
                        })}
                    </div>
                </div>
            </section>
        </div>
    );
}

function MonitorRowsTable({ rows }) {
    return (
        <div className="peer-monitor-list">
            {rows.map((row, index) => {
                const score = Number(row.averageRating || 0);
                const scoreWidth = Math.max(0, Math.min(100, (score / 5) * 100));
                return (
                    <article className="peer-monitor-row" key={`${row.form}-${row.assignmentId}-${row.categoryTitle}-${index}`}>
                        <div className="peer-monitor-person">
                            <span>{row.form}</span>
                            <strong>{row.evaluateeName}</strong>
                            <small>{row.program || row.positionTitle || row.period}</small>
                        </div>

                        <div className="peer-monitor-category">
                            <span>Category</span>
                            <strong>{row.categoryTitle}</strong>
                        </div>

                        <div className="peer-monitor-score">
                            <div>
                                <strong>{score.toFixed(2)}</strong>
                                <span>/5</span>
                            </div>
                            <div className="peer-monitor-scorebar" aria-hidden="true">
                                <span style={{ width: `${scoreWidth}%` }} />
                            </div>
                            <small>{Number(row.factorWeight || 0).toFixed(0)}% weight | {Number(row.weightedScore || 0).toFixed(4)} weighted</small>
                        </div>

                        <div className="peer-monitor-explanation">
                            <ControlPill
                                label={(row.requiredExplanation || '').replaceAll('_', ' ') || 'Reason for rating'}
                                variant="info"
                            />
                            <CategoryNotes row={row} />
                        </div>

                        <div className="peer-monitor-ai">
                            <span>AI Review</span>
                            <ControlPill
                                label={(row.aiDecision || 'none').replaceAll('_', ' ')}
                                variant={row.aiDecision === 'pending_review' ? 'warning' : row.aiDecision === 'accepted' || row.aiDecision === 'edited' ? 'success' : 'default'}
                            />
                        </div>
                    </article>
                );
            })}
        </div>
    );
}

function EvaluationPeriodControl({ onChanged, refreshKey = 0, preferredPeriodId = '', onPreferredPeriodApplied }) {
    const currentYear = new Date().getFullYear();
    const {
        selectedPeriodId: globalSelectedPeriodId,
        setSelectedPeriodId: setGlobalSelectedPeriodId,
        refresh: refreshGlobalPeriods,
    } = useEvaluationPeriod();
    const [period, setPeriod] = useState(null);
    const [periodOptions, setPeriodOptions] = useState([]);
    const [selectedPeriodId, setSelectedPeriodId] = useState('');
    const [form, setForm] = useState({
        period_name: `${currentYear} Midyear Appraisal`,
        school_year: `${currentYear}-${currentYear + 1}`,
        date_start: new Date().toISOString().slice(0, 10),
        date_end: '',
    });
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState('');
    const loadPeriod = async () => {
        setLoading(true);
        try {
            const payload = await apiFetch('/api/evaluation-period.php?action=periods');
            const list = Array.isArray(payload.data) ? payload.data : [];
            const requestedPeriodId = preferredPeriodId || globalSelectedPeriodId;
            const syncedPeriod = requestedPeriodId
                ? list.find((item) => String(item.id) === String(requestedPeriodId))
                : null;
            const nextPeriod = syncedPeriod || payload.current || list[0] || null;
            setPeriodOptions(list);
            setPeriod(nextPeriod);
            if (nextPeriod?.id) {
                setSelectedPeriodId(String(nextPeriod.id));
                if (preferredPeriodId && String(globalSelectedPeriodId) !== String(nextPeriod.id)) {
                    setGlobalSelectedPeriodId(String(nextPeriod.id));
                }
                if (preferredPeriodId) {
                    onPreferredPeriodApplied?.();
                }
                setForm((current) => ({
                    period_name: nextPeriod.period_name || current.period_name,
                    school_year: nextPeriod.school_year || current.school_year,
                    date_start: nextPeriod.date_start || current.date_start,
                    date_end: nextPeriod.date_end || current.date_end,
                }));
            }
        } catch (error) {
            addToast({ type: 'error', text: error.message || 'Unable to load evaluation period.' });
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadPeriod();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [globalSelectedPeriodId, preferredPeriodId, refreshKey]);

    const updateField = (field, value) => {
        setForm((current) => ({ ...current, [field]: value }));
    };

    const selectPeriod = (periodId) => {
        setSelectedPeriodId(periodId);
        setGlobalSelectedPeriodId(periodId);
        const selected = periodOptions.find((item) => String(item.id) === String(periodId));
        if (!selected) return;

        setPeriod(selected);
        setForm((current) => ({
            period_name: selected.period_name || current.period_name,
            school_year: selected.school_year || current.school_year,
            date_start: selected.date_start || current.date_start,
            date_end: selected.date_end || current.date_end,
        }));

    };

    const submitAction = async (action) => {
        const confirmed = await confirmProceed({
            message: action === 'open'
                ? 'Assigned evaluators will be able to answer and submit forms.'
                : 'All evaluators will be blocked from answering, editing, or submitting forms.',
            confirmText: action === 'open' ? 'Open Evaluation' : 'Lock Evaluation',
        });
        if (!confirmed) return;

        setSaving(action);
        try {
            const payload = await apiFetch('/api/evaluation-period.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, period_id: selectedPeriodId ? Number(selectedPeriodId) : 0, ...form }),
            });
            setPeriod(payload.data || null);
            if (payload.data?.id) {
                setSelectedPeriodId(String(payload.data.id));
                setGlobalSelectedPeriodId(String(payload.data.id));
            }
            await refreshGlobalPeriods?.({
                selectCurrent: action === 'open',
                selectPeriodId: payload.data?.id ? String(payload.data.id) : selectedPeriodId,
            });
            await loadPeriod();
            addToast({ type: 'success', text: payload.message || 'Evaluation period updated.' });
            onChanged?.(payload.data || null);
        } catch (error) {
            addToast({ type: 'error', text: error.message || 'Unable to update evaluation period.' });
        } finally {
            setSaving('');
        }
    };

    const isOpen = !!period?.is_open;
    const periodStatus = String(period?.status || (isOpen ? 'open' : 'draft')).toLowerCase();
    const statusLabel = loading
        ? 'Loading'
        : periodStatus === 'open'
            ? 'Open'
            : periodStatus === 'locked'
                ? 'Locked'
                : periodStatus === 'closed'
                    ? 'Closed'
                    : 'Draft';

    return (
        <section className="evaluation-period-card overflow-hidden rounded-xl border border-emerald-200 bg-white shadow-sm dark:border-emerald-900/60 dark:bg-gray-800">
            <div className="grid gap-4 border-b border-gray-100 bg-emerald-50/70 px-6 py-5 dark:border-gray-700 dark:bg-emerald-950/20 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
                <div className="flex items-start gap-3">
                    <div className={`evaluation-period-state-icon grid h-11 w-11 shrink-0 place-items-center rounded-xl ${isOpen ? 'is-open' : 'is-locked'}`}>
                        {isOpen ? <UnlockKeyhole size={20} /> : <Lock size={20} />}
                    </div>
                    <div className="min-w-0">
                        <div className="mb-2 flex flex-wrap items-center gap-2">
                            <h2 className="text-lg font-bold text-gray-900 dark:text-gray-100">Evaluation Period Control</h2>
                            <ControlPill label={statusLabel} variant={isOpen ? 'success' : 'danger'} />
                        </div>
                        <p className="text-sm text-gray-600 dark:text-gray-300">
                            Admin opens or locks access for Dean, Program Head, Faculty, and peer evaluator forms.
                        </p>
                    </div>
                </div>
                <div className="rounded-xl border border-emerald-100 bg-white px-4 py-3 text-sm dark:border-emerald-900/60 dark:bg-gray-900">
                    <div className="flex items-center gap-2 font-semibold text-gray-900 dark:text-gray-100">
                        <ShieldCheck size={16} className={isOpen ? 'text-blue-500 dark:text-blue-300' : 'text-slate-400'} />
                        {period?.period_name || 'No evaluation period selected'}
                    </div>
                    <p className="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400">
                        Academic Year {period?.school_year || form.school_year} • {period?.date_start || form.date_start || 'Start date'} to {period?.date_end || form.date_end || 'Due date'}
                    </p>
                </div>
            </div>

            <div className="evaluation-period-body grid gap-5 p-6">
                <label className="grid gap-1.5 text-sm font-semibold text-gray-700 dark:text-gray-300">
                    Select Evaluation Period to Open
                    <select
                        className="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:focus:ring-emerald-900/40"
                        value={selectedPeriodId}
                        onChange={(event) => selectPeriod(event.target.value)}
                        disabled={loading || periodOptions.length === 0}
                    >
                        {periodOptions.length === 0 ? (
                            <option value="">No evaluation periods available</option>
                        ) : (
                            periodOptions.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.period_name} {item.school_year ? `(AY ${item.school_year})` : ''} [{item.is_open ? 'Open' : 'Locked'}]
                                </option>
                            ))
                        )}
                    </select>
                </label>

                <div className="evaluation-period-fields grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <label className="grid gap-1.5 text-sm font-semibold text-gray-700 dark:text-gray-300">
                        School Year
                        <input className="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:focus:ring-emerald-900/40" value={form.school_year} onChange={(event) => updateField('school_year', event.target.value)} />
                    </label>
                    <label className="grid gap-1.5 text-sm font-semibold text-gray-700 dark:text-gray-300">
                        Period Name
                        <input
                            className="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:focus:ring-emerald-900/40"
                            value={form.period_name}
                            onChange={(event) => updateField('period_name', event.target.value)}
                            autoComplete="new-password"
                            name="appraisia-period-name"
                            data-lpignore="true"
                            data-form-type="other"
                        />
                    </label>
                    <label className="grid gap-1.5 text-sm font-semibold text-gray-700 dark:text-gray-300">
                        Start Date
                        <ModernDatePicker value={form.date_start} onChange={(value) => updateField('date_start', value)} label="Start date" minYear={2000} maxYear={currentYear + 15} disableFuture={false} required className="schedule-modern-date" />
                    </label>
                    <label className="grid gap-1.5 text-sm font-semibold text-gray-700 dark:text-gray-300">
                        Due Date
                        <ModernDatePicker value={form.date_end} onChange={(value) => updateField('date_end', value)} label="Due date" minYear={2000} maxYear={currentYear + 15} minDate={form.date_start} disableFuture={false} required className="schedule-modern-date" />
                    </label>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="evaluation-period-note max-w-2xl text-sm text-gray-500 dark:text-gray-400">
                        {period?.message || 'Open a period to allow assigned evaluators to access their forms. Lock it to protect final results.'}
                    </p>
                    <div className="evaluation-period-action-buttons flex flex-wrap gap-2">
                        <button type="button" onClick={() => submitAction('open')} disabled={!!saving} className="period-action-primary inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60">
                            {saving === 'open' ? <Loader2 size={16} className="animate-spin" /> : <UnlockKeyhole size={16} />}
                            {saving === 'open' ? 'Opening...' : 'Open Evaluation'}
                        </button>
                        <button type="button" onClick={() => submitAction('lock')} disabled={!!saving || !isOpen} className="period-action-secondary inline-flex items-center gap-2 rounded-lg bg-slate-700 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50">
                            {saving === 'lock' ? <Loader2 size={16} className="animate-spin" /> : <Lock size={16} />}
                            {saving === 'lock' ? 'Locking...' : 'Lock Evaluation'}
                        </button>
                    </div>
                </div>

                
            </div>
        </section>
    );
}

// --------------- Question Editor Component ---------------

function CategoryEditor({ category, index, onChange, onRemove, onMoveUp, onMoveDown, canMoveUp, canMoveDown, readonly }) {
    const handleUpdate = (field, value) => {
        onChange(index, { ...category, [field]: value });
    };

    const handleQuestionUpdate = (qIndex, field, value) => {
        const updated = category.questions.map((q, i) =>
            i === qIndex ? { ...q, [field]: value } : q
        );
        handleUpdate('questions', updated);
    };

    const addQuestion = () => {
        const newQ = {
            id: uid('q_'),
            text: '',
            type: 'rating',
            weight: 1,
        };
        handleUpdate('questions', [...category.questions, newQ]);
    };

    const removeQuestion = async (qIndex) => {
        const confirmed = await confirmDeleteData({
            message: 'This question will be removed from the questionnaire draft. This action cannot be undone.',
        });
        if (!confirmed) return;
        const updated = category.questions.filter((_, i) => i !== qIndex);
        handleUpdate('questions', updated);
    };

    return (
        <div className={`questionnaire-category-card ${category.isOpen ? 'is-open' : ''}`}>
            {/* Category Header */}
            <div className="questionnaire-category-card-header">
                <div className="questionnaire-category-card-main">
                    <span className="questionnaire-category-number">{String(index + 1).padStart(2, '0')}</span>
                    <button
                        type="button"
                        onClick={() => handleUpdate('isOpen', !category.isOpen)}
                        className="questionnaire-category-toggle"
                        aria-label={`${category.isOpen ? 'Collapse' : 'Expand'} ${category.title || 'category'}`}
                    >
                        {category.isOpen ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
                    </button>
                    <input
                        className="questionnaire-category-title-input"
                        value={category.title}
                        onChange={(e) => handleUpdate('title', e.target.value)}
                        placeholder="Category title"
                    />
                    <label className="questionnaire-category-weight"><span>Weight</span><input type="number" value={category.weight} onChange={(e) => handleUpdate('weight', parseFloat(e.target.value) || 0)} min="0" max="100" step="0.5" /><b>%</b></label>
                </div>
                {!readonly && (
                    <div className="questionnaire-category-actions">
                        <button
                            type="button"
                            onClick={onMoveUp}
                            disabled={!canMoveUp}
                            aria-label={`Move ${category.title || 'category'} up`}
                            title="Move category up"
                            className="questionnaire-category-action"
                        >
                            <ArrowUp size={14} />
                        </button>
                        <button
                            type="button"
                            onClick={onMoveDown}
                            disabled={!canMoveDown}
                            aria-label={`Move ${category.title || 'category'} down`}
                            title="Move category down"
                            className="questionnaire-category-action"
                        >
                            <ArrowDown size={14} />
                        </button>
                        <button
                            type="button"
                            onClick={onRemove}
                            aria-label={`Delete ${category.title || 'category'}`}
                            title="Delete category"
                            className="questionnaire-category-action is-delete"
                        >
                            <Trash2 size={14} />
                        </button>
                    </div>
                )}
            </div>

            {/* Category Body */}
            {category.isOpen && (
                <div className="p-4 space-y-3">
                    <textarea
                        className="w-full text-xs text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800/50 rounded-lg p-3 border-0 resize-none focus:ring-1 focus:ring-blue-300 dark:focus:ring-blue-600"
                        rows={2}
                        value={category.description || ''}
                        onChange={(e) => handleUpdate('description', e.target.value)}
                        placeholder="Category description (optional)"
                    />

                    {category.questions.map((q, qi) => (
                        <div key={q.id} className="flex items-start gap-3 bg-gray-50 dark:bg-gray-800/50 rounded-lg p-3">
                            <div className="flex-1">
                                <input
                                    className="w-full text-sm bg-transparent border-0 outline-none focus:ring-0 text-gray-800 dark:text-gray-200"
                                    value={q.text}
                                    onChange={(e) => handleQuestionUpdate(qi, 'text', e.target.value)}
                                    placeholder="Enter question text"
                                />
                            </div>
                            <select
                                className="text-xs border border-gray-200 dark:border-gray-700 rounded-lg px-2 py-1.5 text-gray-600 dark:text-gray-400"
                                value={q.type}
                                onChange={(e) => handleQuestionUpdate(qi, 'type', e.target.value)}
                            >
                                <option value="rating">Rating</option>
                                <option value="comment">Comment</option>
                            </select>
                            {!readonly && (
                                <button type="button" onClick={() => removeQuestion(qi)} className="p-1 text-gray-300 dark:text-gray-600 hover:text-red-500 dark:hover:text-red-400 transition-colors">
                                    <X size={14} />
                                </button>
                            )}
                        </div>
                    ))}

                    {!readonly && (
                        <button type="button" onClick={addQuestion} className="question-add-button flex items-center gap-1.5 text-xs font-medium transition-colors">
                            <Plus size={14} /> Add Question
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}

// --------------- Evaluation Engine ---------------

function evaluateQuestionnaire(questionnaire, responses) {
    if (!questionnaire || !responses) return null;
    const categories = questionnaire.categories || [];
    let totalWeightedScore = 0;
    let totalWeight = 0;
    const details = [];

    for (const cat of categories) {
        const catResponses = cat.questions.map((q) => responses[q.id] || 0);
        const catAvg = catResponses.length > 0 ? catResponses.reduce((a, b) => a + b, 0) / catResponses.length : 0;
        const weighted = catAvg * (cat.weight / 100);
        totalWeightedScore += weighted;
        totalWeight += cat.weight;
        details.push({
            title: cat.title,
            weight: cat.weight,
            average: catAvg,
            weighted,
            responseCount: catResponses.filter((r) => r > 0).length,
            total: cat.questions.length,
        });
    }

    const finalScore = totalWeight > 0 ? (totalWeightedScore / (totalWeight / 100)) : 0;
    const percentage = finalScore / 5 * 100;

    let interpretation = 'Unsatisfactory';
    if (percentage >= 90) interpretation = 'Outstanding';
    else if (percentage >= 85) interpretation = 'Very Satisfactory';
    else if (percentage >= 80) interpretation = 'Satisfactory';
    else if (percentage >= 75) interpretation = 'Fair';

    return { finalScore, percentage, interpretation, details };
}

function getScoreInterpretation(avgScore) {
    const pct = (avgScore / 5) * 100;
    if (pct >= 90) return { label: 'Outstanding', color: 'text-emerald-600 dark:text-emerald-400' };
    if (pct >= 85) return { label: 'Very Satisfactory', color: 'text-blue-600 dark:text-blue-400' };
    if (pct >= 80) return { label: 'Satisfactory', color: 'text-amber-600 dark:text-amber-400' };
    if (pct >= 75) return { label: 'Fair', color: 'text-orange-600 dark:text-orange-400' };
    return { label: 'Unsatisfactory', color: 'text-red-600 dark:text-red-400' };
}

// --------------- Helper functions for questionnaire structure ---------------

function createQuestion(text = '') {
    return { id: uid('q_'), text, type: 'rating', weight: 1 };
}

function createCategory(title = '', weight = 0, description = '', questions = []) {
    return {
        id: uid('c_'),
        title,
        weight,
        description,
        questions: questions.length ? questions : [createQuestion('')],
        isOpen: false,
    };
}

function createQuestionnaire(type) {
    const isAdmin = type === 'admin';
    return {
        id: uid('qnr_'),
        type,
        title: isAdmin ? 'PMAS Form A — Administrative Evaluation' : 'PMAS Form B — Faculty Evaluation',
        description: isAdmin
            ? 'Evaluates administrative leadership, job knowledge, interpersonal skills, and initiative.'
            : 'Evaluates classroom management, job knowledge, and communication skills.',
        categories: [],
        info: {
            allowMultiplePerEvaluatee: false,
            allowSelfEvaluation: false,
            allowedEvaluatorRoles: isAdmin ? ['vpaa', 'dean', 'program_head'] : ['dean', 'program_head', 'teacher'],
            allowedEvaluateeRoles: isAdmin ? ['dean', 'program_head'] : ['teacher'],
        },
    };
}

const questionnaireTypeOptions = [
    { key: 'admin', source: 'admin', label: 'PMAS Form A (Admin)', purpose: 'Administrative appraisal questionnaire for leadership evaluation.' },
    { key: 'faculty', source: 'faculty', label: 'PMAS Form B (Faculty)', purpose: 'Faculty appraisal questionnaire for regular evaluation.' },
    { key: 'self_admin', source: 'self_admin', label: 'Leadership Self Evaluation', purpose: 'Self-evaluation questionnaire for Dean, VPAA, and Program Head roles.' },
    { key: 'self_faculty', source: 'self_faculty', label: 'Faculty Self Evaluation', purpose: 'Self-evaluation questionnaire for Faculty Members.' },
];

function getQuestionnaireSource(type) {
    return questionnaireTypeOptions.find((option) => option.key === type)?.source || type;
}

const leadershipSelfEvaluationOptions = [
    { value: 'dean', label: 'Dean Self Evaluation' },
    { value: 'vpaa', label: 'VPAA Self Evaluation' },
    { value: 'program_head', label: 'Program Head Self Evaluation' },
];

const facultySelfEvaluationOptions = [
    { value: 'faculty', label: 'Faculty Self Evaluation' },
];

// --------------- Main Component ---------------

export default function EvaluationAssignmentWorkbench({ initialTab }) {
    const scheduleMaxYear = new Date().getFullYear() + 15;
    const location = useLocation();
    const assignmentParams = useMemo(() => new URLSearchParams(location.search), [location.search]);
    const normalizedInitialTab = initialTab === 'participants'
        ? 'peer'
        : initialTab === 'preview' || questionnaireTypeOptions.some((option) => option.key === initialTab)
            ? 'questionnaires'
            : (tabs.some((tab) => tab.key === initialTab) ? initialTab : 'assignment');

    // Tab state
    const [activeTab, setActiveTab] = useState(normalizedInitialTab);
    const [peerPanel, setPeerPanel] = useState(initialTab === 'participants' ? 'participants' : 'assignments');
    // Schedule state
    const [schedules, setSchedules] = useState([]);
    const [periods, setPeriods] = useState([]);
    const [loadingSchedules, setLoadingSchedules] = useState(true);
    const [newSchedule, setNewSchedule] = useState(defaultScheduleForm);
    const [creating, setCreating] = useState(false);
    const [editingScheduleId, setEditingScheduleId] = useState(null);
    const [editingScheduleOriginal, setEditingScheduleOriginal] = useState(null);
    const scheduleFormRef = useRef(null);
    const schoolYearInputRef = useRef(null);
    const [deleteLoading, setDeleteLoading] = useState(null);
    const [scheduleFilter, setScheduleFilter] = useState('');
    const [periodControlRefreshKey, setPeriodControlRefreshKey] = useState(0);
    const [periodControlSelectionId, setPeriodControlSelectionId] = useState('');

    // Questionnaire state
    const [questionnaires, setQuestionnaires] = useState({
        admin: createQuestionnaire('admin'),
        faculty: createQuestionnaire('faculty'),
    });
    const [formBStatus, setFormBStatus] = useState('loading');
    const [formAStatus, setFormAStatus] = useState('loading');
    const [activeEditTab, setActiveEditTab] = useState(
        questionnaireTypeOptions.some((option) => option.key === initialTab) ? initialTab : 'admin'
    );
    const [questionnairePanel, setQuestionnairePanel] = useState(initialTab === 'preview' ? 'preview' : 'editor');

    // Preview state
    const [previewQuestionnaireType, setPreviewQuestionnaireType] = useState('admin');
    const [previewResponses, setPreviewResponses] = useState({});
    const [monitorRows, setMonitorRows] = useState([]);
    const [monitorLoading, setMonitorLoading] = useState(false);
    const [monitorPeriodId, setMonitorPeriodId] = useState('');
    const [monitorSearch, setMonitorSearch] = useState('');
    const [monitorUserFilter, setMonitorUserFilter] = useState('');
    const [monitorCategoryFilter, setMonitorCategoryFilter] = useState('');
    const [evaluatorMonitorFaculty, setEvaluatorMonitorFaculty] = useState('');
    const [evaluatorMonitorFacultySearch, setEvaluatorMonitorFacultySearch] = useState('');
    const [evaluatorMonitorDepartment, setEvaluatorMonitorDepartment] = useState('');
    const [evaluatorMonitorPeriod, setEvaluatorMonitorPeriod] = useState('');
    const [evaluatorMonitorOptions, setEvaluatorMonitorOptions] = useState({ faculty: [], periods: [] });
    const [evaluatorMonitorRows, setEvaluatorMonitorRows] = useState([]);
    const [evaluatorMonitorComparison, setEvaluatorMonitorComparison] = useState([]);
    const [evaluatorMonitorStatistics, setEvaluatorMonitorStatistics] = useState(null);
    const [evaluatorMonitorSelectedFaculty, setEvaluatorMonitorSelectedFaculty] = useState(null);
    const [evaluatorMonitorSelf, setEvaluatorMonitorSelf] = useState(null);
    const [evaluatorMonitorLoading, setEvaluatorMonitorLoading] = useState(false);
    const [evaluatorMonitorStatusFilter, setEvaluatorMonitorStatusFilter] = useState('');
    const [evaluatorMonitorRoleFilter, setEvaluatorMonitorRoleFilter] = useState('');
    const [evaluatorMonitorView, setEvaluatorMonitorView] = useState('breakdown');
    const [evaluatorMonitorSort, setEvaluatorMonitorSort] = useState({ key: 'evaluatorName', direction: 'asc' });
    const [evaluatorMonitorDetail, setEvaluatorMonitorDetail] = useState(null);
    const [evaluatorMonitorDetailLoading, setEvaluatorMonitorDetailLoading] = useState(false);
    const [selfMonitorRows, setSelfMonitorRows] = useState([]);
    const [selfMonitorLoading, setSelfMonitorLoading] = useState(false);
    const [selfMonitorSearch, setSelfMonitorSearch] = useState('');
    const [selfMonitorPeriod, setSelfMonitorPeriod] = useState('');
    const [selfMonitorProgram, setSelfMonitorProgram] = useState('');
    const [selfMonitorStatus, setSelfMonitorStatus] = useState('');
    const [selfMonitorReopeningId, setSelfMonitorReopeningId] = useState(null);
    const [selfMonitorEditRow, setSelfMonitorEditRow] = useState(null);

    const evaluatorMonitorDepartments = useMemo(() => (
        [...new Set(
            evaluatorMonitorOptions.faculty
                .map((faculty) => String(faculty.department || '').trim())
                .filter(Boolean)
        )].sort((left, right) => left.localeCompare(right))
    ), [evaluatorMonitorOptions.faculty]);

    const evaluatorMonitorFilteredFaculty = useMemo(() => {
        const term = evaluatorMonitorFacultySearch.trim().toLowerCase();
        return evaluatorMonitorOptions.faculty.filter((faculty) => {
            if (String(faculty.id) === String(evaluatorMonitorFaculty)) return true;
            if (evaluatorMonitorDepartment && String(faculty.department || '') !== evaluatorMonitorDepartment) {
                return false;
            }
            if (!term) return true;
            const searchableDetails = [
                faculty.full_name,
                faculty.program_code,
                faculty.department,
            ].filter(Boolean).join(' ').toLowerCase();
            return searchableDetails.includes(term);
        });
    }, [evaluatorMonitorDepartment, evaluatorMonitorFaculty, evaluatorMonitorFacultySearch, evaluatorMonitorOptions.faculty]);

    // Notifications for save
    useEffect(() => {
        const requestedStatus = assignmentParams.get('status');
        if (requestedStatus === 'completed' || requestedStatus === 'pending' || requestedStatus === 'overdue') {
            setActiveTab('monitor');
        }
    }, [assignmentParams]);

    useEffect(() => {
        setEvaluatorMonitorStatusFilter('');
        setEvaluatorMonitorRoleFilter('');
    }, [evaluatorMonitorFaculty, evaluatorMonitorPeriod]);

    // --------------- Data Fetching ---------------

    const fetchSchedules = useCallback(async (background = false) => {
        try {
            if (!background) setLoadingSchedules(true);
            const data = await apiFetch('/api/evaluation-assignments.php');
            setSchedules(data.data || []);
        } catch (err) {
            addToast({ type: 'error', text: err.message || 'Failed to load schedules.' });
        } finally {
            if (!background) setLoadingSchedules(false);
        }
    }, []);

    const fetchPeriods = useCallback(async () => {
        try {
            const data = await apiFetch('/api/evaluation-period.php?action=periods');
            const list = Array.isArray(data.data) ? data.data : [];
            setPeriods(list);
            const currentId = data.current?.id ? String(data.current.id) : '';
            setNewSchedule((current) => ({
                ...current,
                school_year: current.school_year || data.current?.school_year || '',
                period_name: current.period_name || data.current?.period_name || '',
                date_start: current.date_start || data.current?.date_start || '',
                due_date: current.due_date || data.current?.date_end || '',
            }));
            setMonitorPeriodId((current) => current || currentId || (list[0]?.id ? String(list[0].id) : ''));
        } catch (err) {
            addToast({ type: 'error', text: err.message || 'Failed to load evaluation periods.' });
        }
    }, []);



    const refreshSetupData = useCallback(async (background = false) => {
        await Promise.all([fetchSchedules(background), fetchPeriods()]);
    }, [fetchPeriods, fetchSchedules]);

    const loadMonitor = useCallback(async (background = false) => {
        if (activeTab !== 'monitor') return;
        if (!background) setMonitorLoading(true);
        try {
            const params = new URLSearchParams();
            if (monitorPeriodId) params.set('period_id', monitorPeriodId);
            params.set('_', String(Date.now()));
            const data = await apiFetch(`/api/evaluation-category-monitor.php${params.toString() ? `?${params.toString()}` : ''}`);
            if (!data.ok) {
                throw new Error(data.message || 'Unable to load category explanation monitor.');
            }
            setMonitorRows(Array.isArray(data.data) ? data.data : []);
        } catch (error) {
            addToast({ type: 'error', text: error.message });
        } finally {
            if (!background) setMonitorLoading(false);
        }
    }, [activeTab, monitorPeriodId]);

    const loadEvaluatorMonitorOptions = useCallback(async () => {
        try {
            const data = await apiFetch('/api/evaluation-category-monitor-evaluator.php?action=options');
            if (!data.ok) throw new Error(data.message || 'Unable to load evaluator monitor options.');
            const faculty = Array.isArray(data.faculty) ? data.faculty : [];
            const periods = Array.isArray(data.periods) ? data.periods : [];
            setEvaluatorMonitorOptions({ faculty, periods });
            setEvaluatorMonitorFaculty((current) => current || (faculty[0]?.id ? String(faculty[0].id) : ''));
            setEvaluatorMonitorPeriod((current) => current || (periods[0]?.period ? String(periods[0].period) : ''));
        } catch (error) {
            addToast({ type: 'error', text: error.message || 'Unable to load evaluator monitor options.' });
        }
    }, []);

    const loadEvaluatorMonitor = useCallback(async (background = false) => {
        if (activeTab !== 'monitor' || !evaluatorMonitorFaculty) return;
        if (!background) setEvaluatorMonitorLoading(true);
        try {
            const params = new URLSearchParams({
                action: 'faculty_evaluators',
                faculty_id: evaluatorMonitorFaculty,
                _: String(Date.now()),
            });
            if (evaluatorMonitorPeriod) params.set('period', evaluatorMonitorPeriod);
            const data = await apiFetch(`/api/evaluation-category-monitor-evaluator.php?${params.toString()}`);
            if (!data.ok) throw new Error(data.message || 'Unable to load evaluator breakdown.');
            setEvaluatorMonitorRows(Array.isArray(data.evaluators) ? data.evaluators : []);
            setEvaluatorMonitorComparison(Array.isArray(data.comparison) ? data.comparison : []);
            setEvaluatorMonitorStatistics(data.statistics || null);
            setEvaluatorMonitorSelectedFaculty(data.faculty || null);
            setEvaluatorMonitorSelf(data.selfEvaluation || null);
        } catch (error) {
            addToast({ type: 'error', text: error.message || 'Unable to load evaluator breakdown.' });
        } finally {
            if (!background) setEvaluatorMonitorLoading(false);
        }
    }, [activeTab, evaluatorMonitorFaculty, evaluatorMonitorPeriod]);

    const openEvaluatorDetail = useCallback(async (assignmentId) => {
        if (!assignmentId || !evaluatorMonitorFaculty) return;
        setEvaluatorMonitorDetailLoading(true);
        try {
            const params = new URLSearchParams({
                action: 'evaluator_detail',
                faculty_id: evaluatorMonitorFaculty,
                assignment_id: String(assignmentId),
            });
            if (evaluatorMonitorPeriod) params.set('period', evaluatorMonitorPeriod);
            const data = await apiFetch(`/api/evaluation-category-monitor-evaluator.php?${params.toString()}`);
            if (!data.ok) throw new Error(data.message || 'Unable to load evaluator detail.');
            setEvaluatorMonitorDetail(data.detail || null);
        } catch (error) {
            addToast({ type: 'error', text: error.message || 'Unable to load evaluator detail.' });
        } finally {
            setEvaluatorMonitorDetailLoading(false);
        }
    }, [evaluatorMonitorFaculty, evaluatorMonitorPeriod]);

    const loadSelfMonitor = useCallback(async (background = false) => {
        if (activeTab !== 'self_monitor') return;
        if (!background) setSelfMonitorLoading(true);
        try {
            const params = new URLSearchParams({ role: 'faculty', _: String(Date.now()) });
            const data = await apiFetch(`/api/self-evaluations.php?${params.toString()}`);
            if (!data.ok) {
                throw new Error(data.message || 'Unable to load self-evaluation monitor.');
            }
            setSelfMonitorRows(Array.isArray(data.records) ? data.records : []);
        } catch (error) {
            addToast({ type: 'error', text: error.message || 'Unable to load self-evaluation monitor.' });
        } finally {
            if (!background) setSelfMonitorLoading(false);
        }
    }, [activeTab]);

    const reopenSelfEvaluation = useCallback(async (row) => {
        const recordId = Number(row?.id || 0);
        if (!recordId) return;
        const name = row?.full_name || 'this faculty member';
        const confirmed = await confirmProceed({
            title: 'Are you sure you want to reopen this self-evaluation?',
            message: `This will allow ${name} to edit the submitted self-evaluation again.`,
            confirmText: 'Reopen',
        });
        if (!confirmed) return;

        setSelfMonitorReopeningId(recordId);
        try {
            const data = await apiFetch('/api/self-evaluations.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'reopen', record_id: recordId }),
            });
            if (!data.ok) {
                throw new Error(data.message || 'Unable to reopen self-evaluation.');
            }
            addToast({ type: 'success', text: data.message || 'Self-evaluation reopened for editing.' });
            await loadSelfMonitor(false);
        } catch (error) {
            addToast({ type: 'error', text: error.message || 'Unable to reopen self-evaluation.' });
        } finally {
            setSelfMonitorReopeningId(null);
        }
    }, [loadSelfMonitor]);

    const { refreshing: setupRefreshing } = useLiveRefresh(refreshSetupData, [], {
        intervalMs: 10000,
    });
    const [selfQuestionnaireDrafts, setSelfQuestionnaireDrafts] = useState({ self_admin: null, self_faculty: null });

    const { refreshing: monitorRefreshing } = useLiveRefresh(loadMonitor, [activeTab, monitorPeriodId], {
        enabled: false,
        intervalMs: 6000,
    });

    useEffect(() => {
        if (activeTab === 'monitor') {
            loadEvaluatorMonitorOptions();
        }
    }, [activeTab, loadEvaluatorMonitorOptions]);

    useEffect(() => {
        if (activeTab === 'monitor' && evaluatorMonitorFaculty) {
            loadEvaluatorMonitor(false);
        }
    }, [activeTab, evaluatorMonitorFaculty, evaluatorMonitorPeriod, loadEvaluatorMonitor]);

    const { refreshing: evaluatorMonitorRefreshing } = useLiveRefresh(loadEvaluatorMonitor, [activeTab, evaluatorMonitorFaculty, evaluatorMonitorPeriod], {
        enabled: activeTab === 'monitor' && Boolean(evaluatorMonitorFaculty),
        intervalMs: 8000,
    });

    const { refreshing: selfMonitorRefreshing } = useLiveRefresh(loadSelfMonitor, [activeTab], {
        enabled: activeTab === 'self_monitor',
        intervalMs: 6000,
    });

    // --------------- Questionnaire Loading ---------------

    useEffect(() => {
        const loadFormB = async () => {
            try {
                setFormBStatus('loading');
                const data = await apiFetch('/api/form_b_admin.php');
                const payload = data.data || data;
                if (data.ok && payload.categories) {
                    setQuestionnaires((prev) => ({
                        ...prev,
                        faculty: {
                            ...prev.faculty,
                            categories: payload.categories.map((c) => ({
                                id: c.id || c.sourceId || uid('c_'),
                                title: c.title || c.category_name || '',
                                weight: Number(c.factor_weight ?? c.weight ?? 0),
                                description: c.description || '',
                                questions: (c.questions || []).map((q) => ({
                                    id: q.id || q.sourceId || uid('q_'),
                                    text: q.text || q.question_text || '',
                                    type: q.type || 'rating',
                                    weight: q.weight || 1,
                                })),
                                isOpen: false,
                            })),
                        },
                    }));
                    setFormBStatus('synced');
                } else {
                    setFormBStatus('error');
                }
            } catch {
                setFormBStatus('error');
            }
        };
        const loadFormA = async () => {
            try {
                setFormAStatus('loading');
                const data = await apiFetch('/api/form_a_admin.php?action=categories');
                const payload = data.data || data;
                if (data.ok && payload.categories) {
                    setQuestionnaires((prev) => ({
                        ...prev,
                        admin: {
                            ...prev.admin,
                            categories: payload.categories.map((c) => ({
                                id: c.id || c.sourceId || uid('c_'),
                                title: c.title || c.category_name || '',
                                weight: Number(c.factor_weight ?? c.weight ?? 0),
                                description: c.description || '',
                                questions: (c.questions || []).map((q) => ({
                                    id: q.id || q.sourceId || uid('q_'),
                                    text: q.text || q.question_text || '',
                                    type: q.type || 'rating',
                                    weight: q.weight || 1,
                                })),
                                isOpen: false,
                            })),
                        },
                    }));
                    setFormAStatus('synced');
                } else {
                    setFormAStatus('error');
                }
            } catch {
                setFormAStatus('error');
            }
        };
        loadFormB();
        loadFormA();
    }, []);

    // --------------- Schedule CRUD ---------------

    function toScheduleDateInput(value) {
        return value ? String(value).slice(0, 10) : '';
    }

    const handleCreateSchedule = async () => {
        const schoolYear = (newSchedule.school_year || '').trim();
        const periodName = (newSchedule.period_name || '').trim();
        if (!schoolYear || !periodName || !newSchedule.date_start || !newSchedule.due_date) {
            addToast({ type: 'error', text: 'Please complete Academic Year, Period Name, Start Date, and Due Date.' });
            return;
        }

        if (new Date(newSchedule.due_date) < new Date(newSchedule.date_start)) {
            addToast({ type: 'error', text: 'Due Date cannot be earlier than Start Date.' });
            return;
        }

        const confirmed = await confirmSaveChanges({
            message: 'The evaluation schedule and assignments will be created for the selected period.',
            confirmText: 'Create Schedule',
        });
        if (!confirmed) return;

        setCreating(true);

        try {            const data = await apiFetch('/api/evaluation-assignments.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(newSchedule),
            });
            if (data.ok) {
                addToast({ type: 'success', text: data.message || 'Evaluation assignment schedule created successfully.' });
                setNewSchedule((current) => ({ ...current, period_name: '', due_date: '' }));
                setPeriodControlSelectionId(String(data.data?.evaluation_period_id || ''));
                setPeriodControlRefreshKey((current) => current + 1);
                await fetchSchedules();
                await fetchPeriods();
            } else {
                addToast({ type: 'error', text: data.error || 'Failed to create schedule.' });
            }
        } catch (err) {
            addToast({ type: 'error', text: err.message || 'Network error. Please try again.' });
        } finally {
            setCreating(false);
        }
    };

    const handleEditSchedule = (schedule) => {
        setEditingScheduleId(schedule.id);
        const editableSchedule = {
            school_year: schedule.school_year || '',
            period_name: schedule.evaluation_period_name || '',
            date_start: toScheduleDateInput(schedule.period_start),
            due_date: toScheduleDateInput(schedule.due_date || schedule.period_end),
        };
        setEditingScheduleOriginal(editableSchedule);
        setNewSchedule(editableSchedule);
        window.requestAnimationFrame(() => {
            scheduleFormRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            window.setTimeout(() => schoolYearInputRef.current?.focus({ preventScroll: true }), 450);
        });
    };

    const handleCancelEditSchedule = () => {
        setEditingScheduleId(null);
        setEditingScheduleOriginal(null);
        setNewSchedule(defaultScheduleForm());
    };

    const handleUpdateSchedule = async () => {
        const schoolYear = (newSchedule.school_year || '').trim();
        const periodName = (newSchedule.period_name || '').trim();
        if (!editingScheduleId || !schoolYear || !periodName || !newSchedule.date_start || !newSchedule.due_date) {
            addToast({ type: 'error', text: 'Please complete Academic Year, Period Name, Start Date, and Due Date.' });
            return;
        }

        if (new Date(newSchedule.due_date) < new Date(newSchedule.date_start)) {
            addToast({ type: 'error', text: 'Due Date cannot be earlier than Start Date.' });
            return;
        }

        const dueDateChanged = editingScheduleOriginal?.due_date && editingScheduleOriginal.due_date !== newSchedule.due_date;
        const confirmed = await confirmSaveChanges({
            message: dueDateChanged
                ? `Do you want to edit the deadline date from ${formatDateLabel(editingScheduleOriginal.due_date)} to ${formatDateLabel(newSchedule.due_date)}? Existing assignments and submitted results will remain intact.`
                : 'This will update the selected schedule period details. Existing assignments and submitted results will not be removed.',
            confirmText: dueDateChanged ? 'Update Deadline Date' : 'Update Schedule',
        });
        if (!confirmed) return;

        setCreating(true);
        try {
            const data = await apiFetch('/api/evaluation-assignments.php', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update', id: editingScheduleId, ...newSchedule }),
            });
            if (data.ok) {
                addToast({ type: 'success', text: data.message || 'Schedule period updated successfully.' });
                setEditingScheduleId(null);
                setEditingScheduleOriginal(null);
                setNewSchedule(defaultScheduleForm());
                setPeriodControlRefreshKey((current) => current + 1);
                await fetchSchedules();
                await fetchPeriods();
            } else {
                addToast({ type: 'error', text: data.error || 'Failed to update schedule.' });
            }
        } catch (err) {
            addToast({ type: 'error', text: err.message || 'Network error. Please try again.' });
        } finally {
            setCreating(false);
        }
    };

    const handleDeleteSchedule = async (scheduleId) => {
        const confirmed = await confirmDeleteData({
            message: 'This schedule will be cancelled. Submitted assignment data and results will be kept.',
            confirmText: 'Cancel Schedule',
        });
        if (!confirmed) return;

        setDeleteLoading(scheduleId);
        try {
        const data = await apiFetch(`/api/evaluation-assignments.php?id=${scheduleId}`, {
            method: 'DELETE',
        });
        if (data.ok) {
                addToast({ type: 'success', text: data.message || 'Schedule deleted successfully.' });
                await fetchSchedules();
            } else {
                addToast({ type: 'error', text: data.error || 'Failed to delete schedule.' });
            }
        } catch (err) {
            addToast({ type: 'error', text: err.message || 'Network error. Please try again.' });
        } finally {
            setDeleteLoading(null);
        }
    };

    // --------------- Questionnaire CRUD ---------------

    const handleUpdateQuestionnaire = (type, updates) => {
        setQuestionnaires((prev) => ({
            ...prev,
            [type]: { ...prev[type], ...updates },
        }));
    };

    const handleUpdateCategory = (type, index, updatedCategory) => {
        setQuestionnaires((prev) => {
            const cats = [...prev[type].categories];
            cats[index] = updatedCategory;
            return {
                ...prev,
                [type]: { ...prev[type], categories: cats },
            };
        });
    };

    const handleAddCategory = (type) => {
        setQuestionnaires((prev) => ({
            ...prev,
            [type]: {
                ...prev[type],
                categories: [...prev[type].categories, createCategory('New Category', 0, '', [createQuestion('')])],
            },
        }));
    };

    const handleRemoveCategory = async (type, index) => {
        const confirmed = await confirmDeleteData({
            message: 'This questionnaire category will be removed from the draft. This action cannot be undone.',
        });
        if (!confirmed) return;
        setQuestionnaires((prev) => {
            const cats = prev[type].categories.filter((_, i) => i !== index);
            return {
                ...prev,
                [type]: { ...prev[type], categories: cats },
            };
        });
    };

    const handleMoveCategory = (type, index, direction) => {
        setQuestionnaires((prev) => {
            const cats = [...prev[type].categories];
            const nextIndex = index + direction;

            if (nextIndex < 0 || nextIndex >= cats.length) {
                return prev;
            }

            [cats[index], cats[nextIndex]] = [cats[nextIndex], cats[index]];

            return {
                ...prev,
                [type]: { ...prev[type], categories: cats },
            };
        });
    };

    const handleSaveQuestionnaire = async (type) => {
        const qnr = questionnaires[type];
        const totalWeight = qnr.categories.reduce((sum, c) => sum + (c.weight || 0), 0);

        if (Math.abs(totalWeight - 100) > 0.01) {
            addToast({ type: 'error', text: `Category weights must total 100%. Current total: ${totalWeight}%` });
            return;
        }

        const confirmed = await confirmSaveChanges();
        if (!confirmed) return;

        try {
            const endpoint = type === 'admin' ? '/api/form_a_admin.php' : '/api/form_b_admin.php';
            const data = await apiFetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'save_categories',
                    categories: qnr.categories.map((c) => ({
                        id: Number.isFinite(Number(c.id)) ? Number(c.id) : undefined,
                        title: c.title,
                        weight: c.weight,
                        description: c.description,
                        questions: c.questions.map((q) => ({
                            id: Number.isFinite(Number(q.id)) ? Number(q.id) : undefined,
                            text: q.text,
                            type: q.type,
                            weight: q.weight,
                        })),
                    })),
                }),
            });
            if (data.ok) {
                addToast({ type: 'success', text: `${type === 'admin' ? 'Admin' : 'Faculty'} questionnaire saved.` });
                const payload = data.data || data;
                if (Array.isArray(payload.categories)) {
                    setQuestionnaires((prev) => ({
                        ...prev,
                        [type]: {
                            ...prev[type],
                            categories: payload.categories.map((c) => ({
                                id: c.id || c.sourceId || uid('c_'),
                                title: c.title || c.category_name || '',
                                weight: Number(c.factor_weight ?? c.weight ?? 0),
                                description: c.description || '',
                                questions: (c.questions || []).map((q) => ({
                                    id: q.id || q.sourceId || uid('q_'),
                                    text: q.text || q.question_text || '',
                                    type: q.type || 'rating',
                                    weight: q.weight || 1,
                                })),
                                isOpen: false,
                            })),
                        },
                    }));
                }
                if (type === 'admin') {
                    setFormAStatus('synced');
                } else {
                    setFormBStatus('synced');
                }
            } else {
                addToast({ type: 'error', text: data.error || 'Failed to save questionnaire.' });
            }
        } catch {
            addToast({ type: 'error', text: 'Network error. Could not save questionnaire.' });
        }

        };

    // --------------- Preview ---------------

    const previewQuestionnaire = useMemo(() => {
        return questionnaires[getQuestionnaireSource(previewQuestionnaireType)] || null;
    }, [questionnaires, previewQuestionnaireType]);
    const isSelfEvaluationPreview = previewQuestionnaireType === 'self_admin' || previewQuestionnaireType === 'self_faculty';

    const previewEvaluation = useMemo(() => {
        return evaluateQuestionnaire(previewQuestionnaire, previewResponses);
    }, [previewQuestionnaire, previewResponses]);

    const handlePreviewResponse = (questionId, value) => {
        setPreviewResponses((prev) => ({ ...prev, [questionId]: value }));
    };

    // --------------- Computed ---------------

    const filteredSchedules = useMemo(() => {
        if (!scheduleFilter) return schedules;
        const q = scheduleFilter.toLowerCase();
        return schedules.filter(
            (s) =>
                (s.evaluation_period_name && s.evaluation_period_name.toLowerCase().includes(q)) ||
                s.status.toLowerCase().includes(q) ||
                (s.created_by_name && s.created_by_name.toLowerCase().includes(q))
        );
    }, [schedules, scheduleFilter]);

    const meaningfulMonitorRows = useMemo(() => {
        return monitorRows.filter(hasMeaningfulMonitorRow);
    }, [monitorRows]);

    const monitorUserOptions = useMemo(() => {
        const options = new Map();
        meaningfulMonitorRows.forEach((row) => {
            const value = String(row.evaluateeName || '').trim();
            if (value && !options.has(value.toLowerCase())) {
                options.set(value.toLowerCase(), value);
            }
        });
        return Array.from(options.values()).sort((a, b) => a.localeCompare(b));
    }, [meaningfulMonitorRows]);

    const monitorCategoryOptions = useMemo(() => {
        const options = new Map();
        meaningfulMonitorRows.forEach((row) => {
            const value = String(row.categoryTitle || '').trim();
            if (value && !options.has(value.toLowerCase())) {
                options.set(value.toLowerCase(), value);
            }
        });
        return Array.from(options.values()).sort((a, b) => a.localeCompare(b));
    }, [meaningfulMonitorRows]);

    useEffect(() => {
        if (monitorUserFilter && !monitorUserOptions.includes(monitorUserFilter)) {
            setMonitorUserFilter('');
        }
        if (monitorCategoryFilter && !monitorCategoryOptions.includes(monitorCategoryFilter)) {
            setMonitorCategoryFilter('');
        }
    }, [monitorUserFilter, monitorUserOptions, monitorCategoryFilter, monitorCategoryOptions]);

    const filteredMonitorRows = useMemo(() => {
        const query = monitorSearch.trim().toLowerCase();
        const userFilter = monitorUserFilter.trim().toLowerCase();
        const categoryFilter = monitorCategoryFilter.trim().toLowerCase();

        return meaningfulMonitorRows.filter((row) => {
            if (userFilter && String(row.evaluateeName || '').trim().toLowerCase() !== userFilter) {
                return false;
            }
            if (categoryFilter && String(row.categoryTitle || '').trim().toLowerCase() !== categoryFilter) {
                return false;
            }
            if (!query) {
                return true;
            }

            const searchable = [
                row.form,
                row.evaluateeName,
                row.evaluateeRole,
                row.positionTitle,
                row.program,
                row.department,
                row.period,
                row.categoryTitle,
                row.requiredExplanation,
                row.behavioralEvidence,
                row.reasonForRating,
                row.recommendation,
                row.aiDecision,
                row.explanationComplete ? 'complete completed' : 'incomplete pending',
            ]
                .map((value) => String(value || '').toLowerCase())
                .join(' ');
            return searchable.includes(query);
        });
    }, [meaningfulMonitorRows, monitorSearch, monitorUserFilter, monitorCategoryFilter]);

    const hasMonitorFilters = Boolean(
        monitorSearch.trim() ||
        monitorUserFilter.trim() ||
        monitorCategoryFilter.trim()
    );

    const monitorDepartmentGroups = useMemo(() => {
        return buildDepartmentMonitorGroups(filteredMonitorRows);
    }, [filteredMonitorRows]);

    const meaningfulMonitorSummary = useMemo(() => ({
        total: filteredMonitorRows.length,
        complete: filteredMonitorRows.filter((row) => row.explanationComplete === true).length,
        pendingReview: filteredMonitorRows.filter((row) => row.aiDecision === 'pending_review').length,
    }), [filteredMonitorRows]);

    const evaluatorMonitorRoleOptions = useMemo(() => {
        const options = new Map();
        evaluatorMonitorRows.forEach((row) => {
            const value = String(row.roleLabel || '').trim();
            if (value && !options.has(value.toLowerCase())) options.set(value.toLowerCase(), value);
        });
        return Array.from(options.values()).sort((a, b) => a.localeCompare(b));
    }, [evaluatorMonitorRows]);

    const filteredEvaluatorMonitorRows = useMemo(() => {
        const status = evaluatorMonitorStatusFilter.trim().toLowerCase();
        const role = evaluatorMonitorRoleFilter.trim().toLowerCase();
        return evaluatorMonitorRows.filter((row) => {
            if (status && String(row.submissionStatus || '').toLowerCase() !== status) return false;
            if (role && String(row.roleLabel || '').toLowerCase() !== role) return false;
            return true;
        });
    }, [evaluatorMonitorRows, evaluatorMonitorStatusFilter, evaluatorMonitorRoleFilter]);

    const sortedEvaluatorMonitorRows = useMemo(() => {
        const rows = [...filteredEvaluatorMonitorRows];
        const { key, direction } = evaluatorMonitorSort;
        rows.sort((a, b) => {
            const av = a[key];
            const bv = b[key];
            const an = Number(av);
            const bn = Number(bv);
            let result;
            if (Number.isFinite(an) && Number.isFinite(bn) && String(av ?? '') !== '' && String(bv ?? '') !== '') {
                result = an - bn;
            } else {
                result = String(av ?? '').localeCompare(String(bv ?? ''));
            }
            return direction === 'asc' ? result : -result;
        });
        return rows;
    }, [filteredEvaluatorMonitorRows, evaluatorMonitorSort]);

    const evaluatorMonitorSummary = useMemo(() => {
        const stats = evaluatorMonitorStatistics || {};
        const total = Number(stats.evaluatorCount || evaluatorMonitorRows.length || 0);
        const submitted = Number(stats.submittedCount || evaluatorMonitorRows.filter((row) => row.submissionStatus === 'submitted').length || 0);
        const pending = Number(stats.pendingCount || Math.max(0, total - submitted));
        return {
            total,
            submitted,
            pending,
            completionRate: total > 0 ? Math.round((submitted / total) * 100) : 0,
            average: Number(stats.averageScore || 0),
            stddev: Number(stats.standardDeviation || 0),
        };
    }, [evaluatorMonitorRows, evaluatorMonitorStatistics]);

    const updateEvaluatorMonitorSort = useCallback((key) => {
        setEvaluatorMonitorSort((current) => ({
            key,
            direction: current.key === key && current.direction === 'asc' ? 'desc' : 'asc',
        }));
    }, []);

    const selfMonitorPeriodOptions = useMemo(() => {
        const options = new Map();
        selfMonitorRows.forEach((row) => {
            const value = String(row.evaluation_period || '').trim();
            if (value && !options.has(value.toLowerCase())) options.set(value.toLowerCase(), value);
        });
        return Array.from(options.values()).sort((a, b) => b.localeCompare(a));
    }, [selfMonitorRows]);

    const selfMonitorProgramOptions = useMemo(() => {
        const options = new Map();
        selfMonitorRows.forEach((row) => {
            const value = String(row.program_code || row.faculty_department || row.department || '').trim();
            if (value && !options.has(value.toLowerCase())) options.set(value.toLowerCase(), value);
        });
        return Array.from(options.values()).sort((a, b) => a.localeCompare(b));
    }, [selfMonitorRows]);

    useEffect(() => {
        if (selfMonitorPeriod && !selfMonitorPeriodOptions.includes(selfMonitorPeriod)) {
            setSelfMonitorPeriod('');
        }
        if (selfMonitorProgram && !selfMonitorProgramOptions.includes(selfMonitorProgram)) {
            setSelfMonitorProgram('');
        }
    }, [selfMonitorPeriod, selfMonitorPeriodOptions, selfMonitorProgram, selfMonitorProgramOptions]);

    const filteredSelfMonitorRows = useMemo(() => {
        const query = selfMonitorSearch.trim().toLowerCase();
        const period = selfMonitorPeriod.trim().toLowerCase();
        const program = selfMonitorProgram.trim().toLowerCase();
        const status = selfMonitorStatus.trim().toLowerCase();

        return selfMonitorRows.filter((row) => {
            if (period && String(row.evaluation_period || '').trim().toLowerCase() !== period) return false;
            const rowProgram = String(row.program_code || row.faculty_department || row.department || '').trim().toLowerCase();
            if (program && rowProgram !== program) return false;
            if (status && String(row.status || '').trim().toLowerCase() !== status) return false;
            if (!query) return true;

            const searchable = [
                row.full_name,
                row.role,
                row.department,
                row.faculty_department,
                row.program_code,
                row.evaluation_period,
                row.form_type,
                row.performance_level,
                row.status,
            ].map((value) => String(value || '').toLowerCase()).join(' ');
            return searchable.includes(query);
        });
    }, [selfMonitorRows, selfMonitorSearch, selfMonitorPeriod, selfMonitorProgram, selfMonitorStatus]);

    const selfMonitorGroups = useMemo(() => buildSelfEvaluationGroups(filteredSelfMonitorRows), [filteredSelfMonitorRows]);

    const selfMonitorSummary = useMemo(() => {
        const ratedRows = filteredSelfMonitorRows.filter((row) => Number(row.overall_rating || 0) > 0);
        const average = ratedRows.length
            ? ratedRows.reduce((sum, row) => sum + Number(row.overall_rating || 0), 0) / ratedRows.length
            : 0;
        return {
            total: filteredSelfMonitorRows.length,
            submitted: filteredSelfMonitorRows.filter((row) => String(row.status || '').toLowerCase() === 'submitted').length,
            reopened: filteredSelfMonitorRows.filter((row) => String(row.status || '').toLowerCase() === 'reopened').length,
            average,
        };
    }, [filteredSelfMonitorRows]);

    const hasSelfMonitorFilters = Boolean(
        selfMonitorSearch.trim() ||
        selfMonitorPeriod.trim() ||
        selfMonitorProgram.trim() ||
        selfMonitorStatus.trim()
    );

    const scheduleStats = useMemo(() => {
        const total = schedules.length;
        const active = schedules.filter((s) => s.status === 'active').length;
        const totalAssignments = schedules.reduce((sum, s) => sum + (parseInt(s.total_assignments) || 0), 0);
        return { total, active, totalAssignments };
    }, [schedules]);

    // --------------- Questionnaire computed ---------------

    const activeQuestionnaireType = getQuestionnaireSource(activeEditTab);
    const activeQuestionnaire = questionnaires[activeQuestionnaireType];
    const isSelfEvaluationQuestionnaire = activeEditTab === 'self_admin' || activeEditTab === 'self_faculty';

    const totalWeight = useMemo(() => {
        return activeQuestionnaire?.categories?.reduce((sum, c) => sum + (c.weight || 0), 0) || 0;
    }, [activeQuestionnaire]);

    const isValidWeight = Math.abs(totalWeight - 100) < 0.01;

    // --------------- Render ---------------

    return (
        <section className="assignment-workbench module-wide page-enter">
            {/* Hero */}
            <div className="flex items-start justify-between mb-6 flex-wrap gap-4">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Evaluation Assignment Workbench</h1>
                    <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Create and manage evaluation schedules, questionnaires, and preview evaluations.
                    </p>
                </div>
                <div className="flex items-center gap-3 flex-shrink-0">
                    {activeTab === 'assignment' && (
                        <div className="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 rounded-lg px-3 py-1.5">
                            <UserCheck size={14} />
                            <span>{scheduleStats.total} Schedules</span>
                            <span className="text-gray-300 dark:text-gray-600">|</span>
                            <span>{scheduleStats.totalAssignments} Total Assignments</span>
                        </div>
                    )}
                </div>
            </div>

            {/* Metrics */}
            {activeTab === 'assignment' && (
                <div className="grid grid-cols-4 gap-4 mb-6">
                    <MetricCard icon={Calendar} label="Total Schedules" value={scheduleStats.total} variant="primary" />
                    <MetricCard icon={CheckCircle2} label="Active Schedules" value={scheduleStats.active} variant="success" />
                    <MetricCard icon={ListChecks} label="Total Assignments" value={scheduleStats.totalAssignments} variant="warning" />
                    <MetricCard icon={ClipboardList} label="Status" variant="info" value={scheduleStats.total > 0 ? `${Math.round(scheduleStats.active / scheduleStats.total * 100)}% Active` : '—'} />
                </div>
            )}

            {/* Message */}
            

            {/* Save Message */}
            

            {/* Tab Bar */}
            <div className="assignment-workbench-tabs" role="tablist" aria-label="Evaluation assignment sections">
                {tabs.map((tab) => (
                    <button
                        key={tab.key}
                        type="button"
                        role="tab"
                        aria-selected={activeTab === tab.key}
                        onClick={() => setActiveTab(tab.key)}
                        className={`assignment-workbench-tab ${activeTab === tab.key ? 'active' : ''}`}
                    >
                        <tab.icon size={16} />
                        <span>{tab.label}</span>
                    </button>
                ))}
            </div>

            {/* ========== ASSIGNMENT TAB ========== */}
            {activeTab === 'assignment' && (
                <div className="space-y-6">
                    {/* Create/Edit Schedule Form */}
                    <div ref={scheduleFormRef} className={`assignment-schedule-card bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 ${editingScheduleId ? 'is-editing-schedule' : ''}`}>
                        {editingScheduleId && <div className="assignment-edit-mode-badge"><Pencil size={14} /> Editing selected schedule</div>}
                        <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-1">
                            {editingScheduleId ? 'Edit Evaluation Assignment Schedule' : 'Create Evaluation Assignment Schedule'}
                        </h2>
                        <p className="text-sm text-gray-500 dark:text-gray-400 mb-5">
                            {editingScheduleId
                                ? 'Update the selected schedule period details. Existing assignments and submitted results will remain intact.'
                                : 'Enter the academic year, period name, start date, and due date. The system will create the period and generate assignments based on existing roles, departments, and evaluation rules.'}
                        </p>

                        <div className="assignment-schedule-form grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-5">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Academic Year</label>
                                <input
                                    type="text"
                                    className="assignment-schedule-input w-full border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2.5 text-sm focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 focus:border-blue-500 dark:focus:border-blue-400 outline-none"
                                    value={newSchedule.school_year}
                                    onChange={(e) => setNewSchedule((prev) => ({ ...prev, school_year: e.target.value }))}
                                    placeholder="e.g., 2025-2026"
                                />
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Period Name</label>
                                <input
                                    ref={schoolYearInputRef}
                                    type="text"
                                    className="assignment-schedule-input w-full border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2.5 text-sm focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 focus:border-blue-500 dark:focus:border-blue-400 outline-none"
                                    value={newSchedule.period_name}
                                    onChange={(e) => setNewSchedule((prev) => ({ ...prev, period_name: e.target.value }))}
                                    placeholder="e.g., 2025 Midyear Appraisal"
                                    autoComplete="new-password"
                                    name="appraisia-assignment-period-name"
                                    data-lpignore="true"
                                    data-form-type="other"
                                />
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Start Date</label>
                                <ModernDatePicker value={newSchedule.date_start} onChange={(value) => setNewSchedule((prev) => ({ ...prev, date_start: value }))} label="Start date" minYear={2000} maxYear={scheduleMaxYear} disableFuture={false} required className="schedule-modern-date" />
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Due Date</label>
                                <ModernDatePicker value={newSchedule.due_date} onChange={(value) => setNewSchedule((prev) => ({ ...prev, due_date: value }))} label="Due date" minYear={2000} maxYear={scheduleMaxYear} minDate={newSchedule.date_start} disableFuture={false} required className="schedule-modern-date" />
                            </div>
                        </div>

                        <div className="mt-5 flex items-center gap-3">
                            <button
                                onClick={editingScheduleId ? handleUpdateSchedule : handleCreateSchedule}
                                disabled={creating || !newSchedule.school_year.trim() || !newSchedule.period_name.trim() || !newSchedule.date_start || !newSchedule.due_date}
                                className="assignment-create-button inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 dark:bg-blue-700 hover:bg-blue-700 dark:hover:bg-blue-800 disabled:bg-blue-300 text-white text-sm font-medium rounded-lg transition-colors disabled:cursor-not-allowed"
                            >
                                {creating ? (
                                    <>
                                        <Loader2 size={16} className="animate-spin" />
                                        {editingScheduleId ? 'Updating...' : 'Creating...'}
                                    </>
                                ) : (
                                    <>
                                        {editingScheduleId ? <Save size={16} /> : <Plus size={16} />}
                                        {editingScheduleId ? 'Update Schedule' : 'Create Assignment'}
                                    </>
                                )}
                            </button>
                            {editingScheduleId && (
                                <button
                                    type="button"
                                    onClick={handleCancelEditSchedule}
                                    disabled={creating}
                                    className="inline-flex items-center gap-2 px-5 py-2.5 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 text-sm font-medium rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors disabled:opacity-50"
                                >
                                    <X size={16} />
                                    Cancel Edit
                                </button>
                            )}
                        </div>
                    </div>

                    {/* Schedules List */}
                    <div className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
                        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-700">
                            <h3 className="text-base font-semibold text-gray-900 dark:text-gray-100">Assignment Schedules</h3>
                            <div className="flex items-center gap-3">
                                <div className="relative">
                                    <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500" />
                                    <input
                                        type="text"
                                        className="assignment-schedule-search pl-9 pr-3 py-1.5 text-sm border border-gray-200 dark:border-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 focus:border-blue-500 dark:focus:border-blue-400 outline-none w-48"
                                        placeholder="Search schedules..."
                                        value={scheduleFilter}
                                        onChange={(e) => setScheduleFilter(e.target.value)}
                                    />
                                </div>
                            </div>
                        </div>

                        {loadingSchedules ? (
                            <div className="flex items-center justify-center py-16">
                                <Loader2 size={24} className="animate-spin text-gray-400 dark:text-gray-500" />
                            </div>
                        ) : filteredSchedules.length === 0 ? (
                            <div className="text-center py-16 text-gray-400 dark:text-gray-500">
                                <ClipboardList size={32} className="mx-auto mb-3 opacity-50" />
                                <p className="text-sm font-medium">No schedules found</p>
                                <p className="text-xs mt-1">Create your first evaluation schedule above.</p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="bg-gray-50 dark:bg-gray-800/50 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                            <th className="px-6 py-3">Evaluation Period</th>
                                            <th className="px-6 py-3">Academic Year</th>
                                            <th className="px-6 py-3">Start Date</th>
                                            <th className="px-6 py-3">Due Date</th>
                                            <th className="px-6 py-3">Status</th>
                                            <th className="px-6 py-3">Assignments</th>
                                            <th className="px-6 py-3">Created By</th>
                                            <th className="px-6 py-3">Created Date</th>
                                            <th className="px-6 py-3">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                                        {filteredSchedules.map((schedule) => (
                                            <tr key={schedule.id} className={`hover:bg-gray-50 dark:hover:bg-gray-700/50 dark:bg-gray-800/50 transition-colors ${Number(editingScheduleId) === Number(schedule.id) ? 'is-selected-for-edit' : ''}`}>
                                                <td className="px-6 py-4 font-medium text-gray-900 dark:text-gray-100">
                                                    {schedule.evaluation_period_name}
                                                </td>
                                                <td className="px-6 py-4 text-gray-600 dark:text-gray-400">
                                                    {schedule.school_year || '—'}
                                                </td>
                                                <td className="px-6 py-4 text-gray-600 dark:text-gray-400">
                                                    {formatDateLabel(schedule.period_start)}
                                                </td>
                                                <td className="px-6 py-4 text-gray-600 dark:text-gray-400">
                                                    {formatDateLabel(schedule.due_date)}
                                                </td>
                                                <td className="px-6 py-4">
                                                    <ControlPill
                                                        label={schedule.status === 'active' ? 'Active' : schedule.status === 'completed' ? 'Completed' : 'Cancelled'}
                                                        variant={
                                                            schedule.status === 'active'
                                                                ? 'success'
                                                                : schedule.status === 'completed'
                                                                ? 'info'
                                                                : 'warning'
                                                        }
                                                    />
                                                </td>
                                                <td className="px-6 py-4">
                                                    <span className="font-semibold text-gray-900 dark:text-gray-100">
                                                        {schedule.total_assignments}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 text-gray-600 dark:text-gray-400">
                                                    {schedule.created_by_name}
                                                </td>
                                                <td className="px-6 py-4 text-gray-500 dark:text-gray-400 text-xs">
                                                    {formatDateLabel(schedule.created_at)}
                                                </td>
                                                <td className="px-6 py-4">
                                                    <div className="flex items-center gap-2">
                                                        <button
                                                            onClick={() => handleEditSchedule(schedule)}
                                                            className={`assignment-schedule-edit-button p-1.5 text-gray-400 dark:text-gray-500 hover:text-blue-600 dark:hover:text-blue-300 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors ${Number(editingScheduleId) === Number(schedule.id) ? 'is-active' : ''}`}
                                                            title="Edit schedule"
                                                            aria-pressed={Number(editingScheduleId) === Number(schedule.id)}
                                                        >
                                                            <Pencil size={14} />
                                                        </button>
                                                        <button
                                                            onClick={() => handleDeleteSchedule(schedule.id)}
                                                            disabled={deleteLoading === schedule.id}
                                                            className="p-1.5 text-gray-400 dark:text-gray-500 hover:text-red-500 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors disabled:opacity-50"
                                                            title="Delete schedule"
                                                        >
                                                            {deleteLoading === schedule.id ? (
                                                                <Loader2 size={14} className="animate-spin" />
                                                            ) : (
                                                                <Trash2 size={14} />
                                                            )}
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>

                    <EvaluationPeriodControl
                        refreshKey={periodControlRefreshKey}
                        preferredPeriodId={periodControlSelectionId}
                        onPreferredPeriodApplied={() => setPeriodControlSelectionId('')}
                        onChanged={() => { refreshSetupData(false); }}
                    />
                    {setupRefreshing && <span className="live-refresh-indicator compact">Syncing setup...</span>}
                </div>
            )}

            {/* ========== PEER-TO-PEER ASSIGNMENT TAB ========== */}
            {activeTab === 'peer' && (
                <section className="peer-assignment-module">
                    <header className="peer-assignment-module-head">
                        <div>
                            <p>Peer Assignment Module</p>
                            <h2>{peerPanel === 'participants' ? 'Evaluation Period Participants' : 'Peer Assignment Management'}</h2>
                            <span>
                                {peerPanel === 'participants'
                                    ? 'Select who is included in the current evaluation period before generating or reviewing peer assignments.'
                                    : 'Create, review, and monitor evaluator-to-faculty peer assignments for the selected period.'}
                            </span>
                        </div>
                        <div className="peer-assignment-module-actions" role="group" aria-label="Peer assignment management views">
                            <button
                                type="button"
                                className={peerPanel === 'assignments' ? 'active' : ''}
                                aria-pressed={peerPanel === 'assignments'}
                                onClick={() => setPeerPanel('assignments')}
                            >
                                <ShieldCheck size={17} />
                                Peer Assignments
                            </button>
                            <button
                                type="button"
                                className={`manage-participants ${peerPanel === 'participants' ? 'active' : ''}`}
                                aria-pressed={peerPanel === 'participants'}
                                onClick={() => setPeerPanel('participants')}
                            >
                                <UserCheck size={17} />
                                Manage Period Participants
                            </button>
                        </div>
                    </header>
                    <div key={peerPanel} className="peer-assignment-module-panel" aria-live="polite">
                        {peerPanel === 'participants' ? (
                            <PeriodParticipantsPanel />
                        ) : (
                            <PeerAssignmentsPanel
                                admin
                                title="Automated Department Peer-to-Peer Assignments"
                            />
                        )}
                    </div>
                </section>
            )}

            {/* ========== CATEGORY MONITOR TAB ========== */}
            {activeTab === 'monitor' && (
                <div className="peer-monitor-page evaluator-monitor-page">
                    <div className="peer-monitor-metrics evaluator-monitor-metrics">
                        <MetricCard icon={UserCheck} label="Evaluators Assigned" value={evaluatorMonitorSummary.total || 0} variant="primary" />
                        <MetricCard icon={CheckCircle2} label="Submitted" value={evaluatorMonitorSummary.submitted || 0} variant="success" />
                        <MetricCard icon={Clock} label="Pending" value={evaluatorMonitorSummary.pending || 0} variant="warning" />
                        <MetricCard icon={BarChart3} label="Average Rating" value={evaluatorMonitorSummary.average ? `${evaluatorMonitorSummary.average.toFixed(2)}/5` : '--'} variant="info" />
                        <MetricCard icon={AlertTriangle} label="Score Variance" value={evaluatorMonitorSummary.stddev ? evaluatorMonitorSummary.stddev.toFixed(2) : '--'} variant="warning" />
                    </div>

                    <div className="peer-monitor-panel">
                        <div className="peer-monitor-head">
                            <div>
                                <p className="peer-monitor-eyebrow">Per Evaluator Results</p>
                                <h2>Evaluator Breakdown</h2>
                                <p>
                                    {evaluatorMonitorSelectedFaculty?.full_name || 'Select a faculty member'} - {evaluatorMonitorSelectedFaculty?.program_code || evaluatorMonitorSelectedFaculty?.department || 'All programs'} - {evaluatorMonitorSummary.completionRate}% complete
                                </p>
                                {evaluatorMonitorRefreshing && <span className="live-refresh-indicator compact">Syncing evaluator results...</span>}
                            </div>
                            <div className="peer-monitor-tools">
                                <select
                                    className="peer-monitor-period"
                                    value={evaluatorMonitorDepartment}
                                    onChange={(event) => {
                                        const department = event.target.value;
                                        setEvaluatorMonitorDepartment(department);
                                        setEvaluatorMonitorFacultySearch('');
                                        if (department) {
                                            const firstEmployee = evaluatorMonitorOptions.faculty.find(
                                                (faculty) => String(faculty.department || '') === department
                                            );
                                            setEvaluatorMonitorFaculty(firstEmployee?.id ? String(firstEmployee.id) : '');
                                        }
                                    }}
                                    aria-label="Filter evaluated employees by department"
                                >
                                    <option value="">All departments</option>
                                    {evaluatorMonitorDepartments.map((department) => (
                                        <option key={department} value={department}>
                                            {department === 'CAS, CBA, CCJE, COED, CITE' ? 'VPAA' : department}
                                        </option>
                                    ))}
                                </select>
                                <label className="peer-monitor-search">
                                    <Search size={16} aria-hidden="true" />
                                    <input
                                        type="search"
                                        value={evaluatorMonitorFacultySearch}
                                        onChange={(event) => setEvaluatorMonitorFacultySearch(event.target.value)}
                                        placeholder="Search employee name, program, or department"
                                        aria-label="Search evaluated employee by name, program, or department"
                                    />
                                </label>
                                <select
                                    className="peer-monitor-period"
                                    value={evaluatorMonitorFaculty}
                                    onChange={(event) => setEvaluatorMonitorFaculty(event.target.value)}
                                    aria-label="Select faculty member"
                                >
                                    <option value="">Select evaluated employee</option>
                                    {evaluatorMonitorFilteredFaculty.map((faculty) => (
                                        <option key={faculty.id} value={faculty.id}>
                                            {faculty.full_name} — {faculty.program_code || 'No program'}
                                        </option>
                                    ))}
                                </select>
                                <select
                                    className="peer-monitor-period"
                                    value={evaluatorMonitorPeriod}
                                    onChange={(event) => setEvaluatorMonitorPeriod(event.target.value)}
                                    aria-label="Select evaluation period"
                                >
                                    <option value="">All periods</option>
                                    {evaluatorMonitorOptions.periods.map((period) => (
                                        <option key={period.period} value={period.period}>
                                            {period.period}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        {evaluatorMonitorSelectedFaculty && evaluatorMonitorSelf && (
                            <section className="category-monitor-self-status" aria-label="Self evaluation status">
                                <div className="category-monitor-self-copy">
                                    <span>Self Evaluation</span>
                                    <EvaluatorStatusBadge status={evaluatorMonitorSelf.status} />
                                    <p>
                                        {evaluatorMonitorSelf.status === 'submitted' && evaluatorMonitorSelf.submittedAt
                                            ? `Submitted on ${formatDateLabel(evaluatorMonitorSelf.submittedAt)}`
                                            : evaluatorMonitorSelf.status === 'reopened' && evaluatorMonitorSelf.reopenedAt
                                            ? `Reopened on ${formatDateLabel(evaluatorMonitorSelf.reopenedAt)}`
                                            : evaluatorMonitorSelf.status === 'not_required'
                                            ? 'No Self Evaluation is required for this cycle.'
                                            : evaluatorMonitorSelf.deadline
                                            ? `Deadline ${formatDateLabel(evaluatorMonitorSelf.deadline)}`
                                            : 'No submission recorded for this cycle.'}
                                    </p>
                                </div>
                                {evaluatorMonitorSelf.canView && evaluatorMonitorSelf.status === 'submitted' && (
                                    <button
                                        type="button"
                                        className="category-monitor-self-view"
                                        onClick={() => setSelfMonitorEditRow({
                                            id: evaluatorMonitorSelf.recordId,
                                            assignment_id: evaluatorMonitorSelf.assignmentId,
                                            faculty_department: evaluatorMonitorSelectedFaculty.department,
                                        })}
                                    >
                                        <Eye size={15} /> View Self Evaluation
                                    </button>
                                )}
                            </section>
                        )}

                        <div className="evaluator-monitor-tabs" role="tablist" aria-label="Evaluator monitor views">
                            {[
                                ['breakdown', 'Evaluator Breakdown'],
                                ['comparison', 'Comparison Matrix'],
                            ].map(([key, label]) => (
                                <button
                                    key={key}
                                    type="button"
                                    className={evaluatorMonitorView === key ? 'active' : ''}
                                    onClick={() => setEvaluatorMonitorView(key)}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>

                        <div className="evaluator-monitor-filters">
                            <select
                                className="peer-monitor-period"
                                value={evaluatorMonitorStatusFilter}
                                onChange={(event) => setEvaluatorMonitorStatusFilter(event.target.value)}
                                aria-label="Filter evaluator rows by status"
                            >
                                <option value="">All Evaluators</option>
                                <option value="submitted">Only Submitted</option>
                                <option value="pending">Only Pending</option>
                                <option value="overdue">Only Overdue</option>
                                <option value="draft">Only Draft</option>
                            </select>
                            <select
                                className="peer-monitor-period"
                                value={evaluatorMonitorRoleFilter}
                                onChange={(event) => setEvaluatorMonitorRoleFilter(event.target.value)}
                                aria-label="Filter evaluator rows by role"
                            >
                                <option value="">All Roles</option>
                                {evaluatorMonitorRoleOptions.map((role) => (
                                    <option key={role} value={role}>{role}</option>
                                ))}
                            </select>
                            <div className="evaluator-export-actions">
                                <button type="button" disabled><FileText size={14} /> Summary</button>
                                <button type="button" disabled><ClipboardList size={14} /> Matrix</button>
                            </div>
                        </div>

                        {evaluatorMonitorLoading ? (
                            <div className="flex items-center justify-center py-16">
                                <Loader2 size={24} className="animate-spin text-gray-400 dark:text-gray-500" />
                            </div>
                        ) : evaluatorMonitorRows.length === 0 ? (
                            <div className="dipascaf-empty">
                                No evaluator results are available for the selected faculty member and period.
                            </div>
                        ) : sortedEvaluatorMonitorRows.length === 0 ? (
                            <div className="dipascaf-empty">
                                No evaluator results match the selected status and role filters.
                            </div>
                        ) : evaluatorMonitorView === 'breakdown' ? (
                            <EvaluatorBreakdownTable
                                rows={sortedEvaluatorMonitorRows}
                                sort={evaluatorMonitorSort}
                                onSort={updateEvaluatorMonitorSort}
                                onDetail={openEvaluatorDetail}
                            />
                        ) : (
                            <div className="evaluator-comparison-wrap">
                                <table className="evaluator-comparison-table">
                                    <thead>
                                        <tr>
                                            <th>Category / Factor</th>
                                            {sortedEvaluatorMonitorRows.map((row) => (
                                                <th key={row.assignmentId}>{row.evaluatorName}<small>{row.roleLabel}</small></th>
                                            ))}
                                            <th>Average</th>
                                            <th>Variation</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {evaluatorMonitorComparison.map((category) => (
                                            <tr key={category.categoryTitle}>
                                                <td>{category.categoryTitle}</td>
                                                {sortedEvaluatorMonitorRows.map((row) => {
                                                    const score = (category.scores || []).find((item) => Number(item.assignmentId) === Number(row.assignmentId))?.score;
                                                    return (
                                                        <td key={`${category.categoryTitle}-${row.assignmentId}`}>
                                                            <span className={`evaluator-matrix-score score-${scoreTone(score)}`}>
                                                                {score ? Number(score).toFixed(2) : '--'}
                                                            </span>
                                                        </td>
                                                    );
                                                })}
                                                <td>{Number(category.average || 0).toFixed(2)}</td>
                                                <td>{Number(category.spread || 0).toFixed(2)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {evaluatorMonitorDetailLoading && (
                            <div className="evaluator-detail-loading">
                                <Loader2 size={18} className="animate-spin" />
                                Loading evaluator detail...
                            </div>
                        )}
                        <EvaluatorDetailModal
                            detail={evaluatorMonitorDetail}
                            statistics={evaluatorMonitorStatistics}
                            onClose={() => setEvaluatorMonitorDetail(null)}
                        />
                    </div>
                </div>
            )}

            {/* ========== CATEGORY MONITOR TAB ========== */}
            {activeTab === 'legacy_monitor' && (
                <div className="peer-monitor-page">
                    <div className="peer-monitor-metrics">
                        <MetricCard icon={ClipboardList} label="Category Results" value={meaningfulMonitorSummary.total || 0} variant="primary" />
                        <MetricCard icon={CheckCircle2} label="Complete Explanations" value={meaningfulMonitorSummary.complete || 0} variant="success" />
                        <MetricCard icon={MessageSquare} label="AI Pending Review" value={meaningfulMonitorSummary.pendingReview || 0} variant="warning" />
                    </div>

                    <div className="peer-monitor-panel">
                        <div className="peer-monitor-head">
                            <div>
                                <p className="peer-monitor-eyebrow">Peer Monitor</p>
                                <h2>Category Explanation Review</h2>
                                <p>Clear view of peer category scores, required explanations, completion, and AI review status.</p>
                                {monitorRefreshing && <span className="live-refresh-indicator compact">Syncing monitor...</span>}
                            </div>
                            <div className="peer-monitor-tools">
                                <label className="peer-monitor-search">
                                    <Search size={16} />
                                    <input
                                        value={monitorSearch}
                                        onChange={(event) => setMonitorSearch(event.target.value)}
                                        placeholder="Search name, category, status..."
                                    />
                                </label>
                                <select
                                    className="peer-monitor-period"
                                    value={monitorUserFilter}
                                    onChange={(event) => setMonitorUserFilter(event.target.value)}
                                    aria-label="Filter monitor by user"
                                >
                                    <option value="">All users</option>
                                    {monitorUserOptions.map((name) => (
                                        <option key={name} value={name}>
                                            {name}
                                        </option>
                                    ))}
                                </select>
                                <select
                                    className="peer-monitor-period"
                                    value={monitorCategoryFilter}
                                    onChange={(event) => setMonitorCategoryFilter(event.target.value)}
                                    aria-label="Filter monitor by category"
                                >
                                    <option value="">All categories</option>
                                    {monitorCategoryOptions.map((category) => (
                                        <option key={category} value={category}>
                                            {category}
                                        </option>
                                    ))}
                                </select>
                                <select
                                    className="peer-monitor-period"
                                    value={monitorPeriodId}
                                    onChange={(event) => setMonitorPeriodId(event.target.value)}
                                    aria-label="Monitor evaluation period"
                                >
                                    {periods.length === 0 && <option value="">Current period</option>}
                                    {periods.map((period) => (
                                        <option key={period.id} value={period.id}>
                                            {period.period_name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        

                        {monitorLoading ? (
                            <div className="flex items-center justify-center py-16">
                                <Loader2 size={24} className="animate-spin text-gray-400 dark:text-gray-500" />
                            </div>
                        ) : monitorDepartmentGroups.length === 0 ? (
                            <div className="dipascaf-empty">
                                {hasMonitorFilters
                                    ? 'No category explanation records match your search.'
                                    : 'No category explanation records are available for this period.'}
                            </div>
                        ) : (
                            <div className="peer-monitor-groups">
                                {monitorDepartmentGroups.map((department) => (
                                    <section key={department.name} className="peer-monitor-department">
                                        <div className="peer-monitor-department-head">
                                            <div>
                                                <h3>{department.name}</h3>
                                                <p>
                                                    {department.total} category results, {department.complete} complete explanations
                                                </p>
                                            </div>
                                            <div className="peer-monitor-pill-row">
                                                {department.roles['Program Head'].length > 0 && <ControlPill label={`${department.roles['Program Head'].length} Program Head`} variant="info" />}
                                                {department.roles.Faculty.length > 0 && <ControlPill label={`${department.roles.Faculty.length} Faculty`} variant="success" />}
                                                {department.roles.Dean.length > 0 && <ControlPill label={`${department.roles.Dean.length} Dean`} variant="default" />}
                                                {department.pendingReview > 0 && <ControlPill label={`${department.pendingReview} AI review`} variant="warning" />}
                                            </div>
                                        </div>

                                        <div className="peer-monitor-role-groups">
                                            {['Program Head', 'Faculty', 'Dean'].map((roleLabel) => {
                                                const rows = department.roles[roleLabel] || [];
                                                if (rows.length === 0) return null;
                                                return (
                                                    <section key={roleLabel} className="peer-monitor-role">
                                                        <div className="peer-monitor-role-head">
                                                            <h4>{roleLabel}</h4>
                                                            <span>{rows.length} result{rows.length !== 1 ? 's' : ''}</span>
                                                        </div>
                                                        <MonitorRowsTable rows={rows} />
                                                    </section>
                                                );
                                            })}
                                        </div>
                                    </section>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* Legacy self-monitor view retained only for data compatibility; it
                is no longer reachable from navigation. Category Monitor is the
                single monitoring surface for Self status and details. */}
            {false && activeTab === 'self_monitor' && (
                <div className="peer-monitor-page self-eval-monitor-page">
                    <div className="peer-monitor-metrics self-eval-monitor-metrics">
                        <MetricCard icon={ClipboardList} label="Self Evaluations" value={selfMonitorSummary.total || 0} variant="primary" />
                        <MetricCard icon={CheckCircle2} label="Submitted" value={selfMonitorSummary.submitted || 0} variant="success" />
                        <MetricCard icon={RotateCcw} label="Reopened" value={selfMonitorSummary.reopened || 0} variant="warning" />
                        <MetricCard icon={BarChart3} label="Average Rating" value={selfMonitorSummary.average ? selfMonitorSummary.average.toFixed(2) : '--'} variant="info" />
                    </div>

                    <div className="peer-monitor-panel">
                        <div className="peer-monitor-head">
                            <div>
                                <p className="peer-monitor-eyebrow">Self-Evaluation Monitor</p>
                                <h2>Faculty Self-Evaluation Review</h2>
                                <p>Focused view of faculty self-evaluation submissions, score breakdowns, status, and reopen activity.</p>
                                {selfMonitorRefreshing && <span className="live-refresh-indicator compact">Syncing self-evaluations...</span>}
                            </div>
                            <div className="peer-monitor-tools">
                                <label className="peer-monitor-search">
                                    <Search size={16} />
                                    <input
                                        value={selfMonitorSearch}
                                        onChange={(event) => setSelfMonitorSearch(event.target.value)}
                                        placeholder="Search faculty, program, level..."
                                    />
                                </label>
                                <select
                                    className="peer-monitor-period"
                                    value={selfMonitorProgram}
                                    onChange={(event) => setSelfMonitorProgram(event.target.value)}
                                    aria-label="Filter self-evaluations by program"
                                >
                                    <option value="">All programs</option>
                                    {selfMonitorProgramOptions.map((program) => (
                                        <option key={program} value={program}>
                                            {program}
                                        </option>
                                    ))}
                                </select>
                                <select
                                    className="peer-monitor-period"
                                    value={selfMonitorStatus}
                                    onChange={(event) => setSelfMonitorStatus(event.target.value)}
                                    aria-label="Filter self-evaluations by status"
                                >
                                    <option value="">All statuses</option>
                                    <option value="draft">Draft</option>
                                    <option value="submitted">Submitted</option>
                                    <option value="reopened">Reopened</option>
                                </select>
                                <select
                                    className="peer-monitor-period"
                                    value={selfMonitorPeriod}
                                    onChange={(event) => setSelfMonitorPeriod(event.target.value)}
                                    aria-label="Filter self-evaluations by period"
                                >
                                    <option value="">All periods</option>
                                    {selfMonitorPeriodOptions.map((period) => (
                                        <option key={period} value={period}>
                                            {period}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        {selfMonitorLoading ? (
                            <div className="flex items-center justify-center py-16">
                                <Loader2 size={24} className="animate-spin text-gray-400 dark:text-gray-500" />
                            </div>
                        ) : selfMonitorGroups.length === 0 ? (
                            <div className="dipascaf-empty">
                                {hasSelfMonitorFilters
                                    ? 'No self-evaluation records match your filters.'
                                    : 'No faculty self-evaluation records are available yet.'}
                            </div>
                        ) : (
                            <div className="peer-monitor-groups">
                                {selfMonitorGroups.map((department) => (
                                    <section key={department.name} className="peer-monitor-department">
                                        <div className="peer-monitor-department-head">
                                            <div>
                                                <h3>{department.name}</h3>
                                                <p>
                                                    {department.total} self-evaluation record{department.total !== 1 ? 's' : ''}, {department.submitted} submitted
                                                </p>
                                            </div>
                                            <div className="peer-monitor-pill-row">
                                                <ControlPill label={`${department.submitted} Submitted`} variant="success" />
                                                {department.reopened > 0 && <ControlPill label={`${department.reopened} Reopened`} variant="warning" />}
                                            </div>
                                        </div>

                                        <div className="peer-monitor-role-groups">
                                            <section className="peer-monitor-role">
                                                <div className="peer-monitor-role-head">
                                                    <h4>Faculty Self Evaluations</h4>
                                                    <span>{department.rows.length} profile{department.rows.length !== 1 ? 's' : ''}</span>
                                                </div>
                                                <SelfEvaluationMonitorRowsTable
                                                    rows={department.rows}
                                                    onView={setSelfMonitorEditRow}
                                                    viewingId={selfMonitorEditRow?.id || null}
                                                />
                                            </section>
                                        </div>
                                    </section>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* ========== QUESTIONNAIRES TAB ========== */}
            {activeTab === 'questionnaires' && (
                <div className="space-y-6">
                    {/* Questionnaire Type Selector */}
                    <div className="flex gap-2 mb-2 flex-wrap">
                        {questionnaireTypeOptions.map((option) => (
                            <button
                                key={option.key}
                                onClick={() => { setActiveEditTab(option.key); setQuestionnairePanel('editor'); }}
                                className={`px-4 py-2 rounded-lg text-sm font-medium transition-all ${
                                    questionnairePanel === 'editor' && activeEditTab === option.key
                                        ? 'bg-white dark:bg-gray-800 shadow-sm dark:shadow-gray-900/30 border border-gray-200 dark:border-gray-700 text-gray-900 dark:text-gray-100'
                                        : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'
                                }`}
                                title={option.purpose}
                            >
                                {option.label}
                            </button>
                        ))}
                        <button
                            type="button"
                            onClick={() => setQuestionnairePanel('preview')}
                            className={`px-4 py-2 rounded-lg text-sm font-medium transition-all inline-flex items-center gap-2 ${
                                questionnairePanel === 'preview'
                                    ? 'bg-white dark:bg-gray-800 shadow-sm dark:shadow-gray-900/30 border border-gray-200 dark:border-gray-700 text-gray-900 dark:text-gray-100'
                                    : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'
                            }`}
                            title="Preview evaluation and self-evaluation questionnaires"
                        >
                            <Eye size={15} /> Evaluation Preview
                        </button>
                        <button
                            type="button"
                            onClick={() => setQuestionnairePanel('goals')}
                            className={`px-4 py-2 rounded-lg text-sm font-medium transition-all inline-flex items-center gap-2 ${
                                questionnairePanel === 'goals'
                                    ? 'bg-white dark:bg-gray-800 shadow-sm dark:shadow-gray-900/30 border border-gray-200 dark:border-gray-700 text-gray-900 dark:text-gray-100'
                                    : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'
                            }`}
                            title="Preview the PMAS Form 1 Goals Record Sheet questionnaire"
                        >
                            <ListChecks size={15} /> Goals Record Sheet
                        </button>
                    </div>
                    {questionnairePanel === 'editor' && <>
                    <div className="questionnaire-purpose-note">
                        <strong>{questionnaireTypeOptions.find((option) => option.key === activeEditTab)?.label}</strong>
                        <span>{questionnaireTypeOptions.find((option) => option.key === activeEditTab)?.purpose}</span>
                    </div>

                    {/* Status indicator */}
                    {!isSelfEvaluationQuestionnaire && (
                        <div className="flex items-center gap-3 text-xs text-gray-400 dark:text-gray-500 mb-2">
                            <span>Questionnaire:</span>
                            <ControlPill
                                label={activeQuestionnaireType === 'admin' ? formAStatus : formBStatus}
                                variant={
                                    (activeQuestionnaireType === 'admin' ? formAStatus : formBStatus) === 'synced'
                                        ? 'success'
                                        : (activeQuestionnaireType === 'admin' ? formAStatus : formBStatus) === 'loading'
                                        ? 'warning'
                                        : 'danger'
                                }
                            />
                            {!isValidWeight && (
                                <span className="text-amber-600 dark:text-amber-400 font-medium flex items-center gap-1">
                                    <AlertTriangle size={12} />
                                    Weights total {totalWeight}% (must be 100%)
                                </span>
                            )}
                        </div>
                    )}

                    {/* Questionnaire editor */}
                    {isSelfEvaluationQuestionnaire ? (
                        <SelfEvaluationModule
                            key={activeEditTab}
                            role={{ key: 'admin', user: { name: '', email: '', department: '' } }}
                            initialTargetRole={activeEditTab === 'self_admin' ? 'dean' : 'faculty'}
                            targetRoleOptions={activeEditTab === 'self_admin' ? leadershipSelfEvaluationOptions : facultySelfEvaluationOptions}
                            onTemplateChange={(template) => setSelfQuestionnaireDrafts((current) => ({ ...current, [activeEditTab]: template }))}
                        />
                    ) : (
                    <div className="questionnaire-editor-surface">
                        <div className="questionnaire-editor-top">
                            <div className="questionnaire-editor-heading">
                                <span>Questionnaire configuration</span>
                                <input
                                    className="questionnaire-editor-title"
                                    value={activeQuestionnaire.title}
                                    onChange={(e) => handleUpdateQuestionnaire(activeQuestionnaireType, { title: e.target.value })}
                                />
                                <textarea
                                    className="questionnaire-editor-description"
                                    rows={1}
                                    value={activeQuestionnaire.description}
                                    onChange={(e) => handleUpdateQuestionnaire(activeQuestionnaireType, { description: e.target.value })}
                                />
                            </div>
                            <div className="questionnaire-editor-actions">
                                <span className={`questionnaire-total-weight ${isValidWeight ? 'is-valid' : 'is-warning'}`}><b>{totalWeight}%</b><small>Total weight</small></span>
                                <button
                                    onClick={() => handleAddCategory(activeQuestionnaireType)}
                                    className="questionnaire-add-category"
                                >
                                    <Plus size={14} /> Add Category
                                </button>
                                <button
                                    onClick={() => handleSaveQuestionnaire(activeQuestionnaireType)}
                                    className="questionnaire-save"
                                >
                                    <Save size={14} /> Save
                                </button>
                            </div>
                        </div>

                        {/* Weight warning */}
                        {!isValidWeight && (
                            <div className="mb-4 px-4 py-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl text-sm text-amber-700 dark:text-amber-300 flex items-center gap-2">
                                <AlertTriangle size={16} />
                                Category weights must total 100%. Current total: <strong>{totalWeight}%</strong>
                            </div>
                        )}

                        {/* Categories */}
                        <div className="questionnaire-category-stack">
                            {activeQuestionnaire.categories.length === 0 ? (
                                <div className="text-center py-10 text-gray-400 dark:text-gray-500">
                                    <p className="text-sm">No categories yet. Click "Add Category" to start building your questionnaire.</p>
                                </div>
                            ) : (
                                activeQuestionnaire.categories.map((category, index) => (
                                    <CategoryEditor
                                        key={category.id}
                                        category={category}
                                        index={index}
                                        onChange={(i, updated) => handleUpdateCategory(activeQuestionnaireType, i, updated)}
                                        onRemove={() => handleRemoveCategory(activeQuestionnaireType, index)}
                                        onMoveUp={() => handleMoveCategory(activeQuestionnaireType, index, -1)}
                                        onMoveDown={() => handleMoveCategory(activeQuestionnaireType, index, 1)}
                                        canMoveUp={index > 0}
                                        canMoveDown={index < activeQuestionnaire.categories.length - 1}
                                    />
                                ))
                            )}
                        </div>
                    </div>
                    )}
                    </>}
                </div>
            )}

            {activeTab === 'questionnaires' && questionnairePanel === 'goals' && (
                <div className="goals-questionnaire-preview-shell">
                    <GoalsRecordTemplateManager />
                </div>
            )}

            {/* ========== PREVIEW TAB ========== */}
            {activeTab === 'questionnaires' && questionnairePanel === 'preview' && (
                <div className={`evaluation-preview-layout grid grid-cols-1 lg:grid-cols-3 gap-6 ${isSelfEvaluationPreview ? 'is-self-preview' : 'is-live-preview'}`}>
                    {/* Main preview area */}
                    <div className="evaluation-preview-panel lg:col-span-2">
                        <div className="evaluation-preview-toolbar">
                            <div className="evaluation-preview-heading">
                                <span className="evaluation-preview-heading-icon"><Eye size={20} /></span>
                                <div>
                                    <span className="evaluation-preview-eyebrow">Questionnaire simulator</span>
                                    <h2>Evaluation Preview</h2>
                                    <p>Test the form and see how each rating affects the computed result.</p>
                                </div>
                            </div>
                            <div className="evaluation-preview-picker">
                                <label htmlFor="evaluation-preview-form">Preview form</label>
                                <select
                                    id="evaluation-preview-form"
                                    value={previewQuestionnaireType}
                                    onChange={(e) => {
                                        setPreviewQuestionnaireType(e.target.value);
                                        setPreviewResponses({});
                                    }}
                                >
                                    <optgroup label="Evaluation Questionnaires">
                                        {questionnaireTypeOptions.filter((option) => !option.key.startsWith('self_')).map((option) => (
                                            <option key={option.key} value={option.key}>{option.label}</option>
                                        ))}
                                    </optgroup>
                                    <optgroup label="Self Evaluation Questionnaires">
                                        {questionnaireTypeOptions.filter((option) => option.key.startsWith('self_')).map((option) => (
                                            <option key={option.key} value={option.key}>{option.label}</option>
                                        ))}
                                    </optgroup>
                                </select>
                            </div>
                        </div>

                        {isSelfEvaluationPreview ? (
                            <SelfEvaluationModule
                                key={`preview-${previewQuestionnaireType}`}
                                role={{ key: 'admin', user: { name: '', email: '', department: '' } }}
                                initialTargetRole={previewQuestionnaireType === 'self_admin' ? 'dean' : 'faculty'}
                            targetRoleOptions={previewQuestionnaireType === 'self_admin' ? leadershipSelfEvaluationOptions : facultySelfEvaluationOptions}
                            displayMode="preview"
                            templateOverride={selfQuestionnaireDrafts[previewQuestionnaireType]}
                        />
                        ) : previewQuestionnaire && previewQuestionnaire.categories.length > 0 ? (
                            <EvaluationModal
                                embeddedPreview
                                categoriesOverride={previewQuestionnaire.categories}
                                evaluation={{
                                    questionnaireType: previewQuestionnaireType,
                                    role: previewQuestionnaireType === 'admin' ? 'dean' : 'faculty',
                                    fullName: 'Questionnaire Preview',
                                    evaluateeName: 'Questionnaire Preview',
                                    position: previewQuestionnaireType === 'admin' ? 'Administrative Personnel' : 'Faculty Member',
                                    department: 'Preview Department',
                                    status: 'pending',
                                }}
                                evaluatorRole="dean"
                                period={{ period_name: 'Current Appraisal Period', is_open: true }}
                            />
                        ) : (
                            <div className="text-center py-16 text-gray-400 dark:text-gray-500">
                                <FileText size={32} className="mx-auto mb-3 opacity-50" />
                                <p className="text-sm">No questionnaire configured for preview.</p>
                                <p className="text-xs mt-1">Add categories and questions in the questionnaire tabs first.</p>
                            </div>
                        )}
                    </div>

                    {/* Score sidebar */}
                    <div className="preview-computation-card">
                        <div className="preview-computation-head">
                            <span className="preview-computation-icon">
                                <BarChart3 size={16} />
                            </span>
                            <div>
                                <h3>Real-Time Computation</h3>
                                <p>Live weighted score preview</p>
                            </div>
                        </div>

                        {isSelfEvaluationPreview ? (
                            <div className="preview-computation-empty">
                                <FileText size={28} />
                                <strong>PMAS Self Evaluation Preview</strong>
                                <p>The self-evaluation form uses its own table-based output, rating, confirmation, and career development computation sections.</p>
                            </div>
                        ) : previewEvaluation ? (
                            <div className="preview-computation-body">
                                {/* Score ring */}
                                <div className="preview-computation-hero">
                                    <div
                                        className="preview-live-score-ring"
                                        style={{ '--preview-score': `${Math.min(100, Math.max(0, previewEvaluation.percentage))}%` }}
                                    >
                                        <strong>{Math.round(previewEvaluation.finalScore * 10) / 10}</strong>
                                        <span>/5</span>
                                    </div>
                                    <div className="preview-score-copy">
                                        <span>Overall Score</span>
                                        <strong>{previewEvaluation.interpretation}</strong>
                                        <em>{Math.round(previewEvaluation.percentage)}% completion equivalent</em>
                                    </div>
                                </div>

                                <div className="preview-score-metrics">
                                    <article>
                                        <span>Weighted</span>
                                        <strong>{Math.round(previewEvaluation.finalScore * 100) / 100}</strong>
                                    </article>
                                    <article>
                                        <span>Percent</span>
                                        <strong>{Math.round(previewEvaluation.percentage)}%</strong>
                                    </article>
                                    <article>
                                        <span>Rated</span>
                                        <strong>
                                            {previewEvaluation.details.reduce((sum, d) => sum + d.responseCount, 0)}
                                            <small>/{previewEvaluation.details.reduce((sum, d) => sum + d.total, 0)}</small>
                                        </strong>
                                    </article>
                                </div>

                                {/* Category breakdown */}
                                <div className="preview-category-score-list">
                                    <div className="preview-category-score-title">
                                        <span>Category Breakdown</span>
                                        <strong>{previewEvaluation.details.length} areas</strong>
                                    </div>
                                    {previewEvaluation.details.map((d, i) => (
                                        <article
                                            key={i}
                                            className="preview-category-score-row"
                                            style={{ '--category-score': `${Math.min(100, Math.max(0, (d.average / 5) * 100))}%` }}
                                        >
                                            <div className="preview-category-score-copy">
                                                <span>{d.title}</span>
                                                <em>{d.responseCount}/{d.total} rated | {d.weight}% weight</em>
                                            </div>
                                            <strong>
                                                {Math.round(d.average * 10) / 10}
                                                <small>/5</small>
                                            </strong>
                                            <div className="preview-category-score-bar" aria-hidden="true">
                                                <span />
                                            </div>
                                        </article>
                                    ))}
                                </div>

                                <div className="preview-computation-footer">
                                    <CheckCircle2 size={15} />
                                    <span>Final Percentage</span>
                                    <strong>{Math.round(previewEvaluation.percentage)}%</strong>
                                </div>
                            </div>
                        ) : (
                            <div className="preview-computation-empty">
                                <BarChart3 size={26} />
                                <p>Rate at least one question to see the score computation.</p>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {selfMonitorEditRow && createPortal((
                <div className="dipascaf-modal-backdrop self-eval-task-backdrop" onClick={(event) => event.target === event.currentTarget && setSelfMonitorEditRow(null)}>
                    <div className="dipascaf-modal-panel eval-form-panel self-eval-task-panel" role="dialog" aria-modal="true" aria-label="View submitted faculty self evaluation">
                        <button
                            type="button"
                            className="dipascaf-modal-close"
                            onClick={() => setSelfMonitorEditRow(null)}
                            aria-label="Close self evaluation viewer"
                        >
                            <X size={18} />
                        </button>
                        <SelfEvaluationModule
                            key={`dean-self-edit-${selfMonitorEditRow.id}`}
                            role={{ key: 'admin', user: { name: '', email: '', department: selfMonitorEditRow.faculty_department || selfMonitorEditRow.department || '' } }}
                            initialTargetRole={selfMonitorEditRow.role === 'program_head' ? 'program_head' : 'faculty'}
                            targetRoleOptions={[{
                                value: selfMonitorEditRow.role === 'program_head' ? 'program_head' : 'faculty',
                                label: selfMonitorEditRow.role === 'program_head' ? 'Program Head Self Evaluation' : 'Faculty Self Evaluation',
                            }]}
                            assignmentId={selfMonitorEditRow.assignment_id}
                            managedRecordId={selfMonitorEditRow.id}
                            displayMode="managed_view"
                        />
                    </div>
                </div>
            ), document.body)}
        </section>
    );
}
