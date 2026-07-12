# Tasks: unify-ticket-supertype

## Phase 0 — Additive schema

- [x] 0.1 Register the `ticket` schema in the pipelinq register
  - **spec_ref**: `specs/unify-ticket-supertype/spec.md#requirement-ticket-supertype-schema`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - `ticket` schema present with `ticketType` discriminator and the unioned fields (design.md table)
    - `x-schema-org-by-type` marker maps request→Demand, complaint→Message, contactmoment→CommunicateAction
    - Unified `status` enum + merged `x-openregister-lifecycle` guarded by `ticketType` match
    - Notifications (ADR-031) moved onto `ticket` with per-type `match`
    - Additive only — the existing three schemas are untouched in this phase

- [x] 0.2 Wire the `ticket_schema` config key (SettingsLoadService SCHEMA_SLUGS + SchemaMapService; consumed by the new TicketService resolver)
  - **spec_ref**: `specs/unify-ticket-supertype/spec.md#requirement-ticket-supertype-schema`
  - **files**: `lib/Service/SettingsService.php`, `lib/Service/SettingsLoadService.php`, schema-map service
  - **acceptance_criteria**:
    - Imported schema ID stored under `ticket_schema`; existing request/complaint/contactmoment keys retained during transition

## Phase 1 — Migration

- [x] 1.1 Implement `Repair\MigrateToTicketSupertype` (copy + map)
  - **spec_ref**: `specs/unify-ticket-supertype/spec.md#requirement-lossless-migration-of-existing-records`
  - **files**: `lib/Repair/MigrateToTicketSupertype.php`, `appinfo/info.xml`
  - **acceptance_criteria**:
    - Idempotent (marker per produced ticket; re-run creates no duplicates)
    - Field mapping per design.md; contactmoment status derived from `outcome`, `outcome` retained
    - Dry-run count logged before writes; aborts cleanly if OpenRegister absent
    - Old objects left intact

- [x] 1.2 Remap intra-CRM references
  - **spec_ref**: `specs/unify-ticket-supertype/spec.md#scenario-contactmoment-parent-linkage-preserved`
  - **files**: `lib/Repair/MigrateToTicketSupertype.php`
  - **acceptance_criteria**:
    - `contactmoment.request` → `ticket.parentTicket`
    - `task.requestId` → new ticket UUID

- [x] 1.3 Remap OpenRegister link tables — VERIFIED NO-OP: 0 mail/deck/calendar/file links existed on any of the 3 source schemas, so there was nothing to re-point before deleting them.
  - **spec_ref**: `specs/unify-ticket-supertype/spec.md#scenario-attachments-and-threads-survive`
  - **files**: `lib/Repair/MigrateToTicketSupertype.php`
  - **acceptance_criteria**:
    - Every mail/deck/calendar/file link on an old object re-points to the new ticket UUID
    - Before/after link counts match; verification failure aborts with a clear message

- [x] 1.4 Verify parity
  - **spec_ref**: `specs/unify-ticket-supertype/spec.md#scenario-count-parity-after-migration`
  - **files**: `lib/Repair/MigrateToTicketSupertype.php`
  - **acceptance_criteria**:
    - `count(ticket) == count(request)+count(complaint)+count(contactmoment)`; summary emitted

## Phase 2 — UI + write cutover

- [x] 2.1 Collapse navigation + index to one Tickets workspace
  - **spec_ref**: `specs/unify-ticket-supertype/spec.md#requirement-unified-tickets-workspace`
  - **files**: `src/manifest.json`
  - **acceptance_criteria**:
    - One **Tickets** menu entry; no separate Complaints / Contactmomenten entry
    - `type:index` over `schema: ticket` with a `ticketType` facet + per-type saved views

- [x] 2.2 Type-aware detail page (was ticked but NOT implemented — the page had no type-aware config at all and rendered the whole union, so every complaint showed a wall of em dashes: Cti Extension —, Recording URL —, Disposition Notes —. Now real, via `config.hideEmpty`.)
  - **spec_ref**: `specs/unify-ticket-supertype/spec.md#scenario-type-specific-field-relevance`
  - **files**: `src/manifest.json`
  - **acceptance_criteria**:
    - Request / complaint / contactmoment field blocks show/hide by `ticketType`
    - Implemented data-driven rather than by enumerating per-type field lists: nc-vue
      `hideEmpty` (CnObjectDataWidget, forwarded by CnDetailPage — nextcloud-vue #184 + #186,
      beta.197) drops valueless fields, so the page is type-aware from the object itself.
    - Non-destructive: the field being edited, a field with unsaved changes, and the full
      Edit form stay visible, so an empty field is still reachable to fill in.
    - Live-verified: complaint 31 em dashes -> 0 (telephony fields gone, Complaint Category
      kept); contactmoment shows Duration/Occurred At/Outcome and hides Complaint Category.

- [x] 2.3 Cut contactmomenten reporting over to tickets (ReportingService::fetchContactmomenten was a STUB returning []; implemented against ticket+ticketType=contactmoment — endpoint now returns real KPIs)
  - **spec_ref**: `specs/unify-ticket-supertype/spec.md#scenario-contactmomenten-reporting-reads-tickets`
  - **files**: `lib/Controller/ReportingController.php`, `src/components/rapportage/*`, `src/manifest.json`
  - **acceptance_criteria**:
    - KPIs + channel distribution filter `ticket` by `ticketType=contactmoment`

- [x] 2.4 Rewire create AND read surfaces to `ticket` (25 PHP + 16 Vue + manifest.json, all via the shared TicketService; full unit suite green 1514/1514)
  - **spec_ref**: `specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets`
  - **files**: CTI adapter, omnichannel registration form/controller, SLA/klacht flow, ZGW bridge, notification writers
  - **acceptance_criteria**:
    - Each surface writes `ticket` with the correct `ticketType`; old-slug writes removed

## Phase 3 — Retire (separate deploy, after production soak)

- [x] 3.1 Deprecate + remove the old schemas and config keys
  - **spec_ref**: `specs/unify-ticket-supertype/spec.md#requirement-lossless-migration-of-existing-records`
  - **files**: `lib/Settings/pipelinq_register.json`, `lib/Settings/register.d/*`, `lib/Service/SettingsService.php`, `lib/Service/SchemaMapService.php`, `lib/Service/SettingsLoadService.php`, `appinfo/info.xml`, `src/manifest.json`, `src/manifest.d/85-kcc-werkplek.json`, `lib/Portal/PortalContributionProvider.php`
  - **acceptance_criteria**:
    - Done as a clean-slate retirement (no production instance existed, so no soak was needed)
    - 3 schemas removed from the register (30 -> 27) and deleted from OpenRegister; their 79 objects deleted after migration
    - Fragment overlays retargeted onto `ticket`: contactsUid (15), slaStatus (56, two identical overlays collapsed into one), zgwResourceMappings (80), CTI telephony (70)
    - Retention preserved: contactmoment's x-openregister-archival reconciled onto `ticket` with ordered first-match rules (see design.md)
    - Config keys dropped (SCHEMA_SLUGS / SchemaMapService / SettingsService); MigrateToTicketSupertype repair step retired
    - 7 legacy manifest pages deleted (65 -> 58) and all navigation repointed at Tickets / TicketDetail
    - Also removed a pre-existing dead `RemoveRetiredAvgJobs` repair-step reference that errored on every upgrade

## Phase 4 — Defects found while verifying (the change was NOT green when it claimed to be)

- [x] 4.1 `ticket.title` was declared `translatable: true` — migrated tickets were UN-EDITABLE
  - **spec_ref**: `specs/unify-ticket-supertype/spec.md#requirement-lossless-migration-of-existing-records`
  - **files**: `lib/Settings/register.d/99-unify-ticket-supertype.json`, `lib/Repair/NormaliseTicketTitle.php`, `appinfo/info.xml`
  - **acceptance_criteria**:
    - The flag appears nowhere in the design or spec and was the only translatable property on
      the schema; all three source schemas (`request.title`, `complaint.title`,
      `contactmoment.subject`) stored plain strings. So this was a slip, not a decision.
    - OpenRegister wrapped every migrated title into a locale map (`{"nl": "..."}`). With the
      flag on, OR resolved it back on read, which MASKED the corruption — only the detail
      header leaked raw JSON. Any save then failed: `Property 'title' should be type 'string'
      but is 'object'` (HTTP 400). Every migrated ticket was un-editable.
    - Flag removed; `Repair\NormaliseTicketTitle` unwraps `{nl: X}` -> `X`, idempotent.
    - Live-verified: all 79 ticket titles are plain strings; editing a ticket returns 200.
    - The repair must bypass RBAC (`_rbac:false`) AND act as an admin (`currentUser`): a repair
      step has no session, so OR fail-closes on `update` and denies folder access. Reading also
      needs `_rbac:false` or findAll returns only the handful of objects 'Anonymous' may read.

- [x] 4.2 `sanitizeForSave()` seam on TicketService (OpenRegister read-modify-write artefact)
  - **spec_ref**: `specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets`
  - **files**: `lib/Service/TicketService.php`
  - **acceptance_criteria**:
    - OR hands `format: date-time` back as `Y-m-d H:i:s`, which fails its own `date-time` format
      on the way in. Every ticket write funnels through one seam that re-emits ISO-8601.
    - Only reshapes values that already parse as an instant, so genuinely invalid input still
      fails validation instead of being silently masked.
    - The underlying platform bug is fixed at source in openregister (PR #345): MagicSearchHandler
      — the path findAll() actually uses — never applied the date-time normalisation its sibling
      MagicStatisticsHandler already did. It was NOT pipelinq-specific: `lead.stageEnteredAt`
      (a schema this change never touched) 400'd identically.
