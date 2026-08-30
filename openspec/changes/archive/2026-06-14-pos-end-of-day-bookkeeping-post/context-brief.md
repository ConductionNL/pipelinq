---
status: draft
---

# POS End-of-day Bookkeeping Post Pipeline

## Purpose

Aggregate Z-report → outbound message → shillinq JournalEntry; retry with idempotency key. Defines the canonical POS↔ERP contract.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 13/13 competitors
- **Dependencies:** pos-transaction-core, pos-cash-management

## Cross-app integration

Direct integration; shillinq.JournalEntry.post with idempotency on transaction UUID.

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 0 competitor implementations. See `/tmp/pipelinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
