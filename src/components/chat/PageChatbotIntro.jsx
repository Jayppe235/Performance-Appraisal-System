import { useEffect, useMemo, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { Bot, X } from 'lucide-react';

const pageGuideMessages = {
  admin: {
    dashboard: 'Welcome to the Admin Dashboard. Here, you can view the overall faculty evaluation summary, active evaluation period, pending submissions, completed evaluations, and important system updates.',
    people: 'This page allows you to manage user accounts, roles, departments, programs, and access permissions within APPRAISIA.',
    department: 'This page allows you to manage department records, assigned users, programs, and faculty information in one organized workspace.',
    assignments: 'This page lets you create evaluation schedules, manage questionnaires, and use Category Monitor as the single view for Self, Peer, Program Head, Dean, and other evaluation statuses.',
    'ai-actions': 'This page helps you monitor role-scoped faculty progress, reviewer coverage, completion risks, submitted records, and development priorities for the selected evaluation period.',
    reports: 'This page allows you to generate evaluation reports, view department and program summaries, and export records for documentation and decision making.',
    settings: 'This page lets you update profile settings, notification preferences, refresh behavior, and review archived system records.',
  },
  dean: {
    overview: 'Welcome to the Dean Dashboard. Here, you can see faculty evaluation records counted in the current evaluation period, including submitted, pending, and reviewed evaluations under your department.',
    evaluate: 'This page allows you to view assigned evaluation cards, complete pending reviews, and monitor evaluation work under your department.',
    'self-evaluation-review': 'This page allows you to review faculty self evaluations, add Dean remarks, approve submissions, and forward approved evaluations to the Admin.',
    summary: 'This page shows department AI analysis, strengths, weak areas, and recommended development actions based on evaluation results.',
    report: 'This page allows you to view department level evaluation results, identify strengths and areas for improvement, and generate reports for faculty development.',
  },
  programHead: {
    overview: 'Welcome to the Program Head Dashboard. Here, you can monitor evaluation data, faculty progress, and performance summaries for faculty members under your program.',
    evaluate: 'This page allows you to evaluate assigned faculty and peers, check pending tasks, and complete required appraisal cards.',
    summary: 'This page shows AI assisted and rule based insights for your program. You can view strengths, weak areas, and recommended faculty development actions based on evaluation results.',
    results: 'This page allows you to review performance summaries and evaluation results connected to your program responsibilities.',
    report: 'This page allows you to generate program evaluation reports for review, planning, and faculty development.',
  },
  vpaa: {
    overview: 'Welcome to the VPAA Dashboard. Here, you can view institutional level evaluation summaries, department performance, Dean evaluation results, and academic performance indicators.',
    evaluate: 'This page allows you to complete assigned Dean evaluations and monitor VPAA evaluation tasks for the active period.',
    analytics: 'This page allows you to compare department evaluation results, monitor academic performance, and identify areas that need improvement across the institution.',
    reports: 'This page allows you to generate institutional evaluation reports for academic review, planning, and decision making.',
  },
  faculty: {
    overview: 'Welcome to your Faculty Dashboard. Here, you can view your evaluation status, current evaluation period, pending tasks, submitted evaluations, and available feedback.',
    evaluate: 'This page allows you to complete assigned evaluations and self evaluation tasks for the current appraisal period.',
    results: 'This page allows you to view your evaluation results, feedback, AI assisted insights, strengths, areas for improvement, and recommended development actions.',
  },
};

const roleFallbackMessages = {
  admin: 'This APPRAISIA page helps you manage evaluation records, users, reports, and system activity from your Admin workspace.',
  dean: 'This APPRAISIA page helps you monitor department evaluation activity, faculty progress, and development needs.',
  programHead: 'This APPRAISIA page helps you monitor program evaluation progress, faculty activity, and performance insights.',
  vpaa: 'This APPRAISIA page helps you review institutional evaluation progress, department performance, and academic planning records.',
  faculty: 'This APPRAISIA page helps you complete evaluation tasks, review progress, and understand your appraisal results.',
};

function routeSection(pathname, roleKey) {
  const parts = pathname.split('/').filter(Boolean);
  if (roleKey === 'programHead') return parts[1] || 'overview';
  if (roleKey === 'admin' && parts[1] === 'department') return 'department';
  return parts[1] || (roleKey === 'admin' ? 'dashboard' : 'overview');
}

function storageKey(roleKey, section) {
  return `appraisia-page-guide:v1:${roleKey}:${section}`;
}

export default function PageChatbotIntro({ role, onVisibilityChange }) {
  const location = useLocation();
  const [mounted, setMounted] = useState(false);
  const [visible, setVisible] = useState(false);
  const [activeGuide, setActiveGuide] = useState(null);

  const guide = useMemo(() => {
    const roleKey = role?.key || 'admin';
    const section = routeSection(location.pathname, roleKey);
    const message = pageGuideMessages[roleKey]?.[section] || roleFallbackMessages[roleKey];
    return message ? { roleKey, section, message } : null;
  }, [location.pathname, role?.key]);

  useEffect(() => {
    if (!guide) {
      setVisible(false);
      setMounted(false);
      setActiveGuide(null);
      onVisibilityChange?.(false);
      return undefined;
    }

    const key = storageKey(guide.roleKey, guide.section);
    if (window.sessionStorage.getItem(key) === 'shown') {
      setVisible(false);
      setMounted(false);
      setActiveGuide(null);
      onVisibilityChange?.(false);
      return undefined;
    }

    window.sessionStorage.setItem(key, 'shown');
    setActiveGuide(guide);
    setMounted(true);

    const showTimer = window.setTimeout(() => {
      setVisible(true);
      onVisibilityChange?.(true);
    }, 180);

    const hideTimer = window.setTimeout(() => {
      setVisible(false);
      onVisibilityChange?.(false);
    }, 6500);

    const unmountTimer = window.setTimeout(() => {
      setMounted(false);
      setActiveGuide(null);
    }, 7050);

    return () => {
      window.clearTimeout(showTimer);
      window.clearTimeout(hideTimer);
      window.clearTimeout(unmountTimer);
    };
  }, [guide, onVisibilityChange]);

  useEffect(() => {
    onVisibilityChange?.(visible);
  }, [onVisibilityChange, visible]);

  function closeGuide() {
    setVisible(false);
    onVisibilityChange?.(false);
    window.setTimeout(() => {
      setMounted(false);
      setActiveGuide(null);
    }, 360);
  }

  if (!activeGuide || !mounted) return null;

  return (
    <aside className={`page-chatbot-intro ${visible ? 'is-visible' : 'is-hidden'}`} role="status" aria-live="polite">
      <span className="page-chatbot-intro-icon" aria-hidden="true">
        <Bot size={18} />
      </span>
      <div className="page-chatbot-intro-copy">
        <strong>APPRAISIA guide</strong>
        <p>{activeGuide.message}</p>
      </div>
      <button type="button" className="page-chatbot-intro-close" onClick={closeGuide} aria-label="Close page guide">
        <X size={15} />
      </button>
    </aside>
  );
}
