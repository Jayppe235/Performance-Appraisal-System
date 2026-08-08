import { assetUrl } from '../../data/apiBase.js';
import { isSelfEvaluationAssignment } from './selfEvaluationUtils.js';

function computeDeadlineInfo(deadline) {
  if (!deadline) return null;
  const now = new Date();
  now.setHours(0, 0, 0, 0);
  const due = new Date(deadline);
  due.setHours(0, 0, 0, 0);
  const diffDays = Math.round((due - now) / (1000 * 60 * 60 * 24));
  if (diffDays < 0) return { label: `Overdue by ${Math.abs(diffDays)}d`, variant: 'overdue', urgent: true };
  if (diffDays === 0) return { label: 'Due today', variant: 'due-today', urgent: true };
  if (diffDays <= 3) return { label: `${diffDays}d remaining`, variant: 'urgent', urgent: true };
  return { label: `${diffDays}d remaining`, variant: 'normal', urgent: false };
}

function formatRolePosition(role, position) {
  const cleanRole = String(role || '').trim();
  const cleanPosition = String(position || '').trim();
  if (!cleanRole) return cleanPosition || 'Role not set';
  if (!cleanPosition) return cleanRole;
  return cleanRole.toLowerCase() === cleanPosition.toLowerCase()
    ? cleanRole
    : `${cleanRole} - ${cleanPosition}`;
}

export default function EvaluationCard({ evaluation, onOpen, readOnly = false, periodLocked = false, busy = false }) {
  const done = evaluation.status === 'submitted';
  const overdue = evaluation.status === 'overdue';
  const inProgress = evaluation.status === 'in_progress' || evaluation.status === 'progress';
  const fullName = evaluation.fullName || evaluation.evaluateeName || 'Assigned Employee';
  const initial = fullName.charAt(0).toUpperCase();
  const isPeer = evaluation.section === 'peer';
  const isSelf = isSelfEvaluationAssignment(evaluation);
  // A submitted evaluation is complete even when its last saved draft
  // progress was lower or the questionnaire changed after submission.
  const progressPercent = done
    ? 100
    : Number.isFinite(Number(evaluation.progressPercent))
      ? Math.max(0, Math.min(100, Number(evaluation.progressPercent)))
      : 0;
  const deadlineLabel = evaluation.deadline || 'Not set';
  const actionLabel = periodLocked && !done
    ? 'Evaluation Locked'
    : busy
      ? 'Checking Form...'
    : readOnly
      ? (done ? (isSelf ? 'View Self Evaluation' : 'View Results') : 'Monitor Evaluation')
    : (done
      ? (isSelf ? 'View Self Evaluation' : 'View Submitted Evaluation')
      : inProgress
        ? (isSelf ? 'Continue Self Evaluation' : isPeer ? 'Continue Peer Evaluation' : 'Continue Evaluation')
        : isSelf ? 'Start Self Evaluation' : isPeer ? 'Start Peer Evaluation' : 'Start Evaluation');

  // Deadline urgency badge
  const deadlineInfo = computeDeadlineInfo(evaluation.deadline);

  // Compute filled levels for 1-5 rating display
  const score = evaluation.score || 0;
  const hasPreviousScore = evaluation.previousScore !== null && evaluation.previousScore !== undefined && Number.isFinite(Number(evaluation.previousScore));
  const scoreChange = Number(evaluation.scoreChange || 0);
  const levels = [1, 2, 3, 4, 5];

  const statusClass = done ? 'done' : overdue ? 'overdue' : inProgress ? 'in-progress' : 'pending';
  const statusLabel = done ? 'Completed' : overdue ? 'Overdue' : inProgress ? 'In Progress' : 'Pending';

  return (
    <article className={`dipascaf-eval-card ${statusClass} card-pop`}>
      <div className="dipascaf-card-cover" aria-hidden="true" />
      <div className="dipascaf-card-top">
        <div className="dipascaf-avatar">
          {evaluation.avatar ? <img src={assetUrl(evaluation.avatar)} alt={`${fullName} profile`} /> : initial}
        </div>
        <div className="dipascaf-card-badges">
          {deadlineInfo && !done && (
            <span className={`dipascaf-deadline-badge ${deadlineInfo.variant}`}>
              {deadlineInfo.label}
            </span>
          )}
          <span className={`dipascaf-status ${statusClass}`}>{statusLabel}</span>
        </div>
      </div>
      <h3>{fullName}</h3>
      <p>{formatRolePosition(evaluation.role, evaluation.position)}</p>
      <div className="dipascaf-card-meta">
        <span className="dipascaf-meta-row full" title={evaluation.department || 'Department not set'}>
          <small>Department</small>
          <strong>{evaluation.department || 'Department not set'}</strong>
        </span>
        <span className="dipascaf-meta-row" title={evaluation.program || 'Program not set'}>
          <small>Program</small>
          <strong>{evaluation.program || 'Program not set'}</strong>
        </span>
        <span className="dipascaf-meta-row dipascaf-deadline-line">
          <small>Deadline</small>
          <strong>{deadlineLabel}</strong>
          {deadlineInfo && !done && (
            <em className={`deadline-urgency ${deadlineInfo.variant}`}>{deadlineInfo.label}</em>
          )}
        </span>
        {evaluation.dateEvaluated && (
          <span className="dipascaf-meta-row">
            <small>Evaluated</small>
            <strong>{evaluation.dateEvaluated}</strong>
          </span>
        )}
      </div>
      <div className="dipascaf-progress-summary">
        <div>
          <small>Progress</small>
          <strong>{progressPercent}%</strong>
        </div>
        <div className="dipascaf-card-progress" aria-label={`Evaluation progress ${progressPercent}%`}>
          <span style={{ width: `${progressPercent}%` }} />
        </div>
      </div>
      {evaluation.relationshipTag && (
        <div className="peer-confidential-note">
          <strong>{evaluation.relationshipTag}</strong>
        </div>
      )}
      {isPeer && !readOnly && !evaluation.relationshipTag && (
        <div className="peer-confidential-note">
          <strong>Confidential peer task</strong>
          <span>Only your assigned peer is shown here.</span>
        </div>
      )}
      {done && (
        <div className="dipascaf-ai-mini">
          <strong>AI insights</strong>
          <div className="eval-card-rating">
            <div className="eval-card-rating-dots">
              {levels.map((level) => {
                const filled = score >= level;
                const partial = !filled && score >= level - 1 && score < level;
                const fillPct = partial ? Math.round((score - (level - 1)) * 100) : 0;
                return (
                  <div
                    key={level}
                    className={`eval-card-rating-dot ${filled ? 'filled' : partial ? 'partial' : 'empty'}`}
                    style={partial ? {
                      background: `linear-gradient(to top, #0f973d ${fillPct}%, #e2e8f0 ${fillPct}%)`,
                      color: fillPct >= 50 ? '#ffffff' : '#166534',
                    } : {}}
                    title={`Level ${level}`}
                  >
                    {level}
                  </div>
                );
              })}
            </div>
            <span className="eval-card-rating-score">{score.toFixed(2)}<small>/5</small></span>
          </div>
          {hasPreviousScore && (
            <div className="eval-card-comparison">
              <span>Previous: {Number(evaluation.previousScore).toFixed(2)} ({evaluation.previousPeriod || 'older period'})</span>
              <strong className={scoreChange > 0 ? 'up' : scoreChange < 0 ? 'down' : ''}>
                {scoreChange > 0 ? '+' : ''}{scoreChange.toFixed(2)}
              </strong>
            </div>
          )}
        </div>
      )}
      <div className="dipascaf-card-actions">
        <button type="button" className={periodLocked && !done ? 'dipascaf-locked-btn' : done ? 'dipascaf-view-btn' : 'dipascaf-evaluate-btn'} onClick={() => onOpen(evaluation)} disabled={busy}>
          {actionLabel}
        </button>
      </div>
    </article>
  );
}
