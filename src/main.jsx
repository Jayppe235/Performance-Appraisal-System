import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import App from './App.jsx';
import ConnectivityGate from './components/common/ConnectivityGate.jsx';
import './styles/app.css';
import './styles/sidebar-motion.css';
import './styles/self-review-responsive.css';
import './styles/account-dropdown.css';
import './styles/people-modal-dark.css';
import './styles/account-management.css';
import './styles/sidebar-performance.css';
import './styles/performance-reports.css';
import './styles/assignment-workbench-fixes.css';
import './styles/department-profile-polish.css';
import './styles/department-ai-layout.css';
import './styles/responsive-final.css';

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <BrowserRouter>
      <ConnectivityGate>
        <App />
      </ConnectivityGate>
    </BrowserRouter>
  </React.StrictMode>,
);
