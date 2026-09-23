import { useLocation, useNavigate } from 'react-router-dom';
import { X } from 'lucide-react';

export default function NotificationContext() {
  const location = useLocation();
  const navigate = useNavigate();
  const target = location.state?.notificationTarget;
  if (!target) return null;
  return (
    <aside aria-label="Notification context" style={{ margin: '0 0 18px', padding: '14px 16px', border: '1px solid #c7d7fe', borderRadius: 10, background: '#eff6ff', color: '#1e3a5f', display: 'flex', gap: 12, alignItems: 'flex-start' }}>
      <div style={{ flex: 1, minWidth: 0, overflowWrap: 'anywhere', fontSize: 13, lineHeight: 1.6 }}>
        <strong>Opened from: {target.title}</strong>
        {target.search && <div>Search reference: {target.search}</div>}
        {target.date && <div>Report date: {target.date}</div>}
        <div>The notification is a snapshot. Check the current status below before taking action. If no result appears, the record may be unavailable or the filters may need adjusting.</div>
      </div>
      <button type="button" aria-label="Dismiss notification context" onClick={() => navigate(location.pathname + location.search, { replace: true, state: null })} style={{ border: 0, background: 'transparent', cursor: 'pointer', padding: 4 }}><X size={18} /></button>
    </aside>
  );
}
