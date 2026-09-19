# Tasks: contact-moments-on-pipelinq-schema

## Phase 0: Schema

- [x] 0.1 Add `direction` to the contact moment facet in `lib/Settings/register.d/98-contactmoment-direction.json` with the per-type required guard (V1)
  - **spec_ref**: `specs/contactmomenten/spec.md#requirement-direction-is-a-first-class-field-req-cmd-001`
  - **files**: `lib/Settings/register.d/98-contactmoment-direction.json`
- [x] 0.2 Widen `caseReference` to an ADR-048 semantic reference and drop the `procest` app id from the schema (V1)
  - **spec_ref**: `specs/contactmomenten/spec.md#requirement-a-contact-moment-references-a-case-semantically-req-cmd-002`
  - **files**: `lib/Settings/register.d/99-unify-ticket-supertype.json`

## Phase 1: Migration

- [x] 1.1 Add `Repair\MigrateContactMomentDirection`, idempotent, mapping both spellings; register in `appinfo/info.xml` (V1)
  - **spec_ref**: `specs/contactmomenten/spec.md#requirement-direction-is-a-first-class-field-req-cmd-001`
  - **files**: `lib/Repair/MigrateContactMomentDirection.php`, `appinfo/info.xml`

## Phase 2: Leaves

- [x] 2.1 Add `lib/Integration/ContactMomentLeafProvider.php` with `list` and `create` through `TicketService`; register on `RegisterLeafProvidersEvent` (V1)
  - **spec_ref**: `specs/contactmomenten/spec.md#requirement-contact-moments-are-a-data-provider-leaf-with-append-req-cmd-003`
- [x] 2.2 Register `pipelinq-contact-moments-panel` on both halves with the filtered list, the widget and the quick-log form with `direction` (V1)
  - **spec_ref**: `specs/contactmomenten/spec.md#requirement-a-panel-renders-the-contact-moments-on-the-host-req-cmd-004`
- [x] 2.3 Add the Direction facet to the contactmomenten list view (V1)

## Phase 3: Quality

- [x] 3.1 PHPUnit: validation, migration idempotence, resolver stub, provider refusal
- [x] 3.2 Playwright `tests/e2e/contact-moments-leaf.spec.ts`; Dutch and English strings; docs with screenshots
- [x] 3.3 Hand the leaf ids and the `create` payload to dossiq for its Communication tab and the retirement of `customerContact` and the `kcc-werkplek` copy
