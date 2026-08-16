import { useEffect, useMemo, useState } from 'react';
import { AlertCircle, Building2, CalendarRange, CheckCircle2, ChevronDown, Download, Eye, FileSpreadsheet, FileText, GraduationCap, LockKeyhole, Printer, RotateCcw, Search, SlidersHorizontal, Users } from 'lucide-react';
import apiFetch from '../../data/api.js';
import { assetUrl, reportUrl } from '../../data/apiBase.js';
import { useEvaluationPeriod } from '../../contexts/EvaluationPeriodContext.jsx';

const noDataMessage = 'No evaluation data available for the selected appraisal period.';

function scoreLabel(score) {
  const value = Number(score || 0);
  if (!value) return 'No rating';
  if (value >= 4.5) return 'Excellent';
  if (value >= 3.75) return 'Very Satisfactory';
  if (value >= 3) return 'Satisfactory';
  return 'Needs Improvement';
}

function performanceLevelPresentation(level = '') {
  const normalized = String(level).trim().toLowerCase();
  if (['incomplete', 'n/a', 'no rating', 'no-rating'].includes(normalized)) {
    return { className: 'incomplete', label: 'Incomplete / N/A' };
  }
  return {
    className: normalized.replaceAll(' ', '-'),
    label: level || 'Incomplete / N/A',
  };
}

function recommendedAction(area) {
  const text = String(area || '').trim();
  if (!text) return 'Awaiting completed category results.';
  return `Prioritize coaching, mentoring, or training for ${text}.`;
}

function categoryList(items = [], limit = 2) {
  if (!items.length) return 'N/A';
  const names = items.map((item) => item.category || item.weakArea || item.weakest_category || 'Category').filter(Boolean);
  const visible = names.slice(0, limit).join(', ');
  return names.length > limit ? `${visible} +${names.length - limit} more` : visible;
}

function CategoryBars({ rows = [] }) {
  if (!rows.length) return <div className="dipascaf-empty">{noDataMessage}</div>;
  return (
    <div className="role-report-bars">
      {rows.slice(0, 6).map((row, index) => {
        const score = Number(row.average_score || row.score || 0);
        return (
          <div className="role-report-bar-row" key={row.category} style={{ '--report-row-index': index }}>
            <div><strong>{row.category}</strong><span>{score ? score.toFixed(2) : 'N/A'} / 5</span></div>
            <i><b style={{ width: `${Math.max(3, Math.min(100, (score / 5) * 100))}%` }} /></i>
          </div>
        );
      })}
    </div>
  );
}

function CategoryScorePills({ rows = [] }) {
  const visible = rows.slice(0, 3);
  if (!visible.length) return <span className="role-report-muted">N/A</span>;
  return (
    <div className="role-report-score-pills">
      {visible.map((item) => (
        <span key={item.category}>
          <strong>{item.category}</strong>
          <em>{scoreText(item.score ?? item.average_score)} / 5</em>
        </span>
      ))}
      {rows.length > visible.length && <small>+{rows.length - visible.length} more</small>}
    </div>
  );
}

function RatingDistribution({ distribution = {} }) {
  const rows = [
    ['Excellent', distribution.excellent || 0],
    ['Very Satisfactory', distribution.very_satisfactory || 0],
    ['Satisfactory', distribution.satisfactory || 0],
    ['Needs Improvement', distribution.needs_improvement || 0],
  ];
  const max = Math.max(1, ...rows.map((row) => Number(row[1] || 0)));
  return (
    <div className="role-report-distribution">
      {rows.map(([label, count], index) => (
        <div key={label} style={{ '--report-row-index': index }}>
          <span>{label}</span>
          <i><b style={{ width: `${(Number(count) / max) * 100}%` }} /></i>
          <strong>{count}</strong>
        </div>
      ))}
    </div>
  );
}

function scoreText(score) {
  const value = Number(score || 0);
  return value ? value.toFixed(2) : 'N/A';
}

function resultCountText(count) {
  const value = Number(count || 0);
  return `${value} result${value === 1 ? '' : 's'}`;
}

function AdminEvaluationBreakdownDetails({ rows = [], selectedBreakdownTerm = '' }) {
  const [openResults, setOpenResults] = useState({});
  const [activeCategories, setActiveCategories] = useState({});

  if (!rows.length) return null;

  function rowKey(row) {
    return String(row.faculty_id || row.id || row.faculty || row.full_name || '');
  }

  function categoryKey(row, category) {
    return `${rowKey(row)}-${category.category}`;
  }

  function toggleResult(key) {
    setOpenResults((current) => ({
      ...current,
      [key]: !current[key],
    }));
  }

  return (
    <div className="role-report-detail-results">
      {rows.map((row) => {
        const categories = (row.category_scores || row.categoryScores || []).filter((category) => {
          if (!selectedBreakdownTerm) return true;
          return String(category.form_type || '').toLowerCase() === selectedBreakdownTerm;
        });
        if (categories.length === 0) return null;
        const currentRowKey = rowKey(row);
        const activeCategoryName = activeCategories[currentRowKey] || categories[0]?.category || '';
        const activeCategory = categories.find((category) => category.category === activeCategoryName) || categories[0];
        const activeResultKey = categoryKey(row, activeCategory);
        const categoryScore = activeCategory.score ?? activeCategory.average_score;
        const questions = activeCategory.questions || [];
        const isOpen = Boolean(openResults[activeResultKey]);

        return (
          <article className="role-report-faculty-result" key={`detail-${row.faculty_id || row.id || row.faculty}`}>
            <div className="role-report-faculty-head">
              <div>
                <h4>{row.full_name || row.faculty}</h4>
                <p>Evaluator detail for {row.department || 'Unassigned Department'}</p>
              </div>
              <span>Overall Result: {scoreText(row.average_score)} / 5.00</span>
            </div>
            <div className="role-report-result-layout">
              <aside className="role-report-category-sidebar" aria-label={`${row.full_name || row.faculty} evaluation categories`}>
                {categories.map((category) => {
                  const isActive = category.category === activeCategory.category;
                  return (
                    <button
                      type="button"
                      className={isActive ? 'active' : ''}
                      key={`${currentRowKey}-nav-${category.category}`}
                      onClick={() => setActiveCategories((current) => ({ ...current, [currentRowKey]: category.category }))}
                    >
                      <strong>{category.category}</strong>
                      <span>{scoreText(category.score ?? category.average_score)} / 5.00</span>
                      <em>{resultCountText(category.result_count)} | {(category.questions || []).length} questions</em>
                    </button>
                  );
                })}
              </aside>
              <section className="role-report-category-detail" key={`${currentRowKey}-${activeCategory.category}`}>
                <div className="role-report-category-head">
                  <div>
                    <h5>{activeCategory.category}</h5>
                    <p>Category result first. Open the result to see each question number.</p>
                  </div>
                  <strong>{scoreText(categoryScore)} / 5.00</strong>
                  <button
                    type="button"
                    onClick={() => toggleResult(activeResultKey)}
                  >
                    {isOpen ? 'Hide Question Numbers' : 'View Result'}
                  </button>
                </div>
                <div className="role-report-result-summary" aria-label={`${activeCategory.category} result summary`}>
                  <article>
                    <span>Category Result</span>
                    <strong>{scoreText(categoryScore)}</strong>
                    <small>{scoreLabel(categoryScore)}</small>
                  </article>
                  <article>
                    <span>Weighted Score</span>
                    <strong>{scoreText(activeCategory.weighted_score ?? activeCategory.weightedScore)}</strong>
                    <small>Recorded category weight result</small>
                  </article>
                  <article>
                    <span>Question Numbers</span>
                    <strong>{questions.length}</strong>
                    <small>Shown after pressing View Result</small>
                  </article>
                  <article>
                    <span>Submitted Results</span>
                    <strong>{activeCategory.result_count ?? 0}</strong>
                    <small>Evaluation records included</small>
                  </article>
                </div>
                {isOpen && (
                  <div
                    className="role-report-question-table show-results"
                    role="table"
                    aria-label={`${activeCategory.category} question results`}
                  >
                    <div role="row" className="role-report-question-header">
                      <span role="columnheader">No.</span>
                      <span role="columnheader">Question</span>
                      <span role="columnheader">Rating</span>
                      <span role="columnheader">Responses</span>
                      <span role="columnheader">Evidence</span>
                    </div>
                    {questions.length === 0 && (
                      <div role="row" className="role-report-question-row empty">
                        <span>No question-level numbers recorded for this category.</span>
                      </div>
                    )}
                    {questions.map((question, questionIndex) => (
                      <div role="row" className="role-report-question-row" key={question.question}>
                        <b role="cell">{questionIndex + 1}</b>
                        <span role="cell">{question.question}</span>
                        <strong role="cell">{scoreText(question.average_score)} / 5.00</strong>
                        <em role="cell">{question.answer_count ?? 0}</em>
                        <p role="cell">
                          {(question.evidence || []).length > 0
                            ? question.evidence.join(' | ')
                            : 'No evidence text provided.'}
                        </p>
                      </div>
                    ))}
                  </div>
                )}
              </section>
            </div>
          </article>
        );
      })}
    </div>
  );
}

function ExportControls({ roleKey, selectedPeriodId, programCode, evaluationForm = '' }) {
  const [format, setFormat] = useState('pdf');
  const [busy, setBusy] = useState(false);
  const endpoint = roleKey === 'dean'
    ? 'reports/dean_download.php'
    : roleKey === 'vpaa'
      ? 'reports/vpaa_download.php'
    : roleKey === 'programHead'
      ? 'reports/program_head_download.php'
      : 'reports/download.php';

  function exportReport() {
    setBusy(true);
    const url = new URL(reportUrl(endpoint));
    url.searchParams.set('report_type', 'complete_export');
    url.searchParams.set('format', format);
    if (selectedPeriodId) url.searchParams.set('period_id', selectedPeriodId);
    if (programCode) url.searchParams.set('program_code', programCode);
    if (evaluationForm) url.searchParams.set('evaluation_form', evaluationForm);
    window.location.href = url.toString();
    window.setTimeout(() => setBusy(false), 1400);
  }

  return (
    <div className="role-report-export">
      <label>
        Export
        <select value={format} onChange={(event) => setFormat(event.target.value)}>
          <option value="pdf">PDF</option>
          <option value="excel">Excel</option>
        </select>
      </label>
      <button type="button" onClick={exportReport} disabled={busy}>
        <Download size={15} /> {busy ? 'Preparing...' : 'Download'}
      </button>
    </div>
  );
}

function RoleReportWorkspace({ role }) {
  const { selectedPeriodId, setSelectedPeriodId, periods, selectedPeriod } = useEvaluationPeriod();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [programFilter, setProgramFilter] = useState('');
  const [departmentSearch, setDepartmentSearch] = useState('');
  const [evaluationBreakdown, setEvaluationBreakdown] = useState('');
  const [showFacultyRecords, setShowFacultyRecords] = useState(false);
  const [printingIndividual, setPrintingIndividual] = useState(false);
  const roleKey = role?.key || '';
  const isAdmin = roleKey === 'admin';
  const isVpaa = roleKey === 'vpaa';
  const isDean = roleKey === 'dean';
  const isProgramHead = roleKey === 'programHead';

  function printIndividualReport() {
    setShowFacultyRecords(true);
    setPrintingIndividual(true);
    document.body.classList.add('printing-individual-report');
    const finish = () => {
      document.body.classList.remove('printing-individual-report');
      setPrintingIndividual(false);
      window.removeEventListener('afterprint', finish);
    };
    window.addEventListener('afterprint', finish);
    window.setTimeout(() => window.print(), 180);
  }

  useEffect(() => {
    let alive = true;
    async function loadReportData() {
      setLoading(true);
      setError('');
      try {
        const params = new URLSearchParams();
        if (selectedPeriodId) params.set('period_id', selectedPeriodId);
        if (evaluationBreakdown) params.set('evaluation_form', evaluationBreakdown);
        params.set('_', String(Date.now()));
        const endpoint = isAdmin ? '/api/admin-analytics.php' : isVpaa ? '/api/vpaa-summary.php' : isDean ? '/api/dean-analytics.php' : '/api/program-head-summary.php';
        const payload = await apiFetch(`${endpoint}?${params.toString()}`);
        if (!alive) return;
        if (isVpaa) {
          const source = payload.data || {};
          const factors = source.factorSummary || [];
          const departments = source.departmentSummaries || [];
          const facultyCount = Number(source.summary?.faculty || 0);
          const reviewed = Number(source.summary?.reviewed || 0);
          const scores = factors.map((item) => Number(item.averageScore || 0)).filter((score) => score > 0);
          const average = scores.length ? Number((scores.reduce((sum, score) => sum + score, 0) / scores.length).toFixed(2)) : null;
          setData({
            summary: { faculty_count: facultyCount, submitted: reviewed, pending: Math.max(0, facultyCount - reviewed), average_score: average, overall_weighted_average: average, interpretation: scoreLabel(average) },
            categoryScores: factors.map((item) => ({ category: item.factor || item.weakArea, average_score: Number(item.averageScore || 0) })),
            highestRatedAreas: [...factors].sort((a, b) => Number(b.averageScore || 0) - Number(a.averageScore || 0)).map((item) => ({ category: item.factor || item.weakArea })),
            lowestRatedAreas: [...factors].sort((a, b) => Number(a.averageScore || 0) - Number(b.averageScore || 0)).map((item) => ({ category: item.factor || item.weakArea })),
            programs: departments.map((item) => ({
              program_code: item.department,
              program_name: item.department,
              faculty_count: item.facultyCount || item.affectedFaculty || 0,
              average_score: item.averageScore || 0,
              completion_rate: item.facultyCount ? Math.round(((item.facultyCount - (item.affectedFaculty || 0)) / item.facultyCount) * 100) : 0,
            })),
            facultyProfiles: [],
            facultyResults: (source.facultyRecommendations || []).map((item, index) => ({
              id: `${item.department || 'department'}-${item.facultyName || index}`,
              faculty: item.facultyName,
              program: item.program,
              department: item.department,
              averageRating: Number(item.averageScore || 0),
              weakArea: item.weakArea,
              strongArea: item.strongArea,
              seminar: item.seminar || item.recommendation,
              categoryScores: [],
            })),
            ratingDistribution: {},
            generatedSummary: factors.length ? `Institution-wide results cover ${facultyCount} faculty across ${departments.length} departments. The current priority area is ${factors[0]?.weakArea || factors[0]?.factor || 'professional development'}.` : noDataMessage,
          });
        } else {
          setData(payload.data || null);
        }
      } catch (err) {
        if (alive) {
          setError(err.message || 'Unable to load reports.');
          setData(null);
        }
      } finally {
        if (alive) setLoading(false);
      }
    }
    loadReportData();
    return () => { alive = false; };
  }, [evaluationBreakdown, isAdmin, isDean, isVpaa, selectedPeriodId]);

  const programs = useMemo(() => {
    if (isAdmin) return data?.departmentPerformance || data?.programs || [];
    if (isVpaa) return data?.programs || [];
    if (isDean) return data?.programs || [];
    return (data?.programs || []).map((program) => ({
      program_code: program.code,
      program_name: program.name,
      faculty_count: (data?.facultyResults || []).filter((row) => row.program === program.code).length,
    }));
  }, [data, isAdmin, isDean, isVpaa]);

  useEffect(() => {
    if (!isAdmin && !isDean && !programFilter && programs.length > 0) {
      setProgramFilter(programs[0].program_code || programs[0].code || '');
    }
  }, [isAdmin, isDean, programFilter, programs]);

  const summary = data?.summary || {};
  const categoryScores = data?.categoryScores || [];
  const highestAreas = data?.highestRatedAreas || [];
  const lowestAreas = data?.lowestRatedAreas || [];
  const distribution = data?.ratingDistribution || {};
  const generatedSummary = data?.generatedSummary || noDataMessage;
  const departmentSearchTerm = departmentSearch.trim().toLowerCase();
  const selectedBreakdown = evaluationBreakdown.trim();
  const selectedBreakdownTerm = selectedBreakdown === 'form_a'
    ? 'a'
    : selectedBreakdown === 'form_b'
      ? 'b'
      : selectedBreakdown.toLowerCase();

  const displayCategoryScores = useMemo(() => {
    if (!isAdmin || !selectedBreakdownTerm) return categoryScores;
    return categoryScores.filter((row) => String(row.form_type || '').toLowerCase() === selectedBreakdownTerm);
  }, [categoryScores, isAdmin, selectedBreakdownTerm]);

  function rowBreakdownCategories(row) {
    if (!selectedBreakdownTerm) return row.category_scores || row.categoryScores || [];
    return (row.category_scores || row.categoryScores || []).filter(
      (item) => String(item.form_type || '').toLowerCase() === selectedBreakdownTerm
    );
  }

  function rowBreakdownScore(row) {
    if (!selectedBreakdownTerm) return null;
    const categories = rowBreakdownCategories(row);
    if (categories.length === 0) return null;
    const scores = categories.map((item) => Number(item.score ?? item.average_score)).filter(Number.isFinite);
    if (scores.length === 0) return null;
    return { score: Number((scores.reduce((total, score) => total + score, 0) / scores.length).toFixed(2)) };
  }

  const filteredPrograms = useMemo(() => {
    const departmentRows = !isAdmin || !departmentSearchTerm ? programs : programs.filter((program) => {
      const code = String(program.program_code || program.code || '').toLowerCase();
      const name = String(program.program_name || program.name || '').toLowerCase();
      return code.includes(departmentSearchTerm) || name.includes(departmentSearchTerm);
    });

    if (!isAdmin || !selectedBreakdownTerm) return departmentRows;

    const facultyProfiles = data?.facultyProfiles || [];
    return departmentRows
      .map((program) => {
        const departmentName = String(program.program_code || program.code || '');
        const scores = facultyProfiles
          .filter((row) => String(row.department || '') === departmentName)
          .map((row) => rowBreakdownScore(row)?.score)
          .filter((score) => score !== null && score !== undefined && score !== '');
        if (scores.length === 0) return null;
        const total = scores.reduce((sum, score) => sum + Number(score || 0), 0);
        return {
          ...program,
          average_score: Number((total / scores.length).toFixed(2)),
        };
      })
      .filter(Boolean);
  }, [data, departmentSearchTerm, isAdmin, programs, selectedBreakdownTerm]);
  const facultyRows = useMemo(() => {
    const rows = (isAdmin || isDean) ? (data?.facultyProfiles || []) : (data?.facultyResults || []);
    return rows.filter((row) => {
      if (isAdmin) {
        const matchesDepartment = !departmentSearchTerm || String(row.department || '').toLowerCase().includes(departmentSearchTerm);
        const matchesBreakdown = !selectedBreakdownTerm || Boolean(rowBreakdownScore(row));
        return matchesDepartment && matchesBreakdown;
      }
      if (!programFilter) return true;
      return (row.program_code || row.program) === programFilter;
    });
  }, [data, departmentSearchTerm, isAdmin, isDean, programFilter, selectedBreakdownTerm]);
  const hasData = Number(summary.submitted || summary.reviewed || 0) > 0 || categoryScores.length > 0;
  const overallAverage = summary.average_score ?? null;
  const weightedAverage = summary.overall_weighted_average ?? null;
  const title = isAdmin ? 'Admin Report Workspace' : isVpaa ? 'VPAA Institution Report' : isDean ? 'Department Report' : 'Program Head Report Workspace';
  const scopeLabel = isAdmin
    ? `Institution-wide results${selectedPeriod?.period_name ? `: ${selectedPeriod.period_name}` : ''}`
    : isVpaa
    ? `Institution and assigned department results${selectedPeriod?.period_name ? `: ${selectedPeriod.period_name}` : ''}`
    : isDean
    ? `Department scope${data?.departments?.length ? `: ${data.departments.join(', ')}` : ''}`
    : `Program scope${programs.length ? `: ${programs.map((p) => p.program_code || p.code).join(', ')}` : ''}`;

  if (!isAdmin && !isVpaa && !isDean && !isProgramHead) {
    return <GenericReportGrid role={role} />;
  }

  return (
    <section className={`role-report-workspace module-wide page-enter ${isDean ? 'dean-report-workspace' : ''} ${isVpaa ? 'vpaa-report-workspace' : ''}`}>
      <div className="role-report-hero">
        <div className="role-report-hero-copy">
          <p className="eyebrow">Reports</p>
          <h2>{title}</h2>
          <p>{scopeLabel}. A concise evaluation summary with optional faculty-level details.</p>
        </div>
        <div className={`role-report-controls ${isDean ? 'dean-report-controls' : ''} ${isVpaa ? 'vpaa-report-controls' : ''}`}>
          <label>
            Appraisal Period
            <select value={selectedPeriodId} onChange={(event) => setSelectedPeriodId(event.target.value)}>
              {periods.length === 0 && <option value="">Current period</option>}
              {periods.map((period) => (
                <option key={period.id} value={period.id}>{period.period_name}</option>
              ))}
            </select>
          </label>
          {isDean && (
            <label>
              Program
              <select value={programFilter} onChange={(event) => setProgramFilter(event.target.value)}>
                <option value="">All Programs</option>
                {programs.map((program) => (
                  <option key={program.program_code} value={program.program_code}>
                    {program.program_name || program.program_code}
                  </option>
                ))}
              </select>
            </label>
          )}
          {isProgramHead && programs.length > 1 && (
            <label>
              Program
              <select value={programFilter} onChange={(event) => setProgramFilter(event.target.value)}>
                {programs.map((program) => (
                  <option key={program.program_code} value={program.program_code}>{program.program_code}</option>
                ))}
              </select>
            </label>
          )}
          {isAdmin && (
            <label className="role-report-search">
              Department
              <span>
                <Search size={15} aria-hidden="true" />
                <input
                  type="search"
                  value={departmentSearch}
                  onChange={(event) => setDepartmentSearch(event.target.value)}
                  placeholder="Search department"
                  aria-label="Search department"
                />
                {departmentSearch && (
                  <button type="button" onClick={() => setDepartmentSearch('')}>
                    Clear
                  </button>
                )}
              </span>
            </label>
          )}
          {isAdmin && (
            <label>
              Evaluation Breakdown
              <select value={evaluationBreakdown} onChange={(event) => setEvaluationBreakdown(event.target.value)}>
                <option value="">All evaluation forms</option>
                <option value="form_a">Form A</option>
                <option value="form_b">Form B</option>
                <option value="self">Self Evaluation</option>
              </select>
            </label>
          )}
          <ExportControls
            roleKey={roleKey}
            selectedPeriodId={selectedPeriodId}
            programCode={isDean && programFilter ? programFilter : ''}
            evaluationForm={isAdmin ? evaluationBreakdown : ''}
          />
        </div>
      </div>

      {loading && <div className="dipascaf-empty">Loading report analytics...</div>}
      {error && <div className="notice warning">{error}</div>}
      {!loading && !error && !hasData && <div className="notice info">{noDataMessage}</div>}

      {!loading && !error && (
        <>
          <section className="role-report-section role-report-executive" id="report-summary">
            <div className="role-report-section-title"><FileText size={18} /><div><h3>Evaluation Summary</h3><p>Key results for the {isAdmin || isVpaa ? 'whole institution' : isDean ? `${programFilter || 'entire department'}` : 'assigned program'}.</p></div></div>
            <div className="role-report-metrics">
              <article><span>Faculty</span><strong>{summary.faculty_count ?? summary.faculty ?? 0}</strong><small>Total faculty members</small></article>
              <article><span>Completed</span><strong>{summary.submitted ?? summary.reviewed ?? 0}</strong><small>Completed evaluations</small></article>
              <article><span>Pending</span><strong>{summary.pending ?? 0}</strong><small>Pending evaluations</small></article>
              <article><span>Average Rating</span><strong>{overallAverage ?? 'N/A'}</strong><small>{scoreLabel(overallAverage)}</small></article>
            </div>

            <div className="role-report-compact-grid">
              <div className="role-report-summary-grid">
                <article><span>Top Performance Areas</span><strong>{categoryList(highestAreas)}</strong></article>
                <article><span>Priority Improvement Areas</span><strong>{categoryList(lowestAreas)}</strong></article>
                <article><span>Weighted Average</span><strong>{weightedAverage ?? 'N/A'}</strong><small>{summary.interpretation || scoreLabel(overallAverage)}</small></article>
                <article className="wide"><span>Executive Summary</span><p>{generatedSummary}</p></article>
              </div>
              <div
                className="role-report-analytics-grid"
                id="report-analytics"
                key={`${selectedPeriodId}-${departmentSearchTerm}-${selectedBreakdownTerm}`}
              >
                <article><h4>Category Scores</h4><CategoryBars rows={displayCategoryScores} /></article>
                <article><h4>Rating Distribution</h4><RatingDistribution distribution={distribution} /></article>
                <article className="wide role-report-comparison">
                  <h4>{isAdmin || isVpaa ? 'Department Comparison' : isDean ? 'Program Comparison' : 'Completion Status'}</h4>
                  <div className="role-report-program-list">
                    {filteredPrograms.length === 0 && <div className="dipascaf-empty">{noDataMessage}</div>}
                    {filteredPrograms.map((program, index) => (
                      <div
                        key={`${program.program_code}-${program.program_name || ''}`}
                        style={{ '--report-row-index': index }}
                      >
                        <span title={program.program_name || program.program_code}>
                          {program.program_name || program.program_code}
                        </span>
                        <i><b style={{ width: `${Math.min(100, Number(program.completion_rate ?? (summary.faculty ? ((Number(summary.reviewed || 0) / Number(summary.faculty || 1)) * 100) : 0)))}%` }} /></i>
                        <strong>{program.average_score ?? summary.average_score ?? 'N/A'}</strong>
                      </div>
                    ))}
                  </div>
                </article>
              </div>
            </div>
          </section>

          <section className="role-report-section role-report-individual-section" id="report-individual">
            <div className="role-report-section-title role-report-detail-heading">
              <Users size={18} />
              <div>
                <h3>Faculty Performance Records</h3>
                <p>{facultyRows.length} record{facultyRows.length === 1 ? '' : 's'} available for the selected filters.</p>
              </div>
              <div className="role-report-detail-actions"><button type="button" className="role-report-print-individual" onClick={printIndividualReport} disabled={printingIndividual}><Printer size={15} /> {printingIndividual ? 'Preparing...' : 'Print Individual Report'}</button><button
                type="button"
                className="role-report-detail-toggle"
                onClick={() => setShowFacultyRecords((current) => !current)}
                aria-expanded={showFacultyRecords}
                aria-controls="faculty-report-details"
              >
                {showFacultyRecords ? 'Hide details' : 'View details'}
              </button></div>
            </div>
            {isAdmin && (departmentSearchTerm || selectedBreakdownTerm) && (
              <div className="role-report-filter-note">
                Filtered to {facultyRows.length} faculty record{facultyRows.length === 1 ? '' : 's'}
                {departmentSearchTerm ? ` in matching department${filteredPrograms.length === 1 ? '' : 's'}` : ''}
                {selectedBreakdown ? ` for ${selectedBreakdown === 'form_a' ? 'Form A' : selectedBreakdown === 'form_b' ? 'Form B' : 'Self Evaluation'}` : ''}.
              </div>
            )}
            <div
              id="faculty-report-details"
              className={`role-report-detail-content ${showFacultyRecords ? 'is-open' : ''}`}
            >
              {isAdmin && selectedBreakdownTerm && (
                <AdminEvaluationBreakdownDetails rows={facultyRows} selectedBreakdownTerm={selectedBreakdownTerm} />
              )}
              <div className="self-eval-table-wrap">
                <table className="self-eval-table role-report-table">
                  <thead>
                    <tr>
                      <th>Faculty</th>
                      {(isAdmin || isDean) && <th>{isAdmin ? 'Department' : 'Program'}</th>}
                      <th>Status</th>
                      <th>{isAdmin && selectedBreakdownTerm ? 'Breakdown Rating' : 'Overall Rating'}</th>
                      <th>Category Scores</th>
                      <th>Strengths</th>
                      <th>Weaknesses</th>
                      <th>Recommended Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    {facultyRows.length === 0 && <tr><td colSpan={(isAdmin || isDean) ? 8 : 7}>{noDataMessage}</td></tr>}
                    {facultyRows.map((row) => {
                      const breakdownScore = isAdmin ? rowBreakdownScore(row) : null;
                      const average = isAdmin && selectedBreakdownTerm
                        ? (breakdownScore?.score ?? null)
                        : (isAdmin || isDean) ? row.average_score : (row.averageRating === 'Pending' ? null : Number(row.averageRating));
                      const rowCategories = rowBreakdownCategories(row);
                      const weakness = row.weakest_category || row.ai_weak_area || row.weakArea || '';
                      const strength = row.strongest_category || row.ai_strength_area || row.strongArea || '';
                      return (
                        <tr key={row.faculty_id || row.id || row.faculty}>
                          <td>{row.full_name || row.faculty}</td>
                          {(isAdmin || isDean) && <td>{isAdmin ? row.department : row.program_code}</td>}
                          <td><span className={`role-report-status ${average ? 'completed' : 'pending'}`}>{average ? 'Completed' : 'Pending'}</span></td>
                          <td><span className="role-report-rating">{average ?? 'N/A'}</span></td>
                          <td><CategoryScorePills rows={rowCategories} /></td>
                          <td>{strength || 'N/A'}</td>
                          <td>{weakness || 'N/A'}</td>
                          <td>{row.seminar || recommendedAction(weakness)}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>
          </section>
        </>
      )}
    </section>
  );
}

function GenericReportGrid({ role }) {
  const { selectedPeriodId, setSelectedPeriodId, periods } = useEvaluationPeriod();
  const roleKey = role?.key || '';
  const isVpaa = roleKey === 'vpaa';
  const [format, setFormat] = useState('pdf');
  const [meta, setMeta] = useState(null);
  const [error, setError] = useState('');
  const [busyType, setBusyType] = useState('');

  const reportDefinitions = [
    ['evaluation_status', 'Evaluation Status Report', 'Operations', 'Daily HR monitoring'],
    ['department_summary', 'Department Summary Report', 'Department', 'Dean and department review'],
    ['faculty_performance', 'Faculty Performance Report', 'Performance', 'Individual performance tracking'],
    ['peer_assignments', 'Peer Assignment Report', 'Peer Review', 'Confidential assignment checking'],
    ['ai_training', 'AI Insights and Training Report', 'AI Analytics', 'Development planning'],
    ['complete_export', 'Complete Evaluation Export', 'Export', 'Records and backup'],
  ];

  useEffect(() => {
    let alive = true;
    async function loadMeta() {
      try {
        setError('');
        const payload = await apiFetch(`/api/report-meta.php?role=${encodeURIComponent(roleKey || 'admin_hr')}`);
        if (alive) setMeta(payload.data || null);
      } catch (err) {
        if (alive) setError(err.message || 'Unable to load report status.');
      }
    }
    loadMeta();
    return () => { alive = false; };
  }, [roleKey]);

  function handleGenerate(reportType = 'complete_export') {
    setBusyType(reportType);
    const endpoint = isVpaa ? 'reports/vpaa_download.php' : 'reports/download.php';
    const url = new URL(reportUrl(endpoint));
    url.searchParams.set('report_type', reportType);
    url.searchParams.set('format', format);
    if (selectedPeriodId) url.searchParams.set('period_id', selectedPeriodId);
    window.location.href = url.toString();
    window.setTimeout(() => setBusyType(''), 1400);
  }

  return (
    <>
      <section className="admin-box admin-report-intro analytics-report-intro module-wide page-enter">
        <div>
          <span className="eyebrow">{isVpaa ? 'VPAA Reports' : 'Admin Reports'}</span>
          <h2>{isVpaa ? 'Institution Reports & Analytics' : 'Reports & Analytics Dashboard'}</h2>
          <p>Generate evaluation exports, department summaries, faculty performance reports, and AI training plans.</p>
        </div>
        <div className="role-report-export">
          <label>
            Appraisal Period
            <select value={selectedPeriodId} onChange={(event) => setSelectedPeriodId(event.target.value)}>
              {periods.length === 0 && <option value="">All periods</option>}
              <option value="">All periods</option>
              {periods.map((period) => (
                <option key={period.id} value={period.id}>{period.period_name}</option>
              ))}
            </select>
          </label>
          <label>
            Export
            <select value={format} onChange={(event) => setFormat(event.target.value)}>
              <option value="pdf">PDF</option>
              <option value="excel">Excel</option>
              <option value="csv">CSV</option>
            </select>
          </label>
        </div>
      </section>

      {error && <div className="notice warning module-wide">{error}</div>}

      <section className="admin-report-grid module-wide" aria-label="Specific admin report types">
        {reportDefinitions.map(([key, title, category, bestFor], index) => {
          const report = meta?.reports?.[key] || {};
          const progress = Number(report.progress || 0);
          return (
            <article className="admin-report-card" style={{ '--card-delay': `${index * 80}ms` }} key={key}>
              <div className="admin-report-card-top">
                <span className="admin-report-stat">{report.badge || 'Ready'}</span>
                <span className="admin-report-icon" data-icon={key === 'complete_export' ? 'download' : key === 'ai_training' ? 'spark' : 'activity'} aria-hidden="true" />
              </div>
              <span className="admin-report-badge">{category}</span>
              <div className="admin-report-title-row">
                <FileText size={18} />
                <h3>{title}</h3>
              </div>
              <p>{key === 'complete_export' ? 'Downloads the detailed evaluation assignment and score list.' : 'Uses live assignment, faculty, result, and intervention records.'}</p>
              <small>Best for: {bestFor}</small>
              <div className="admin-report-progress" aria-label={`Report readiness ${progress} percent`}>
                <span style={{ '--progress-value': `${Math.max(0, Math.min(100, progress))}%` }} />
              </div>
              <div className="admin-report-actions">
                <button type="button" onClick={() => handleGenerate(key)} disabled={busyType === key}>
                  <Download size={15} />
                  <span className="report-button-text">{busyType === key ? 'Preparing' : 'Generate'}</span>
                </button>
              </div>
            </article>
          );
        })}
      </section>
    </>
  );
}

function performanceReportDefaults(roleKey) {
  if (roleKey === 'vpaa') return { report_type: 'overall_department', department_id: '', role: '', program: '', faculty_id: '', period_id: '', sort: 'score_desc', orientation: 'auto' };
  if (roleKey === 'programHead') return { report_type: 'department', department_id: '', role: 'teacher', program: '', faculty_id: '', period_id: '', sort: 'name', orientation: 'auto' };
  return { report_type: 'department', department_id: '', role: 'teacher', program: '', faculty_id: '', period_id: '', sort: 'name', orientation: 'auto' };
}

function AnalyticsPreview({ analytics, displayScore }) {
  if (!analytics) return null;
  const sources = Object.values(analytics.sources || {});
  const distribution = analytics.charts?.rating_distribution || { labels: [], values: [] };
  const total = distribution.values.reduce((sum, value) => sum + Number(value || 0), 0) || 1;
  const colors = ['#07875c', '#37b77e', '#e5b72e', '#df762e'];
  let cursor = 0;
  const stops = distribution.values.map((value, index) => { const start = cursor; cursor += Number(value || 0) / total * 100; return `${colors[index]} ${start}% ${cursor}%`; });
  const categories = analytics.charts?.categories || [];
  const linePoints = categories.slice(0, 10).map((item, index, rows) => `${rows.length === 1 ? 50 : index / (rows.length - 1) * 100},${100 - Math.max(0, Math.min(5, Number(item.score))) / 5 * 100}`).join(' ');
  return <section className="report-ai-analytics" aria-labelledby="report-ai-title">
    <div className="report-ai-heading"><div><span>AI Analytics</span><h3 id="report-ai-title">PMAS Evidence Overview</h3><p>Selected-period analysis from authorized completed evaluation records.</p></div><strong>{analytics.consolidated?.available ? `${displayScore(analytics.consolidated.score)} · ${analytics.consolidated.level}` : 'Incomplete evidence'}</strong></div>
    {(analytics.warnings || []).map((warning) => <div className="report-analytics-warning" role="status" key={warning}><AlertCircle size={17} /> {warning}</div>)}
    <div className="report-source-cards">{sources.map((source) => <article key={source.key}><header><span>{source.label}</span><b>{source.available ? displayScore(source.score) : 'N/A'}</b></header><small>{source.completed_count} completed result{source.completed_count === 1 ? '' : 's'}</small><div className="report-mini-bar"><i style={{ width: `${Math.max(0, Math.min(100, Number(source.score || 0) * 20))}%` }} /></div><dl><div><dt>Strengths</dt><dd>{source.strengths?.map((item) => `${item.title} (${displayScore(item.score)})`).join(', ') || 'Insufficient data'}</dd></div><div><dt>Improve</dt><dd>{source.improvement_areas?.map((item) => `${item.title} (${displayScore(item.score)})`).join(', ') || 'Insufficient data'}</dd></div></dl></article>)}</div>
    <div className="report-chart-grid">
      <article className="report-chart-panel"><h4>Performance Distribution</h4><div className="report-donut" style={{ background: `conic-gradient(${stops.join(',') || '#dbe9e2 0 100%'})` }}><span><b>{total === 1 && distribution.values.every((v) => !v) ? 0 : total}</b><small>People</small></span></div><ul>{distribution.labels.map((label, index) => <li key={label}><i style={{ background: colors[index] }} />{label}<b>{distribution.values[index] || 0}</b></li>)}</ul></article>
      <article className="report-chart-panel"><h4>Form Score Comparison</h4><div className="report-source-bars">{sources.map((source) => <div key={source.key}><label>{source.label}<b>{displayScore(source.score)}</b></label><span><i style={{ height: `${Math.max(2, Number(source.score || 0) * 20)}%` }} /></span></div>)}</div></article>
      <article className="report-chart-panel report-category-panel"><h4>Category Results</h4><div className="report-category-bars">{categories.slice(0, 10).map((item, index) => <div key={`${item.title}-${index}`}><label>{item.title}<b>{displayScore(item.score)}</b></label><span><i style={{ width: `${Number(item.score || 0) * 20}%` }} /></span></div>)}</div></article>
      <article className="report-chart-panel"><h4>Selected-Period Category Profile</h4><svg className="report-line-chart" viewBox="0 0 100 100" role="img" aria-label="Line graph of category scores from zero to five"><line x1="0" y1="100" x2="100" y2="100" /><line x1="0" y1="0" x2="0" y2="100" /><polyline points={linePoints} /></svg><table className="report-chart-data"><thead><tr><th>Category</th><th>Score</th></tr></thead><tbody>{categories.slice(0, 10).map((item, index) => <tr key={`${item.title}-data-${index}`}><td>{item.title}</td><td>{displayScore(item.score)}</td></tr>)}</tbody></table></article>
    </div>
    <article className={`report-recommendation ${analytics.recommendation ? '' : 'unavailable'}`}><span>Overall Faculty Development Recommendation</span>{analytics.recommendation ? <><h3>{analytics.recommendation.activity_type}: {analytics.recommendation.title}</h3><p>{analytics.recommendation.objective}</p><h4>Why this is recommended</h4><p>{analytics.recommendation.reason}</p><table><thead><tr><th>Source</th><th>Evidence category</th><th>Score</th><th>Trigger</th></tr></thead><tbody>{analytics.recommendation.evidence.map((item, index) => <tr key={`${item.source}-${item.category}-${index}`}><td>{item.source}</td><td>{item.category}</td><td>{displayScore(item.score)}</td><td>{item.trigger}</td></tr>)}</tbody></table></> : <><h3>Recommendation unavailable</h3><p>PMAS Form A and PMAS Form B must both contain completed evidence. No values have been invented.</p></>}</article>
  </section>;
}

function PerformanceReportWorkspace({ role }) {
  const [metadata, setMetadata] = useState({ departments: [], programs: [], periods: [] });
  const roleKey = role?.key || '';
  const [filters, setFilters] = useState(() => performanceReportDefaults(roleKey));
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const [exporting, setExporting] = useState('');
  const [exportStage, setExportStage] = useState('');
  const [error, setError] = useState('');
  const [advancedOpen, setAdvancedOpen] = useState(false);
  const isDean = roleKey === 'dean';
  const isVpaa = roleKey === 'vpaa';
  const isProgramHead = roleKey === 'programHead';

  useEffect(() => {
    let alive = true;
    apiFetch('/api/performance-report.php').then((payload) => {
      if (!alive) return;
      setMetadata(payload.metadata || { departments: [], programs: [], periods: [] });
      const latestPeriod = payload.metadata?.periods?.[0];
      if (latestPeriod) setFilters((current) => current.period_id ? current : ({ ...current, period_id: String(latestPeriod.id) }));
      if ((isDean || isProgramHead) && payload.data?.department_code) {
        const department = (payload.metadata?.departments || []).find((item) => item.code === payload.data.department_code);
        if (department) setFilters((current) => ({ ...current, department_id: String(department.id) }));
      }
      if (isProgramHead && payload.data?.program && payload.data.program !== 'All Programs') {
        setFilters((current) => ({ ...current, program: payload.data.program, role: 'teacher' }));
      }
    }).catch((err) => alive && setError(err.message || 'Unable to load report options.'));
    return () => { alive = false; };
  }, [isDean, isProgramHead]);

  useEffect(() => {
    if (!isProgramHead || !filters.period_id) return undefined;
    let alive = true;
    apiFetch(`/api/performance-report.php?period_id=${encodeURIComponent(filters.period_id)}`).then((payload) => {
      if (!alive) return;
      const scopedMetadata = payload.metadata || { departments: [], programs: [], periods: [] };
      setMetadata(scopedMetadata);
      setFilters((current) => {
        const stillAssigned = !current.program || (scopedMetadata.programs || []).some((program) => program.code === current.program);
        return {
          ...current,
          department_id: scopedMetadata.departments?.[0] ? String(scopedMetadata.departments[0].id) : current.department_id,
          program: stillAssigned ? current.program : '',
        };
      });
    }).catch((err) => alive && setError(err.message || 'Unable to load the programs assigned for this evaluation.'));
    return () => { alive = false; };
  }, [filters.period_id, isProgramHead]);

  const availablePrograms = useMemo(() => metadata.programs.filter((program) => !filters.department_id || Number(program.department_id) === Number(filters.department_id)), [filters.department_id, metadata.programs]);
  const selectedDepartment = metadata.departments.find((item) => Number(item.id) === Number(filters.department_id));

  function updateFilter(name, value) {
    setFilters((current) => ({
      ...current,
      [name]: value,
      ...(name === 'department_id' ? { program: '' } : {}),
      ...(name === 'report_type' && value === 'overall_department' ? { department_id: '', program: '', role: '' } : {}),
    }));
  }

  function queryString() {
    const params = new URLSearchParams({ ...filters, include_analytics: '1' });
    return params.toString();
  }

  async function previewReport() {
    if (filters.report_type === 'department' && !filters.department_id) {
      setError('Select a department for a Department Performance Report.');
      return;
    }
    if (!filters.period_id) {
      setError('Select an evaluation period before previewing the report.');
      return;
    }
    setLoading(true); setError('');
    try {
      const payload = await apiFetch(`/api/performance-report.php?${queryString()}`);
      setMetadata(payload.metadata || metadata);
      setReport(payload.data || null);
    } catch (err) { setError(err.message || 'Unable to generate the report preview.'); }
    finally { setLoading(false); }
  }

  function resetFilters() {
    const defaults = performanceReportDefaults(roleKey);
    const scopedDepartment = (isDean || isProgramHead) && metadata.departments[0] ? String(metadata.departments[0].id) : '';
    const scopedProgram = '';
    setFilters({ ...defaults, department_id: scopedDepartment, program: scopedProgram, period_id: metadata.periods[0] ? String(metadata.periods[0].id) : '' });
    setReport(null); setError('');
  }

  function preloadReportImages() {
    const sources = [report?.institution_logo, report?.department_logo].filter(Boolean).map(assetUrl);
    return Promise.all(sources.map((source) => new Promise((resolve) => {
      const image = new Image();
      image.onload = resolve;
      image.onerror = resolve;
      image.src = source;
    })));
  }

  async function exportReport(format) {
    if (exporting) return;
    const url = new URL(reportUrl('performance_download.php'));
    Object.entries(filters).forEach(([key, value]) => value !== '' && url.searchParams.set(key, value));
    if (report?.snapshot?.id) url.searchParams.set('snapshot_id', report.snapshot.id);
    url.searchParams.set('format', format);
    url.searchParams.set('_export', String(Date.now()));
    setExporting(format); setExportStage('Preparing assets...'); setError('');
    try {
      await preloadReportImages();
      setExportStage(`Generating ${format === 'word' ? 'Word' : format.toUpperCase()}...`);
      const response = await fetch(url.toString(), { credentials: 'include', cache: 'no-store' });
      if (!response.ok) {
        const message = (await response.text()).trim();
        throw new Error(response.status === 403
          ? 'Your report session was not accepted. Please sign in again and retry the export.'
          : message || `Unable to generate ${format.toUpperCase()}.`);
      }
      const blob = await response.blob();
      const signature = new Uint8Array(await blob.slice(0, 5).arrayBuffer());
      const isPdf = signature[0] === 0x25 && signature[1] === 0x50 && signature[2] === 0x44 && signature[3] === 0x46;
      const isZip = signature[0] === 0x50 && signature[1] === 0x4b;
      if ((format === 'pdf' && !isPdf) || ((format === 'word' || format === 'excel') && !isZip)) {
        const diagnostic = (await blob.text()).slice(0, 180).replace(/\s+/g, ' ').trim();
        throw new Error(diagnostic.startsWith('<?php')
          ? 'The server returned PHP source instead of generating the report. Redeploy the corrected export route.'
          : `The server returned an invalid ${format.toUpperCase()} file. Please preview the report and try again.`);
      }
      const disposition = response.headers.get('Content-Disposition') || '';
      const filename = disposition.match(/filename="?([^";]+)"?/i)?.[1] || `APPRAISIA-report.${format === 'word' ? 'docx' : format === 'excel' ? 'xlsx' : 'pdf'}`;
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob); link.download = filename;
      document.body.appendChild(link); link.click(); link.remove();
      setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    } catch (err) { setError(err.message || `Unable to generate ${format.toUpperCase()}. Please try again.`); }
    finally { setExporting(''); setExportStage(''); }
  }

  function printReport() {
    if (!report?.rows?.length || exporting) return;
    setExporting('print'); setExportStage('Preparing Print View...');
    window.setTimeout(() => { window.print(); setExporting(''); setExportStage(''); }, 180);
  }

  const overall = report?.report_type === 'overall_department';
  const analyticsMode = ['consolidated', 'form_a', 'form_b'].includes(report?.report_type);
  // A Dean is permanently scoped to one department, so repeating it in every
  // result row adds width without adding useful information.
  const showDepartmentColumn = !isDean && report?.report_type !== 'department';
  const displayScore = (score) => score === null || score === undefined ? 'N/A' : Number(score).toFixed(2);
  const periodLabel = report?.period ? report.period.school_year || report.period.period_name : 'All Evaluation Periods';
  const selectedProgram = metadata.programs.find((item) => item.code === filters.program);
  const assignedProgramCount = isProgramHead ? metadata.programs.length : 0;
  const programScopeTitle = selectedProgram
    ? `${selectedProgram.code} — ${selectedProgram.name}`
    : assignedProgramCount > 1
      ? `All assigned programs: ${metadata.programs.map((item) => item.code).join(', ')}`
      : metadata.programs[0]
        ? `${metadata.programs[0].code} — ${metadata.programs[0].name}`
        : 'No assigned program';
  const scopedDepartment = metadata.departments.find((item) => Number(item.id) === Number(filters.department_id)) || metadata.departments[0];
  const hasReportData = Boolean(report?.rows?.length);
  const incompleteRecordCount = report?.rows?.filter((row) => Number(row.completed || 0) < Number(row.assignments || 0)).length || 0;
  const selectedPeriod = metadata.periods.find((item) => String(item.id) === String(filters.period_id));
  const selectedPeriodLabel = selectedPeriod ? `AY ${selectedPeriod.school_year || selectedPeriod.period_name}` : 'No period selected';
  const reportingScopeLabel = isProgramHead ? 'Program Head' : isDean ? 'Dean' : isVpaa ? 'VPAA' : 'Admin/HR';
  const reportingScopeDescription = isProgramHead ? 'Fixed by your authenticated Program Head assignment.' : isDean ? 'Department access is fixed by your authenticated Dean assignment.' : 'Institution-wide access with configurable report scope.';
  const statusScopeLabel = isProgramHead ? (selectedProgram?.code || (assignedProgramCount > 1 ? 'All assigned programs' : metadata.programs[0]?.code || 'Program')) : selectedDepartment?.name || (isDean ? scopedDepartment?.name : 'All Departments');

  const workspaceTitle = isVpaa ? 'Institutional Appraisal Reports' : isDean ? 'Department Appraisal Reports' : isProgramHead ? 'Program Appraisal Reports' : 'Performance Factors Appraisal Reports';
  const workspaceDescription = isVpaa ? 'Compare academic departments and review institution-wide appraisal results.' : isDean ? 'Review faculty and program performance within your assigned department.' : isProgramHead ? 'Review faculty appraisal results within your assigned program.' : 'Generate department, role-based, and consolidated performance reports from submitted APPRAISIA evaluation results.';

  return <section className={`performance-report-workspace program-report-workspace module-wide page-enter report-workspace-${roleKey}`}>
    <header className="performance-report-hero"><div><span className="eyebrow">{isVpaa ? 'VPAA Report Generation' : isDean ? 'Dean Report Generation' : isProgramHead ? 'Program Head Report Generation' : 'Report Generation'}</span><h2>{workspaceTitle}</h2><p>{workspaceDescription}</p></div><span><FileText size={26} /></span></header>
    <section className="program-report-scope" aria-labelledby="reporting-scope-title"><div className="program-report-card-heading"><div><LockKeyhole size={18} /><div><h3 id="reporting-scope-title">Your Reporting Scope</h3><p>{reportingScopeDescription}</p></div></div><span><LockKeyhole size={13} /> {isDean || isProgramHead ? 'Assigned scope' : 'Authorized access'}</span></div><div className="program-report-scope-grid">
      <article><Building2 size={19} /><div><span>Department Scope</span><strong tabIndex="0" title={isDean || isProgramHead ? scopedDepartment?.name || 'Assigned department' : 'All academic departments'}>{isDean || isProgramHead ? scopedDepartment?.name || 'Assigned department' : 'All Academic Departments'}</strong></div></article>
      <article><GraduationCap size={19} /><div><span>Program Scope</span>{isProgramHead ? <strong tabIndex="0" title={programScopeTitle}><b>{selectedProgram?.code || (assignedProgramCount > 1 ? 'ALL PROGRAMS' : metadata.programs[0]?.code || 'Program')}</b><small>{selectedProgram?.name || (assignedProgramCount > 1 ? metadata.programs.map((item) => item.code).join(' · ') : metadata.programs[0]?.name || 'Assigned program')}</small></strong> : <strong>{isDean ? 'Programs within assigned department' : 'All Academic Programs'}</strong>}</div></article>
      <article><Users size={19} /><div><span>Role Scope</span><strong>{isProgramHead ? 'Faculty Members' : filters.role ? filters.role === 'teacher' ? 'Faculty Members' : filters.role === 'program_head' ? 'Program Heads' : 'Deans' : 'All Applicable Roles'}</strong></div></article>
      <article><LockKeyhole size={19} /><div><span>Access</span><strong>{reportingScopeLabel} Reporting Access</strong></div></article>
    </div></section>
    <nav className="program-report-stepper" aria-label="Report workflow"><span className="complete"><CheckCircle2 size={16} /> <b>1</b> Configure</span><i /><span className={report ? 'complete' : 'active'}>{report ? <CheckCircle2 size={16} /> : <Eye size={16} />} <b>2</b> Preview</span><i /><span className={hasReportData ? 'active' : ''}><Download size={16} /> <b>3</b> Export</span></nav>
    <section className="performance-report-config"><div className="performance-report-section-head"><SlidersHorizontal size={19} /><div><h3>Report Configuration</h3><p>Choose report options, preview the output, then export when ready.</p></div></div><div className={`performance-report-filter-grid ${isProgramHead ? 'program-report-filter-grid' : 'leader-report-filter-grid'}`}>
      <label>Report Type<select value={filters.report_type} onChange={(e) => updateFilter('report_type', e.target.value)}><optgroup label="Original Performance Reports"><option value="department">Department Performance Report</option>{isVpaa && <option value="overall_department">Institutional Department Comparison</option>}<option value="role_based">Role-Based Performance Report</option></optgroup><optgroup label="Separate AI Analytics Reports"><option value="consolidated">Overall AI Analytics</option><option value="form_a">PMAS Form A Analytics</option><option value="form_b">PMAS Form B Analytics</option></optgroup></select><small>Overall AI Analytics combines PMAS Form A and PMAS Form B only.</small></label>
      {!isProgramHead && !isDean && <label>Department<select value={filters.department_id} disabled={filters.report_type === 'overall_department'} onChange={(e) => updateFilter('department_id', e.target.value)}><option value="">All Departments</option>{metadata.departments.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>}
      {!isProgramHead && <label>Role<select value={filters.role} disabled={filters.report_type === 'overall_department'} onChange={(e) => updateFilter('role', e.target.value)}><option value="">All Applicable Roles</option><option value="dean">Dean</option><option value="program_head">Program Head</option><option value="teacher">Faculty Member</option></select></label>}
      {isProgramHead && assignedProgramCount > 1 && <label>Program<select id="program-report-program" value={filters.program} onChange={(e) => { updateFilter('program', e.target.value); setReport(null); }}><option value="">All Assigned Programs</option>{metadata.programs.map((item) => <option key={item.id} value={item.code}>{item.code} — {item.name}</option>)}</select><small>Select one handled program or combine all assigned programs.</small></label>}
      <label>Evaluation Name<select id="program-report-period" aria-describedby={isProgramHead ? 'program-period-help' : undefined} value={filters.period_id} onChange={(e) => updateFilter('period_id', e.target.value)}><option value="">Select Evaluation Name</option>{metadata.periods.map((item) => <option key={item.id} value={item.id}>{item.period_name || (item.school_year ? `AY ${item.school_year}` : `Evaluation ${item.id}`)}</option>)}</select>{isProgramHead && <small id="program-period-help">Uses the selected appraisal evaluation for this report.</small>}</label>
      {!isProgramHead && <label>Program<select value={filters.program} disabled={!filters.department_id || filters.report_type === 'overall_department'} onChange={(e) => updateFilter('program', e.target.value)}><option value="">All Programs</option>{availablePrograms.map((item) => <option key={item.id} value={item.code}>{item.code} — {item.name}</option>)}</select></label>}
      <label>Faculty Member<select value={filters.faculty_id} onChange={(e) => updateFilter('faculty_id', e.target.value)}><option value="">All authorized faculty</option>{metadata.faculty?.filter((item) => (!filters.department_id || item.department === selectedDepartment?.name || item.department === selectedDepartment?.code) && (!filters.program || item.program === filters.program)).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
      <label>Sort By<select value={filters.sort} onChange={(e) => updateFilter('sort', e.target.value)}><option value="name">Name A–Z</option><option value="score_desc">Highest Mean</option><option value="score_asc">Lowest Mean</option></select></label>
      {!isProgramHead && <label>Page Orientation<select value={filters.orientation} onChange={(e) => updateFilter('orientation', e.target.value)}><option value="auto">Auto</option><option value="portrait">Portrait</option><option value="landscape">Landscape</option></select></label>}
    </div>{isProgramHead && <div className={`program-report-advanced ${advancedOpen ? 'open' : ''}`}><button type="button" aria-expanded={advancedOpen} onClick={() => setAdvancedOpen((open) => !open)}><span><SlidersHorizontal size={15} /> Advanced Options</span><ChevronDown size={16} /></button>{advancedOpen && <div><label>Page Orientation<select value={filters.orientation} onChange={(e) => updateFilter('orientation', e.target.value)}><option value="auto">Auto — based on report width</option><option value="portrait">Portrait — compact reports</option><option value="landscape">Landscape — wide tables</option></select></label></div>}</div>}<div className="performance-report-config-actions"><button type="button" onClick={resetFilters}><RotateCcw size={15} /> {isProgramHead ? 'Reset' : 'Reset Filters'}</button><button type="button" className="primary" onClick={previewReport} disabled={loading}><Search size={15} /> {loading ? 'Preparing Preview...' : 'Preview Report'}</button></div></section>
    {error && <div className="notice error" role="alert">{error}</div>}
    <section className={`program-report-status ${hasReportData ? 'ready' : report ? 'empty' : ''}`} aria-live="polite"><div>{hasReportData ? <CheckCircle2 size={20} /> : report ? <AlertCircle size={20} /> : <Eye size={20} />}<div><h3>{hasReportData ? 'Report Ready' : report ? 'No report data available' : 'No preview generated yet'}</h3><p>{hasReportData ? 'The preview is ready to review and export.' : report ? 'No completed appraisal results were found for the selected evaluation period.' : 'Preview the report to verify data before exporting.'}</p>{hasReportData && incompleteRecordCount > 0 && <p className="program-report-incomplete"><AlertCircle size={13} /> {incompleteRecordCount} record{incompleteRecordCount === 1 ? ' has' : 's have'} incomplete evaluation sources.</p>}</div></div>{hasReportData && <dl><div><dt>Scope</dt><dd>{statusScopeLabel}</dd></div><div><dt>Period</dt><dd>{selectedPeriodLabel}</dd></div><div><dt>Records</dt><dd>{report.rows.length} report record{report.rows.length === 1 ? '' : 's'}</dd></div><div><dt>Generated</dt><dd>Just now</dd></div></dl>}<div className="program-report-status-actions">{hasReportData && <a href="#performance-report-preview"><Eye size={14} /> View Preview</a>}{report && !hasReportData && <button type="button" onClick={() => document.getElementById('program-report-period')?.focus()}><CalendarRange size={14} /> Change Period</button>}<button type="button" onClick={previewReport} disabled={loading}><RotateCcw size={14} /> Refresh</button></div></section>
    <section className="program-report-export" aria-labelledby="report-export-title"><div className="program-report-export-heading"><span><Download size={17} /></span><div><h3 id="report-export-title">Export Report</h3><p>{hasReportData ? 'Choose a format for the verified report preview.' : 'Preview the report before exporting.'}</p></div></div><div className="program-report-export-actions"><button type="button" title="Print the current report preview." onClick={printReport} disabled={!hasReportData || !!exporting}><Printer size={16} /> {exporting === 'print' ? exportStage : 'Print'}</button><button type="button" className="primary" title="Download a print-ready PDF report." onClick={() => exportReport('pdf')} disabled={!hasReportData || !!exporting}><FileText size={16} /> {exporting === 'pdf' ? exportStage : 'PDF'}</button><button type="button" title="Download report data as a spreadsheet." onClick={() => exportReport('excel')} disabled={!hasReportData || !!exporting}><FileSpreadsheet size={16} /> {exporting === 'excel' ? exportStage : 'Excel'}</button><button type="button" title="Download an editable Word document." onClick={() => exportReport('word')} disabled={!hasReportData || !!exporting}><Download size={16} /> {exporting === 'word' ? exportStage : 'Word'}</button></div></section>
    {report && <section className="performance-report-preview" id="performance-report-preview"><div className="performance-report-paper" data-report-ready="true">
      <header><div className="performance-report-brand"><span className="performance-report-logo"><img src={assetUrl(report.institution_logo || 'assets/images/ndmc-seal.png')} alt="Notre Dame of Midsayap College seal" onError={(event) => { const image = event.currentTarget; const fallback = assetUrl('assets/images/ndmc-seal.png'); image.onerror = () => { if (image.parentElement) image.parentElement.style.display = 'none'; }; if (image.src !== new URL(fallback, window.location.origin).href) image.src = fallback; else if (image.parentElement) image.parentElement.style.display = 'none'; }} /></span><div><h2>Notre Dame of Midsayap College</h2><h3>{overall ? 'Institutional Academic Departments' : report.department}</h3><p>Midsayap, Cotabato</p></div>{report.department_logo && <span className="performance-report-logo department"><img src={assetUrl(report.department_logo)} alt={`${report.department} logo`} onError={(event) => { if (event.currentTarget.parentElement) event.currentTarget.parentElement.style.display = 'none'; }} /></span>}</div><hr /><div className="performance-report-title"><h4>{overall ? 'OVERALL DEPARTMENT PERFORMANCE REPORT' : 'PERFORMANCE FACTORS APPRAISAL'}</h4><strong>{report.role_label}</strong><span>{report.period?.school_year ? `AY ${report.period.school_year}` : report.period?.period_name || 'All Evaluation Periods'}</span></div></header>
      <div className="performance-report-meta"><div><span>Department</span><b>{report.department}</b></div><div><span>Program</span><b>{report.program}</b></div><div><span>Role</span><b>{report.role_label}</b></div><div><span>Evaluation Period</span><b>{periodLabel}</b></div><div><span>Generated</span><b>{new Date(report.generated_at).toLocaleDateString()}</b></div><div><span>Generated By</span><b>{report.generated_by || 'Authorized User'}</b></div></div>
      {analyticsMode && <AnalyticsPreview analytics={report.analytics} displayScore={displayScore} />}
      {overall && <div className="performance-report-summary"><article><span>Institutional Mean</span><strong>{displayScore(report.summary.overall_mean)}</strong></article><article><span>Highest Department</span><strong>{report.summary.highest_department || 'N/A'}</strong></article><article><span>Departments</span><strong>{report.summary.departments}</strong></article><article><span>Evaluated Personnel</span><strong>{report.summary.personnel}</strong></article><article><span>Completion</span><strong>{report.summary.completion}%</strong></article></div>}
      {!analyticsMode && <><div className="performance-report-table-wrap"><table className={showDepartmentColumn ? 'with-department' : 'single-department'}><thead><tr>{overall ? <><th>Department</th><th>Personnel</th><th>Peer Mean</th><th>Head Mean</th><th>PH/SC Mean</th><th>Overall Mean</th><th>Level of Performance</th></> : <><th>Name</th>{showDepartmentColumn && <th>Department</th>}<th>Program</th><th>Peer</th><th>Head</th><th>PH/SC</th><th>Total</th><th>Mean</th><th>Level of Performance</th></>}</tr></thead><tbody>{report.rows.map((row) => { const level = performanceLevelPresentation(row.level); return overall ? <tr key={row.department}><td>{row.department}</td><td>{row.personnel}</td><td>{displayScore(row.peer)}</td><td>{displayScore(row.head)}</td><td>{displayScore(row.phsc)}</td><td>{displayScore(row.mean)}</td><td><span className={`performance-level ${level.className}`}>{level.label}</span></td></tr> : <tr key={row.id}><td>{row.name}</td>{showDepartmentColumn && <td>{row.department}</td>}<td>{row.program || 'N/A'}</td><td>{displayScore(row.peer)}</td><td>{displayScore(row.head)}</td><td>{displayScore(row.phsc)}</td><td>{displayScore(row.total)}</td><td>{displayScore(row.mean)}</td><td><span className={`performance-level ${level.className}`}>{level.label}</span></td></tr>; })}{report.rows.length === 0 && <tr><td colSpan={overall ? 7 : showDepartmentColumn ? 9 : 8} className="empty">No completed evaluation results found for the selected filters.</td></tr>}</tbody></table></div>
      <section className="performance-report-legend" aria-label="Report legend"><strong>Performance Rating Legend</strong><div><span className="excellent"><i />Excellent <b>4.50–5.00</b></span><span className="very-satisfactory"><i />Very Satisfactory <b>3.75–4.49</b></span><span className="satisfactory"><i />Satisfactory <b>3.00–3.74</b></span><span className="needs-improvement"><i />Needs Improvement <b>Below 3.00</b></span><span className="incomplete"><i />Incomplete / N/A <b>Required results unavailable</b></span></div><small>Badge colors match the performance levels above. Peer = peer evaluators · Head = Dean or Program Head · PH/SC = Program Head / Section Chair · Mean = overall computed rating.</small></section>
      {!overall && <footer className="performance-report-signatory"><div className="signature-space" /><span className="signature-line" /><strong>{report.signatory || 'Dean assignment not configured'}</strong><small>Dean</small><small>{report.department}</small></footer>}</>}
    </div></section>}
  </section>;
}

export default function ReportGrid({ role }) {
  return ['admin', 'vpaa', 'dean', 'programHead'].includes(role?.key) ? <PerformanceReportWorkspace role={role} /> : <RoleReportWorkspace role={role} />;
}
