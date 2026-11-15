# Proposal: pipelinq-setup-wizard-review

## Why

Ruben reviewed the pipelinq setup wizard on beta (2026-10-06) and found five
problems:

- A1: loading example data took two steps, a choice and a separate run step.
  Each dataset card should carry its own Load button.
- A2: the wizard asked the administrator to provision the register. That is a
  repair action and belongs on the admin settings page.
- A3: the wizard should refuse to continue when a required app is missing.
- A4: the organisation step listed KvK before the name and asked for no
  address or contact details.
- A5: the wizard asked for a Shillinq base URL and an XWiki base URL. Both are
  knowable from the instance itself.

## What changes

- The `demo-data` choice step declares `loadAction: load-demo-data` and the
  separate `load-demo-data` run-action step is removed. The
  `load-demo-data` action accepts `{ dataset }` in its body, validates it
  against the offered datasets, and stores the pick only once the load has
  succeeded. Without a body it still loads the stored pick.
- The `provision` step is removed. A "Provision data" card on the pipelinq
  admin settings page runs the same `provision-register` action.
- The manifest `dependencies` keep `openregister` as the one required app.
  The wizard's dependency gate (nextcloud-vue) reads that list.
- The organisation step asks, in this order: organisation name, Chamber of
  Commerce (KvK) number, VAT number, street and number, postcode, city,
  country, email address, phone number, website. All are stored as
  `receipt_company_*` app-config keys. The receipt keeps reading
  `receipt_company_address` and composes the address from the new fields
  when it is empty.
- The `integrations` step and the `shillinq_app_url` and `xwiki_direct_url`
  keys are removed. `IntegrationDetector` finds Shillinq as an installed app
  and XWiki through the XWiki app or OpenRegister's `xwiki` integration
  (integriq). The billing deep link uses the detected Shillinq URL. The admin
  page shows both detections read only.
- `GET /api/setup/status` reports exactly the manifest's step ids.

## Waiting on nextcloud-vue

`loadAction` on a choice step and the dependency gate are added by parallel
nextcloud-vue lanes. Until pipelinq installs that release, the choice step
only records the pick. That pick answers the step, so the wizard never stays
open, and `occ pipelinq:demo:seed` loads the data.

## Out of scope

Existing installs that stored `shillinq_app_url` or `xwiki_direct_url` keep
the value in app config, but nothing reads it any more.
