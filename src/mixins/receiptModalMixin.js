// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { emit } from '@nextcloud/event-bus'
import { generateUrl } from '@nextcloud/router'
import { loadActiveReceiptTemplates } from '../services/receiptTemplates.js'

/**
 * The headers of a request to the receipt endpoints.
 *
 * @return {object} The headers.
 */
function receiptHeaders() {
	return {
		requesttoken: OC.requestToken,
		'OCS-APIREQUEST': 'true',
	}
}

/**
 * What the till's receipt modals (Email Receipt, Print Receipt) share: the
 * template choice, the preview, the request to the transaction's receipt
 * endpoint and the status line.
 *
 * The host defines `showPreview(receipt)` for what it reads from the
 * preview beyond its text, and runs its own submit through `postReceipt`.
 *
 * @spec openspec/specs/pos/spec.md
 */
export default {
	props: {
		transactionId: {
			type: String,
			required: true,
		},

		templates: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			loadedTemplates: [],
			selectedTemplate: null,
			previewText: '',
			previewLoading: false,
			statusMessage: '',
			statusType: '',
		}
	},

	computed: {
		/**
		 * Template options for the NcSelect dropdown.
		 *
		 * @return {Array} The options.
		 */
		templateOptions() {
			const templates = this.templates.length > 0 ? this.templates : this.loadedTemplates
			return templates.map((tpl) => ({
				id: tpl.id,
				label: tpl.name || tpl.id,
			}))
		},

		/**
		 * What the shared modal body shows.
		 *
		 * @return {object} The body's props.
		 */
		receiptBody() {
			return {
				selectedTemplate: this.selectedTemplate,
				templateOptions: this.templateOptions,
				previewText: this.previewText,
				previewLoading: this.previewLoading,
				statusMessage: this.statusMessage,
				statusType: this.statusType,
			}
		},
	},

	async mounted() {
		if (this.templates.length === 0) {
			this.loadedTemplates = await loadActiveReceiptTemplates()
		}
		await this.loadPreview()
	},

	methods: {
		/**
		 * A template was chosen: keep it and preview the receipt with it.
		 *
		 * @param {object} template The chosen option.
		 * @return {Promise<void>}
		 */
		selectTemplate(template) {
			this.selectedTemplate = template
			return this.loadPreview()
		},

		/**
		 * Load the rendered receipt preview.
		 *
		 * @return {Promise<void>}
		 */
		async loadPreview() {
			this.previewLoading = true
			this.statusMessage = ''
			try {
				const receipt = await this.fetchPreview()
				if (receipt) {
					this.previewText = receipt.text || ''
					this.showPreview(receipt)
				}
			} finally {
				this.previewLoading = false
			}
		},

		/**
		 * The receipt rendered with the chosen template.
		 *
		 * @return {Promise<(object|null)>} The receipt, or null when the server gave none.
		 */
		async fetchPreview() {
			const params = this.selectedTemplate
				? `?template=${encodeURIComponent(this.selectedTemplate.id)}`
				: ''
			const response = await fetch(
				this.receiptUrl(`preview${params}`),
				{
					headers: receiptHeaders(),
				},
			)
			const data = await response.json().catch(() => ({}))
			return response.ok && data.receipt ? data.receipt : null
		},

		/**
		 * Post a receipt action (`email`, `print`) for the transaction, with the chosen template.
		 *
		 * @param {string} action The action.
		 * @return {Promise<{ok: boolean, data: object}>} Whether the server took it, and its answer.
		 */
		async postReceipt(action) {
			const body = this.templateBody()
			const response = await fetch(
				this.receiptUrl(action),
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						...receiptHeaders(),
					},
					body: JSON.stringify(body),
				},
			)
			const data = await response.json().catch(() => ({}))
			return { ok: response.ok, data }
		},

		/**
		 * @return {object} The request body: the chosen template, when there is one.
		 */
		templateBody() {
			const body = {}
			if (this.selectedTemplate) {
				body.template = this.selectedTemplate.id
			}
			return body
		},

		/**
		 * @param {string} path The path under the transaction's receipt endpoint.
		 * @return {string} The URL.
		 */
		receiptUrl(path) {
			return generateUrl(
				`/apps/pipelinq/api/pos-transactions/${this.transactionId}/receipt/${path}`,
			)
		},

		/**
		 * @param {string} message What went wrong.
		 * @return {void}
		 */
		showReceiptError(message) {
			this.statusType = 'error'
			this.statusMessage = message
		},

		/**
		 * @param {string} message What was done.
		 * @return {void}
		 */
		showReceiptSuccess(message) {
			this.statusType = 'success'
			this.statusMessage = message
		},

		/**
		 * Close the modal after a receipt action.
		 *
		 * @return {void}
		 */
		closeAfterReceipt() {
			// Opened from a declared header action, the modal closes and the page re-reads the receipt log itself.
			emit('cn:page:refresh', {})
			this.$emit('close')
		},
	},
}
