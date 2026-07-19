import { NavLink } from 'react-router-dom';
import {
  BarChart3,
  Bot,
  ClipboardCheck,
  ClipboardList,
  ChevronLeft,
  FileText,
  LayoutDashboard,
  LineChart,
  ListChecks,
  Settings,
  SquarePen,
  Users,
} from 'lucide-react';

const navIcons = {
  assistant: Bot,
  assignments: ClipboardList,
  dashboard: LayoutDashboard,
  evaluations: ClipboardCheck,
  insights: LineChart,
  reports: FileText,
  results: BarChart3,
  settings: Settings,
  selfEvaluation: SquarePen,
  summary: ListChecks,
  users: Users,
};

export default function Sidebar({ role, sidebarCollapsed = false, onClose, onToggleCollapse }) {
  return (
    <aside className="admin-sidebar" aria-label={`${role.portal} navigation`}>
      <div className="sidebar-brand">
        <span className="brand-icon">{role.brandLetter}</span>
        <span className="sidebar-brand-copy">
          <strong>APPRAISIA</strong>
          <small>{role.portal}</small>
        </span>
        <button
          className="sidebar-collapse"
          type="button"
          aria-label={sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          aria-expanded={!sidebarCollapsed}
          onClick={onToggleCollapse}
        >
          <ChevronLeft className="sidebar-collapse-icon" size={18} aria-hidden="true" />
        </button>
      </div>

      <nav className="sidebar-menu">
        {role.nav.map((item) => {
          const Icon = navIcons[item.icon] || LayoutDashboard;
          return (
            <NavLink key={item.key} to={`${role.basePath}/${item.key}`} onClick={onClose} data-nav-key={item.key}>
              <span className="menu-icon" aria-hidden="true"><Icon size={18} strokeWidth={2.2} /></span>
              <span className="sidebar-item-label">{item.label}</span>
            </NavLink>
          );
        })}
      </nav>
    </aside>
  );
}
