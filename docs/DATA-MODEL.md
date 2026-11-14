# Data model

The source of truth for the schema. Migrations must match it.

## Conventions

- **Money** is unsigned integer **centavos** (`reservation_fee = 150000` is ₱1,500.00). There are no floats anywhere.
- **Lease dates** (`move_in`, `move_out`) are `DATE` columns. `move_out` is exclusive. Moments (`hold_expires_at`, `decided_at`) are `TIMESTAMP` columns stored in UTC. The app timezone for "today" is `Asia/Manila`.
- **Enums** are `VARCHAR` columns with a `CHECK` constraint, mirrored by a PHP backed enum. This avoids MySQL `ENUM` migration pain and works on both MySQL 8 and MariaDB.
- **Landlord ownership is denormalized.** `rooms`, `spaces` and `reservations` carry `landlord_account_id`, kept consistent with **composite foreign keys**: `(property_id, landlord_account_id)` references `properties (id, landlord_account_id)`, and so on down the chain. The database itself then guarantees a room can't belong to a different landlord than its property. The isolation scope becomes a plain indexed `WHERE` with no joins.
- **No hard deletes** of anything a reservation points to. Properties, rooms and spaces are archived (`archived_at`) instead.

## Tables

`*` marks a column added to the scope document's model; the reason is in the Notes column.

### Accounts and people

| Table | Columns | Constraints / notes |
| --- | --- | --- |
| `users` | id, name, email, phone, type, password, email_verified_at, timestamps | `type IN ('renter','landlord')`. One user is one type; a landlord who wants to rent uses a second account (decision to check). Unique email |
| `landlord_accounts` | id, name, slug, contact_phone, is_verified, verified_at\*, timestamps | Unique slug. `is_verified` is set by `php artisan landlord:verify {slug}`. Only verified accounts can publish |
| `landlord_members` | id, landlord_account_id, user_id, role, timestamps | `role IN ('owner','caretaker')`. **Unique `user_id`**: a landlord user belongs to exactly one account, which makes "current account" unambiguous. At least one owner per account, enforced in `RemoveMember` |
| `landlord_invitations`\* | id, landlord_account_id, email, role, token_hash, invited_by, expires_at, accepted_at, revoked_at, timestamps | Needed for caretaker invites. The token is stored hashed and expires after 7 days. Correction: owner revokes |

### Listings

| Table | Columns | Constraints / notes |
| --- | --- | --- |
| `properties` | id, landlord_account_id, kind, name, slug, city, city_slug\*, barangay, address, lat, lng, gender_policy, house_rules, is_published, archived_at\*, timestamps | `kind IN ('apartment','boarding_house')`, `gender_policy IN ('any','male_only','female_only')`. Unique `(city_slug, slug)` for `/listings/{city}/{slug}`. Unique `(id, landlord_account_id)` as the composite FK target. `lat`/`lng` are `DECIMAL(9,6)` |
| `amenities` | id, name, slug | Seeded catalogue |
| `property_amenity` | property_id, amenity_id | Composite PK |
| `property_photos` | id, property_id, landlord_account_id\*, path, sort, status\*, timestamps | `status IN ('processing','ready','failed')`. Set by the resize job. Public disk |
| `rooms` | id, property_id, landlord_account_id\*, name, room_type, monthly_rate, deposit_months, advance_months, reservation_fee\*, archived_at\*, timestamps | `room_type IN ('private','shared')`. `monthly_rate > 0`, `reservation_fee >= 0`, `deposit_months` and `advance_months` 0–3. `reservation_fee` was missing from the scope document (`$room->reservationFee()`) |
| `spaces` | id, room_id, landlord_account_id\*, label, archived_at\*, timestamps | Unique `(room_id, label)`. A private room has exactly one space; enforced in the action, not the database |

### Reservations

| Table | Columns | Constraints / notes |
| --- | --- | --- |
| `reservations` | id, space_id, landlord_account_id\*, renter_id, move_in, move_out, status, reservation_fee, hold_expires_at, proof_rejections\*, decided_by, decided_at, decision_reason\*, cancelled_by\*, cancelled_at\*, fee_refunded\*, refund_note\*, timestamps | `CHECK (move_in < move_out)`, `CHECK (status IN (…8 statuses…))`, `CHECK (proof_rejections BETWEEN 0 AND 2)`. **Index `(space_id, status, move_in, move_out)`** for overlap checks and availability search. Index `(renter_id, status)` for the open-reservation limits. Index `(landlord_account_id, status)` for the dashboard |
| `payment_proofs` | id, reservation_id, landlord_account_id\*, path, amount, reference_no, status\*, reviewed_by\*, reviewed_at\*, rejection_reason\*, uploaded_at | `status IN ('pending','accepted','rejected')`. Several rows per reservation, because a rejected proof is kept and a new one uploaded. Private disk. `amount` is in centavos as the renter typed it |
| `viewing_requests` | id, property_id, landlord_account_id\*, renter_id, preferred_at, status, landlord_note, decided_by\*, timestamps | `status IN ('requested','accepted','declined','done','cancelled'*)`. `cancelled` lets the renter withdraw |
| `audit_logs` | id, actor_id, landlord_account_id, action, subject_type, subject_id, before (JSON), after (JSON), created_at | **Append-only.** The app's database user has `INSERT, SELECT` only on this table. There's no `updated_at` |

## Write actions and their correction paths

Each write action lists how a mistake is undone, so no flow is a dead end.

| Write action | Who | Correction path |
| --- | --- | --- |
| Create or edit property, room, space | owner | Edit again; archive instead of delete. Rates already snapshotted on reservations don't change |
| Archive room or space | owner | Unarchive. Archiving is blocked while a blocking reservation exists on it |
| Publish property | owner | Unpublish. Existing reservations are unaffected |
| Upload photo | owner | Delete photo, reorder |
| Invite caretaker | owner | Revoke the invite; remove the member |
| Remove member | owner | Re-invite. The last owner can't be removed |
| Reservation transitions | see [RESERVATIONS.md](RESERVATIONS.md#correction-paths) | see there |
| Viewing request | renter | Renter cancels. Landlord's accept or decline can be changed until `preferred_at` passes |
| Verify landlord account | operator (CLI) | `landlord:verify --revoke {slug}` unpublishes all of the account's properties |

## Invariants enforced by the database

| Invariant | How |
| --- | --- |
| A room, space, reservation or proof belongs to the same landlord as its property | Composite foreign keys |
| `move_in < move_out`, non-negative money, valid statuses | `CHECK` constraints (MySQL ≥ 8.0.16, MariaDB ≥ 10.2) |
| Audit log can't be edited or deleted | Grants: the app's DB user lacks `UPDATE`, `DELETE` on `audit_logs` |
| The app can't change the schema | Separate `migrator` and `app` DB users; see [ADR 0008](adr/0008-least-privilege-database-users.md) |
| No two blocking reservations overlap on a space | **Not expressible** in MySQL or MariaDB. Enforced by the room lock in `ReserveSpace`; see [ADR 0001](adr/0001-room-lock-vs-exclusion-constraint.md) and tests 1–4 |
