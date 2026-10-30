# Churn risk and history summary

See which clients may leave, and why, before they go. Read a short written summary of a client's recent history before you call.

## Specs

- `openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md`

## Churn risk

Every client carries a risk level: low, medium or high. The client page shows it in the row of figures at the top, with the heaviest reason under it. The Clients list has a Risk column and an At risk filter, which lists every client at medium or high.

### The signals

Pipelinq adds up points from five signals. Each signal that counts becomes a reason, and each reason names the record behind it where there is one.

| Signal | Points | Window or cap |
|---|---|---|
| A contract in its renewal window (expiring) | 30 | while it is expiring |
| A contract that ended without renewal (churned) | 40 | ended in the last 90 days |
| An unhappy survey answer (detractor) | 25 each | last 180 days, at most 50 |
| Open complaints | 10 each | at most 30 |
| A ticket past its deadline | 15 | once, however many |
| No contact | 20 | nothing for more than 90 days |

A client with 60 points or more is high. From 30 points it is medium. Below that it is low.

A client that never had contact is counted from the day it was added, so a new client is not marked silent.

### When it is worked out

- Every night, for every client.
- At once, when a contract of the client changes status, or when a survey answer of the client is classified as unhappy.

Pipelinq only writes the client when the level or the reasons change. A quiet client is not rewritten every night, so its history stays readable.

### Changing the weights

An administrator can change any weight in app config, key `client_risk_weights`, as a JSON object. Keys you leave out keep their default.

```bash
occ config:app:set pipelinq client_risk_weights --value='{"silenceDays": 60, "high": 70}'
```

The keys are `contractExpiring`, `contractChurned`, `contractChurnedDays`, `detractor`, `detractorCap`, `detractorDays`, `complaint`, `complaintCap`, `slaBreached`, `silence`, `silenceDays`, `high` and `medium`. Pipelinq ignores unknown keys and negative values.

## History summary

`GET /apps/pipelinq/api/clients/{id}/history-summary?generate=true` asks hermiq for a few sentences about the client's last 90 days. It reads the client's contact moments, requests, complaints, leads and notes, newest first, at most 40 records. The answer says how many records it read and when it was written.

- Only someone who may open the client gets an answer.
- The records are read with that person's rights. A ticket they may not open never reaches hermiq, so it never shows up in the summary.
- Nothing is stored. The text is written on request and then forgotten.
- Without hermiq the endpoint answers `available: false`, and no summary card is shown.
- The text goes to whichever language model the administrator set up in hermiq.

The card that shows this summary on the client page waits for its board.
