# Tasks — pipelinq: adopt RegisterResolverService

ADR-032 cap respected (≤20 unchecked tasks).

Spec-only change. Code paths listed are implementation hints for the apply phase.
The register-resolver migration is the biggest single win in the pipelinq backend
cleanup batch.

## Phase 1 — register-resolver consumption

Eight call sites of `$appConfig->getValueString(APP_ID, 'register', '')`. Migrate
ALL to `RegisterResolverService` per the OR-side spec. Audit citation:
`.claude/audit-2026-05-03/04-hardcoded.md`.

- [ ] 1.1 `lib/Service/QueueService.php:57` — replace
      `$appConfig->getValueString(APP_ID, 'register', '')` with
      `RegisterResolverService::resolve('queue')`.
- [ ] 1.2 `lib/Service/QueueService.php:145` — same migration.
- [ ] 1.3 `lib/Service/QueueService.php:236` — same migration.
- [ ] 1.4 `lib/Service/QueueService.php:292` — same migration.
- [ ] 1.5 `lib/Service/DefaultQueueService.php:122` — same migration.
- [ ] 1.6 `lib/Service/DefaultQueueService.php:179` — same migration.
- [ ] 1.7 `lib/Service/ContactVcardService.php:102` — replace with
      `RegisterResolverService::resolve('contact')`.
- [ ] 1.8 `lib/Service/ContactVcardWriterService.php:139` — same migration as 1.7.
- [ ] 1.9 Verify no remaining `getValueString(APP_ID, 'register', '')` matches in
      `lib/` after the migration.
