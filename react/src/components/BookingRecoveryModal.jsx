import { useEffect, useMemo, useState } from 'react';
import { CalendarDays, ShoppingCart, Users, X } from 'lucide-react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useCms } from '../context/CmsContext';
import { calculateBookingEstimate } from '../utils/bookingPricing';
import {
  BOOKING_CART_EVENT,
  BOOKING_CART_EXPIRED_EVENT,
  BOOKING_CART_STORAGE_KEY,
  acknowledgeBookingRecovery,
  clearBookingCart,
  consumeRecoverySuppression,
  getAddonLineTotal,
  getBookingCart,
  getBookingCartDeadline,
  expireBookingCart,
  getBookingNights,
  getRoomImage,
  hasAcknowledgedBookingRecovery,
  isBookingRecoveryPath,
  isBookingWorkflowPath,
  resetBookingRecoveryAcknowledgement,
  restoreBookingCartToSession,
} from '../utils/bookingCart';
import { formatCurrency } from '../utils/currency';
import { showToast } from '../utils/showToast';
import './BookingRecoveryModal.css';

const formatDate = (value) => {
  if (!value) return 'Date not selected';
  return new Date(`${value}T12:00:00`).toLocaleDateString('en-PH', {
    month: 'short', day: 'numeric', year: 'numeric',
  });
};

export default function BookingRecoveryModal() {
  const location = useLocation();
  const navigate = useNavigate();
  const { taxRate } = useCms();
  const [cart, setCart] = useState(null);
  const [isOpen, setIsOpen] = useState(false);

  useEffect(() => {
    let timer;
    const check = () => {
      clearTimeout(timer);
      expireBookingCart();
      const deadline = getBookingCartDeadline();
      if (deadline) timer = setTimeout(check, Math.max(1, deadline - Date.now()));
    };
    const expired = (event) => {
      setCart(getBookingCart());
      setIsOpen(false);
      if (event.detail?.sessionExpired && isBookingWorkflowPath(location.pathname)) {
        showToast('Your saved booking progress expired after 30 minutes. Please start a new booking.', 'info');
        navigate('/', { replace: true });
      }
    };
    const storageChanged = (event) => {
      if (event.key === BOOKING_CART_STORAGE_KEY || event.key === null) check();
    };
    window.addEventListener(BOOKING_CART_EXPIRED_EVENT, expired);
    window.addEventListener(BOOKING_CART_EVENT, check);
    window.addEventListener('focus', check);
    window.addEventListener('pageshow', check);
    window.addEventListener('storage', storageChanged);
    document.addEventListener('visibilitychange', check);
    check();
    return () => {
      clearTimeout(timer);
      window.removeEventListener(BOOKING_CART_EXPIRED_EVENT, expired);
      window.removeEventListener(BOOKING_CART_EVENT, check);
      window.removeEventListener('focus', check);
      window.removeEventListener('pageshow', check);
      window.removeEventListener('storage', storageChanged);
      document.removeEventListener('visibilitychange', check);
    };
  }, [location.pathname, navigate]);

  useEffect(() => {
    if (!isBookingWorkflowPath(location.pathname)) {
      resetBookingRecoveryAcknowledgement();
      setIsOpen(false);
      return;
    }

    if (!isBookingRecoveryPath(location.pathname)) {
      acknowledgeBookingRecovery();
      setIsOpen(false);
      return;
    }

    if (consumeRecoverySuppression()) {
      acknowledgeBookingRecovery();
      setIsOpen(false);
      return;
    }

    if (hasAcknowledgedBookingRecovery()) {
      setIsOpen(false);
      return;
    }

    const restored = restoreBookingCartToSession() || getBookingCart();
    setCart(restored);
    setIsOpen(Boolean(restored?.selectedRooms?.length));
  }, [location.key, location.pathname]);

  useEffect(() => {
    const updateCart = () => setCart(getBookingCart());
    window.addEventListener(BOOKING_CART_EVENT, updateCart);
    return () => window.removeEventListener(BOOKING_CART_EVENT, updateCart);
  }, []);

  useEffect(() => {
    if (!isOpen) return undefined;
    const previousOverflow = document.body.style.overflow;
    const handleKeyDown = (event) => {
      if (event.key === 'Escape') setIsOpen(false);
    };
    document.body.style.overflow = 'hidden';
    window.addEventListener('keydown', handleKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [isOpen]);

  const summary = useMemo(() => {
    if (!cart) return null;
    const nights = getBookingNights(cart.bookingData);
    const roomsTotal = cart.selectedRooms.reduce(
      (sum, room) => sum + (Number(room.price) || 0) * nights,
      0,
    );
    const addonsTotal = Object.values(cart.roomAddons || {}).reduce(
      (sum, addons) => sum + (Array.isArray(addons) ? addons.reduce((total, addon) => total + getAddonLineTotal(addon), 0) : 0),
      0,
    );
    const discount = cart.promoResult?.valid ? Number(cart.promoResult.discount_amount || 0) : 0;
    return {
      nights,
      guests: Math.max(1, Number(cart.bookingData?.adults || 0) + Number(cart.bookingData?.children || 0)),
      total: calculateBookingEstimate({ roomsSubtotal: roomsTotal, addonsTotal, discount, taxRate }).total,
    };
  }, [cart, taxRate]);

  if (!isOpen || !cart?.selectedRooms?.length || !summary) return null;

  const primaryRoom = cart.selectedRooms[0];
  const additionalRooms = cart.selectedRooms.length - 1;

  const handleStartOver = () => {
    clearBookingCart();
    setIsOpen(false);
    navigate('/');
  };

  const handleContinue = () => {
    if (!restoreBookingCartToSession()) { setIsOpen(false); return; }
    const activeRoom = cart.selectedRooms[cart.selectedRooms.length - 1];
    if (activeRoom?.roomId) sessionStorage.setItem('activeAddonRoomId', String(activeRoom.roomId));
    acknowledgeBookingRecovery();
    setIsOpen(false);
    navigate('/add-ons');
  };

  const handleViewCart = () => {
    if (!restoreBookingCartToSession()) { setIsOpen(false); return; }
    acknowledgeBookingRecovery();
    setIsOpen(false);
    navigate('/cart');
  };

  return (
    <div className="booking-recovery-backdrop" role="presentation" onMouseDown={() => setIsOpen(false)}>
      <section
        className="booking-recovery-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="booking-recovery-title"
        onMouseDown={(event) => event.stopPropagation()}
      >
        <button type="button" className="booking-recovery-close" onClick={() => setIsOpen(false)} aria-label="Close booking reminder">
          <X aria-hidden="true" />
        </button>

        <div className="booking-recovery-heading">
          <span className="booking-recovery-icon"><ShoppingCart aria-hidden="true" /></span>
          <div>
            <p>Your stay is saved</p>
            <h2 id="booking-recovery-title">Complete Your Booking</h2>
          </div>
        </div>

        <div className="booking-recovery-preview">
          <img src={getRoomImage(primaryRoom)} alt={primaryRoom.name || 'Selected room'} />
          <div className="booking-recovery-details">
            <h3>{primaryRoom.name || primaryRoom.room_type || 'Selected room'}</h3>
            {additionalRooms > 0 && <span className="booking-recovery-more">+ {additionalRooms} more room{additionalRooms === 1 ? '' : 's'}</span>}
            <div className="booking-recovery-meta">
              <span><CalendarDays aria-hidden="true" /> {formatDate(cart.bookingData?.checkIn)} – {formatDate(cart.bookingData?.checkOut)}</span>
              <span><Users aria-hidden="true" /> {summary.guests} guest{summary.guests === 1 ? '' : 's'} · {summary.nights} night{summary.nights === 1 ? '' : 's'}</span>
            </div>
            <div className="booking-recovery-total">
              <span>Current total</span>
              <strong>{formatCurrency(summary.total)}</strong>
            </div>
          </div>
        </div>

        <p className="booking-recovery-note">Booking progress is saved for 30 minutes, until {new Date(cart.expiresAt).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })}. After that, it clears automatically. This does not affect confirmed reservations.</p>

        <div className="booking-recovery-actions">
          <button type="button" className="recovery-start-over" onClick={handleStartOver}>Start Over</button>
          <button type="button" className="recovery-continue" onClick={handleContinue}>Continue</button>
          <button type="button" className="recovery-view-cart" onClick={handleViewCart}>View Cart</button>
        </div>
      </section>
    </div>
  );
}
