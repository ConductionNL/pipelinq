# Design: lead-management

## Status
implemented

## Architecture

### Data Layer
All data stored as OpenRegister objects in the `pipelinq` register using schema-validated JSON objects.

### Frontend
Vue 2.7 SPA with Pinia stores querying OpenRegister API directly. Uses `@conduction/nextcloud-vue` components.

### Backend
Nextcloud App Framework (PHP 8.1+). Thin client pattern -- Pipelinq provides UI/UX, OpenRegister handles persistence.

### Integration Points
- OpenRegister API for CRUD operations
- Nextcloud ICommentsManager for notes
- Nextcloud Activity/Notification APIs for events
- Nextcloud Contacts IManager for sync

## Components

See `specs/lead-management/spec.md` for detailed requirements and scenarios.

## i18n
All user-facing strings use `t('pipelinq', '...')` with translations in `l10n/en.json` and `l10n/nl.json`.

## Testing
Unit tests in `tests/Unit/Service/` for backend services. Frontend validated via browser testing.
