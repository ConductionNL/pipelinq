# Tasks: 04 Blast and Attribution Services

## BlastService (Task 2.3 of giant)

- [x] Create `lib/Service/BlastService.php`
- [x] Implement `sendBlast(string $blastId, bool $isDraft = false): array` — load Blast, assert draft, call ComplianceService gate, return skip summary on missing consent, else queue compliant BlastDeliveries and transition to "sending"
- [x] In sendBlast: if abSplitPercent set, create variant B Blast and split members deterministically via hash(contactId)
- [x] Implement `dispatchBlastDeliveries(string $blastId, int $maxPerSecond = 100): int` — batch queued rows, read throttle from openconnector source, call `send-mail`, store providerId, update totals, enforce rate limit
- [x] Implement `createAbVariant(string $parentBlastId, array $variantData): string`
- [x] Implement `updateBlastTotals(string $blastId): void` — recount by status
- [x] Implement `transitionQueuedDeliveries(string $contactId, string $blastId, string $newStatus): void` (called by ComplianceService)
- [x] Inject `SegmentService`, `ComplianceService`, `ObjectService`, `IAppConfig`, `LoggerInterface`; add `@spec` PHPDoc

## AttributionService (Task 2.4 of giant)

- [x] Create `lib/Service/AttributionService.php`
- [x] Implement `recordClick(string $blastDeliveryId, array $clickEvent): void` — set firstClickAt + clickedUrls
- [x] Implement `linkBlastToDeal(string $blastDeliveryId, string $dealId): void` — create AttributionLink
- [x] Implement `getBlastAttributedValue(string $blastId): float` — sum attributedValue
- [x] Inject `ObjectService`, `LoggerInterface`; add `@spec` PHPDoc
