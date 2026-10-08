# Design: typed fields and indicators on a party

## D1. Why the typed values do not go on the Nextcloud Contact

`unify-client-contact` made the Nextcloud Contact authoritative for identity and
demoted pipelinq's own name, e-mail and phone to denormalised mirrors. The obvious
next step would be to put custom fields there too.

It does not work. A vCard holds extension fields as untyped strings with no schema,
no validation and no way to declare a choice list. osTicket's passer is a **form**
attached to a user or an organisation, with typed fields, which is precisely what a
vCard cannot express.

So identity stays on the Contact and typed values hang on pipelinq's relationship
record, beside the mirrors already there. The line is: anything a phone's address
book should show is on the Contact, anything only this organisation cares about is
on the relationship record.

## D2. A field set is a declaration, not a second field engine

OpenRegister already validates properties against a schema, and `master-data-management`
already relies on OpenRegister annotations rather than app-side engines. Building a
custom-field runtime here would be the duplication ADR-011 and ADR-022 both warn
against.

So `partyFieldSet` declares which properties a party kind carries, and the values are
stored and validated the way every other property in the fleet is. An administrator
adding "vestigingsnummer" writes a field set entry; OpenRegister does the typing.

Field kinds: text, number, date, boolean, choice from a code list, and a reference to
another object. That is the vocabulary osTicket's form types cover, and it stops
short of the thirteen the case-type depth study measures, which is a different study
about case types and is CT-1's work in openregister.

## D3. An indicator is a vocabulary plus a value, not a tag

Three shapes were possible.

1. **A boolean per concern on the party.** `deceased`, `addressProtected`,
   `aggressive`. Every new concern is a schema change, and the list is a customer's.
2. **A tag.** No severity, no period, no source, and no declared effect on handling.
   The sweep's own framing rejects the tag shape for the project object for the same
   reason.
3. **A declared vocabulary with values against it.**

Option 3. `partyIndicator` is the declaration: code, label, severity, and the effects
it asserts. `partyIndicatorValue` is one instance: a party, an indicator, a period,
and the source that set it.

The source matters. "Overleden" set from a BRP lookup and "agressie-registratie" set
by a KCC supervisor are both true and are challenged differently.

## D4. The indicator resolves live, and never gets copied onto a case

The candidate says "shown on every case of theirs". The tempting implementation is to
stamp the indicator onto the case when the case is created.

That is wrong in both directions. A person who dies after the case opens keeps a case
that says they are alive, and an indicator lifted after a dispute leaves stale copies
on twenty cases. Neither is visible as a failure.

So there is no copy. A surface showing a party resolves that party's indicators at
read time, and a period on the value makes a lifted indicator stop showing on its own
date. This is the same argument `hours-leaf` makes against an aggregated total and
the agenda change in humaniq makes against a stored agenda: the derived thing is
derived, every time.

## D5. The effect is declared by pipelinq and enforced by the caller

An indicator can assert three things today:

| effect | the question it answers | who asks |
|---|---|---|
| `blocksOutbound` | may we send this person anything? | the app about to send |
| `blocksAddressPublication` | may this address be published? | the app about to publish |
| `requiresAcknowledgement` | must a handler confirm they have seen this? | the surface showing the party |

pipelinq answers. It does not intercept. Putting the enforcement in pipelinq would
mean pipelinq knowing about every outbound path in the fleet, which is exactly the
coupling the ownership rule exists to prevent.

The contract is one question with a clear answer: given this party and this act, is
it blocked, and by which indicator. A caller that does not ask is a defect in the
caller, and the gate for it belongs with the send path, not here.

## D6. The organisation tree follows Zammad's guards, because they are the hard part

`app/models/group.rb:27` in Zammad carries a parent, a materialised path, and guards
against a cycle and against depth. The object is trivial; the guards are where this
goes wrong.

- **A cycle** makes any recursive read hang. Refused on write, by checking the
  proposed parent's path.
- **Unbounded depth** makes a breadcrumb useless and a query slow. Capped, with the
  cap administered.
- **A moved node** must move its subtree's paths with it, in one act, or half the
  tree points at a parent that no longer holds it.

The path is materialised because the common read is "everything under this afdeling",
and doing that recursively per read against an object store is slow enough to matter
on a real municipal tree.

Row 5.7 in the ledger is the **internal** organisation. This is the **customer**
organisation, and the two stay separate: a gemeente's own afdelingen are org units,
and a housing corporation with six regional offices is a customer tree.

## D7. What a merge has to carry

`master-data-management` merges two parties reversibly, with a preview. Adding fields
and indicators to a party adds two things the merge must handle.

- **Field values** follow the existing survivorship rules: the winning value per
  field by source trust tier, with the loser retained for reversal.
- **Indicators are unioned, not survived.** If one record says the person is deceased
  and the other does not, the merged party is deceased. Picking a winner here would
  silently drop a safety flag, and a safety flag dropped by an algorithm is the worst
  possible failure of this change.

That asymmetry is deliberate and is written into the spec rather than left to the
merge implementation to infer.
