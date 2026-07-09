# Dashboard — No Permanently-Null Widgets Delta

**Spec refs**: `customer-satisfaction-closed-loop` (active change — restoration owner, not duplicated here), `migrate-forms-to-forms-leaf` (root cause), AnalyticsService.php:278 (`$responses = [];`)
**Standards**: default dashboards show only widgets that can render data on a functioning install

## ADDED Requirements

### Requirement: No Permanently-Null Default Widgets

The default Operational dashboard SHALL NOT include a widget whose data source is known-empty for every install. The Satisfaction KPI (`SatisfactionKpiWidget`, widget id `satisfaction`) SHALL be removed from the default Operational dashboard definition (widget def, layout slot, and template mapping) until the `customer-satisfaction-closed-loop` change re-sources CSAT data — that change owns restoration; this requirement only removes the dead tile. The vacated grid space SHALL be reflowed so the layout has no hole, and a manifest note SHALL record the restoration owner. No placeholder or "coming soon" tile SHALL replace it.

**Feature tier**: MVP

#### Scenario: Operational dashboard renders no empty satisfaction tile

- GIVEN a functioning install with normal CRM activity
- WHEN the Operational dashboard loads
- THEN no Customer Satisfaction widget MUST be present
- AND every rendered KPI widget MUST be backed by a live data source

#### Scenario: Restoration ownership recorded

- WHEN `src/manifest.json` is inspected
- THEN a note MUST record that the satisfaction widget returns via `customer-satisfaction-closed-loop`

#### Scenario: Layout reflows without a hole

- WHEN the Operational dashboard renders after removal
- THEN the KPI row MUST show no empty grid slot where the widget was
