<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Ticket-detail in-body section (kind:'section'): turn a question about a
  - resident's Woo dossier into a Woo request in dossiq
  - (questions-about-a-citizen-dossier REQ-QCD-008, hydra woo-citizen-journey
  - J4.6 and C5). Self-fetches GET /api/tickets/{id}/woo-request/availability
  - and renders the action only when dossiq answers and the ticket is an
  - unconverted question about a dossier. Hidden otherwise, never disabled.
  - After converting it shows the case, with a link when dossiq gave one.
  -->
<template>
	<div v-if="showButton || isConverted" class="woo-conversion-section">
		<NcButton
			v-if="showButton"
			variant="primary"
			:disabled="busy"
			@click="convert">
			{{ t('pipelinq', 'Convert to Woo request') }}
		</NcButton>
		<NcNoteCard v-if="isConverted" type="success">
			{{ t('pipelinq', 'This question is now a Woo request.') }}
			<a v-if="caseUrl" :href="caseUrl" class="woo-conversion-section__link">{{
				t('pipelinq', 'Open the Woo request')
			}}</a>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'WooConversionSection',
	components: {
		NcButton,
		NcNoteCard,
	},

	props: {
		/** The ticket id, token-resolved from `@objectId`. */
		objectId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			canConvert: false,
			status: '',
			caseReference: '',
			caseUrl: '',
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * @return {boolean} Whether the ticket is converted.
		 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
		 */
		isConverted() {
			return this.status === 'converted' && this.caseReference !== ''
		},

		/**
		 * @return {boolean} Whether to offer the conversion.
		 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
		 */
		showButton() {
			return this.canConvert && !this.isConverted
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/**
			 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
			 */
			handler() {
				this.loadAvailability()
			},
		},
	},

	methods: {
		t,

		/**
		 * Ask whether dossiq can take this ticket.
		 *
		 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
		 */
		async loadAvailability() {
			this.canConvert = false
			if (!this.objectId) {
				return
			}
			try {
				const { data } = await axios.get(
					generateUrl(
						'/apps/pipelinq/api/tickets/{id}/woo-request/availability',
						{ id: this.objectId },
					),
				)
				this.canConvert = data.canConvert === true
				this.status = data.status || ''
				this.caseReference = data.caseReference || ''
			} catch {
				this.canConvert = false
			}
		},

		/**
		 * Start the Woo request in dossiq and record it on the ticket.
		 *
		 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
		 */
		async convert() {
			if (this.busy) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.post(
					generateUrl('/apps/pipelinq/api/tickets/{id}/woo-request', {
						id: this.objectId,
					}),
					{},
				)
				this.status = 'converted'
				this.caseReference = data.caseReference || ''
				this.caseUrl = /^(\/|https?:\/\/)/.test(String(data.caseUrl || ''))
					? String(data.caseUrl)
					: ''
				this.canConvert = false
			} catch (err) {
				const body = (err && err.response && err.response.data) || {}
				this.error =
					body.status === 'intake-failed'
						? t(
								'pipelinq',
								'dossiq could not start the Woo request. Try again later.',
							)
						: t('pipelinq', 'This question can no longer be converted.')
				await this.loadAvailability()
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.woo-conversion-section {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.woo-conversion-section__link {
	margin-inline-start: 8px;
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
