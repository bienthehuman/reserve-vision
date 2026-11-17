# 0011. Stream payment proofs through PHP, behind user-bound signed links, not pre-signed storage URLs

- Status: Accepted (2026-11-17)
- Date: 2026-11-17 (slice 5.3)

## Context

A payment proof is a screenshot of a GCash or bank transfer: names, account numbers, amounts. Only the renter who sent it and the members of the landlord account it was sent to may see it, and a link that leaks (forwarded, in a browser history, in a log) must not open it for anyone else. In production the files live in an S3-compatible bucket (MinIO by default, [DEPLOY.md](../../DEPLOY.md#storage-for-payment-proofs)).

## Options

1. **S3 pre-signed URLs**: the app checks access, then redirects to a short-lived URL the storage service serves. No PHP time per byte, but the URL is a bearer token (anyone holding it gets the file until it expires), and the bucket must be reachable from the internet.
2. **Stream through PHP behind a signed, user-bound link**: `GET /proofs/{proof}` checks the policy and redirects to `/proofs/{proof}/file?expires=…&user=…&signature=…`, valid 5 minutes; that route checks the signature, that the logged-in user is the one named in the link, and the policy again, then streams the bytes from the private bucket.
3. **Stream through PHP on every request, no signed link**: simpler, but each embed (`<img>`) and download repeats the full lookup, and there is no expiry to reason about.

## Decision

Option 2. The bucket is never public, and nothing in front of the app (Caddy) can reach it. The download carries `Content-Security-Policy: sandbox`, `X-Content-Type-Options: nosniff` and `Cache-Control: private, no-store`. Proofs are stored byte for byte as uploaded (evidence), and judged by their content (magic bytes, a complete decode, a PDF's structure), never by the name or the browser's MIME type.

## Consequences

- Good: a leaked link is useless to anyone else: another renter, another account, a guest, after 5 minutes, after access was lost, or tampered with, all get `403` or `404` (**T9**, [tests/Feature/Proofs/T9SignedProofUrlTest.php](../../tests/Feature/Proofs/T9SignedProofUrlTest.php)).
- Good: the storage credentials and bucket stay internal; any S3 provider works without public-access settings.
- Bad: PHP serves a few hundred kilobytes per view. Proofs are viewed a handful of times each, so this is cheap; photos, which every visitor loads, are public and served by Caddy instead.
- Bad: the signature covers the path and query only (`signed:relative`), so it holds behind proxies; that is a choice to remember if the app is ever served from two hosts.
- Details: [ACCESS.md](../ACCESS.md#private-files).
