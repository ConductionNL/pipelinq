status: draft

# Loyalty Program

## Purpose

Provide a loyalty-programme capability inside pipelinq so that retailers, horeca, dienstverleners, and webshops running on pipelinq can issue, manage, and redeem loyalty points, run tier-based recognition programmes, issue and redeem gift cards, and report on programme performance. Today pipelinq covers pos-transactions, klantbeeld-360, voorraad, and basic kortingen, but customer-loyalty mechanics either require an external SaaS (LoyaltyLion, Smile.io, Piggy) integrated through openconnector or a custom build per klant. That works for the largest klanten but is overkill for the MKB segment that wants a stempelkaart-vervanger op de telefoon, and it leaves customer-identifying data scattered across systems.

This spec introduces a native loyalty engine. A programme defines its own points-rules (1 punt per €1, dubbele punten op dinsdag, x punten bij eerste aankoop, x punten bij een review, x punten op verjaardag), tier-rules (zilver/goud/platina drempels met automatisch herclasseren per jaar of rolling-12-months), expiry policies (punten vervallen na 12 maanden inactiviteit), and redemption options (korting in de winkel, gratis product, gift card, externe partner). Every customer has a point-ledger that is append-only so that every credit and debit is traceable. Gift cards are a separate but related capability: issue (with serial number, balance, expiry), redeem at POS, partial redemption tracking, refunds. Reporting covers programme economics (kosten als percentage van omzet, redemption rate, breakage), customer value (CLV pre- en post-loyalty), and tier distribution.

The engine is multi-programme so a holding met meerdere merken kan per merk een programma draaien, met optionele cross-brand puntenpool.

## Data Model

**LoyaltyProgramme**: programmeId, naam, merk, beschrijving, startdatum, einddatum, status (concept, actief, gepauzeerd, beeindigd), valuta, taal, termsUrl, brandingProfileId.

**PointsRule**: ruleId, programmeId, naam, trigger (purchase, signup, birthday, review, referral, visit, manual), conditie (JSON: filter op productcategorie, klantsegment, dag/tijd, kanaal), formule (vast aantal, percentage van bedrag, gestaffeld), maxPerKlantPerPeriode, geldigVan, geldigTot, prioriteit.

**TierRule**: tierId, programmeId, naam, sequence, drempelType (lifetimePoints, rollingPoints12m, jaarlijkseSpend), drempelWaarde, benefits[] (JSON: extra punten multiplier, gratis verzending, exclusieve aanbiedingen), upgradeBeleid (immediate, end_of_period), downgradeBeleid.

**KlantLoyaltyAccount**: accountId, klantId (FK klantbeeld-360), programmeId, currentBalance, lifetimePoints, currentTierId, tierBehaaldOp, tierGeldigTot, status (actief, geblokkeerd, opgezegd), aangemaaktOp.

**PointsLedgerEntry** (append-only): entryId, accountId, type (credit, debit, expiry, adjustment, transfer), aantal, balansNa, brondocument (transactionId, giftCardId, manualReason), regelId (FK PointsRule), timestamp, verwerktDoor.

**RedemptionOption**: optionId, programmeId, naam, kostenInPunten, beloningType (discount, free_product, gift_card, partner_voucher), beloningWaarde, voorraad (optioneel), geldigVan, geldigTot, perKlantLimiet.

**Redemption**: redemptionId, accountId, optionId, transactionId (optioneel), puntenBesteed, beloningCode, status (gereserveerd, gebruikt, vervallen, geannuleerd), aangemaaktOp, gebruiktOp.

**GiftCard**: giftCardId, programmeId, serial (uniek), pin (hashed), initialeBalans, currentBalans, valuta, status (issued, active, depleted, expired, blocked), uitgegevenOp, uitgegevenAan, vervaltOp, kanaal (purchased, promotional, refund).

**GiftCardTransaction**: txId, giftCardId, type (issue, redeem, partial_redeem, refund, top_up, block), bedrag, balansNa, posTransactionId, timestamp, verwerktDoor.

## Requirements

### REQ-LOY-001: Programma activeren
GIVEN een concept-programma met minimaal één PointsRule en één RedemptionOption, WHEN de beheerder het programma activeert, THEN het systeem MUST de configuratie valideren (geldige datums, geen overlappende rules met conflicterende formules, redemption options haalbaar binnen verwachte puntensaldo), MUST de status naar actief zetten, en MUST een audit-record schrijven.

### REQ-LOY-002: Punten toekennen bij transactie
GIVEN een actief programma met purchase-trigger rules, WHEN pipelinq een pos-transaction afrondt voor een geïdentificeerde klant met een actief LoyaltyAccount, THEN het systeem MUST alle van toepassing zijnde rules evalueren in prioriteits-volgorde, MUST het berekende aantal punten als één PointsLedgerEntry van type credit toevoegen, en MUST de currentBalance en lifetimePoints atomair bijwerken.

### REQ-LOY-003: Tier herclassificatie
GIVEN een TierRule met drempelType en upgradeBeleid, WHEN de lifetimePoints of rolling-window-spend van een klant een drempel overschrijdt, THEN het systeem MUST volgens het upgradeBeleid (immediate of end_of_period) de currentTierId bijwerken, MUST een tier-changed event uitsturen voor downstream gebruik (notificatie, email), en MUST de tierGeldigTot herberekenen.

### REQ-LOY-004: Punten inwisselen
GIVEN een klant met voldoende currentBalance en een geldige RedemptionOption, WHEN de klant of POS-medewerker een redemption initieert, THEN het systeem MUST het puntenaantal reserveren via een PointsLedgerEntry van type debit, MUST een Redemption-record aanmaken met status gereserveerd, MUST een unieke beloningCode genereren, en MUST de redemption als gebruikt markeren zodra deze in een transactie is verwerkt.

### REQ-LOY-005: Vervallen van punten
GIVEN een programma met een expiry-policy (bv. 12 maanden inactiviteit), WHEN het systeem de dagelijkse expiry-batch draait, THEN het MUST alle accounts evalueren tegen de policy, MUST per vervallend bedrag een PointsLedgerEntry van type expiry toevoegen, MUST de klant 30 dagen vooraf notificeren via de geconfigureerde notificatie-kanaal, en MUST nooit punten verwijderen zonder ledger-spoor.

### REQ-LOY-006: Gift card uitgifte
GIVEN een gift-card programma, WHEN een gift card wordt verkocht of als promotie uitgegeven, THEN het systeem MUST een uniek serial genereren, een pin hashen, de initiële balans vastleggen, een GiftCardTransaction van type issue schrijven, en de kaart pas activeren wanneer de bijbehorende pos-transactie is afgerond (om frauduleuze refund-uitgifte te voorkomen).

### REQ-LOY-007: Gift card inwisseling met partial redemption
GIVEN een actieve gift card met saldo X, WHEN deze wordt aangeboden bij een POS-transactie van waarde Y, THEN het systeem MUST bij Y <= X het volledige bedrag in mindering brengen en het restsaldo bewaren; bij Y > X MUST het systeem het saldo volledig benutten en het verschil als openstaand bedrag teruggeven aan de POS-flow; in beide gevallen MUST een GiftCardTransaction worden geschreven.

### REQ-LOY-008: Programma rapportage
GIVEN een loyalty-programme met minimaal 30 dagen geschiedenis, WHEN een beheerder het rapportage-dashboard opent, THEN het systeem MUST de volgende kerncijfers tonen: aantal actieve accounts, uitgegeven punten in periode, ingewisselde punten in periode, vervallen punten (breakage), redemption rate als percentage, geschatte programmakosten als percentage van loyalty-gerelateerde omzet, tier-verdeling, en CLV-vergelijking loyalty versus niet-loyalty klanten.

## Standards

- PCI-DSS niveau (voor gift cards die als payment instrument fungeren — geen kaartnummer-opslag, alleen serial+pin-hash).
- AVG/GDPR — klantenprofiel-koppeling via klantbeeld-360, expliciete opt-in voor het programma vastgelegd.
- IFRS 15 / RJ 270 — uitgegeven loyalty-punten als verplichting op de balans; rapportage moet de verplichting kunnen kwantificeren.
- EMV-tokenisatie indien gift cards via betaal-terminals worden aangeboden.

## Cross-app

- **pipelinq pos-transaction-core**: triggerbron voor purchase-points en gift-card inwisseling.
- **pipelinq klantbeeld-360**: identificatie van de klant achter het loyalty-account, segmentatie voor conditionele rules.
- **pipelinq voucher-engine**: redemption-codes en gift-card serials kunnen door dezelfde validator gaan.
- **openconnector**: koppeling met externe loyalty-platformen (Piggy, LoyaltyLion) als hybride scenario, en met email/SMS-providers voor notificaties.
- **financeq**: outstanding-points-liability boeking en gift-card-balans op de balans.
- **launchpad**: dashboards voor programma-economie, tier-verdeling, CLV.

## Target users

Retail-eigenaren, marketingmanagers, customer-success teams, horeca-uitbaters, webshop-managers, financieel-administrateurs voor de verplichtingen-rapportage, MKB-ondernemers die een stempelkaart willen vervangen.
