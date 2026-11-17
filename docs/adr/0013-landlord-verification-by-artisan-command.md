# 0013. Verify landlord accounts with an artisan command, not an admin web role

- Status: Accepted (2026-11-17)
- Date: 2026-11-14

## Context

Anyone can register as a landlord, so a new landlord account must not publish listings until someone has checked it is real. That someone is the platform's operator. An admin web interface is a whole second application: a role that sees every account's data (the opposite of [landlord isolation](../ACCESS.md#isolation-three-layers)), its own pages, policies, audit and tests.

## Options

1. **An admin web role** with pages to list, verify and revoke accounts.
2. **An artisan command** run on the server by the operator: `php artisan landlord:verify {slug}`, `--list`, `--revoke`.

## Decision

Option 2. There is no web role above the landlord accounts. Verification and revocation (which also unpublishes every property of the account, in one transaction) are console commands, recorded in the audit log with no actor. The operator's fallback for when email fails is a command too: `landlord:invite` prints a caretaker invitation link instead of mailing it.

## Consequences

- Good: no web surface can see across accounts; the isolation scope has no exception for an admin.
- Good: small, testable, and enough for one operator.
- Bad: the operator needs shell access to the server, and there is no queue of waiting accounts on a page (`--list` prints it).
- Follow-up: an admin web UI is in the backlog, for when there is more than one operator.
- Details: [ACCESS.md](../ACCESS.md#actors), [DEPLOY.md](../../DEPLOY.md#verifying-landlord-accounts).
