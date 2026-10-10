/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact moment draft (contact-moments-keep-draft).
 *
 * While an agent types in the quick log, the form is kept on the server as a
 * `contactMomentDraft` object: two seconds after the last change, and once
 * more with `fetch keepalive` when the tab goes hidden, which a closing tab
 * still sends. The schema is private by default (OpenRegister `scope:
 * private`), so only the agent who started it, and an administrator, can read
 * it. No ticket list, queue or report counts it, because it is not a ticket.
 *
 * Everything here is free of Vue so the timing and the session handling can
 * be tested with fake timers.
 *
 * @spec openspec/changes/contact-moments-keep-draft/specs/contact-moment-drafts/spec.md#requirement-a-manual-contact-moment-is-kept-as-a-private-draft-req-cmd2-001
 */

/** The object type the drafts live in. */
export const DRAFT_TYPE = 'contactMomentDraft'

/** Quiet time after the last change before the draft is written. */
export const DRAFT_DELAY_MS = 2000

/** A draft older than this is never offered again, and is removed. */
export const DRAFT_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000

/** The quick log fields a draft keeps. */
export const DRAFT_FIELDS = [
	'title',
	'channel',
	'outcome',
	'client',
	'contact',
	'parentTicket',
	'description',
	'duration',
	'notes',
]

/**
 * Whether the form holds nothing the agent typed or picked.
 *
 * The client and the request the quick log was opened on are filled in for
 * the agent, so on their own they are not worth a draft.
 *
 * @param {object} form The quick log form.
 * @param {{clientId: (string|null), requestId: (string|null)}} context Where the quick log was opened.
 * @return {boolean} True when there is nothing to keep.
 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
 */
export function isDraftFormEmpty(form, context = {}) {
	if (!form) {
		return true
	}
	for (const key of ['title', 'description', 'duration', 'notes']) {
		if (typeof form[key] === 'string' && form[key].trim() !== '') {
			return false
		}
	}
	for (const key of ['channel', 'outcome', 'contact']) {
		if (form[key]) {
			return false
		}
	}
	if (form.client && form.client !== (context.clientId || null)) {
		return false
	}
	if (form.parentTicket && form.parentTicket !== (context.requestId || null)) {
		return false
	}
	return true
}

/**
 * The draft object to write for this form.
 *
 * Empty references are left out rather than written as null: the schema
 * types them as strings, and a null would be refused.
 *
 * @param {object} form The quick log form.
 * @param {{author: string, clientId: (string|null), requestId: (string|null), now: Date}} context Who and where.
 * @return {object} The draft payload.
 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.1
 */
export function buildDraftPayload(form, context) {
	const kept = {}
	for (const key of DRAFT_FIELDS) {
		const value = form?.[key]
		if (value !== null && value !== undefined && value !== '') {
			kept[key] = value
		}
	}
	const payload = {
		author: context.author,
		form: kept,
		updatedAt: (context.now || new Date()).toISOString(),
	}
	if (context.clientId) {
		payload.client = context.clientId
	}
	if (context.requestId) {
		payload.request = context.requestId
	}
	return payload
}

/**
 * Split the agent's drafts into the one to offer and the ones to remove.
 *
 * A draft belongs to one author, client and request. Of the matching ones
 * the newest within seven days is offered; older matches and expired drafts
 * are returned for removal, so they do not wait on the archival sweep.
 *
 * @param {Array<object>} drafts The drafts OpenRegister returned.
 * @param {{author: string, clientId: (string|null), requestId: (string|null), now: Date}} context Who and where.
 * @return {{offer: (object|null), stale: Array<object>}} The draft to offer and the ones to remove.
 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.3
 */
export function pickDraft(drafts, context) {
	const now = (context.now || new Date()).getTime()
	const stale = []
	const matching = []
	for (const draft of drafts || []) {
		if (!draft || draft.author !== context.author) {
			continue
		}
		const changed = Date.parse(draft.updatedAt || '')
		if (Number.isNaN(changed) || now - changed > DRAFT_MAX_AGE_MS) {
			stale.push(draft)
			continue
		}
		if (
			(draft.client || null) === (context.clientId || null)
			&& (draft.request || null) === (context.requestId || null)
		) {
			matching.push(draft)
		}
	}
	matching.sort((a, b) => Date.parse(b.updatedAt) - Date.parse(a.updatedAt))
	return { offer: matching[0] || null, stale: stale.concat(matching.slice(1)) }
}

/**
 * Whether a failed request failed because the session has ended.
 *
 * Nextcloud answers 401 when nobody is logged in, and 412 when the page's
 * request token no longer matches the session, which is what a tab sees after
 * its agent logged in again elsewhere.
 *
 * @param {object|null} error The store's error object (it carries `status`), or a Response.
 * @return {boolean} True for an ended session.
 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.4
 */
export function isSessionEnded(error) {
	const status = Number(error?.status)
	return status === 401 || status === 412
}

/**
 * Fetch a fresh request token after the agent logged in again in another tab.
 *
 * The tab's own token belongs to the ended session, so without this the
 * retry would be refused with 412 even though the agent is logged in.
 *
 * @param {Function} fetchImpl The fetch to use.
 * @param {string} url The csrftoken endpoint.
 * @return {Promise<boolean>} True when a new token was installed.
 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.4
 */
export async function refreshRequestToken(fetchImpl, url) {
	try {
		const response = await fetchImpl(url, { credentials: 'same-origin' })
		if (!response.ok) {
			return false
		}
		const body = await response.json()
		if (!body?.token) {
			return false
		}
		if (typeof OC !== 'undefined') {
			OC.requestToken = body.token
		}
		if (typeof document !== 'undefined' && document.head) {
			document.head.dataset.requesttoken = body.token
		}
		return true
	} catch {
		return false
	}
}

/**
 * Debounced writer for one quick log's draft.
 *
 * `schedule(payload)` restarts the quiet timer; a null payload means the form
 * is empty and the draft is removed instead. `flush()` writes what is pending
 * right away (used on hidden, with keepalive). Writes never overlap: a change
 * that arrives while one is in flight is written after it.
 *
 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
 */
export class DraftAutosaver {

	/**
	 * @param {object} options The options.
	 * @param {Function} options.write Called with (payload, {keepalive}); resolves when written.
	 * @param {Function} options.remove Called with ({keepalive}); resolves when removed.
	 * @param {number} [options.delayMs] Quiet time before writing.
	 * @param {Function} [options.onError] Called with the error of a failed write.
	 */
	constructor({ write, remove, delayMs = DRAFT_DELAY_MS, onError = () => {} }) {
		this.write = write
		this.remove = remove
		this.delayMs = delayMs
		this.onError = onError
		this.timer = null
		this.pending = undefined
		this.running = null
		this.stopped = false
	}

	/**
	 * Remember the form's latest state and restart the quiet timer.
	 *
	 * @param {object|null} payload The draft to write, or null to remove it.
	 * @return {void}
	 */
	schedule(payload) {
		if (this.stopped) {
			return
		}
		this.pending = payload
		this.clearTimer()
		this.timer = setTimeout(() => {
			this.timer = null
			this.flush()
		}, this.delayMs)
	}

	/**
	 * Write what is pending now.
	 *
	 * @param {{keepalive: boolean}} [options] Send with keepalive, for a closing tab.
	 * @return {Promise<void>} Resolves when the write is done.
	 */
	async flush({ keepalive = false } = {}) {
		this.clearTimer()
		if (this.pending === undefined || this.stopped) {
			return this.running || undefined
		}
		const payload = this.pending
		this.pending = undefined
		const previous = this.running
		const run = (async () => {
			if (previous && !keepalive) {
				await previous
			}
			try {
				if (payload === null) {
					await this.remove({ keepalive })
				} else {
					await this.write(payload, { keepalive })
				}
			} catch (error) {
				this.onError(error)
			}
		})()
		this.running = run
		await run
		if (this.running === run) {
			this.running = null
		}
	}

	/**
	 * Drop anything pending and write nothing more.
	 *
	 * @return {void}
	 */
	stop() {
		this.stopped = true
		this.pending = undefined
		this.clearTimer()
	}

	/**
	 * Resume after `stop()`, for a save that failed and keeps the form open.
	 *
	 * @return {void}
	 */
	resume() {
		this.stopped = false
	}

	/**
	 * @return {void}
	 */
	clearTimer() {
		if (this.timer !== null) {
			clearTimeout(this.timer)
			this.timer = null
		}
	}

}
