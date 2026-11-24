# Client identifiers and filling in from the KvK

Keep an organisation's official numbers on its client record, see for each one
whether it was checked against its register and when, and fill in the empty
company details of many organisations at once from the KvK register.

## The schemes

An organisation can hold any number of identifiers. Each has a scheme and a
number:

| Scheme | Format | Checked against |
|--------|--------|-----------------|
| KvK number | 8 digits | The KvK register, through OpenRegister's KvK connection |
| RSIN | 9 digits that pass the eleven test | Format only |
| VAT number | Two letters of an EU country (NL, DE, …) and that country's VAT format, such as `NL123456789B01` | VIES, through shillinq, when shillinq is installed |
| DUNS number | 9 digits | Format only: there is no open DUNS register |
| OIN | 20 digits | Format only |

Spaces, dots and dashes are taken out of a number before it is stored, and a VAT
number is stored in capitals. A company may hold several VAT numbers, one per EU
country.

The format is checked every time the client is saved, wherever the save comes
from: the client page, the API, an import or a bulk job. A number that does not
match its scheme is refused with the reason, for example *The RSIN 123456789 is
not valid: it fails the eleven test.*, and the client keeps the numbers it had.

## Add a number

1. Open the organisation's client page.
2. In **Identifiers**, pick the scheme, type the number and press **Add**.
3. The number is listed with status **Not checked**.

**Remove** takes a number off the list.

## Check a number

A KvK number and a VAT number have a **Check** button.

- **KvK number**: the number is looked up in the KvK register. Found: **Valid**.
  Not known to the register: **Not valid**. When the register cannot be reached,
  for example because the KvK connection in OpenRegister is not configured, the
  status is **Register unavailable** and the number can be checked again later.
- **VAT number**: the number is checked with VIES through shillinq. Without
  shillinq the status stays **Not checked**, with the note that VAT numbers are
  checked when shillinq is installed. A valid VIES answer also records until when
  it may be relied on.

Each check records its status, the time and the register it was checked with on
the number, so the list always shows the last check. Checking needs the right to
change the client; a client you may not open cannot be checked.

## Fill in from KvK

Fill in the empty company details of many organisations at once.

1. Open **Clients** and tick the organisations.
2. Choose **Fill in from KvK**.
3. Press **Preview**. Nothing is written yet. The dialog shows how many records
   will change and how many are skipped.
4. Press **Confirm** to write the details.

For each selected organisation the KvK number is taken from its KvK identifier,
or else from its **KvK number** field, and looked up in the KvK. These fields are
filled, each only when it is empty:

| Field | From the KvK |
|-------|--------------|
| Place | The place of the visiting address |
| Legal form | The registered legal form |
| Industry | The SBI activities, the main activity first |

What it never does:

- It never overwrites a field that holds a value, not even with a different one.
- It never changes the client's name: the client's own name wins.
- It never writes the address: the address is the mirror of the linked Nextcloud
  contact and is changed there.
- It never looks up a person. Person data may only be read one person at a time
  and for a stated purpose.

An organisation is skipped, with the reason on the **Bulk changes** page, when it
has no KvK number, when the KvK does not know the number, when the KvK cannot be
reached, or when every field the KvK fills already holds a value. A skipped
organisation is not changed.

The fill runs as an OpenRegister bulk job, like **Change a field**, so it is
listed on **Bulk changes** and can be undone there for seven days: the undo puts
back the empty values the fill replaced. See [Bulk changes](bulk-changes.md).

## API

`POST /api/clients/{id}/identifiers/check` with `scheme` (`kvk` or `vat`) and
`value` checks one number of a client and answers the checked identifier. See
the API reference for the responses.
