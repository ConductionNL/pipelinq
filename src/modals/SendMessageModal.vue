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
	<NcModal :name="t('pipelinq', 'Send message')" @close="$emit('close')">
		<div class="send-message">
			<NcSelect
				v-model="channel"
				:options="channelOptions"
				:inputLabel="t('pipelinq', 'Channel')"
				label="label"
				:reduce="(o) => o.value" />

			<template v-if="channel">
				<div
					class="send-message__consent"
					:class="
						consentOk
							? 'send-message__consent--ok'
							: 'send-message__consent--warn'
					">
					<p>
						{{
							t('pipelinq', 'Consent for {channel}: {state}', {
								channel: channelLabel,
								state: consentLabel,
							})
						}}
					</p>
					<template v-if="!consentOk">
						<NcTextField
							v-model="consentEvidence"
							:label="t('pipelinq', 'Evidence (required)')"
							:placeholder="
								t(
									'pipelinq',
									'e.g. verbal confirmation during call on {date}',
									{ date: today },
								)
							" />
						<NcSelect
							v-model="consentLegalBasis"
							:options="legalBasisOptions"
							:inputLabel="t('pipelinq', 'Legal basis')"
							label="label"
							:reduce="(o) => o.value" />
						<NcButton
							variant="secondary"
							:disabled="!consentEvidence || recordingConsent"
							@click="recordConsent">
							{{
								recordingConsent
									? t('pipelinq', 'Recording…')
									: t('pipelinq', 'Record opt-in')
							}}
						</NcButton>
					</template>
				</div>

				<template v-if="whatsappNeedsTemplate">
					<p class="send-message__hint">
						{{
							t(
								'pipelinq',
								'The 24-hour WhatsApp session window is closed — an approved template is required to start a new business-initiated message.',
							)
						}}
					</p>
					<NcSelect
						v-model="templateId"
						:options="templateOptions"
						:inputLabel="t('pipelinq', 'Template')"
						label="label"
						:reduce="(o) => o.value" />
					<p
						v-if="selectedTemplate"
						class="send-message__template-preview">
						{{ selectedTemplate.body }}
					</p>
					<NcTextField
						v-for="(param, index) in templateParams"
						:key="index"
						v-model="templateParams[index]"
						:label="t('pipelinq', 'Parameter {n}', { n: index + 1 })" />
				</template>
				<template v-else>
					<NcTextArea
						v-model="body"
						:label="t('pipelinq', 'Message')"
						:placeholder="t('pipelinq', 'Type your message…')" />
				</template>
			</template>

			<p
				v-if="sendResult && sendResult.status !== 'sent'"
				class="send-message__result"
				role="alert">
				{{ resultMessage }}
			</p>

			<div class="send-message__actions">
				<NcButton @click="$emit('close')">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canSend" @click="send">
					{{ sending ? t('pipelinq', 'Sending…') : t('pipelinq', 'Send') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcModal, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'

export default {
	name: 'SendMessageModal',
	components: {
		NcButton,
		NcModal,
		NcSelect,
		NcTextArea,
		NcTextField,
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
			channel: null,
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
			if (this.whatsappNeedsTemplate) {
				return !!this.templateId
			}
			return this.body.trim() !== ''
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
						body: this.whatsappNeedsTemplate ? '' : this.body,
						templateId: this.whatsappNeedsTemplate
							? this.templateId
							: null,
						parameters: this.whatsappNeedsTemplate
							? this.templateParams
							: [],
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
	padding: 20px;
	max-width: 560px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.send-message__consent {
	padding: 8px 12px;
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.send-message__consent p {
	margin: 0 0 8px;
}

.send-message__consent--warn {
	box-shadow: inset 3px 0 0 0 var(--color-warning);
}

.send-message__consent--ok {
	box-shadow: inset 3px 0 0 0 var(--color-success);
}

.send-message__hint {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.send-message__template-preview {
	margin: 0;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-size: 13px;
	white-space: pre-wrap;
}

.send-message__result {
	color: var(--color-error);
	margin: 0;
}

.send-message__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-top: 8px;
}
</style>
