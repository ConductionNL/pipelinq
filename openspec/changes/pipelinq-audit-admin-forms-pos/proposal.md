# Proposal: pipelinq-audit-admin-forms-pos

## Why

The live audit of 7 October 2026 (audit-1, audit-2, audit-3) found these
problems on the admin page, in the create and edit forms, on the POS and in
the reference data:

- A5: the admin page asks for a Shillinq WIP webhook URL and a Shillinq AP
  webhook URL, even when Shillinq is not installed. The xWiki test message
  still says "Check the direct URL", a field that no longer exists.
- A1: nobody can reopen the setup wizard after closing it.
- A6: a fresh install ships reference records that read as example data.
- B3: the POS VAT split shows Dutch rate names to English users.
- B4: product prices show no currency, and the POS formats every amount as
  euros in Dutch.
- D7: creating a client from the lead form leaves the lead form and loses
  the typed name.
- A second, generic Create client form appears from the contact picker.
- A saved client carries `language: 'nl'` beside its correspondence language,
  and the industry field accepts free text.
- The client edit dialog offers no name or email.
- Create forms show schema jargon as help text.
- The POS shows Dutch labels and uuids in English, and the product detail
  shows lead uuids.
- The organisation step of the setup wizard shows no intro text.
- The demo seed skips 40 records whose user fields point at demo users that
  do not exist.

## What changes

- Shillinq hand-offs (WIP and AP) follow the detected Shillinq app. The two
  webhook URL fields and their app-config keys are gone. The admin page shows
  the Shillinq options only when Shillinq is installed; otherwise the
  Detected integrations card says it is not installed.
- The skills Vergunningen and WMO / Zorg, the SLA policy Goud-tier klant-SLA and the segment Advice customers without a product move to the example data (A6); DefaultSkillService no longer creates the two skills; the marketing change ships four standard audiences.
- Client name, email and phone are edited on the client page and written back to the Nextcloud Contact (unify-client-contact REQ-PUCC-004).
- See `tasks.md` for the full list per audit item.

## Out of scope

- The POS journal raise (`shillinq_journal_webhook_url`) is not on the admin
  page and keeps its current gate; moving it to detection needs its own
  change.
- CTI "API base URL" and Matomo "address" stay: they point outside the
  Nextcloud server (Ruben's decision).
