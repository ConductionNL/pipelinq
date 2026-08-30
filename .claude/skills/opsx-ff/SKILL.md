---
name: opsx-ff
description: Create a change and generate all artifacts needed for implementation in one go
metadata:
  category: Workflow
  tags: [workflow, artifacts, experimental]
---

Fast-forward through artifact creation - generate everything needed to start implementation.

**Input**: The argument after `/opsx-ff` is the change name (kebab-case), OR a description of what the user wants to build.

**Steps**

1. **If no input provided, ask what they want to build**

   Use the **AskUserQuestion tool** (open-ended, no preset options) to ask:
   > "What change do you want to work on? Describe what you want to build or fix."

   From their description, derive a kebab-case name (e.g., "add user authentication" → `add-user-auth`).

   **IMPORTANT**: Do NOT proceed without understanding what the user wants to build.

1.5. **Confirm the plan before generating**

   Summarize your understanding and use **AskUserQuestion** to confirm before doing any work:

   > "I'll create a change called `<name>` to: <one-sentence summary of what the user wants to build>. Ready to generate all artifacts?"

   Options:
   - **Yes, generate all artifacts** — proceed to Step 2
   - **Let me clarify something first** — ask a targeted follow-up question, then re-confirm before continuing

   **Do NOT create any files until confirmed.**

1.55. **Select model for artifact generation**

   This skill generates OpenSpec artifacts (proposal, specs, design, tasks) — the quality of these artifacts determines implementation quality downstream.

   Ask the user using AskUserQuestion:

   **"Which model should I use for artifact generation?"**

   | Model | Pros | Cons |
   |---|---|---|
   | **Sonnet (recommended)** | Good artifact quality, moderate quota | Solid for most changes |
   | **Opus** | Best design and architectural reasoning | Uses more quota — worth it for complex or architectural changes |

   - **Sonnet**
   - **Opus**

   Use the **Agent tool** with `model: "sonnet"` or `model: "opus"` (whichever was selected) to delegate Steps 2–4. Pass the subagent:
   - The change name
   - Full contents of any app design files loaded in Step 1.6
   - The complete instructions for Steps 2–4 from this skill
   - Instruction: return a `DEFERRED_QUESTIONS` list at the end of its output — one entry per decision made under uncertainty (see Step 4c)

   When the subagent completes:
   - **MANDATORY**: If the subagent returned ANY `DEFERRED_QUESTIONS`, you MUST ask the user EVERY question — no exceptions. Do NOT evaluate, triage, or skip questions yourself. Do NOT conclude "no user input needed" for any question. The subagent deferred these questions precisely because they require human judgment.
   - For each deferred question, use **AskUserQuestion** to present:
     1. The question the subagent would have asked
     2. The provisional decision the subagent made
     3. Which artifact it affects
   - Ask one question at a time. Wait for the user's answer before asking the next.
   - After each answer: re-read the relevant artifact. If the user's answer differs from the provisional decision, update the artifact. Show "✎ Updated <artifact-id>" or "✓ Kept as-is" based on the user's explicit confirmation.
   - Then continue to Step 5.

1.6. **Load app design context (if present)**

   Before creating any artifacts, check for and silently load app design documents. These inform proposal scope, architecture constraints, and applicable ADRs — the `openspec instructions` context does not include them.

   | File | If present, use to... |
   |------|----------------------|
   | `openspec/changes/<name>/context-brief.md` | **Specter intelligence brief** — full features, user stories, stakeholders, schemas, standards, ADRs. This is the PRIMARY input when present — read it fully and use its data for all artifacts |
   | `openspec/architecture/` | Check for repo-specific ADRs that constrain or inform the implementation approach |
   | `.claude/openspec/architecture/` | Check company-wide ADRs (always apply) |
   | `docs/ARCHITECTURE.md` | Understand app-specific technology decisions and data model |
   | `docs/FEATURES.md` | Confirm the feature tier and roadmap phase for what is being built |

   If a `context-brief.md` exists, it contains market-researched features with demand scores, real user stories with acceptance criteria, stakeholder profiles with pain points, and full data model schemas. Use this data directly in artifacts — do not invent features or stories when the brief provides them.

   If none of these files exist beyond the standard ADRs, proceed silently — do not block or prompt the user.

2. **Create the change directory**
   ```bash
   openspec new change "<name>"
   ```
   This creates a scaffolded change at `openspec/changes/<name>/`.

3. **Get the artifact build order**
   ```bash
   openspec status --change "<name>" --json
   ```
   Parse the JSON to get:
   - `applyRequires`: array of artifact IDs needed before implementation (e.g., `["tasks"]`)
   - `artifacts`: list of all artifacts with their status and dependencies

4. **Create artifacts in sequence until apply-ready**

   Use the **TodoWrite tool** to track progress through the artifacts.

   Loop through artifacts in dependency order (artifacts with no pending dependencies first):

   a. **For each artifact that is `ready` (dependencies satisfied)**:
      - Get instructions:
        ```bash
        openspec instructions <artifact-id> --change "<name>" --json
        ```
      - The instructions JSON includes:
        - `context`: Project background (constraints for you - do NOT include in output)
        - `rules`: Artifact-specific rules (constraints for you - do NOT include in output)
        - `template`: The structure to use for your output file
        - `instruction`: Schema-specific guidance for this artifact type
        - `outputPath`: Where to write the artifact
        - `dependencies`: Completed artifacts to read for context
      - Read any completed dependency files for context
      - Create the artifact file using `template` as the structure
      - Apply `context` and `rules` as constraints - but do NOT copy them into the file
      - Show brief progress: "✓ Created <artifact-id>"

   b. **Continue until all `applyRequires` artifacts are complete**
      - After creating each artifact, re-run `openspec status --change "<name>" --json`
      - Check if every artifact ID in `applyRequires` has `status: "done"` in the artifacts array
      - Stop when all `applyRequires` artifacts are done

   c. **If an artifact requires user input** (unclear context):
      - Make a reasonable decision and continue — AskUserQuestion is not available inside a subagent
      - Add an entry to `DEFERRED_QUESTIONS`: the question you would have asked, the decision you made, and which artifact it affected
      - Return the full `DEFERRED_QUESTIONS` list at the end of your output so the parent can follow up with the user

5. **Show final status**
   ```bash
   openspec status --change "<name>"
   ```

**Output**

After completing all artifacts, summarize:
- Change name and location
- List of artifacts created with brief descriptions
- What's ready: "All artifacts created! Ready for implementation."

**What's Next**

**Recommended:** `/opsx-apply` — start implementing the tasks

**Optional before that:**
- `/opsx-plan-to-issues` — create GitHub Issues for progress tracking

**Spec maintenance:** After creating artifacts, check the proposal's `## Capabilities` section. For each capability listed under "Modified Capabilities" or "New Capabilities", find (or create) the corresponding spec at `openspec/specs/<capability>/spec.md` and:
- Add this change to the `**OpenSpec changes**` list (as a new line, after any existing entries, oldest-first ordering)
- Set `**Status**: in-progress` if it was `planned` or `done` — a new active change always moves the spec back to `in-progress`
- If the list exceeds 15 entries, apply the grouping rule from `.claude/docs/writing-specs.md` (group by timeframe, never remove entries)

**Artifact Creation Guidelines**

- Follow the `instruction` field from `openspec instructions` for each artifact type
- The schema defines what each artifact should contain - follow it
- Read dependency artifacts for context before creating new ones
- Use the `template` as a starting point, filling in based on context
- **design.md MUST include a Seed Data section** (ADR-001): research realistic objects per schema with general organization data (municipality, consultancy, travel agency). The apply agent generates `_registers.json` entries from this section
- **tasks.md MUST include a seed data task** when the change introduces or modifies OpenRegister schemas

## Capture Learnings

After artifacts are created, review what happened and append any new observations to [learnings.md](learnings.md):

- **Patterns That Work** — artifact generation approaches that produce high-quality, implementation-ready output
- **Mistakes to Avoid** — artifact generation errors, underspecified decisions, or deferred question pitfalls
- **Domain Knowledge** — facts about OpenSpec artifact schemas, dependency ordering, or generation patterns
- **Open Questions** — unresolved artifact quality challenges for future investigation

Each entry must include today's date. One insight per bullet. Skip if nothing new was learned.

---

**Guardrails**
- Create ALL artifacts needed for implementation (as defined by schema's `apply.requires`)
- Always read dependency artifacts before creating a new one
- If context is critically unclear, ask the user - but prefer making reasonable decisions to keep momentum
- **NEVER skip deferred questions from the subagent** — every `DEFERRED_QUESTIONS` entry MUST be presented to the user via AskUserQuestion, regardless of how reasonable the subagent's provisional decision seems
- If a change with that name already exists, ask if user wants to continue it or create a new one
- Verify each artifact file exists after writing before proceeding to next
