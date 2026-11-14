---
kind: code
depends_on: []
---

# Proposal: screen-pop-incoming-call

## Summary

See who is calling while the phone rings. A card names the caller, and one click
opens their record. Until now the list of matched callers and the new-contact
form were built and no page opened them.

## Motivation

Decision 231 (Ruben, 12 October 2026) on question Q-pipelinq-cti-1: wire the
existing screen pop to the incoming-call card of board PqInkomendGesprek, as its
own small change.

`cti-screenpop-adapter` shipped the lookup (`POST /api/cti/screen-pop`), the
chooser (`ScreenPopModal`) and the new-contact form (`NewContactIntakeModal`).
Its spec records that nothing mounts either modal and nothing calls the lookup.
The telephony webhook already writes a contact moment for every ringing call, on
the line of the agent it rings for. So the missing piece is small: ask which call
is on my line, and show it.

## Scope

### In scope

- `GET /api/cti/incoming-call`: the open inbound call on the signed-in agent's
  own line, or none.
- The incoming-call card, mounted once in the app shell. It calls the screen pop
  with the number of that call and opens the match, the chooser or the
  new-contact form.
- The call's contact moment is linked to the record the agent opens.

### Out of scope

- A telephony adapter. Pipelinq's own platforms stay as they are. The fleet
  adapter lives in integriq (`kcc-cti-adapter`), and its calls reach the card
  through the generic call mirror of `kcc-agent-panel`.
- The call wrap-up. `CtiDispositionModal` belongs to board PqGesprekAfronden and
  keeps waiting for it.
- Answering the call from the browser. The agent picks up on their phone.

## Where the board and this change differ

- The board's line "Opnemen start een contactmoment met een timer. Je status
  wordt In gesprek." describes call control the app does not have. The contact
  moment starts when the platform reports the call. The card says what is true:
  the call is logged, open the client to see their requests.
- The board draws three facts under the caller (Open, Laatste contact, Wachtend).
  The lookup returns the record and no counts. Those rows are task 2.3, not
  built here.
- The card has a Close button the board does not draw. Without it an agent who
  takes no action keeps a card over their work until the call ends.
- For several matches the board draws the chooser open. Here the card says how
  many contacts match and the chooser opens on a click, so a ringing phone never
  puts a modal over a form the agent is typing in.
