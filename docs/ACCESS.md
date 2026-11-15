# Access: roles, isolation and the role × step walkthrough

The source of truth for who can do what. Policies and the isolation tests are checked against this file, and a test parses the matrix below so the two can't drift (see [CONVENTIONS.md](CONVENTIONS.md#docs-that-are-tested)).

## Actors

| Actor | How they get in |
| --- | --- |
| **Public** | Not logged in. Browses; anything members-only sends them to `/login`, and the page they wanted is kept (flow B) |
| **Renter** | `/register`, "I want to: **rent a place**" → `RegisterRenter` creates a `users.type = renter` user. Logged in at once, then must confirm their email before reserving. Lands on `/` after login |
| **Owner** | `/register`, "I want to: **list my property**" plus a business or display name → `RegisterLandlord` creates, in one transaction, the user (`type = landlord`), an **unverified** `landlord_account` (unique slug from the name) and the **owner** membership. Must confirm their email before opening `/landlord`, which shows "awaiting verification" until the operator verifies the account. Lands on `/landlord` after login. Also by owner invite (4.3) |
| **Caretaker** | Owner invite only (4.3). Lands on `/landlord` after login |
| **Operator** | Shell access only: `php artisan landlord:verify`. Not a web role; an admin UI is under README "Next" |

### Logging in (slice 2.4)

Laravel Fortify, headless: Fortify's controllers and login pipeline, Inertia pages in `resources/js/Pages/Auth`, routes in `routes/auth.php` ([CLAUDE.md](../CLAUDE.md#auth-slice-24)).

| What | Rule |
| --- | --- |
| Login | Email (case-insensitive) and password, optional "keep me logged in". The session is regenerated. Afterwards: the page that asked for login, else `/` for renters and `/landlord` for landlords |
| Logout | `POST /logout`: the session is invalidated and the CSRF token regenerated |
| Email verification | Required (`verified` middleware) for reserving (2.5) and every landlord page. The signed link is valid 60 minutes; "send the link again" is limited to 6 a minute. Demo users are seeded verified |
| Password reset | Link by email, valid 60 minutes, one use. The form answers the same whether or not the email has an account |
| Rate limits | Login 5 a minute per email + IP; sign-up and reset emails 3 a minute per IP. Shown as a form error ("Too many tries. Wait 60 seconds, then try again."), not a 429 page |
| `/landlord` | Logged in, verified, and a landlord user with a membership; a renter gets `403` |
| Reserving (2.5) | `POST /rooms/{room}/reservations` and `/reservations/{id}`: logged in, verified, and a renter (`renter` middleware); a landlord gets `403`. A guest goes to `/login` and comes back to the room page. Someone else's reservation is `404`. 10 reserve requests a minute per renter. Routes and responses: [RESERVATIONS.md](RESERVATIONS.md#over-http-slice-25) |
| Session cookie | `HttpOnly`, `SameSite=Lax`, `Secure` in production |

The code never says "tenant". See [ADR 0005](adr/0005-no-word-tenant.md).

## Isolation: three layers

1. **Query scope.** Every landlord-owned model (`Property`, `Room`, `Space`, `Reservation`, `PaymentProof`, `ViewingRequest`, `PropertyPhoto`, `AuditLog`, `LandlordInvitation`) uses the `BelongsToLandlordAccount` trait. In a landlord request it adds `where <table>.landlord_account_id = ?`. The account is resolved **once per request from the user's membership** (`CurrentLandlordAccount`), never from a route or request parameter.
2. **Policies.** Every action checks that the record belongs to the current account and that the role allows it.
3. **Tests** that try to break both layers: test 6 in [BUILD_PLAN.md](BUILD_PLAN.md), and the policy matrix test.

Renter and public queries go through separate, explicit scopes (`published()`, `ownedByRenter($user)`). A renter never touches the landlord scope.

**404, not 403, across accounts.** A landlord asking for another account's record gets `404`, because the scoped query finds nothing. `403` is only for "this record is yours, but your role can't do that", for example a caretaker editing a rate.

### How it works (slice 3.1)

**The current account.** `App\Support\CurrentLandlordAccount` (`account()`, `id()`, `role()`, `isOwner()`) reads the logged-in user's `landlord_members` row (`user_id` is unique, so there is exactly one). Nothing in the request (route, query, body, header) is ever consulted. It is a **scoped** instance: the queue worker drops it before every job, and it re-resolves if the logged-in user changes within a request. Asked for with no landlord user, it throws `NoCurrentLandlordAccount`; jobs and commands that act for an account take the account id as an argument instead. The `landlord` middleware uses it: a landlord user without a membership gets `403`.

**When the scope applies.** `LandlordAccountScope` asks one question on every query: *is the logged-in user a landlord user?*

| Logged in | Scope adds |
| --- | --- |
| A landlord user with a membership (owner or caretaker) | `where <table>.landlord_account_id = <their account>`, the column qualified so joins work |
| A landlord user **without** a membership | `where 1 = 0`: nothing matches (fail closed) |
| A renter, or nobody (guests, console commands, queued jobs, seeders) | nothing |

Because the scope sits on the model, it also covers route model binding (another account's id is a `404` by construction), relations, eager loads and `whereHas`. The queue worker also forgets the auth guards before each job (`Queue::looping` in `AppServiceProvider`), so a job that logs a user in can't leave them logged in for the next job. Raw `DB::table()` and `DB::select()` skip the scope entirely.

**Writes.** While a landlord user is logged in, saving a landlord-owned row fills `landlord_account_id` with the current account if it's empty, and throws `CrossAccountWrite` if it names another account (or the user has no membership). Without a landlord user the caller sets it, as `ReserveSpace` does from the room.

**Leaving the scope** is explicit, greppable, and checked by `tests/Arch/LandlordScopeTest.php`:

| Named scope | Removes the scope and constrains to | Used by |
| --- | --- | --- |
| `Property::published()` | published, not archived, verified account | `Room::listed()`; listing pages (5.x) |
| `Room::listed()` | not archived, property `published()` | the room page, `StoreReservation` |
| `Space::listed()` | not archived, room `listed()` | the room page's space count and availability |
| `Reservation::onListedSpaces()` | reservations on `listed()` spaces (counts only, never shown) | `Room::freeSpaceCount()` |
| `Reservation::ownedByRenter($user)` | `renter_id = $user->id` (and `openForRenter()` builds on it) | `ShowReservation`, `ReserveSpace`'s limits, the room page |

Landlords browse listings through these too, so a landlord on another account's room page sees what a guest sees. `withoutGlobalScope(…)` and `withoutGlobalScopes()` anywhere else fail the build, except in the system allowlist in that test, which is **empty**: system code has no landlord user, so it doesn't need to leave the scope. `DB::table()` on a landlord-owned table is allowed only in `database/` (seeders, factories) and `DoctorCommand` (the audit trigger probe).

**Policies** (`app/Policies`, discovered by name) are the second layer, built from three checks in `App\Policies\Concerns\ChecksAccess`: a landlord on a record (`404` unless it's their account, then `403` unless their role may), a landlord with no record yet (`403` unless their role may), and a renter on their own record (`403` for landlords, `404` for another renter's). Guests are denied before any policy runs. Each ability is named after its matrix row, and `tests/Feature/Docs/PermissionMatrixTest.php` maps every row to its abilities.

## Permission matrix

| Action | Owner | Caretaker | Renter | Public |
| --- | --- | --- | --- | --- |
| Browse published listings | ✓ | ✓ | ✓ | ✓ |
| View own account's properties, rooms, spaces and photos | ✓ | ✓ | — | — |
| Create or edit properties, rooms, spaces, rates | ✓ | — | — | — |
| Upload, reorder or delete photos | ✓ | — | — | — |
| Publish or unpublish a property | ✓ | — | — | — |
| Archive or unarchive a room or space | ✓ | — | — | — |
| View reservations for own account | ✓ | ✓ | — | — |
| View own reservations | — | — | own only | — |
| View a payment proof | ✓ | ✓ | own only | — |
| Reject proof, confirm or decline | ✓ | ✓ | — | — |
| Undo a confirmation | ✓ | ✓ | — | — |
| Mark moved in, end stay | ✓ | ✓ | — | — |
| Withdraw a confirmed reservation | ✓ | ✓ | — | — |
| Record a refund | ✓ | ✓ | — | — |
| Answer viewing requests | ✓ | ✓ | — | — |
| Invite or remove caretakers, revoke invites | ✓ | — | — | — |
| View audit log | ✓ | — | — | — |
| Reserve a space, upload proof, cancel own reservation | — | — | ✓ | — |
| Request or cancel a viewing | — | — | ✓ | — |
| See a renter's contact details | for reservations and viewings on own account | same | own only | — |

A ✓ for an owner or caretaker always means "on their own account"; "own only" for a renter means their own reservation, proof or viewing request. Another account's or another renter's record is a `404` for every ability. "View own account's properties, rooms, spaces and photos" and "View own reservations" were added in slice 3.1, to name abilities the pages already needed (the landlord pages of 3.2 and the renter's hold page of 2.5). [PermissionMatrixTest](../tests/Feature/Docs/PermissionMatrixTest.php) maps each row to the policy abilities that implement it.

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
