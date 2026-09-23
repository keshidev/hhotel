import { useNotificationTarget } from '../../hooks/useNotificationTarget';
import React, { useState, useEffect, useRef } from 'react';
import {
  Search, User, CalendarDays, BedDouble, CheckCircle2,
  ArrowRight, Loader2, AlertCircle,
  Users, CheckSquare, Square, CreditCard,
} from 'lucide-react';
import './CheckIn.css';
import checkInService from '../../services/receptionist/checkInService';
import NoShowConfirmModal from './NoShowConfirmModal';
import StatusBadge from '../../components/StatusBadge';

// ── helpers ───────────────────────────────────────────────────────────────────
const formatDate = (dateStr) => {
  if (!dateStr) return '—';
  return new Date(dateStr).toLocaleDateString('en-US', {
    month: 'short', day: '2-digit', year: 'numeric',
  });
};

const getNights = (checkIn, checkOut) => {
  if (!checkIn || !checkOut) return 0;
  const diff = new Date(checkOut) - new Date(checkIn);
  return Math.round(diff / (1000 * 60 * 60 * 24));
};

const fmt = (value) =>
  `₱${Number(value ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const isSettled = (value) => Number.isFinite(Number(value)) && Number(value) <= 0.009;

const toDatetimeLocal = (dateValue = new Date()) => {
  const date = new Date(dateValue);
  const pad = (value) => String(value).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

const createRequestKey = () => globalThis.crypto?.randomUUID?.()
  ?? 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
    const random = Math.floor(Math.random() * 16);
    return (character === 'x' ? random : ((random & 0x3) | 0x8)).toString(16);
  });

const netPaidFromPayments = (payments = []) => {
  let paid = 0;
  let refunded = 0;

  payments.forEach((payment) => {
    const amount = Number(payment?.amount ?? 0);
    const type = String(payment?.payment_type ?? '');
    const status = String(payment?.payment_status ?? '');

    if (type === 'refund' && ['completed', 'refunded'].includes(status)) {
      refunded += amount;
    } else if (type !== 'refund' && status === 'completed') {
      paid += amount;
    }
  });

  return Math.max(0, paid - refunded);
};

const toYmd = (value) => {
  if (!value) return null;
  const str = String(value);
  const match = str.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (match) return `${match[1]}-${match[2]}-${match[3]}`;

  const dt = new Date(value);
  if (Number.isNaN(dt.getTime())) return null;
  return dt.toLocaleDateString('en-CA');
};

const getNoShowButtonState = (booking, nowDate, cutoffTime = '18:00', cutoffLabel = '6:00 PM') => {
  if (!booking) return { show: false, disabled: true, reason: '' };

  const bookingStatus = String(booking.status || '').toLowerCase();
  if (bookingStatus !== 'confirmed') return { show: false, disabled: true, reason: '' };

  const checkInYmd = toYmd(booking.checkInRaw || booking.checkIn);
  const todayYmd = nowDate.toLocaleDateString('en-CA');
  if (!checkInYmd) return { show: false, disabled: true, reason: '' };
  if (checkInYmd > todayYmd) return { show: false, disabled: true, reason: '' };
  if (checkInYmd < todayYmd) return { show: true, disabled: false, reason: '' };
  const [cutoffHour, cutoffMinute] = String(cutoffTime).split(':').map(Number);
  const cutoff = new Date(nowDate);
  cutoff.setHours(
    Number.isInteger(cutoffHour) ? cutoffHour : 18,
    Number.isInteger(cutoffMinute) ? cutoffMinute : 0,
    0,
    0
  );
  if (nowDate >= cutoff) return { show: true, disabled: false, reason: '' };

  return {
    show: true,
    disabled: true,
    reason: `Available after ${cutoffLabel} on the check-in date`,
  };
};

const formatRoomDisplay = (roomType, roomNumber) => {
  const type = String(roomType ?? '').trim();
  const num = String(roomNumber ?? '').trim();
  const label = `${type} ${num}`.trim();
  return label || 'N/A';
};

const extractRooms = (booking) => {
  if (Array.isArray(booking.rooms) && booking.rooms.length > 0) {
    return booking.rooms
      .map((room, index) => {
        const display = String(room?.display ?? '').trim()
          || formatRoomDisplay(room?.room_type, room?.room_number);

        return {
          bookingRoomId: room?.booking_room_id ?? `api-room-${index}`,
          roomNumber: room?.room_number ?? null,
          roomType: room?.room_type ?? null,
          display,
        };
      })
      .filter((room) => room.display && room.display !== 'N/A');
  }

  if (Array.isArray(booking.booking_rooms) && booking.booking_rooms.length > 0) {
    return booking.booking_rooms
      .map((line, index) => {
        const room = line?.room;
        if (!room) return null;

        return {
          bookingRoomId: line?.id ?? `line-room-${index}`,
          roomNumber: room?.room_number ?? null,
          roomType: room?.room_type ?? null,
          display: formatRoomDisplay(room?.room_type, room?.room_number),
        };
      })
      .filter(Boolean);
  }

  return [];
};

const buildChipRoomLabel = (rooms) => {
  if (!Array.isArray(rooms) || rooms.length === 0) return 'N/A';
  if (rooms.length === 1) return rooms[0].display;
  return `${rooms[0].display} +${rooms.length - 1}`;
};

const mapBooking = (booking) => {
  const rooms         = extractRooms(booking);
  const roomCount     = Number(booking.room_count ?? rooms.length ?? 0);
  const totalAmount   = Number(booking.total_amount ?? 0);
  const totalPaid     = Number(booking.total_paid ?? netPaidFromPayments(booking.payments));
  const remainingBalance = Math.max(0, Number(booking.remaining_balance ?? (totalAmount - totalPaid)));
  const balanceSettled = isSettled(remainingBalance);
  // Support both old is_day_tour boolean and new stay_type enum
  const isDayTour     = booking.stay_type === 'day_use' || Boolean(booking.is_day_tour);
  const bookingSource = booking.booking_source ?? '';

  // For day use: same-day stay — show check_in date for check_out display.
  // check_out may be next-day if crossing midnight (e.g. 7pm-7am).
  const displayCheckOut = isDayTour ? booking.check_in : booking.check_out;

  return {
    id:              booking.id,
    referenceNumber: booking.reference_number,
    name:            booking.primary_guest?.name ?? 'Unknown Guest',
    bookingId:       booking.reference_number,
    status:          booking.booking_status,
    rooms,
    roomCount,
    bookingRoomIds:  rooms
      .map((room) => Number(room.bookingRoomId))
      .filter((id) => Number.isInteger(id) && id > 0),
    room:            rooms[0]?.display ?? 'N/A',
    assignedRoom:    String(booking.assigned_room ?? '').trim() || rooms.map((room) => room.display).join(', ') || 'N/A',
    chipRoom:        buildChipRoomLabel(rooms),
    nights:          isDayTour ? 0 : Math.max(1, getNights(booking.check_in, booking.check_out)),
    checkIn:         formatDate(booking.check_in),
    checkOut:        formatDate(displayCheckOut),
    checkInRaw:      booking.check_in,
    checkOutRaw:     displayCheckOut,
    isDayTour,
    bookingSource,
    totalAmount,
    totalPaid,
    remainingBalance,
    balanceSettled,
    prepaid:         balanceSettled,
    email:           booking.primary_guest?.email ?? '',
    phone:           booking.primary_guest?.phone ?? '',
  };
};

const showToast = (message, type = 'success') => {
  const existing = document.getElementById('checkin-toast');
  if (existing) existing.remove();
  const toast = document.createElement('div');
  toast.id = 'checkin-toast';
  toast.style.cssText = `
    position:fixed;top:20px;right:20px;padding:14px 22px;border-radius:8px;
    color:#fff;font-size:14px;font-weight:500;z-index:9999;
    box-shadow:0 8px 30px rgba(0,0,0,0.18);
    background:${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#f59e0b'};
    transition:opacity 0.3s ease;
  `;
  toast.textContent = message;
  document.body.appendChild(toast);
  setTimeout(() => {
    toast.style.opacity = '0';
    setTimeout(() => toast.remove(), 300);
  }, 3000);
};

const C = {
  primary: '#1a4bcc',
  text: '#0d1b3e',
  textSec: '#4a5568',
  textMute: '#64748b',
  border: '#dde3f0',
  borderLight: '#e8eef7',
  bg: '#f7f9ff',
  surface: '#fff',
  green: '#4caf50',
};

// ── component ─────────────────────────────────────────────────────────────────
export default function CheckIn() {
  // ── single guest flow ──
  useNotificationTarget((target) => {
    let active = true;
    setQuery(target.bookingDatabaseId ? '' : target.search);
    setSelected(null); setIsCheckedIn(false); setConfirmModal(false); setBulkMode(false);
    if (target.bookingDatabaseId) {
      checkInService.getBooking(target.bookingDatabaseId).then((response) => {
        if (active) setQuery(response.data?.reference_number || '');
      }).catch(() => {
        if (active) setSearchError('The booking linked to this notification is unavailable.');
      });
    }
    return () => { active = false; };
  });
  const [query, setQuery]               = useState('');
  const [results, setResults]           = useState([]);
  const [selected, setSelected]         = useState(null);
  const [confirmModal, setConfirmModal] = useState(false);
  const [noShowModalOpen, setNoShowModalOpen] = useState(false);
  const [noShowLoading, setNoShowLoading] = useState(false);
  const [noShowError, setNoShowError] = useState('');
  const [isCheckedIn, setIsCheckedIn]   = useState(false);
  const [searching, setSearching]       = useState(false);
  const [actionLoading, setActionLoading] = useState(false);
  const [earlyCheckInRequired, setEarlyCheckInRequired] = useState(false);
  const [earlyCheckInMessage, setEarlyCheckInMessage] = useState('');
  const [earlyCheckInReason, setEarlyCheckInReason] = useState('');
  const [searchError, setSearchError]   = useState(null);
  const [nowTick, setNowTick] = useState(Date.now());
  const [noShowCutoff, setNoShowCutoff] = useState({ time: '18:00', label: '6:00 PM' });
  const [settleModal, setSettleModal] = useState(false);
  const [settleLoading, setSettleLoading] = useState(false);
  const [settleAmount, setSettleAmount] = useState('');
  const [settleMethod, setSettleMethod] = useState('cash');
  const [settleNote, setSettleNote] = useState('');
  const [settleGcashReference, setSettleGcashReference] = useState('');
  const [settleGcashSender, setSettleGcashSender] = useState('');
  const [settleGcashPaidAt, setSettleGcashPaidAt] = useState(toDatetimeLocal());
  const [settleMerchantConfirmed, setSettleMerchantConfirmed] = useState(false);
  const settleRequestKey = useRef(createRequestKey());

  // ── today arrivals / bulk ──
  const [todayArrivals, setTodayArrivals]       = useState([]);
  const [arrivalsLoading, setArrivalsLoading]   = useState(false);
  const [bulkMode, setBulkMode]                 = useState(false);
  const [selectedIds, setSelectedIds]           = useState(new Set());
  const [bulkConfirmModal, setBulkConfirmModal] = useState(false);
  const [bulkLoading, setBulkLoading]           = useState(false);
  const [bulkResults, setBulkResults]           = useState(null); // { succeeded, failed }

  const debounceRef = useRef(null);
  const dropdownRef = useRef(null);

  // ── derived ──
  const pendingArrivals  = todayArrivals.filter((g) => g.status === 'confirmed' || g.status === 'pending');
  const eligibleArrivals = pendingArrivals.filter((g) => g.balanceSettled);
  const selectedGuests   = eligibleArrivals.filter((g) => selectedIds.has(g.id));

  // ── loaders ──
  const loadTodayArrivals = async () => {
    setArrivalsLoading(true);
    try {
      const res = await checkInService.getTodayArrivals();
      const bookings = res.data?.data ?? [];
      setTodayArrivals(bookings.map(mapBooking));
      setNoShowCutoff({
        time: res.meta?.no_show_cutoff_time || '18:00',
        label: res.meta?.no_show_cutoff_label || '6:00 PM',
      });
    } catch {
      // non-critical
    } finally {
      setArrivalsLoading(false);
    }
  };

  useEffect(() => { loadTodayArrivals(); }, []);

  useEffect(() => {
    const intervalId = setInterval(() => setNowTick(Date.now()), 60000);
    return () => clearInterval(intervalId);
  }, []);

  // ── debounced search ──
  useEffect(() => {
    if (query.trim().length < 2) { setResults([]); return; }
    clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(async () => {
      setSearching(true);
      setSearchError(null);
      try {
        const res = await checkInService.searchGuests(query);
        const bookings = res.data?.data ?? [];
        setResults(bookings.map(mapBooking));
        setNoShowCutoff({
          time: res.meta?.no_show_cutoff_time || '18:00',
          label: res.meta?.no_show_cutoff_label || '6:00 PM',
        });
      } catch {
        setSearchError('Failed to search guests.');
        setResults([]);
      } finally {
        setSearching(false);
      }
    }, 400);
    return () => clearTimeout(debounceRef.current);
  }, [query]);

  // ── close dropdown on outside click ──
  useEffect(() => {
    const handler = (e) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target))
        setResults([]);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, []);

  // ── single guest handlers ──
  const handleSelect = (guest) => {
    setSelected(guest);
    setQuery(guest.name);
    setResults([]);
    setIsCheckedIn(false);
    setConfirmModal(false);
    setEarlyCheckInRequired(false);
    setEarlyCheckInMessage('');
    setEarlyCheckInReason('');
    setNoShowModalOpen(false);
    setNoShowError('');
    setSettleModal(false);
  };

  const handleCheckIn = async () => {
    if (!selected) return;
    setActionLoading(true);
    try {
      if (earlyCheckInRequired) {
        const response = await checkInService.requestEarlyCheckIn(
          selected.referenceNumber,
          earlyCheckInReason.trim()
        );
        setConfirmModal(false);
        setEarlyCheckInRequired(false);
        setEarlyCheckInMessage('');
        setEarlyCheckInReason('');
        showToast(response.message || 'Early check-in request sent to an administrator.', 'success');
        return;
      }

      await checkInService.completeCheckIn(selected.referenceNumber, {
        booking_room_ids: selected.bookingRoomIds,
      });
      setIsCheckedIn(true);
      setConfirmModal(false);
      setSelected((prev) => ({ ...prev, status: 'checked_in' }));
      showToast(`${selected.name} checked in successfully!`, 'success');
      loadTodayArrivals();
    } catch (err) {
      const data = err.response?.data ?? {};
      const msg = data.message || 'Failed to complete check-in.';
      if (data.code === 'EARLY_APPROVAL_REQUIRED') {
        setEarlyCheckInRequired(true);
        setEarlyCheckInMessage(msg);
        showToast('Administrator approval is required for early check-in.', 'warning');
      } else if (data.code === 'EARLY_APPROVAL_PENDING') {
        setConfirmModal(false);
        showToast(msg, 'warning');
      } else if (data.code === 'BALANCE_REQUIRED') {
        setSelected((previous) => previous ? {
          ...previous,
          remainingBalance: Number(data.remaining_balance ?? previous.remainingBalance),
          balanceSettled: false,
          prepaid: false,
        } : previous);
        setConfirmModal(false);
        setSettleAmount(String(Number(data.remaining_balance ?? selected.remainingBalance ?? 0)));
        setSettleModal(true);
        showToast(msg, 'warning');
      } else {
        showToast(msg, 'error');
        setConfirmModal(false);
      }
    } finally {
      setActionLoading(false);
    }
  };

  const openSettleModal = () => {
    if (!selected || selected.balanceSettled) return;
    setSettleAmount(String(selected.remainingBalance));
    setSettleMethod('cash');
    setSettleNote('Remaining balance collected at check-in');
    setSettleGcashReference('');
    setSettleGcashSender('');
    setSettleGcashPaidAt(toDatetimeLocal());
    setSettleMerchantConfirmed(false);
    settleRequestKey.current = createRequestKey();
    setSettleModal(true);
  };

  const handleSettleBalance = async () => {
    if (!selected || settleLoading) return;

    const requestedAmount = Number(settleAmount);
    if (!Number.isFinite(requestedAmount) || requestedAmount <= 0) {
      showToast('Enter a valid payment amount.', 'warning');
      return;
    }
    if (requestedAmount - selected.remainingBalance > 0.009) {
      showToast(`Amount cannot exceed ${fmt(selected.remainingBalance)}.`, 'warning');
      return;
    }
    if (!settleNote.trim()) {
      showToast('Please enter a payment note.', 'warning');
      return;
    }
    if (settleMethod === 'gcash' && settleGcashReference.trim().length < 6) {
      showToast('Enter the GCash transaction reference.', 'warning');
      return;
    }
    if (settleMethod === 'gcash' && settleGcashSender.trim().length < 2) {
      showToast('Enter the GCash sender name.', 'warning');
      return;
    }
    if (settleMethod === 'gcash' && (!settleGcashPaidAt || !settleMerchantConfirmed)) {
      showToast('Confirm the GCash payment against the official merchant record.', 'warning');
      return;
    }

    setSettleLoading(true);
    try {
      const bookingKey = selected.id ?? selected.referenceNumber;
      const response = await checkInService.settleBalance(bookingKey, {
        amount: requestedAmount,
        payment_method: settleMethod,
        notes: settleNote.trim(),
        idempotency_key: settleRequestKey.current,
        gcash_reference: settleMethod === 'gcash' ? settleGcashReference.trim() : null,
        gcash_sender_name: settleMethod === 'gcash' ? settleGcashSender.trim() : null,
        gcash_paid_at: settleMethod === 'gcash' ? settleGcashPaidAt : null,
        merchant_record_confirmed: settleMethod === 'gcash' ? settleMerchantConfirmed : null,
      });

      const refreshedResponse = await checkInService.getBooking(bookingKey);
      const refreshed = mapBooking(refreshedResponse.data);
      setSelected(refreshed);
      setSettleModal(false);
      settleRequestKey.current = createRequestKey();
      await loadTodayArrivals();
      showToast(response.message || 'Balance payment recorded.', 'success');
    } catch (error) {
      const data = error.response?.data ?? {};
      if (/no remaining balance/i.test(data.message ?? '')) {
        const refreshedResponse = await checkInService.getBooking(selected.id);
        setSelected(mapBooking(refreshedResponse.data));
        setSettleModal(false);
        showToast('Balance is already settled.', 'success');
      } else {
        showToast(data.message || 'Failed to record payment.', 'error');
      }
    } finally {
      setSettleLoading(false);
    }
  };

  const closeCheckInModal = () => {
    if (actionLoading) return;
    setConfirmModal(false);
    setEarlyCheckInRequired(false);
    setEarlyCheckInMessage('');
    setEarlyCheckInReason('');
  };

  const closeNoShowModal = () => {
    if (noShowLoading) return;
    setNoShowModalOpen(false);
    setNoShowError('');
  };

  const handleMarkNoShow = async (payload) => {
    if (!selected) return;
    if (!payload?.contacted_guest) {
      setNoShowError('Please confirm that you attempted to contact the guest.');
      return;
    }

    setNoShowLoading(true);
    setNoShowError('');
    try {
      const response = await checkInService.markAsNoShow(selected.id, payload);

      setTodayArrivals((prev) => prev.filter((g) => g.id !== selected.id));
      setResults((prev) => prev.filter((g) => g.id !== selected.id));
      setSelectedIds((prev) => {
        const next = new Set(prev);
        next.delete(selected.id);
        return next;
      });
      setSelected(null);
      setNoShowModalOpen(false);

      if (!response.already_processed) {
        window.dispatchEvent(new CustomEvent('receptionist:checkin-count-delta', {
          detail: { delta: -1 },
        }));
      }

      showToast(response.message || 'Booking marked as No-Show.', 'success');
    } catch (err) {
      const msg = err?.response?.data?.message || 'Failed to mark booking as no-show.';
      setNoShowError(msg);
    } finally {
      setNoShowLoading(false);
    }
  };

  // ── bulk handlers ──
  const toggleSelectAll = () => {
    if (selectedIds.size === eligibleArrivals.length) {
      setSelectedIds(new Set());
    } else {
      setSelectedIds(new Set(eligibleArrivals.map((g) => g.id)));
    }
  };

  const toggleOne = (id) => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  };

  const handleBulkCheckIn = async () => {
    if (selectedGuests.length === 0) return;
    setBulkLoading(true);
    const succeeded = [];
    const failed    = [];

    await Promise.allSettled(
      selectedGuests.map(async (g) => {
        try {
          await checkInService.completeCheckIn(g.referenceNumber, {
            booking_room_ids: g.bookingRoomIds,
          });
          succeeded.push(g);
        } catch (err) {
          failed.push({ guest: g, reason: err.response?.data?.message || 'Unknown error' });
        }
      })
    );

    setBulkResults({ succeeded, failed });
    setBulkConfirmModal(false);
    setBulkLoading(false);
    setSelectedIds(new Set());
    await loadTodayArrivals();

    if (failed.length === 0) {
      showToast(`${succeeded.length} guest(s) checked in successfully!`, 'success');
    } else {
      showToast(`${succeeded.length} checked in, ${failed.length} failed.`, 'warning');
    }
  };

  const canCheckIn = selected && !isCheckedIn &&
    selected.balanceSettled &&
    (selected.status === 'confirmed' || selected.status === 'pending');
  const noShowButtonState = getNoShowButtonState(
    selected,
    new Date(nowTick),
    noShowCutoff.time,
    noShowCutoff.label
  );
  const selectedRoomCount = selected?.roomCount ?? selected?.rooms?.length ?? 0;
  const isMultiRoomBooking = selectedRoomCount > 1;

  const statusLabel = (s) => {
    if (s === 'confirmed')  return 'Confirmed';
    if (s === 'checked_in') return 'Checked In';
    if (s === 'pending')    return 'Pending';
    return s;
  };

  return (
    <div className="checkin-page">
      {/* ── Page Header ── */}
      <div
        className="page-header"
        style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: '1rem' }}
      >
        <div>
          <h1>Check-In</h1>
          <p className="page-subtitle">Search and complete guest arrivals for today.</p>
        </div>

        {/* Bulk Mode Toggle — only show when there are arrivals */}
        {todayArrivals.length > 0 && (
          <button
            onClick={() => {
              setBulkMode(!bulkMode);
              setSelectedIds(new Set());
              setBulkResults(null);
              setSelected(null);
              setQuery('');
              setIsCheckedIn(false);
            }}
            style={{
              display: 'flex', alignItems: 'center', gap: 8,
              background: bulkMode ? '#1a4bcc' : 'transparent',
              color: bulkMode ? '#fff' : '#1a4bcc',
              border: '2px solid #1a4bcc',
              borderRadius: 8, padding: '0.55rem 1.1rem',
              fontWeight: 700, cursor: 'pointer', fontSize: '0.88rem',
              fontFamily: 'inherit', transition: 'all 0.2s',
            }}
          >
            <Users size={16} />
            {bulkMode ? 'Exit Bulk Mode' : 'Bulk Check-In'}
          </button>
        )}
      </div>

      {/* ══════════════════════════════════════════════════════
          BULK MODE
         ══════════════════════════════════════════════════════ */}
      {bulkMode ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>

          {/* ── Results summary after bulk action ── */}
          {bulkResults && (
            <div style={{
              background: bulkResults.failed.length === 0 ? '#f0fdf4' : '#fffbeb',
              border: `1px solid ${bulkResults.failed.length === 0 ? '#86efac' : '#fcd34d'}`,
              borderRadius: 10, padding: '1rem 1.25rem',
            }}>
              <div style={{ fontWeight: 700, color: C.text, marginBottom: '0.4rem' }}>
                Bulk Check-In Results
              </div>
              <div style={{ color: '#166534', fontSize: '0.9rem' }}>
                ✓ {bulkResults.succeeded.length} guest(s) checked in successfully
              </div>
              {bulkResults.failed.length > 0 && (
                <div style={{ marginTop: '0.5rem' }}>
                  <div style={{ color: '#92400e', fontSize: '0.9rem', fontWeight: 600 }}>
                    ✕ {bulkResults.failed.length} failed:
                  </div>
                  {bulkResults.failed.map(({ guest: g, reason }) => (
                    <div key={g.id} style={{ color: '#92400e', fontSize: '0.85rem', marginTop: '0.25rem' }}>
                      • {g.name} ({g.chipRoom}) — {reason}
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {arrivalsLoading ? (
            <div style={{ display: 'flex', justifyContent: 'center', padding: '3rem' }}>
              <Loader2 size={28} style={{ animation: 'spin 1s linear infinite', color: C.primary }} />
            </div>
          ) : todayArrivals.length === 0 ? (
            <div style={{ textAlign: 'center', padding: '4rem 1rem', color: C.textMute }}>
              No expected arrivals found for today.
            </div>
          ) : (
            <div style={{ background: C.surface, border: `1px solid ${C.border}`, borderRadius: 12, overflow: 'hidden' }}>

              {/* Header row with Select All + action button */}
              <div style={{
                display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                padding: '0.9rem 1.25rem', borderBottom: `1px solid ${C.borderLight}`,
                background: C.bg, flexWrap: 'wrap', gap: '0.75rem',
              }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
                  <button
                    onClick={toggleSelectAll}
                    style={{ background: 'none', border: 'none', cursor: 'pointer', padding: 0, display: 'flex', alignItems: 'center', color: '#1a4bcc' }}
                  >
                    {selectedIds.size === eligibleArrivals.length && eligibleArrivals.length > 0
                      ? <CheckSquare size={20} />
                      : <Square size={20} />
                    }
                  </button>
                  <span style={{ fontWeight: 700, color: C.text, fontSize: '0.95rem' }}>
                    Today's Arrivals
                  </span>
                  <span style={{ background: '#dbeafe', color: '#1e40af', fontSize: '0.75rem', fontWeight: 700, padding: '2px 10px', borderRadius: 20 }}>
                    {pendingArrivals.length} pending
                  </span>
                </div>

                {selectedIds.size > 0 && (
                  <button
                    onClick={() => setBulkConfirmModal(true)}
                    style={{
                      display: 'flex', alignItems: 'center', gap: 6,
                      background: '#1a4bcc', color: '#fff', border: 'none',
                      borderRadius: 8, padding: '0.55rem 1.1rem',
                      fontWeight: 700, cursor: 'pointer', fontSize: '0.88rem',
                      fontFamily: 'inherit',
                    }}
                  >
                    <ArrowRight size={15} />
                    Check In Selected ({selectedIds.size})
                  </button>
                )}
              </div>

              {/* Guest rows */}
              {pendingArrivals.length === 0 ? (
                <div style={{ padding: '1.5rem', color: C.textMute, fontSize: '0.9rem', textAlign: 'center' }}>
                  No pending arrivals — all guests have already checked in.
                </div>
              ) : (
                pendingArrivals.map((g) => (
                  <div
                    key={g.id}
                    onClick={() => g.balanceSettled && toggleOne(g.id)}
                    style={{
                      display: 'flex', alignItems: 'center', gap: '1rem',
                      padding: '0.9rem 1.25rem',
                      borderBottom: `1px solid ${C.borderLight}`,
                      cursor: g.balanceSettled ? 'pointer' : 'not-allowed',
                      background: selectedIds.has(g.id) ? 'rgba(26,75,204,0.06)' : C.surface,
                      opacity: g.balanceSettled ? 1 : 0.68,
                      transition: 'background 0.15s',
                    }}
                  >
                    <span style={{ color: C.primary, flexShrink: 0 }}>
                      {selectedIds.has(g.id) ? <CheckSquare size={18} /> : <Square size={18} />}
                    </span>
                    <div style={{ flex: 1 }}>
                      <div style={{ fontWeight: 600, color: C.text }}>{g.name}</div>
                      <div style={{ color: C.textMute, fontSize: '0.83rem' }}>
                        {g.chipRoom} · {g.checkIn}
                        {g.isDayTour
                          ? ' · Day Use (12 hrs)'
                          : ` → ${g.checkOut} · ${g.nights} night(s)`
                        }
                      </div>
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', flexShrink: 0 }}>
                      {g.balanceSettled ? (
                        <span style={{ background: '#dcfce7', color: '#166534', fontSize: '0.72rem', fontWeight: 700, padding: '2px 8px', borderRadius: 20 }}>
                          Balance settled
                        </span>
                      ) : (
                        <span style={{ background: '#fef3c7', color: '#92400e', fontSize: '0.72rem', fontWeight: 700, padding: '2px 8px', borderRadius: 20 }}>
                          Due {fmt(g.remainingBalance)}
                        </span>
                      )}
                      <StatusBadge status={g.status} label={statusLabel(g.status)} />
                    </div>
                  </div>
                ))
              )}
            </div>
          )}
        </div>
      ) : (
        /* ══════════════════════════════════════════════════════
            SINGLE GUEST MODE (original flow)
           ══════════════════════════════════════════════════════ */
        <>
          {/* Search */}
          <div className="checkin-search-wrapper" ref={dropdownRef}>
            <div className="checkin-search-box">
              {searching
                ? <Loader2 size={18} className="search-icon spin" />
                : <Search size={18} className="search-icon" />
              }
              <input
                type="text"
                placeholder="Search guest name, booking ID, or email…"
                value={query}
                onChange={(e) => {
                  setQuery(e.target.value);
                  setSelected(null);
                  setIsCheckedIn(false);
                }}
              />
            </div>

            {searchError && (
              <div className="search-error">
                <AlertCircle size={14} /> {searchError}
              </div>
            )}

            {results.length > 0 && !selected && (
              <ul className="search-dropdown">
                {results.map((g) => (
                  <li key={g.id} className="search-dropdown-item" onClick={() => handleSelect(g)}>
                    <User size={15} />
                    <span>{g.name}</span>
                    <span className="dropdown-booking-id">{g.bookingId}</span>
                    <StatusBadge status={g.status} label={statusLabel(g.status)} />
                  </li>
                ))}
              </ul>
            )}

            {query.trim().length >= 2 && !searching && results.length === 0 && !selected && !searchError && (
              <div className="empty-state">
                <User size={40} />
                <p>No guest found for "<strong>{query}</strong>"</p>
                <span>Try a different name, booking ID, or email</span>
              </div>
            )}
          </div>

          {/* Today's Expected Arrivals Strip */}
          {(todayArrivals.length > 0 || arrivalsLoading) && (
            <div className="expected-strip">
              <span className="expected-strip-label">Expected Arrivals</span>
              {arrivalsLoading ? (
                <Loader2 size={14} className="spin" />
              ) : (
                todayArrivals.map((g) => (
                  <button key={g.id} className="expected-chip" onClick={() => handleSelect(g)}>
                    <span className="chip-dot" />
                    {g.name}
                    <span className="chip-room">· {g.chipRoom}</span>
                  </button>
                ))
              )}
            </div>
          )}

          {/* Guest Card */}
          {selected && (
            <div className={`guest-card ${isCheckedIn ? 'guest-card--done' : ''}`}>
              <div className="guest-card-header">
                <div className="guest-avatar-wrap">
                  <User size={26} />
                </div>
                <div className="guest-meta">
                  <h2 className="guest-name">{selected.name}</h2>
                  <span className="guest-booking-id">Booking ID: {selected.bookingId}</span>
                </div>
                <StatusBadge
                  status={isCheckedIn ? 'checked_in' : selected.status}
                  label={isCheckedIn ? 'Checked In' : statusLabel(selected.status)}
                />
              </div>

              <div className="guest-card-body">
                <div className="info-tile">
                  <BedDouble size={16} className="tile-icon" />
                  <div>
                    <span className="tile-label">
                      {isMultiRoomBooking
                        ? `Assigned Rooms (${selectedRoomCount})`
                        : 'Assigned Room'}
                    </span>
                    {isMultiRoomBooking ? (
                      <span className="tile-value tile-room-list">
                        {selected.rooms.map((room) => (
                          <span key={room.bookingRoomId} className="tile-room-line">
                            {room.display}
                          </span>
                        ))}
                      </span>
                    ) : (
                      <span className="tile-value">{selected.room}</span>
                    )}
                  </div>
                </div>
                <div className="info-tile">
                  <CalendarDays size={16} className="tile-icon" />
                  <div>
                    <span className="tile-label">Stay Duration</span>
                    <span className="tile-value">
                      {selected.isDayTour
                        ? 'Day Use (12 hrs)'
                        : `${selected.nights} Night${selected.nights !== 1 ? 's' : ''}`}
                    </span>
                  </div>
                </div>
                <div className="info-tile">
                  <CalendarDays size={16} className="tile-icon" />
                  <div>
                    <span className="tile-label">Check-In</span>
                    <span className="tile-value">{selected.checkIn}</span>
                  </div>
                </div>
                <div className="info-tile">
                  <CalendarDays size={16} className="tile-icon" />
                  <div>
                    <span className="tile-label">Check-Out</span>
                    <span className="tile-value">{selected.checkOut}</span>
                  </div>
                </div>
              </div>

              <div className="guest-card-footer">
                {selected.balanceSettled && (
                  <span className="prepaid-badge">
                    <CheckCircle2 size={14} /> Full booking balance settled
                  </span>
                )}
                <div className="guest-card-actions">
                  {!isCheckedIn ? (
                    <>
                      {!selected.balanceSettled && (
                        <div className="payment-warning-inline">
                          <AlertCircle size={14} /> Balance due: <strong>{fmt(selected.remainingBalance)}</strong>
                        </div>
                      )}
                      {!selected.balanceSettled && (
                        <button className="btn-balance" onClick={openSettleModal} disabled={settleLoading}>
                          <CreditCard size={15} /> Record Balance Payment
                        </button>
                      )}
                      {noShowButtonState.show && (
                        <span title={noShowButtonState.disabled ? noShowButtonState.reason : ''}>
                          <button
                            className="btn-no-show"
                            onClick={() => {
                              setNoShowError('');
                              setNoShowModalOpen(true);
                            }}
                            disabled={noShowButtonState.disabled || noShowLoading}
                          >
                            Mark as No-Show
                          </button>
                        </span>
                      )}
                      <button
                        className="btn-primary"
                        onClick={() => setConfirmModal(true)}
                        disabled={!canCheckIn || actionLoading}
                      >
                        {actionLoading
                          ? <><Loader2 size={15} className="spin" /> Processing…</>
                          : <>Complete Check-In <ArrowRight size={16} /></>
                        }
                      </button>
                    </>
                  ) : (
                    <div className="success-banner">
                      <CheckCircle2 size={18} /> Check-In completed successfully!
                    </div>
                  )}
                </div>
              </div>
            </div>
          )}
        </>
      )}

      {/* ── Single Guest Confirm Modal ── */}
      {settleModal && selected && (
        <div className="modal-lay" onClick={() => !settleLoading && setSettleModal(false)}>
          <div className="modal-square balance-modal" onClick={(event) => event.stopPropagation()}>
            <h3>Record Check-In Balance</h3>
            <p>Collect the original booking balance before giving room access to <strong>{selected.name}</strong>.</p>
            <div className="balance-summary">
              <span>Booking total <strong>{fmt(selected.totalAmount)}</strong></span>
              <span>Verified net paid <strong>{fmt(selected.totalPaid)}</strong></span>
              <span>Balance due <strong>{fmt(selected.remainingBalance)}</strong></span>
            </div>
            <div className="balance-form">
              <label>
                Amount received
                <input type="number" min="0.01" step="0.01" max={selected.remainingBalance} value={settleAmount} onChange={(event) => setSettleAmount(event.target.value)} />
              </label>
              <label>
                Payment method
                <select value={settleMethod} onChange={(event) => setSettleMethod(event.target.value)}>
                  <option value="cash">Cash</option>
                  <option value="gcash">GCash</option>
                </select>
              </label>
              {settleMethod === 'gcash' && (
                <div className="gcash-check-panel">
                  <strong>Verify using the official hotel GCash merchant record</strong>
                  <input value={settleGcashReference} onChange={(event) => setSettleGcashReference(event.target.value)} placeholder="GCash transaction/reference number" maxLength={80} />
                  <input value={settleGcashSender} onChange={(event) => setSettleGcashSender(event.target.value)} placeholder="GCash sender name" maxLength={120} />
                  <label>
                    Date and time paid
                    <input type="datetime-local" value={settleGcashPaidAt} max={toDatetimeLocal()} onChange={(event) => setSettleGcashPaidAt(event.target.value)} />
                  </label>
                  <label className="gcash-confirmation">
                    <input type="checkbox" checked={settleMerchantConfirmed} onChange={(event) => setSettleMerchantConfirmed(event.target.checked)} />
                    I matched the reference, sender, amount, and paid time against the official hotel GCash record.
                  </label>
                </div>
              )}
              <label>
                Payment note
                <textarea rows={3} maxLength={500} value={settleNote} onChange={(event) => setSettleNote(event.target.value)} />
              </label>
            </div>
            <div className="modal-actions">
              <button className="btn-secondary" onClick={() => setSettleModal(false)} disabled={settleLoading}>Cancel</button>
              <button className="btn-primary" onClick={handleSettleBalance} disabled={settleLoading}>
                {settleLoading ? <><Loader2 size={15} className="spin" /> Saving…</> : 'Save Payment'}
              </button>
            </div>
          </div>
        </div>
      )}

      {confirmModal && (
        <div className="modal-lay" onClick={closeCheckInModal}>
          <div className="modal-square" onClick={(e) => e.stopPropagation()}>
            <h3>{earlyCheckInRequired ? 'Request Early Check-In' : 'Confirm Check-In'}</h3>
            <p>
              You are about to check in <strong>{selected?.name}</strong> to{' '}
              <strong>{selected?.assignedRoom || selected?.room}</strong>.
            </p>
            {earlyCheckInRequired && (
              <div style={{ marginTop: '1rem' }}>
                <div style={{ padding: '0.75rem', borderRadius: 8, background: '#fff7ed', color: '#9a3412', fontSize: '0.85rem', marginBottom: '0.8rem' }}>
                  {earlyCheckInMessage}
                </div>
                <label htmlFor="early-check-in-reason" style={{ display: 'block', fontSize: '0.82rem', fontWeight: 700, marginBottom: '0.4rem' }}>
                  Early check-in reason <span style={{ color: '#dc2626' }}>*</span>
                </label>
                <textarea
                  id="early-check-in-reason"
                  value={earlyCheckInReason}
                  onChange={(event) => setEarlyCheckInReason(event.target.value)}
                  maxLength={500}
                  rows={3}
                  placeholder="Example: Room is ready and guest arrived early due to an early flight."
                  style={{ width: '100%', resize: 'vertical', border: `1px solid ${C.border}`, borderRadius: 8, padding: '0.7rem', font: 'inherit', boxSizing: 'border-box' }}
                />
                <small style={{ color: C.textMute }}>At least 10 characters. An administrator must approve this request.</small>
              </div>
            )}
            <div className="modal-actions">
              <button className="btn-secondary" onClick={closeCheckInModal} disabled={actionLoading}>
                Cancel
              </button>
              <button
                className="btn-primary"
                onClick={handleCheckIn}
                disabled={actionLoading || (earlyCheckInRequired && earlyCheckInReason.trim().length < 10)}
              >
                {actionLoading
                  ? <><Loader2 size={15} className="spin" /> Processing…</>
                  : <>{earlyCheckInRequired ? 'Send Approval Request' : 'Confirm'} <ArrowRight size={15} /></>
                }
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ── Bulk Confirm Modal ── */}
      <NoShowConfirmModal
        open={noShowModalOpen}
        guest={selected}
        loading={noShowLoading}
        errorMessage={noShowError}
        cutoffLabel={noShowCutoff.label}
        onClose={closeNoShowModal}
        onConfirm={handleMarkNoShow}
      />

      {bulkConfirmModal && (
        <div
          onClick={() => !bulkLoading && setBulkConfirmModal(false)}
          style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', backdropFilter: 'blur(4px)', zIndex: 9999, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '1rem' }}
        >
          <div
            onClick={(e) => e.stopPropagation()}
            style={{ background: 'var(--color-surface)', borderRadius: 14, border: `1px solid ${C.border}`, boxShadow: '0 20px 60px rgba(0,0,0,0.2)', width: '100%', maxWidth: 460, padding: '2rem', fontFamily: "'Inter', sans-serif", color: 'var(--color-text-primary)' }}
          >
            <h3 style={{ margin: '0 0 0.5rem', fontFamily: "'Inter', sans-serif", fontSize: '1.0625rem', fontWeight: 700, color: 'var(--color-text-primary)' }}>
              Bulk Check-In Confirmation
            </h3>
            <p style={{ margin: '0 0 1rem', fontFamily: "'Inter', sans-serif", color: 'var(--color-text-secondary)', fontSize: '0.875rem', fontWeight: 400 }}>
              You are about to check in <strong style={{ color: C.text }}>{selectedGuests.length} guest(s)</strong>.
            </p>

            {/* Guest list preview */}
            <div style={{ background: C.bg, borderRadius: 8, border: `1px solid ${C.borderLight}`, maxHeight: 220, overflowY: 'auto', marginBottom: '1.25rem' }}>
              {selectedGuests.map((g) => (
                <div key={g.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '0.6rem 1rem', borderBottom: `1px solid ${C.borderLight}`, fontSize: '0.88rem' }}>
                  <div>
                    <span style={{ fontWeight: 600, color: C.text }}>{g.name}</span>
                    <span style={{ color: C.textMute, marginLeft: 8 }}>{g.chipRoom}</span>
                  </div>
                  <div style={{ display: 'flex', gap: '0.4rem', alignItems: 'center' }}>
                    {g.balanceSettled && (
                      <span style={{ background: '#dcfce7', color: '#166534', fontSize: '0.72rem', fontWeight: 700, padding: '2px 8px', borderRadius: 20 }}>
                        Balance settled
                      </span>
                    )}
                    <span style={{ background: '#dbeafe', color: '#1e40af', fontSize: '0.72rem', fontWeight: 700, padding: '2px 8px', borderRadius: 20 }}>
                      {statusLabel(g.status)}
                    </span>
                  </div>
                </div>
              ))}
            </div>

            <div style={{ display: 'flex', gap: '0.75rem', justifyContent: 'flex-end' }}>
              <button
                onClick={() => setBulkConfirmModal(false)}
                disabled={bulkLoading}
                style={{ padding: '0.625rem 1.25rem', borderRadius: 8, border: `1px solid ${C.border}`, background: 'var(--color-surface)', color: 'var(--color-text-secondary)', cursor: 'pointer', fontFamily: "'Inter', sans-serif", fontSize: '0.875rem', fontWeight: 500 }}
              >
                Cancel
              </button>
              <button
                onClick={handleBulkCheckIn}
                disabled={bulkLoading}
                style={{ padding: '0.6rem 1.4rem', borderRadius: 8, border: 'none', background: '#2e7d32', color: '#fff', fontWeight: 500, cursor: bulkLoading ? 'not-allowed' : 'pointer', fontFamily: "'Inter', sans-serif", fontSize: '0.875rem', display: 'flex', alignItems: 'center', gap: 6 }}
              >
                {bulkLoading
                  ? <><Loader2 size={14} style={{ animation: 'spin 1s linear infinite' }} /> Processing {selectedGuests.length}...</>
                  : <><ArrowRight size={14} /> Confirm Check-In ({selectedGuests.length})</>
                }
              </button>
            </div>
          </div>
        </div>
      )}

      <style>{`@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }`}</style>
    </div>
  );
}
