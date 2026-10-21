# Channels as field lists

## Why

The emails, phone numbers and social profiles of a client or contact were drawn by an app-local section with two app-local dialogs. nextcloud-vue's `field-list` now adds, edits and removes entries of a list field, keeps one entry primary, checks a pattern, links an entry with `mailto:` or `tel:` and calls an endpoint after a save, so the same card is configuration.

## What changes

- ClientDetail and ContactDetail show a `tabs` card (Emails, Phones, Social profiles) whose tabs are `field-list` widgets, and a `facts` card with the preferred channel, timezone and language.
- Write email on an address opens the registered `WriteEmailModal`.
- Every save of emails or phones calls the Nextcloud Contacts write-back; when it fails the save stands and a notice says so.
- The single `email` and `phone` follow the primary entry of `emails` and `phones` in a pre-save listener, so every edit path keeps them equal, not only this card.
- Removed: `ContactChannelsSection`, `ContactEmailPhoneModal`, `ContactSocialProfileModal` and the `ContactChannelsWidget` registry entry.

## Needs

nextcloud-vue `field-list` with `edit`, checkbox fields, `add.exclusive`, `add.requireOneOf` and `row.href` schemes (branches `feature/pl-abstract-pl1-field-list` and `feature/pl-abstract-pl1-field-list-edit`).
