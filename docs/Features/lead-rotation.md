# Lead rotation

Pipelinq can hand out new leads in turn, so the work is shared fairly and nobody has to divide it by hand.

## Specs

- `openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md`

## Switching it on

An administrator switches **Assign new leads in turn** on under *Lead Management* in the Pipelinq admin settings. It is off by default.

## Who gets a lead

When a lead is created without an owner, for example through the website form, Pipelinq gives it to a colleague who:

- has a skill that covers the lead's category,
- is available for routing,
- has not paused lead assignment, and
- has not reached their own limit of new leads in 30 days.

Among those colleagues, the one who received the fewest leads in the last 30 days gets it. When two colleagues have the same number, the one who waited longest since their last lead gets it.

A lead that is created with an owner keeps that owner.

## Limits and pauses per colleague

Under *Agent profiles* in the admin settings, each colleague's profile has two settings:

- **Most new leads in 30 days**: leave it empty for no limit.
- **Pause lead assignment**: for leave or a full week. A paused colleague gets no leads through rotation and shows *Paused for new leads* in the list.

## Why a lead has its owner

For a lead that rotation handled, the lead page shows a **Why this owner** card in the side column. When nobody was eligible, the lead stays without an owner and the card says so; the suggested colleagues just below it help to pick someone by hand.
