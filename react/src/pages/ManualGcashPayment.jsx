import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  AlertCircle,
  AlertTriangle,
  ArrowLeft,
  BadgeCheck,
  Check,
  CheckCircle,
  Clock,
  Download,
  FileCheck2,
  Headphones,
  Info,
  Loader,
  LockKeyhole,
  RefreshCw,
  ShieldCheck,
  Smartphone,
  Upload,
} from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { format } from 'date-fns';
import clientBookingService from '../services/client/clientBookingService';
import BookingProgress from '../components/BookingProgress';
import { StaffDateTimePicker } from '../components/StaffDatePicker';
import { showToast } from '../utils/showToast';
import { formatCurrency } from '../utils/currency';
import './ManualGcashPayment.css';

const initialForm = {
  transaction_reference: '',
  sender_name: '',
  amount: '',
  paid_at: '',
  declaration_accepted: false,
  proof: null,
};

const GCASH_REFERENCE_LENGTH = 13;

const normalizeGcashReference = (value = '') => (
  String(value).replace(/\D/g, '').slice(0, GCASH_REFERENCE_LENGTH)
);

const ManualGcashPayment = ({ bookingId }) => {
  const navigate = useNavigate();
  const [data, setData] = useState(null);
  const [form, setForm] = useState(initialForm);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [paidAtError, setPaidAtError] = useState(false);
  const submissionInFlightRef = useRef(false);

  const latestStatus = String(data?.latest_submission?.status || '');
  const awaitingReview = ['pending_verification', 'escalated'].includes(latestStatus);
  const approved = data?.payment_status === 'completed' || data?.lifecycle_status === 'paid';
  const paymentWindowExpired = Boolean(
    data?.payment_due_at && new Date(data.payment_due_at).getTime() <= Date.now(),
  );
  const canResubmit = latestStatus === 'rejected'
    && Number(data?.attempts_remaining || 0) > 0
    && !paymentWindowExpired;
  const correctionClosed = latestStatus === 'rejected' && !canResubmit;
  const isRebookingAdjustment = Boolean(data?.is_rebooking_adjustment);

  const deadline = useMemo(() => {
    if (!data?.payment_due_at) return '';
    return new Date(data.payment_due_at).toLocaleString();
  }, [data?.payment_due_at]);

  const load = useCallback(async (prepare = false) => {
    try {
      setError('');
      const response = prepare
        ? await clientBookingService.prepareManualGcashPayment(bookingId)
        : await clientBookingService.getManualGcashStatus(bookingId);
      setData(response.data);
      setForm((current) => ({
        ...current,
        amount: current.amount || String(response.data?.required_amount ?? ''),
      }));
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'Unable to load the manual GCash payment page.');
    } finally {
      setLoading(false);
    }
  }, [bookingId]);

  useEffect(() => {
    load(true);
  }, [load]);

  useEffect(() => {
    if (!awaitingReview || approved) return undefined;
    const timer = setInterval(() => load(false), 20000);
    return () => clearInterval(timer);
  }, [awaitingReview, approved, load]);

  useEffect(() => {
    if (!approved) return;
    const timer = setTimeout(() => {
      if (isRebookingAdjustment) {
        navigate('/my-booking', { replace: true });
      } else {
        navigate('/confirmation', {
          replace: true,
          state: {
            bookingId: String(bookingId),
            bookingReference: data?.booking_reference || '',
          },
        });
      }
    }, 1500);
    return () => clearTimeout(timer);
  }, [approved, bookingId, data?.booking_reference, isRebookingAdjustment, navigate]);

  const update = (field, value) => setForm((current) => ({
    ...current,
    [field]: field === 'transaction_reference' ? normalizeGcashReference(value) : value,
  }));

  const submit = async (event) => {
    event.preventDefault();
    if (submissionInFlightRef.current) return;
    if (!new RegExp(`^[0-9]{${GCASH_REFERENCE_LENGTH}}$`).test(form.transaction_reference)) {
      showToast('Enter the complete 13-digit GCash Transaction Reference ID.', 'error');
      return;
    }
    if (!form.proof) {
      showToast('Choose a payment proof image or PDF.', 'error');
      return;
    }
    if (!form.paid_at) {
      setPaidAtError(true);
      showToast('Select the date and time shown on your GCash receipt.', 'error');
      return;
    }

    submissionInFlightRef.current = true;
    const payload = new FormData();
    payload.append('transaction_reference', form.transaction_reference);
    payload.append('sender_name', form.sender_name);
    payload.append('amount', form.amount);
    payload.append('paid_at', form.paid_at);
    payload.append('declaration_accepted', form.declaration_accepted ? '1' : '0');
    payload.append('proof', form.proof);

    setSubmitting(true);
    try {
      const response = await clientBookingService.submitManualGcashProof(bookingId, payload);
      setData(response.data);
      setForm(initialForm);
      showToast(response.message, 'success');
    } catch (requestError) {
      const validation = requestError.response?.data?.errors;
      const firstValidation = validation ? Object.values(validation).flat()[0] : null;
      showToast(firstValidation || requestError.response?.data?.message || 'Proof submission failed.', 'error');
    } finally {
      submissionInFlightRef.current = false;
      setSubmitting(false);
    }
  };

  const downloadQr = async () => {
    try {
      const response = await fetch(data.merchant.qr_url, { credentials: 'include', cache: 'no-store' });
      if (!response.ok) throw new Error('QR download failed');
      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `hotel-gcash-${data.booking_reference}.png`;
      link.click();
      URL.revokeObjectURL(url);
    } catch {
      showToast('Unable to download the merchant QR.', 'error');
    }
  };

  if (loading) {
    return (
      <main className="manual-gcash-state-shell">
        <BookingProgress currentStep={5} />
        <section className="manual-gcash-state manual-gcash-loading-state" aria-live="polite">
          <div className="manual-gcash-state-icon"><Loader className="spin" /></div>
          <span className="manual-gcash-state-kicker">Secure payment</span>
          <h1>Preparing your GCash payment</h1>
          <p>We are loading the official hotel merchant details and your exact downpayment.</p>
        </section>
      </main>
    );
  }

  if (error) {
    return (
      <main className="manual-gcash-state-shell">
        <BookingProgress currentStep={5} />
        <section className="manual-gcash-state manual-gcash-error-state" role="alert">
          <div className="manual-gcash-state-icon"><AlertTriangle /></div>
          <span className="manual-gcash-state-kicker">Payment unavailable</span>
          <h1>We couldn&apos;t open this payment</h1>
          <p>{error}</p>
          <div className="manual-gcash-state-actions">
            <button type="button" className="manual-gcash-primary-action" onClick={() => load(true)}><RefreshCw size={18} /> Try Again</button>
            <button type="button" className="manual-gcash-secondary-action" onClick={() => navigate('/my-booking')}><ArrowLeft size={18} /> My Bookings</button>
          </div>
        </section>
      </main>
    );
  }

  if (approved) {
    return (
      <main className="manual-gcash-state-shell">
        <BookingProgress currentStep={6} />
        <section className="manual-gcash-state manual-gcash-approved-state" aria-live="polite">
          <div className="manual-gcash-state-icon"><CheckCircle /></div>
          <span className="manual-gcash-status-pill approved"><Check size={14} /> Verified</span>
          <h1>Payment approved</h1>
          <p>{isRebookingAdjustment ? 'Your additional payment is verified. Hotel staff can now complete the room change.' : 'Your booking is confirmed. We’re preparing your confirmation details now.'}</p>
          <div className="manual-gcash-redirecting"><Loader size={16} className="spin" /> {isRebookingAdjustment ? 'Returning to My Bookings...' : 'Redirecting to confirmation...'}</div>
        </section>
      </main>
    );
  }

  if (correctionClosed) {
    return (
      <main className="manual-gcash-state-shell">
        <BookingProgress currentStep={5} />
        <section className="manual-gcash-state manual-gcash-error-state" role="alert">
          <div className="manual-gcash-state-icon"><AlertTriangle /></div>
          <span className="manual-gcash-state-kicker">Payment window closed</span>
          <h1>This proof can no longer be corrected</h1>
          <p>{isRebookingAdjustment ? 'The payment deadline or submission limit was reached. Your existing booking remains confirmed and unchanged.' : 'The payment deadline or allowed submission limit has been reached. The booking was not confirmed.'}</p>
          <div className="manual-gcash-state-actions">
            <button type="button" className="manual-gcash-secondary-action" onClick={() => navigate('/my-booking')}><ArrowLeft size={18} /> Return to My Bookings</button>
          </div>
        </section>
      </main>
    );
  }

  if (awaitingReview) {
    return (
      <main className="manual-gcash-state-shell">
        <BookingProgress currentStep={5} />
        <section className="manual-gcash-state manual-gcash-review-state">
          <div className="manual-gcash-state-icon"><Clock /></div>
          <span className={`manual-gcash-status-pill ${latestStatus}`}>
            {latestStatus === 'escalated' ? <AlertCircle size={14} /> : <Clock size={14} />}
            {latestStatus === 'escalated' ? 'Priority review' : 'Verification in progress'}
          </span>
          <span className="manual-gcash-state-kicker">Proof received securely</span>
          <h1>{latestStatus === 'escalated' ? 'Your payment needs administrator review' : 'We are verifying your payment'}</h1>
          <p>Hotel staff are matching your submitted proof with the official merchant GCash record.</p>

          <div className="manual-gcash-review-notice">
            <Info size={21} />
            <div><strong>Do not pay again.</strong><span>A screenshot does not automatically confirm a booking. Most reviews finish within 15 minutes.</span></div>
          </div>

          <div className="manual-gcash-reference"><span>Booking reference</span><strong>{data.booking_reference}</strong></div>

          <div className="manual-gcash-progress" aria-label="Payment verification progress">
            <div className="complete"><span><Check size={15} /></span><strong>Submitted</strong><small>Proof received</small></div>
            <div className="active"><span><Loader size={15} className="spin" /></span><strong>Verifying</strong><small>Staff review</small></div>
            <div><span><Check size={15} /></span><strong>Confirmed</strong><small>Email sent</small></div>
          </div>

          <div className="manual-gcash-state-actions">
            <button type="button" className="manual-gcash-primary-action" onClick={() => load(false)}><RefreshCw size={18} /> Refresh Status</button>
            <button type="button" className="manual-gcash-secondary-action" onClick={() => navigate('/my-booking')}>Return to My Bookings</button>
          </div>
          <p className="manual-gcash-help"><Headphones size={15} /> You may safely leave this page. We&apos;ll email you after verification.</p>
        </section>
      </main>
    );
  }

  return (
    <main className="manual-gcash-shell">
      <BookingProgress currentStep={5} />
      <header className="manual-gcash-page-heading">
        <div>
          <span className="manual-gcash-eyebrow"><LockKeyhole size={14} /> Secure manual payment</span>
          <h1>{isRebookingAdjustment ? 'Complete your room change payment' : 'Complete your GCash downpayment'}</h1>
          <p>{isRebookingAdjustment ? 'Pay the exact additional amount for your requested room change, then submit proof for hotel verification.' : 'Scan the official merchant QR, then submit your transaction details for hotel verification.'}</p>
        </div>
        <div className="manual-gcash-heading-reference"><span>Booking reference</span><strong>{data.booking_reference}</strong></div>
      </header>

      <div className="manual-gcash-page">
        <aside className="manual-gcash-merchant-card">
          <div className="manual-gcash-merchant-heading">
            <div className="manual-gcash-merchant-icon"><Smartphone size={22} /></div>
            <div><span>Step 1</span><h2>Scan and pay</h2></div>
          </div>
          <div className="manual-gcash-verified"><BadgeCheck size={17} /> Official hotel merchant</div>
          <div className="manual-gcash-qr-frame"><img src={data.merchant.qr_url} alt="Official hotel GCash merchant QR" /></div>
          <button type="button" className="manual-gcash-download" onClick={downloadQr}><Download size={17} /> Download QR</button>
          <dl>
            <div><dt>Merchant</dt><dd>{data.merchant.merchant_name}</dd></div>
            <div><dt>Account name</dt><dd>{data.merchant.account_name}</dd></div>
            {data.merchant.account_number && <div><dt>Account number</dt><dd>{data.merchant.account_number}</dd></div>}
          </dl>
          <div className="manual-gcash-security-note"><ShieldCheck size={18} /><span>Only pay the merchant details shown on this secure page.</span></div>
        </aside>

        <section className="manual-gcash-proof-card">
          <div className="manual-gcash-proof-heading">
            <div className="manual-gcash-proof-title">
              <div className="manual-gcash-proof-icon"><FileCheck2 size={20} /></div>
              <div><span>Step 2</span><h2>Submit payment proof</h2></div>
            </div>
            <div className="manual-gcash-secure-label"><LockKeyhole size={14} /> Private upload</div>
          </div>

          <div className="manual-gcash-required">
            <div><span>{isRebookingAdjustment ? 'Exact additional payment' : 'Exact downpayment'}</span><small>Pay this amount only</small></div>
            <strong>{formatCurrency(data.required_amount)}</strong>
          </div>
          <div className="manual-gcash-deadline"><Clock size={18} /><div><span>Payment deadline</span><strong>{deadline}</strong></div></div>
          <div className="manual-gcash-warning"><AlertTriangle size={19} /><span>Verify the merchant and exact amount before paying. Never reuse one GCash transaction for another booking.</span></div>

          {canResubmit && (
            <div className="manual-gcash-rejection" role="alert">
              <AlertCircle size={20} />
              <div><strong>Previous proof rejected</strong><p>{data.latest_submission.review_reason}</p><span>{data.attempts_remaining} submission attempt(s) remaining.</span></div>
            </div>
          )}

          <form onSubmit={submit}>
            <div className="manual-gcash-form-section">
              <div className="manual-gcash-form-section-title"><span>1</span><div><strong>Transaction details</strong><small>Copy these directly from your GCash receipt.</small></div></div>
              <div className="manual-gcash-form-grid">
                <label className="manual-gcash-field manual-gcash-field-wide">
                  <span>13-digit GCash Transaction Reference ID <b>*</b></span>
                  <input
                    type="text"
                    inputMode="numeric"
                    autoComplete="off"
                    value={form.transaction_reference}
                    onChange={(event) => update('transaction_reference', event.target.value)}
                    placeholder="Example: 1234567890123"
                    pattern="[0-9]{13}"
                    aria-describedby="gcash-reference-help"
                    required
                  />
                  <span className="manual-gcash-field-meta" id="gcash-reference-help">
                    <small>Copy the Transaction Reference ID from your GCash receipt.</small>
                    <small>{form.transaction_reference.length}/{GCASH_REFERENCE_LENGTH}</small>
                  </span>
                </label>
                <label className="manual-gcash-field manual-gcash-field-wide"><span>GCash sender name <b>*</b></span><input value={form.sender_name} onChange={(event) => update('sender_name', event.target.value)} placeholder="Name shown on the GCash receipt" required maxLength={120} /></label>
                <label className="manual-gcash-field"><span>Amount paid <b>*</b></span><div className="manual-gcash-money-input"><span>₱</span><input type="number" min="0.01" step="0.01" value={form.amount} onChange={(event) => update('amount', event.target.value)} required /></div></label>
                <div className="manual-gcash-field">
                  <span>Date and time paid <b>*</b></span>
                  <StaffDateTimePicker
                    value={form.paid_at}
                    onChange={(value) => { update('paid_at', value); setPaidAtError(false); }}
                    max={format(new Date(), "yyyy-MM-dd'T'HH:mm")}
                    ariaLabel="Date and time paid"
                    collisionPadding={12}
                    popoverClassName="manual-gcash-date-popover"
                    invalid={paidAtError}
                  />
                  {paidAtError && <small className="manual-gcash-field-error" role="alert">Select the date and time shown on your GCash receipt.</small>}
                </div>
              </div>
            </div>

            <div className="manual-gcash-form-section">
              <div className="manual-gcash-form-section-title"><span>2</span><div><strong>Payment proof</strong><small>Upload a clear, complete receipt for staff verification.</small></div></div>
              <label className={`manual-gcash-file ${form.proof ? 'has-file' : ''}`}>
                <div className="manual-gcash-file-icon">{form.proof ? <FileCheck2 size={27} /> : <Upload size={27} />}</div>
                <div><strong>{form.proof?.name || 'Choose proof image or PDF'}</strong><span>{form.proof ? 'Ready to submit securely' : 'JPEG, PNG, WebP, or PDF · Maximum 5 MB'}</span></div>
                <em>{form.proof ? 'Change file' : 'Browse file'}</em>
                <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={(event) => update('proof', event.target.files?.[0] || null)} required />
              </label>
            </div>

            <label className="manual-gcash-declaration"><input type="checkbox" checked={form.declaration_accepted} onChange={(event) => update('declaration_accepted', event.target.checked)} required /><span><strong>I confirm these payment details are truthful.</strong> I understand hotel staff must verify them against the official merchant GCash record before {isRebookingAdjustment ? 'my room change receives final approval' : 'my booking is confirmed'}.</span></label>
            <button type="submit" className="manual-gcash-submit" disabled={submitting}>{submitting ? <Loader size={19} className="spin" /> : <ShieldCheck size={20} />} {submitting ? 'Uploading securely...' : 'Submit Proof for Verification'}</button>
            <p className="manual-gcash-privacy"><LockKeyhole size={14} /> Your proof is stored privately and can only be viewed by authorized hotel staff.</p>
          </form>
        </section>
      </div>
    </main>
  );
};

export default ManualGcashPayment;
