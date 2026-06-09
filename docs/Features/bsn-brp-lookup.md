# BSN-validatie + BRP-lookup (HaalCentraal Personen)

Pipelinq integreert met **HaalCentraal Personen v2.0** (RvIG) zodat behandelaars
het Burgerservicenummer (BSN) van een contact kunnen valideren én — onder een
expliciet vastgelegde **doelbinding** — persoonsgegevens kunnen ophalen uit het
BRP. Elke bevraging wordt vastgelegd in een onveranderlijke audit-trail die 5
jaar bewaard blijft (RvIG-richtlijn).

## Voor medewerkers

### BSN-validatie (11-proef)
Op het contact-detailscherm verschijnt een BSN-veld. De 11-proef draait
client-side en geeft direct visuele feedback: groen vinkje = geldig, rode tekst
= ongeldig. De "Ophalen uit BRP"-knop blijft uitgegrijsd zolang het BSN niet
voldoet aan de 11-proef.

### BRP-lookup
Klik op "Ophalen uit BRP". Het systeem opent een verplichte modal:
- **Verzoekreden** — bijv. "Behandeling AVG-inzageverzoek art. 15", "VOG-screening".
- **Doelbinding / wettelijke grondslag** — bijv. "Publieke taak — Wet BRP art. 3.3".
- **Aanvullende toelichting** — optioneel, minstens 20 tekens aanbevolen.
- **VOG-screening** — vink dit aan om een extra Justis-vlag in het audit-record te zetten.

Na bevestiging krijgt de medewerker de persoonsgegevens van het BRP. Een
"⚡ van cache"-badge verschijnt wanneer de response uit de 24-uurs response-cache
komt. Het BSN zelf staat **nooit** in de URL, het scherm, de logs of het
audit-record — alleen de SHA-256 hash en de gemaskeerde variant `***45678*`.

### Geheimhouding
Als BRP een geheimhouding-vlag teruggeeft (`indicatieGeheim=1`), verschijnt
een rood slot-icoon en wordt het adres standaard verborgen. Met "Toon adres
onder verantwoording" wordt het adres alsnog getoond — dit schrijft een extra
audit-record (`actie: brp-adres-onthuld`).

## Voor admins

### Configureren van HaalCentraal
Onder *Beheerinstellingen → Pipelinq* zijn de BRP-instellingen te configureren
via de REST-API `/api/brp/settings`:
- OAuth2 endpoint, client ID en client secret (versleuteld via NC ICrypto).
- Pad naar mTLS-certificaat, key en CA-bundle (PEM, op het host-filesystem).
- Cache TTL (default 24h) en retentie-termijn (default 7 dagen).
- Lijst toegestane groepen (default `behandelaar-burgerzaken`, `behandelaar-avg`).

### BRP Monitor
Onder *Beheer → BRP Monitor* (route `/admin/brp-monitor`) staan dagelijkse
KPI's: aantal lookups, cache-hit ratio, foutpercentage en gemiddelde
responstijd. Daarnaast de mTLS-certificaat-status met een aftellende klok:
- 🟢 OK
- 🟡 verloopt binnen 30 dagen
- 🔴 verloopt binnen 7 dagen (admin krijgt automatisch een NC-notificatie)

### Webhook voor BRP-mutaties
HaalCentraal kan via `POST /api/brp/mutations` cache-invalidaties pushen. De
webhook-handler verifieert een HMAC-SHA256-signature (header `X-Signature`).
Het webhook-secret rouleer je via `POST /api/brp/settings/webhook-secret` —
het secret wordt **éénmalig** in de response getoond en daarna alleen als
`webhookSecretSet: true` weergegeven.

## Compliance

| Standaard | Hoe wordt het afgedekt |
|---|---|
| **AVG art. 5** (dataminimalisatie, opslagbeperking) | BrpPersoon-records worden automatisch verwijderd na de configureerbare retentie-termijn (`BrpRetentionJob`). |
| **AVG art. 6 lid 1 sub e** (publieke taak) | Doelbinding-modal verplicht een wettelijke grondslag voordat de lookup start. |
| **AVG art. 17** (recht op vergetelheid) | `BsnAuditService::pseudonymise()` herrekent de BSN-hash met een HMAC-secret, audit-keten blijft intact. |
| **AVG art. 30** (verwerkingsregister) | Elke bevraging schrijft een `BsnAuditRecord` (immutable schema). |
| **Wet BRP art. 3.3** | Doelbinding wordt vastgelegd in het LookupVerzoek en in elk AuditRecord. |
| **BSN-masking** | Raw BSN gaat NOOIT in logs/URLs/cookies/responses. Alleen `***XXXX*` of SHA-256-hash. |
| **HaalCentraal API v2.0 / RvIG-aansluitvoorwaarden** | OAuth2 client_credentials + mutual TLS (PKIoverheid). |

## Architectuur

```
Contact-detail → BSN input → 11-proef (client-side)
                      ↓
                BrpDoelbindingModal (verplichte doelbinding)
                      ↓
POST /api/brp/lookup → BrpController
   ├─ BsnValidationService.validate (server-side defense)
   ├─ BrpCacheService.get → cache-hit pad
   ├─ HaalCentraalClient.lookupPersoon → OAuth2 + mTLS
   ├─ OptOutService.recordFromBrpResponse (indicatieGeheim)
   ├─ Contact stamp (verifiedBSN, brpPersoonId, geheimhouding)
   └─ BsnAuditService.recordLookup (altijd, ook bij errors)

Webhook: POST /api/brp/mutations → BrpMutationWebhookListener
   ├─ hash_equals(HMAC-SHA256, X-Signature)
   ├─ BrpCacheService.invalidate(bsn)
   └─ BsnAuditService.recordLookup (actie: brp-cache-invalidated)

Cron:
- BrpHealthCheckJob (1×/dag): cert-expiry → admin-notificatie wanneer < 30d
- BrpMonitorJob (1×/dag): aggreatie van 24h audit-records voor BRP Monitor
- BrpRetentionJob (1×/dag): verwijdert BrpPersoon waar retentieTot < now
```

## OpenSpec

Volledige specs en acceptance criteria staan in
`openspec/changes/bsn-validatie-en-brp-lookup/`:
- `proposal.md` — probleemstelling + scope + success criteria.
- `design.md` — schema-definities, service-API's, frontend-layout, seed data.
- `specs.md` — 10 REQ-BSN-* requirements met scenarios.
- `tasks.md` — implementatie-checklist (66 sub-tasks, alle ✓).

## Security review highlights

- **BSN nooit in plaintext persisted**: schemas gebruiken `bsnHash` (SHA-256);
  raw BSN bestaat alleen in de geheugenscope van een enkele request.
- **`hash_equals` voor HMAC**: webhook-signature-vergelijking is timing-attack-safe.
- **mTLS afgedwongen door de RvIG endpoint**: client-cert is verplicht per
  HaalCentraal-aansluitvoorwaarden.
- **Geen BSN in URL**: alle endpoints accepteren BSN in de request-body (POST);
  routes refereren alleen aan opaque UUID's voor `gekoppeldContact` /
  `gekoppeldVerzoek`.
- **Permissions**: `BrpController` weigert met HTTP 403 + audit-record
  `geweigerd-onbevoegd` als de actor niet in een toegestane groep zit (default
  `behandelaar-burgerzaken`/`behandelaar-avg`).
- **Cache poisoning resistant**: cache-keys zijn `bsnHash`, niet vrije strings;
  invalidatie loopt uitsluitend via de HMAC-gevalideerde webhook.

## Performance baseline

| Operatie | Doel | Geleverd door |
|---|---|---|
| Client-side 11-proef | < 100 ms | Pure JS, geen netwerk |
| Cache-hit lookup | < 10 ms | OR query op `bsnHash` (geïndexeerd) |
| Cache-miss lookup (incl. HaalCentraal) | < 2 s | 5 s timeout met 2 s connect-timeout |
| Audit-record write | < 50 ms | Synchroon, async upgrade mogelijk |
