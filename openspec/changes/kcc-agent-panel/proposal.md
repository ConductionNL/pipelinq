---
kind: code
---

# Proposal: kcc-agent-panel

## Summary

Pipelinq hosts a shared agent panel: when a call is routed through integriq's
`kcc-cti-adapter`, the panel shows who is calling and what is open for them,
sourced from integriq's call event and from OpenRegister. Any app that wants
caller context links to the panel instead of building its own.

Split out of dossiq's `contacts-domain` change (task 4.2, Tier B item B20) on
Ruben's 2026-09-11 decision: the panel is a contact-centre surface, not a
dossiq page, so it belongs with the app whose domain is contact-centre work.
This change designs the panel only. It does not touch integriq's CTI
protocol (`kcc-cti-adapter` is integriq's, referenced here, not restated) and
does not implement.

## Motivation

dossiq's `kcc-klantcontact-integratie` capability lets an agent log a contact
moment by hand, but nothing shows the agent who is calling before they pick
up (`contacts-domain` task 4.2, blocked). integriq's `kcc-cti-adapter`
(proposed, not yet built on either side) defines exactly that fact: a
`CallEvent` with the caller's resolved identity and open case references,
dispatched the moment a call rings. Something has to render it. Two things
make that something pipelinq rather than dossiq:

- More than one app wants this. dossiq's citizen desk and pipelinq's own
  commercial CRM desk both take inbound calls; a panel built inside dossiq
  would need rebuilding, or embedding, the moment a second app wanted it.
- Pipelinq already owns contact-centre concerns: `client`/`contact` records,
  a `ticket` supertype for open matters, and — separately — a full
  telephony adapter (`cti-screenpop-adapter`, shipped) for pipelinq's own
  PBX integration.

**This is not that adapter, and does not extend it.**
`cti-screenpop-adapter` is pipelinq's own direct integration with a
per-organisation telephony platform (CallVoip, RingCentral, Asterisk): a
webhook per platform, its own contact matching by phone number
(`CtiContactMatcher`), its own `ticket`-shaped contactmoment, navigation to
pipelinq's own `/customer-360/contact/{id}`. It solves "a call comes into
*this* pipelinq organisation's own PBX." The KCC panel solves a different
problem: "a call comes in through the fleet's shared KCC telephony bridge,
and the caller may not be a pipelinq client at all." The two integrations
stay separate because they answer to different telephony sources and,
today, different apps' data. A future change may converge them; this one
does not.

## Affected projects

- `pipelinq`: new `kcc-agent-panel` capability — a page, a webhook consumer
  for integriq's `CallEvent`, and a caller-identity resolver over
  OpenRegister.
- `integriq`: none. `kcc-cti-adapter`'s `CallEvent` and CloudEvent shape are
  read and referenced, not changed.
- `dossiq`: none in this change. `contacts-domain` task 4.2 is re-scoped
  (see that change's tasks.md) to link to this panel once both it and
  `kcc-cti-adapter` ship, instead of carrying the panel as a blocker.

## Scope

### In scope

- A pipelinq page that shows the identified caller and their open items for
  the duration of a call, fed by integriq's `CallEvent`.
- A caller-identity resolution that works whether or not the caller is a
  pipelinq client: pipelinq's own `client`/`contact` by phone number, and
  any OpenRegister row implementing the fleet's requester/customer semantic
  type by the identifying number integriq's adapter resolved.
- A plain, linkable URL any app can point at, with no shared component and
  no iframe.

### Out of scope

- integriq's CTI protocol, webhook verification, or caller-to-`partij`
  resolution (`kcc-cti-adapter` D1-D3). This change is a consumer of that
  contract.
- `cti-screenpop-adapter` (pipelinq's own PBX integration): no change, no
  shared code path, no migration.
- Click to dial, transfer, or any call-control action from the panel. The
  panel is read-only caller context; integriq's `kcc-cti-adapter` proposal
  itself defers click-to-dial.
- Implementation. Tasks below are unchecked; a piece is only built here if
  it is trivially small and clearly correct, which none of this is.
