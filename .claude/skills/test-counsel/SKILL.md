---
name: test-counsel
description: Test a project's features from 8 persona perspectives using browser, API, and documentation testing
---

# Test Counsel — Multi-Persona Feature Testing

Test a project's implemented features from 8 persona perspectives using browser interaction, API testing, and documentation review — all driven by the project's OpenSpec specifications.

**Input**: Optional argument after `/test-counsel`:
- No argument → ask which project to test
- Project name → test that project directly (e.g., `opencatalogi`, `openregister`)

**Available projects**: Any directory under apps-extra with an `openspec/` folder.

---

## Personas

The Test Counsel uses 8 personas representing the full spectrum of Dutch public sector users. Each persona card is stored in `.claude/personas/`:

| Persona | File | Testing Focus |
|---------|------|---------------|
| Henk Bakker | `henk-bakker.md` | Readability, text size, Dutch language, simple navigation, elderly UX |
| Fatima El-Amrani | `fatima-el-amrani.md` | Visual clarity, icon usage, mobile viewport, text density, literacy barriers |
| Sem de Jong | `sem-de-jong.md` | Performance, keyboard nav, dark mode, console errors, modern UX patterns |
| Noor Yilmaz | `noor-yilmaz.md` | Security controls, audit trails, RBAC, org isolation, data leaks, BIO2 |
| Annemarie de Vries | `annemarie-de-vries.md` | API standards, NLGov compliance, GEMMA mapping, OpenAPI spec, publiccode.yml |
| Mark Visser | `mark-visser.md` | Business workflows, CRUD efficiency, form clarity, status indicators, Dutch terms |
| Priya Ganpat | `priya-ganpat.md` | API quality via browser fetch(), DX, error responses, pagination, integration |
| Jan-Willem van der Berg | `janwillem-van-der-berg.md` | Plain language, jargon-free, findability, 3-click rule, contact info, help |

---

## Steps

### Step -1: Environment Configuration

Ask the user about the target environment using AskUserQuestion:

**"Which environment do you want to test against?"**
- **Local development** — Backend: localhost:8080, Frontend: localhost:3000 (if separate UI), Admin: admin/admin
- **Custom environment** — I'll provide URLs and credentials

If **Custom**, ask follow-up questions one at a time:
1. "What is the backend URL?"
2. "What is the frontend URL? (or same as backend if no separate UI)"
3. "What are the test user credentials? (format: username:password)"

Store as `{BACKEND}`, `{FRONTEND}`, `{TEST_USER}`, `{TEST_PASS}`.

For **Local development**, use:
- `{BACKEND}` = `http://localhost:8080`
- `{FRONTEND}` = `http://localhost:8080` (or `http://localhost:3000` if project has separate UI)
- `{TEST_USER}` = `admin`
- `{TEST_PASS}` = `admin`

### Step 0: Determine the Project

If no project was provided as argument, use AskUserQuestion to ask:

**"Which project would you like the Test Counsel to test?"**

List the available projects by checking which directories have `openspec/` folders.

Store the chosen project as `{PROJECT}`.

### Step 1: Read the Project's Specs and Understand What to Test

Read the following files:

1. `{PROJECT}/project.md` — Project context, URLs, architecture
2. `{PROJECT}/openspec/specs/` — All spec files (what was specified)
3. `{PROJECT}/openspec/changes/` — Active changes (recently added features)
4. `openspec/specs/` — Shared specs (api-patterns, nl-design, nextcloud-app)

Build a test plan:
- What features exist and should be testable?
- What URLs/pages should be visited?
- What API endpoints should be tested?
- What documentation should exist?

### Step 1.5a: Load Test Scenarios (optional)

Check whether the project has saved test scenarios:
```bash
ls {PROJECT}/test-scenarios/TS-*.md 2>/dev/null
```

If scenario files exist, parse their frontmatter. Filter to those with `status: active` and `test-commands` containing `test-counsel`.

Group them by persona relevance using the `personas` frontmatter field:

```
Found {N} test scenario(s) for {PROJECT}:

Relevant to all personas:
  TS-001  [HIGH]  functional  — Create a new register

Relevant to specific personas:
  TS-002  [MED]   api         — API returns paginated results   → Priya Ganpat, Annemarie de Vries
  TS-003  [HIGH]  security    — Unauthenticated access blocked  → Noor Yilmaz
  TS-004  [LOW]   accessibility — Form labels are readable      → Henk Bakker, Fatima El-Amrani
```

Ask the user using AskUserQuestion:

**"Test scenarios exist for this project. Include them in this test run?"**
- **Yes, include all** — each persona agent receives the scenarios relevant to their persona (matched by persona slug in frontmatter), plus any scenario with no specific persona
- **Yes, let me choose** — show the list and let the user select which to include
- **No, skip scenarios** — proceed with standard testing only

Store `{INCLUDED_SCENARIOS}` — a mapping of persona slug → list of relevant scenario objects (id, title, steps, preconditions, acceptance criteria).

Each persona sub-agent will receive only the scenarios matching their persona slug (or all scenarios if the user chose "include all" and no persona filter is set).

**If no scenarios exist**: proceed silently. Note at the end: "No test scenarios defined yet. Create them with `/test-scenario-create`."

---

### Step 1.5: Select Agent Model

Ask the user using AskUserQuestion:

**"Which model should the persona agents use?"**

| Model | Speed | Quota | Best for |
|---|---|---|---|
| **Haiku** | Fastest | Low | Parallel runs — broad coverage, efficient |
| **Sonnet** | Balanced | Moderate | Better reasoning, more nuanced findings |
| **Opus** | Slowest | High | Deepest analysis — for critical or final runs |

- **Haiku (default)** — Recommended for parallel runs. Fast and quota-efficient. Its 200k context window is smaller than Sonnet/Opus (both 1M) — for browser-heavy runs with many snapshots, consider Sonnet.
- **Sonnet** — Better reasoning depth for more nuanced findings. Uses more quota than Haiku across 8 parallel agents.
- **Opus** — Highest quality analysis. With 8 agents running in parallel this uses substantial quota — best reserved for final pre-release testing or targeted critical reviews.

Store as `{MODEL}`:
- Haiku → `"haiku"`
- Sonnet → `"sonnet"`
- Opus → `"opus"`

### Step 2: Launch Persona Test Agents in Parallel

Launch 8 Task agents in parallel (all in a single message), one per persona. Each agent tests the live application from their persona's perspective. Use `subagent_type: "general-purpose"` and `model: "{MODEL}"` (from Step 1.5).

**Browser assignment** — each agent gets its own browser to avoid conflicts:

| Agent | Persona | Browser |
|-------|---------|---------|
| 1 | Henk Bakker | `browser-2` |
| 2 | Fatima El-Amrani | `browser-3` |
| 3 | Sem de Jong | `browser-4` |
| 4 | Noor Yilmaz | `browser-5` |
| 5 | Annemarie de Vries | `browser-7` |
| 6 | Mark Visser | `browser-1` |
| 7 | Priya Ganpat | `browser-2` (sequential after Henk) |
| 8 | Jan-Willem van der Berg | `browser-3` (sequential after Fatima) |

**Note**: With 7 browsers and 8 agents, launch the first 6 in parallel, then the remaining 2 after the first batch completes. Or launch all 8 and let 2 share browsers sequentially.

**Sub-agent prompt template** (replace variables):

```
You are a Test Counsel agent testing the **{PROJECT}** application as **{PERSONA_NAME}**.

## Your Persona
Read the persona card at `.claude/personas/{PERSONA_FILE}` to understand your character completely. Stay fully in character throughout all testing.

## Browser
Use `browser-{N}` tools (`mcp__browser-{N}__*`) for all browser interactions.

## Environment
- **Backend**: {BACKEND}
- **Frontend**: {FRONTEND}
- **Login**: {TEST_USER} / {TEST_PASS}

## What to Test
Read the project specs to understand what features should exist:
1. `{PROJECT}/project.md`
2. All files in `{PROJECT}/openspec/specs/`

## Test Scenarios for Your Persona

{IF INCLUDED_SCENARIOS for this persona is non-empty:}
The following test scenarios were defined specifically for your persona. Execute these **first**, before free exploration — they represent the highest-priority flows to verify:

{For each scenario: ID, title, preconditions, Given-When-Then steps, acceptance criteria}

For each scenario:
1. Set up the preconditions
2. Follow the Given-When-Then steps exactly as written, using the provided test data
3. Verify each acceptance criterion — record PASS / FAIL / PARTIAL / BLOCKED
4. Screenshot each step: `{PROJECT}/test-results/screenshots/personas/{PERSONA_SLUG}/{SCENARIO_ID}-step-{N}.png`
5. Check `browser_console_messages` after each action

Include a **"## Test Scenario Results"** section in your report with a table:
| Scenario | Title | Criterion | Status | Observed |
|---|---|---|---|---|

{END IF}

---

## Testing Approach

### 1. Browser Testing (UI)
Log in and navigate through the application as your persona would:
- Navigate to {FRONTEND} (or {BACKEND}/index.php/apps/{PROJECT} for Nextcloud apps)
- Log in with the test credentials
- Visit every major page/section mentioned in the specs
- For each page:
  - `browser_snapshot` — observe the page from your persona's perspective
  - Test interactions your persona would attempt
  - Check `browser_console_messages` for errors
  - Note anything that doesn't match your persona's needs/expectations

### 2. API Testing (from browser)
Use `browser_evaluate` to test API endpoints mentioned in the specs:
```javascript
const response = await fetch('{BACKEND}/index.php/apps/{app}/api/{resource}', {
    headers: { 'requesttoken': OC.requestToken }
});
return JSON.stringify({
    status: response.status,
    headers: Object.fromEntries(response.headers.entries()),
    body: await response.json()
}, null, 2);
```
Test from your persona's perspective:
- Can your persona's role access these endpoints?
- Do the responses make sense for your persona?
- Are errors helpful and understandable?

### 3. Documentation Testing
Check if documentation exists and serves your persona:
- Is there in-app help?
- Are API docs accessible if relevant to your persona?
- Is the documentation in Dutch where needed?
- Does it match the actual behavior?

### 4. Spec Compliance Testing
For each feature in the specs, verify:
- Is it implemented?
- Does it work as specified?
- Does it serve your persona's needs?

## {PERSONA_TESTING_FOCUS}

## Output Format

Write your results as a structured report:

```markdown
# Test Counsel Report: {PERSONA_NAME} — {PROJECT}

**Date:** {today's date}
**Environment:** {BACKEND}
**Persona:** {PERSONA_NAME} ({one-line description})
**Browser:** browser-{N}

## Summary
- **Features tested**: {count}
- **PASS**: {count}
- **PARTIAL**: {count}
- **FAIL**: {count}
- **NOT IMPLEMENTED**: {count}

## Feature Test Results

### {Spec Section / Feature Name}
| Aspect | Status | Notes |
|--------|--------|-------|
| Implemented? | YES/NO/PARTIAL | {details} |
| Works as specified? | YES/NO/PARTIAL | {details} |
| Serves {PERSONA_NAME}'s needs? | YES/NO/PARTIAL | {persona perspective} |

**{PERSONA_NAME}'s reaction**: "{in-character quote}"

{repeat for each feature}

## API Test Results (if applicable)
| Endpoint | Method | Status | Response | Persona Notes |
|----------|--------|--------|----------|--------------|
| /api/{resource} | GET | {code} | {summary} | {persona perspective} |

## Console Errors
| Page | Error | Severity |
|------|-------|----------|
| {page} | {error} | HIGH/MEDIUM/LOW |

## Persona-Specific Findings

### {PERSONA_FOCUS_AREA} Assessment
| Criterion | Status | Evidence | {PERSONA_NAME} would say... |
|-----------|--------|----------|----------------------------|
| {criterion} | PASS/FAIL | {what was observed} | "{in-character quote}" |

## Top Issues
| # | Issue | Severity | Category | Recommendation |
|---|-------|----------|----------|----------------|
| 1 | {issue} | CRITICAL/HIGH/MEDIUM/LOW | {category} | {suggestion} |

## {PERSONA_NAME}'s Verdict
"{A paragraph from the persona summarizing their overall experience testing this application}"
```
```

**Persona-specific testing focus:**

| Persona | Testing Focus Instructions |
|---------|--------------------------|
| Henk | Check text size (>=16px body), button size (>=44px), Dutch labels, simple navigation, clear errors, breadcrumbs, contrast ratios |
| Fatima | Set viewport to 375x812 mobile, check icon clarity, text density, visual hierarchy, color-coded status, touch targets, scrolling discovery |
| Sem | Measure page load time, test Tab/Escape/Enter/arrow keys, check dark mode, inspect console, monitor network requests, verify URL state management |
| Noor | Navigate to settings first, look for audit logs, test RBAC boundaries, try URL manipulation for org isolation, check PII in URLs, verify session controls |
| Annemarie | Test API endpoints for NLGov compliance, check pagination format, verify OpenAPI spec availability, look for publiccode.yml, assess GEMMA alignment |
| Mark | Test CRUD workflows for efficiency (count clicks), check form field clarity, verify status indicators, test search, check Dutch business terminology |
| Priya | Use browser_evaluate for API calls, test all CRUD via fetch(), verify error response format, check pagination/filtering/sorting, assess OpenAPI accuracy |
| Jan-Willem | Check for jargon on every page, test search with plain Dutch terms, count clicks to complete tasks, find contact info, verify B1 language level |

### Step 3: Synthesize Test Results

After all agents complete, read their reports and create a synthesized Test Counsel report.

**Write the synthesis to**: `{PROJECT}/test-results/test-counsel-report.md`

```markdown
# Test Counsel Report: {PROJECT}

**Date:** {today's date}
**Environment:** {BACKEND} / {FRONTEND}
**Method:** 8-persona browser, API, and documentation testing against OpenSpec specifications
**Personas:** Henk Bakker, Fatima El-Amrani, Sem de Jong, Noor Yilmaz, Annemarie de Vries, Mark Visser, Priya Ganpat, Jan-Willem van der Berg

---

## Overall Results

| Persona | Features Tested | PASS | PARTIAL | FAIL | Not Implemented |
|---------|----------------|------|---------|------|-----------------|
| Henk Bakker | {n} | {n} | {n} | {n} | {n} |
| Fatima El-Amrani | {n} | {n} | {n} | {n} | {n} |
...{all 8 personas}
| **Total** | {n} | {n} | {n} | {n} | {n} |

---

## Critical Issues (found by 3+ personas)

| # | Issue | Severity | Found by | Recommendation |
|---|-------|----------|----------|----------------|
| 1 | {issue} | CRITICAL/HIGH | {persona names} | {recommendation} |

---

## Spec vs Implementation Gap Analysis

| Spec Feature | Implemented? | Working? | Persona Feedback |
|-------------|-------------|---------|-----------------|
| {feature from spec} | YES/NO/PARTIAL | YES/NO | {summary of persona reactions} |

---

## Per-Persona Highlights

### Henk Bakker (Elderly Citizen)
- **Can Henk use this?** YES/WITH DIFFICULTY/NO
- **Top blocker**: {issue}
- **Quote**: "{in-character Dutch quote}"

### Fatima El-Amrani (Low-Literate Migrant)
...{repeat for all 8}

---

## Testing Categories

### Accessibility & Readability
| Issue | Severity | Personas | Spec Reference |
|-------|----------|----------|---------------|
| {issue} | {severity} | {who found it} | {spec section} |

### Security & Compliance
| Issue | Severity | Personas | Standard |
|-------|----------|----------|----------|
| {issue} | {severity} | {who found it} | {BIO2/AVG/etc} |

### API Quality & Standards
| Issue | Severity | Personas | NLGov Rule |
|-------|----------|----------|-----------|
| {issue} | {severity} | {who found it} | {rule} |

### UX & Performance
| Issue | Severity | Personas | Notes |
|-------|----------|----------|-------|
| {issue} | {severity} | {who found it} | {details} |

### Language & Content
| Issue | Severity | Personas | Notes |
|-------|----------|----------|-------|
| {issue} | {severity} | {who found it} | {details} |

---

## Console Errors Summary

| Error | Occurrences | Pages | Severity |
|-------|-------------|-------|----------|
| {error} | {count} | {pages} | {severity} |

---

## Recommendations

### CRITICAL (fix immediately)
1. {recommendation + which personas affected}

### HIGH (fix before next release)
1. {recommendation + which personas affected}

### MEDIUM (improve when possible)
1. {recommendation + which personas affected}

---

## Suggested OpenSpec Changes

| Change Name | Description | Related Issues | Personas Affected |
|-------------|-------------|---------------|------------------|
| {name} | {description} | {issue numbers from above} | {personas} |
```

### Step 4: Report to User

Display a concise summary:
- Total features tested across all personas
- Overall pass/fail rates per persona
- Top 5 critical issues
- Any spec features that are not yet implemented
- Link to the full report: `{PROJECT}/test-results/test-counsel-report.md`
- Offer to create OpenSpec changes for any gaps found

---

## Capture Learnings

After testing completes, review what happened and append any new observations to [learnings.md](learnings.md):

- **Patterns That Work** — multi-persona approaches that found meaningful cross-cutting issues
- **Mistakes to Avoid** — false consensus, persona overlap, or synthesis errors
- **Domain Knowledge** — facts about cross-persona testing patterns or Dutch government accessibility
- **Open Questions** — unresolved testing challenges

Each entry must include today's date. One insight per bullet. Skip if nothing new was learned.

---

## Returning to caller

After generating the report and summary, output a structured result line and return control:

```
COUNSEL_TEST_RESULT: PASS | FAIL  CRITICAL_COUNT: <n>  SUMMARY: <one-line summary>
```

- **PASS** = no CRITICAL issues found across all personas
- **FAIL** = any CRITICAL issues found

**If invoked from `/opsx-apply-loop`**: your work is complete after outputting the result line. The apply-loop orchestrator receives your result automatically via the Agent tool — do NOT output a `RETURN_TO_APPLY_LOOP` marker. Do NOT offer to create OpenSpec changes, do NOT ask what to do next.
