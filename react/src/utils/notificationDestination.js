const text = (value) => value == null ? '' : String(value);

export function notificationDate(value) {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
  }).format(date);
}

export function notificationMessage(notification) {
  const meta = notification.meta || notification.data || {};
  if (notification.type === 'payment_pending_summary' && meta.pending_count != null) {
    return `${meta.pending_count} payment record(s) were pending when this summary was sent. Pending records may not have a submitted payment proof.`;
  }
  return notification.message;
}

// Destinations remain within the signed-in role's existing pages and permissions.
export function notificationDestination(notification, role, config) {
  const meta = notification.meta || notification.data || {};
  const type = notification.type;
  const prefix = role === 'admin' ? '/admin' : '/receptionist';
  let route = config[`route_${role}`];
  let label = config[`cta_${role}`];
  // Some producers store the database ID; others store the public reference.
  const bookingReference = text(meta.booking_reference || meta.reference_number || meta.booking_id);
  let search = bookingReference;
  let status = '';
  let date = '';
  if (route === '/admin/dashboard') {
    route = type.startsWith('staff_') ? '/admin/audit-trail' : '/admin/reports/reservation';
    label = type.startsWith('staff_') ? 'View related activity' : 'View reservation report';
  }
  if (type.startsWith('payment_') || type === 'guest_checkout_with_balance') {
    if (route) {
      route = `${prefix}/manual-gcash-reviews?section=records`;
      search = text(meta.booking_reference || meta.reference_number || meta.booking_id || meta.payment_id);
      label = 'View payment records';
      if (type === 'payment_pending_summary' || type === 'payment_pending_reminder') {
        status = 'Pending';
        label = 'View pending records';
      }
    }
  }
  if (type === 'daily_summary') {
    date = notificationDate(notification.created_at);
    search = '';
    label = 'View revenue for this date';
  }
  if (route === '/admin/rooms') search = text(meta.room_number || meta.room);
  if (role === 'receptionist' && route === '/receptionist/reservation' && !search) search = text(meta.room_number || meta.room);
  if (type.startsWith('room_transfer_') && meta.request_id) search = `RTR${text(meta.request_id).padStart(4, '0')}`;
  if (type.startsWith('early_checkin_') && role === 'admin' && meta.request_id) search = `ECI${text(meta.request_id).padStart(4, '0')}`;
  if (type === 'contact_inquiry') search = text(meta.reference_number || meta.email || meta.guest_email || meta.guest_name);
  return {
    route, label,
    state: { notificationTarget: {
      id: notification.id, title: notification.title, type, search, status, date,
      checkIn: text(meta.check_in), checkOut: text(meta.check_out),
      receivedAt: notification.created_at,
      bookingDatabaseId: /^\d+$/.test(bookingReference) ? bookingReference : '',
    } },
  };
}
