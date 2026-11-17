# 0003. Take reservation fees by uploaded proof, verified by hand

- Status: Accepted (2026-11-17)
- Date: 2026-11-14
- Updated: 2026-11-17 (as built in v1, last section)

## Context

Filipino landlords commonly take reservation fees by GCash or bank transfer and check the screenshot themselves. A real gateway needs business registration, KYC and fees, none of which a portfolio project can obtain, and it would add scope without showing new engineering.

## Options

1. **Proof upload + manual verification**, with a hold window and a reject/re-upload path.
2. **Payment gateway** (PayMongo, Xendit or similar) in sandbox mode.

## Decision

Option 1. The system never moves money. It records the claimed amount and reference number, the landlord's decision, and whether a fee was refunded.

## Consequences

- Good: it matches how the target users already work; no third-party dependency.
- Good: it creates the interesting engineering problems on purpose: holds that expire, private files, signed URLs, a decision workflow.
- Bad: the landlord can be fooled by a fake screenshot. The landlord is responsible for checking their own account; the UI says so.
- Bad: payment proofs are sensitive files. They're stored privately, served only via short-lived signed URLs bound to the user, and re-checked by policy (T9).
- Follow-up: a gateway is listed under the README's "Out of scope" (it was "Next" when this was written).

## Update 2026-11-17: as built in v1

- The hold is 24 hours; a proof moves it to `proof_uploaded`, which blocks the space until the landlord decides ([RESERVATIONS.md](../RESERVATIONS.md#transition-table)).
- The landlord can ask for a new proof twice; the third proof can only be confirmed or the reservation declined (`MAX_PROOF_REJECTIONS`). A confirmation can be undone for a short window, and a refund is recorded, not made (slice 6.1).
- Unanswered proofs are chased by email at 24 and 48 hours (slice 6.3).
- Proofs are kept byte for byte, judged by their content, and streamed through PHP after three checks rather than served by pre-signed storage URLs: [ADR 0011](0011-stream-private-proofs-through-php.md). T9 proves a leaked link is useless to anyone else.
