import { useNotificationTarget } from '../../hooks/useNotificationTarget';
import React, { useEffect, useMemo, useState } from 'react';
import { Search, Filter, ChevronDown, Eye, X, User, BedDouble, ClipboardCheck } from 'lucide-react';
import '../receptionist/Reservation.css';
import cancellationRequestService from '../../services/receptionist/cancellationRequestService';
import { PageSkeletonLoader, usePageCache } from '../../components/ProtectedRoute';
import StatusBadge from '../../components/StatusBadge';
import TableActionButton from '../../components/TableActionButton';
import useAutoRefresh from '../../hooks/useAutoRefresh';

const STATUS_FILTERS = [
  { label: 'All', value: 'All' },
  { label: 'Pending Approval', value: 'pending_approval' },
  { label: 'Approved', value: 'approved' },
  { label: 'Refund Pending', value: 'refund_pending' },
  { label: 'Refunded', value: 'refunded' },
  { label: 'Rejected', value: 'rejected' },
  { label: 'Cancelled', value: 'cancelled' },
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

const toTitleCase = (value) => String(value || '')
  .replace(/_/g, ' ')
  .toLowerCase()
  .replace(/\b\w/g, (char) => char.toUpperCase());

const formatBookingStatus = (status) => {
  const key = String(status || '').toLowerCase();
  const map = {
    pending: 'Pending',
    confirmed: 'Confirmed',
    checked_in: 'Checked In',
    checked_out: 'Checked Out',
    cancelled: 'Cancelled',
    no_show: 'No-Show',
  };
  return map[key] ?? toTitleCase(status);
};

const formatRoomLabel = (room) => {
  const raw = String(room || '').trim();
  if (!raw || raw.toLowerCase() === 'n/a') return 'N/A';
  if (raw.toLowerCase().includes(' - room ')) return raw;

  const match = raw.match(/^(.*)\s+(\S+)$/);
  if (!match) return toTitleCase(raw);

  const roomType = toTitleCase(match[1]);
  const roomNumber = match[2];
  return `${roomType} - Room ${roomNumber}`;
};

const formatRefundMethod = (method) => {
  if (!method) return 'N/A';
  const map = {
    original_payment_method: 'Original Payment Method',
    cash: 'Cash',
    gcash: 'GCash',
    bank_transfer: 'Bank Transfer',
  };
  const key = String(method).toLowerCase();
  return map[key] ?? toTitleCase(method);
};

const formatRequestedBy = (value) => {
  const raw = String(value || '').trim();
  if (!raw || raw.toLowerCase() === 'unknown') return 'Guest (Self-Requested)';
  return raw;
};

const CancellationPage = () => {
  useNotificationTarget((target) => { setSearch(target.search); setStatusFilter('All'); setSelected(null); });
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('All');
  const [selected, setSelected] = useState(null);
  const [records, setRecords] = useState([]);
  const [error, setError] = useState(null);

  const { shouldShowSkeleton, markPageAsLoaded } = usePageCache('cancellations');
  const [loading, setLoading] = useState(shouldShowSkeleton);

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

  useEffect(() => {
    fetchRequests();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusFilter]);

  const fetchRequests = async ({ showSkeleton = shouldShowSkeleton, showErrorToast = true } = {}) => {
    try {
      if (showSkeleton) {
        setLoading(true);
      }

      const params = {};
      if (statusFilter !== 'All') {
        params.status = statusFilter;
      }

      const response = await cancellationRequestService.getRequests(params);
      setRecords(response.requests || []);
      setError(null);
      markPageAsLoaded();
    } catch (err) {
      console.error('Error fetching cancellation requests:', err);
      setError('Failed to load cancellation requests. Please try again.');
      if (showErrorToast) {
        showToast('Failed to load cancellation requests', 'error');
      }
    } finally {
      if (showSkeleton) {
        setLoading(false);
      }
    }
  };

  useAutoRefresh(
    () => fetchRequests({ showSkeleton: false, showErrorToast: false }),
    { deps: [statusFilter], intervalMs: 15000 }
  );

  const filtered = useMemo(() => {
    return records.filter((record) => {
      const lowerSearch = search.toLowerCase();
      return (
        String(record.guest || '').toLowerCase().includes(lowerSearch)
        || String(record.id || '').toLowerCase().includes(lowerSearch)
        || String(record.bookingId || '').toLowerCase().includes(lowerSearch)
        || String(record.room || '').toLowerCase().includes(lowerSearch)
      );
    });
  }, [records, search]);

  if (loading) {
    return <PageSkeletonLoader title="Cancellation Requests" />;
  }

  if (error) {
    return (
      <div className="r-cancellation-page">
        <div className="page-header">
          <div>
            <h1>Cancellation Requests</h1>
            <p className="page-subtitle">Track cancellation requests submitted for admin approval.</p>
          </div>
        </div>
        <div style={{ textAlign: 'center', padding: '3rem', color: 'red' }}>
          {error}
          <br />
          <button onClick={fetchRequests} style={{ marginTop: '1rem' }}>Retry</button>
        </div>
      </div>
    );
  }

  return (
    <div className="r-cancellation-page">
      <div className="page-header">
        <div>
          <h1>Cancellation Requests</h1>
          <p className="page-subtitle">Track cancellation requests submitted for admin approval.</p>
        </div>
      </div>

      <div className="page-toolbar">
        <div className="search-wrap">
          <Search size={16} />
          <input
            type="text"
            placeholder="Search by guest, request ID, booking ID..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
        <div className="filter-wrap">
          <Filter size={16} />
          <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
            {STATUS_FILTERS.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
          </select>
          <ChevronDown size={14} className="select-chevron" />
        </div>
      </div>

      <div className="table-card">
        <div className="table-container">
          <table className="data-table">
            <thead>
              <tr>
                <th>Request ID</th>
                <th>Booking ID</th>
                <th>Guest</th>
                <th>Room</th>
                <th>Check-in</th>
                <th>Refund Amount</th>
                <th>Status</th>
                <th>Requested On</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {filtered.length === 0 ? (
                <tr><td colSpan={9} className="empty-row">No cancellation requests found.</td></tr>
              ) : (
                filtered.map((record) => (
                  <tr key={record.id}>
                    <td className="booking-id">{record.id}</td>
                    <td className="booking-id">{record.bookingId}</td>
                    <td className="guest-name">{record.guest}</td>
                    <td>{record.room}</td>
                    <td>{record.checkIn}</td>
                    <td className="amount-cell">₱{Number(record.refundAmount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
                    <td><StatusBadge status={record.statusLabel} /></td>
                    <td className="date-cell">{record.requestedAt ? String(record.requestedAt).replace('T', ' ').slice(0, 16) : 'N/A'}</td>
                    <td>
                      <div className="action-group">
                        <TableActionButton iconOnly label="View cancellation details" onClick={() => setSelected(record)}>
                          <Eye size={15} />
                        </TableActionButton>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {selected && (
        <div className="modal-overlay" onClick={() => setSelected(null)}>
          <div className="modal-box" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <div>
                <h3>Cancellation Request Details</h3>
                <span className="booking-id">{selected.id} - {selected.bookingId}</span>
              </div>
              <button className="modal-close" onClick={() => setSelected(null)}><X size={20} /></button>
            </div>

            <div className="modal-body">
              <div className="detail-grid">
                <div className="detail-section">
                  <div className="detail-section-title"><User size={15} /> Guest Info</div>
                  <div className="detail-row"><span>Name</span><strong>{selected.guest}</strong></div>
                  <div className="detail-row"><span>Booking Status</span><strong>{formatBookingStatus(selected.bookingStatus)}</strong></div>
                  <div className="detail-row"><span>Requested By</span><strong>{formatRequestedBy(selected.requestedBy)}</strong></div>
                </div>
                <div className="detail-section">
                  <div className="detail-section-title"><BedDouble size={15} /> Stay Info</div>
                  <div className="detail-row"><span>Room</span><strong>{formatRoomLabel(selected.room)}</strong></div>
                  <div className="detail-row"><span>Check-in</span><strong>{selected.checkIn}</strong></div>
                  <div className="detail-row"><span>Check-out</span><strong>{selected.checkOut}</strong></div>
                  <div className="detail-row"><span>Refund Amount</span><strong className="amount-highlight">₱{Number(selected.refundAmount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</strong></div>
                  <div className="detail-row"><span>Refund Method</span><strong>{formatRefundMethod(selected.refundMethod)}</strong></div>
                </div>
              </div>

              <div className="detail-section" style={{ marginTop: '1.25rem' }}>
                <div className="detail-section-title"><ClipboardCheck size={15} /> Workflow Status</div>
                <div className="detail-row">
                  <span>Status</span>
                  <StatusBadge status={selected.statusLabel} />
                </div>
                <div className="detail-row"><span>Requested At</span><strong>{selected.requestedAt || 'N/A'}</strong></div>
                <div className="detail-row"><span>Approved At</span><strong>{selected.approvedAt || 'N/A'}</strong></div>
                <div className="detail-row"><span>Rejected At</span><strong>{selected.rejectedAt || 'N/A'}</strong></div>
                <div className="detail-row"><span>Finalized At</span><strong>{selected.finalizedAt || 'N/A'}</strong></div>
              </div>

              <div className="note-block">
                <div className="note-block-label">Reason</div>
                <p className="note-block-text">
                  {selected.reason || <span className="note-block-empty">No reason provided</span>}
                </p>
              </div>

              <div className="note-block">
                <div className="note-block-label">Request Note</div>
                <p className="note-block-text">
                  {selected.requestNote || <span className="note-block-empty">No request note provided</span>}
                </p>
              </div>

              <div className="note-block">
                <div className="note-block-label">Admin Decision Note</div>
                <p className="note-block-text">
                  {selected.decisionNote || <span className="note-block-empty">No admin decision note provided</span>}
                </p>
              </div>
            </div>

            <div className="modal-footer">
              <button className="modal-btn btn-ghost" onClick={() => setSelected(null)}>Close</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default CancellationPage;
