# Contactmomenten — Design

## Overview

This change completes the contactmomenten feature by fixing the broken data flow, adding a backend service for permission-checked deletion, and ensuring all views are properly wired.

## Architecture

### Backend

#### ContactmomentService (`lib/Service/ContactmomentService.php`)

Handles business logic for contactmomenten operations requiring server-side authorization:

- **delete(string $id, string $currentUserId)**: Fetches the contactmoment from OpenRegister, checks if `$currentUserId` matches the `agent` field or is a Nextcloud admin (via `IGroupManager::isAdmin`). If authorized, deletes via `ObjectService`. If not, throws `NotPermittedException`.

**Dependencies:**
- `OCA\OpenRegister\Service\ObjectService` — CRUD operations
- `OCP\IGroupManager` — Admin group check
- `OCA\Pipelinq\Service\SchemaMapService` — Resolve register/schema IDs

#### ContactmomentController (`lib/Controller/ContactmomentController.php`)

Thin REST controller exposing:

| Method | Route | Description |
|--------|-------|-------------|
| `destroy(string $id)` | `DELETE /api/contactmomenten/{id}` | Permission-checked delete |

**DI:** `ContactmomentService`, `IUserSession`

### Frontend

#### Router Fix

Change the `/contactmomenten` route to use `ContactmomentenList` instead of `ContactmomentList`:

```js
// Before
import ContactmomentList from '../views/contactmomenten/ContactmomentList.vue'
// After  
import ContactmomentenList from '../views/contactmomenten/ContactmomentenList.vue'
```

The `ContactmomentList.vue` file becomes unused and should be removed.

#### ContactmomentForm Fix

Wire `ContactmomentForm.vue` save method to use the object store:

```js
const result = await this.objectStore.saveObject('contactmoment', payload)
```

Instead of the current no-op that just shows a toast.

#### Delete Flow

`ContactmomentDetail.vue` already has a delete button. Wire its `confirmDelete` method to call `DELETE /api/contactmomenten/{id}` instead of `objectStore.deleteObject()` to get server-side permission checking.

### Data Model

No schema changes. The contactmoment schema is already defined in `lib/Settings/pipelinq_register.json` with all required properties.

### Seed Data

Example seed contactmomenten for development:

```json
[
  {
    "subject": "Vraag over vergunning",
    "channel": "telefoon",
    "outcome": "afgehandeld",
    "agent": "admin",
    "contactedAt": "2026-03-25T09:15:00Z",
    "summary": "Burger belt over status bouwvergunning. Doorverwezen naar afdeling VTH.",
    "notes": "Verwacht reactie binnen 5 werkdagen.",
    "channelMetadata": {"richting": "inkomend", "gespreksduur": "PT4M23S"}
  },
  {
    "subject": "Klacht afvalinzameling",
    "channel": "email",
    "outcome": "vervolgactie",
    "agent": "admin",
    "contactedAt": "2026-03-24T14:30:00Z",
    "summary": "Inwoner meldt dat container niet is geleegd.",
    "channelMetadata": {"afzender": "inwoner@example.nl"}
  },
  {
    "subject": "Baliebezoek paspoort",
    "channel": "balie",
    "outcome": "afgehandeld",
    "agent": "admin",
    "contactedAt": "2026-03-24T10:00:00Z",
    "summary": "Paspoort aanvraag ingediend.",
    "channelMetadata": {"locatie": "Stadskantoor", "volgnummer": "A042"}
  }
]
```

## File Changes Summary

| File | Action | Purpose |
|------|--------|---------|
| `lib/Service/ContactmomentService.php` | Create | Permission-checked delete |
| `lib/Controller/ContactmomentController.php` | Create | REST API for contactmomenten |
| `appinfo/routes.php` | Edit | Add delete route |
| `src/router/index.js` | Edit | Fix list view import |
| `src/views/contactmomenten/ContactmomentForm.vue` | Edit | Wire save to object store |
| `src/views/contactmomenten/ContactmomentDetail.vue` | Edit | Wire delete to API |
| `src/views/contactmomenten/ContactmomentList.vue` | Delete | Replaced by ContactmomentenList |
| `tests/Unit/Service/ContactmomentServiceTest.php` | Create | Unit tests |
| `tests/Unit/Controller/ContactmomentControllerTest.php` | Create | Unit tests |
