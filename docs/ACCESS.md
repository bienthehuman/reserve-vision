# Access: roles, isolation and the role × step walkthrough

The source of truth for who can do what. Policies and the isolation tests are checked against this file, and a test parses the matrix below so the two can't drift (see [CONVENTIONS.md](CONVENTIONS.md#docs-that-are-tested)).

## Actors

| Actor | How they get in |
| --- | --- |
| **Public** | Not logged in. Browses; anything members-only sends them to `/login`, and the page they wanted is kept (flow B) |
| **Renter** | `/register`, "I want to: **rent a place**" → `RegisterRenter` creates a `users.type = renter` user. Logged in at once, then must confirm their email before reserving. Lands on `/` after login |
| **Owner** | `/register`, "I want to: **list my property**" plus a business or display name → `RegisterLandlord` creates, in one transaction, the user (`type = landlord`), an **unverified** `landlord_account` (unique slug from the name) and the **owner** membership. Must confirm their email before opening `/landlord`, which says the account is being checked until the operator verifies the account. Lands on `/landlord` after login. (Inviting a co-owner is in the backlog: invitations are for caretakers) |
| **Caretaker** | Owner invite only: the owner enters an email on the members page, the person gets a link by email (`/invitations/{token}`, good for 7 days) and signs up or logs in through it ([Accepting an invitation](#accepting-an-invitation-slice-43)). Their email counts as confirmed, because the link proved the inbox. Lands on `/landlord` after login |
| **Operator** | Shell access only: `php artisan landlord:verify`, and `php artisan landlord:invite {slug} {email}`, which makes a caretaker invitation in the first owner's name and prints the link instead of emailing it (for when email can't be relied on). Not a web role; an admin UI is under README "Next" |

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
| Reserving (2.5, 5.3) | `POST /rooms/{room}/reservations` and every `/reservations…` page: logged in, verified, and a renter (`renter` middleware); a landlord gets `403`. A guest who presses Reserve goes to `/login` ("Log in or sign up to reserve…"), and a renter who hasn't confirmed their email to the "confirm your email" page; either way the room on its listing page, with the same stay, is kept as the page to come back to (`KeepReservationIntent`), after login or after the emailed link. Someone else's reservation is `404`. 10 reserve requests a minute per renter. Routes and responses: [RESERVATIONS.md](RESERVATIONS.md#over-http-slice-25) |
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
| `LandlordInvitation::forToken($token)` | `token_hash = sha256($token)`: the one invitation the link's holder has the key to | the invitation page and `AcceptInvitation` (4.3): the person isn't a member yet, and may be logged in as a landlord user without a membership, for whom the scope matches nothing |

Landlords browse listings through these too, so a landlord on another account's room page sees what a guest sees. `withoutGlobalScope(…)` and `withoutGlobalScopes()` anywhere else fail the build, except in the system allowlist in that test, which is **empty**: system code has no landlord user, so it doesn't need to leave the scope. `DB::table()` on a landlord-owned table is allowed only in `database/` (seeders, factories) and `DoctorCommand` (the audit trigger probe).

**Policies** (`app/Policies`, discovered by name) are the second layer, built from three checks in `App\Policies\Concerns\ChecksAccess`: a landlord on a record (`404` unless it's their account, then `403` unless their role may), a landlord with no record yet (`403` unless their role may), and a renter on their own record (`403` for landlords, `404` for another renter's). Guests are denied before any policy runs. Each ability is named after its matrix row, and `tests/Feature/Docs/PermissionMatrixTest.php` maps every row to its abilities.

### The landlord pages (slice 3.2)

Every `/landlord` page is behind `auth`, `verified` and `landlord`, shows the landlord menu (Dashboard, Reservations, Properties, and Members for owners only; the header itself has only the account menu, whose one action is Log out) and gets its records through the isolation scope, so another account's id is a `404`. Controllers are thin: they authorize with the policy ability named after the matrix row, and the writes are actions that audit.

| Page | Route | Matrix row it implements | Who |
| --- | --- | --- | --- |
| Reservations | `GET /landlord/reservations` (`?status=`, 15 a page, newest first) | View reservations for own account (`viewAny`) | owner, caretaker |
| Reservation | `GET /landlord/reservations/{reservation}` | View reservations for own account (`view`); **the only page with the renter's email and phone** (`viewRenterContact`); each payment proof with its amount, reference and status, and the file in a `ProofViewer` through `/proofs/{proof}` (View a payment proof, `view` on the proof; slice 5.3, [Private files](#private-files)) | owner, caretaker |
| Properties | `GET /landlord/properties`, `GET /landlord/properties/{property}` | View own account's properties, rooms, spaces and photos (`viewAny`, `view`) | owner, caretaker |
| Dashboard | `GET /landlord` | (the landlord menu's first page) | owner, caretaker |
| Add or edit a property | `GET /landlord/properties/create`, `POST /landlord/properties` → `CreateProperty` (`property.created`); `GET …/{property}/edit`, `PUT …/{property}` → `UpdateProperty` (`property.updated`) | Create or edit properties, rooms, spaces, rates (`create`, `update`) | owner; a caretaker gets `403` on all four and no button |
| Add or edit a room | `GET …/properties/{property}/rooms/create`, `POST …/rooms` → `CreateRoom` (`room.created`); `GET /landlord/rooms/{room}/edit`, `PUT …/{room}` → `UpdateRoom` (`room.updated`). It replaced 3.2's rate form: the rate is one field | same (`create` on a room in a property, `update`) | owner |
| Add or rename a space | `POST /landlord/rooms/{room}/spaces` → `AddSpace` (`space.added`); `PATCH /landlord/spaces/{space}` → `RenameSpace` (`space.renamed`) | same (`create` on a space in a room, `update`) | owner |
| Photos (slice 4.2) | On the property page. `POST …/properties/{property}/photos` → `UploadPropertyPhoto` (`photo.uploaded`), one file per request; `PATCH /landlord/photos/{photo}` → `DescribePropertyPhoto` (`photo.described`); `POST …/photos/{photo}/move` → `MovePropertyPhoto` (`photo.moved`); `POST …/photos/{photo}/retry` → `RetryPropertyPhoto` (`photo.retried`, failed photos only); `DELETE …/photos/{photo}` → `DeletePropertyPhoto` (`photo.deleted`) | Upload, reorder or delete photos (`upload`, `update`, `reorder`, `retry`, `delete`) | owner; a caretaker sees the photos and no button |
| Archive or unarchive ("Restore" on the page) | `POST` and `DELETE` on `/landlord/{properties,rooms,spaces}/{id}/archive` → `Archive…` / `Unarchive…` (`property.archived`, `room.unarchived`, …) | Archive or unarchive a property, room or space (`archive`, `unarchive`) | owner |
| Publish or unpublish (slice 4.3) | `POST` and `DELETE` on `/landlord/properties/{property}/publish` → `PublishProperty` (`property.published`) / `UnpublishProperty` (`property.unpublished`) | Publish or unpublish a property (`publish`, `unpublish`) | owner; a caretaker sees neither the checklist nor a button |
| Members | `GET /landlord/members` | Invite or remove caretakers, revoke invites (`viewAny`): the page where that happens | owner; a caretaker gets `403` and no link |
| Invite a caretaker | `POST /landlord/invitations` → `CreateInvitation`, audit `invitation.created`, and the invitation email (queued) | Invite or remove caretakers, revoke invites (`create`) | owner |
| Send an invitation again, or cancel it (slice 4.3) | `POST /landlord/invitations/{invitation}/resend` → `ResendInvitation` (`invitation.resent`: a new token, so the old link dies, and 7 more days; a new email); `DELETE /landlord/invitations/{invitation}` → `RevokeInvitation` (`invitation.revoked`) | same (`resend`, `revoke`) | owner |
| Remove a member (slice 4.3) | `DELETE /landlord/members/{member}` → `RemoveMember` (`member.removed`) | same (`remove`) | owner |
| Decisions (slice 6.1) | On the reservation page, a **decision panel** (`App\Support\Reservations\DecisionPanel`): only the moves the transition table has from the status that the user's role may make, each disabled with the reason when its guard says no right now. `POST /landlord/reservations/{reservation}/confirm` → `ConfirmReservation` (`reservation.confirmed`), `…/reject` → `RejectProof` (`reservation.proof_rejected`, reason required), `…/decline` → `DeclineReservation` (`reservation.declined`, reason required) | Reject proof, confirm or decline (`confirm`, `rejectProof`, `decline`) | owner, caretaker |
| Undo a confirmation (6.1) | `POST …/{reservation}/undo` → `UndoConfirmation` (`reservation.confirmation_undone`) | Undo a confirmation (`undoConfirmation`) | owner, caretaker |
| Moved in, end the stay (6.1) | `POST …/{reservation}/moved-in` → `MarkMovedIn` (`reservation.moved_in`); `POST …/{reservation}/end` → `EndStay` (`reservation.stay_ended`) | Mark moved in, end stay (`markMovedIn`, `endStay`) | owner, caretaker |
| Withdraw (6.1) | `POST …/{reservation}/withdraw` → `WithdrawReservation` (`reservation.withdrawn`, reason required) | Withdraw a confirmed reservation (`withdraw`) | owner; a caretaker gets `403` and no button ("Only an owner can withdraw…") |
| Refund record (6.1) | `PUT …/{reservation}/refund` → `RecordRefund` (`reservation.refund_recorded`, one row per change), the refund card on a declined or cancelled reservation that had a proof | Record a refund (`recordRefund`) | owner, caretaker |

A form request asks the policy before it validates, so a caretaker gets `403` and never a validation message. An invitation is saved with a random token **stored as a SHA-256 hash** and valid for 7 days; the plain token exists only in the email (a queued, encrypted job). It is rejected for an email that belongs to a member of any account, to a renter (a person is one type), or that already has a pending invitation on this account. Changing a rate or fee touches nothing else: reservations keep the fee they were made with.

**Editing (slice 4.1).** The dashboard shows four figures, each linking to the reservations list that shows what was counted (`?status=proof_uploaded`, `?show=proofs_overdue`, `?show=holds_expiring`, `?show=moving_in`): payments to review (uploaded proofs, and how many waited over 24 hours), live holds that run out within 6 hours, confirmed move-ins from today to 7 days ahead, and occupancy (spaces with a confirmed or active stay today, out of the spaces that aren't archived in a room and property that aren't either). Every query is scoped to the account. Owners edit; a caretaker sees the same pages without a single button, and every write route and edit form answers `403` before validating (T7 walks the route list, so a new write route can't be missed). The address is entered as province, city, barangay and street address (optional), and written street first (slice 4.4): the three places are dropdowns you can type into, from the PSA's PSGC list (every province, city or municipality, and barangay; [DATA_MODEL.md](DATA_MODEL.md#places-slice-44)). The form posts only `city_id` and `barangay_id`; the server refuses a barangay that isn't in the chosen city ("That barangay isn't in the city you chose…", on the barangay field) and takes the province from the city, so no place is ever typed. Once the property has been published, its city and so its province are locked with the slug; its barangay can still change within the city. A property from before 4.4 that `places:import` couldn't link opens with the dropdown empty and its old text shown under it ("You wrote: Not set"). The lists come from two public, read-only endpoints, `GET /places/provinces/{province}/cities` and `GET /places/cities/{city}/barangays` (by slug; an unknown one is `404`), cacheable for a week and limited to 120 requests a minute per IP; they are neither landlord nor renter routes, so not T6 cases. The slug (the listing link) is generated from the name, unique per city (`-2`, `-3` on a clash); the form has no field for it (the request still accepts one until the property is first published). A room's type changes only while it has never had a reservation. Archiving is refused while a reservation still holds the space (`Reservation::blocking()`), naming it. The room row is locked first by every action that archives, changes a fee or type, or adds or renames spaces ([RESERVATIONS.md](RESERVATIONS.md#concurrency)).

**Photos (slice 4.2).** JPEG, PNG or WebP, judged by content (not the file name or the browser's MIME type): up to 10 MB, at least 800 px on the longer side, at most 40 megapixels, and the file must decode. At most 12 per property. The first photo is the cover; Up and Down buttons reorder. The files are public once resized (the path is a random UUID), as they will be on the listing page; the original upload never is, and it is deleted after the resize. The resize job is the system's work and writes no audit row.

**Publishing (slice 4.3).** A property can be published only when **all** of these hold: the account is verified, the property isn't archived, a room that isn't archived has at least one space that isn't, one photo is `ready`, the payment instructions are written, and the address is complete: its city and barangay are linked to the places list (slice 4.4; the form requires both, so only a property from before 4.4 can lack them). The property page shows an owner this as a checklist, each missing item with a link to fix it, and a Publish button that stays disabled until the list is done; `PublishProperty` checks the same list (`App\Support\PublishChecklist`) again on rows it has locked (the account, every room by id, then the property: `ArchiveProperty`'s order, so an archive can't interleave) and refuses with what is missing. The first publish sets `first_published_at`. Unpublishing is always allowed and touches no reservation: the ones the property has run their course, and nobody new can reserve.

**Decisions (slice 6.1).** The reservation page's decision panel offers what [RESERVATIONS.md](RESERVATIONS.md#transition-table) allows: on a proof waiting, Confirm, Ask for a new proof (until two were sent back; then the panel says to decline instead) and Decline; on a confirmed stay, Mark as moved in (disabled until the move-in date: "Moving in opens on Tue 1 Jun."), Undo confirmation ("Undo is possible until 3:40 PM today.") and, for owners, Withdraw; on an active stay, End stay. Reasons are typed in the dialog and shown to the renter. Decline, withdraw, end stay and moved in say in their dialog that they can't be undone. Every move is checked again by its action on the locked reservation row, so a page that is out of date gets the `error` flash, never a wrong move. The page names who decided, when, and the reason. The renter's hold page shows what the landlord did at the top (a proof sent back with the reason, the new countdown and the form again; a confirmation; a decline or withdrawal with the reason; the refund record once there is one), and My reservations adds "send a new proof" or "by the landlord" to the badge. The dashboard's "Payments to review" opens the reservation whose proof has waited longest.

**Members (slice 4.3).** An owner removes a caretaker or another owner; nobody removes themself, and **the last owner can't be removed**. `RemoveMember` locks every member row of the account and reads those rules from the locked rows, so of two owners removing each other at the same moment exactly one succeeds. A removed person keeps their login but has no membership: every landlord page answers `403` (a page of one record answers `404`, because the record is looked up first and the scope finds nothing for them). They can be invited again.

T6 and T7 ([tests](../tests/Feature/Isolation/T6CrossAccessTest.php)) prove all this on every route: see [CLAUDE.md](../CLAUDE.md#landlord-pages-slice-32).

### Accepting an invitation (slice 4.3)

`GET /invitations/{token}` and `POST /invitations/{token}` are public: the token is the key. Both are limited to 10 a minute per IP address (two separate counts, so mistyping the form doesn't lock the page), which makes guessing a 48-character token pointless. The invitation is found by `hash('sha256', $token)`.

**One neutral page.** An unknown, expired, revoked or already accepted token gets the same page with the same sentence, "This invitation link isn't valid any more. Ask the owner to send a new one.", and status `404`. It never says which case it was, and shows nothing of any invitation.

A valid token shows the account's name, the role and who sent the invitation, and then depends on who is looking:

| Who opens the link | What they see | What accepting does |
| --- | --- | --- |
| Not logged in, and no user has the invited email | A short sign-up form: name, mobile number, password. The email is shown, fixed to the invited one | Creates a **landlord** user with the invited email, **marked verified** (the token proves the inbox), and the membership, in one transaction; logs them in → `/landlord` |
| Not logged in, and a user has that email | "Log in to join". The link is kept as the page to return to after login | Signing up through the link is refused |
| Logged in as the invited email, a landlord user without an account (a removed member) | One button, "Join <account>" | The membership; their email is marked verified if it wasn't → `/landlord` |
| Logged in as the invited email, but a renter | Refused: a person is one type ([DATA_MODEL](DATA_MODEL.md)); ask for another email to be invited | Refused (`422`) |
| Logged in as the invited email, already a member of an account | Refused: `landlord_members.user_id` is unique, a person belongs to one account | Refused (`422`) |
| Logged in with another email | Says so, and offers to log out | Refused (`422`) |

`AcceptInvitation` locks the invitation row first, then the user row, so two accepts of one link (or an accept and a revoke or resend) run one after the other: exactly one membership. Accepting sets `accepted_at` and writes one audit row on the account, `invitation.accepted`, with the new member as the actor. The role is the invitation's, never the request's.

### Public pages (slice 5.1)

| Page | What it shows | Notes |
| --- | --- | --- |
| `GET /` | The pitch and the 6 newest listings (`first_published_at`, then id) as cards | |
| `GET /listings` | Search results: published properties that match the query-string filters, 12 to a page, cheapest matching room first (or newest) | Slice 5.2. `city`, `barangay`, `min_price`, `max_price`, `kind`, `gender` (`female` or `male`), `amenities[]`, `move_in` with `months`, `sort`, `page`. Bad values are ignored. **"For women" also shows places open to everyone** (women-only and `any`; men likewise), and the page says so. A place nobody can reserve for the dates is hidden, never shown as full. `GET /listings/count` returns the match count as JSON for the filter sheet |
| `GET /listings/{city}/{slug}` | The photos (cover first), name, kind, gender policy, the full address with an "Open in Maps" link, the landlord account's name, the from-price, amenities, house rules (which carry the refund rule), and each room with its fees, deposit, advance and free beds for a stay | `city` is the property's `city_slug`. Only `Property::published()` (published, not archived, verified account): anything else, a wrong city or an unknown slug is `404`, for a landlord too. Photos come through `PropertyPhoto::shown()` (ready photos of a published property), rooms through `Room::listed()` |
| `GET /rooms/{room}` | Nothing: `301` to the room's anchor on its listing | Listed rooms only, else `404` |
| `GET /sitemap.xml`, `GET /robots.txt` | The home page and every published listing; the crawl rules | Production's `robots.txt` names the sitemap and disallows `/landlord`, `/reservations`, `/invitations`, `/login` and `/register`; every other environment disallows everything |

**Never on these pages:** the landlord's phone or email (a renter reaches them after reserving), the property's payment instructions (shown on the hold page), and anything about other renters, including how many are on hold. Free beds are a count for the stay in the link, nothing more. The reserve button of each room is a plain `<form method="post">` with a `_token`, so it works without JavaScript; a broken rule (no free bed, a move-in outside the window…) comes back as the `error` flash on the listing page (`BusinessRuleException` redirects a plain form post back, like an Inertia one). A guest who presses it is sent to log in and comes back to that room on the listing with the same stay (slice 5.3).

## Permission matrix

| Action | Owner | Caretaker | Renter | Public |
| --- | --- | --- | --- | --- |
| Browse published listings | ✓ | ✓ | ✓ | ✓ |
| View own account's properties, rooms, spaces and photos | ✓ | ✓ | — | — |
| Create or edit properties, rooms, spaces, rates | ✓ | — | — | — |
| Upload, reorder or delete photos | ✓ | — | — | — |
| Publish or unpublish a property | ✓ | — | — | — |
| Archive or unarchive a property, room or space | ✓ | — | — | — |
| View reservations for own account | ✓ | ✓ | — | — |
| View own reservations | — | — | own only | — |
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

A ✓ for an owner or caretaker always means "on their own account"; "own only" for a renter means their own reservation, proof or viewing request. Another account's or another renter's record is a `404` for every ability. "View own account's properties, rooms, spaces and photos" and "View own reservations" were added in slice 3.1, to name abilities the pages already needed (the landlord pages of 3.2 and the renter's hold page of 2.5). [PermissionMatrixTest](../tests/Feature/Docs/PermissionMatrixTest.php) maps each row to the policy abilities that implement it.

## Role × step walkthrough

Each main flow, step by step, with who acts and what they see. This is where gaps show up before code.

### Flow A: Landlord onboarding to first listing

| Step | Actor | Notes |
| --- | --- | --- |
| Register as landlord | owner | Creates `landlord_account` + owner membership |
| Wait for verification | operator | `landlord:verify {slug}`. Until then the owner can draft but not publish; the dashboard says the account is being checked |
| Create property, rooms, spaces, rates | owner | Shared room: choose bed count, spaces generated as Bed A… |
| Upload photos | owner | Several at once. Resized by a queued job; the page shows "Resizing…" and checks every two seconds until each is ready or failed. The first photo is the cover |
| Publish | owner | The property page lists what is missing: a verified account, a room with a space, a ready photo, payment instructions. The button works when the list is done |
| Invite caretaker | owner | Email with a link, good for 7 days; the owner can send it again or cancel it |
| Accept | caretaker | Opens the link: signs up (name, mobile number, password; no email to confirm) or logs in with that email, and lands on `/landlord` with read-only property pages |
| Remove a member | owner | The person keeps their login; landlord pages refuse them. The last owner can't be removed |

### Flow B: Renter reserves a bed

| Step | Actor | Notes |
| --- | --- | --- |
| Search and browse | public, renter | Server-rendered; works without JavaScript |
| Choose a room, move-in date and months | renter | Must log in or register first; the intent is kept across login |
| Reserve | renter | `ReserveSpace` → hold. The hold page (`/reservations/{id}`) shows the fee, the property's payment instructions, the landlord account's name and phone, and a countdown ("Your hold ends in 23 hours"; without JavaScript "until 3:40 PM, Mon 16 Nov, Manila time") |
| Upload proof | renter | Image or PDF, ≤ 5 MB, amount and reference number, on the hold page while the hold is live ([Private files](#private-files)) |
| Cancel | renter | From the hold page while held, proof uploaded, or confirmed before the move-in date; the dialog quotes the house rules on refunds |
| My reservations | renter | `/reservations` (the account menu): current ones first, then past, ten a page |
| Review proof | owner or caretaker | Proof opened via a signed URL. Confirm, ask for a new proof (the renter gets a new 24-hour hold, at most twice) or decline, from the decision panel (6.1) |
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
| The invited person could already have an account, be a renter, or be logged in as someone else | The acceptance page has a case for each ([Accepting an invitation](#accepting-an-invitation-slice-43)) |
| An owner could publish a property nobody can pay for or see | The publish checklist (4.3) |
| One renter's parallel requests could beat the hold limits | Renter-row lock ([RESERVATIONS.md](RESERVATIONS.md#concurrency)) |

## Private files

Payment proofs live on the private `proofs` disk (`local` under `storage/app/private` in dev and tests, a private bucket in production that nothing but the app can read). Built in slice 5.3.

**Upload.** `POST /reservations/{reservation}/proofs` (renter, own reservation, else `404` before the form is even read): the file, the amount paid and the reference number. The file is judged by its **content**, never its name or the MIME type the browser sent (`App\Rules\PaymentProofFile`, `App\Support\Proofs\ProofFileInspector`): its first bytes must be a JPEG, PNG, WebP or PDF, at most 5 MB; an image must decode completely; a PDF must end with `%%EOF`, not be encrypted, and have at least one page object (also inside compressed object streams). HTML, SVG, GIF and anything renamed are refused. The bytes are stored **exactly as uploaded** (evidence: nothing is re-encoded or stripped) at a random path, `proofs/<uuid>.<ext>`, with the detected type. `UploadPaymentProof` then moves the reservation `held` → `proof_uploaded` (a live hold only).

**Viewing: two routes, three checks.**

1. `GET /proofs/{proof}` (logged in): the one link every page uses. It looks the proof up (through the isolation scope for a landlord, so another account's proof is a `404` at once), asks `PaymentProofPolicy::view` (the renter whose reservation it is, or an owner or caretaker of its account; anyone else `404`), and redirects (`Cache-Control: private, no-store`) to a **signed URL valid for 5 minutes** that names the proof **and the user it was made for**: `/proofs/{proof}/file?expires=…&user=…&signature=…`. The signature covers the path and query only (`signed:relative`), so it holds behind Caddy or GitHub's port forwarding.
2. `GET /proofs/{proof}/file` checks, in this order: the **signature and expiry** (`403`); that **the logged-in user is the one in the URL** (`403`, guests included: this route has no `auth` redirect on purpose); and **the policy again**, on a fresh lookup, so access lost within the 5 minutes (a removed caretaker) is refused (`404`). Then it **streams the file through PHP** with:

   | Header | Value |
   | --- | --- |
   | `Content-Type` | the type detected at upload |
   | `Content-Disposition` | `inline; filename="proof-<id>.<ext>"` |
   | `Cache-Control` | `private, no-store` |
   | `X-Content-Type-Options` | `nosniff` |
   | `Content-Security-Policy` | `sandbox` (no script, forms or plugins: a crafted file can't act in the app's origin). `SecurityHeaders` keeps a policy a response sets itself |

**Why stream, not an S3 pre-signed URL.** A pre-signed URL is a bearer token: anyone holding it gets the file until it expires, and the bucket would have to be reachable from the internet. Streaming keeps the bucket private and every byte behind the three checks above, at the cost of PHP time for a few hundred kilobytes per view. The landlord's `ProofViewer` (an image inline, a PDF as a link) and the renter's list of sent proofs both use `/proofs/{proof}`.

A leaked URL is useless to anyone else: T9 ([tests/Feature/Proofs/T9SignedProofUrlTest.php](../tests/Feature/Proofs/T9SignedProofUrlTest.php)) uses one as another renter, as the owner and caretaker of another account, as a member of the same account, as a guest, after 5 minutes, with the proof id, user or expiry changed, and after the caretaker was removed: always `403` or `404`, never the bytes. The renter and the account's owner and caretaker get exactly the bytes with the headers above. T6 walks both routes too.
