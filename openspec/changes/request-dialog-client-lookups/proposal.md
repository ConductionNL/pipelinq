---
kind: code
---

# Find the client in the new request dialog, or look them up in the BRP or the KVK

## Why

Board PqNieuwVerzoek draws Klant as a search field. Typing "de Vries" lists the matching clients under it, each with its kind and address, a known client marked "Bekend" with its open tickets. Under the list stand three pills: "Opzoeken in de BRP", "Opzoeken in de KVK" and "Nieuwe klant". Live has a plain client dropdown and only "Nieuwe klant", so a handler who does not find the caller must leave the dialog to look them up.

Both lookups exist server-side and are not reachable from this dialog:

- **BRP.** `POST /api/brp/lookup` (BrpController) looks a person up by BSN with a reason and a purpose binding, checks `brp.allowed_groups`, and writes the Wet BRP audit record. `BrpClientCheckDialog` uses it to verify a known client on the werkplek.
- **KVK.** `KvkApiClient` (`companyByNumber`, `searchByName`) serves organisation enrichment through OpenRegister's KvK source. No endpoint answers a single search from a dialog.

## What changes

- **Klant is a search field with a result list** (name, kind, address, open tickets, "Bekend" for an existing client), backed by the client search the dialog already uses.
- **"Opzoeken in de BRP"** opens the BRP lookup (BSN, reason, purpose binding), runs `POST /api/brp/lookup`, and on a match selects the existing client with that identity or creates a resident client from the found name and address. The BSN is not stored on the ticket. The lookup is offered only to users in `brp.allowed_groups`; the server keeps checking that before it asks the BRP.
- **"Opzoeken in de KVK"** searches the Handelsregister by name or KVK number through a new `GET /api/kvk/search` that wraps `KvkApiClient`, behind the same login and rate limit as the enrichment endpoints, and selects or creates an organisation client from the chosen company (KVK number, legal form, place).
- **"Nieuwe klant"** stays.
- The three pills are the tonal pill the board draws.

## Risks

- Personal data: the BRP result fills a client record. Only name and address are written, the lookup is audited as today, and the purpose binding is required.
- integriq: `brp-monitor-moves-to-integriq` retires pipelinq's direct BRP transport. This change calls the lookup endpoint only, so it is independent of where the transport lives.

## Out of scope

Bulk enrichment (organisation-enrichment) and the werkplek's identity check (brp-verifies-the-client) are unchanged.
