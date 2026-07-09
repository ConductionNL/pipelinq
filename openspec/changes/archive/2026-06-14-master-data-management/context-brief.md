---
status: draft
---

# master-data-management

## Purpose

Lever **Master Data Management (MDM)** als kern-capability binnen Pipelinq voor het beheren van cross-app master data — Contact, Account, Product, Vendor — als één **gouden record** (golden record) per entiteit, met **per-attribuut herkomst- en vertrouwens-tracking** per bronsysteem, krachtige **deduplicatie- en merge-tooling**, en gecontroleerde **downstream synchronisatie** naar afnemende applicaties (Shillinq, Decidesk, Procest, Scholiq, OpenCatalogi). MDM is een infrastructurele laag die in elk volwassen MKB+-IT-landschap noodzakelijk wordt zodra meer dan twee transactiesystemen overlappende master data raken: zonder MDM ontstaat onvermijdelijk de "klant Jansen die 4 keer in CRM, 2 keer in boekhouding, 3 keer in offerte-systeem en 1 keer in product-catalogus staat, elk met een licht andere naam, ander adres, ander BTW-nummer", met als gevolg dubbele facturatie, niet-aansluitende reconciliaties, AVG-overtredingen (recht op verwijdering kan niet betrouwbaar worden uitgevoerd), en gemiste cross-sell.

Pipelinq is binnen het Conduction-portfolio de aangewezen plek voor MDM omdat het al de **CRM-data-eigenaar** is (contacten + accounts + leads) en omdat het de **operationele lead** heeft op klantinteractie. Door MDM in Pipelinq te leggen — in plaats van in OpenRegister of in elke afzonderlijke app — wordt voorkomen dat MDM verwordt tot een technisch platform zonder business-context: Pipelinq-gebruikers zien direct de impact van duplicates op hun verkoopcijfers en zijn intrinsiek gemotiveerd om data-kwaliteit te bewaken. OpenRegister levert wel de **schema-laag en de fysieke opslag** (een MDM-register is uiteindelijk gewoon een OR-register met annotaties), maar de **golden-record-bepaling**, **merge-workflow**, **trust-tier-configuratie** en **sync-orchestratie** wonen in Pipelinq.

De spec introduceert vier kernconcepten: (1) **Master Entity** — de unieke gouden-record-identiteit per entiteitstype, met stabiele master-ID die nooit verandert ongeacht merges; (2) **Source Record** — een door een specifieke bron geleverd ruwbestand voor een Master Entity, met eigen lifecycle (kan worden ingetrokken, verbeterd, opnieuw geladen); (3) **Trust Configuration** — per (entiteitstype, attribuut, bron) een vertrouwens-tier (gold / silver / bronze / discard) die bepaalt welk bronwaarde "wint" bij conflict; (4) **Sync Queue** — een gedwongen-volgorde uitleverpipeline naar downstream apps, met retries, dead-letter, en confirmation-callbacks. Daaromheen: **duplicate detection** (deterministische + probabilistische matching), **merge-tooling** (handmatig en geautomatiseerd, altijd reversible), **audit trail** (elke merge en gold-record-mutatie blijft 10 jaar bewaard), en **read-API + sync-API** voor consumerende apps.

Het succescriterium: een Pipelinq-tenant met overlap tussen CRM, Shillinq-boekhouding en Procest-projecten heeft binnen één scherm overzicht van duplicate-candidates, kan ze in een wizard mergen met preview van impact op downstream apps, en ziet vervolgens dat Shillinq's debiteurenadministratie binnen 60 seconden automatisch is bijgewerkt zonder dubbele facturatie of broken-references.

## Data Model

Vijf nieuwe schemas in een nieuw `mdm` register binnen Pipelinq, plus extensies op bestaande registers.

**`master-entity`** is de gouden record. Attributes: `masterId` (UUID, stabiel voor het leven van de entiteit, nooit hergebruikt na merge), `entityType` (contact / account / product / vendor — uitbreidbaar), `goldenRecord` (JSON object met de "winnende" waarden per attribuut), `attributeProvenance` (object: per attribuut `{value, sourceSystem, sourceRecordId, trustTier, lastUpdated, confidence}`), `aliases` (array van vorige master IDs uit merges, voor backward-compat lookups), `mergedFrom` (array — historie van masterIDs die in deze zijn opgegaan), `status` (active / merged-into-other / soft-deleted / quarantined), `mergedIntoMasterId` (FK self, alleen als status=merged-into-other), `dataQualityScore` (decimal 0-1 — computed uit completeness, freshness en agreement van bronnen), `lastReviewedAt` (timestamp), `tags` (array — bijv. "vip-klant", "regulated-supplier"), `gdprNotes` (text — t.b.v. recht-op-verwijdering tracking).

**`source-record`** is een door één bronsysteem geleverd ruwbestand. Attributes: `sourceRecordId` (composite: `{sourceSystem}:{nativeId}`), `sourceSystem` (pipelinq-crm / shillinq-debiteuren / procest-stakeholders / scholiq-leerlingen / decidesk-leden / kvk-api / overheidsorganisatie-register / etc.), `nativeId` (de ID in het bronsysteem), `entityType` (contact / account / product / vendor), `currentMasterEntity` (FK master-entity), `rawAttributes` (JSON — letterlijke bronwaarden), `mappedAttributes` (JSON — na normalisatie/cleansing), `firstSeen` (timestamp), `lastSeen` (timestamp), `lastChange` (timestamp), `confidence` (decimal — vertrouwen in deze specifieke versie, kan dalen bij oude data), `linkageMethod` (deterministic-key / probabilistic-match / manual-assignment), `linkageConfidence` (decimal 0-1), `withdrawn` (boolean — bronsysteem heeft record verwijderd).

**`trust-configuration`** is de configuratie-tabel die per (entiteitstype, attribuut, bron) bepaalt welke trust-tier geldt. Attributes: `entityType`, `attribute` (bijv. "email", "kvkNumber", "vatNumber", "billingAddress"), `sourceSystem`, `trustTier` (gold / silver / bronze / discard), `freshnessDecayDays` (int — na hoeveel dagen wordt tier verlaagd?), `manualOverrideAllowed` (boolean), `rationale` (text), `effectiveFrom` (date). Voorbeeld: voor attribuut `vatNumber` op entityType `account` zou bron `kvk-api` `trustTier=gold` zijn, `shillinq-debiteuren` `silver`, en `pipelinq-crm` `bronze` — bij conflict wint dus KvK altijd.

**`merge-operation`** is de log van elke (semi-)automatische of handmatige merge. Attributes: `id`, `mergedIntoMasterId` (FK), `mergedFromMasterIds` (array), `mergedAt`, `mergedBy` (user — kan "system-auto-merge" zijn), `mergeReason` (duplicate-detected-deterministic / duplicate-detected-probabilistic / manual-bulk / data-stewardship-review / migration), `preMergeSnapshot` (JSON — om reversal mogelijk te maken), `attributeResolutionLog` (array — per attribuut welke bron won en waarom), `downstreamSyncStatus` (per-app status of de merge is doorgepropageerd), `reversible` (boolean — typisch true tot 30 dagen na merge), `reversedAt` (timestamp, optional), `reversedBy` (user, optional).

**`sync-queue-item`** is één downstream-sync-item per app per Master Entity per change. Attributes: `id`, `masterEntity` (FK), `targetSystem` (shillinq / procest / scholiq / opencatalogi / decidesk), `changeType` (create / update / merge / soft-delete / reverse-merge), `payload` (JSON — de uit te wisselen data), `status` (queued / sending / sent / acknowledged / failed / dead-letter), `attemptCount` (int), `lastAttemptAt`, `nextRetryAt`, `errorMessage` (text), `acknowledgedAt`, `acknowledgmentReference` (text — bevestigingsnummer van target system), `priority` (decimal — voor ordening; merges hebben hogere prio dan kleine attribuut-updates).

**Extensies:** Het bestaande Pipelinq `contact`-register krijgt `masterEntityRef` (FK naar `master-entity`) en `isMasterRecord` (boolean — true alleen voor de "canonical" CRM-representatie die de master entity vertegenwoordigt). Vergelijkbare extensies op `account`-register (Pipelinq), `product`-register (Pipelinq + andere apps die catalog gebruiken), en `vendor`-register (typisch in Shillinq).

## Requirements

### Requirement: REQ-MDM-001 Gouden record per Master Entity

Het systeem MOET per Master Entity één gouden record bijhouden waarvan de attribuutwaarden zijn bepaald door de geconfigureerde trust-tiers van bronnen, niet door wie het laatst schreef.

#### Scenario: Conflict op telefoonnummer tussen CRM en KvK

- **GIVEN** een Master Entity voor account "Voorbeeld B.V." met source records uit `pipelinq-crm` (telefoon "020-1234567", `trustTier=bronze`) en `kvk-api` (telefoon "020-7654321", `trustTier=gold`)
- **WHEN** de golden-record-recompute draait
- **THEN** wordt `goldenRecord.phone="020-7654321"` met `attributeProvenance.phone.sourceSystem="kvk-api"`, `trustTier=gold`
- **AND** blijft de CRM-waarde zichtbaar in `source-record` voor audit
- **AND** wordt het downstream-sync queue-item aangemaakt om de wijziging naar afnemers door te zetten

### Requirement: REQ-MDM-002 Deterministische duplicate detection op natuurlijke sleutels

Het systeem MOET dagelijks (of bij elke nieuwe source-record) deterministische duplicaten detecteren op natuurlijke sleutels zoals KvK-nummer, BSN-hash, BTW-nummer, e-mailadres en telefoonnummer.

#### Scenario: Zelfde KvK-nummer in twee Master Entities

- **GIVEN** twee master-entities met respectievelijk masterId=A en masterId=B die beide KvK-nummer "12345678" voeren
- **WHEN** de duplicate detector draait
- **THEN** wordt een merge-candidate gegenereerd met `linkageMethod=deterministic-key`, `linkageConfidence=1.0`
- **AND** verschijnt het in de stewardship-queue van de data-steward voor goedkeuring
- **OF** wordt automatisch gemerged als `manualOverrideAllowed=false` voor KvK-conflicts

### Requirement: REQ-MDM-003 Probabilistische duplicate detection op fuzzy-match

Het systeem MOET ook probabilistische duplicate-detection ondersteunen die fuzzy-match-algoritmen toepast (Levenshtein-afstand, Jaro-Winkler, n-gram TF-IDF) op naam + adres + telefoon, met configureerbare drempels.

#### Scenario: "Jansens Bouw BV" versus "Jansen's Bouw B.V."

- **GIVEN** twee master-entities met naam "Jansens Bouw BV" (postcode 1234AB, telefoon 020-1234567) en "Jansen's Bouw B.V." (postcode 1234AB, telefoon 020-1234567)
- **AND** een `linkageConfidence` drempel van 0.85
- **WHEN** de probabilistische detector draait
- **THEN** wordt een match-candidate gegenereerd met `linkageMethod=probabilistic-match`, `linkageConfidence=0.93` (hoge naam-similarity + zelfde adres + zelfde telefoon)
- **AND** verschijnt in de stewardship-queue voor menselijk besluit (boven 0.95 kan auto-merge worden geconfigureerd)

### Requirement: REQ-MDM-004 Merge-tooling met preview en reversibility

Een merge MOET reversible zijn (binnen een configureerbaar venster, default 30 dagen) en MOET vóór commit een preview tonen van alle wijzigingen aan het gouden record en aan downstream systemen.

#### Scenario: Merge twee account-records met downstream impact

- **GIVEN** een data-steward die twee account-master-entities A en B wil mergen, beide met openstaande facturen in Shillinq
- **WHEN** de merge-wizard wordt geopend
- **THEN** toont het systeem een preview: golden record na merge (welke attributen overleven, met provenance), lijst van downstream sync-items die zullen ontstaan (Shillinq: 2 facturen referenties bijwerken; Procest: 1 project relink), en een waarschuwing dat reversal binnen 30 dagen mogelijk is
- **AND** wordt na bevestiging de merge uitgevoerd, het `merge-operation` record geschreven, en sync-queue-items aangemaakt
- **AND** kan de data-steward binnen 30 dagen "Reverse merge" kiezen, waarna `preMergeSnapshot` wordt teruggezet en reverse-sync-items naar downstream gaan

### Requirement: REQ-MDM-005 Per-attribuut trust-tier configureerbaar

Het systeem MOET per (entiteitstype, attribuut, bronsysteem) trust-tiers configureerbaar maken voor data-stewards, met effectieve datum en motivatie.

#### Scenario: KvK-API wordt geactiveerd als gold voor adressen

- **GIVEN** een nieuwe `trust-configuration` met `entityType=account`, `attribute=billingAddress`, `sourceSystem=kvk-api`, `trustTier=gold`, `effectiveFrom=2026-06-01`
- **WHEN** na 1 juni 2026 een KvK-update binnenkomt voor account "Voorbeeld B.V." met een nieuw adres
- **THEN** wint het KvK-adres het van een ouder shillinq-debiteuren-adres (gold > silver)
- **AND** wordt de wijziging vastgelegd in `attributeProvenance.billingAddress` met `lastUpdated`, `sourceSystem=kvk-api`, `trustTier=gold`
- **AND** ontstaan sync-queue-items om het nieuwe adres naar downstream apps te propageren

### Requirement: REQ-MDM-006 Downstream sync-queue met retries en confirmation

Het systeem MOET wijzigingen in gouden records via een sync-queue per downstream-app afleveren, met automatische retries (exponential backoff) en confirmation-callbacks bij succesvolle verwerking.

#### Scenario: Sync naar Shillinq na merge

- **GIVEN** een succesvolle merge die in Shillinq twee debiteuren-records moet samenvoegen
- **WHEN** de sync-queue-processor start
- **THEN** wordt een `sync-queue-item` met `targetSystem=shillinq`, `changeType=merge` aangemaakt en `status=queued`
- **AND** wordt het item naar de Shillinq sync-API gestuurd via openconnector; bij succes update Shillinq de debiteurenrecords en stuurt een acknowledgment terug
- **AND** wordt `status=acknowledged` met `acknowledgmentReference=<shillinq-bevestiging>` opgeslagen
- **AND** bij falen wordt exponential backoff toegepast (1m, 5m, 30m, 2u, 12u, 24u) tot max 7 dagen, daarna `status=dead-letter` met handmatige interventie vereist

### Requirement: REQ-MDM-007 Data-quality-score per Master Entity

Elke Master Entity MOET een `dataQualityScore` (0-1) hebben die wordt berekend uit completeness (verplichte velden ingevuld?), freshness (recent geactualiseerd?), en agreement (bronnen het eens?).

#### Scenario: Lage quality score voor verouderd account

- **GIVEN** een Master Entity voor account "Oude Klant B.V." waarvan KvK 8 maanden geleden voor het laatst is opgehaald (freshness laag) en e-mail ontbreekt (completeness laag) en CRM zegt telefoon X maar Shillinq zegt telefoon Y zonder dat gold-bron actief is (agreement laag)
- **WHEN** de scoring draait
- **THEN** wordt `dataQualityScore=0.42` getoond met breakdown: completeness 0.65, freshness 0.30, agreement 0.55
- **AND** verschijnt het account op het data-quality-dashboard met `quality-score < 0.6` filter
- **AND** kan de data-steward direct een herstelactie starten (KvK refresh, conflict resolution wizard)

### Requirement: REQ-MDM-008 Audit-trail per merge en gold-record-mutatie

Het systeem MOET voor elke merge én voor elke gold-record-attribuut-mutatie een audit-trail vastleggen die minimaal 10 jaar bewaard blijft (AVG / fiscale bewaarplicht).

#### Scenario: Audit-trail bij accountantscontrole

- **GIVEN** een accountant die in 2030 wil verifiëren wie wanneer welk bedrag aan welk account heeft gefactureerd
- **WHEN** de accountant het account "Voorbeeld B.V." opent
- **THEN** toont het systeem de volledige master-entity historie: alle merges (welke records gingen op in deze master, wanneer, door wie, met welke onderbouwing), alle gold-record-attribuut-wijzigingen (datum, oude waarde, nieuwe waarde, sourceSystem, user), alle downstream sync-events
- **AND** is deze history exporteerbaar in PDF en machine-leesbaar JSON voor accountantsdossier
- **AND** kan een GDPR-data-subject access request volledig worden onderbouwd

### Requirement: REQ-MDM-009 AVG-recht-op-verwijdering tegen gouden record

Het systeem MOET het AVG-recht-op-verwijdering correct uitvoeren: bij een succesvol verzoek wordt de Master Entity gesoftdeleted, alle source-records worden geanonimiseerd, downstream apps krijgen een soft-delete sync-item, en de audit-trail wordt geanonimiseerd (maar de gebeurtenisstructuur blijft voor wettelijke aantoonbaarheid).

#### Scenario: Recht-op-verwijdering voor contact "Pietje Puk"

- **GIVEN** een AVG-verzoek van een natuurlijk persoon "Pietje Puk" tot verwijdering, contactgegevens in 5 bronsystemen
- **WHEN** de data-steward het verzoek na verificatie goedkeurt
- **THEN** wordt de Master Entity gesoftdeleted, source-records geanonimiseerd (naam/adres/contact → "[verwijderd]"), en sync-queue-items naar alle 5 downstream systemen aangemaakt
- **AND** ontvangen downstream apps via openconnector een soft-delete-instructie
- **AND** wordt de audit-trail geanonimiseerd maar de "verwijdering uitgevoerd op datum X door user Y na AVG-verzoek Z" blijft voor wettelijke verantwoording bewaard
- **AND** wordt na 30 dagen (cooling-off voor reversal in geval van fout) de Master Entity hard verwijderd

### Requirement: REQ-MDM-010 Read-API voor downstream apps

Het systeem MOET een read-API publiceren waarmee downstream apps het gouden record kunnen ophalen op masterId, op aliasId (oude masterId van vóór merge), of op natuurlijke sleutel (KvK, BSN-hash, e-mail).

#### Scenario: Procest haalt account-master op KvK

- **GIVEN** Procest dat bij nieuw project een stakeholder-organisatie wil koppelen op basis van KvK-nummer "12345678"
- **WHEN** Procest de Pipelinq MDM read-API aanroept `GET /api/mdm/master?type=account&kvk=12345678`
- **THEN** krijgt Procest het golden record terug met `masterId`, alle gold-tier-attributen, `dataQualityScore`, en links naar onderliggende source-records
- **AND** registreert Procest de masterId op het project; bij toekomstige merges in Pipelinq krijgt Procest via sync-queue de update

### Requirement: REQ-MDM-011 Sync-naar-OpenRegister van gouden record per entityType

Het systeem MOET het gouden record per entityType beschikbaar maken in OpenRegister als canonieke schema-instance, zodat apps die het OpenRegister-pad gebruiken (i.p.v. de directe MDM-API) ook altijd de juiste data zien.

#### Scenario: Synchronisatie naar OR account-schema

- **GIVEN** een wijziging in de gold-record-attributen van een Master Entity account
- **WHEN** de sync-naar-OR draait
- **THEN** wordt de bijbehorende object in het OR `account`-schema bijgewerkt met de nieuwe gold-tier-attributen
- **AND** wordt `masterEntityRef` in dat OR-record gezet, zodat apps die via OR werken impliciet altijd op de master uitkomen
- **AND** worden oude (pre-merge) OR-records gemerkt als `merged-into`, voor backward-compat queries

### Requirement: REQ-MDM-012 Conflict-resolution-wizard voor data-stewards

Het systeem MOET een conflict-resolution-wizard bieden waarmee data-stewards expliciet kunnen kiezen welke bronwaarde wint, met onderbouwing en met effect op de trust-configuratie (kunnen ze direct kiezen "altijd in dit geval"-regel toevoegen).

#### Scenario: Stewardship beslist BTW-nummer-conflict

- **GIVEN** een Master Entity met conflicterend BTW-nummer uit bron-pipelinq-crm (NL123456789B01) en shillinq-debiteuren (NL123456789B02)
- **WHEN** de data-steward de conflict-wizard opent
- **THEN** toont het systeem beide waarden met bronlast-update-tijdstip en wie laatst wijzigde
- **AND** kan de steward "Shillinq wint" kiezen met motivatie "Shillinq bron geverifieerd via VAT-validatie EU-service"
- **AND** wordt de wizard-keuze toegepast op deze entity én optioneel als regel toegevoegd aan `trust-configuration` (BTW-nummer: shillinq > pipelinq-crm)

## Standards & Sources

Primair: **Gartner Critical Capabilities for Master Data Management Solutions** (jaarlijks rapport, definieert MDM-functionele baseline), **TDWI Master Data Management Body of Knowledge**, **DAMA DMBoK 2** (Data Management Body of Knowledge) hoofdstuk 10 Reference and Master Data. Voor data-quality-scoring: **ISO 8000-8 Data Quality — Information and Data Quality: Concepts and Measuring** en **ISO 25012 Data Quality Model**. Voor matching-algoritmen: Fellegi-Sunter probabilistic record linkage (1969), Levenshtein-Damerau distance, Jaro-Winkler similarity, n-gram TF-IDF cosine similarity. Open-source matching-engine inspiratie: Apache Solr met levenshtein-plugin, Splink (UK Ministry of Justice MDM matching library), Dedupe.io.

AVG-/privacy-aspecten: **AVG art. 17** (recht op gegevenswissing), **AVG art. 15** (inzagerecht), **AVG art. 20** (recht op dataportabiliteit), **AVG art. 30** (verwerkingsregister), **Autoriteit Persoonsgegevens richtsnoer** *Identificeren en authenticeren* voor natuurlijk-persoon-matching. Voor BSN-hashing: NORA-standaarden, BSN-koppelregister-richtlijnen. Voor KvK-koppeling: **Handelsregisterwet 2007** en KvK Dataservice. Voor BTW-validatie: EU VIES VAT validation API, Belastingdienst BTW-controle. Voor financiële AVG: NBA-handreiking *AVG en accountancy*.

Conduction-interne specs: **OpenRegister ADR-005** (i18n keys vs identifiers), **ADR-019** (Pluggable Integration Registry), **ADR-024 Tier-4** (frontend via nextcloud-vue), **ADR-031** (declaratieve composities boven PHP-services), **ADR-032** (config-kind changes). Pipelinq specs: `pipelinq-or-register-resolver`, `contact-relationship-mapping`, `klantbeeld-360` (consumer van MDM voor 360-view), `pipeline-insights`.

## Cross-app integration

- **openregister** — fysieke schema's en object-opslag voor `master-entity`, `source-record`, `trust-configuration`, `merge-operation`, `sync-queue-item`. Reverse sync van gouden record naar OR-schema's voor entityTypes (zie REQ-MDM-011).
- **openconnector** — outbound sync naar downstream apps (Shillinq, Procest, Scholiq, OpenCatalogi, Decidesk) via REST adapters; inbound feeds van externe bronnen (KvK Dataservice, VIES VAT, Handelsregister, eventueel BSN-koppelregister via DigiKoppeling).
- **shillinq** — consumeert MDM voor debiteuren- en crediteuren-master; bij merge worden facturen en betalingen geherlinkt. Boekhouding is een primaire datakwaliteitsbeoordelaar (foutieve master leidt direct tot fout in jaarrekening).
- **procest** — consumeert MDM voor stakeholder- en organisatie-koppeling op projecten; bij merge worden project-relaties bijgewerkt.
- **scholiq** — consumeert MDM voor leerling-master (waar privacy-sensitiviteit het hoogst is); AVG-recht-op-verwijdering pad gaat speciaal door scholiq omdat onderwijsdata aparte bewaartermijnen heeft.
- **opencatalogi** — consumeert MDM voor organisatie- en product-master (product-catalogus vereist consistente vendor-info).
- **decidesk** — consumeert MDM voor lid- en organisatie-master in besluitvormingsprocessen; bij merge worden besluitstemmen en deelname-historie geherlinkt zonder integriteitsverlies.
- **docudesk** — bewaart juridische onderbouwing van merges (bij grote merges met financiële impact), AVG-verwijderingsverzoeken en hun afhandeling.
- **launchpad** — publiceert MDM-quality-dashboard: data-quality-score-trend, aantal openstaande duplicate-candidates, sync-queue-health (queued/sent/failed), top-10 master-entities met laagste kwaliteit, AVG-verzoek-doorlooptijd.

## Target users

Primair de **Data Steward** of **Data Quality Manager** — een rol die in MKB+ vaak gecombineerd is met financieel-controllerschap of CRM-beheerder, maar in grotere organisaties als dedicated functie bestaat. Deze persoon onderhoudt de trust-configuratie, beslist over duplicate-merge-candidates die boven de auto-merge-drempel niet uitkomen maar wel onder probabilistische match-drempel zitten, en bewaakt de data-quality-trends. In sectoren met sterke regulering (financiële dienstverlening, zorg, onderwijs) is dit doorgaans een full-time rol.

Secundair: de **CRM-eigenaar / commercieel directeur** (gebruikt MDM-rapporten om sales-pipeline-data te controleren op duplicates en blind spots), de **financieel controller** (gebruikt de master-data-aansluiting tussen CRM en boekhouding voor reconciliaties — een verkeerd gemerged debiteur kan tot dubbel-facturatie leiden), de **AVG functionaris gegevensbescherming (FG)** (gebruikt het AVG-pad voor verwijderverzoeken en het inzagerecht), de **architectuur / IT-afdeling** (configureert nieuwe sync-targets, koppelt nieuwe bronsystemen, monitoort sync-health).

Tertiair: elke **eindgebruiker** in elke aangesloten app die impliciet profiteert van een schoon master-data-landschap zonder dat ze direct met MDM-tools werken — een verkoper in Pipelinq die "klant Jansen" zoekt en één resultaat krijgt in plaats van vijf, een boekhouder in Shillinq die een betaling kan reconciliëren omdat de debiteuren-master matched, een projectleider in Procest die een stakeholder-organisatie correct koppelt aan een opdracht. De **externe accountant** is een tertiaire gebruiker die de master-data-audit-trail consulteert bij specifieke onderzoeken (typisch: dubbel-facturatie-onderzoek, transfer-pricing-verificatie tussen groepsentiteiten, AVG-compliance-toets).

Strategische waarde voor Pipelinq: MDM is dé technische capability waar moderne ERP-suites (NetSuite, Salesforce, Microsoft Dynamics) tegen aanlopen zodra ze door tenant-groei boven de 100-FTE drempel komen. Door MDM native in de Conduction-suite te leveren — met OpenRegister als opslag, openconnector als sync-rail, en Pipelinq als beheers-UI — wordt een dure dedicated MDM-product-categorie (Informatica MDM, Riversand, Reltio: licentiekosten EUR 50K-500K per jaar) overbodig voor MKB+-tenants die anders gedwongen zouden zijn een specialist tool aan te schaffen. Het levert tegelijk een platform-effect: hoe meer Conduction-apps aansluiten op de MDM, hoe waardevoller MDM wordt en hoe sterker de lock-in op de gehele Conduction-suite.
