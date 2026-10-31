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

## Drafts: a half typed contact moment is kept

While you type in the quick log on a client, the form is kept as a draft two seconds after you stop typing, and again when you close or leave the tab. The next time you open the quick log for that client, it offers the draft back: **Restore draft** puts your text in the form, **Discard** throws it away.

- A draft is yours. Colleagues never see it.
- A draft is not in a ticket list, a queue or a report. It appears there the moment you save it.
- Save turns the draft into the contact moment. Nothing is copied, so the contact moment keeps the draft's history.
- A draft you leave alone for seven days is removed.
- If your session ends while you type, Save keeps your text on screen and says so. Log in again in a new tab, come back, and press Save again.

For administrators: the draft is the ticket itself, stored in OpenRegister with status `draft`. The `ticket` schema sets `draftLifetimeDays: 7` and `draftsListed: false` (`lib/Settings/register.d/99-zzz-contact-moment-drafts.json`). OpenRegister's daily draft cleanup removes old drafts, so pipelinq runs no job for it. This needs an OpenRegister version with the draft owner action.

## Change History

- **2026-10-12**: The quick log keeps a half typed contact moment as a draft of the ticket itself and saves it through OpenRegister's owner action (contact-moments-keep-draft)
- **2026-03-25**: Completed backend service, fixed frontend data flow (feature/65/contactmomenten)
- **2026-03-22**: Initial implementation of views, store, schema, navigation (archived)
