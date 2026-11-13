# Client retention

Clients and their contact persons get a retention term that starts when the
relationship ends. When the term has run out, OpenRegister puts the records on
a destruction list, a records officer approves or rejects the list, and
OpenRegister destroys what was approved.

## When the clock starts

A client records the moment its relationship ended in **Relationship ended**.
You do not fill it in yourself:

- Setting **Account status** to *inactive* stamps the date and time.
- Setting it back to *active* clears the date again.
- *Blocked* leaves the date as it is: blocking a client does not end the
  relationship, and does not revive it.

The term is two years from that date, after which the record is nominated for
destruction. A client whose relationship never ended has no destruction date,
so an active client is never listed, however old it is.

The term is declared on the client schema in OpenRegister. Changing it is a
schema change, not a setting in Pipelinq. The two years are a default; your
data protection officer decides the real term.

## What happens to contact persons

A contact person takes its date from the client it belongs to. When the
client's relationship ends, every contact person of that client gets the same
destruction date; when the client comes back, theirs is cleared too. A contact
person that belongs to no client has no destruction date.

## Who approves

Nothing is destroyed without approval. OpenRegister's destruction check puts
records past their date on a destruction list, and a records officer approves
or rejects each list through OpenRegister's archival endpoints. A record under
a legal hold is skipped, even on an approved list.

## Existing records

Clients and contact persons that existed before the term was declared are given
their retention metadata once, by the repair step that runs on upgrade. A
record that already carries a nomination is left as it is.
