# Tasks: bsn-validatie-en-brp-lookup

## 1. Data Model: OpenRegister Schemas

- [x] 1.1 Create `BsnValidatie` schema in OpenRegister
  - **spec_ref**: `specs.md#REQ-BSN-001`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the pipelinq register is loaded
    - THEN `BsnValidatie` schema MUST include: ingevoerdBsn, isFormeelGeldig, elfproefScore, validatieTijdstip, geinitieerdDoor, context, verzoekId

- [x] 1.2 Create `BrpLookupVerzoek` schema in OpenRegister
  - **spec_ref**: `specs.md#REQ-BSN-002`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register is updated
    - THEN `BrpLookupVerzoek` schema MUST include: bsn, verzoekreden, doelbinding, grondslag, aangevraagdDoor, aangevraagdNamens, verzoekTijdstip, gekoppeldVerzoek, gekoppeldContact, responseStatus, responseTijdstip, responseDuurMs, haalcentraalCorrelationId, responseBevatGeheimhouding, responseInCache, cacheVerlooptOp
    - AND index on (bsn_hash, verzoekTijdstip)

- [x] 1.3 Create `BrpPersoon` schema in OpenRegister
  - **spec_ref**: `specs.md#REQ-BSN-004`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register is updated
    - THEN `BrpPersoon` schema MUST include: bsn, voornamen, voorletters, voorvoegsel, geslachtsnaam, adellijkeTitel, geboortedatum, geboorteplaats, geboorteland, geslacht, verblijfplaats (object), indicatieGeheim, opgehaaldOp, bronsysteem, lookupVerzoekId, gekoppeldContact, retentieTot
    - AND encryption: at-rest via Nextcloud native encryption
    - AND index on (gekoppeldContact, opgehaaldOp DESC)

- [x] 1.4 Create `BsnAuditRecord` schema in OpenRegister
  - **spec_ref**: `specs.md#REQ-BSN-005`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register is updated
    - THEN `BsnAuditRecord` schema MUST include: actie, bsn, actor, actorRol, tijdstip, verzoekreden, doelbinding, uitkomst, responseCode, ipAdres, userAgent, haalcentraalCorrelationId, gekoppeldVerzoek, vogScreening, bewaartot
    - AND schema setting: immutable: true (cannot be modified via standard CRUD)
    - AND index on (tijdstip DESC, actor, tijdstip)

- [x] 1.5 Create `OptOutVlag` schema in OpenRegister
  - **spec_ref**: `specs.md#REQ-BSN-006`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register is updated
    - THEN `OptOutVlag` schema MUST include: bsn, type, bron, ingangsdatum, einddatum, beperkt (array), lokaalOpgevoerdDoor, notitie
    - AND index on (bsn_hash, type)

- [x] 1.6 Extend `contact` schema with 3 new fields
  - **spec_ref**: `design.md#Extended Schema: contact`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the contact schema is updated
    - THEN new fields MUST be added: verifiedBSN (boolean), brpPersoonId (string reference), geheimhouding (boolean)
    - AND default values: verifiedBSN=false, brpPersoonId=null, geheimhouding=false

---

## 2. Backend: Services

- [x] 2.1 Create `lib/Service/BsnValidationService.php`
  - **spec_ref**: `specs.md#REQ-BSN-001`
  - **files**: `lib/Service/BsnValidationService.php`
  - **acceptance_criteria**:
    - GIVEN the service is instantiated
    - THEN `validate(string $bsnInput): BsnValidationResult` method MUST exist
    - AND check input length = 9 digits only
    - AND compute 11-proef (sum of digit × position, modulo 11)
    - AND return BsnValidationResult with isFormeelGeldig (true/false) and error message if invalid

- [x] 2.2 Create `lib/Service/HaalCentraalClient.php`
  - **spec_ref**: `specs.md#REQ-BSN-003`
  - **files**: `lib/Service/HaalCentraalClient.php`
  - **acceptance_criteria**:
    - GIVEN HaalCentraal OAuth2 credentials and mTLS certificate are configured
    - THEN constructor MUST accept: client_id, client_secret, cert_path, key_path, ca_bundle, oauth_endpoint
    - AND method `lookupPersoon(string $bsn): ?BrpPersoon` MUST exist
    - AND implement OAuth2 client_credentials flow with token caching (50 min)
    - AND use Guzzle HTTP client with mTLS config
    - AND parse HaalCentraal HAL+JSON response and normalize to BrpPersoon
    - AND throw HaalCentraalException on error

- [x] 2.3 Create `lib/Service/BrpCacheService.php`
  - **spec_ref**: `specs.md#REQ-BSN-004`
  - **files**: `lib/Service/BrpCacheService.php`
  - **acceptance_criteria**:
    - GIVEN the service is instantiated
    - THEN methods MUST exist:
      - `get(string $bsn): ?BrpPersoon` — returns cached entry if not expired
      - `set(BrpPersoon $person, int $ttlHours = 24): void` — stores with retentieTot = now + ttl
      - `invalidate(string $bsn): void` — marks entry as expired

- [x] 2.4 Create `lib/Service/BsnAuditService.php`
  - **spec_ref**: `specs.md#REQ-BSN-005`
  - **files**: `lib/Service/BsnAuditService.php`
  - **acceptance_criteria**:
    - GIVEN the service is instantiated
    - THEN method `recordLookup(actor, bsn, verzoekreden, doelbinding, uitkomst, responseCode, correlationId): void` MUST exist
    - AND always write to immutable BsnAuditRecord schema
    - AND mask BSN in all logging output as `***{last1digit}`

- [x] 2.5 Create `lib/Service/OptOutService.php`
  - **spec_ref**: `specs.md#REQ-BSN-006`
  - **files**: `lib/Service/OptOutService.php`
  - **acceptance_criteria**:
    - GIVEN the service is instantiated
    - THEN methods MUST exist:
      - `hasOptOut(string $bsn): bool` — returns true if active OptOutVlag exists
      - `getOptOut(string $bsn): ?OptOutVlag`
      - `recordFromBrpResponse(BrpPersoon $person): void` — creates OptOutVlag if indicatieGeheim = "1"

- [x] 2.6 Create `lib/Listener/BrpMutationWebhookListener.php`
  - **spec_ref**: `specs.md#REQ-BSN-004-03`
  - **files**: `lib/Listener/BrpMutationWebhookListener.php`
  - **acceptance_criteria**:
    - GIVEN webhook endpoint is registered at POST `/api/brp/mutations`
    - WHEN external HaalCentraal webhook fires
    - THEN listener MUST verify HMAC-SHA256 signature
    - AND extract BSN from payload
    - AND call `BrpCacheService.invalidate($bsn)`
    - AND return 200 OK

---

## 3. Backend: Controllers & Health Checks

- [x] 3.1 Create `lib/Controller/BrpController.php`
  - **spec_ref**: `specs.md#REQ-BSN-002, REQ-BSN-003`
  - **files**: `lib/Controller/BrpController.php`
  - **acceptance_criteria**:
    - GIVEN the controller is instantiated
    - THEN methods MUST exist:
      - `POST /api/brp/lookup` — accepts BSN, verzoekId, verzoekreden, doelbinding
      - Returns BrpPersoon on success or error response
      - Validates doelbinding is not empty (400 if missing)
      - Requires user role behandelaar-burgerzaken or behandelaar-avg (403 if missing)

- [x] 3.2 Implement lookup flow in BrpController
  - **spec_ref**: `design.md#Flow: BRP Lookup with Doelbinding`
  - **files**: `lib/Controller/BrpController.php`
  - **acceptance_criteria**:
    - GIVEN POST /api/brp/lookup with valid params
    - WHEN request is received
    - THEN:
      1. Check doelbinding not empty (400 if empty)
      2. Check user permissions (403 if unauthorized)
      3. Call BrpCacheService.get($bsn)
      4. If cache miss: call HaalCentraalClient.lookupPersoon($bsn)
      5. If cache hit or success: call BrpCacheService.set($person) if needed
      6. Call OptOutService.recordFromBrpResponse($person)
      7. Create BrpLookupVerzoek record
      8. Update Contact: verifiedBSN=true, brpPersoonId=$person.id, geheimhouding={from OptOut}
      9. Call BsnAuditService.recordLookup(...)
      10. Return BrpPersoon or error

- [x] 3.3 Create daily health-check job
  - **spec_ref**: `specs.md#REQ-BSN-003-02`
  - **files**: `lib/Job/BrpHealthCheckJob.php`
  - **acceptance_criteria**:
    - GIVEN job scheduled daily (via cron or OpenRegister job)
    - WHEN job runs
    - THEN it MUST check certificate expiration
    - AND if expiring in < 30 days: create Nextcloud Notification to admin
    - AND update admin cache with certificate expiry info

- [x] 3.4 Create BRP Monitor job
  - **spec_ref**: `specs.md#REQ-BSN-010`
  - **files**: `lib/Job/BrpMonitorJob.php`
  - **acceptance_criteria**:
    - GIVEN job runs daily at midnight
    - WHEN job executes
    - THEN it MUST query last 24h of BsnAuditRecords
    - AND compute: lookup count, cache-hit ratio, avg response time, error rate
    - AND store report (write to cache or BrpMonitorReport schema)
    - AND if error rate > 10%: send notification to admin

- [x] 3.5 Create Retention cleanup job
  - **spec_ref**: `specs.md#REQ-BSN-008`
  - **files**: `lib/Job/BrpRetentionJob.php`
  - **acceptance_criteria**:
    - GIVEN job runs daily
    - WHEN job executes
    - THEN it MUST query BrpPersoon records where retentieTot < now
    - AND delete expired records
    - AND update Contact: verifiedBSN=false for deleted records
    - AND preserve BsnAuditRecord (immutable)

---

## 4. Backend: Configuration & Admin Settings

- [x] 4.1 Add HaalCentraal OAuth2 credentials to admin settings
  - **spec_ref**: `design.md#Backend`
  - **files**: `lib/Settings/Admin.php` or settings form
  - **acceptance_criteria**:
    - GIVEN admin opens Pipelinq settings
    - WHEN scrolling to "BRP Configuration"
    - THEN admin can input and save:
      - OAuth2 Endpoint URL
      - Client ID
      - Client Secret
      - mTLS Certificate (file upload)
      - mTLS Key (file upload)
      - CA Bundle (file upload)

- [x] 4.2 Add cache and retention settings
  - **spec_ref**: `specs.md#REQ-BSN-004-04, REQ-BSN-008-01`
  - **files**: `lib/Settings/Admin.php`
  - **acceptance_criteria**:
    - GIVEN admin settings page
    - WHEN "BRP Configuration" section is displayed
    - THEN admin can configure:
      - Cache TTL (hours): default 24
      - Retention period (days): default 7
      - Health check timezone: default UTC
      - Webhook secret key (auto-generated, manual reset option)

---

## 5. Frontend: Contact Detail View

- [x] 5.1 Add BSN input field with inline validation
  - **spec_ref**: `specs.md#REQ-BSN-001`
  - **files**: frontend Contact detail component
  - **acceptance_criteria**:
    - GIVEN Contact detail view is rendered
    - WHEN BSN input field is displayed
    - THEN:
      - Placeholder text: "Bijv. 123456782"
      - On input change: call BsnValidationService.validate() (client-side)
      - If invalid: show red error message, disable "Ophalen uit BRP" button
      - If valid: show green checkmark, enable button

- [x] 5.2 Create "Ophalen uit BRP" button
  - **spec_ref**: `design.md#Contact Detail View`
  - **files**: frontend Contact detail component
  - **acceptance_criteria**:
    - GIVEN Contact detail view
    - WHEN BSN input is valid
    - THEN button appears enabled
    - WHEN button is clicked
    - THEN modal opens (see 5.3)

- [x] 5.3 Create doelbinding modal
  - **spec_ref**: `specs.md#REQ-BSN-002`
  - **files**: frontend modal component
  - **acceptance_criteria**:
    - GIVEN modal is opened
    - WHEN modal renders
    - THEN it MUST display:
      - Title: "Doelbinding Verzoeken"
      - Dropdown "Verzoekreden" (required): options = ["Behandeling AVG-inzageverzoek art. 15", "Behandeling AVG-verwijderverzoek art. 17", "VOG-screening", "Reguliere verzoekbehandeling", "Overig"]
      - Dropdown "Doelbinding" (required): options = ["Publieke taak — Wet BRP art. 3.3", "AVG art. 6 lid 1 sub e", "Rechtmatig belang", "Overig"]
      - Textarea "Aanvullende toelichting" (optional, >= 20 chars recommended)
      - Buttons: "Ophalen", "Annuleren"
    - WHEN user leaves required fields empty: "Ophalen" button disabled
    - WHEN user clicks "Ophalen": POST to `/api/brp/lookup`

- [x] 5.4 Add response spinner and error handling
  - **spec_ref**: `design.md#Contact Detail View`
  - **files**: frontend
  - **acceptance_criteria**:
    - GIVEN API request is sent
    - WHEN waiting for response
    - THEN spinner appears with "Ophalen uit BRP..."
    - AND timeout after 5 seconds shows error: "Verzoek timeout — probeer opnieuw"
    - IF error response: show error banner with message from backend
    - IF success: close modal, render Persoon detail (see 5.5)

- [x] 5.5 Create Persoon detail view
  - **spec_ref**: `design.md#Frontend - Persoon Detail`
  - **files**: frontend detail component
  - **acceptance_criteria**:
    - GIVEN BrpPersoon is returned from API
    - WHEN detail view renders
    - THEN display:
      - Name: "Maria Wilhelmina van der Berg" (or geheimhouding icon if applicable)
      - Geboortedatum, Geboorteplaats, Geslacht
      - IF NOT geheimhouding: show address (Straat, Huisnummer, Postcode, Woonplaats)
      - IF geheimhouding: show "[GEHEIM]" with "Toon adres onder verantwoording" link
      - Cache indicator "⚡ van cache" if responseInCache=true

- [x] 5.6 Create timeline event for lookup
  - **spec_ref**: `specs.md#REQ-BSN-009-02`
  - **files**: frontend timeline component
  - **acceptance_criteria**:
    - GIVEN BRP lookup succeeds
    - WHEN timeline is rendered
    - THEN add event: "brp-lookup-uitgevoerd"
    - AND event text: "BRP-gegevens opgehaald (verzoekreden: {reason}, cache: {yes/no})"
    - AND **NEVER include BSN in event text**

---

## 6. Frontend: Admin Dashboard

- [x] 6.1 Create BRP-Monitor admin tegel
  - **spec_ref**: `specs.md#REQ-BSN-010`
  - **files**: frontend admin dashboard component
  - **acceptance_criteria**:
    - GIVEN admin opens admin area
    - WHEN dashboard loads
    - THEN new tegel "BRP Monitor" appears showing:
      - Last 24 hours: Lookups, Cache hits (%), Errors (%), Avg response time
      - Certificate status: "Expires {date} ({days} days)" with color badge
      - Link: "View detailed report"

- [x] 6.2 Implement detailed BRP report view
  - **spec_ref**: `specs.md#REQ-BSN-010`
  - **files**: frontend report component
  - **acceptance_criteria**:
    - GIVEN admin clicks "View detailed report"
    - WHEN report page loads
    - THEN display:
      - Chart: Lookups per hour (last 24h)
      - Chart: Error rate % over time
      - Table: Top errors by count
      - Certificate expiration timeline (30, 14, 7 days alerts)

---

## 7. Localization (i18n)

- [x] 7.1 Add Dutch translations
  - **spec_ref**: `design.md#Frontend`
  - **files**: `translationfiles/nl.json` or similar
  - **acceptance_criteria**:
    - GIVEN all user-facing strings
    - THEN Dutch translations MUST include:
      - `bsn.input.placeholder` = "Bijv. 123456782"
      - `bsn.validation.invalid` = "Dit BSN voldoet niet aan de 11-proef"
      - `bsn.validation.length` = "Een BSN bestaat uit exact 9 cijfers"
      - `brp.button.lookup` = "Ophalen uit BRP"
      - `brp.modal.title` = "Doelbinding Verzoeken"
      - `brp.modal.reason` = "Verzoekreden"
      - `brp.modal.binding` = "Doelbinding / wettelijke grondslag"
      - `brp.error.not_found` = "BSN niet aangetroffen in BRP — controleer invoer"
      - `brp.error.unavailable` = "BRP momenteel niet bereikbaar — probeer over enkele minuten opnieuw"
      - `brp.error.unauthorized` = "U bent niet bevoegd voor deze lookup"
      - `brp.secret.label` = "[GEHEIM]"
      - `brp.secret.show_link` = "Toon adres onder verantwoording"
      - `brp.cache.indicator` = "⚡ van cache"
      - `bsn.audit.timeline_event` = "BRP-gegevens opgehaald ({reason}, {cache})"

- [x] 7.2 Add English translations
  - **spec_ref**: `design.md#Frontend`
  - **files**: `translationfiles/en.json`
  - **acceptance_criteria**:
    - GIVEN all user-facing strings
    - THEN English translations MUST exist with same keys as Dutch
    - AND match spec text semantics

---

## 8. Seed Data

- [x] 8.1 Create seed BrpLookupVerzoek objects
  - **spec_ref**: `design.md#Seed Data`
  - **files**: migration or seed fixture
  - **acceptance_criteria**:
    - GIVEN seed data loads
    - THEN 2 BrpLookupVerzoek objects MUST exist:
      - Example 1: succesvol (responseStatus: "geslaagd")
      - Example 2: niet-gevonden (responseStatus: "niet-gevonden")

- [x] 8.2 Create seed BrpPersoon objects
  - **spec_ref**: `design.md#Seed Data`
  - **files**: migration or seed fixture
  - **acceptance_criteria**:
    - GIVEN seed data loads
    - THEN 1-2 BrpPersoon objects MUST exist with:
      - Full name, birthdate, address, gender
      - One with indicatieGeheim: "0" (no secret)
      - One with indicatieGeheim: "1" (geheimhouding) optional

- [x] 8.3 Create seed BsnAuditRecord objects
  - **spec_ref**: `design.md#Seed Data`
  - **files**: migration or seed fixture
  - **acceptance_criteria**:
    - GIVEN seed data loads
    - THEN 2 BsnAuditRecord objects MUST exist:
      - Example 1: brp-lookup-uitgevoerd, uitkomst: "geslaagd"
      - Example 2: brp-lookup-uitgevoerd, uitkomst: "niet-gevonden"

- [x] 8.4 Create seed OptOutVlag object
  - **spec_ref**: `design.md#Seed Data`
  - **files**: migration or seed fixture
  - **acceptance_criteria**:
    - GIVEN seed data loads
    - THEN 1 OptOutVlag object MUST exist:
      - type: "geheimhouding-gemeente"
      - bron: "BRP"

---

## 9. Testing & Verification

- [x] 9.1 Unit tests for BsnValidationService
  - **spec_ref**: `specs.md#REQ-BSN-001`
  - **files**: `tests/Unit/Service/BsnValidationServiceTest.php`
  - **acceptance_criteria**:
    - Test valid BSN (11-proef passes)
    - Test invalid BSN (11-proef fails)
    - Test short input (< 9 chars)
    - Test long input (> 9 chars)
    - Test non-numeric input
    - Coverage: 100%

- [x] 9.2 Unit tests for HaalCentraalClient
  - **spec_ref**: `specs.md#REQ-BSN-003`
  - **files**: `tests/Unit/Service/HaalCentraalClientTest.php`
  - **acceptance_criteria**:
    - Mock HaalCentraal endpoint
    - Test successful lookup (200 OK)
    - Test BSN not found (404)
    - Test authentication error (401)
    - Test timeout (> 5s)
    - Test mTLS certificate validation

- [x] 9.3 Integration tests for lookup flow
  - **spec_ref**: `design.md#Flow: BRP Lookup with Doelbinding`
  - **files**: `tests/Integration/BrpLookupFlowTest.php`
  - **acceptance_criteria**:
    - Test end-to-end: BSN input → validation → doelbinding modal → lookup → Persoon detail
    - Test cache hit
    - Test cache miss
    - Test error handling

- [x] 9.4 Verify no build errors
  - **spec_ref**: `proposal.md#Success Criteria`
  - **files**: all
  - **acceptance_criteria**:
    - GIVEN all code is written
    - WHEN `npm run build` and `php artisan check` run
    - THEN exit code MUST be 0
    - AND no TypeScript/PHP/eslint/stylelint errors

---

## 10. Documentation & Compliance

- [x] 10.1 Create user-facing documentation
  - **spec_ref**: proposal.md
  - **files**: `docs/bsn-brp-lookup.md` (new)
  - **acceptance_criteria**:
    - GIVEN docs are written
    - THEN doc MUST explain:
      - What BSN validation is and why
      - How to trigger BRP lookup
      - What doelbinding means and compliance importance
      - How geheimhouding (secret) handling works
      - Where to find audit logs
    - AND include screenshots of Contact detail, modal, Persoon detail

- [x] 10.2 Create admin configuration guide
  - **spec_ref**: design.md#Backend
  - **files**: `docs/admin/bsn-brp-setup.md` (new)
  - **acceptance_criteria**:
    - GIVEN admins need to set up HaalCentraal integration
    - THEN guide MUST explain:
      - Where to obtain OAuth2 credentials (RvIG, gemeente)
      - Where to obtain and configure mTLS certificate (Logius PKIoverheid)
      - How to input credentials in admin panel
      - How to test connectivity (health check)
      - How to configure cache TTL, retention period
      - How to set up webhook URL for mutations

- [x] 10.3 Create audit and compliance checklist
  - **spec_ref**: specs.md (all)
  - **files**: `docs/compliance/bsn-audit-checklist.md` (new)
  - **acceptance_criteria**:
    - Document how to verify compliance with:
      - AVG art. 5 (dataminimalisatie, doelbinding, opslagbeperking)
      - AVG art. 30 (verwerkingsregister — BsnAuditRecord as evidence)
      - Wet BRP art. 3.3 (doelbinding)
      - BSN masking in logs (checklist: grep patterns to verify)
      - Audit export for RvIG inspectors (how to generate audit report)
      - Retention job verification (how to verify automatic deletion)

- [x] 10.4 Create CHANGELOG entry
  - **spec_ref**: proposal.md
  - **files**: `CHANGELOG.md`
  - **acceptance_criteria**:
    - GIVEN feature is complete
    - THEN CHANGELOG entry MUST describe:
      - New BsnValidatie, BrpLookupVerzoek, BrpPersoon, BsnAuditRecord, OptOutVlag schemas
      - BSN 11-proef validation (client-side)
      - HaalCentraal Personen REST client (OAuth2 + mTLS)
      - Response caching with 24-hour TTL
      - Immutable audit trail (5-year retention per RvIG)
      - Geheimhouding (secret) handling for protected citizens
      - Configureable retention (default 7 days)
      - AVG art. 17 (Right-to-be-forgotten) support
      - VOG-screening compatibility
      - Admin BRP-Monitor dashboard
      - i18n: Dutch + English

---

## 11. Final Checks

- [x] 11.1 Security review
  - **spec_ref**: OWASP ASVS 4.0.3
  - **files**: security-review.md (new)
  - **acceptance_criteria**:
    - Review for SQL injection (use parameterized queries everywhere)
    - Review for XSS (mask BSN in all outputs)
    - Review for SSRF (mTLS validation, certificate pinning if possible)
    - Review for information disclosure (no BSN in logs/URLs/cookies)
    - Review for authentication bypass (verify permission checks on all endpoints)
    - Review for insecure deserialization (trust only HaalCentraal signed responses)

- [x] 11.2 Performance review
  - **spec_ref**: specs.md#REQ-BSN-003-01
  - **files**: performance-notes.md
  - **acceptance_criteria**:
    - Verify HaalCentraal lookup completes in < 2 seconds
    - Verify cache hit completes in < 10ms
    - Verify 11-proef validation < 100ms client-side
    - Profile database queries (ensure indexes on audit table)

- [x] 11.3 Compliance checklist
  - **spec_ref**: specs.md (all standards)
  - **files**: compliance-checklist.md
  - **acceptance_criteria**:
    - Verify compliance with:
      - ✓ Wet BRP art. 1.7, 3.3 — doelbinding required
      - ✓ Wabb — sector-based restrictions (if configured)
      - ✓ AVG art. 5 — dataminimalisatie (cache TTL, retention)
      - ✓ AVG art. 30 — audit trail (BsnAuditRecord immutable)
      - ✓ NORA — herbruikbaarheid (OpenConnector Source)
      - ✓ HaalCentraal API v2.0 — REST + OAuth2 + mTLS
      - ✓ RvIG voorwaarden — mTLS certs, OAuth2
      - ✓ NEN 7510 (if org is healthcare)
      - ✓ BIO — logging, access control

---
