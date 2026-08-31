#!/usr/bin/env node
/*
 * Guard: every `@spec` target must resolve, active or archived.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * `@spec openspec/...` tags are the traceability link from a method back to
 * the requirement that asked for it. Nothing checked that the path on the far
 * end of that link still resolves:
 *
 *   - hydra gate-16 matches the literal string `@spec openspec/` and never
 *     opens the file;
 *   - the phpcs sniff only checks that a tag is PRESENT.
 *
 * So a tag can point at nothing and still be green everywhere. Measured on
 * 2026-08-31: 1,989 tags across 98 distinct targets did not resolve as
 * written.
 *
 * 🔴 ARCHIVING A CHANGE IS NOT DELETING IT, AND THIS IS THE WHOLE POINT.
 * When a change is archived it moves to `openspec/changes/archive/` AND gains
 * a date prefix:
 *
 *   openspec/changes/entity-notes/tasks.md
 *   openspec/changes/archive/2026-05-31-entity-notes/tasks.md
 *
 * The content is intact; only the path moved. A checker that compares the
 * literal path calls all 1,989 of those broken and produces a rewrite that is
 * pure churn — and the rewritten archive paths break again at the next
 * reorganisation. This resolver therefore follows the rename, so a link to an
 * ARCHIVED spec is as valid as a link to an active one. Of those 98 targets,
 * 97 resolve this way.
 *
 * A spec still being drafted inside an active change also resolves: a tag may
 * name `openspec/specs/<capability>/spec.md` before the change that introduces
 * it is promoted, and `openspec/changes/<change>/specs/<capability>/spec.md`
 * is the same document at an earlier address.
 *
 * Anchors are checked too when present, against the headings in the resolved
 * file, using GitHub's slug rules plus this fleet's bare `REQ-XXX-000` form.
 *
 * Exit code is the number of unresolvable targets.
 */
const fs = require('fs')
const path = require('path')

const ROOT = path.resolve(__dirname, '..')
const SPEC_RE = /@spec\s+(openspec\/[^\s*]+)/g
const SCAN_DIRS = ['lib', 'src']
const SCAN_EXT = new Set(['.php', '.js', '.vue', '.ts', '.mjs'])

/** Every file under dir whose extension we scan. */
function walk(dir, out = []) {
	let entries
	try {
		entries = fs.readdirSync(dir, { withFileTypes: true })
	} catch {
		return out
	}
	for (const e of entries) {
		const p = path.join(dir, e.name)
		if (e.isDirectory()) {
			if (e.name !== 'node_modules' && e.name !== 'vendor') walk(p, out)
		} else if (SCAN_EXT.has(path.extname(e.name))) {
			out.push(p)
		}
	}
	return out
}

/** Archived change directories, indexed by the slug under their date prefix. */
function archiveIndex() {
	const base = path.join(ROOT, 'openspec', 'changes', 'archive')
	const index = new Map()
	let names = []
	try {
		names = fs.readdirSync(base)
	} catch {
		return index
	}
	for (const name of names) {
		// `2026-05-31-2026-03-20-client-management` archives
		// `2026-03-20-client-management`: strip ONE leading date only.
		const slug = name.replace(/^\d{4}-\d{2}-\d{2}-/, '')
		if (!index.has(slug)) index.set(slug, [])
		index.get(slug).push(name)
	}
	return index
}

/** Candidate on-disk locations for one cited target, best first. */
function candidates(target, archive) {
	const out = [path.join(ROOT, target)]

	const changed = target.match(/^openspec\/changes\/([^/]+)\/(.+)$/)
	if (changed) {
		const [, slug, tail] = changed
		for (const dir of archive.get(slug) || []) {
			out.push(path.join(ROOT, 'openspec', 'changes', 'archive', dir, tail))
		}
	}

	// A capability spec may still live inside the change that introduces it.
	const spec = target.match(/^openspec\/specs\/([^/]+)\/(.+)$/)
	if (spec) {
		const [, capability, tail] = spec
		const changesDir = path.join(ROOT, 'openspec', 'changes')
		let changes = []
		try {
			changes = fs.readdirSync(changesDir)
		} catch {
			changes = []
		}
		for (const change of changes) {
			if (change === 'archive') continue
			out.push(path.join(changesDir, change, 'specs', capability, tail))
		}
		for (const dirs of archive.values()) {
			for (const dir of dirs) {
				out.push(
					path.join(changesDir, 'archive', dir, 'specs', capability, tail),
				)
			}
		}
	}

	return out
}

/**
 * The slug forms a heading can plausibly be cited by.
 *
 * There is no single right answer to check against, which is why this returns
 * a set. GitHub's slugger DELETES punctuation and turns each remaining space
 * into a hyphen, so `REQ-MDM-001 — Golden Record` becomes
 * `req-mdm-001--golden-record` with a DOUBLE hyphen where the em-dash was.
 * Hand-written tags routinely collapse that to one, and some tools turn `A/B`
 * into `a-b` where GitHub yields `ab`. Accepting every one of those is
 * deliberate: the job here is to catch an anchor that names nothing in the
 * file, not to arbitrate slug dialects. Being strict about the dialect
 * produced hundreds of findings that were all the same non-problem.
 */
function slugVariants(heading) {
	const lower = heading.toLowerCase().trim()
	const dropped = lower.replace(/[^\w\s-]/g, '')
	const hyphenated = lower.replace(/[^\w\s-]/g, ' ')
	const out = new Set()
	for (const base of [dropped, hyphenated]) {
		out.add(base.trim().replace(/ /g, '-'))
		out.add(base.trim().replace(/\s+/g, '-'))
	}
	return [...out].filter(Boolean)
}

/**
 * Anchors a markdown file offers.
 *
 * Three schemes are in use across openspec, and a matcher that knows only the
 * first reports the other two as broken — 887 false positives when measured
 * with headings alone, against 0 real ones:
 *
 *   1. GitHub heading slugs, e.g. `#requirement-lead-crud-mvp`.
 *   2. A `REQ-XXX-000` id ANYWHERE in a heading, not only at its start:
 *      `### Scenario REQ-BIE-008-01: Successful file upload` is cited as
 *      `specs.md#REQ-BIE-008-01`.
 *   3. A task id from a `tasks.md` checklist item — `- [x] 3.3 Create daily
 *      health-check job` is cited as `tasks.md#3.3` or `#task-3.3`. These are
 *      list items, so no heading will ever match them.
 */
function anchorsOf(file) {
	const found = new Set()
	for (const line of fs.readFileSync(file, 'utf8').split('\n')) {
		const heading = line.match(/^#{1,6}\s+(.*?)\s*$/)
		if (heading) {
			for (const s of slugVariants(heading[1])) found.add(s)
			// `## 3. Frontend` is cited as `#task-3`: a tasks.md section number
			// IS the task id, and no item literally reads "task-3".
			const section = heading[1].match(/^(\d+(?:\.\d+)*)\.?\s/)
			if (section) {
				found.add(section[1])
				found.add(`task-${section[1]}`)
			}
			// `## SegmentService (Task 2.1 of giant)` is cited as `#task-2.1`:
			// the id sits INSIDE the heading, not at its start.
			const inline = heading[1].match(/\(Task\s+(\d+(?:\.\d+)*)/i)
			if (inline) {
				found.add(inline[1])
				found.add(`task-${inline[1]}`)
			}
		}
		const text = heading ? heading[1] : line
		// `REQ-BIE-008-01`, `REQ-001` and `REQ-PORTAL-ORIGIN` are all in use, so
		// the token cannot assume a letters-then-digits shape.
		for (const req of text.matchAll(/REQ-[A-Z0-9]+(?:-[A-Z0-9]+)*\b/g)) {
			found.add(req[0])
		}
		// `- [x] 3.3 Create ...` and `- [x] task-30: ...` are both in use.
		// `[ ]`, `[x]` and `[~]` (adapted) are all in use as checkbox states.
		const task = line.match(/^\s*-\s*\[[^\]]?\]\s*((?:task-)?\d+(?:\.\d+)*)/i)
		if (task) {
			found.add(task[1])
			found.add(task[1].replace(/^task-/i, ''))
		}
	}
	return found
}

/**
 * A cited anchor, reduced to the forms anchorsOf() emits.
 *
 * Case is ignored, and the `requirement-` / `scenario-` prefix is optional on
 * either side: `#Backend-SLA-Deadline-Service` names the heading
 * `### Requirement: Backend SLA Deadline Service` beyond any doubt, and
 * refusing it teaches readers that the check is noise. What must still fail is
 * an anchor naming nothing in the file at all.
 */
function anchorForms(anchor) {
	const forms = new Set()
	// The anchor is itself a slug attempt, so slugify it the same way a
	// heading is: `#Requirement:-A-settled-POS-sale-...` keeps a colon and its
	// original case, and names its heading perfectly well without them.
	const slugged = anchor
		.toLowerCase()
		.replace(/[^\w\s-]/g, '')
		.replace(/\s+/g, '-')
	for (const base of [anchor, anchor.replace(/^task-/i, ''), slugged]) {
		forms.add(base)
		forms.add(base.toLowerCase())
		forms.add(base.toLowerCase().replace(/^(requirement|scenario)-/, ''))
		forms.add(`requirement-${base.toLowerCase()}`)
		forms.add(`scenario-${base.toLowerCase()}`)
	}
	return forms
}

const archive = archiveIndex()
const cites = new Map()
for (const dir of SCAN_DIRS) {
	for (const file of walk(path.join(ROOT, dir))) {
		const text = fs.readFileSync(file, 'utf8')
		for (const m of text.matchAll(SPEC_RE)) {
			const target = m[1].replace(/[.,;:]+$/, '')
			if (!cites.has(target)) cites.set(target, new Set())
			cites.get(target).add(path.relative(ROOT, file))
		}
	}
}

const unresolved = []
const anchorMisses = []
for (const [target, users] of cites) {
	const [file, anchor] = target.split('#')
	// ALL existing candidates, not the first. A change can be archived more
	// than once (entity-notes has three dated copies), and the cited anchor may
	// live in a later revision than the one that happens to sort first.
	const hits = candidates(file, archive).filter((p) => fs.existsSync(p))
	if (hits.length === 0) {
		unresolved.push({ target, users })
		continue
	}
	const hit = hits[0]
	// Anchors are checked against the file the citation NAMES when that file
	// exists, and only against the fallbacks when it does not. Pooling every
	// candidate's anchors let a stale citation pass by matching an ARCHIVED
	// copy that still carried the old heading: renaming a requirement in the
	// live spec left `#requirement-prospect-to-lead-conversion` resolving
	// against the archive, and hydra's gate-46 caught what this missed.
	const literal = path.join(ROOT, file)
	const anchorSources = fs.existsSync(literal) === true ? [literal] : hits
	const offered = anchor
		? new Set(
				anchorSources
					.flatMap((h) => [...anchorsOf(h)])
					.flatMap((a) => [
						a,
						a.toLowerCase(),
						a.toLowerCase().replace(/^(requirement|scenario)-/, ''),
					]),
			)
		: null
	// A heading may also be cited by its leading words: `## BlastSendJob (Task
	// 2.5 of giant)` slugs to `blastsendjob-task-25-of-giant` and is cited as
	// `#blastsendjob`. Accept a prefix that ends on a hyphen boundary and is
	// long enough not to match by accident.
	const forms = anchor ? [...anchorForms(anchor)] : []
	const prefixHit =
		anchor
		&& forms.some(
			(a) =>
				a.length >= 8
				&& [...offered].some((o) => o === a || o.startsWith(`${a}-`)),
		)
	if (anchor && !forms.some((a) => offered.has(a)) && !prefixHit) {
		anchorMisses.push({ target, users, hit: path.relative(ROOT, hit) })
	}
}

const label = `spec-links [pipelinq]: ${cites.size} distinct @spec targets`
if (unresolved.length === 0 && anchorMisses.length === 0) {
	console.log(`${label} — all resolve (active or archived), anchors included`)
	process.exit(0)
}

console.error(label)
for (const u of unresolved) {
	console.error(`  UNRESOLVED  ${u.target}`)
	for (const f of [...u.users].slice(0, 3)) console.error(`              ${f}`)
}
for (const a of anchorMisses) {
	console.error(`  NO ANCHOR   ${a.target}`)
	console.error(`              resolved to ${a.hit}`)
	for (const f of [...a.users].slice(0, 2)) console.error(`              ${f}`)
}
console.error(
	`\n${unresolved.length} unresolvable target(s), ${anchorMisses.length} missing anchor(s).`,
)
console.error(
	'An ARCHIVED spec is a valid target: this check follows the archive rename.',
)
process.exit(unresolved.length + anchorMisses.length)
