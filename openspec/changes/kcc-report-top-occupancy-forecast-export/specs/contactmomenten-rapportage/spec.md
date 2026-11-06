## ADDED Requirements

### Requirement: The report lists the top 10 subjects
`GET /api/rapportage/top-subjects` SHALL return at most ten subjects of a period, most contact moments first, each with its count. It SHALL apply the team, channel and employee filters together.

#### Scenario: A week of contacts
- GIVEN contact moments on twelve subjects in a week
- WHEN a team lead asks for that week
- THEN the answer holds the ten subjects with the most contact moments, in order

#### Scenario: Filtered on a team
- GIVEN the filter team Burgerzaken
- WHEN the top subjects are read
- THEN only contact moments of that team count

### Requirement: The report shows occupancy per employee and hour
`GET /api/rapportage/occupancy` SHALL return, per employee and per hour of the working day, the share of that hour spent on contact moments in the period. An hour without data SHALL read as zero, never be left out.

#### Scenario: Two employees
- GIVEN two employees with contact moments on one day
- WHEN occupancy is read for that day
- THEN the answer holds one row per employee with a value for every hour

#### Scenario: A reader who may not see employees
- GIVEN a reader without the team lead role
- WHEN occupancy is read
- THEN the request is refused before any contact moment is read

### Requirement: The report forecasts the coming week
`GET /api/rapportage/forecast` SHALL return the expected contacts for each of the next seven days, from the daily average of past weeks. A closed day SHALL read as zero, and the answer SHALL hold the total beside the total of a normal week.

#### Scenario: A week with a public holiday
- GIVEN an average of 250 contacts a day and a public holiday on Monday
- WHEN the forecast is read
- THEN Monday and the weekend read 0, Tuesday to Friday read about 250, and the total reads about 1,000 against 1,250

#### Scenario: Too little history
- GIVEN fewer than two weeks of contact moments
- WHEN the forecast is read
- THEN the answer says there is too little history and holds no figures

### Requirement: The report exports to Excel and PDF
`GET /api/rapportage/export` SHALL take `format=xlsx` or `format=pdf` beside the CSV. The workbook SHALL hold a summary sheet, a sheet with one row per contact and a team sheet. The PDF SHALL be a formatted report for management. Both SHALL apply the filters on screen and the same access check as the report.

#### Scenario: Excel
- GIVEN a report for a week, filtered on one team
- WHEN the reader exports to Excel
- THEN the workbook holds the three sheets for that week and team only

#### Scenario: PDF
- GIVEN the same report
- WHEN the reader exports to PDF
- THEN the document holds the figures shown on screen

#### Scenario: An unknown format
- GIVEN `format=doc`
- WHEN the export is requested
- THEN the answer is a 400 and nothing is generated
