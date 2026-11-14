# Proposal: client-log-and-messages-board-form

## Why

pl6 on :8080 (PqKlant, PqOrganisatie): "Berichten" printed its title 24/600 where the board draws a 17/700 card title,
and "Contactmoment registreren" floated each label inside a 34px Nextcloud field with 13px grey labels on its selects
and 34px buttons. The boards draw both cards as board forms: labels 14/600 6px above 40px fields, text areas three
lines at 1.5, buttons 40px.

## What changes

- The inline contact-moment log form and the messages card opt into nc-vue's `cn-board-form`, so their text fields,
  text areas and buttons take the board settings card's look.
- The log form's own labels (Channel, Outcome, Request) are 14/600 in the main ink, 6px above the field, the fields
  14px apart.
- The messages card's title is 17/700 on its normal line in the board look.

## Reach

ContactmomentQuickLog (inline only) and MessagingConversationSection, board look only. The field look needs the nc-vue
release that carries `cn-board-form`; before it the class does nothing and only the labels and the title change.
