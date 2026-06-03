<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<NcDialog
		:name="t('pipelinq', 'Doelbinding Verzoeken')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="brp-doelbinding">
			<NcSelect
				v-model="verzoekreden"
				:input-label="t('pipelinq', 'Verzoekreden')"
				:options="verzoekredenOptions"
				:placeholder="t('pipelinq', 'Choose a reason')"
				:clearable="false"
				required />

			<NcSelect
				v-model="doelbinding"
				:input-label="t('pipelinq', 'Doelbinding / legal basis')"
				:options="doelbindingOptions"
				:placeholder="t('pipelinq', 'Choose a legal basis')"
				:clearable="false"
				required />

			<NcTextArea
				v-model="toelichting"
				:label="t('pipelinq', 'Additional explanation')"
				:placeholder="t('pipelinq', 'Optional — recommended at least 20 characters')"
				resize="vertical" />

			<p v-if="errorMessage" class="brp-doelbinding__error">
				{{ errorMessage }}
			</p>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="!canSubmit || submitting"
				@click="submit">
				<template v-if="submitting" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('pipelinq', 'Ophalen') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcSelect, NcTextArea, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'BrpDoelbindingModal',
	components: {
		NcDialog,
		NcButton,
		NcSelect,
		NcTextArea,
		NcLoadingIcon,
	},
	props: {
		/** The validated 9-digit BSN to look up. Never placed in a URL. */
		bsn: {
			type: String,
			required: true,
		},
		/** The Pipelinq contact UUID to link the lookup to (optional). */
		contactId: {
			type: String,
			default: '',
		},
		/** A related Pipelinq request UUID (optional). */
		verzoekId: {
			type: String,
			default: '',
		},
	},
	emits: ['close', 'resolved'],
	data() {
		return {
			verzoekreden: null,
			doelbinding: null,
			toelichting: '',
			submitting: false,
			errorMessage: '',
			verzoekredenOptions: [
				t('pipelinq', 'Handling GDPR access request (art. 15)'),
				t('pipelinq', 'Handling GDPR erasure request (art. 17)'),
				t('pipelinq', 'VOG screening'),
				t('pipelinq', 'Regular request handling'),
				t('pipelinq', 'Other'),
			],
			doelbindingOptions: [
				t('pipelinq', 'Public task — Wet BRP art. 3.3'),
				t('pipelinq', 'GDPR art. 6(1)(e)'),
				t('pipelinq', 'Legitimate interest'),
				t('pipelinq', 'Other'),
			],
		}
	},
	computed: {
		/**
		 * Both required fields must be set before the lookup can proceed.
		 *
		 * @return {boolean} Whether the form is submittable.
		 */
		canSubmit() {
			return !!this.verzoekreden && !!this.doelbinding
		},
	},
	methods: {
		/**
		 * POST the doelbinding-gated lookup to the backend. The BSN travels in
		 * the request body only — never in the URL (REQ-BSN-009).
		 */
		async submit() {
			if (!this.canSubmit) {
				return
			}
			this.submitting = true
			this.errorMessage = ''

			const controller = new AbortController()
			const timeout = setTimeout(() => controller.abort(), 5000)

			try {
				const response = await fetch(generateUrl('/apps/pipelinq/api/brp/lookup'), {
					method: 'POST',
					signal: controller.signal,
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify({
						bsn: this.bsn,
						verzoekreden: this.verzoekreden,
						doelbinding: this.doelbinding,
						grondslag: this.doelbinding,
						toelichting: this.toelichting,
						contactId: this.contactId,
						verzoekId: this.verzoekId,
					}),
				})
				const data = await response.json().catch(() => ({}))

				if (!response.ok) {
					this.errorMessage = this.mapError(response.status, data.error)
					return
				}

				this.$emit('resolved', {
					...data,
					verzoekreden: this.verzoekreden,
				})
				this.$emit('close')
			} catch (e) {
				if (e.name === 'AbortError') {
					this.errorMessage = t('pipelinq', 'Request timed out — please try again')
				} else {
					this.errorMessage = t('pipelinq', 'BRP is currently unavailable — please try again in a few minutes')
				}
			} finally {
				clearTimeout(timeout)
				this.submitting = false
			}
		},
		/**
		 * Map an HTTP status to a friendly, BSN-free message.
		 *
		 * @param {number} status The HTTP status code.
		 * @param {string} backendError The backend error message, if any.
		 * @return {string} The localized message.
		 */
		mapError(status, backendError) {
			if (status === 403) {
				return t('pipelinq', 'You are not authorized for this lookup')
			}
			if (status === 404) {
				return t('pipelinq', 'BSN not found in BRP — check the input')
			}
			if (status === 400) {
				return backendError || t('pipelinq', 'Doelbinding is required')
			}
			return t('pipelinq', 'BRP is currently unavailable — please try again in a few minutes')
		},
	},
}
</script>

<style scoped>
.brp-doelbinding {
	display: flex;
	flex-direction: column;
	gap: 14px;
	padding: 8px 0;
}

.brp-doelbinding__error {
	color: var(--color-error);
	font-size: 13px;
	margin: 0;
}
</style>
