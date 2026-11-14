# ReserveVision: notes for agents and contributors

Read [docs/CONVENTIONS.md](docs/CONVENTIONS.md) first. The docs in `docs/` are the source of truth. Update them in the same PR as any behaviour change.

## Workflow

- One slice = one branch (`slice/NN-name`) = one PR. The user reviews, merges and says "merged and pulled".
- Make in-slice design calls yourself and list them under **Decisions to check** in the PR; ask only when a choice is costly to reverse.
- **No AI attribution** in commits or PR descriptions (no Co-Authored-By, no "Generated with" footer).
- Out-of-slice ideas go to the backlog table in docs/BUILD-PLAN.md.
- Never use the word "tenant" (ADR 0005).

## Environment

_Filled in by slice 1: ports, service names, tool versions, how to run each test suite, DB users, known CLI quirks._
