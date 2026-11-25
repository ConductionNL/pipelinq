---
kind: code
screens: [PqGeavanceerdHub]
---

# Proposal: advanced-personal-settings-card

## Summary

Board PqGeavanceerdHub closes the Beheerder group with a "Persoonlijke instellingen" card, marked "Alleen voor jou".
Personal settings live in the app's user settings dialog, not on a page of their own. The card opens that dialog through
the link card's `action: "user-settings"` (nextcloud-vue 2.165.0). The panel's "Alleen beheerders" badge takes the purple
tone the board draws.

## What changes

- `99-advanced.json`: a `PersonalSettings` card in the `admin` category, `action: "user-settings"`, status "Only for you",
  icon AccountCogOutline, without a permission so every user sees it.
- The panel badge gets `tone: "purple"`.
- en/nl strings for the card.

Needs nextcloud-vue with schema 2.165.0 (`feat/pq-c26-link-card-settings-action`).
