import { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { confirmLogout } from '../components/common/ConfirmationModal.jsx';

export default function LogoutPage({ onLogout }) {
  const navigate = useNavigate();

  useEffect(() => {
    let alive = true;
    async function requestLogout() {
      const confirmed = await confirmLogout();
      if (!alive) return;
      if (!confirmed) {
        if (window.history.length > 1) {
          navigate(-1);
        } else {
          navigate('/login', { replace: true });
        }
        return;
      }
      Promise.resolve(onLogout()).finally(() => {
        if (alive) navigate('/login', { replace: true });
      });
    }
    requestLogout();
    return () => {
      alive = false;
    };
  }, [navigate, onLogout]);

  return null;
}
