# pipeline Specification (delta)

## ADDED Requirements

### Requirement: Pipelines are saved without null optional fields (REQ-RF-010)

The default pipelines and the pipeline form SHALL leave an empty optional
field out of the saved pipeline rather than sending null. A mapping without
a stage total SHALL have no `totalsProperty`. A stage without a probability
SHALL have no `probability`. The default sales pipeline's totals label SHALL
be the reporting currency.

#### Scenario: A fresh install gets its default pipelines

- GIVEN a fresh install with the pipeline schema configured
- WHEN the default pipelines are created
- THEN OpenRegister accepts the Sales Pipeline and the Service Requests pipeline

#### Scenario: A pipeline without a totals property saves

- GIVEN an admin adds a mapping and leaves the totals property empty
- WHEN they save the pipeline
- THEN the pipeline is stored
