# Design: pos-staff-pin-permissions

## Reuse Analysis

Before introducing new schemas and services, the following OpenRegister and platform capabilities
were verified for overlap:

| Platform capability | Assessment |
|---------------------|------------|
| `AuthorizationService` (OpenRegister) | Handles object-level RBAC for OpenRegister objects. Does NOT cover POS action-level permissions (void, discount, refund, no-sale) — no overlap. |
| `agentProfile` (Pipelinq schema) | Links Nextcloud users to CRM routing skills and workload. Scoped to CRM agent routing, not POS terminal authentication — no overlap. |
| `PropertyRbacHandler` (OpenRegister) | Field-level visibility rules based on Nextcloud groups. Does not support PIN auth or POS action gates — no overlap. |
| Nextcloud user sessions | Handles application login. POS terminals are shared devices where operators hot-swap by PIN mid-session — not covered by NC sessions. |

**Conclusion**: new `posRole` and `posStaff` schemas are required. No existing service can be
extended to cover POS staff authentication and action-level permission enforcement.

---

## Architecture

### Data Model

Two new schemas added to `lib/Settings/pipelinq_register.json`:

#### posRole

Defines a named set of POS permissions that can be assigned to multiple staff members.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Role display name (e.g., Kassamedewerker, Manager) |
| description | string | No | Optional description of the role |
| canVoid | boolean | No | Whether this role may void completed transactions (default: false) |
| maxDiscountPercent | integer | No | Maximum line-item discount percentage (0–100, default: 0) |
| canRefund | boolean | No | Whether this role may process refunds (default: false) |
| canNoSale | boolean | No | Whether this role may open the cash drawer without a transaction (default: false) |

#### posStaff

Represents a POS operator. Linked to an optional Nextcloud user account for deeper integration.
PIN is stored as bcrypt hash and is NEVER returned by API responses.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| displayName | string | Yes | Name shown on POS terminal after login |
| userId | string | No | Nextcloud user UID — links staff to a Nextcloud account |
| posRole | string | Yes | UUID reference to the staff member's posRole |
| pinHash | string | Yes | bcrypt hash of the 4–6 digit PIN (never exposed in API) |
| isActive | boolean | No | Whether this staff member may log in (default: true) |
| failedPinAttempts | integer | No | Consecutive failed PIN attempts (reset on success, lockout at 5) |
| lockedUntil | string | No | ISO timestamp until which login is locked after 5 failed attempts |

---

### Backend

#### PosRoleService (`lib/Service/PosRoleService.php`)

- `listRoles(): array` — List all posRole objects from OpenRegister
- `getRole(string $id): array` — Get single role
- `saveRole(array $data): array` — Create or update role; enforce constraint maxDiscountPercent ∈ [0, 100]
- `deleteRole(string $id): void` — Delete role; throw if any active posStaff references it

#### PosStaffService (`lib/Service/PosStaffService.php`)

- `listStaff(): array` — List all posStaff objects, stripping `pinHash` and `failedPinAttempts`
- `getStaff(string $id): array` — Get single staff object, stripping sensitive fields
- `saveStaff(array $data): array` — Create or update; bcrypt-hash PIN on write, never store plain text
- `deleteStaff(string $id): void` — Hard delete
- `validatePin(string $staffId, string $pin): array` — Verify PIN, enforce lockout logic, return role permissions on success
- `getPermissions(string $staffId): array` — Return role permission matrix for a staff member

#### PosStaffController (`lib/Controller/PosStaffController.php`)

All endpoints require `#[NoAdminRequired]` for staff auth; admin write operations require
`IGroupManager::isAdmin()` check.

| Method | URL | Auth | Action |
|--------|-----|------|--------|
| GET | `/api/pos/staff` | admin | List staff (pinHash stripped) |
| GET | `/api/pos/staff/{id}` | admin | Get staff detail (pinHash stripped) |
| POST | `/api/pos/staff` | admin | Create staff |
| PUT | `/api/pos/staff/{id}` | admin | Update staff |
| DELETE | `/api/pos/staff/{id}` | admin | Delete staff |
| POST | `/api/pos/staff/auth` | `#[NoAdminRequired]` | Validate PIN, return session token + permissions |

#### PosRoleController (`lib/Controller/PosRoleController.php`)

| Method | URL | Auth | Action |
|--------|-----|------|--------|
| GET | `/api/pos/roles` | `#[NoAdminRequired]` | List all roles |
| GET | `/api/pos/roles/{id}` | `#[NoAdminRequired]` | Get role detail |
| POST | `/api/pos/roles` | admin | Create role |
| PUT | `/api/pos/roles/{id}` | admin | Update role |
| DELETE | `/api/pos/roles/{id}` | admin | Delete role |

---

### Frontend

#### Admin views (settings surface)

**StaffList.vue** (`src/views/pos/StaffList.vue`)

Tabular list of staff members: display name, linked Nextcloud user, role badge, active/inactive
toggle, edit and delete actions. "Add staff" button opens StaffForm.

**StaffForm.vue** (`src/views/pos/StaffForm.vue`)

Create/edit form: displayName, userId (optional typeahead), posRole selector, PIN entry (masked,
4–6 digits), isActive toggle. On create the PIN field is required; on edit it is optional (leave
blank to keep current PIN).

**RoleList.vue** (`src/views/pos/RoleList.vue`)

Tabular list of roles: name, permission summary badges (Void / Discount XX% / Refund / No-sale),
staff count, edit and delete actions.

**RoleForm.vue** (`src/views/pos/RoleForm.vue`)

Create/edit form: name, description, canVoid checkbox, maxDiscountPercent slider (0–100),
canRefund checkbox, canNoSale checkbox.

#### POS terminal component

**PinLoginModal.vue** (`src/components/pos/PinLoginModal.vue`)

Full-screen PIN entry modal shown when the POS terminal requires staff identification.
Numeric keypad (accessible, keyboard-navigable), masked PIN display, "Cancel" button.
On success stores `{ staffId, displayName, permissions }` in Pinia posSessionStore.
On failure increments a local counter and shows remaining attempts. On lockout shows locked
message and retry timestamp.

#### Pinia store

**posSessionStore** (`src/store/modules/posSessionStore.js`)

Holds active staff session: `staffId`, `displayName`, `permissions` (canVoid, maxDiscountPercent,
canRefund, canNoSale), `expiresAt`. Cleared on logout or session timeout.

---

### Permission enforcement

A `useStaffPermissions()` composable wraps posSessionStore and exposes:
- `canVoid()` — returns boolean
- `canApplyDiscount(percent)` — returns boolean (true if ≤ maxDiscountPercent)
- `canRefund()` — returns boolean
- `canNoSale()` — returns boolean

Each POS action gate calls the relevant composable method. If the check fails, an `NcDialog`
confirmation is shown: "This action requires supervisor or manager authorisation. Ask a manager
to log in and complete this action." (V1 blocks; V2 adds manager override PIN inline.)

---

### Per-staff sales attribution

Each POS transaction object stores `staffMemberId` (UUID of the active posStaff record) at
creation time. A per-staff sales report endpoint aggregates transactions by `staffMemberId`.

Shillinq commission journal integration passes `staffMemberId` on each transaction line item
so shillinq can apply its commission rules.

---

## Seed Data

Seed data is included in `lib/Settings/pipelinq_register.json` under `components.objects[]`
using the `@self` envelope. Re-importing is idempotent (matched by slug).

### posRole — 3 seed objects

```json
{
  "@self": { "register": "pipelinq", "schema": "posRole", "slug": "pos-role-kassamedewerker" },
  "name": "Kassamedewerker",
  "description": "Standaard kassamedewerker zonder speciale bevoegdheden",
  "canVoid": false,
  "maxDiscountPercent": 0,
  "canRefund": false,
  "canNoSale": false
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "posRole", "slug": "pos-role-supervisor" },
  "name": "Supervisor",
  "description": "Kassasupervisor met beperkte kortings- en retourbevoegdheid",
  "canVoid": false,
  "maxDiscountPercent": 15,
  "canRefund": true,
  "canNoSale": true
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "posRole", "slug": "pos-role-manager" },
  "name": "Manager",
  "description": "Filiaalmanager met volledige kassabevoegdheden",
  "canVoid": true,
  "maxDiscountPercent": 100,
  "canRefund": true,
  "canNoSale": true
}
```

### posStaff — 5 seed objects

PINs shown are the plain-text values for test/demo purposes only; stored as bcrypt hash.

```json
{
  "@self": { "register": "pipelinq", "schema": "posStaff", "slug": "pos-staff-anna-de-vries" },
  "displayName": "Anna de Vries",
  "userId": "",
  "posRole": "pos-role-kassamedewerker",
  "pinHash": "$2y$12$seed-hash-anna",
  "isActive": true,
  "failedPinAttempts": 0
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "posStaff", "slug": "pos-staff-bob-janssen" },
  "displayName": "Bob Janssen",
  "userId": "",
  "posRole": "pos-role-kassamedewerker",
  "pinHash": "$2y$12$seed-hash-bob",
  "isActive": true,
  "failedPinAttempts": 0
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "posStaff", "slug": "pos-staff-carla-peters" },
  "displayName": "Carla Peters",
  "userId": "",
  "posRole": "pos-role-supervisor",
  "pinHash": "$2y$12$seed-hash-carla",
  "isActive": true,
  "failedPinAttempts": 0
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "posStaff", "slug": "pos-staff-david-visser" },
  "displayName": "David Visser",
  "userId": "",
  "posRole": "pos-role-manager",
  "pinHash": "$2y$12$seed-hash-david",
  "isActive": true,
  "failedPinAttempts": 0
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "posStaff", "slug": "pos-staff-emma-bakker" },
  "displayName": "Emma Bakker",
  "userId": "",
  "posRole": "pos-role-kassamedewerker",
  "pinHash": "$2y$12$seed-hash-emma",
  "isActive": false,
  "failedPinAttempts": 0
}
```

---

## Files Changed

### New files

- `lib/Service/PosRoleService.php`
- `lib/Service/PosStaffService.php`
- `lib/Controller/PosRoleController.php`
- `lib/Controller/PosStaffController.php`
- `src/store/modules/posSessionStore.js`
- `src/composables/useStaffPermissions.js`
- `src/components/pos/PinLoginModal.vue`
- `src/views/pos/StaffList.vue`
- `src/views/pos/StaffForm.vue`
- `src/views/pos/RoleList.vue`
- `src/views/pos/RoleForm.vue`

### Modified files

- `lib/Settings/pipelinq_register.json` — add `posRole` and `posStaff` schemas + seed objects
- `appinfo/routes.php` — add `/api/pos/staff` and `/api/pos/roles` routes
- `src/store/store.js` — register `pos-role` and `pos-staff` object types
- `src/router/index.js` — add POS staff and role admin routes
- `src/navigation/MainMenu.vue` — add POS staff management nav item (admin only)
