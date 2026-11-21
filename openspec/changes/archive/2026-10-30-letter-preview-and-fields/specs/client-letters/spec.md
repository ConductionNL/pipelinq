## ADDED Requirements

### Requirement: The letter dialog previews the letter and lists what was filled in

When a template is chosen, the letter dialog SHALL show filinq's preview of the letter for the client, and the ticket and contact if chosen. Beside the preview it SHALL list the fields filinq filled, each with its value and where the value came from. It SHALL add one line that says either that all fields are filled or which are still empty. A preview SHALL NOT make, file or log a letter. When the choice changes, the preview SHALL follow it.

#### Scenario: every field is filled
- GIVEN a client with a template whose four fields filinq fills
- WHEN the user opens the letter dialog
- THEN the preview shows the letter and the line reads "Alle 4 velden ingevuld, niets ontbreekt"

#### Scenario: a field stays empty
- GIVEN a template that prints an appointment the client does not have
- WHEN the preview is made
- THEN that field reads "Niet ingevuld" with its source, and the line names it as still empty

### Requirement: A letter can be downloaded without being filed

The dialog SHALL offer "Pdf downloaden". It SHALL return the letter as a PDF without a copy in the user's Files and without a contact moment on the client.

#### Scenario: download only
- GIVEN a chosen template
- WHEN the user chooses "Pdf downloaden"
- THEN the PDF downloads, and nothing is filed or logged
