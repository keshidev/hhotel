import { useNotificationTarget } from '../../hooks/useNotificationTarget';
import React, { useEffect, useMemo, useState } from 'react';
import { Search, Filter, ChevronDown, Eye, X, BedDouble, ArrowRightLeft } from 'lucide-react';
import './Reservation.css';
import transferRequestService from '../../services/receptionist/transferRequestService';
import { PageSkeletonLoader, usePageCache } from '../../components/ProtectedRoute';
import StatusBadge from '../../components/StatusBadge';
import TableActionButton from '../../components/TableActionButton';
import useAutoRefresh from '../../hooks/useAutoRefresh';

const STATUS_FILTERS = [
  { label: 'All', value: 'All' },
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

const TransferRequestsPage = () => {
  useNotificationTarget((target) => { setSearch(target.search); setStatusFilter('All'); setSelected(null); });
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('All');
  const [selected, setSelected] = useState(null);
  const [records, setRecords] = useState([]);
  const [error, setError] = useState(null);

  const { shouldShowSkeleton, markPageAsLoaded } = usePageCache('transfer-requests');
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

      const response = await transferRequestService.getRequests(params);
      setRecords(response.requests || []);
      setError(null);
      markPageAsLoaded();
    } catch (err) {
      console.error('Error fetching transfer requests:', err);
      setError('Failed to load transfer requests. Please try again.');
      if (showErrorToast) {
        showToast(err?.response?.data?.message || 'Failed to load transfer requests', 'error');
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
    const keyword = search.trim().toLowerCase();
    return records.filter((record) => (
      String(record.id || '').toLowerCase().includes(keyword)
      || String(record.bookingId || '').toLowerCase().includes(keyword)
      || String(record.guest || '').toLowerCase().includes(keyword)
      || String(record.currentRoom || '').toLowerCase().includes(keyword)
      || String(record.targetRoom || '').toLowerCase().includes(keyword)
    ));
  }, [records, search]);

  if (loading) {
    return <PageSkeletonLoader title="Transfer Requests" />;
  }

  if (error) {
    return (
      <div className="r-reservation-page">
        <div className="page-header">
          <div>
            <h1>Transfer Requests</h1>
            <p className="page-subtitle">Track room transfer requests submitted for admin approval.</p>
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
    <div className="r-reservation-page">
      <div className="page-header">
        <div>
          <h1>Transfer Requests</h1>
          <p className="page-subtitle">Track room transfer requests submitted for admin approval.</p>
        </div>
      </div>

      <div className="page-toolbar">
        <div className="search-wrap">
          <Search size={16} />
          <input
            type="text"
            placeholder="Search by request, booking, guest, or room..."
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
                <th>Current Room</th>
                <th>Target Room</th>
                <th>Status</th>
                <th>Requested On</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {filtered.length === 0 ? (
                <tr><td colSpan={8} className="empty-row">No transfer requests found.</td></tr>
              ) : (
                filtered.map((record) => (
                  <tr key={record.id}>
                    <td className="booking-id">{record.id}</td>
                    <td className="booking-id">{record.bookingId}</td>
                    <td className="guest-name">{record.guest}</td>
                    <td>{record.currentRoom}</td>
                    <td>{record.targetRoom}</td>
                    <td><StatusBadge status={record.statusLabel} /></td>
                    <td className="date-cell">{record.requestedAt ? String(record.requestedAt).replace('T', ' ').slice(0, 16) : 'N/A'}</td>
                    <td>
                      <div className="action-group">
                        <TableActionButton iconOnly label="View transfer details" onClick={() => setSelected(record)}>
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
                <h3>Transfer Request Details</h3>
                <span className="booking-id">{selected.id} - {selected.bookingId}</span>
              </div>
              <button className="modal-close" onClick={() => setSelected(null)}><X size={20} /></button>
            </div>

            <div className="modal-body">
              <div className="detail-grid">
                <div className="detail-section">
                  <div className="detail-section-title"><BedDouble size={15} /> Booking Info</div>
                  <div className="detail-row"><span>Guest</span><strong>{selected.guest}</strong></div>
                  <div className="detail-row"><span>Booking Status</span><strong>{formatBookingStatus(selected.bookingStatus)}</strong></div>
                  <div className="detail-row"><span>Check-in</span><strong>{selected.checkIn}</strong></div>
                  <div className="detail-row"><span>Check-out</span><strong>{selected.checkOut}</strong></div>
                </div>
                <div className="detail-section">
                  <div className="detail-section-title"><ArrowRightLeft size={15} /> Transfer Info</div>
                  <div className="detail-row"><span>Current Room</span><strong>{formatRoomLabel(selected.currentRoom)}</strong></div>
                  <div className="detail-row"><span>Target Room</span><strong>{formatRoomLabel(selected.targetRoom)}</strong></div>
                  <div className="detail-row"><span>Requested By</span><strong>{selected.requestedBy}</strong></div>
                  <div className="detail-row"><span>Requested At</span><strong>{selected.requestedAt || 'N/A'}</strong></div>
                </div>
              </div>

              <div className="detail-section" style={{ marginTop: '1.25rem' }}>
                <div className="detail-section-title">Workflow Status</div>
                <div className="detail-row">
                  <span>Status</span>
                  <StatusBadge status={selected.statusLabel} />
                </div>
                <div className="detail-row"><span>Approved At</span><strong>{selected.approvedAt || 'N/A'}</strong></div>
                <div className="detail-row"><span>Rejected At</span><strong>{selected.rejectedAt || 'N/A'}</strong></div>
                <div className="detail-row"><span>Completed At</span><strong>{selected.completedAt || 'N/A'}</strong></div>
              </div>

              <div className="note-block">
                <div className="note-block-label">Reason</div>
                <p className="note-block-text">
                  {selected.reason || <span className="note-block-empty">No reason provided</span>}
                </p>
              </div>

              <div className="note-block">
                <div className="note-block-label">Admin Decision Note</div>
                <p className="note-block-text">
                  {selected.decisionNote || <span className="note-block-empty">No admin decision note provided</span>}
                </p>
              </div>

              <div className="note-block">
                <div className="note-block-label">Completion Note</div>
                <p className="note-block-text">
                  {selected.completionNote || <span className="note-block-empty">No completion note provided</span>}
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

export default TransferRequestsPage;
