## 1. Actor-registry bridge schema

- [ ] 1.1 Add the `vngActor` schema (config, no service) to `lib/Settings/register.d/82-vng-klantinteracties.json` — properties `actorUuid`, `actorType` (medewerker/geautomatiseerdeActor/organisatorischeEenheid), `nextcloudUserId`, `naam`, `actief`; register it under `components.registers.pipelinq.schemas`
- [ ] 1.2 Add `vngActor` seed objects covering all three actor types (per design.md Seed Data)

## 2. OpenConnector binding config

- [ ] 2.1 Add the `vngKlantinteractieBinding` schema + `binding-default` seed to the fragment, referencing the OpenConnector adapter slugs verbatim (`vng-klantcontacten`, `vng-partijen`, `vng-maak-klantcontact`, `vng-avg-bsn-policy`, `vng-referentienummer`)
- [ ] 2.2 Verify every referenced slug matches the frozen slugs in the `vng-klantinteracties-adapter` design.md Seed Data (no drift)

## 3. Mapping contract + AVG policy documentation-as-config

- [ ] 3.1 Confirm the VNG ↔ canonical mapping contract uses only real pipelinq field names (`ticket`/`client`/`contact`/`task`) and adds no VNG-shaped field to any canonical schema
- [ ] 3.2 Confirm the AVG BSN policy (11-proef + `brpPersoon.bsnHash`, no raw BSN stored or reconstructed) is reflected in the mapping so `contact.verifiedBSN`/`contact.brpPersoonId` are the only BSN-derived fields written

## 4. Cross-spec references

- [ ] 4.1 Add an OpenSpec-changes note to `omnichannel-registratie`, `contactmomenten`, and `brp-lookup` pointing at this change

## Verification
- All tasks checked off
- `openspec validate vng-klantinteracties-leaf --type change --strict` passes
- `82-vng-klantinteracties.json` is valid JSON and the fragment merges without discarding backend delta
- Seeds import on a clean install and `vngActor` resolves to a Nextcloud user id

## Tests (company-wide ADR-009)
- JSON-schema validity of the fragment; seed import smoke test
- Functional mapping verification is covered by the OpenConnector adapter's Newman/PHPUnit tests (cross-repo)
- Browser tests: N/A — declarative config, no UI surface

## Documentation (company-wide ADR-010)
- Document the VNG ↔ canonical mapping contract and the AVG raw-BSN deviation in `docs/`

## i18n (company-wide hydra ADR-007)
- N/A — no new user-facing strings (fragment is declarative config; VNG field names are contract-fixed)
