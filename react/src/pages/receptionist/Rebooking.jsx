import { useNotificationTarget } from '../../hooks/useNotificationTarget';
import React, { useEffect, useMemo, useState } from 'react';
import {
  AlertTriangle, Ban, BedDouble, Check, ChevronDown, Clock, CreditCard,
  Eye, Filter, RefreshCw, Search, ShieldCheck, Upload, User, WalletCards, X,
} from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import rebookingService from '../../services/receptionist/rebookingService';
import { PageSkeletonLoader, usePageCache } from '../../components/ProtectedRoute';
import StatusBadge from '../../components/StatusBadge';
import TableActionButton from '../../components/TableActionButton';
import useAutoRefresh from '../../hooks/useAutoRefresh';
import { showToast } from '../../utils/showToast';
import { formatCurrency } from '../../utils/currency';
import './Reservation.css';
import './Rebooking.css';

const STATUS_FILTERS = [
  { value: 'All', label: 'All requests' },
  { value: 'Pending', label: 'Pending' },
  { value: 'awaiting_payment', label: 'Awaiting Payment' },
  { value: 'under_review', label: 'Under Review' },
  { value: 'Approved', label: 'Approved' },
  { value: 'Rejected', label: 'Rejected' },
];

const initialRefund = {
  recipientName: '', recipientAccount: '', reference: '', processedAt: '', reason: '', confirmed: false,
};

const isPriceReductionWithoutRefund = (row) => !row?.refund && row?.workflowStatus === 'ready'
  && Number(row?.financial?.price_difference || 0) < 0
  && Number(row?.financial?.amount || 0) <= 0;

const workflowLabel = (row) => row?.refundRecordedAwaitingApproval
  ? 'Refund recorded — approval pending'
  : isPriceReductionWithoutRefund(row)
  ? 'Ready — No Refund Needed'
  : row?.workflowStatusLabel;

const RebookingPage = ({ role = 'receptionist' }) => {
  const navigate = useNavigate();
  useNotificationTarget((target) => { setSearch(target.search); setStatusFilter('All'); setSelected(null); });
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('All');
  const [selected, setSelected] = useState(null);
  const [records, setRecords] = useState([]);
  const [error, setError] = useState(null);
  const [decisionNote, setDecisionNote] = useState('');
  const [actionLoading, setActionLoading] = useState('');
  const [refund, setRefund] = useState(initialRefund);
  const [refundProof, setRefundProof] = useState(null);
  const isAdmin = role === 'admin';
  const cacheKey = isAdmin ? 'admin-rebooking-approvals' : 'rebookings';
  const { shouldShowSkeleton, markPageAsLoaded } = usePageCache(cacheKey);
  const [loading, setLoading] = useState(shouldShowSkeleton);

  const fetchRebookings = async ({ showSkeleton = shouldShowSkeleton, showErrorToast = true } = {}) => {
    try {
      if (showSkeleton) setLoading(true);
      const params = statusFilter === 'All' ? {} : { status: statusFilter };
      const response = await rebookingService.getRebookings(params, role);
      setRecords(response.rebookings || []);
      setError(null);
      markPageAsLoaded();
    } catch (requestError) {
      setError('Failed to load room change requests.');
      if (showErrorToast) showToast(requestError?.response?.data?.message || 'Failed to load room change requests.', 'error');
    } finally {
      if (showSkeleton) setLoading(false);
    }
  };

  useEffect(() => { fetchRebookings(); }, [statusFilter]); // eslint-disable-line react-hooks/exhaustive-deps
  useAutoRefresh(() => fetchRebookings({ showSkeleton: false, showErrorToast: false }), {
    deps: [statusFilter, role], intervalMs: 15000,
  });

  const filtered = useMemo(() => {
    const term = search.trim().toLowerCase();
    if (!term) return records;
    return records.filter((row) => [row.id, row.originalId, row.guest, row.newRoom]
      .some((value) => String(value || '').toLowerCase().includes(term)));
  }, [records, search]);

  const openDetails = (row) => {
    setSelected(row);
    setDecisionNote(row.note || '');
    setRefund({ ...initialRefund, reason: `Room change refund for ${row.id}` });
    setRefundProof(null);
  };

  const closeModal = () => {
    if (actionLoading) return;
    setSelected(null);
    setDecisionNote('');
    setRefund(initialRefund);
    setRefundProof(null);
  };

  const applyResult = async (response) => {
    if (response?.rebooking) {
      setSelected(response.rebooking);
      setRecords((current) => current.map((item) => item.id === response.rebooking.id ? response.rebooking : item));
    }
    showToast(response?.message || 'Rebooking updated.', response?.finalized === false ? 'warning' : 'success');
    await fetchRebookings({ showSkeleton: false });
  };

  const runAction = async (name, request) => {
    try {
      setActionLoading(name);
      await applyResult(await request());
    } catch (requestError) {
      const validation = requestError?.response?.data?.errors;
      const firstError = validation ? Object.values(validation).flat()[0] : null;
      showToast(firstError || requestError?.response?.data?.message || 'Unable to update this request.', 'error');
    } finally {
      setActionLoading('');
    }
  };

  const approve = () => runAction('approve', () => rebookingService.approveRebooking(
    selected.id, decisionNote.trim() ? { note: decisionNote.trim() } : {}, role,
  ));
  const reject = () => {
    if (!decisionNote.trim()) return showToast('Add a reason before rejecting this request.', 'warning');
    return runAction('reject', () => rebookingService.rejectRebooking(selected.id, { note: decisionNote.trim() }, role));
  };
  const requestPayment = () => runAction('payment', () => rebookingService.requestAdditionalPayment(selected.id, role));
  const sendRefundReview = () => runAction('refund-review', () => rebookingService.sendForRefundReview(selected.id, role));

  const processRefund = () => {
    if (!refund.recipientName.trim() || !refund.recipientAccount.trim() || !refund.reference.trim()
      || !refund.processedAt || refund.reason.trim().length < 10 || !refund.confirmed || !refundProof) {
      showToast('Complete every refund field, attach proof, and confirm the completed transfer.', 'warning');
      return;
    }
    const payload = new FormData();
    payload.append('recipient_name', refund.recipientName.trim());
    payload.append('recipient_account', refund.recipientAccount.trim());
    payload.append('gcash_reference', refund.reference.trim());
    payload.append('processed_at', refund.processedAt);
    payload.append('refund_reason', refund.reason.trim());
    payload.append('manual_transfer_confirmed', '1');
    payload.append('proof', refundProof);
    runAction('refund', () => rebookingService.processRefund(selected.id, payload));
  };

  const financial = selected?.financial || {};
  const remainingBalance = Math.max(0, Number(financial.projected_total || 0) - Number(financial.net_paid || 0));
  const reductionWithoutRefund = isPriceReductionWithoutRefund(selected);
  const workflowMessage = selected?.refundRecordedAwaitingApproval
    ? `The ${formatCurrency(selected.refund.amount)} refund and its proof are saved under reference ${selected.refund.reference}. Room change approval is still pending. Resolve the approval issue, then use Approve Room Change to retry. Do not send or record another refund.`
    : {
    ready: reductionWithoutRefund
      ? `The price is ${formatCurrency(Math.abs(Number(financial.price_difference || 0)))} lower, but no refund is due. The verified payment of ${formatCurrency(financial.net_paid || 0)} does not exceed the new total of ${formatCurrency(financial.projected_total || 0)}. Remaining balance after approval: ${formatCurrency(remainingBalance)}.`
      : 'Payment requirements are satisfied. Staff can complete the room change after the final availability check.',
    additional_payment_required: `The guest must pay an additional ${formatCurrency(financial.amount || 0)} before this request can be approved.`,
    awaiting_payment: `The secure payment request was sent. The requested room is held until ${financial.payment_due_at ? new Date(financial.payment_due_at).toLocaleString() : 'the payment deadline'}.`,
    payment_under_review: 'The guest submitted proof. Complete verification in Payment Operations before approving the room change.',
    refund_review: `This change requires a ${formatCurrency(financial.amount || 0)} refund. Only an administrator can record the completed transfer and finalize the room change.`,
  }[selected?.workflowStatus] || 'Review the request details and financial status before taking action.';

  if (loading) return <PageSkeletonLoader title={isAdmin ? 'Rebooking Approvals' : 'Room Change Requests'} />;
  if (error) return <div className="rebooking-page-state"><h1>{isAdmin ? 'Rebooking Approvals' : 'Room Change Requests'}</h1><p>{error}</p><button onClick={fetchRebookings}>Try Again</button></div>;

  return (
    <div className="r-rebooking-page rebooking-workflow-page">
      <div className="page-header"><div><h1>{isAdmin ? 'Rebooking Approvals' : 'Room Change Requests'}</h1><p className="page-subtitle">{isAdmin ? 'Handle refund exceptions and monitor every room change.' : 'Review room changes, request additional payments, and complete ready requests.'}</p></div></div>

      <div className="rebooking-workflow-guide">
        <div><CreditCard size={18} /><span><strong>Upgrade</strong> Request the exact additional payment.</span></div>
        <div><ShieldCheck size={18} /><span><strong>Verification</strong> Final approval unlocks after proof review.</span></div>
        <div><WalletCards size={18} /><span><strong>Refund</strong> Administrator records evidence and finalizes.</span></div>
      </div>

      <div className="page-toolbar rebooking-toolbar">
        <div className="search-wrap"><Search size={16} /><input aria-label="Search rebooking requests" placeholder="Search request, booking, guest, or room..." value={search} onChange={(event) => setSearch(event.target.value)} /></div>
        <div className="filter-wrap"><Filter size={16} /><select aria-label="Filter rebooking requests" value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}>{STATUS_FILTERS.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select><ChevronDown size={14} className="select-chevron" /></div>
      </div>

      <div className="table-card rebooking-table-card"><div className="table-container"><table className="data-table rebooking-table">
        <thead><tr><th>Request</th><th>Booking</th><th>Guest</th><th>Requested Room</th><th>Price Change</th><th>Next Step</th><th>Action</th></tr></thead>
        <tbody>{filtered.length === 0 ? <tr><td colSpan={7} className="empty-row">No room change requests found.</td></tr> : filtered.map((row) => (
          <tr key={row.id}>
            <td data-label="Request" className="booking-id">{row.id}</td><td data-label="Booking" className="booking-id">{row.originalId}</td><td data-label="Guest" className="guest-name">{row.guest}</td><td data-label="Requested Room">{row.roomChangesCount > 1 ? `${row.roomChangesCount} room changes` : row.newRoom}</td><td data-label="Price Change" className={String(row.priceDiff).startsWith('+') ? 'money-up' : String(row.priceDiff).startsWith('-') ? 'money-down' : ''}>{row.priceDiff}</td><td data-label="Next Step"><StatusBadge status={workflowLabel(row)} /></td><td data-label="Action"><TableActionButton iconOnly label="View room change details" onClick={() => openDetails(row)}><Eye size={15} /></TableActionButton></td>
          </tr>
        ))}</tbody>
      </table></div></div>

      {selected && <div className="modal-overlay" onClick={closeModal}><div className="modal-box modal-wide rebooking-modal" onClick={(event) => event.stopPropagation()}>
        <div className="modal-header"><div><h3>Room Change Details</h3><span className="booking-id">{selected.id} · {selected.originalId}</span></div><button className="modal-close" onClick={closeModal} disabled={Boolean(actionLoading)}><X size={20} /></button></div>
        <div className="modal-body rebooking-modal-body">
          <div className="rebooking-detail-grid">
            <section className="rebooking-section"><h4><User size={16} /> Guest</h4><div className="detail-row"><span>Name</span><strong>{selected.guest}</strong></div><div className="detail-row"><span>Phone</span><strong>{selected.phone}</strong></div><div className="detail-row"><span>Email</span><strong>{selected.email}</strong></div></section>
            <section className="rebooking-section"><h4><Clock size={16} /> Stay</h4><div className="detail-row"><span>Check-in</span><strong>{selected.newCheckIn}</strong></div><div className="detail-row"><span>Check-out</span><strong>{selected.newCheckOut}</strong></div><div className="detail-row"><span>Nights</span><strong>{selected.nights}</strong></div></section>
          </div>

          <section className="rebooking-section"><h4><BedDouble size={16} /> Requested Room Change</h4>{(selected.roomChanges || []).map((change) => <div className="room-change-row" key={change.rebookingId}><div><span>Current</span><strong>{change.oldRoom}</strong></div><RefreshCw size={18} /><div><span>Requested</span><strong>{change.newRoom}</strong></div><b>{change.priceDiff}</b></div>)}</section>
          <section className="rebooking-financial"><div><span>Current Total</span><strong>{formatCurrency(financial.original_total || 0)}</strong></div><div><span>New Total</span><strong>{formatCurrency(financial.projected_total || 0)}</strong></div><div><span>Verified Paid</span><strong>{formatCurrency(financial.net_paid || 0)}</strong></div><div><span>Required Downpayment</span><strong>{formatCurrency(financial.required_downpayment || 0)}</strong></div><div><span>Remaining Balance</span><strong>{formatCurrency(remainingBalance)}</strong></div></section>
          <div className={`rebooking-next-step ${selected.workflowStatus || ''}`}><AlertTriangle size={20} /><div><span>Next step</span><strong>{workflowLabel(selected)}</strong><p>{workflowMessage}</p></div></div>
          <section className="rebooking-section"><h4>Request Reason</h4><p className="rebooking-reason">{selected.reason}</p></section>
          {selected.canDecide && <section className="rebooking-section"><label className="rebooking-label" htmlFor="rebooking-note">Staff note</label><textarea id="rebooking-note" value={decisionNote} onChange={(event) => setDecisionNote(event.target.value)} rows={3} maxLength={500} placeholder="Add a decision note or rejection reason" disabled={Boolean(actionLoading)} /></section>}

          {isAdmin && selected.canProcessRefund && <section className="rebooking-refund-form"><div className="refund-form-heading"><WalletCards size={20} /><div><h4>Record completed GCash refund</h4><p>Send the refund from the official account first, then record its evidence here.</p></div></div><div className="refund-grid">
            <label><span>Recipient name *</span><input value={refund.recipientName} onChange={(event) => setRefund((value) => ({ ...value, recipientName: event.target.value }))} /></label><label><span>Recipient GCash number *</span><input inputMode="numeric" placeholder="09XXXXXXXXX" value={refund.recipientAccount} onChange={(event) => setRefund((value) => ({ ...value, recipientAccount: event.target.value }))} /></label><label><span>GCash refund reference *</span><input value={refund.reference} onChange={(event) => setRefund((value) => ({ ...value, reference: event.target.value }))} /></label><label><span>Date and time sent *</span><input type="datetime-local" value={refund.processedAt} onChange={(event) => setRefund((value) => ({ ...value, processedAt: event.target.value }))} /></label>
          </div><label className="refund-reason"><span>Refund reason *</span><textarea rows={3} value={refund.reason} onChange={(event) => setRefund((value) => ({ ...value, reason: event.target.value }))} /></label><label className="refund-upload"><Upload size={20} /><span>{refundProof?.name || 'Choose official GCash refund proof'}</span><small>JPEG, PNG, WebP, or PDF · maximum 5 MB</small><input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={(event) => setRefundProof(event.target.files?.[0] || null)} /></label><label className="refund-confirm"><input type="checkbox" checked={refund.confirmed} onChange={(event) => setRefund((value) => ({ ...value, confirmed: event.target.checked }))} /><span>I confirm the refund was already sent from the official hotel GCash account.</span></label></section>}
        </div>

        <div className="modal-footer rebooking-modal-footer">
          <button className="modal-btn btn-ghost" onClick={closeModal} disabled={Boolean(actionLoading)}>Close</button>
          {selected.workflowStatus === 'payment_under_review' && <button className="modal-btn btn-secondary" onClick={() => navigate(`/${role}/manual-gcash-reviews?section=records`)}><WalletCards size={15} /> Payment Operations</button>}
          {selected.canReject && <button className="modal-btn btn-danger" onClick={reject} disabled={Boolean(actionLoading)}><Ban size={15} /> {actionLoading === 'reject' ? 'Rejecting...' : 'Reject'}</button>}
          {selected.canRequestPayment && <button className="modal-btn btn-payment" onClick={requestPayment} disabled={Boolean(actionLoading)}><CreditCard size={15} /> {actionLoading === 'payment' ? 'Sending...' : `Request ${formatCurrency(financial.amount || 0)}`}</button>}
          {selected.canSendRefundReview && <button className="modal-btn btn-refund" onClick={sendRefundReview} disabled={Boolean(actionLoading)}><WalletCards size={15} /> {actionLoading === 'refund-review' ? 'Sending...' : 'Send for Refund Review'}</button>}
          {isAdmin && selected.canProcessRefund && <button className="modal-btn btn-refund" onClick={processRefund} disabled={Boolean(actionLoading)}><ShieldCheck size={15} /> {actionLoading === 'refund' ? 'Finalizing...' : 'Record Refund & Finalize'}</button>}
          {selected.canApprove && <button className="modal-btn btn-approve" onClick={approve} disabled={Boolean(actionLoading)}><Check size={15} /> {actionLoading === 'approve' ? 'Approving...' : 'Approve Room Change'}</button>}
        </div>
      </div></div>}
    </div>
  );
};

export default RebookingPage;
