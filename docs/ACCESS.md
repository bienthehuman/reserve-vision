# Access: roles, isolation and the role × step walkthrough

The source of truth for who can do what. Policies and the isolation tests are checked against this file, and a test parses the matrix below so the two can't drift (see [CONVENTIONS.md](CONVENTIONS.md#docs-that-are-tested)).

## Actors

| Actor | How they get in |
| --- | --- |
| **Public** | Not logged in |
| **Renter** | Self-registration, `users.type = renter` |
| **Owner** | Self-registration as a landlord, which creates a `landlord_account` with them as owner. Also by owner invite |
| **Caretaker** | Owner invite only |
| **Operator** | Shell access only: `php artisan landlord:verify`. Not a web role; an admin UI is under README "Next" |

The code never says "tenant". See [ADR 0005](adr/0005-no-word-tenant.md).

## Isolation: three layers

1. **Query scope.** Every landlord-owned model (`Property`, `Room`, `Space`, `Reservation`, `PaymentProof`, `ViewingRequest`, `PropertyPhoto`, `AuditLog`, `LandlordInvitation`) uses the `BelongsToLandlordAccount` trait. In a landlord request it adds `where landlord_account_id = ?`. The account is resolved **once per request from the user's membership** (`CurrentLandlordAccount`), never from a route or request parameter.
2. **Policies.** Every action checks that the record belongs to the current account and that the role allows it.
3. **Tests** that try to break both layers: test 6 in [BUILD_PLAN.md](BUILD_PLAN.md), and the policy matrix test.

Renter and public queries go through separate, explicit scopes (`published()`, `ownedByRenter($user)`). A renter never touches the landlord scope.

**404, not 403, across accounts.** A landlord asking for another account's record gets `404`, because the scoped query finds nothing. `403` is only for "this record is yours, but your role can't do that", for example a caretaker editing a rate.

## Permission matrix

| Action | Owner | Caretaker | Renter | Public |
| --- | --- | --- | --- | --- |
| Browse published listings | ✓ | ✓ | ✓ | ✓ |
| Create or edit properties, rooms, spaces, rates | ✓ | — | — | — |
| Upload, reorder or delete photos | ✓ | — | — | — |
| Publish or unpublish a property | ✓ | — | — | — |
| Archive or unarchive a room or space | ✓ | — | — | — |
| View reservations for own account | ✓ | ✓ | — | — |
| View a payment proof | ✓ | ✓ | own only | — |
| Reject proof, confirm or decline | ✓ | ✓ | — | — |
| Undo a confirmation | ✓ | ✓ | — | — |
| Mark moved in, end stay | ✓ | ✓ | — | — |
| Withdraw a confirmed reservation | ✓ | — | — | — |
| Record a refund | ✓ | ✓ | — | — |
| Answer viewing requests | ✓ | ✓ | — | — |
| Invite or remove caretakers, revoke invites | ✓ | — | — | — |
| View audit log | ✓ | — | — | — |
| Reserve a space, upload proof, cancel own reservation | — | — | ✓ | — |
| Request or cancel a viewing | — | — | ✓ | — |
| See a renter's contact details | for reservations and viewings on own account | same | own only | — |

## Role × step walkthrough

Each main flow, step by step, with who acts and what they see. This is where gaps show up before code.

### Flow A: Landlord onboarding to first listing

| Step | Actor | Notes |
| --- | --- | --- |
| Register as landlord | owner | Creates `landlord_account` + owner membership |
| Wait for verification | operator | `landlord:verify {slug}`. Until then the owner can draft but not publish; the dashboard shows "awaiting verification" |
| Create property, rooms, spaces, rates | owner | Shared room: choose bed count, spaces generated as Bed A… |
| Upload photos | owner | Resized by a queued job; the page shows "processing" until ready |
| Publish | owner | Blocked unless verified, with at least one room and one ready photo |
| Invite caretaker | owner | Email with a link; the caretaker registers or logs in with that email and accepts |

### Flow B: Renter reserves a bed

| Step | Actor | Notes |
| --- | --- | --- |
| Search and browse | public, renter | Server-rendered; works without JavaScript |
| Choose a room, move-in date and months | renter | Must log in or register first; the intent is kept across login |
| Reserve | renter | `ReserveSpace` → hold. The page shows the fee, payment instructions from the property, and a countdown |
| Upload proof | renter | Image or PDF, ≤ 5 MB, amount and reference number |
| Review proof | owner or caretaker | Proof opened via a signed URL. Confirm, reject (re-upload) or decline |
| Notified | renter | Email at every status change |
| Move in | owner or caretaker | Mark moved in on or after `move_in` |
| Stay ends | system or landlord | `EndStays` job, or `EndStay` early |

### Flow C: Viewing request

| Step | Actor | Notes |
| --- | --- | --- |
| Request a viewing | renter | Preferred date and time; at most 3 open requests per renter |
| Accept or decline with a note | owner or caretaker | Renter emailed |
| Mark done | owner or caretaker | After `preferred_at` |
| Cancel | renter | While requested or accepted |

### Gaps found and how they were resolved

| Gap | Resolution |
| --- | --- |
| Nobody verified landlord accounts | Operator CLI command ([kickoff decision](BUILD_PLAN.md#decisions-log)) |
| Nobody marked moved in or ended | Owner or caretaker; plus the `EndStays` job |
| A wrong proof forced a decline | `RejectProof` re-opens the hold |
| A mistaken confirm was permanent | `UndoConfirmation` within 24h |
| Caretaker invite had no table or flow | `landlord_invitations` |
| One renter's parallel requests could beat the hold limits | Renter-row lock ([RESERVATIONS.md](RESERVATIONS.md#concurrency)) |

## Private files

Payment proofs live on the private disk (`local` in dev, a private S3 bucket in production).

1. `GET /reservations/{id}/proof` checks `PaymentProofPolicy::view` and redirects to a **signed URL valid for 5 minutes** that names the proof and the user ID.
2. The signed download route checks the signature, **checks that the logged-in user matches the user in the URL, and runs the policy again**, then streams the file with `Content-Disposition: inline`, `Cache-Control: private, no-store` and `X-Content-Type-Options: nosniff`.

A leaked URL is useless to anyone else (test 9).
