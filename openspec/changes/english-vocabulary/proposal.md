# English vocabulary for pipelinq — Berichtenbox / BRP / klant domain

> Implements `hydra/openspec/changes/fleet-english-vocabulary`.

## Why

Scan found **6 Dutch-named schemas and 31 Dutch property names**. pipelinq is
the app where the adapter rule (§1) and the internationalisation rule (§2) meet:
most of its Dutch names describe **Logius/VNG products** it integrates with.

## What changes

### Product names stay, confined to the adapter (§1)

`Berichtenbox` is a Logius/MijnOverheid **product name**, not a Dutch word for
"message box". Per the existing decision recorded in
`feedback_english-code-and-portaliq-owns-message-box`, it stays only inside the
adapter — and the abstraction above it belongs to **Portaliq**, not pipelinq.

⚠️ This change must not entrench the abstraction in pipelinq. `berichtenboxMessage`,
`berichtenboxReply`, `berichtenboxTemplate` become English message-box schemas
**owned by Portaliq**, with pipelinq consuming them and keeping only a
`Berichtenbox` *adapter*. That is an architectural move and needs an ADR before
the rename — see risks.

### Internationalised (§2)

| Dutch | English |
|---|---|
| `klant*` | `customer*` (`klantId` → `customerId`, `klantLoyaltyAccount` → `CustomerLoyaltyAccount`) |
| `verzoek` | `request` (`brpLookupVerzoek` → `PersonLookupRequest`, `lookupVerzoekId` → `lookupRequestId`) |
| `balans` | `balance` (`balansNa` → `balanceAfter`, `currentBalans` → `currentBalance`) |
| `geldigVan` / `geldigTot` | `validFrom` / `validUntil` |
| `beschrijving` | `description` · `naam` → `name` · `bedrag` → `amount` |
| `gemeenteCode` | `municipalityCode` |
| `maxPerKlantPerPeriode` | `maxPerCustomerPerPeriod` |
| `maakKlantcontact*` | `createCustomerContact*` |

### Wire formats stay (§1)

`vngKlantinteractieBinding` and the `klantcontacten*Endpoint` values name **VNG
API endpoints**. The endpoint *values* stay; the property *names* holding them
become English (`customerContactCreateEndpoint`, …).

### Dutch → l10n (§3), code too (§5)

## Tasks

- [ ] Inventory per schema and per lib/+src/ file — real counts.
- [ ] **ADR first**: does the message-box abstraction move to Portaliq as part
      of this, or does pipelinq rename in place and move later? Renaming in
      place makes the later move harder.
- [ ] Rename the `klant`/`verzoek`/`balans` families.
- [ ] Keep `Berichtenbox` only as the adapter name; English everywhere above it.
- [ ] Rename classes/methods/files; update DI and register-fragment class refs.
- [ ] Diff every filter/read key against the new schema.
- [ ] `l10n/nl.json` + `check-l10n`.
- [ ] Full suite + hydra gates.

## Risks

- Renaming in place could entrench an abstraction that architecturally belongs
  to Portaliq — the ADR gates this change.
- BRP/VNG payload keys are external; renaming a *read* breaks the integration.
