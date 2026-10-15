# Lead rotation

New leads reach the sales team in turn. Pipelinq gives each new lead to the colleague who has had the fewest lately, and nobody gets more than their cap.

## Specs

- `openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md`

## How a lead finds its colleague

An administrator switches on automatic lead assignment. From then on, every new lead without an owner gets one.

1. Pipelinq looks at the lead's category and finds the colleagues whose skill covers it.
2. It drops colleagues who are not available for routing, who paused lead assignment, or who reached their cap.
3. It counts the leads each remaining colleague received in the last 30 days.
4. The colleague with the fewest gets the lead. On a tie, the one who waited longest gets it.

A lead made with an owner keeps that owner. Rotation only fills an empty one.

## Why this lead went to this colleague

The lead records the reason, for example: "Rotation: fewest leads in 30 days (3, cap 10), skill Solar." It also records when rotation assigned it.

When nobody qualifies, the lead stays without an owner. Its reason then says rotation found no eligible colleague, and the suggested colleagues on the lead page still work as before.

## Settings

| Setting | Where | Effect |
|---|---|---|
| `lead_rotation_enabled` | App config, `PUT /api/settings` | `true` switches automatic assignment on. Off by default. |
| Lead cap per 30 days | Agent profile | The most leads rotation gives this colleague in 30 days. Empty means no cap. |
| Pause lead assignment | Agent profile | While on, the colleague gets no leads through rotation. |

The switch on the admin page, the two agent profile fields in the form and the reason on the lead page follow once their screens are drawn. Until then an administrator sets them through the API.

## What it does not do

- Tickets are not rotated. They keep the queue and the suggestion.
- There are no territory rules, such as by postcode or region.
