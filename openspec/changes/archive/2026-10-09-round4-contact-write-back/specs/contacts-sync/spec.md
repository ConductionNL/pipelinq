# contacts-sync delta: round4-contact-write-back

## ADDED Requirements

### Requirement: Write-back updates the existing card

The write-back of a client or contact that is linked to a Nextcloud contact
MUST update that contact's existing card, in the addressbook that holds it,
and MUST NOT create a second card with the same UID. Only when no addressbook
of the user holds the linked UID MAY a new card be created under that UID, in
a writable addressbook that is not the system addressbook.

#### Scenario: Editing the phone of a client whose contact exists

- GIVEN a client linked to a Nextcloud contact whose card has phone 010-1111111
- WHEN the user changes the client's phone to 010-2222222 and saves
- THEN the write-back answers success
- AND the contact's card has phone 010-2222222
- AND the addressbook still holds one card with that UID

#### Scenario: The card lives in another addressbook

- GIVEN the linked card is in the user's second addressbook
- WHEN the write-back runs
- THEN that card is updated
- AND no card is created in the first addressbook

#### Scenario: The card was deleted

- GIVEN no addressbook holds the linked UID
- WHEN the write-back runs
- THEN a card with that UID is created in a writable addressbook that is not the system addressbook

### Requirement: A failed write-back is shown to the user

When the write-back to Nextcloud Contacts fails, the user MUST be told that the
change is saved in Pipelinq but not in Nextcloud Contacts. The saved client
MUST stay saved.

#### Scenario: The server refuses the write-back

- GIVEN the user saved a change to a client's name, email or phone
- WHEN `POST /api/contacts-sync/write-back` fails
- THEN an error toast reads "Your changes are saved here, but not in Nextcloud Contacts. The contact there still has the old details."
- AND in Dutch "Uw wijzigingen zijn hier opgeslagen, maar niet in Nextcloud Contactpersonen. Het contact daar heeft nog de oude gegevens."
