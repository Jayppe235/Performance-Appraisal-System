import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { AlertTriangle, Award, Brain, Building2, CheckCircle2, ChevronDown, ChevronLeft, ChevronRight, Eye, GraduationCap, Loader2, RefreshCw, TrendingDown, TrendingUp, Users, X } from 'lucide-react';
import apiFetch from '../../data/api.js';
import useLiveRefresh from '../../hooks/useLiveRefresh.js';

function scoreTone(score) {
  const value = Number(score || 0);
  if (value <= 3) return 'weak';
  if (value <= 3.75) return 'moderate';
  return 'strong';
}

function FieldScoreBar({ score }) {
  const numeric = Math.max(0, Math.min(5, Number(score || 0)));
  return (
    <div className={`department-ai-scorebar ${scoreTone(numeric)}`} aria-label={`Score ${numeric.toFixed(2)} out of 5`}>
      <span style={{ width: `${(numeric / 5) * 100}%` }} />
    </div>
  );
}

function groupRankedFields(fields) {
  const ranked = [...fields].sort((a, b) => Number(a.score || 0) - Number(b.score || 0));
  if (ranked.length === 0) {
    return {
      ranked,
      groups: [],
      weakest: null,
      strongest: null,
    };
  }

  const edgeCount = Math.max(1, Math.ceil(ranked.length * 0.25));
  const groups = [
    {
      key: 'critical',
      title: 'Fields Needing Attention',
      label: 'Critical (Bottom 25%)',
      icon: AlertTriangle,
      items: ranked.slice(0, edgeCount),
    },
    {
      key: 'moderate',
      title: 'Solid Performance',
      label: 'Moderate',
      icon: TrendingUp,
      items: ranked.slice(edgeCount, Math.max(edgeCount, ranked.length - edgeCount)),
    },
    {
      key: 'strong',
      title: 'Top Performers',
      label: 'Strong (Top 25%)',
      icon: CheckCircle2,
      items: ranked.slice(Math.max(edgeCount, ranked.length - edgeCount)),
    },
  ].filter((group) => group.items.length > 0);

  return {
    ranked,
    groups,
    weakest: ranked[0],
    strongest: ranked[ranked.length - 1],
  };
}

function normalizedOptionLabel(value) {
  return String(value || '').trim().toLowerCase();
}

function isAllProgramsLabel(value) {
  return normalizedOptionLabel(value).replace(/[^a-z0-9]+/g, ' ') === 'all programs';
}

function recommendedSessionForField(fieldName) {
  const key = normalizedOptionLabel(fieldName);
  if (key.includes('communication')) return 'Communication skills and constructive feedback workshop';
  if (key.includes('classroom')) return 'Classroom management and learner engagement seminar';
  if (key.includes('job knowledge') || key.includes('quality')) return 'Job knowledge and work excellence mentoring';
  if (key.includes('leadership') || key.includes('management')) return 'Leadership planning and management coaching';
  if (key.includes('teamwork') || key.includes('interpersonal')) return 'Team collaboration and interpersonal sensitivity seminar';
  if (key.includes('initiative') || key.includes('resourcefulness') || key.includes('creativity')) return 'Innovation, initiative, and resourcefulness workshop';
  if (key.includes('institutional')) return 'Institutional commitment and values alignment session';
  if (key.includes('commitment') || key.includes('responsibility')) return 'Professional responsibility and job commitment coaching';
  return `Targeted professional development session for ${fieldName}`;
}

function roleLabel(value) {
  if (value === 'program_head') return 'Program Head';
  if (value === 'dean') return 'Dean';
  return 'Faculty';
}

function recommendationTone(status) {
  const value = String(status?.recommendation_status || status || '').toLowerCase();
  if (value === 'final') return 'final';
  if (value === 'interim') return 'interim';
  return 'preliminary';
}

function recommendationStatusFromCounts(submitted, total) {
  const numericSubmitted = Math.max(0, Number(submitted || 0));
  const numericTotal = Math.max(0, Number(total || 0));
  const pct = numericTotal > 0 ? Math.round((numericSubmitted / numericTotal) * 100) : 0;
  const pending = Math.max(0, numericTotal - numericSubmitted);
  const status = numericTotal > 0 && numericSubmitted >= numericTotal
    ? 'final'
    : numericSubmitted > 0
      ? 'interim'
      : 'preliminary';
  const label = status === 'final'
    ? 'FINAL RECOMMENDATION'
    : status === 'interim'
      ? 'INTERIM RECOMMENDATION'
      : 'PRELIMINARY RECOMMENDATION';

  return {
    recommendation_status: status,
    submitted_count: numericSubmitted,
    total_assigned: numericTotal,
    pending_count: pending,
    completion_percentage: pct,
    caveat_text: status === 'final'
      ? `${label} - Based on complete evaluations.`
      : `${label} - ${pct}% complete; final recommendation may still change.`,
    pending_evaluators: [],
  };
}

function RecommendationStatusBanner({ status, compact = false }) {
  if (!status) return null;
  const tone = recommendationTone(status);
  const pct = Number(status.completion_percentage || 0);
  const pending = Number(status.pending_count || 0);
  const submitted = Number(status.submitted_count || 0);
  const total = Number(status.total_assigned || 0);
  const title = tone === 'final'
    ? 'Final Recommendation'
    : tone === 'interim'
      ? `Interim Recommendation - ${pct}% complete`
      : `Preliminary Recommendation - ${pct}% complete`;

  return (
    <div className={`ai-rec-status-banner ${tone} ${compact ? 'compact' : ''}`}>
      <div className="ai-rec-status-head">
        {tone === 'final' ? <CheckCircle2 size={15} /> : <AlertTriangle size={15} />}
        <strong>{title}</strong>
        <span>{submitted}/{total} submitted</span>
      </div>
      <div className="ai-rec-status-bar" aria-hidden="true">
        <span style={{ width: `${Math.max(0, Math.min(100, pct))}%` }} />
      </div>
      {tone !== 'final' && (
        <details className="ai-rec-pending-details">
          <summary>{pending} pending evaluator{pending === 1 ? '' : 's'} may change this recommendation</summary>
          <p>Pending evaluator names are available in faculty drill-down.</p>
        </details>
      )}
    </div>
  );
}

function SummaryMetric({ tone, icon: Icon, label, field }) {
  if (!field) return null;
  return (
    <div className={`department-ai-summary-tile ${tone}`}>
      <div className="department-ai-summary-icon"><Icon size={18} /></div>
      <div>
        <span>{label}</span>
        <strong title={field.name}>{field.name}</strong>
        <small>{Number(field.score || 0).toFixed(2)} / 5</small>
      </div>
    </div>
  );
}

function InsightAccordion({ title, meta, children, scrollable = false }) {
  const [open, setOpen] = useState(false);

  return (
    <section className={`department-ai-accordion ${open ? 'is-open' : ''}`}>
      <button
        type="button"
        className="department-ai-accordion-trigger"
        aria-expanded={open}
        onClick={() => setOpen((current) => !current)}
      >
        <span>
          <strong>{title}</strong>
          {meta && <small>{meta}</small>}
        </span>
        <ChevronDown size={19} aria-hidden="true" />
      </button>
      <div className="department-ai-accordion-collapse" aria-hidden={!open}>
        <div>
          <div className={`department-ai-accordion-content ${scrollable ? 'is-scrollable' : ''}`}>
            {children}
          </div>
        </div>
      </div>
    </section>
  );
}

function FieldBand({ group }) {
  const Icon = group.icon;
  return (
    <div className={`department-ai-field-band ${group.key}`}>
      <div className="department-ai-band-head">
        <div>
          <h4>{group.title}</h4>
          <span>{group.label}</span>
        </div>
        <Icon size={18} />
      </div>
      <div className="department-ai-field-list">
        {group.items.map((field) => (
          <div className="department-ai-field-row" key={field.name}>
            <div className="department-ai-field-copy">
              <strong title={field.name}>{field.name}</strong>
              <small>{Number(field.score || 0).toFixed(2)} / 5</small>
            </div>
            <FieldScoreBar score={field.score} />
          </div>
        ))}
      </div>
    </div>
  );
}

function FacultyList({ faculty = [] }) {
  if (!Array.isArray(faculty) || faculty.length === 0) {
    return (
      <div className="department-ai-faculty-list empty">
        <span>No faculty assigned to this program</span>
      </div>
    );
  }

  return (
    <div className="department-ai-faculty-list">
      <div className="department-ai-faculty-head">
        <span>Faculty</span>
        <small>{faculty.filter((item) => item.evaluated).length}/{faculty.length} evaluated</small>
      </div>
      <div className="department-ai-faculty-items">
        {faculty.map((item) => (
          <div className="department-ai-faculty-item" key={item.id || item.name}>
            <div className="department-ai-faculty-copy">
              <span>{item.name || 'Faculty Member'}</span>
              {item.evaluated && (
                <small>
                  Average {Number(item.averageScore || 0).toFixed(2)} / 5
                  {item.weakArea ? ` • Weak area: ${item.weakArea}` : ''}
                </small>
              )}
              {!item.evaluated && <small>No submitted result yet</small>}
              {item.recommendation && <small>Recommendation: {item.recommendation}</small>}
            </div>
            <small className={item.evaluated ? 'evaluated' : 'pending'}>{item.evaluated ? 'Evaluated' : 'Pending'}</small>
          </div>
        ))}
      </div>
    </div>
  );
}

function FacultyAnalysisDirectory({ faculty = [], onViewDetails }) {
  const rows = Array.isArray(faculty) ? faculty : [];

  return (
    <div className="program-ai-faculty-directory">
      {rows.length === 0 ? (
        <div className="eval-monitor-empty">No faculty members are assigned to this program.</div>
      ) : (
        <div className="program-ai-faculty-grid">
          {rows.map((member) => {
            const assignmentTotal = Number(member.assignmentTotal || 0);
            const assignmentCompleted = Number(member.assignmentCompleted || 0);
            const completion = assignmentTotal > 0 ? Math.round((assignmentCompleted / assignmentTotal) * 100) : 0;
            const average = member.averageScore !== null && member.averageScore !== undefined
              ? Number(member.averageScore || 0).toFixed(2)
              : 'N/A';
            const scorePercent = average === 'N/A' ? 0 : Math.max(0, Math.min(100, (Number(average) / 5) * 100));
            const weakArea = member.weakArea || 'No weak area yet';
            const recommendation = member.recommendation || (member.weakArea ? recommendedSessionForField(member.weakArea) : 'Awaiting submitted evaluation results');
            const strengths = Array.isArray(member.strengths) ? member.strengths : [];
            const weakAreas = Array.isArray(member.weakAreas) ? member.weakAreas : [];
            const scores = Array.isArray(member.scores) ? member.scores : [];

            return (
              <article className={`program-ai-faculty-card ${member.evaluated ? 'evaluated' : 'pending'}`} key={member.id || member.name}>
                <div className="program-ai-faculty-card-head">
                  <div className="eval-monitor-faculty-avatar">
                    {(member.name || 'F').charAt(0).toUpperCase()}
                  </div>
                  <div>
                    <h4>{member.name || 'Faculty Member'}</h4>
                    <span>
                      {member.role === 'program_head' ? 'Program Head' : member.role === 'dean' ? 'Dean' : 'Faculty'}
                      {member.program_code ? ` • ${member.program_code}` : ''}
                    </span>
                  </div>
                  <small className={member.evaluated ? 'evaluated' : 'pending'}>{member.evaluated ? 'Evaluated' : 'Pending'}</small>
                </div>

                <div className="program-ai-faculty-stats">
                  <div className="program-ai-score-block">
                    <span>Average Score</span>
                    <strong>{average}<small>/5</small></strong>
                    <div className="program-ai-mini-bar"><i style={{ width: `${scorePercent}%` }} /></div>
                  </div>
                  <div className="program-ai-score-block">
                    <span>Completion</span>
                    <strong>{completion}%<small>{assignmentCompleted}/{assignmentTotal || 0}</small></strong>
                    <div className="program-ai-mini-bar completion"><i style={{ width: `${completion}%` }} /></div>
                  </div>
                </div>

                <div className="program-ai-faculty-analysis">
                  <div className="program-ai-analysis-item weak">
                    <span><AlertTriangle size={14} /> Weak Area</span>
                    <strong>{weakArea}</strong>
                  </div>
                  <div className="program-ai-analysis-item strength">
                    <span><Award size={14} /> Strength</span>
                    <strong>{member.strongArea || strengths[0]?.name || 'Awaiting results'}</strong>
                  </div>
                  <div className="program-ai-analysis-item recommendation">
                    <span><Brain size={14} /> AI Recommendation</span>
                    <p>{recommendation}</p>
                  </div>
                </div>

                {member.evaluated && (
                  <div className="program-ai-faculty-detail">
                    <div>
                      <span>Weak Areas</span>
                      <div className="program-ai-chip-list">
                        {(weakAreas.length > 0 ? weakAreas : [{ name: weakArea, score: member.weakScore }]).slice(0, 3).map((item) => (
                          <small className="weak" key={`weak-${member.id}-${item.name}`}>
                            <span>{item.name}</span>
                            {item.score ? <b>{Number(item.score).toFixed(2)}</b> : null}
                          </small>
                        ))}
                      </div>
                    </div>
                    <div>
                      <span>Strengths</span>
                      <div className="program-ai-chip-list">
                        {(strengths.length > 0 ? strengths : [{ name: member.strongArea, score: member.strongScore }]).filter((item) => item.name).slice(0, 3).map((item) => (
                          <small className="strong" key={`strong-${member.id}-${item.name}`}>
                            <span>{item.name}</span>
                            {item.score ? <b>{Number(item.score).toFixed(2)}</b> : null}
                          </small>
                        ))}
                      </div>
                    </div>
                  </div>
                )}
                <button className="program-ai-view-details" type="button" onClick={() => onViewDetails?.(member)}>
                  <Eye size={14} /> View Details
                </button>
              </article>
            );
          })}
        </div>
      )}
    </div>
  );
}

function FacultyDetailsPanel({ faculty, onClose }) {
  const panelRef = useRef(null);
  const onCloseRef = useRef(onClose);

  useEffect(() => {
    onCloseRef.current = onClose;
  }, [onClose]);

  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    const previouslyFocused = document.activeElement;
    const handleKeyDown = (event) => {
      if (event.key === 'Escape') onCloseRef.current?.();
    };

    document.body.style.overflow = 'hidden';
    window.addEventListener('keydown', handleKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', handleKeyDown);
      previouslyFocused?.focus?.({ preventScroll: true });
    };
  }, []);

  useEffect(() => {
    if (!faculty) return;

    window.requestAnimationFrame(() => {
      const panel = panelRef.current;
      if (!panel) return;
      panel.scrollTop = 0;
      panel.focus({ preventScroll: true });
    });
  }, [faculty]);

  if (!faculty) return null;

  const scores = Array.isArray(faculty.scores) ? faculty.scores : [];
  const weakAreas = Array.isArray(faculty.weakAreas) ? faculty.weakAreas : [];
  const strengths = Array.isArray(faculty.strengths) ? faculty.strengths : [];
  const assignmentTotal = Number(faculty.assignmentTotal || 0);
  const assignmentCompleted = Number(faculty.assignmentCompleted || 0);
  const pending = Math.max(0, assignmentTotal - assignmentCompleted);
  const completion = assignmentTotal > 0 ? Math.round((assignmentCompleted / assignmentTotal) * 100) : 0;
  const average = faculty.averageScore !== null && faculty.averageScore !== undefined
    ? Number(faculty.averageScore || 0)
    : 0;
  const recommendation = faculty.recommendation || (faculty.weakArea ? recommendedSessionForField(faculty.weakArea) : 'Awaiting submitted evaluator Form A and Form B results.');
  const formLabel = faculty.formType || (faculty.role === 'dean' || faculty.role === 'program_head' ? 'Form A' : 'Form B');

  return createPortal(
    <div className="program-ai-faculty-modal" role="dialog" aria-modal="true" aria-label={`${faculty.name || 'Faculty'} AI analysis details`}>
      <div className="program-ai-faculty-modal-panel" ref={panelRef} tabIndex={-1}>
        <div className="program-ai-faculty-modal-head">
          <div className="program-ai-modal-profile">
            <div className="program-ai-modal-avatar">{(faculty.name || 'F').charAt(0).toUpperCase()}</div>
            <div>
              <span className="program-ai-modal-eyebrow">Faculty Performance Profile</span>
              <h3>{faculty.name || 'Faculty Member'}</h3>
              <p>{faculty.role === 'program_head' ? 'Program Head' : 'Faculty'} • {faculty.department || 'Department'}{faculty.program_code ? ` / ${faculty.program_code}` : ''}</p>
            </div>
          </div>
          <button type="button" onClick={onClose} aria-label="Close faculty details"><X size={20} /></button>
        </div>

        <div className="program-ai-faculty-modal-score">
          <div className="program-ai-modal-score-overview">
            <div className="program-ai-score-ring" style={{ '--score-pct': `${Math.max(0, Math.min(100, (average / 5) * 100))}%` }}>
              <strong>{average ? average.toFixed(2) : 'N/A'}</strong>
              <span>/5.0</span>
            </div>
            <small>Overall Score</small>
          </div>
          <div className="program-ai-modal-pills">
            <span className="assignments"><Users size={16} /><b>{assignmentTotal}</b> Assignments</span>
            <span className="completed"><CheckCircle2 size={16} /><b>{assignmentCompleted}</b> Completed</span>
            <span className="pending"><AlertTriangle size={16} /><b>{pending}</b> Pending</span>
          </div>
        </div>

        <div className="program-ai-modal-recommendation">
          <div className="program-ai-modal-recommendation-head">
            <span><GraduationCap size={18} /></span>
            <div>
              <small>APPRAISIA Recommendation</small>
              <strong>{completion >= 100 ? `Final ${formLabel} Recommendation` : `Interim ${formLabel} Recommendation - ${completion}% complete`}</strong>
            </div>
          </div>
          <div className="program-ai-modal-bar"><span style={{ width: `${completion}%` }} /></div>
          {pending > 0 && <small>{pending} pending evaluator{pending === 1 ? '' : 's'} may change this recommendation</small>}
          <p><GraduationCap size={16} /> {recommendation}</p>
        </div>

        <div className="program-ai-modal-split">
          <section className="weak-areas">
            <h4><AlertTriangle size={18} /> Weak Areas</h4>
            {(weakAreas.length > 0 ? weakAreas : [{ name: faculty.weakArea, score: faculty.weakScore }]).filter((item) => item.name).map((item) => (
              <div className="program-ai-score-row" key={`modal-weak-${item.name}`}>
                <strong>{item.name}</strong>
                <small>{item.score ? Number(item.score).toFixed(2) : ''}/5</small>
              </div>
            ))}
          </section>
          <section className="strengths">
            <h4><Award size={18} /> Strengths</h4>
            {(strengths.length > 0 ? strengths : [{ name: faculty.strongArea, score: faculty.strongScore }]).filter((item) => item.name).map((item) => (
              <div className="program-ai-score-row" key={`modal-strong-${item.name}`}>
                <strong>{item.name}</strong>
                <small>{item.score ? Number(item.score).toFixed(2) : ''}/5</small>
              </div>
            ))}
          </section>
        </div>

        <section className="program-ai-modal-categories">
          <div className="program-ai-modal-section-heading">
            <span><TrendingUp size={18} /></span>
            <div>
              <h4>Category Scores</h4>
              <small>Detailed performance across evaluated fields</small>
            </div>
          </div>
          {scores.length === 0 ? (
            <div className="eval-monitor-empty">No evaluator {formLabel} category scores yet.</div>
          ) : (
            <div className="program-ai-modal-category-grid">
              {scores.map((score) => (
                <article className="program-ai-modal-category-card" key={`modal-score-${score.name}`}>
                  <strong>{score.name}</strong>
                  <span><b>{Number(score.score || 0).toFixed(2)}</b><small>/ 5.00</small></span>
                  <FieldScoreBar score={score.score} />
                  <small>{score.seminar || recommendedSessionForField(score.name)}</small>
                </article>
              ))}
            </div>
          )}
        </section>
      </div>
    </div>,
    document.body
  );
}

export default function DepartmentAiInsights({ scope = 'all' }) {
  const [programFilter, setProgramFilter] = useState('');
  const [programData, setProgramData] = useState([]);
  const [analysisSummary, setAnalysisSummary] = useState(null);
  const [periods, setPeriods] = useState([]);
  const [selectedPeriodId, setSelectedPeriodId] = useState('');
  const [departmentFilter, setDepartmentFilter] = useState('');
  const [roleFilter, setRoleFilter] = useState('');
  const [monitorView, setMonitorView] = useState('departments');
  const [selectedDepartmentCode, setSelectedDepartmentCode] = useState('');
  const [selectedProgramId, setSelectedProgramId] = useState('');
  const [selectedFaculty, setSelectedFaculty] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const isScoped = scope !== 'all';

  useEffect(() => {
    let alive = true;

    async function loadPeriods() {
      try {
        const payload = await apiFetch('/api/evaluation-period.php?action=periods');
        if (!alive) return;
        const list = Array.isArray(payload.data) ? payload.data : [];
        setPeriods(list);
        const currentId = payload.current?.id ? String(payload.current.id) : '';
        setSelectedPeriodId((value) => value || currentId || (list[0]?.id ? String(list[0].id) : ''));
      } catch {
        if (alive) setPeriods([]);
      }
    }

    loadPeriods();
    return () => {
      alive = false;
    };
  }, []);

  const loadProgramAnalysis = useCallback(async (background = false) => {
    if (!background) setLoading(true);
    setError('');

    try {
      const params = new URLSearchParams();
      if (isScoped) params.set('scope', scope);
      if (selectedPeriodId) params.set('period_id', selectedPeriodId);
      if (!isScoped && roleFilter) params.set('role', roleFilter);
      const query = params.toString() ? `?${params.toString()}` : '';
      const payload = await apiFetch(`/api/department-analysis.php${query}`);
      setProgramData(Array.isArray(payload.data) ? payload.data : []);
      setAnalysisSummary(payload.summary || null);
      return payload;
    } catch (err) {
      setError(err.message || 'Unable to load program analysis.');
      if (!background) {
        setProgramData([]);
        setAnalysisSummary(null);
      }
      return null;
    } finally {
      if (!background) setLoading(false);
    }
  }, [isScoped, roleFilter, scope, selectedPeriodId]);

  const { refreshing: aiRefreshing, refresh: refreshAIAnalysis } = useLiveRefresh(
    loadProgramAnalysis,
    [isScoped, scope, selectedPeriodId, roleFilter],
    { intervalMs: 9000 }
  );

  const selectedPeriod = useMemo(
    () => periods.find((period) => String(period.id) === String(selectedPeriodId)) || null,
    [periods, selectedPeriodId]
  );
  const securedScope = analysisSummary?.scope || null;
  const scopeRole = securedScope?.role || '';
  const scopeLabel = securedScope?.label || scope;
  const isProgramHeadScope = scopeRole === 'program_head';
  const scopeTitle = scopeRole === 'program_head' ? 'Program Scope' : scopeRole === 'vpaa' ? 'Institution Scope' : 'Department Scope';
  const showProgramFilter = scopeRole !== 'vpaa';
  const pageTitle = isProgramHeadScope ? 'Program AI Analysis' : 'Department AI Analysis';
  const pageDescription = isProgramHeadScope
    ? 'Review individual faculty weak areas, strengths, scores, and recommendations first, then inspect the overall program trend and development actions.'
    : 'Review individual faculty weak areas, strengths, scores, and recommendations first, then inspect the overall department and program performance summary.';

  // Derive unique departments present in the program data
  const availableDepartments = useMemo(() => {
    const seen = new Set();
    const result = [];
    for (const item of programData) {
      const code = item.department_code;
      const name = item.department_name;
      if (!code || seen.has(code)) continue;
      seen.add(code);
      result.push({ code, name });
    }
    // Sort by name
    result.sort((a, b) => (a.name || a.code).localeCompare(b.name || b.code));
    return result;
  }, [programData]);

  // Filter programs by selected department
  const filteredProgramOptions = useMemo(() => {
    if (!departmentFilter) return programData;
    return programData.filter((item) => item.department_code === departmentFilter);
  }, [programData, departmentFilter]);

  const programOptions = useMemo(() => {
    const seen = new Set();
    const options = [];

    for (const item of filteredProgramOptions) {
      const label = String(item.program || '').trim();
      if (!label || isAllProgramsLabel(label)) continue;

      const key = normalizedOptionLabel(label);
      if (seen.has(key)) continue;
      seen.add(key);
      options.push(item);
    }

    return options;
  }, [filteredProgramOptions]);

  // Compute visible insights: department filter + program filter
  const insights = useMemo(() => {
    let filtered = isScoped ? programData : filteredProgramOptions;
    if (programFilter) {
      filtered = filtered.filter((item) => item.program === programFilter);
    }
    return filtered;
  }, [filteredProgramOptions, programData, programFilter, isScoped]);

  const scopedFacultyRows = useMemo(() => (
    insights.flatMap((program) => (
      (Array.isArray(program.faculty) ? program.faculty : []).map((member) => ({
        ...member,
        program_code: member.program_code || program.program_code || '',
        program_name: member.program_name || program.program || '',
        department: member.department || program.department_name || '',
      }))
    ))
  ), [insights]);

  const programScopeLabel = useMemo(() => {
    if (!isProgramHeadScope) return scopeLabel;
    const names = Array.from(new Set(insights.map((item) => item.program).filter(Boolean)));
    return names.length > 0 ? names.join(', ') : scopeLabel;
  }, [insights, isProgramHeadScope, scopeLabel]);

  const departmentGroups = useMemo(() => {
    const groups = new Map();

    for (const program of insights) {
      const departmentCode = program.department_code || 'unassigned';
      const departmentName = program.department_name || 'Unassigned Department';
      if (!groups.has(departmentCode)) {
        groups.set(departmentCode, {
          code: departmentCode,
          name: departmentName,
          programs: [],
          facultyCount: 0,
          evaluatedCount: 0,
          completeCount: 0,
          fieldBuckets: new Map(),
        });
      }

      const group = groups.get(departmentCode);
      group.programs.push(program);
      group.facultyCount += Number(program.facultyCount || 0);
      group.evaluatedCount += Number(program.evaluatedCount || 0);
      group.completeCount += Number(program.completeCount ?? program.evaluatedCount ?? 0);

      for (const field of program.fields || []) {
        const fieldName = String(field.name || '').trim();
        if (!fieldName) continue;
        const current = group.fieldBuckets.get(fieldName) || {
          name: fieldName,
          scoreTotal: 0,
          count: 0,
          seminar: field.seminar || recommendedSessionForField(fieldName),
        };
        const resultCount = Math.max(1, Number(field.resultCount || 1));
        current.scoreTotal += Number(field.score || 0) * resultCount;
        current.count += resultCount;
        if (!current.seminar && field.seminar) current.seminar = field.seminar;
        group.fieldBuckets.set(fieldName, current);
      }
    }

    return [...groups.values()].map((department) => {
      const isComplete = Number(department.facultyCount || 0) > 0
        && Number(department.completeCount || 0) >= Number(department.facultyCount || 0);
      const fields = [...department.fieldBuckets.values()]
        .filter((field) => field.count > 0)
        .map((field) => ({
          name: field.name,
          score: field.scoreTotal / field.count,
          seminar: field.seminar || recommendedSessionForField(field.name),
        }))
        .sort((a, b) => a.score - b.score);
      const weakest = fields[0] || null;
      const strongest = fields[fields.length - 1] || null;

      return {
        ...department,
        fieldBuckets: undefined,
        isComplete,
        departmentFields: fields,
        recommendation: weakest
          ? {
              weakAreas: fields.slice(0, 3).map((field) => ({
                name: field.name,
                score: field.score,
              })),
              weakArea: weakest.name,
              weakScore: weakest.score,
              strongArea: strongest?.name || '',
              seminar: weakest.seminar,
              isPreliminary: !isComplete,
            }
          : null,
      };
    }).sort((a, b) => a.name.localeCompare(b.name));
  }, [insights]);

  const selectedDepartment = useMemo(
    () => departmentGroups.find((department) => department.code === selectedDepartmentCode) || null,
    [departmentGroups, selectedDepartmentCode]
  );

  const selectedProgram = useMemo(
    () => selectedDepartment?.programs.find((program) => String(program.id) === String(selectedProgramId)) || null,
    [selectedDepartment, selectedProgramId]
  );

  useEffect(() => {
    setMonitorView('departments');
    setSelectedDepartmentCode('');
    setSelectedProgramId('');
    setSelectedFaculty(null);
  }, [departmentFilter, programFilter, roleFilter, selectedPeriodId]);

  // Reset program filter when department changes
  function handleDepartmentChange(value) {
    setDepartmentFilter(value);
    setProgramFilter('');
  }

  function completionPercent(completed, total) {
    const numericTotal = Number(total || 0);
    if (numericTotal <= 0) return 0;
    return Math.round((Number(completed || 0) / numericTotal) * 100);
  }

  function MiniProgress({ completed, total }) {
    const pct = completionPercent(completed, total);
    return (
      <div className="eval-monitor-bar-wrapper">
        <div className="eval-monitor-bar" role="progressbar" aria-label="Evaluation completion" aria-valuemin="0" aria-valuemax="100" aria-valuenow={pct}><span style={{ width: `${pct}%` }} /></div>
        <small>{pct}% complete</small>
      </div>
    );
  }

  function recommendationStatusLabel(evaluated, total) {
    const pct = completionPercent(evaluated, total);
    return pct >= 100 ? 'Final PMAS recommendation' : `Preliminary PMAS recommendation - ${pct}% evaluated`;
  }

  function renderProgramAnalysis(program) {
    if (!program?.fields || program.fields.length === 0) {
      return (
        <div className="eval-monitor-empty">No submitted Form A or Form B category results are available for this program yet.</div>
      );
    }

    const ranked = [...program.fields].sort((a, b) => a.score - b.score);
    const { groups, weakest, strongest } = groupRankedFields(ranked);
    const completionCounts = displayCompletionCounts(program);
    const completion = completionPercent(completionCounts.completed, completionCounts.total);

    return (
      <article className="department-ai-card">
        <div className="department-ai-card-head department-ai-executive-head">
          <div>
            <span>Program performance overview</span>
            <h3>{program.program}</h3>
            <small className="department-ai-subtitle">{program.department_name}</small>
          </div>
          <div className="department-ai-head-status"><Brain size={18} /><span>AI-assisted analysis</span></div>
        </div>

        <div className="department-ai-executive-kpis">
          <div className="department-ai-kpi evaluated">
            <span><Users size={16} /> Evaluated faculty</span>
            <strong>{program.evaluatedCount}<small> of {program.facultyCount}</small></strong>
          </div>
          <div className="department-ai-kpi completion">
            <span><CheckCircle2 size={16} /> Evaluation completion</span>
            <strong>{completion}%</strong>
            <div role="progressbar" aria-label="Program evaluation completion" aria-valuemin="0" aria-valuemax="100" aria-valuenow={completion}><i style={{ width: `${completion}%` }} /></div>
          </div>
          <SummaryMetric tone="weak" icon={TrendingDown} label="Weakest Field" field={weakest} />
          <SummaryMetric tone="strong" icon={TrendingUp} label="Strongest Field" field={strongest} />
        </div>

        <div className="department-ai-insight-strip">
          <span>{recommendationStatusLabel(program.evaluatedCount ?? 0, program.facultyCount)}</span>
          <strong>{program.weakAreaCount || 0} weak areas</strong>
          <small>{program.interventionCount || 0} interventions</small>
        </div>

        <InsightAccordion title="Field Rankings" meta={`${ranked.length} evaluation fields`} scrollable>
          <div className="department-ai-ranking">
            {groups.map((group) => <FieldBand key={group.key} group={group} />)}
          </div>
        </InsightAccordion>

        <InsightAccordion title="Recommended PMAS Action" meta="Suggested development action">
          <div className="department-ai-recommendation">
            <GraduationCap className="h-5 w-5" />
            <div>
              <span>Recommended PMAS Action</span>
              <strong>{weakest.seminar}</strong>
              <p><b>Focus:</b> strengthen {weakest.name.toLowerCase()} first based on submitted evaluations. <b>Opportunity:</b> use {strongest.name.toLowerCase()} as this program's current advantage.</p>
            </div>
          </div>
        </InsightAccordion>
      </article>
    );
  }

  function renderProgramHeadAnalysis(program) {
    const totalFaculty = Number(program?.facultyCount || 0);
    const evaluatedFaculty = Number(program?.evaluatedCount || 0);
    const assignmentCounts = displayCompletionCounts(program);
    const completionBaseCompleted = assignmentCounts.total > 0 ? assignmentCounts.completed : evaluatedFaculty;
    const completionBaseTotal = assignmentCounts.total > 0 ? assignmentCounts.total : totalFaculty;
    const pendingFaculty = Math.max(0, totalFaculty - evaluatedFaculty);

    return (
      <article className="eval-monitor-dept-card program-ai-analysis-card" key={program.id || program.program_code}>
        <div className="eval-monitor-dept-card-header">
          <div className="eval-monitor-dept-card-icon">
            <Building2 size={22} />
          </div>
          <div>
            <h3>{program.program || programScopeLabel}</h3>
            <span className="eval-monitor-dept-code">{program.program_code || programScopeLabel}</span>
          </div>
        </div>
        <div className="eval-monitor-dept-card-body">
          <div className="eval-monitor-dept-card-meta">
            <span><Users size={14} /> {totalFaculty} Faculty</span>
            <span><Award size={14} /> Program Scope</span>
          </div>
          <div className="eval-monitor-dept-card-stats">
            <div className="eval-monitor-dept-stat">
              <span>Evaluated Faculty</span>
              <strong className="text-success">{evaluatedFaculty}</strong>
            </div>
            <div className="eval-monitor-dept-stat">
              <span>Pending Evaluations</span>
              <strong className="text-warning">{pendingFaculty}</strong>
            </div>
            <div className="eval-monitor-dept-stat">
              <span>Total Faculty</span>
              <strong>{totalFaculty}</strong>
            </div>
          </div>
          <MiniProgress completed={completionBaseCompleted} total={completionBaseTotal} />
          {renderCompactAiRecommendation(program, 'program')}
        </div>
      </article>
    );
  }

  function openDepartment(department) {
    setSelectedDepartmentCode(department.code);
    setSelectedProgramId('');
    setMonitorView('programs');
  }

  function openProgram(program) {
    setSelectedProgramId(String(program.id));
    setMonitorView('faculty');
  }

  function goBack() {
    if (monitorView === 'faculty') {
      setSelectedProgramId('');
      setMonitorView('programs');
      return;
    }
    setSelectedDepartmentCode('');
    setMonitorView('departments');
  }

  function recommendationSubmissionCounts(scopeItem) {
    const programs = Array.isArray(scopeItem.programs) && scopeItem.programs.length > 0
      ? scopeItem.programs
      : [scopeItem];
    const facultyRows = programs.flatMap((program) => Array.isArray(program.faculty) ? program.faculty : []);

    const assignmentTotals = facultyRows.reduce(
      (totals, faculty) => ({
        completed: totals.completed + Number(faculty.assignmentCompleted || 0),
        total: totals.total + Number(faculty.assignmentTotal || 0),
      }),
      { completed: 0, total: 0 }
    );

    if (assignmentTotals.total > 0) {
      return assignmentTotals;
    }

    return {
      completed: Number(scopeItem.evaluatedCount ?? scopeItem.completeCount ?? 0),
      total: Number(scopeItem.facultyCount || 0),
    };
  }

  function displayCompletionCounts(scopeItem) {
    const assignmentCounts = recommendationSubmissionCounts(scopeItem);
    if (assignmentCounts.total > 0) {
      return assignmentCounts;
    }
    return {
      completed: Number(scopeItem.evaluatedCount ?? scopeItem.completeCount ?? 0),
      total: Number(scopeItem.facultyCount || 0),
    };
  }

  function roleRecommendationSummaries(scopeItem) {
    const programs = Array.isArray(scopeItem.programs) && scopeItem.programs.length > 0
      ? scopeItem.programs
      : [scopeItem];
    const facultyRows = programs.flatMap((program) => Array.isArray(program.faculty) ? program.faculty : []);

    return ['program_head', 'teacher'].map((role) => {
      const roleRows = facultyRows.filter((faculty) => (faculty.role || 'teacher') === role);
      const recommendationsForRole = roleRows.flatMap((faculty) => (
        Array.isArray(faculty.recommendations) ? faculty.recommendations : []
      ));
      const firstRecommendation = recommendationsForRole[0] || null;
      const fallbackWeakArea = roleRows
        .flatMap((faculty) => Array.isArray(faculty.weakAreas) ? faculty.weakAreas : [])
        .sort((a, b) => Number(a.score || 0) - Number(b.score || 0))[0] || null;
      const focus = firstRecommendation?.weak_category || fallbackWeakArea?.name || '';

      if (!firstRecommendation && !focus) return null;

      return {
        role,
        label: roleLabel(role),
        form: role === 'program_head' ? 'Form A' : 'Form B',
        title: firstRecommendation?.recommendation_title || recommendedSessionForField(focus),
        focus,
      };
    }).filter(Boolean);
  }

  function renderCompactAiRecommendation(scopeItem, scopeLabel = 'department') {
    const fields = scopeItem.departmentFields || scopeItem.fields || [];
    const weakAreas = scopeItem.recommendation?.weakAreas || [...fields]
      .sort((a, b) => Number(a.score || 0) - Number(b.score || 0))
      .slice(0, 3)
      .map((field) => ({ name: field.name, score: field.score }));
    const weakest = weakAreas[0] || null;
    const focusAreas = weakAreas.slice(0, 2).map((field) => field.name).join(', ');
    const submissionCounts = displayCompletionCounts(scopeItem);
    const status = recommendationStatusFromCounts(submissionCounts.completed, submissionCounts.total);
    const caveat = status.caveat_text;
    const roleSummaries = roleRecommendationSummaries(scopeItem);

    return (
      <div className="eval-monitor-dept-ai-recommendation" onClick={(event) => event.stopPropagation()}>
        <div className="eval-monitor-dept-ai-recommendation-head">
          <Brain size={15} />
          <span>{scopeLabel === 'program' ? 'Program AI Recommendation' : 'Department AI Recommendation'}</span>
        </div>
        <RecommendationStatusBanner status={status} compact />
        {weakest ? (
          <>
            <strong>{recommendedSessionForField(weakest.name)}</strong>
            <p>
              {caveat} Focus: {focusAreas || weakest.name}.
            </p>
            {roleSummaries.length > 0 && (
              <div className="department-ai-role-recommendations">
                {roleSummaries.map((item) => (
                  <div key={item.role} className="department-ai-role-recommendation">
                    <span>{item.label} · {item.form}</span>
                    <strong>{item.title}</strong>
                    {item.focus && <small>Focus: {item.focus}</small>}
                  </div>
                ))}
              </div>
            )}
          </>
        ) : (
          <p>{caveat || `No priority weak area was detected for this ${scopeLabel} yet.`}</p>
        )}
      </div>
    );
  }

  function renderDepartments() {
    const layoutClass = departmentGroups.length === 1
      ? 'is-single'
      : departmentGroups.length === 2
      ? 'is-double'
      : 'is-multiple';
    return (
      <div className={`eval-monitor-table-container department-ai-department-results ${layoutClass}`}>
        <div className={`eval-monitor-dept-grid ${layoutClass}`}>
          {departmentGroups.map((department) => {
            const completionCounts = displayCompletionCounts(department);
            return (
              <div
                key={department.code}
                className={`eval-monitor-dept-card ${layoutClass === 'is-single' ? 'is-featured' : ''}`}
                role="button"
                tabIndex={0}
                onClick={() => openDepartment(department)}
                onKeyDown={(event) => {
                  if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openDepartment(department);
                  }
                }}
                aria-label={`Open ${department.name} AI analysis`}
              >
                <div className="eval-monitor-dept-card-header">
                  <div className="eval-monitor-dept-card-icon">
                    <Building2 size={22} />
                  </div>
                  <div>
                    <h3>{department.name}</h3>
                    <span className="eval-monitor-dept-code">{department.code}</span>
                  </div>
                  <ChevronRight size={20} className="eval-monitor-chevron" />
                </div>
                <div className="eval-monitor-dept-card-body">
                  <div className="eval-monitor-dept-card-meta">
                    <span><Award size={14} /> {department.programs.length} Programs</span>
                    <span><Users size={14} /> {department.facultyCount} Faculty</span>
                  </div>
                  <div className="eval-monitor-dept-card-stats">
                    <div className="eval-monitor-dept-stat">
                      <span>Evaluated</span>
                      <strong className="text-success">{completionCounts.completed}</strong>
                    </div>
                    <div className="eval-monitor-dept-stat">
                      <span>Pending</span>
                      <strong className="text-warning">{Math.max(0, completionCounts.total - completionCounts.completed)}</strong>
                    </div>
                    <div className="eval-monitor-dept-stat">
                      <span>Programs</span>
                      <strong>{department.programs.length}</strong>
                    </div>
                    <div className="eval-monitor-dept-stat">
                      <span>Faculty</span>
                      <strong>{department.facultyCount}</strong>
                    </div>
                  </div>
                  <MiniProgress completed={completionCounts.completed} total={completionCounts.total} />
                  {renderCompactAiRecommendation(department, 'department')}
                </div>
              </div>
            );
          })}
        </div>
      </div>
    );
  }

  function renderPrograms() {
    if (!selectedDepartment) return null;
    return (
      <>
        <div className="eval-monitor-breadcrumb">
          <button type="button" onClick={goBack}>
            <ChevronLeft size={16} /> All Departments
          </button>
          <span>/</span>
          <span>{selectedDepartment.name}</span>
        </div>
        <div className="eval-monitor-hero compact">
          <div>
            <p className="eyebrow">Programs</p>
            <h2>{selectedDepartment.name}</h2>
            <p>{selectedDepartment.programs.length} program{selectedDepartment.programs.length !== 1 ? 's' : ''} under this department.</p>
          </div>
        </div>
        <div className="eval-monitor-table-container">
          <div className="eval-monitor-program-grid">
            {selectedDepartment.programs.map((program) => {
              const completionCounts = displayCompletionCounts(program);
              return (
                <div
                  key={program.id}
                  className="eval-monitor-program-card"
                  role="button"
                  tabIndex={0}
                  onClick={() => openProgram(program)}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                      event.preventDefault();
                      openProgram(program);
                    }
                  }}
                  aria-label={`Open ${program.program} AI analysis`}
                >
                  <div className="eval-monitor-program-card-header">
                    <div className="eval-monitor-program-card-icon">
                      <Award size={20} />
                    </div>
                    <div>
                      <h3>{program.program}</h3>
                      <span className="eval-monitor-dept-code">{program.program_code}</span>
                    </div>
                    <ChevronRight size={20} className="eval-monitor-chevron" />
                  </div>
                  <div className="eval-monitor-program-card-body">
                    <div className="eval-monitor-program-meta">
                      <span><Users size={14} /> {program.facultyCount} Faculty</span>
                      <span>{program.evaluatedCount}/{program.facultyCount} evaluated</span>
                    </div>
                    <div className="eval-monitor-program-stats">
                      <div className="eval-monitor-program-stat primary">
                        <span>Completion</span>
                        <strong>{completionPercent(completionCounts.completed, completionCounts.total)}%</strong>
                      </div>
                      <div className="eval-monitor-program-stat accent">
                        <span>Fields</span>
                        <strong>{program.fields?.length || 0}</strong>
                      </div>
                    </div>
                    <MiniProgress completed={completionCounts.completed} total={completionCounts.total} />
                    {renderCompactAiRecommendation(program, 'program')}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      </>
    );
  }

  function renderFaculty() {
    if (!selectedDepartment || !selectedProgram) return null;
    const faculty = Array.isArray(selectedProgram.faculty) ? selectedProgram.faculty : [];
    return (
      <>
        <div className="eval-monitor-breadcrumb">
          <button type="button" onClick={goBack}>
            <ChevronLeft size={16} /> {selectedDepartment.name}
          </button>
          <span>/</span>
          <span>{selectedProgram.program}</span>
        </div>
        <div className="eval-monitor-hero compact">
          <div>
            <p className="eyebrow">Faculty Members</p>
            <h2>{selectedProgram.program}</h2>
            <p>{faculty.length} faculty member{faculty.length !== 1 ? 's' : ''} in this program.</p>
          </div>
        </div>
        <div className="department-ai-summary-section-head">
          <div>
            <span>Overall Program Summary</span>
            <strong>Common weak areas, performance trends, and suggested development actions</strong>
          </div>
        </div>
        {renderProgramAnalysis(selectedProgram)}
        <div className="eval-monitor-table-container">
          {faculty.length === 0 ? (
            <div className="eval-monitor-empty">No faculty assigned to this program.</div>
          ) : (
            <FacultyAnalysisDirectory faculty={faculty} onViewDetails={setSelectedFaculty} />
          )}
        </div>
      </>
    );
  }

  function renderProgramCard(program) {
    if (!program.fields || program.fields.length === 0) {
      return (
        <article className="department-ai-card card-pop" key={program.id}>
          <div className="department-ai-card-head">
            <div>
              <span>{program.evaluatedCount}/{program.facultyCount} evaluated</span>
              <h3>{program.program}</h3>
              {!isProgramHeadScope && program.department_name && (
                <small className="department-ai-subtitle">{program.department_name}</small>
              )}
            </div>
            <Brain className="h-8 w-8 text-academic-600" />
          </div>
          <FacultyList faculty={program.faculty} />
          <p className="muted">No completed Form A or Form B category results have been submitted for this program yet.</p>
          <div className="department-ai-insight-strip">
            <span>{program.trend || 'No completed results yet'}</span>
            <strong>{program.weakAreaCount || 0} weak areas</strong>
            <small>{program.interventionCount || 0} interventions</small>
          </div>
        </article>
      );
    }

    const ranked = [...program.fields].sort((a, b) => a.score - b.score);
    const { groups, weakest, strongest } = groupRankedFields(ranked);

    return (
      <article className="department-ai-card card-pop" key={program.id}>
        <div className="department-ai-card-head">
          <div>
            <span>{program.evaluatedCount}/{program.facultyCount} evaluated</span>
            <h3>{program.program}</h3>
            {!isProgramHeadScope && program.department_name && (
              <small className="department-ai-subtitle">{program.department_name}</small>
            )}
          </div>
          <div className="department-ai-evaluated-metric">
            <strong>{program.evaluatedCount}</strong>
            <span>of {program.facultyCount}</span>
            <small>evaluated</small>
          </div>
        </div>

        <FacultyList faculty={program.faculty} />

        <div className="department-ai-summary">
          <SummaryMetric tone="weak" icon={TrendingDown} label="Weakest Field" field={weakest} />
          <SummaryMetric tone="strong" icon={TrendingUp} label="Strongest Field" field={strongest} />
        </div>

        <div className="department-ai-insight-strip">
          <span>{program.trend || 'Stable performance trend'}</span>
          <strong>{program.weakAreaCount || 0} weak areas</strong>
          <small>{program.interventionCount || 0} interventions</small>
        </div>

        <InsightAccordion title="Field Rankings" meta={`${ranked.length} evaluation fields`} scrollable>
          <div className="department-ai-ranking">
            {groups.map((group) => <FieldBand key={group.key} group={group} />)}
          </div>
        </InsightAccordion>

        <InsightAccordion title="Recommended PMAS Action" meta="Suggested development action">
          <div className="department-ai-recommendation">
            <GraduationCap className="h-5 w-5" />
            <div>
              <span>Recommended PMAS Action</span>
              <strong>{weakest.seminar}</strong>
              <p><b>Focus:</b> strengthen {weakest.name.toLowerCase()} first. <b>Opportunity:</b> use {strongest.name.toLowerCase()} as this program's current advantage.</p>
              <button type="button">View Seminar Details</button>
            </div>
          </div>
        </InsightAccordion>
      </article>
    );
  }

  return (
    <section className="department-ai-page module-wide page-enter" aria-busy={loading || aiRefreshing}>
      <div className="department-ai-hero">
        <div className="department-ai-hero-intro">
          <p className="eyebrow"><Brain size={13} /> AI Insights</p>
          <div className="department-ai-title-row">
            <span className="department-ai-title-icon"><Building2 size={22} /></span>
            <h2>{pageTitle}</h2>
          </div>
          <p>{pageDescription}</p>
        </div>
        <div className="department-ai-filters" aria-label="Analysis controls">
          <div className="department-ai-filter-heading">
            <div><span>Analysis controls</span><strong>Choose the reporting scope</strong></div>
            <small>Live evaluation data</small>
          </div>
          {isScoped ? (
            <div className="department-ai-filter-controls scoped open">
              <div className="department-ai-scope">
                <span>{scopeTitle}</span>
                <strong>{programScopeLabel}</strong>
              </div>
              <label>
                Evaluation Period
                <select value={selectedPeriodId} onChange={(event) => setSelectedPeriodId(event.target.value)}>
                  {periods.length === 0 && <option value="">Current period</option>}
                  {periods.map((period) => (
                    <option key={period.id} value={period.id}>
                      {period.period_name}
                    </option>
                  ))}
                </select>
              </label>
              <button type="button" className="eval-monitor-btn ghost department-ai-refresh" onClick={() => refreshAIAnalysis(false)} disabled={loading || aiRefreshing} aria-label="Refresh department AI analysis" aria-busy={loading || aiRefreshing}>
                {loading || aiRefreshing ? <Loader2 size={15} className="animate-spin" /> : <RefreshCw size={15} />} Refresh Data
              </button>
              {selectedPeriod && (
                <div className="department-ai-active-period" role="status" aria-live="polite" aria-label={`Selected period ${selectedPeriod.period_name}`}>
                  <span>Analyzing</span><strong>{selectedPeriod.period_name}</strong>
                  {selectedPeriod.year ? <small>Year {selectedPeriod.year}</small> : null}
                </div>
              )}
            </div>
          ) : (
            <div className="department-ai-filter-controls">
              <label>
                Evaluation Period
                <select value={selectedPeriodId} onChange={(event) => setSelectedPeriodId(event.target.value)}>
                  {periods.length === 0 && <option value="">Current period</option>}
                  {periods.map((period) => (
                    <option key={period.id} value={period.id}>
                      {period.period_name}
                    </option>
                  ))}
                </select>
              </label>
              <label>
                Department
                <select value={departmentFilter} onChange={(event) => handleDepartmentChange(event.target.value)}>
                  <option value="">All departments</option>
                  {availableDepartments.map((dept) => (
                    <option key={dept.code} value={dept.code}>
                      {dept.name || dept.code}
                    </option>
                  ))}
                </select>
              </label>
              {showProgramFilter && (
                <label>
                  Program
                  <select value={programFilter} onChange={(event) => setProgramFilter(event.target.value)}>
                    <option value="">All programs</option>
                    {programOptions.map((item) => (
                      <option key={item.id} value={item.program}>
                        {item.program}
                      </option>
                    ))}
                  </select>
                </label>
              )}
              <button type="button" className="eval-monitor-btn ghost department-ai-refresh" onClick={() => refreshAIAnalysis(false)} disabled={loading || aiRefreshing} aria-label="Refresh department AI analysis" aria-busy={loading || aiRefreshing}>
                {loading || aiRefreshing ? <Loader2 size={15} className="animate-spin" /> : <RefreshCw size={15} />} Refresh Data
              </button>
              {selectedPeriod && (
                <div className="department-ai-active-period" role="status" aria-live="polite" aria-label={`Selected period ${selectedPeriod.period_name}`}>
                  <span>Analyzing</span><strong>{selectedPeriod.period_name}</strong>
                  {selectedPeriod.year ? <small>Year {selectedPeriod.year}</small> : null}
                </div>
              )}
            </div>
          )}
        </div>
      </div>
      <div className="department-ai-grid">
        {loading && (
          <article className="department-ai-loading-skeleton" role="status" aria-live="polite">
            <span className="sr-only">Preparing role-based PMAS analysis.</span>
            <div className="department-ai-skeleton-line heading" />
            <div className="department-ai-skeleton-metrics">{[1, 2, 3, 4].map((item) => <i key={item} />)}</div>
            <div className="department-ai-skeleton-line progress" />
            <div className="department-ai-skeleton-content"><i /><i /></div>
          </article>
        )}

        {!loading && error && (
          <div className="department-ai-state error" role="alert">
            <Brain size={22} />
            <div><strong>AI analysis could not be loaded</strong><p>{error}</p></div>
            <button type="button" onClick={() => refreshAIAnalysis(false)}>Try Again</button>
          </div>
        )}

        {!loading && !error && insights.length === 0 && (
          <div className="department-ai-state info">
            <Brain size={22} />
            <div><strong>No analysis available yet</strong><p>Submit completed evaluations first to generate real category averages per program.</p></div>
            <button type="button" onClick={() => refreshAIAnalysis(false)}>Refresh Data</button>
          </div>
        )}

        {!loading && !error && insights.length > 0 && monitorView !== 'faculty' && !isProgramHeadScope && (
          <div className="department-ai-summary-section-head">
            <div>
              <span>{isProgramHeadScope ? 'Overall Program Summary' : monitorView === 'departments' ? 'Overall Department Summary' : 'Overall Program Summary'}</span>
              <strong>{isProgramHeadScope ? 'Common weak areas, performance trends, and suggested development actions' : 'Common weak areas, trends, and development needs by department and program'}</strong>
            </div>
          </div>
        )}

        {!loading && !error && insights.length > 0 && isProgramHeadScope && (
          <>
            <div className="department-ai-summary-section-head">
              <div>
                <span>Overall Program Summary</span>
                <strong>Common weak areas, performance trends, and suggested development actions</strong>
              </div>
            </div>
            {insights.map((program) => renderProgramHeadAnalysis(program))}
          </>
        )}

        {!loading && !error && insights.length > 0 && isProgramHeadScope && (
          <FacultyAnalysisDirectory faculty={scopedFacultyRows} onViewDetails={setSelectedFaculty} />
        )}
        {!loading && !error && insights.length > 0 && !isProgramHeadScope && monitorView === 'departments' && renderDepartments()}
        {!loading && !error && insights.length > 0 && monitorView === 'programs' && renderPrograms()}
        {!loading && !error && insights.length > 0 && monitorView === 'faculty' && renderFaculty()}
      </div>
      <FacultyDetailsPanel faculty={selectedFaculty} onClose={() => setSelectedFaculty(null)} />
    </section>
  );
}
