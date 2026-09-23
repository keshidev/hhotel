import React, { useState, useEffect, useCallback, useRef } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { 
  ArrowLeft, Calendar, MapPin, Users, Mail, Phone,
  RefreshCw, CheckCircle, Clock, AlertCircle,
  XCircle, ChevronDown, ChevronUp, MoreVertical, Tag, Star, FileText, LogOut,
} from 'lucide-react';
import Button from '../components/Button';
import { showToast } from '../utils/showToast';
import clientBookingService from '../services/client/clientBookingService';
import { formatCurrency } from '../utils/currency';
import { useCms } from '../context/CmsContext';
import {
  clearGuestBookingSession,
  readGuestBookingSession,
  rememberGuestBookingSession,
} from '../utils/guestBookingSession';
import './BookingDetails.css';

const BOOKING_CACHE_KEY = 'booking_last_success';

const BookingDetails = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const { taxRate } = useCms();
  const [booking, setBooking] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [refreshing, setRefreshing] = useState(false);
  const [autoRefresh, setAutoRefresh] = useState(true);
  const [showActionsMenu, setShowActionsMenu] = useState(false);
  const [resumingPayment, setResumingPayment] = useState(false);
  const actionsMenuRef = useRef(null);
  
  const [accordions, setAccordions] = useState({
    stayDetails: true,
    roomDetails: true,
    guestInfo: true,
    paymentInfo: true
  });

  const [feedbackData,   setFeedbackData]   = useState(null);
  const [feedbackLoaded, setFeedbackLoaded] = useState(false);

  const toggleAccordion = (section) => {
    setAccordions(prev => ({ ...prev, [section]: !prev[section] }));
  };

  const fetchBookingDetails = useCallback(async (showRefreshIndicator = false) => {
    try {
      if (showRefreshIndicator) {
        setRefreshing(true);
      } else {
        setLoading(true);
      }
      setError(null);

      const storedLookup = readGuestBookingSession();
      const email = location.state?.email || storedLookup.email;
      const referenceNumber = location.state?.reservationId || storedLookup.referenceNumber;

      if (!email || !referenceNumber) {
        navigate('/my-booking');
        return;
      }

      const response = await clientBookingService.checkBookingStatus(email, referenceNumber);
      
      if (response.success) {
        setBooking(response.data);
        rememberGuestBookingSession({ email, referenceNumber, bookingId: response.data?.id });

        if (response.data?.booking_status === 'checked_out') {
          setFeedbackData(response.data.feedback || null);
          setFeedbackLoaded(true);
        }
        sessionStorage.setItem(BOOKING_CACHE_KEY, JSON.stringify({
          email: String(email).toLowerCase(),
          referenceNumber: String(referenceNumber).toUpperCase(),
          data: response.data,
          cachedAt: Date.now(),
        }));
      } else {
        setBooking(null);
        setError(response.message || 'Booking not found');
      }
    } catch (err) {
      console.error('Error fetching booking:', err);
      const status = err.response?.status;
      const message = err.response?.data?.message || 'Failed to load booking details';

      if (status === 429) {
        setAutoRefresh(false);
        try {
          const storedLookup = readGuestBookingSession();
          const email = location.state?.email || storedLookup.email;
          const referenceNumber = location.state?.reservationId || storedLookup.referenceNumber;
          const cachedRaw = sessionStorage.getItem(BOOKING_CACHE_KEY);
          if (cachedRaw && email && referenceNumber) {
            const cached = JSON.parse(cachedRaw);
            const emailMatch = cached?.email === String(email).toLowerCase();
            const refMatch = cached?.referenceNumber === String(referenceNumber).toUpperCase();
            if (emailMatch && refMatch && cached?.data) {
              setBooking(cached.data);
              setError(null);
              showToast('Too many refresh attempts. Showing latest saved booking. Please wait about 1 minute.', 'warning');
              return;
            }
          }
        } catch { /* ignore */ }
        setError('Too many attempts. Please wait about 1 minute, then try again.');
        return;
      }

      if (status === 404) {
        setBooking(null);
        setError('Booking not found. Please check your reference number and email.');
        return;
      }

      setError(message);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [location.state, navigate]);

  useEffect(() => { fetchBookingDetails(); }, [fetchBookingDetails]);

  useEffect(() => {
      if (!autoRefresh) return;
      const terminalStatuses = ['checked_out', 'cancelled'];
      if (terminalStatuses.includes(booking?.booking_status)) {
          setAutoRefresh(false);
          return;
      }
      const interval = setInterval(() => fetchBookingDetails(true), 60000);
      return () => clearInterval(interval);
  }, [autoRefresh, fetchBookingDetails, booking?.booking_status]);
  
  useEffect(() => {
    const handleClickOutside = (event) => {
      if (actionsMenuRef.current && !actionsMenuRef.current.contains(event.target)) {
        setShowActionsMenu(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);
  const deriveBedTypeLabel = (roomType) => {
    const map = {
      superior_twin: 'Twin Beds',
      superior_queen: 'Queen Bed',
      family: 'Family Bed Setup',
      executive_suite: 'King Bed',
      premier: 'King Bed',
      deluxe: 'King Bed',
    };
    return map[String(roomType || '').toLowerCase()] || 'Standard Bed';
  };

  const normalizeRoomAddons = (bookingRoom) => {
    const directAddons = Array.isArray(bookingRoom?.addons)
      ? bookingRoom.addons
      : Array.isArray(bookingRoom?.room_addons)
        ? bookingRoom.room_addons
        : [];

    if (directAddons.length > 0) {
      return directAddons;
    }

    const breakdown = booking?.addons_breakdown && typeof booking.addons_breakdown === 'object'
      ? booking.addons_breakdown
      : {};

    const keys = [
      bookingRoom?.booking_room_id,
      bookingRoom?.id,
      bookingRoom?.room_id,
      bookingRoom?.room?.id,
    ]
      .filter(v => v !== null && v !== undefined)
      .map(v => String(v));

    for (const key of keys) {
      if (Array.isArray(breakdown[key])) {
        return breakdown[key];
      }
    }

    return [];
  };

  const getAddonQuantity = (addon) => Math.max(1, parseInt(addon?.quantity ?? 1, 10) || 1);
  const getAddonUnitPrice = (addon) => parseFloat(addon?.price || 0);
  const getAddonLineTotal = (addon) => {
    const explicitLineTotal = parseFloat(addon?.line_total);
    if (Number.isFinite(explicitLineTotal)) {
      return explicitLineTotal;
    }
    return getAddonUnitPrice(addon) * getAddonQuantity(addon);
  };
  const storedLookup = readGuestBookingSession();
  const requestEmail = location.state?.email || storedLookup.email;
  const requestRef   = booking?.reference_number || location.state?.reservationId || storedLookup.referenceNumber;

  const clearBookingFromTab = () => {
    setShowActionsMenu(false);
    clearGuestBookingSession();
    navigate('/my-booking', { replace: true });
  };
  const canShowGuestActions    = ['pending','confirmed'].includes(booking?.booking_status);
  const canRequestCancellation = canShowGuestActions && !booking?.cancellation_attempted;
  const rebookingDeadline = booking?.created_at
    ? new Date(new Date(booking.created_at).getTime() + (24 * 60 * 60 * 1000))
    : null;
  const isRebookingWindowExpired = rebookingDeadline instanceof Date
    && !Number.isNaN(rebookingDeadline.getTime())
    && Date.now() > rebookingDeadline.getTime();
  const canRequestRebookingBase = booking?.booking_status === 'confirmed'
    && !booking?.rebooking_attempted
    && !booking?.has_been_rebooked
    && !booking?.lifecycle_locked_for_cancellation;
  const canRequestRebooking = canRequestRebookingBase && !isRebookingWindowExpired;
  const canShowActionsMenu     = canRequestCancellation || canRequestRebookingBase;
  const canResubmitPayment = Boolean(booking?.payment_resubmission?.available);

  const handlePaymentResubmission = async () => {
    if (!requestEmail || !requestRef || !booking?.id) {
      showToast('Search for this booking again before correcting the payment proof.', 'error');
      return;
    }

    setResumingPayment(true);
    try {
      const response = await clientBookingService.resumeManualGcashPayment(
        booking.id,
      );
      navigate(`/payment/${response.booking_id || booking.id}`);
    } catch (requestError) {
      showToast(
        requestError.response?.data?.message || 'Payment proof resubmission is unavailable.',
        'error',
      );
      await fetchBookingDetails(true);
    } finally {
      setResumingPayment(false);
    }
  };

  const handleActionNavigation = (path) => {
    setShowActionsMenu(false);
    navigate(path, { state: { email: requestEmail, reservationId: requestRef } });
  };

  const formatDate = (value) => {
    if (!value) return 'N/A';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'N/A';
    return date.toLocaleDateString('en-US', {
      month: 'long',
      day: 'numeric',
      year: 'numeric',
    });
  };

  const formatDateTime = (value) => {
    if (!value) return 'N/A';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'N/A';
    return date.toLocaleString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  };

  const calculateDiscount = () => parseFloat(booking?.discount_amount || 0);
  const calculateTotal = () => parseFloat(booking?.total_amount || 0);

  const getStatusBadge = (status) => {
    const normalizedStatus = String(status || 'pending').toLowerCase();

    const statusMeta = {
      pending: { label: 'Pending', className: 'status-badge-pending', icon: Clock },
      confirmed: { label: 'Confirmed', className: 'status-badge-confirmed', icon: CheckCircle },
      checked_in: { label: 'Checked In', className: 'status-badge-checked-in', icon: CheckCircle },
      checked_out: { label: 'Checked Out', className: 'status-badge-completed', icon: CheckCircle },
      cancelled: { label: 'Cancelled', className: 'status-badge-cancelled', icon: XCircle },
    }[normalizedStatus] || { label: 'Pending', className: 'status-badge-pending', icon: Clock };

    const StatusIcon = statusMeta.icon;

    return (
      <span className={`status-badge ${statusMeta.className}`}>
        <StatusIcon size={14} />
        {statusMeta.label}
      </span>
    );
  };

  const getPaymentStatusBadge = (payment) => {
    const normalizedStatus = String(
      payment?.lifecycle_status || payment?.payment_status || 'pending'
    ).toLowerCase();
    const statusMeta = {
      pending: { label: 'Pending', className: 'payment-status-pending', icon: Clock },
      authorized: { label: 'Authorized', className: 'payment-status-pending', icon: Clock },
      capture_pending: { label: 'Capture Pending', className: 'payment-status-pending', icon: Clock },
      capture_unknown: { label: 'Capture Status Unknown', className: 'payment-status-pending', icon: AlertCircle },
      paid: { label: 'Completed', className: 'payment-status-completed', icon: CheckCircle },
      completed: { label: 'Completed', className: 'payment-status-completed', icon: CheckCircle },
      paid_under_review: { label: 'Paid - Under Review', className: 'payment-status-pending', icon: AlertCircle },
      refund_required: { label: 'Paid - Refund Required', className: 'payment-status-pending', icon: AlertCircle },
      assignment_failed: { label: 'Paid - Assignment Required', className: 'payment-status-pending', icon: AlertCircle },
      rejected: { label: 'Needs Correction', className: 'payment-status-failed', icon: AlertCircle },
      failed: { label: 'Failed', className: 'payment-status-failed', icon: XCircle },
      refunded: { label: 'Refunded', className: 'payment-status-completed', icon: CheckCircle },
    }[normalizedStatus] || { label: 'Pending', className: 'payment-status-pending', icon: Clock };
    const StatusIcon = statusMeta.icon;

    return (
      <span className={`payment-status-badge ${statusMeta.className}`}>
        <StatusIcon size={13} />
        {statusMeta.label}
      </span>
    );
  };

  const getPaymentMethodLabel = (payment) => {
    if (!payment) return 'N/A';
    const method = String(payment.payment_method || '').trim();
    return method ? method.toUpperCase() : 'N/A';
  };

  const getPaymentAlertBody = (payment) => {
    const status = String(payment?.lifecycle_status || payment?.payment_status || 'pending').toLowerCase();

    if (['paid', 'completed'].includes(status)) {
      return (
        <p>
          Payment has been verified. Amount paid: {formatCurrency(parseFloat(payment?.paid_amount || payment?.amount || 0))}.
        </p>
      );
    }

    if (status === 'failed') {
      return (
        <p>
          Payment was not completed. Please contact the front desk if this was deducted from your account.
        </p>
      );
    }

    if (status === 'rejected') {
      return (
        <p>
          The previous payment proof was rejected. Use <strong>Correct Payment Proof</strong> before the payment deadline. Do not pay again.
        </p>
      );
    }

    if (status === 'capture_unknown') {
      return <p>Payment confirmation is delayed. Do not pay again while the hotel verifies the provider result.</p>;
    }

    if (status === 'paid_under_review') {
      return <p>Payment was received, but the booking requires staff review before confirmation.</p>;
    }

    if (status === 'refund_required') {
      return <p>Payment was received and requires refund review by hotel staff.</p>;
    }

    if (status === 'assignment_failed') {
      return <p>Payment was received, but room assignment requires assistance from hotel staff.</p>;
    }

    return (
      <p>
        Payment is pending verification. Please keep your payment reference for review.
      </p>
    );
  };

  // ---- Loading / error states --------------------------------------------------
  if (loading && !booking) {
    return (
      <div className="booking-details-page">
        <div className="loading-container">
          <div className="loading-spinner"></div>
          <p>Loading your booking...</p>
        </div>
      </div>
    );
  }

  if (error && !booking) {
    const isRateLimited = /too many attempts|too many requests/i.test(error);
    return (
      <div className="booking-details-page">
        <div className="error-container">
          <h2>{isRateLimited ? 'Please Wait' : 'Booking Not Found'}</h2>
          <p>{error}</p>
          <Button onClick={() => navigate('/my-booking')}>Try Again</Button>
        </div>
      </div>
    );
  }

  if (!booking) {
    return (
      <div className="booking-details-page">
        <div className="error-container">
          <h2>No Booking Data</h2>
          <p>Please search for your booking first.</p>
          <Button onClick={() => navigate('/my-booking')}>Search Booking</Button>
        </div>
      </div>
    );
  }

  // ---- Derived data ------------------------------------------------------------
  const primaryGuest  = booking.primary_guest || {};
  const rooms         = booking.bookingRooms || [];
  const roomAssignmentPending = String(booking.room_assignment_status || 'pending_assignment') !== 'assigned';
  const roomSubtotal = rooms.reduce((sum, room) => sum + parseFloat(room?.subtotal || 0), 0);
  const addonLineItems = rooms.flatMap((bookingRoom, roomIndex) => {
    const room = bookingRoom?.room || {};
    const roomTypeRaw = room?.room_type || bookingRoom?.requested_room_type;
    const roomTypeLabel = roomTypeRaw
      ? String(roomTypeRaw).replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
      : 'Room';
    const roomLabel = (!roomAssignmentPending && room?.room_number)
      ? `${roomTypeLabel} ${room.room_number}`
      : roomTypeLabel;

    return normalizeRoomAddons(bookingRoom).map((addon, addonIndex) => ({
      key: `${roomIndex}-${addonIndex}-${addon?.id || addon?.name || 'addon'}`,
      name: addon?.name || 'Add-on',
      description: addon?.description || null,
      quantity: getAddonQuantity(addon),
      unitPrice: getAddonUnitPrice(addon),
      lineTotal: getAddonLineTotal(addon),
      roomLabel,
    }));
  });
  const addonsSubtotal = addonLineItems.reduce((sum, addon) => sum + addon.lineTotal, 0);
  const payments      = booking.payments || [];
  const latestPayment = payments[0] || {};
  const discount      = calculateDiscount();
  const total         = calculateTotal();
  const storedTaxAmount = parseFloat(booking.tax_amount || 0);
  const hasTaxSnapshot = booking.tax_rate !== null && booking.tax_rate !== undefined;
  const bookingTaxRate = hasTaxSnapshot ? Number(booking.tax_rate) : taxRate;
  const subtotalBeforeDiscount = roomSubtotal + addonsSubtotal;
  const taxableSubtotal = Math.max(0, subtotalBeforeDiscount - discount);
  const extractedTaxAmount = taxableSubtotal > 0 && bookingTaxRate > 0
    ? taxableSubtotal - (taxableSubtotal / (1 + bookingTaxRate))
    : 0;
  const taxAmount = hasTaxSnapshot ? storedTaxAmount : extractedTaxAmount;
  const subtotal = subtotalBeforeDiscount;
  const totalPaid     = parseFloat(booking.total_paid || 0);
  const remaining     = parseFloat(booking.remaining_balance || 0);
  const latestRebooking = booking.latest_rebooking || booking.latestRebooking || null;
  const cancellationRequestStatus = booking.cancellation_request_status || null;
  const rebookingRequestStatus = booking.rebooking_request_status || null;
  const hasApprovedRoomChange = latestRebooking?.status === 'approved'
    && (latestRebooking?.from_room || latestRebooking?.to_room);
  const cancellationStatusLabel = {
    pending_approval: 'Pending Approval',
    approved: 'Approved',
    refund_pending: 'Refund Pending',
    refunded: 'Refunded',
  }[cancellationRequestStatus] || cancellationRequestStatus;
  const rebookingStatusLabel = {
    pending: 'Pending Approval',
    under_review: 'Under Review',
    approved: 'Approved',
    awaiting_payment: 'Awaiting Payment',
  }[rebookingRequestStatus] || rebookingRequestStatus;

  const houseRules = [
    'Check-in starts after 3:00 PM and check-out is before 12:00 PM.',
    'Down payment is required to confirm the reservation.',
    'Cancellation is non-refundable when requested within 24 hours of check-in time.',
    'A room change is allowed once, after payment is verified, and within 24 hours of the original booking.',
    'Please present a valid government-issued ID during check-in.',
  ];

  // ---- Render ------------------------------------------------------------------
  return (
    <div className="booking-details-page">
      <section className="details-header">
        <div className="container">
          <button className="back-button" onClick={() => navigate('/my-booking')}>
            <ArrowLeft size={20} /><span>Back to Search</span>
          </button>

          <div className="header-content">
            <div className="header-left">
              <h1 className="confirmation-title">Booking Details</h1>
              <div className="reservation-id">
                <span className="label">Reference Number:</span>
                <span className="value">{booking.reference_number}</span>
              </div>
              {getStatusBadge(booking.booking_status)}
            </div>
            <div className="header-actions">
              {canResubmitPayment && (
                <button className="action-btn action-btn-payment" onClick={handlePaymentResubmission} disabled={resumingPayment}>
                  <AlertCircle size={18} /> {resumingPayment ? 'Opening...' : 'Correct Payment Proof'}
                </button>
              )}
              <div className="actions-menu-wrap" ref={actionsMenuRef}>
                <button
                  className="action-btn action-btn-menu"
                  onClick={() => setShowActionsMenu(p => !p)}
                  aria-expanded={showActionsMenu}
                  aria-haspopup="menu"
                >
                  <MoreVertical size={18}/>
                  <span>Manage Booking</span>
                  <ChevronDown size={16} className={showActionsMenu ? 'menu-chevron-open' : ''}/>
                </button>
                {showActionsMenu && (
                  <div className="actions-menu-dropdown" role="menu">
                    <button
                      className="actions-menu-item"
                      onClick={() => {
                        setShowActionsMenu(false);
                        fetchBookingDetails(true);
                      }}
                      disabled={refreshing}
                      role="menuitem"
                    >
                      <RefreshCw size={17} className={refreshing ? 'spinning' : ''}/>
                      <span>{refreshing ? 'Refreshing...' : 'Refresh Details'}</span>
                    </button>
                    <button
                      className="actions-menu-item"
                      onClick={() => {
                        setShowActionsMenu(false);
                        navigate('/confirmation', {
                          state: {
                            reservationId: booking.reference_number,
                            email: requestEmail,
                          },
                        });
                      }}
                      role="menuitem"
                    >
                      <FileText size={17}/>
                      <span>View Confirmation</span>
                    </button>
                    {canShowActionsMenu && <div className="actions-menu-divider"/>}
                    {canRequestRebookingBase && (
                      <button
                        className={`actions-menu-item ${!canRequestRebooking ? 'disabled' : ''}`}
                        onClick={() => canRequestRebooking && handleActionNavigation('/booking-details/rebooking')}
                        disabled={!canRequestRebooking}
                        title={!canRequestRebooking ? 'The 24-hour room change window for this booking has expired.' : undefined}
                        role="menuitem"
                      >
                        <Calendar size={17}/>
                        <span>Request Room Change</span>
                      </button>
                    )}
                    {canRequestCancellation && (
                      <button className="actions-menu-item danger" onClick={() => handleActionNavigation('/booking-details/cancellation')} role="menuitem">
                        <XCircle size={17}/>
                        <span>Cancel Booking</span>
                      </button>
                    )}
                    <div className="actions-menu-divider"/>
                    <button className="actions-menu-item clear-session" onClick={clearBookingFromTab} role="menuitem">
                      <LogOut size={17}/>
                      <span className="actions-menu-copy">
                        <strong>Sign Out of This Booking</strong>
                        <small>Does not cancel it; email and reference are needed next time</small>
                      </span>
                    </button>
                  </div>
                )}
              </div>
            </div>
          </div>

          <div className="auto-refresh-toggle">
            <label>
              <input type="checkbox" checked={autoRefresh} onChange={e => setAutoRefresh(e.target.checked)}/>
              <span>Auto-refresh every minute</span>
            </label>
            {refreshing && <span className="refresh-indicator">Refreshing...</span>}
          </div>
        </div>
      </section>

      <section className="details-content">
        <div className="container">
          <div className="content-grid">

            {/* LEFT COLUMN */}
            <div className="left-column">

              {booking.booking_status === 'cancelled' && ['refund_pending', 'refunded'].includes(booking.cancellation_refund_status) && (
                <div className="alert alert-room-change">
                  <div className="alert-header"><h3>Reservation Cancelled</h3></div>
                  <div className="alert-body">
                    <p>{booking.cancellation_refund_status === 'refund_pending' ? 'Refund Pending' : 'Refund Completed'}: PHP {Number(booking.cancellation_refund_amount || 0).toFixed(2)}</p>
                    <p>{booking.cancellation_refund_status === 'refund_pending' ? 'Your reservation is cancelled. Hotel staff will notify you when your refund is recorded as completed.' : 'Your refund has been recorded as completed.'}</p>
                  </div>
                </div>
              )}
              {cancellationRequestStatus && booking.booking_status !== 'cancelled' && (
                <div className="alert alert-room-change">
                  <div className="alert-header">
                    <h3>Cancellation Request</h3>
                  </div>
                  <div className="alert-body">
                    <p>Status: {cancellationStatusLabel}</p>
                    <p>
                      A cancellation request is in progress. Booking lifecycle actions are temporarily locked until this request is resolved.
                    </p>
                  </div>
                </div>
              )}

              {rebookingRequestStatus && (
                <div className="alert alert-room-change">
                  <div className="alert-header">
                    <h3>Room Change Request</h3>
                  </div>
                  <div className="alert-body">
                    <p>Status: {rebookingStatusLabel}</p>
                    <p>An open room change request already exists for this booking. Please wait for staff to resolve it.</p>
                  </div>
                </div>
              )}

              {canRequestRebookingBase && (
                <div className="alert alert-room-change">
                  <div className="alert-header">
                    <h3>Room Change Policy</h3>
                  </div>
                  <div className="alert-body">
                    <p>A room change is allowed once, after payment is verified, and within 24 hours of the original booking.</p>
                    {isRebookingWindowExpired && (
                      <p className="rebooking-window-expired">The 24-hour room change window for this booking has expired.</p>
                    )}
                  </div>
                </div>
              )}

              {/* Payment Alert */}
              {latestPayment && (
                <div className={`alert alert-${latestPayment.payment_status}`}>
                  <div className="alert-header">
                    <h3>Payment Status</h3>
                    {getPaymentStatusBadge(latestPayment)}
                  </div>
                  <div className="alert-body">{getPaymentAlertBody(latestPayment)}</div>
                </div>
              )}

              {hasApprovedRoomChange && (
                <div className="alert alert-room-change">
                  <div className="alert-header">
                    <h3>Room Change Approved</h3>
                  </div>
                  <div className="alert-body">
                    {latestRebooking.from_room && latestRebooking.to_room && (
                      <p>{`${latestRebooking.from_room} -> ${latestRebooking.to_room}`}</p>
                    )}
                    {!latestRebooking.from_room && latestRebooking.to_room && (
                      <p>Updated room assignment: {latestRebooking.to_room}</p>
                    )}
                    {latestRebooking.approved_at && (
                      <p>Approved on {formatDateTime(latestRebooking.approved_at)}</p>
                    )}
                    {latestRebooking.note && (
                      <p className="rejection-reason">Front desk note: {latestRebooking.note}</p>
                    )}
                  </div>
                </div>
              )}

              {/* Stay Details */}
              <div className="details-card accordion-card">
                <div className="accordion-header" onClick={() => toggleAccordion('stayDetails')}>
                  <h2 className="card-heading">Stay Details</h2>
                  {accordions.stayDetails ? <ChevronUp size={20}/> : <ChevronDown size={20}/>}
                </div>
                {accordions.stayDetails && (
                  <div className="accordion-content">
                    <div className="stay-info">
                      <div className="info-row">
                        <Calendar size={20}/>
                        <div className="info-content">
                          <span className="info-label">Check-in</span>
                          <span className="info-value">{formatDate(booking.check_in)}</span>
                          <span className="info-note">After 3:00 PM</span>
                        </div>
                      </div>
                      <div className="info-row">
                        <Calendar size={20}/>
                        <div className="info-content">
                          <span className="info-label">Check-out</span>
                          <span className="info-value">{formatDate(booking.check_out)}</span>
                          <span className="info-note">Before 12:00 PM</span>
                        </div>
                      </div>
                      <div className="info-row">
                        <MapPin size={20}/>
                        <div className="info-content">
                          <span className="info-label">Duration</span>
                          <span className="info-value">
                            {rooms[0]?.nights || Math.ceil((new Date(booking.check_out)-new Date(booking.check_in))/(1000*60*60*24))} Night(s)
                          </span>
                        </div>
                      </div>
                      <div className="info-row">
                        <Users size={20}/>
                        <div className="info-content">
                          <span className="info-label">Guests</span>
                          <span className="info-value">{booking.number_of_guests} Guest(s)</span>
                        </div>
                      </div>
                    </div>
                  </div>
                )}
              </div>

              {/* Room Details */}
              <div className="details-card accordion-card">
                <div className="accordion-header" onClick={() => toggleAccordion('roomDetails')}>
                  <h2 className="card-heading">Room Details</h2>
                  {accordions.roomDetails ? <ChevronUp size={20}/> : <ChevronDown size={20}/>}
                </div>
                {accordions.roomDetails && (
                  <div className="accordion-content">
                    {rooms.map((bookingRoom, index) => {
                      const room          = bookingRoom.room;
                      const roomTypeRaw   = room?.room_type || bookingRoom?.requested_room_type || null;
                      const pricePerNight = parseFloat(bookingRoom.price_per_night || 0);
                      const checkInDate = booking?.check_in ? new Date(booking.check_in) : null;
                      const storedNights = Number(bookingRoom?.nights ?? 0);
                      const derivedCheckoutFromStoredNights = (() => {
                        if (!checkInDate || Number.isNaN(checkInDate.getTime()) || !Number.isFinite(storedNights) || storedNights <= 0) {
                          return null;
                        }
                        const next = new Date(checkInDate);
                        next.setDate(next.getDate() + Math.round(storedNights));
                        return next;
                      })();
                      const fallbackBookingCheckout = booking?.check_out ? new Date(booking.check_out) : null;
                      const extendedCheckoutDate = bookingRoom?.extended_checkout ? new Date(bookingRoom.extended_checkout) : null;
                      const effectiveCheckOutDate = (
                        extendedCheckoutDate && !Number.isNaN(extendedCheckoutDate.getTime())
                          ? extendedCheckoutDate
                          : (derivedCheckoutFromStoredNights || fallbackBookingCheckout)
                      );
                      const computedNights = (
                        checkInDate && effectiveCheckOutDate
                        && !Number.isNaN(checkInDate.getTime())
                        && !Number.isNaN(effectiveCheckOutDate.getTime())
                      )
                        ? Math.round((effectiveCheckOutDate - checkInDate) / 86400000)
                        : 0;
                      const nightCount = Math.max(1, computedNights || (Number.isFinite(storedNights) ? Math.round(storedNights) : 0));
                      const computedLineSubtotal = pricePerNight * nightCount;
                      const roomLineSubtotal = bookingRoom?.extended_checkout
                        ? computedLineSubtotal
                        : parseFloat(bookingRoom.subtotal || computedLineSubtotal || 0);
                      const imageUrl      = bookingRoom.image_urls?.[0] || room?.image_urls?.[0] || null;
                      const roomTypeName  = roomTypeRaw
                        ? String(roomTypeRaw).replace(/_/g,' ').replace(/\b\w/g, c => c.toUpperCase())
                        : 'Room';
                      const roomAddons = normalizeRoomAddons(bookingRoom);
                      const roomAmenities = Array.isArray(room?.amenities) ? room.amenities : [];
                      const bedType = room?.bed_type || deriveBedTypeLabel(roomTypeRaw);

                      return (
                        <div key={bookingRoom.id || index} className="bd-room-item">
                          <div className="bd-room-card">
                            {/* Thumbnail */}
                            <div className={`bd-room-image ${!imageUrl ? 'bd-room-image-placeholder' : ''}`}>
                              {imageUrl
                                ? <img src={imageUrl} alt={roomTypeName}/>
                                : <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#ccc" strokeWidth="1.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                              }
                            </div>

                            {/* Info */}
                            <div className="bd-room-info">
                              <div className="bd-room-header-row">
                                <div>
                                  <h3 className="bd-room-name">{roomTypeName}</h3>
                                  {roomAssignmentPending ? (
                                    <p className="bd-room-number">Room number will be assigned after payment is verified.</p>
                                  ) : (
                                    <>
                                      <p className="bd-room-number">Room {room?.room_number}</p>
                                      <p className="bd-room-number">Floor {room?.floor ?? 'N/A'} - {bedType}</p>
                                    </>
                                  )}
                                </div>
                                <div className="bd-room-price-block">
                                  <span className="bd-room-subtotal">
                                    {formatCurrency(roomLineSubtotal)}
                                  </span>
                                  <span className="bd-room-rate">
                                    {formatCurrency(pricePerNight)} x {nightCount} night{nightCount!==1?'s':''}
                                  </span>
                                </div>
                              </div>
                              <p className="bd-room-tax-note">Taxes and fees included in room price</p>
                              {!roomAssignmentPending && roomAmenities.length > 0 && (
                                <p className="bd-room-tax-note">Amenities: {roomAmenities.join(', ')}</p>
                              )}
                            </div>
                          </div>

                          {/* Add-ons */}
                          <div className="bd-addons">
                            <div className="bd-addons-heading"><Tag size={13}/> Add-ons</div>
                            {roomAddons.length === 0 ? (
                              <div className="bd-addon-empty">No add-ons</div>
                            ) : (
                              roomAddons.map((addon, ai) => {
                                const quantity = getAddonQuantity(addon);
                                const unitPrice = getAddonUnitPrice(addon);
                                const lineTotal = getAddonLineTotal(addon);
                                return (
                                  <div key={ai} className="bd-addon-row">
                                    <div className="bd-addon-meta">
                                      <span className="bd-addon-name">{addon.name || 'Add-on'}</span>
                                      {addon.description && (
                                        <span className="bd-addon-description">{addon.description}</span>
                                      )}
                                      <span className="bd-addon-qty">Qty {quantity} × {formatCurrency(unitPrice)}</span>
                                    </div>
                                    <span className="bd-addon-price">{formatCurrency(lineTotal)}</span>
                                  </div>
                                );
                              })
                            )}
                          </div>

                          {index < rooms.length-1 && <hr className="bd-room-divider"/>}
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>

              {/* Guest Information */}
              <div className="details-card accordion-card">
                <div className="accordion-header" onClick={() => toggleAccordion('guestInfo')}>
                  <h2 className="card-heading">Guest Information</h2>
                  {accordions.guestInfo ? <ChevronUp size={20}/> : <ChevronDown size={20}/>}
                </div>
                {accordions.guestInfo && (
                  <div className="accordion-content">
                    <div className="bd-guest-grid">
                      <div className="bd-guest-field">
                        <span className="bd-guest-label">Full Name</span>
                        <span className="bd-guest-value">{primaryGuest.name || 'N/A'}</span>
                      </div>
                      <div className="bd-guest-field">
                        <span className="bd-guest-label">Email Address</span>
                        <span className="bd-guest-value">{primaryGuest.email || 'N/A'}</span>
                      </div>
                      <div className="bd-guest-field">
                        <span className="bd-guest-label">Phone Number</span>
                        <span className="bd-guest-value">{primaryGuest.phone || 'N/A'}</span>
                      </div>
                    </div>
                    {booking.special_requests && (
                      <div className="bd-special-requests">
                        <span className="bd-guest-label">Special Requests</span>
                        <p className="bd-special-text">{booking.special_requests}</p>
                      </div>
                    )}
                  </div>
                )}
              </div>

              {/* Payment Information */}
              {latestPayment && (
                <div className="details-card accordion-card">
                  <div className="accordion-header" onClick={() => toggleAccordion('paymentInfo')}>
                    <h2 className="card-heading">Payment Information</h2>
                    {accordions.paymentInfo ? <ChevronUp size={20}/> : <ChevronDown size={20}/>}
                  </div>
                  {accordions.paymentInfo && (
                    <div className="accordion-content">
                      <div className="payment-info-grid">
                        <div className="payment-info-row"><span className="label">Payment Method</span><span className="value">{getPaymentMethodLabel(latestPayment)}</span></div>
                        <div className="payment-info-row"><span className="label">Payment Status</span><span className="value">{getPaymentStatusBadge(latestPayment)}</span></div>
                        {latestPayment.transaction_reference && <div className="payment-info-row"><span className="label">Reference Number</span><span className="value">{latestPayment.transaction_reference}</span></div>}
                        <div className="payment-info-row"><span className="label">Booking Date</span><span className="value">{formatDateTime(booking.created_at)}</span></div>
                        {latestPayment.paid_at && <div className="payment-info-row"><span className="label">Paid At</span><span className="value">{formatDateTime(latestPayment.paid_at)}</span></div>}
                      </div>
                    </div>
                  )}
                </div>
              )}
            </div>

            {/* RIGHT COLUMN */}
            <div className="right-column">

              {/* Price Summary */}
              <div className="summary-card sticky-card">
                <h2 className="card-heading">Price Summary</h2>
                <div className="price-breakdown">

                  {/* Rooms */}
                  <div className="price-row">
                    <span className="price-label">Rooms</span>
                    <span className="price-value">{formatCurrency(roomSubtotal)}</span>
                  </div>

                  {/* Add-ons */}
                                    {addonLineItems.length > 0 ? (
                    <>
                      {addonLineItems.map((addon) => (
                        <div key={addon.key} className="price-row price-row-addon-item">
                          <span className="price-label price-label-muted">
                            {addon.name} ({addon.quantity}×) · {addon.roomLabel}
                          </span>
                          <span className="price-value">{formatCurrency(addon.lineTotal)}</span>
                        </div>
                      ))}
                      <div className="price-row price-row-addon-subtotal">
                        <span className="price-label">Add-Ons Subtotal</span>
                        <span className="price-value">{formatCurrency(addonsSubtotal)}</span>
                      </div>
                    </>
                  ) : (
                    <div className="price-row">
                      <span className="price-label">Add-Ons</span>
                      <span className="price-value">None</span>
                    </div>
                  )}

                  {/* Subtotal */}
                  <div className="price-row">
                    <span className="price-label">Subtotal</span>
                    <span className="price-value">{formatCurrency(subtotal)}</span>
                  </div>

                  {/* Promo discount */}
                  {discount > 0 && (
                    <div className="price-row price-row-discount">
                      <span className="price-label">Promo Discount</span>
                      <span className="price-value">-{formatCurrency(discount)}</span>
                    </div>
                  )}

                  {/* Taxes */}
                  <div className="price-row">
                    <span className="price-label">Taxes and fees</span>
                    <span className="price-value">{formatCurrency(taxAmount)}</span>
                  </div>

                  <div className="price-divider"/>

                  {/* Total */}
                  <div className="price-row total-row">
                    <span className="price-label">Total</span>
                    <span className="price-value">{formatCurrency(total)}</span>
                  </div>
                  <p className="bd-tax-included-note">Taxes and fees are included in the room price.</p>

                  <div className="price-divider"/>

                  {/* Amount Paid */}
                  <div className="price-row">
                    <span className="price-label">Amount Paid</span>
                    <span className="price-value price-value-paid">{formatCurrency(totalPaid)}</span>
                  </div>

                  {/* Remaining Balance */}
                  <div className="price-row">
                    <span className="price-label">Remaining Balance</span>
                    <span className={`price-value ${remaining > 0 ? 'price-value-balance' : 'price-value-paid'}`}>
                      {formatCurrency(remaining)}
                    </span>
                  </div>

                  {remaining > 0 && <p className="bd-balance-note">Remaining balance is payable at check-in.</p>}
                </div>

                <div className="booking-dates-summary">
                  <p><strong>Booked on:</strong> {formatDateTime(booking.created_at)}</p>
                  <p><strong>Reference:</strong> {booking.reference_number}</p>
                </div>
              </div>

              {/* House Rules */}
              <div className="summary-card house-rules-card">
                <h2 className="card-heading">House Rules</h2>
                <ul className="house-rules-list">
                  {houseRules.map((rule,i) => <li key={i}>{rule}</li>)}
                </ul>
              </div>

              {booking?.booking_status === 'checked_out' && feedbackLoaded && (
                <div className="summary-card" style={{ marginTop: '1rem' }}>
                  <h2 className="card-heading">Your Feedback</h2>
              
                  {feedbackData?.is_submitted ? (
                    // Already submitted - show summary
                    <div>
                      <div style={{ display: 'flex', gap: 2, marginBottom: '0.75rem' }}>
                        {[1,2,3,4,5].map((s) => (
                          <Star key={s} size={20}
                            fill={s <= (feedbackData.rating_overall ?? 0) ? '#1A4BCC' : 'none'}
                            strokeWidth={1.5} style={{ color: '#1A4BCC' }} />
                        ))}
                      </div>
                      {feedbackData.review && (
                        <p style={{ fontSize: '0.88rem', color: '#4a5568', fontStyle: 'italic', lineHeight: 1.6, marginBottom: '0.75rem' }}>
                          "{feedbackData.review}"
                        </p>
                      )}
                      <p style={{ fontSize: '0.78rem', color: '#64748b' }}>
                        Submitted on {new Date(feedbackData.submitted_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                      </p>
                      {feedbackData.admin_reply && (
                        <div style={{ marginTop: '1rem', padding: '0.75rem 1rem', background: '#f0f9f0', borderLeft: '3px solid #16a34a', fontSize: '0.85rem', color: '#166534', lineHeight: 1.6 }}>
                          <strong>Hotel Response:</strong><br />{feedbackData.admin_reply}
                        </div>
                      )}
                    </div>
                  ) : feedbackData && !feedbackData.is_submitted && feedbackData.form_url ? (
                    // Not yet submitted - show button
                    <div>
                      <p style={{ fontSize: '0.88rem', color: '#4a5568', lineHeight: 1.6, marginBottom: '1.25rem' }}>
                        How was your stay? Share your experience and help us improve.
                      </p>
                      <a
                        href={feedbackData.form_url}
                        style={{ display: 'inline-flex', alignItems: 'center', gap: 8, background: '#0d1b3e', color: '#ffffff', padding: '12px 24px', textDecoration: 'none', fontSize: '0.88rem', fontWeight: 700, letterSpacing: '0.5px', border: '2px solid #1A4BCC' }}
                      >
                        <Star size={15} /> Leave Feedback
                      </a>
                    </div>
                  ) : (
                    <p style={{ fontSize: '0.85rem', color: '#64748b' }}>Feedback link will be sent to your email.</p>
                  )}
                </div>
              )}
            </div>
          </div>
        </div>
      </section>
    </div>
  );
};

export default BookingDetails;






