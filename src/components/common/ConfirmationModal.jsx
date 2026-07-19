import { useCallback, useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { AlertTriangle, CheckCircle2, Loader2, LogOut, Save, ShieldAlert, Trash2, X } from 'lucide-react';
import robotAssistant from '../../../assets/images/ROBOT 1.svg';

let globalConfirm = null;

const PRESETS = {
  logout: {
    title: 'Are you sure you want to log out?',
    message: 'Any unsaved changes may be lost.',
    cancelText: 'Cancel',
    confirmText: 'Logout',
    variant: 'warning',
    icon: LogOut,
  },
  submitEvaluation: {
    title: 'Are you sure you want to submit this evaluation?',
    message: 'Please review your ratings and behavioral evidence before submitting. Once submitted, this evaluation may no longer be edited unless it is reopened by the authorized administrator.',
    cancelText: 'Review Again',
    confirmText: 'Submit Evaluation',
    variant: 'primary',
    icon: CheckCircle2,
    illustration: robotAssistant,
    illustrationAlt: 'AI assistant robot',
  },
  save: {
    title: 'Are you sure you want to save these changes?',
    message: 'The updated information will be applied to the system.',
    cancelText: 'Cancel',
    confirmText: 'Save Changes',
    variant: 'primary',
    icon: Save,
  },
  delete: {
    title: 'Are you sure you want to delete this data?',
    message: 'This action cannot be undone.',
    cancelText: 'Cancel',
    confirmText: 'Delete',
    variant: 'danger',
    icon: Trash2,
  },
  proceed: {
    title: 'Are you sure you want to proceed with this action?',
    message: 'This will update the status of the selected record.',
    cancelText: 'Cancel',
    confirmText: 'Confirm',
    variant: 'warning',
    icon: ShieldAlert,
  },
};

export function confirmAction(options = {}) {
  if (!globalConfirm) {
    return Promise.resolve(window.confirm(options.title || options.message || 'Are you sure you want to continue?'));
  }
  return globalConfirm(options);
}

export const confirmLogout = (options = {}) => confirmAction({ preset: 'logout', ...options });
export const confirmSubmitEvaluation = (options = {}) => confirmAction({ preset: 'submitEvaluation', ...options });
export const confirmSaveChanges = (options = {}) => confirmAction({ preset: 'save', ...options });
export const confirmDeleteData = (options = {}) => confirmAction({ preset: 'delete', ...options });
export const confirmProceed = (options = {}) => confirmAction({ preset: 'proceed', ...options });

export default function ConfirmationModalProvider() {
  const [request, setRequest] = useState(null);
  const [busy, setBusy] = useState(false);

  const open = useCallback((options = {}) => new Promise((resolve) => {
    const preset = PRESETS[options.preset] || {};
    setBusy(false);
    setRequest({
      ...preset,
      ...options,
      resolve,
      icon: options.icon || preset.icon || AlertTriangle,
    });
  }), []);

  useEffect(() => {
    globalConfirm = open;
    return () => {
      globalConfirm = null;
    };
  }, [open]);

  useEffect(() => {
    if (!request) return undefined;
    function handleKeyDown(event) {
      if (event.key === 'Escape' && !busy) {
        request.resolve(false);
        setRequest(null);
      }
    }
    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [busy, request]);

  if (!request) return null;

  const Icon = request.icon || AlertTriangle;
  const variant = request.variant || 'primary';
  const hasIllustration = Boolean(request.illustration);

  function close() {
    if (busy) return;
    request.resolve(false);
    setRequest(null);
  }

  async function confirm() {
    if (busy) return;
    setBusy(true);
    request.resolve(true);
    setRequest(null);
  }

  return createPortal(
    <div className="app-confirm-backdrop" role="presentation" onClick={(event) => event.target === event.currentTarget && close()}>
      <section className={`app-confirm-modal ${variant}${hasIllustration ? ' has-illustration' : ''}`} role="dialog" aria-modal="true" aria-labelledby="app-confirm-title" aria-describedby="app-confirm-message">
        <button type="button" className="app-confirm-close" onClick={close} disabled={busy} aria-label="Close confirmation">
          <X size={18} />
        </button>
        <div className="app-confirm-hero">
          <div className="app-confirm-icon">
            <Icon size={24} />
          </div>
          {hasIllustration && (
            <div className="app-confirm-illustration" aria-hidden="true">
              <img src={request.illustration} alt={request.illustrationAlt || ''} />
            </div>
          )}
        </div>
        <div className="app-confirm-copy">
          {hasIllustration && (
            <div className="app-confirm-status">
              <span>Ready</span>
              <strong>Evaluation Complete</strong>
            </div>
          )}
          <h2 id="app-confirm-title">{request.title}</h2>
          <p id="app-confirm-message">{request.message}</p>
        </div>
        <div className="app-confirm-actions">
          <button type="button" className="app-confirm-cancel" onClick={close} disabled={busy}>
            {request.cancelText || 'Cancel'}
          </button>
          <button type="button" className="app-confirm-submit" onClick={confirm} disabled={busy}>
            {busy && <Loader2 size={16} className="animate-spin" />}
            {request.confirmText || 'Confirm'}
          </button>
        </div>
      </section>
    </div>,
    document.body
  );
}
