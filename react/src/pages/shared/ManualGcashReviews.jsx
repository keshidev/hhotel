import { useNotificationTarget } from '../../hooks/useNotificationTarget';
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  AlertCircle,
  ArrowRight,
  CalendarClock,
  CheckCircle,
  ClipboardCheck,
  ChevronDown,
  CreditCard,
  Eye,
  FileText,
  Filter,
  Hash,
  Loader,
  RefreshCw,
  Search,
  ShieldCheck,
  UserRound,
  X,
  XCircle,
} from 'lucide-react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import adminApi from '../../services/adminApi';
import receptionistApi from '../../services/receptionistApi';
import { showToast } from '../../utils/showToast';
import { formatCurrency } from '../../utils/currency';
import StatusBadge from '../../components/StatusBadge';
import TableActionButton from '../../components/TableActionButton';
import ManualGcashReconciliationPanel from './ManualGcashReconciliationPanel';
import PaymentRecordsPanel from '../receptionist/Payment';
import './ManualGcashReviews.css';

const initialReview = {
  merchant_reference: '',
  verified_amount: '',
  merchant_paid_at: '',
  merchant_record_confirmed: false,
  admin_override: false,
  override_reason: '',
  reason: '',
};

const emptyOperationsSummary = {
  pending_verification: 0,
  escalated_review: 0,
  pending_review: 0,
  unreconciled: 0,
  open_exceptions: 0,
  refund_required: 0,
};

const ManualGcashReviews = ({ role }) => {
  const api = role === 'admin' ? adminApi : receptionistApi;
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [reviewQueue, setReviewQueue] = useState('ready');
  const [reviewHistoryStatus, setReviewHistoryStatus] = useState('');
  const [reconciliationQueue, setReconciliationQueue] = useState('needs_reconciliation');
  const [operationsSummary, setOperationsSummary] = useState(emptyOperationsSummary);
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState(null);
  const [proofUrl, setProofUrl] = useState('');
  const [proofUnavailable, setProofUnavailable] = useState('');
  const [action, setAction] = useState('approve');
  const [saving, setSaving] = useState(false);
  const [review, setReview] = useState(initialReview);
  const [section, setSection] = useState(() => {
    const requested = searchParams.get('section');
    return ['reviews', 'reconciliation', 'records'].includes(requested) ? requested : 'reviews';
  });
  const [refreshKey, setRefreshKey] = useState(0);
  useEffect(() => {
    const requested = searchParams.get('section');
    setSection(['reviews', 'reconciliation', 'records'].includes(requested) ? requested : 'reviews');
  }, [searchParams]);
  useNotificationTarget((target) => {
    setSearch(target.search); setReviewQueue('ready'); setReviewHistoryStatus(''); closeReview();
  });

  const canReview = useMemo(
    () => selected && (
      selected.status === 'pending_verification'
      || (selected.status === 'escalated' && role === 'admin')
    ),
    [role, selected],
  );
  const requiresAdminReview = selected?.status === 'escalated' && role !== 'admin';

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const response = await api.get('/manual-gcash-reviews', {
        params: {
          queue: reviewQueue,
          status: reviewQueue === 'history' ? reviewHistoryStatus || undefined : undefined,
          search: search || undefined,
        },
      });
      setRows(response.data?.data || []);
    } catch (error) {
      showToast(error.response?.data?.message || 'Unable to load manual GCash reviews.', 'error');
    } finally {
      setLoading(false);
    }
  }, [api, reviewHistoryStatus, reviewQueue, search]);

  const loadOperationsSummary = useCallback(async () => {
    try {
      const response = await api.get('/manual-gcash-reconciliations/summary');
      setOperationsSummary({ ...emptyOperationsSummary, ...(response.data || {}) });
    } catch (error) {
      showToast(error.response?.data?.message || 'Unable to load Manual GCash work counts.', 'error');
    }
  }, [api]);

  useEffect(() => { load(); }, [load]);
  useEffect(() => { loadOperationsSummary(); }, [loadOperationsSummary]);
  useEffect(() => () => { if (proofUrl) URL.revokeObjectURL(proofUrl); }, [proofUrl]);

  const closeReview = () => {
    setSelected(null);
    if (proofUrl) URL.revokeObjectURL(proofUrl);
    setProofUrl('');
    setProofUnavailable('');
  };

  const open = async (row) => {
    setSelected(row);
    setAction('approve');
    setReview({
      ...initialReview,
      merchant_reference: row.transaction_reference,
      verified_amount: String(row.submitted_amount),
      merchant_paid_at: row.paid_at_local || '',
    });
    if (proofUrl) URL.revokeObjectURL(proofUrl);
    setProofUrl('');
    setProofUnavailable('');
    if (row.proof?.available === false) {
      setProofUnavailable('This proof was securely removed after the approved 180-day retention period.');
      return;
    }
    try {
      const response = await api.get(`/manual-gcash-reviews/${row.id}/proof`, { responseType: 'blob' });
      setProofUrl(URL.createObjectURL(response.data));
    } catch (error) {
      if (error.response?.status === 410) {
        setProofUnavailable('This proof was securely removed after the approved 180-day retention period.');
      } else {
        showToast(error.response?.data?.message || 'Unable to open private proof.', 'error');
      }
    }
  };

  const submitReview = async () => {
    if (requiresAdminReview) {
      showToast('This escalated proof requires an administrator.', 'error');
      return;
    }

    setSaving(true);
    try {
      if (action === 'approve') {
        await api.post(`/manual-gcash-reviews/${selected.id}/approve`, {
          merchant_reference: review.merchant_reference,
          verified_amount: review.verified_amount,
          merchant_paid_at: review.merchant_paid_at,
          merchant_record_confirmed: review.merchant_record_confirmed,
          admin_override: role === 'admin' ? review.admin_override : false,
          override_reason: role === 'admin' ? review.override_reason : '',
        });
        showToast('Manual GCash payment approved.', 'success');
      } else {
        const response = await api.post(`/manual-gcash-reviews/${selected.id}/reject`, { reason: review.reason });
        showToast(response.data?.message || 'Payment proof rejected.', 'success');
      }
      closeReview();
      await Promise.all([load(), loadOperationsSummary()]);
    } catch (error) {
      const validation = error.response?.data?.errors;
      showToast(validation ? Object.values(validation).flat()[0] : error.response?.data?.message || 'Review action failed.', 'error');
    } finally {
      setSaving(false);
    }
  };

  const refresh = () => {
    loadOperationsSummary();
    if (section === 'reviews') load();
    else setRefreshKey((value) => value + 1);
  };

  const selectSection = (nextSection) => {
    setSection(nextSection);
    const nextParams = new URLSearchParams(searchParams);
    if (nextSection === 'reviews') nextParams.delete('section');
    else nextParams.set('section', nextSection);
    setSearchParams(nextParams, { replace: true });
  };

  const openWorkQueue = (nextSection, nextQueue) => {
    closeReview();
    selectSection(nextSection);
    if (nextSection === 'reviews') setReviewQueue(nextQueue);
    else setReconciliationQueue(nextQueue);
  };

  const proofQueueDetails = {
    ready: {
      title: 'Proofs ready for review',
      description: 'Verify these submissions against the official merchant GCash record.',
    },
    admin: {
      title: 'Administrator review required',
      description: role === 'admin'
        ? 'These overdue proofs need an administrator decision.'
        : 'These overdue proofs are visible here, but only an administrator can decide them.',
    },
    history: {
      title: 'Proof review history',
      description: 'Look up completed approvals and rejections.',
    },
  }[reviewQueue];

  return (
    <div className="manual-review-page">
      <div className="manual-review-page-header">
        <div>
          <h1>Payment Operations</h1>
          <p>Review GCash submissions, reconcile merchant records, and search the complete payment ledger.</p>
        </div>
        <button type="button" className="manual-review-refresh" onClick={refresh}>
          <RefreshCw size={15} /> Refresh
        </button>
      </div>

      <div className="manual-review-section-tabs" role="tablist" aria-label="Manual GCash operations">
        <button type="button" role="tab" aria-selected={section === 'reviews'} className={section === 'reviews' ? 'active' : ''} onClick={() => selectSection('reviews')}><span>1</span><div><strong>Proof Review</strong><small>Verify guest submissions</small></div><em>{operationsSummary.pending_review}</em></button>
        <button type="button" role="tab" aria-selected={section === 'reconciliation'} className={section === 'reconciliation' ? 'active' : ''} onClick={() => selectSection('reconciliation')}><span>2</span><div><strong>Daily Reconciliation</strong><small>Match approved payments</small></div><em>{operationsSummary.unreconciled + operationsSummary.open_exceptions}</em></button>
        <button type="button" role="tab" aria-selected={section === 'records'} className={section === 'records' ? 'active' : ''} onClick={() => selectSection('records')}><span>3</span><div><strong>Payment Records</strong><small>Search every payment method</small></div></button>
      </div>

      <div className="manual-review-work-overview" aria-label="Manual GCash work queues">
        <button type="button" onClick={() => openWorkQueue('reviews', 'ready')} className={section === 'reviews' && reviewQueue === 'ready' ? 'active' : ''}><ShieldCheck size={18} /><span><strong>{operationsSummary.pending_verification}</strong><small>Proofs ready for review</small></span><ArrowRight size={15} /></button>
        <button type="button" onClick={() => openWorkQueue('reviews', 'admin')} className={`attention ${section === 'reviews' && reviewQueue === 'admin' ? 'active' : ''}`}><AlertCircle size={18} /><span><strong>{operationsSummary.escalated_review}</strong><small>Need administrator</small></span><ArrowRight size={15} /></button>
        <button type="button" onClick={() => openWorkQueue('reconciliation', 'needs_reconciliation')} className={section === 'reconciliation' && reconciliationQueue === 'needs_reconciliation' ? 'active' : ''}><ClipboardCheck size={18} /><span><strong>{operationsSummary.unreconciled}</strong><small>Need reconciliation</small></span><ArrowRight size={15} /></button>
        <button type="button" onClick={() => openWorkQueue('reconciliation', 'exceptions')} className={`attention ${section === 'reconciliation' && reconciliationQueue === 'exceptions' ? 'active' : ''}`}><XCircle size={18} /><span><strong>{operationsSummary.open_exceptions}</strong><small>Open exceptions</small></span><ArrowRight size={15} /></button>
      </div>

      {operationsSummary.refund_required > 0 && (
        <button type="button" className="manual-review-related-action" onClick={() => navigate(role === 'admin' ? '/admin/cancellation-approvals' : '/receptionist/cancellation')}>
          <AlertCircle size={16} />
          <span><strong>{operationsSummary.refund_required} refund {operationsSummary.refund_required === 1 ? 'case requires' : 'cases require'} attention</strong><small>Open the cancellation and refund workflow</small></span>
          <ArrowRight size={16} />
        </button>
      )}

      {section === 'reviews' ? <>
      <div className="manual-review-queue-tabs" role="tablist" aria-label="Proof review queues">
        <button type="button" role="tab" aria-selected={reviewQueue === 'ready'} className={reviewQueue === 'ready' ? 'active' : ''} onClick={() => setReviewQueue('ready')}>Ready for Review <span>{operationsSummary.pending_verification}</span></button>
        <button type="button" role="tab" aria-selected={reviewQueue === 'admin'} className={reviewQueue === 'admin' ? 'active attention' : ''} onClick={() => setReviewQueue('admin')}>Needs Admin <span>{operationsSummary.escalated_review}</span></button>
        <button type="button" role="tab" aria-selected={reviewQueue === 'history'} className={reviewQueue === 'history' ? 'active' : ''} onClick={() => setReviewQueue('history')}>Review History</button>
      </div>
      <div className="manual-review-toolbar">
        <div className="manual-review-search">
          <Search size={16} />
          <input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            onKeyDown={(event) => event.key === 'Enter' && load()}
            placeholder="Search booking, guest, or GCash reference"
          />
        </div>
        {reviewQueue === 'history' && <div className="manual-review-filter">
          <Filter size={15} />
          <select value={reviewHistoryStatus} onChange={(event) => setReviewHistoryStatus(event.target.value)}>
            <option value="">All Completed Reviews</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
          </select>
          <ChevronDown size={14} />
        </div>}
        <button type="button" className="manual-review-search-button" onClick={load}>Search</button>
      </div>

      <div className="manual-review-table-card">
        <div className="manual-review-table-heading">
          <div><CreditCard size={17} /><span><strong>{proofQueueDetails.title}</strong><small>{proofQueueDetails.description}</small></span></div>
          <span>{rows.length} {rows.length === 1 ? 'record' : 'records'}</span>
        </div>

        {loading ? (
          <div className="manual-review-empty"><Loader className="spin" size={22} /><strong>Loading payment reviews...</strong></div>
        ) : rows.length === 0 ? (
          <div className="manual-review-empty"><ShieldCheck size={28} /><strong>No payment proofs found</strong><span>There are no records in this queue matching your search.</span></div>
        ) : (
          <div className="manual-review-table-wrap">
            <table>
              <thead>
                <tr><th>Submitted</th><th>Booking</th><th>Guest</th><th>GCash Reference</th><th>Amount</th><th>Status</th><th aria-label="Actions" /></tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id}>
                    <td className="manual-review-date">{new Date(row.submitted_at).toLocaleString()}</td>
                    <td className="manual-review-code">{row.booking_reference}{row.purpose === 'rebooking_adjustment' && <small className="manual-review-purpose">Room change</small>}</td>
                    <td className="manual-review-guest">{row.guest_name}</td>
                    <td className="manual-review-code">{row.transaction_reference}</td>
                    <td className="manual-review-amount">{formatCurrency(row.submitted_amount)}</td>
                    <td><StatusBadge status={row.status} /></td>
                    <td><TableActionButton label="Open payment proof" onClick={() => open(row)}><Eye size={14} /> {reviewQueue === 'history' || (row.status === 'escalated' && role !== 'admin') ? 'View' : 'Review'}</TableActionButton></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
      </> : section === 'reconciliation' ? <ManualGcashReconciliationPanel api={api} role={role} refreshKey={refreshKey} queue={reconciliationQueue} onQueueChange={setReconciliationQueue} onSummaryChange={setOperationsSummary} /> : <PaymentRecordsPanel key={refreshKey} embedded role={role} onOpenProofReview={() => selectSection('reviews')} />}

      {selected && section === 'reviews' && (
        <div
          className="manual-review-modal"
          role="dialog"
          aria-modal="true"
          aria-labelledby="manual-review-title"
          onMouseDown={(event) => event.target === event.currentTarget && closeReview()}
        >
          <div className="manual-review-dialog">
            <div className="manual-review-modal-header">
              <div><span>{selected.purpose === 'rebooking_adjustment' ? 'Room change payment' : 'Payment verification'}</span><h2 id="manual-review-title">Review {selected.booking_reference}</h2></div>
              <button type="button" className="manual-review-close" onClick={closeReview} aria-label="Close review"><X size={18} /></button>
            </div>

            <div className="manual-review-grid">
              <section className="manual-review-submission">
                <div className="manual-review-section-title"><FileText size={17} /><div><h3>Customer submission</h3><p>Details provided by the guest</p></div></div>
                <div className="manual-review-detail-list">
                  <div><span><UserRound size={14} /> Sender</span><strong>{selected.sender_name}</strong></div>
                  <div><span><Hash size={14} /> Reference</span><strong>{selected.transaction_reference}</strong></div>
                  <div><span><CreditCard size={14} /> Amount</span><strong>{formatCurrency(selected.submitted_amount)}</strong></div>
                  <div><span><CalendarClock size={14} /> Paid time</span><strong>{new Date(selected.paid_at).toLocaleString()}</strong></div>
                </div>
                <div className="manual-review-proof-heading"><span>Private payment proof</span><small>Visible to authorized staff only</small></div>
                <div className="manual-review-proof">
                  {proofUnavailable ? (
                    <div><ShieldCheck size={20} /> {proofUnavailable}</div>
                  ) : proofUrl ? (
                    selected.proof.mime_type === 'application/pdf'
                      ? <iframe src={proofUrl} title="Payment proof PDF" />
                      : <img src={proofUrl} alt="Private payment proof" />
                  ) : <div><Loader className="spin" size={20} /> Loading proof...</div>}
                </div>
              </section>

              <section className="manual-review-verification">
                <div className="manual-review-section-title"><ShieldCheck size={17} /><div><h3>Merchant record verification</h3><p>Match every field before approval</p></div></div>
                {canReview ? (
                  <>
                    <div className="manual-review-tabs">
                      <button type="button" className={action === 'approve' ? 'active' : ''} onClick={() => setAction('approve')}><CheckCircle size={15} /> Approve</button>
                      <button type="button" className={action === 'reject' ? 'active danger' : ''} onClick={() => setAction('reject')}><XCircle size={15} /> Reject Proof</button>
                    </div>

                    {action === 'approve' ? (
                      <div className="manual-review-form">
                        <label><span>Merchant GCash reference</span><input value={review.merchant_reference} onChange={(event) => setReview({ ...review, merchant_reference: event.target.value })} /></label>
                        <label><span>Verified amount</span><input type="number" step="0.01" value={review.verified_amount} onChange={(event) => setReview({ ...review, verified_amount: event.target.value })} /></label>
                        <label><span>Merchant payment time</span><input type="datetime-local" value={review.merchant_paid_at} onChange={(event) => setReview({ ...review, merchant_paid_at: event.target.value })} /></label>
                        <label className="manual-review-check"><input type="checkbox" checked={review.merchant_record_confirmed} onChange={(event) => setReview({ ...review, merchant_record_confirmed: event.target.checked })} /><span>I personally found this transaction in the official merchant GCash records.</span></label>
                        {role === 'admin' && (
                          <>
                            <label className="manual-review-check"><input type="checkbox" checked={review.admin_override} onChange={(event) => setReview({ ...review, admin_override: event.target.checked })} /><span>Admin exception for non-exact details</span></label>
                            {review.admin_override && <label><span>Required override reason</span><textarea value={review.override_reason} onChange={(event) => setReview({ ...review, override_reason: event.target.value })} /></label>}
                          </>
                        )}
                      </div>
                    ) : (
                      <div className="manual-review-form">
                        <div className={`manual-review-rejection-impact ${selected.can_retry ? 'retry' : 'closed'}`}>
                          <AlertCircle size={17} />
                          <div>
                            <strong>{selected.can_retry
                              ? 'This rejects the proof, not the booking.'
                              : selected.purpose === 'rebooking_adjustment'
                                ? 'This closes the payment request, not the existing booking.'
                                : 'This rejection will close the booking.'}</strong>
                            <span>
                              {selected.can_retry
                                ? `The guest can upload a correction before ${new Date(selected.payment_due_at).toLocaleString()} (${selected.attempts_remaining} attempt(s) remaining).`
                                : 'The payment deadline or submission limit has been reached, so no correction will be allowed.'}
                            </span>
                          </div>
                        </div>
                        <label><span>Rejection reason</span><textarea value={review.reason} onChange={(event) => setReview({ ...review, reason: event.target.value })} placeholder="Explain what did not match and what the guest must correct." /></label>
                      </div>
                    )}

                    <button type="button" className={`manual-review-submit ${action === 'reject' ? 'reject' : ''}`} onClick={submitReview} disabled={saving}>
                      {saving ? <><Loader className="spin" size={15} /> Saving...</> : action === 'approve' ? <><CheckCircle size={15} /> Confirm Approval</> : <><XCircle size={15} /> {selected.can_retry ? 'Reject Proof — Allow Correction' : selected.purpose === 'rebooking_adjustment' ? 'Reject Proof — Return to Rebooking' : 'Reject Proof — Close Booking'}</>}
                    </button>
                  </>
                ) : requiresAdminReview ? (
                  <div className="manual-review-complete">
                    <AlertCircle size={22} />
                    <div>
                      <strong>Administrator review required</strong>
                      <p>This proof was escalated after the review deadline. A receptionist can view it, but only an administrator can approve or reject it.</p>
                    </div>
                  </div>
                ) : (
                  <div className="manual-review-complete"><CheckCircle size={22} /><div><strong>Review completed</strong><p>{selected.review_reason || 'No review note.'}</p></div></div>
                )}
              </section>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default ManualGcashReviews;
