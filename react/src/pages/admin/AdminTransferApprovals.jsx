import { useNotificationTarget } from '../../hooks/useNotificationTarget';
import React, { useEffect, useMemo, useState } from 'react';
import { Search, Filter, ChevronDown, Eye, X, BedDouble, ArrowRightLeft } from 'lucide-react';
import approvalService from '../../services/admin/approvalService';
import StatusBadge from '../../components/StatusBadge';
import TableActionButton from '../../components/TableActionButton';
import './AdminShared.css';
import './AdminApprovalToolbar.css';
import useAutoRefresh from '../../hooks/useAutoRefresh';

const STATUS_OPTIONS = [
  { label: 'All', value: 'all' },
  { label: 'Pending Approval', value: 'pending_approval' },
  { label: 'Approved', value: 'approved' },
  { label: 'Rejected', value: 'rejected' },
  { label: 'Completed', value: 'completed' },
];

const showToast = (message, type = 'success') => {
  const toast = document.createElement('div');
  toast.className = `simple-toast toast-${type}`;
  toast.textContent = message;
  document.body.appendChild(toast);

  setTimeout(() => toast.classList.add('show'), 10);
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => {
      if (toast.parentNode) {
        document.body.removeChild(toast);
      }
    }, 300);
  }, 3000);
};

const AdminTransferApprovals = () => {
  useNotificationTarget((target) => { setSearch(target.search); setStatusFilter('all'); setSelected(null); });
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [records, setRecords] = useState([]);
  const [selected, setSelected] = useState(null);
  const [decisionNote, setDecisionNote] = useState('');
  const [completionNote, setCompletionNote] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    fetchRequests();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusFilter]);

  useEffect(() => {
    const styleId = 'simple-toast-styles';
    if (!document.getElementById(styleId)) {
      const style = document.createElement('style');
      style.id = styleId;
      style.textContent = `
        .simple-toast {
          position: fixed;
          top: 20px;
          right: 20px;
          padding: 16px 24px;
          border-radius: 8px;
          color: white;
          font-size: 14px;
          font-weight: 500;
          box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
          z-index: 9999;
          transform: translateX(400px);
          opacity: 0;
          transition: all 0.3s ease;
        }
        .simple-toast.show {
          transform: translateX(0);
          opacity: 1;
        }
        .toast-success { background: #10b981; }
        .toast-error { background: #ef4444; }
        .toast-warning { background: #f59e0b; }
      `;
      document.head.appendChild(style);
    }
  }, []);

  const fetchRequests = async ({ showLoading = true, showErrorToast = true } = {}) => {
    try {
      if (showLoading) {
        setLoading(true);
      }
      const response = await approvalService.getTransferRequests({
        status: statusFilter,
      });
      setRecords(response.requests || []);
      setError(null);
    } catch (err) {
      setError('Failed to load transfer approvals.');
      if (showErrorToast) {
        showToast(err?.response?.data?.message || 'Failed to load transfer approvals', 'error');
      }
    } finally {
      if (showLoading) {
        setLoading(false);
      }
    }
  };

  useAutoRefresh(
    () => fetchRequests({ showLoading: false, showErrorToast: false }),
    { deps: [statusFilter], intervalMs: 15000 }
  );

  const filtered = useMemo(() => {
    const keyword = search.trim().toLowerCase();
    if (!keyword) return records;
    return records.filter((item) => (
      String(item.id || '').toLowerCase().includes(keyword)
      || String(item.bookingId || '').toLowerCase().includes(keyword)
      || String(item.guest || '').toLowerCase().includes(keyword)
      || String(item.currentRoom || '').toLowerCase().includes(keyword)
      || String(item.targetRoom || '').toLowerCase().includes(keyword)
    ));
  }, [records, search]);

  const openDetails = (row) => {
    setSelected(row);
    setDecisionNote(row.decisionNote || '');
    setCompletionNote(row.completionNote || '');
  };

  const updateSelectedAndList = (updated) => {
    setSelected(updated);
    setRecords((prev) => prev.map((item) => (item.id === updated.id ? updated : item)));
  };

  const handleApprove = async () => {
    if (!selected) return;
    try {
      setSaving(true);
      const response = await approvalService.approveTransferRequest(selected.requestId, {
        decision_note: decisionNote.trim() || null,
      });
      updateSelectedAndList(response.request);
      showToast(response.message || 'Request approved.', 'success');
      await fetchRequests();
    } catch (err) {
      showToast(err?.response?.data?.message || 'Failed to approve request.', 'error');
    } finally {
      setSaving(false);
    }
  };

  const handleReject = async () => {
    if (!selected) return;
    if (!decisionNote.trim()) {
      showToast('Decision note is required for rejection.', 'warning');
      return;
    }
    try {
      setSaving(true);
      const response = await approvalService.rejectTransferRequest(selected.requestId, {
        decision_note: decisionNote.trim(),
      });
      updateSelectedAndList(response.request);
      showToast(response.message || 'Request rejected.', 'success');
      await fetchRequests();
    } catch (err) {
      showToast(err?.response?.data?.message || 'Failed to reject request.', 'error');
    } finally {
      setSaving(false);
    }
  };

  const handleComplete = async () => {
    if (!selected) return;
    try {
      setSaving(true);
      const response = await approvalService.completeTransferRequest(selected.requestId, {
        completion_note: completionNote.trim() || null,
      });
      updateSelectedAndList(response.request);
      showToast(response.message || 'Transfer completed.', 'success');
      await fetchRequests();
    } catch (err) {
      showToast(err?.response?.data?.message || 'Failed to complete transfer.', 'error');
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return <div style={{ padding: '2rem' }}>Loading transfer approvals...</div>;
  }

  if (error) {
    return (
      <div style={{ padding: '2rem' }}>
        <h1 style={{ marginBottom: '1rem' }}>Transfer Approvals</h1>
        <div style={{ color: '#ef4444' }}>{error}</div>
      </div>
    );
  }

  return (
    <div className="transfer-approval-page admin-approval-page">
      <div className="page-header">
        <div>
          <h1>Room Transfer Approvals</h1>
          <p className="page-subtitle">Review and complete room transfer requests with strict approval flow.</p>
        </div>
      </div>

      <div className="page-toolbar">
        <div className="search-wrap">
          <Search size={16} />
          <input
            type="text"
            placeholder="Search by request, booking, guest, room..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>

        <div className="filter-wrap">
          <Filter size={16} />
          <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
            {STATUS_OPTIONS.map((item) => (
              <option key={item.value} value={item.value}>{item.label}</option>
            ))}
          </select>
          <ChevronDown size={14} className="select-chevron" />
        </div>
      </div>

      <div className="content-card">
        <div className="table-container">
          <table className="data-table">
            <thead>
              <tr>
                <th>Request</th>
                <th>Booking</th>
                <th>Guest</th>
                <th>Current Room</th>
                <th>Target Room</th>
                <th>Status</th>
                <th>Requested At</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              {filtered.length === 0 ? (
                <tr>
                  <td colSpan={8} style={{ textAlign: 'center', padding: '2rem', color: 'var(--color-text-secondary)' }}>
                    No room transfer requests found.
                  </td>
                </tr>
              ) : (
                filtered.map((row) => (
                  <tr key={row.id}>
                    <td className="booking-id">{row.id}</td>
                    <td className="booking-id">{row.bookingId}</td>
                    <td>{row.guest}</td>
                    <td>{row.currentRoom}</td>
                    <td>{row.targetRoom}</td>
                    <td><StatusBadge status={row.statusLabel} /></td>
                    <td>{row.requestedAt ? String(row.requestedAt).replace('T', ' ').slice(0, 16) : 'N/A'}</td>
                    <td>
                      <TableActionButton iconOnly label="View transfer details" onClick={() => openDetails(row)}>
                        <Eye size={15} />
                      </TableActionButton>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {selected && (
        <div className="modal-overlay" onClick={() => !saving && setSelected(null)}>
          <div className="modal-content modal-large" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <div>
                <h2>Transfer Request {selected.id}</h2>
                <div style={{ fontSize: '0.85rem', color: 'var(--color-text-secondary)' }}>{selected.bookingId}</div>
              </div>
              <button className="modal-close" onClick={() => setSelected(null)} disabled={saving}><X size={18} /></button>
            </div>

            <div style={{ padding: '1.5rem' }}>
              <div className="detail-grid" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1.25rem' }}>
                <div>
                  <div style={{ fontWeight: 700, marginBottom: '0.6rem', display: 'flex', alignItems: 'center', gap: 6 }}>
                    <BedDouble size={15} /> Booking
                  </div>
                  <div style={{ color: 'var(--color-text-secondary)', lineHeight: 1.7 }}>
                    <div><strong style={{ color: 'var(--color-text-primary)' }}>{selected.guest}</strong></div>
                    <div>Booking Status: {selected.bookingStatus}</div>
                    <div>Check-in: {selected.checkIn}</div>
                    <div>Check-out: {selected.checkOut}</div>
                  </div>
                </div>
                <div>
                  <div style={{ fontWeight: 700, marginBottom: '0.6rem', display: 'flex', alignItems: 'center', gap: 6 }}>
                    <ArrowRightLeft size={15} /> Transfer Details
                  </div>
                  <div style={{ color: 'var(--color-text-secondary)', lineHeight: 1.7 }}>
                    <div>Current: <strong style={{ color: 'var(--color-text-primary)' }}>{selected.currentRoom}</strong></div>
                    <div>Target: <strong style={{ color: 'var(--color-text-primary)' }}>{selected.targetRoom}</strong></div>
                    <div>Requested By: {selected.requestedBy}</div>
                    <div>Requested At: {selected.requestedAt || 'N/A'}</div>
                  </div>
                </div>
              </div>

              <div style={{ marginTop: '1rem', padding: '0.9rem', border: '1px solid var(--color-border)', borderRadius: 8, background: 'var(--color-background)' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                  <span>Status</span>
                  <StatusBadge status={selected.statusLabel} />
                </div>
                <div style={{ fontSize: '0.86rem', color: 'var(--color-text-secondary)', lineHeight: 1.7, marginTop: '0.6rem' }}>
                  <div>Approved By: {selected.approvedBy || 'N/A'}</div>
                  <div>Approved At: {selected.approvedAt || 'N/A'}</div>
                  <div>Rejected At: {selected.rejectedAt || 'N/A'}</div>
                  <div>Completed By: {selected.completedBy || 'N/A'}</div>
                  <div>Completed At: {selected.completedAt || 'N/A'}</div>
                </div>
              </div>

              <div style={{ marginTop: '1rem', padding: '0.9rem', border: '1px solid var(--color-border)', borderRadius: 8 }}>
                <div style={{ fontWeight: 700, marginBottom: 6 }}>Reason</div>
                <div style={{ color: 'var(--color-text-secondary)' }}>{selected.reason}</div>
              </div>

              <div style={{ marginTop: '1rem' }}>
                <label style={{ fontWeight: 600, fontSize: '0.86rem', display: 'block', marginBottom: 6 }}>Decision Note</label>
                <textarea
                  rows={3}
                  value={decisionNote}
                  onChange={(e) => setDecisionNote(e.target.value)}
                  placeholder="Add note for approve/reject"
                  style={{ width: '100%', border: '1px solid var(--color-border)', borderRadius: 8, padding: '0.7rem', fontFamily: 'inherit', resize: 'vertical' }}
                  disabled={saving}
                />
              </div>

              <div style={{ marginTop: '0.75rem' }}>
                <label style={{ fontWeight: 600, fontSize: '0.86rem', display: 'block', marginBottom: 6 }}>Completion Note</label>
                <textarea
                  rows={2}
                  value={completionNote}
                  onChange={(e) => setCompletionNote(e.target.value)}
                  placeholder="Optional note when completing transfer"
                  style={{ width: '100%', border: '1px solid var(--color-border)', borderRadius: 8, padding: '0.7rem', fontFamily: 'inherit', resize: 'vertical' }}
                  disabled={saving}
                />
              </div>
            </div>

            <div className="modal-footer">
              <button className="btn-secondary modal-close-compact" onClick={() => setSelected(null)} disabled={saving}>Close</button>
              {selected.canReject && <button className="btn-danger" onClick={handleReject} disabled={saving}>Reject</button>}
              {selected.canApprove && <button className="btn-primary" onClick={handleApprove} disabled={saving}>Approve</button>}
              {selected.canComplete && <button className="btn-primary" onClick={handleComplete} disabled={saving}>Complete Transfer</button>}
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default AdminTransferApprovals;
