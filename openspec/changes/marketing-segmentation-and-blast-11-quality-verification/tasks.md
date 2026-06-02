# Tasks: 11 Quality and Verification

## Run tests (Task 7.1 of giant)

- [ ] Run `composer test` or equivalent; all test methods pass
- [ ] Check coverage: SegmentService, ComplianceService, BlastService, AttributionService >=80%; fix failing tests before PR

## E2E workflow (Task 7.2 of giant)

- [ ] Start dev server; create test segment (simple rule) + matching contacts with consent
- [ ] Create email template with unsubscribe token + address; create blast and send
- [ ] Verify blast progresses draft → sending → sent; BlastDeliveries created; monitor in BlastMonitor; seed data loads

## Compliance blocking (Task 7.3 of giant)

- [ ] Create contact without email consent; blast targeting segment including it; attempt send → modal with contact ID
- [ ] Verify "Skip contacts" works; create template without unsubscribe token → save rejected

## A/B testing (Task 7.4 of giant)

- [ ] Create blast with A/B split 50%; send to segment >=1000 contacts
- [ ] Verify variant A and B both created and sending; once >500 delivered + 24h check PerformanceDashboard significance test (p-value + interpretation)

## Unsubscribe propagation (Task 7.5 of giant)

- [ ] Send test blast; simulate unsubscribe webhook POST `/webhook/sendgrid`
- [ ] Check ConsentRecord within 1 minute (withdrawnAt set); verify future blasts skip the unsubscribed contact

## Pre-merge checklist (Task 8.1 of giant)

- [ ] Walk pre-merge checklist: ObjectService-only CRUD, IUserSession identity, generic errors, thin controllers, async webhooks, consent gating, template enforcement, configurable rate limit + soft-bounce threshold, attribution temporal order, Dutch seed data, `@spec` PHPDoc, coverage, integration test present
