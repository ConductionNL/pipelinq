<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Request-detail in-body section (kind:'section') for the ADR-051 emit-side
  - "Convert to case" handoff (semantic-handoff-emit). Self-fetches
  - GET /api/handoff/request/{id}/availability on mount so the action renders
  - ONLY when an installed app implements https://openregister.app/ns#Case AND
  - the request is `in_progress` (canConvert) — hidden otherwise, never
  - disabled-with-tooltip, per the ADR-051 hidden-without-implementer rule.
  -
  - On a successful POST /api/handoff/request/{id}/convert-to-case the request
  - has already moved to `converted` server-side (core fields become read-only
  - server-side); this section swaps the button for a converted notice + the
  - caseReference. The target app is kind-addressed (procest today, possibly
  - zaakafhandelapp tomorrow) and unknown to the frontend, so no precise
  - cross-app route can be built — the reference is shown as a labeled,
  - copyable value rather than a guessed/broken link.
  -
  - @spec openspec/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
  -->
<template>
	<div v-if="loading" class="request-conversion-section">
		<NcLoadingIcon :size="24" />
	</div>
	<div v-else-if="hasContent" class="request-conversion-section">
		<NcButton
			v-if="showConvertButton"
			variant="primary"
			:disabled="busy"
			@click="convertToCase">
			{{ t('pipelinq', 'Convert to case') }}
		</NcButton>

		<template v-if="isConverted">
			<NcNoteCard type="success" class="request-conversion-section__notice">
				{{ t('pipelinq', 'This request has been converted to a case.') }}
			</NcNoteCard>
			<div v-if="caseReference" class="request-conversion-section__reference">
				<span class="request-conversion-section__reference-label">
					{{ t('pipelinq', 'Case reference') }}
				</span>
				<code class="request-conversion-section__reference-value">{{
					caseReference
				}}</code>
				<NcButton variant="tertiary" @click="copyReference">
					{{ t('pipelinq', 'Copy') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

const DEFAULT_AVAILABILITY = {
	available: false,
	status: '',
	canConvert: false,
	caseReference: '',
}

export default {
	name: 'RequestConversionSection',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},

	inject: {
		cnSectionContext: { default: null },
	},

	props: {
		/** The request id (token-resolved from @objectId by CnBodySections). */
		requestId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: false,
			busy: false,
			availability: { ...DEFAULT_AVAILABILITY },
		}
	},

	computed: {
		/** The resolved request id — prop wins, else the injected section context. */
		resolvedId() {
			if (this.requestId) {
				return this.requestId
			}
			const ctx = this.cnSectionContext
			const bag =
				ctx && typeof ctx === 'object' && 'value' in ctx ? ctx.value : ctx
			return (bag && bag.objectId) || ''
		},

		caseReference() {
			return this.availability.caseReference || ''
		},

		/** Converted whenever the server reports status converted or a case is already linked. */
		isConverted() {
			return this.availability.status === 'converted' || !!this.caseReference
		},

		/** Shown only when an ns#Case implementer exists AND the request is in_progress. */
		showConvertButton() {
			return this.availability.canConvert === true && !this.isConverted
		},

		/** Whether this section has anything to render at all (else it stays hidden). */
		hasContent() {
			return this.showConvertButton || this.isConverted
		},
	},

	watch: {
		resolvedId: {
			immediate: true,
			handler() {
				this.loadAvailability()
			},
		},
	},

	methods: {
		/**
		 * Fetch the current conversion availability for this request.
		 */
		async loadAvailability() {
			if (!this.resolvedId) {
				this.availability = { ...DEFAULT_AVAILABILITY }
				return
			}
			this.loading = true
			try {
				const { data } = await axios.get(
					generateUrl(
						'/apps/pipelinq/api/handoff/request/{id}/availability',
						{ id: this.resolvedId },
					),
				)
				this.availability = {
					available: !!data.available,
					status: data.status || '',
					canConvert: !!data.canConvert,
					caseReference: data.caseReference || '',
				}
			} catch (e) {
				this.availability = { ...DEFAULT_AVAILABILITY }
			} finally {
				this.loading = false
			}
		},

		/**
		 * Convert the request into a case via the semantic handoff endpoint.
		 */
		async convertToCase() {
			if (!this.resolvedId || this.busy) {
				return
			}
			this.busy = true
			try {
				const { data } = await axios.post(
					generateUrl(
						'/apps/pipelinq/api/handoff/request/{id}/convert-to-case',
						{ id: this.resolvedId },
					),
					{},
				)
				this.availability = {
					...this.availability,
					status: 'converted',
					canConvert: false,
					caseReference: data.caseReference || '',
				}
				showSuccess(t('pipelinq', 'Request converted to case.'))
				this.$emit('converted', { caseReference: data.caseReference || '' })
			} catch (err) {
				const body = (err && err.response && err.response.data) || {}
				if (
					body.status === 'invalid-status'
					|| body.status === 'not-available'
				) {
					showError(
						t(
							'pipelinq',
							'Conversion is no longer available for this request.',
						),
					)
					await this.loadAvailability()
				} else if (body.status === 'handoff-failed') {
					showError(
						t('pipelinq', 'Could not create the case: {reason}', {
							reason: body.reason || t('pipelinq', 'unknown error'),
						}),
					)
				} else {
					showError(
						t('pipelinq', 'Could not convert this request to a case.'),
					)
				}
			} finally {
				this.busy = false
			}
		},

		/**
		 * Copy the case reference UUID to the clipboard.
		 */
		async copyReference() {
			try {
				await navigator.clipboard.writeText(this.caseReference)
				showSuccess(t('pipelinq', 'Case reference copied.'))
			} catch (e) {
				showError(t('pipelinq', 'Could not copy the case reference.'))
			}
		},
	},
}
</script>

<style scoped>
.request-conversion-section {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.request-conversion-section__reference {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.request-conversion-section__reference-label {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.request-conversion-section__reference-value {
	padding: 2px 8px;
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-background-hover);
	font-size: 13px;
}
</style>
