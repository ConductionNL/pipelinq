# Tasks: clients-registry-numbers-and-enrichment

## 1. Schema

- [ ] 1.1 Add `lib/Settings/register.d/19-client-identifiers.json` giving `client` an `identifiers` array (`scheme`, `value`, `checkStatus`, `checkedAt`, `checkSource`, `validUntil`) with the scheme enum and the per-scheme patterns JSON schema can hold
  - Verify: `composer check:strict` exit 0; a register import on a dev instance logs no `PARTIAL IMPORT`; an OpenRegister save with KvK `1234` is refused
- [ ] 1.2 English schema titles in `l10n/` (`check:schema-l10n`)
  - Verify: `npm run check:schema-l10n` exit 0

## 2. Checks

- [ ] 2.1 `lib/Service/ClientIdentifierService.php::validateFormat()` with the RSIN eleven test and the EU VAT country patterns
  - Verify: PHPUnit `tests/Unit/Service/ClientIdentifierServiceTest.php` with valid and invalid numbers per scheme
- [ ] 2.2 `checkKvk()` through the OpenRegister KvK leaf, writing `checkStatus`, `checkedAt`, `checkSource`; `unavailable` on a 503
  - Verify: PHPUnit with a mocked HTTP client for 200, 404 and 503
- [ ] 2.3 `checkVat()` resolving shillinq `ViesService` lazily; `unchecked` with source `shillinq not installed` without it
  - Verify: PHPUnit with the container returning the service and returning nothing
- [ ] 2.4 Route `POST /api/clients/{id}/identifiers/check` with `#[NoAdminRequired]` and an object authorisation check on the client
  - Verify: hydra gates route-auth, no-admin-idor and route-reachability pass on the diff

## 3. ClientDetail

- [ ] 3.1 Identifiers section on ClientDetail (manifest body widget) with Add and Check
  - Verify: Playwright `tests/e2e/client-identifiers.spec.ts` adds a KvK number and sees status valid after Check against a mocked leaf

## 4. Enrichment

- [ ] 4.1 `lib/BulkAction/FillFromKvkAction.php` implementing `ReversibleBulkActionInterface`, empty fields only, skip reasons "no KvK number" and "KvK unavailable"; register it on `BulkActionRegistrationEvent` in `lib/AppInfo/Application.php`
  - Verify: PHPUnit on `apply()` with commit false and true, and on the prior values it records
- [ ] 4.2 Clients list bulk action Fill in from KvK, using the preview dialog of `clients-bulk-edit-and-undo`
  - Verify: Playwright fills the address of two clients, then undoes it from Bulk changes

## 5. Docs

- [ ] 5.1 `docs/Features/client-identifiers.md`: the schemes, what Check does, what enrichment fills and what it never overwrites
  - Verify: docs build exit 0
