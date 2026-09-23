import { useNotificationTarget } from '../../hooks/useNotificationTarget';
import React, { useEffect, useMemo, useState, useRef } from 'react';
import { Search, Filter, ChevronDown, Eye, X, Clock3, CheckCircle2, XCircle } from 'lucide-react';
import approvalService from '../../services/admin/approvalService';
import StatusBadge from '../../components/StatusBadge';
import TableActionButton from '../../components/TableActionButton';
import useAutoRefresh from '../../hooks/useAutoRefresh';
import './AdminShared.css';
import './AdminApprovalToolbar.css';

const STATUS_OPTIONS = [
  ['All', 'all'],
  ['Pending Approval', 'pending_approval'],
  ['Approved', 'approved'],
  ['Rejected', 'rejected'],
  ['Consumed', 'consumed'],
  ['Expired', 'expired'],
];

const showToast = (message, type = 'success') => {
  const toast = document.createElement('div');
  toast.className = `simple-toast toast-${type}`;
  toast.textContent = message;
  document.body.appendChild(toast);
  setTimeout(() => toast.classList.add('show'), 10);
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => toast.remove(), 300);
  }, 3000);
};

const AdminEarlyCheckInApprovals = () => {
  const [records, setRecords] = useState([]);
  const [status, setStatus] = useState('pending_approval');
  useNotificationTarget((target) => { setSearch(target.search); setStatus('all'); setSelected(null); });
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState(null);
  const [decisionNote, setDecisionNote] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    const styleId = 'simple-toast-styles';
    if (!document.getElementById(styleId)) {
      const style = document.createElement('style');
      style.id = styleId;
      style.textContent = `
        .simple-toast { position:fixed;top:20px;right:20px;padding:16px 24px;border-radius:8px;color:white;font-size:14px;font-weight:500;box-shadow:0 10px 40px rgba(0,0,0,.2);z-index:9999;transform:translateX(400px);opacity:0;transition:all .3s ease }
        .simple-toast.show { transform:translateX(0);opacity:1 }
        .toast-success { background:#10b981 }.toast-error { background:#ef4444 }.toast-warning { background:#f59e0b }
      `;
      document.head.appendChild(style);
    }
  }, []);

  const latestRequest = useRef(0);
  const load = async ({ quiet = false } = {}) => {
    const request = ++latestRequest.current;
    if (!quiet) setLoading(true);
    try {
      const response = await approvalService.getEarlyCheckInRequests({ status });
      if (request !== latestRequest.current) return;
      setRecords(response.requests || []);
      setError('');
    } catch (requestError) {
      if (request !== latestRequest.current) return;
      setError(requestError?.response?.data?.message || 'Failed to load early check-in requests.');
    } finally {
      if (request === latestRequest.current && !quiet) setLoading(false);
    }
  };

  useEffect(() => { load(); }, [status]); // eslint-disable-line react-hooks/exhaustive-deps
  useAutoRefresh(() => load({ quiet: true }), { deps: [status], intervalMs: 15000 });

  const filtered = useMemo(() => {
    const keyword = search.trim().toLowerCase();
    if (!keyword) return records;
    return records.filter((item) => [item.id, item.bookingId, item.guest, item.requestedBy]
      .some((value) => String(value || '').toLowerCase().includes(keyword)));
  }, [records, search]);

  const open = (row) => {
    setSelected(row);
    setDecisionNote(row.decisionNote || '');
  };

  const decide = async (decision) => {
    if (!selected) return;
    if (decision === 'reject' && decisionNote.trim().length < 5) {
      showToast('Enter a rejection reason using at least 5 characters.', 'warning');
      return;
    }

    setSaving(true);
    try {
      const payload = { decision_note: decisionNote.trim() || null };
      const response = decision === 'approve'
        ? await approvalService.approveEarlyCheckInRequest(selected.requestId, payload)
        : await approvalService.rejectEarlyCheckInRequest(selected.requestId, payload);
      showToast(response.message, 'success');
      setSelected(response.request);
      await load({ quiet: true });
    } catch (requestError) {
      showToast(requestError?.response?.data?.message || 'Unable to process request.', 'error');
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <div style={{ padding: '2rem' }}>Loading early check-in approvals...</div>;

  return (
    <div className="transfer-approval-page admin-approval-page">
      <div className="page-header">
        <div>
          <h1>Early Check-In Approvals</h1>
          <p className="page-subtitle">Approve or reject requests made before the official hotel check-in time.</p>
        </div>
      </div>

      <div className="page-toolbar">
        <div className="search-wrap">
          <Search size={16} />
          <input placeholder="Search request, booking, guest, requester..." value={search} onChange={(event) => setSearch(event.target.value)} />
        </div>
        <div className="filter-wrap">
          <Filter size={16} />
          <select value={status} onChange={(event) => setStatus(event.target.value)}>
            {STATUS_OPTIONS.map(([label, value]) => <option key={value} value={value}>{label}</option>)}
          </select>
          <ChevronDown size={14} className="select-chevron" />
        </div>
      </div>

      {error && <div style={{ color: '#dc2626', marginBottom: 12 }}>{error}</div>}

      <div className="content-card">
        <div className="table-container">
          <table className="data-table">
            <thead><tr><th>Request</th><th>Booking</th><th>Guest</th><th>Room</th><th>Requested By</th><th>Status</th><th>Expires</th><th>Action</th></tr></thead>
            <tbody>
              {filtered.length === 0 ? (
                <tr><td colSpan={8} style={{ textAlign: 'center', padding: '2rem' }}>No early check-in requests found.</td></tr>
              ) : filtered.map((row) => (
                <tr key={row.requestId}>
                  <td>{row.id}</td><td>{row.bookingId}</td><td>{row.guest}</td><td>{row.rooms?.join(', ') || 'N/A'}</td>
                  <td>{row.requestedBy}</td><td><StatusBadge status={row.status} label={row.statusLabel} /></td>
                  <td>{row.officialCheckIn}</td>
                  <td><TableActionButton iconOnly label="Open early check-in request" onClick={() => open(row)}><Eye size={16} /></TableActionButton></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {selected && (
        <div className="modal-overlay" onClick={() => !saving && setSelected(null)}>
          <div className="modal-content modal-large" onClick={(event) => event.stopPropagation()}>
            <div className="modal-header">
              <div><div style={{ fontSize: '0.72rem', color: 'var(--color-text-secondary)', textTransform: 'uppercase', fontWeight: 700 }}>Early Check-In Request</div><h2>{selected.bookingId}</h2></div>
              <button className="modal-close" onClick={() => setSelected(null)} disabled={saving}><X size={18} /></button>
            </div>
            <div style={{ padding: '1.5rem' }}>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', gap: '1rem' }}>
                {[
                  ['Guest', selected.guest], ['Room', selected.rooms?.join(', ') || 'N/A'],
                  ['Booked Check-In', selected.checkIn], ['Official Time', selected.officialCheckIn],
                  ['Requested By', selected.requestedBy], ['Status', selected.statusLabel],
                ].map(([label, value]) => (
                  <div key={label} style={{ padding: '0.8rem', border: '1px solid var(--color-border)', borderRadius: 8 }}>
                    <div style={{ color: 'var(--color-text-secondary)', fontSize: '0.78rem', marginBottom: 4 }}>{label}</div>
                    <strong style={{ fontSize: '0.88rem' }}>{value}</strong>
                  </div>
                ))}
              </div>
              <div style={{ display: 'flex', gap: 10, marginTop: '1rem', padding: '0.9rem', border: '1px solid var(--color-border)', borderRadius: 8, background: 'var(--color-background)' }}>
                <Clock3 size={17} /><div><strong style={{ fontSize: '0.84rem' }}>Receptionist reason</strong><p style={{ margin: '0.35rem 0 0', color: 'var(--color-text-secondary)' }}>{selected.reason}</p></div>
              </div>
              <label htmlFor="early-decision-note" style={{ display: 'block', marginTop: '1rem', marginBottom: 6, fontSize: '0.86rem', fontWeight: 600 }}>Decision note {selected.canReject ? '(required when rejecting)' : ''}</label>
              <textarea id="early-decision-note" rows={3} maxLength={500} value={decisionNote} onChange={(event) => setDecisionNote(event.target.value)} disabled={!selected.canApprove || saving} style={{ width: '100%', border: '1px solid var(--color-border)', borderRadius: 8, padding: '0.7rem', fontFamily: 'inherit', resize: 'vertical' }} />
            </div>
            {selected.canApprove && (
              <div className="modal-footer">
                <button className="btn-danger" onClick={() => decide('reject')} disabled={saving}><XCircle size={16} /> Reject</button>
                <button className="btn-primary" onClick={() => decide('approve')} disabled={saving}><CheckCircle2 size={16} /> Approve Early Check-In</button>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
};

export default AdminEarlyCheckInApprovals;
