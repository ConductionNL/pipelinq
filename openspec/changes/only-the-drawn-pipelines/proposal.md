# Proposal: only-the-drawn-pipelines

## Why

Ruben, 27 October: an instance with real pipelines keeps only the pipelines the PqPipelines board draws. On :8080 the pipeline list also held the shipped defaults ("Sales Pipeline", "Service Requests"), the demo seed's ("[Demo] Sales pipeline", "[Demo] Service pipeline") and the mock register's ("Voorbeeld Title 1..3"). "Sales Pipeline" is also marked as default and boards requests, so two pipelines claimed to be the default.

Two causes:

1. The repair step re-created the shipped defaults whenever no pipeline was titled "Sales Pipeline". Removing the shipped pipeline brought it back on the next `occ upgrade`.
2. Nothing removed mock pipelines from an instance that had collected them.

## What changes

- The repair step creates the shipped defaults only when the register holds no pipeline at all. A fresh, empty install still gets them, so the app works out of the box. An instance with its own pipelines (an example set's, or ones an admin made) does not get them back.
- `occ pipelinq:pipelines:remove-mocks [--dry-run]` removes the known mock pipelines. A mock is known by its slug (mock register) or by its exact title and description (shipped defaults, demo seed). A pipeline is only removed when no lead and no ticket is on it. Otherwise the command reports it, together with what is on it. It also reports when more than one pipeline is marked as default. The command is safe to run again.
- The Zuiddrecht example set already brings Verkoop and the two request boards. "Vragen en meldingen" is the default.

## Not changed

- The shipped defaults' content, and `pipelinq:demo:seed`.
- A renamed or re-described copy of a shipped pipeline counts as the owner's own and is never removed.

## Rollback

Revert the commit. Removed pipelines are soft-deleted by OpenRegister.
