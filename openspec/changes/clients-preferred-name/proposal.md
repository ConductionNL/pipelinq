---
kind: code
depends_on: []
---

# Proposal: clients-preferred-name

## Summary

Record the name a resident or a contact person likes to be called by, and see
it wherever you address them. Today an administrator has to add a custom field
for it first, and nothing else in pipelinq knows that field exists.

## Motivation

One row of the pipelinq capability matrix (`openspec/parity/capabilities.json`,
compared 2026-09-24), decided `build` by the OpenSpec pass of 2026-09-27. It is
in the core area of the matrix (clients).

**`clients-preferred-name`**, "Record the name a resident likes to be called by".
Rated partial, built.state built. Matrix evidence: "no preferred name, roepnaam
or nickname property on client or contact (grep of lib/Settings and src for
preferred name, roepnaam, nickname, aanspreek); an administrator can add one as a
custom field (clients-custom-fields, lib/Service/RegisterResolverService.php) or
as a typed party field (lib/Settings/register.d/17-party-fields-and-indicators.json)".
Note: "not a shipped field; has to be configured". Demand: feature request,
https://github.com/Klantinteractie-Servicesysteem/KISS-frontend/issues/1469.
Competitor cells: odoo-crm yes, espocrm partial, kiss no, hubspot-crm and
pipedrive unknown.

- odoo-crm: source read, `odoo/addons/base/models/properties_base_definition_mixin.py:14`
  properties on `res.partner` with the Add Property button on the Contacts form
  (`res_partner_views.xml:196`) let an admin add a "Preferred name" field
  without code.

The missing half is a shipped field that the rest of the app uses. A custom
field holds the value but no screen, template or sync reads it.

## What changes

- Person clients and contacts get a `preferredName` field.
- It syncs both ways with the Nextcloud contact as the vCard `NICKNAME`
  property (RFC 6350).
- ClientDetail, ContactDetail and the telephony screen pop show it next to the
  full name.
- Mail and message templates can use `{{preferredName}}`, falling back to the
  full name when it is empty.

## Out of scope

- A salutation or gender field. `correspondenceLanguage` already exists
  (`register.d/18-correspondence-language.json`); a form of address is a
  separate decision.
- Moving values out of custom fields an organisation already made. The user doc
  explains how; no repair step guesses which custom field meant this.

## Impact

- `lib/Settings/register.d/` fragment adding `preferredName` to `client` and `contact`.
- `lib/Service/ContactVcardPropertyBuilder.php` and `lib/Service/ContactDataBuilder.php`.
- `lib/Service/TemplateRenderer.php` variable set.
- ClientDetail, ContactDetail and the CTI screen pop.
