import { useNotificationTarget } from '../../hooks/useNotificationTarget';
﻿import React, { useState, useEffect, useMemo } from 'react';
import {
  Search, Filter, ChevronDown, Eye,
  X, User, BedDouble
} from 'lucide-react';
import './Reservation.css';
import reservationService from '../../services/receptionist/reservationService';
import transferRequestService from '../../services/receptionist/transferRequestService';
import { PageSkeletonLoader, usePageCache } from '../../components/ProtectedRoute';
import Pagination from '../../components/Pagination';
import StatusBadge from '../../components/StatusBadge';
import TableActionButton from '../../components/TableActionButton';
import { usePagination } from '../../hooks/usePagination';
import useAutoRefresh from '../../hooks/useAutoRefresh';
import { formatCurrency } from '../../utils/currency';

const STATUS_OPTIONS = [
  { label: 'All', value: 'All' },
  { label: 'Confirmed', value: 'confirmed' },
  { label: 'Checked-In', value: 'checked_in' },
  { label: 'Checked-Out', value: 'checked_out' },
  { label: 'Pending', value: 'pending' },
  { label: 'Room Pending', value: 'room_pending' },
  { label: 'Cancelled', value: 'cancelled' },
  { label: 'No-Show', value: 'no_show' },
];

const normalizeBookingStatus = (status) =>
  String(status || '').toLowerCase().replace(/[\s-]+/g, '_');

const shouldShowRoomPendingBadge = (booking) => {
  const assignmentStatus = String(booking?.roomAssignmentStatus || 'pending_assignment');
  const bookingStatus = normalizeBookingStatus(booking?.status);
  return assignmentStatus === 'pending_assignment' && ['pending', 'confirmed'].includes(bookingStatus);
};

const canAssignRoomManually = (booking) => {
  const assignmentStatus = String(booking?.roomAssignmentStatus || 'pending_assignment');
  const bookingStatus = normalizeBookingStatus(booking?.status);
  return assignmentStatus === 'pending_assignment' && ['confirmed', 'checked_in'].includes(bookingStatus);
};

const formatRoomTypeLabel = (roomType) => String(roomType || '')
  .replace(/_/g, ' ')
  .replace(/\b\w/g, (char) => char.toUpperCase())
  .trim();

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

const ReservationPage = () => {
  useNotificationTarget((target) => { setSearch(target.search); setStatusFilter('All'); setSelected(null); });
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('All');
  const [selected, setSelected] = useState(null);
  const [transferRooms, setTransferRooms] = useState([]);
  const [loadingTransferRooms, setLoadingTransferRooms] = useState(false);
  const [transferBookingRoomId, setTransferBookingRoomId] = useState('');
  const [transferTargetRoomId, setTransferTargetRoomId] = useState('');
  const [transferReason, setTransferReason] = useState('');
  const [transferSubmitting, setTransferSubmitting] = useState(false);
  const [assignableRooms, setAssignableRooms] = useState([]);
  const [loadingAssignableRooms, setLoadingAssignableRooms] = useState(false);
  const [assigningRoom, setAssigningRoom] = useState(false);
  const [assignBookingRoomId, setAssignBookingRoomId] = useState('');
  const [assignTargetRoomId, setAssignTargetRoomId] = useState('');
  const [pendingBookingLines, setPendingBookingLines] = useState([]);
  const [records, setRecords] = useState([]);
  const [error, setError] = useState(null);

  const { shouldShowSkeleton, markPageAsLoaded } = usePageCache('reservations');
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
    fetchReservations();
  }, [statusFilter]);

  const fetchReservations = async ({ showSkeleton = shouldShowSkeleton, showErrorToast = true } = {}) => {
    try {
      if (showSkeleton) {
        setLoading(true);
      }
      const params = {};
      if (statusFilter !== 'All' && statusFilter !== 'room_pending') {
        params.status = statusFilter;
      }
      const response = await reservationService.getReservations(params);
      setRecords(response.reservations || []);
      setError(null);
      markPageAsLoaded();
    } catch (err) {
      console.error('Error fetching reservations:', err);
      setError('Failed to load reservations. Please try again.');
      if (showErrorToast) {
        showToast('Failed to load reservations', 'error');
      }
    } finally {
      if (showSkeleton) {
        setLoading(false);
      }
    }
  };

  useAutoRefresh(
    () => fetchReservations({ showSkeleton: false, showErrorToast: false }),
    { deps: [statusFilter], intervalMs: 15000 }
  );

  const filtered = useMemo(() => {
    return records.filter((r) => {
      if (statusFilter === 'room_pending' && !shouldShowRoomPendingBadge(r)) {
        return false;
      }
      const matchSearch =
        String(r.guest || '').toLowerCase().includes(search.toLowerCase()) ||
        String(r.id || '').toLowerCase().includes(search.toLowerCase()) ||
        String(r.room || '').toLowerCase().includes(search.toLowerCase());
      return matchSearch;
    });
  }, [records, search, statusFilter]);

  const {
    currentPage,
    totalPages,
    itemsPerPage,
    paginatedData,
    totalItems,
    handlePageChange,
    handleItemsPerPageChange,
    resetPage
  } = usePagination(filtered, 10);

  useEffect(() => {
    resetPage();
  }, [search]);

  const closeModal = () => {
    setSelected(null);
    setTransferRooms([]);
    setTransferBookingRoomId('');
    setTransferTargetRoomId('');
    setTransferReason('');
    setLoadingTransferRooms(false);
    setTransferSubmitting(false);
    setAssignableRooms([]);
    setLoadingAssignableRooms(false);
    setAssigningRoom(false);
    setAssignBookingRoomId('');
    setAssignTargetRoomId('');
    setPendingBookingLines([]);
  };

  const isCheckedIn = selected?.status === 'Checked-In';
  const bookingRoomLines = selected?.bookingRooms || [];
  const canTransfer = isCheckedIn && bookingRoomLines.length > 0;

  const normalizeRoomAddons = (bookingRoom) => {
    if (Array.isArray(bookingRoom?.addons)) {
      return bookingRoom.addons;
    }
    if (Array.isArray(bookingRoom?.room_addons)) {
      return bookingRoom.room_addons;
    }
    const breakdown = selected?.addons_breakdown && typeof selected.addons_breakdown === 'object'
      ? selected.addons_breakdown
      : {};
    const keys = [bookingRoom?.booking_room_id, bookingRoom?.id, bookingRoom?.room_id, bookingRoom?.room?.id]
      .filter((value) => value !== null && value !== undefined)
      .map((value) => String(value));
    for (const key of keys) {
      if (Array.isArray(breakdown[key])) {
        return breakdown[key];
      }
    }
    return [];
  };

  const getAddonQuantity = (addon) => Math.max(1, parseInt(addon?.quantity ?? 1, 10) || 1);
  const getAddonUnitPrice = (addon) => Number(addon?.price || 0);
  const getAddonLineTotal = (addon) => {
    const explicitLineTotal = Number(addon?.line_total);
    if (Number.isFinite(explicitLineTotal)) {
      return explicitLineTotal;
    }
    return getAddonUnitPrice(addon) * getAddonQuantity(addon);
  };

  const selectedAddonItems = (selected?.bookingRooms || []).flatMap((line, lineIndex) => {
    const roomType = line?.room?.room_type || line?.requested_room_type
      ? formatRoomTypeLabel(line?.room?.room_type || line?.requested_room_type)
      : 'Room';
    const roomLabel = line?.room?.room_number ? `${roomType} ${line.room.room_number}` : roomType;
    return normalizeRoomAddons(line).map((addon, addonIndex) => ({
      key: `${lineIndex}-${addonIndex}-${addon?.id || addon?.name || 'addon'}`,
      roomLabel,
      name: addon?.name || 'Add-on',
      description: addon?.description || null,
      quantity: getAddonQuantity(addon),
      unitPrice: getAddonUnitPrice(addon),
      lineTotal: getAddonLineTotal(addon),
    }));
  });

  const loadTransferRooms = async () => {
    if (!selected?.id) return;
    try {
      setLoadingTransferRooms(true);
      const params = transferBookingRoomId
        ? { booking_room_id: Number(transferBookingRoomId) }
        : {};
      const res = await reservationService.getTransferRooms(selected.id, params);
      setTransferRooms(res.available_rooms || []);
      if (res.selected_booking_room_id) {
        setTransferBookingRoomId(String(res.selected_booking_room_id));
      }
      if ((res.available_rooms || []).length > 0) {
        setTransferTargetRoomId(String(res.available_rooms[0].id));
      } else {
        setTransferTargetRoomId('');
      }
    } catch (err) {
      showToast(err?.response?.data?.message || 'Failed to load available rooms', 'error');
    } finally {
      setLoadingTransferRooms(false);
    }
  };

  const submitTransferRoom = async () => {
    if (!selected?.id || !transferTargetRoomId) {
      showToast('Please select a target room first.', 'warning');
      return;
    }
    if ((bookingRoomLines?.length || 0) > 1 && !transferBookingRoomId) {
      showToast('Please select which room line to transfer.', 'warning');
      return;
    }
    if (!transferReason.trim()) {
      showToast('Transfer reason is required.', 'warning');
      return;
    }

    try {
      setTransferSubmitting(true);
      const res = await transferRequestService.createRequest({
        booking_id: selected.id,
        booking_room_id: transferBookingRoomId ? Number(transferBookingRoomId) : null,
        target_room_id: Number(transferTargetRoomId),
        reason: transferReason.trim(),
      });
      showToast(res?.message || 'Room transfer request submitted.', 'success');
      closeModal();
      await fetchReservations();
    } catch (err) {
      showToast(err?.response?.data?.message || 'Failed to submit transfer request', 'error');
    } finally {
      setTransferSubmitting(false);
    }
  };

  const loadAssignableRooms = async (bookingRoomId = null, bookingId = null) => {
    const targetBookingId = bookingId || selected?.id;
    if (!targetBookingId) return;
    try {
      setLoadingAssignableRooms(true);
      const params = bookingRoomId ? { booking_room_id: Number(bookingRoomId) } : {};
      const response = await reservationService.getAssignableRooms(targetBookingId, params);
      const lines = Array.isArray(response.pending_lines) ? response.pending_lines : [];
      const selectedLineId = response.selected_booking_room_id ? String(response.selected_booking_room_id) : '';
      const rooms = Array.isArray(response.available_rooms) ? response.available_rooms : [];

      setPendingBookingLines(lines);
      setAssignBookingRoomId(selectedLineId);
      setAssignableRooms(rooms);
      setAssignTargetRoomId(rooms[0]?.id ? String(rooms[0].id) : '');
    } catch (err) {
      showToast(err?.response?.data?.message || 'Failed to load assignable rooms.', 'error');
    } finally {
      setLoadingAssignableRooms(false);
    }
  };

  useEffect(() => {
    if (canAssignRoomManually(selected)) {
      loadAssignableRooms(null, selected.id);
    }
  }, [selected?.id, selected?.roomAssignmentStatus, selected?.status]);

  const submitAssignRoom = async () => {
    if (!selected?.id) return;
    if (!assignTargetRoomId) {
      showToast('Please select a room to assign.', 'warning');
      return;
    }

    try {
      setAssigningRoom(true);
      const payload = {
        room_id: Number(assignTargetRoomId),
      };
      if (assignBookingRoomId) {
        payload.booking_room_id = Number(assignBookingRoomId);
      }
      const response = await reservationService.assignRoom(selected.id, payload);
      showToast(response?.message || 'Room assigned successfully.', 'success');
      await fetchReservations({ showSkeleton: false, showErrorToast: false });
      closeModal();
    } catch (err) {
      showToast(err?.response?.data?.message || 'Failed to assign room.', 'error');
    } finally {
      setAssigningRoom(false);
    }
  };

  if (loading) {
    return <PageSkeletonLoader title="Reservations" />;
  }

  if (error) {
    return (
      <div className="r-reservation-page">
        <div className="page-header">
          <div>
            <h1>Reservations</h1>
            <p className="page-subtitle">Browse and search guest reservations.</p>
          </div>
        </div>
        <div style={{ textAlign: 'center', padding: '3rem', color: 'red' }}>
          {error}
          <br />
          <button onClick={fetchReservations} style={{ marginTop: '1rem' }}>Retry</button>
        </div>
      </div>
    );
  }

  return (
    <div className="r-reservation-page">
      <div className="page-header">
        <div>
          <h1>Reservations</h1>
          <p className="page-subtitle">Browse and search guest reservations.</p>
        </div>
      </div>

      {/* Toolbar */}
      <div className="page-toolbar">
        <div className="search-wrap">
          <Search size={16} />
          <input
            type="text"
            placeholder="Search by guest, booking ID, or room..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>

        <div className="filter-wrap">
          <Filter size={16} />
          <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
            {STATUS_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
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
                <th>Booking ID</th>
                <th>Guest</th>
                <th>Room</th>
                <th>Check-in</th>
                <th>Check-out</th>
                <th>Total</th>
                <th>Balance</th>
                <th>Payment</th>
                <th>Status</th>
                <th>Source</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {paginatedData.length === 0 ? (
                <tr>
                  <td colSpan={11} className="empty-row">No reservations found.</td>
                </tr>
              ) : (
                paginatedData.map((r) => (
                  <tr key={r.id}>
                    <td className="booking-id">{r.id}</td>
                    <td className="guest-name">{r.guest}</td>
                    <td>
                      <div className="room-cell">
                        {r.room.split(', ').map((rm, i) => (
                          <span key={i} className="room-cell-item">{rm}</span>
                        ))}
                        {shouldShowRoomPendingBadge(r) && (
                          <StatusBadge status="room_pending" label="Room Pending" />
                        )}
                      </div>
                    </td>
                    <td>{r.checkIn}</td>
                    {/* Day use: display same date as check-in */}
                    <td>{(r.stayType === 'day_use' || r.isDayTour) ? r.checkIn : r.checkOut}</td>
                    <td className="amount-cell">{formatCurrency(Number(r.totalAmount || 0))}</td>
                    <td className="amount-cell">{formatCurrency(Number(r.remainingBalance || 0))}</td>
                    <td>
                      <StatusBadge status={r.paymentStatus || 'pending'} />
                    </td>
                    <td>
                      <StatusBadge status={r.status} />
                    </td>
                    <td>
                      <span className={`source-badge source-${(r.bookingSource || 'online').replace('_', '-')}`}>
                        {r.bookingSource === 'walk_in' ? 'Walk-In' : 'Online'}
                        {(r.stayType === 'day_use' || r.isDayTour) && <span className="day-tour-tag"> - Day Use</span>}
                      </span>
                    </td>
                    <td>
                      <div className="action-group">
                        <TableActionButton
                          iconOnly
                          label="View reservation details"
                          onClick={() => {
                            setSelected(r);
                            setTransferRooms([]);
                            const initialLineId = r?.bookingRooms?.[0]?.booking_room_id;
                            setTransferBookingRoomId(initialLineId ? String(initialLineId) : '');
                            setTransferTargetRoomId('');
                            setTransferReason('');
                            setAssignableRooms([]);
                            setPendingBookingLines([]);
                            setAssignBookingRoomId('');
                            setAssignTargetRoomId('');
                          }}
                        >
                          <Eye size={15} />
                        </TableActionButton>
                        {canAssignRoomManually(r) && (
                          <TableActionButton
                            label="Assign room"
                            onClick={async () => {
                              setSelected(r);
                              setAssignableRooms([]);
                              setPendingBookingLines([]);
                              setAssignBookingRoomId('');
                              setAssignTargetRoomId('');
                              await loadAssignableRooms(null, r.id);
                            }}
                          >
                            Assign Room
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

        {/* Pagination */}
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

      {/* Detail Modal */}
      {selected && (
        <div className="modal-overlay" onClick={closeModal}>
          <div className="modal-box" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <div>
                <h3>Reservation Details</h3>
                <span className="booking-id">{selected.id}</span>
              </div>
              <button className="modal-close" onClick={closeModal}>
                <X size={20} />
              </button>
            </div>

            <div className="modal-body">
              <div className="detail-grid">
                <div className="detail-section">
                  <div className="detail-section-title"><User size={15} /> Guest Info</div>
                  <div className="detail-row"><span>Name</span><strong>{selected.guest}</strong></div>
                  <div className="detail-row"><span>Phone</span><strong>{selected.phone}</strong></div>
                  <div className="detail-row"><span>Email</span><strong>{selected.email}</strong></div>
                </div>
                <div className="detail-section">
                  <div className="detail-section-title"><BedDouble size={15} /> Stay Details</div>
                  <div className="detail-row"><span>Room</span><strong>{selected.room}</strong></div>
                  <div className="detail-row">
                    <span>Check-in</span>
                    <strong>{selected.checkIn}</strong>
                  </div>
                  <div className="detail-row">
                    <span>Check-out</span>
                    <strong>{(selected.stayType === 'day_use' || selected.isDayTour) ? selected.checkIn : selected.checkOut}</strong>
                  </div>
                  <div className="detail-row">
                    <span>{(selected.stayType === 'day_use' || selected.isDayTour) ? 'Duration' : 'Nights'}</span>
                    <strong>{(selected.stayType === 'day_use' || selected.isDayTour) ? 'Day Use (12 hrs)' : selected.nights}</strong>
                  </div>
                  {/* Show the actual 12-hour time window for day tours */}
                  {(selected.stayType === 'day_use' || selected.isDayTour) && selected.dayTourStartTime && (
                    <div className="detail-row">
                      <span>Time Window</span>
                      <strong>{selected.dayTourStartTime} - {selected.dayTourEndTime}</strong>
                    </div>
                  )}
                  {selected.promoCode && (
                    <div className="detail-row">
                      <span>Promo Code</span>
                      <strong className="promo-code-inline">{selected.promoCode}</strong>
                    </div>
                  )}
                  {selected.discountAmount > 0 && (
                    <div className="detail-row">
                      <span>Discount</span>
                      <strong style={{ color: '#16a34a' }}>
                        -{formatCurrency(Number(selected.discountAmount || 0))}
                      </strong>
                    </div>
                  )}
                  <div className="detail-row"><span>Total</span><strong className="amount-highlight">{formatCurrency(Number(selected.totalAmount || 0))}</strong></div>
                  <div className="detail-row"><span>Total Paid</span><strong>{formatCurrency(Number(selected.totalPaid || 0))}</strong></div>
                  <div className="detail-row"><span>Remaining Balance</span><strong className="amount-highlight">{formatCurrency(Number(selected.remainingBalance || 0))}</strong></div>
                </div>
              </div>

              <div className="detail-status-row">
                <div>
                  <span>Booking Status</span>
                  <StatusBadge status={selected.status} />
                </div>
                <div>
                  <span>Payment Status</span>
                  <StatusBadge status={selected.paymentStatus || 'pending'} />
                </div>
                <div>
                  <span>Booking Source</span>
                  <span className={`source-badge source-${(selected.bookingSource || 'online').replace('_', '-')}`}>
                    {selected.bookingSource === 'walk_in' ? 'Walk-In' : 'Online'}
                    {(selected.stayType === 'day_use' || selected.isDayTour) && <span className="day-tour-tag"> - Day Use</span>}
                  </span>
                </div>
              </div>

              {selectedAddonItems.length > 0 && (
                <div className="detail-section reservation-addons-section">
                  <div className="detail-section-title"><BedDouble size={15} /> Add-Ons</div>
                  <div className="reservation-addon-list">
                    {selectedAddonItems.map((addon) => (
                      <div key={addon.key} className="reservation-addon-item">
                        <div className="reservation-addon-main">
                          <div className="reservation-addon-name">{addon.name}</div>
                          <div className="reservation-addon-room">{addon.roomLabel}</div>
                          {addon.description && <div className="reservation-addon-desc">{addon.description}</div>}
                        </div>
                        <div className="reservation-addon-pricing">
                          <div>Qty {addon.quantity} x {formatCurrency(addon.unitPrice)}</div>
                          <strong>{formatCurrency(addon.lineTotal)}</strong>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {canAssignRoomManually(selected) && (
                <div className="transfer-room-section">
                  <div className="transfer-room-head">
                    <strong>Room Assignment</strong>
                    <button
                      className="modal-btn btn-info"
                      type="button"
                      onClick={() => loadAssignableRooms(assignBookingRoomId || null)}
                      disabled={loadingAssignableRooms}
                    >
                      {loadingAssignableRooms ? 'Loading...' : 'Refresh Available Rooms'}
                    </button>
                  </div>

                  {pendingBookingLines.length > 1 && (
                    <div className="transfer-room-form">
                      <label>Pending Room Line</label>
                      <select
                        value={assignBookingRoomId}
                        onChange={async (e) => {
                          const nextId = e.target.value;
                          setAssignBookingRoomId(nextId);
                          await loadAssignableRooms(nextId || null);
                        }}
                      >
                        {pendingBookingLines.map((line) => (
                          <option key={line.booking_room_id} value={line.booking_room_id}>
                            {formatRoomTypeLabel(line.requested_room_type || 'Room Type')}
                          </option>
                        ))}
                      </select>
                    </div>
                  )}

                  {assignableRooms.length > 0 ? (
                    <div className="transfer-room-form">
                      <label>Available Rooms</label>
                      <select
                        value={assignTargetRoomId}
                        onChange={(e) => setAssignTargetRoomId(e.target.value)}
                      >
                        {assignableRooms.map((room) => (
                          <option key={room.id} value={room.id}>
                            {formatRoomTypeLabel(room.room_type)} {room.room_number} - floor {room.floor}
                          </option>
                        ))}
                      </select>
                      <button
                        className="modal-btn btn-success"
                        type="button"
                        onClick={submitAssignRoom}
                        disabled={assigningRoom}
                      >
                        {assigningRoom ? 'Assigning...' : 'Assign Room'}
                      </button>
                    </div>
                  ) : (
                    !loadingAssignableRooms && (
                      <div className="transfer-room-note">
                        No available rooms for this pending assignment yet.
                      </div>
                    )
                  )}
                </div>
              )}

              {isCheckedIn && (
                <div className="transfer-room-section">
                  <div className="transfer-room-head">
                    <strong>Room Transfer</strong>
                    {canTransfer ? (
                      <button
                        className="modal-btn btn-info"
                        type="button"
                        onClick={loadTransferRooms}
                        disabled={loadingTransferRooms}
                      >
                        {loadingTransferRooms ? 'Loading Rooms...' : 'Load Available Rooms'}
                      </button>
                    ) : (
                      <span className="transfer-room-note">
                        Room transfer is unavailable because no active room line was found.
                      </span>
                    )}
                  </div>

                  {canTransfer && bookingRoomLines.length > 1 && (
                    <div className="transfer-room-form">
                      <label>Room Line to Transfer</label>
                      <select
                        value={transferBookingRoomId}
                        onChange={(e) => {
                          setTransferBookingRoomId(e.target.value);
                          setTransferRooms([]);
                          setTransferTargetRoomId('');
                        }}
                      >
                        {bookingRoomLines.map((line) => (
                          <option key={line.booking_room_id} value={line.booking_room_id}>
                            {line.room?.room_type} {line.room?.room_number}
                          </option>
                        ))}
                      </select>
                    </div>
                  )}

                  {canTransfer && transferRooms.length > 0 && (
                    <div className="transfer-room-form">
                      <label>Target Room</label>
                      <select
                        value={transferTargetRoomId}
                        onChange={(e) => setTransferTargetRoomId(e.target.value)}
                      >
                        {transferRooms.map((room) => (
                          <option key={room.id} value={room.id}>
                            {room.room_type} {room.room_number} - floor {room.floor} - {formatCurrency(Number(room.price_per_night || 0))}
                          </option>
                        ))}
                      </select>

                      <label>Reason *</label>
                      <input
                        type="text"
                        value={transferReason}
                        onChange={(e) => setTransferReason(e.target.value)}
                        placeholder="Required: guest preference, maintenance issue, etc."
                      />

                      <button
                        className="modal-btn btn-success"
                        type="button"
                        onClick={submitTransferRoom}
                        disabled={transferSubmitting}
                      >
                        {transferSubmitting ? 'Submitting...' : 'Submit Transfer Request'}
                      </button>
                    </div>
                  )}
                </div>
              )}
            </div>

            <div className="modal-footer">
              <button className="modal-btn btn-ghost" onClick={closeModal}>Close</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default ReservationPage;


