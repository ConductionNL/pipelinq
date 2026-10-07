# Design: one-contact-moment-on-several-cases

Kind: code. One property widened, one migration, one filter, two acts, one marker.

## D1. `caseReference` becomes a set

`contact-moments-on-pipelinq-schema` makes `caseReference` an ADR-048 semantic
reference to the `case` type. This change makes it an array of them: ordered, at least
one entry, no duplicates, every entry a semantic reference of the same type.

The migration is mechanical and idempotent: a string value becomes a one-element array
holding that value. No value is dropped and no reference is rewritten, because the
entries keep the same shape they had; only the cardinality moves. A record already
migrated is left alone.

`request` keeps the single reference. A verzoek converts into one case by definition,
and widening it would invite the same drift this change exists to prevent, one object
along.

## D2. One primary, named rather than positional

The set carries `primaryCaseReference`, one of its own members. A surface with room for
one case shows that one. Without it, "the first" becomes the answer, and "the first"
changes when somebody reorders the set, which is a silent move of a fact.

The primary defaults to the reference the contact moment was created against. Removing
the primary from the set requires naming a new one in the same act.

## D3. The filter reads membership, not equality

The leaf's list filter for a host object becomes a membership test over the set. Under
ADR-058 it stays a bounded query: the case side holds the index, so "the contact
moments of this case" is answered from the case's own reference list rather than by
scanning every ticket for a member that matches.

## D4. Two acts, and what they refuse

`fileOnAlsoCase` appends a reference. It records who appended it and when, on the
contact moment, so a shared record can say how it came to be shared.

`unfileFromCase` removes one. It refuses to empty the set: a contact moment with no
case is a record nobody can find again, so the last reference cannot be removed, only
replaced. It refuses to remove the primary unless the same call names the new one.

Neither act edits the contact moment's content. Appending a case is not a correction of
what was said on the phone.

## D5. The shared marker

A contact moment on more than one case renders a marker naming the others. This is not
decoration: somebody editing a shared record needs to know before they edit, not after.
The marker resolves each other case by its semantic reference, so a case the reader may
not see renders as a count rather than a title.

## D6. What is not here

The document half of row 6.25. A document on a case is a Files node in the case folder
with a dossiq projection, and the ownership rules put that on filinq and the platform.
It stays open against filinq's `case-documents-and-the-flat-list`.
