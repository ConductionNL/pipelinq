---
description: Start a new OpenSpec change in openspec/changes/
---

Start an OpenSpec change named `$ARGUMENTS` (kebab-case, says what changes).

1. Read `openspec/config.yaml`: its `rules` say what the proposal, specs, design and tasks must contain.
2. Look at one recent change in `openspec/changes/` and follow its shape.
3. Create `openspec/changes/$ARGUMENTS/` with:
   - `proposal.md`: at least why the change is needed and what changes;
   - `design.md`, only when the change makes a design decision worth recording;
   - `tasks.md`: checklist of implementation tasks;
   - `specs/<capability>/spec.md`: delta requirements with scenarios against the capability in `openspec/specs/<capability>/`.
4. Do not write code until the proposal is agreed. When the change is built, merge its delta specs into `openspec/specs/` and move the change to `openspec/changes/archive/`.
