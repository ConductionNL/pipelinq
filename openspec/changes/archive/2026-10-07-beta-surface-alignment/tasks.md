# Tasks: beta-surface-alignment

- [x] Read `appinfo/info.xml`, `src/manifest.json` (nav/menu/pages), and skim
      `lib/Controller/` + `lib/Settings/pipelinq_register.json` to derive the
      real shipped feature list.
- [x] Locate and read the live product page source
      (`conduction-website/src/pages/apps/pipelinq.mdx` + NL translation) and
      `pipelinq/docs/intro.md`.
- [x] Grep-verify every integration/vendor claim on those two surfaces
      against `lib/`/`src/` (DocuDesk, Twinfield, AFAS, "LaunchPad",
      per-team/group filters on leads).
- [x] Build the canonical six-item feature vocabulary (clients/contacts,
      lead pipeline/deal-flow, quotes→contracts, contactmomenten,
      reporting/CSV export, dashboards).
- [x] Rewrite `conduction-website/src/pages/apps/pipelinq.mdx` (EN) to use
      the canonical vocabulary and drop fabricated claims; fix version
      label to match `info.xml`.
- [x] Rewrite `conduction-website/i18n/nl/docusaurus-plugin-content-pages/apps/pipelinq.mdx`
      (NL) to match, in real Dutch (not a literal re-translation of the old
      fabricated copy).
- [x] Rewrite `pipelinq/docs/intro.md` "What you get" bullets to match the
      canonical vocabulary.
- [x] Fix `pipelinq/docs/installation.md` Nextcloud version prerequisite
      (29+ → 28+) to match `appinfo/info.xml`.
- [x] Add an app-dependency comment to `appinfo/info.xml` documenting the
      verified OpenRegister/Deck/OpenConnector relationship (info.xsd has no
      native `<dependency>` tag for app-to-app deps; comment convention
      matches `procest/appinfo/info.xml`).
- [x] Confirm `img/app.svg` matches the brand app-icon convention (white
      fill, 24×24) — no change needed.
- [x] Write `proposal.md` documenting the canonical feature list, every
      surface edit, and a verified/removed claims table.
- [x] Note the out-of-scope decision point (docs/Features/* reflects a much
      larger product than "CRM"; left untouched, flagged for a product
      decision).
</content>
