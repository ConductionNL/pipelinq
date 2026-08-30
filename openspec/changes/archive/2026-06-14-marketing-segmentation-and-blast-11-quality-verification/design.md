# Design: 11 Quality and Verification

## Scope

Verification and review closeout. No new production code beyond fixes that
verification surfaces. Exercises the full feature built across members 01–10.

## Approach (ADR-008 + ADR-005)

- Automated: `composer test` (or equivalent) green; coverage >=80% on
  SegmentService, ComplianceService, BlastService, AttributionService.
- Manual E2E against the running app: create segment → compliant template →
  send blast → watch BlastMonitor progress through draft → sending → sent.
- Compliance: send blocked for a contact without email consent (modal +
  skip), template save rejected without unsubscribe token.
- A/B: 50/50 split, both variants created, significance test renders once the
  thresholds are met.
- Unsubscribe: simulated webhook withdraws consent within 60s; future sends
  skip the contact.
- Pre-merge checklist: ObjectService-only CRUD, IUserSession-derived identity,
  generic errors, thin controllers, async webhook processing, consent gating,
  template enforcement, per-source rate limiting, attribution temporal order,
  Dutch seed data, `@spec` PHPDoc, coverage, integration test present.
