# No-Show Safety and Staff Workflow

## Receptionist Workflow

### Eligibility

- The booking must still be `confirmed`.
- The check-in date must be today or earlier.
- The configured no-show cutoff must have passed. The default is 6:00 PM.
- Staff must attempt to contact the guest on or after the check-in date.

The cutoff can be changed in the system setting `no_show_cutoff_time`. Each no-show stores the cutoff used so the decision remains auditable if the setting changes later.

### Steps

1. Open **Front Desk > Check-In**.
2. Find the booking under expected arrivals.
3. Select **Mark as No-Show**.
4. Record the contact method, contact date and time, contact result, and useful notes.
5. Read and check the confirmation statement.
6. Select **Confirm No-Show** once.

A repeated request is safely accepted without creating a second audit record or sending another notification.

### System actions

- Changes both booking and reservation status to `no_show`.
- Records the staff member, timestamp, contact evidence, and cutoff used.
- Revokes every active customer payment-page session for the booking.
- Recalculates room state instead of forcing the room to `available`.
- Queues the guest email when an email address exists.
- Creates an immutable audit entry.

### Room safety

- A room used by another checked-in booking stays `occupied`.
- A maintenance room stays `maintenance`.
- A cleaning room stays `cleaning`.
- Only a room with no stronger operational state becomes available.

### Payment handling

- If no money was received, the financial disposition is `no_payment`.
- If money was received, the disposition is `manual_review_required` and the net paid amount is stored.
- Marking a no-show never automatically refunds or forfeits money.
- Authorized staff must apply hotel policy and document the financial decision separately.

### Email states

- `queued`: the email entered the delivery queue.
- `sent`: delivery completed successfully.
- `retrying`: delivery failed temporarily and will be tried again.
- `failed`: delivery attempts failed; staff must contact the guest manually.
- `not_applicable`: no guest email exists; staff must contact the guest manually.

The API message reports the real notification state. It does not claim an email was sent when it was only queued or no address exists.

## Admin Review

1. Open **Admin > Reservations** and filter by **No-Show**.
2. Review the staff member, mark time, contact evidence, and cutoff snapshot.
3. Resolve any `manual_review_required` payment according to hotel policy.
4. Review the audit entry **Booking Marked as No-Show**.
5. Review email delivery fields if notification is disputed.

No-show records are retained for operational and audit history.

## Guest Guidance

A no-show means the hotel recorded that the guest did not arrive by the permitted cutoff and staff attempted contact. The reservation is no longer active and its payment page is revoked.

If the status is incorrect, the guest should contact the hotel immediately and provide the booking reference. Rebooking, refunds, or retained payments remain subject to hotel policy and staff review.
