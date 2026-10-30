# An event's form binding and "Formulier bewerken"

## Why

PqEvenement draws the registration form card with two buttons: "Pagina openen" and "Formulier bewerken". The event only knew the form's title and page URL, not which portaliq form binding publishes it, so there was nothing to edit the form through.

## What changes

- campaignEvent 1.2.0 gets an optional `formBinding`: the uuid of the portaliq `portalFormBinding` the sign-up form is published with (`register.d/99-zz-event-reminder-and-check-in.json`).
- The form card gets "Formulier bewerken", a navigate action to `/index.php/apps/portaliq/form-bindings/{formBinding}`. The facts widget does not draw an action whose `@source` field is empty, so an event without a binding shows no button.

## Depends on

- portaliq's FormBindingDetail page (`/form-bindings/:id`), already live.
