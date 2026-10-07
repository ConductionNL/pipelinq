# Proposal: example-data-out-of-the-register

kind: fix

## Why

An administrator set up pipelinq on cloud.conduction.nl, picked "None, I will set this up myself" at the example data step, and still got example data: products such as Cappuccino and T-shirt Conduction, the client Zonnereizen, tender leads, tickets and point of sale transactions.

The wizard was not at fault. Its "None" answer seeds nothing. The records came from the register descriptor itself: `pipelinq_register.json` and twenty `register.d` fragments carried 257 example records under `components.objects`. OpenRegister imports those on every install, every upgrade and every run of the "Provision data" step, whatever the administrator chose.

## What changes

- The register descriptor keeps only reference data every instance needs: refund reasons, POS roles, payment providers, tender types, agent skills, party indicators, billing categories, the default Sales pipeline, the AVG policy pack, SLA policies, message templates, the standard marketing audiences and the instance mail transport. 41 records.
- The example records move to `lib/Settings/pipelinq_example_register.json`, a descriptor of type `mock`. OpenRegister never imports a mock by itself.
- Loading example data (the wizard's dataset card or `occ pipelinq:demo:seed`) now also imports that descriptor, under its own configuration identity `pipelinq.demo`.
- `occ pipelinq:demo:seed --remove` also removes those records. That includes records an existing install received from the old register: they are found by schema and slug and removed only while their name still matches, so a record somebody renamed stays.
- Ten example contact moments named the retired `contactmoment` schema and were silently skipped on import. They are now tickets of type `interaction`, mapped the way the seeder and the ticket migration already map them.
- Eleven example leads had no `pipeline` (and eight no `client`), both required, so OpenRegister refused them on every install without an error. They now sit in an example pipeline, "Verkoop (voorbeeld)", with the default Sales stages, and the eight organisations they name are example clients.
- Gemeente Voorbeeld, Meridiaan Advies and Zonnereizen each existed twice: once from the base register and once from the time billing fragment. Each pair is merged into one client that keeps the billing fields.

Loaded on a clean instance, the example descriptor imports 262 records with none skipped.

## What does not change

Installs that already hold the example records keep them. Nothing is deleted on upgrade; an administrator removes them on request.

## Out of scope

The wizard's step layout (a load button per dataset card, dependency check, provisioning on the admin screen) is a separate change.
