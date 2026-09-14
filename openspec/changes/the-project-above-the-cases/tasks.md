## 1. The project record

- [ ] 1.1 Add the `project` schema to a new `lib/Settings/register.d/` fragment:
      name, optional client, optional org unit, period, status, owner, description.
- [ ] 1.2 Add `projectTask` and `projectTeamMember` beneath it.
- [ ] 1.3 Manifest pages: project index, project detail, task and team widgets.
- [ ] 1.4 Confirm no schema slug collides, per the fleet slug rule the unify changes
      established.

## 2. Work items by reference

- [ ] 2.1 Add `projectWorkItem`: project, `domainObjectType`, `domainObjectRef`.
- [ ] 2.2 Refuse a second project link for one object, naming the holder.
- [ ] 2.3 Resolve a referenced object's title through the owning app; render an
      unresolved item when that app is absent.
- [ ] 2.4 Confirm deleting a project deletes references only.

## 3. Progress

- [ ] 3.1 Add `progressMode` with `manual`, `fromTasks` and `fromEffort`, an instance
      default and a per-project override.
- [ ] 3.2 Derive `fromTasks` from closed over total.
- [ ] 3.3 Derive `fromEffort` from humaniq's hours over the estimate.
- [ ] 3.4 Report the mode with every progress figure.
- [ ] 3.5 Report "cannot be computed" rather than zero when the source is absent.

## 4. The estimation scale

- [ ] 4.1 Add `estimationScale`: ordered points with label and weight, scope, active
      flag.
- [ ] 4.2 Refuse a second active scale in one scope.
- [ ] 4.3 Keep an inactive scale resolving on estimates already written.

## 5. Estimates per role

- [ ] 5.1 Add `projectEstimate`: work item, role, value on the active scale.
- [ ] 5.2 Derive the total on read; store none.

## 6. Cycles

- [ ] 6.1 Add `projectCycle`: name, period, status, under a project.
- [ ] 6.2 Write a versioned progress snapshot on close.
- [ ] 6.3 Read a closed cycle's chart from the snapshot; keep live figures beside it.
- [ ] 6.4 Add the carry-over act: source, target, items moved, and a per-item trail.

## 7. The effort roll-up

- [ ] 7.1 Sum humaniq's hours over the project's work items, on read.
- [ ] 7.2 Store no total on the project.
- [ ] 7.3 Report "cannot be read" rather than zero when humaniq is absent.

## 8. The leaf

- [ ] 8.1 Register the project integration leaf and ship its bundle.
- [ ] 8.2 Confirm the leaf is not registered when pipelinq is absent.

## 9. Verification

- [ ] 9.1 Unit tests for the single-project refusal, the derived total, the snapshot
      immutability and the degradations.
- [ ] 9.2 e2e coverage or a reason-bearing exclusion per scenario, per gate 19.
- [ ] 9.3 Manifest validation exits 0.
