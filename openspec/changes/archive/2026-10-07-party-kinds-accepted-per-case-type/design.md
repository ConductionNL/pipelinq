# Design: party kinds accepted per case type

## D1. Three places the list could live, and why it lives here

1. **In the case app.** dossiq declares `melder`, `gemachtigde`,
   `belanghebbende`. Then pipelinq declares its own for a CRM context, and a
   municipality running both has two vocabularies for one idea. A schema slug is
   global per organisation, so the second declaration does not sit beside the first,
   it collides with it. The fleet audit of 2026-09-05 found that collision eighteen
   times.
2. **In openregister.** It is generic enough to belong there. But a party kind is a
   property of the party model, and the party model is pipelinq's under the
   ownership rule. openregister holds the contact schemas the kinds are shaped from,
   which is the half the cluster assigns to it.
3. **In pipelinq, with the acceptance declared by the consuming app.**

Option 3, which is exactly what the cluster's mechanism says: "extend openregister's
contact schemas and pipelinq's contact registry; dossiq declares which kinds a case
type accepts".

## D2. The registry and the acceptance are two objects, deliberately

`partyKind` is a vocabulary entry. `partyKindAcceptance` binds kinds to one record
type of one consuming app.

Keeping them apart means pipelinq never learns what a zaaktype is. The acceptance
names its target as `<app>:<schema>:<type>`, a string pipelinq stores and matches and
never interprets. dossiq writes `dossiq:zaak:subsidie-aanvraag`; pipelinq compares
strings.

The alternative, a `caseTypes` array on the kind, would put every consuming app's
type list inside pipelinq's vocabulary and would grow without bound.

## D3. The picker follows, and the write refuses

Two halves, and only one of them is a rule.

- **The picker** reads the acceptance for the record type it was opened on and offers
  those kinds in the declared order. Where no acceptance exists it offers everything,
  because an app that has not declared anything must keep working.
- **The write** refuses a party link whose kind is not accepted for that record type,
  naming both. This runs whether the write came from the picker, an import, an API
  call or a flow.

A hidden option is a convenience and an API caller walks straight past it. The
candidate's row is "party kinds accepted per case type, **with the picker
following**", and the order in that phrase is the design: acceptance first, picker
second.

The default when nothing is declared is permissive on purpose. A restrictive default
would break every existing link on the day this ships, which is a migration nobody
asked for.

## D4. Cardinality is on the kind, per record

"At most one `aanvrager` per case" is true of the kind, not of the case type. Putting
it on the acceptance would let one case type allow two aanvragers and another allow
one, which is a difference nobody wants and everybody would eventually configure by
accident.

So `partyKind.maxPerRecord` is 1 or unbounded. A second link of a single-cardinality
kind is refused, naming the party that already holds it. Replacing is an explicit
act: end the first, then add the second.

## D5. Identity shape, and the party who has no account

A kind declares which identity shapes it takes: a natural person, an organisation, or
neither. `neither` is the interesting one, and the sweep has a separate `must` for it
(`C-parties-and-contacts-3`, also a matrix hole): "most melders never make an
account", and GLPI's passer is anonymous actors at
`src/CommonITILActor.php:68-76`, `users_id = 0` plus an alternative e-mail.

This change declares the property. It does not build the notifiable account-less
party, because notifying a party with no account is openregister's notification
dialect, not pipelinq's registry. Splitting it this way keeps this change M and keeps
the boundary where decision D19's sibling rule puts it: the owner holds the logic.

## D6. Order is declared, because a picker's first option is a default in practice

The acceptance carries the kinds in order. The first kind offered is the one most
handlers take, and on a subsidy case that should be `aanvrager` rather than whatever
sorts first alphabetically.

This is a one-field decision with a measurable effect on how many cases get the wrong
party kind, and it costs nothing to declare.
