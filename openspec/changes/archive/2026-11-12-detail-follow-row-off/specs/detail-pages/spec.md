# detail-pages Delta: detail-follow-row-off

## ADDED Requirements

### Requirement: Simple detail pages draw no follow row

In the simple profile the task, Z report and social post pages SHALL draw no follow row under the page header, as the
ticket page. The full profile SHALL keep the follow row on those pages.

#### Scenario: Board PqTaak

- **WHEN** the agent opens the task "M. de Graaf terugbellen" in the simple profile
- **THEN** the header ends in its breadcrumb line, without "Volgen"
- **AND** the tabs start directly under the next-step card

#### Scenario: Full profile keeps following

- **WHEN** an administrator sets the full structure profile
- **THEN** the task, Z report and social post pages draw the follow row as before
