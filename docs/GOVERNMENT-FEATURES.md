# Pipelinq — Overheidsfunctionaliteiten

> Functiepagina voor Nederlandse overheidsorganisaties.
> Gebruik deze checklist om te toetsen aan uw Programma van Eisen.

**Product:** Pipelinq
**Categorie:** CRM & klantinteractie
**Licentie:** AGPL (vrije open source)
**Leverancier:** Conduction B.V.
**Platform:** Nextcloud + Open Register (self-hosted / on-premise / cloud)

## Legenda

| Status | Betekenis |
|--------|-----------|
| Beschikbaar | Functionaliteit is beschikbaar in de huidige versie |
| Gepland (MVP) | Gepland voor eerste release |
| Gepland (V1) | Gepland voor versie 1.0 |
| Gepland (Enterprise) | Gepland voor enterprise-versie |
| Via platform | Functionaliteit wordt geleverd door Nextcloud / OpenRegister |
| Op aanvraag | Beschikbaar als maatwerk |
| N.v.t. | Niet van toepassing voor dit product |

---

## 1. Functionele eisen

### Klantbeheer (Contactmanagement)

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| F-01 | Klanten aanmaken, bekijken, wijzigen, verwijderen (persoon + organisatie) | Gepland (MVP) | Volledige CRUD |
| F-02 | Klantoverzicht met zoeken, sorteren, filteren | Gepland (MVP) | Lijst- en kaartweergave |
| F-03 | Klantdetailpagina met activiteitentijdlijn | Gepland (MVP) | Interactiehistorie |
| F-04 | Contactpersonen gekoppeld aan organisaties | Gepland (MVP) | Relatiebeheer |
| F-05 | Nextcloud Contacten synchronisatie | Gepland (MVP) | Geen dubbele invoer |
| F-06 | Duplicaatdetectie | Gepland (V1) | Datakwaliteit |
| F-07 | Import/export (CSV, vCard) | Gepland (V1) | Migratie en rapportage |
| F-08 | Contactsegmentatie (tags) | Gepland (V1) | Groepering en targeting |
| F-09 | Hiërarchische organisaties (moeder/dochter) | Gepland (Enterprise) | Overheidsorganisatiestructuren |
| F-10 | BSN/KVK-nummer opzoeken | Gepland (Enterprise) | Identiteitsverificatie |

### Verzoeken & Leads

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| F-11 | Verzoeken met statuslevenscyclus | Gepland (MVP) | Kern-workflow |
| F-12 | Verzoekoverzicht met filters | Gepland (MVP) | Werkvoorraad |
| F-13 | Lead-beheer (waarde, kans, sluitdatum) | Gepland (MVP) | Verkoopentiteiten |
| F-14 | Prioriteitsniveaus | Gepland (MVP) | Triage |
| F-15 | Verzoek-naar-zaak conversie | Gepland (V1) | Brug naar Procest |
| F-16 | Kanaalregistratie (telefoon, e-mail, balie, web) | Gepland (V1) | Omnichannel-analyse |
| F-17 | SLA-tracking (respons/oplostijd) | Gepland (Enterprise) | Dienstverleningskwaliteit |

### Pipeline & Kanban

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| F-18 | Configureerbare pipelines (admin) | Gepland (MVP) | Kern kanban-workflow |
| F-19 | Pipeline-stappen met drag-and-drop | Gepland (MVP) | Visueel workflow-beheer |
| F-20 | Standaard Sales Pipeline | Gepland (MVP) | Uit-de-doos bruikbaar |
| F-21 | Standaard Service Pipeline | Gepland (MVP) | Uit-de-doos bruikbaar |
| F-22 | Pipeline-weergave (kanban / lijst toggle) | Gepland (MVP) | Flexibele weergave |
| F-23 | Pipeline-analytics (conversieratio's, funnelweergave) | Gepland (V1) | Management-informatie |
| F-24 | Meerdere pipelines per team | Gepland (V1) | Team-specifieke workflows |

### Werkvoorraad & Dashboard

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| F-25 | Persoonlijke werkvoorraad (mijn leads, mijn verzoeken) | Gepland (MVP) | Productiviteitsoverzicht |
| F-26 | Dashboard met aantallen en statusverdeling | Gepland (MVP) | Management-informatie |
| F-27 | Cross-app werkvoorraad (inclusief Procest taken) | Gepland (V1) | Geïntegreerd werkbeheer |
| F-28 | KPI-dashboard | Gepland (V1) | Stuurinformatie |

---

## 2. Technische eisen

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| T-01 | On-premise / self-hosted installatie | Beschikbaar | Nextcloud-app |
| T-02 | Open source (broncode beschikbaar) | Beschikbaar | AGPL, GitHub |
| T-03 | RESTful API | Via platform | OpenRegister REST API |
| T-04 | Event-driven architectuur | Via platform | OpenRegister events |
| T-05 | Database-onafhankelijkheid | Via platform | PostgreSQL, MySQL, SQLite |
| T-06 | Containerisatie (Docker) | Beschikbaar | Docker Compose |
| T-07 | MCP (AI-integratie) | Via platform | OpenRegister MCP |

---

## 3. Beveiligingseisen

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| B-01 | RBAC | Via platform | OpenRegister RBAC |
| B-02 | Audit trail | Via platform | OpenRegister mutatie-historie |
| B-03 | BIO-compliance | Via platform | Nextcloud BIO |
| B-04 | 2FA | Via platform | Nextcloud 2FA |
| B-05 | SSO / SAML / LDAP | Via platform | Nextcloud SSO |
| B-06 | DigiD | Via platform | Via SAML |
| B-07 | Versleuteling (rust + transit) | Via platform | Nextcloud encryption + TLS |

---

## 4. Privacyeisen (AVG/GDPR)

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| P-01 | Recht op inzage | Gepland (V1) | Data-export per klant |
| P-02 | Recht op vergetelheid | Gepland (V1) | Klantgegevens verwijdering |
| P-03 | Recht op rectificatie | Via platform | Object wijzigen via OpenRegister |
| P-04 | Bewaartermijnen | Gepland (Enterprise) | Automatische opschoning |
| P-05 | Data minimalisatie | Beschikbaar | Schema-gebaseerd |

---

## 5. Toegankelijkheidseisen

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| A-01 | WCAG 2.1 AA | Gepland (MVP) | Nextcloud-componenten |
| A-02 | EN 301 549 | Gepland (MVP) | Via WCAG AA |
| A-03 | Toetsenbordnavigatie | Gepland (MVP) | Volledig navigeerbaar |
| A-04 | Screenreader | Gepland (MVP) | ARIA-labels |
| A-05 | NL Design System | Gepland (V1) | Via NL Design app |
| A-06 | Meertalig (NL/EN) | Gepland (MVP) | Volledige vertaling |

---

## 6. Integratiestandaarden

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| I-01 | Common Ground architectuur | Beschikbaar | Laag 5 (interactie) bovenop OpenRegister |
| I-02 | VNG Klantinteracties API | Gepland (V1) | Mapping naar VNG standaard |
| I-03 | VNG Verzoeken API | Gepland (V1) | Mapping naar VNG standaard |
| I-04 | StUF-koppeling | Via app | OpenConnector StUF-vertaling |
| I-05 | Procest-brug (verzoek-naar-zaak) | Gepland (V1) | CRM-naar-zaak workflow |
| I-06 | Federatie (cross-organisatie CRM) | Gepland (Enterprise) | Gefedereerd klantbeheer |
| I-07 | Webhook-ondersteuning | Gepland (Enterprise) | Event-driven integratie |
| I-08 | Nextcloud Contacten sync | Gepland (MVP) | CardDAV-integratie |

---

## 7. Archivering

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| AR-01 | Bewaartermijnen voor klantgegevens | Gepland (Enterprise) | Via OpenRegister retention |
| AR-02 | Archivering van afgesloten verzoeken | Gepland (Enterprise) | Automatische archivering |
| AR-03 | TMLO/MDTO-metadata | Via platform | Via OpenRegister |

---

## 8. Beheer en onderhoud

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| BO-01 | Nextcloud App Store | Beschikbaar | Installatie via App Store |
| BO-02 | Automatische updates | Beschikbaar | Via Nextcloud app-updater |
| BO-03 | Beheerderspaneel | Gepland (MVP) | Pipelines, stappen, instellingen |
| BO-04 | Documentatie | Beschikbaar | Gebruiker/beheerder/developer docs |
| BO-05 | Open source community | Beschikbaar | GitHub Issues |
| BO-06 | Professionele ondersteuning (SLA) | Op aanvraag | Via Conduction B.V. |

---

## 9. Platform-voordelen (via Nextcloud)

| Functionaliteit | Beschrijving |
|-----------------|-------------|
| Contacten | Nextcloud Contacts sync — geen dubbele invoer |
| Bestanden | Klantdossiers via Nextcloud Files |
| Agenda | Opvolgafspraken in Nextcloud Calendar |
| Chat per klant | Nextcloud Talk room per klant/verzoek |
| Notificaties | Toewijzingen, statuswijzigingen, deadlines |
| Activiteitenlogboek | CRM-gebeurtenissen in Activity |
| Federatie | Klanten delen tussen organisaties |
| Mobiele apps | iOS/Android toegang tot CRM |
| AI-assistent | Klantsamenvattingen, lead-suggesties |

---

## 10. Onderscheidende kenmerken

| Kenmerk | Toelichting |
|---------|-------------|
| **Nextcloud-native** | CRM in uw bestaande samenwerkingsplatform |
| **CRM + Zaak in één** | Pipelinq → Procest: verzoek-naar-zaak in één klik |
| **NL Design System** | Overheidshuisstijl via design tokens |
| **Data-hergebruik** | Klantdata herbruikbaar door Procest, OpenCatalogi, etc. |
| **Soeverein** | Geen SaaS-vendor lock-in, volledig on-premise |
| **Lichtgewicht** | Geen apart CRM-systeem — draait als Nextcloud-app |
