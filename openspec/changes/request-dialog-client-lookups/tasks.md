# Tasks: request-dialog-client-lookups

- [ ] RequestForm: Klant as a search field with the result list (kind, address, open tickets, "Bekend")
- [ ] BRP pill: lookup dialog on POST /api/brp/lookup; select or create a resident client; offered only to brp.allowed_groups
- [ ] GET /api/kvk/search wrapping KvkApiClient (login, rate limit); KVK pill selects or creates an organisation client
- [ ] Tonal pills as the board draws them
- [ ] PHPUnit for the KVK search endpoint and its auth; vitest for the dialog
