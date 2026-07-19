import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Bot, CalendarDays, ClipboardList, Copy, FileQuestion, MessageCircle, Sparkles, Target, X } from 'lucide-react';
import { apiUrl } from '../../data/apiBase.js';
import { assistantRobotImage } from '../../data/visualAssets.js';
import PageChatbotIntro from './PageChatbotIntro.jsx';
import { useEvaluationPeriod } from '../../contexts/EvaluationPeriodContext.jsx';

const robotImage = assistantRobotImage;
const welcome = 'Hi, my name is APPRAISIA, your personal assistant. How can I help you today?';

const chatSuggestions = {
  admin: [
    {
      title: 'Progress',
      questions: [
        'Which departments are below 70% completion, what is blocking them, and what should HR prioritize this week?',
        'Find duplicate, reassigned, or not-required evaluator records in the current cycle.',
        'Compare department completion with the previous period and identify the three largest declines.',
        'Draft an institution-wide evaluation follow-up plan using current risks.',
      ],
    },
    {
      title: 'Performance',
      questions: [
        'Show lowest-performing program.',
        'What are the top weak areas?',
        'Which faculty members need intervention?',
        'Summarize recent AI insights.',
      ],
    },
    {
      title: 'Questionnaires',
      questions: [
        'How many Form A and Form B questions are active?',
        'Explain Form A versus Form B.',
        'Why is behavioral evidence required?',
        'What should be checked before submission?',
      ],
    },
  ],
  vpaa: [
    {
      title: 'VPAA Review',
      questions: [
        'Generate VPAA summary',
        'Compare Dean evaluation completion and recurring weaknesses across my departments for the last three periods.',
        'Which assigned department has the greatest combined completion and performance risk?',
        'Draft VPAA priorities based on overdue evaluations and repeated weak areas.',
      ],
    },
    {
      title: 'Follow Up',
      questions: [
        'Show pending evaluations',
        'Which faculty need intervention?',
        'What weak areas are common in my departments?',
        'What development actions should be prioritized?',
      ],
    },
  ],
  dean: [
    {
      title: 'Department',
      questions: [
        'Which faculty have submitted Self Evaluations but still lack reviewer confirmation?',
        'Which weak areas repeat across faculty and periods in my department?',
        'Compare my department completion and scores with the previous period.',
        'Draft a department review checklist ordered by urgency.',
      ],
    },
    {
      title: 'Actions',
      questions: [
        'Show training plans for my department',
        'Which faculty members are pending evaluation?',
        'What interventions should I prioritize?',
        'What should I review before submitting department summary?',
      ],
    },
  ],
  programHead: [
    {
      title: 'Program',
      questions: [
        'Which faculty in my program declined in two or more categories, and what evidence supports the change?',
        'Which pending reviews should I complete first based on deadlines and risk?',
        'Compare program strengths and weak areas across the latest two periods.',
        'Draft a coaching agenda for the highest-priority faculty needs.',
      ],
    },
    {
      title: 'Tasks',
      questions: [
        'List pending evaluations I need to complete',
        'What training should my program prioritize?',
        'What should I check before submitting evaluations?',
      ],
    },
  ],
  faculty: [
    {
      title: 'My Results',
      questions: [
        'Compare my latest two periods and explain my three largest category changes.',
        'Which evidence supports my strongest and weakest categories?',
        'Draft three measurable development goals based on my latest results.',
        'Explain my current Self Evaluation and assignment status.',
      ],
    },
    {
      title: 'Guidance',
      questions: [
        'What training or development is recommended?',
        'How do I start my evaluation?',
        'Why is behavioral evidence required?',
        'What should I prepare before answering the questionnaire?',
      ],
    },
  ],
};

const assistantModes = [
  {
    key: 'overview',
    label: 'Overview',
    icon: Bot,
    hint: 'Summarize the user scope and current priorities.',
    prompt: 'Give me a personalized overview for my role.',
  },
  {
    key: 'compare',
    label: 'Compare',
    icon: Sparkles,
    hint: 'Compare periods, categories, or authorized organizational scopes.',
    prompt: 'Compare the latest evaluation results with the previous period and highlight important changes.',
  },
  {
    key: 'explain',
    label: 'Explain',
    icon: FileQuestion,
    hint: 'Explain results, evidence, status, and calculations.',
    prompt: 'Explain the most important result and the evidence supporting it.',
  },
  {
    key: 'risk',
    label: 'Risk Check',
    icon: Target,
    hint: 'Identify overdue, missing, declining, or incomplete records.',
    prompt: 'Identify the highest-priority evaluation risks in my authorized scope.',
  },
  {
    key: 'draft',
    label: 'Draft Plan',
    icon: Target,
    hint: 'Draft recommendations without changing PMAS records.',
    prompt: 'Draft a prioritized action plan from the current authorized evaluation data.',
  },
];

const quickReplies = [
  { label: 'Evaluation Status', prompt: 'How do I check my evaluation status?' },
  { label: 'What is PMAS?', prompt: 'Explain PMAS and how this system works.' },
  { label: 'Reports', prompt: 'Where can I view evaluation reports?' },
  { label: 'Schedule', prompt: 'How do I check evaluation schedules and deadlines?' },
];

function assistantUrl() {
  return '/api/assistant.php';
}

function pageAwareQuestion(roleKey, pathname) {
  const section = pathname.split('/').filter(Boolean)[1] || 'overview';
  const prompts = {
    admin: {
      assignments: 'Audit the selected period for overdue, duplicate, reassigned, and missing evaluator requirements.',
      'ai-actions': 'Identify the highest-priority performance risks and draft an evidence-based intervention summary.',
      people: 'Check for leadership, department, and program assignment gaps that could affect evaluations.',
      reports: 'Summarize which evaluation report should be generated for the current decision.',
    },
    dean: {
      evaluate: 'Which department evaluation tasks should I review first, and why?',
      'self-evaluation-review': 'Which submitted Self Evaluations still need my confirmation or decision?',
      summary: 'Explain the most important department weak-area pattern and recommended response.',
    },
    programHead: {
      evaluate: 'Prioritize my pending faculty and peer evaluation tasks by deadline and risk.',
      'self-evaluation-review': 'Which faculty Self Evaluations need Program Head review in this period?',
      summary: 'Draft a coaching agenda from my program’s current strengths and weak areas.',
    },
    vpaa: {
      evaluate: 'Prioritize pending Dean evaluations across my authorized academic scope.',
      analytics: 'Compare department trends and explain the highest institutional risk.',
    },
    faculty: {
      evaluate: 'Explain my pending evaluation and Self Evaluation tasks for the selected period.',
      results: 'Compare my latest results and draft measurable improvement goals.',
    },
  };
  return prompts[roleKey]?.[section] || 'Give me a role-specific analysis of the most important information on this page.';
}

async function readAssistantResponse(response) {
  const contentType = response.headers.get('content-type') || '';
  const body = await response.text();

  if (!contentType.includes('application/json')) {
    throw new Error(`Assistant returned ${response.status} ${response.statusText || 'response'} instead of JSON.`);
  }

  try {
    return JSON.parse(body);
  } catch (error) {
    throw new Error('Assistant returned invalid JSON.');
  }
}

export default function FloatingChat({ role }) {
  const navigate = useNavigate();
  const { selectedPeriod } = useEvaluationPeriod();
  const [open, setOpen] = useState(false);
  const [showBubble, setShowBubble] = useState(() => window.localStorage.getItem('dipascaf-chat-welcome-shown') !== '1');
  const [pageIntroVisible, setPageIntroVisible] = useState(false);
  const [message, setMessage] = useState('');
  const [messages, setMessages] = useState([{ from: 'Assistant', text: welcome }]);
  const [sending, setSending] = useState(false);
  const [activeMode, setActiveMode] = useState('overview');
  const [copiedIndex, setCopiedIndex] = useState(null);
  const logRef = useRef(null);
  const suggestionGroups = [
    { title: 'This Page', questions: [pageAwareQuestion(role.key, window.location.pathname)] },
    ...(chatSuggestions[role.key] || chatSuggestions.admin),
  ];
  const currentMode = assistantModes.find((mode) => mode.key === activeMode) || assistantModes[0];
  const userName = role.user?.name || 'Current user';
  const userScope = [role.user?.department, role.user?.program].filter(Boolean).join(' / ');

  useEffect(() => {
    if (logRef.current) {
      logRef.current.scrollTop = logRef.current.scrollHeight;
    }
  }, [messages, open]);

  useEffect(() => {
    if (copiedIndex === null) return undefined;
    const timer = window.setTimeout(() => setCopiedIndex(null), 1600);
    return () => window.clearTimeout(timer);
  }, [copiedIndex]);

  function openChat(sample = '') {
    setOpen(true);
    setShowBubble(false);
    window.localStorage.setItem('dipascaf-chat-welcome-shown', '1');
    if (sample) setMessage(sample);
  }

  async function submitMessage(clean, modeOverride = activeMode) {
    if (!clean || sending) return;
    const pendingId = `pending-${Date.now()}`;
    const recentMessages = messages
      .slice(-6)
      .map((item) => ({ from: item.from, text: item.text }))
      .filter((item) => !String(item.text || '').includes('Thinking...'));

    setMessages((current) => [
      ...current,
      { from: 'You', text: clean },
      { id: pendingId, from: 'Assistant', text: 'Thinking...' },
    ]);
    setMessage('');
    setSending(true);

    try {
      const response = await fetch(apiUrl(assistantUrl()), {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          message: clean,
          role_key: role.key,
          role_name: role.user.name,
          assistant_mode: modeOverride,
          page_path: window.location.pathname,
          user_scope: userScope,
          recent_messages: JSON.stringify(recentMessages),
          selected_period: selectedPeriod?.period_name || '',
        }),
      });

      // If the API redirected to login (session expired), redirect the user
      if (response.status === 401 || response.status === 403 || (response.headers.get('content-type') || '').includes('text/html')) {
        try { localStorage.removeItem('dipascaf-session'); } catch (_) {}
        window.location.href = '/login';
        return;
      }

      const payload = await readAssistantResponse(response);
      const answer = payload.answer || payload.error || 'The assistant could not return an answer.';

      setMessages((current) => current.map((item) => (
        item.id === pendingId ? { from: 'Assistant', text: answer, payload } : item
      )));
    } catch (error) {
      setMessages((current) => current.map((item) => (
        item.id === pendingId
          ? { from: 'Assistant', text: `${error.message} Please make sure Apache is running in XAMPP, then try again.` }
          : item
      )));
    } finally {
      setSending(false);
    }
  }

  async function submit(event) {
    event.preventDefault();
    await submitMessage(message.trim());
  }

  async function askSuggestion(suggestion) {
    setOpen(true);
    setShowBubble(false);
    window.localStorage.setItem('dipascaf-chat-welcome-shown', '1');
    await submitMessage(suggestion);
  }

  async function askModePrompt(mode) {
    setActiveMode(mode.key);
    setOpen(true);
    setShowBubble(false);
    window.localStorage.setItem('dipascaf-chat-welcome-shown', '1');
    await submitMessage(mode.prompt, mode.key);
  }

  async function copyMessage(text, index) {
    try {
      await navigator.clipboard.writeText(text);
      setCopiedIndex(index);
    } catch (_) {
      setCopiedIndex(null);
    }
  }

  return (
    <>
      <button className={`floating-chat-toggle chatbot-pulse ${open ? 'is-open' : ''}`} type="button" aria-label="Open APPRAISIA assistant" aria-expanded={open} onClick={() => openChat()}>
        <img className="floating-chat-logo" src={robotImage} alt="" aria-hidden="true" />
        <MessageCircle className="sr-only" />
      </button>
      <PageChatbotIntro role={role} onVisibilityChange={setPageIntroVisible} />
      {showBubble && !pageIntroVisible && (
        <aside className="floating-chat-nudge is-visible" role="status" aria-live="polite">
          <span className="floating-chat-nudge-label">APPRAISIA Assistant</span>
          <p>{welcome}</p>
          <button className="floating-chat-nudge-close" type="button" aria-label="Close AI assistant message" onClick={() => { setShowBubble(false); window.localStorage.setItem('dipascaf-chat-welcome-shown', '1'); }}>
            <X className="h-4 w-4" />
          </button>
        </aside>
      )}
      {open && (
        <section className="floating-chat-panel" aria-label="APPRAISIA assistant">
          <div className="floating-chat-header">
            <div>
              <strong>APPRAISIA Assistant</strong>
              <span>{role.portal} support for {userName}</span>
            </div>
            <button type="button" aria-label="Close assistant" onClick={() => setOpen(false)}>x</button>
          </div>
          <div className="floating-chat-context">
            <div>
              <span>Active mode</span>
              <strong>{currentMode.label}</strong>
              <small>{currentMode.hint}</small>
            </div>
            <div>
              <span>User scope</span>
              <strong>{role.user?.databaseRole || role.portal}</strong>
              <small>{userScope || 'Role-based dashboard context'}</small>
            </div>
          </div>
          <div className="floating-chat-modes" aria-label="Assistant modes">
            {assistantModes.map((mode) => {
              const Icon = mode.icon;
              return (
                <button
                  key={mode.key}
                  type="button"
                  className={activeMode === mode.key ? 'active' : ''}
                  onClick={() => setActiveMode(mode.key)}
                  title={mode.hint}
                >
                  <Icon size={14} />
                  {mode.label}
                </button>
              );
            })}
          </div>
          <div className="floating-chat-starters" aria-label="Quick replies">
            {quickReplies.map((reply) => {
              const Icon = reply.label === 'Schedule' ? CalendarDays : reply.label === 'Reports' ? ClipboardList : reply.label === 'What is PMAS?' ? FileQuestion : Sparkles;
              return (
                <button key={reply.label} type="button" onClick={() => askSuggestion(reply.prompt)} disabled={sending}>
                  <Icon size={14} />
                  {reply.label}
                </button>
              );
            })}
          </div>
          <div className="chat-log floating-chat-log" ref={logRef}>
            {messages.map((item, index) => (
              <div key={`${item.from}-${index}`} className={`chat-message ${item.from === 'You' ? 'user' : 'assistant'}`}>
                <div className="chat-bubble" style={{whiteSpace:'pre-wrap'}}>
                  <strong>{item.from}</strong>
                  {item.text}
                  {item.from !== 'You' && item.payload && (
                    <div className="chat-structured-response">
                      {(item.payload.metrics || []).length > 0 && (
                        <div className="chat-response-metrics">
                          {item.payload.metrics.map((metric) => <span key={metric.label}><small>{metric.label}</small><strong>{metric.value}</strong></span>)}
                        </div>
                      )}
                      {(item.payload.evidence || []).length > 0 && (
                        <details><summary>Evidence and scope</summary>{item.payload.evidence.map((entry) => <p key={entry}>{entry}</p>)}</details>
                      )}
                      {(item.payload.warnings || []).length > 0 && (
                        <details className="has-warning"><summary>Data warnings</summary>{item.payload.warnings.map((entry) => <p key={entry}>{entry}</p>)}</details>
                      )}
                      {item.payload.draft && <p className="chat-draft-notice">{item.payload.draft}</p>}
                      {item.payload.navigation?.path && (
                        <button className="chat-navigation-action" type="button" onClick={() => { navigate(item.payload.navigation.path); setOpen(false); }}>
                          {item.payload.navigation.label || 'Open workspace'}
                        </button>
                      )}
                      {(item.payload.follow_ups || []).length > 0 && (
                        <div className="chat-follow-ups" aria-label="Suggested follow-up questions">
                          <span>Ask next</span>
                          {item.payload.follow_ups.map((followUp) => <button type="button" key={followUp} onClick={() => submitMessage(followUp)} disabled={sending}>{followUp}</button>)}
                        </div>
                      )}
                    </div>
                  )}
                  {item.from !== 'You' && !String(item.text || '').includes('Thinking...') && (
                    <div className="chat-message-actions">
                      <button type="button" onClick={() => copyMessage(item.text, index)} title="Copy response">
                        <Copy size={13} />
                        {copiedIndex === index ? 'Copied' : 'Copy'}
                      </button>
                      <button type="button" onClick={() => submitMessage('Turn that into a prioritized action plan.')} disabled={sending}>
                        <ClipboardList size={13} />
                        Action plan
                      </button>
                    </div>
                  )}
                </div>
              </div>
            ))}
          </div>
          <div className="floating-chat-quick-actions" aria-label="Mode quick actions">
            {assistantModes.map((mode) => (
              <button key={mode.key} type="button" onClick={() => askModePrompt(mode)} disabled={sending}>
                {mode.prompt}
              </button>
            ))}
          </div>
          <div className="floating-chat-samples" aria-label="Suggested assistant questions">
            {suggestionGroups.map((group) => (
              <div className="floating-chat-sample-group" key={group.title}>
                <span>{group.title}</span>
                <div>
                  {group.questions.map((suggestion) => (
                    <button key={suggestion} type="button" onClick={() => askSuggestion(suggestion)} disabled={sending}>
                      {suggestion}
                    </button>
                  ))}
                </div>
              </div>
            ))}
          </div>
          <form className="chat-form floating-chat-form" onSubmit={submit}>
            <input value={message} onChange={(event) => setMessage(event.target.value)} placeholder="Ask APPRAISIA..." autoComplete="off" disabled={sending} />
            <button type="submit" disabled={sending}>{sending ? 'Sending' : 'Send'}</button>
          </form>
        </section>
      )}
    </>
  );
}
