# Design: 03 Compliance Service

## Scope

`lib/Service/ComplianceService.php` only. Reads ConsentRecord +
CampaignTemplate schemas (member 01) via `ObjectService`, calls
`SegmentService` (member 02) for member lists. No Vue, no controllers.

## Methods

- `checkSegmentCompliance(string $segmentId, string $channel): array` —
  get segment members, check each member's ConsentRecord for lawful basis
  on channel (withdrawnAt null); return
  `{ compliant, missingConsent: [ids], missingCount }`.
- `hasConsentForChannel(string $contactId, string $channel): bool` — query
  ConsentRecord; true if lawfulBasis set and non-withdrawn.
- `recordConsentWithdrawal(string $contactId, string $channel, string $reason, ?string $sourceBlastId = null): void` —
  set withdrawnAt + withdrawnReason; transition queued deliveries for the
  contact to "unsubscribed-before-send" (via BlastService, wired in member 04);
  log withdrawal.
- `validateTemplate(array $templateData, string $channel): ?string` — for
  email, require `{{unsubscribe_link}}` + physical-address placeholders; for
  SMS, no validation.

## Security / patterns

ADR-005: fail-safe on consent — "imported" lawful basis does NOT satisfy
gating. ADR-001/022: ConsentRecord CRUD via `ObjectService`. Inject
`ObjectService`, `LoggerInterface` (and `SegmentService`). `@spec` PHPDoc.
The `transitionQueuedDeliveries` call into BlastService is wired when
member 04 lands; until then withdrawal records consent and logs.
