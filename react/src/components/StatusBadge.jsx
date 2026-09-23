import './StatusBadge.css';

const STATUS_TONES = {
  accepted: 'info',
  active: 'success',
  approved: 'success',
  available: 'success',
  awaiting_gcash: 'warning',
  awaiting_payment: 'warning',
  cancelled: 'danger',
  checked_in: 'info',
  checked_out: 'neutral',
  cleaning: 'info',
  completed: 'success',
  confirmed: 'success',
  consumed: 'success',
  due_soon: 'warning',
  escalated: 'danger',
  expected: 'info',
  expired: 'neutral',
  exception_open: 'danger',
  exception_resolved: 'success',
  failed: 'danger',
  for_verification: 'warning',
  inactive: 'neutral',
  in_progress: 'warning',
  maintenance: 'danger',
  matched: 'success',
  new: 'info',
  no_show: 'dark',
  no_payment: 'neutral',
  occupied: 'info',
  paid: 'success',
  partial: 'warning',
  pending: 'warning',
  pending_approval: 'warning',
  pending_verification: 'warning',
  private: 'neutral',
  published: 'success',
  refunded: 'success',
  refund_pending: 'warning',
  rejected: 'danger',
  reserved: 'success',
  resolved: 'success',
  room_pending: 'warning',
  spam: 'danger',
  upcoming: 'info',
  unreconciled: 'warning',
  unpaid: 'danger',
  verified: 'success',
  overdue: 'danger',
};

const normalizeStatus = (status) => String(status ?? '')
  .trim()
  .toLowerCase()
  .replace(/[^a-z0-9]+/g, '_')
  .replace(/^_+|_+$/g, '');

const formatStatus = (status) => {
  const normalized = normalizeStatus(status);

  if (!normalized) return '—';

  return normalized
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
};

export default function StatusBadge({ status, label, tone, className = '' }) {
  const normalized = normalizeStatus(status ?? label);
  const resolvedTone = tone || STATUS_TONES[normalized] || 'neutral';
  const text = label ?? formatStatus(status);

  return (
    <span className={`hhotel-status-badge hhotel-status-badge--${resolvedTone} ${className}`.trim()}>
      {text}
    </span>
  );
}
