# Tasks: pipelinq-audit-admin-forms-pos

## Phase 1: Admin page

- [x] 1.1 Shillinq WIP and AP hand-offs follow the detected app; drop both webhook URL fields and keys; fix the xWiki message (A5)
  - **spec_ref**: `specs/admin-settings/spec.md#requirement-shillinq-hand-offs-follow-the-detected-app`
- [x] 1.2 Add a "Run the setup wizard again" card to the admin page (A1)
  - **spec_ref**: `specs/admin-settings/spec.md#requirement-the-setup-wizard-can-run-again-from-the-admin-page`

## Phase 2: POS and product display

- [x] 2.1 Translate the VAT rate names on the invoice split; English UI says VAT (B3)
  - **spec_ref**: `specs/pos-display/spec.md#requirement-pos-amounts-and-labels-follow-the-user`
- [x] 2.2 Format POS amounts in the setup currency and the user's locale; show product price and cost with a currency (B4)
  - **spec_ref**: `specs/pos-display/spec.md#requirement-pos-amounts-and-labels-follow-the-user`
- [x] 2.3 English POS menu and page titles; tender type by name; lead name on Used on deals
  - **spec_ref**: `specs/pos-display/spec.md#requirement-pos-amounts-and-labels-follow-the-user`

## Phase 3: Client and other forms

- [x] 3.1 The New client dialog prefills the typed name and returns to the form that opened it (D7)
  - **spec_ref**: `specs/client-forms/spec.md#requirement-one-client-dialog-that-returns-to-the-form-that-opened-it`
- [x] 3.2 Pickers in generic forms open the New client dialog (drop the Clients `createOverride`)
  - **spec_ref**: `specs/client-forms/spec.md#requirement-one-client-dialog-that-returns-to-the-form-that-opened-it`
- [x] 3.3 Client edit asks for name, email and phone and writes them back to the contact; industry from the list
  - **spec_ref**: `specs/client-forms/spec.md#requirement-client-edit-asks-for-name-and-email`
- [x] 3.4 No `language: 'nl'` default on client and contact
  - **spec_ref**: `specs/client-forms/spec.md#requirement-no-conflicting-language-on-a-new-party`
- [x] 3.5 Plain help in the client, contact, task and product forms; technical text kept in `x-notes`
  - **spec_ref**: `specs/client-forms/spec.md#requirement-forms-show-plain-help`
- [x] 3.6 New client and New lead show errors only after a change or a save attempt
  - **spec_ref**: `specs/client-forms/spec.md#requirement-errors-wait-for-the-user`

## Phase 4: Example data

- [x] 4.1 Example records' user fields point at existing users, so none are skipped
  - **spec_ref**: `specs/example-data/spec.md#requirement-every-example-record-imports`
- [x] 4.2 Move Vergunningen, WMO / Zorg, Goud-tier klant-SLA and the advice segment to the example data; drop the two skills from DefaultSkillService; four standard audiences (A6, Ruben 7 October)
  - **spec_ref**: `specs/example-data/spec.md#requirement-example-looking-reference-records-are-example-data`

## Phase 5: Identity write-back

- [x] 5.1 Record in unify-client-contact that name, email and phone are edited on the client page and written back (Ruben 7 October)
  - **spec_ref**: `specs/unify-client-contact/spec.md#requirement-req-pucc-004-the-system-shall-reuse-the-existing-contact-sync-pattern-and-keep-the-nextcloud-contact-authoritative`
