# H+ Hotel System Training — Presenter Outline

This outline is designed for a three-hour company handover session. Use the full training guide for detailed instructions and the live-demo worksheet to record generated references.

## Slide 1 — Session title

**On screen:** H+ Hotel Booking and Operations System — User Training and Handover

**Say:** Today we will follow one reservation from the guest website through payment, front-desk operations, management reports, and audit review.

## Slide 2 — What trainees will be able to do

- Complete the booking lifecycle
- Perform receptionist and administrator responsibilities
- Handle common exceptions
- Protect guest and payment data
- Know when and how to escalate

## Slide 3 — Environment rule

**Show:** `https://staging.hhotelbooking.com` and its staging/test-mode indicator.

**Say:** All demonstrations and exercises happen on staging with fictional data. Production remains closed throughout training.

**Ask:** How can you tell which environment is open?

## Slide 4 — The three perspectives

| Guest | Receptionist | Administrator |
|---|---|---|
| Search, book, verify, pay, look up, request changes, give feedback | Reservations, payments, arrivals, stays, departures, inquiries | Accounts, rooms, approvals, promos, content, reports, settings, audit |

## Slide 5 — Booking lifecycle

**Show:** `Pending → Confirmed → Checked In → Checked Out`

**Explain:** Verification, payment, room assignment, and balance requirements control whether the next action is allowed. Cancelled, Rejected, No Show, and Expired are exception outcomes.

## Slide 6 — Demo story

**On screen:** Maria Santos, two adults, one-night stay, quiet Deluxe room, training phone `+63 917 123 4567`.

**Say:** We will keep Maria’s booking reference visible because it connects every screen in the story.

## Slide 7 — Guest room search

**Live demo:** Select dates and guests, apply a training promo if available, compare room type/capacity/rate, view details, add a room, review cart.

**Checkpoint:** Does the room capacity support the party?

## Slide 8 — Guest information and policies

**Live demo:** Enter fictional contact/address details and the special request.

**Show:** Fixed `+63` prefix; paste `09171234567`; display becomes `9171234567`. Demonstrate one invalid number.

**Checkpoint:** Have the total, dates, policies, and acknowledgement been reviewed?

## Slide 9 — Create and verify

**Live demo:** Submit once, record booking reference, open the verification email, complete verification.

**Say:** Do not refresh or submit repeatedly while creation is processing.

## Slide 10 — Payment principles

**On screen:** Screenshot ≠ verified payment.

**Explain:** Manual GCash approval requires a match against the official merchant record: reference, exact amount, and paid time.

## Slide 11 — Guest payment step

**Live demo:** Use an authorized sandbox payment, or submit the prepared manual GCash training proof.

**Show:** Booking reference, amount, proof fields, and Verifying state.

**Checkpoint:** Verifying means review is pending; it does not mean the booking is confirmed.

## Slide 12 — Receptionist dashboard

**Live demo:** Sign in as receptionist; show arrivals, departures, occupancy, notification/badge indicators.

**Ask:** Which queue should be checked first at the start of a shift?

## Slide 13 — Reservation review

**Live demo:** Search Maria’s reference; verify guest, dates, room type, assigned room, status, payment, balance, and special request.

**Checkpoint:** Ask a trainee to state the next allowed action and why.

## Slide 14 — GCash proof review

**Live demo:** Open GCash Reviews → Proof Review, compare evidence, approve an exact authorized staging match.

**Exception demo:** Open the prepared mismatch, choose Reject Proof, and enter a specific correction reason.

**Say:** Overdue/escalated proof and override decisions belong to the administrator. Underpayment cannot be overridden.

## Slide 15 — Daily reconciliation

**Live demo:** Open Daily Reconciliation; demonstrate Exact Match and explain Flag Exception.

**Say:** An administrator closes an open reconciliation exception.

## Slide 16 — Room assignment and check-in readiness

**Live demo:** Confirm automatic assignment or use Assign Room.

**On screen checklist:** Confirmed booking, completed required payment, zero required balance, every room assigned, room ready, eligible arrival time.

## Slide 17 — Check-in

**Live demo:** Search Maria; collect any remaining balance; complete check-in.

**Exception:** If too early, submit an early check-in request with a specific reason, switch to admin for approval, then retry.

## Slide 18 — During-stay room transfer

**Live demo:** Reservation → Room Transfer → load rooms → select active room line/target → enter reason → submit. Administrator reviews Transfer Approvals.

**Checkpoint:** The target room must be eligible and operationally ready.

## Slide 19 — Extra charges and extension

**Live demo:** Check-Out → Extra Charges; add one prepared training charge. Preview Extend Room Stay without submitting unless inventory was reserved for the exercise.

**Say:** Charges must have clear descriptions and approved amounts.

## Slide 20 — Final checkout

**Live demo:** Review active room lines, settle remaining balance, confirm zero balance, finalize checkout.

**Explain:** Multi-room stays can use room-level checkout. Bulk actions require verifying every selected record.

## Slide 21 — Guest follow-up

**Live demo:** My Booking lookup using reference and training email. Show cancellation/rebooking routes when eligible. Open training feedback link if available.

## Slide 22 — Administrator management

**Guided tour:**

- User Management
- Room Management
- Promo Codes
- Content Management
- Settings

**Say:** Access, price, policy, content, and configuration changes follow the company’s approval process and are tested on staging first.

## Slide 23 — Approvals

**Guided tour:** Cancellation, refund, transfer, early check-in, escalated GCash, and reconciliation exception.

**Ask:** What evidence must be reviewed before each decision?

## Slide 24 — Reports and audit

**Live demo:** Filter reports to the training date; export one PDF; compare it with the screen; locate the demo action in Audit Trail.

**Say:** Reports answer what happened financially and operationally. Audit answers who performed the recorded action and what changed.

## Slide 25 — Daily operating rhythm

**Opening:** dashboard, arrivals/departures, room readiness, payments, pending requests.  
**During shift:** search first, verify status and balance, submit once, record references.  
**Closing:** reconcile payments, hand over unresolved references, review queues, log out.

## Slide 26 — Never-do list

- Never approve payment from a screenshot alone.
- Never share passwords or accounts.
- Never create a duplicate because a page is slow.
- Never collect a second payment before investigating the first.
- Never bypass an approval queue.
- Never place an uncleared room back into Available.
- Never use production for practice.

## Slide 27 — Hands-on assessment

Assign receptionist and administrator tasks from the full guide. Observe without coaching on the final attempt. Record Pass/Retry and notes in the worksheet.

## Slide 28 — Handover and support

Confirm the system owner, backup owner, payment reviewer, approval owner, support contact, escalation contents, and production release process.

## Slide 29 — Final questions and sign-off

Review unresolved questions, assign owners and dates, complete cleanup, and sign the training worksheet.

**Close with:** The correct action starts with the correct environment, the correct account, and the current booking and payment state.
