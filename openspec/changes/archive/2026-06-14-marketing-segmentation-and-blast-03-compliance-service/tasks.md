# Tasks: 03 Compliance Service

## ComplianceService (Task 2.2 of giant)

- [x] Create `lib/Service/ComplianceService.php`
  Skeleton landed in `lib/Service/ComplianceService.php` — class shell with
  three slug constants (`DEFAULT_REGISTER_SLUG = "pipelinq"`,
  `DEFAULT_CONSENT_RECORD_SCHEMA_SLUG = "consentRecord"`,
  `DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG = "blastDelivery"`) matching the
  chain-root register fragment, the standard Pipelinq SPDX/PHPDoc
  header, and the shared OR/array-normalisation helpers (`getObjectService`,
  `toArray`, `extractObjectId`, slug resolvers).
- [x] Implement `checkSegmentCompliance(string $segmentId, string $channel): array` — get members via SegmentService, check each ConsentRecord (lawful-basis on channel, withdrawnAt null); return `{ compliant, missingConsent, missingCount }`
  Implemented — walks `SegmentService::getMembersForBlast()`, gates each
  member through `hasConsentForChannel()`, returns the
  `{compliant, missingConsent[], missingCount}` triple. Empty recipient
  sets short-circuit to `compliant: true, missingCount: 0`. Members
  without a contactId are conservatively counted as missing (fail safe).
- [x] Implement `hasConsentForChannel(string $contactId, string $channel): bool` — query ConsentRecord, true if lawfulBasis set and non-withdrawn
  Implemented — `findConsentRecord()` queries OR via
  `ObjectService::findAll(filters: [contactId, channel])` with a
  defensive in-PHP filter (OR's filter DSL silently drops unknown keys);
  `hasConsentForChannel` returns true iff `lawfulBasis ∈ {consent,
  legitimate-interest, contract}` and `withdrawnAt` is empty.
- [x] Implement `recordConsentWithdrawal(string $contactId, string $channel, string $reason, ?string $sourceBlastId = null): void` — set withdrawnAt + withdrawnReason, transition queued deliveries to "unsubscribed-before-send", log withdrawal
  Implemented — sets `withdrawnAt` to UTC ISO-8601 and `withdrawnReason`
  on the existing ConsentRecord (`persistConsentUpdate`); creates a
  synthetic record when none exists so the audit ledger is preserved
  for GDPR Art. 7(3) withdrawals against rows that were never captured
  (`persistConsentCreate`); idempotent on already-withdrawn records;
  `transitionQueuedDeliveries` walks BlastDelivery rows for the contact,
  flips queued→unsubscribed-before-send (status + unsubscribedAt). When
  `$sourceBlastId` is supplied, only rows for that blast are skipped.
  Member 04 will replace the direct OR write with a BlastService call
  for counter roll-up; the seam is the private method.
- [x] Implement `validateTemplate(array $templateData, string $channel): ?string` — email requires `{{unsubscribe_link}}` + physical-address placeholders; SMS returns null
  Implemented — returns null for any non-email channel; for email checks
  `bodyHtml`, `bodyText`, AND `footerOverride` for the literal
  `{{unsubscribe_link}}` token, then for any of `{{physical_address}}`,
  `{{sender_address}}`, `{{company_address}}`, `{{address_block}}` OR
  a non-empty `footerOverride`. Returns descriptive errors citing
  GDPR Art. 7(3) and CAN-SPAM § 7704(a)(5).
- [x] Treat lawful-basis "imported" as NOT satisfying consent gating (ADR-005 fail-safe)
  Implemented — `LAWFUL_BASIS_UNSATISFYING = ['imported']` is consulted
  before `LAWFUL_BASIS_ALLOWED`; matches surface an `info`-level log
  noting why the contact was excluded so an operator can trace the
  block back to the import without flipping the rule open.
- [x] Inject `ObjectService`, `LoggerInterface`, `SegmentService`
  DI shape: `ContainerInterface` (lazy OR `ObjectService` resolve to
  avoid hard-loading OR at app boot, mirroring SegmentService),
  `IAppConfig`, `SegmentService`, `LoggerInterface`. NC autowiring
  satisfies the constructor without an explicit `registerService` —
  same pattern slice 02 used. No changes to `lib/AppInfo/Application.php`.
- [x] Add `@spec` PHPDoc
  Every public method carries an `@spec` PHPDoc tag pointing at the
  tasks-file anchor (`#check-segment-compliance`,
  `#has-consent-for-channel`, `#record-consent-withdrawal`,
  `#validate-template`); the constructor carries `#di`; the class
  carries `#compliance-service`. The shared OR helpers reuse the
  SegmentService convention of leaving private helpers untagged.
