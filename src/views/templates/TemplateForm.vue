<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<div class="template-form">
		<header class="template-form__header">
			<h2>
				{{
					isEditing
						? t('pipelinq', 'Edit template')
						: t('pipelinq', 'New template')
				}}
			</h2>
		</header>

		<NcLoadingIcon v-if="loading" :size="32" />

		<div v-else class="template-form__body">
			<p v-if="loadError" class="template-form__error" role="alert">
				{{ loadError }}
			</p>

			<section class="template-form__panel">
				<label class="template-form__label" for="template-form-name">
					{{ t('pipelinq', 'Template name') }} *
				</label>
				<input
					id="template-form-name"
					v-model="model.name"
					type="text"
					class="template-form__input"
					:placeholder="t('pipelinq', 'Renewal Reminder')" />

				<NcSelect
					v-model="channelOption"
					:options="channelOptions"
					:inputLabel="t('pipelinq', 'Channel') + ' *'"
					label="label"
					:clearable="false"
					class="template-form__channel" />

				<template v-if="model.channel === 'email'">
					<label class="template-form__label" for="template-form-subject">
						{{ t('pipelinq', 'Subject') }}
					</label>
					<input
						id="template-form-subject"
						v-model="model.subject"
						type="text"
						class="template-form__input"
						:class="{
							'template-form__input--error': fieldErrors.subject,
						}" />
					<p
						v-if="fieldErrors.subject"
						class="template-form__field-error"
						role="alert">
						{{ fieldErrors.subject }}
					</p>

					<label
						class="template-form__label"
						for="template-form-sender-name">
						{{ t('pipelinq', 'Sender name') }}
					</label>
					<input
						id="template-form-sender-name"
						v-model="model.senderName"
						type="text"
						class="template-form__input" />

					<label
						class="template-form__label"
						for="template-form-sender-email">
						{{ t('pipelinq', 'Sender email') }}
					</label>
					<input
						id="template-form-sender-email"
						v-model="model.senderEmail"
						type="email"
						class="template-form__input" />

					<label class="template-form__label" for="template-form-reply-to">
						{{ t('pipelinq', 'Reply-to email') }}
					</label>
					<input
						id="template-form-reply-to"
						v-model="model.replyTo"
						type="email"
						class="template-form__input" />
				</template>

				<label class="template-form__label" for="template-form-body-html">
					{{
						model.channel === 'email'
							? t('pipelinq', 'HTML body')
							: t('pipelinq', 'Message body')
					}}
				</label>
				<textarea
					id="template-form-body-html"
					v-model="model.bodyHtml"
					class="template-form__textarea"
					:class="{ 'template-form__input--error': fieldErrors.bodyHtml }"
					rows="8"
					:placeholder="
						model.channel === 'email'
							? t(
									'pipelinq',
									'Use {{unsubscribe_link}} and a physical address so the compliance check passes.',
								)
							: ''
					" />
				<p
					v-if="fieldErrors.bodyHtml"
					class="template-form__field-error"
					role="alert">
					{{ fieldErrors.bodyHtml }}
				</p>

				<template v-if="model.channel === 'email'">
					<label
						class="template-form__label"
						for="template-form-body-text">
						{{ t('pipelinq', 'Plain-text body') }}
					</label>
					<textarea
						id="template-form-body-text"
						v-model="model.bodyText"
						class="template-form__textarea"
						rows="4" />

					<label class="template-form__label" for="template-form-footer">
						{{ t('pipelinq', 'Footer override') }}
					</label>
					<textarea
						id="template-form-footer"
						v-model="model.footerOverride"
						class="template-form__textarea"
						rows="2" />
				</template>
			</section>

			<p v-if="saveError" class="template-form__error" role="alert">
				{{ saveError }}
			</p>

			<footer class="template-form__actions">
				<NcButton
					variant="tertiary"
					@click="$router.push({ name: 'Templates' })">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canSave" @click="save">
					{{
						isEditing
							? t('pipelinq', 'Save changes')
							: t('pipelinq', 'Create template')
					}}
				</NcButton>
			</footer>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcSelect } from '@nextcloud/vue'

export default {
	name: 'TemplateForm',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
	},

	data() {
		return {
			loading: false,
			loadError: '',
			saveError: '',
			saving: false,
			// Field-level errors parsed out of the single compliance error
			// string ComplianceService.validateTemplate() returns -- @see
			// parseFieldErrors. The service does not (yet) return a
			// structured per-field map, so this is a best-effort mapping
			// from the known error phrasings to the field that caused them.
			fieldErrors: {},
			model: {
				name: '',
				channel: 'email',
				subject: '',
				bodyHtml: '',
				bodyText: '',
				senderName: '',
				senderEmail: '',
				replyTo: '',
				footerOverride: '',
			},
		}
	},

	computed: {
		/**
		 * @return {string|null} The CampaignTemplate id being edited, or null for New.
		 */
		templateId() {
			return this.$route?.params?.id || null
		},

		/**
		 * @return {boolean} Whether this instance is editing an existing template.
		 */
		isEditing() {
			return this.templateId !== null
		},

		/**
		 * @return {Array<{value:string,label:string}>} The two channel options.
		 */
		channelOptions() {
			return [
				{ value: 'email', label: this.t('pipelinq', 'Email') },
				{ value: 'sms', label: this.t('pipelinq', 'SMS') },
			]
		},

		/**
		 * Selected channel as an NcSelect option object.
		 *
		 * @return {object}
		 */
		channelOption: {
			get() {
				return (
					this.channelOptions.find((o) => o.value === this.model.channel)
					|| this.channelOptions[0]
				)
			},

			set(option) {
				this.model.channel = option?.value || 'email'
			},
		},

		/**
		 * @return {boolean} Whether the form has enough to attempt a save.
		 */
		canSave() {
			return (
				this.model.name.trim() !== ''
				&& this.model.bodyHtml.trim() !== ''
				&& !this.saving
			)
		},
	},

	async created() {
		if (this.isEditing) {
			await this.loadTemplate()
		}
	},

	methods: {
		/**
		 * Load the CampaignTemplate being edited.
		 */
		async loadTemplate() {
			this.loading = true
			this.loadError = ''
			try {
				const url = generateUrl(
					`/apps/pipelinq/api/templates/${this.templateId}`,
				)
				const { data } = await axios.get(url)
				this.model = { ...this.model, ...data }
			} catch (e) {
				this.loadError =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not load this template.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Best-effort mapping from ComplianceService's single compliance
		 * error string to the field that should show it, so a missing
		 * `{{unsubscribe_link}}` or address block reads as a field error
		 * rather than only a page-level banner.
		 *
		 * @param {string} message The error string from the API.
		 * @return {object} A `{ bodyHtml?: string, subject?: string }` map.
		 *
		 * @spec openspec/changes/marketing-segments-ui-repair/specs/marketing-api/spec.md#scenario-template-create-validates-compliance
		 */
		parseFieldErrors(message) {
			if (!message) {
				return {}
			}
			const lower = message.toLowerCase()
			if (lower.includes('unsubscribe') || lower.includes('address')) {
				return { bodyHtml: message }
			}
			if (lower.includes('subject')) {
				return { subject: message }
			}
			return {}
		},

		/**
		 * Create or update the CampaignTemplate.
		 *
		 * @spec openspec/changes/marketing-segments-ui-repair/specs/marketing-api/spec.md#scenario-template-create-validates-compliance
		 */
		async save() {
			if (!this.canSave) {
				return
			}
			this.saving = true
			this.saveError = ''
			this.fieldErrors = {}
			const payload = {
				name: this.model.name,
				channel: this.model.channel,
				subject: this.model.subject,
				bodyHtml: this.model.bodyHtml,
				bodyText: this.model.bodyText,
				senderName: this.model.senderName,
				senderEmail: this.model.senderEmail,
				footerOverride: this.model.footerOverride,
			}
			try {
				if (this.isEditing) {
					const url = generateUrl(
						`/apps/pipelinq/api/templates/${this.templateId}`,
					)
					await axios.patch(url, payload)
				} else {
					const url = generateUrl('/apps/pipelinq/api/templates')
					await axios.post(url, payload)
				}
				this.$router.push({ name: 'Templates' })
			} catch (e) {
				const message =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not save this template.')
				this.saveError = message
				this.fieldErrors = this.parseFieldErrors(message)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.template-form {
	max-width: 900px;
	margin: 0 auto;
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.template-form__header h2 {
	margin: 0;
}

.template-form__body {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.template-form__panel {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.template-form__label {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.template-form__input,
.template-form__textarea {
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-darker);
	color: var(--color-main-text);
	font-family: inherit;
}

.template-form__input--error {
	border-color: var(--color-error);
}

.template-form__channel {
	max-width: 320px;
}

.template-form__field-error,
.template-form__error {
	color: var(--color-error);
	font-weight: 600;
	margin: 0;
}

.template-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
