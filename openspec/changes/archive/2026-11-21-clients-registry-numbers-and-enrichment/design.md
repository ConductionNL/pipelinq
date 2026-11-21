# Design: clients-registry-numbers-and-enrichment

## Context (read at pipelinq development cfe0a0a51, OpenRegister development 555af7212, shillinq development)

- **Client schema.** `lib/Settings/pipelinq_register.json` schema `client` has
  `name, type, email, phone, address, website, industry, notes, contactsUid`.
  Fragments add identity mirrors (`15-unify-client-contact.json`), channels
  (`16-contact-channel-details.json`), typed party fields `fieldValues`,
  `parentOrganisation`, `organisationPath` (`17-party-fields-and-indicators.json`),
  `correspondenceLanguage` (`18-`), `masterEntityRef` (`90-`) and
  `shillinqOrganisationRef` (`91-`). No registry number.
- **KvK today.** `lib/Service/KvkApiClient.php` searches by SBI code for
  prospect discovery, first through OpenRegister's KvK leaf
  (`fetchViaOpenRegister`, `GET /apps/openregister/api/integrations/kvk/search`,
  raw KvK rows) and else directly (`DEFAULT_API_BASE https://api.kvk.nl/api/v1`,
  `/zoeken`). `lib/Service/KvkResultMapper.php:41` maps `kvkNummer`, trade name,
  address and SBI code and description.
- **VAT today.** pipelinq has none. shillinq keeps the one VIES path,
  `lib/Service/ViesService.php::validate()` (shillinq change
  `tax-vat-number-check`, design D1 "ViesService::validate() stays the only
  path"), storing validity with `validUntil`.
- **Bulk jobs.** OpenRegister lets an app add bulk actions:
  `lib/Event/BulkActionRegistrationEvent.php::registerAction()` takes a
  `BulkActionInterface` (`getId`, `getLabel`, `validateParameters`,
  `apply(ObjectEntity, parameters, commit, actor)`); a
  `ReversibleBulkActionInterface` action records prior values so
  `BulkJobReversal` can undo it.

## Decisions

### D1. One `identifiers` list, not one property per number

`client.identifiers` is an array of `{scheme, value, checkStatus, checkedAt,
checkSource, validUntil}` with `scheme` in `kvk, rsin, vat, duns, oin`.
A list keeps the schema stable when a register is added and lets one company
hold several VAT numbers (one per EU country). `checkStatus` is one of
`unchecked, valid, invalid, unavailable`. The fragment declares the property
in a new `lib/Settings/register.d/19-client-identifiers.json`.

### D2. Format on save, register on request

Format rules run on every save: KvK 8 digits, RSIN 9 digits with the eleven
test, VAT a two-letter EU prefix and the country's pattern, DUNS 9 digits, OIN
20 digits. A pattern that JSON schema can express is declared on the schema;
the eleven test runs in `ClientIdentifierService::validateFormat()`. A register
check runs only when a user presses Check or when enrichment runs, and writes
`checkStatus`, `checkedAt` and `checkSource`.

### D3. KvK through the OpenRegister leaf, VAT through shillinq

`ClientIdentifierService::checkKvk()` calls the OpenRegister KvK leaf with the
KvK number (the same call shape as `KvkApiClient::fetchViaOpenRegister`), and
falls back to the direct KvK API only where `KvkApiClient` already does.
`checkVat()` resolves shillinq's `ViesService` lazily from the container, the
way pipelinq resolves hermiq in `RelevanceScorer`; without shillinq the status
stays `unchecked` with source `shillinq not installed`. pipelinq writes no VIES
client of its own (ADR-012, one path per external register).

### D4. Enrichment is an OpenRegister bulk action

`FillFromKvkAction` (id `pipelinq:fill-from-kvk`) implements
`ReversibleBulkActionInterface`. For each selected organisation with a `kvk`
identifier it looks the number up, maps the result through `KvkResultMapper`
and writes only fields that are empty: `address`, `industry` (from the SBI
description) and `name` never (a client's own name wins). A client without a
KvK number is skipped with reason "no KvK number". Registering it on
`BulkActionRegistrationEvent` gives it OpenRegister's preview, ceilings, audit
and undo, and makes it appear on the Bulk changes page of
`clients-bulk-edit-and-undo` with no extra code.

### D5. Where the user sees it

- ClientDetail gets an Identifiers section (manifest body widget) listing
  scheme, value, status and checked date, with Add and Check actions.
- The Clients list gets a second bulk action, Fill in from KvK, opening the
  same preview dialog as Change a field with the action id swapped.

## Declarative versus imperative (ADR-031)

| Behaviour | Path | Why |
|---|---|---|
| Identifier list and patterns | Declarative, schema | Data |
| Eleven test | Imperative, `ClientIdentifierService` | Not expressible as a pattern |
| KvK and VAT checks | Imperative, external registers (ADR-031 exception) | External lookups |
| Enrichment | OpenRegister bulk action | Reuses preview and undo |

## Risks

- The KvK leaf may be unconfigured (`503` with `details.cause`). The check
  writes `unavailable` and the enrichment skips the member with that reason,
  rather than failing the whole job.
- Old clients may hold a KvK number in `notes` or a custom field. Moving those
  is not automated; the proposal names it and a follow-up can add a repair step.
