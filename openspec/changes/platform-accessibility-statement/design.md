# Design: platform-accessibility-statement

## Context (read at pipelinq development cfe0a0a51)

- **The only accessibility test is the portal's.**
  `tests/e2e/portal-accessibility.spec.ts:5` checks the portal's landmarks,
  skip link, live regions, form errors and keyboard order, and says at :20 that
  the axe-core sweep "requires @axe-core/playwright; reserved for the deploy/QA
  step". No test under `tests/e2e/` touches a back office page for
  accessibility.
- **axe-core is a dependency nobody uses.** `package.json:49` lists
  `"axe-core": "^4.13.0"` under `dependencies`; `git grep axe-core -- src tests`
  finds only the comment in the portal spec.
- **No statement anywhere.** `git grep -i toegankelijkheidsverklaring` finds
  nothing in `docs/` or `src/`. `docs/Features/government-compliance.md:118-127`
  lists A-01 "WCAG 2.1 AA", A-02 "EN 301 549", A-03 keyboard and A-04 screen
  reader as "Gepland (MVP)". `README.md:242` claims "Accessibility: WCAG AA"
  with no evidence behind it.
- **Four known keyboard traps are already specified.** The open change
  `keyboard-accessible-click-toggles` names `ProspectWidget.vue:3`,
  `ComplaintsOverviewWidget.vue:2`, `ProjectWbsTree.vue:28` and
  `FindClientWidget.vue:44`.
- **The back office.** `src/manifest.json` and `src/manifest.d/*.json` declare
  105 pages. The ones a KCC employee or account manager uses every day are
  Dashboard (`/`), KccWerkplek (`/werkplek`), MyWork, Queue, Tickets,
  TicketDetail, Clients, ClientDetail, Contacts, ContactDetail, Leads,
  LeadDetail, Pipeline and Tasks.
- **Admin page.** `lib/Settings/AdminSettings.php` renders
  `src/views/settings/Settings.vue` at `/settings/admin/pipelinq`, built from
  `NcSettingsSection` blocks.
- **Fleet guidance.** hydra `.claude/skills/team-qa/references/dutch-gov-compliance.md:18-25`:
  statements use status levels, audits follow WCAG-EM and stay valid three
  years. hydra `.claude/skills/team-frontend/references/dutch-gov-frontend-standards.md:10`:
  automated tools catch only part of the issues, so manual testing is needed.

## Decisions

### D1. Two documents with two owners

Conduction publishes an Accessibility Conformance Report for pipelinq, per
release: EN 301 549 clause 9, which is WCAG 2.1 AA, one row per success
criterion. The municipality publishes the toegankelijkheidsverklaring, because
the law puts that on the organisation that offers the service. pipelinq ships
a draft of that statement, in Dutch, filled in with what the report can say,
and leaves the organisation's own fields open.

### D2. A fixed sample, audited three ways

The sample is the fourteen daily pages in Context, plus the create and edit
dialogs of a client and a ticket. Each is checked by an axe-core sweep in
Playwright, by a keyboard-only walk, and by a manual screen reader pass (NVDA
on Windows and Orca on Linux) following WCAG-EM. The report says per criterion
which of the three decided it, because axe-core alone decides only a minority
of criteria.

### D3. axe-core injected, no new package

The sweep injects `axe-core`'s `axe.min.js` into the page with
`page.addScriptTag` and runs it scoped to WCAG 2.1 A and AA tags. The package is
already a dependency; `@axe-core/playwright` is not added. The sweep scopes to
the app's content root so Nextcloud's own header does not count against
pipelinq, the way the portal spec scopes to `.portal-app`.

### D4. Known issues are a list, not a silence

The suite reads an allow list of known violations, each with the change that
fixes it. The four controls of `keyboard-accessible-click-toggles` start on that
list, pointing at that change. A new violation fails the suite; a listed one
that no longer occurs fails it too, so the list cannot rot.

### D5. The admin page links, the app does not grow a page

The admin page gets one section, Accessibility, with the report link, the draft
statement link and the date of the last manual audit. Both documents live on
the docs site (`docs/compliance/`), so a release updates them without a code
change.

### D6. The sweep runs nightly, the audit per release

The axe and keyboard specs join the nightly e2e run on `development`. The
manual audit is repeated for each minor release and at least every three years,
and the report carries the version and date it covers.

## Risks

- Nextcloud's own chrome (header, app menu) can fail criteria pipelinq cannot
  fix. The report names those as third party, with the Nextcloud version.
- nextcloud-vue components carry most of the markup. A violation inside a
  shared component is fixed there; the report links the nextcloud-vue issue.
- A report that says "supports" for a criterion nobody checked by hand is worse
  than none. The report leaves a criterion "not evaluated" rather than guessing.
