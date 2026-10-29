## ADDED Requirements

### Requirement: A customer's last contact is a derived segment field

`SegmentSignalService` MUST publish `pipelinqLastContactAt`, the latest `occurredAt` among the contact moments (tickets with `ticketType: interaction`) linked to the customer, as a date-time string. Pipelinq MUST NOT store it on the client.

A customer with no dated contact moment MUST resolve to nothing, so a rule "last contact after a date" leaves that customer out.

The customer audience of the segment builder MUST offer the field as "Last contact", a date, with the Before and After operators.

#### Scenario: The last contact is the newest contact moment

@e2e exclude a derived field has no rendered form of its own: the browser sees only the audience it produces. Asserted by tests/Unit/Service/Marketing/SegmentSignalServiceTest.php (testLastContactIsTheNewestContactMoment).
- **WHEN** a customer has contact moments on 10 March and 2 September, and a request ticket on 4 September
- **THEN** `pipelinqLastContactAt` is the 2 September moment

#### Scenario: A customer never contacted resolves to nothing

@e2e exclude same reason. Asserted by tests/Unit/Service/Marketing/SegmentSignalServiceTest.php (testLastContactIsNullWithoutAContactMoment).
- **WHEN** a customer has no contact moment
- **THEN** `pipelinqLastContactAt` resolves to nothing, and not to a date
