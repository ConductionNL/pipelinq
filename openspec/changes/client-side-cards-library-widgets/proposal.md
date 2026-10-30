## Why

PqKlant and PqOrganisatie draw "Contactkanalen" and "Relaties" as side cards with "Toevoegen" in the title row. pq-b5o placed them with two app cards (`ContactChannelsCard`, `ContactRelationshipsCard`), which raised gate 52's custom widget count from 52 to 54. The person and organisation pages are shared with dossiq and shillinq, and pages are manifest config over library widgets, so the cards now come from nextcloud-vue.

## What changes

- ClientDetail `client-channels` is the library `field-groups` widget: e-mail addresses, phone numbers and social profiles as label and value pairs ("E-mail, privé", "Telefoon, mobiel", "Sociaal: Nog geen"), one Add menu in the title row, edit and remove per row. The plain `email` and `phone` follow the primary entry (`mirror`) and every save goes on to Nextcloud Contacts (`afterSave` write-back).
- ClientDetail `client-relationships` is the library `object-links` widget over the `relationship` schema: the type in words and the other party as a link; Add writes the relationship and its inverse, Remove deletes both.
- `ContactChannelsCard` and `ContactRelationshipsCard` leave the registry; the `headerAdd` path they used leaves `ContactChannelsSection` and `ContactRelationships`, which still serve the contact page.

## Impact

Needs nextcloud-vue with `field-groups`, `object-links` and field-list `pairs`/`mirror` (feat/ncv-contact-side-cards); an older library draws the two cards empty. New nl/en strings for the relationship type labels and the lowercase channel kinds. No PHP, no schema change.
