# Proposal: pipelinq-forms-review

## Why

Ruben reviewed the pipelinq forms on beta (2026-10-06). The forms showed
fields in alphabetical order, said BTW in an English installation, asked a
client's language twice, typed user ids and timezones by hand, showed a uuid
in the products Category column, and the getting-started tour created a
contact before there was a client to put it on.

## What changes

- B1: every field on product, client, contact, lead, leadProduct and crmTask
  carries an `order`. A product starts with its name and description.
- B2: `isMasterRecord` and `masterEntityRef` (client, contact, product) carry
  an `x-help` explanation, shown behind an (i) icon.
- B3: English says VAT. The `vatClass` title is "VAT class", its options carry
  `x-enum-labels` with the rate ("High (21%)"), and ProductForm says VAT.
- B4: the VAT rate per class is a setting (`vat_rates`) on the admin page. The
  POS catalogue prices with it and the product form labels follow it. Price
  descriptions say "in the reporting currency" instead of EUR.
- B5 and columns: the products Category column resolves the category name
  (`fkResolve`). The Products price column reads `unitPrice`; the Tasks
  columns read `subject`, `assigneeUserId` and `deadline`.
- D1, D7: `x-allow-create` on contact.client, lead.client, lead.contact and
  leadProduct.product.
- D2: `correspondenceLanguage` is `format: language` with `x-default:
  current-language`.
- D3: every timezone is `format: timezone`.
- D4: every property that holds a Nextcloud user id is `format: nc-user`;
  crmTask.assigneeGroupId is `format: nc-group`.
- D5: `language` is hidden from the forms; the repair step
  `NormalisePartyFormFields` copies it into an empty `correspondenceLanguage`.
- D6: `client.industry` is a list of sectors. The same repair step wraps a
  stored string. Seed data, the contact import and segment `equals` rules
  follow.
- D8: `client.parentOrganisation` is a `$ref` to client, so it renders as a
  searchable dropdown.
- The hand-written create client form asks industry (multi-select), account
  owner (user search, defaults to you), correspondence language (defaults to
  your language) and timezone.
- C2: the tour creates a client first, then a contact on that client, then a
  product and a lead. The create client dialog tells the tour it created one.

All presentation keys (`order`, `x-help`, picker formats, `x-default`,
`x-allow-create`) live in one fragment,
`lib/Settings/register.d/99-zz-form-presentation.json`.

## Waiting on nextcloud-vue

`x-help`, `x-allow-create`, `x-default` and the `nc-user`, `nc-group`,
`language` and `timezone` pickers are read by the nextcloud-vue release the
parallel library lanes are building. Until pipelinq installs it these keys are
inert: the fields render as before (text inputs), and nothing breaks.
OpenRegister's validator ignores formats it does not know.

## Risk

`client.industry` changes type. The repair step runs right after the register
import, before any other step saves a client.
