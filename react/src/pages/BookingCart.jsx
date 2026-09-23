import { useEffect, useMemo, useRef, useState } from 'react';
import { ArrowLeft, CalendarDays, Plus, ShoppingBag, Trash2, Users, X } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { useCms } from '../context/CmsContext';
import { calculateBookingEstimate } from '../utils/bookingPricing';
import {
  BOOKING_CART_EVENT,
  getAddonLineTotal,
  getBookingCart,
  getBookingNights,
  getRoomImage,
  persistBookingCart,
  restoreBookingCartToSession,
  suppressNextRecoveryPrompt,
} from '../utils/bookingCart';
import { formatCurrency } from '../utils/currency';
import './BookingCart.css';

const formatDate = (value) => {
  if (!value) return 'Not selected';
  return new Date(`${value}T12:00:00`).toLocaleDateString('en-PH', {
    weekday: 'short', month: 'short', day: 'numeric', year: 'numeric',
  });
};

function RemoveRoomDialog({ room, onCancel, onConfirm }) {
  const dialogRef = useRef(null);
  const cancelRef = useRef(null);

  useEffect(() => {
    const dialog = dialogRef.current;
    const trigger = document.activeElement;
    dialog.showModal();
    cancelRef.current.focus();
    return () => {
      dialog.close();
      if (trigger?.isConnected) trigger.focus();
    };
  }, []);

  return (
    <dialog ref={dialogRef} className="booking-cart-remove-dialog" aria-labelledby="remove-room-title" aria-describedby="remove-room-description" onCancel={onCancel} onClick={(event) => { if (event.target === dialogRef.current) onCancel(); }}>
      <div className="booking-cart-remove-header">
        <h2 id="remove-room-title">Remove Room</h2>
        <button type="button" className="booking-cart-remove-close" onClick={onCancel} aria-label="Close remove room dialog"><X size={16} aria-hidden="true" /></button>
      </div>
      <div className="booking-cart-remove-body" id="remove-room-description">
        <p>Are you sure you want to remove <strong>{room.name || room.room_type || 'this room'}</strong> from your cart?</p>
        <p>Any add-ons selected for this room will also be removed.</p>
      </div>
      <div className="booking-cart-remove-actions">
        <button type="button" className="booking-cart-confirm-remove" onClick={onConfirm}>Remove</button>
        <button ref={cancelRef} type="button" className="booking-cart-cancel-remove" onClick={onCancel}>Cancel</button>
      </div>
    </dialog>
  );
}

export default function BookingCart() {
  const navigate = useNavigate();
  const { taxRate } = useCms();
  const [cart, setCart] = useState(() => getBookingCart());
  const [roomToRemove, setRoomToRemove] = useState(null);

  useEffect(() => {
    setCart(restoreBookingCartToSession() || getBookingCart());
    const handleUpdate = () => setCart(getBookingCart());
    window.addEventListener(BOOKING_CART_EVENT, handleUpdate);
    return () => window.removeEventListener(BOOKING_CART_EVENT, handleUpdate);
  }, []);

  const totals = useMemo(() => {
    if (!cart) return null;
    const nights = getBookingNights(cart.bookingData);
    const roomsSubtotal = cart.selectedRooms.reduce(
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
      ...calculateBookingEstimate({ roomsSubtotal, addonsTotal, discount, taxRate }),
    };
  }, [cart, taxRate]);

  const totalGuests = cart
    ? Math.max(1, Number(cart.bookingData?.adults || 0) + Number(cart.bookingData?.children || 0))
    : 0;
  const removeRoom = (roomId) => {
    setRoomToRemove(null);
    const currentCart = getBookingCart();
    if (!currentCart) {
      setCart(null);
      return;
    }
    const selectedRooms = currentCart.selectedRooms.filter((room) => room.roomId !== roomId);
    const roomAddons = { ...currentCart.roomAddons };
    delete roomAddons[roomId];
    const updated = persistBookingCart({ selectedRooms, roomAddons });
    setCart(updated);
  };

  const addRoom = () => {
    suppressNextRecoveryPrompt();
    navigate('/select-room');
  };

  const chooseAddons = () => {
    const activeRoom = cart?.selectedRooms?.[cart.selectedRooms.length - 1];
    if (activeRoom?.roomId) sessionStorage.setItem('activeAddonRoomId', String(activeRoom.roomId));
    navigate('/add-ons');
  };

  if (!cart?.selectedRooms?.length || !totals) {
    return (
      <main className="booking-cart-page booking-cart-empty">
        <div className="booking-cart-empty-card">
          <span className="booking-cart-empty-icon"><ShoppingBag aria-hidden="true" /></span>
          <p className="booking-cart-eyebrow">Booking Cart</p>
          <h1>Your cart is empty</h1>
          <p>Choose your dates and add a room to begin your stay.</p>
          <button type="button" onClick={() => navigate('/select-room')}>Browse Rooms</button>
        </div>
      </main>
    );
  }

  return (
    <main className="booking-cart-page">
      <div className="booking-cart-shell">
        <button type="button" className="booking-cart-back" onClick={() => navigate(-1)}>
          <ArrowLeft aria-hidden="true" /> Back
        </button>

        <div className="booking-cart-title-row">
          <div>
            <p className="booking-cart-eyebrow">Your Stay</p>
            <h1>Booking Cart</h1>
            <p>{cart.selectedRooms.length} room{cart.selectedRooms.length === 1 ? '' : 's'} saved for your stay</p>
          </div>
        </div>

        <div className="booking-cart-layout">
          <section className="booking-cart-rooms" aria-label="Rooms in cart">
            <div className="booking-cart-stay-summary">
              <span><CalendarDays aria-hidden="true" /><strong>Check-in</strong>{formatDate(cart.bookingData?.checkIn)}</span>
              <span><CalendarDays aria-hidden="true" /><strong>Check-out</strong>{formatDate(cart.bookingData?.checkOut)}</span>
              <span><Users aria-hidden="true" /><strong>Guests</strong>{totalGuests} guest{totalGuests === 1 ? '' : 's'}</span>
              <span className="booking-cart-nights"><strong>{totals.nights}</strong> night{totals.nights === 1 ? '' : 's'}</span>
            </div>

            {cart.selectedRooms.map((room) => {
              const addons = cart.roomAddons?.[room.roomId] || [];
              return (
                <article className="booking-cart-room" key={room.roomId}>
                  <img src={getRoomImage(room)} alt={room.name || 'Selected room'} />
                  <div className="booking-cart-room-info">
                    <div className="booking-cart-room-heading">
                      <div>
                        <h2>{room.name || room.room_type || 'Selected room'}</h2>
                        <p>{room.description || 'Comfortable room'}</p>
                      </div>
                      <button type="button" onClick={() => setRoomToRemove(room)} aria-label={`Remove ${room.name || 'room'} from cart`}>
                        <Trash2 aria-hidden="true" /> Remove
                      </button>
                    </div>
                    <div className="booking-cart-room-meta">
                      <span>Sleeps {Number(room.capacity) || 2}</span>
                      <span>{formatCurrency(Number(room.price) || 0)} per night</span>
                    </div>
                    {addons.length > 0 ? (
                      <div className="booking-cart-addons">
                        <strong>Selected add-ons</strong>
                        {addons.map((addon) => (
                          <span key={addon.id}>
                            {addon.name} × {Math.max(1, Number(addon.quantity) || 1)}
                            <b>{formatCurrency(getAddonLineTotal(addon))}</b>
                          </span>
                        ))}
                      </div>
                    ) : (
                      <p className="booking-cart-no-addons">No add-ons selected yet.</p>
                    )}
                    <div className="booking-cart-room-total">
                      <span>Room total for {totals.nights} night{totals.nights === 1 ? '' : 's'}</span>
                      <strong>{formatCurrency((Number(room.price) || 0) * totals.nights)}</strong>
                    </div>
                  </div>
                </article>
              );
            })}

            <button type="button" className="booking-cart-add-room" onClick={addRoom}>
              <Plus aria-hidden="true" /> Add Another Room
            </button>
          </section>

          <aside className="booking-cart-price-card">
            <p className="booking-cart-eyebrow">Price Details</p>
            <h2>Booking Summary</h2>
            <div className="booking-cart-price-lines">
              <span><em>Rooms</em><strong>{formatCurrency(totals.roomsSubtotal)}</strong></span>
              {totals.addonsTotal > 0 && <span><em>Add-ons</em><strong>{formatCurrency(totals.addonsTotal)}</strong></span>}
              {totals.discount > 0 && <span className="booking-cart-discount"><em>Promo discount</em><strong>−{formatCurrency(totals.discount)}</strong></span>}
              <span><em>Taxes and fees included</em><strong>{formatCurrency(totals.taxAmount)}</strong></span>
            </div>
            <div className="booking-cart-grand-total">
              <span>Total</span>
              <strong>{formatCurrency(totals.total)}</strong>
            </div>

            <button type="button" className="booking-cart-addons-btn" onClick={chooseAddons}>Choose Add-ons</button>
            <button type="button" className="booking-cart-checkout-btn" onClick={() => navigate('/guest-details')}>
              Continue Booking
            </button>
            <p className="booking-cart-availability-note">Room availability is confirmed when your booking is submitted.</p>
          </aside>
        </div>
      </div>
      {roomToRemove && (
        <RemoveRoomDialog
          room={roomToRemove}
          onCancel={() => setRoomToRemove(null)}
          onConfirm={() => removeRoom(roomToRemove.roomId)}
        />
      )}
    </main>
  );
}
