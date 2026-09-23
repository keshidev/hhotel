import { useNotificationTarget } from '../../hooks/useNotificationTarget';
import { useCallback, useEffect, useState } from 'react';
import { Eye, History, RefreshCw, Search, X } from 'lucide-react';
import adminApi from '../../services/adminApi';
import receptionistApi from '../../services/receptionistApi';
import StatusBadge from '../../components/StatusBadge';
import TableActionButton from '../../components/TableActionButton';
import { showToast } from '../../utils/showToast';
import './ContactInquiries.css';

const statusLabels = {
  all: 'All inquiries',
  new: 'New',
  in_progress: 'In progress',
  resolved: 'Resolved',
  spam: 'Spam',
};

const subjectLabels = {
  general: 'General inquiry',
  reservation: 'Reservation',
  billing: 'Billing',
  feedback: 'Feedback',
};

const resolutionEmailLabels = {
  not_sent: 'Not sent',
  pending: 'Queued',
  sending: 'Sending',
  sent: 'Sent',
  failed: 'Delivery failed',
  not_applicable: 'No email available',
};

function formatDate(value) {
  return value ? new Date(value).toLocaleString() : '—';
}

export default function ContactInquiries({ role }) {
  const api = role === 'admin' ? adminApi : receptionistApi;
  const [rows, setRows] = useState([]);
  const [summary, setSummary] = useState({ new: 0, in_progress: 0, resolved: 0, spam: 0 });
  const [status, setStatus] = useState('all');
  useNotificationTarget((target) => { setSearch(target.search); setStatus('all'); setSelected(null); });
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [selected, setSelected] = useState(null);
  const [saving, setSaving] = useState(false);
  const [pendingAction, setPendingAction] = useState(null);
  const [reason, setReason] = useState('');
  const [customerResponse, setCustomerResponse] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const response = await api.get('/contact-inquiries', { params: { status, search: search.trim() || undefined, per_page: 50 } });
      setRows(response.data?.data?.data || []);
      setSummary(response.data?.summary || {});
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'Unable to load guest inquiries.');
    } finally {
      setLoading(false);
    }
  }, [api, search, status]);

  useEffect(() => {
    const timer = setTimeout(load, 250);
    return () => clearTimeout(timer);
  }, [load]);

  const openInquiry = async (row) => {
    setSelected(row);
    setPendingAction(null);
    setReason('');
    setCustomerResponse('');
    setError('');

    try {
      const response = await api.get(`/contact-inquiries/${row.id}`);
      setSelected(response.data?.data || row);
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'Unable to load the inquiry history.');
    }
  };

  const updateStatus = async (nextStatus, statusReason = '', resolutionMessage = '') => {
    if (!selected || saving) return;
    setSaving(true);
    setError('');
    try {
      const response = await api.patch(`/contact-inquiries/${selected.id}/status`, {
        status: nextStatus,
        reason: statusReason.trim() || undefined,
        customer_response: nextStatus === 'resolved' ? resolutionMessage.trim() || null : undefined,
      });
      setSelected(response.data?.data || null);
      setPendingAction(null);
      setReason('');
      setCustomerResponse('');
      showToast(response.data?.message || 'Inquiry status updated.', 'success');
      await load();
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'Unable to update this inquiry.');
    } finally {
      setSaving(false);
    }
  };

  const requestStatusChange = (statusValue, label, requiresReason = false) => {
    if (statusValue === 'resolved') {
      setPendingAction({ status: statusValue, label, kind: 'resolution' });
      setReason('');
      setCustomerResponse('');
      return;
    }

    if (requiresReason) {
      setPendingAction({ status: statusValue, label, kind: 'reason' });
      setReason('');
      setCustomerResponse('');
      return;
    }

    updateStatus(statusValue);
  };

  const submitReasonedStatusChange = () => {
    if (!pendingAction) return;
    if (pendingAction.kind === 'resolution') {
      updateStatus(pendingAction.status, '', customerResponse);
      return;
    }
    if (!reason.trim()) return;
    updateStatus(pendingAction.status, reason);
  };

  return (
    <div className="ci-page">
      <div className="ci-heading">
        <div><h1>Guest Inquiries</h1><p>Read and track messages submitted from the public contact page.</p></div>
        <button className="ci-primary" onClick={load} disabled={loading}><RefreshCw size={16} /> Refresh</button>
      </div>

      <div className="ci-summary">
        <div><span>New</span><strong>{summary.new || 0}</strong></div>
        <div><span>In progress</span><strong>{summary.in_progress || 0}</strong></div>
        <div><span>Resolved</span><strong>{summary.resolved || 0}</strong></div>
        <div><span>Spam</span><strong>{summary.spam || 0}</strong></div>
      </div>

      <div className="ci-toolbar">
        <label><Search size={17} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search reference, guest, email, or booking" /></label>
        <select value={status} onChange={(event) => setStatus(event.target.value)}>
          {Object.entries(statusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </select>
      </div>

      {error && <div className="ci-error" role="alert">{error}</div>}
      <div className="ci-table-wrap">
        <table className="ci-table">
          <thead><tr><th>Reference</th><th>Guest</th><th>Subject</th><th>Received</th><th>Status</th><th>Action</th></tr></thead>
          <tbody>
            {!loading && rows.map((row) => (
              <tr key={row.id}>
                <td><strong>{row.reference_number}</strong>{row.booking_reference && <small>Booking: {row.booking_reference}</small>}</td>
                <td>{[row.first_name, row.last_name].filter(Boolean).join(' ') || 'Anonymized'}<small>{row.email || 'Details removed'}</small></td>
                <td>{subjectLabels[row.subject] || row.subject}</td>
                <td>{formatDate(row.created_at)}</td>
                <td><StatusBadge status={row.status} label={statusLabels[row.status]} /></td>
                <td><TableActionButton label="View inquiry" onClick={() => openInquiry(row)}><Eye size={16} /> View</TableActionButton></td>
              </tr>
            ))}
            {!loading && rows.length === 0 && <tr><td colSpan="6" className="ci-empty">No guest inquiries match this filter.</td></tr>}
            {loading && <tr><td colSpan="6" className="ci-empty">Loading inquiries…</td></tr>}
          </tbody>
        </table>
      </div>

      {selected && (
        <div className="ci-modal-backdrop" onMouseDown={(event) => event.target === event.currentTarget && setSelected(null)}>
          <section className="ci-modal" role="dialog" aria-modal="true" aria-label="Guest inquiry details">
            <header><div><span>Guest inquiry</span><h2>{selected.reference_number}</h2></div><button onClick={() => setSelected(null)} aria-label="Close"><X /></button></header>
            <div className="ci-details">
              <div><span>Guest</span><strong>{[selected.first_name, selected.last_name].filter(Boolean).join(' ')}</strong></div>
              <div><span>Email</span><strong>{selected.email || 'Removed'}</strong></div>
              <div><span>Phone</span><strong>{selected.phone || 'Not provided'}</strong></div>
              <div><span>Booking reference</span><strong>{selected.booking_reference || 'Not provided'}</strong></div>
              <div><span>Subject</span><strong>{subjectLabels[selected.subject] || selected.subject}</strong></div>
              <div><span>Assigned to</span><strong>{selected.assignee?.name || 'Unassigned'}</strong></div>
              {selected.status === 'resolved' && (
                <div><span>Customer notification</span><strong>{resolutionEmailLabels[selected.resolution_email_status] || 'Not sent'}</strong></div>
              )}
            </div>
            <div className="ci-message"><span>Message</span><p>{selected.message || 'Message removed by retention policy.'}</p></div>
            {selected.status === 'resolved' && (
              <div className="ci-message ci-resolution-message">
                <span>Customer-facing resolution</span>
                <p>{selected.resolution_message || 'Standard resolution acknowledgement sent without an additional response.'}</p>
              </div>
            )}

            <div className="ci-history">
              <div className="ci-section-title"><History size={15} /><span>Status history</span></div>
              {(selected.status_history || []).length > 0 ? (
                <ol>
                  {selected.status_history.map((entry) => (
                    <li key={entry.id}>
                      <div>
                        <strong>
                          {entry.from_status ? `${statusLabels[entry.from_status]} → ` : ''}
                          {statusLabels[entry.to_status] || entry.to_status}
                        </strong>
                        <span>{formatDate(entry.created_at)}</span>
                      </div>
                      <p>{entry.reason || 'No additional reason provided.'}</p>
                      <small>{entry.actor?.name || 'System'} · {entry.actor_role || 'system'}</small>
                    </li>
                  ))}
                </ol>
              ) : <p className="ci-history-empty">No status changes recorded yet.</p>}
            </div>

            {pendingAction && (
              <div className="ci-reason-box">
                <label htmlFor="ci-status-reason">
                  {pendingAction.kind === 'resolution' ? 'Customer-facing response (optional)' : `Reason for: ${pendingAction.label}`}
                </label>
                {pendingAction.kind === 'resolution' && (
                  <p className="ci-resolution-help">
                    This text will be emailed to the customer. Leave it blank to send the standard resolution acknowledgement.
                  </p>
                )}
                <textarea
                  id="ci-status-reason"
                  value={pendingAction.kind === 'resolution' ? customerResponse : reason}
                  onChange={(event) => pendingAction.kind === 'resolution' ? setCustomerResponse(event.target.value) : setReason(event.target.value)}
                  maxLength={pendingAction.kind === 'resolution' ? 2000 : 500}
                  rows={pendingAction.kind === 'resolution' ? 5 : 3}
                  placeholder={pendingAction.kind === 'resolution' ? 'Briefly explain how the concern was addressed…' : 'Explain why this status change is needed.'}
                  autoFocus
                />
                <div>
                  <span>{pendingAction.kind === 'resolution' ? customerResponse.length : reason.length}/{pendingAction.kind === 'resolution' ? 2000 : 500}</span>
                  <button type="button" onClick={() => { setPendingAction(null); setReason(''); setCustomerResponse(''); }} disabled={saving}>Cancel</button>
                  <button type="button" className="ci-primary" onClick={submitReasonedStatusChange} disabled={saving || (pendingAction.kind !== 'resolution' && !reason.trim())}>
                    {saving ? 'Saving...' : pendingAction.kind === 'resolution' ? 'Resolve and notify customer' : `Confirm ${pendingAction.label}`}
                  </button>
                </div>
              </div>
            )}

            <footer>
              {selected.status === 'new' && <button onClick={() => requestStatusChange('in_progress', 'Start handling')} disabled={saving || pendingAction}>Start handling</button>}
              {selected.status === 'in_progress' && <button className="success" onClick={() => requestStatusChange('resolved', 'Resolve inquiry')} disabled={saving || pendingAction}>Mark resolved</button>}
              {['new', 'in_progress'].includes(selected.status) && <button className="danger" onClick={() => requestStatusChange('spam', 'Mark as spam', true)} disabled={saving || pendingAction}>Mark spam</button>}
              {selected.status === 'resolved' && <button onClick={() => requestStatusChange('in_progress', 'Reopen inquiry', true)} disabled={saving || pendingAction}>Reopen</button>}
              {selected.status === 'spam' && role === 'admin' && <button onClick={() => requestStatusChange('new', 'Restore inquiry', true)} disabled={saving || pendingAction}>Restore from spam</button>}
              {selected.status === 'spam' && role !== 'admin' && <span className="ci-admin-note">Only an administrator can restore this inquiry.</span>}
            </footer>
          </section>
        </div>
      )}
    </div>
  );
}
