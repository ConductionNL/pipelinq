# Tasks: ticket-leaf-closed-reference-assignee

- [x] 1.1 Test (red first): `TicketLeafProviderTest` (closed, all, unknown filter, reference/assigneeName/resolvedAt).
- [x] 1.2 `byMasterEntity` and the richer row.
- [x] 2.1 Test: `TicketReferenceSequenceTest`, `TicketReferenceCreatingListenerTest`.
- [x] 2.2 Ticket `reference` property, sequence, creating listener.
- [x] 3.1 Existing tickets have no reference until they are re-created or backfilled; the example sets can carry theirs.
  - Built: repair step `lib/Repair/BackfillTicketReferences.php` (after `BackfillTicketPhoneE164` in `appinfo/info.xml`) draws a number from the same yearly `TicketReferenceSequence` for every request and complaint without a `reference`; other ticket types and tickets that have one are left alone, so a second run writes nothing. Test: `tests/Unit/Repair/BackfillTicketReferencesTest.php`.
  - Found red live first: two tickets created in one process both got `PQ-2026-0001`, because `InvoiceSequenceService` read its counter through the per-process `IAppConfig` cache (fixed in the same branch: the counter is read from and reset in `oc_appconfig`; `tests/Unit/Service/InvoiceSequenceServiceTest.php`).
  - Live 26 Nov 2026 (sandbox NC 34 + MariaDB, OpenRegister main e07d9e1b): three tickets created in one process got 0001, 0002, 0003; six requests and complaints with their `reference` removed, then `occ maintenance:repair`: "Ticket references: 6 numbered, 0 unchanged, 0 failed." (PQ-2026-0004 to 0009); a second repair: "0 numbered, 6 unchanged".
