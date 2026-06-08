# Tasks: pos-staff-pin-permissions

## 0. Deduplication Check

- [x] 0.1 Search `openspec/specs/` and `openregister/lib/Service/` for existing staff auth,
  role permission, or PIN management capabilities. Document findings (even if "no overlap found")
  before writing any code.

## 1. Schema Definition

- [x] 1.1 Add `posRole` schema to `lib/Settings/pipelinq_register.json` with properties:
  `name` (string, required), `description` (string), `canVoid` (boolean, default false),
  `maxDiscountPercent` (integer, 0–100, default 0), `canRefund` (boolean, default false),
  `canNoSale` (boolean, default false).
- [x] 1.2 Add `posStaff` schema to `lib/Settings/pipelinq_register.json` with properties:
  `displayName` (string, required), `userId` (string), `posRole` (string uuid, required),
  `pinHash` (string, required, writeOnly: true), `isActive` (boolean, default true),
  `failedPinAttempts` (integer, default 0), `lockedUntil` (string, date-time).
- [x] 1.3 Add both schemas to the register's `schemas` array.
- [x] 1.4 Add seed data to `components.objects[]`: 3 posRole objects (Kassamedewerker, Supervisor,
  Manager) and 5 posStaff objects (Anna de Vries, Bob Janssen, Carla Peters, David Visser,
  Emma Bakker) with Dutch names and bcrypt-hashed placeholder PINs.

## 2. Backend — PosRoleService

- [x] 2.1 Create `lib/Service/PosRoleService.php`:
  - `listRoles()` — fetch all posRole objects from OpenRegister
  - `getRole(string $id)` — fetch single role
  - `saveRole(array $data)` — validate maxDiscountPercent ∈ [0, 100]; create or update
  - `deleteRole(string $id)` — reject if role is assigned to any active posStaff
- [x] 2.2 Add `@spec openspec/changes/pos-staff-pin-permissions/tasks.md#2` PHPDoc tag to the class.

## 3. Backend — PosStaffService

- [x] 3.1 Create `lib/Service/PosStaffService.php`:
  - `listStaff()` — fetch all posStaff objects, strip `pinHash` from output
  - `getStaff(string $id)` — fetch single staff, strip `pinHash`
  - `saveStaff(array $data)` — bcrypt-hash PIN before save; skip hash if PIN omitted on edit
  - `deleteStaff(string $id)` — hard delete
  - `validatePin(string $staffId, string $pin)` — check `isActive`, `lockedUntil`, verify bcrypt;
    on failure increment `failedPinAttempts` and lock for 15 min at count 5;
    on success reset `failedPinAttempts` to 0 and return role permissions
  - `getPermissions(string $staffId)` — return role's permission matrix for a staff member
- [x] 3.2 Add `@spec` PHPDoc tag to the class.
- [x] 3.3 Extract `authorizeStaff(object, user)` helper: per-object auth check used in all
  mutation endpoints (pattern from ADR-005).

## 4. Backend — Controllers and Routes

- [x] 4.1 Create `lib/Controller/PosRoleController.php` with actions:
  `index`, `show`, `create`, `update`, `destroy`.
  - `index` and `show`: `#[NoAdminRequired]`
  - `create`, `update`, `destroy`: require `IGroupManager::isAdmin()` check
- [x] 4.2 Create `lib/Controller/PosStaffController.php` with actions:
  `index`, `show`, `create`, `update`, `destroy`, `authenticate`.
  - `authenticate` (POST `/api/pos/staff/auth`): `#[NoAdminRequired]`, no per-object auth needed
  - All other write actions: require `IGroupManager::isAdmin()` check
  - Strip `pinHash` from ALL responses; NEVER return it
- [x] 4.3 Add routes to `appinfo/routes.php`:
  - GET/POST `/api/pos/roles`
  - GET/PUT/DELETE `/api/pos/roles/{id}`
  - GET/POST `/api/pos/staff`
  - GET/PUT/DELETE `/api/pos/staff/{id}`
  - POST `/api/pos/staff/auth`
- [x] 4.4 Add `@spec` PHPDoc tags to both controllers.

## 5. Frontend — Pinia Store

- [x] 5.1 Register `pos-role` and `pos-staff` object types in `src/store/store.js` using
  `createObjectStore` (one each).
- [x] 5.2 Create `src/store/modules/posSessionStore.js` using Pinia `defineStore`:
  state: `staffId`, `displayName`, `permissions` (canVoid, maxDiscountPercent, canRefund, canNoSale),
  `expiresAt`.
  Actions: `openSession(data)`, `closeSession()`, `isSessionActive()`.

## 6. Frontend — Permission Composable

- [x] 6.1 Create `src/composables/useStaffPermissions.js`:
  - `canVoid()` — reads posSessionStore
  - `canApplyDiscount(percent)` — returns true if percent ≤ maxDiscountPercent
  - `canRefund()` — reads posSessionStore
  - `canNoSale()` — reads posSessionStore
  - Each method returns false if no active session

## 7. Frontend — POS Terminal Component

- [x] 7.1 Create `src/components/pos/PinLoginModal.vue`:
  - Full-screen modal with numeric keypad and masked PIN display
  - POST to `/api/pos/staff/auth` via `@nextcloud/axios`
  - On success: call `posSessionStore.openSession(response)` and emit `login-success`
  - On failure: show remaining attempts; on lockout show locked-until timestamp
  - Keyboard-navigable (WCAG AA)
  - All strings via `t(appName, '...')`; Dutch translations in `l10n/nl.json`

## 8. Frontend — Admin Views

- [x] 8.1 Create `src/views/pos/RoleList.vue`:
  - `CnIndexPage` with `useListView('pos-role', ...)` — columns: name, permission badges, staff count
  - Add role button opens RoleForm dialog
  - Delete shows `CnDeleteDialog` with staff-count warning if role is in use
- [x] 8.2 Create `src/views/pos/RoleForm.vue`:
  - `CnFormDialog` or `CnTabbedFormDialog` with fields: name, description,
    canVoid checkbox, maxDiscountPercent slider, canRefund checkbox, canNoSale checkbox
  - Works for both create and edit
- [x] 8.3 Create `src/views/pos/StaffList.vue`:
  - `CnIndexPage` with `useListView('pos-staff', ...)` — columns: displayName, role badge,
    active toggle, last login
  - Add staff button opens StaffForm dialog
- [x] 8.4 Create `src/views/pos/StaffForm.vue`:
  - Fields: displayName, userId (typeahead), role selector, PIN (masked, required on create),
    isActive toggle
  - On edit: PIN field optional (blank = keep existing)

## 9. Navigation and Routing

- [x] 9.1 Add routes to `src/router/index.js`:
  - `/pos/staff` → StaffList (name: PosStaffList)
  - `/pos/roles` → RoleList (name: PosRoleList)
- [x] 9.2 Add "POS Medewerkers" nav item to `src/navigation/MainMenu.vue` under admin settings
  section (only rendered when `isAdmin` is true).

## 10. Per-staff Sales Attribution

- [x] 10.1 Update transaction creation logic to write `staffMemberId` from the active
  posSessionStore when creating a transaction.
- [x] 10.2 Add a per-staff sales report endpoint: GET `/api/pos/reports/staff-sales`
  returning transactions grouped and totalled by `staffMemberId`.
- [x] 10.3 Verify that the shillinq commission feed includes `staffMemberId` on each
  transaction line item passed to shillinq.

## 11. Verification

- [x] 11.1 Run `npm run build` and verify zero errors.
- [x] 11.2 Run `php vendor/bin/phpstan analyse lib/` and verify no new errors.
- [x] 11.3 Manual browser test: create role → create staff → PIN login on POS terminal →
  attempt void (blocked) → apply discount exceeding limit (blocked) → logout.
- [x] 11.4 Run pre-commit checklist from ADR-015 (SPDX headers, ObjectService arg counts,
  no `$e->getMessage()` in responses, store registration, no raw fetch, component imports).
