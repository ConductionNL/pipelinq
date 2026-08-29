---
name: clean-env
description: Reset the OpenRegister development environment (stop, remove volumes, restart, install apps)
---

# Clean Environment

Reset the Conduction dev instance and bring it back up healthy.

> **This skill used to name a script that does not exist.** It said to run
> `bash .claude/scripts/clean-env.sh`; there is no such file in this repo or in
> any app checkout, and there never was one to find. Its documented app list was
> five entries long (`openregister opencatalogi softwarecatalog nldesign
> launchpad`) at a time when the fleet was twenty-one and three of those five
> names were app *directories* rather than app ids. Anyone following it got a
> "command not found" if they were lucky and a half-configured instance if they
> improvised past it. The real, maintained entry point is
> `.github/dev-up.sh` — it is the one place that knows about the mounts, the
> vendored PHP dependencies and the frontend bundles.

**Model check — only apply when this skill is run standalone. Skip this section entirely if this skill was called from within another skill — the calling skill is responsible for model selection.**

**Check the active model** from your system context (it appears as "You are powered by the model named…").

- **On Haiku**: proceed normally — this is the right model for this task.
- **On Sonnet**: inform the user and ask using AskUserQuestion:
  > "⚠️ You're on Sonnet. This skill runs a shell script — purely mechanical, no reasoning required. Haiku is a better fit and conserves quota for heavier tasks. Switch with `/model haiku`, or proceed with Sonnet."
  Options: **Proceed with Sonnet** / **Switch to Haiku first** (stop here if switching)
- **On Opus**: stop immediately:
  > "You're on Opus. This skill runs a shell script — purely mechanical, no reasoning required. Opus is overkill here and will waste quota unnecessarily. Please switch to Haiku (`/model haiku`) and re-run."

## Instructions

Work from the workspace root (the directory holding `.github/` and the app
checkouts as siblings).

### Restart and heal — the common case

Most "my environment is broken" reports need only this. It re-establishes
partial mounts, clears maintenance mode, runs pending migrations, installs any
missing `vendor/`, enables every mounted Conduction app and reports any app
whose frontend bundle would render blank:

```bash
bash .github/dev-up.sh
```

### Full reset — destructive

**This deletes the database and every volume.** Only run it when a full reset is
actually intended; `dev-up.sh` alone fixes most breakage.

```bash
docker compose -p openregister -f .github/docker-compose.yml down -v
bash .github/dev-up.sh
```

## Verifying

`dev-up.sh` ends in a status block — read it rather than assuming success:

- `needsDbUpgrade: false` — an instance stuck at `true` serves a 503 the moment
  anything trips maintenance mode.
- `apps visible: N/N` — a shortfall is named per app, and it says whether the
  cause is an empty checkout (clone it) or a mount that did not attach.
- `apps enabled:` plus any `⚠` lines. **A `⚠ … need a frontend rebuild` line
  means those apps are enabled and still render a blank page** — `occ` reports
  them as perfectly healthy. Run the `npm ci && npm run build` command the
  script prints for each one.

Then open http://localhost:8080 (admin/admin) and confirm the apps appear in the
app menu.

## If an app still fails to enable

`dev-up.sh` prints the actual `occ` error per app; act on that rather than
retrying. The two failures that recur:

- **`Class "…" not found`** — the app's `vendor/` is missing or half-installed.
  `(cd <app-dir> && composer install --no-dev --ignore-platform-reqs)`. If
  composer dies on `Could not delete …/vendor/…`, the tree contains root-owned
  files from a container-side composer run; `dev-up.sh` heals that on its next
  run.
- **`SKIP <app> — empty checkout`** — the directory is mounted but empty. Clone
  the repo into it, or drop its mount from `.github/docker-compose.yml`.

To enable one by hand (note `-u www-data` — without it `occ` runs as root and
refuses):

```bash
docker exec -u www-data nextcloud php occ app:enable <app-id>
```

Use the app **id** (`integriq`, `filinq`, `dossiq`, `stackiq`, `keepiq`,
`larpinq`, `learniq`, `decidiq`, `buildiq`, `humaniq`), not the checkout
directory name (`openconnector`, `docudesk`, `procest`, …). The id is the `<id>`
in `appinfo/info.xml`; a wrong name is not an error you will notice, because
`occ app:enable` on an unknown app just does nothing useful.

> 💡 If you switched models to run this command, don't forget to switch back to your preferred model with `/model <name>` (e.g. `/model default` or `/model sonnet`).
