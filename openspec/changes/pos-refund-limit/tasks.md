## 1. Build

- [ ] 1.1 A refund limit setting (euros, empty by default) in the POS admin settings, read through the settings service.
- [ ] 1.2 `lib/Lifecycle/PosRefundManagerGuard.php` allows refund and complete at or below the limit for a till user with access to the transaction; above it the manager rule stays (REQ-PLG-007).
- [ ] 1.3 The refund screen says when a refund needs a manager.
- [ ] 1.4 Unit tests in `tests/Unit/Lifecycle/` for below, above and no limit, with the over-refund cap still enforced.

## 2. Hand-over

- [ ] 2.1 `@spec` tags and an e2e test or a reason-bearing `@e2e exclude` per scenario.
- [ ] 2.2 When archiving, narrow REQ-PLG-004 in `openspec/specs/pos-lifecycle-guard-adoption/spec.md` to refunds above the limit; its `### REQ-...` heading is not a `Requirement:` header, so a MODIFIED delta cannot reach it.
