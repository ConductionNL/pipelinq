/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The client → contact cascade shared by every form that links a lead, request
 * or contactmoment to a party.
 *
 * Two pickers, one dependency: the contact picker stays disabled until a client
 * is chosen and is then scoped to that client, and either picker can create the
 * object it cannot find instead of dead-ending on "no results".
 *
 * This is a mixin rather than a third copy. LeadForm and RequestForm carried
 * byte-identical versions of these eight methods, which is exactly the shape
 * that goes wrong quietly: a fix applied to one and not the other leaves the
 * other broken with every test still green.
 *
 * 🔴 THE CONSUMER MUST OWN `form.client` AND `form.contact`.
 * The mixin reads and writes both by name. A form whose fields are called
 * something else must map them, not rename the mixin's.
 *
 * The consumer supplies the TEMPLATE and registers the two dialogs itself.
 * The mixin deliberately does NOT register them: components reached through a
 * mixin are invisible to `vue/no-undef-components`, so the linter can no longer
 * tell a real missing registration from this one, and a reader of the template
 * has nothing to follow to find them.
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
 */

export default {
	data() {
		return {
			// Inline-create plumbing. `resolveCreate` is the promise resolver
			// CnResourceSelect is awaiting; it MUST be settled exactly once,
			// however the dialog ends, or the picker stays in its loading state
			// forever with no error to explain why.
			clientDialogOpen: false,
			contactDialogOpen: false,
			pendingName: '',
			resolveCreate: null,
		}
	},

	computed: {
		/**
		 * Scope for the contact picker. CnResourceSelect drops empty entries,
		 * so an unchosen client scopes to nothing rather than querying for
		 * contacts whose client is the empty string.
		 *
		 * @return {{client: (string|null)}} The filter object.
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
		 */
		contactFilters() {
			return { client: this.form.client }
		},
	},

	methods: {
		/**
		 * Selecting a different client invalidates the contact under it.
		 *
		 * @param {string} value The chosen client uuid, or '' when cleared.
		 * @return {void}
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
		 */
		onClientChange(value) {
			const next = value || null
			if (next !== this.form.client) {
				this.form.contact = null
			}
			this.form.client = next
		},

		/**
		 * CnResourceSelect create hook for the client picker. The `client`
		 * schema needs more than a name — `contactsUid` is server-minted — so
		 * the typed term opens the full create dialog instead of being saved
		 * directly.
		 *
		 * @return {Promise<object|null>} The created client, or null if cancelled.
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
		 */
		createClient() {
			return new Promise((resolve) => {
				this.resolveCreate = resolve
				this.clientDialogOpen = true
			})
		},

		/**
		 * Same hook for the contact picker, carrying the typed name and the
		 * selected client into the dialog.
		 *
		 * @param {string} term The name typed into the picker.
		 * @return {Promise<object|null>} The created contact, or null if cancelled.
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
		 */
		createContact(term) {
			this.pendingName = term || ''
			return new Promise((resolve) => {
				this.resolveCreate = resolve
				this.contactDialogOpen = true
			})
		},

		/**
		 * Settle the awaiting picker exactly once, however the dialog ended.
		 *
		 * @param {object|null} created The created object, or null when cancelled.
		 * @return {void}
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
		 */
		settleCreate(created) {
			const resolve = this.resolveCreate
			this.resolveCreate = null
			this.pendingName = ''
			if (resolve) resolve(created)
		},

		/**
		 * @param {string} id The created client's uuid (ClientCreateDialog emits an id).
		 * @return {void}
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
		 */
		onClientCreated(id) {
			this.clientDialogOpen = false
			this.form.contact = null
			this.settleCreate(id ? { id } : null)
		},

		/**
		 * @return {void}
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
		 */
		closeClientDialog() {
			this.clientDialogOpen = false
			this.settleCreate(null)
		},

		/**
		 * @param {object} contact The created contact object.
		 * @return {void}
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
		 */
		onContactCreated(contact) {
			this.contactDialogOpen = false
			this.settleCreate(contact || null)
		},

		/**
		 * @return {void}
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
		 */
		closeContactDialog() {
			this.contactDialogOpen = false
			this.settleCreate(null)
		},
	},
}
