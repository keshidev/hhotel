import { toAmount } from './currency';

export const BOOKING_TAX_RATE = 0.12;

export const calculateBookingEstimate = ({
  roomsSubtotal = 0,
  addonsTotal = 0,
  discount = 0,
  taxRate = BOOKING_TAX_RATE,
} = {}) => {
  // Children aged 8+ are tracked for occupancy policy only.
  // No per-person surcharge is applied here because pricing is room-based.
  const rooms = toAmount(roomsSubtotal);
  const addons = toAmount(addonsTotal);
  const discountAmount = Math.max(0, toAmount(discount));

  const subtotal = rooms + addons;
  const total = Math.max(0, subtotal - discountAmount);
  const safeRate = Math.max(0, Number(taxRate) || 0);
  const taxAmount = safeRate > 0 ? total - (total / (1 + safeRate)) : 0;
  const amountBeforeTax = Math.max(0, total - taxAmount);

  return {
    roomsSubtotal: rooms,
    addonsTotal: addons,
    subtotal,
    discount: discountAmount,
    discountedSubtotal: total,
    amountBeforeTax,
    taxAmount,
    total,
  };
};
