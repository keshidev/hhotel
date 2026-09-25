import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ChevronLeft, X } from 'lucide-react';
import Button from '../components/Button';
import BookingProgress from '../components/BookingProgress';
import { calculateBookingEstimate } from '../utils/bookingPricing';
import { formatCurrency } from '../utils/currency';
import { persistBookingCart, restoreBookingCartToSession } from '../utils/bookingCart';
import { useCms } from '../context/CmsContext';
import './AddOns.css';

const DEFAULT_DOWNPAYMENT_POLICY = 'Down payment is required to confirm the reservation.';
const DEFAULT_CANCELLATION_POLICY = 'If cancellation is requested within 24 hours of check-in time, it is non-refundable.';

const ADDONS = [
  {
    id: 'rollaway_bed',
    category: 'Room Enhancements',
    name: 'Rollaway Bed',
    subtitle: 'STRETCH OUT IN COMFORT',
    description:
      'Add extra sleeping space for a more restful stay. Our rollaway bed is prepared with clean linens and set up by our team for your convenience. It is perfect for families or small groups who want added comfort.',
    price: 800,
    pricingType: 'per_stay',
    pricingLabel: 'Per Room / Stay',
    excludesTax: true,
    quantityLabel: 'Number of items',
    minQty: 1,
    maxQty: 2,
    active: true,
    image: 'https://images.unsplash.com/photo-1566665797739-1674de7a421a?w=560&h=360&fit=crop',
  },
  {
    id: 'extra_pillows_and_blankets',
    category: 'Room Enhancements',
    name: 'Extra Pillows and Blankets',
    subtitle: 'COZY UP ANYTIME',
    description:
      'Make your room feel even more relaxing with extra pillows and blankets. This add-on is ideal for guests who love a softer, warmer sleep setup. Enjoy a cozier night with comfort tailored to your preference.',
    price: 350,
    pricingType: 'per_stay',
    pricingLabel: 'Per Room / Stay',
    excludesTax: true,
    quantityLabel: 'Number of items',
    minQty: 1,
    maxQty: 6,
    active: true,
    image: 'https://images.unsplash.com/photo-1616628182509-6f0a8a7f3f5a?w=560&h=360&fit=crop',
  },
  {
    id: 'breakfast_package',
    category: 'Food & Beverage',
    name: 'Breakfast Package',
    subtitle: 'START YOUR DAY RIGHT',
    description:
      'Enjoy a satisfying breakfast to begin your morning with ease. Choose this package for a convenient and flavorful start before your plans for the day. It is a great option for both business and leisure guests.',
    price: 650,
    pricingType: 'per_guest',
    pricingLabel: 'Per Guest / Day',
    excludesTax: true,
    quantityLabel: 'Number of guests',
    minQty: 1,
    maxQty: 12,
    active: true,
    image: 'https://images.unsplash.com/photo-1525351484163-7529414344d8?w=560&h=360&fit=crop',
  },
  {
    id: 'early_check_in',
    category: 'Convenience',
    name: 'Early Check-In',
    subtitle: 'ARRIVE AND UNWIND EARLY',
    description:
      'Settle into your room sooner and enjoy more time to relax. This add-on is ideal for early arrivals who want immediate comfort after travel. Start your stay smoothly without waiting for standard check-in time.',
    price: 500,
    pricingType: 'per_stay',
    pricingLabel: 'Per Room / Stay',
    excludesTax: true,
    quantityLabel: 'Number of items',
    minQty: 1,
    maxQty: 1,
    active: true,
    image: 'https://images.unsplash.com/photo-1590490360182-c33d57733427?w=560&h=360&fit=crop',
  },
  {
    id: 'late_check_out',
    category: 'Convenience',
    name: 'Late Check-Out',
    subtitle: 'TAKE YOUR TIME',
    description:
      'Extend your departure and enjoy a more flexible final day. This add-on gives you extra breathing room for packing, rest, or a slower morning. Leave at a more comfortable pace before heading out.',
    price: 500,
    pricingType: 'per_stay',
    pricingLabel: 'Per Room / Stay',
    excludesTax: true,
    quantityLabel: 'Number of items',
    minQty: 1,
    maxQty: 1,
    active: true,
    image: 'https://images.unsplash.com/photo-1455587734955-081b22074882?w=560&h=360&fit=crop',
  },
  {
    id: 'extra_toiletries_kit',
    category: 'Personal Care',
    name: 'Extra Toiletries Kit',
    subtitle: 'FRESHEN UP WITH EASE',
    description:
      'Enjoy added essentials for a more convenient and comfortable stay. This kit is perfect for longer visits or guests who simply prefer extra personal care items. Everything is prepared to help you feel refreshed anytime.',
    price: 250,
    pricingType: 'per_stay',
    pricingLabel: 'Per Room / Stay',
    excludesTax: true,
    quantityLabel: 'Number of items',
    minQty: 1,
    maxQty: 10,
    active: true,
    image: 'https://images.unsplash.com/photo-1540555700478-4be289fbecef?w=560&h=360&fit=crop',
  },
  {
    id: 'laundry_service',
    category: 'Personal Care',
    name: 'Laundry Service',
    subtitle: 'STAY CLEAN AND CRISP',
    description:
      'Keep your wardrobe fresh throughout your stay with our laundry service. This add-on is ideal for business travelers, long stays, or guests who want extra convenience. Enjoy clean, ready-to-wear clothes without leaving the hotel.',
    price: 400,
    pricingType: 'per_stay',
    pricingLabel: 'Per Room / Stay',
    excludesTax: true,
    quantityLabel: 'Number of items',
    minQty: 1,
    maxQty: 10,
    active: true,
    image: 'https://images.unsplash.com/photo-1626806787461-102c1a0f4f79?w=560&h=360&fit=crop',
  },
];

const CATEGORY_ORDER = ['Room Enhancements', 'Food & Beverage', 'Convenience', 'Personal Care'];

const ssGet = (key, fallback) => {
  try {
    const raw = sessionStorage.getItem(key);
    return raw !== null ? JSON.parse(raw) : fallback;
  } catch {
    return fallback;
  }
};

const ssSet = (key, value) => {
  try {
    sessionStorage.setItem(key, JSON.stringify(value));
    if (key === 'roomAddons') persistBookingCart({ roomAddons: value });
  } catch {
    // ignore
  }
};

const getAddonLineTotal = (addon) => {
  const explicitLineTotal = Number(addon?.line_total);
  if (Number.isFinite(explicitLineTotal)) return explicitLineTotal;

  const unitPrice = Number(addon?.price || 0);
  const quantity = Math.max(1, parseInt(addon?.quantity ?? 1, 10) || 1);
  return unitPrice * quantity;
};

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
        border: '1px solid #e5e7eb', boxShadow: '0 20px 60px rgba(0,0,0,0.2)',
        fontFamily: 'inherit',
      }}
      onClick={(e) => e.stopPropagation()}
    >
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '1.1rem 1.4rem', borderBottom: '1px solid #e5e7eb' }}>
        <span style={{ fontWeight: 700, fontSize: '0.95rem', color: '#1f2937' }}>Remove Room</span>
        <button onClick={onCancel} style={{ background: 'none', border: '1px solid #e5e7eb', cursor: 'pointer', padding: '4px', display: 'flex', alignItems: 'center', color: '#6b7280' }}>
          <X size={16} />
        </button>
      </div>

      <div style={{ padding: '1.4rem' }}>
        <p style={{ margin: '0 0 0.4rem', color: '#1f2937', fontSize: '0.92rem', lineHeight: 1.5 }}>
          Are you sure you want to remove <strong>{room?.name}</strong> from your cart?
        </p>
        <p style={{ margin: 0, color: '#6b7280', fontSize: '0.85rem' }}>
          Any add-ons selected for this room will also be removed.
        </p>
      </div>

      <div style={{ display: 'flex', gap: '0.75rem', justifyContent: 'flex-end', padding: '1rem 1.4rem', borderTop: '1px solid #e5e7eb', background: '#f9fafb' }}>
        <button
          onClick={onConfirm}
          style={{ padding: '0.55rem 1.2rem', border: '1px solid #1a4bcc', background: '#fff', color: '#1a4bcc', cursor: 'pointer', fontFamily: 'inherit', fontSize: '0.88rem', fontWeight: 700 }}
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

const QuantityModal = ({ addon, quantity, nights, onChangeQty, onCancel, onConfirm }) => {
  if (!addon) return null;

  const safeQty = Math.max(addon.minQty, Math.min(addon.maxQty, quantity));
  const total = addon.price * safeQty;

  return (
    <div className="addon-qty-modal-overlay" onClick={onCancel}>
      <div className="addon-qty-modal" onClick={(e) => e.stopPropagation()}>
        <div className="addon-qty-modal-header">
          <h3>{addon.name}</h3>
          <button className="addon-qty-close" onClick={onCancel}>
            <X size={16} />
          </button>
        </div>

        <div className="addon-qty-modal-body">
          <p className="addon-qty-subtitle">{addon.subtitle}</p>
          <p className="addon-qty-pricing">
            {formatCurrency(addon.price)} <span>{addon.pricingLabel}</span>
          </p>
          <p className="addon-qty-tax-note">Including Taxes and Fees</p>

          <div className="addon-qty-stepper-wrap">
            <label>{addon.quantityLabel}</label>
            <div className="addon-qty-stepper">
              <button
                type="button"
                onClick={() => onChangeQty(-1)}
                disabled={safeQty <= addon.minQty}
              >
                -
              </button>
              <span>{safeQty}</span>
              <button
                type="button"
                onClick={() => onChangeQty(1)}
                disabled={safeQty >= addon.maxQty}
              >
                +
              </button>
            </div>
            {addon.pricingType === 'per_guest' && nights > 0 ? (
              <small className="addon-qty-helper">Stay duration: {nights} night(s)</small>
            ) : null}
          </div>

          <div className="addon-qty-total">
            Total: {formatCurrency(total)} - Including Taxes and Fees
          </div>
        </div>

        <div className="addon-qty-modal-actions">
          <button className="addon-qty-cancel" onClick={onCancel}>Cancel</button>
          <button className="addon-qty-confirm" onClick={onConfirm}>ADD TO MY STAY</button>
        </div>
      </div>
    </div>
  );
};

const AddOns = () => {
  const navigate = useNavigate();
  const { addonsItems, taxRate, downpaymentRate, get } = useCms();
  const mounted = useRef(false);
  const successTimerRef = useRef(null);

  const [bookingData, setBookingData] = useState(null);
  const [selectedRooms, setSelectedRooms] = useState([]);
  const [roomAddons, setRoomAddons] = useState({});
  const [activeRoomId, setActiveRoomId] = useState(null);
  const [removeTarget, setRemoveTarget] = useState(null);
  const [addonSuccessKey, setAddonSuccessKey] = useState('');
  const [qtyModal, setQtyModal] = useState({ open: false, addon: null, qty: 1 });

  useEffect(() => {
    restoreBookingCartToSession();
    const stored = sessionStorage.getItem('bookingData');
    const roomsStored = sessionStorage.getItem('selectedRooms');

    if (!stored || !roomsStored) {
      navigate('/select-room');
      return;
    }

    const booking = JSON.parse(stored);
    const rooms = JSON.parse(roomsStored);

    const validatedRooms = rooms.map((r) => ({
      ...r,
      price: r.price ? parseFloat(r.price) : 0,
      name: r.name || 'Room',
      description: r.description || 'Comfortable room',
    }));

    const savedAddons = ssGet('roomAddons', {});
    const savedActiveId = sessionStorage.getItem('activeAddonRoomId');
    const lastRoom = validatedRooms[validatedRooms.length - 1];
    const matchedRoom = savedActiveId
      ? validatedRooms.find((r) => String(r.roomId) === savedActiveId)
      : null;
    const resolvedActive = (matchedRoom ?? lastRoom)?.roomId ?? null;

    setBookingData(booking);
    setSelectedRooms(validatedRooms);
    setRoomAddons(savedAddons);
    setActiveRoomId(resolvedActive);
    persistBookingCart({ bookingData: booking, selectedRooms: validatedRooms, roomAddons: savedAddons });

    mounted.current = true;
  }, [navigate]);

  useEffect(() => {
    if (!mounted.current || activeRoomId == null) return;
    sessionStorage.setItem('activeAddonRoomId', String(activeRoomId));
  }, [activeRoomId]);

  useEffect(() => () => {
    if (successTimerRef.current) clearTimeout(successTimerRef.current);
  }, []);

  const calculateNights = (checkIn, checkOut) =>
    Math.ceil(Math.abs(new Date(checkOut) - new Date(checkIn)) / (1000 * 60 * 60 * 24));

  const getRoomPrice = (room) => {
    const p = parseFloat(room.price);
    return Number.isNaN(p) ? 0 : p;
  };

  const nights = calculateNights(bookingData?.checkIn, bookingData?.checkOut);

  const addonPolicy = [
    'Selected add-ons are included in your booking total and follow the same payment and cancellation terms as your room reservation.',
    get('policy_downpayment', DEFAULT_DOWNPAYMENT_POLICY),
    'The required down payment is paid through GCash, and any remaining balance is due at check-in.',
    get('policy_cancellation', DEFAULT_CANCELLATION_POLICY),
  ].join(' ');

  const cmsAddonMap = useMemo(() => {
    const map = {};
    (addonsItems || []).forEach((item) => {
      const key = String(item?.id || '').trim();
      if (!key) return;
      map[key] = item;
    });
    return map;
  }, [addonsItems]);

  const displayAddons = useMemo(() => (
    ADDONS.map((addon) => {
      const cmsItem = cmsAddonMap[addon.id];

      return {
        ...addon,
        description: cmsItem?.display_description || addon.description,
        image: cmsItem?.image || addon.image,
        policy: addonPolicy,
      };
    })
  ), [addonPolicy, cmsAddonMap]);

  const groupedAddons = useMemo(() => {
    const grouped = displayAddons.filter((addon) => addon.active).reduce((acc, addon) => {
      if (!acc[addon.category]) acc[addon.category] = [];
      acc[addon.category].push(addon);
      return acc;
    }, {});

    return CATEGORY_ORDER.map((category) => ({
      category,
      items: grouped[category] || [],
    })).filter((group) => group.items.length > 0);
  }, [displayAddons]);

  const findAddonForRoom = (roomId, addonId) =>
    (roomAddons[roomId] || []).find((addon) => addon.id === addonId) || null;

  const isAddonSelected = (addonId, roomId) =>
    (roomAddons[roomId] || []).some((addon) => addon.id === addonId);

  const openQuantityModal = (addon) => {
    if (!activeRoomId) return;

    const existing = findAddonForRoom(activeRoomId, addon.id);
    const initialQty = Math.max(
      addon.minQty,
      Math.min(addon.maxQty, parseInt(existing?.quantity ?? addon.minQty, 10) || addon.minQty)
    );

    setQtyModal({ open: true, addon, qty: initialQty });
  };

  const updateModalQty = (delta) => {
    setQtyModal((prev) => {
      if (!prev.open || !prev.addon) return prev;
      const nextQty = Math.max(prev.addon.minQty, Math.min(prev.addon.maxQty, prev.qty + delta));
      return { ...prev, qty: nextQty };
    });
  };

  const closeQuantityModal = () => {
    setQtyModal({ open: false, addon: null, qty: 1 });
  };

  const confirmQuantityModal = () => {
    if (!qtyModal.open || !qtyModal.addon || !activeRoomId) return;

    const addon = qtyModal.addon;
    const quantity = Math.max(addon.minQty, Math.min(addon.maxQty, qtyModal.qty));
    const lineTotal = addon.price * quantity;

    const selectedAddon = {
      id: addon.id,
      category: addon.category,
      name: addon.name,
      subtitle: addon.subtitle,
      description: addon.description,
      price: addon.price,
      pricingType: addon.pricingType,
      pricingLabel: addon.pricingLabel,
      excludesTax: addon.excludesTax,
      quantityLabel: addon.quantityLabel,
      minQty: addon.minQty,
      maxQty: addon.maxQty,
      policy: addon.policy,
      active: addon.active,
      quantity,
      addon_id: addon.id,
      line_total: lineTotal,
    };

    setRoomAddons((prev) => {
      const current = prev[activeRoomId] || [];
      const index = current.findIndex((item) => item.id === addon.id);
      const nextRoomAddons = [...current];

      if (index >= 0) {
        nextRoomAddons[index] = selectedAddon;
      } else {
        nextRoomAddons.push(selectedAddon);
      }

      const next = { ...prev, [activeRoomId]: nextRoomAddons };
      ssSet('roomAddons', next);
      return next;
    });

    if (successTimerRef.current) clearTimeout(successTimerRef.current);
    setAddonSuccessKey(`${activeRoomId}:${addon.id}`);
    successTimerRef.current = setTimeout(() => setAddonSuccessKey(''), 1600);

    closeQuantityModal();
  };

  const removeAddonFromRoom = (roomId, addonId) => {
    setRoomAddons((prev) => {
      const next = {
        ...prev,
        [roomId]: (prev[roomId] || []).filter((addon) => addon.id !== addonId),
      };
      ssSet('roomAddons', next);
      return next;
    });
  };

  const confirmRemoveRoom = (room) => {
    setRemoveTarget(room);
  };

  const handleRemoveConfirmed = () => {
    if (!removeTarget) return;

    const updated = selectedRooms.filter((r) => r.roomId !== removeTarget.roomId);
    setSelectedRooms(updated);
    sessionStorage.setItem('selectedRooms', JSON.stringify(updated));

    setRoomAddons((prev) => {
      const next = { ...prev };
      delete next[removeTarget.roomId];
      ssSet('roomAddons', next);
      persistBookingCart({ bookingData, selectedRooms: updated, roomAddons: next });
      return next;
    });

    if (updated.length === 0) {
      sessionStorage.removeItem('activeAddonRoomId');
      setRemoveTarget(null);
      navigate('/select-room');
      return;
    }

    if (activeRoomId === removeTarget.roomId) {
      setActiveRoomId(updated[updated.length - 1]?.roomId ?? null);
    }

    setRemoveTarget(null);
  };

  const handleBack = () => {
    ssSet('roomAddons', roomAddons);
    navigate('/select-room');
  };

  const handleContinue = () => {
    if (selectedRooms.length === 0) return;

    ssSet('roomAddons', roomAddons);
    persistBookingCart({ bookingData, selectedRooms, roomAddons });
    sessionStorage.removeItem('activeAddonRoomId');
    navigate('/guest-details');
  };

  if (!bookingData || selectedRooms.length === 0) return null;

  const roomsTotal = selectedRooms.reduce((sum, room) => sum + getRoomPrice(room) * nights, 0);
  const addonsTotal = Object.values(roomAddons).reduce((sum, list) => (
    sum + (Array.isArray(list) ? list.reduce((inner, addon) => inner + getAddonLineTotal(addon), 0) : 0)
  ), 0);

  const pricing = calculateBookingEstimate({ roomsSubtotal: roomsTotal, addonsTotal, taxRate });
  const downpaymentAmount = Math.round(pricing.total * downpaymentRate * 100) / 100;
  const remainingBalance = Math.round(Math.max(0, pricing.total - downpaymentAmount) * 100) / 100;
  return (
    <div className="addons-page">
      <BookingProgress currentStep={3} />
      {removeTarget && (
        <RemoveRoomModal
          room={removeTarget}
          onConfirm={handleRemoveConfirmed}
          onCancel={() => setRemoveTarget(null)}
        />
      )}

      {qtyModal.open && qtyModal.addon && (
        <QuantityModal
          addon={qtyModal.addon}
          quantity={qtyModal.qty}
          nights={nights}
          onChangeQty={updateModalQty}
          onCancel={closeQuantityModal}
          onConfirm={confirmQuantityModal}
        />
      )}

      <header className="addons-header">
        <button className="back-btn" onClick={handleBack}>
          <ChevronLeft size={20} /> Add to Your Room
        </button>
      </header>

      <section className="addons-container">
        <div className="addons-main">
          <h1 className="addons-title">Add to Your Room</h1>

          {selectedRooms.length > 1 && (
            <div className="addon-room-tabs">
              {selectedRooms.map((room, idx) => (
                <button
                  key={room.roomId}
                  className={`addon-room-tab ${activeRoomId === room.roomId ? 'active' : ''}`}
                  onClick={() => setActiveRoomId(room.roomId)}
                >
                  Room {idx + 1} - {room.name}
                  {roomAddons[room.roomId]?.length > 0 && (
                    <span className="addon-room-tab-badge">{roomAddons[room.roomId].length}</span>
                  )}
                </button>
              ))}
            </div>
          )}

          {activeRoomId && (
            <p className="addons-subtitle">
              Adding add-ons to: <strong>{selectedRooms.find((r) => r.roomId === activeRoomId)?.name || 'Room'}</strong>
            </p>
          )}

          <div className="addons-list">
            {groupedAddons.map((group) => (
              <div key={group.category} className="addon-category-group">
                <h2 className="addon-category-title">{group.category}</h2>

                {group.items.map((addon) => {
                  const selectedAddon = activeRoomId ? findAddonForRoom(activeRoomId, addon.id) : null;
                  const selectedQty = Math.max(1, parseInt(selectedAddon?.quantity ?? addon.minQty, 10) || addon.minQty);
                  const successKey = `${activeRoomId}:${addon.id}`;

                  return (
                    <div key={addon.id} className="addon-card">
                      <div className="addon-image">
                        <img src={addon.image} alt={addon.name} />
                      </div>

                      <div className="addon-content">
                        <div className="addon-header">
                          <div>
                            <h3 className="addon-name">{addon.name}</h3>
                            <span className="addon-category">{addon.subtitle}</span>
                          </div>

                          <div className="addon-price-wrap">
                            <span className="addon-price">{formatCurrency(addon.price)}</span>
                            <span className="addon-pricing-label">{addon.pricingLabel}</span>
                            <span className="addon-tax-excl">Including Taxes and Fees</span>
                          </div>
                        </div>

                        <p className="addon-description">{addon.description}</p>

                        <div className="addon-policies">
                          <h4>Policies</h4>
                          <p>{addon.policy}</p>
                        </div>

                        {selectedAddon && (
                          <p className="addon-selected-meta">
                            Selected: Qty {selectedQty} - {formatCurrency(getAddonLineTotal(selectedAddon))}
                          </p>
                        )}

                        <button
                          className={`addon-btn ${successKey === addonSuccessKey ? 'added' : ''}`}
                          onClick={() => openQuantityModal(addon)}
                          disabled={!activeRoomId}
                        >
                          {successKey === addonSuccessKey
                            ? 'ADDED TO MY STAY'
                            : isAddonSelected(addon.id, activeRoomId)
                              ? 'UPDATE IN MY STAY'
                              : 'ADD TO MY STAY'}
                        </button>
                      </div>
                    </div>
                  );
                })}
              </div>
            ))}
          </div>
        </div>

        <aside className="cart-sidebar">
          <div className="cart-card">
            <h2 className="cart-title">
              Your Cart: {selectedRooms.length} Item{selectedRooms.length > 1 ? 's' : ''}
            </h2>

            <div className="cart-items">
              {selectedRooms.map((room, idx) => (
                <div
                  key={room.roomId}
                  className={`cart-item ${room.roomId === activeRoomId ? 'highlight' : ''}`}
                  onClick={() => setActiveRoomId(room.roomId)}
                  style={{ cursor: 'pointer' }}
                >
                  <div className="item-label">ROOM {idx + 1}</div>
                  <h4 className="item-name">{room.name || 'Room'}</h4>
                  <p className="item-description">{room.description || 'Comfortable room'}</p>
                  <p className="item-price">{formatCurrency(getRoomPrice(room))}</p>
                  <p className="item-duration">{nights} Night stay</p>

                  {roomAddons[room.roomId]?.length > 0 && (
                    <div className="room-addons-list">
                      <div className="addons-label">Add-ons:</div>
                      {roomAddons[room.roomId].map((addon) => {
                        const qty = Math.max(1, parseInt(addon.quantity ?? 1, 10) || 1);
                        return (
                          <div key={addon.id} className="addon-item">
                            <div className="addon-item-info">
                              <span className="addon-item-name">{addon.name}</span>
                              <span className="addon-item-meta">
                                Qty {qty} x {formatCurrency(addon.price)}
                              </span>
                              <span className="addon-item-price">{formatCurrency(getAddonLineTotal(addon))}</span>
                            </div>
                            <button
                              className="addon-remove-btn"
                              onClick={(e) => {
                                e.stopPropagation();
                                removeAddonFromRoom(room.roomId, addon.id);
                              }}
                              title="Remove add-on"
                            >
                              x
                            </button>
                          </div>
                        );
                      })}
                    </div>
                  )}

                  <div className="item-actions">
                    <button
                      className="action-link remove"
                      onClick={(e) => {
                        e.stopPropagation();
                        confirmRemoveRoom(room);
                      }}
                    >
                      Remove
                    </button>
                  </div>
                </div>
              ))}
            </div>

            <div className="cart-summary">
              <div className="summary-row total">
                <span>Total</span>
                <span>{formatCurrency(pricing.total)}</span>
              </div>
              <div className="summary-row">
                <span>Required Down Payment (DP)</span>
                <span>{formatCurrency(downpaymentAmount)}</span>
              </div>
              <div className="summary-row">
                <span>Balance at Check-in</span>
                <span>{formatCurrency(remainingBalance)}</span>
              </div>
            </div>

            <Button variant="primary" fullWidth onClick={handleContinue}>CONTINUE</Button>
            <Button variant="outline" fullWidth onClick={handleBack}>ADD A ROOM</Button>
          </div>
        </aside>
      </section>

      <div className="addons-mobile-actions" aria-label="Booking actions">
        <Button variant="outline" onClick={handleBack}>ADD A ROOM</Button>
        <Button variant="primary" onClick={handleContinue}>CONTINUE</Button>
      </div>
    </div>
  );
};

export default AddOns;
