---
status: draft
---
# BSN-validatie + BRP-lookup via HaalCentraal

## Purpose

Nederlandse overheidsorganisaties die burgerverzoeken in Pipelinq behandelen werken structureel met het Burgerservicenummer (BSN) als persoonsidentificatie. Het BSN is wettelijk geregeld (Wet algemene bepalingen burgerservicenummer) en mag uitsluitend gebruikt worden door bestuursorganen en aangewezen sectoren (zorg, onderwijs, sociale zekerheid). Voor gemeenten, waterschappen en uitvoeringsorganisaties is BSN-validatie en BRP-lookup een fundamentele bouwsteen: zonder geverifieerd BSN kan geen authentieke persoonsgegevens worden opgevraagd, geen besluit per beschikking worden uitgegeven, en geen AVG-verzoek rechtmatig worden afgehandeld.

Deze capability levert twee gekoppelde functies:

1. **BSN-validatie via de 11-proef**: lokale, deterministische controle of een opgegeven 9-cijferige string een geldig BSN-format heeft. Dit voorkomt typefouten en obvious testdata zonder externe call.
2. **BRP-lookup via HaalCentraal Personen API (RvIG)**: REST-client voor de bevraging van authentieke persoonsgegevens uit de Basisregistratie Personen, vervanger van het verouderde StUF-BG SOAP-protocol. De client ondersteunt OAuth2 client credentials met mutual TLS en levert genormaliseerde Persoon-objecten terug die direct gekoppeld worden aan de Pipelinq Contact-entiteit.

De capability is strikt opgezet rond AVG-principes (Article 5 GDPR — dataminimalisatie, doelbinding, opslagbeperking). Elke lookup vereist een geregistreerde verzoekreden en doelbinding, wordt geaudit in een onveranderlijk logboek, en de response wordt maximaal 24 uur gecached. Daarna moet opnieuw worden bevraagd of de gegevens worden volgens retentiebeleid uit het Contact verwijderd. Burgers met een opt-out (geheimhouding gemeente of indicatie geheim BRP) worden expliciet herkend en de response wordt afgeschermd conform Wet BRP artikel 2.57.

De capability is daarmee niet alleen een technische integratie, maar een gecontroleerde poort: alle BSN-bewegingen in Pipelinq lopen hierdoorheen en zijn daarmee aantoonbaar herleidbaar voor toezichthouders (Autoriteit Persoonsgegevens, RvIG-audit, Functionaris voor Gegevensbescherming).

## Data Model

### BsnValidatie
Resultaat van een 11-proef check. Wordt gelogd maar niet persistent bewaard tenzij gekoppeld aan een lookup-poging.

```json
{
  "id": "bsn-val-2026-08-14-a7c3",
  "ingevoerdBsn": "123456782",
  "isFormeelGeldig": true,
  "elfproefScore": 88,
  "validatieTijdstip": "2026-08-14T09:23:11+02:00",
  "geinitieerdDoor": "medewerker:m.devries@gemeente-zeist.nl",
  "context": "contact-aanmaken",
  "verzoekId": "verzoek-2026-08-14-1043"
}
```

### BrpLookupVerzoek
Het verzoek zelf, met doelbinding en grondslag. Persistent voor audit trail.

```json
{
  "id": "brp-lookup-2026-08-14-9bd1",
  "bsn": "123456782",
  "verzoekreden": "Behandeling AVG-inzageverzoek artikel 15",
  "doelbinding": "uitvoering wettelijke taak — Wet BRP art. 3.3 lid 1",
  "grondslag": "publieke taak (AVG art. 6 lid 1 sub e)",
  "aangevraagdDoor": "medewerker:m.devries@gemeente-zeist.nl",
  "aangevraagdNamens": "afdeling:Burgerzaken",
  "verzoekTijdstip": "2026-08-14T09:23:45+02:00",
  "gekoppeldVerzoek": "verzoek-2026-08-14-1043",
  "gekoppeldContact": "contact-mvr-2026-002",
  "responseStatus": "geslaagd",
  "responseTijdstip": "2026-08-14T09:23:46+02:00",
  "responseDuurMs": 412,
  "haalcentraalCorrelationId": "hc-corr-7f3a9b2e",
  "responseBevatGeheimhouding": false,
  "responseInCache": true,
  "cacheVerlooptOp": "2026-08-15T09:23:46+02:00"
}
```

### BrpPersoon
Genormaliseerde response uit HaalCentraal, gekoppeld aan een Contact. Versleuteld at-rest.

```json
{
  "id": "brp-persoon-2026-08-14-9bd1",
  "bsn": "123456782",
  "voornamen": "Maria Wilhelmina",
  "voorletters": "M.W.",
  "voorvoegsel": "van der",
  "geslachtsnaam": "Berg",
  "adellijkeTitel": null,
  "geboortedatum": "1978-03-22",
  "geboorteplaats": "Utrecht",
  "geboorteland": "Nederland",
  "geslacht": "vrouw",
  "verblijfplaats": {
    "straat": "Lange Voorhout",
    "huisnummer": 14,
    "huisletter": "H",
    "huisnummertoevoeging": null,
    "postcode": "2514 EA",
    "woonplaats": "'s-Gravenhage",
    "land": "Nederland"
  },
  "indicatieGeheim": "0",
  "opgehaaldOp": "2026-08-14T09:23:46+02:00",
  "bronsysteem": "HaalCentraal-BRP-v2.0",
  "lookupVerzoekId": "brp-lookup-2026-08-14-9bd1",
  "gekoppeldContact": "contact-mvr-2026-002",
  "retentieTot": "2026-08-15T09:23:46+02:00"
}
```

### BsnAuditRecord
Onveranderlijke audit-regel die naar de centrale logging gaat en niet via UI wijzigbaar is.

```json
{
  "id": "audit-2026-08-14-44a8",
  "actie": "brp-lookup-uitgevoerd",
  "bsn": "123456782",
  "actor": "medewerker:m.devries@gemeente-zeist.nl",
  "actorRol": "behandelaar-burgerzaken",
  "tijdstip": "2026-08-14T09:23:46+02:00",
  "verzoekreden": "Behandeling AVG-inzageverzoek artikel 15",
  "doelbinding": "uitvoering wettelijke taak — Wet BRP art. 3.3 lid 1",
  "uitkomst": "geslaagd",
  "responseCode": 200,
  "ipAdres": "10.42.18.7",
  "userAgent": "Pipelinq/2.4.1 (Nextcloud)",
  "haalcentraalCorrelationId": "hc-corr-7f3a9b2e",
  "gekoppeldVerzoek": "verzoek-2026-08-14-1043",
  "bewaartot": "2031-08-14T09:23:46+02:00"
}
```

### OptOutVlag
Per BSN bekende opt-out / geheimhouding. Wordt overgenomen uit BRP-response en aanvullend lokaal beheerd voor verzoekers die expliciet contact-opt-out hebben.

```json
{
  "id": "optout-2026-08-14-77c2",
  "bsn": "123456782",
  "type": "geheimhouding-gemeente",
  "bron": "BRP",
  "ingangsdatum": "2024-11-01",
  "einddatum": null,
  "beperkt": ["commerciele-derden", "kerkgenootschappen"],
  "lokaalOpgevoerdDoor": null,
  "notitie": null
}
```

## Requirements

### REQ-BSN-001: Formele 11-proef validatie

**Scenario 1: geldig BSN wordt geaccepteerd**
- **GIVEN** een medewerker voert BSN `123456782` in het Contact-formulier in
- **WHEN** de frontend client-side validatie de 11-proef uitvoert
- **THEN** wordt de invoer als formeel geldig gemarkeerd met een groene check, en wordt geen externe call gedaan

**Scenario 2: ongeldig BSN wordt geweigerd**
- **GIVEN** een medewerker voert BSN `123456789` in (faalt de 11-proef)
- **WHEN** de validatie wordt uitgevoerd
- **THEN** wordt de invoer geweigerd met de melding "Dit is geen geldig BSN-formaat (11-proef faalt)" en wordt de BRP-lookup-knop disabled

**Scenario 3: niet-9-cijferige invoer**
- **GIVEN** een medewerker voert `12345678` (8 cijfers) of `12345678a` (letter) in
- **WHEN** de validatie wordt uitgevoerd
- **THEN** wordt direct gemeld "Een BSN bestaat uit exact 9 cijfers" zonder de 11-proef uberhaupt uit te voeren

### REQ-BSN-002: BRP-lookup vereist expliciete verzoekreden en doelbinding

**Scenario 1: lookup zonder reden wordt geblokkeerd**
- **GIVEN** een medewerker klikt "Ophalen uit BRP" op een Contact zonder doelbinding te kiezen
- **WHEN** het systeem het verzoek probeert te valideren
- **THEN** wordt de lookup geblokkeerd en wordt een modal getoond met verplichte velden "verzoekreden" en "doelbinding (wettelijke grondslag)"

**Scenario 2: doelbinding wordt vastgelegd**
- **GIVEN** een medewerker selecteert "Behandeling AVG-verzoek art. 15" als reden en "uitvoering wettelijke taak — AVG art. 6 lid 1 sub e" als grondslag
- **WHEN** het verzoek wordt verzonden
- **THEN** wordt een BrpLookupVerzoek aangemaakt met beide velden gevuld en wordt deze gekoppeld aan het lopende verzoek

**Scenario 3: vrij tekstveld voor specifieke onderbouwing**
- **GIVEN** de doelbinding-categorie is gekozen
- **WHEN** de medewerker een aanvullende toelichting van >= 20 tekens invult
- **THEN** wordt deze opgeslagen in `verzoekreden` en getoond in het audit-record

### REQ-BSN-003: HaalCentraal Personen REST-client met OAuth2 mTLS

**Scenario 1: succesvolle authenticatie en lookup**
- **GIVEN** Pipelinq is geconfigureerd met een OAuth2 client-credentials key + clientcertificate voor de HaalCentraal-omgeving van de gemeente
- **WHEN** een geldig BRP-lookup-verzoek wordt verstuurd
- **THEN** wordt een access-token opgehaald (max 1x per 50 minuten gecached), een mTLS-verbinding opgezet, en wordt het Persoon-object teruggegeven binnen 2 seconden

**Scenario 2: certificaat verloopt**
- **GIVEN** het clientcertificaat verloopt over <30 dagen
- **WHEN** de daily health-check loopt
- **THEN** wordt een waarschuwing naar de beheerder gestuurd (`certificaat verloopt op {datum}`) en wordt een banner getoond in de Pipelinq-admin

**Scenario 3: HaalCentraal endpoint niet bereikbaar**
- **GIVEN** de HaalCentraal-API geeft HTTP 503 of een timeout >5s
- **WHEN** een lookup wordt geprobeerd
- **THEN** krijgt de medewerker de melding "BRP momenteel niet bereikbaar — probeer over enkele minuten opnieuw", wordt de fout gelogd met correlation-id, en blijft het Contact verwerkbaar zonder geverifieerde gegevens

### REQ-BSN-004: Response-cache met TTL van maximaal 24 uur

**Scenario 1: cache-hit binnen 24 uur**
- **GIVEN** voor BSN `123456782` is een geldige lookup gedaan om 09:23
- **WHEN** binnen 24 uur (zelfde dag) opnieuw wordt opgevraagd voor hetzelfde verzoek
- **THEN** wordt de gecachte response gebruikt, `responseInCache: true` gemarkeerd, en wordt het audit-record alsnog aangemaakt zodat alle bevragingen herleidbaar blijven

**Scenario 2: cache-expiratie forceert refresh**
- **GIVEN** een Persoon-record is `opgehaaldOp: 2026-08-14T09:23` en het is nu `2026-08-15T09:24`
- **WHEN** een nieuwe lookup wordt gevraagd
- **THEN** wordt de cache als verlopen beschouwd, wordt een nieuwe HaalCentraal-call gedaan, en wordt het oude Persoon-record overschreven

**Scenario 3: cache-clear bij wijziging in BRP**
- **GIVEN** een externe webhook (HaalCentraal Mutaties) signaleert wijziging voor `123456782`
- **WHEN** de notificatie binnenkomt
- **THEN** wordt de cache voor dat BSN gemarkeerd als invalid en wordt de volgende lookup verplicht ververst

### REQ-BSN-005: Audit-trail voor elke BSN-bevraging (onveranderlijk)

**Scenario 1: succesvolle lookup wordt vastgelegd**
- **GIVEN** een succesvolle BRP-lookup is uitgevoerd
- **WHEN** de response is verwerkt
- **THEN** wordt een BsnAuditRecord aangemaakt met actor, tijdstip, BSN, doelbinding, en HaalCentraal-correlation-id; deze is alleen-lezen via UI en wordt 5 jaar bewaard (AVG art. 30 RvIG-richtlijn)

**Scenario 2: gefaalde lookup wordt ook vastgelegd**
- **GIVEN** een lookup faalt met HTTP 404 (BSN onbekend bij BRP)
- **WHEN** het systeem de fout afhandelt
- **THEN** wordt een BsnAuditRecord met `uitkomst: niet-gevonden` en `responseCode: 404` aangemaakt; de medewerker krijgt de melding "BSN niet aangetroffen in BRP — controleer invoer"

**Scenario 3: poging zonder rechten**
- **GIVEN** een medewerker zonder rol `behandelaar-burgerzaken` of `behandelaar-avg` probeert de lookup-knop te activeren
- **WHEN** het verzoek het backend bereikt
- **THEN** wordt het geweigerd met HTTP 403, wordt een audit-record `uitkomst: geweigerd-onbevoegd` aangemaakt, en wordt de FG (Functionaris Gegevensbescherming) optioneel genotificeerd bij >3 weigeringen per dag voor dezelfde gebruiker

### REQ-BSN-006: Opt-out / geheimhouding-respectering

**Scenario 1: indicatie geheim in BRP-response**
- **GIVEN** de HaalCentraal-response bevat `indicatieGeheim: "1"` (geheimhouding gemeente)
- **WHEN** Pipelinq de response verwerkt
- **THEN** wordt een OptOutVlag aangemaakt, wordt het Contact gemarkeerd met een rood geheimhoudings-icoon, en worden adresgegevens niet getoond in standaard-views (alleen via expliciete "toon onder verantwoording"-actie met extra audit)

**Scenario 2: lokale opt-out van Contact-burger**
- **GIVEN** een burger heeft expliciet aangegeven niet meer benaderd te willen worden (via Pipelinq formulier)
- **WHEN** de medewerker dit registreert
- **THEN** wordt een OptOutVlag van `type: lokale-contact-opt-out` opgevoerd, en wordt bij elke volgende uitgaande communicatie via Pipelinq een blocking-waarschuwing getoond

**Scenario 3: doorgifte aan derden geweigerd**
- **GIVEN** een Contact heeft `beperkt: ["commerciele-derden"]` in zijn OptOutVlag
- **WHEN** een integratie probeert het Contact te exporteren naar een aanverwante CRM
- **THEN** wordt de export geweigerd met de melding "Doorgifte aan derden niet toegestaan voor dit Contact (BRP-geheimhouding)"

### REQ-BSN-007: VOG-melding-compatibiliteit (Verklaring Omtrent Gedrag)

**Scenario 1: VOG-verwerking gemarkeerd**
- **GIVEN** een verzoek is van type "VOG-aanvraag" en gebruikt het BSN
- **WHEN** de medewerker de BRP-lookup uitvoert
- **THEN** wordt de doelbinding automatisch gevuld met "VOG-screening — Wet Justitiele Gegevens art. 9", en wordt een extra audit-flag `vogScreening: true` gezet voor Justis-controle

**Scenario 2: VOG-onderdeel mag niet naar derde gemeente**
- **GIVEN** Contact heeft VOG-screening-historie
- **WHEN** een ander Pipelinq-tenant het Contact via uitwisseling probeert te raadplegen
- **THEN** wordt de VOG-context afgeschermd; alleen de oorspronkelijke aanvragende organisatie ziet de screening-status

### REQ-BSN-008: Configureerbare retentie en Right-to-be-forgotten

**Scenario 1: configureerbare retentie per organisatie**
- **GIVEN** de organisatie heeft retentie ingesteld op 7 dagen na afhandeling verzoek
- **WHEN** een verzoek wordt gesloten
- **THEN** wordt 7 dagen later de gekoppelde BrpPersoon automatisch verwijderd, blijft alleen het audit-record bewaard, en wordt het Contact `verifiedBSN`-flag op `false` gezet

**Scenario 2: AVG art. 17 verwijderverzoek**
- **GIVEN** een burger doet een art. 17-verzoek (recht op vergetelheid) en dit wordt toegekend
- **WHEN** de behandelaar de verwijdering uitvoert via de AVG-workflow
- **THEN** worden BrpPersoon-records gewist, OptOutVlag verwijderd, en audit-records gepseudonimiseerd (BSN → hash) met behoud van audit-integriteit

**Scenario 3: overlijden geregistreerd in BRP**
- **GIVEN** de HaalCentraal-mutatiefeed signaleert overlijden voor BSN `123456782`
- **WHEN** de webhook wordt verwerkt
- **THEN** wordt het Contact gemarkeerd met `overledenOp: {datum}`, worden lopende geautomatiseerde communicaties gestopt, en wordt na 1 jaar (gemeente-instelbaar) het volledige persoonsdossier verwijderd

### REQ-BSN-009: BSN-veld nooit in plain-text in logs of URLs

**Scenario 1: applicatielogging maskeert BSN**
- **GIVEN** een fout treedt op tijdens BRP-lookup
- **WHEN** de exception in Nextcloud's `nextcloud.log` belandt
- **THEN** wordt het BSN gemaskeerd als `***45678*` (eerste 3 en laatste 1 zichtbaar) in alle stacktraces en messages

**Scenario 2: URL-parameters bevatten geen BSN**
- **GIVEN** een medewerker opent het Persoon-detailscherm
- **WHEN** de URL wordt gegenereerd
- **THEN** bevat de URL alleen de Pipelinq-interne `lookupVerzoekId`, nooit het BSN zelf; ook niet in `Referer`-headers of analytics-pixels

### REQ-BSN-010: Service-monitor en SLA-rapportage

**Scenario 1: dagelijkse beschikbaarheidsrapportage**
- **GIVEN** Pipelinq draait een achtergrond-job om middernacht
- **WHEN** de job de afgelopen 24h analyseert
- **THEN** wordt een rapport gegenereerd met aantal lookups, gemiddelde responsetijd, foutpercentage en cache-hit-ratio, beschikbaar in de admin-tegel "BRP-monitor"

**Scenario 2: alert bij verhoogd foutpercentage**
- **GIVEN** binnen 1 uur >10% van de lookups faalt
- **WHEN** de minuut-aggregator een drempel overschrijdt
- **THEN** wordt een notificatie naar de beheerder gestuurd via Nextcloud Notifications en optioneel via webhook naar de gemeentelijke monitoring (Zabbix/Prometheus)

## Standards & Sources

- **Wet BRP** (Basisregistratie Personen) — bevoegdheidskaders voor BSN-bevraging, met name art. 1.7 en 3.3.
- **Wet algemene bepalingen burgerservicenummer** (Wabb) — sectorale beperking van BSN-gebruik.
- **AVG / GDPR** — art. 5 (dataminimalisatie, doelbinding, opslagbeperking), art. 6 (rechtmatigheidsgronden), art. 15-22 (rechten betrokkenen), art. 30 (verwerkingsregister), art. 32 (passende beveiliging).
- **HaalCentraal Personen API v2.0** — RvIG-API, OpenAPI-spec via [haalcentraal.nl](https://www.haalcentraal.nl); vervangt StUF-BG SOAP per beleidskeuze 2024.
- **RvIG-aansluitvoorwaarden BRP** — minimum-eisen aan PKIoverheid clientcertificaten, OAuth2 client-credentials, mTLS.
- **NORA principes** (Nederlandse Overheid Referentie Architectuur) — herbruikbaarheid, transparantie, controleerbaarheid.
- **NEN 7510** (informatiebeveiliging in de zorg) — relevant voor BSN-gebruik in zorgsector-tenants.
- **BIO** (Baseline Informatiebeveiliging Overheid) — logging, monitoring, toegangscontrole.
- **Forum Standaardisatie** — pas-toe-of-leg-uit-lijst (DigiD, OAuth, REST/JSON, TLS 1.3, PKIoverheid).
- **Logius PKIoverheid** — leverancier van de clientcertificaten voor de mTLS-koppeling.
- **11-proef BSN-algoritme** — gepubliceerd door RvIG; deterministisch, sum van `(positie × cijfer)` met laatste cijfer als `−1×`-factor, modulo 11.
- **Autoriteit Persoonsgegevens — handreiking BSN-gebruik** (2023).
- **OWASP ASVS 4.0.3** — control-set V8 (Data Protection) en V10 (Communications).

## Cross-app integration

**OpenRegister (foundation)**: BsnAuditRecord, BrpLookupVerzoek, BrpPersoon en OptOutVlag worden gemodelleerd als schemas in het OpenRegister van Pipelinq. Het BsnAuditRecord-schema is `immutable: true` om wijziging via standaard-CRUD te blokkeren. Retentie-velden (`bewaartot`, `retentieTot`) worden gebruikt door de OpenRegister-retentie-job.

**Pipelinq client-management (capability dep)**: het Contact-entity krijgt twee nieuwe velden:
- `verifiedBSN` (boolean) — `true` na succesvolle BRP-lookup, `false` na retentie-expiratie of verwijdering.
- `brpPersoonId` (referentie) — wijst naar het laatste geldige Persoon-record.
- `geheimhouding` (boolean) — afgeleid uit de OptOutVlag, toont het rode icoon in alle Contact-views.

**Pipelinq contactmoment-timeline**: elke lookup, cache-hit, en cache-clear genereert een timeline-event `brp-lookup-uitgevoerd` zichtbaar voor de behandelaar (geen BSN in de event-tekst, alleen verzoekreden en uitkomst).

**OpenConnector**: de HaalCentraal-client wordt geconfigureerd als een `Source` van type `oauth2-mtls-haalcentraal`. Mappings worden in OpenConnector beheerd zodat HaalCentraal-veranderingen niet doorbreken naar app-code. Dit volgt ADR-019 (Pluggable Integration Registry).

**AVG-verzoeken-workflow capability (sibling)**: elke art. 15-inzage of art. 17-vergetelheid triggert automatisch een BRP-lookup met vooringevulde doelbinding, en sluit aan op de retentie-cyclus.

**DocuDesk**: wanneer een Persoonsdossier (verzoek-bundel) wordt geexporteerd, wordt de afdruk van de Persoon-gegevens gegenereerd met DocuDesk-template; geheimhoudings-velden worden in de template afgeschermd.

**Docudesk audit-export naar SIEM**: de BsnAuditRecord-stream wordt via een outbound webhook (configureerbaar) naar het centrale SIEM van de gemeente gestuurd voor onafhankelijke bewaring.

## Target users

- **Behandelaar Burgerzaken** (primaire gebruiker): voert BSN in bij Contact-aanmaak, ziet 11-proef-feedback direct, doet BRP-lookup ter verificatie. Verwacht responsetijd <2s en duidelijke foutmeldingen bij niet-bereikbare BRP.
- **Behandelaar AVG-verzoeken**: gebruikt geautomatiseerde lookup als onderdeel van workflow (geen handmatige knop), vertrouwt op vooringevulde doelbinding.
- **Behandelaar VOG/screening**: vereist extra audit-flag voor Justis-controle.
- **Functionaris voor Gegevensbescherming (FG)**: heeft alleen-lezen-rol op alle BsnAuditRecords, kan rapportages per gebruiker / per dag / per doelbinding genereren, krijgt alerts bij verdachte patronen (veel weigeringen, ongebruikelijke tijdstippen).
- **Beheerder / functioneel-applicatiebeheerder**: configureert HaalCentraal-keys, certificaten, retentie-instellingen; monitort SLA-dashboard.
- **Interne audit / accountant**: kan via export-functie alle bevragingen over een periode opleveren met BSN gehasht voor controle, in CSV of JSON formaat conform RvIG-rapportageformaat.
- **Autoriteit Persoonsgegevens / RvIG-auditor** (externe inspectie): krijgt op verzoek een rapportage waaruit blijkt dat alle lookups een geldige doelbinding hadden, conform AVG art. 30 verwerkingsregister.
- **Burger zelf** (indirect): heeft geen direct UI, maar profiteert van geheimhoudings-respect, opt-out-handhaving en mogelijkheid tot inzage van wie wanneer zijn BSN heeft bevraagd (via AVG art. 15-workflow).
