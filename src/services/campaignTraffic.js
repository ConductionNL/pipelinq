// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the site-traffic block on the blast performance dashboard should say,
 * given how its per-blast reads went.
 *
 * WHY THIS IS A FUNCTION AND NOT THREE LINES IN THE COMPONENT. The dashboard
 * used to carry this decision in one nullable boolean, `trafficConnected`, and
 * that value meant three different things at once: still loading, nothing to
 * ask about, and every ask failed. The template read all three as "loading", so
 * an instance with no blasts showed a spinner that never stopped.
 *
 * The distinction is the whole defect, and the component's vitest environment
 * mounts nothing, so keeping it here is what makes it testable at all.
 */

/**
 * Classify the outcome of the per-blast performance reads.
 *
 * @param {object} counts          How the fan-out went.
 * @param {number} counts.asked    Blasts a request was actually sent for.
 * @param {number} counts.answered Requests that came back without throwing.
 *
 * @return {'no-blasts'|'unreadable'|'answered'} What the block should say.
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast
 */
export function trafficOutcome({ asked, answered }) {
	// Order matters. `asked === 0` has to win, because answered is then also 0
	// and "every request failed" would be a claim about requests never sent.
	if (!asked) {
		return 'no-blasts'
	}

	if (!answered) {
		return 'unreadable'
	}

	return 'answered'
}

/**
 * Read every blast's site traffic, a few blasts at a time, in list order.
 *
 * The first answer that says no portal is connected stops the reads that
 * have not started yet: every blast would answer the same.
 *
 * @param {Array<object>} blasts      The listed blasts.
 * @param {Function}      read        Async `(id) => performance answer` for one blast.
 * @param {number}        concurrency How many reads may be in flight at once.
 *
 * @return {Promise<{rows: Array<object>, connected: boolean|null, asked: number, answered: number}>}
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast
 */
export async function readTrafficRows(blasts, read, concurrency) {
	const queue = blasts
		.map((blast) => ({ id: blast.id || blast.uuid || blast.slug, name: blast.name }))
		.filter((entry) => Boolean(entry.id))
	const found = new Map()
	let connected = null
	let asked = 0
	let answered = 0
	let stopped = false

	const worker = async () => {
		for (;;) {
			const entry = stopped ? undefined : queue.shift()
			if (entry === undefined) {
				return
			}
			asked += 1
			let data
			try {
				data = await read(entry.id)
			} catch {
				continue
			}
			answered += 1
			if (!data?.connected) {
				connected = false
				stopped = true
				return
			}
			connected = true
			if ((data.site?.sessions || 0) > 0) {
				found.set(entry.id, {
					id: entry.id,
					name: entry.name || entry.id,
					campaign: data.campaign || '',
					opened: data.email?.opened || 0,
					clicked: data.email?.clicked || 0,
					sessions: data.site?.sessions || 0,
					dealCount: data.deals?.dealCount || 0,
				})
			}
		}
	}

	const order = queue.map((entry) => entry.id)
	await Promise.all(Array.from({ length: Math.min(concurrency, queue.length) }, worker))

	return {
		rows: order.map((id) => found.get(id)).filter(Boolean),
		connected,
		asked,
		answered,
	}
}
