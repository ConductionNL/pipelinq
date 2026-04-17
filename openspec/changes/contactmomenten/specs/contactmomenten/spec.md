<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: omnichannel-registratie (Omnichannel Registratie)
     This spec extends the existing `omnichannel-registratie` capability. Do NOT define new entities or build new CRUD — reuse what `omnichannel-registratie` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Contactmomenten Specification (Delta)

## Purpose

This change completes the contactmomenten implementation by fixing the broken data flow in the list view, wiring the standalone form to the object store, ensuring the `ContactmomentenList.vue` (CnIndexPage-based) is used as the primary list, and adding a `ContactmomentService` PHP backend for permission-checked deletion.

**Standards**: VNG Klantinteracties (`Contactmoment`, `KlantContactmoment`, `ObjectContactmoment`), Schema.org (`CommunicateAction`)
**Feature tier**: MVP (fix existing, add backend service), V1 (client timeline integration fix)

## Delta from Base Spec

The base spec at `openspec/specs/contactmomenten/spec.md` defines the full requirements. This delta addresses implementation gaps:

### Gap 1: List View Uses Stub Component

The router imports `ContactmomentList.vue` which has `fetchData() { this.contactmomenten = [] }` -- it never loads real data. The proper `ContactmomentenList.vue` (using CnIndexPage + useListView) exists but is not routed.

### Gap 2: ContactmomentForm Save is No-Op

`ContactmomentForm.vue` calls `showSuccess()` but never persists to OpenRegister. Needs to use the object store `saveObject('contactmoment', data)`.

### Gap 3: No Backend Service for Permission-Checked Delete

The spec requires "Only the creating agent or an admin MUST be able to delete." No PHP `ContactmomentService` exists to enforce this server-side.

### Gap 4: Navigation Badge Not Implemented

The nav badge for unresolved contactmomenten is specified but the `MainMenu.vue` shows a static nav item with no count.

## Requirements (additions to base spec)

---

### Requirement: ContactmomentService Backend

The system MUST provide a `ContactmomentService` PHP service that handles permission-checked deletion of contactmomenten.

**Feature tier**: MVP

#### Scenario: Delete by creating agent

- **WHEN** the agent who created a contactmoment requests deletion
- **THEN** the service MUST delete the contactmoment from OpenRegister
- AND return success

#### Scenario: Delete by admin

- **WHEN** an admin user requests deletion of any contactmoment
- **THEN** the service MUST delete the contactmoment regardless of agent
- AND return success

#### Scenario: Delete by non-creator non-admin rejected

- **WHEN** a user who is not the creating agent and not an admin requests deletion
- **THEN** the service MUST throw an exception with HTTP 403
- AND the contactmoment MUST NOT be deleted

---

### Requirement: ContactmomentController API

The system MUST provide a `ContactmomentController` with a delete endpoint at `DELETE /api/contactmomenten/{id}`.

**Feature tier**: MVP

#### Scenario: Delete endpoint returns 200 on success

- **WHEN** an authorized user calls `DELETE /api/contactmomenten/{id}`
- **THEN** the controller MUST return HTTP 200 with `{ "success": true }`

#### Scenario: Delete endpoint returns 403 on unauthorized

- **WHEN** a non-authorized user calls `DELETE /api/contactmomenten/{id}`
- **THEN** the controller MUST return HTTP 403 with error message

---

### Requirement: Correct List View Routing

The router MUST use `ContactmomentenList.vue` (CnIndexPage-based) as the component for the `/contactmomenten` route.

**Feature tier**: MVP

#### Scenario: List view loads data from OpenRegister

- **WHEN** a user navigates to `/contactmomenten`
- **THEN** the CnIndexPage component MUST fetch contactmomenten via the object store
- AND display them in a sortable, filterable table

---

### Requirement: Form Persists to OpenRegister

The `ContactmomentForm.vue` MUST save contactmomenten via the object store, not just show a success toast.

**Feature tier**: MVP

#### Scenario: Form creates contactmoment

- **WHEN** a user fills the form and clicks Register
- **THEN** the form MUST call `objectStore.saveObject('contactmoment', data)`
- AND on success, navigate to the contactmomenten list
- AND on error, display the error message
