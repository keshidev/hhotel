# H+ Hotel Staff Quick Reference

## Before every action

1. Confirm the correct environment and your own account.
2. Search for the booking before creating another one.
3. Check booking status, payment status, balance, and assigned room.
4. Read the confirmation dialog and submit once.
5. Record or hand over the booking/reference number.

## Booking flow

`Pending → Verified/Paid → Confirmed → Room Assigned → Checked In → Checked Out`

Exception endings include `Cancelled`, `Rejected`, `No Show`, and `Expired`.

## Receptionist menu

| Menu | Use it for |
|---|---|
| Dashboard | Arrivals, departures, occupancy, and pending work |
| Reservation | Find bookings, view details, assign rooms, request transfers |
| Payment | Review payment records requiring action |
| GCash Reviews | Verify proof and reconcile approved manual GCash payments |
| Cancellation | View cancellation requests and status |
| Transfer Requests | Follow approved/rejected room-transfer work |
| Rebooking | Approve or reject booking-change requests |
| Guest Inquiries | Start, resolve, or flag public messages |
| Walk-In | Create same-day overnight or day-use bookings |
| Check-In | Collect required balance and check in eligible guests |
| Check-Out | Add charges, extend/close rooms, settle balance, finalize departure |
| Settings | Profile, password, and notification preferences |

## Administrator menu

| Menu | Use it for |
|---|---|
| Dashboard | Management summary |
| User Management | Create, edit, activate, or deactivate staff |
| Room Management | Rooms, rates, capacity, amenities, images, and status |
| Promo Codes | Discount rules, dates, limits, and activation |
| Content Management | Homepage, rooms, add-ons, policies, location, and brand |
| Cancellation Approvals | Decision, refund, proof, and finalization |
| Transfer Approvals | Approve/reject target-room changes |
| GCash Reviews | Escalated proof review and reconciliation exceptions |
| Early Check-In | Approve/reject early arrivals |
| Guest Inquiries | Oversight and spam restoration |
| Reports | Revenue, occupancy, reservations, modifications, and feedback |
| Audit Trail | Who changed what and when |
| Settings | Merchant QR, general, notifications, security, and email status |

## Payment rule

A screenshot is not payment confirmation. For manual GCash, match the official merchant record’s:

- Reference
- Exact amount
- Paid date and time

Reject or flag an exception when they do not match. Never reuse a reference or approve an underpayment.

## Check-in rule

The booking must be Confirmed, the payment requirement completed, the required balance settled, every room assigned, the room ready, and the arrival time eligible. Submit an early check-in request when required.

## Check-out rule

Review room lines and charges, record the full remaining payment, confirm the balance is zero, then finalize checkout. For multi-room stays, select the correct room line.

## Philippine mobile entry

The guest field displays a fixed `+63`. Enter the ten-digit mobile portion beginning with `9`, for example `9171234567`. Pasted `09171234567` is normalized automatically.

## Never do these

- Work in production during training.
- Share accounts or passwords.
- Approve GCash using only a screenshot.
- Ask for a second payment before investigating the first.
- Create a duplicate booking because the screen is slow.
- Bypass cancellation, transfer, early check-in, refund, or reconciliation approval.
- Set a room Available before operational clearance.
- Edit the database directly to fix an operational problem.

## Escalation note

Give support the environment, date/time, staff name, booking/payment/reference number, expected result, actual result, exact error, and whether the action was retried. Never send passwords or unnecessary guest/payment data.
