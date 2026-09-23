import React, { useEffect, useMemo, useState } from 'react';
import { AlertTriangle, Loader2 } from 'lucide-react';

const toDatetimeLocal = (dateValue = new Date()) => {
  const date = new Date(dateValue);
  const pad = (value) => String(value).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

const formatModalDate = (rawDate) => {
  if (!rawDate) return '—';
  const date = new Date(rawDate);
  if (!Number.isNaN(date.getTime())) {
    return date.toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    });
  }
  return String(rawDate);
};

const NoShowConfirmModal = ({
  open,
  guest,
  loading,
  errorMessage,
  cutoffLabel,
  onClose,
  onConfirm,
}) => {
  const [contactedGuest, setContactedGuest] = useState(false);
  const [contactMethod, setContactMethod] = useState('phone');
  const [contactedAt, setContactedAt] = useState(toDatetimeLocal());
  const [contactOutcome, setContactOutcome] = useState('no_response');
  const [contactNotes, setContactNotes] = useState('');

  useEffect(() => {
    if (open) {
      setContactedGuest(false);
      setContactMethod('phone');
      setContactedAt(toDatetimeLocal());
      setContactOutcome('no_response');
      setContactNotes('');
    }
  }, [open, guest?.id]);

  const roomText = useMemo(
    () => guest?.assignedRoom || guest?.room || 'N/A',
    [guest]
  );
  const checkInText = useMemo(
    () => formatModalDate(guest?.checkInRaw || guest?.checkIn),
    [guest]
  );

  if (!open || !guest) return null;

  const canConfirm = contactedGuest
    && contactMethod
    && contactedAt
    && contactOutcome
    && !loading;

  const handleConfirm = () => {
    onConfirm({
      contacted_guest: contactedGuest,
      contact_method: contactMethod,
      contacted_at: contactedAt,
      contact_outcome: contactOutcome,
      contact_notes: contactNotes.trim() || null,
    });
  };

  return (
    <div className="modal-lay" onClick={onClose}>
      <div className="modal-square modal-square-no-show" onClick={(event) => event.stopPropagation()}>
        <div className="no-show-title">
          <AlertTriangle size={22} aria-hidden="true" />
          <h3>Mark as No-Show?</h3>
        </div>

        <div className="no-show-summary">
          <div className="no-show-row"><span>Guest:</span><strong>{guest.name || 'N/A'}</strong></div>
          <div className="no-show-row"><span>Booking:</span><strong>{guest.referenceNumber || guest.bookingId || 'N/A'}</strong></div>
          <div className="no-show-row"><span>Room:</span><strong>{roomText}</strong></div>
          <div className="no-show-row"><span>Check-In:</span><strong>{checkInText}</strong></div>
          <div className="no-show-row"><span>Eligible after:</span><strong>{cutoffLabel || '6:00 PM'}</strong></div>
        </div>

        <div className="no-show-form-grid">
          <label className="no-show-field">
            <span>Contact method *</span>
            <select value={contactMethod} onChange={(event) => setContactMethod(event.target.value)} disabled={loading}>
              <option value="phone">Phone call</option>
              <option value="sms">SMS</option>
              <option value="email">Email</option>
              <option value="other">Other</option>
            </select>
          </label>

          <label className="no-show-field">
            <span>Contact time *</span>
            <input
              type="datetime-local"
              value={contactedAt}
              max={toDatetimeLocal(new Date(Date.now() + (5 * 60 * 1000)))}
              onChange={(event) => setContactedAt(event.target.value)}
              disabled={loading}
            />
          </label>

          <label className="no-show-field no-show-field-full">
            <span>Contact result *</span>
            <select value={contactOutcome} onChange={(event) => setContactOutcome(event.target.value)} disabled={loading}>
              <option value="no_response">No response</option>
              <option value="number_unreachable">Number unreachable</option>
              <option value="message_left">Message left or sent</option>
              <option value="other">Other</option>
            </select>
          </label>

          <label className="no-show-field no-show-field-full">
            <span>Contact notes</span>
            <textarea
              rows="3"
              maxLength="500"
              value={contactNotes}
              onChange={(event) => setContactNotes(event.target.value)}
              placeholder="Example: Called twice and sent an SMS."
              disabled={loading}
            />
          </label>
        </div>

        <label className="no-show-checkbox">
          <input
            type="checkbox"
            checked={contactedGuest}
            onChange={(event) => setContactedGuest(event.target.checked)}
            disabled={loading}
          />
          <span>I confirm that I attempted to contact the guest and the details above are accurate.</span>
        </label>

        {errorMessage ? <div className="no-show-error">{errorMessage}</div> : null}

        <div className="no-show-note">
          <strong>Important:</strong> Marking a no-show releases only rooms that are safe to release.
          Any paid amount requires manual financial review and is not automatically refunded or forfeited.
          An email will be queued only when the guest has an email address.
        </div>

        <div className="modal-actions">
          <button className="btn-secondary" onClick={onClose} disabled={loading}>Cancel</button>
          <button className="btn-no-show-confirm" onClick={handleConfirm} disabled={!canConfirm}>
            {loading ? <><Loader2 size={15} className="spin" /> Confirming…</> : 'Confirm No-Show'}
          </button>
        </div>
      </div>
    </div>
  );
};

export default NoShowConfirmModal;
