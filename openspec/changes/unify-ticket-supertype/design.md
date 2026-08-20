# Design: unify-ticket-supertype

**Spec refs**: ADR-000 (data model), ADR-031 (x-openregister-notifications), ADR-062 (bespoke detail pages), `contactmomenten`, `klachtenregistratie`, `omnichannel-registratie`, `kcc-werkplek`, `my-work`, `sla-engine`, `cti-screenpop-adapter`, `zgw-api-bridge`
**Standards**: VNG Klantinteracties (`Contactmoment`, `Klant`, `Medewerker`), Schema.org (`Demand`, `Message`, `CommunicateAction`)

## The `ticket` schema

One schema, slug `ticket`, with a required `ticketType` discriminator. Fields are
the union of the three source schemas; a field is only *relevant* to some types,
enforced in the UI (per-type field visibility) rather than by hard schema
validation, so a single object shape serves all three.

| field | type | source(s) | relevant to |
|-------|------|-----------|-------------|
| `ticketType` | enum `request`\|`complaint`\|`contactmoment` | **new** (discriminator) | all |
| `title` | string (required) | request.title, complaint.title, contactmoment.**subject** | all |
| `description` | string | request/complaint.description, contactmoment.**summary** | all |
| `client` | uuid | all three `client` | all |
| `contact` | uuid | request/complaint `contact` | request, complaint |
| `assignee` | string | request.assignee, complaint.**assignedTo**, contactmoment.**agent** | all |
| `status` | enum (superset, below) | request.status, complaint.status | all |
| `priority` | enum `low\|normal\|high\|urgent` | request/complaint priority | request, complaint |
| `channel` | string | request.channel (free), complaint.channel (enum), contactmoment.channel (enum) | all |
| `occurredAt` | date-time | request.**requestedAt**, contactmoment.**contactedAt** | all |
| `parentTicket` | uuid (self-ref) | contactmoment.**request** | contactmoment |
| `category` | string | request.category | request |
| `pipeline` | uuid | request.pipeline | request |
| `stage` | string | request.stage | request |
| `stageOrder` | integer | request.stageOrder | request |
| `queue` | uuid | request.queue | request |
| `caseReference` | uuid | request.caseReference | request |
| `complaintCategory` | enum `service\|product\|communication\|billing\|other` | complaint.**category** | complaint |
| `slaDeadline` | date-time | complaint.slaDeadline | complaint |
| `resolvedAt` | date-time | complaint.resolvedAt | complaint |
| `resolution` | string | complaint.resolution | complaint |
| `outcome` | enum (contactmoment vocab) | contactmoment.outcome | contactmoment |
| `duration` | string | contactmoment.duration | contactmoment |
| `channelMetadata` | object | contactmoment.channelMetadata | contactmoment |
| `notes` | string | contactmoment.notes | contactmoment |

Notes:
- `complaint.category` becomes **`complaintCategory`** to avoid colliding with the
  free-string `request.category` (different value spaces). No data is lost.
- `contactmoment.subject`/`summary` fold onto the common `title`/`description` so
  every ticket has one headline field.
- Per-type Schema.org identity is preserved via an `x-schema-org-by-type` marker
  on the schema (request→Demand, complaint→Message, contactmoment→CommunicateAction)
  so downstream JSON-LD / VNG mappings stay correct.

### Status superset + mapping

Unified `status` enum: `new`, `in_progress`, `resolved`, `completed`, `rejected`,
`converted`, `closed`.

| source | source value | → unified |
|--------|--------------|-----------|
| request | new / in_progress / completed / rejected / converted | identical |
| complaint | new / in_progress / rejected | identical |
| complaint | resolved | `resolved` |
| contactmoment | *(no status field)* — derive from `outcome` | afgehandeld/opgelost → `resolved`; doorverbonden/doorverwezen → `closed`; terugbelverzoek/vervolgactie → `in_progress`; (default) → `new` |

The contactmoment `outcome` value is **also** retained verbatim in the `outcome`
field, so the derivation is additive and reversible.

### Lifecycle

The existing per-type `x-openregister-lifecycle` blocks (complaint resolution,
request stage transitions) merge into one lifecycle keyed on `status`, guarded by
`ticketType` in each transition's `match` so a request transition can't fire on a
contactmoment. Notifications (ADR-031 dialect) move onto `ticket` with the same
`match`-by-type guard.

## Migration (`Repair\MigrateToTicketSupertype`, idempotent)

Runs in Phase 1. Guarded by an app-config marker so it runs once and is
re-runnable safely (each source object records its produced ticket UUID; a second
run skips already-migrated objects).

1. **Dry-run + count.** Log counts of request/complaint/contactmoment objects and
   of mail/deck/calendar/file links pointing at them. Abort with a clear message
   if OpenRegister is unavailable.
2. **Copy.** For each source object create a `ticket` with `ticketType` set and
   fields mapped per the table above. Store `oldUuid → newTicketUuid` in a
   migration map (an app-config JSON blob keyed by source schema, plus the marker
   written into each new ticket's `@self` for idempotency).
3. **Remap intra-CRM references.**
   - `contactmoment.request` → `ticket.parentTicket` (look up the migrated request's
     new UUID).
   - `task.requestId` → new ticket UUID (task schema unchanged otherwise).
4. **Remap OpenRegister link tables** (highest-risk sub-step, separately verified):
   mail, deck, calendar, file links keyed on the old object UUID are re-pointed to
   the new ticket UUID via the OR link APIs. Counts before/after must match.
5. **Verify.** Assert `count(tickets) == count(request)+count(complaint)+count(contactmoment)`
   and that every remapped link resolves. Emit a summary; leave old objects intact.

Retirement of the old schemas (Phase 3) is a **separate** change-gate after the
migration is verified in production — never in the same deploy as the copy.

## UI cutover (Phase 2)

- **Navigation** (`src/manifest.json`): remove the `Requests` (label *Tickets*),
  `Complaints` and `Contactmomenten` menu entries; add one **Tickets** entry →
  a `type:index` page over `register: pipelinq, schema: ticket` with a
  `ticketType` facet (request / complaint / contactmoment) and a saved-view per
  type for continuity.
- **Detail** — one `type:detail` page (ADR-062 bespoke style) whose body widgets
  show/hide per `ticketType`: the request block (pipeline/stage/queue/case),
  complaint block (SLA/resolution/complaintCategory), contactmoment block
  (channel/outcome/duration/channelMetadata + parent-ticket link). Comms leaves
  (mail/calendar/files) unchanged.
- **Reporting** — `RapportageContactmomenten` + `ChannelDistributionSection` query
  `ticket` filtered to `ticketType=contactmoment`; the KPI endpoints in
  `ReportingController` filter by `ticketType` instead of schema.
- **Create surfaces** — CTI screen-pop and omnichannel registration create
  `ticket{ticketType:contactmoment}`; the SLA engine and klacht flow create
  `ticket{ticketType:complaint}`; the ZGW bridge maps onto `ticket`.

## Alternatives considered

- **Keep three schemas, present one inbox (union view).** Rejected by the product
  owner in favour of a true single object — the union view leaves the triplicated
  data model and reporting in place.
- **Discriminator via three sub-schemas / composition.** OpenRegister supports
  schema composition, but a flat discriminator is simpler to query, facet and
  report on, and matches how the SQL/RBAC layers already filter by a field.

## Retention: the one place the merge forces a real trade-off (RESOLVED)

The retired `contactmoment` schema carried an `x-openregister-archival` block (a
VNG/AVG retention policy: default `P2Y`, plus outcome rules). `request` and
`complaint` carried **none**. A merged schema can hold only ONE archival block,
and OpenRegister's retention condition DSL evaluates a **single comparison** per
rule (no `AND`), so `ticketType == 'contactmoment' && outcome == '…'` is not
expressible. `retention.default` is also mandatory — and a naive `default: P2Y`
on `ticket` would start auto-disposing requests and complaints, which previously
were never disposed.

Resolved using the fact that rules are **ordered and first-match-wins**, and that
an absent field never matches:

```
rules:
  ticketType == 'request'      -> P100Y   # exempt: preserves "no retention"
  ticketType == 'complaint'    -> P100Y   # exempt: preserves "no retention"
  outcome    == 'afgehandeld'  -> P2Y     # contactmoment (only it has `outcome`)
  outcome    == 'doorverbonden'-> P1Y     # contactmoment
default: P2Y                              # contactmoment fallback (as before)
```

This reproduces the pre-merge semantics exactly for all three subtypes.

**One unavoidable behaviour change:** OpenRegister's `SCHEMA_ARCHIVAL_IMMUTABLE`
guard is *schema-level* — any schema declaring archival refuses user-driven
deletes (rows expire via `ArchivalRetentionTask`). So request- and
complaint-type tickets can no longer be hard-deleted by hand, where previously
they could. That is a defensible posture for an auditable client-interaction
record, but it is a change, and it is the one thing to revisit with the DPO if
hand-deletion of requests must be retained.

## Open questions

- Should request/complaint tickets acquire a *real* retention term (they are
  currently exempted at `P100Y`)? That is a DPO decision, not a technical one.
- Whether historical `request` case references held by external ZGW systems must
  keep resolving at the old slug (a redirect/alias) or can be cut over hard.
