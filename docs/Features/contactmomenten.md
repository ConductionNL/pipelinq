# Contactmomenten

## Summary

Register every customer interaction (phone, email, chat, desk visit) as a structured contact moment. Provides complete contact history per person and organization.

## Standards

| Standard | Mapping |
|----------|---------|
| Schema.org | `CommunicateAction` |
| VNG Klantinteracties | `Contactmoment`, `KlantContactmoment`, `ObjectContactmoment` |

## Components

### Backend
- `lib/Service/ContactmomentService.php` -- Permission-checked delete (creator or admin only)
- `lib/Controller/ContactmomentController.php` -- REST API: `DELETE /api/contactmomenten/{id}`
- Schema defined in `lib/Settings/pipelinq_register.json` (contactmoment object)

### Frontend
- `src/views/contactmomenten/ContactmomentenList.vue` -- List view with CnIndexPage, filtering, sorting
- `src/views/contactmomenten/ContactmomentDetail.vue` -- Detail view with edit/delete
- `src/views/contactmomenten/ContactmomentForm.vue` -- Standalone registration form with channel-specific fields
- `src/components/ContactmomentQuickLog.vue` -- Reusable quick-log form for embedding in other views

### Data Model

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| subject | string | Yes | Subject of the interaction |
| channel | enum | Yes | telefoon, email, balie, chat, social, brief |
| outcome | enum | No | afgehandeld, doorverbonden, terugbelverzoek, vervolgactie |
| client | UUID | No | Reference to client |
| request | UUID | No | Reference to request |
| agent | string | Auto | Nextcloud user UID |
| contactedAt | datetime | Auto | Timestamp of interaction |
| duration | string | No | ISO 8601 duration |
| channelMetadata | object | No | Channel-specific data |
| notes | string | No | Internal notes |

## Change History

- **2026-03-25**: Completed backend service, fixed frontend data flow (feature/65/contactmomenten)
- **2026-03-22**: Initial implementation of views, store, schema, navigation (archived)
