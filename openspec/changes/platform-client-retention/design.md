# Design: platform-client-retention

## Context (read at pipelinq development cfe0a0a51, OpenRegister development 555af7212)

- **What expires today.** `lib/Settings/pipelinq_register.json:1522` (task) and
  `lib/Settings/register.d/99-unify-ticket-supertype.json:27` (ticket) declare
  `x-openregister-archival`. The matrix cites :1516 for the task block; it sits
  at :1522 at this sha. `lib/BackgroundJob/BrpRetentionJob.php:189` deletes BRP
  person rows past `retentionTo`. The `client` schema
  (`pipelinq_register.json:59`, extended in
  `register.d/15-unify-client-contact.json:4`) and the `contact` schema
  (`pipelinq_register.json:164`) declare nothing about retention.
- **What the client carries.** `accountStatus` (`active`, `inactive`,
  `blocked`; `15-unify-client-contact.json:86`) is "Lifecycle status of the CRM
  relationship/account record". `lifecycleStage` (:60) is a sales stage. No
  property records when the relationship ended. A contact person points at its
  client through `client` (`pipelinq_register.json`, contact properties,
  `$ref: client`).
- **OpenRegister has two retention paths, and the annotation path does not fit
  a client.**
  - `x-openregister-archival` is swept hourly by
    `lib/BackgroundJob/ArchivalRetentionTask.php`, which counts from the row's
    `_created` (:357-381) and deletes without review. A schema that declares it
    refuses every hand delete with 403 `SCHEMA_ARCHIVAL_IMMUTABLE`
    (`Schema::hasArchivalAnnotation()`, `lib/Db/Schema.php:3783`). On a client
    both are wrong: an active client created three years ago would be deleted,
    and no user could delete a duplicate client.
  - The schema's `archive` configuration (`Schema::getArchive()`, a JSON column,
    `lib/Db/Schema.php:498`) feeds `RetentionService::applyArchivalMetadata()`
    (`lib/Service/RetentionService.php:147`), called on create at
    `lib/Service/Object/SaveObject.php:3916`. It writes `archiefnominatie`,
    `bewaartermijn` and `archiefactiedatum`, where the start date follows
    `afleidingswijze` (`lib/Service/Archival/ArchiveActionDateCalculator.php`).
    `DestructionCheckJob` puts records past their date on a destruction list,
    a person approves it (`appinfo/routes.php:2005` and `:2016-2017`), and
    `DestructionExecutionJob` destroys, skipping legal holds. Hand deletes stay
    allowed.
- **Where the start date comes from.** `ander_datumkenmerk` reads a named date
  property (`sourceDateProperty`) and refuses to fall back to the creation date
  when it is empty (`REFUSE_WITHOUT_BRONDATUM`,
  `ArchiveActionDateCalculator.php:87`). The five relation methods read a date
  off a record this one points at (`sourceRelation`, `sourceRelationProperty`,
  :268-269).
- **The date is recalculated only for two methods.**
  `RetentionService::recalculateArchiveActionDate()` (`:318-336`) looks for a
  changed source property only under `eigenschap` and `afgehandeld`/`termijn`.
  Under `ander_datumkenmerk` a date set after creation is never picked up, and
  a cleared date never clears the destruction date.
- **Anonymise is not an outcome yet.** `Appraisal::ANONYMISE`
  (`lib/Service/Archival/Appraisal.php:80`) exists as a nomination, but
  `AnonymisationSweep` has no caller, and the OpenRegister change
  `anonymising-as-an-archival-outcome` has no task ticked. Its planner reads the
  profile from `x-openregister-archival.anonymisation`
  (`AnonymisationPlanner.php:53`), and the annotation validator refuses that
  block without `retention` (`ArchivalAnnotationValidator.php:166`).
- **Lifecycle actions.** OpenRegister runs a transition's `actions[]` on every
  save path (`lib/Listener/LifecycleActionListener.php:144`), and `set-fields`
  resolves `@now` (`lib/Lifecycle/Action/SetFieldsAction.php`).
- **No review screen in OpenRegister.** `git grep -i destruction` over
  OpenRegister `src/` finds nothing; the lists are reachable only by API
  (`archival#listDestructionLists`, `appinfo/routes.php:2014`).

## Decisions

### D1. The `archive` path, not the annotation

Client and contact declare an `archive` block, not `x-openregister-archival`.
The annotation sweep counts from creation, destroys without review and blocks
hand deletes. The `archive` path starts from a date the schema names, puts the
record on a reviewed list, and leaves hand deletes alone. This is still
ADR-031: the term is declared on the schema, and OpenRegister runs it
(ADR-022).

### D2. The relationship end is a stamped date

The client gets `relationshipEndedAt` (date-time, read only in forms). A
lifecycle on `accountStatus` declares transitions between its three values;
the transition to `inactive` runs `set-fields` with `relationshipEndedAt:
"@now"`, and the transition back to `active` sets it to null. The client's
`archive` block reads it: `afleidingswijze: ander_datumkenmerk`,
`sourceDateProperty: relationshipEndedAt`, `defaultBewaartermijn: P2Y`,
`defaultNominatie: vernietigen`. With no date, OpenRegister computes no
destruction date, so an active client is never listed.

### D3. OpenRegister must recalculate under `ander_datumkenmerk`

A client becomes inactive long after it was created, so its destruction date is
set on an update, not on create. OpenRegister's recalculation skips this method
today (Context). pipelinq depends on an OpenRegister change that recalculates,
and clears, the destruction date when the source date changes under
`ander_datumkenmerk` and under the relation methods. Until it lands, task 1.3's
test fails and the PR says so.

### D4. A contact person follows its client

The contact's `archive` block uses a relation method with `sourceRelation:
client` and `sourceRelationProperty: relationshipEndedAt`, so a contact person
leaves with the organisation it belongs to. A contact with no client has no
date and is never listed. This needs D3's recalculation to reach a contact when
its client changes, which the same OpenRegister change must cover.

### D5. Retention review is a pipelinq page on OpenRegister's API

A custom page RetentionReview (`/retention-review`, settings section) reads
`GET /apps/openregister/api/archival/destruction-lists`, keeps the entries whose
schema is `client` or `contact`, and offers Approve and Reject on each list
through the matching `archival#` routes. It writes nothing itself. If
OpenRegister ships its own review screen, this page becomes a link to it.

### D6. The anonymisation profile waits for OpenRegister

Each schema will declare which properties are personal data: name, emails,
phones, address, website and notes on the client; name, emails, phones, BSN
and BRP id on the contact. OpenRegister reads that profile only from
`x-openregister-archival`, which D1 rules out. So pipelinq asks OpenRegister to
read the profile beside the `archive` block too, and declares it once that
lands. Task 4 is gated on it.

### D7. Existing records are back-filled once

`applyArchivalMetadata()` runs on create only. A repair step
`BackfillClientRetention` resolves OpenRegister's `RetentionService` lazily and
applies the metadata to every client and contact that has none. It is
idempotent, because the service refuses to overwrite a nomination already
present (`RetentionService.php:157-160`).

## Risks

- A destroyed client leaves tickets and leads that point at it. OpenRegister's
  row `ret-linked-destroy-conflict` (specified, not built) would warn about
  that; until then the review page shows how many tickets and leads reference
  each client on the list.
- Two years is a default, not advice. The DPO sets the real term; the PR asks
  for sign-off the way the ticket and task blocks did.
- A client that is `blocked` keeps no date. Blocking is not the end of a
  relationship; if the DPO disagrees, one more transition action covers it.
