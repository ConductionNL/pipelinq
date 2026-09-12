#!/usr/bin/env node
/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * e2e navigation-cost check.
 *
 * WHY THIS EXISTS
 * ---------------
 * CI runs the Playwright suite with `workers: 6` against a single-threaded
 * `php -S` (tests/e2e/playwright.config.ts). Six browsers therefore queue on
 * one PHP process, and one document load of the app costs 13 to 23 seconds
 * there against about 2 seconds locally. The per-test budget is 60 seconds, so
 * a test has room for roughly TWO loads. A third is not slow, it is a timeout.
 *
 * That arithmetic is invisible while writing a test, and it has now cost three
 * separate investigations:
 *
 *   • spec-coverage/bi-export-jobs-bug.spec.ts — three loads, one of them an
 *     openApp() the next line navigated off, one a reload() whose comment
 *     dated from hash routing. Fixed in #1912.
 *   • spec-coverage/appointment-booking.spec.ts — openApp() plus a deep link,
 *     on top of three seeded objects. Failed in run 34532703820 and passed on
 *     the same head in 34532768410: flaky, which is worse to diagnose than
 *     broken.
 *   • rapportage.spec.ts — a beforeEach that loaded a dashboard twice for six
 *     tests, five of which never looked at it. Papered over with a 180 s
 *     per-test raise.
 *
 * Every one of them presented as a bare "Test timeout of 60000ms exceeded"
 * naming no assertion, which reads as "the page is broken" rather than "this
 * test is too slow". Reading the traces to find out otherwise was the single
 * largest cost each time. This check finds the same shapes statically, before
 * anyone pays for a run.
 *
 * WHAT IT REJECTS
 * ---------------
 *   1. ABANDONED LOAD — `openApp(page)` immediately followed by a navigation.
 *      openApp() boots the shell at the Dashboard and waits for it; if the
 *      very next statement navigates somewhere else, that whole load was spent
 *      on a page the test never asserts against. Use `gotoAppRoute()`, which
 *      deep-links in one load.
 *
 *      openApp() is still correct when a test genuinely starts on the
 *      Dashboard, and when it needs a token-bearing page for an API call
 *      before it knows the URL it will visit. Both of those put real
 *      statements between the two calls, so neither trips this rule.
 *
 *   2. STALE RELOAD — `page.goto(...)` immediately followed by an
 *      unconditional `page.reload()`. Under hash routing a goto did not
 *      remount the view and the reload was how you got the target route; the
 *      shell moved to createWebHistory() in #1684 and the goto became a full
 *      document load that mounts the route itself. The reload after it is a
 *      second load re-rendering what is already on screen.
 *
 *      The guarded form — `const alreadyMounted = page.url().includes(...)`
 *      then `if (alreadyMounted) { await page.reload() }` — is correct and is
 *      NOT flagged: a same-document route change really does need a remount.
 *      spec-coverage/declarative-view-system.spec.ts is the reference.
 *
 *   3. GUARD READ TOO LATE — that same guard, but assigned AFTER the goto.
 *      `page.url()` then always names the page just navigated to, the guard is
 *      always true, and the reload never stops happening. It reads as a fix
 *      and behaves as a no-op, which is worse than the unguarded form because
 *      nobody looks at it twice. This rule exists because the first draft of
 *      this very change made that mistake in two files.
 *
 * It is deliberately dependency-free (pure Node, no build, no npm install), in
 * the same style as tests/l10n/check-l10n.js, so CI and a bare checkout both
 * run it the same way.
 *
 * Usage:
 *   node scripts/check-e2e-navigation.js
 *
 * Exit codes:
 *   0  no spec carries either shape
 *   1  at least one does (each reported with file, line and the fix)
 */

const fs = require('fs')
const path = require('path')

const E2E_DIR = path.resolve(__dirname, '..', 'tests', 'e2e')

/**
 * Every *.spec.ts under tests/e2e, recursively.
 *
 * @param {string} dir Directory to walk.
 * @return {string[]} Absolute paths, sorted.
 */
function specFiles(dir) {
	const out = []
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name)
		if (entry.isDirectory()) {
			if (entry.name === 'node_modules' || entry.name.startsWith('.')) continue
			out.push(...specFiles(full))
		} else if (entry.name.endsWith('.spec.ts')) {
			out.push(full)
		}
	}
	return out.sort()
}

/**
 * The index of the next line that carries executable code, skipping blanks and
 * comment-only lines. Comments are skipped on purpose: a paragraph explaining
 * WHY a navigation is there must not be able to hide the navigation after it.
 *
 * @param {string[]} lines The file's lines.
 * @param {number} from Index to start looking from.
 * @return {number} Index of the next code line, or -1 if there is none.
 */
function nextCodeLine(lines, from) {
	for (let i = from; i < lines.length; i++) {
		const t = lines[i].trim()
		if (
			t === ''
			|| t.startsWith('//')
			|| t.startsWith('*')
			|| t.startsWith('/*')
		) {
			continue
		}
		return i
	}
	return -1
}

const OPENS_DASHBOARD = /\bopenApp\s*\(/
const NAVIGATES = /\b(page\.goto\s*\(|goto[A-Z]\w*\s*\(|\w*[Pp]age\.goto\s*\()/
const GOES_TO = /\bpage\.goto\s*\(/
const RELOADS = /\bpage\.reload\s*\(/
const REMOUNT_GUARD = /alreadyMounted|alreadyOpen|isMounted/
const GUARD_ASSIGNMENT =
	/(const|let|var)\s+(alreadyMounted|alreadyOpen|isMounted)\s*=/

const findings = []

for (const file of specFiles(E2E_DIR)) {
	const rel = path.relative(path.resolve(__dirname, '..'), file)
	const lines = fs.readFileSync(file, 'utf8').split('\n')

	for (let i = 0; i < lines.length; i++) {
		const line = lines[i]
		const trimmed = line.trim()
		if (trimmed.startsWith('//') || trimmed.startsWith('*')) continue

		// 1. openApp() whose load the next statement throws away.
		if (OPENS_DASHBOARD.test(line) && !line.includes('function')) {
			const next = nextCodeLine(lines, i + 1)
			if (next !== -1 && NAVIGATES.test(lines[next])) {
				findings.push({
					file: rel,
					line: i + 1,
					rule: 'abandoned-load',
					detail:
						'openApp() boots the Dashboard and line '
						+ (next + 1)
						+ ' navigates straight off it, so that load is spent on a page '
						+ 'this test never asserts against.',
					fix: 'deep-link in one load with gotoAppRoute(page, route) from tests/e2e/helpers/pipelinq.ts',
				})
			}
		}

		// 2. goto() followed by an unconditional reload().
		if (GOES_TO.test(line) && !line.includes('function')) {
			// A goto call can span several lines; find where its statement ends.
			let end = i
			let depth = 0
			let started = false
			for (; end < lines.length; end++) {
				for (const ch of lines[end]) {
					if (ch === '(') {
						depth++
						started = true
					} else if (ch === ')') depth--
				}
				if (started && depth <= 0) break
			}
			// Walk forward over the guard scaffolding only. A reload reached
			// through nothing but a guard assignment, an `if`, and braces is a
			// reload OF THIS GOTO; a reload that first has to step over a real
			// statement (an assertion, a click) is something else and is left
			// alone.
			//
			// The walk matters: an earlier draft of this check looked only at
			// the single next code line, so inserting the guard assignment
			// between goto and reload hid the reload from it entirely. That is
			// how a check goes quiet on exactly the case it was written for.
			let next = -1
			let cursor = end + 1
			for (let step = 0; step < 4; step++) {
				const n = nextCodeLine(lines, cursor)
				if (n === -1) break
				if (RELOADS.test(lines[n])) {
					next = n
					break
				}
				const t = lines[n].trim()
				const scaffolding =
					GUARD_ASSIGNMENT.test(t)
					|| /^(if|}\s*else if)\s*\(/.test(t)
					|| t === '{'
				if (!scaffolding) break
				cursor = n + 1
			}

			if (next !== -1) {
				// The guard has to be READ before the goto. `page.url()` after a
				// goto always names the page just navigated to, so a guard
				// assigned afterwards is always true and the reload never stops
				// happening: a no-op wearing the shape of a fix. This check was
				// written after making exactly that mistake in this file's own
				// first draft, which is why it looks at WHERE the assignment is
				// and not merely whether the word appears.
				const guardedBefore = lines
					.slice(Math.max(0, i - 8), i)
					.some((l) => GUARD_ASSIGNMENT.test(l))
				const guardedAfter = lines
					.slice(i, next + 1)
					.some((l) => GUARD_ASSIGNMENT.test(l))
				const conditional =
					guardedBefore
					&& REMOUNT_GUARD.test(lines.slice(i, next + 1).join('\n'))

				if (guardedAfter && !guardedBefore) {
					findings.push({
						file: rel,
						line: next + 1,
						rule: 'guard-read-too-late',
						detail:
							'the remount guard is assigned AFTER the goto on line '
							+ (i + 1)
							+ ', so page.url() already names the target and the guard is '
							+ 'always true. The reload is unconditional in practice.',
						fix: 'assign the guard BEFORE the goto (see spec-coverage/declarative-view-system.spec.ts)',
					})
				} else if (!conditional) {
					findings.push({
						file: rel,
						line: next + 1,
						rule: 'stale-reload',
						detail:
							'page.goto() on line '
							+ (i + 1)
							+ ' is already a full document load that mounts the route '
							+ '(the shell routes on history since #1684), so this reload() '
							+ 'is a second load re-rendering what is on screen.',
						fix: 'drop the reload(), or guard it on `alreadyMounted` when the route change can be same-document (see spec-coverage/declarative-view-system.spec.ts)',
					})
				}
			}
		}
	}
}

if (findings.length === 0) {
	const n = specFiles(E2E_DIR).length
	console.log(
		`check-e2e-navigation: OK — ${n} spec files carry no abandoned load and no stale reload.`,
	)
	process.exit(0)
}

console.error(
	`check-e2e-navigation: ${findings.length} navigation(s) cost a page load for nothing.\n`
		+ 'CI runs 6 workers against one php -S, so a load is 13 to 23 s against a 60 s\n'
		+ 'per-test budget. A wasted load is how a passing test becomes a flaky one.\n',
)
for (const f of findings) {
	console.error(`  ${f.file}:${f.line}  [${f.rule}]`)
	console.error(`    ${f.detail}`)
	console.error(`    fix: ${f.fix}\n`)
}
process.exit(1)
