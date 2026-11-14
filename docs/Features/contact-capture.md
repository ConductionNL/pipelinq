# Contact capture (Paste or scan)

**Status:** Implemented (change `clients-contact-capture`)

## Overview

Paste an email signature, or take a photo of a business card, and get a new
client or contact form with the fields already filled in. You check the fields
and press Save. Nothing is stored before you do.

The **Paste or scan** action is in the header actions of the **Clients** and
**Contacts** lists.

## How it works

1. Choose **Paste or scan**.
2. Paste the signature or the text of a card into the text area and press
   **Read text**, or choose **Photo of a business card**. On a phone the photo
   input opens the camera; on a computer it asks for an image file.
3. Pipelinq proposes fields: name, organisation, job title, email, phone,
   website, street, postal code, city and KvK number. Every field can be
   edited.
4. Lines that could not be placed are listed under **Lines without a field**.
   Pick a field for each line you want to use; leave the rest as *Not used*.
5. Press **Use these fields**. The ordinary create dialog of the page opens
   with the values filled in, with its usual checks and duplicate warning.
   Press **Save** there to create the record.

### What fills which form

| Proposed field | New client | New contact |
|---|---|---|
| Organisation | Name (type Organisation) | - (pick the client in the form) |
| Name | Name (type Person) when there is no organisation; otherwise in the notes as the contact person | Name |
| Job title | In the notes | Role |
| Email, phone | Email, phone | Email, phone |
| Website | Website | - |
| Street, postal code, city | Address | - |
| KvK number | In the notes | - |

## How the text is read

- **Rules first.** Email addresses, phone numbers (written in international
  format, as telephony expects them), websites, a KvK number labelled as one,
  and a Dutch postcode with its city are found by fixed rules.
- **Then a language model, when available.** When the hermiq app is installed,
  it labels the remaining lines as name, organisation or job title. It can
  only pick lines from your text, never invent one. Without hermiq those lines
  are handed to you as lines without a field.

## Where a card photo goes

A photo is read by Nextcloud's text recognition task
(`core:image2text:ocr`, part of Nextcloud AI task processing):

- The photo is uploaded to Pipelinq and written as a **short-lived file in your
  own Nextcloud storage**, in the hidden folder `.pipelinq-contact-capture/`,
  because the recognition task reads files as you.
- The recognition task runs straight away. Afterwards the file and the
  recognition task are **deleted**, also when recognition fails. The photo is
  never stored in OpenRegister or attached to the new record.
- Which service reads the photo is decided by the **administrator's Nextcloud
  AI configuration**: a provider app installed on the server, or an external
  service the administrator connected. When that is an external service, the
  photo is sent there for recognition.
- When no provider for text recognition is installed, the dialog says that
  photos cannot be read on this server and only offers the paste option.

A photo can be at most 10 MB and must be an image.

## API

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/api/contact-capture/parse` | `text` (JSON or form field) or `file` (multipart photo) in, proposed fields out. Answers 409 when photos cannot be read, 413 when the text or photo is too large, 415 when the file is not an image, 422 when no text was found on the photo, 502 when recognition failed. |
| `GET` | `/api/contact-capture/status` | `{photo, maxPhotoBytes}`: whether photos can be read for you. |

Both need a signed-in user and store nothing.

## Specification

- Spec: `openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md`
- Design: `openspec/changes/clients-contact-capture/design.md`
- Tasks: `openspec/changes/clients-contact-capture/tasks.md`

## Related files

- `lib/Service/ContactCaptureService.php`, `lib/Service/ContactLineLabeller.php`: the field proposal
- `lib/Service/ContactPhotoReader.php`: the photo path
- `lib/Controller/ContactCaptureController.php`: the routes
- `src/modals/ContactCaptureModal.vue`: the dialog
