<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - SendQuoteModal sends a quote from the lead's lines. Opened by the "Send
  - quote" header action on LeadDetail, which is shown only while filinq is
  - there; the lead comes from the route, because a header action's modal
  - props are not resolved against the page's object. The user picks the
  - quote template and a valid-until date and sees who the quote goes to
  - before sending. filinq renders and files the PDF; pipelinq mails it and
  - logs the contact moment.
  -
  - @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
  -->
<template>
	<CnDialog
		:name="t('pipelinq', 'Send quote')"
		width="wizard"
		data-testid="quote-dialog"
		@closing="$emit('close')">
		<div class="quote-modal" data-testid="quote-modal">
			<p v-if="loading">
				{{ t('pipelinq', 'Loading templates') }}
			</p>
			<p v-else-if="!available" class="quote-modal__error" role="alert">
				{{ t('pipelinq', 'Quotes are made by filinq, and filinq is not available.') }}
			</p>
			<template v-else-if="!result">
				<p v-if="templates.length === 0">
					{{ t('pipelinq', 'There are no quote templates yet. An administrator adds them in filinq with the namespace pipelinq.') }}
				</p>
				<div v-else class="quote-modal__fields">
					<div class="quote-modal__field">
						<label for="quote-modal-template" class="quote-modal__label">{{ t('pipelinq', 'Template') }}</label>
						<NcSelect
							v-model="templateId"
							inputId="quote-modal-template"
							:options="templateOptions"
							:inputLabel="t('pipelinq', 'Template')"
							labelOutside
							:reduce="(o) => o.value"
							label="label"
							data-testid="quote-template" />
					</div>
					<div class="quote-modal__field">
						<NcDateTimePickerNative
							id="quote-modal-valid-until"
							:modelValue="validUntil"
							:label="t('pipelinq', 'Valid until')"
							type="date"
							data-testid="quote-valid-until"
							@update:modelValue="validUntil = $event" />
					</div>
				</div>
				<p
v-if="recipient"
					class="quote-modal__recipient"
					role="status"
					data-testid="quote-recipient">
					{{ t('pipelinq', 'The quote goes to {name} ({email}).', { name: recipient.name || recipient.email, email: recipient.email }) }}
				</p>
				<p
v-else
					class="quote-modal__error"
					role="alert"
					data-testid="quote-no-recipient">
					{{ t('pipelinq', 'This lead has no contact or client with an email address. Add one, then send the quote.') }}
				</p>
			</template>
			<div v-else class="quote-modal__result" data-testid="quote-result" role="status">
				<p>
					{{ t('pipelinq', 'Quote {number} was sent to {email}.', { number: result.number, email: result.recipient.email }) }}
				</p>
				<p v-if="result.path" class="quote-modal__hint">
					{{ t('pipelinq', 'A copy is filed at {path}.', { path: result.path }) }}
				</p>
				<p v-if="!result.contactMomentId" class="quote-modal__error" role="alert">
					{{ t('pipelinq', 'The quote was sent, but it could not be logged as a contact moment.') }}
				</p>
			</div>
			<p v-if="error" class="quote-modal__error" role="alert">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ result ? t('pipelinq', 'Close') : t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!result"
				variant="primary"
				:disabled="!canSend"
				data-testid="quote-send"
				@click="send">
				<template #icon>
					<FileSendOutline :size="20" />
				</template>
				{{ sending ? t('pipelinq', 'Sending quote') : t('pipelinq', 'Send quote') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { NcButton, NcDateTimePickerNative, NcSelect } from '@nextcloud/vue'
import FileSendOutline from 'vue-material-design-icons/FileSendOutline.vue'
import { fetchLetterTemplates } from '../services/letters.js'
import { defaultValidUntil, fetchQuoteRecipient, isoDate, sendQuote } from '../services/quotes.js'

export default {
	name: 'SendQuoteModal',
	components: {
		CnDialog,
		FileSendOutline,
		NcButton,
		NcDateTimePickerNative,
		NcSelect,
	},

	props: {
		/** The lead; read from the route on a lead page when not given. */
		leadId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			loading: true,
			available: false,
			templates: [],
			templateId: null,
			validUntil: defaultValidUntil(),
			recipient: null,
			sending: false,
			result: null,
			error: '',
		}
	},

	computed: {
		/**
		 * @return {string} The lead: the prop, else the route's id on a lead page.
		 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
		 */
		resolvedLeadId() {
			if (this.leadId) {
				return this.leadId
			}
			const path = this.$route?.path || ''
			return path.startsWith('/leads/') ? (this.$route?.params?.id || '') : ''
		},

		/**
		 * @return {Array<{value: string, label: string}>} The templates as select options.
		 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
		 */
		templateOptions() {
			return this.templates.map((tpl) => ({ value: tpl.id, label: tpl.name }))
		},

		/**
		 * @return {boolean} Whether a template, a date and a recipient are there.
		 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
		 */
		canSend() {
			return this.available && !this.sending && !!this.templateId && !!this.validUntil && !!this.recipient
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Load the templates and the recipient.
		 *
		 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const [templates, recipient] = await Promise.all([
					fetchLetterTemplates(),
					fetchQuoteRecipient(this.resolvedLeadId),
				])
				this.available = templates.available
				this.templates = templates.templates
				this.recipient = recipient
				if (this.templates.length === 1) {
					this.templateId = this.templates[0].id
				}
			} catch (e) {
				this.error = e?.response?.data?.message || t('pipelinq', 'The quote could not be prepared.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Send the quote and show where it went.
		 *
		 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
		 */
		async send() {
			if (!this.canSend) {
				return
			}
			this.sending = true
			this.error = ''
			try {
				this.result = await sendQuote(this.resolvedLeadId, {
					templateId: this.templateId,
					validUntil: isoDate(this.validUntil),
				})
			} catch (e) {
				this.error = e?.response?.data?.message || t('pipelinq', 'The quote could not be sent.')
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.quote-modal {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-height: 120px;
}

.quote-modal__error {
	color: var(--color-error-text, var(--color-error));
}

.quote-modal__fields {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.quote-modal__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.quote-modal__label {
	font-size: 14px;
	font-weight: 600;
}

.quote-modal__hint {
	color: var(--color-text-maxcontrast);
}
</style>
