import { useCallback, useMemo, useState } from 'react';
import {
  AlertCircle, BookOpenCheck, ClipboardCheck, Clock, Lightbulb,
  Search, Star, Target, TrendingDown, TrendingUp, BarChart3, LineChart,
} from 'lucide-react';
import apiFetch from '../../data/api.js';
import { useEvaluationPeriod } from '../../contexts/EvaluationPeriodContext.jsx';
import useLiveRefresh from '../../hooks/useLiveRefresh.js';

// ─── Pure SVG Trend Line Chart ─────────────────────────────────────────
function ScoreTrendChart({ rows }) {
  if (!rows || rows.length < 2) return null;

  const sorted = [...rows]
    .filter((r) => r.overallScore != null)
    .sort((a, b) => (a.period || '').localeCompare(b.period || ''));

  if (sorted.length < 2) return null;

  const W = 560, H = 180, PAD = { top: 20, right: 20, bottom: 30, left: 40 };
  const chartW = W - PAD.left - PAD.right;
  const chartH = H - PAD.top - PAD.bottom;

  const scores = sorted.map((r) => Number(r.overallScore) || 0);
  const minScore = Math.max(0, Math.min(...scores) - 0.5);
  const maxScore = Math.min(5, Math.max(...scores) + 0.5);
  const range = maxScore - minScore || 1;

  const xScale = (i) => PAD.left + (i / (sorted.length - 1)) * chartW;
  const yScale = (v) => PAD.top + chartH - ((v - minScore) / range) * chartH;

  const linePath = sorted
    .map((r, i) => `${i === 0 ? 'M' : 'L'}${xScale(i).toFixed(1)},${yScale(Number(r.overallScore) || 0).toFixed(1)}`)
    .join(' ');

  const areaPath = `${linePath} L${xScale(sorted.length - 1).toFixed(1)},${yScale(0).toFixed(1)} L${xScale(0).toFixed(1)},${yScale(0).toFixed(1)} Z`;

  const yTicks = [];
  for (let v = Math.ceil(minScore); v <= Math.floor(maxScore); v++) {
    yTicks.push(v);
  }

  return (
    <div className="personal-chart-card">
      <div className="personal-chart-head">
        <LineChart size={16} />
        <span>Score Trend</span>
        <small>{sorted.length} periods</small>
      </div>
      <div className="personal-chart-body">
        <svg viewBox={`0 0 ${W} ${H}`} className="personal-trend-svg" aria-label="Score trend chart">
          {/* Grid lines */}
          {yTicks.map((v) => (
            <g key={v}>
              <line
                x1={PAD.left} y1={yScale(v)}
                x2={W - PAD.right} y2={yScale(v)}
                stroke="var(--theme-border, #e5e7eb)" strokeWidth="1"
              />
              <text x={PAD.left - 6} y={yScale(v) + 4} textAnchor="end" fontSize="10" fill="var(--theme-muted, #6b7280)">
                {v}.0
              </text>
            </g>
          ))}

          {/* Area fill */}
          <path d={areaPath} fill="var(--theme-primary, #3b82f6)" fillOpacity="0.08" />

          {/* Line */}
          <path d={linePath} fill="none" stroke="var(--theme-primary, #3b82f6)" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />

          {/* Dots */}
          {sorted.map((r, i) => {
            const cx = xScale(i);
            const cy = yScale(Number(r.overallScore) || 0);
            const score = Number(r.overallScore) || 0;
            const prevScore = i > 0 ? (Number(sorted[i - 1]?.overallScore) || 0) : null;
            const isUp = prevScore !== null && score > prevScore;
            const isDown = prevScore !== null && score < prevScore;
            const labelColor = isUp ? '#16a34a' : isDown ? '#dc2626' : '#6b7280';
            return (
              <g key={r.periodKey || i}>
                <circle cx={cx} cy={cy} r="4" fill="var(--theme-primary, #3b82f6)" stroke="#fff" strokeWidth="2" />
                <title>{r.period}: {score.toFixed(2)}/5</title>
                {/* Label above dot */}
                <text x={cx} y={cy - 10} textAnchor="middle" fontSize="10" fontWeight="600" fill={labelColor}>
                  {score.toFixed(2)}
                </text>
              </g>
            );
          })}

          {/* X-axis labels */}
          {sorted.map((r, i) => (
            <text
              key={r.periodKey || i}
              x={xScale(i)}
              y={H - 6}
              textAnchor={i === 0 ? 'start' : i === sorted.length - 1 ? 'end' : 'middle'}
              fontSize="9"
              fill="var(--theme-muted, #6b7280)"
            >
              {r.year || r.period?.slice(0, 10) || `P${i + 1}`}
            </text>
          ))}
        </svg>
      </div>
    </div>
  );
}

// ─── Pure SVG Horizontal Bar Chart (Category Scores) ─────────────────
function CategoryBarChart({ strengths, weaknesses }) {
  const combined = useMemo(() => {
    const map = new Map();
    (strengths || []).forEach((s) => {
      map.set(s.category, { category: s.category, score: Number(s.score) || 0, type: 'strength' });
    });
    (weaknesses || []).forEach((w) => {
      if (map.has(w.category)) {
        const existing = map.get(w.category);
        existing.score = (existing.score + Number(w.score) || 0) / 2;
      } else {
        map.set(w.category, { category: w.category, score: Number(w.score) || 0, type: 'weakness' });
      }
    });
    return [...map.values()].sort((a, b) => b.score - a.score);
  }, [strengths, weaknesses]);

  if (combined.length === 0) return null;

  const BAR_H = 24;
  const GAP = 10;
  const LABEL_W = 160;
  const CHART_H = combined.length * (BAR_H + GAP) + 10;
  const W = 480;
  const barMaxW = W - LABEL_W - 50;

  return (
    <div className="personal-chart-card">
      <div className="personal-chart-head">
        <BarChart3 size={16} />
        <span>Category Scores</span>
        <small>{combined.length} categories</small>
      </div>
      <div className="personal-chart-body">
        <svg viewBox={`0 0 ${W} ${CHART_H}`} className="personal-bar-svg" aria-label="Category scores bar chart">
          {combined.map((item, i) => {
            const y = i * (BAR_H + GAP) + 6;
            const pct = (item.score / 5) * 100;
            const color = item.score >= 4 ? '#16a34a' : item.score >= 3 ? '#ca8a04' : '#dc2626';
            return (
              <g key={item.category}>
                {/* Label */}
                <text x={LABEL_W - 8} y={y + BAR_H / 2 + 4} textAnchor="end" fontSize="11" fontWeight="500" fill="var(--theme-text, #111827)">
                  {item.category.length > 22 ? item.category.slice(0, 20) + '…' : item.category}
                </text>
                {/* Bar background */}
                <rect x={LABEL_W} y={y} width={barMaxW} height={BAR_H} rx="4" fill="var(--theme-border, #e5e7eb)" />
                {/* Bar fill */}
                <rect x={LABEL_W} y={y} width={Math.max(4, (pct / 100) * barMaxW)} height={BAR_H} rx="4" fill={color} opacity="0.85">
                  <title>{item.category}: {item.score.toFixed(2)}/5</title>
                </rect>
                {/* Score text */}
                <text x={LABEL_W + Math.max(4, (pct / 100) * barMaxW) + 6} y={y + BAR_H / 2 + 4} fontSize="11" fontWeight="600" fill={color}>
                  {item.score.toFixed(2)}
                </text>
              </g>
            );
          })}
        </svg>
      </div>
    </div>
  );
}

function CategoryScorePerEvaluator({ rows }) {
  const [selected, setSelected] = useState('all');

  const options = useMemo(() => {
    const result = [{ value: 'all', label: 'All Evaluators' }];
    const typeOrder = [
      'Self-Assessment',
      'Peer Evaluation',
      'Program Head Evaluation',
      'Dean Evaluation',
      'VPAA Evaluation',
    ];
    const presentTypes = new Set((rows || []).map((row) => row.evaluationType).filter(Boolean));
    typeOrder.forEach((type) => {
      if (presentTypes.has(type)) result.push({ value: `type:${type}`, label: type });
    });

    const evaluatorMap = new Map();
    (rows || []).forEach((row) => {
      const key = String(row.evaluatorId || row.assignmentId || row.evaluatorName || '');
      if (!key || evaluatorMap.has(key)) return;
      evaluatorMap.set(key, {
        value: `evaluator:${key}`,
        label: `${row.evaluatorName || 'Evaluator'} - ${row.evaluationType || 'Evaluation'}`,
      });
    });

    return [...result, ...evaluatorMap.values()];
  }, [rows]);

  const visibleRows = useMemo(() => {
    if (!selected || selected === 'all') return rows || [];
    if (selected.startsWith('type:')) {
      const type = selected.slice(5);
      return (rows || []).filter((row) => row.evaluationType === type);
    }
    if (selected.startsWith('evaluator:')) {
      const id = selected.slice(10);
      return (rows || []).filter((row) => String(row.evaluatorId || row.assignmentId || row.evaluatorName || '') === id);
    }
    return rows || [];
  }, [rows, selected]);

  const scoreSummary = useMemo(() => {
    const ratings = visibleRows.map((row) => Number(row.averageRating) || 0).filter((score) => score > 0);
    const weighted = visibleRows.reduce((sum, row) => sum + (Number(row.weightedScore) || 0), 0);
    return {
      average: ratings.length ? ratings.reduce((sum, score) => sum + score, 0) / ratings.length : 0,
      weighted,
      count: visibleRows.length,
    };
  }, [visibleRows]);

  if (!rows || rows.length === 0) return null;

  return (
    <section className="category-evaluator-panel">
      <div className="category-evaluator-head">
        <div>
          <p className="eyebrow">Category Score per Evaluator</p>
          <h3>Evaluator Contribution Breakdown</h3>
        </div>
        <label className="category-evaluator-filter">
          <span>View by evaluator</span>
          <select value={selected} onChange={(event) => setSelected(event.target.value)}>
            {options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
          </select>
        </label>
      </div>

      <div className="category-evaluator-summary">
        <article><span>Visible Categories</span><strong>{scoreSummary.count}</strong></article>
        <article><span>Average Rating</span><strong>{scoreSummary.average ? scoreSummary.average.toFixed(2) : '--'}<small>/5</small></strong></article>
        <article><span>Total Weighted</span><strong>{scoreSummary.weighted ? scoreSummary.weighted.toFixed(4) : '--'}</strong></article>
      </div>

      {visibleRows.length === 0 ? (
        <div className="eval-monitor-empty">
          <ClipboardCheck size={28} />
          <strong>No category scores found</strong>
          <p>No submitted category scores are available for the selected evaluator filter.</p>
        </div>
      ) : (
        <div className="category-evaluator-list">
          {visibleRows.map((row, index) => (
            <article className="category-evaluator-card" key={`${row.assignmentId}-${row.category}-${index}`}>
              <div className="category-evaluator-card-head">
                <div>
                  <span>{row.evaluationType}</span>
                  <strong>{row.category}</strong>
                  <small>{row.evaluatorName} - {row.form}</small>
                </div>
                <div className="category-evaluator-score">
                  <strong>{Number(row.averageRating || 0).toFixed(2)}</strong>
                  <span>/5</span>
                </div>
              </div>
              <div className="category-evaluator-metrics">
                <span>Total rating: <strong>{Number(row.totalRating || 0).toFixed(2)}</strong></span>
                <span>Questions: <strong>{row.questionCount || 0}</strong></span>
                <span>Factor weight: <strong>{Number(row.factorWeight || 0).toFixed(2)}%</strong></span>
                <span>Weighted score: <strong>{Number(row.weightedScore || 0).toFixed(4)}</strong></span>
              </div>
              <div className="category-evaluator-notes">
                {row.reasonForRating && <p><strong>Comment:</strong> {row.reasonForRating}</p>}
                {row.behavioralEvidence && <p><strong>Behavioral evidence:</strong> {row.behavioralEvidence}</p>}
                {row.recommendation && <p><strong>Recommendation:</strong> {row.recommendation}</p>}
                {!row.reasonForRating && !row.behavioralEvidence && !row.recommendation && <p>No comments or behavioral evidence were submitted for this category.</p>}
              </div>
            </article>
          ))}
        </div>
      )}
    </section>
  );
}

// ─── Main Component ──────────────────────────────────────────────────
export default function PersonalPerformanceSummary({ receivedCount = 0 }) {
  const { selectedPeriodId } = useEvaluationPeriod();
  const [rows, setRows] = useState([]);
  const [summary, setSummary] = useState({ latestScore: null, latestPeriod: '', canRevealResults: true, completion: null, message: '' });
  const [insights, setInsights] = useState({ strengths: [], weaknesses: [], recommendations: [] });
  const [categoryEvaluatorResults, setCategoryEvaluatorResults] = useState([]);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const loadResults = useCallback(async (background = false) => {
    if (!background) setLoading(true);
    setError('');
    try {
      const params = new URLSearchParams();
      if (selectedPeriodId) params.set('period_id', selectedPeriodId);
      params.set('_', String(Date.now()));
      const payload = await apiFetch(`/api/my-evaluation-results.php?${params.toString()}`);
      if (!payload.ok) throw new Error(payload.message || 'Unable to load evaluation results.');
      setRows(Array.isArray(payload.data) ? payload.data : []);
      setSummary(payload.summary || {});
      setInsights(payload.insights || { strengths: [], weaknesses: [], recommendations: [] });
      setCategoryEvaluatorResults(Array.isArray(payload.categoryEvaluatorResults) ? payload.categoryEvaluatorResults : []);
    } catch (err) {
      setError(err.message || 'Unable to load evaluation results.');
    } finally {
      if (!background) setLoading(false);
    }
  }, [selectedPeriodId]);

  const { refreshing: liveRefreshing } = useLiveRefresh(loadResults, [selectedPeriodId], {
    intervalMs: 6000,
  });

  const filteredRows = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return rows;
    return rows.filter((row) => `${row.period} ${row.year} ${row.performanceLevel} ${row.status}`.toLowerCase().includes(q));
  }, [rows, search]);

  const latestScore = summary.latestScore ? Number(summary.latestScore).toFixed(2) : null;
  const latestPeriod = summary.latestPeriod || 'No period yet';
  const scorePct = latestScore ? Math.round((parseFloat(latestScore) / 5) * 100) : 0;
  const canRevealResults = summary.canRevealResults !== false;
  const completion = summary.completion || { total: receivedCount || rows.length, submitted: rows.length, pending: 0 };

  return (
    <div className="eval-monitor-container module-wide page-enter personal-performance-summary">
      {/* Loading Skeleton */}
      {loading && (
        <div className="eval-monitor-skeleton">
          {[1, 2, 3].map((i) => (
            <div key={i} className="eval-monitor-skeleton-card">
              <div className="skeleton-line w-24" />
              <div className="skeleton-line w-32" />
              <div className="skeleton-line w-full" />
            </div>
          ))}
        </div>
      )}

      {error && <div className="eval-monitor-empty error">{error}</div>}

      {!loading && !error && (
        <>
          {/* Hero Section */}
          <div className="eval-monitor-hero">
            <div>
              <p className="eyebrow">Your Evaluation Results</p>
              <h2>Personal Performance Summary</h2>
              <p>
                {canRevealResults
                  ? 'APPRAISIA has consolidated your submitted appraisal records into overall ratings, strengths, improvement areas, and development recommendations for the selected evaluation period.'
                  : 'Your overall performance summary remains locked until all required evaluators have successfully submitted their evaluations for the selected period.'}
              </p>
              {liveRefreshing && <span className="live-refresh-indicator">Updating summary...</span>}
            </div>
            <div className="eval-monitor-hero-chart">
              <div className="eval-monitor-donut" style={{ '--pct': scorePct }}>
                <strong>{latestScore || '--'}</strong>
                <span>Latest Score</span>
              </div>
              <div className="eval-monitor-hero-stats">
                <span><ClipboardCheck size={14} /> Received: {receivedCount || rows.length}</span>
                <span><Star size={14} /> Score: {latestScore || 'Pending'}</span>
                <span><Clock size={14} /> Period: {latestPeriod}</span>
                <span><Target size={14} /> Results: {rows.length}</span>
              </div>
            </div>
          </div>

          <div className="personal-summary-explainer" aria-label="Personal performance summary process">
            <article>
              <span><ClipboardCheck size={15} /> Progress</span>
              <p>Track evaluations received, appraisal cycle status, and completion for the selected period.</p>
            </article>
            <article>
              <span><BarChart3 size={15} /> Scoring</span>
              <p>Results use predefined algorithms, weighted averages, and submitted assessment records.</p>
            </article>
            <article>
              <span><Lightbulb size={15} /> Feedback</span>
              <p>Unlocked summaries show strengths, areas for improvement, and development recommendations.</p>
            </article>
          </div>

          {/* Metrics Row */}
          <div className="eval-monitor-metrics">
            <article className="metric-primary">
              <span>Evaluations Received</span>
              <strong>{completion.submitted || receivedCount || rows.length}</strong>
              <small>{completion.total ? `of ${completion.total} submitted` : 'Total completed evaluations'}</small>
            </article>
            <article className="metric-success">
              <span>Latest Overall Score</span>
              <strong>{latestScore || 'No result yet'}</strong>
              <small>Most recent period average</small>
            </article>
            <article className="metric-info">
              <span>Latest Period</span>
              <strong>{latestPeriod}</strong>
              <small>Most recent appraisal cycle</small>
            </article>
          </div>

          {/* ── Charts Row ─────────────────────────────────────────── */}
          {canRevealResults && rows.length > 0 && (
            <div className="personal-charts-row">
              <ScoreTrendChart rows={rows} />
              <CategoryBarChart
                strengths={insights.strengths}
                weaknesses={insights.weaknesses}
              />
            </div>
          )}

          {canRevealResults && (
            <CategoryScorePerEvaluator rows={categoryEvaluatorResults} />
          )}

          {!canRevealResults && (
            <div className="faculty-results-locked">
              <AlertCircle size={24} />
              <div>
                <strong>Overall results are locked until evaluations are complete.</strong>
                <p>{summary.message || `Waiting for ${completion.pending || 0} evaluator${completion.pending === 1 ? '' : 's'} to submit for ${latestPeriod}.`}</p>
              </div>
            </div>
          )}

          {canRevealResults && (
            <div className="faculty-ai-insights">
              <article className="faculty-insight-card strengths">
                <div className="faculty-insight-head">
                  <TrendingUp size={18} />
                  <div>
                    <span>Strengths</span>
                    <strong>Highest rated areas</strong>
                  </div>
                </div>
                {insights.strengths?.length > 0 ? insights.strengths.map((item) => (
                  <div className="faculty-insight-row" key={`strength-${item.category}`}>
                    <span>{item.category}</span>
                    <strong>{Number(item.score || 0).toFixed(2)}/5</strong>
                  </div>
                )) : <p>No strength areas available yet.</p>}
              </article>

              <article className="faculty-insight-card weaknesses">
                <div className="faculty-insight-head">
                  <TrendingDown size={18} />
                  <div>
                    <span>Areas for Improvement</span>
                    <strong>Priority development areas</strong>
                  </div>
                </div>
                {insights.weaknesses?.length > 0 ? insights.weaknesses.map((item) => (
                  <div className="faculty-insight-row" key={`weakness-${item.category}`}>
                    <span>{item.category}</span>
                    <strong>{Number(item.score || 0).toFixed(2)}/5</strong>
                  </div>
                )) : <p>No weak areas available yet.</p>}
              </article>

              <article className="faculty-insight-card recommendations">
                <div className="faculty-insight-head">
                  <Lightbulb size={18} />
                  <div>
                    <span>Development Recommendations</span>
                    <strong>Suggested next actions</strong>
                  </div>
                </div>
                {insights.recommendations?.length > 0 ? insights.recommendations.map((item) => (
                  <div className="faculty-recommendation-row" key={`recommendation-${item.category}`}>
                    <BookOpenCheck size={16} />
                    <div>
                      <strong>{item.seminar}</strong>
                      <p>{item.action}</p>
                    </div>
                  </div>
                )) : <p>Recommendations will appear once category scores are available.</p>}
              </article>
            </div>
          )}

          {/* Search / Filter */}
          {canRevealResults && <div className="eval-monitor-table-container">
            <div className="eval-monitor-toolbar">
              <div className="eval-monitor-search">
                <Search size={16} />
                <input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search period, year, level, status..." />
              </div>
              <div className="eval-monitor-toolbar-actions">
                <small>{filteredRows.length} result{filteredRows.length !== 1 ? 's' : ''}</small>
              </div>
            </div>

            {filteredRows.length === 0 && (
              <div className="eval-monitor-empty">
                <ClipboardCheck size={28} />
                <strong>No evaluation results found</strong>
                <p>{search ? 'No results match your search criteria.' : 'Completed evaluations will appear here once your peers submit their ratings.'}</p>
              </div>
            )}

            {filteredRows.length > 0 && (
              <div className="eval-monitor-dept-grid">
                {filteredRows.map((row) => (
                    <div className="eval-monitor-dept-card" key={row.periodKey || row.period}>
                    <div className="eval-monitor-dept-card-header">
                      <div className="eval-monitor-dept-card-icon">
                        <ClipboardCheck size={20} />
                      </div>
                      <div>
                        <h3>Evaluation Period</h3>
                        <span className="eval-monitor-dept-code">{row.period}</span>
                      </div>
                    </div>
                    <div className="eval-monitor-dept-card-body">
                      <div className="eval-monitor-dept-card-meta">
                        <span>Performance Level: {row.performanceLevel || 'Pending'}</span>
                        <span>Status: {row.status || 'Completed'}</span>
                        {row.totalAssignments ? <span>Evaluators: {row.completedAssignments}/{row.totalAssignments}</span> : null}
                      </div>
                      <div className="eval-monitor-dept-card-score">
                        <span>Overall Score</span>
                        <strong>{row.overallScore ? Number(row.overallScore).toFixed(2) : '--'}<small>/5</small></strong>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>}
        </>
      )}
    </div>
  );
}
