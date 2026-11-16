# The availability query (slice 5.2)

`GET /listings?city=quezon-city&min_price=3000&max_price=9000&move_in=2026-12-20&months=3` answers "which places have a room I can reserve for these dates, at this price, here?". This page is the query, its plan on the demo data, the indexes it uses, and what was (not) changed.

## One definition of "blocking"

The search does not restate when a reservation blocks a bed. It composes the two scopes `ReserveSpace` uses:

```
Space::freeDuring($in, $out)          not archived, and no reservation where
  └─ Reservation::blockingDuring()      (isolation scope removed: every account's reservations count)
       ├─ Reservation::blocking()        status in (proof_uploaded, confirmed, active), or held with hold_expires_at > "now"
       └─ Reservation::overlapping()     move_in < $out and move_out > $in   (move_out exclusive)
```

`$out = $in->addMonthsNoOverflow($months)`, the same rule as `ReserveSpace`. "Now" is a binding from Laravel's clock, never SQL `NOW()`. `Room::freeSpaceCount()` (the listing page's "N beds free") calls the same `freeDuring()`. Because there is no copy, a change to `blocking()` changes booking and search together; T10 compares the result with a brute-force check written independently, and it fails when `blocking()` is wrong (below).

## The SQL

A property matches when it passes the property filters and has at least one room that passes the room filters. Demo data, "now" = 2026-11-16 16:26 UTC (Laravel's `toRawSql()`, one page of 12):

```sql
select * from `properties` where `properties`.`is_published` = 1 and `properties`.`archived_at` is null and exists (select * from `landlord_accounts` where `properties`.`landlord_account_id` = `landlord_accounts`.`id` and `is_verified` = 1) and (`properties`.`city_slug` = 'quezon-city') and exists (select * from `rooms` where `properties`.`id` = `rooms`.`property_id` and `rooms`.`archived_at` is null and exists (select * from `properties` where `rooms`.`property_id` = `properties`.`id` and `properties`.`is_published` = 1 and `properties`.`archived_at` is null and exists (select * from `landlord_accounts` where `properties`.`landlord_account_id` = `landlord_accounts`.`id` and `is_verified` = 1)) and `rooms`.`monthly_rate` >= 300000 and `rooms`.`monthly_rate` <= 900000 and exists (select * from `spaces` where `rooms`.`id` = `spaces`.`room_id` and `spaces`.`archived_at` is null and not exists (select * from `reservations` where `spaces`.`id` = `reservations`.`space_id` and (`reservations`.`status` in ('proof_uploaded', 'confirmed', 'active') or (`reservations`.`status` = 'held' and `reservations`.`hold_expires_at` > '2026-11-16 16:26:48')) and `reservations`.`move_in` < '2027-03-20' and `reservations`.`move_out` > '2026-12-20'))) order by (select min(rooms.monthly_rate) from `rooms` where `rooms`.`archived_at` is null and exists (select * from `properties` where `rooms`.`property_id` = `properties`.`id` and `properties`.`is_published` = 1 and `properties`.`archived_at` is null and exists (select * from `landlord_accounts` where `properties`.`landlord_account_id` = `landlord_accounts`.`id` and `is_verified` = 1)) and `rooms`.`property_id` = `properties`.`id` and `rooms`.`monthly_rate` >= 300000 and `rooms`.`monthly_rate` <= 900000 and exists (select * from `spaces` where `rooms`.`id` = `spaces`.`room_id` and `spaces`.`archived_at` is null and not exists (select * from `reservations` where `spaces`.`id` = `reservations`.`space_id` and (`reservations`.`status` in ('proof_uploaded', 'confirmed', 'active') or (`reservations`.`status` = 'held' and `reservations`.`hold_expires_at` > '2026-11-16 16:26:48')) and `reservations`.`move_in` < '2027-03-20' and `reservations`.`move_out` > '2026-12-20'))) asc, `properties`.`first_published_at` desc, `properties`.`id` desc limit 12
```

Everything is in the database: the place, price, kind, gender policy, amenities, the date, and the order. PHP only formats the page's twelve properties. (`rooms()` is the same room conditions without the property wrapper, for T10; `summaries()` is one more query for the twelve cards: rooms that match, cheapest rate, free beds.)

The nested `exists (select * from properties …)` inside the room subquery comes from `Room::listed()`, the named public scope, which is kept on purpose (a landlord logged in sees other accounts' listings through it); the optimizer flattens it.

## Plan (MySQL 8.4.13, `EXPLAIN ANALYZE`)

Demo data: 60 properties, 2,165 reservations on about 400 spaces. A typical search: one city, a price range, a date and 3 months. 19 properties match.

```
-> Limit: 12 row(s)  (actual time=4.04..4.05 rows=12 loops=1)
    -> Sort: `(select #8)`, properties.first_published_at DESC, properties.id DESC, limit input to 12 row(s) per chunk  (actual time=4.03..4.04 rows=12 loops=1)
        -> Stream results  (cost=5.61 rows=249e-6) (actual time=2.16..2.99 rows=19 loops=1)
            -> Nested loop semijoin (FirstMatch)  (cost=5.61 rows=249e-6) (actual time=1.96..2.55 rows=19 loops=1)
                -> Nested loop inner join  (cost=5.61 rows=0.00499) (actual time=1.37..1.58 rows=28 loops=1)
                    -> Nested loop inner join  (cost=5.59 rows=0.0499) (actual time=1.36..1.55 rows=28 loops=1)
                        -> Nested loop inner join  (cost=5.24 rows=0.998) (actual time=0.884..0.971 rows=69 loops=1)
                            -> Filter: (landlord_accounts.is_verified = 1)  (cost=1.75 rows=1.5) (actual time=0.0208..0.0278 rows=12 loops=1)
                                -> Table scan on landlord_accounts  (cost=1.75 rows=15) (actual time=0.0183..0.0236 rows=15 loops=1)
                            -> Filter: (properties.archived_at is null)  (cost=1.71 rows=0.665) (actual time=0.00598..0.00889 rows=5.75 loops=12)
                                -> Index lookup on properties using properties_landlord_account_id_is_published_index (landlord_account_id = landlord_accounts.id, is_published = 1)  (cost=1.71 rows=6.65) (actual time=0.00581..0.00829 rows=5.75 loops=12)
                        -> Filter: ((properties.is_published = 1) and (properties.archived_at is null) and (properties.city_slug = 'quezon-city'))  (cost=0.255 rows=0.05) (actual time=0.00825..0.00828 rows=0.406 loops=69)
                            -> Single-row index lookup on properties using PRIMARY (id = properties.id)  (cost=0.255 rows=1) (actual time=0.00136..0.00139 rows=1 loops=69)
                    -> Filter: (landlord_accounts.is_verified = 1)  (cost=0.451 rows=0.1) (actual time=708e-6..804e-6 rows=1 loops=28)
                        -> Single-row index lookup on landlord_accounts using PRIMARY (id = properties.landlord_account_id)  (cost=0.451 rows=1) (actual time=520e-6..556e-6 rows=1 loops=28)
                -> Filter: ((rooms.archived_at is null) and (rooms.monthly_rate >= 300000) and (rooms.monthly_rate <= 900000) and exists(select #6))  (cost=0.0811 rows=0.05) (actual time=0.0345..0.0345 rows=0.679 loops=28)
                    -> Index lookup on rooms using rooms_property_id_landlord_account_id_foreign (property_id = properties.id)  (cost=0.0811 rows=2.48) (actual time=0.0025..0.00272 rows=1.75 loops=28)
                    -> Select #6 (subquery in condition; dependent)
                        -> Limit: 1 row(s)  (cost=1.33 rows=1) (actual time=0.0306..0.0306 rows=0.679 loops=28)
                            -> Nested loop antijoin  (cost=1.33 rows=1.41) (actual time=0.0304..0.0304 rows=0.679 loops=28)
                                -> Filter: (spaces.archived_at is null)  (cost=0.558 rows=0.215) (actual time=0.00335..0.00381 rows=1.79 loops=28)
                                    -> Index lookup on spaces using spaces_room_id_label_unique (room_id = rooms.id)  (cost=0.558 rows=2.15) (actual time=0.00321..0.00359 rows=1.79 loops=28)
                                -> Filter: (((reservations.`status` in ('proof_uploaded','confirmed','active')) or ((reservations.`status` = 'held') and (reservations.hold_expires_at > '2026-11-16 16:26:48'))) and (reservations.move_in < '2027-03-20') and (reservations.move_out > '2026-12-20'))  (cost=39.4 rows=6.56) (actual time=0.0147..0.0147 rows=0.62 loops=50)
                                    -> Index lookup on reservations using reservations_space_id_landlord_account_id_foreign (space_id = spaces.id)  (cost=39.4 rows=6.56) (actual time=0.00537..0.00557 rows=2.34 loops=50)
```

Reading it: it starts from the 12 verified accounts, finds their published properties through `properties_landlord_account_id_is_published_index`, checks the city on the primary-key row, finds each property's rooms by `rooms_property_id_landlord_account_id_foreign`, its spaces by `spaces_room_id_label_unique`, and probes `reservations` once per space as a **nested-loop antijoin** (the `NOT EXISTS`), which stops at the first blocking row. The `Sort` is the order-by subquery (the cheapest matching room), evaluated once per candidate property.

**Timing:** 2.27ms on average over 20 runs through the application (query only, dev machine); the plan itself says 4 ms.

## Indexes

Used: `properties_landlord_account_id_is_published_index`, `rooms_property_id_landlord_account_id_foreign`, `spaces_room_id_label_unique` and, for the antijoin, `reservations_space_id_landlord_account_id_foreign` (about 2.3 reservations per space).

**No index was added or changed.** The documented `reservations_space_status_dates_index (space_id, status, move_in, move_out)` is not the one MySQL picks, because the blocking condition is an `OR` (a status list, or a live hold), so its `status` and date columns can't narrow the lookup. Forcing it (`USE INDEX`) on the antijoin alone, 5 runs each:

| Index | ms per run |
| --- | --- |
| optimizer's choice (`…_space_id_landlord_account_id_foreign`) | 2.05, 2.03, 2.09, 2.19, 1.97 |
| forced `reservations_space_status_dates_index` | 7.26, 2.09, 2.24, 1.99, 2.03 |

Equal: with a handful of reservations per space, finding the space's rows is the whole cost and any index on `space_id` does it. An index would only matter when one space has thousands of reservations, which a stay of at most 12 months in a bed that is archived when it is retired makes unlikely; revisit with `EXPLAIN` if that changes.

## Notes

- Inside Pest's `DatabaseTransactions`, on MySQL, T10's queries take 40 to 50 ms instead of 2 ms (the tables were just filled in an open transaction, so InnoDB's statistics are those of empty tables). On MariaDB 11.4 the same T10 takes 9 s in all instead of 60 s. This is a test-environment effect, not the production plan above.
- Counting for the filter sheet's button (`GET /listings/count`) runs the same query as a `count(*)`.
