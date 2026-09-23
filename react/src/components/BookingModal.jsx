import React, { useState, useEffect, useCallback } from 'react';
import { X, Calendar, Users, User, AlertCircle } from 'lucide-react';
import './BookingModal.css';
import Button from './Button';

// ─── API base (mirrors clientBookingService.js) ───────────────────────────────
const CLIENT_API_BASE =
  (import.meta.env.VITE_API_URL || 'http://localhost:8000/api') + '/client';

const MOBILE_CALENDAR_QUERY = '(max-width: 600px)';

const getVisibleMonthCount = () => (
  typeof window !== 'undefined' && window.matchMedia(MOBILE_CALENDAR_QUERY).matches ? 1 : 2
);

async function fetchMonthAvailability(year, month) {
  const res = await fetch(
    `${CLIENT_API_BASE}/rooms/availability-calendar?year=${year}&month=${month}`
  );
  if (!res.ok) throw new Error('Failed to fetch availability');
  const json = await res.json();
  return json.data; // { "2026-03-18": "available", "2026-03-19": "fully_booked", ... }
}
// ─────────────────────────────────────────────────────────────────────────────

const BookingModal = ({ isOpen, onClose, onProceed }) => {
  const getMonthStart = () => {
    const now = new Date();
    return new Date(now.getFullYear(), now.getMonth(), 1);
  };

  const [checkIn, setCheckIn]   = useState(null);
  const [checkOut, setCheckOut] = useState(null);
  const [adults, setAdults]     = useState(2);
  const [children, setChildren] = useState([]);
  const [currentMonth, setCurrentMonth] = useState(() => getMonthStart());
  const [visibleMonthCount, setVisibleMonthCount] = useState(getVisibleMonthCount);
  const [showChildPolicy, setShowChildPolicy] = useState(false);

  // availability[dateStr] = 'available' | 'fully_booked' | 'unavailable'
  const [availability, setAvailability]   = useState({});
  const [loadingMonths, setLoadingMonths] = useState(new Set());

  const totalGuests = adults + children.length;
  const maxGuests   = 10;
  const canAddAdult = totalGuests < maxGuests;
  const canAddChild = totalGuests < maxGuests;

  // Online booking = overnight only. Earliest check-in is TOMORROW.
  const tomorrow = new Date();
  tomorrow.setDate(tomorrow.getDate() + 1);
  tomorrow.setHours(0, 0, 0, 0);
  const minCheckIn = tomorrow.getTime();

  // ── Fetch availability for a given month offset ──────────────────────────
  const loadAvailability = useCallback(
    async (offset) => {
      const d = new Date(currentMonth);
      d.setMonth(d.getMonth() + offset);
      const year  = d.getFullYear();
      const month = d.getMonth() + 1; // JS months are 0-based
      const key   = `${year}-${month}`;

      setLoadingMonths((prev) => new Set(prev).add(key));
      try {
        const data = await fetchMonthAvailability(year, month);
        setAvailability((prev) => ({ ...prev, ...data }));
      } catch (err) {
        console.error('Availability fetch failed:', err);
      } finally {
        setLoadingMonths((prev) => {
          const next = new Set(prev);
          next.delete(key);
          return next;
        });
      }
    },
    [currentMonth]
  );

  // Fetch both visible months whenever the modal opens or the user navigates
  useEffect(() => {
    if (!isOpen) return;
    setCurrentMonth(getMonthStart());
  }, [isOpen]);

  useEffect(() => {
    const mediaQuery = window.matchMedia(MOBILE_CALENDAR_QUERY);
    const updateVisibleMonthCount = () => setVisibleMonthCount(mediaQuery.matches ? 1 : 2);

    updateVisibleMonthCount();
    mediaQuery.addEventListener('change', updateVisibleMonthCount);
    return () => mediaQuery.removeEventListener('change', updateVisibleMonthCount);
  }, []);

  useEffect(() => {
    if (!isOpen) return;
    Array.from({ length: visibleMonthCount }, (_, offset) => offset)
      .forEach((offset) => loadAvailability(offset));
  }, [isOpen, loadAvailability, visibleMonthCount]);

  // All hooks are declared above — safe to return early now
  if (!isOpen) return null;

  // ── Helpers ───────────────────────────────────────────────────────────────
  const toDateStr = (timestamp) => {
    if (!timestamp) return null;
    const d = new Date(timestamp);
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  };

  const isFullyBooked = (timestamp) =>
    availability[toDateStr(timestamp)] === 'fully_booked';

  // Returns true if any day WITHIN the range (exclusive of endpoints) is fully booked
  const rangeHasFullyBooked = (start, end) => {
    if (!start || !end) return false;
    const cursor = new Date(start);
    cursor.setDate(cursor.getDate() + 1);
    while (cursor.getTime() < end) {
      if (isFullyBooked(cursor.getTime())) return true;
      cursor.setDate(cursor.getDate() + 1);
    }
    return false;
  };

  // True when the current selection has a booked date inside — blocks proceed
  const isRangeBlocked = !!(checkIn && checkOut && rangeHasFullyBooked(checkIn, checkOut));

  const isMonthLoading = (offset) => {
    const d = new Date(currentMonth);
    d.setMonth(d.getMonth() + offset);
    const key = `${d.getFullYear()}-${d.getMonth() + 1}`;
    return loadingMonths.has(key);
  };

  // ── Date selection ────────────────────────────────────────────────────────
  const handleDateClick = (date) => {
    const isPast      = date < minCheckIn;
    const fullyBooked = isFullyBooked(date);
    // Fully booked endpoint dates stay unclickable; past dates always blocked
    if (isPast || fullyBooked) return;

    if (!checkIn || (checkIn && checkOut)) {
      // Start a fresh selection
      setCheckIn(date);
      setCheckOut(null);
    } else if (date > checkIn) {
      // Allow selection even if range crosses booked dates — UI shows warning + X marks
      setCheckOut(date);
    } else {
      // Clicked before current check-in — restart
      setCheckIn(date);
      setCheckOut(null);
    }
  };

  const handleProceed = () => {
    if (checkIn && checkOut && totalGuests >= 1 && totalGuests <= maxGuests) {
      onProceed({
        checkIn,
        checkOut,
        adults,
        children:     children.length,
        childrenAges: children,
      });
    }
  };

  const handleAddAdult    = () => { if (canAddAdult) setAdults(adults + 1); };
  const handleRemoveAdult = () => { if (adults > 1) setAdults(adults - 1); };
  const handleAddChild    = () => { if (canAddChild) setChildren([...children, 5]); };
  const handleRemoveChild = () => { if (children.length > 0) setChildren(children.slice(0, -1)); };
  const handleChildAgeChange = (index, age) => {
    const next = [...children];
    next[index] = parseInt(age);
    setChildren(next);
  };

  // ── Calendar rendering ────────────────────────────────────────────────────
  const getDaysInMonth = (date) => {
    const year  = date.getFullYear();
    const month = date.getMonth();
    const firstDay    = new Date(year, month, 1);
    const lastDay     = new Date(year, month + 1, 0);
    const daysInMonth = lastDay.getDate();
    const startingDayOfWeek = firstDay.getDay();
    return { daysInMonth, startingDayOfWeek };
  };

  const renderCalendar = (monthOffset = 0) => {
    const displayMonth = new Date(currentMonth);
    displayMonth.setMonth(displayMonth.getMonth() + monthOffset);

    const { daysInMonth, startingDayOfWeek } = getDaysInMonth(displayMonth);
    const todayStart    = new Date().setHours(0, 0, 0, 0);
    const tomorrowStart = todayStart + 86400000;
    const loading       = isMonthLoading(monthOffset);

    const days = [];

    // Empty cells before month starts
    for (let i = 0; i < startingDayOfWeek; i++) {
      days.push(
        <div key={`empty-${i}`} className="calendar-day calendar-day-empty" />
      );
    }

    for (let day = 1; day <= daysInMonth; day++) {
      const date      = new Date(displayMonth.getFullYear(), displayMonth.getMonth(), day);
      const timestamp = date.getTime();
      const isPast    = timestamp < tomorrowStart;
      const fullyBooked = !isPast && isFullyBooked(timestamp);

      const isCheckIn        = checkIn  && timestamp === checkIn;
      const isCheckOut       = checkOut && timestamp === checkOut;
      const isInRange        = checkIn  && checkOut && timestamp > checkIn && timestamp < checkOut;
      // A booked day that falls inside the selected range — show red X warning
      const isInRangeBlocked = isInRange && isFullyBooked(timestamp);

      const classNames = [
        'calendar-day',
        isPast             ? 'calendar-day-past'            : '',
        fullyBooked && !isInRange ? 'calendar-day-fully-booked' : '',
        isCheckIn          ? 'calendar-day-selected-start'  : '',
        isCheckOut         ? 'calendar-day-selected-end'    : '',
        isInRangeBlocked   ? 'calendar-day-in-range-blocked': '',
        isInRange && !isInRangeBlocked ? 'calendar-day-in-range' : '',
        loading && !isPast ? 'calendar-day-loading'         : '',
      ].filter(Boolean).join(' ');

      days.push(
        <button
          key={day}
          className={classNames}
          onClick={() => handleDateClick(timestamp)}
          disabled={isPast || (fullyBooked && !isInRange)}
          title={fullyBooked ? 'No rooms available' : undefined}
        >
          <span className="calendar-day-number">{day}</span>
          {(fullyBooked || isInRangeBlocked) && (
            <span className="calendar-day-x" aria-hidden="true">✕</span>
          )}
        </button>
      );
    }

    return days;
  };

  const formatDate = (timestamp) => {
    if (!timestamp) return 'No date selected';
    return new Date(timestamp).toLocaleDateString('en-US', {
      month: 'short',
      day:   'numeric',
      year:  'numeric',
    });
  };

  const changeMonth = (direction) => {
    const newMonth = new Date(
      currentMonth.getFullYear(),
      currentMonth.getMonth() + direction,
      1
    );
    setCurrentMonth(newMonth);
  };

  const getMonthYear = (offset = 0) => {
    const d = new Date(currentMonth);
    d.setMonth(d.getMonth() + offset);
    return d.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
  };

  // ── Render ────────────────────────────────────────────────────────────────
  return (
    <div className="modal-overlay" onClick={onClose}>
      <div className="modal-content" onClick={(e) => e.stopPropagation()}>
        <div className="modal-header">
          <h2 className="modal-title">Book Your Stay</h2>
          <button type="button" className="modal-close" onClick={onClose} aria-label="Close booking form">
            <X size={20} />
          </button>
        </div>

        <div className="modal-body">
          {/* ── Booking summary ── */}
          <div className="booking-summary">
            <div className="date-display">
              <div className="date-card">
                <Calendar size={20} />
                <div>
                  <div className="date-label">Check-in</div>
                  <div className="date-value">{formatDate(checkIn)}</div>
                </div>
              </div>
              <div className="date-card">
                <Calendar size={20} />
                <div>
                  <div className="date-label">Check-out</div>
                  <div className="date-value">{formatDate(checkOut)}</div>
                </div>
              </div>
            </div>

            <div className="guest-controls">
              <div className="guest-control">
                <span className="guest-control-label">
                  <Users size={18} />
                  Adults
                </span>
                <div className="counter">
                  <button onClick={handleRemoveAdult} disabled={adults <= 1}>−</button>
                  <span>{adults}</span>
                  <button onClick={handleAddAdult} disabled={!canAddAdult}>+</button>
                </div>
              </div>

              <div className="guest-control">
                <span className="guest-control-label">
                  <User size={18} />
                  Children
                </span>
                <div className="counter">
                  <button onClick={handleRemoveChild} disabled={children.length <= 0}>−</button>
                  <span>{children.length}</span>
                  <button onClick={handleAddChild} disabled={!canAddChild}>+</button>
                </div>
              </div>
            </div>

            {/* Children ages */}
            {children.length > 0 && (
              <div className="children-ages-section">
                <div className="children-ages-header">
                  <div className="children-ages-label">Children's Ages</div>
                  <button
                    type="button"
                    className="child-policy-link"
                    onClick={() => setShowChildPolicy(true)}
                  >
                    View Child Policy
                  </button>
                </div>
                <div className="children-ages-grid">
                  {children.map((age, index) => (
                    <div key={index} className="child-age-select">
                      <label>Child {index + 1}</label>
                      <select
                        value={age}
                        onChange={(e) => handleChildAgeChange(index, e.target.value)}
                      >
                        {Array.from({ length: 17 }, (_, i) => i + 1).map((ageOption) => (
                          <option key={ageOption} value={ageOption}>
                            {`${ageOption} year${ageOption > 1 ? 's' : ''}`}
                          </option>
                        ))}
                      </select>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Guest limit warning */}
            {totalGuests >= maxGuests && (
              <div className="guest-limit-warning">
                <AlertCircle size={16} />
                <span>Maximum {maxGuests} guests allowed per booking</span>
              </div>
            )}
          </div>

          {/* ── Calendar ── */}
          <div className="calendar-section">
            <div className="calendar-navigation">
              <button type="button" onClick={() => changeMonth(-1)} className="nav-btn" aria-label="Previous month">‹</button>
              <button type="button" onClick={() => changeMonth(1)} className="nav-btn" aria-label="Next month">›</button>
            </div>

            <div className="calendars-container">
              {Array.from({ length: visibleMonthCount }, (_, offset) => offset).map((offset) => (
                <div key={offset} className="calendar-month">
                  <div className="calendar-month-header">
                    <button
                      type="button"
                      className="mobile-calendar-nav"
                      onClick={() => changeMonth(-1)}
                      aria-label="Previous month"
                    >
                      ‹
                    </button>
                    <div className="calendar-month-heading">
                      <h3>{getMonthYear(offset)}</h3>
                      {isMonthLoading(offset) && (
                        <span className="calendar-loading-badge">Loading…</span>
                      )}
                    </div>
                    <button
                      type="button"
                      className="mobile-calendar-nav"
                      onClick={() => changeMonth(1)}
                      aria-label="Next month"
                    >
                      ›
                    </button>
                  </div>
                  <div className="calendar-grid">
                    {['S', 'M', 'T', 'W', 'T', 'F', 'S'].map((d, i) => (
                      <div key={`${d}-${i}-${offset}`} className="calendar-weekday">
                        {d}
                      </div>
                    ))}
                    {renderCalendar(offset)}
                  </div>
                </div>
              ))}
            </div>

            <div className="calendar-legend">
              <div className="legend-item">
                <div className="legend-box check-in" />
                <span>Check-in/out</span>
              </div>
              <div className="legend-item">
                <div className="legend-box in-range" />
                <span>Selected Range</span>
              </div>
              <div className="legend-item">
                <div className="legend-box fully-booked-legend" />
                <span>Fully Booked</span>
              </div>
              <div className="legend-item">
                <div className="legend-box unavailable" />
                <span>Unavailable</span>
              </div>
            </div>
          </div>

          {/* ── Blocked range warning ── */}
          {isRangeBlocked && (
            <div className="range-blocked-warning">
              <span className="range-blocked-icon">⚠</span>
              <span>
                <strong>A date within your stay is not available.</strong>{' '}
                Please choose different dates.
              </span>
            </div>
          )}

          <Button
            variant="primary"
            size="lg"
            fullWidth
            onClick={handleProceed}
            disabled={!checkIn || !checkOut || totalGuests < 1 || totalGuests > maxGuests || isRangeBlocked}
          >
            Continue to Room Selection
          </Button>
        </div>

        {/* ── Child policy modal ── */}
        {showChildPolicy && (
          <div
            className="child-policy-modal-overlay"
            onClick={() => setShowChildPolicy(false)}
          >
            <div
              className="child-policy-modal"
              onClick={(e) => e.stopPropagation()}
            >
              <div className="child-policy-modal-header">
                <h3>Child Policy</h3>
                <button
                  type="button"
                  className="child-policy-close"
                  onClick={() => setShowChildPolicy(false)}
                  aria-label="Close child policy"
                >
                  <X size={18} />
                </button>
              </div>
              <div className="child-policy-modal-body">
                <p>For a smooth check-in experience, please review the policy below:</p>
                <ul>
                  <li>Children aged 1 to 7 years stay free (maximum 2 free children per booking).</li>
                  <li>Children aged 8 and above are counted as adults for room occupancy purposes.</li>
                  <li>Please declare each child age correctly during booking.</li>
                  <li>Maximum room occupancy still applies to adults and children combined.</li>
                  <li>Extra bed availability and charges depend on room type.</li>
                </ul>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default BookingModal;
