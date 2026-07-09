# Docs & Product Pages Conformance — Claims Alignment Delta

**Spec refs**: 2026-07-05 claims audit (all findings verified at HEAD), ADR-045/ADR-022 (OR-owned capabilities), `semantic-handoff-emit` (bridge implementation owner), Nextcloud `resources/app-info.xsd` (licence enum)
**Standards**: truth-in-labelling for store listings and feature pages

## ADDED Requirements

### Requirement: Licence Claims Conformance

Every human-readable licence statement SHALL say EUPL-1.2, matching `LICENSE`: the README licence badge, and the English and Dutch descriptions in `appinfo/info.xml`. The machine `<licence>` element SHALL be `EUPL-1.2` — the SPDX token accepted by Nextcloud's app-info.xsd licence enum since the 2026-05-07 upstream addition (nextcloud/server PR #60212; also in the App Store's accepted-licenses fixtures) — per the product-owner decision of 2026-07-05. Only when the targeted Nextcloud version ships an app-info.xsd predating the EUPL enum value MAY the element fall back to the previous schema-valid value (`agpl`) annotated with an XML comment naming EUPL-1.2 as the canonical licence. The same flip is a fleet-wide follow-up (openregister and other Conduction apps declare `agpl` today) tracked outside this change.

**Feature tier**: MVP

#### Scenario: No AGPL prose remains

- WHEN README.md and appinfo/info.xml are searched for licence statements
- THEN the badge and both description texts MUST reference EUPL-1.2
- AND no prose MUST claim the project is AGPL-licensed

#### Scenario: info.xml stays schema-valid

- WHEN appinfo/info.xml is validated against a Nextcloud app-info.xsd carrying the EUPL enum value (upstream master, stable31+ branch heads, tagged releases from v33.0.5)
- THEN validation MUST pass with `<licence>EUPL-1.2</licence>`

### Requirement: Feature Claims Match Implementation

README.md and appinfo/info.xml SHALL contain no feature claim without implementing code at HEAD: Unified Search SHALL be attributed to OpenRegister (`lib/Search/ObjectsProvider.php` provides it centrally); the Request-to-Case Bridge SHALL be presented as roadmap pointing at the `semantic-handoff-emit` change until that change ships; duplicate detection SHALL be attributed to OpenRegister master-data management (the in-app engine was removed in PR#332); the CSV-import claim SHALL be removed (no CSV import exists — vCard claims remain, backed by code).

**Feature tier**: MVP

#### Scenario: Search claim attributes OR

- WHEN the README Unified Search entry is read
- THEN it MUST state the capability is provided via OpenRegister, not as pipelinq code

#### Scenario: Bridge claim is roadmap until shipped

- GIVEN `semantic-handoff-emit` has not shipped
- WHEN the Request-to-Case wording in README/info.xml is read
- THEN it MUST be marked as in development referencing `semantic-handoff-emit`, not presented as a working feature

#### Scenario: No unbacked import claim

- WHEN info.xml and README import/export wording is read
- THEN no bulk CSV-import support MUST be claimed
- AND vCard import/export claims MUST remain only where code backs them

### Requirement: Features Overlay Status Honesty

`openspec/features.overlay.json` SHALL report `omnichannel-registratie` as `beta` with a recorded reason: outbound WhatsApp/SMS send has no production callers and no UI surface, and SLA notification dispatch defers all channels except nextcloud-notification, while inbound webhooks are wired. The EN/NL summaries SHALL describe inbound registration and consent logging rather than promising outbound reach. (Alternative — wiring outbound send — is an owner decision outside this change; downgrade is the default.)

**Feature tier**: MVP

#### Scenario: Overlay reflects outbound reality

- WHEN `features.overlay.json` is read
- THEN the `omnichannel-registratie` entry MUST carry `status: beta` and a reason naming the unwired outbound path
- AND its summaries MUST NOT promise reaching clients via WhatsApp/SMS

### Requirement: docs/features.json Matches Shipped Reality

`docs/features.json` SHALL NOT describe shipped capabilities as unimplemented: the `kcc-werkplek` entry SHALL describe the shipped Customer Support workspace instead of "not yet implemented; no UI surface to test". Factual staleness found in adjacent entries during apply SHALL be corrected in the same batch.

**Feature tier**: MVP

#### Scenario: kcc-werkplek entry is current

- WHEN `docs/features.json` is read
- THEN the kcc-werkplek entry MUST describe the shipped surface and MUST NOT claim it is unimplemented
