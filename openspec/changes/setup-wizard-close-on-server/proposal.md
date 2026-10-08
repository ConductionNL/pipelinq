# Proposal: setup-wizard-close-on-server

kind: feature. Adopts `@conduction/nextcloud-vue` 2.71.0 and its `setup.dismissAction` (manifest schema 2.53.0), so a closed setup wizard stays closed in every browser.

## Summary

Until now CnAppRoot remembered a closed setup wizard in the browser's localStorage only. Every fresh browser, a colleague's laptop or a Playwright context, opened the wizard again while an optional step (example data, organisation) was still open. Ruben decided the close is recorded on the server for every app.

What changes:

1. `@conduction/nextcloud-vue` goes to `^2.71.0`, and the vendored `tests/schemas/app-manifest-v2.schema.json` is re-synced from the package (2.53.0).
2. `manifest.setup.dismissAction` is `dismiss-setup`. CnAppRoot posts it once when the wizard is closed or finished.
3. `SetupController::runAction('dismiss-setup')` (admin-only, like every setup action) stores the setup version in the app-config key `setup_dismissed_version`. It answers no step: closing the wizard is not a choice of example data or an organisation name, so no real choice is overwritten.
4. `GET /api/setup/status` returns `dismissed`: the stored version, or `false`. nextcloud-vue keeps the wizard closed while that version is at least `setup.version`, so a later version bump opens it again.
5. The admin card "Run the setup wizard again" reads neither record, so it still opens the wizard after a close.

The library also brings the `config-fields` intro text, which the `organisation` step's `body` now shows.

## Out of scope

Answering the open optional steps on close (option (a) of the library). The organisation step has no neutral answer, and writing one would overwrite a real choice.
