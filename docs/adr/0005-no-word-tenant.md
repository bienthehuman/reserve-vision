# 0005. Avoid the word "tenant" in the code

- Status: Proposed
- Date: 2026-11-14

## Context

In rental language a "tenant" is the person renting. In SaaS language a "tenant" is the customer organization whose data is isolated. This app has both concepts, so the word is ambiguous everywhere it appears.

## Decision

The code, database, docs and UI never use "tenant". The SaaS customer is a **landlord account**; its users are **landlord members** (owner or caretaker); the person renting is a **renter**. Multi-tenancy is called **landlord isolation**.

## Consequences

- Good: no reader has to guess which meaning is intended.
- Bad: off-the-shelf "multi-tenancy" packages don't fit the vocabulary. They aren't needed anyway; isolation is a global scope, policies and tests.
- An architecture test fails the build if `tenant` appears in `app/`, `database/`, `resources/` or `routes/`.
