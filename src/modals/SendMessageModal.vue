<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - SendMessageModal is the outbound WhatsApp/SMS composer
  - (outbound-messaging-provider-wiring). It only ever REFLECTS the preflight
  - facts passed in as a prop (available channels, WhatsApp session-window
  - state, consent state, approved templates) — it never decides consent /
  - budget / template gating itself. The local "can I send" check below is a
  - soft UX gate to avoid an obviously-doomed submit and to surface the
  - opt-in action; POST /api/messaging/send always goes to the server and its
  - returned status (consent-missing / budget-exceeded / template-required /
  - template-invalid / no-provider / failed / sent) is what's actually shown,
  - since preflight facts can be stale by send time.
  -
  - @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
  -->
<template>
	<CnDialog
		width="form"
		:name="t('pipelinq', 'Send message')"
		:eyebrow="eyebrow"
		:subtitle="subtitle"
		data-testid="send-message-dialog"
		@closing="$emit('close')">
		<div class="send-message">
			<fieldset class="send-message__channel">
				<legend class="send-message__label">
					{{ t('pipelinq', 'Channel') }}
				</legend>
				<div
					role="radiogroup"
					class="send-message__switch"
					:aria-label="t('pipelinq', 'Channel')">
					<button
						v-for="option in channelChoices"
						:key="option.value"
						type="button"
						role="radio"
						class="send-message__switch-option"
						:class="{ 'send-message__switch-option--on': channel === option.value }"
						:aria-checked="channel === option.value ? 'true' : 'false'"
						:disabled="!option.available"
						@click="channel = option.value">
						<component :is="option.icon" :size="16" />
						{{ option.label }}
					</button>
				</div>
				<p
					v-if="channelOptions.length === 0"
					class="send-message__hint"
					data-testid="send-message-no-channel">
					{{
						t(
							'pipelinq',
							'No SMS or WhatsApp provider is active. An administrator sets one up under Connections.',
						)
					}}
				</p>
				<p
					v-if="channel && consentOk"
					role="status"
					class="send-message__consent-state send-message__consent-state--ok"
					data-testid="send-message-consent-state">
					<CheckCircleOutline :size="16" />
					{{ consentText }}
				</p>
			</fieldset>

			<section
				v-if="channel && !consentOk"
				class="send-message__consent"
				data-testid="send-message-consent">
				<p
					role="status"
					class="send-message__consent-state"
					:class="
						effectiveConsentState === 'opted-out'
							? 'send-message__consent-state--error'
							: 'send-message__consent-state--warn'
					"
					data-testid="send-message-consent-state">
					<AlertCircleOutline :size="16" />
					{{ consentText }}
				</p>
				<div class="send-message__pair">
					<NcSelect
						v-model="consentLegalBasis"
						:options="legalBasisOptions"
						:inputLabel="t('pipelinq', 'Legal basis (optional)')"
						label="label"
						:reduce="(o) => o.value" />
					<NcTextField
						v-model="consentEvidence"
						:label="t('pipelinq', 'Evidence')"
						:placeholder="
							t(
								'pipelinq',
								'e.g. verbal confirmation during call on {date}',
								{ date: today },
							)
						" />
				</div>
				<NcButton
					class="send-message__tonal"
					:disabled="!consentEvidence || recordingConsent"
					@click="recordConsent">
					<template #icon>
						<ShieldCheckOutline :size="16" />
					</template>
					{{
						recordingConsent
							? t('pipelinq', 'Recording…')
							: t('pipelinq', 'Record consent')
					}}
				</NcButton>
			</section>

			<template v-if="channel">
				<p v-if="whatsappNeedsTemplate" class="send-message__hint">
					{{
						t(
							'pipelinq',
							'The 24-hour WhatsApp session window is closed — an approved template is required to start a new business-initiated message.',
						)
					}}
				</p>
				<template v-if="showTemplates">
					<NcSelect
						v-model="templateId"
						:options="templateOptions"
						:inputLabel="
							whatsappNeedsTemplate
								? t('pipelinq', 'Template')
								: t('pipelinq', 'Template (optional)')
						"
						label="label"
						:reduce="(o) => o.value" />
					<p
						v-if="selectedTemplate"
						class="send-message__template-preview">
						{{ selectedTemplate.body }}
					</p>
					<div v-if="templateParams.length" class="send-message__pair">
						<NcTextField
							v-for="(param, index) in templateParams"
							:key="index"
							v-model="templateParams[index]"
							:label="t('pipelinq', 'Parameter {n}', { n: index + 1 })" />
					</div>
				</template>
				<template v-if="!whatsappNeedsTemplate">
					<SavedReplyPicker
						:key="channel"
						:channel="channel"
						:language="language"
						:values="placeholderValues"
						@pick="body = $event" />
					<NcTextArea
						v-model="body"
						:label="
							showTemplates
								? t('pipelinq', 'Message (optional)')
								: t('pipelinq', 'Message')
						"
						:placeholder="t('pipelinq', 'Type your message…')" />
				</template>
			</template>

			<p class="send-message__note" data-testid="send-message-note">
				<InformationOutline :size="16" />
				{{ t('pipelinq', 'The message is saved as a contact moment.') }}
			</p>

			<p
				v-if="sendResult && sendResult.status !== 'sent'"
				class="send-message__result"
				role="alert">
				{{ resultMessage }}
			</p>
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canSend" @click="send">
				<template #icon>
					<Send class="send-message__send-icon" :size="16" />
				</template>
				{{ sending ? t('pipelinq', 'Sending…') : t('pipelinq', 'Send') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import MessageTextOutline from 'vue-material-design-icons/MessageTextOutline.vue'
import Send from 'vue-material-design-icons/Send.vue'
import ShieldCheckOutline from 'vue-material-design-icons/ShieldCheckOutline.vue'
import Whatsapp from 'vue-material-design-icons/Whatsapp.vue'
import SavedReplyPicker from '../components/SavedReplyPicker.vue'

export default {
	name: 'SendMessageModal',
	components: {
		AlertCircleOutline,
		CheckCircleOutline,
		InformationOutline,
		NcButton,
		CnDialog,
		Send,
		ShieldCheckOutline,
		NcSelect,
		NcTextArea,
		NcTextField,
		SavedReplyPicker,
	},

	props: {
		contactId: {
			type: String,
			required: true,
		},

		clientId: {
			type: String,
			default: '',
		},

		/** The client the message is about, named in the dialog's eyebrow. */
		clientName: {
			type: String,
			default: '',
		},

		/** Who receives the message, named in the dialog's subtitle. */
		recipientName: {
			type: String,
			default: '',
		},

		/** The number the message goes to, after the recipient's name. */
		recipientPhone: {
			type: String,
			default: '',
		},

		/** Open on this channel, e.g. `whatsapp` after Send again asked for a template. */
		initialChannel: {
			type: String,
			default: '',
		},

		/** Placeholder values for a saved reply, e.g. `{'contact.name': 'Jan'}`. */
		placeholderValues: {
			type: Object,
			default: () => ({}),
		},

		/** The contact's correspondence language; its saved replies sort first. */
		language: {
			type: String,
			default: '',
		},

		preflight: {
			type: Object,
			default: () => ({
				channels: { sms: false, whatsapp: false },
				whatsappSessionOpen: false,
				consent: { sms: 'unknown', whatsapp: 'unknown' },
				templates: [],
			}),
		},
	},

	emits: ['sent', 'close'],
	data() {
		return {
			channel: this.initialChannel || this.onlyChannel(),
			body: '',
			templateId: null,
			templateParams: [],
			sending: false,
			sendResult: null,
			consentEvidence: '',
			consentLegalBasis: 'consent',
			recordingConsent: false,
			localConsent: null,
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		today() {
			return new Date().toLocaleDateString()
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		channelOptions() {
			const options = []
			if (this.preflight.channels && this.preflight.channels.sms) {
				options.push({ value: 'sms', label: t('pipelinq', 'SMS') })
			}
			if (this.preflight.channels && this.preflight.channels.whatsapp) {
				options.push({ value: 'whatsapp', label: t('pipelinq', 'WhatsApp') })
			}
			return options
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		channelLabel() {
			return this.channel === 'sms'
				? t('pipelinq', 'SMS')
				: t('pipelinq', 'WhatsApp')
		},

		/**
		 * Both channels as switch options, each enabled when a provider for
		 * it is active.
		 *
		 * @return {Array<object>} value, label, icon and available per channel.
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		channelChoices() {
			const channels = this.preflight.channels || {}
			return [
				{ value: 'sms', label: t('pipelinq', 'SMS'), icon: MessageTextOutline, available: !!channels.sms },
				{ value: 'whatsapp', label: t('pipelinq', 'WhatsApp'), icon: Whatsapp, available: !!channels.whatsapp },
			]
		},

		/**
		 * The client the message is about, as the eyebrow names it.
		 *
		 * @return {string} "Message · {client}", or empty without a name.
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		eyebrow() {
			const name = this.clientName || this.recipientName
			return name ? t('pipelinq', 'Message · {name}', { name }) : ''
		},

		/**
		 * The recipient and the number the message goes to.
		 *
		 * @return {string} "To {name}, {phone}", or the parts there are.
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		subtitle() {
			const parts = [this.recipientName, this.recipientPhone].filter(Boolean)
			return parts.length ? t('pipelinq', 'To {recipient}', { recipient: parts.join(', ') }) : ''
		},

		/**
		 * The consent state of the chosen channel, in words.
		 *
		 * @return {string} "Consent for {channel}: {state}".
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		consentText() {
			return t('pipelinq', 'Consent for {channel}: {state}', {
				channel: this.channelLabel,
				state: this.consentLabel,
			})
		},

		/**
		 * Whether the approved WhatsApp templates are offered: on WhatsApp,
		 * when there are templates or one is required.
		 *
		 * @return {boolean} True to show the template select.
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		showTemplates() {
			return this.channel === 'whatsapp'
				&& (this.whatsappNeedsTemplate || this.templateOptions.length > 0)
		},

		whatsappNeedsTemplate() {
			return this.channel === 'whatsapp' && !this.preflight.whatsappSessionOpen
		},

		templateOptions() {
			return (this.preflight.templates || []).map((tpl) => ({
				value: tpl.id,
				label: `${tpl.externalId} (${tpl.language})`,
			}))
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		selectedTemplate() {
			return (
				(this.preflight.templates || []).find(
					(tpl) => tpl.id === this.templateId,
				) || null
			)
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		effectiveConsentState() {
			if (this.localConsent && this.localConsent.channel === this.channel) {
				return this.localConsent.state
			}
			return (
				(this.preflight.consent && this.preflight.consent[this.channel])
				|| 'unknown'
			)
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		consentLabel() {
			const labels = {
				'opted-in': t('pipelinq', 'opted in'),
				'opted-out': t('pipelinq', 'opted out'),
				unknown: t('pipelinq', 'unknown'),
			}
			return labels[this.effectiveConsentState] || labels.unknown
		},

		/**
		 * Whether the currently selected channel's consent state is
		 * acceptable for a business-initiated send: a WhatsApp template send
		 * needs an explicit opt-in; SMS (and an in-window WhatsApp reply)
		 * only needs the contact to not have opted out. This mirrors the
		 * server's own gating (REQ-OM-005) purely for UX — the server
		 * decides authoritatively on send regardless of this value.
		 *
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		consentOk() {
			if (!this.channel) {
				return true
			}
			if (this.whatsappNeedsTemplate) {
				return this.effectiveConsentState === 'opted-in'
			}
			return this.effectiveConsentState !== 'opted-out'
		},

		legalBasisOptions() {
			return [
				{ value: 'consent', label: t('pipelinq', 'Consent') },
				{ value: 'contract', label: t('pipelinq', 'Contract') },
				{
					value: 'legitimate-interest',
					label: t('pipelinq', 'Legitimate interest'),
				},
			]
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		canSend() {
			if (this.sending || !this.channel || !this.consentOk) {
				return false
			}
			if (this.whatsappNeedsTemplate || this.templateId) {
				return !!this.templateId
			}
			return this.body.trim() !== ''
		},

		/**
		 * Whether the send goes out as the chosen template.
		 *
		 * @return {boolean} True when a template is required or picked.
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		useTemplate() {
			return this.channel === 'whatsapp'
				&& (this.whatsappNeedsTemplate || !!this.templateId)
		},

		resultMessage() {
			if (!this.sendResult) {
				return ''
			}
			const messages = {
				'consent-missing': t(
					'pipelinq',
					'The server refused to send: consent is missing for this channel.',
				),

				'budget-exceeded': t(
					'pipelinq',
					'The server refused to send: the messaging budget for this period is exceeded.',
				),

				'template-required': t(
					'pipelinq',
					'The server refused to send: the WhatsApp session window has expired and a template is required.',
				),

				'template-invalid': t(
					'pipelinq',
					'The server refused to send: the selected template is invalid ({reason}).',
					{ reason: this.sendResult.reason || t('pipelinq', 'unknown') },
				),

				'no-provider': t(
					'pipelinq',
					'The server refused to send: no active provider is configured for this channel.',
				),

				failed: t(
					'pipelinq',
					'The message could not be delivered by the provider.',
				),
			}
			return (
				messages[this.sendResult.status]
				|| t('pipelinq', 'The server could not process this send request.')
			)
		},
	},

	watch: {
		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		channel() {
			this.sendResult = null
			this.body = ''
			this.templateId = null
			this.templateParams = []
		},

		selectedTemplate(tpl) {
			if (!tpl) {
				this.templateParams = []
				return
			}
			const matches = (tpl.body || '').match(/\{\{\d+\}\}/g) || []
			const count = matches.length
			this.templateParams = new Array(count).fill('')
		},
	},

	methods: {
		/**
		 * The channel to open on when only one has an active provider.
		 *
		 * @return {string|null} That channel, or null.
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		onlyChannel() {
			const channels = (this.preflight && this.preflight.channels) || {}
			const available = ['sms', 'whatsapp'].filter((key) => channels[key])
			return available.length === 1 ? available[0] : null
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		async recordConsent() {
			if (!this.consentEvidence) {
				return
			}
			this.recordingConsent = true
			try {
				const { data } = await axios.post(
					generateUrl('/apps/pipelinq/api/messaging/consent'),
					{
						contactId: this.contactId,
						channel: this.channel,
						action: 'opt-in',
						source: 'admin-override',
						evidence: this.consentEvidence,
						legalBasis: this.consentLegalBasis,
					},
				)
				this.localConsent = {
					channel: this.channel,
					state: data.state || 'opted-in',
				}
				showSuccess(t('pipelinq', 'Consent recorded.'))
			} catch {
				showError(t('pipelinq', 'Failed to record consent.'))
			} finally {
				this.recordingConsent = false
			}
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		async send() {
			if (!this.canSend) {
				return
			}
			this.sending = true
			this.sendResult = null
			try {
				const { data } = await axios.post(
					generateUrl('/apps/pipelinq/api/messaging/send'),
					{
						contactId: this.contactId,
						channel: this.channel,
						body: this.useTemplate ? '' : this.body,
						templateId: this.useTemplate ? this.templateId : null,
						parameters: this.useTemplate ? this.templateParams : [],
						clientId: this.clientId,
					},
				)
				this.sendResult = data
				if (data.status === 'sent') {
					showSuccess(t('pipelinq', 'Message sent.'))
					this.$emit('sent', data)
					this.$emit('close')
				}
			} catch (e) {
				this.sendResult = (e.response && e.response.data) || {
					status: 'failed',
				}
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped>
.send-message {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.send-message__channel {
	margin: 0;
	padding: 0;
	border: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.send-message__label {
	padding: 0 0 6px;
	font-size: 14px;
	font-weight: 600;
}

/* The channel as a two-option switch: a grey track, the chosen option raised. */
.send-message__switch {
	align-self: flex-start;
	display: flex;
	gap: 2px;
	padding: 3px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-dark);
}

.send-message__switch-option {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	height: 36px;
	min-height: 36px;
	margin: 0;
	padding: 0 14px;
	border: 0;
	border-radius: 6px;
	background: transparent;
	color: var(--color-main-text);
	font-size: 14px;
	font-weight: 600;
	cursor: pointer;
}

.send-message__switch-option--on {
	background: var(--color-main-background);
	box-shadow: 0 1px 2px rgba(27, 28, 29, 0.12);
}

.send-message__switch-option:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.send-message__consent-state {
	margin: 0;
	display: inline-flex;
	align-self: flex-start;
	align-items: center;
	gap: 8px;
	padding: 6px 12px;
	border-radius: var(--border-radius-large, 8px);
	font-size: 14px;
	font-weight: 600;
}

.send-message__consent-state--ok {
	background: var(--color-success-light, #e7f3ea);
	color: var(--color-success-text);
}

.send-message__consent-state--warn {
	background: var(--color-warning-light, #fff4de);
	color: var(--color-warning-text);
}

.send-message__consent-state--error {
	background: var(--color-error-light, #fcedec);
	color: var(--color-error-text);
}

/* Consent still missing: the fields that record it, in a dashed box. */
.send-message__consent {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 14px 16px;
	border: 1px dashed var(--color-border-maxcontrast);
	border-radius: 10px;
	background: var(--color-background-hover);
}

.send-message__pair {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	gap: 14px;
	align-items: end;
}

.send-message__tonal {
	align-self: flex-start;
	--button-size: 34px;
	min-height: 34px;
	border: 0;
	border-radius: 17px;
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 13px;
}

.send-message__hint {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.send-message__template-preview {
	margin: 0;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-size: 13px;
	white-space: pre-wrap;
}

.send-message__note {
	margin: 0;
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 12px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.send-message__result {
	color: var(--color-error);
	margin: 0;
}
</style>
