# Tasks: a-contact-question-is-required-and-dated

- [x] 1.1 `askQuestion` names its required fields
  - Files: lib/Portal/PortalContributionProvider.php
  - Test: tests/Unit/Portal/PortalContributionProviderTest.php
- [x] 1.2 `askQuestion` names `occurredAt` as the field stamped at creation
  - Files: lib/Portal/PortalContributionProvider.php
  - Test: tests/Unit/Portal/PortalContributionProviderTest.php
- [x] 1.3 Live: ask a question on /contact/vraag and read "Gesteld" on Mijn vragen
  - Done 28 Nov 2026 on a sandbox instance (NC 34.0.6, PostgreSQL 16, openregister main, pipelinq/portaliq/dossiq development, Zuiddrecht example site, resident via portal dev-login): the question saved (POST 200), Mijn vragen (/mijn/pipelinq/questions) lists it with Status "Ontvangen" and Gesteld "28-11-2026", the ticket carries occurredAt 2026-11-28T20:39:14+00:00. "Uw vraag" carries no "niet verplicht" mark; sending with only a subject shows "Uw vraag is verplicht." and sends nothing.
