<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# MDM read-API reference

The MDM read-API lets downstream apps (Shillinq, Procest, …) look up the current
**golden record** for a master entity by `masterId`, by a pre-merge **alias**, or
by a **natural key** (KvK, VAT, email, phone, SKU). It is read-only: no mutations
are exposed to external consumers.

## Authentication

All endpoints require an authenticated Nextcloud session or bearer token. A request
without an authenticated user returns `401 Unauthorized`. Downstream apps
authenticate with admin-configured API credentials. Only `GET` is supported.

## Endpoints

### Query by masterId or alias

```
GET /api/mdm/master/{id}
```

`{id}` may be a live `masterId`, or a pre-merge id (alias) of an entity that was
merged away.

- If the id resolves to a **live** entity, the golden record is returned.
- If the id belongs to an entity whose `status` is `merged-into-other`, or is an
  alias of a surviving entity, the **survivor's** golden record is returned with an
  added `note` and the original `mergedFrom` id.
- If no entity is found: `404 Not Found`.

**Example — live entity**

```http
GET /api/mdm/master/550e8400-e29b-41d4-a716-446655440002
```

```json
{
  "masterId": "550e8400-e29b-41d4-a716-446655440002",
  "entityType": "account",
  "goldenRecord": {
    "name": "Voorbeeld B.V.",
    "kvkNumber": "12345678",
    "vatNumber": "NL123456789B01",
    "billingAddress": "Bedrijfsplein 10, 5678 XY Utrecht, NL"
  },
  "dataQualityScore": 0.92,
  "attributeProvenance": {
    "kvkNumber": { "value": "12345678", "sourceSystem": "kvk-api", "trustTier": "gold", "lastUpdated": "2026-05-10T11:45:00Z" }
  },
  "aliases": [],
  "status": "active"
}
```

**Example — merged / alias id** (returns the survivor)

```json
{
  "masterId": "550e8400-e29b-41d4-a716-446655440002",
  "entityType": "account",
  "goldenRecord": { "name": "Voorbeeld B.V." },
  "status": "active",
  "note": "This masterId was merged; current masterId is 550e8400-e29b-41d4-a716-446655440002",
  "mergedFrom": "550e8400-e29b-41d4-a716-446655449999"
}
```

### Query by natural key

```
GET /api/mdm/master?type={entityType}&{key}={value}
```

Resolves a single active master entity by a natural key. Supported keys per entity
type:

| entityType | Supported keys (query param → golden-record attribute) |
|---|---|
| `account` | `kvk` → `kvkNumber`, `vat` → `vatNumber`, `email` → `email`, `phone` → `phone` |
| `contact` | `email` → `email`, `phone` → `phone` |
| `product` | `sku` → `sku` |
| `vendor` | `kvk` → `kvkNumber`, `vat` → `vatNumber` |

If more than one key is supplied, the first match in the table order wins.

**Example**

```http
GET /api/mdm/master?type=account&kvk=12345678
```

Returns the same golden-record shape as the by-id endpoint.

**Status codes**

| Status | Condition |
|---|---|
| `200 OK` | Exactly one active match |
| `400 Bad Request` | Unknown/missing `type`, or no supported natural key supplied |
| `401 Unauthorized` | No authenticated user |
| `404 Not Found` | No master entity matches the natural key |
| `409 Conflict` | Multiple entities match — data-integrity issue, requires manual review |
| `500 Internal Server Error` | Lookup failed |

## Response shape

Every successful response is a projection of the master entity:

| Field | Type | Description |
|---|---|---|
| `masterId` | string | Stable master id of the (surviving) entity |
| `entityType` | string | `contact` / `account` / `product` / `vendor` |
| `goldenRecord` | object | Winning attribute values per the trust configuration |
| `dataQualityScore` | decimal 0–1 \| null | Completeness/freshness/agreement composite |
| `attributeProvenance` | object | Per-attribute winning source, tier, timestamp |
| `aliases` | array | Pre-merge ids that resolve to this entity |
| `status` | string | `active`, `merged-into-other`, `soft-deleted`, `quarantined` |
| `note` | string | (alias/merge responses only) human-readable merge note |
| `mergedFrom` | string | (alias/merge responses only) the requested pre-merge id |

## Rate limiting

The read-API inherits the host Nextcloud instance's request throttling; there is no
MDM-specific rate limit. Downstream apps should cache golden records and rely on the
outbound sync queue for change propagation rather than polling tight loops.

## See also

- [MDM administrator guide](../admin/master-data-management.md)
