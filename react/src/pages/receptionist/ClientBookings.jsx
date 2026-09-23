// src/pages/receptionist/ClientBookings.jsx
import React, { useState, useEffect } from 'react';
import bookingService from '../../services/receptionist/bookingService';
import ConfirmDialog from '../../components/ConfirmDialog';
import StatusBadge from '../../components/StatusBadge';
import TableActionButton from '../../components/TableActionButton';
import './ClientBookings.css';

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

const ClientBookings = () => {
  const [bookings, setBookings] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [stats, setStats] = useState(null);

  const [filters, setFilters] = useState({
    status: 'all',
    search: '',
    start_date: '',
    end_date: '',
  });

  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);

  const [selectedBooking, setSelectedBooking] = useState(null);
  const [showModal, setShowModal] = useState(false);

  const [actionDialog, setActionDialog] = useState({
    open: false,
    action: null,
    bookingId: null,
    title: '',
    message: '',
    confirmLabel: 'Confirm',
    danger: false,
    requireReason: false,
    requestEarlyCheckIn: false,
  });
  const [rejectReason, setRejectReason] = useState('');

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

  useEffect(() => {
    fetchBookings();
    fetchStats();
  }, [filters, currentPage]);

  const fetchBookings = async () => {
    setLoading(true);
    setError('');

    try {
      const response = await bookingService.getBookings({
        ...filters,
        page: currentPage,
        per_page: 15,
      });

      if (response.success) {
        setBookings(response.data.data);
        setTotalPages(response.data.last_page);
      }
    } catch (err) {
      console.error('Error fetching bookings:', err);
      setError('Failed to load bookings');
    } finally {
      setLoading(false);
    }
  };

  const fetchStats = async () => {
    try {
      const response = await bookingService.getStats();
      if (response.success) {
        setStats(response.data);
      }
    } catch (err) {
      console.error('Error fetching stats:', err);
    }
  };

  const handleFilterChange = (e) => {
    const { name, value } = e.target;
    setFilters((prev) => ({
      ...prev,
      [name]: value,
    }));
    setCurrentPage(1);
  };

  const openActionDialog = (action, bookingId) => {
    const map = {
      confirm: {
        title: 'Confirm Booking',
        message: 'Are you sure you want to confirm this booking?',
        confirmLabel: 'Confirm Booking',
        danger: false,
        requireReason: false,
      },
      reject: {
        title: 'Reject Booking',
        message: 'Please provide a reason before rejecting this booking.',
        confirmLabel: 'Reject Booking',
        danger: true,
        requireReason: true,
      },
      checkin: {
        title: 'Check In Guest',
        message: 'Proceed with guest check-in for this booking?',
        confirmLabel: 'Check In',
        danger: false,
        requireReason: false,
        requestEarlyCheckIn: false,
      },
      checkout: {
        title: 'Check Out Guest',
        message: 'Proceed with guest check-out for this booking?',
        confirmLabel: 'Check Out',
        danger: false,
        requireReason: false,
      },
    };

    setRejectReason('');
    setActionDialog({
      open: true,
      action,
      bookingId,
      ...map[action],
    });
  };

  const closeActionDialog = () => {
    setActionDialog({
      open: false,
      action: null,
      bookingId: null,
      title: '',
      message: '',
      confirmLabel: 'Confirm',
      danger: false,
      requireReason: false,
      requestEarlyCheckIn: false,
    });
    setRejectReason('');
  };

  const handleActionConfirm = async () => {
    const { action, bookingId, requireReason, requestEarlyCheckIn } = actionDialog;

    if (!action || !bookingId) return;

    if (requireReason && (!rejectReason.trim() || (requestEarlyCheckIn && rejectReason.trim().length < 10))) {
      showToast(requestEarlyCheckIn ? 'Enter an early check-in reason using at least 10 characters.' : 'Rejection reason is required.', 'warning');
      return;
    }

    try {
      let response;
      if (action === 'confirm') {
        response = await bookingService.confirmBooking(bookingId);
        if (response.success) showToast('Booking confirmed successfully!', 'success');
      }

      if (action === 'reject') {
        response = await bookingService.rejectBooking(bookingId, rejectReason.trim());
        if (response.success) showToast('Booking rejected successfully!', 'success');
      }

      if (action === 'checkin') {
        if (requestEarlyCheckIn) {
          response = await bookingService.requestEarlyCheckIn(bookingId, rejectReason.trim());
          if (response.success) showToast(response.message || 'Approval request sent.', 'success');
        } else {
          response = await bookingService.checkIn(bookingId);
          if (response.success) showToast('Guest checked in successfully!', 'success');
        }
      }

      if (action === 'checkout') {
        response = await bookingService.checkOut(bookingId);
        if (response.success) showToast('Guest checked out successfully!', 'success');
      }

      closeActionDialog();
      fetchBookings();
      fetchStats();
    } catch (err) {
      const msg = err.response?.data?.message || 'Unknown error';
      const code = err.response?.data?.code;

      if (action === 'checkin' && code === 'EARLY_APPROVAL_REQUIRED') {
        setActionDialog((previous) => ({
          ...previous,
          title: 'Request Early Check-In',
          message: msg,
          confirmLabel: 'Send Approval Request',
          requireReason: true,
          requestEarlyCheckIn: true,
        }));
        showToast(msg, 'warning');
        return;
      }

      if (action === 'checkin' && code === 'EARLY_APPROVAL_PENDING') {
        closeActionDialog();
        showToast(msg, 'warning');
        return;
      }

      showToast(`Action failed: ${msg}`, 'error');
    }
  };

  const viewDetails = async (id) => {
    try {
      const response = await bookingService.getBooking(id);
      if (response.success) {
        setSelectedBooking(response.data);
        setShowModal(true);
      }
    } catch {
      showToast('Failed to load booking details', 'error');
    }
  };

  return (
    <div className="client-bookings-page">
      <div className="page-header">
        <h1>Client Bookings</h1>
        <button className="btn btn-primary" onClick={fetchBookings}>
          Refresh
        </button>
      </div>

      {stats && (
        <div className="stats-grid">
          <div className="stat-card"><h3>{stats.pending_bookings}</h3><p>Pending</p></div>
          <div className="stat-card"><h3>{stats.confirmed_bookings}</h3><p>Confirmed</p></div>
          <div className="stat-card"><h3>{stats.checked_in}</h3><p>Checked In</p></div>
          <div className="stat-card"><h3>${stats.total_revenue?.toFixed(2)}</h3><p>Total Revenue</p></div>
        </div>
      )}

      <div className="filters-section">
        <input
          type="text"
          name="search"
          placeholder="Search by name, email, or reference..."
          value={filters.search}
          onChange={handleFilterChange}
          className="form-input"
        />

        <select
          name="status"
          value={filters.status}
          onChange={handleFilterChange}
          className="form-select"
        >
          <option value="all">All Status</option>
          <option value="pending">Pending</option>
          <option value="confirmed">Confirmed</option>
          <option value="checked_in">Checked In</option>
          <option value="checked_out">Checked Out</option>
          <option value="cancelled">Cancelled</option>
        </select>

        <input type="date" name="start_date" value={filters.start_date} onChange={handleFilterChange} className="form-input" />
        <input type="date" name="end_date" value={filters.end_date} onChange={handleFilterChange} className="form-input" />
      </div>

      {loading ? (
        <div className="loading">Loading bookings...</div>
      ) : error ? (
        <div className="error">{error}</div>
      ) : (
        <div className="table-container">
          <table className="bookings-table">
            <thead>
              <tr>
                <th>Reference</th>
                <th>Guest Name</th>
                <th>Room(s)</th>
                <th>Check-in</th>
                <th>Check-out</th>
                <th>Status</th>
                <th>Total</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {bookings.length === 0 ? (
                <tr><td colSpan="8" className="text-center">No bookings found</td></tr>
              ) : (
                bookings.map((booking) => (
                  <tr key={booking.id}>
                    <td>{booking.reference_number}</td>
                    <td>{booking.guest?.guest_name || 'N/A'}</td>
                    <td>{booking.booking_rooms?.map((br) => br.room?.room_number).join(', ') || 'N/A'}</td>
                    <td>{booking.check_in}</td>
                    <td>{booking.check_out}</td>
                    <td>
                      <StatusBadge status={booking.booking_status} />
                    </td>
                    <td>${booking.total_amount}</td>
                    <td className="actions-cell">
                      <TableActionButton label="View booking" onClick={() => viewDetails(booking.id)}>
                        View
                      </TableActionButton>

                      {booking.booking_status === 'pending' && (
                        <>
                          <TableActionButton tone="success" label="Confirm booking" onClick={() => openActionDialog('confirm', booking.id)}>
                            Confirm
                          </TableActionButton>
                          <TableActionButton tone="danger" label="Reject booking" onClick={() => openActionDialog('reject', booking.id)}>
                            Reject
                          </TableActionButton>
                        </>
                      )}

                      {booking.booking_status === 'confirmed' && (
                        <TableActionButton label="Check in booking" onClick={() => openActionDialog('checkin', booking.id)}>
                          Check In
                        </TableActionButton>
                      )}

                      {booking.booking_status === 'checked_in' && (
                        <TableActionButton tone="warning" label="Check out booking" onClick={() => openActionDialog('checkout', booking.id)}>
                          Check Out
                        </TableActionButton>
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      )}

      {totalPages > 1 && (
        <div className="pagination">
          <button
            onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
            disabled={currentPage === 1}
            className="btn btn-sm"
          >
            Previous
          </button>
          <span>Page {currentPage} of {totalPages}</span>
          <button
            onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
            disabled={currentPage === totalPages}
            className="btn btn-sm"
          >
            Next
          </button>
        </div>
      )}

      {showModal && selectedBooking && (
        <div className="modal-overlay" onClick={() => setShowModal(false)}>
          <div className="modal-content" onClick={(e) => e.stopPropagation()}>
            <h2>Booking Details</h2>
            <div className="booking-details">
              <p><strong>Reference:</strong> {selectedBooking.reference_number}</p>
              <p><strong>Guest:</strong> {selectedBooking.guest?.guest_name}</p>
              <p><strong>Email:</strong> {selectedBooking.guest?.guest_email}</p>
              <p><strong>Phone:</strong> {selectedBooking.guest?.guest_phone}</p>
              <p><strong>Check-in:</strong> {selectedBooking.check_in}</p>
              <p><strong>Check-out:</strong> {selectedBooking.check_out}</p>
              <p><strong>Guests:</strong> {selectedBooking.number_of_guests}</p>
              <p><strong>Status:</strong> {selectedBooking.booking_status}</p>
              <p><strong>Total:</strong> ${selectedBooking.total_amount}</p>
              {selectedBooking.special_requests && (
                <p><strong>Special Requests:</strong><br />{selectedBooking.special_requests}</p>
              )}
            </div>
            <button className="btn btn-secondary" onClick={() => setShowModal(false)}>
              Close
            </button>
          </div>
        </div>
      )}

      <ConfirmDialog
        open={actionDialog.open}
        title={actionDialog.title}
        message={actionDialog.message}
        confirmLabel={actionDialog.confirmLabel}
        cancelLabel="Cancel"
        danger={actionDialog.danger}
        confirmDisabled={actionDialog.requireReason && (
          !rejectReason.trim()
          || (actionDialog.requestEarlyCheckIn && rejectReason.trim().length < 10)
        )}
        onCancel={closeActionDialog}
        onConfirm={handleActionConfirm}
      >
        {actionDialog.requireReason && (
          <div>
            <label style={{ display: 'block', marginBottom: 6, fontSize: '0.875rem', fontFamily: "'Inter', sans-serif", fontWeight: 500, color: 'var(--color-text-secondary)' }}>
              {actionDialog.requestEarlyCheckIn ? 'Early Check-In Reason' : 'Rejection Reason'}
            </label>
            <textarea
              rows={3}
              value={rejectReason}
              onChange={(e) => setRejectReason(e.target.value)}
              maxLength={500}
              placeholder={actionDialog.requestEarlyCheckIn
                ? 'Example: Room is ready and guest arrived early due to an early flight.'
                : 'Enter reason for rejecting this booking'}
                style={{
                  width: '100%',
                  border: '1px solid #d1d5db',
                  borderRadius: 8,
                  padding: '10px 12px',
                  fontFamily: "'Inter', sans-serif",
                  fontSize: '0.875rem',
                  fontWeight: 400,
                  color: 'var(--color-text-primary)',
                }}
              />
              {actionDialog.requestEarlyCheckIn && (
                <small style={{ display: 'block', marginTop: 6, color: 'var(--color-text-secondary)' }}>
                  At least 10 characters. An administrator must approve this request.
                </small>
              )}
          </div>
        )}
      </ConfirmDialog>
    </div>
  );
};

export default ClientBookings;
