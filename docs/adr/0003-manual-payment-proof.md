# 0003. Take reservation fees by uploaded proof, verified by hand

- Status: Proposed
- Date: 2026-11-14

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
- Follow-up: a gateway is listed under README "Next".
