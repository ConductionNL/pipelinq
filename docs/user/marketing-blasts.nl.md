<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Marketing-blasts — gebruikershandleiding

Deze gids loodst marketing-managers door de volledige Pipelinq
marketing-campagneflow: het bouwen van een regel-gebaseerd segment,
het schrijven van een compliant template, het inplannen en versturen
van de blast, het draaien van een A/B-variantentest, en het bewaken
van levering en omzetattributie na de verzending.

> De Engelse versie van deze gids staat op
> [`marketing-blasts.md`](marketing-blasts.md).

## In één oogopslag

| Wilt u … | Open dan … |
| --- | --- |
| Een doelgroep definiëren | **Marketing → Segments** |
| Een e-mail/SMS-tekst schrijven | **Marketing → Templates** |
| Een campagne versturen | **Marketing → Blasts → New blast** |
| Een lopende verzending volgen | **Marketing → Blasts → (uw blast) → Monitor** |
| Resultaten over campagnes vergelijken | **Marketing → Blast performance** |

Alle vijf de schermen staan onder de **Marketing**-groep in de
hoofdnavigatie van Pipelinq. Ze zijn afgeschermd op de marketing-rol
(`pipelinq-marketing`); gebruikers zonder die rol zien de groep niet.

## 1. Maak een segment met de regelbouwer

Een **Segment** is een levende, regel-gebaseerde query — geen
bevroren lijst. Bij elke verzending wordt het segment opnieuw
geëvalueerd tegen de contactgegevens, zodat nieuw toegevoegde of
nieuw kwalificerende contacten automatisch meegenomen worden.

### 1.1 Open de segmentbouwer

1. Ga naar **Marketing → Segments**.
2. Klik rechtsboven op **New segment**.
3. Geef het segment een duidelijke naam (bijv. *Gemeente prospects —
   Zuid-Holland*). De naam verschijnt in de wizard **New blast** en
   op het performance-dashboard.
4. Kies het **entiteittype** — *Contact* of *Customer*. De
   beschikbare velden in de regelbouwer veranderen mee.

### 1.2 Bouw de regelboom

De regelbouwer laat u **AND**- en **OR**-groepen van losse predicaten
samenstellen. Een predicaat heeft drie delen:

- **Veld** — een eigenschap van de gekozen entiteit (bijv. `tags`,
  `country`, `lifecycle_stage`).
- **Operator** — `equals`, `notEquals`, `in`, `notIn`, `contains`,
  `gt`, `lt`, `gte`, `lte`, `between`, `exists`, `notExists`.
- **Waarde** — getypt tegen het JSON-schema-type van het veld. De
  bouwer weigert combinaties die niet matchen (u kunt bijvoorbeeld
  geen `gt` toepassen op een string-veld).

Groepen kunnen genest worden: bijv. `(tags contains "vip" AND country
= "NL") OR lifecycle_stage in ["customer", "evangelist"]`.

### 1.3 Schat de doelgroep

Klik op **Estimate size**. Pipelinq evalueert de regelboom tegen de
huidige contact-/klantenset en geeft een telling terug. De schatting
wordt een uur gecached (instellingsafhankelijk) om de register niet
te belasten bij snel bewerken-opslaan. Klik **Refresh** om de cache
te omzeilen.

U kunt ook **Preview members** klikken om de eerste vijftig matchende
contacten te bekijken — handig om te controleren of de regels doen
wat u verwacht voordat u naar de hele doelgroep verstuurt.

### 1.4 Sla het segment op

Klik **Save**. Opgeslagen segmenten verschijnen in de segmentlijst en
zijn te kiezen in de wizard **New blast**. Eén segment kan in
meerdere blasts hergebruikt worden.

> **Tip.** Houd regelbomen klein en herbruikbaar. *"Duitstalige
> klanten"* en *"VIP-klanten"* als twee segmenten combineren beter
> dan één *"Duitstalige VIP-klanten"*-megasegment.

## 2. Maak een compliant template

Een **Template** is de body van een marketing-bericht. Pipelinq
ondersteunt twee kanalen:

- **E-mail** — HTML- en plain-text-body met onderwerpregel.
- **SMS** — één tekstbody, 160 tekens geconcateneerd.

Beide kanaaltypen forceren compliance-checks voordat de template
opgeslagen of in een blast gebruikt mag worden (zie *Compliance-eisen*
hieronder).

### 2.1 Open de template-editor

1. Ga naar **Marketing → Templates**.
2. Klik **New template**.
3. Kies het **kanaal** (e-mail of SMS).
4. Geef de template een naam (bijv. *Q4 Outreach — Gemeenten*).

### 2.2 Schrijf de body

Het body-veld accepteert standaard merge-velden tussen dubbele
accolades:

- `{{contact.firstName}}` — de voornaam van de ontvanger.
- `{{contact.organization}}` — de organisatie van de ontvanger
  (indien bekend).
- `{{unsubscribe_url}}` — de unieke uitschrijflink voor deze
  ontvanger (verplicht — zie *Compliance-eisen*).
- `{{view_in_browser_url}}` — de publieke "bekijk in browser"-link.

Voor **e-mail** vult u daarnaast het veld **Subject**. Een
onderwerpregel langer dan 78 tekens wordt gemarkeerd maar niet
geblokkeerd.

### 2.3 Compliance-eisen

Pipelinq dwingt onderstaande eisen af bij elke save. De template-editor
markeert elke eis en weigert opslaan zolang er iets ontbreekt:

| Kanaal | Eis | Waarom |
| --- | --- | --- |
| E-mail | Merge-veld `{{unsubscribe_url}}` in de body | CAN-SPAM §316.5(a)(3), AVG art. 7 lid 3 |
| E-mail | Fysiek afzenderadres (straat + stad + land) | CAN-SPAM §316.5(a)(5) |
| E-mail | List-Unsubscribe-header (automatisch toegevoegd) | RFC 8058 one-click unsubscribe |
| SMS | `STOP`-keywordfooter (bijv. *"Reply STOP to opt out"*) | TCPA §227(b), AVG art. 7 lid 3 |
| Beide | Afzenderidentiteit (organisatienaam) | CAN-SPAM §316.5(a)(4), AVG art. 13 |

Het **organisatieadres** en de **standaard afzenderidentiteit**
worden eenmaal per instance ingesteld door een beheerder onder
**Beheerinstellingen → Marketing → Afzenderidentiteit**.

### 2.4 Preview en opslaan

Klik **Preview** om de template te renderen met sample-contactdata.
De preview toont de merge-veld-substitutie, opmaak en het renderen
van de uitschrijflink. Klik **Save** als u tevreden bent. Opgeslagen
templates verschijnen in de wizard **New blast**.

## 3. Een blast inplannen en versturen

Een **Blast** is het versturen van één template naar één segment via
één kanaal, optioneel ingepland voor een toekomstig moment en
optioneel gesplitst in A/B-varianten.

### 3.1 Open de wizard New blast

Ga naar **Marketing → Blasts** en klik **+ New blast**. De wizard
loodst u door zes stappen. U kunt vrij vooruit en terug; de blast
wordt als **draft** aangemaakt op de laatste stap.

### 3.2 Stap 1 — Naam

Geef de blast een omschrijvende naam (bijv. *Q4 Outreach —
Gemeenten*). De naam staat in de blastlijst en op het
performance-dashboard.

### 3.3 Stap 2 — Segment

Kies een van de segmenten uit sectie 1. De wizard toont de geschatte
doelgroepgrootte zodat u nog een sanity check heeft voordat u verder
gaat.

### 3.4 Stap 3 — Template

Kies een template uit sectie 2. De wizard filtert templates op het
kanaal dat u in stap 4 kiest, dus is de lijst leeg? Ga terug en
controleer of u een template voor het gekozen kanaal heeft.

### 3.5 Stap 4 — Kanaal

Kies **Email** of **SMS**. Heeft uw instance meer dan één provider
ingericht (bijv. zowel SendGrid als SES voor e-mail), kies dan ook de
**Connector source** — de provider die de levering daadwerkelijk
verzorgt. Leeg laten valt terug op de standaard.

### 3.6 Stap 5 — Plannen

Vul een **Send at**-datum-tijd in. De blast schakelt automatisch van
`scheduled` naar `sending` op dat moment (de dispatch-loop draait
elke vijf minuten). Leeg laten verstuurt direct bij submit op stap 6.

### 3.7 Stap 6 — A/B-split (optioneel)

Vink **Run an A/B variant test** aan om de doelgroep over twee
templatevarianten te verdelen. Het splitpercentage is het aandeel van
de doelgroep dat **variant A** ontvangt; de rest krijgt variant B.
Kies de tweede template in het variant-slot dat verschijnt.

De wizard voert bij submit een **consent preflight** uit:

- Het segment wordt opnieuw geëvalueerd voor de actuele ontvangerlijst.
- Het consent-record van elke ontvanger voor het gekozen kanaal wordt
  gecontroleerd.
- Ontbreekt consent voor een of meer ontvangers, dan verschijnt de
  dialoog **Missing consent** met drie opties:
  - **Cancel** — niet versturen, terug naar de wizard.
  - **Request consent** — start een aparte consent-aanvraag-flow voor
    de contacten zonder consent; de blast blijft een draft.
  - **Skip and send** — sluit de contacten zonder consent uit en
    verstuur naar de rest. De uitgesloten contacten staan in het
    leveringslog van de blast voor audit.

Bevestigt u, dan wordt de blast aangemaakt als `scheduled` (bij een
toekomstig moment) of gaat direct over naar `sending`.

## 4. A/B-test-flows

Pipelinq ondersteunt één A/B-split per blast: twee templatevarianten,
één segment, één kanaal. De split staat op stap 6 van de wizard
**New blast**.

### 4.1 Hoe de split werkt

Zodra de blast naar `sending` gaat, partitioneert de dispatcher de
ontvangerlijst deterministisch:

- Van het contact-ID wordt een hash bepaald.
- De hash wordt gemapt op een bucket in `[0, 100)`.
- Ontvangers in `[0, splitPercent)` krijgen variant A; ontvangers in
  `[splitPercent, 100)` krijgen variant B.

Dezelfde ontvanger valt bij her-evaluatie altijd in dezelfde bucket,
dus een gepauzeerde/hervatte blast wijst consistent toe.

### 4.2 De vergelijking lezen

Zodra beide varianten **minstens 100 leveringen elk** gestuurd
hebben, wordt het tabblad **A/B Testing** van **Blast performance**
ontgrendeld. Het toont:

- **Open rate** van A en B naast elkaar.
- **Click rate** van A en B naast elkaar.
- Een marker voor **statistische significantie** — groen als een
  chi-kwadraattest de nulhypothese verwerpt bij *p* < 0,05, grijs
  zo niet. Onder 100 leveringen per arm toont de marker
  *insufficient sample*.

### 4.3 Acteren op het resultaat

De winnende variant wordt niet automatisch gepromoveerd — winnaar
kiezen is een menselijk besluit. Het gangbare patroon: kloon de
winnende template naar een nieuwe "champion"-template en gebruik die
als default voor de volgende blast.

## 5. Levering en attributie bewaken

### 5.1 Live verzending — Blast monitor

Open vanuit de blastlijst een blast en klik **Monitor** (of open
direct **Marketing → Blasts → (uw blast) → Monitor**). Het
monitorscherm toont:

- De huidige **status** van de blast — `draft`, `scheduled`,
  `sending`, `sent`, `paused`, `canceled`.
- Live **leveringstotalen** — in wachtrij, verzonden, afgeleverd,
  bounced, uitgeschreven, klacht.
- Een **per-levering-log** — elke ontvanger met status en tijdstempel.

De totalen blijven actueel via webhook-ingest vanuit de ingerichte
provider (SendGrid, SES, Twilio). Webhook-events worden
HMAC-geverifieerd voordat ze geaccepteerd worden.

#### Eigen open/click-tracking (basis-tier)

Provider-webhooks zijn standaard de bron van open/click-telemetrie,
maar vereisen een webhook-capable provider. Verstuurt uw instance via
een gewone per-tenant connector zonder webhook-ondersteuning? Zet dan
**eigen tracking** aan zodat open- en klikpercentages toch gevuld
worden:

- Een beheerder zet **`blast.first_party_tracking`** aan onder **Admin
  settings → Pipelinq**. Standaard **uit** — staat de instelling uit,
  dan gedragen rendering en levertelemetrie van blasts zich exact als
  voorheen.
- Staat de instelling aan, dan wordt elke link in een verzonden blast
  herschreven naar een ondertekende Pipelinq-redirect en wordt een
  1×1-trackingpixel toegevoegd aan de e-mailbody. Beide gebruiken
  opaque, HMAC-ondertekende tokens (standaard 90 dagen geldig,
  door de beheerder aan te passen) die alleen het leverings-id
  bevatten — er verschijnt nooit een e-mailadres, naam of ander
  persoonsgegeven in een trackingURL.
- Opens en clicks die zo geregistreerd worden, vullen **dezelfde**
  velden (`openedAt`, `firstClickAt`, `status`) en dezelfde
  totalenoptelling als provider-webhooks, dus de tabbladen Overview en
  A/B testing hieronder werken identiek, ongeacht welke bron de
  gebeurtenis registreerde. Beide bronnen tegelijk gebruiken leidt niet
  tot dubbeltellingen — elke gebeurtenis wordt één keer per levering
  geregistreerd.
- **Bewaartermijn**: eigen open/click-gebeurtenissen worden opgeslagen
  op de bestaande `blastDelivery`-rij (geen apart eventlog) en worden
  dus precies zo lang bewaard als de bijbehorende blast/levering — het
  verwijderen of archiveren van de blast verwijdert ook de
  bijbehorende trackingdata.
- **Privacy**: de pixel en redirect registreren nooit IP-adres of
  user-agent; de unsubscribe-link en het verplichte fysieke-adresblok
  worden nooit herschreven, dus uitschrijven en compliance-rapportage
  blijven onaangetast door het aanzetten van deze instelling.

#### Rapporteren aan Portaliq-verkeer

Draait u naast Pipelinq een portaal in Portaliq? Dan ziet u mailopens
en -klikken naast sitebezoeken in de verkeersrapporten van dat portaal.

- Een beheerder zet **`blast.traffic_portal`** onder **Admin settings,
  Pipelinq** op de portaalslug in Portaliq. Laat de instelling leeg om
  mailtracking binnen Pipelinq te houden. Leeg is de standaard.
- Elke geregistreerde open of klik wordt dan ook aan dat portaal
  gemeld als `email_open`- of `email_click`-verkeersevent. Het event
  bevat de blast, de contactreferentie, de campagnenaam en de
  aangeklikte link. Het bevat nooit een e-mailadres, IP-adres of
  user-agent.
- De blastlevering in Pipelinq blijft leidend. De melding loopt pas na
  het wegschrijven van de levering. Is Portaliq niet geïnstalleerd, of
  mislukt de melding, dan wordt de open of klik hier gewoon
  geregistreerd en blijven pixel en redirect werken.
- Portaliq koppelt mailverkeer aan een campagne, niet aan een persoon.
  Een mailopen en een sitebezoek horen alleen bij elkaar als het
  sitebezoek dezelfde campagneparameters draagt.

Een lopende verzending stoppen? Klik **Cancel**. Alle nog niet
verstuurde leveringen worden afgebroken; reeds aan de provider
overgedragen berichten gaan door. De blast gaat naar `canceled`.

### 5.2 Geaggregeerde performance — Blast performance

**Marketing → Blast performance** is het analytics-scherm na de
verzending. Het heeft drie tabbladen:

#### Tabblad Overview

Een sorteerbare tabel met alle blasts en hun KPI's:

- **Open rate** = geopend ÷ afgeleverd.
- **Click rate** = geklikt ÷ afgeleverd.
- **Unsubscribe rate** = uitgeschreven ÷ afgeleverd.
- **Bounce rate** = bounced ÷ verzonden.
- **Complaint rate** = klachten ÷ afgeleverd (spam-meldingen).

Sorteer op een kolom om uw beste (of slechtste) campagnes te vinden.

#### Tabblad A/B testing

De zij-aan-zij-vergelijking uit sectie 4.2.

#### Tabblad Attribution

Per blast toont **Attribution**:

- **Attributed deal count** — het aantal CRM-deals waarvan de eerste
  link naar de klant een klik op deze blast was.
- **Attributed revenue (EUR)** — de gesommeerde waarde van die
  toegekende deals.

Attributie gebruikt een **first-click**-model met een instelbaar
attributievenster (standaard 30 dagen). Klikt een ontvanger op een
getrackte link, dan koppelt Pipelinq de klik aan de blast. Wordt de
organisatie van die ontvanger binnen het venster klant op een deal,
dan wordt die deal aan de blast toegerekend.

Het attributievenster wordt per instance ingesteld door een beheerder
onder **Beheerinstellingen → Marketing → Attributievenster**.

### 5.3 Resultaten exporteren

Zowel het tabblad **Overview** als **Attribution** biedt een knop
**Download CSV** voor offline rapportage. De download bevat één rij
per blast en één kolom per KPI.

## Probleemoplossing

| Symptoom | Wat te controleren |
| --- | --- |
| Blast blijft `scheduled` voorbij de verzendtijd | De dispatch-loop (`BlastSendJob`) draait elke 5 minuten. Ziet u na 10 minuten nog niets? Vraag uw beheerder het background-job-log te checken. |
| Veel bounces vlak na verzending | Uw contactlijst bevat mogelijk verouderde adressen. Gebruik **Delivery log → bounced** om ze te identificeren en op te schonen. |
| Open rate erg laag | Opens worden gemeten via een 1×1 trackingpixel; mailclients die remote afbeeldingen blokkeren (vooral Outlook op Windows) rapporteren onder. Click rate is een betrouwbaardere indicator. |
| Attribution staat op nul | Attributie hangt af van click tracking + de klant op de deal die matcht met de organisatie van de blast-ontvanger. Controleer of click tracking aanstaat op de links in de template en of de klant op de deal correct gekoppeld is. |
| Groep **Marketing** ontbreekt in de navigatie | U zit niet in de gebruikersgroep `pipelinq-marketing`. Vraag uw beheerder u toe te voegen. |

## Zie ook

- Beheerinstellingen voor marketing — **Beheer → Instellingen →
  Marketing** (afzenderidentiteit, default provider,
  attributievenster).
- De klantportaal-gids voor de uitschrijfflow aan de
  ontvangerskant: [`portal-guide.md`](portal-guide.md).
