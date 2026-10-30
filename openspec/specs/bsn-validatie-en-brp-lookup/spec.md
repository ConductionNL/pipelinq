# bsn-validatie-en-brp-lookup Specification

## Purpose

Validating a BSN with the 11-check and looking a person up in the BRP through HaalCentraal Personen, with a recorded reason and purpose, a short-lived cache, an immutable audit trail, respect for secrecy and opt-out, and no BSN in logs or URLs. Synced from the archived change `2026-06-14-bsn-validatie-en-brp-lookup`.

## Requirements

### Requirement: Formele 11-proef validatie (REQ-BSN-001)

The system SHALL validate a BSN with the 11-check before any lookup and refuse an invalid one without an external call.

De 11-proef validatie gebeurt client-side en blokkeert ongeldig BSN onmiddellijk.

**Feature tier**: MVP
**Spec ref**: `design.md#BsnValidationService`
**Files**: `lib/Service/BsnValidationService.php`, frontend Contact detail view

#### Scenario: Geldig BSN wordt geaccepteerd (REQ-BSN-001-01)

- GIVEN een medewerker voert BSN `123456782` in het Contact-formulier in
- WHEN de frontend client-side validatie de 11-proef uitvoert
- THEN wordt de invoer als formeel geldig gemarkeerd met een groene check
- AND geen externe call naar HaalCentraal wordt gedaan
- AND de "Ophalen uit BRP"-knop wordt enabled

#### Scenario: Ongeldig BSN wordt geweigerd (REQ-BSN-001-02)

- GIVEN een medewerker voert BSN `123456789` in (faalt de 11-proef)
- WHEN de validatie wordt uitgevoerd
- THEN wordt de invoer geweigerd met de melding "Dit BSN voldoet niet aan de 11-proef (controlesom fout)"
- AND de "Ophalen uit BRP"-knop blijft disabled
- AND geen lookup wordt gestart

#### Scenario: Niet-9-cijferige invoer (REQ-BSN-001-03)

- GIVEN een medewerker voert `12345678` (8 cijfers) of `12345678a` (letter) in
- WHEN de validatie wordt uitgevoerd
- THEN wordt direct gemeld "Een BSN bestaat uit exact 9 cijfers" zonder 11-proef uberhaupt uit te voeren
- AND de "Ophalen uit BRP"-knop blijft disabled

#### Scenario: Validatie zonder externe call (REQ-BSN-001-04)

- GIVEN een medewerker voert een willekeurig geldig BSN in
- WHEN de 11-proef wordt gecontroleerd
- THEN gebeurt dit geheel client-side (geen verzoek naar /api/brp/validate)
- AND de response is onmiddellijk (<100ms)

### Requirement: BRP-lookup vereist expliciete verzoekreden en doelbinding (REQ-BSN-002)

The system SHALL refuse a BRP lookup that has no request reason and purpose, and SHALL record both with the lookup.

Elke BRP-lookup moet een geldige verzoekreden en doelbinding hebben, vastgelegd voor compliance.

**Feature tier**: MVP
**Spec ref**: `design.md#BRP Lookup with Doelbinding`
**Files**: `lib/Controller/BrpController.php`, frontend modal

#### Scenario: Lookup zonder reden wordt geblokkeerd (REQ-BSN-002-01)

- GIVEN een medewerker klikt "Ophalen uit BRP" op een Contact met geldig BSN
- WHEN het systeem een modal toont
- THEN moet de medewerker een "Verzoekreden" selecteren (verplicht dropdown)
- AND zonder selectie kan de "Ophalen"-knop niet geklikt worden (greyed out)
- AND geen API-call naar HaalCentraal wordt gedaan

#### Scenario: Doelbinding wordt vastgelegd (REQ-BSN-002-02)

- GIVEN een medewerker selecteert "Behandeling AVG-verzoek art. 15" als reden en "Publieke taak — Wet BRP art. 3.3" als grondslag
- WHEN het verzoek wordt verzonden
- THEN wordt een BrpLookupVerzoek aangemaakt met beide velden gevuld
- AND dit verzoek wordt gekoppeld aan het lopende Contact (gekoppeldContact)
- AND de lookup gaat door naar HaalCentraal

#### Scenario: Vrij tekstveld voor specifieke onderbouwing (REQ-BSN-002-03)

- GIVEN de verzoekreden is geselecteerd
- WHEN de medewerker aanvullende toelichting invult van >= 20 tekens
- THEN wordt deze opgeslagen in BrpLookupVerzoek.verzoekreden als aangevulde waarde
- AND getoond in het BsnAuditRecord voor audit-controle

### Requirement: HaalCentraal Personen REST-client met OAuth2 mTLS (REQ-BSN-003)

The system SHALL query HaalCentraal Personen with OAuth2 client credentials over mutual TLS.

De client communiceert secure met HaalCentraal via OAuth2 client-credentials en mutual TLS.

**Feature tier**: MVP
**Spec ref**: `design.md#HaalCentraalClient`
**Files**: `lib/Service/HaalCentraalClient.php`, admin settings

#### Scenario: Succesvolle authenticatie en lookup (REQ-BSN-003-01)

- GIVEN Pipelinq is geconfigureerd met OAuth2 client credentials en mTLS certificaat
- WHEN een geldig BRP-lookup-verzoek wordt verstuurd
- THEN wordt een access-token opgehaald (gecached max 50 min)
- AND mTLS-verbinding opgezet naar HaalCentraal endpoint
- AND Persoon-object teruggegeven binnen 2 seconden
- AND response wordt geparser naar BrpPersoon object

#### Scenario: Certificaat verloopt binnenkort (REQ-BSN-003-02)

- GIVEN het clientcertificaat verloopt over < 30 dagen
- WHEN de daily health-check loopt (eenmalig per dag)
- THEN wordt een waarschuwing naar de beheerder gestuurd via Nextcloud Notifications
- AND admin dashboard toont "Certificate expires: {datum} ({days} days)"
- AND status badge wordt "⚠️ Expires soon"

#### Scenario: HaalCentraal endpoint niet bereikbaar (REQ-BSN-003-03)

- GIVEN de HaalCentraal-API geeft HTTP 503 of een timeout > 5s
- WHEN een lookup wordt geprobeerd
- THEN krijgt de medewerker de melding "BRP momenteel niet bereikbaar — probeer over enkele minuten opnieuw"
- AND error wordt gelogd met correlation-id
- AND Contact blijft verwerkbaar zonder geverifieerde gegevens
- AND BsnAuditRecord wordt toch geschreven met uitkomst=fout, responseCode=503

### Requirement: Response-cache met TTL van maximaal 24 uur (REQ-BSN-004)

The system SHALL cache BRP responses for a configurable time of at most 24 hours.

BRP-responses worden gecacht om latency te verminderen, met configureerbare vervaldatum.

**Feature tier**: MVP
**Spec ref**: `design.md#BrpCacheService`
**Files**: `lib/Service/BrpCacheService.php`

#### Scenario: Cache-hit binnen 24 uur (REQ-BSN-004-01)

- GIVEN voor BSN `123456782` is een geldige lookup gedaan om 09:23 op dag X
- WHEN binnen 24 uur (hetzelfde Contact) opnieuw wordt opgevraagd
- THEN wordt de gecachte BrpPersoon-response gebruikt
- AND `responseInCache: true` gemarkeerd in de lookup
- AND response-time < 10ms (uit cache, niet uit HaalCentraal)
- AND BsnAuditRecord wordt alsnog aangemaakt zodat alle bevragingen herleidbaar blijven

#### Scenario: Cache-expiratie forceert refresh (REQ-BSN-004-02)

- GIVEN een Persoon-record heeft `opgehaaldOp: 2026-08-14T09:23` en `retentieTot: 2026-08-15T09:23`
- WHEN nu is `2026-08-15T09:24` en een nieuwe lookup wordt gevraagd
- THEN wordt de cache als verlopen beschouwd
- AND een nieuwe HaalCentraal-call wordt gedaan
- AND het oude Persoon-record wordt overschreven (uuid blijft hetzelfde, data+retentieTot worden ververst)

#### Scenario: Cache-clear bij wijziging in BRP (REQ-BSN-004-03)

- GIVEN een externe webhook (HaalCentraal Mutaties) signaleert wijziging voor `123456782`
- WHEN de notificatie binnenkomt op `/api/brp/mutations`
- THEN wordt de cache voor dat BSN gemarkeerd als invalid
- AND de volgende lookup forceert een HaalCentraal-call (cache wordt genegeerd)

#### Scenario: Configureerbare TTL (REQ-BSN-004-04)

- GIVEN een organisatie wil cache-TTL aanpassen (standaard 24 uur)
- WHEN de admin de setting "BRP Cache TTL (uren)" aanpast naar bijv. 4 uur
- THEN gebruiken nieuwe lookups de nieuwe TTL-waarde
- AND bestaande gecachte records gebruiken nog hun oorspronkelijke retentieTot

### Requirement: Audit-trail voor elke BSN-bevraging (onveranderlijk) (REQ-BSN-005)

The system SHALL write every BSN lookup, successful or not, to an immutable audit trail.

Elke lookup, ongeacht uitkomst, wordt in een onveranderlijk auditlogboek vastgelegd.

**Feature tier**: MVP
**Spec ref**: `design.md#BsnAuditRecord`
**Files**: `lib/Service/BsnAuditService.php`

#### Scenario: Succesvolle lookup wordt vastgelegd (REQ-BSN-005-01)

- GIVEN een succesvolle BRP-lookup is uitgevoerd
- WHEN de response is verwerkt
- THEN wordt een BsnAuditRecord aangemaakt met:
  - actie: "brp-lookup-uitgevoerd"
  - actor: medewerker die lookup initieerde
  - tijdstip: ISO 8601 timestamp
  - BSN: gehashed na opslag (***45678* in UI)
  - doelbinding: vastgelegd voor compliance-audit
  - haalcentraalCorrelationId: tracking ID
- AND record is alleen-lezen via UI (immutable: true in schema)
- AND bewaartot: now + 5 jaren (RvIG-richtlijn)

#### Scenario: Gefaalde lookup wordt ook vastgelegd (REQ-BSN-005-02)

- GIVEN een lookup faalt met HTTP 404 (BSN onbekend bij BRP)
- WHEN het systeem de fout afhandelt
- THEN wordt een BsnAuditRecord aangemaakt met:
  - uitkomst: "niet-gevonden"
  - responseCode: 404
- AND medewerker krijgt melding "BSN niet aangetroffen in BRP — controleer invoer"
- AND record is permanent behouden (geen delete)

#### Scenario: Poging zonder rechten (REQ-BSN-005-03)

- GIVEN een medewerker zonder rol `behandelaar-burgerzaken` of `behandelaar-avg` probeert de lookup-knop te activeren
- WHEN het verzoek het backend bereikt
- THEN wordt het geweigerd met HTTP 403
- AND een BsnAuditRecord met uitkomst: "geweigerd-onbevoegd" wordt aangemaakt
- AND FG (Functionaris Gegevensbescherming) kan optioneel genotificeerd worden bij >3 weigeringen per dag voor dezelfde gebruiker

#### Scenario: BSN-masking in logs (REQ-BSN-005-04)

- GIVEN een exception treedt op tijdens BRP-lookup
- WHEN de exception in applicatie-logs wordt gelogd
- THEN wordt het BSN gemaskeerd als `***45678*` (eerste 3 + laatste 1 zichtbaar)
- AND in alle stacktraces en messages

### Requirement: Opt-out / geheimhouding-respectering (REQ-BSN-006)

The system SHALL recognise BRP secrecy and a local opt-out and shield that person's data accordingly.

Burgers met geheimhouding in BRP of lokale opt-out worden herkend en hun data afgeschermd.

**Feature tier**: MVP
**Spec ref**: `design.md#OptOutService`
**Files**: `lib/Service/OptOutService.php`, frontend Contact detail

#### Scenario: Indicatie geheim in BRP-response (REQ-BSN-006-01)

- GIVEN de HaalCentraal-response bevat `indicatieGeheim: "1"` (geheimhouding gemeente)
- WHEN Pipelinq de response verwerkt
- THEN wordt een OptOutVlag aangemaakt met type: "geheimhouding-gemeente"
- AND Contact wordt gemarkeerd met rood geheimhoudings-icoon
- AND adresgegevens worden verborgen in standaard-views
- AND alleen via expliciete "toon adres onder verantwoording"-actie zichtbaar (met extra audit-entry)

#### Scenario: Lokale opt-out van Contact-burger (REQ-BSN-006-02)

- GIVEN een burger voert formulier in: "Wil niet meer benaderd worden"
- WHEN de medewerker dit registreert via Contact-interface
- THEN wordt een OptOutVlag aangemaakt met type: "lokale-contact-opt-out"
- AND bij elke volgende uitgaande communicatie via Pipelinq wordt een blocking-waarschuwing getoond
- AND communicatie kan alleen voortgezet worden met expliciete bevestiging van medewerker

#### Scenario: Doorgifte aan derden geweigerd (REQ-BSN-006-03)

- GIVEN een Contact heeft OptOutVlag met beperkt: ["commerciele-derden"]
- WHEN een integratie probeert het Contact te exporteren naar aanverwante CRM
- THEN wordt de export geweigerd met melding "Doorgifte aan derden niet toegestaan voor dit Contact (BRP-geheimhouding)"
- AND export-logboek registreert de poging (voor audit)

### Requirement: VOG-melding-compatibiliteit (Verklaring Omtrent Gedrag) (REQ-BSN-007)

The system SHALL mark lookups made for a VOG request with an extra audit flag.

Voor VOG-aanvragen wordt een extra audit-vlag gezet voor Justis-controle.

**Feature tier**: MVP
**Spec ref**: `design.md#Frontend`
**Files**: frontend modal, `lib/Service/BsnAuditService.php`

#### Scenario: VOG-verwerking gemarkeerd (REQ-BSN-007-01)

- GIVEN een verzoek is van type "VOG-aanvraag" en gebruikt het BSN
- WHEN de medewerker de BRP-lookup uitvoert
- THEN wordt de doelbinding automatisch gevuld met "VOG-screening — Wet Justitiele Gegevens art. 9"
- AND in BsnAuditRecord wordt vogScreening: true gezet
- AND deze vlag wordt zichtbaar voor audit-export naar Justis

#### Scenario: VOG-onderdeel mag niet naar derde gemeente (REQ-BSN-007-02)

- GIVEN Contact bevat VOG-screening-historie (vogScreening: true in audit-records)
- WHEN een ander Pipelinq-tenant het Contact via uitwisseling probeert te raadplegen
- THEN wordt de VOG-context afgeschermd
- AND alleen de oorspronkelijke aanvragende organisatie ziet vogScreening-vlag
- AND andere tenants zien geen VOG-referentie

### Requirement: Configureerbare retentie en Right-to-be-forgotten (REQ-BSN-008)

The system SHALL delete BRP data after a configurable retention period and on an erasure request, keeping the audit records pseudonymised.

BRP-gegevens worden na configureerbare termijn verwijderd; audit-records blijven (gepseudonimiseerd).

**Feature tier**: MVP
**Spec ref**: `design.md#BrpPersoon`
**Files**: `lib/Job/BrpRetentionJob.php`, admin settings

#### Scenario: Configureerbare retentie per organisatie (REQ-BSN-008-01)

- GIVEN organisatie heeft retentie ingesteld op 7 dagen na afhandeling verzoek
- WHEN een verzoek wordt gesloten
- THEN wordt 7 dagen later de gekoppelde BrpPersoon automatisch verwijderd via background job
- AND Contact.verifiedBSN wordt false gezet
- AND Contact.brpPersoonId blijft staan (nu nullpointer naar deleted record)
- AND BsnAuditRecord blijft behouden, maar BSN wordt gepseudonimiseerd (hash)

#### Scenario: AVG art. 17 verwijderverzoek (Right-to-be-forgotten) (REQ-BSN-008-02)

- GIVEN een burger doet een art. 17-verzoek en dit wordt toegekend
- WHEN de behandelaar het verwijderverzoek uitvoert via AVG-workflow
- THEN worden BrpPersoon-records verwijderd
- AND OptOutVlag wordt verwijderd
- AND BsnAuditRecord wordt gepseudonimiseerd (BSN → SHA-256 hash)
- AND audit-integriteit blijft behouden (records zijn nog traceerbaar via actor/tijdstip/doelbinding)

#### Scenario: Overlijden geregistreerd in BRP (REQ-BSN-008-03)

- GIVEN HaalCentraal-mutatiefeed signaleert overlijden voor BSN `123456782`
- WHEN de webhook wordt verwerkt
- THEN wordt Contact gemarkeerd met overledenOp: {datum}
- AND lopende geautomatiseerde communicaties naar dit Contact worden gestopt
- AND na 1 jaar (gemeente-instelbaar) wordt volledige persoonsdossier verwijderd (niet alleen BrpPersoon, ook Contact-gerelateerde data)

### Requirement: BSN-veld nooit in plain-text in logs of URLs (REQ-BSN-009)

The system SHALL never write a BSN in plain text to logs or put it in a URL.

BSN wordt beschermd door masking in logs en nooit in URLs/parameters zichtbaar.

**Feature tier**: MVP
**Spec ref**: `design.md#Frontend`
**Files**: Logging configuratie, frontend URL generation

#### Scenario: Applicatielogging maskeert BSN (REQ-BSN-009-01)

- GIVEN een fout treedt op tijdens BRP-lookup
- WHEN de exception in Nextcloud's applicatie-logs belandt
- THEN wordt het BSN gemaskeerd als `***45678*` in alle stacktraces, messages, context
- AND raw (unmasked) BSN mag alleen in `BsnAuditRecord.bsn` staan (encrypted/hashed)

#### Scenario: URL-parameters bevatten geen BSN (REQ-BSN-009-02)

- GIVEN een medewerker opent het Persoon-detailscherm
- WHEN de URL wordt gegenereerd
- THEN bevat de URL alleen interne Pipelinq-ID's (bijv. ?personId=uuid-123)
- AND bevat NOOIT het BSN zelf
- AND ook niet in HTTP Referer-headers, analytics-pixels, of cookies

### Requirement: Service-monitor en SLA-rapportage (REQ-BSN-010)

The system SHALL monitor the availability and response time of the BRP integration and report it against the SLA.

Het systeem monitort beschikbaarheid en performa van BRP-integratie.

**Feature tier**: MVP
**Spec ref**: `design.md#Admin Dashboard: BRP-Monitor`
**Files**: `lib/Job/BrpMonitorJob.php`, frontend admin dashboard

#### Scenario: Dagelijkse beschikbaarheidsrapportage (REQ-BSN-010-01)

- GIVEN Pipelinq draait een achtergrond-job om middernacht (configurable)
- WHEN de job de afgelopen 24h analyseert
- THEN wordt een rapport gegenereerd met:
  - Aantal lookups
  - Cache-hit ratio (%)
  - Gemiddelde responsetijd (ms)
  - Foutpercentage (%)
- AND rapport beschikbaar in admin-tegel "BRP-Monitor"

#### Scenario: Alert bij verhoogd foutpercentage (REQ-BSN-010-02)

- GIVEN binnen 1 uur > 10% van de lookups faalt
- WHEN de minuut-aggregator een drempel overschrijdt
- THEN wordt een notificatie naar beheerder gestuurd via Nextcloud Notifications
- AND optioneel via webhook naar gemeentelijke monitoring (Zabbix/Prometheus format)
- AND notificatie bevat: "BRP error rate exceeded 10% in last hour (12 failures of 100 attempts)"

#### Scenario: Certificate expiration warning (REQ-BSN-010-03)

- GIVEN certificaat verloopt over < 30 dagen
- WHEN health-check loopt
- THEN beheerder ontvangt waarschuwing
- AND admin dashboard geeft visual indicator met countdown
- AND als vervaldatum binnen < 7 dagen: escalatie naar kritiek (rood badge)

