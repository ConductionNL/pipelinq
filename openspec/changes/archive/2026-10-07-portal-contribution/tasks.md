# Tasks: portal-contribution

Tracking issue: Conduction/pipelinq#343 (Wave 1 — provider + tests only; bespoke-portal retirement is a later phase, do not close the issue from this change).

- [x] T1: Verify the full scoping map against the register JSONs at HEAD (`pipelinq_register.json` + `register.d/*.json`) — schemas, scoping properties, UUID-ness, internal-field exclusions.
  - `request.client` / `complaint.client` / `contract.clientRef` are uuid refs; `avgVerzoek.verzoekerContact` is a contact UUID ref; `klantLoyaltyAccount.klantId` is an NC contact UID
  - contactmoment / booking / berichtenboxMessage exclusions documented in design.md with schema-quoted reasons

- [x] T2: Add `lib/Portal/PortalContributionProvider.php` — plain, dependency-free (no portaliq import, no implements, no constructor deps), repo-style EUPL-1.2/SPDX docblock, `@spec` tags.
  - Class is at the convention FQCN `OCA\Pipelinq\Portal\PortalContributionProvider`
  - No `use` of any portaliq symbol anywhere in the file

- [x] T3: Implement `getAudiences(): array` returning `['client', 'customer']` and the v1 fallback `getAudience(): string` returning `'client'`.

- [x] T4: Implement `getContribution(array $subject): ?array` — branch on `$subject['audience']` only; return `null` for anything else (fail-closed, no endpoint actions anywhere).

- [x] T5: Client-audience manifest: collections `request`/`complaint` (scopeField `client`) + `contract` (scopeField `clientRef`), all `scopeClaim: "clientId"`, register `pipelinq`, listable; actions `createRequest` + `createComplaint` with fields `title`, `description`, `category` only.

- [x] T6: Customer-audience manifest: collections `avgVerzoek` (scopeField `verzoekerContact`, `scopeClaim: "contactId"`, `minTrust: "substantial"`) + `klantLoyaltyAccount` (scopeField `klantId`, `scopeClaim: "customerUid"`); action `createAvgVerzoek` with fields `artikel`, `specifiekeVraag`, `scope` only.

- [x] T7: Add `tests/Unit/Portal/PortalContributionProviderTest.php` — direct construction, nil-UUID subjects (design.md Seed Data), pinning audiences, per-audience manifest shape, scoping map, whitelists, forbidden internal fields, null for unknown/missing audience, create-only actions.

- [x] T8: Create `openspec/specs/portal-contribution/spec.md` with `status: in-progress` and run `openspec validate portal-contribution` until valid.

- [x] T9: Run the gate suite the CI way (docker php:8.3-cli): `composer lint`, `composer phpcs`, `composer phpmd`, `composer psalm`, `composer phpstan`, and the unit suite (`vendor/bin/phpunit -c phpunit-unit.xml`); fix violations in the files this change touches (max 3 cycles, report honestly).
  - Existing unit tests stay green; no baseline files edited to pass

- [x] T10: Commit on `feat/portal-contribution` (conventional message, no Co-Authored-By); do not push, do not open a PR.
