---
kind: code
---

# Proposal: store-installed-and-updates

## Summary

pl2 PqStore: the store page now matches its board.

- **Page:** the library's store page type (CnStorePage) replaces pipelinq's own StoreGallery. Its description counts the registry ("Templates and connectors for Pipelinq, {count} in the registry"), it has a Connectors lens, and the three built-in templates are named.
- **Installed and updates:** pipelinq records what this instance installed. After an install that wrote every component, `StoreInstallLedger` keeps the item's slug and version in app config (`store_installed`). Search marks each card `installed`, `installedVersion`, `updateAvailable` (the registry's version is newer) and `openUrl` (where an item of that kind is used). It also returns `checkedAt`, the last time the registry answered.
- **Store items:** a `commercial-template` schema joins the pipelinq register (register.d/96).
- **Example data:** the Zuiddrecht set points the store at this instance (`registry_url` `local`, read through OpenRegister's LocalStoreRegistry). It holds the board's nine items and declares five as installed, two of them behind the registry's version.

## Depends on

- **OpenRegister** feat/store-local-registry: the `local` registry. Without it, the set's `registry_url` reads as an unreachable registry and the page falls back to the built-in templates.
- **nextcloud-vue** feat/store-page-board-shell: the board header, lens chips and side column. Older libraries draw the cards only, and ignore `kindLenses`.

## Not built

- **Publish:** pipelinq has no publish surface, so the board's Publiceren button is not drawn.
