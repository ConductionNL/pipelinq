# Tasks: request-dialog-client-lookups

- [x] RequestForm: Klant as a search field with the result list (initials, kind, address) on CnSearchSelect, the DqNieuweZaak "Aanvrager" field
- [ ] Each row also names the open tickets and marks a known client "Bekend"
- [ ] BRP pill: lookup dialog on POST /api/brp/lookup; select or create a resident client; offered only to brp.allowed_groups
- [ ] GET /api/kvk/search wrapping KvkApiClient (login, rate limit); KVK pill selects or creates an organisation client
  - Built (server half): `GET /api/kvk/search` → `KvkSearchController::search()` wraps `KvkApiClient` (`companyByNumber` for an 8-digit query, else `searchByName`), behind the same `CrmPrivilegeCheck` login as the enrichment endpoints; the KVK source's own rate limit applies downstream. The KVK pill (frontend) is not built here.
- [ ] Tonal pills as the board draws them
- [ ] PHPUnit for the KVK search endpoint and its auth; vitest for the dialog
  - Built (PHPUnit): `tests/Unit/Controller/KvkSearchControllerTest.php` (refused without the CRM capability, empty query 400, name vs 8-digit-number routing) plus a `CrmPrivilegeRefusalGolden/KvkSearchController.json` entry. The dialog vitest is not built here.
