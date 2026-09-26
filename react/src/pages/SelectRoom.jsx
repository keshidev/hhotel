import React, { useState, useEffect, useMemo, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  ChevronLeft, ChevronRight, Users, Bed, X, AlertTriangle,
  Wifi, Tv, Wind, Coffee, Refrigerator,
  Bath, Shield, Phone, Star, Thermometer,
  Utensils, ShowerHead, Dumbbell, Car, Eye, SlidersHorizontal,
  Trash2,
} from 'lucide-react';
import Button from '../components/Button';
import ConfirmDialog from '../components/ConfirmDialog';
import RoomDetailsModal from '../components/RoomDetailsModal';
import BookingProgress from '../components/BookingProgress';
import { calculateBookingEstimate } from '../utils/bookingPricing';
import { formatCurrency } from '../utils/currency';
import { beginNewBooking, persistBookingCart, restoreBookingCartToSession } from '../utils/bookingCart';
import clientBookingService from '../services/client/clientBookingService';
import './SelectRoom.css';
import { useCms } from '../context/CmsContext';

// â”€â”€ Constants
const CLIENT_API_BASE =
  (import.meta.env.VITE_API_URL || 'http://localhost:8000/api') + '/client';

const MONTHS     = ['January','February','March','April','May','June',
                    'July','August','September','October','November','December'];
const DAY_LABELS = ['Su','Mo','Tu','We','Th','Fr','Sa'];
const ROOM_FILTER_TYPES = ['executive_suite','deluxe','superior_twin','superior_queen','premier'];

const AMENITY_ICONS = {
  'wifi':             <Wifi size={14} />,
  'tv':               <Tv size={14} />,
  'air conditioning': <Wind size={14} />,
  'ac':               <Wind size={14} />,
  'coffee maker':     <Coffee size={14} />,
  'coffee':           <Coffee size={14} />,
  'mini bar':         <Refrigerator size={14} />,
  'minibar':          <Refrigerator size={14} />,
  'refrigerator':     <Refrigerator size={14} />,
  'fridge':           <Refrigerator size={14} />,
  'bathtub':          <Bath size={14} />,
  'bath':             <Bath size={14} />,
  'safe':             <Shield size={14} />,
  'room service':     <Phone size={14} />,
  'balcony':          <Eye size={14} />,
  'hair dryer':       <Thermometer size={14} />,
  'restaurant':       <Utensils size={14} />,
  'gym':              <Dumbbell size={14} />,
  'parking':          <Car size={14} />,
  'shower':           <ShowerHead size={14} />,
};

const ROOM_TYPE_LABELS = {
  executive_suite: 'Executive Suite',
  deluxe:          'Deluxe',
  superior_twin:   'Superior Twin',
  superior_queen:  'Superior Queen',
  premier:         'Premier',
};

const HIDDEN_ROOM_TYPES = new Set(['family']);

// â”€â”€ Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const getAmenityIcon  = (name) => AMENITY_ICONS[name?.toLowerCase().trim()] ?? <Star size={14} />;
const formatRoomType  = (type) => ROOM_TYPE_LABELS[type?.toLowerCase()] ?? type;
// CMS-aware version used inside the component (see getRoomLabel in useCms)

const calculateNights = (checkIn, checkOut) =>
  Math.ceil(Math.abs(new Date(checkOut) - new Date(checkIn)) / (1000 * 60 * 60 * 24));

const formatDateToString = (d) => {
  const date = d instanceof Date ? d : new Date(d);
  return `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
};

const startOfDay = (d) => { const n = new Date(d); n.setHours(0,0,0,0); return n; };
const isSameDay  = (a, b) =>
  a.getFullYear()===b.getFullYear() && a.getMonth()===b.getMonth() && a.getDate()===b.getDate();

const getTomorrow = () => {
  const t = new Date(); t.setDate(t.getDate()+1); t.setHours(0,0,0,0); return t;
};

const ssGet = (key, fallback) => {
  try { const r = sessionStorage.getItem(key); return r!==null ? JSON.parse(r) : fallback; }
  catch { return fallback; }
};

const getAddonQuantity = (addon) => Math.max(1, parseInt(addon?.quantity ?? 1, 10) || 1);
const getAddonLineTotal = (addon) => {
  const explicitLineTotal = Number(addon?.line_total);
  if (Number.isFinite(explicitLineTotal)) return explicitLineTotal;
  return (Number(addon?.price) || 0) * getAddonQuantity(addon);
};

const fmtDisplay = (ds) => ds
  ? new Date(ds).toLocaleDateString('en-US', { weekday:'short', month:'short', day:'numeric', year:'numeric' })
  : '';

const fmtMobileDateRange = (checkIn, checkOut) => {
  if (!checkIn) return 'Select your dates';
  const start = new Date(checkIn);
  const startLabel = start.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  if (!checkOut) return `${startLabel} — Select check-out`;
  const end = new Date(checkOut);
  const endLabel = end.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  return `${startLabel} — ${endLabel}`;
};

// â”€â”€ RemoveRoomModal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const RemoveRoomModal = ({ room, onConfirm, onCancel }) => (
  <div
    style={{
      position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)',
      backdropFilter: 'blur(4px)', zIndex: 9999,
      display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '1rem',
    }}
    onClick={onCancel}
  >
    <div
      style={{
        background: '#fff', width: '100%', maxWidth: 420,
        border: '1px solid #e5e7eb',
        boxShadow: '0 20px 60px rgba(0,0,0,0.2)',
        fontFamily: 'inherit',
      }}
      onClick={e => e.stopPropagation()}
    >
      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '1.1rem 1.4rem', borderBottom: '1px solid #e5e7eb' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
          <span style={{ fontWeight: 700, fontSize: '0.95rem', color: '#1f2937' }}>Remove Room</span>
        </div>
        <button onClick={onCancel} style={{ background: 'none', border: '1px solid #e5e7eb', cursor: 'pointer', padding: '4px', display: 'flex', alignItems: 'center', color: '#6b7280' }}>
          <X size={16} />
        </button>
      </div>

      {/* Body */}
      <div style={{ padding: '1.4rem' }}>
        <p style={{ margin: '0 0 0.4rem', color: '#1f2937', fontSize: '0.92rem', lineHeight: 1.5 }}>
          Are you sure you want to remove <strong>{room?.name}</strong> from your cart?
        </p>
        <p style={{ margin: 0, color: '#6b7280', fontSize: '0.85rem' }}>
          Any add-ons selected for this room will also be removed.
        </p>
      </div>

      {/* Footer */}
      <div style={{ display: 'flex', gap: '0.75rem', justifyContent: 'flex-end', padding: '1rem 1.4rem', borderTop: '1px solid #e5e7eb', background: '#f9fafb' }}>
        <button
          onClick={onConfirm}
          style={{ padding: '0.55rem 1.2rem', border: '1px solid #1a4bcc', background: '#fff', color: '#1a4bcc', cursor: 'pointer', fontFamily: 'inherit', fontSize: '0.88rem', fontWeight: 700, display: 'flex', alignItems: 'center', gap: '0.4rem' }}
        >
          Remove
        </button>
        <button
          onClick={onCancel}
          style={{ padding: '0.55rem 1.2rem', border: '1px solid #1a4bcc', background: '#1a4bcc', cursor: 'pointer', fontFamily: 'inherit', fontSize: '0.88rem', fontWeight: 500, color: '#fff' }}
        >
          Cancel
        </button>
      </div>
    </div>
  </div>
);

// â”€â”€ CalendarMonth â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const CalendarMonth = ({
  year, month, checkIn, checkOut,
  hoverDate, selecting, onDayClick, onDayHover, availability,
}) => {
  const firstDay    = new Date(year, month, 1).getDay();
  const daysInMonth = new Date(year, month+1, 0).getDate();
  const tomorrow    = getTomorrow();

  const toDs        = (date) => formatDateToString(date);
  const isFullyBooked = (date) => availability[toDs(date)] === 'fully_booked';
  const isUnavailable = (date) => availability[toDs(date)] === 'unavailable';

  const ciDate   = checkIn  ? startOfDay(new Date(checkIn))  : null;
  const coDate   = checkOut ? startOfDay(new Date(checkOut)) : null;
  const hovDate  = hoverDate ? startOfDay(hoverDate) : null;
  const rangeEnd = coDate || (selecting==='checkout' ? hovDate : null);

  const cells = [];
  for (let i=0; i<firstDay; i++) cells.push(null);
  for (let d=1; d<=daysInMonth; d++) cells.push(new Date(year, month, d));

  const getClass = (date) => {
    if (!date) return '';
    const isPast      = date < tomorrow;
    const fullyBooked = isFullyBooked(date);
    const unavail     = isUnavailable(date);
    if (isPast || unavail) return 'cal-day past';

    const isCI        = ciDate && isSameDay(date, ciDate);
    const isCO        = coDate && isSameDay(date, coDate);
    const inRng       = ciDate && rangeEnd && date>ciDate && date<rangeEnd;
    const inRngBlocked = inRng && fullyBooked;
    const isHov       = hovDate && isSameDay(date, hovDate) && selecting==='checkout';

    let cls = 'cal-day';
    if (fullyBooked && !inRng) cls += ' fully-booked';
    if (isCI)              cls += ' checkin-day';
    if (isCO)              cls += ' checkout-day';
    if (inRng && !inRngBlocked) cls += ' in-range';
    if (inRngBlocked)      cls += ' in-range-blocked';
    if (isHov && !isCO)    cls += ' hover-day';
    return cls;
  };

  return (
    <div className="dual-cal-month">
      <div className="dual-cal-month-title">{MONTHS[month]} {year}</div>
      <div className="dual-cal-grid">
        {DAY_LABELS.map(d => <div key={d} className="dual-cal-day-name">{d}</div>)}
        {cells.map((date, i) => {
          if (!date) return <div key={i} className="cal-empty" />;
          const isPast      = date < tomorrow;
          const fullyBooked = isFullyBooked(date);
          const unavail     = isUnavailable(date);
          const inRng       = ciDate && coDate && date>ciDate && date<coDate;
          const blocked     = isPast || unavail || (fullyBooked && !inRng);

          return (
            <div
              key={i}
              className={getClass(date)}
              style={{ position:'relative' }}
              onClick={() => !blocked && onDayClick(date)}
              onMouseEnter={() => !blocked && onDayHover && onDayHover(date)}
            >
              {date.getDate()}
              {(fullyBooked || unavail) && !isPast && (
                <span className="cal-day-x">&times;</span>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
};

// â”€â”€ DatePickerDropdown â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const DatePickerDropdown = ({
  checkIn,
  checkOut,
  adults,
  children,
  childrenAges = [],
  promoInput,
  promoResult,
  promoError,
  onPromoChange,
  onPromoRemove,
  onApply,
  onClose,
  onPreviewChange,
}) => {
  const [localCI,   setLocalCI]   = useState(checkIn  || '');
  const [localCO,   setLocalCO]   = useState(checkOut || '');
  const [localAdults, setLocalAdults] = useState(adults);
  const [localChildren, setLocalChildren] = useState(children);
  const [localChildrenAges, setLocalChildrenAges] = useState(() => {
    const normalized = Array.isArray(childrenAges)
      ? childrenAges.map((age) => Math.max(1, Math.min(17, Number(age) || 1)))
      : [];
    if (normalized.length >= children) return normalized.slice(0, children);
    return [...normalized, ...Array.from({ length: Math.max(0, children - normalized.length) }, () => 7)];
  });
  const [selecting, setSelecting] = useState('checkin');
  const [hoverDate, setHoverDate] = useState(null);
  const [availability, setAvailability] = useState({});

  const initDate = checkIn ? new Date(checkIn) : new Date();
  const [viewYear,  setViewYear]  = useState(initDate.getFullYear());
  const [viewMonth, setViewMonth] = useState(initDate.getMonth());

  const nextDate  = new Date(viewYear, viewMonth+1, 1);
  const nextYear  = nextDate.getFullYear();
  const nextMonth = nextDate.getMonth();

  const loadMonth = async (year, month) => {
    try {
      const res  = await fetch(`${CLIENT_API_BASE}/rooms/availability-calendar?year=${year}&month=${month+1}`);
      const json = await res.json();
      if (json.success) setAvailability(prev => ({ ...prev, ...json.data }));
    } catch { /* silently ignore */ }
  };

  useEffect(() => {
    loadMonth(viewYear, viewMonth);
    loadMonth(nextYear, nextMonth);
  }, [viewYear, viewMonth, nextYear, nextMonth]);

  useEffect(() => {
    setLocalChildrenAges((previous) => {
      if (localChildren <= 0) return [];
      const next = [...previous].slice(0, localChildren);
      while (next.length < localChildren) next.push(7);
      return next.map((age) => Math.max(1, Math.min(17, Number(age) || 1)));
    });
  }, [localChildren]);

  const prevMonth = () => {
    const d = new Date(viewYear, viewMonth-1, 1);
    setViewYear(d.getFullYear()); setViewMonth(d.getMonth());
  };
  const goNext = () => {
    const d = new Date(viewYear, viewMonth+1, 1);
    setViewYear(d.getFullYear()); setViewMonth(d.getMonth());
  };

  const rangeHasBlockedDate = () => {
    if (!localCI || !localCO) return false;
    const cursor  = new Date(localCI);
    cursor.setDate(cursor.getDate()+1);
    const endDate = new Date(localCO);
    while (cursor < endDate) {
      const ds = formatDateToString(cursor);
      if (availability[ds]==='fully_booked' || availability[ds]==='unavailable') return true;
      cursor.setDate(cursor.getDate()+1);
    }
    return false;
  };

  const isRangeBlocked = rangeHasBlockedDate();

  const handleDayClick = (date) => {
    const ds       = formatDateToString(date);
    const tomorrow = getTomorrow();
    if (date < tomorrow) return;
    if (availability[ds]==='fully_booked' || availability[ds]==='unavailable') return;

    if (selecting === 'checkin') {
      setLocalCI(ds); setLocalCO('');
      setSelecting('checkout'); setHoverDate(null);
      onPreviewChange?.(ds, null);
    } else {
      if (localCI && date <= new Date(localCI)) {
        setLocalCI(ds); setLocalCO('');
        setSelecting('checkout'); setHoverDate(null);
        onPreviewChange?.(ds, null);
      } else {
        setLocalCO(ds); setSelecting(null); setHoverDate(null);
        onPreviewChange?.(localCI, ds);
      }
    }
  };

  const nights   = localCI && localCO ? calculateNights(localCI, localCO) : 0;
  const canApply = !!(localCI && localCO) && !isRangeBlocked;

  return (
    <div className="booking-dropdown date-dropdown" onClick={e => e.stopPropagation()}>
      <div className="date-dropdown-instruction">
        <span>
          {isRangeBlocked
            ? <span className="cal-range-blocked-warning">Warning: A date within your stay is not available. Please choose different dates.</span>
            : selecting==='checkin'  ? '\u2193 Select your check-in date'
            : selecting==='checkout' ? '\u2193 Now select your check-out date'
            : nights > 0            ? `\u2713 ${nights} night${nights!==1?'s':''} selected \u2014 review your search details`
            : '\u2713 Both dates selected \u2014 review your search details'}
        </span>
        <button type="button" className="dropdown-close-btn" onClick={onClose} aria-label="Close calendar"><X size={18} /></button>
      </div>
      <div className="dual-cal-wrapper">
        <button className="dual-cal-nav-btn" onClick={prevMonth}><ChevronLeft size={16} /></button>
        <div className="dual-cal-months">
          <CalendarMonth year={viewYear} month={viewMonth} checkIn={localCI} checkOut={localCO} hoverDate={hoverDate} selecting={selecting} onDayClick={handleDayClick} onDayHover={setHoverDate} availability={availability} />
          <div className="dual-cal-divider" />
          <CalendarMonth year={nextYear} month={nextMonth} checkIn={localCI} checkOut={localCO} hoverDate={hoverDate} selecting={selecting} onDayClick={handleDayClick} onDayHover={setHoverDate} availability={availability} />
        </div>
        <button className="dual-cal-nav-btn" onClick={goNext}><ChevronRight size={16} /></button>
      </div>
      <div className="cal-legend">
        <div className="cal-legend-item"><div className="cal-legend-box legend-selected"/>Selected</div>
        <div className="cal-legend-item"><div className="cal-legend-box legend-range"/>In range</div>
        <div className="cal-legend-item"><div className="cal-legend-box legend-booked"/>Fully booked</div>
        <div className="cal-legend-item"><div className="cal-legend-box legend-unavail"/>Unavailable</div>
      </div>
      <div className="mobile-search-extras">
        <section className="mobile-search-section" aria-labelledby="mobile-search-guests-title">
          <h3 id="mobile-search-guests-title">Guests</h3>
          <div className="guest-row">
            <div>
              <div className="guest-row-label">Adults</div>
              <div className="guest-row-sub">Ages 13 or above</div>
            </div>
            <div className="guest-row-controls">
              <button type="button" className="guest-btn" onClick={() => setLocalAdults(value => Math.max(1, value - 1))} disabled={localAdults <= 1}>−</button>
              <span className="guest-val">{localAdults}</span>
              <button type="button" className="guest-btn" onClick={() => setLocalAdults(value => Math.min(10, value + 1))} disabled={localAdults >= 10}>+</button>
            </div>
          </div>
          <div className="guest-row">
            <div>
              <div className="guest-row-label">Children</div>
              <div className="guest-row-sub">Ages 1–17</div>
            </div>
            <div className="guest-row-controls">
              <button type="button" className="guest-btn" onClick={() => setLocalChildren(value => Math.max(0, value - 1))} disabled={localChildren <= 0}>−</button>
              <span className="guest-val">{localChildren}</span>
              <button type="button" className="guest-btn" onClick={() => setLocalChildren(value => Math.min(10, value + 1))} disabled={localChildren >= 10}>+</button>
            </div>
          </div>
          {localChildren > 0 && (
            <div className="guest-children-ages">
              <div className="guest-children-policy">
                Enter each child&apos;s age so room occupancy is calculated correctly.
              </div>
              <div className="guest-children-ages-grid">
                {Array.from({ length: localChildren }).map((_, index) => (
                  <label key={`mobile-child-age-${index}`} className="guest-child-age-field">
                    Child {index + 1} Age
                    <input
                      type="number"
                      min={1}
                      max={17}
                      value={localChildrenAges[index] ?? 7}
                      onChange={(event) => {
                        const value = Math.max(1, Math.min(17, Number(event.target.value) || 1));
                        setLocalChildrenAges((previous) => {
                          const next = [...previous];
                          next[index] = value;
                          return next;
                        });
                      }}
                    />
                  </label>
                ))}
              </div>
            </div>
          )}
        </section>

        <section className="mobile-search-section" aria-labelledby="mobile-search-code-title">
          <h3 id="mobile-search-code-title">Special Code or Rate</h3>
          {promoResult?.valid ? (
            <div className="mobile-search-code-applied">
              <span>{promoInput} applied</span>
              <button type="button" onClick={onPromoRemove}>Remove</button>
            </div>
          ) : (
            <input
              type="text"
              className="mobile-search-code-input"
              placeholder="Enter promo or discount code"
              value={promoInput}
              onChange={(event) => onPromoChange(event.target.value.toUpperCase())}
            />
          )}
          {promoError && <p className="promo-error">{promoError}</p>}
          {!promoResult?.valid && promoInput && (
            <p className="mobile-search-code-note">Your code will be validated after you select a room.</p>
          )}
        </section>
      </div>
      <div className="date-dropdown-footer">
        <button className="date-footer-clear" onClick={() => { setLocalCI(''); setLocalCO(''); setSelecting('checkin'); setHoverDate(null); onPreviewChange?.(null, null); }}>
          Clear dates
        </button>
        <button className="date-footer-apply" disabled={!canApply} onClick={() => canApply && onApply(localCI, localCO, localAdults, localChildren, localChildrenAges, promoInput)}>
          <span className="date-apply-desktop-label">Apply</span>
          <span className="date-apply-mobile-label">Search</span>
        </button>
      </div>
    </div>
  );
};

// â”€â”€ GuestPickerDropdown â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const GuestPickerDropdown = ({ adults, children, childrenAges = [], onApply, onClose }) => {
  const [localAdults, setLocalAdults] = useState(adults);
  const [localChildren, setLocalChildren] = useState(children);
  const [localChildrenAges, setLocalChildrenAges] = useState(() => {
    const normalized = Array.isArray(childrenAges)
      ? childrenAges.map((age) => Math.max(1, Math.min(17, Number(age) || 1)))
      : [];
    if (normalized.length >= children) return normalized.slice(0, children);
    return [...normalized, ...Array.from({ length: Math.max(0, children - normalized.length) }, () => 7)];
  });

  useEffect(() => {
    setLocalChildrenAges((prev) => {
      if (localChildren <= 0) return [];
      const next = [...prev].slice(0, localChildren);
      while (next.length < localChildren) next.push(7);
      return next.map((age) => Math.max(1, Math.min(17, Number(age) || 1)));
    });
  }, [localChildren]);

  const Counter = ({ label, sub, value, min, max, onChange }) => (
    <div className="guest-row">
      <div>
        <div className="guest-row-label">{label}</div>
        <div className="guest-row-sub">{sub}</div>
      </div>
      <div className="guest-row-controls">
        <button className="guest-btn" onClick={() => onChange(Math.max(min, value - 1))} disabled={value <= min}>-</button>
        <span className="guest-val">{value}</span>
        <button className="guest-btn" onClick={() => onChange(Math.min(max, value + 1))} disabled={value >= max}>+</button>
      </div>
    </div>
  );

  return (
    <div className="booking-dropdown guest-dropdown" onClick={(e) => e.stopPropagation()}>
      <div className="guest-dropdown-header">
        <span className="guest-dropdown-title">Guests</span>
        <button className="dropdown-close-btn" onClick={onClose}><X size={16} /></button>
      </div>
      <div className="guest-rows">
        <Counter label="Adults" sub="Ages 13 or above" value={localAdults} min={1} max={10} onChange={setLocalAdults} />
        <Counter label="Children" sub="Ages 1-17" value={localChildren} min={0} max={10} onChange={setLocalChildren} />
        {localChildren > 0 && (
          <div className="guest-children-ages">
            <div className="guest-children-policy">
              Children aged 1–7 stay free (maximum 2). Children aged 8 and above are counted as adults for room occupancy purposes.
            </div>
            <div className="guest-children-ages-grid">
              {Array.from({ length: localChildren }).map((_, index) => (
                <label key={`child-age-${index}`} className="guest-child-age-field">
                  Child {index + 1} Age
                  <input
                    type="number"
                    min={1}
                    max={17}
                    required
                    value={localChildrenAges[index] ?? 7}
                    onChange={(event) => {
                      const value = Math.max(1, Math.min(17, Number(event.target.value) || 1));
                      setLocalChildrenAges((prev) => {
                        const next = [...prev];
                        next[index] = value;
                        return next;
                      });
                    }}
                  />
                </label>
              ))}
            </div>
          </div>
        )}
      </div>
      <div className="guest-dropdown-footer">
        <button className="date-footer-clear" onClick={() => { setLocalAdults(2); setLocalChildren(0); setLocalChildrenAges([]); }}>Reset</button>
        <button className="date-footer-apply" onClick={() => onApply(localAdults, localChildren, localChildrenAges)}>Apply</button>
      </div>
    </div>
  );
};
// â”€â”€ Main Component â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const SelectRoom = () => {
  const { getRoomLabel, getRoomContent, taxRate, downpaymentRate } = useCms();
  const navigate = useNavigate();

  const [bookingData,    setBookingData]    = useState(null);
  const [selectedRooms,  setSelectedRooms]  = useState([]);
  const [roomAddons,     setRoomAddons]     = useState({});
  const [availableRooms, setAvailableRooms] = useState([]);
  const [loading,        setLoading]        = useState(true);
  const [error,          setError]          = useState(null);
  const [errorCode,      setErrorCode]      = useState(null);
  const [activeFilter,   setActiveFilter]   = useState('all');
  const [sortOrder,      setSortOrder]      = useState('recommended');
  const [filterSheetOpen, setFilterSheetOpen] = useState(false);
  const [draftRoomFilter, setDraftRoomFilter] = useState('all');
  const [draftSortOrder, setDraftSortOrder] = useState('recommended');
  const [modalRoom,      setModalRoom]      = useState(null);
  const [roomsUnavailable, setRoomsUnavailable] = useState(false);

  // â”€â”€ Remove confirmation modal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  const [removeTarget, setRemoveTarget] = useState(null); // room object to confirm removal

  const [openPicker, setOpenPicker] = useState(null);
  const summaryRef  = useRef(null);

  const [previewCI, setPreviewCI] = useState(null);
  const [previewCO, setPreviewCO] = useState(null);

  const [promoOpen,    setPromoOpen]    = useState(false);
  const [promoInput,   setPromoInput]   = useState('');
  const [promoResult,  setPromoResult]  = useState(null);
  const [promoLoading, setPromoLoading] = useState(false);
  const [promoError,   setPromoError]   = useState('');

  useEffect(() => {
    const handler = (e) => {
      if (summaryRef.current && !summaryRef.current.contains(e.target)) {
        setOpenPicker(null);
        setPreviewCI(null);
        setPreviewCO(null);
      }
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, []);

  useEffect(() => {
    restoreBookingCartToSession();
    setRoomAddons(ssGet('roomAddons', {}));

    const savedPromoCode   = sessionStorage.getItem('promoCode');
    const savedPromoResult = ssGet('promoResult', null);
    if (savedPromoCode)   setPromoInput(savedPromoCode);
    if (savedPromoResult) setPromoResult(savedPromoResult);

    const stored      = sessionStorage.getItem('bookingData');
    const roomsStored = sessionStorage.getItem('selectedRooms');

    if (stored) {
      const booking = JSON.parse(stored);
      if (!Array.isArray(booking.childrenAges)) {
        booking.childrenAges = Array.from({ length: Math.max(0, Number(booking.children) || 0) }, () => 7);
      }
      setBookingData(booking);

      const parsedRooms = roomsStored ? JSON.parse(roomsStored) : [];
      setSelectedRooms(parsedRooms);

      if (savedPromoCode && parsedRooms.length > 0) {
        revalidatePromo(parsedRooms);
      }

      fetchAvailableRooms(booking);
    } else {
      const t  = new Date(); t.setHours(0,0,0,0);
      const ci = new Date(t); ci.setDate(ci.getDate()+1);
      const co = new Date(t); co.setDate(co.getDate()+2);
      const fallback = {
        checkIn:  formatDateToString(ci),
        checkOut: formatDateToString(co),
        adults: 2, children: 0, childrenAges: [],
      };
      beginNewBooking(fallback);
      sessionStorage.setItem('selectedRooms', JSON.stringify([]));
      setBookingData(fallback);
      setSelectedRooms([]);
      fetchAvailableRooms(fallback);
    }
  }, []);

  useEffect(() => {
    if (!filterSheetOpen) return undefined;
    const previousOverflow = document.body.style.overflow;
    const handleKeyDown = (event) => {
      if (event.key === 'Escape') setFilterSheetOpen(false);
    };
    const handleResize = () => {
      if (!window.matchMedia('(max-width: 600px)').matches) setFilterSheetOpen(false);
    };
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', handleKeyDown);
    window.addEventListener('resize', handleResize);
    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener('keydown', handleKeyDown);
      window.removeEventListener('resize', handleResize);
    };
  }, [filterSheetOpen]);

  const fetchAvailableRooms = async (booking) => {
    try {
      setLoading(true);
      const response = await clientBookingService.getAvailableRooms(
        formatDateToString(booking.checkIn),
        formatDateToString(booking.checkOut),
        (booking.adults||2) + (booking.children||0)
      );
      if (response.success) {
        const liveRooms = response.data;
        const typeKey = (value) => String(value || '').toLowerCase().trim();
        const liveRoomsByType = liveRooms.reduce((acc, room) => {
          const key = typeKey(room.room_type);
          if (!key) return acc;
          if (!acc[key]) acc[key] = [];
          acc[key].push(room);
          return acc;
        }, {});
        const representativeByType = Object.fromEntries(
          Object.entries(liveRoomsByType).map(([key, rooms]) => [
            key,
            [...rooms].sort(
              (a, b) => Number(a.price_per_night || a.price || 0) - Number(b.price_per_night || b.price || 0)
            )[0],
          ])
        );
        setAvailableRooms(liveRooms);
        setError(null);
        setErrorCode(null);
        // Read the latest cart after the availability request completes.
        const prev = ssGet('selectedRooms', []);
        const typeUsage = {};
        const valid = prev.flatMap((room) => {
          const selectedTypeKey = typeKey(room.requested_room_type || room.room_type);
          if (!selectedTypeKey) return [];

          const availableCount = liveRoomsByType[selectedTypeKey]?.length || 0;
          const usedCount = typeUsage[selectedTypeKey] || 0;
          if (usedCount >= availableCount) return [];

          typeUsage[selectedTypeKey] = usedCount + 1;
          const representative = representativeByType[selectedTypeKey] || liveRoomsByType[selectedTypeKey]?.[0];
          if (!representative) return [];

          return [{
            ...room,
            id: representative.id,
            sourceRoomId: representative.id,
            room_type: representative.room_type || room.room_type,
            requested_room_type: representative.room_type || room.requested_room_type || room.room_type,
            name: `${formatRoomType(representative.room_type || room.room_type)}`,
            description: room.description || representative.description || 'Comfortable room with modern amenities',
            price: parseFloat(representative.price_per_night || representative.price || room.price || 0),
          }];
        });

        if (JSON.stringify(valid) !== JSON.stringify(prev)) {
          sessionStorage.setItem('selectedRooms', JSON.stringify(valid));
          const vIds   = new Set(valid.map(r => String(r.roomId)));
          const pruned = Object.fromEntries(Object.entries(ssGet('roomAddons',{})).filter(([k]) => vIds.has(k)));
          sessionStorage.setItem('roomAddons', JSON.stringify(pruned));
          persistBookingCart({ bookingData: booking, selectedRooms: valid, roomAddons: pruned });
          setRoomAddons(pruned);
          if (valid.length < prev.length) setRoomsUnavailable(true);
        }
        setSelectedRooms(valid);
      }
    } catch (err) {
      const msgs = err.response?.data?.errors ? Object.values(err.response.data.errors).flat() : null;
      const responseCode = err.response?.data?.error_code || null;
      setErrorCode(responseCode);
      setError(
        responseCode === 'BOOKING_MAINTENANCE'
          ? err.response?.data?.message
          : (msgs ? 'Validation error: '+msgs.join(', ') : 'Failed to load available rooms. Please try again.')
      );
    } finally {
      setLoading(false);
    }
  };

  const handleDateApply = (
    checkIn,
    checkOut,
    adults = bookingData.adults,
    children = bookingData.children,
    childrenAges = bookingData.childrenAges || [],
    code = promoInput,
  ) => {
    const normalizedChildren = Math.max(0, Number(children) || 0);
    const normalizedAges = Array.isArray(childrenAges)
      ? childrenAges
        .map((age) => Math.max(1, Math.min(17, Number(age) || 1)))
        .slice(0, normalizedChildren)
      : [];
    const normalizedCode = String(code || '').trim().toUpperCase();
    const updated = {
      ...bookingData,
      checkIn,
      checkOut,
      adults: Math.max(1, Number(adults) || 1),
      children: normalizedChildren,
      childrenAges: normalizedAges,
    };
    setBookingData(updated);
    sessionStorage.setItem('bookingData', JSON.stringify(updated));
    setPromoInput(normalizedCode);
    setPromoResult(null);
    setPromoError('');
    sessionStorage.removeItem('promoResult');
    if (normalizedCode) sessionStorage.setItem('promoCode', normalizedCode);
    else sessionStorage.removeItem('promoCode');
    setOpenPicker(null);
    setPreviewCI(null);
    setPreviewCO(null);
    setSelectedRooms([]); setRoomAddons({});
    sessionStorage.setItem('selectedRooms', JSON.stringify([]));
    sessionStorage.setItem('roomAddons', JSON.stringify({}));
    persistBookingCart({ bookingData: updated, selectedRooms: [], roomAddons: {}, promoCode: normalizedCode, promoResult: null });
    fetchAvailableRooms(updated);
  };

  const handleGuestApply = (adults, children, childrenAges = []) => {
    const normalizedAges = Array.isArray(childrenAges)
      ? childrenAges.map((age) => Math.max(1, Math.min(17, Number(age) || 1))).slice(0, Math.max(0, children))
      : [];
    const updated = { ...bookingData, adults, children, childrenAges: normalizedAges };
    setBookingData(updated);
    sessionStorage.setItem('bookingData', JSON.stringify(updated));
    setOpenPicker(null);
    setSelectedRooms([]); setRoomAddons({});
    sessionStorage.setItem('selectedRooms', JSON.stringify([]));
    sessionStorage.setItem('roomAddons', JSON.stringify({}));
    persistBookingCart({ bookingData: updated, selectedRooms: [], roomAddons: {} });
    fetchAvailableRooms(updated);
  };

  const addRoom = async (room) => {
    const cartLineId = `${room.id}-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const cmsRoomContent = getRoomContent(room.room_type);
    const roomForCart = {
      ...room,
      roomId:      cartLineId,
      id:          room.id,
      sourceRoomId: room.id,
      name:        `${formatRoomType(room.room_type)}`,
      description: room.description || 'Comfortable room with modern amenities',
      price:       parseFloat(room.price_per_night || room.price || 0),
      requested_room_type: room.room_type,
      image: cmsRoomContent.image || (room.image_urls?.length > 0 ? room.image_urls[0] : ''),
    };
    const updated = [...selectedRooms, roomForCart];
    setSelectedRooms(updated);
    sessionStorage.setItem('selectedRooms', JSON.stringify(updated));
    sessionStorage.setItem('activeAddonRoomId', String(roomForCart.roomId));
    persistBookingCart({ bookingData, selectedRooms: updated, roomAddons });
    if (promoInput.trim()) await revalidatePromo(updated);
    navigate('/add-ons');
  };

  // â”€â”€ Remove with confirmation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  const confirmRemoveRoom = (room) => {
    setRemoveTarget(room);
  };

  const handleRemoveConfirmed = () => {
    if (!removeTarget) return;
    const updated = selectedRooms.filter(r => r.roomId !== removeTarget.roomId);
    setSelectedRooms(updated);
    sessionStorage.setItem('selectedRooms', JSON.stringify(updated));
    setRoomAddons(prev => {
      const next = { ...prev };
      delete next[removeTarget.roomId];
      sessionStorage.setItem('roomAddons', JSON.stringify(next));
      persistBookingCart({ bookingData, selectedRooms: updated, roomAddons: next });
      return next;
    });
    setRemoveTarget(null);
  };

  const handleRemoveCancelled = () => {
    setRemoveTarget(null);
  };

  const handleCheckout = () => {
    if (selectedRooms.length > 0) {
      navigate('/guest-details');
    }
  };

  // â”€â”€ Promo â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  const promoEndpoint = () => `${CLIENT_API_BASE}/promo-codes/validate`;

  const buildPromoBody = (rooms, code) => {
    const bd = ssGet('bookingData', {});
    const n  = calculateNights(bd.checkIn, bd.checkOut);
    const currentAddons  = ssGet('roomAddons', {});
    const addonsTotal    = Object.values(currentAddons).reduce(
      (s, list) => s + (Array.isArray(list) ? list.reduce((a, x) => a + getAddonLineTotal(x), 0) : 0), 0
    );
    const roomsTotal = rooms.reduce((s, r) => s + parseFloat(r.price) * n, 0);
    return {
      code,
      guest_email:    sessionStorage.getItem('bookingEmail') || 'guest@placeholder.com',
      check_in:       formatDateToString(bd.checkIn),
      check_out:      formatDateToString(bd.checkOut),
      subtotal:       roomsTotal + addonsTotal,
      booking_source: 'online',
    };
  };

  const applyPromo = async () => {
    if (!promoInput.trim()) { setPromoError('Please enter a promo code.'); return; }
    setPromoLoading(true); setPromoError(''); setPromoResult(null);
    try {
      const res  = await fetch(promoEndpoint(), {
        method:'POST', headers:{'Content-Type':'application/json',Accept:'application/json'},
        body: JSON.stringify(buildPromoBody(selectedRooms, promoInput.trim().toUpperCase())),
      });
      const data = await res.json();
      if (data.valid) {
        setPromoResult(data);
        sessionStorage.setItem('promoCode',   promoInput.trim().toUpperCase());
        sessionStorage.setItem('promoResult', JSON.stringify(data));
        persistBookingCart({ selectedRooms, roomAddons, promoCode: promoInput.trim().toUpperCase(), promoResult: data });
      } else {
        setPromoError(data.message || 'Invalid promo code.');
      }
    } catch { setPromoError('Could not validate promo code.'); }
    finally  { setPromoLoading(false); }
  };

  const revalidatePromo = async (rooms) => {
    const code = sessionStorage.getItem('promoCode');
    if (!code || rooms.length===0) return;
    try {
      const res  = await fetch(promoEndpoint(), {
        method:'POST', headers:{'Content-Type':'application/json',Accept:'application/json'},
        body: JSON.stringify(buildPromoBody(rooms, code)),
      });
      const data = await res.json();
      if (data.valid) {
        setPromoResult(data);
        sessionStorage.setItem('promoResult', JSON.stringify(data));
        persistBookingCart({ selectedRooms: rooms, promoCode: code, promoResult: data });
      }
      else {
        setPromoResult(null); setPromoInput(''); sessionStorage.removeItem('promoCode'); sessionStorage.removeItem('promoResult');
        persistBookingCart({ selectedRooms: rooms, promoCode: '', promoResult: null });
      }
    } catch {}
  };

  const removePromo = () => {
    setPromoResult(null); setPromoInput(''); setPromoError('');
    sessionStorage.removeItem('promoCode');
    sessionStorage.removeItem('promoResult');
    persistBookingCart({ selectedRooms, roomAddons, promoCode: '', promoResult: null });
  };

  // â”€â”€ Derived totals â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  const nights      = bookingData ? calculateNights(bookingData.checkIn, bookingData.checkOut) : 0;
  const roomsTotal  = selectedRooms.reduce((s,r) => s+parseFloat(r.price)*nights, 0);
  const addonsTotal = Object.values(roomAddons).reduce(
    (s,list) => s+(Array.isArray(list)?list.reduce((a,x)=>a+getAddonLineTotal(x),0):0), 0
  );
  const discount = promoResult?.valid ? (promoResult.discount_amount||0) : 0;
  const pricing = calculateBookingEstimate({ roomsSubtotal: roomsTotal, addonsTotal, discount, taxRate });
  const total = pricing.total;
  const downpaymentAmount = Math.round(total * downpaymentRate * 100) / 100;
  const remainingBalance = Math.round(Math.max(0, total - downpaymentAmount) * 100) / 100;
  const downpaymentPercentage = new Intl.NumberFormat('en-US', {
    maximumFractionDigits: 2,
  }).format(downpaymentRate * 100);
  const totalGuests = Math.max(
    1,
    Number(bookingData?.adults || 0) + Number(bookingData?.children || 0)
  );
  const visibleRooms = availableRooms.filter(
    (room) => (
      !HIDDEN_ROOM_TYPES.has(String(room.room_type || '').toLowerCase())
      && Math.max(0, Number(room.capacity) || 0) >= totalGuests
    )
  );
  const roomTypeInventory = useMemo(() => {
    const grouped = {};
    visibleRooms.forEach((room) => {
      const key = String(room.room_type || '').toLowerCase();
      if (!key) return;
      if (!grouped[key]) grouped[key] = [];
      grouped[key].push(room);
    });
    return Object.values(grouped).map((items) => {
      const sorted = [...items].sort(
        (a, b) => Number(a.price_per_night || a.price || 0) - Number(b.price_per_night || b.price || 0)
      );
      return {
        room_type: sorted[0]?.room_type,
        rooms: sorted,
      };
    });
  }, [visibleRooms]);
  const roomTypeCards = useMemo(() => (
    roomTypeInventory.map(({ room_type, rooms }) => ({
      ...rooms[0],
      room_type,
      availability_count: rooms.length,
    }))
  ), [roomTypeInventory]);
  const roomTypeOptions = useMemo(() => {
    const preferredOrder = new Map(ROOM_FILTER_TYPES.map((type, index) => [type, index]));
    return [...roomTypeInventory].sort((a, b) => {
      const aKey = String(a.room_type || '').toLowerCase();
      const bKey = String(b.room_type || '').toLowerCase();
      const aRank = preferredOrder.get(aKey) ?? ROOM_FILTER_TYPES.length;
      const bRank = preferredOrder.get(bKey) ?? ROOM_FILTER_TYPES.length;
      return aRank - bRank || getRoomLabel(a.room_type).localeCompare(getRoomLabel(b.room_type));
    });
  }, [getRoomLabel, roomTypeInventory]);
  const filteredRooms = useMemo(() => {
    const matchingRooms = activeFilter === 'all'
      ? [...roomTypeCards]
      : roomTypeCards.filter(
        (room) => String(room.room_type || '').toLowerCase() === activeFilter.toLowerCase()
      );

    if (sortOrder === 'price-low') {
      return matchingRooms.sort(
        (a, b) => Number(a.price_per_night || a.price || 0) - Number(b.price_per_night || b.price || 0)
      );
    }
    if (sortOrder === 'price-high') {
      return matchingRooms.sort(
        (a, b) => Number(b.price_per_night || b.price || 0) - Number(a.price_per_night || a.price || 0)
      );
    }
    return matchingRooms;
  }, [activeFilter, roomTypeCards, sortOrder]);
  const hasActiveRoomControls = activeFilter !== 'all' || sortOrder !== 'recommended';
  const resetRoomControls = () => {
    setActiveFilter('all');
    setSortOrder('recommended');
  };
  const openFilterSheet = () => {
    setDraftRoomFilter(activeFilter);
    setDraftSortOrder(sortOrder);
    setFilterSheetOpen(true);
  };
  const applyFilterSheet = () => {
    setActiveFilter(draftRoomFilter);
    setSortOrder(draftSortOrder);
    setFilterSheetOpen(false);
  };
  const draftMatchingRoomCount = draftRoomFilter === 'all'
    ? roomTypeCards.length
    : roomTypeCards.filter(
      (room) => String(room.room_type || '').toLowerCase() === draftRoomFilter.toLowerCase()
    ).length;

  if (!bookingData) return null;

  if (loading) return (
    <div className="select-room-page">
      <BookingProgress currentStep={2} />
      <div style={{textAlign:'center',padding:'3rem'}}><p>Loading available rooms...</p></div>
    </div>
  );

  if (error) return (
    <div className="select-room-page">
      <BookingProgress currentStep={2} />
      <div style={{textAlign:'center',padding:'3rem',color:errorCode === 'BOOKING_MAINTENANCE' ? '#0f172a' : 'red'}}>
        {errorCode === 'BOOKING_MAINTENANCE' && <h2>Online booking is temporarily unavailable</h2>}
        <p>{error}</p>
        {errorCode === 'BOOKING_MAINTENANCE' ? (
          <button onClick={() => navigate('/')} style={{marginTop:'1rem',padding:'0.5rem 1rem',cursor:'pointer'}}>Return Home</button>
        ) : (
          <button onClick={() => fetchAvailableRooms(bookingData)} style={{marginTop:'1rem',padding:'0.5rem 1rem',cursor:'pointer'}}>Retry</button>
        )}
      </div>
    </div>
  );

  const displayCI = (openPicker === 'date' && previewCI) ? previewCI : bookingData.checkIn;
  const displayCO = (openPicker === 'date' && previewCO) ? previewCO : bookingData.checkOut;

  return (
    <div className="select-room-page">
      <ConfirmDialog
        open={roomsUnavailable}
        title="Room availability changed"
        message="One or more rooms are no longer available and have been removed from your cart. Please review your remaining rooms before continuing."
        confirmLabel="Review Rooms"
        hideCancel
        onConfirm={() => setRoomsUnavailable(false)}
        onCancel={() => setRoomsUnavailable(false)}
      />
      <BookingProgress currentStep={2} />

      {/* Remove Room Confirmation Modal */}
      {removeTarget && (
        <RemoveRoomModal
          room={removeTarget}
          onConfirm={handleRemoveConfirmed}
          onCancel={handleRemoveCancelled}
        />
      )}

      {/* Hero Banner */}
      <section className="room-banner">
        <div className="banner-image">
          <img src="https://images.unsplash.com/photo-1664711942326-2c3351e215e6?w=600&auto=format&fit=crop&q=60" alt="Hotel Interior" />
          <div className="banner-overlay" />
        </div>
        <div className="banner-content">
          <div className="hotel-info-card">
            <h1 className="hotel-name">H+HOTEL</h1>
            <div className="hotel-details">
              <div className="hotel-detail-item">
                <svg className="detail-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>
                </svg>
                <span>One Nenita Place 89 Road 1 Bagong Pagasa, Quezon City, Philippines</span>
              </div>
              <div className="hotel-detail-item">
                <svg className="detail-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                </svg>
                <span>+63 917 809 9482</span>
              </div>
              <div className="hotel-detail-item">
                <svg className="detail-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                  <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                </svg>
                <a href="https://hhotelbooking.com" target="_blank" rel="noopener noreferrer" className="hotel-link">https://hhotelbooking.com</a>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* â”€â”€ Booking Summary Bar â”€â”€ */}
      <section className={`booking-summary-section ${openPicker === 'date' ? 'mobile-calendar-open' : ''}`} ref={summaryRef}>
        <div className="container">
          <div className="mobile-booking-summary" aria-label="Booking search details">
            <button
              type="button"
              className={`mobile-booking-summary-card ${openPicker==='date' ? 'picker-active' : ''}`}
              onClick={() => { setOpenPicker(p => p==='date' ? null : 'date'); setPromoOpen(false); }}
            >
              <span className="mobile-booking-summary-copy">
                <strong>{fmtMobileDateRange(displayCI, displayCO)}</strong>
                <span>{bookingData.adults} adult{bookingData.adults>1?'s':''}, {bookingData.children} child{bookingData.children!==1?'ren':''}</span>
              </span>
              <ChevronRight size={20} aria-hidden="true" />
            </button>
          </div>

          <div className="booking-summary-inline">
            <div className={`summary-inline-item summary-clickable ${openPicker==='date' ? 'picker-active' : ''}`} onClick={() => setOpenPicker(p => p==='date' ? null : 'date')}>
              <svg className="summary-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
              </svg>
              <div className="summary-inline-content">
                <span className="summary-inline-label">CHECK-IN</span>
                <span className={`summary-inline-value ${previewCI && openPicker==='date' ? 'preview-active' : ''}`}>{fmtDisplay(displayCI)}</span>
              </div>
            </div>

            <span className="summary-sep">{'\u2192'}</span>

            <div className={`summary-inline-item summary-clickable ${openPicker==='date' ? 'picker-active' : ''}`} onClick={() => setOpenPicker(p => p==='date' ? null : 'date')}>
              <svg className="summary-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
              </svg>
              <div className="summary-inline-content">
                <span className="summary-inline-label">CHECK-OUT</span>
                <span className={`summary-inline-value ${previewCO && openPicker==='date' ? 'preview-active' : ''}`}>
                  {displayCO ? fmtDisplay(displayCO) : <span style={{color:'#aaa',fontStyle:'italic'}}>Select date</span>}
                </span>
              </div>
            </div>

            <div className={`summary-inline-item summary-clickable ${openPicker==='guest' ? 'picker-active' : ''}`} onClick={() => setOpenPicker(p => p==='guest' ? null : 'guest')}>
              <svg className="summary-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
              </svg>
              <div className="summary-inline-content">
                <span className="summary-inline-label">GUESTS</span>
                <span className="summary-inline-value">
                  {bookingData.adults} adult{bookingData.adults>1?'s':''},{' '}
                  {bookingData.children} child{bookingData.children!==1?'ren':''}
                </span>
              </div>
            </div>

            <button className={`special-codes-btn ${promoResult?.valid ? 'promo-active' : ''}`} onClick={() => { setPromoOpen(p => !p); setOpenPicker(null); }}>
              {promoResult?.valid ? `${promoInput} Applied` : 'Special Codes or Rates'}
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" style={{transform:promoOpen?'rotate(180deg)':'none',transition:'transform 0.2s'}}>
                <polyline points="6 9 12 15 18 9"/>
              </svg>
            </button>
          </div>

          {openPicker==='date' && (
            <DatePickerDropdown
              checkIn={bookingData.checkIn} checkOut={bookingData.checkOut}
              adults={bookingData.adults}
              children={bookingData.children}
              childrenAges={bookingData.childrenAges || []}
              promoInput={promoInput}
              promoResult={promoResult}
              promoError={promoError}
              onPromoChange={(value) => { setPromoInput(value); setPromoError(''); }}
              onPromoRemove={removePromo}
              onApply={handleDateApply}
              onClose={() => { setOpenPicker(null); setPreviewCI(null); setPreviewCO(null); }}
              onPreviewChange={(ci, co) => { setPreviewCI(ci); setPreviewCO(co); }}
            />
          )}

          {openPicker==='guest' && (
            <GuestPickerDropdown
              adults={bookingData.adults}
              children={bookingData.children}
              childrenAges={bookingData.childrenAges || []}
              onApply={handleGuestApply}
              onClose={() => setOpenPicker(null)}
            />
          )}

          {promoOpen && (
            <div className="promo-dropdown">
              {promoResult?.valid ? (
                <div className="promo-dropdown-applied">
                  <div className="promo-dropdown-info">
                    <span className="promo-tag">{promoResult.code_label}</span>
                    <span className="promo-savings">{promoResult.savings_label}</span>
                  </div>
                  <button className="promo-remove-btn" onClick={removePromo}>Remove</button>
                </div>
              ) : (
                <div className="promo-dropdown-input">
                  <input type="text" className="promo-input" placeholder="Enter promo / discount code"
                    value={promoInput} autoFocus
                    onChange={e => { setPromoInput(e.target.value.toUpperCase()); setPromoError(''); }}
                    onKeyDown={e => e.key==='Enter' && applyPromo()}
                  />
                  <button className="promo-apply-btn" onClick={applyPromo} disabled={promoLoading}>
                    {promoLoading ? 'Checking...' : 'Apply'}
                  </button>
                </div>
              )}
              {promoError && <p className="promo-error">{promoError}</p>}
            </div>
          )}
        </div>
      </section>

      {/* Page Header */}
      <section className="page-header">
        <div className="container">
          <button className="back-btn" onClick={() => navigate(-1)}>
            <ChevronLeft size={20} /> SELECT ROOM
          </button>
        </div>
      </section>

      {/* Main Content */}
      <section className="select-room-content">
        <div className="container">
          <div className="content-layout">

            {/* Rooms Grid */}
            <div className="rooms-section">
              <div className="room-listing-header">
                <div className="room-listing-heading">
                  <h2 className="section-heading">Choose your room</h2>
                  <p>
                    {filteredRooms.length === roomTypeCards.length
                      ? `${roomTypeCards.length} room ${roomTypeCards.length === 1 ? 'type' : 'types'} available`
                      : `${filteredRooms.length} of ${roomTypeCards.length} room types shown`}
                  </p>
                </div>
                <button
                  type="button"
                  className={`mobile-room-filter-button ${hasActiveRoomControls ? 'is-active' : ''}`}
                  onClick={openFilterSheet}
                  aria-haspopup="dialog"
                >
                  <SlidersHorizontal size={20} aria-hidden="true" />
                  <span>Filters</span>
                  {hasActiveRoomControls && <span className="mobile-room-filter-count" aria-label="Active filters">{(activeFilter !== 'all' ? 1 : 0) + (sortOrder !== 'recommended' ? 1 : 0)}</span>}
                </button>
                <div className="room-listing-controls" aria-label="Room listing controls">
                  <label className="room-control-field">
                    <span>Room type</span>
                    <select value={activeFilter} onChange={(event) => setActiveFilter(event.target.value)}>
                      <option value="all">All room types</option>
                      {roomTypeOptions.map(({ room_type, rooms }) => (
                        <option key={room_type} value={room_type}>
                          {getRoomLabel(room_type)} ({rooms.length})
                        </option>
                      ))}
                    </select>
                  </label>
                  <label className="room-control-field">
                    <span>Sort by</span>
                    <select value={sortOrder} onChange={(event) => setSortOrder(event.target.value)}>
                      <option value="recommended">Recommended</option>
                      <option value="price-low">Price: low to high</option>
                      <option value="price-high">Price: high to low</option>
                    </select>
                  </label>
                  {hasActiveRoomControls && (
                    <button type="button" className="room-controls-reset" onClick={resetRoomControls}>
                      Reset
                    </button>
                  )}
                </div>
              </div>
              {filterSheetOpen && (
                <div className="room-filter-sheet-overlay" onClick={() => setFilterSheetOpen(false)}>
                  <section
                    className="room-filter-sheet"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="room-filter-sheet-title"
                    onClick={(event) => event.stopPropagation()}
                  >
                    <header className="room-filter-sheet-header">
                      <h2 id="room-filter-sheet-title">Filters</h2>
                      <button type="button" onClick={() => setFilterSheetOpen(false)} aria-label="Close filters">
                        <X size={24} />
                      </button>
                    </header>
                    <div className="room-filter-sheet-body">
                      <fieldset className="room-filter-group">
                        <legend>Room type</legend>
                        <label className="room-filter-option">
                          <input type="radio" name="mobile-room-type" value="all" checked={draftRoomFilter === 'all'} onChange={(event) => setDraftRoomFilter(event.target.value)} />
                          <span>All room types</span>
                        </label>
                        {roomTypeOptions.map(({ room_type, rooms }) => (
                          <label className="room-filter-option" key={room_type}>
                            <input type="radio" name="mobile-room-type" value={room_type} checked={draftRoomFilter === room_type} onChange={(event) => setDraftRoomFilter(event.target.value)} />
                            <span>{getRoomLabel(room_type)}</span>
                            <small>{rooms.length}</small>
                          </label>
                        ))}
                      </fieldset>
                      <fieldset className="room-filter-group">
                        <legend>Sort by</legend>
                        {[
                          ['recommended', 'Recommended'],
                          ['price-low', 'Lowest price'],
                          ['price-high', 'Highest price'],
                        ].map(([value, label]) => (
                          <label className="room-filter-option" key={value}>
                            <input type="radio" name="mobile-room-sort" value={value} checked={draftSortOrder === value} onChange={(event) => setDraftSortOrder(event.target.value)} />
                            <span>{label}</span>
                          </label>
                        ))}
                      </fieldset>
                      <button
                        type="button"
                        className="room-filter-sheet-clear"
                        onClick={() => { setDraftRoomFilter('all'); setDraftSortOrder('recommended'); }}
                      >
                        Clear all
                      </button>
                    </div>
                    <footer className="room-filter-sheet-footer">
                      <span><strong>{draftMatchingRoomCount}</strong> matching room {draftMatchingRoomCount === 1 ? 'type' : 'types'}</span>
                      <button type="button" onClick={applyFilterSheet}>Apply</button>
                    </footer>
                  </section>
                </div>
              )}
              {filteredRooms.length===0 ? (
                <div className="room-unavailable-notice" role="status">
                  <AlertTriangle size={30} aria-hidden="true" />
                  <div>
                    {roomTypeCards.length === 0 ? (
                      <>
                        <h3>No rooms can accommodate {totalGuests} guest{totalGuests === 1 ? '' : 's'}.</h3>
                        <p>Please reduce the number of guests or choose different dates.</p>
                        <button type="button" onClick={() => setOpenPicker('guest')}>Change Guests</button>
                      </>
                    ) : (
                      <>
                        <h3>No rooms match your filters.</h3>
                        <p>Clear the room type filter to see every available option.</p>
                        <button type="button" onClick={resetRoomControls}>Clear Filters</button>
                      </>
                    )}
                  </div>
                </div>
              ) : (
                <div className="rooms-grid">
                  {filteredRooms.map(room => {
                    const cmsRoomContent = getRoomContent(room.room_type);
                    const amenities = (() => {
                      if (!room.amenities) return [];
                      if (Array.isArray(room.amenities)) return room.amenities;
                      try { return JSON.parse(room.amenities); } catch { return []; }
                    })();
                    return (
                      <div key={room.id} className="room-card">
                        <div className="room-image">
                          <img src={cmsRoomContent.image || (room.image_urls?.length>0 ? room.image_urls[0] : 'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?w=800&h=600&fit=crop')} alt={room.room_type} />
                          <div className="room-capacity-badge"><Users size={14}/> Up to {room.capacity||2} Guests</div>
                        </div>
                        <div className="room-details">
                          <div className="room-info-top">
                            <h3 className="room-name">{getRoomLabel(room.room_type)}</h3>
                            <p className="room-meta">
                              {room.bed_type && <span>{room.bed_type}</span>}
                              {room.bed_type && <span className="room-meta-dot">&middot;</span>}
                              <span>Sleeps {room.capacity||2}</span>
                              <span className="room-meta-dot">&middot;</span>
                              <span>{room.availability_count || 1} available</span>
                              {room.size_sqm && <><span className="room-meta-dot">&middot;</span><span>{room.size_sqm} sq m</span></>}
                            </p>
                            <p className="room-description">{cmsRoomContent.description || room.description||'Lorem ipsum dolor sit amet, consectetur adipiscing elit.'}</p>
                            {amenities.length>0 && (
                              <button className="room-details-link" onClick={() => setModalRoom({...room,amenities})}>Room Details</button>
                            )}
                          </div>
                          <div className="room-info-bottom">
                            <hr className="room-card-divider"/>
                            <div className="room-footer">
                              <div className="room-deposit-info">
                                <div className="room-deposit-badge">
                                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                    <rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>
                                  </svg>
                                  {downpaymentPercentage}% Deposit Required
                                </div>
                                <p className="room-deposit-desc">Pay {downpaymentPercentage}% to confirm booking. Remaining balance payable at check-in.</p>
                              </div>
                              <div className="room-price-block" style={{display:'flex',flexDirection:'column',gap:'0.5rem',minWidth:'160px',width:'160px'}}>
                                <div className="room-price" style={{display:'flex',flexDirection:'column',alignItems:'flex-start'}}>
                                  <span className="price-amount">{formatCurrency(parseFloat(room.price_per_night||room.price||0))}</span>
                                  <span className="price-period">Per Night</span>
                                  <span className="price-tax-note">Including taxes and fees</span>
                                </div>
                                <Button variant="primary" size="md" onClick={() => addRoom(room)} style={{width:'100%',display:'block'}}>
                                  BOOK NOW
                                </Button>
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
            </div>

            {/* Room Details Modal */}
            {modalRoom && (
              <RoomDetailsModal
                room={modalRoom}
                label={getRoomLabel(modalRoom.room_type)}
                image={getRoomContent(modalRoom.room_type).image || modalRoom.image_urls?.[0]}
                getAmenityIcon={getAmenityIcon}
                onClose={() => setModalRoom(null)}
              />
            )}
            {/* Cart Sidebar */}
            <aside className="cart-sidebar">
              <div className="cart-card">
                <h2 className="cart-title">Your Cart: {selectedRooms.length} Item{selectedRooms.length!==1?'s':''}</h2>
                {selectedRooms.length>0 ? (
                  <>
                    <div className="cart-items">
                      {selectedRooms.map((room,idx) => (
                        <div key={room.roomId} className="cart-item">
                          <div className="item-label">ROOM {idx+1}</div>
                          <h4 className="item-name">{room.name}</h4>
                          <p className="item-description">{room.description}</p>
                          <p className="item-price">{formatCurrency(parseFloat(room.price) || 0)}</p>
                          <p className="item-duration">{nights} Night stay</p>
                          {roomAddons[room.roomId]?.length>0 && (
                            <div className="room-addons-list">
                              <div className="addons-label">Add-ons:</div>
                              {roomAddons[room.roomId].map(addon => (
                                <div key={addon.id} className="addon-item">
                                  <div className="addon-item-info">
                                    <span className="addon-item-name">{addon.name}</span>
                                    <span className="addon-item-meta">
                                      Qty {getAddonQuantity(addon)} x {formatCurrency(Number(addon?.price || 0))}
                                    </span>
                                    <span className="addon-item-price">{formatCurrency(getAddonLineTotal(addon))}</span>
                                  </div>
                                </div>
                              ))}
                            </div>
                          )}
                          <div className="item-actions">
                            <button
                              className="action-link remove"
                              onClick={(e) => { e.stopPropagation(); confirmRemoveRoom(room); }}
                            >
                              Remove
                            </button>
                          </div>
                        </div>
                      ))}
                    </div>
                    <div className="cart-summary">
                      <div className="summary-row total"><span>Total</span><span>{formatCurrency(total)}</span></div>
                      <div className="summary-row"><span>Required Down Payment (DP)</span><span>{formatCurrency(downpaymentAmount)}</span></div>
                      <div className="summary-row"><span>Balance at Check-in</span><span>{formatCurrency(remainingBalance)}</span></div>
                    </div>
                    <Button
                      variant="primary"
                      fullWidth
                      onClick={handleCheckout}
                    >
                      CONTINUE
                    </Button>
                  </>
                ) : (
                  <div className="cart-empty">
                    <Bed size={48} style={{opacity:0.3,marginBottom:'1rem'}}/>
                    <p>No rooms selected yet</p>
                  </div>
                )}
              </div>
            </aside>

          </div>
        </div>
      </section>
    </div>
  );
};

export default SelectRoom;




