import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertTriangle, Building2, CheckCircle2, ChevronDown, ChevronUp, Clock3, TrendingDown, TrendingUp, UserRoundX, UsersRound } from 'lucide-react';
import { Bar, Doughnut } from 'react-chartjs-2';
import { ArcElement, BarElement, CategoryScale, Chart as ChartJS, Legend, LinearScale, Tooltip } from 'chart.js';

ChartJS.register(ArcElement, BarElement, CategoryScale, LinearScale, Tooltip, Legend);

function metricDefinitions(basePath, selectedDepartment = '') {
  const admin=basePath==='/admin';
  const workPath=admin?`${basePath}/assignments`:`${basePath}/evaluate`;
  const insightPath=admin?`${basePath}/reports`:`${basePath}/summary`;
  const peoplePath=admin?`${basePath}/people`:insightPath;
  return [
    ['overdue','Overdue Evaluations','danger',AlertTriangle,'Review overdue',`${workPath}?status=overdue`],
    ['below_50','Faculty Below 50%','danger',UserRoundX,'Review faculty',insightPath],
    ['period_people','People in Selected Period','info',UsersRound,'View period people',peoplePath],
    ['people_with_assignments','People With Assignments','info',Building2,'View assignments',workPath],
    ['pending','Pending Evaluations','warning',Clock3,admin?'View in AI Monitoring':'Open pending',admin?`${basePath}/ai-actions?focus=pending${selectedDepartment?`&department=${encodeURIComponent(selectedDepartment)}`:''}`:`${workPath}?status=pending`],
    ['completed','Completed Evaluations','success',CheckCircle2,'View completed',insightPath],
  ];
}

function dateLabel(value) {
  if (!value) return 'No deadline set';
  const date = new Date(`${value}T00:00:00`);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString(undefined,{month:'short',day:'numeric',year:'numeric'});
}

function relativeTime(value) {
  const time=new Date(String(value||'').replace(' ','T')).getTime();
  if (!Number.isFinite(time)) return value;
  const seconds=Math.max(0,Math.floor((Date.now()-time)/1000));
  if (seconds<60) return 'just now';
  const minutes=Math.floor(seconds/60); if (minutes<60) return `${minutes} min ago`;
  const hours=Math.floor(minutes/60); if (hours<24) return `${hours} hr ago`;
  const days=Math.floor(hours/24); return `${days} day${days===1?'':'s'} ago`;
}

function DetailList({ rows, kind }) {
  const [open,setOpen]=useState(false);
  if (!rows?.length) return null;
  const visible=open?rows:rows.slice(0,3);
  return <div className={`dashboard-detail-list${open ? ' is-expanded' : ''}`}>
    {visible.map((row,index)=><div key={row.id || row.code || index}>
      <span>{row.faculty || row.name || row.full_name}</span>
      {kind==='overdue' && <small>{row.days_overdue} day{row.days_overdue===1?'':'s'} overdue · {row.evaluator}</small>}
      {kind==='pending' && <small>{row.requirement_type==='self_evaluation'?'Self-Evaluation not submitted':row.requirement_type==='peer_evaluation'?'Official peer evaluation missing':'No evaluation assignment'} · {String(row.period_role || 'participant').replace('_',' ')}{row.program ? ` · ${row.program}` : ''}</small>}
    </div>)}
    {rows.length>3 && <button className="dashboard-detail-toggle" type="button" onClick={()=>setOpen(v=>!v)} aria-expanded={open}>{open?<ChevronUp size={14}/>:<ChevronDown size={14}/>} {open?'Show less':`Show all ${rows.length}`}</button>}
  </div>;
}

function MetricCard({ definition, overview }) {
  const [key,label,tone,Icon,cta,href]=definition;
  const trend=overview.trends?.[key];
  const delta=trend?.delta;
  const details=key==='overdue'?overview.details?.overdue:key==='pending'?overview.details?.missing_assignments:null;
  const adverse=['overdue','below_50','pending'].includes(key);
  const improved=delta != null && (adverse ? delta<0 : delta>0);
  const deadline=key==='pending'?overview.deadlines?.pending:key==='overdue'?overview.deadlines?.overdue:null;
  return <article className={`admin-overview-metric metric-${tone} metric-key-${key}`}>
    <div className="admin-overview-metric-head"><span className="metric-symbol"><Icon size={18}/></span><span>{label}</span></div>
    <strong>{overview.counts?.[key] ?? '—'}</strong>
    <div className={`metric-trend ${delta==null?'muted':improved?'good':'bad'}`}>
      {delta==null ? 'Select a comparison period' : <>{delta>=0?<TrendingUp size={14}/>:<TrendingDown size={14}/>} {delta>0?'+':''}{delta} vs {overview.comparison?.label || 'selected period'}</>}
    </div>
    {deadline!==null && <p className="metric-deadline">{key==='overdue'?'Earliest overdue':'Next cutoff'}: <b>{dateLabel(deadline)}</b></p>}
    <DetailList rows={details} kind={key}/>
    <Link to={href}>{cta}</Link>
  </article>;
}

export default function AdminDashboardOverview({ overview, loading, error, filters, onFiltersChange, periods=[], selectedPeriodId='', basePath='/admin', scopeName='institution' }) {
  const darkMode = typeof document !== 'undefined'
    && Boolean(document.querySelector('.admin-body.dark-mode, .admin-dashboard-body.dark-mode'));
  const progress=overview?.progress || {total:0,completed:0,pending:0,overdue:0,percentage:0};
  const donut=useMemo(()=>({labels:['Completed','Pending','Overdue'],datasets:[{data:[progress.completed,progress.pending,progress.overdue],backgroundColor:['#169c5b','#e8a317','#d9363e'],borderWidth:0}]}),[progress]);
  const breakdown=(overview?.department_breakdown || []).filter(
    row => String(row.department || '').trim().toLowerCase() !== 'unassigned department'
  );
  const deptData=useMemo(()=>({labels:breakdown.map(r=>r.department),datasets:[
    {label:'Completed',data:breakdown.map(r=>r.completed),backgroundColor:'#169c5b',borderRadius:7,borderSkipped:false,maxBarThickness:34},
    {label:'Pending',data:breakdown.map(r=>r.pending),backgroundColor:'#e8a317',borderRadius:7,borderSkipped:false,maxBarThickness:34},
    {label:'Overdue',data:breakdown.map(r=>r.overdue),backgroundColor:'#d9363e',borderRadius:7,borderSkipped:false,maxBarThickness:34},
  ]}),[breakdown]);
  const breakdownTotals=useMemo(()=>breakdown.reduce((totals,row)=>({
    completed:totals.completed+Number(row.completed||0),
    pending:totals.pending+Number(row.pending||0),
    overdue:totals.overdue+Number(row.overdue||0),
  }),{completed:0,pending:0,overdue:0}),[breakdown]);
  const bands=overview?.performance_distribution || {};
  const comparisonPeriods=(overview?.filters?.periods?.length
    ? overview.filters.periods.map(period=>({id:period.value,label:period.label,status:period.status}))
    : periods.map(period=>({id:period.id,label:period.period_name || period.school_year,status:period.status})));
  const bandData=useMemo(()=>({labels:['Below 50%','50–75%','Above 75%'],datasets:[{label:'Faculty',data:[bands.below_50||0,bands.between_50_75||0,bands.above_75||0],backgroundColor:['#d9363e','#e8a317','#169c5b'],borderRadius:8}]}),[bands]);
  const chartOptions={
    responsive:true,
    maintainAspectRatio:false,
    indexAxis:'y',
    animation:{duration:550,easing:'easeOutQuart'},
    plugins:{
      legend:{position:'bottom',labels:{color:darkMode?'#b8c8dc':'#52695f',usePointStyle:true,pointStyle:'circle',boxWidth:8,boxHeight:8,padding:18,font:{size:11,weight:'600'}}},
      tooltip:{backgroundColor:'rgba(12,38,30,.94)',padding:11,cornerRadius:9,titleFont:{size:12,weight:'700'},bodyFont:{size:11}},
    },
    scales:{
      x:{stacked:true,beginAtZero:true,border:{display:false},grid:{color:darkMode?'rgba(148,163,184,.12)':'rgba(83,116,101,.10)'},ticks:{color:darkMode?'#91a5bd':'#52695f',precision:0,padding:7,font:{size:10}}},
      y:{stacked:true,border:{display:false},grid:{display:false},ticks:{color:darkMode?'#a9bad0':'#52695f',padding:10,font:{size:10,weight:'600'}}},
    },
  };
  return <>
    <div className="admin-overview-filters" aria-label="Dashboard filters">
      <label>College / Department<select value={filters.department} onChange={e=>onFiltersChange({...filters,department:e.target.value,program:''})}><option value="">All departments</option>{overview?.filters?.departments?.map(d=><option key={d.value} value={d.value}>{d.label}</option>)}</select></label>
      <label>Program<select value={filters.program} onChange={e=>onFiltersChange({...filters,program:e.target.value})}><option value="">All programs</option>{overview?.filters?.programs?.map(p=><option key={p.value} value={p.value}>{p.label}</option>)}</select></label>
      <label>Compare with period<select value={filters.comparisonPeriodId} onChange={e=>onFiltersChange({...filters,comparisonPeriodId:e.target.value})}><option value="">Select a period</option>{comparisonPeriods.map(period=><option key={period.id} value={period.id}>{period.label}{String(period.id)===String(selectedPeriodId) ? ' (current)' : period.status ? ` (${period.status})` : ''}</option>)}</select></label>
      {(filters.department||filters.program) && <button type="button" onClick={()=>onFiltersChange({...filters,department:'',program:''})}>Clear filters</button>}
    </div>
    {error && <p className="dashboard-live-warning">Live refresh paused: {error}</p>}
    <div className="admin-overview-lead">
      <article className="attention-total"><span>Items need attention</span><strong>{(overview?.counts?.overdue||0)+(overview?.counts?.below_50||0)+(overview?.counts?.pending||0)}</strong><small>{loading?'Refreshing live data':'Prioritized evaluation checks'}</small></article>
      <article className="completion-panel"><div className="completion-chart"><Doughnut data={donut} options={{responsive:true,maintainAspectRatio:false,cutout:'72%',plugins:{legend:{display:false},tooltip:{enabled:true}}}}/><div><strong>{progress.percentage}%</strong><span>complete</span></div></div><div><h3>Overall evaluation progress</h3><strong>{progress.completed}/{progress.total} completed</strong><p>{progress.pending} pending · {progress.overdue} overdue</p></div></article>
    </div>
    <section className="admin-overview-metrics">{metricDefinitions(basePath, filters.department).map(def=><MetricCard key={def[0]} definition={def} overview={overview||{}} />)}</section>
    <section className="admin-overview-panels">
      <article className="department-completion-panel"><header><div><span>Evaluation status</span><h3>Completion by department</h3><p>Assignment progress within the authorized {scopeName} scope.</p></div><div className="department-chart-totals" aria-label="Filtered assignment totals"><b className="completed">{breakdownTotals.completed}<small>Completed</small></b><b className="pending">{breakdownTotals.pending}<small>Pending</small></b><b className="overdue">{breakdownTotals.overdue}<small>Overdue</small></b></div></header><div className="dashboard-chart department-chart" style={{'--department-chart-height':`${Math.min(390,Math.max(230,breakdown.length*54+120))}px`}}>{breakdown.length?<Bar data={deptData} options={chartOptions}/>:<p className="dashboard-empty">No assignments match these filters.</p>}</div></article>
      <article><header><div><span>Faculty outcomes</span><h3>Performance distribution</h3></div></header><div className="dashboard-chart"><Bar data={bandData} options={{...chartOptions,indexAxis:'x',scales:{x:{stacked:false,border:{display:false},grid:{display:false},ticks:{color:darkMode?'#a9bad0':'#52695f'}},y:{beginAtZero:true,border:{display:false},grid:{color:darkMode?'rgba(148,163,184,.12)':'rgba(83,116,101,.10)'},ticks:{color:darkMode?'#91a5bd':'#52695f',precision:0}}}}}/></div><p className="chart-note">{bands.without_results||0} faculty excluded without completed results.</p></article>
    </section>
  </>;
}
