# Design: platform-help-and-partner-directory

## Context (read at pipelinq development ec6b0277)

- The onboarding walkthrough is a `walkthrough` block in `src/manifest.json:113` with `completionConfigKey: walkthrough_seen_version`.
- Documentation is a Docusaurus site under `docs/`; `publiccode.yml` and `appinfo/info.xml` name the vendor.
- App settings are stored through `IAppConfig`; the administration screen is `lib/Settings/AdminSettings.php` with its Vue admin entry.
- No course or partner data exists anywhere in the repo.

## Decisions

### D1. Content is configuration, held as app config

Courses and partners are two JSON lists in `IAppConfig` (`help_courses`, `help_partners`), validated on write: title required, links must be `https`, at most 50 entries each. They are not OpenRegister objects, because they are not CRM data, and they are per installation, so an installation can list its own local partner.

### D2. Ship one partner, no courses

`help_partners` defaults to Conduction (from `info.xml`). `help_courses` defaults to empty. The page never lists a course that does not exist.

### D3. A custom page in the manifest

The Help page is a manifest `custom` page with `HelpView.vue`, visible to every signed-in user; the administration section is a tab in the existing settings screen. The public read endpoint is `GET /api/help`, authenticated, no admin required; write is admin only.

### D4. External links are marked

Every link to a course or partner opens in a new tab with `rel="noopener noreferrer"` and an accessible note that it leaves the app. Partner contact email is a `mailto:` link.

## Declarative-vs-imperative decision

The lists are configuration, so they are data the administrator edits, not schema. The page is a component listed in the manifest.

