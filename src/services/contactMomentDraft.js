/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact moment draft (contact-moments-keep-draft).
 *
 * While an agent types in the quick log, the form is kept on the server as
 * the contact moment itself: a `ticket` of type `interaction` in OpenRegister's
 * status `draft` (decisions 180 and 216). It is written two seconds after the
 * last change, and once more with `fetch keepalive` when the tab goes hidden,
 * which a closing tab still sends. Save makes that same object definitive
 * through OpenRegister's owner action, so the saved contact moment keeps the
 * draft's id.
 *
 * pipelinq carries no draft store and no cleanup of its own. OpenRegister
 * keeps a draft to its owner, keeps it out of every ticket list
 * (`draftsListed: false` on the schema) and removes it after seven days
 * without a change (`draftLifetimeDays: 7`).
 *
 * Everything here is free of Vue so the timing and the session handling can
 * be tested with fake timers.
 *
 * @spec openspec/changes/contact-moments-keep-draft/specs/contact-moment-drafts/spec.md#requirement-a-manual-contact-moment-is-kept-as-a-private-draft-req-cmd2-001
 */

/** The object type a draft lives in: the contact moment's own. */
export const DRAFT_TYPE = 'ticket'

/** The ticket type of a contact moment. */
export const CONTACT_MOMENT_TYPE = 'interaction'

/** Quiet time after the last change before the draft is written. */
export const DRAFT_DELAY_MS = 2000

/**
 * A draft older than this is never offered again. OpenRegister removes it:
 * the ticket schema sets `draftLifetimeDays` to the same seven days.
 */
export const DRAFT_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000

/** The quick log fields a draft keeps, each a property of the ticket schema. */
export const DRAFT_FIELDS = [
	'title',
	'channel',
	'direction',
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
 * A value equal to its preset in `context.defaults` (a channel the form opens
 * on, say) is not worth a draft either.
 *
 * @param {object} form The quick log form.
 * @param {{clientId: (string|null), requestId: (string|null), defaults: (object|undefined)}} context Where the quick log was opened, and its presets.
 * @return {boolean} True when there is nothing to keep.
 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
 * @spec openspec/changes/contact-log-dialog-keeps-draft/tasks.md#task-4
 */
export function isDraftFormEmpty(form, context = {}) {
	if (!form) {
		return true
	}
	const defaults = context.defaults || {}
	for (const key of ['title', 'description', 'duration', 'notes']) {
		if (typeof form[key] === 'string' && form[key].trim() !== '') {
			return false
		}
	}
	for (const key of ['channel', 'direction', 'outcome', 'contact']) {
		if (form[key] && form[key] !== defaults[key]) {
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
 * The ticket fields the form holds, empty ones left out.
 *
 * Empty references are left out rather than written as null: the schema
 * types them as strings, and a null would be refused.
 *
 * @param {object} form The quick log form.
 * @return {object} The filled fields.
 */
function filledFields(form) {
	const kept = {}
	for (const key of DRAFT_FIELDS) {
		const value = form?.[key]
		if (value !== null && value !== undefined && value !== '') {
			kept[key] = value
		}
	}
	return kept
}

/**
 * The draft to write for this form: a contact moment ticket in status draft.
 *
 * A draft may miss required fields (a subject, say), but every value it
 * holds must fit the ticket schema, so only schema properties are written.
 *
 * The assignee is left out until the contact moment is saved: nobody is
 * assigned a note that is still being typed.
 *
 * @param {object} form The quick log form.
 * @return {object} The draft ticket.
 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.1
 */
export function buildDraftTicket(form) {
	return {
		ticketType: CONTACT_MOMENT_TYPE,
		...filledFields(form),
		'@self': { status: 'draft' },
	}
}

/**
 * The contact moment as it is saved: the form's fields and the moment of saving.
 *
 * Used for the owner action that makes the draft definitive, and for a plain
 * create when there is no draft to finish. The empty `channelMetadata`
 * envelope the form used to send is left out: the owner action checks the
 * whole schema, and an empty JSON object reaches a PHP validator as an empty
 * list, which is not the object the schema asks for.
 *
 * @param {object} form The quick log form.
 * @param {{author: string, now: Date}} context Who saves, and when.
 * @return {object} The ticket fields.
 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.3
 */
export function buildContactMoment(form, context) {
	return {
		ticketType: CONTACT_MOMENT_TYPE,
		...filledFields(form),
		occurredAt: (context.now || new Date()).toISOString(),
		assignee: context.author,
	}
}

/**
 * When a draft ticket was last changed, as OpenRegister recorded it.
 *
 * @param {object} draft The draft ticket.
 * @return {string} The ISO moment, or an empty string.
 *
 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.3
 */
export function draftChangedAt(draft) {
	return draft?.['@self']?.updated || draft?.['@self']?.created || ''
}

/**
 * The quick log form a draft ticket restores.
 *
 * @param {object} draft The draft ticket.
 * @return {object} The form fields the draft holds.
 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.3
 */
export function draftFormFrom(draft) {
	return filledFields(draft)
}

/**
 * Pick the draft to offer from the agent's draft contact moments.
 *
 * A draft is offered where it was typed: on the same client and the same
 * request. Only the agent's own drafts count (an administrator's list can
 * hold other people's), only contact moments, and only one changed within
 * seven days. OpenRegister removes the older ones, so nothing is deleted here.
 * A context without `clientId` matches on the request alone: the ticket's
 * contact dialog lets the agent change the customer.
 *
 * @param {Array<object>} drafts The draft tickets OpenRegister returned.
 * @param {{author: string, clientId: (string|null|undefined), requestId: (string|null), now: Date}} context Who and where.
 * @return {object|null} The newest matching draft, or null.
 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.3
 * @spec openspec/changes/contact-log-dialog-keeps-draft/tasks.md#task-4
 */
export function pickDraft(drafts, context) {
	const now = (context.now || new Date()).getTime()
	const matching = []
	for (const draft of drafts || []) {
		const self = draft?.['@self'] || {}
		if (
			!draft
			|| self.status !== 'draft'
			|| self.owner !== context.author
			|| draft.ticketType !== CONTACT_MOMENT_TYPE
		) {
			continue
		}
		const changed = Date.parse(draftChangedAt(draft))
		if (Number.isNaN(changed) || now - changed > DRAFT_MAX_AGE_MS) {
			continue
		}
		if (
			(context.clientId === undefined || (draft.client || null) === (context.clientId || null))
			&& (draft.parentTicket || null) === (context.requestId || null)
		) {
			matching.push(draft)
		}
	}
	matching.sort(
		(a, b) => Date.parse(draftChangedAt(b)) - Date.parse(draftChangedAt(a)),
	)
	return matching[0] || null
}

/**
 * One sentence for a refused save: OpenRegister's message and what it found.
 *
 * @param {object|null} body The refusal body, `{ message, findings }`.
 * @return {string} The text to show, or an empty string.
 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.3
 */
export function refusalText(body) {
	const parts = []
	if (typeof body?.message === 'string' && body.message !== '') {
		parts.push(body.message)
	}
	for (const finding of body?.findings || []) {
		if (typeof finding?.message === 'string' && finding.message !== '') {
			parts.push(finding.message)
		}
	}
	return parts.join(' ')
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
 * @param {function(string, object): Promise<Response>} fetchImpl The fetch to use.
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
	 * @param {function(object, object): Promise<void>} options.write Called with (payload, {keepalive}); resolves when written.
	 * @param {function(object): Promise<void>} options.remove Called with ({keepalive}); resolves when removed.
	 * @param {number} [options.delayMs] Quiet time before writing.
	 * @param {function(Error): void} [options.onError] Called with the error of a failed write.
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
	 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
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
	 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
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
	 * Wait for a write that is already on its way.
	 *
	 * @return {Promise<void>} Resolves when nothing is in flight.
	 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.3
	 */
	async settle() {
		if (this.running) {
			await this.running
		}
	}

	/**
	 * Drop anything pending and write nothing more.
	 *
	 * @return {void}
	 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
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
	 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
	 */
	resume() {
		this.stopped = false
	}

	/**
	 * @return {void}
	 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
	 */
	clearTimer() {
		if (this.timer !== null) {
			clearTimeout(this.timer)
			this.timer = null
		}
	}
}
