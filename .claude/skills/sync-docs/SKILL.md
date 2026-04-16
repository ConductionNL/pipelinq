---
name: sync-docs
description: "Check and sync documentation to reflect current project state — app feature docs (docs/) for a specific Nextcloud app, or developer/Claude docs in the .github repo (docs/claude/)"
metadata:
  category: Workflow
  tags: [docs, sync, maintenance]
---

# Sync Docs — Check and Update Documentation

Two documentation targets can be synced:

- **`app`** — Feature and user-facing docs for a specific Nextcloud app in `{APP_DIR}/docs/` (feature docs, architecture docs, standards references, etc.)
- **`dev`** — Developer/Claude guides in the `.github` repo at `{GITHUB_REPO}/docs/claude/` (workflow.md, writing-specs.md, writing-docs.md, commands.md, testing.md, etc.)

**Input**: Optional argument `app [app-name]`, `dev`, or just the app name directly. If omitted, ask which target.

---

## Step 0: Determine Target

If no argument provided, ask using AskUserQuestion:

**"Which docs do you want to sync?"**
- **`app` — App and user-facing docs** (`{APP_DIR}/docs/`) — feature documentation, standards references, admin guides
- **`dev` — Developer and Claude guides** (`~/.github/docs/claude/`) — workflow docs, spec writing guide, command reference

Store as `{SYNC_TARGET}` (`app` or `dev`).

### If `dev` is selected — locate the `.github` repo

Check if `~/.github` exists and contains a `.git` directory (i.e., is a git repo). Expand `~` to the actual home directory.

- **Found** — store the absolute path as `{GITHUB_REPO}` (e.g. `/home/wilco/.github`). The docs to sync live at `{GITHUB_REPO}/docs/claude/`.
- **Not found** — inform the user: *"Cannot find the .github repo at ~/.github. This repo is required for dev docs sync. Clone it first and try again."* **Stop the skill.**

### If `app` is selected — locate `.github` repo, `apps-extra`, and resolve `{APP_NAME}`

Also resolve `{GITHUB_REPO}` (same check as dev mode above) — needed for reading `writing-docs.md`. If not found, warn the user but continue (writing-docs.md is used as guidance, not as the sync target).

Discover the `apps-extra` directory:
1. Check if the workspace root's parent directory is named `apps-extra` — if so, use that parent as `{APPS_EXTRA}`.
2. Otherwise, check if an `apps-extra/` subdirectory exists inside the workspace root.
3. If neither is found, inform the user: *"Cannot find an apps-extra directory (checked parent of workspace and workspace/apps-extra/). This directory is required for app docs sync."* **Stop the skill.**

Determine `{APP_NAME}`:
- If passed as argument (e.g. `/sync-docs app openregister` or `/sync-docs openregister`), use it directly.
- Otherwise, scan `{APPS_EXTRA}/` for subdirectories that are git repos (contain a `.git` directory) and ask the user which app to sync docs for. If no git repos are found, inform the user and stop.

Store the resolved app directory path as `{APP_DIR}` (e.g. `{APPS_EXTRA}/openregister`).

---

## Step 0.5: Check writing-docs.md Currency *(optional)*

After determining the target, ask using AskUserQuestion:

**"Run pre-flight metadata checks before syncing?"**

These checks validate `config.yaml` rules, Sources of Truth accuracy, and schema alignment with `writing-specs.md`. Useful for catching project-level drift, but skippable for quick syncs.

- **Yes, run checks** — continue with Step 0.5
- **No, skip** — proceed directly to the relevant sync mode

Only run the following if the user selected "Yes":

Run the four checks described in [references/preflight-checks.md](references/preflight-checks.md) (Checks A–D) in parallel, then follow the reporting and confirmation flow in that file.

---

## Documentation Principles (applies to all modes)

All auditing and updating follows `writing-docs.md` (located at `{GITHUB_REPO}/docs/claude/writing-docs.md`). Read it before starting any sync. The sections most relevant to gap analysis:

- **[Reference, Don't Duplicate](../../docs/writing-docs.md#the-core-rule-reference-dont-duplicate)** — every piece of information should live in exactly one place; flag any content that restates a source of truth and replace it with a link
- **[Sources of Truth](../../docs/writing-docs.md#sources-of-truth)** — the authoritative table mapping each concern to its canonical file; use this to determine what to load and what to link to
- **[Audience Determines Location](../../docs/writing-docs.md#audience-determines-location)** — each doc has one target audience; flag content written for the wrong audience
- **[Document Lifecycle Markers](../../docs/writing-docs.md#document-lifecycle-markers)** — rules for `[Future]` and `[Legacy]` markers
- **[Outdated and Legacy Documentation](../../docs/writing-docs.md#outdated-and-legacy-documentation)** — when to update, move, mark, or delete
- **[Writing Anti-Patterns](../../docs/writing-docs.md#writing-anti-patterns)** — flag time-sensitive language, hardcoded versions, vague actors
- **[Formatting Alignment](../../docs/writing-docs.md#formatting-alignment)** — check markdown table separator width and cell padding; check ASCII diagram `│` alignment and label/description spacing; fix misalignments when editing any file

Do not re-derive these principles inline; read `writing-docs.md` and apply them directly.

---

## APP DOCS MODE

Syncs the feature and user-facing documentation for a specific Nextcloud app to match what is actually implemented.

### Phase 1: Load Source of Truth

Read all of the following in parallel:

**Specs and roadmap:**
1. All spec files in `{APP_DIR}/openspec/specs/*/spec.md` — what is currently specified
2. `{APP_DIR}/openspec/ROADMAP.md` — project phases and current phase (if present)
3. `{APP_DIR}/openspec/app-config.json` — app identity, goals, features list (if present)

**Company-wide Architectural Design Rules:**
4. All ADR files in `{APPS_EXTRA}/.claude/openspec/architecture/` — the constraints every Conduction app must follow (API conventions, NL Design, i18n requirements, test coverage, screenshots, etc.). These are **read as auditing context only** — never link to them from app docs.

**App-specific ADRs** (if present):
5. All ADR files in `{APP_DIR}/openspec/architecture/` — app-level overrides or additions to company-wide rules.

### Phase 2: Read Existing Docs

Read all user- and admin-facing documentation files for the app. At minimum check for:

**Root level:**
- `{APP_DIR}/README.md` — the primary public-facing description: feature summary, screenshots, setup/install instructions, external links

**`{APP_DIR}/docs/` tree:**
- `{APP_DIR}/docs/features/README.md` and all individual feature docs in `{APP_DIR}/docs/features/`
- `{APP_DIR}/docs/ARCHITECTURE.md` — high-level architecture and data model description (if present)
- `{APP_DIR}/docs/FEATURES.md` — consolidated feature overview (if present)
- `{APP_DIR}/docs/GOVERNMENT-FEATURES.md` — government/standards-specific feature notes (if present)
- `{APP_DIR}/docs/DESIGN-REFERENCES.md` — standards and design references (if present)
- `{APP_DIR}/docs/zgw-implementation.md` or similar standards implementation notes (if present)
- Any other `.md` files in `{APP_DIR}/docs/` that are not clearly developer-internal

**Developer-internal folders to skip** (do not audit for user-facing correctness):
- `{APP_DIR}/docs/development/`, `{APP_DIR}/docs/development-notes/`, `{APP_DIR}/docs/Technical/` — these are developer notes, not user docs; flag only if they contain content that belongs in user-facing docs instead

### Phase 3: Gap Analysis

For each doc file, compare content against the loaded specs, ADRs, and documentation principles (see [Documentation Principles](#documentation-principles-applies-to-all-modes)). Identify:

**Outdated content** — describes functionality that has changed or been removed
**Missing content** — features that are implemented (per specs with status `in-progress` or `done`) but not documented
**Stale `[Future]` markers** — things marked as future that are now implemented; apply the full removal checklist from [Document Lifecycle Markers](../../docs/writing-docs.md#document-lifecycle-markers)
**Broken cross-references** — links to spec files or other docs that have moved or been renamed (see [Link Structure](../../docs/writing-docs.md#link-structure))
**Duplicated content** — information that already lives in a spec; flag and propose replacing with a link per [Reference, Don't Duplicate](../../docs/writing-docs.md#the-core-rule-reference-dont-duplicate)
**Wrong audience content** — developer/technical content in user-facing guides; flag for removal or relocation per [Audience Determines Location](../../docs/writing-docs.md#audience-determines-location)
**Writing anti-patterns** — time-sensitive language, hardcoded versions, vague actors; see [Writing Anti-Patterns](../../docs/writing-docs.md#writing-anti-patterns)
**Missing standards references** — features that lack GEMMA, ZGW, or Forum Standaardisatie references where applicable

**ADR compliance gaps** — use the loaded ADRs as a checklist against the docs:
- ADR-003 (NL Design System) — does the UI/UX description reflect NL Design components?
- ADR-005 (i18n) — does the docs mention both Dutch and English support where relevant?
- ADR-007 (Security) — are authentication and role requirements described accurately?
- ADR-010 (Documentation with Screenshots) — does `{APP_DIR}/README.md` and feature docs include screenshots for key views? Are the image files present on disk? Use `docs/features/img/` for feature-specific screenshots (reference as `img/{feature}-{view}.png`), `docs/img/` for general or multi-doc screenshots, and `docs/screenshots/` for App Store gallery shots.
- Any app-specific ADRs — does the documentation reflect app-level architectural decisions?

**`{APP_DIR}/README.md`-specific checks:**
- Does the feature list match what is actually implemented (per specs)?
- Are setup/install instructions still accurate?
- Do screenshots exist on disk for all screenshot references?
- Are external links (docs site, GitHub badges) still correct?

Present a summary table:

```
{APP_DIR}/README.md:
  ✓ Feature list — matches implemented specs
  ✗ Screenshots missing for "Settings" view — ADR-010 requires screenshots for all key views
  ~ Install instructions — reference old environment variable name

{APP_DIR}/docs/features/README.md:
  ✓ Search feature — up to date
  ✗ Export feature — not documented, but openspec/specs/export/spec.md exists with status done
  ~ Export section still marked [Future] — check if implemented

{APP_DIR}/docs/ARCHITECTURE.md:
  ✓ Data model — accurate
  ✗ API layer diagram — does not reflect current controller structure per specs
  ...
```

### Phase 4: Confirm and Update

Use AskUserQuestion:

**"I found N outdated or missing items across {APP_NAME} docs. How would you like to proceed?"**
- **Update all** — apply all identified updates
- **Review each file** — go file by file, confirm before each update
- **Show full diff first** — show all proposed changes, then confirm once
- **Cancel** — no changes

Apply updates using the Edit tool (never rewrite entire files unless everything needs changing).

**When updating**, follow the full guidance in `writing-docs.md` (at `{GITHUB_REPO}/docs/claude/writing-docs.md`):
- Keep the existing writing style and structure
- Update factual content only (URLs, feature descriptions, steps, settings)
- Move `[Future]` items to implemented sections when appropriate — follow the full removal checklist in [Document Lifecycle Markers](../../docs/writing-docs.md#document-lifecycle-markers)
- Use `[Legacy]` markers for superseded content — see [Outdated and Legacy Documentation](../../docs/writing-docs.md#outdated-and-legacy-documentation)
- Add new sections for features not yet documented
- Preserve any content that is still accurate
- **Replace duplicated content with links** — follow [Reference, Don't Duplicate](../../docs/writing-docs.md#the-core-rule-reference-dont-duplicate) and [Handling large duplicates](../../docs/writing-docs.md#handling-large-duplicates)
- Avoid writing anti-patterns — see [Writing Anti-Patterns](../../docs/writing-docs.md#writing-anti-patterns)
- **Never add links pointing into `.claude/`** — see the No `.claude/` Links rule in [Guardrails](#guardrails)
- **Screenshot storage** — all feature screenshots go in `{APP_DIR}/docs/features/img/` with filenames `{feature}-{view}.png` (e.g., `projects-list.png`). Reference them from feature docs with relative paths (`img/projects-list.png`). Copy from `{APPS_EXTRA}/test-results/` after test runs — that directory is ephemeral.

### Phase 5: Report

```
Docs Sync — {APP_NAME} App Docs
──────────────────────────────────
{APP_DIR}/README.md                       — N changes applied
{APP_DIR}/docs/features/README.md         — N changes applied
{APP_DIR}/docs/features/search.md         — up to date, no changes
{APP_DIR}/docs/ARCHITECTURE.md            — N changes applied
{APP_DIR}/docs/ARCHITECTURE.md            — up to date, no changes

All {APP_NAME} docs are now current.
```

---

## DEV DOCS MODE (`{GITHUB_REPO}/docs/claude/`)

Syncs the developer and Claude workflow documentation in the `.github` repo to match current commands, skills, and project conventions.

**Prerequisites:** `{GITHUB_REPO}` was resolved in Step 0. The docs to sync live at `{GITHUB_REPO}/docs/claude/`.

### Phase 1: Load Source of Truth

The authoritative list of what counts as a source of truth for this project lives in the **Sources of Truth** table in `{GITHUB_REPO}/docs/claude/writing-docs.md`. Read that table first, then load all sources relevant to the dev docs. Sources come from two locations — the `.github` repo and the current workspace:

**From the `.github` repo (`{GITHUB_REPO}`):**
8. `{GITHUB_REPO}/global-settings/settings.json` and `{GITHUB_REPO}/global-settings/VERSION` — source of truth for harness configuration; used to verify `global-claude-settings.md` accuracy
9. `{GITHUB_REPO}/usage-tracker/README.md`, `{GITHUB_REPO}/usage-tracker/SETUP.md`, `{GITHUB_REPO}/usage-tracker/MODELS.md` — source of truth for usage tracker setup and model list; used to verify tracker references in `README.md` and `global-claude-settings.md`

**From the current workspace:**
1. All skill SKILL.md files in `.claude/skills/`
3. All spec files in `openspec/specs/*/spec.md` — for writing-specs.md accuracy check
4. `openspec/config.yaml` — active schema name and context rules
5. The conduction schema: `openspec/schemas/conduction/schema.yaml` and all files in `openspec/schemas/conduction/templates/`
6. All files in `personas/` — source of truth for persona names, behavior, and device preferences; used to verify `testing.md` and persona tester references
7. The workspace root `Makefile` — source of truth for available `make` targets; used to verify `make` command references in `README.md` and `getting-started.md`

### Phase 2: Read Existing Dev Docs

Read all files in `{GITHUB_REPO}/docs/claude/`:
- `README.md`
- `commands.md`
- `workflow.md`
- `writing-specs.md`
- `writing-docs.md`
- `testing.md`
- `docker.md`
- `getting-started.md`
- `global-claude-settings.md`
- `parallel-agents.md`
- Any other `.md` files found

### Phase 3: Gap Analysis

Check each doc for accuracy, completeness, and documentation principle violations (see [Documentation Principles](#documentation-principles-applies-to-all-modes)). For all files, also apply:
- [Reference, Don't Duplicate](../../docs/writing-docs.md#the-core-rule-reference-dont-duplicate) — flag any content that restates a source of truth elsewhere
- [Audience Determines Location](../../docs/writing-docs.md#audience-determines-location) — flag content written for the wrong audience
- [Link Structure](../../docs/writing-docs.md#link-structure) — flag broken or absolute-path links
- [Writing Anti-Patterns](../../docs/writing-docs.md#writing-anti-patterns) — flag time-sensitive language, hardcoded versions, vague actors

**`commands.md`** — Does it list all current skills/commands? Are any commands missing or have outdated descriptions? Are there command descriptions that duplicate content already in command files (should link instead)?

**`workflow.md`** — Does the artifact progression diagram match actual command behavior? Are the step descriptions accurate?

**`writing-specs.md`** — Does the spec structure template match what actual specs look like? Is the field reference table accurate? Is the grouping rule for the `**OpenSpec changes**` list present?

**`writing-docs.md`** — Do the documentation principles reflect current project rules? Is the Sources of Truth table up to date? Are all entries pointing to files that actually exist?

**`testing.md`** — Are the testing commands described accurately? Do persona references match current `personas/` in the workspace?

**`getting-started.md`** — Are the setup steps still accurate for the current Docker/bootstrap setup? Do any `make` commands referenced exist as targets in the workspace root `Makefile`?

**`global-claude-settings.md`** — Do the permissions, hooks, and env vars described match what is actually in `{GITHUB_REPO}/global-settings/settings.json`? Are any settings documented that no longer exist in `settings.json`? Are there settings or hooks in `settings.json` that are undocumented or misdescribed?

**`README.md`** (docs/claude/) — Is the docs index complete? Does the Quick Reference flow match the actual workflow?

**`schema.yaml` (specs artifact instruction)** — Does the `specs` artifact instruction in `openspec/schemas/conduction/schema.yaml` align with `writing-specs.md`? Apply the same logic as Check C from Step 0.5. Flag any scenario format, RFC 2119 guidance, or required-section differences that were introduced in `writing-specs.md` but not reflected in the schema instruction.

**`templates/spec.md`** — Does the template in `openspec/schemas/conduction/templates/spec.md` use GIVEN/WHEN/THEN? Does it include all three delta operations (ADDED/MODIFIED/REMOVED)? Does it match the delta spec format documented in `writing-specs.md`?

**`{GITHUB_REPO}/README.md`** — Does it accurately describe the project, workspace structure, and setup steps? Is it consistent with what's actually implemented? Do any `make` commands referenced exist as targets in the workspace root `Makefile`? Do any references to usage-tracker (setup steps, CLI commands, model list) still match `{GITHUB_REPO}/usage-tracker/README.md`, `SETUP.md`, and `MODELS.md`?

Present a summary per file showing what's accurate, what's outdated, what's missing, and what violates documentation principles.

### Phase 4: Confirm and Update

Same flow as App Docs Phase 4 — ask before making changes, offer per-file or all-at-once.

**When updating `{GITHUB_REPO}/docs/claude/`**, follow `{GITHUB_REPO}/docs/claude/writing-docs.md`:
- Never change the *intent* of the documentation without user confirmation — these docs guide Claude's behavior
- Focus on factual accuracy: command names, file paths, step descriptions
- **Replace duplicated content with links** — if a dev doc restates what's already in a command file or spec, replace with a reference per [Reference, Don't Duplicate](../../docs/writing-docs.md#the-core-rule-reference-dont-duplicate)
- Use `[Legacy]` markers for superseded approaches — see [Outdated and Legacy Documentation](../../docs/writing-docs.md#outdated-and-legacy-documentation)
- Avoid writing anti-patterns — see [Writing Anti-Patterns](../../docs/writing-docs.md#writing-anti-patterns)
- If a significant behavioral change is proposed, flag it for user review

### Phase 5: Report

```
Docs Sync — Dev Docs ({GITHUB_REPO})
──────────────────────────────────────
{GITHUB_REPO}/README.md                                         — N changes applied
{GITHUB_REPO}/docs/claude/commands.md                           — N commands added/updated
{GITHUB_REPO}/docs/claude/workflow.md                           — artifact diagram updated
{GITHUB_REPO}/docs/claude/writing-specs.md                      — up to date, no changes
{GITHUB_REPO}/docs/claude/testing.md                            — N changes applied
{GITHUB_REPO}/docs/claude/README.md                             — N links updated
openspec/schemas/conduction/schema.yaml                         — specs instruction updated
openspec/schemas/conduction/templates/spec.md                   — template updated
```

### Phase 6: Commands and Skills Review

After applying documentation changes, audit commands and skills in two parts.

---

#### Part A — Change-impact check

Run this unconditionally. Read all `SKILL.md` files in `.claude/skills/` and compare them against the documentation changes just made in this sync. Look for:

- **Stale references** — a command or skill references a file path, section heading, or doc name that was renamed or moved during this sync
- **Outdated instructions** — a command or skill instructs Claude to follow a workflow, use a tool, or apply a principle that was updated in the docs just synced
- **Missing guidance** — the updated doc introduced a new rule or convention (e.g. a new writing anti-pattern, a new source of truth entry) that a command or skill should now follow but doesn't mention
- **Redundant inline content** — a command or skill restates something now clearly documented in a doc file; replace with a link

After completing Part A, present its findings and ask using AskUserQuestion:

**"Part A (change-impact check) complete. Would you also like to run the standalone health check on all skill files (`.claude/skills/`)?"**

The standalone health check reads every command and skill file independently and audits the full content — broken links, stale references, anti-patterns, scope creep, and more. It is thorough but takes significantly longer.

- **Yes, run the health check** — continue to Part B
- **No, skip** — go straight to the combined summary with Part A findings only

---

#### Part B — Standalone health check *(optional)*

Only run if the user selected "Yes" above.

Read every command and skill file independently and audit against the current state of the project. This is not limited to what changed in this sync — it is a full content review.

**What to load as reference:**
- `{GITHUB_REPO}/docs/claude/writing-docs.md` — documentation principles (link vs duplicate, audience, anti-patterns)
- `openspec/config.yaml` — current rules, schema name, and context conventions
- `{GITHUB_REPO}/docs/claude/commands.md` — canonical command descriptions and signatures
- `openspec/schemas/conduction/schema.yaml` — active schema artifact instructions

**What to check for each command and skill file:**

1. **Broken or stale links** — any `[text](path)` that points to a file, section heading, or anchor that no longer exists or was moved. Cross-check against the actual file tree. Flag every broken path.

2. **Stale content references** — mentions of file paths, command names, persona names, spec names, or tool names that have changed. Example: a command that references an app directory that was renamed, or a skill that lists a command flag that no longer exists.

3. **Duplicated content** — inline content that restates something already clearly covered in a source of truth (per `writing-docs.md` Sources of Truth table). Per the Reference, Don't Duplicate principle: flag blocks of inline guidance that should link to the authoritative doc instead. Common cases:
   - A skill that restates writing-docs.md rules rather than pointing to the section
   - A command that restates spec structure details already in writing-specs.md
   - A command that inlines persona descriptions that live in `personas/`

4. **Missing cross-references** — a command or skill covers a concern for which a relevant doc exists, but doesn't reference it. Example: a testing skill that describes persona behavior without pointing to `personas/`; a spec-creation command that doesn't reference `writing-specs.md`. Only flag where the missing link would meaningfully help Claude.

5. **Writing anti-patterns** — time-sensitive language ("currently", "as of now", "recently"), hardcoded version numbers in prose, vague actors ("the user", "you should"), "see above" / "see below" positional references. See [Writing Anti-Patterns](../../docs/writing-docs.md#writing-anti-patterns).

6. **Outdated workflow steps** — steps that no longer match current project conventions, tooling, or the command descriptions in `commands.md`. Examples: a skill that says to run a command with a flag that was removed; a command that refers to an artifact phase that no longer exists; a skill that references the wrong browser number for parallel testing.

7. **Content that should be removed** — instructions for features or workflows that have been removed from scope, or caveats that are no longer true. Only remove content when it is clearly no longer valid — if in doubt, flag as `[Verify]`.

8. **Scope creep** — a skill or command that has grown to include guidance that belongs in a different file (e.g. lengthy setup instructions in a testing skill that belong in `getting-started.md`). Flag for extraction and linking.

**Depth guidance:**
- Read every `SKILL.md` in `.claude/skills/`
- For each file, run all 8 checks above
- Flag only real issues — don't flag something just because it could theoretically be shorter or link somewhere. The bar is: does this mislead Claude, break something, or clearly violate writing-docs.md principles?

---

After presenting Part B findings (or if B was skipped), ask using AskUserQuestion:

**"Would you also like to run a doc structure review of `{GITHUB_REPO}/docs/claude/`?"**

- **Yes, run doc structure review** — continue to Part C
- **No, skip** — go straight to the combined summary

---

#### Part C — Doc structure review *(optional)*

Only run if the user selected "Yes" above.

Read all files in `{GITHUB_REPO}/docs/claude/` and audit the structure holistically — not individual file content, but how the files relate to each other.

**What to check:**

1. **Overlap in purpose** — do any two docs serve the same audience for the same concern? A doc that overlaps heavily in topic and audience with another is a maintenance risk: they will diverge over time. Flag pairs that cover more than ~50% of the same ground.

2. **Differentiation** — for docs that overlap in subject or audience, is each doc's distinct role clear from the first paragraph? A reader picking up either file should immediately understand why this one exists and how it differs from the other. If the distinction is not visible from the intro, flag it.

3. **Missing cross-references between overlapping docs** — when two docs share topic or audience, do they reference each other at the relevant point? Could the overlap in one file be reduced by replacing duplicated content with a link to the other? Flag cases where a single well-placed cross-reference would eliminate meaningful duplication.

4. **Doc proliferation** — docs that cover a concern narrow enough to warrant only a section in an existing file. A standalone doc is justified when it has internal navigation needs, targets a distinct audience, or is frequently referenced from multiple places. A short, narrowly-scoped doc that is always read alongside one other doc is a candidate for merger.

**Depth guidance:**
- Read every file in `{GITHUB_REPO}/docs/claude/`
- For each pair of docs that share subject matter, run checks 1–4
- Flag only real structural issues — a different angle on the same topic is not overlap; repetition of the same content for the same audience is

---

#### Combined summary and confirmation

Present a single consolidated summary covering all parts that were run.

```
Dev Docs Review
────────────────────────────────────────
Skills & Commands  (Part A + Part B — or "Part A only" if B was skipped)

  .claude/skills/sync-docs/SKILL.md               — N items  [A: 1, B: 2]
  .claude/skills/opsx-ff/SKILL.md                  — up to date
  .claude/skills/test-counsel/SKILL.md             — N items  [B: 1 stale link, 1 anti-pattern]
  .claude/skills/test-scenario-create/SKILL.md     — up to date
  ...
  Subtotal: N items across M skills

Doc Structure  (Part C — omit section if C was skipped)

  docs/claude/workflow.md + getting-started.md    — N items  [C: overlap, no cross-ref]
  docs/claude/docker.md                           — up to date
  ...
  Subtotal: N items across M doc pairs

Total: N items across M files
```

For each flagged item, include a one-line description of the issue and which check (1–8, Part A, or C) identified it.

Then ask using AskUserQuestion:

**"Documentation sync is complete. I found N items across M files. What would you like to do?"**
- **Update all** — apply all identified updates
- **Review each** — go through each file one at a time, confirm before each update
- **Show proposed changes first** — show all diffs, then confirm once
- **Skip** — leave commands and skills as-is for now

Apply updates following the same guardrails as Phase 4 — never change the intent of a command or skill without user confirmation. Flag anything ambiguous as `[Verify]` rather than making assumptions.

```
All dev docs are now current.
```

---

## Capture Learnings

After execution, review what happened and append new observations to [learnings.md](learnings.md) under the appropriate section:

- **Patterns That Work** — approaches that produced good results
- **Mistakes to Avoid** — errors encountered and how they were resolved
- **Domain Knowledge** — facts discovered during this run
- **Open Questions** — unresolved items for future investigation

Each entry must include today's date. One insight per bullet. Skip if nothing new was learned.

---

## Guardrails

- **Never auto-save** — always show what will change and ask for confirmation before writing
- **Docs only — never touch code or config** — this command makes changes exclusively to `.md` documentation files. Never modify source code, scripts, JSON, YAML, TOML, shell scripts, or any other non-markdown file, even if they contain documentation-adjacent content (e.g., inline comments, descriptions in `settings.json`). Load non-markdown files as read-only reference — never write to them
- **Non-standard doc files require confirmation** — if documentation content is found in a file that is not a `.md` file (e.g., a `README` without extension, an `.rst` file, or a `CHANGELOG`), always ask for confirmation before making any changes to it
- **Preserve writing style** — match the tone and structure of existing docs
- **Don't invent features** — only document what is in specs with status `in-progress` or `done`
- **Cross-reference accurately** — when adding links to specs or other files, verify the file exists first
- **Flag ambiguities** — if you're unsure whether something is implemented, mark it as `[Verify]` in your proposed changes rather than assuming
- **App docs stay user-friendly** — `{APP_DIR}/docs/` is for end users and admins, not developers; keep it jargon-free
- **Dev docs stay precise** — `{GITHUB_REPO}/docs/claude/` is read by Claude at runtime; accuracy matters more than prose quality
- **Follow writing-docs.md** — the full documentation principles live at `{GITHUB_REPO}/docs/claude/writing-docs.md`; apply them when writing any update
- **No `.claude/` links in app docs** — the `.claude/` folder is an internal Claude workspace tool; app users and admins must never be directed there. When auditing against ADRs, use them as context to check correctness — never insert links like `[ADR-001](../../.claude/openspec/architecture/...)` into app documentation. If a doc needs to acknowledge an architectural standard, name it in prose and be explicit about its origin: use "Conduction ADR-002" (for company-wide rules from `{APPS_EXTRA}/.claude/openspec/architecture/`) or "{APP_NAME} ADR-001" (for app-specific rules from `{APP_DIR}/openspec/architecture/`) — because both levels use the same numbering scheme and an unqualified "ADR-001" is ambiguous. Never use a file link to either location. Mentioning the qualified ADR name inline is fine; linking to any `.claude/` path is not.
