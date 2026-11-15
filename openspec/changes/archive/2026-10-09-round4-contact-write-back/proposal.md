# Proposal: round4-contact-write-back

## Why

The round 3 cloud check (9 October) found that editing a client's name, email
or phone saves the client, but the Nextcloud contact keeps its old values.
`POST /api/contacts-sync/write-back` answers 500 ("Write-back to Contacts did
not complete") and the page shows nothing, so the user believes the contact
has the new details.

The cause, reproduced on a local instance with a client whose contact already
existed: the writer passed the contact's `UID` to
`IAddressBook::createOrUpdate()` but no `URI`. Nextcloud's `AddressBookImpl`
only updates a card by its `URI`; without one it creates a new card, and
`CardDavBackend::createCard()` refuses a second card with the same UID
("VCard object with uid already exists in this addressbook collection"). The
writer caught that, returned null, and the controller answered 500. Every edit
of a client that already had a contact failed this way.

## What changes

- `ContactVcardWriterService` looks up the card with the linked UID in each of
  the user's addressbooks and updates it by its `URI`, in the addressbook that
  holds it. Only when no addressbook holds the UID does it create a card under
  that UID, in the first writable addressbook that is not the system
  addressbook. A card in a read-only addressbook is not written. The
  contact-first create path (`writeVcard()`) uses the same lookup.
- When the write-back fails, the user sees an error toast: "Your changes are
  saved here, but not in Nextcloud Contacts. The contact there still has the
  old details." (en + nl). The client stays saved.

## Out of scope

Round 4 items 2 (the "Task changed: {{subject}}" placeholder) and 3 (Related
on the lead page loads in about 9 s) were traced to OpenRegister and the
library and are reported to those lanes; see the PR body.
