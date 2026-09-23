export const refundApiFields = {
  recipient_name: 'recipientName',
  recipient_account: 'recipientAccount',
  gcash_reference: 'gcashReference',
  processed_at: 'processedAt',
  refund_reason: 'refundReason',
  manual_transfer_confirmed: 'transferConfirmed',
  proof: 'proof',
};

export function validateCancellationRefund(form, proof) {
  const errors = {};
  if (!form.recipientName.trim()) errors.recipientName = 'Enter the recipient name.';
  else if (form.recipientName.trim().length > 120) errors.recipientName = 'Use no more than 120 characters for the recipient name.';

  const account = form.recipientAccount.replace(/\D/g, '');
  if (!/^(09\d{9}|639\d{9})$/.test(account)) errors.recipientAccount = 'Enter a valid GCash number, such as 09171234567 or +63 917 123 4567.';
  if (form.gcashReference.replace(/[^a-z0-9]/gi, '').length < 6) errors.gcashReference = 'Enter the GCash refund reference with at least 6 letters or digits.';
  else if (form.gcashReference.trim().length > 80) errors.gcashReference = 'Use no more than 80 characters for the GCash reference.';

  if (!form.processedAt || !Number.isFinite(Date.parse(form.processedAt))) errors.processedAt = 'Enter the date and time the refund was sent.';
  if (form.refundReason.trim().length < 10) errors.refundReason = 'Enter a refund reason of at least 10 characters.';
  else if (form.refundReason.trim().length > 1000) errors.refundReason = 'Use no more than 1,000 characters for the refund reason.';

  if (!proof) errors.proof = 'Attach the official GCash refund proof.';
  else if (proof.size > 5 * 1024 * 1024) errors.proof = 'Choose a refund proof file no larger than 5 MB.';
  else if (proof.type && !['image/jpeg', 'image/png', 'image/webp', 'application/pdf'].includes(proof.type)) errors.proof = 'Choose a JPEG, PNG, WebP, or PDF refund proof.';
  if (!form.transferConfirmed) errors.transferConfirmed = 'Confirm that the refund was already sent from the hotel GCash account.';
  return errors;
}
