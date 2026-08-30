# Proposal: bsn-validatie-en-brp-lookup

## Problem

Nederlandse overheidsorganisaties kunnen burgerverzoeken zonder gevalideerd Burgerservicenummer (BSN) en authentieke persoonsgegevens niet rechtmatig behandelen. Dit leidt tot inefficiëntie, compliance-risico's en het risico op ongeautoriseerde access tot persoonlijke data.

Current gaps:

1. **Geen client-side BSN-validatie** — Typefouten in BSN worden pas laat ontdekt, verspillen resources bij het aanmaken van verzoeken.

2. **Geen REST-integratie met HaalCentraal** — De oudere StUF-BG SOAP-API is afgekeurd. Gemeenten hebben geen moderne manier om BRP-gegevens op te halen.

3. **Geen audit-trail voor BSN-bewegingen** — Als toezichthouders (Autoriteit Persoonsgegevens, RvIG) controleren, kan niet aangetoond worden dat elke lookup een geldige doelbinding had.

4. **Geen cache-strategie** — Elke lookup doet een nieuwe HaalCentraal-call, wat latency oplevert en onnodige belasting veroorzaakt.

5. **Geen geheimhouding-respect** — Burgers met opt-out of geheimheim-indicatie in BRP worden niet herkend; hun adresgegevens kunnen ongewenst getoond worden.

6. **Geen retentiebeheer** — BRP-gegevens worden onbeperkt opgeslagen, in strijd met AVG article 5 (opslagbeperking).

## Solution

Voeg twee gekoppelde capabilities toe aan Pipelinq:

1. **BSN-validatie via 11-proef** — Lokale, deterministische client-side controle op basis van het RvIG-algoritme. Voorkomt typefouten zonder externe call.

2. **HaalCentraal Personen REST-client** — OAuth2 client-credentials + mutual TLS. Levert genormaliseerde Persoon-objecten op met:
   - **Doelbinding vereist** — Elke lookup vereist geregistreerde verzoekreden en wettelijke grondslag (AVG art. 6).
   - **Response-cache met TTL** — Maximaal 24 uur, daarna opnieuw bevragen. Audit-record wordt voor elke lookup aangemaakt.
   - **Onveranderlijk auditlogboek** — BsnAuditRecord leidraad voor inspectie door toezichthouders; 5 jaar bewaard per RvIG-richtlijn.
   - **Geheimhouding-respect** — OptOutVlag wordt herkend en Contact wordt afgeschermd; adresgegevens alleen via expliciete "toon onder verantwoording"-actie.
   - **Configureerbare retentie** — Persoonsgegevens worden na ingestelde periode (standaard 7 dagen na afhandeling verzoek) verwijderd; audit blijft behouden.

3. **OpenConnector integratie** — HaalCentraal-client als pluggable Source in OpenConnector, zodat API-wijzigingen niet doorbreken naar app-code.

4. **VOG-markering** — Voor Verklaring Omtrent Gedrag-requests automatisch extra audit-vlag voor Justis-controle.

## Scope

### Data Schema (OpenRegister)
- `BsnValidatie` — resultaat van 11-proef
- `BrpLookupVerzoek` — lookup-request met doelbinding (persistent voor audit)
- `BrpPersoon` — genormaliseerde response uit HaalCentraal (versleuteld at-rest, met TTL)
- `BsnAuditRecord` — onveranderlijke audit-regel (immutable, 5 jaar bewaard)
- `OptOutVlag` — per BSN bekende geheimhouding / opt-out

Extended `Contact` schema:
- `verifiedBSN` (boolean) — `true` na succesvolle lookup, `false` na retentie-expiratie
- `brpPersoonId` (reference) — wijst naar laatste geldige Persoon-record
- `geheimhouding` (boolean) — afgeleid uit OptOutVlag voor UI-rendering

### Backend
- `lib/Service/BsnValidationService.php` — 11-proef implementatie
- `lib/Service/HaalCentraalClient.php` — OAuth2 mTLS REST-client
- `lib/Service/BsnAuditService.php` — onveranderlijk audit-logboek
- `lib/Service/BrpCacheService.php` — response-cache met TTL + webhook-invalidatie
- `lib/Service/OptOutService.php` — geheimhouding-controle
- `lib/Listener/BrpMutationWebhookListener.php` — extern webhook voor cache-clear bij wijzigingen
- Admin settings: HaalCentraal OAuth keys, mTLS certificaat, retentie-instellingen, health-check timezone

### Frontend
- Contact detail view: BSN-invoer met inline 11-proef validatie, "Ophalen uit BRP"-knop
- Modal voor doelbinding + verzoekreden (met vooringevulde opties voor AVG-workflow)
- BRP-lookup status: spinner, success, foutmelding, cache-indicator
- Persoon-detailscherm: geheimhoudings-icoon, adresgegevens (verborgen bij opt-out)
- Timeline-event "brp-lookup-uitgevoerd" zonder BSN in tekst
- Admin dashboard: BRP-monitor tegel met dagelijkse stats (hits, misses, fouten), certificaat-vervaldatum

### Cross-App Integration
- **OpenRegister**: BsnAuditRecord is `immutable: true`, retentie-velden gebruikt door OpenRegister-retentie-job
- **Pipelinq Contact-management**: Contact krijgt `verifiedBSN`, `brpPersoonId`, `geheimhouding` velden
- **OpenConnector**: HaalCentraal-client als Source type `oauth2-mtls-haalcentraal` (ADR-019)
- **AVG-verzoeken-workflow**: art. 15-inzage en art. 17-vergetelheid triggeren automatisch lookup met vooringevulde doelbinding
- **DocuDesk**: export-template beschermt geheimhoudings-velden
- **SIEM**: BsnAuditRecord-stream via webhook naar centrale audit-bewaring

### Seed Data
- 3 voorbeeldbronnen van BrnpLookupVerzoek (geslaagd, niet-gevonden, geheimhouding)
- 2 voorbeelden van BsnAuditRecord (succesvolle lookup, gefaalde lookup)
- 1 voorbeeld van OptOutVlag (geheimhouding gemeente)
- 1 voorbeeld van BrpPersoon met adresgegevens (genormaliseerd)

**Depends on**: Contact management, OpenRegister, OpenConnector, AVG-verzoeken-workflow, DocuDesk (sibling)

## Out of Scope

- Reverse-sync van HaalCentraal-wijzigingen via mutatiefeed (apart capability)
- Bulk-import van BRP-gegevens voor bulkverzoeken
- Multi-tenant geheimhouding-afscherming via OpenRegister views
- BSN-masking in HTML-output (geleverd door DocuDesk)
- Justis-integratie voor VOG-screening-verwerking (integratie config in OpenConnector)
- Dashboard-widget voor dagelijkse BRP-monitor-rapportage (onderdeel van launchpad-integratie)
- SMS/WhatsApp notificatie voor certificaat-verlopen (admin email via Nextcloud Notifications)

## Success Criteria

- Een medewerker kan BSN invoeren in Contact-formulier; client-side 11-proef geeft direct feedback (groen vinkje of rode fout)
- BSN < 9 cijfers of niet-numeriek wordt onmiddellijk geweigerd zonder externe call
- "Ophalen uit BRP"-knop is disabled totdat BSN formeel geldig is
- Medewerker moet doelbinding selecteren voordat BRP-lookup kan starten; lookup wordt geweigerd zonder doelbinding
- Succesvolle BRP-lookup haalt Persoon-object op, stelt Contact.verifiedBSN op true, vult Contact.brpPersoonId in
- Response wordt gecacht; volgende lookup binnen 24h toont cache-indicator maar maakt toch audit-record aan
- Cache verloopt na 24h; volgende lookup doet opnieuw HaalCentraal-call
- Extern webhook van HaalCentraal kan cache-invalidatie triggeren; volgende lookup verplicht vernieuwd
- Persoon met indicatie geheim in BRP-response krijgt rood geheimhoudings-icoon; adresgegevens verborgen
- Contact met lokale opt-out krijgt blocking-waarschuwing bij elke uitgaande communicatie
- Lookup zonder doelbinding (REST API direct aanroep) wordt geweigerd met HTTP 403
- BsnAuditRecord wordt aangemaakt voor elke lookup; actor, doelbinding, HaalCentraal-correlation-id zijn zichtbaar
- Admin kan certificaat-vervaldatum zien; waarschuwing verschijnt wanneer vervaldatum < 30 dagen
- Contact.brpPersoonId wordt automatisch null gesteld 7 dagen (configurable) na verzoek-afsluiting; Contact.verifiedBSN wordt false
- BsnAuditRecord blijft behouden (gepseudonimiseerd bij AVG art. 17 verwijdering)
- `npm run build` produceert nul fouten na alle wijzigingen

## Standards

- **Wet BRP** — artikel 1.7, 3.3, 2.57 (geheimhouding) — bevoegdheidsraamwerk voor BSN-bevraging
- **Wabb** (Wet algemene bepalingen burgerservicenummer) — sectorale beperking van BSN-gebruik
- **AVG / GDPR** — art. 5 (dataminimalisatie, doelbinding, opslagbeperking), art. 6 (rechtmatigheidsgronden), art. 15-22 (rechten), art. 30 (verwerkingsregister)
- **HaalCentraal Personen API v2.0** — RvIG-API, OpenAPI-spec
- **RvIG aansluitvoorwaarden** — PKIoverheid mTLS, OAuth2 client-credentials
- **NORA principes** — herbruikbaarheid, transparantie, controleerbaarheid
- **NEN 7510** — informatiebeveiliging zorg (relevant voor zorg-tenants)
- **BIO** (Baseline Informatiebeveiliging Overheid) — logging, monitoring, access control
- **Forum Standaardisatie** — pas-toe-of-leg-uit: DigiD, OAuth, REST/JSON, TLS 1.3, PKIoverheid
- **Logius PKIoverheid** — mTLS clientcertificaten
- **11-proef BSN-algoritme** — RvIG gepubliceerd, deterministisch
- **OWASP ASVS 4.0.3** — V8 (Data Protection), V10 (Communications)
- **ADR-001** — international-first, Dutch API mapping layer (schema.org → Klantinteracties)
- **ADR-019** — Pluggable Integration Registry (OpenConnector Source types)
