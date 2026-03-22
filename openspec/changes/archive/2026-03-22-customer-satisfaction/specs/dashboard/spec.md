# Dashboard — Customer Satisfaction Delta

## ADDED Requirements

### Requirement: Customer Satisfaction KPI Card
The dashboard MUST display a satisfaction KPI card showing NPS score or average rating, color-coded by score level.

#### Scenario: Display NPS
- **WHEN** active surveys have NPS responses
- **THEN** the KPI card MUST display the NPS score with appropriate color variant

### Requirement: Satisfaction Trend Widget
The dashboard MUST display a trend widget showing rolling weekly satisfaction scores over the last 12 weeks.

#### Scenario: Trend display
- **WHEN** survey responses exist for the last 30 days
- **THEN** the trend widget MUST display a bar chart of weekly scores
