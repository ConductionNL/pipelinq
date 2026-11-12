# vng-klantinteracties-leaf Specification (delta)

## Purpose

Requirements for capability matrix rows whose behaviour no requirement stated yet. Rows: `gov-klantinteracties`.

## ADDED Requirements

### Requirement: Stored contact moments can be read in the VNG Klantinteracties format

The system SHALL serve stored contact moments through the VNG Klantinteracties klantcontacten endpoint, mapped from the contact moment fields.

#### Scenario: Reading a logged contact moment as a klantcontact

- GIVEN a contact moment logged in pipelinq with a subject, channel and time
- WHEN it is requested through the klantcontacten endpoint
- THEN it is returned as a klantcontact with onderwerp, kanaal and plaatsgevondenOp filled from the contact moment
