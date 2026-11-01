/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Keeps a contact moment form as a private draft (contact-moments-keep-draft):
 * the client page's quick log and the ticket's contact dialog share it.
 *
 * The host provides `form` (data) and `draftContext` (computed:
 * `{ author, clientId, requestId }`, the values it filled in for the agent).
 * It may override `draftPickContext` (which drafts to offer), `draftDefaults`
 * (preset values that are not a draft on their own), `draftableForm()` (the
 * fields as the ticket names them) and `applyDraftForm(kept)` (put a restored
 * draft back). It calls `loadDraft()` once its form is prefilled, and
 * `saveContactMoment(data)` to save.
 *
 * @spec openspec/changes/contact-log-dialog-keeps-draft/tasks.md#task-1
 */

import { generateUrl } from '@nextcloud/router'
import {
	buildDraftTicket,
	CONTACT_MOMENT_TYPE,
	DRAFT_TYPE,
	DraftAutosaver,
	draftChangedAt,
	draftFormFrom,
	isDraftFormEmpty,
	isSessionEnded,
	pickDraft,
	refreshRequestToken,
	refusalText,
} from '../services/contactMomentDraft.js'
import { useObjectStore } from '../store/modules/object.js'

/**
 * An error that carries the HTTP status of a failed write.
 *
 * @param {object|null} error The store's error object.
 * @return {Error} The error.
 */
function draftError(error) {
	const failure = new Error(error?.message || '')
	failure.status = error?.status
	return failure
}

/**
 * The headers of a write OpenRegister accepts from this page.
 *
 * @return {object}
 */
function writeHeaders() {
	return {
		'Content-Type': 'application/json',
		requesttoken: window.OC?.requestToken || '',
		'OCS-APIREQUEST': 'true',
	}
}

export default {
	data() {
		return {
			// The draft ticket kept on the server for this author and place.
			draftId: null,
			offeredDraft: null,
			// Set when a save or an autosave was refused because the session
			// ended; the next save fetches a fresh request token first.
			sessionEnded: false,
			// Off until the restore question is answered, so a draft that is
			// still being offered is never overwritten by the opening form.
			draftWatching: false,
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
		 */
		author() {
			return window.OC?.getCurrentUser?.()?.uid || null
		},

		/**
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.3
		 */
		offeredDraftTime() {
			const changed = draftChangedAt(this.offeredDraft)
			return changed ? new Date(changed).toLocaleString() : ''
		},

		/**
		 * Which drafts to offer: by default the place the form was opened.
		 *
		 * @return {object}
		 * @spec openspec/changes/contact-log-dialog-keeps-draft/tasks.md#task-4
		 */
		draftPickContext() {
			return this.draftContext
		},

		/**
		 * Preset values that are not a draft on their own.
		 *
		 * @return {object}
		 * @spec openspec/changes/contact-log-dialog-keeps-draft/tasks.md#task-4
		 */
		draftDefaults() {
			return {}
		},
	},

	watch: {
		form: {
			deep: true,
			/**
			 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
			 */
			handler() {
				this.scheduleDraft()
			},
		},
	},

	created() {
		this.autosaver = new DraftAutosaver({
			write: (payload, options) => this.writeDraft(payload, options),
			remove: (options) => this.removeDraft(options),
			onError: (error) => {
				if (isSessionEnded(error)) {
					this.sessionEnded = true
				}
			},
		})
		this.onVisibilityChange = () => {
			if (document.visibilityState === 'hidden') {
				this.autosaver.flush({ keepalive: true })
			}
		}
		document.addEventListener('visibilitychange', this.onVisibilityChange)
	},

	/**
	 * Keep what was typed when the form closes without saving.
	 *
	 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
	 */
	beforeUnmount() {
		document.removeEventListener('visibilitychange', this.onVisibilityChange)
		this.autosaver.flush()
	},

	methods: {
		/**
		 * The form's fields as the ticket names them.
		 *
		 * @return {object}
		 */
		draftableForm() {
			return this.form
		},

		/**
		 * Put a restored draft's fields back in the form.
		 *
		 * @param {object} kept The fields the draft holds.
		 */
		applyDraftForm(kept) {
			for (const key of Object.keys(this.form)) {
				if (kept[key] !== undefined) {
					this.form[key] = kept[key]
				}
			}
		},

		/**
		 * Write the form's state after the quiet time, or remove the draft
		 * when the form holds nothing the agent typed.
		 *
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
		 */
		scheduleDraft() {
			if (!this.draftWatching || !this.author || !this.autosaver) {
				return
			}
			const form = this.draftableForm()
			this.autosaver.schedule(
				isDraftFormEmpty(form, { ...this.draftContext, defaults: this.draftDefaults })
					? null
					: buildDraftTicket(form),
			)
		},

		/**
		 * Save the contact moment: finish the draft when there is one, else
		 * create it. A failure keeps the form and resumes the draft.
		 *
		 * @param {object} data The ticket fields.
		 * @return {Promise<object>} The saved ticket.
		 * @throws {Error} The failure, with `sessionEnded` set for an ended session.
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.3
		 */
		async saveContactMoment(data) {
			// Nothing may write the draft back while the contact moment is saved,
			// and a write already on its way must land first: it may be the one
			// that creates the draft this save is about to finish.
			this.autosaver.stop()
			await this.autosaver.settle()

			if (this.sessionEnded) {
				await refreshRequestToken(
					(...args) => fetch(...args),
					generateUrl('/csrftoken'),
				)
			}

			try {
				const result = this.draftId
					? await this.finishDraft(this.draftId, data)
					: await this.createContactMoment(data)
				this.sessionEnded = false
				this.draftId = null
				return result
			} catch (error) {
				this.autosaver.resume()
				if (isSessionEnded(error)) {
					this.sessionEnded = true
					error.sessionEnded = true
				}
				throw error
			}
		},

		/**
		 * Save a contact moment that has no draft to finish.
		 *
		 * @param {object} data The ticket fields.
		 * @return {Promise<object>} The saved ticket.
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.3
		 */
		async createContactMoment(data) {
			const store = useObjectStore()
			const result = await store.saveObject(DRAFT_TYPE, data)
			if (!result) {
				throw draftError(store.getError(DRAFT_TYPE))
			}
			return result
		},

		/**
		 * Make the draft the saved contact moment: same object, same id.
		 *
		 * OpenRegister's owner action lays the form over the stored draft,
		 * checks it against the whole ticket schema and moves it out of
		 * draft. A draft that is gone (removed after seven days, or discarded
		 * in another tab) is saved as a new contact moment instead.
		 *
		 * @param {string} id The draft's id.
		 * @param {object} data The ticket fields.
		 * @return {Promise<object>} The saved ticket.
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.3
		 */
		async finishDraft(id, data) {
			const response = await fetch(
				generateUrl('/apps/openregister/api/objects/pipelinq/' + DRAFT_TYPE + '/' + id + '/activate'),
				{
					method: 'POST',
					credentials: 'same-origin',
					headers: writeHeaders(),
					body: JSON.stringify({ object: data }),
				},
			)
			if (response.ok) {
				return await response.json()
			}
			if (response.status === 404 || response.status === 409) {
				this.draftId = null
				return this.createContactMoment(data)
			}
			const body = await response.json().catch(() => null)
			throw draftError({
				status: response.status,
				message: refusalText(body),
			})
		},

		/**
		 * Find this author's draft for this place, and offer it.
		 *
		 * `_drafts=true` asks OpenRegister for drafts alone. It answers the
		 * agent's own, and an administrator's list can hold other people's,
		 * so the owner is asked for and checked again in `pickDraft`.
		 *
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.3
		 */
		async loadDraft() {
			if (!this.author) {
				return
			}
			let drafts
			try {
				drafts = await useObjectStore().fetchCollection(DRAFT_TYPE, {
					ticketType: CONTACT_MOMENT_TYPE,
					_drafts: 'true',
					'@self[owner]': this.author,
					_limit: 50,
				})
			} catch {
				drafts = []
			}
			const offer = pickDraft(drafts || [], {
				...this.draftPickContext,
				now: new Date(),
			})
			if (offer) {
				this.draftId = offer.id
				this.offeredDraft = offer
				return
			}
			this.draftWatching = true
		},

		/**
		 * Put the draft's fields back in the form.
		 *
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.3
		 */
		restoreDraft() {
			this.applyDraftForm(draftFormFrom(this.offeredDraft))
			this.offeredDraft = null
			this.draftWatching = true
		},

		/**
		 * Throw the draft away and keep the form as it opened.
		 *
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.3
		 */
		async discardDraft() {
			this.offeredDraft = null
			await this.dropDraft()
			this.autosaver.resume()
			this.draftWatching = true
		},

		/**
		 * Remove the server draft, if there is one.
		 *
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.3
		 */
		async dropDraft() {
			this.autosaver.stop()
			if (this.draftId) {
				const id = this.draftId
				this.draftId = null
				await useObjectStore().deleteObject(DRAFT_TYPE, id)
			}
		},

		/**
		 * Write the draft: create it once, then overwrite it.
		 *
		 * @param {object} payload The draft.
		 * @param {{keepalive: boolean}} options Keepalive for a closing tab.
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
		 */
		async writeDraft(payload, { keepalive }) {
			if (keepalive) {
				await this.sendDraftKeepalive(this.draftId ? 'PUT' : 'POST', this.draftId, payload)
				return
			}
			const store = useObjectStore()
			const data = this.draftId ? { ...payload, id: this.draftId } : payload
			const saved = await store.saveObject(DRAFT_TYPE, data)
			if (!saved) {
				throw draftError(store.getError(DRAFT_TYPE))
			}
			this.draftId = saved.id || this.draftId
		},

		/**
		 * Remove the draft because the form was emptied.
		 *
		 * @param {{keepalive: boolean}} options Keepalive for a closing tab.
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
		 */
		async removeDraft({ keepalive }) {
			if (!this.draftId) {
				return
			}
			const id = this.draftId
			this.draftId = null
			if (keepalive) {
				await this.sendDraftKeepalive('DELETE', id, null)
				return
			}
			await useObjectStore().deleteObject(DRAFT_TYPE, id)
		},

		/**
		 * The write a closing tab still delivers.
		 *
		 * @param {string} method POST, PUT or DELETE.
		 * @param {string|null} id The draft's id, when it exists.
		 * @param {object|null} payload The draft.
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
		 */
		async sendDraftKeepalive(method, id, payload) {
			const url = generateUrl('/apps/openregister/api/objects/pipelinq/' + DRAFT_TYPE + (id ? '/' + id : ''))
			const response = await fetch(url, {
				method,
				keepalive: true,
				credentials: 'same-origin',
				headers: writeHeaders(),
				body: payload ? JSON.stringify(payload) : undefined,
			})
			if (!response.ok) {
				throw draftError({ status: response.status })
			}
			// Hidden is also a switch to another tab, after which this page
			// keeps typing: remember the id so the next write overwrites.
			if (method === 'POST') {
				try {
					const created = await response.json()
					this.draftId = created?.id || this.draftId
				} catch {
					// The tab closed before the answer was read.
				}
			}
		},
	},
}
