import { useNotificationTarget } from '../../hooks/useNotificationTarget';
import React, { useState, useEffect, useMemo, useRef } from 'react';
import {
  Search, Filter, ChevronDown, Eye, CheckCircle, XCircle,
  X, CreditCard, Calendar, User, AlertCircle, ShieldCheck
} from 'lucide-react';
import '../receptionist/Reservation.css';
import './Payment.css';
import adminApi from '../../services/adminApi';
import receptionistApi from '../../services/receptionistApi';
import { PageSkeletonLoader, usePageCache } from '../../components/ProtectedRoute';
import Pagination from '../../components/Pagination';
import StatusBadge from '../../components/StatusBadge';
import TableActionButton from '../../components/TableActionButton';
import { usePagination } from '../../hooks/usePagination';
import useAutoRefresh from '../../hooks/useAutoRefresh';

const STATUS_FILTERS = ['All', 'Pending', 'Completed', 'Failed'];

const showToast = (message, type = 'success') => {
  const toast = document.createElement('div');
  toast.className = `simple-toast toast-${type}`;
  toast.textContent = message;
  document.body.appendChild(toast);
  setTimeout(() => toast.classList.add('show'), 10);
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => {
      if (toast.parentNode) document.body.removeChild(toast);
    }, 300);
  }, 3000);
};

const PaymentPage = ({ embedded = false, role = 'receptionist', onOpenProofReview }) => {
  const api = role === 'admin' ? adminApi : receptionistApi;
  useNotificationTarget((target) => { setSearch(target.search); setStatusFilter(target.status || 'All'); setMethodFilter('All'); setSelected(null); });
  const [search, setSearch]                       = useState('');
  const [statusFilter, setStatusFilter]           = useState('All');
  const [methodFilter, setMethodFilter]           = useState('All');
  const [selected, setSelected]                   = useState(null);
  const [records, setRecords]                     = useState([]);
  const [stats, setStats]                         = useState({ total: 0, pending: 0, accepted: 0, rejected: 0 });
  const [error, setError]                         = useState(null);
  const [rejectNote, setRejectNote]               = useState('');
  const [showRejectConfirm, setShowRejectConfirm] = useState(false);

  const { shouldShowSkeleton, markPageAsLoaded } = usePageCache('payments');
  const [loading, setLoading] = useState(shouldShowSkeleton);

  useEffect(() => {
    const styleId = 'simple-toast-styles';
    if (!document.getElementById(styleId)) {
      const style = document.createElement('style');
      style.id = styleId;
      style.textContent = `
        .simple-toast {
          position: fixed; top: 20px; right: 20px;
          padding: 16px 24px; border-radius: 8px; color: white;
          font-size: 14px; font-weight: 500;
          box-shadow: 0 10px 40px rgba(0,0,0,0.2);
          z-index: 9999; transform: translateX(400px); opacity: 0;
          transition: all 0.3s ease;
        }
        .simple-toast.show { transform: translateX(0); opacity: 1; }
        .toast-success { background: #10b981; }
        .toast-error   { background: #ef4444; }
        .toast-warning { background: #f59e0b; }
      `;
      document.head.appendChild(style);
    }
  }, []);

  useEffect(() => { fetchPayments(); }, [methodFilter, statusFilter]);

  const latestRequest = useRef(0);
  const fetchPayments = async ({ showSkeleton = shouldShowSkeleton, showErrorToast = true } = {}) => {
    const request = ++latestRequest.current;
    try {
      if (showSkeleton) setLoading(true);
      const params = {};
      if (statusFilter !== 'All') params.status = statusFilter;
      if (methodFilter !== 'All') params.method = methodFilter.toLowerCase();
      const { data: response } = await api.get('/payment-records', { params });
      if (request !== latestRequest.current) return;
      setRecords(response.payments || []);
      setStats({
        total:         response.stats?.total         || 0,
        pending:       response.stats?.pending       || 0,
        accepted:      response.stats?.accepted      || 0,
        rejected:      response.stats?.rejected      || 0,
      });
      setError(null);
      markPageAsLoaded();
    } catch (err) {
      if (request !== latestRequest.current) return;
      console.error('Error fetching payments:', err);
      setError('Failed to load payments. Please try again.');
      if (showErrorToast) {
        showToast('Failed to load payments', 'error');
      }
    } finally {
      if (request === latestRequest.current && showSkeleton) setLoading(false);
    }
  };

  useAutoRefresh(
    () => fetchPayments({ showSkeleton: false, showErrorToast: false }),
    { deps: [methodFilter, statusFilter], intervalMs: 15000 }
  );

  const filtered = useMemo(() => {
    return records.filter((r) => {
      const matchSearch =
        r.guest.toLowerCase().includes(search.toLowerCase()) ||
        r.id.toLowerCase().includes(search.toLowerCase()) ||
        r.bookingId.toLowerCase().includes(search.toLowerCase()) ||
        r.method.toLowerCase().includes(search.toLowerCase());
      return matchSearch;
    });
  }, [records, search]);

  const {
    currentPage, totalPages, itemsPerPage, paginatedData,
    totalItems, handlePageChange, handleItemsPerPageChange, resetPage
  } = usePagination(filtered, 10);

  useEffect(() => { resetPage(); }, [search]);

  const handleAccept = async (id) => {
    try {
      await receptionistApi.post(`/receptionist/payments/${id}/accept`);
      await fetchPayments();
      if (selected && selected.id === id) setSelected({ ...selected, status: 'Accepted' });
      showToast('Payment accepted successfully!', 'success');
    } catch (err) {
      console.error('Error accepting payment:', err);
      showToast('Failed to accept payment. Please try again.', 'error');
    }
  };

  const handleReject = async (id) => {
    try {
      const { data: response } = await receptionistApi.post(`/receptionist/payments/${id}/reject`, { reason: rejectNote });
      await fetchPayments();
      if (selected && selected.id === id) setSelected({ ...selected, status: 'Rejected' });
      setShowRejectConfirm(false);
      setRejectNote('');
      showToast(
        response?.message ||
          (response?.booking_cancelled
            ? `Payment rejected. Booking ${response.booking_reference} has been cancelled.`
            : 'Payment rejected.'),
        response?.booking_cancelled ? 'warning' : 'info'
      );
    } catch (err) {
      console.error('Error rejecting payment:', err);
      showToast(err?.response?.data?.message || 'Failed to reject payment. Please try again.', 'error');
    }
  };

  const closeModal = () => {
    setSelected(null);
    setShowRejectConfirm(false);
    setRejectNote('');
  };

  const selectedRooms = useMemo(() => {
    if (!selected) return [];

    if (Array.isArray(selected.rooms) && selected.rooms.length > 0) {
      return selected.rooms
        .map((room) => String(room?.display || '').trim())
        .filter(Boolean);
    }

    const fallback = String(selected.room || '').trim();
    return fallback ? [fallback] : [];
  }, [selected]);

  const isManualPending = (r) => role === 'receptionist' && r.allowed_actions === 'manual';
  const isGcashReviewOnly = (r) => r.allowed_actions === 'review_only' && r.payment_status === 'pending';

  const formatAmount = (row) => {
    const value = Number(row?.amount_value);
    if (!Number.isNaN(value)) {
      return `₱${value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }
    return row?.amount || '₱0.00';
  };

  if (loading) return <PageSkeletonLoader title="Payment Records" showStats={true} />;

  if (error) {
    return (
      <div className="r-payment-page">
        {!embedded && <div className="page-header">
          <div>
            <h1>Payments</h1>
            <p className="page-subtitle">Review and process guest payment submissions.</p>
          </div>
        </div>}
        <div style={{ textAlign: 'center', padding: '3rem', color: 'red' }}>
          {error}<br />
          <button onClick={fetchPayments} style={{ marginTop: '1rem' }}>Retry</button>
        </div>
      </div>
    );
  }

  return (
    <div className="r-payment-page">
      {!embedded && <div className="page-header">
        <div>
          <h1>Payment Records</h1>
          <p className="page-subtitle">Search the complete payment ledger across all collection methods.</p>
        </div>
      </div>}

      {/* Summary cards — matches Dashboard stat-card layout */}
      <div className="payment-summary">
        <div className="pay-sum-item">
          <span className="pay-sum-label">All Records</span>
          <span className="pay-sum-val">{stats.total}</span>
        </div>
        <div className="pay-sum-item">
          <span className="pay-sum-label">Pending</span>
          <span className="pay-sum-val">{stats.pending}</span>
        </div>
        <div className="pay-sum-item">
          <span className="pay-sum-label">Completed</span>
          <span className="pay-sum-val">{stats.accepted}</span>
        </div>
        <div className="pay-sum-item">
          <span className="pay-sum-label">Failed</span>
          <span className="pay-sum-val">{stats.rejected}</span>
        </div>
      </div>

      {/* Toolbar */}
      <div className="page-toolbar">
        <div className="search-wrap">
          <Search size={16} />
          <input
            type="text"
            placeholder="Search by guest, payment ID, booking ID, method…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
        <div className="filter-wrap">
          <Filter size={16} />
          <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
            {STATUS_FILTERS.map((s) => <option key={s}>{s}</option>)}
          </select>
          <ChevronDown size={14} className="select-chevron" />
        </div>
        <div className="filter-wrap">
          <CreditCard size={16} />
          <select value={methodFilter} onChange={(e) => setMethodFilter(e.target.value)}>
            <option>All</option>
            <option>Cash</option>
            <option>GCash</option>
          </select>
          <ChevronDown size={14} className="select-chevron" />
        </div>
      </div>

      {/* Table */}
      <div className="table-card">
        <div className="table-container">
          <table className="data-table">
            <thead>
              <tr>
                <th>Payment ID</th><th>Booking ID</th><th>Guest</th>
                <th>Method</th><th>Reference</th><th>Amount</th>
                <th>Date</th><th>Status</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {paginatedData.length === 0 ? (
                <tr><td colSpan={9} className="empty-row">No payments found.</td></tr>
              ) : (
                paginatedData.map((r) => (
                  <tr key={r.id}>
                    <td className="booking-id">{r.id}</td>
                    <td className="booking-id">{r.bookingId}</td>
                    <td className="guest-name">{r.guest}</td>
                    <td>{r.method}</td>
                    <td className="booking-id">{r.reference}</td>
                    <td className="amount-cell">{formatAmount(r)}</td>
                    <td className="date-cell">{r.displayDate || r.recordedAt}</td>
                    <td>
                      <StatusBadge status={r.status} />
                    </td>
                    <td>
                      <div className="action-group">
                        <TableActionButton iconOnly label="View payment details" onClick={() => setSelected(r)}>
                          <Eye size={15} />
                        </TableActionButton>
                        {isManualPending(r) && (
                          <>
                            <TableActionButton iconOnly tone="success" label="Accept payment" onClick={() => handleAccept(r.id)}>
                              <CheckCircle size={15} />
                            </TableActionButton>
                            <TableActionButton
                              iconOnly
                              tone="danger"
                              label="Reject payment"
                              onClick={() => { setSelected(r); setShowRejectConfirm(true); }}
                            >
                              <XCircle size={15} />
                            </TableActionButton>
                          </>
                        )}
                        {isGcashReviewOnly(r) && onOpenProofReview && (
                          <TableActionButton iconOnly label="Open GCash proof review" onClick={onOpenProofReview}>
                            <ShieldCheck size={15} />
                          </TableActionButton>
                        )}
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {!loading && paginatedData.length > 0 && (
          <Pagination
            currentPage={currentPage}
            totalPages={totalPages}
            totalItems={totalItems}
            itemsPerPage={itemsPerPage}
            onPageChange={handlePageChange}
            onItemsPerPageChange={handleItemsPerPageChange}
            pageSizeOptions={[10, 25, 50, 100]}
          />
        )}
      </div>

      {/* Modal */}
      {selected && (
        <div className="modal-overlay" onClick={closeModal}>
          <div className="modal-box" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <div>
                <h3>Payment Details</h3>
                <span className="booking-id">{selected.id} · {selected.bookingId}</span>
              </div>
              <button className="modal-close" onClick={closeModal}><X size={20} /></button>
            </div>

            <div className="modal-body">
              <div className="detail-grid">
                <div className="detail-section">
                  <div className="detail-section-title"><User size={15} /> Guest Info</div>
                  <div className="detail-row"><span>Guest</span><strong>{selected.guest}</strong></div>
                  <div className="detail-row">
                    <span>{selectedRooms.length > 1 ? `Rooms (${selectedRooms.length})` : 'Room'}</span>
                    <strong className="payment-room-list">
                      {selectedRooms.length > 0 ? (
                        selectedRooms.map((room, index) => (
                          <span key={`${selected.id}-room-${index}`}>{room}</span>
                        ))
                      ) : (
                        <span>N/A</span>
                      )}
                    </strong>
                  </div>
                  <div className="detail-row"><span>Nights</span><strong>{selected.nights}</strong></div>
                </div>
                <div className="detail-section">
                  <div className="detail-section-title"><CreditCard size={15} /> Payment Info</div>
                  <div className="detail-row"><span>Method</span><strong>{selected.method}</strong></div>
                  <div className="detail-row"><span>Reference</span><strong>{selected.reference}</strong></div>
                  <div className="detail-row"><span>Amount</span><strong className="amount-highlight">{formatAmount(selected)}</strong></div>
                  <div className="detail-row"><span>Amount Received</span><strong>{`₱${Number(selected.amount_tendered_value ?? selected.amount_value ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`}</strong></div>
                  <div className="detail-row"><span>Change Due</span><strong style={{ color: Number(selected.change_due_value ?? 0) > 0 ? '#b45309' : '#111827' }}>{`₱${Number(selected.change_due_value ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`}</strong></div>
                  <div className="detail-row"><span>Date</span><strong>{selected.displayDate || selected.recordedAt}</strong></div>
                  {selected.paid_at && (
                    <div className="detail-row"><span>Paid At</span><strong>{selected.paid_at}</strong></div>
                  )}
                </div>
              </div>

              {selected.proofUrl && (
                <div className="proof-section">
                  <div className="proof-img-label">Proof of Payment</div>
                  <img src={selected.proofUrl} alt="Proof of payment" className="proof-img" />
                </div>
              )}

              <div className="detail-status-row" style={{ marginTop: '1rem' }}>
                <span>Status</span>
                <StatusBadge status={selected.status} />
              </div>

              {selected.notes && (
                <div style={{ marginTop: '0.75rem', padding: '10px 14px', borderRadius: '6px', background: '#fef9ec', border: '1px solid #fde68a', fontSize: '13px', color: '#92400e' }}>
                  <strong>Note:</strong> {selected.notes}
                </div>
              )}

              {showRejectConfirm && isManualPending(selected) && (
                <div className="reject-box">
                  <div className="reject-warning">
                    <AlertCircle size={18} style={{ color: '#f59e0b', flexShrink: 0 }} />
                    <div>
                      <strong>Warning: Booking cancellation is conditional</strong>
                      <p>
                        Rejecting this payment cancels the booking only when no completed payments exist.
                        If completed payments exist, cancellation must go through approval workflow.
                      </p>
                    </div>
                  </div>
                  <label>Rejection Reason (required)</label>
                  <textarea
                    rows={3}
                    placeholder="e.g. Amount mismatch, unclear photo, invalid payment proof..."
                    value={rejectNote}
                    onChange={(e) => setRejectNote(e.target.value)}
                  />
                </div>
              )}

              {isGcashReviewOnly(selected) && (
                <div style={{
                  marginTop: '1rem', padding: '12px 16px', borderRadius: '8px',
                  background: '#eff6ff', border: '1px solid #bfdbfe', color: '#1e40af', fontSize: '13px'
                }}>
                  <strong>Manual GCash payment — awaiting proof review.</strong> Use Proof Review in Payment Operations to verify it against the official merchant record.
                </div>
              )}
            </div>

            <div className="modal-footer">
              {isManualPending(selected) && !showRejectConfirm && (
                <>
                  <button className="modal-btn btn-success" onClick={() => handleAccept(selected.id)}>
                    <CheckCircle size={16} /> Accept Payment
                  </button>
                  <button className="modal-btn btn-danger" onClick={() => setShowRejectConfirm(true)}>
                    <XCircle size={16} /> Reject Payment
                  </button>
                </>
              )}
              {showRejectConfirm && isManualPending(selected) && (
                <>
                  <button className="modal-btn btn-danger" onClick={() => handleReject(selected.id)}>
                    <XCircle size={16} /> Confirm Reject Payment
                  </button>
                  <button className="modal-btn btn-ghost" onClick={() => setShowRejectConfirm(false)}>Cancel</button>
                </>
              )}
              {!isManualPending(selected) && (
                <>
                  {isGcashReviewOnly(selected) && onOpenProofReview && <button className="modal-btn btn-success" onClick={() => { closeModal(); onOpenProofReview(); }}><ShieldCheck size={16} /> Open Proof Review</button>}
                  <button className="modal-btn btn-ghost" onClick={closeModal}>Close</button>
                </>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default PaymentPage;
