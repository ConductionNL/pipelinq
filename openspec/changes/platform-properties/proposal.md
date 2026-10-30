---
kind: code
---

# Proposal: platform-properties

## Summary

Six capability rows describe what pipelinq is rather than a screen in it: it runs on your own server, its source is open, a colleague costs no seat fee, it lives in the Nextcloud workspace, it uses the Nextcloud login and it follows the house style. All six are built, but no requirement says them, so nothing can cite them and nothing stops a later change from breaking one. This change writes them down as a `platform-properties` spec, adds the `@spec` tags to the code that carries each property, and adds the checks that keep them true.

## The rows this covers

Source: pipelinq `openspec/parity/capabilities.json`.

- **plat-self-host** Run the whole thing on your own server (built: `appinfo/info.xml`).
- **plat-source-code** Read and change the source code (built: `LICENSE`, `<licence>EUPL-1.2</licence>`).
- **plat-no-seat-fee** Add a colleague without the bill going up (built: no seat count in `lib/`).
- **plat-in-workspace** Use the CRM in the workspace where your files and calendar already are (built: `lib/AppInfo/Application.php`).
- **plat-existing-login** Log in with the account you already use for everything else (built: `appinfo/routes.php`, Nextcloud auth).
- **plat-house-style** Match the app to your house style without touching the code (built: Nextcloud theme variables).

## What changes

1. A new main capability `platform-properties` with one requirement per property.
2. `@spec` tags on the files that carry each property.
3. Two checks that keep the properties true: a unit test that reads `appinfo/info.xml` for the licence and the absence of an external service dependency, and a lint check for hard-coded colours in `src/`. The second has work to do: a grep on development finds hex colours in 31 `.vue` files, which the house-style requirement forbids.

## Screens

None. These are properties of the app; see `screens.md`.

## Out of scope

- Theming itself (thematiq and Nextcloud own it).
- Pricing and support contracts, which are not software behaviour.
