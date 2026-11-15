# Consent and opt-outs

Pipelinq asks integriq before it messages a contact. Integriq keeps one opt-out and consent list for every Conduction app, so a person who unsubscribes in one app is not mailed by another.

## What is asked

| Message | Category | Needs consent |
|---|---|---|
| SMS and WhatsApp you send to a contact | `service` | no |
| An answer to the contact's own message | `reply` | no, and an opt-out does not stop it |
| A WhatsApp template | `service` | yes |
| A blast, journey step or list mail | `marketing` | yes |
| An appointment confirmation or reminder | `reminder` | no |
| A Berichtenbox email fallback | the message's category, default `service` | no |
| Password reset and other account mail | `account` | no, always sent |

Without integriq, pipelinq sends account and security mail and refuses everything else. The refusal is logged with the code `authority-unavailable`.

## Where wishes go

After the cutover, a STOP or START, a consent button, a list subscription and an unsubscribe go to integriq. A bounce stays in pipelinq: it says the address does not work, not what the person wants. Dunning suppression also stays in pipelinq and runs after integriq allows a promotional send.

When integriq refuses a write, pipelinq keeps the wish in its own store. A background job hands it to integriq every ten minutes, oldest first.

## The cutover

The setting `consent.store` decides who answers. It starts as `pipelinq`.

On upgrade, the repair step moves both old stores into integriq. Run it again by hand with:

```
occ pipelinq:consent:migrate
```

The command prints what it moved, what it skipped and what integriq refused. Each record carries its pipelinq id, so a second run adds nothing. Only a run without a refusal sets `consent.store` to `integriq`.

The old records stay in pipelinq as history.

## Rolling back

1. Set the flag back: `occ config:app:set pipelinq consent.store --value=pipelinq`.
2. Pipelinq reads its own records again at once.
3. Wishes recorded after the cutover live only in integriq. Open integriq's opt-out log and add the newer ones you need with the consent buttons on the contact.
4. To return later, run `occ pipelinq:consent:migrate` again. It skips what integriq already has.
