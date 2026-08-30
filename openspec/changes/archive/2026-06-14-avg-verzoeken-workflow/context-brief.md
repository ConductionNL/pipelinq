---
status: draft
---
# AVG-verzoeken (Article 15/16/17/18/20 GDPR) workflow

## Purpose

Onder de Algemene Verordening Gegevensbescherming (AVG / GDPR) heeft elke burger een set onvervreemdbare rechten ten aanzien van zijn persoonsgegevens. De vijf operationele rechten — inzage (art. 15), rectificatie (art. 16), wissing / vergetelheid (art. 17), beperking van verwerking (art. 18) en dataportabiliteit (art. 20) — moeten door verwerkingsverantwoordelijken (gemeenten, ZBO's, ministeries, waterschappen, uitvoeringsorganisaties) binnen wettelijke termijnen worden afgehandeld. De wet schrijft voor: binnen 1 maand een gemotiveerd antwoord, met mogelijke verlenging van 2 maanden bij complexiteit (art. 12 lid 3 AVG).

In de praktijk is dit voor gemeenten een ingewikkeld proces: gegevens van een betrokkene zitten verspreid over meerdere systemen (BRP, zaak­systeem, e-mail, OpenConnector-gekoppelde bronnen, archief), de behandelaar moet bewijs verzamelen, een redactietool gebruiken om derden te beschermen, en uiteindelijk een bundel exporteren — alles binnen 30 dagen en aantoonbaar voor de toezichthouder (Autoriteit Persoonsgegevens).

Deze capability levert een end-to-end workflow voor de behandeling van AVG-verzoeken in Pipelinq:

1. **Intake** via webformulier of handmatige registratie, met automatische identificatie van het artikeltype.
2. **Termijnbewaking**: 30-dagen-timer met automatische escalatie en optionele 60-dagen-verlenging onder vastlegging van de gegronde reden.
3. **Workflow-router** per artikeltype: art. 15 (inzage) en art. 20 (portabiliteit) starten een bewijsverzameling; art. 16 en 17 starten een correctie- of verwijderingsproces; art. 18 plaatst een "freeze" op verwerking elders.
4. **Bewijsverzameling**: federated query naar OpenRegister, BRP (via BSN-validatie-capability), OpenConnector-bronnen, en optioneel naar linked apps (DocuDesk, Talk, zaakafhandeling).
5. **Data-export-bundle**: gestructureerde JSON + leesbare PDF, ondertekend, met afzonderlijke metadata-laag voor herkomst en grondslag.
6. **Redactietool**: visueel zwart-blokken van derden, met before/after vergelijking voor de behandelaar.
7. **Weigering met grond**: gemotiveerde afwijzing onder art. 23 (beperkingen) met verplichte verwijzing naar AP-bezwaarprocedure.
8. **AP-escalatie-pad**: bij klacht van betrokkene, dossierbundel direct exporteerbaar naar AP.
9. **Retentie van het verzoekdossier zelf**: 5 jaar bewaring conform RvIG-richtlijn (om aan te kunnen tonen dat een verzoek correct is afgehandeld).
10. **DPIA-flag**: bij verzoeken die wijzen op een systematisch probleem (bv. 10+ wissingsverzoeken om dezelfde categorie gegevens) wordt automatisch een DPIA-review-flag gezet voor de FG.

De capability is bewust ontworpen vanuit de behandelaar: de tijd van een gemiddelde AVG-medewerker is schaars, en het systeem moet de juridische precisie bewaken zonder de behandelaar te dwingen elk artikel opnieuw te begrijpen.

## Data Model

### AvgVerzoek
Het centrale verzoek-object. Gekoppeld aan een Contact (via BSN-validatie capability) en optioneel aan een Persoon.

```json
{
  "id": "avg-verzoek-2026-04-08-0034",
  "kenmerk": "AVG-2026-0034",
  "ingediendOp": "2026-04-08T11:14:00+02:00",
  "ingediendVia": "webformulier",
  "verzoekerContact": "contact-mvr-2026-018",
  "verzoekerNaam": "M.W. van der Berg",
  "verzoekerBsn": "123456782",
  "verzoekerBsnGeverifieerd": true,
  "artikel": "art-15-inzage",
  "specifiekeVraag": "Welke persoonsgegevens van mij zijn vastgelegd in het kader van mijn parkeervergunning-aanvraag van 2024?",
  "scope": ["parkeervergunningen", "facturatie", "communicatie"],
  "wettelijkeTermijnVerloopt": "2026-05-08T23:59:59+02:00",
  "verlengdMet": null,
  "verlengingsgrond": null,
  "status": "in-behandeling",
  "behandelaar": "medewerker:s.jansen@gemeente-zeist.nl",
  "fgGeinformeerd": false,
  "dpiaFlag": false,
  "uitkomst": null,
  "afgerondOp": null,
  "bewijsbundel": "bundle-2026-04-08-0034",
  "retentieTot": "2031-04-08T00:00:00+02:00"
}
```

### TermijnEvent
Verschillende termijn-events die de workflow drijven.

```json
{
  "id": "termijn-event-2026-04-08-0034-T0",
  "verzoekId": "avg-verzoek-2026-04-08-0034",
  "type": "ontvangstbevestiging-verstuurd",
  "tijdstip": "2026-04-08T11:14:32+02:00",
  "deadline": "2026-04-08T15:14:32+02:00",
  "automatisch": true,
  "geslaagd": true,
  "details": "E-mail naar verzoeker bevestigd ontvangst en informeert over termijnen"
}
```

### BewijsItem
Een individueel stukje gevonden bewijs / persoonsgegevens.

```json
{
  "id": "bewijs-item-2026-04-08-0034-007",
  "verzoekId": "avg-verzoek-2026-04-08-0034",
  "bronApp": "openregister",
  "bronRegister": "parkeervergunningen",
  "bronObject": "vergunning-2024-09-882",
  "categorie": "vergunningsaanvraag",
  "verzameldOp": "2026-04-09T14:02:11+02:00",
  "rechtsgrond": "wettelijke taak — Wegenverkeerswet",
  "opgenomenInExport": true,
  "geredigeerd": false,
  "redactiereden": null,
  "inhoudPreview": "Aanvraag bewonersparkeren zone 7, ingediend op 12-09-2024, beschikking 25-09-2024..."
}
```

### ExportBundle
De uiteindelijke uitlevering, met integriteits-hash.

```json
{
  "id": "bundle-2026-04-08-0034",
  "verzoekId": "avg-verzoek-2026-04-08-0034",
  "samengesteldOp": "2026-04-22T16:30:00+02:00",
  "samengesteldDoor": "medewerker:s.jansen@gemeente-zeist.nl",
  "bevatItems": 47,
  "formaat": ["json", "pdf"],
  "bestandsgrootte": "12.3 MB",
  "sha256": "8d4e1f...",
  "ondertekend": true,
  "ondertekeningsType": "PAdES-LTV",
  "uitgeleverdVia": "veilige-download-link",
  "uitgeleverdOp": "2026-04-22T17:00:00+02:00",
  "downloadVerloopt": "2026-05-22T17:00:00+02:00",
  "downloadCode": "***********",
  "verzoekerOntvangstBevestigd": false
}
```

### Weigering
Bij weigering, expliciet onderbouwd.

```json
{
  "id": "weigering-2026-04-08-0034",
  "verzoekId": "avg-verzoek-2026-04-08-0034",
  "weigering": "gedeeltelijk",
  "geweigerdeOnderdelen": ["scope:facturatie"],
  "grond": "art-23-lid-1-sub-e",
  "toelichtingAvg23": "De financiele administratie valt onder een wettelijke bewaarplicht (Belastingwet art. 52). Tot afloop van de fiscale bewaartermijn (7 jaar) kan dit deel niet worden gewist; wel mag inzage worden geboden — dit is alsnog meegenomen in de inzage-bundel.",
  "verwijzingBezwaarProcedure": true,
  "verwijzingAp": "https://autoriteitpersoonsgegevens.nl/melding-doen",
  "ondertekendDoor": "j.de.vries@gemeente-zeist.nl",
  "ondertekendOp": "2026-04-22T16:45:00+02:00"
}
```

### RedactieActie
Per veld een redactie-handeling voor verantwoording.

```json
{
  "id": "redactie-2026-04-08-0034-003",
  "bundleId": "bundle-2026-04-08-0034",
  "bewijsItemId": "bewijs-item-2026-04-08-0034-007",
  "veldpad": "$.beschikkingHandtekening",
  "voorWaarde": "j.handtekening.de.boer@gemeente-zeist.nl",
  "naWaarde": "[geredigeerd: persoonsgegevens medewerker — art. 41 AVG]",
  "uitgevoerdDoor": "medewerker:s.jansen@gemeente-zeist.nl",
  "uitgevoerdOp": "2026-04-20T10:12:00+02:00",
  "grond": "bescherming-rechten-derden"
}
```

## Requirements

### REQ-AVG-001: Automatische artikelherkenning bij intake

**Scenario 1: webformulier-keuze**
- **GIVEN** een burger vult het AVG-webformulier in en selecteert "Ik wil weten welke gegevens jullie van mij hebben"
- **WHEN** het formulier wordt ingezonden
- **THEN** wordt een AvgVerzoek aangemaakt met `artikel: "art-15-inzage"`, en wordt direct een wettelijke termijn berekend (30 dagen) en gevisualiseerd in de behandelaarsweergave

**Scenario 2: vrije tekst valt onder meerdere artikelen**
- **GIVEN** een burger schrijft "Ik wil mijn gegevens corrigeren en daarna verwijderen"
- **WHEN** een behandelaar de intake doet
- **THEN** krijgt de behandelaar een keuze tussen "art. 16 rectificatie" en "art. 17 wissing", en kan optioneel beide artikelen als subverzoeken aanmaken (workflow-vork)

**Scenario 3: niet-AVG-verzoek geweigerd**
- **GIVEN** een burger stuurt een algemeen klacht zonder AVG-grondslag
- **WHEN** de behandelaar het beoordeelt
- **THEN** kan deze het verzoek herclassificeren als "regulier verzoek" (geen AVG-termijn), en wordt het naar de algemene verzoeken-stroom doorgezet

### REQ-AVG-002: 30-dagen termijnbewaking met escalatie

**Scenario 1: standaard 30-dagen timer**
- **GIVEN** een AvgVerzoek is aangemaakt op 8 april om 11:14
- **WHEN** de timer-job draait
- **THEN** wordt `wettelijkeTermijnVerloopt: 2026-05-08T23:59:59` gezet, en wordt 7 dagen voor expiratie automatisch een herinnering naar de behandelaar gestuurd

**Scenario 2: escalatie bij 3 dagen restant**
- **GIVEN** een verzoek is nog niet afgerond en restantduur is <72 uur
- **WHEN** de uurlijkse check loopt
- **THEN** wordt de teamlead in cc gezet op de dagelijkse herinnering, en wordt een rood vlaggetje getoond op het verzoek

**Scenario 3: overschrijding gelogd**
- **GIVEN** termijn is verstreken zonder uitkomst
- **WHEN** middernacht passeert
- **THEN** wordt een TermijnEvent `termijn-overschreden` aangemaakt, wordt de FG genotificeerd, en wordt het verzoek in een aparte "te-laat"-werklijst getoond

### REQ-AVG-003: 60-dagen verlenging met gemotiveerde grond

**Scenario 1: verlenging mits onderbouwing**
- **GIVEN** een behandelaar wil verlengen op dag 25 vanwege complexiteit
- **WHEN** deze de "Verleng termijn"-actie kiest
- **THEN** wordt een verplicht tekstveld getoond ("complexiteit/aantal verzoeken") met min 30 tekens, en bij opslag wordt `verlengdMet: 60` en `verlengingsgrond` gevuld, plus automatische e-mail aan verzoeker met motivering

**Scenario 2: verlenging niet mogelijk na termijn**
- **GIVEN** termijn is al verstreken
- **WHEN** verlenging wordt geprobeerd
- **THEN** wordt actie geweigerd met melding "Verlenging moet uiterlijk op dag 30 worden gecommuniceerd (AVG art. 12 lid 3)"

**Scenario 3: dubbele verlenging niet toegestaan**
- **GIVEN** een verzoek is al eerder verlengd
- **WHEN** de behandelaar opnieuw probeert te verlengen
- **THEN** wordt dit geblokkeerd; alternatief is overdracht naar juridische afdeling met escalatie

### REQ-AVG-004: Federated bewijsverzameling

**Scenario 1: trigger bewijscollectie vanuit OpenRegister**
- **GIVEN** een art. 15-verzoek met scope `["parkeervergunningen"]`
- **WHEN** behandelaar "Verzamel bewijs" klikt
- **THEN** wordt een async job gestart die OpenRegister doorzoekt op alle objecten met BSN-veld = verzoeker, scope-gefilterd, en BewijsItems aanmaakt voor elke hit

**Scenario 2: bron-onbereikbaar wordt geregistreerd**
- **GIVEN** een gekoppelde OpenConnector-bron (extern CRM) reageert niet binnen 10s
- **WHEN** de query loopt
- **THEN** wordt een BewijsItem aangemaakt met `bronApp: "openconnector"`, `verzameldOp: null`, `categorie: "bron-onbereikbaar"`, en wordt de behandelaar gewaarschuwd dat handmatige aanvulling nodig is

**Scenario 3: deduplicatie van bewijsitems**
- **GIVEN** dezelfde gegevens zitten in zowel OpenRegister als een gespiegelde bron
- **WHEN** de collectie gestart wordt
- **THEN** detecteert het systeem identieke records (op basis van content-hash + objectId-koppeling) en markeert duplicaten zodat de behandelaar kan kiezen welke versie wordt geexporteerd

### REQ-AVG-005: Data-export bundel (JSON + PDF)

**Scenario 1: PDF-bundle generatie**
- **GIVEN** alle BewijsItems zijn verzameld en geredigeerd waar nodig
- **WHEN** behandelaar "Genereer bundle" klikt
- **THEN** wordt een PDF samengesteld via DocuDesk, met inhoudsopgave per categorie, per item een paginakop met bron + rechtsgrond, en een afsluitende verklaring van de behandelaar; ook een machine-leesbare JSON-export wordt bijgevoegd

**Scenario 2: cryptografische ondertekening**
- **GIVEN** een bundle is gegenereerd
- **WHEN** deze wordt opgeleverd
- **THEN** wordt deze PAdES-LTV-ondertekend met het gemeente-PKIoverheid-certificaat zodat de burger en eventueel AP de integriteit kunnen verifieren; SHA-256-hash wordt opgenomen in het verzoek-record

**Scenario 3: veilige uitlevering**
- **GIVEN** de bundle is klaar
- **WHEN** deze wordt verzonden
- **THEN** krijgt de verzoeker een eenmalige download-link (max 30 dagen geldig) per beveiligde e-mail of MijnOverheid-Berichtenbox; standaard NIET als plain-attachment vanwege omvang en vertrouwelijkheid

### REQ-AVG-006: Redactietool voor bescherming derden

**Scenario 1: zwart-blok van naam derde**
- **GIVEN** een BewijsItem bevat de naam van een ander persoon (bijv. handhaver, klager)
- **WHEN** de behandelaar dit veld selecteert en "Redigeer" klikt
- **THEN** wordt een RedactieActie aangemaakt met voor/na-waarden, en wordt in de PDF de naam vervangen door een gestandaardiseerde markering met grond

**Scenario 2: before/after vergelijking**
- **GIVEN** een bundle is geredigeerd
- **WHEN** behandelaar "Bekijk redactie-overzicht" opent
- **THEN** wordt een side-by-side weergave getoond van alle wijzigingen voor 4-ogen-controle voor de bundle wordt vrijgegeven

**Scenario 3: redactie niet toegestaan op gegevens verzoeker zelf**
- **GIVEN** behandelaar probeert per ongeluk de eigen naam/adres van de verzoeker te redigeren
- **WHEN** de actie wordt geinitieerd
- **THEN** waarschuwt het systeem "Dit zijn gegevens van de verzoeker zelf — redactie betekent onthouden van het inzagerecht. Doorgaan vereist een AVG-art-23-onderbouwing"

### REQ-AVG-007: Weigering / gedeeltelijke afwijzing met grond

**Scenario 1: gehele weigering**
- **GIVEN** een verzoek raakt aan een lopend strafrechtelijk onderzoek (art. 23 lid 1 sub d)
- **WHEN** behandelaar "Weiger" kiest
- **THEN** wordt een Weigering-record aangemaakt, verplicht onderbouwd met de specifieke uitzonderingsgrond, en wordt de bezwaarprocedure-link toegevoegd

**Scenario 2: gedeeltelijke weigering**
- **GIVEN** scope `["facturatie"]` valt onder fiscale bewaarplicht (geen wissing mogelijk) maar wel inzage
- **WHEN** behandelaar de uitkomst opstelt
- **THEN** kan deze per scope-onderdeel een eigen uitkomst registreren (wel-inzage-niet-wissing), met aparte toelichting

**Scenario 3: verplichte verwijzing naar AP**
- **GIVEN** een weigering wordt verstuurd
- **WHEN** de brief/bundle wordt gegenereerd
- **THEN** moet de standaardtekst de zin bevatten "U kunt een klacht indienen bij de Autoriteit Persoonsgegevens" met directe URL; het systeem blokkeert verzending zonder deze passage

### REQ-AVG-008: AP-escalatie en dossier-overdracht

**Scenario 1: klacht ontvangen**
- **GIVEN** de FG ontvangt een AP-klacht over verzoek AVG-2026-0034
- **WHEN** deze "AP-escalatie" markeert
- **THEN** wordt een volledig dossier-exportpakket samengesteld (verzoek + alle TermijnEvents + BewijsItems + RedactieActies + correspondentie) als ZIP met index, klaar voor verzending

**Scenario 2: AP-rapportage**
- **GIVEN** AP vraagt jaarlijks rapport
- **WHEN** behandelaar het rapport-genereert
- **THEN** worden alle verzoeken in geanonimiseerde aggregatie geexporteerd (aantallen per artikel, doorlooptijden, weigeringspercentages, overschrijdingen)

### REQ-AVG-009: Retentie van het verzoekdossier (5 jaar)

**Scenario 1: dossier blijft 5 jaar**
- **GIVEN** verzoek AVG-2026-0034 is afgerond op 22 april 2026
- **WHEN** retentie-policy wordt toegepast
- **THEN** wordt `retentieTot: 2031-04-22` gezet, dossier blijft beschikbaar in het archief-tabblad maar niet meer in actieve werklijsten, en wordt na 5 jaar automatisch verwijderd

**Scenario 2: aparte retentie voor onderliggende persoonsgegevens**
- **GIVEN** retentie-policy onderscheidt verzoekdossier (5 jaar) en BewijsItems met persoonsdata (max 30 dagen na uitlevering)
- **WHEN** uitlevering afgerond
- **THEN** worden BewijsItem-inhoudvelden gepseudonimiseerd na 30 dagen (alleen metadata blijft), terwijl het verzoekdossier zelf met audit-trail intact blijft

**Scenario 3: vroegtijdige vernietiging niet toegestaan**
- **GIVEN** behandelaar probeert binnen 5 jaar handmatig te verwijderen
- **WHEN** de delete-actie wordt geprobeerd
- **THEN** wordt geweigerd met "Retentie loopt tot {datum} (RvIG-richtlijn)"; alleen FG met audit-onderbouwing kan vervroegde vernietiging activeren

### REQ-AVG-010: DPIA-flag bij patroondetectie

**Scenario 1: piek van vergelijkbare verzoeken**
- **GIVEN** in 30 dagen komen >10 art-17-verzoeken binnen die alle om "verwijdering van marketingdata" vragen
- **WHEN** de wekelijkse analysejob loopt
- **THEN** wordt `dpiaFlag: true` gezet op alle gerelateerde verzoeken en wordt de FG genotificeerd dat een DPIA-review voor de marketing-verwerking aanbevolen is

**Scenario 2: handmatige DPIA-vlag**
- **GIVEN** behandelaar ziet bij intake dat een verzoek wijst op een mogelijk systeemprobleem
- **WHEN** deze de "DPIA-aandacht"-checkbox aanvinkt
- **THEN** wordt de FG genotificeerd en komt het verzoek op de DPIA-review-lijst

**Scenario 3: DPIA-link naar Procest**
- **GIVEN** een DPIA-flag is gezet
- **WHEN** de FG hierop klikt
- **THEN** wordt automatisch een corresponderend Procest-procesverbeter-item aangemaakt, zodat de DPIA-review formeel als procesverbeter-traject wordt opgevolgd

## Standards & Sources

- **AVG / GDPR**: art. 12 (transparantie + termijnen), art. 13-14 (informatieplicht), art. 15 (inzagerecht), art. 16 (rectificatie), art. 17 (vergetelheid), art. 18 (beperking), art. 19 (kennisgevingsplicht), art. 20 (dataportabiliteit), art. 21 (bezwaar), art. 22 (geautomatiseerde besluitvorming), art. 23 (beperkingen), art. 30 (verwerkingsregister), art. 33-34 (datalek-meldingen), art. 35 (DPIA), art. 37-39 (FG).
- **Uitvoeringswet AVG (UAVG)** — Nederlandse uitwerking van afwijkingen en uitzonderingen.
- **Wet Open Overheid (Woo)** — overlap met inzageverzoeken op niet-persoonsgegevens (niet binnen AVG-scope maar workflow lijkt op elkaar).
- **Autoriteit Persoonsgegevens — Handleiding behandeling AVG-verzoeken** (2022) — termijnen, procedures, weigeringsgronden.
- **RvIG-richtlijn dossiervorming BSN-bevragingen** — 5-jaars-bewaarplicht.
- **AP-jaarrapportage-formaat** — gestandaardiseerde rapportage van organisaties met >250 medewerkers.
- **NORA** — controle­baarheid, transparantie, eenmalige uitvraag.
- **NEN-ISO 15489** — records management.
- **Archiefwet 1995** — selectielijst gemeenten/provincies/waterschappen.
- **PAdES-LTV (ETSI EN 319 142)** — Long-Term Validation signatures voor PDF-export.
- **JSON-LD met DCAT-AP-DONL** — voor de machine-leesbare metadata van de bundle.
- **OpenSpec Pipelinq client-management** — gedeelde Contact-definitie.
- **OpenSpec Pipelinq request-management** — gedeelde Verzoek-statemachine.

## Cross-app integration

**OpenRegister (foundation)**: AvgVerzoek, TermijnEvent, BewijsItem, ExportBundle, Weigering en RedactieActie worden gemodelleerd als schemas. De BewijsItem-store kan zeer groot worden; gebruik OpenRegister's pseudonimisering-functie na uitlevering om PII automatisch te wissen terwijl metadata behouden blijft.

**Pipelinq client-management (capability dep)**: Contact-entiteit wordt verrijkt met `lopendeAvgVerzoeken: [...]` zodat in elk Contact-detail direct zichtbaar is welke AVG-verzoeken openstaan. Bij een art. 17-uitkomst (wissing) wordt het Contact zelf afhankelijk van organisatie-instelling ofwel gemarkeerd als "verzocht-wissing" ofwel volledig gepseudonimiseerd.

**Pipelinq request-management (capability dep)**: het AvgVerzoek erft van de generieke Verzoek-statemachine (ingediend → in-behandeling → in-afwachting → afgerond) maar voegt eigen AVG-states toe (`bewijs-verzamelen`, `redactie`, `bundle-genereren`, `wachten-op-verzoeker`, `weigering-opgesteld`).

**BSN-validatie + BRP-lookup (sibling capability)**: bij intake wordt automatisch een BRP-lookup gedaan met vooringevulde doelbinding "behandeling AVG-verzoek art. {X}", zodat de BSN-keten meteen geverifieerd is.

**OpenConnector**: voor bewijsverzameling uit externe bronnen wordt OpenConnector gebruikt; per koppeling moet een "AVG-export-endpoint" worden geregistreerd waar Pipelinq een burger-export kan aanvragen.

**DocuDesk**: rendert PDF-bundle conform organisatie-huisstijl met juridische sjablonen voor de begeleidende brief; onderhoudt template-versies zodat oude verzoeken reproduceerbaar blijven.

**Procest**: DPIA-flags genereren automatisch een Procest-traject; gemaakte verbeteringen sluiten terug naar de oorspronkelijke verzoeken voor traceerbaarheid.

**Talk / mail / Berichtenbox**: voor uitlevering en correspondentie; bundle-download-link wordt nooit als attachment verstuurd maar als veilige URL.

**Nextcloud Notifications + e-mail**: behandelaar krijgt deadline-herinneringen; FG krijgt escalaties en DPIA-flags.

**SIEM / monitoring**: alle TermijnEvents en weigeringsacties kunnen via webhook naar centrale logging worden gestuurd voor onafhankelijke bewaring.

## Target users

- **Behandelaar AVG-verzoeken** (primaire gebruiker): registreert intake, valideert BSN, verzamelt bewijs, redigeert, levert bundle. Belangrijkste UX-zorg: termijnoverzicht in een dashboard met kleur-codering en de mogelijkheid om snel het volgende actie-item op te pakken.
- **Teamlead / coordinator AVG**: ziet werkverdeling, doorlooptijden, risico-overschrijdingen, kan herverdelen tussen behandelaars; verantwoordelijk voor capaciteitsplanning.
- **Functionaris voor Gegevensbescherming (FG)**: monitort patronen, ontvangt DPIA-flags, escalaties, klachten; rapporteert aan AP; heeft alleen-lezen op alle verzoeken.
- **Juridisch medewerker**: ondersteunt complexe weigeringen (art. 23-onderbouwing), behandelt verlengingsbeslissingen en escalaties.
- **Verzoeker / burger** (indirect): krijgt ontvangstbevestiging, eventueel verlengingsnotificatie, en uitlevering via veilige URL; ontvangt bij weigering duidelijke uitleg + verwijzing AP.
- **DPO / privacy officer** (in grotere organisaties): voert de AP-jaarrapportage uit en gebruikt aggregaat-statistieken voor het AVG-jaarverslag.
- **Beheerder / functioneel-applicatiebeheerder**: configureert retentie-instellingen, e-mailtemplates, escalatie-thresholds, bron-koppelingen voor bewijsverzameling.
- **Externe auditor**: krijgt op verzoek toegang tot dossiers (5 jaar bewaard) voor steekproef-controle conform AVG art. 30.
- **Autoriteit Persoonsgegevens (AP)**: ontvangt bij klacht het complete dossier en bij jaarlijkse uitvraag de geaggregeerde rapportage.
- **Procesmanager (via Procest)**: pakt DPIA-flags op als structurele verbeter-trajecten en koppelt resultaten terug.
