> SUPERSEDED 2026-05-31: feature implemented; archived twin archive/2026-03-21-omnichannel-registratie. Archived as already-delivered.

# Proposal: omnichannel-registratie

## Problem

Pipelinq has no channel-aware contact registration UI. KCC agents cannot register interactions from any channel (phone, email, counter, chat, social media, letter) because:
- No registration form exists for the `contactmoment` entity
- No channel-specific metadata is captured (call direction, email thread ID, counter location)
- No call timer is available to record phone call duration
- No filterable list view exists for contactmomenten
- No activity timeline integration exists for client interaction history

54% of tenders explicitly require omnichannel contact registration.

## Proposed Change

Implement omnichannel contact registration with five deliverables:

1. **Channel-adaptive registration form** (`ContactmomentForm.vue`) — form fields adapt dynamically based on the selected channel; required fields and `channelMetadata` keys differ per channel type
2. **Call timer component** (`CallTimer.vue`) — MM:SS timer with start/stop/reset controls, auto-fills the ISO 8601 `duration` field when the `telefoon` channel is selected
3. **Channel-specific metadata** — structured `channelMetadata` per channel: call direction and number for telefoon; thread ID and direction for email; counter location and appointment ID for balie; platform and session ID for chat/social
4. **Contact moment list** (`ContactmomentList.vue`) — filterable list with channel icons, full-text search, date-range and agent filters, and CSV export via OpenRegister's built-in export
5. **Contact moment detail** (`ContactmomentDetail.vue`) — full interaction record with linked client, contact person, and request shown as clickable deep links

### Out of Scope

- Unified inbox (V1)
- Bulk registration (V1)
- CTI / telephony integration (Enterprise)
- Nextcloud Talk integration (Enterprise)
- Zaak auto-linking (Procest cross-app, V1)

## Impact

- **New files**: 4 Vue components/views
- **Modified files**: 3 (`pipelinq_register.json`, `src/router/index.js`, `src/navigation/MainMenu.vue`)
- **Backend changes**: None — all data access via OpenRegister API
- **Risk**: Low — frontend-only feature addition. The `contactmoment` schema is already defined in ADR-000. No changes to existing views.
