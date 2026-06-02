# Tasks: 03 Compliance Service

## ComplianceService (Task 2.2 of giant)

- [ ] Create `lib/Service/ComplianceService.php`
- [ ] Implement `checkSegmentCompliance(string $segmentId, string $channel): array` — get members via SegmentService, check each ConsentRecord (lawful-basis on channel, withdrawnAt null); return `{ compliant, missingConsent, missingCount }`
- [ ] Implement `hasConsentForChannel(string $contactId, string $channel): bool` — query ConsentRecord, true if lawfulBasis set and non-withdrawn
- [ ] Implement `recordConsentWithdrawal(string $contactId, string $channel, string $reason, ?string $sourceBlastId = null): void` — set withdrawnAt + withdrawnReason, transition queued deliveries to "unsubscribed-before-send", log withdrawal
- [ ] Implement `validateTemplate(array $templateData, string $channel): ?string` — email requires `{{unsubscribe_link}}` + physical-address placeholders; SMS returns null
- [ ] Treat lawful-basis "imported" as NOT satisfying consent gating (ADR-005 fail-safe)
- [ ] Inject `ObjectService`, `LoggerInterface`, `SegmentService`
- [ ] Add `@spec` PHPDoc
