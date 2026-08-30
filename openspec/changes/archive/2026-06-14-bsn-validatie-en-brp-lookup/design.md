# Design: bsn-validatie-en-brp-lookup

## Architecture

### Data Layer

#### New Schema: `BsnValidatie`

Resultaat van een 11-proef check. Wordt gelogd maar niet persistent bewaard tenzij gekoppeld aan een lookup-poging.

| Property | Type | Required | Description |
|---|---|---|---|
| `ingevoerdBsn` | string | Yes | De 9-cijferige BSN-invoer (voordat decrypt/masking) |
| `isFormeelGeldig` | boolean | Yes | Resultaat van 11-proef (true/false) |
| `elfproefScore` | integer | No | Intermediaire score (1-10) voor debugging |
| `validatieTijdstip` | string | Yes | ISO 8601 timestamp |
| `geinitieerdDoor` | string | Yes | Nextcloud user UID die validatie initieerde |
| `context` | string | No | Waar validatie gebeurde (contact-aanmaken, avg-request, vog-screening) |
| `verzoekId` | string | No | Referentie naar gekoppeld verzoek (indien van toepassing) |

**Note**: Dit object wordt eenmalig aangemaakt, nooit gewijzigd. Geen TTL.

---

#### New Schema: `BrpLookupVerzoek`

Het verzoek zelf, met doelbinding en grondslag. Persistent voor audit trail.

| Property | Type | Required | Description |
|---|---|---|---|
| `bsn` | string | Yes | 9-cijferig BSN (gehashed in logs) |
| `verzoekreden` | string | Yes | Standaardkeuze of vrij tekstveld (AVG-artikel, VOG, overig) |
| `doelbinding` | string | Yes | Wettelijke grondslag (AVG art. 6, Wet BRP art. 3.3, etc.) |
| `grondslag` | string | Yes | Publieke taak, rechtsplicht, gerechtvaardigd belang, etc. |
| `aangevraagdDoor` | string | Yes | Nextcloud user UID (behandelaar) |
| `aangevraagdNamens` | string | No | Afdeling / rol (behandelaar-burgerzaken, behandelaar-avg, etc.) |
| `verzoekTijdstip` | string | Yes | ISO 8601 wanneer lookup werd aangevraagd |
| `gekoppeldVerzoek` | string | No | UUID referentie naar Pipelinq verzoek (request, complaint, etc.) |
| `gekoppeldContact` | string | No | UUID referentie naar Pipelinq Contact |
| `responseStatus` | string | Yes | geslaagd, niet-gevonden, fout, geweigerd-onbevoegd, etc. |
| `responseTijdstip` | string | No | ISO 8601 wanneer HaalCentraal antwoordde |
| `responseDuurMs` | integer | No | Response time in milliseconds |
| `haalcentraalCorrelationId` | string | No | Tracking ID van HaalCentraal voor support |
| `responseBevatGeheimhouding` | boolean | No | Indicatie dat response `indicatieGeheim = 1` bevatte |
| `responseInCache` | boolean | No | True als deze lookup van cache kwam |
| `cacheVerlooptOp` | string | No | ISO 8601 wanneer gecachte response verloopt |

**Index**: `(bsn_hash, verzoekTijdstip)` voor audit-rapportage per BSN per tijdsperiode.

---

#### New Schema: `BrpPersoon`

Genormaliseerde response uit HaalCentraal. Gekoppeld aan Contact. Versleuteld at-rest (Nextcloud native encryption).

| Property | Type | Required | Description |
|---|---|---|---|
| `bsn` | string | Yes | 9-cijferig BSN (gehashed in logs) |
| `voornamen` | string | Yes | Voornamen (schema:givenName) |
| `voorletters` | string | No | Afgekort (schema:givenName abbreviated) |
| `voorvoegsel` | string | No | vd, de, van, van der, etc. |
| `geslachtsnaam` | string | Yes | Achternaam (schema:familyName) |
| `adellijkeTitel` | string | No | Baron, Gravin, etc. |
| `geboortedatum` | string | Yes | ISO 8601 date (YYYY-MM-DD) |
| `geboorteplaats` | string | No | Plaats van geboorte |
| `geboorteland` | string | No | Landcode (ISO 3166-1 alpha-2) |
| `geslacht` | string | Yes | man, vrouw, onbekend (schema:gender) |
| `verblijfplaats` | object | Yes | Adresgegevens (zie sub-object) |
| `indicatieGeheim` | string | Yes | "0" (geen geheimhouding), "1" (geheimhouding gemeente) |
| `opgehaaldOp` | string | Yes | ISO 8601 wanneer dit Persoon-record van HaalCentraal kwam |
| `bronsysteem` | string | Yes | "HaalCentraal-BRP-v2.0" (voor toekomstige versies) |
| `lookupVerzoekId` | string | Yes | Referentie naar BrpLookupVerzoek |
| `gekoppeldContact` | string | Yes | UUID van Pipelinq Contact |
| `retentieTot` | string | Yes | ISO 8601 datum/tijd tot hoelang dit record mag bestaan |

**Sub-object: `verblijfplaats`**:
```json
{
  "straat": "Lange Voorhout",
  "huisnummer": 14,
  "huisletter": "H",
  "huisnummertoevoeging": null,
  "postcode": "2514 EA",
  "woonplaats": "'s-Gravenhage",
  "land": "Nederland"
}
```

**Index**: `(gekoppeldContact, opgehaaldOp DESC)` voor snelle lookup van meest recente Persoon per Contact.

---

#### New Schema: `BsnAuditRecord`

Onveranderlijke audit-regel die naar centrale logging gaat. Kan niet via UI worden gewijzigd.

| Property | Type | Required | Description |
|---|---|---|---|
| `actie` | string | Yes | brp-lookup-uitgevoerd, brp-lookup-mislukt, brp-lookup-geweigerd, etc. |
| `bsn` | string | Yes | 9-cijferig BSN (gehashed na opslag) |
| `actor` | string | Yes | Nextcloud user UID (like: medewerker:m.devries@gemeente-zeist.nl) |
| `actorRol` | string | No | behandelaar-burgerzaken, behandelaar-avg, beheerder, etc. |
| `tijdstip` | string | Yes | ISO 8601 timestamp van actie |
| `verzoekreden` | string | No | Gekopieerd van BrpLookupVerzoek |
| `doelbinding` | string | No | Gekopieerd van BrpLookupVerzoek |
| `uitkomst` | string | Yes | geslaagd, niet-gevonden, fout, geweigerd-onbevoegd, etc. |
| `responseCode` | integer | No | HTTP status code van HaalCentraal (200, 404, 403, 503, etc.) |
| `ipAdres` | string | No | Requester IP (geanonimiseerd bij logging) |
| `userAgent` | string | No | "Pipelinq/2.x.x (Nextcloud)" |
| `haalcentraalCorrelationId` | string | No | Correlation ID van HaalCentraal |
| `gekoppeldVerzoek` | string | No | Referentie naar Pipelinq verzoek |
| `vogScreening` | boolean | No | True als dit voor VOG-screening was (extra vlag voor Justis) |
| `bewaartot` | string | Yes | ISO 8601 tot hoelang audit-record moet bestaan (5 jaar per RvIG-richtlijn) |

**Schema setting**: `immutable: true` — dit record kan NIET worden gewijzigd of verwijderd via standaard CRUD; alleen systeem mag gepseudonimiseren bij Right-to-be-forgotten.

**Index**: `(tijdstip DESC)` voor dagelijkse audit-export; `(actor, tijdstip)` voor personeels-rapportage.

---

#### New Schema: `OptOutVlag`

Per BSN bekende opt-out / geheimhouding. Wordt overgenomen uit BRP-response en aanvullend lokaal beheerd.

| Property | Type | Required | Description |
|---|---|---|---|
| `bsn` | string | Yes | 9-cijferig BSN (gehashed) |
| `type` | string | Yes | geheimhouding-gemeente, geheimhouding-brp, lokale-contact-opt-out |
| `bron` | string | Yes | BRP, lokaal |
| `ingangsdatum` | string | Yes | ISO date wanneer opt-out van kracht werd |
| `einddatum` | string | No | ISO date wanneer opt-out afloopt (null = onbeperkt) |
| `beperkt` | array | No | Array van restricties: ["commerciele-derden", "kerkgenootschappen", "derdeportalen"] |
| `lokaalOpgevoerdDoor` | string | No | Nextcloud user UID van wie opt-out lokaal registreerde |
| `notitie` | string | No | Vrije toelichting (bijv. "verzoek burger per formulier X") |

**Index**: `(bsn_hash, type)` voor snelle lookup.

---

#### Extended Schema: `contact` (from Pipelinq core)

Twee nieuwe properties:

| Property | Type | Required | Description |
|---|---|---|---|
| `verifiedBSN` | boolean | No | True na succesvolle BRP-lookup; false na retentie-expiratie |
| `brpPersoonId` | string | No | UUID van meest recente geldige BrpPersoon |
| `geheimhouding` | boolean | No | Afgeleid van OptOutVlag; true = render geheimhoudings-icoon |

---

### Backend

#### `lib/Service/BsnValidationService.php`

Implements 11-proef algoritme (RvIG-documented).

**Method: `validate(string $bsnInput): BsnValidationResult`**

```php
public function validate(string $bsnInput): BsnValidationResult {
  // 1. Check length
  if (strlen($bsnInput) !== 9 || !ctype_digit($bsnInput)) {
    return new BsnValidationResult(
      ingevoerdBsn: $bsnInput,
      isFormeelGeldig: false,
      errorMessage: "Een BSN bestaat uit exact 9 cijfers"
    );
  }
  
  // 2. Compute 11-proef
  $sum = 0;
  for ($i = 0; $i < 9; $i++) {
    $position = 9 - $i;  // positions 9 to 1
    $digit = intval($bsnInput[$i]);
    if ($i === 8) {
      $position = -1;  // last digit uses -1 multiplier
    }
    $sum += $digit * $position;
  }
  
  $isValid = ($sum % 11) === 0;
  
  return new BsnValidationResult(
    ingevoerdBsn: $bsnInput,
    isFormeelGeldig: $isValid,
    elfproefScore: $sum % 11,
    validatieTijdstip: new DateTime('now', new DateTimeZone('UTC'))
  );
}
```

**Return**: `BsnValidationResult` (DTO with validation status, error message if invalid)

---

#### `lib/Service/HaalCentraalClient.php`

OAuth2 mTLS REST client for HaalCentraal Personen API v2.0.

**Constructor**: Takes config (OAuth endpoint, client_id, client_secret, certificate path, key path, CA bundle).

**Method: `lookupPersoon(string $bsn, string $verzoekId): ?BrpPersoon`**

```php
public function lookupPersoon(string $bsn, string $verzoekId): ?BrpPersoon {
  // 1. Get or refresh access token (cached max 50 min)
  $token = $this->getAccessToken();
  
  // 2. Construct request with mTLS
  $client = new \GuzzleHttp\Client([
    'cert' => $this->certPath,
    'ssl_key' => $this->keyPath,
    'verify' => $this->caBundle,
  ]);
  
  // 3. Call HaalCentraal endpoint
  try {
    $response = $client->get(
      'https://api.haalcentraal.nl/brp/v2.0/personen',
      [
        'headers' => ['Authorization' => "Bearer $token"],
        'query' => ['burgerservicenummer' => $bsn],
      ]
    );
    
    // 4. Parse response (HaalCentraal returns HAL+JSON)
    $data = json_decode($response->getBody(), true);
    $personData = $data['_embedded']['personen'][0] ?? null;
    
    if (!$personData) {
      return null;  // BSN not found
    }
    
    // 5. Normalize to BrpPersoon
    return $this->normalizePerson($personData, $bsn, $verzoekId);
    
  } catch (\Exception $e) {
    // Log, bubble up to caller for error handling
    throw new HaalCentraalException($e->getMessage(), $e->getCode());
  }
}
```

**Method: `getAccessToken(): string`**

Implements OAuth2 client_credentials grant. Caches token for 50 minutes (token expires at 60 min).

---

#### `lib/Service/BrpCacheService.php`

Manages response caching with TTL and webhook-based invalidation.

**Method: `get(string $bsn): ?BrpPersoon`**

Returns cached BrpPersoon if:
- Entry exists
- `now < retentieTot`
- No external webhook invalidated it

**Method: `set(BrpPersoon $person, int $ttlHours = 24): void`**

Stores person with `retentieTot = now + ttlHours`.

**Method: `invalidate(string $bsn): void`**

Called by webhook listener when HaalCentraal signals mutation. Marks cache entry as expired.

---

#### `lib/Service/BsnAuditService.php`

Creates immutable audit records.

**Method: `recordLookup(string $actor, string $bsn, string $verzoekreden, string $doelbinding, string $uitkomst, int $responseCode = null, string $correlationId = null): void`**

Always writes to BsnAuditRecord (immutable schema). No return value; exceptions bubble up to trigger UI error.

---

#### `lib/Service/OptOutService.php`

Checks and manages opt-out flags.

**Method: `hasOptOut(string $bsn): bool`**

Returns true if OptOutVlag exists and not expired.

**Method: `getOptOut(string $bsn): ?OptOutVlag`**

Returns the flag object or null.

**Method: `recordFromBrpResponse(BrpPersoon $person): void`**

If BrpPersoon has `indicatieGeheim = "1"`, creates OptOutVlag of type `geheimhouding-brp`.

---

#### `lib/Listener/BrpMutationWebhookListener.php`

Implements a webhook endpoint (e.g., `/api/brp/mutations`) that HaalCentraal calls when a citizen's data changes.

**Method: `handleMutation(Request $request): Response`**

```php
public function handleMutation(Request $request): Response {
  // 1. Verify webhook signature (HMAC-SHA256)
  if (!$this->verifySignature($request)) {
    return new Response('Forbidden', 403);
  }
  
  // 2. Parse mutation event
  $data = json_decode($request->getContent(), true);
  $bsn = $data['burgerservicenummer'] ?? null;
  
  // 3. Invalidate cache
  if ($bsn) {
    $this->cacheService->invalidate($bsn);
  }
  
  return new Response('OK', 200);
}
```

---

#### Flow: BRP Lookup with Doelbinding

1. Frontend: Contact detail, medewerker voert BSN in
2. Frontend: 11-proef (client-side, BsnValidationService.validate)
3. Frontend: If valid, "Ophalen uit BRP"-knop wordt enabled
4. Frontend: Medewerker klikt knop → modal toont "Verzoekreden" + "Doelbinding" (verplicht)
5. Frontend: User selecteert reden + grondslag, klikt "Ophalen"
6. Backend controller (`/api/brp/lookup`):
   - Validates doelbinding is not empty
   - Calls HaalCentraalClient.lookupPersoon($bsn, $verzoekId)
   - If cache hit: returns cached BrpPersoon but still records audit
   - If cache miss: calls HaalCentraal API, caches response, records audit
7. Backend: Calls OptOutService.recordFromBrpResponse() if needed
8. Backend: Updates Contact with `verifiedBSN = true`, `brpPersoonId = person.id`
9. Frontend: Renders Persoon-detailscherm (adres hidden if geheimhouding)
10. Backend: BsnAuditRecord written (always, whether cache hit or miss)

---

### Frontend

#### Contact Detail View

**BSN Input Field**:
- Placeholder: "Bijv. 123456782"
- On input change: calls BsnValidationService.validate()
- If invalid: shows error message inline (red), "Ophalen uit BRP"-knop disabled
- If valid: shows green checkmark, button enabled

**"Ophalen uit BRP" Button**:
- Disabled until BSN passes 11-proef
- On click: opens modal

**Modal: "Doelbinding Verzoeken"**:
- Dropdown: "Verzoekreden" (required)
  - Opties: "Behandeling AVG-inzageverzoek art. 15", "Behandeling AVG-verwijderverzoek art. 17", "VOG-screening", "Reguliere verzoekbehandeling", "Overig (vul toelichting in)"
- Dropdown/Textfield: "Doelbinding / wettelijke grondslag" (required)
  - Opties: "Publieke taak — Wet BRP art. 3.3", "AVG art. 6 lid 1 sub e", "Rechtmatig belang", etc.
- Textfield: "Aanvullende toelichting" (optional, >= 20 chars recommended)
- Buttons: "Ophalen", "Annuleren"

**On Submit**:
- POST to `/api/brp/lookup` with BSN, verzoekId, verzoekreden, doelbinding
- Show spinner while waiting (max 5s timeout)
- On success: close modal, render Persoon-detailscherm
- On error: show error banner with message ("BSN niet aangetroffen in BRP", "BRP momenteel niet bereikbaar", "U bent niet bevoegd voor deze lookup")

**Cache Indicator**:
- Small badge "⚡ van cache" next to Persoon-naam if responseInCache = true

#### Persoon Detail

**Layout**:
```
┌─────────────────────────────┐
│ Maria Wilhelmina van der Berg│  (or red geheimhoudings-icoon if geheimhouding)
├─────────────────────────────┤
│ Geboortedatum: 22 mrt 1978  │
│ Geboorteplaats: Utrecht     │
│ Geslacht: vrouw             │
├─────────────────────────────┤
│ Adres (if NOT geheimhouding):
│ Lange Voorhout 14-H
│ 2514 EA 's-Gravenhage
│
│ Adres (if geheimhouding):
│ [GEHEIM - alleen zichtbaar voor behandelaars met extra rechten]
│ [Toon adres onder verantwoording]  ← extra audit-entry
└─────────────────────────────┘
```

**Timeline Event**:
- Add "brp-lookup-uitgevoerd" event:
  - Text: "BRP-gegevens opgehaald (verzoekreden: {reason}, cache: {yes/no})"
  - **Never includes BSN in event text**

#### Admin Dashboard: BRP-Monitor

New tegel in admin area:

```
BRP Monitor
─────────────
Last 24 hours:
  Lookups: 147
  Cache hits: 89 (60%)
  Errors: 3 (2%)
  Avg response time: 342ms
  
Certificate expires: 2026-08-15 (185 days)
  Status: ✓ OK

[View detailed report]
```

---

### Seed Data

**BrpLookupVerzoek 1** (succesvol):
```json
{
  "bsn": "123456782",
  "verzoekreden": "Behandeling AVG-inzageverzoek artikel 15",
  "doelbinding": "Uitvoering wettelijke taak — Wet BRP art. 3.3 lid 1",
  "grondslag": "Publieke taak (AVG art. 6 lid 1 sub e)",
  "aangevraagdDoor": "medewerker:m.devries@gemeente-zeist.nl",
  "aangevraagdNamens": "afdeling:Burgerzaken",
  "verzoekTijdstip": "2026-08-14T09:23:45Z",
  "gekoppeldVerzoek": "verzoek-2026-08-14-1043",
  "gekoppeldContact": "contact-mvr-2026-002",
  "responseStatus": "geslaagd",
  "responseTijdstip": "2026-08-14T09:23:46Z",
  "responseDuurMs": 412,
  "haalcentraalCorrelationId": "hc-corr-7f3a9b2e",
  "responseBevatGeheimhouding": false,
  "responseInCache": false,
  "cacheVerlooptOp": "2026-08-15T09:23:46Z"
}
```

**BrpPersoon 1**:
```json
{
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
    "postcode": "2514 EA",
    "woonplaats": "'s-Gravenhage",
    "land": "Nederland"
  },
  "indicatieGeheim": "0",
  "opgehaaldOp": "2026-08-14T09:23:46Z",
  "bronsysteem": "HaalCentraal-BRP-v2.0",
  "lookupVerzoekId": "brp-lookup-2026-08-14-9bd1",
  "gekoppeldContact": "contact-mvr-2026-002",
  "retentieTot": "2026-08-21T09:23:46Z"
}
```

**BsnAuditRecord 1** (succesvol):
```json
{
  "actie": "brp-lookup-uitgevoerd",
  "bsn": "***45678*",  // masked in display
  "actor": "medewerker:m.devries@gemeente-zeist.nl",
  "actorRol": "behandelaar-burgerzaken",
  "tijdstip": "2026-08-14T09:23:46Z",
  "verzoekreden": "Behandeling AVG-inzageverzoek artikel 15",
  "doelbinding": "Uitvoering wettelijke taak — Wet BRP art. 3.3 lid 1",
  "uitkomst": "geslaagd",
  "responseCode": 200,
  "ipAdres": "10.42.18.7",
  "userAgent": "Pipelinq/2.4.1 (Nextcloud)",
  "haalcentraalCorrelationId": "hc-corr-7f3a9b2e",
  "gekoppeldVerzoek": "verzoek-2026-08-14-1043",
  "vogScreening": false,
  "bewaartot": "2031-08-14T09:23:46Z"
}
```

**BsnAuditRecord 2** (niet-gevonden):
```json
{
  "actie": "brp-lookup-uitgevoerd",
  "bsn": "***78901*",
  "actor": "medewerker:j.smith@gemeente-arnhem.nl",
  "actorRol": "behandelaar-burgerzaken",
  "tijdstip": "2026-08-14T10:15:22Z",
  "verzoekreden": "Reguliere verzoekbehandeling",
  "doelbinding": "Publieke taak (AVG art. 6 lid 1 sub e)",
  "uitkomst": "niet-gevonden",
  "responseCode": 404,
  "haalcentraalCorrelationId": "hc-corr-9e2b4c1f",
  "gekoppeldVerzoek": "verzoek-2026-08-14-2156",
  "vogScreening": false,
  "bewaartot": "2031-08-14T10:15:22Z"
}
```

**OptOutVlag 1** (geheimhouding):
```json
{
  "bsn": "***45678*",
  "type": "geheimhouding-gemeente",
  "bron": "BRP",
  "ingangsdatum": "2024-11-01",
  "einddatum": null,
  "beperkt": ["commerciele-derden", "kerkgenootschappen"],
  "lokaalOpgevoerdDoor": null,
  "notitie": null
}
```
