# contacts-sync: delta for contacts-sync-settings

## ADDED Requirements

### Requirement: A user controls and sees their contacts sync

The user settings dialog MUST show a Contacts section with:

- the number of linked clients and contact persons;
- the addressbook new cards go to;
- a write-back switch, on by default;
- the last full run, with the records whose card could not be written.

With the switch off, a save MUST NOT write the linked card. A full run MUST write every linked record back, and MUST require the same CRM capability as a single write-back.

#### Scenario: write-back off

- GIVEN Pieter turned write-back off
- WHEN he saves Sanne de Vries's phone number
- THEN her Nextcloud contact keeps the old number
- AND no error is shown

#### Scenario: a full run with a failure

- WHEN Pieter clicks "Nu synchroniseren"
- THEN every linked record is written back
- AND the section names the record whose card could not be written
