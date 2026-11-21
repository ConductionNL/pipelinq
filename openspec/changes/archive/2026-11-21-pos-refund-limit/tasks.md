## 1. Build

- [x] 1.1 A refund limit setting (euros, empty by default) in the POS admin settings, read through the settings service.
- [x] 1.2 `lib/Lifecycle/PosRefundManagerGuard.php` allows refund and complete at or below the limit for a till user with access to the transaction; above it the manager rule stays (REQ-PLG-007).
- [x] 1.3 The refund screen says when a refund needs a manager.
- [x] 1.4 Unit tests in `tests/Unit/Lifecycle/` for below, above and no limit, with the over-refund cap still enforced.

## 2. Hand-over

- [x] 2.1 `@spec` tags and an e2e test or a reason-bearing `@e2e exclude` per scenario.
  - The guard, the settings read and the refund screen carry `@spec`; each of the three scenarios has an `@e2e exclude` naming the PHPUnit and Vitest cases that assert it (no seeded non-manager till user or POS manager group on the CI instance).
- [x] 2.2 When archiving, narrow REQ-PLG-004 in `openspec/specs/pos-lifecycle-guard-adoption/spec.md` to refunds above the limit; its `### REQ-...` heading is not a `Requirement:` header, so a MODIFIED delta cannot reach it.
  - Done on archive: REQ-PLG-004 keeps `refund` on a posTransaction and `reject` on a posRefund with a manager, and lets a till user `complete` a posRefund at or below the limit (as `PosTransactionRefundGuard` and `PosRefundManagerGuard` do); REQ-PLG-007 is added to the spec. The `pos-refund-guard` row is built.
