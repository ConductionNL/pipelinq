# Tasks — pipelinq: adopt RegisterResolverService

ADR-032 cap respected (≤20 unchecked tasks).

Spec-only change. Code paths listed are implementation hints for the apply phase.
The register-resolver migration is the biggest single win in the pipelinq backend
cleanup batch.

## Phase 1 — register-resolver consumption

Eight call sites of `$appConfig->getValueString(APP_ID, 'register', '')`. Migrate
ALL to `RegisterResolverService` per the OR-side spec. Audit citation:
`.claude/audit-2026-05-03/04-hardcoded.md`.

- [x] 1.1 `lib/Service/QueueService.php` (line drifted to 60) — replaced
      `$appConfig->getValueString(APP_ID, 'register', '')` with
      `$this->registerResolver->resolve('queue')`.
- [x] 1.2 `lib/Service/QueueService.php` (line drifted to 150) — same migration.
- [x] 1.3 `lib/Service/QueueService.php` (line drifted to 239) — same migration.
- [x] 1.4 `lib/Service/QueueService.php` (line drifted to 293) — same migration.
- [x] 1.5 `lib/Service/DefaultQueueService.php` (line drifted to 127) — same migration.
- [x] 1.6 `lib/Service/DefaultQueueService.php` (line drifted to 182) — same migration.
- [x] 1.7 `lib/Service/ContactVcardService.php` (line drifted to 121) — replaced with
      `$this->registerResolver->resolve('contact')`.
- [x] 1.8 `lib/Service/ContactVcardWriterService.php` (line drifted to 143) — same
      migration as 1.7.
- [x] 1.9 Verified no remaining `getValueString(APP_ID, 'register', '')` matches in
      the **four targeted files** after the migration. NOTE: 16 register reads remain
      in 11 OTHER `lib/` files (RoutingService, controllers, background jobs, etc.) that
      the proposal's "Affected code" list does NOT name — they are out of this Phase-1
      slice's scope (design Decision 1 scopes Phase 1 to exactly eight sites). A
      follow-up slice should migrate the remaining reads.
