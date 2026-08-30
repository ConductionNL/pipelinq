## 1. Actor-registry bridge schema

- [x] 1.1 Add the `vngActor` schema (config, no service) to `lib/Settings/register.d/82-vng-klantinteracties.json` — properties `actorUuid`, `actorType` (medewerker/geautomatiseerdeActor/organisatorischeEenheid), `nextcloudUserId`, `naam`, `actief`; register it under `components.registers.pipelinq.schemas`
- [x] 1.2 Add `vngActor` seed objects covering all three actor types (per design.md Seed Data)

## 2. OpenConnector binding config

- [x] 2.1 Add the `vngKlantinteractieBinding` schema + `binding-default` seed to the fragment, referencing the OpenConnector adapter slugs — **as-built slugs, not the frozen-design ones verbatim; see "Slug corrections" below.**
- [x] 2.2 Verify every referenced slug matches the frozen slugs in the `vng-klantinteracties-adapter` design.md Seed Data (no drift) — **DRIFT FOUND AND CORRECTED.** The adapter's real `configuration/vng-klantinteracties.oas.json` (read against HEAD of the merged `vng-klantinteracties-adapter` PR #134) does not contain `vng-klantcontacten` or `vng-partijen` as single Endpoint slugs; per that change's own documented Deviations ("`rule.type`/`rule.method` are single strings, not comma-lists"), the adapter ships one Endpoint object per HTTP method. The binding schema/seed were built against the REAL slugs (as-built wins):
  - `vng-klantcontacten` → split into `vng-klantcontacten-list` / `-create` / `-update` / `-patch`
  - `vng-partijen` → split into `vng-partijen-list` / `-create`
  - Added `betrokkenenListEndpoint`/`betrokkenenCreateEndpoint` (`vng-betrokkenen-list`/`-create`) and `digitaleadressenListEndpoint`/`digitaleadressenCreateEndpoint` (`vng-digitaleadressen-list`/`-create`) — packaged by the adapter but absent from this leaf's original design.md binding table.
  - `vng-maak-klantcontact` — matches design verbatim.
  - `vng-avg-bsn-policy` — matches design verbatim (inbound/before-timing); added `avgBsnPolicyOutboundGuardRule` = `vng-avg-bsn-policy-outbound-guard` (after-timing) since the adapter split the AVG mechanic into two Rule objects (its `rule.timing` property is single-valued, per that change's Deviations note).
  - `vng-referentienummer` — matches design verbatim.
  - Added `maakKlantcontactCompositeRule` = `vng-maak-klantcontact-composite` and `selfUrlHalRule` = `vng-selfurl-hal` — real adapter Rules not named in this leaf's original design.

## 3. Mapping contract + AVG policy documentation-as-config

- [x] 3.1 Confirm the VNG ↔ canonical mapping contract uses only real pipelinq field names (`ticket`/`client`/`contact`/`task`) and adds no VNG-shaped field to any canonical schema — verified against the real `lib/Settings/pipelinq_register.json` + `register.d/99-unify-ticket-supertype.json` at HEAD: `ticket.title/description/channel/occurredAt/caseReference/parentTicket/assignee/ticketType`, `client.type`, `contact.email/phone/geheimhouding/verifiedBSN/brpPersoonId`, `task.type/callbackPhoneNumber` all exist exactly as named; no VNG-named property was added to any canonical schema.
- [x] 3.2 Confirm the AVG BSN policy (11-proef + `brpPersoon.bsnHash`, no raw BSN stored or reconstructed) is reflected in the mapping so `contact.verifiedBSN`/`contact.brpPersoonId` are the only BSN-derived fields written — confirmed; the `vngKlantinteractieBinding.avgBsnPolicyRule` + `avgBsnPolicyOutboundGuardRule` fields document, without re-implementing, the adapter-side enforcement (`AvgBsnPolicyRule::apply()`).

## 4. Cross-spec references

- [x] 4.1 Add an OpenSpec-changes note to `omnichannel-registratie`, `contactmomenten`, and `brp-lookup` pointing at this change — **already present** (landed with the change's `spec.md` ff PR #385, merged to `development` before this build session started); verified all three notes exist and correctly point at `../../changes/vng-klantinteracties-leaf/` with an accurate one-line summary.

## Verification
- [x] All tasks checked off
- [x] `82-vng-klantinteracties.json` is valid JSON (`python3 -m json.tool`) and the fragment merges without discarding backend delta — verified with a real `ConfigFileLoaderService::loadConfigurationFile()` run against the checked-out app tree (temporary PHPUnit harness, removed after the run): `vngActor` + `vngKlantinteractieBinding` present, both registered under `components.registers.pipelinq.schemas`, pre-existing schemas (`client`, `ticket`, `zgwEndpoint`, 50+ others) and their seed objects all survive.
- [x] Seeds import on a clean install and `vngActor` resolves to a Nextcloud user id — the merge run above confirms all 3 `vngActor` seeds + the 1 `vngKlantinteractieBinding` seed import with populated `@self.slug`; `nextcloudUserId` values (`annelies`, `pipelinq`, `team-vth`) resolve per design.md's Seed Data table (`annelies`/`pipelinq` are real seeded NC users elsewhere in this environment's fixtures; `team-vth` models an organisatorischeEenheid, which VNG's `actorType` enum requires be representable even though it has no single NC user — documented as intentional, matching D4's rationale).
- [x] `openspec validate vng-klantinteracties-leaf --type change --strict` passes ("Change 'vng-klantinteracties-leaf' is valid").

## Tests (company-wide ADR-009)
- JSON-schema validity of the fragment; seed import smoke test
- Functional mapping verification is covered by the OpenConnector adapter's Newman/PHPUnit tests (cross-repo)
- Browser tests: N/A — declarative config, no UI surface

## Documentation (company-wide ADR-010)
- Document the VNG ↔ canonical mapping contract and the AVG raw-BSN deviation in `docs/`

## i18n (company-wide hydra ADR-007)
- N/A — no new user-facing strings (fragment is declarative config; VNG field names are contract-fixed)
