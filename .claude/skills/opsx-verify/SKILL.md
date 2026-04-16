---
name: opsx-verify
description: Verify implementation matches change artifacts before archiving
metadata:
  category: Workflow
  tags: [workflow, verify, experimental]
---

**Check the active model** from your system context (it appears as "You are powered by the model named…").

- **On Haiku**: stop immediately:
  > "This command requires Sonnet or Opus — verifying implementation against specs and running tests needs stronger reasoning than Haiku can reliably provide. Please switch to Sonnet (`/model sonnet`) or Opus (`/model opus`) and re-run."
- **On Sonnet or Opus**: proceed normally.

---

Verify that an implementation matches the change artifacts (specs, tasks, design).

**Input**: Optionally specify a change name after `/opsx-verify` (e.g., `/opsx-verify add-auth`). If omitted, check if it can be inferred from conversation context. If vague or ambiguous you MUST prompt for available changes.

**Steps**

1. **If no change name provided, prompt for selection**

   Run `openspec list --json` to get available changes. Use the **AskUserQuestion tool** to let the user select.

   Show changes that have implementation tasks (tasks artifact exists).
   Include the schema used for each change if available.
   Mark changes with incomplete tasks as "(In Progress)".

   **IMPORTANT**: Do NOT guess or auto-select a change. Always let the user choose.

2. **Check status to understand the schema**
   ```bash
   openspec status --change "<name>" --json
   ```
   Parse the JSON to understand:
   - `schemaName`: The workflow being used (e.g., "spec-driven")
   - Which artifacts exist for this change

3. **Get the change directory and load artifacts**

   ```bash
   openspec instructions apply --change "<name>" --json
   ```

   This returns the change directory and context files. Read all available artifacts from `contextFiles`.

   **Additionally, load optional artifacts if present:**
   - `openspec/changes/<name>/test-plan.md` — pre-defined test cases mapped to spec scenarios; use as the primary oracle for scenario coverage and testing
   - `openspec/changes/<name>/contract.md` — formal API contract; if present, it is the authoritative interface definition and takes precedence over design.md for API verification

4. **Initialize verification report structure**

   Create a report structure with three dimensions:
   - **Completeness**: Track tasks and spec coverage
   - **Correctness**: Track requirement implementation and scenario coverage
   - **Coherence**: Track design adherence and pattern consistency

   Each dimension can have CRITICAL, WARNING, or SUGGESTION issues.

5. **Verify Completeness**

   **Task Completion**:
   - If tasks.md exists in contextFiles, read it
   - Parse checkboxes: `- [ ]` (incomplete) vs `- [x]` (complete)
   - Count complete vs total tasks
   - If incomplete tasks exist:
     - Add CRITICAL issue for each incomplete task
     - Recommendation: "Complete task: <description>" or "Mark as done if already implemented"
   - **Sync already-complete tasks to GitHub** (only if plan.json exists): For every task that is already `[x]` in tasks.md but whose `plan.json` status is not `"done"`, treat it as just-completed and run the full GitHub sync below. If plan.json does not exist, skip all GitHub sync steps silently.
   - If browser or API tests (step 8) verify that acceptance criteria for an incomplete task are met:
     - Mark those criteria as `[x]` in tasks.md
     - If ALL criteria of that task are now checked, mark the task itself as `[x]` in tasks.md
   - **For every task marked `[x]` in tasks.md** (whether already complete before this run, or just completed above), if plan.json exists and that task's `status` in plan.json is not `"done"`:
     - **Check off this task and ALL its sub-checkboxes in the tracking issue body**:
       - Fetch the issue body once (batch all task updates before writing back)
       - For each task to check off: find the parent task line by matching its title (e.g., `- [ ] **1.1 Task title**`), change it to `- [x]`; then scan every immediately following line — for each line starting with `  - [ ]` (2-space indent), change it to `  - [x]`; stop scanning at any line that is NOT an indented sub-checkbox (blank line, new parent checkbox, section header, etc.)
       - **MCP (preferred):** `get_issue` → `{owner, repo, issue_number: <tracking_issue>}` → apply the above changes for all tasks → `update_issue` → `{owner, repo, issue_number: <tracking_issue>, body: <updated_body>}`
       - **CLI (fallback):** `gh issue view <tracking_issue> --repo <repo> --json body --jq '.body'` → apply the above changes for all tasks → `gh issue edit <tracking_issue> --repo <repo> --body "<updated_body>"`
       - **IMPORTANT**: Batch all updates into a single `update_issue` call — fetch the body once, apply all checkbox changes, then write it back once.
     - Update `plan.json`: set `"status": "done"` for that task
     - **Do NOT close the issue** — the issue will be closed when the PR is merged or during archive

   **Spec Coverage**:
   - If delta specs exist in `openspec/changes/<name>/specs/`:
     - Extract all requirements (marked with "### Requirement:")
     - For each requirement:
       - Search codebase for keywords related to the requirement
       - Assess if implementation likely exists
     - If requirements appear unimplemented:
       - Add CRITICAL issue: "Requirement not found: <requirement name>"
       - Recommendation: "Implement requirement X: <description>"

6. **Verify Correctness**

   **Requirement Implementation Mapping**:
   - For each requirement from delta specs:
     - Search codebase for implementation evidence
     - If found, note file paths and line ranges
     - Assess if implementation matches requirement intent
     - If divergence detected:
       - Add WARNING: "Implementation may diverge from spec: <details>"
       - Recommendation: "Review <file>:<lines> against requirement X"

   **Scenario Coverage**:
   - **If test-plan.md is loaded**: use the TCs as the canonical scenario checklist. For each TC:
     - Verify the acceptance criteria are met in the implementation
     - Note the TC's `test command` field — use it in step 8 to run the right test type
     - If a TC's expected result appears unmet: Add WARNING: "TC not satisfied: TC-N <title>"
   - **If no test-plan.md**: fall back to scanning spec scenarios directly:
     - For each scenario in delta specs (marked with "#### Scenario:"):
       - Check if conditions are handled in code
       - If scenario appears uncovered: Add WARNING: "Scenario not covered: <scenario name>"

7. **Verify Coherence**

   **Contract Adherence** (checked first if contract.md exists):
   - If contract.md is loaded: it is the authoritative interface definition — verify against it before design.md
     - For each declared endpoint: verify it exists in code with the correct method, path, and auth requirement
     - For each schema: verify request/response fields match the contract
     - For each error code: verify the declared HTTP status and condition are implemented
     - If an endpoint, schema field, or error code is missing or diverges:
       - Add CRITICAL: "Contract violation: <endpoint/schema/field> does not match contract.md"
       - Recommendation: "Implement contract as specified — contract is the cross-team interface agreement"

   **Design Adherence**:
   - If design.md exists in contextFiles:
     - Extract key decisions (look for sections like "Decision:", "Approach:", "Architecture:")
     - Verify implementation follows those decisions
     - If contradiction detected:
       - Add WARNING: "Design decision not followed: <decision>"
       - Recommendation: "Update implementation or revise design.md to match reality"
   - If neither contract.md nor design.md: Skip coherence check, note "No contract.md or design.md to verify against"

   **Code Pattern Consistency**:
   - Review new code for consistency with project patterns
   - Check file naming, directory structure, coding style
   - If significant deviations found:
     - Add SUGGESTION: "Code pattern deviation: <details>"
     - Recommendation: "Consider following project pattern: <example>"

   **Test Coverage**:
   - For each new PHP service/controller file, check if a corresponding test file exists in `tests/Unit/` or `tests/unit/`
   - For each new Vue component, check if a test file exists (if project has Jest/Vitest)
   - If a new service has NO test:
     - Add CRITICAL: "Missing unit test for <ServiceName>"
     - Recommendation: "Create tests/Unit/Service/<ServiceName>Test.php with at least 3 test methods"
   - If tests exist but cover fewer than 3 methods:
     - Add WARNING: "Insufficient test coverage for <ServiceName>"

   **Documentation**:
   - Check if the PR updates README.md or docs/ with new feature description
   - Check if new API endpoints are documented
   - If no documentation found:
     - Add WARNING: "No documentation for new feature"
     - Recommendation: "Add feature description to README.md and document new API endpoints"

8. **Ask about API and browser testing**

   After the code-level verification, use **AskUserQuestion** to ask:
   "Would you also like to run API and/or browser tests against the specs and implementation?"

   Options:
   - **Both API and browser tests** — Run API tests first, then browser tests
   - **API tests only** — Test API endpoints against spec requirements
   - **Browser tests only** — Test UI behavior against spec scenarios
   - **Skip testing** — Continue with code-level findings only

   **If API testing selected:**

   a. **Discover endpoints** — Read `{app}/appinfo/routes.php` to find endpoints affected by this change. Cross-reference with the specs to identify which endpoints should exist.

   b. **Test CRUD operations** — For each affected resource endpoint, test with curl:
   ```bash
   # CREATE
   curl -s -u admin:admin -X POST -H "Content-Type: application/json" \
     -d '{"name":"Verify Test"}' http://localhost:8080/index.php/apps/{app}/api/{resource}
   # Returns 201 with created object including id

   # READ
   curl -s -u admin:admin http://localhost:8080/index.php/apps/{app}/api/{resource}/{id}
   # Returns 200 with full object; 404 for non-existent

   # LIST
   curl -s -u admin:admin http://localhost:8080/index.php/apps/{app}/api/{resource}
   # Returns 200 with array and pagination metadata

   # UPDATE
   curl -s -u admin:admin -X PUT -H "Content-Type: application/json" \
     -d '{"name":"Updated"}' http://localhost:8080/index.php/apps/{app}/api/{resource}/{id}

   # DELETE
   curl -s -u admin:admin -X DELETE http://localhost:8080/index.php/apps/{app}/api/{resource}/{id}
   ```

   c. **Verify against spec scenarios** — For each GIVEN/WHEN/THEN scenario in the specs, craft a curl request that exercises it. Check response codes, payloads, and error messages match expectations.

   d. **NLGov compliance spot-check** — Verify the basics:
   - URLs use lowercase plural nouns with hyphens
   - Collections include pagination metadata (`total`, `page`, `pages`)
   - Error responses include `message` or `detail` field with proper HTTP status
   - `Content-Type: application/json` on all responses

   e. **Add findings** as CRITICAL (endpoint broken/missing), WARNING (non-compliant), or SUGGESTION (improvement).

   **If browser testing selected:**

   a. **Set up browser session** — Use `browser-1` tools (`mcp__browser-1__*`):
   ```
   1. browser_resize → width: 1920, height: 1080
   2. browser_navigate → http://localhost:8080/index.php/apps/{app}
   3. If redirected to login:
      - browser_fill_form with username: admin, password: admin
      - Submit the form
   4. browser_snapshot → confirm app loaded
   ```

   b. **Test spec scenarios via browser** — For each GIVEN/WHEN/THEN scenario from the specs:
   - **GIVEN**: Navigate to the correct page, verify precondition state
   - **WHEN**: Perform the action using `browser_click`, `browser_type`, `browser_fill_form`
   - **THEN**: `browser_snapshot` to verify expected outcome, `browser_take_screenshot` with filename: `test-results/verify/{change-name}-{scenario-slug}.png`

   c. **Monitor for errors** during testing:
   - `browser_console_messages` (level: "error") after each action
   - `browser_network_requests` to catch failed API calls (4xx/5xx)

   d. **Test core flows** relevant to the change:
   - CRUD: Create → verify in list → update → verify change → delete → verify removed
   - Navigation: sidebar links, back/forward, deep linking
   - Forms: required field validation, success feedback, cancel behavior
   - Loading/error states: indicators, empty states, error messages

   e. **Add findings** with screenshot evidence. CRITICAL for broken flows, WARNING for degraded UX, SUGGESTION for polish.

9. **Generate Verification Report**

   **Summary Scorecard**:
   ```
   ## Verification Report: <change-name>

   ### Summary
   | Dimension    | Status           |
   |--------------|------------------|
   | Completeness | X/Y tasks, N reqs|
   | Correctness  | M/N reqs covered |
   | Coherence    | Followed/Issues  |
   | API Tests    | Passed/Failed/Skipped |
   | Browser Tests| Passed/Failed/Skipped |
   ```

   **Issues by Priority**:

   1. **CRITICAL** (Must fix before archive):
      - Incomplete tasks
      - Missing requirement implementations
      - Failed API/browser tests
      - Each with specific, actionable recommendation

   2. **WARNING** (Should fix):
      - Spec/design divergences
      - Missing scenario coverage
      - Each with specific recommendation

   3. **SUGGESTION** (Nice to fix):
      - Pattern inconsistencies
      - Minor improvements
      - Each with specific recommendation

10. **Fix loop — resolve issues and re-verify**

   **If CRITICAL or WARNING issues found:**
   - Display the full report
   - Use **AskUserQuestion** to ask: "Found issues. Would you like me to fix them?"
     - **Yes, fix all issues** — Fix all CRITICAL and WARNING issues
     - **Yes, fix critical only** — Fix only CRITICAL issues
     - **No, leave as-is** — Skip fixing, proceed to final assessment

   **If fixing:**
   - Work through each issue, making the necessary code changes
   - After all fixes are applied, **re-run verification from step 5** (skip steps 1-4, reuse loaded context)
   - Show updated report with resolved issues marked
   - If new issues are found during re-verify, repeat this fix loop
   - Continue looping until no CRITICAL/WARNING issues remain or the user chooses to stop

11. **Final assessment and archive prompt**

   **FIRST: Re-check task completion** — regardless of other findings, re-read tasks.md and count `- [ ]` items:
   - If ANY tasks are still `- [ ]`: **do NOT offer archive**. Show:
     ```
     ⚠️ N task(s) still incomplete — archive is blocked until all tasks are done:
     - Task X: <description> (incomplete criteria: ...)
     ```
     End the session without offering archive.

   **If all tasks `[x]` AND CRITICAL issues remain (user chose not to fix):**
   - "X critical issue(s) remain. Recommend fixing before archiving."
   - Do NOT prompt for archive

   **If all tasks `[x]` AND only SUGGESTION issues or all clear:**
   - Display: "All checks passed. Implementation matches specs."
   - If plan.json exists, update the pipeline progress comment on the issue (search for `## Pipeline Progress`, update via PATCH if found, create if not):
     ```markdown
     ## Pipeline Progress

     | Stage | Status | Details |
     |-------|--------|---------|
     | Implementation | ✓ Complete | All N tasks done |
     | Quality Checks | ✓ Pass | lint, phpcs, phpstan clean |
     | Verification | ✓ Pass | Completeness, correctness, coherence |
     | Archive | ready | |

     *Updated: YYYY-MM-DD HH:MM UTC*
     ```
   - Also add a brief comment:
     - **MCP (preferred):** GitHub MCP `add_issue_comment` → `{owner, repo, issue_number: <tracking_issue>, body: "✓ Verified by /opsx-verify — all checks passed"}`
     - **CLI (fallback):** `gh issue comment <tracking_issue> --repo <repo> --body "✓ Verified by /opsx-verify — all checks passed"`
   - Use **AskUserQuestion** to ask: "Ready to archive this change?"
     - **Yes, archive now** — Execute `/opsx-archive` for this change
     - **Sync specs first, then archive** — Execute `/opsx-sync` then `/opsx-archive`
     - **No, not yet** — End the session

   **If all tasks `[x]` AND only WARNING issues remain (user chose not to fix):**
   - "No critical issues. Y warning(s) noted."
   - Use **AskUserQuestion** to ask: "Archive this change with noted warnings?"
     - **Yes, archive with warnings** — Execute `/opsx-archive` for this change
     - **Sync specs first, then archive** — Execute `/opsx-sync` then `/opsx-archive`
     - **No, I'll fix them first** — End the session

## Capture Learnings

After verification completes, review what happened and append any new observations to [learnings.md](learnings.md):

- **Patterns That Work** — verification approaches that reliably catch real implementation gaps
- **Mistakes to Avoid** — false positives, wrong severity ratings, or verification errors
- **Domain Knowledge** — facts about OpenSpec schemas, artifact structures, or project patterns
- **Open Questions** — unresolved verification challenges for future investigation

Each entry must include today's date. One insight per bullet. Skip if nothing new was learned.

---

**Verification Heuristics**

- **Completeness**: Focus on objective checklist items (checkboxes, requirements list)
- **Correctness**: Use keyword search, file path analysis, reasonable inference - don't require perfect certainty
- **Coherence**: Look for glaring inconsistencies, don't nitpick style
- **Testing**: Test against spec scenarios, not exhaustive edge cases
- **False Positives**: When uncertain, prefer SUGGESTION over WARNING, WARNING over CRITICAL
- **Actionability**: Every issue must have a specific recommendation with file/line references where applicable

**Graceful Degradation**

- If only tasks.md exists: verify task completion only, skip spec/design checks
- If tasks + specs exist: verify completeness and correctness, skip design
- If full artifacts: verify all three dimensions
- Always note which checks were skipped and why

**Fix Loop Behavior**

- Re-verification after fixes reuses the already-loaded context (no need to re-read artifacts)
- Only re-verify the dimensions that had issues (skip clean dimensions)
- Track which issues were resolved vs newly introduced
- Maximum 3 fix-verify cycles before suggesting the user take over manually

**Output Format**

Use clear markdown with:
- Table for summary scorecard
- Grouped lists for issues (CRITICAL/WARNING/SUGGESTION)
- Code references in format: `file.ts:123`
- Specific, actionable recommendations
- No vague suggestions like "consider reviewing"

> 💡 If you switched models to run this command, don't forget to switch back to your preferred model with `/model <name>` (e.g. `/model default` or `/model sonnet`) when done.
