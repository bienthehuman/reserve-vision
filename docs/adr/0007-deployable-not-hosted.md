# 0007. Ship deployable, not hosted; prove deployment in CI

- Status: Proposed
- Date: 2026-11-14

## Context

The plan delivers a production setup without paying for a live host. The previous project (Hilom) deferred deployment and found it to be the last blocker. The risk is a production setup that has never actually run.

## Options

1. **Live walking skeleton from phase 0**: a VPS or free tier with HTTPS and deploy on tag.
2. **Deployable, not hosted**: production Compose file, deploy script and DEPLOY.md; reviewers use the Codespaces demo.

## Decision

Option 2, with a guard. From slice 1.3, **every PR to `main` builds the production image, boots `compose.prod.yaml` in CI, runs migrations as the `migrator` user, and smoke-tests the home page, a listing page and login over HTTP.**

## Consequences

- Good: no hosting cost or upkeep; the Codespaces demo gives reviewers a running app.
- Good: the production path is exercised continuously, so "deployable" stays true.
- Bad: no public URL to share; HTTPS issuance (Caddy) and real email delivery aren't exercised in CI. DEPLOY.md documents them as manual steps.
- Revisit if a job application needs a live link.
