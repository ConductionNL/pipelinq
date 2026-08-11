## Context

Token-aware scan: **22 schemas / 59 Dutch properties**, **10 files / 9 classes /
19 methods**. The Dutch splits cleanly along a line pipelinq already draws in its own
directory structure — `lib/Service/External/`, `lib/Service/Zgw/`, `lib/Sources/` hold
the adapters; everything else is the app.

Three of its five register fragments are adapter surfaces: `80-berichtenbox.json`
(MijnOverheid Berichtenbox), `80-zgw-api-bridge.json` (ZGW/Notificaties), and
`82-vng-klantinteracties.json` (VNG Klantinteracties API). The loyalty programme
(`70-loyalty-program.json`) is entirely pipelinq's own.

## Goals / Non-Goals

**Goals:**

- Rename the loyalty domain and the BRP/BSN bookkeeping properties that are ours.
- Preserve Berichtenbox, ZGW and VNG Klantinteracties vocabulary at the adapter boundary,
  including the endpoint URL values.
- Adopt the fleet's `customer` and validity-date words.

**Non-Goals:**

- Renaming `Berichtenbox`. It is MijnOverheid's product name — the ratified exemption for
  an external product's proper name.
- Touching the VNG Klantinteracties endpoint **URLs**, as distinct from the properties
  that hold them.
- The Portaliq message-box question. pipelinq's Berichtenbox surface may move to Portaliq
  under the message-box ownership decision; this change does not pre-empt that.

## Decisions

### 1. `klant` → `customer`, the ratified fleet word

pipelinq is the reason `klant` was ratified. It carries `klantId` on four schemas
(`klantLoyaltyAccount`, `pointsLedgerEntry`, `redemption`, `giftCard`), plus
`maxPerKlantPerPeriode`, `maxPerKlantPeriode`, `perKlantLimiet`, and the schema
`klantLoyaltyAccount` itself.

`klantId` → `customerId`, `klantLoyaltyAccount` → `customerLoyaltyAccount`,
`maxPerKlantPerPeriode`/`maxPerKlantPeriode` → `maxPerCustomerPerPeriod`/
`maxPerCustomerPeriod`, `perKlantLimiet` → `perCustomerLimit`.

⚠️ shillinq also carries `klantId`. It is a 2-app word, so the two apps coordinate
directly rather than through the fleet list — but they must still land the same English
word, and `customer` is it.

### 2. The VNG Klantinteracties binding renames its properties and keeps its URLs

`vngKlantinteractieBinding` holds eight properties whose **values are VNG API endpoint
URLs**: `klantcontactenListEndpoint`, `klantcontactenCreateEndpoint`,
`klantcontactenUpdateEndpoint`, `klantcontactenPatchEndpoint`, `betrokkenenListEndpoint`,
`betrokkenenCreateEndpoint`, `maakKlantcontactEndpoint`, `maakKlantcontactCompositeRule`.

**Decision:** rename the properties — `customerContactsListEndpoint`,
`involvedPartiesListEndpoint`, `createCustomerContactEndpoint` and so on — and **never
touch the URL values**. `klantcontacten` and `betrokkenen` are path segments in the VNG
API; a rename there is an integration outage.

This is the same key-versus-value split as openconnector's `decisionStatus` enum, and it
is worth stating twice because the property name and the string it holds look alike here.

### 3. BSN and BRP: rename the identifiers, do not widen the access

`bsnValidatie`, `bsnAuditRecord`, `brpLookupVerzoek`, `brpPersoon`, `contact.brpPersoonId`,
plus `doelbinding` on two schemas.

| Dutch | English |
|---|---|
| `bsnValidatie` | `nationalIdentityNumberValidation` |
| `bsnAuditRecord` | `nationalIdentityNumberAuditRecord` |
| `brpLookupVerzoek` | `brpLookupRequest` |
| `brpPersoon` | `brpPerson` |
| `doelbinding` | `purposeLimitation` |
| `isFormeelGeldig` | `isFormallyValid` |
| `geslachtsnaam`, `adellijkeTitel`, `geboortedatum`, `bronsysteem` | see below |

⚠️🔥 **`doelbinding` is not decoration.** It is the GDPR/BRP purpose-limitation ground
that authorises a BSN lookup at all, and it is audited. Renaming it must not change who
can read it, must not change its required-ness, and must not drop it from the audit
record. A property rename that quietly makes an access-control field optional is a
privacy defect wearing a refactor's clothes.

⚠️ `brpPersoon`'s properties (`geslachtsnaam`, `adellijkeTitel`, `geboortedatum`,
`bronsysteem`) mirror the **Haal Centraal BRP Personen Bevragen** response. The schema is
pipelinq's cache of a wire payload. Classify each against the Haal Centraal contract
before renaming: if the field is stored as received, it is wire.

### 4. `Berichtenbox` stays; the schemas around it are renamed

`berichtenboxMessage`, `berichtenboxReply`, `berichtenboxTemplate` keep the product name
and lose the rest: `berichtenboxMessage.zaakId` → `caseId` (held — see below),
`sentToBerichtenboxAt` is already English, `berichtenboxTemplate.zaaktype` → `caseType`
pending the same coordination.

### 5. `zaakId` is held for the procest window

`berichtenboxMessage.zaakId` and `berichtenboxTemplate.zaaktype` are foreign keys into
procest's domain. Same rule as openconnector and docudesk: procest renames first,
pipelinq follows in the same window. **Not in this change.**

This makes pipelinq the *fourth* app holding the key. The coordinated window is
procest + openconnector + docudesk + pipelinq.

## Risks / Trade-offs

- **A VNG endpoint URL is renamed along with its property** → integration outage,
  silently, because the adapter keeps running and gets 404s that look like empty results.
  Mitigated by decision 2's explicit key/value split.
- **`doelbinding` loses its required-ness or its audit coverage in the rename** → a BSN
  lookup becomes authorisable without a recorded purpose. Mitigated by asserting the
  field's constraints and audit presence before and after, not just its name.
- **`zaakId` renamed unilaterally** → four apps desynchronise instead of three. Mitigated
  by holding it.
- **A Haal Centraal field is renamed** → the cached payload stops matching the source.
  Mitigated by classifying `brpPersoon` field-by-field against the Haal Centraal contract.
- **Portaliq takes over the message box mid-change** → the Berichtenbox schemas move
  apps. Mitigated by keeping those renames minimal and separable.

## Migration Plan

1. Classify `brpPersoon`'s properties against the Haal Centraal contract.
2. Rename the loyalty domain (fully app-local, no coordination, lowest risk).
3. Rename the BSN/BRP identifiers, asserting `doelbinding`'s constraints and audit
   coverage are unchanged.
4. Rename the VNG binding properties; leave every URL value byte-identical.
5. Rename the 9 classes and 19 methods outside the adapter directories.
6. **Hold** `zaakId` and `zaaktype` for the procest window.

**Rollback:** steps 2–5 are app-local. Step 6, when it happens, is a four-app window.

## Open Questions

- Which `brpPersoon` properties are stored exactly as Haal Centraal returns them, and
  which does pipelinq compute? Unmeasured, and it decides the tier for four properties.
- Does the Portaliq message-box decision move the Berichtenbox schemas out of pipelinq
  before this change lands? If so, decision 4 belongs to Portaliq's change instead.
