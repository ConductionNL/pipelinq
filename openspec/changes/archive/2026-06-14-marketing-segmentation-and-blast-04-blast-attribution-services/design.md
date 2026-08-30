# Design: 04 Blast and Attribution Services

## Scope

`lib/Service/BlastService.php` + `lib/Service/AttributionService.php`. Reads
Blast/BlastDelivery/AttributionLink schemas (member 01) via `ObjectService`;
calls `SegmentService` (02) and `ComplianceService` (03). No Vue, no job.

## BlastService

- `sendBlast(string $blastId, bool $isDraft = false): array` — load Blast,
  assert status "draft", call `ComplianceService.checkSegmentCompliance()`;
  on missing consent return skip summary and keep draft; else get members
  via `SegmentService.getMembersForBlast()`, A/B-split deterministically
  (`hash(contactId) % 100 < abSplitPercent`), create queued BlastDelivery
  rows, transition Blast to "sending".
- `dispatchBlastDeliveries(string $blastId, int $maxPerSecond = 100): int` —
  read throttle from openconnector source config; batch (default 50); render
  template; call openconnector source `send-mail` action; persist providerId;
  update totals; enforce rate limit between batches.
- `createAbVariant()`, `updateBlastTotals()`, `transitionQueuedDeliveries()`.

## AttributionService

- `recordClick(string $blastDeliveryId, array $clickEvent): void` — set
  firstClickAt + clickedUrls.
- `linkBlastToDeal(string $blastDeliveryId, string $dealId): void` — create
  AttributionLink joining blastId/contactId/dealId/firstClickAt/closedWonAt/
  attributedValue.
- `getBlastAttributedValue(string $blastId): float` — sum attributedValue.

## Security / patterns

ADR-005: never embed SendGrid/SES/Twilio credentials — delegate to
`OCA\OpenConnector\Service\SourceService::executeAction()`. ADR-001/022: all
CRUD via `ObjectService`. Inject `SegmentService`, `ComplianceService`,
`ObjectService`, `IAppConfig`, `LoggerInterface`. `@spec` PHPDoc.
