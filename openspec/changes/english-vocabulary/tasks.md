# Tasks — english-vocabulary (pipelinq)

Scan: **22 schemas / 59 Dutch properties**, **10 files / 9 classes / 19 methods**.
Three of five register fragments are adapter surfaces (Berichtenbox, ZGW bridge, VNG
Klantinteracties); the loyalty programme is entirely pipelinq's own.

## 1. Classify the wire boundary

- [ ] 1.1 Classify each `brpPersoon` property (`geslachtsnaam`, `adellijkeTitel`,
      `geboortedatum`, `bronsysteem`) against the Haal Centraal BRP Personen Bevragen
      contract: stored-as-received is wire and stays, computed is ours and renames.
- [ ] 1.2 Confirm with the Portaliq message-box decision whether the `berichtenbox*`
      schemas are about to move apps. If they are, task 4 belongs to Portaliq's change.

## 2. Rename the loyalty domain (app-local, lowest risk — do this first)

- [ ] 2.1 `klant` → `customer` throughout: `klantId` → `customerId` on
      `klantLoyaltyAccount`, `pointsLedgerEntry`, `redemption`, `giftCard`; schema
      `klantLoyaltyAccount` → `customerLoyaltyAccount`; `maxPerKlantPerPeriode`/
      `maxPerKlantPeriode`/`perKlantLimiet` → `maxPerCustomerPerPeriod`/
      `maxPerCustomerPeriod`/`perCustomerLimit`. Coordinate the word with shillinq, which
      carries `klantId` too.
- [ ] 2.2 Validity boundaries → the fleet pair: `startdatum`/`einddatum`, `geldigVan`/
      `geldigTot`, `ingangsdatum`/`einddatum` on `optOutVlag`, `tierGeldigTot`.
      `uitgegevenOp`/`vervaltOp` on `giftCard` are **events** → `issuedOn`/`expiresOn`.
- [ ] 2.3 Remaining loyalty properties: `naam` → `name`, `beschrijving` → `description`,
      `aantal` → `quantity`, `bedrag` → `amount`, `drempelWaarde` → `threshold`,
      `kostenInPunten` → `costInPoints`, `beloningWaarde` → `rewardValue`,
      `brondocument` → `sourceDocument`, `regelId` → `ruleId`, `uitgegevenAan` →
      `issuedTo`, schema `optOutVlag` → `optOutFlag`.

## 3. Rename the BSN/BRP identifiers — carefully

- [ ] 3.1 `bsnValidatie` → `nationalIdentityNumberValidation`, `bsnAuditRecord` →
      `nationalIdentityNumberAuditRecord`, `brpLookupVerzoek` → `brpLookupRequest`,
      `brpPersoon` → `brpPerson`, `contact.brpPersoonId` → `brpPersonId`,
      `isFormeelGeldig` → `isFormallyValid`. `Brp` and `Bsn`'s international expansion
      follow the fleet abstraction dictionary.
- [ ] 3.2 ⚠️🔥 `doelbinding` → `purposeLimitation`. **Assert before and after** that its
      required-ness is unchanged, that it still appears in the audit record, and that no
      change widens who can read it. This field is the lawful-basis ground for a BSN
      lookup; a rename that quietly makes it optional is a privacy defect, not a refactor.
- [ ] 3.3 Rename only the `brpPersoon` properties task 1.1 classified as ours.

## 4. Rename the adapter surfaces, preserving values

- [ ] 4.1 `vngKlantinteractieBinding`'s eight endpoint properties → English names
      (`customerContactsListEndpoint`, `involvedPartiesListEndpoint`,
      `createCustomerContactEndpoint`, …). **Leave every URL value byte-identical** —
      `klantcontacten` and `betrokkenen` are VNG API path segments.
- [ ] 4.2 `zgwEndpoint.naam`/`gemeenteCode` → `name`/`municipalityCode`;
      `nrcAbonnement.kanalen[].naam` is the ZGW Notificaties wire field and **stays**;
      `laatstOntvangenOp` → `lastReceivedOn`; `vngActor.naam` → `name`.
- [ ] 4.3 Keep `Berichtenbox` in every identifier that names the product; rename the rest.

## 5. Hold the cross-app keys

- [ ] 5.1 Do **not** rename `berichtenboxMessage.zaakId` or `berichtenboxTemplate.zaaktype`.
      Record as blocked on procest — pipelinq is the fourth app holding this key, alongside
      openconnector and docudesk.

## 6. Code, translations, gates

- [ ] 6.1 Rename the 9 classes and 19 methods that sit outside the adapter directories;
      keep `Berichtenbox`, `Klantinteracties`, `HaalCentraal`, `Zrc` and `Digikoppeling`
      where they name the external thing being adapted. `BrpDoelbindingModal.vue` follows
      3.2's rename.
- [ ] 6.2 `l10n/nl.json` re-pointed not re-extracted; `check-l10n`.
- [ ] 6.3 Re-run the token-aware scan; residual Dutch SHALL be exactly the classified wire
      fields plus the held `zaakId`/`zaaktype`.
- [ ] 6.4 Full suite plus hydra gates 46/53/54/55/57/61, and exercise one BSN lookup end
      to end to confirm the purpose-limitation ground is still enforced and audited.

## Acceptance criteria

- Every VNG endpoint URL byte-identical; only the properties holding them renamed.
- `purposeLimitation` provably as constrained and as audited as `doelbinding` was.
- `zaakId` and `zaaktype` unchanged, with the procest block recorded.
- `brpPersoon` classification recorded per property, not assumed.
- Dutch UI labels unchanged; `check-l10n` passes.
