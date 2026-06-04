---
status: draft
---

# POS Kassakoppeling-compliant Audit Log

## Purpose

Append-only signed ledger of every register action (sale, void, refund, no-sale); hash chain; Belastingdienst export. **Unique NL moat — no OSS competitor packages this cleanly.**

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 5/13 competitors
- **Dependencies:** pos-transaction-core

## Cross-app integration

Audit log linkable to each shillinq journal entry via transaction UUID.

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 0 competitor implementations. See `/tmp/pipelinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
