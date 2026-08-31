<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - BrpContactPanel renders the BSN-input + "Ophalen uit BRP" button + Persoon detail
  - block inside the Contact detail view. The doelbinding modal is a separate file
  - (BrpDoelbindingModal — ADR-004 modal isolation rule).
  -
  - The raw BSN never leaves this component — it is sent once to /api/brp/lookup and
  - then discarded. The response is held in component state and rendered as Persoon
  - details, with adres hidden when `geheimhouding` is true.
  -
  - @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#5.1
  - @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#5.2
  - @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#5.4
  - @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#5.5
  - @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-001
  - @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-002
  - @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-006
  -->
<template>
	<CnDetailCard :title="t('pipelinq', 'BSN and BRP')">
		<div class="brp-panel">
			<div class="brp-panel__bsn-row">
				<NcTextField
					v-model="rawBsn"
					data-testid="brp-bsn-input"
					:label="t('pipelinq', 'BSN')"
					:placeholder="t('pipelinq', 'E.g. 123456782')"
					autocomplete="off"
					inputmode="numeric"
					maxlength="9"
					:helperText="bsnFeedback || ' '"
					:success="validation.isFormallyValid"
					:error="rawBsn.length > 0 && !validation.isFormallyValid" />
				<NcButton
					variant="primary"
					data-testid="brp-lookup-button"
					:disabled="!canLookup"
					@click="openDoelbinding">
					{{ t('pipelinq', 'Retrieve from BRP') }}
				</NcButton>
			</div>

			<div v-if="lookupState === 'loading'" class="brp-panel__status">
				<NcLoadingIcon />
				<span>{{ t('pipelinq', 'Retrieve from BRP…') }}</span>
			</div>
			<div
				v-else-if="lookupState === 'error'"
				class="brp-panel__status brp-panel__status--error">
				{{ errorMessage }}
			</div>

			<div v-if="persoon" class="brp-panel__persoon" data-testid="brp-persoon">
				<div class="brp-panel__persoon-header">
					<span
						v-if="persoon.indicationSecret === '1'"
						class="brp-panel__geheim-icon"
						:title="t('pipelinq', 'Confidentiality active')">
						🔒
					</span>
					<strong>{{ fullName }}</strong>
					<span
						v-if="cacheHit"
						class="brp-panel__cache-badge"
						:title="t('pipelinq', 'Served from cache')">
						⚡ {{ t('pipelinq', 'from cache') }}
					</span>
				</div>
				<dl class="brp-panel__persoon-fields">
					<dt>{{ t('pipelinq', 'Date of birth') }}</dt>
					<dd>{{ persoon.date_of_birth || '-' }}</dd>
					<dt>{{ t('pipelinq', 'Place of birth') }}</dt>
					<dd>{{ persoon.birth_place || '-' }}</dd>
					<dt>{{ t('pipelinq', 'Gender') }}</dt>
					<dd>{{ persoon.gender || '-' }}</dd>
				</dl>
				<div
					v-if="persoon.indicationSecret === '1' && !revealedAddress"
					class="brp-panel__secret">
					<span>[{{ t('pipelinq', 'SECRET') }}]</span>
					<NcButton variant="tertiary" @click="revealAddress">
						{{ t('pipelinq', 'Show address under accountability') }}
					</NcButton>
				</div>
				<div v-else-if="address" class="brp-panel__address">
					<div>
						{{ address.straat }} {{ address.huisnummer
						}}{{ address.huisletter }}
					</div>
					<div>{{ address.postcode }} {{ address.woonplaats }}</div>
					<div v-if="address.land && address.land !== 'Nederland'">
						{{ address.land }}
					</div>
				</div>
			</div>
		</div>

		<BrpDoelbindingModal
			v-if="showModal"
			@close="showModal = false"
			@submit="onLookup" />
	</CnDetailCard>
</template>

<script>
import {
	BSN_ERROR_CHECKSUM,
	BSN_ERROR_LENGTH,
	CnDetailCard,
	validateBsn,
} from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import BrpDoelbindingModal from '../modals/BrpDoelbindingModal.vue'

export default {
	name: 'BrpContactPanel',
	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
		CnDetailCard,
		BrpDoelbindingModal,
	},

	props: {
		contactId: {
			type: String,
			required: true,
		},

		initialBsn: {
			type: String,
			default: '',
		},
	},

	emits: ['contact-updated'],
	data() {
		return {
			rawBsn: this.initialBsn || '',
			showModal: false,
			lookupState: 'idle',
			persoon: null,
			cacheHit: false,
			errorMessage: '',
			revealedAddress: false,
			revealedVerblijfplaats: null,
		}
	},

	computed: {
		validation() {
			return validateBsn(this.rawBsn)
		},

		/**
		 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#5.1
		 */
		bsnFeedback() {
			if (!this.rawBsn) return ''

			// The shared validator returns a stable errorCode and NO message,
			// deliberately: the local copy this replaced returned hardcoded
			// Dutch strings that never reached t(), so a Dutch sentence was
			// shown to every user whatever their language.
			if (this.validation.errorCode === BSN_ERROR_LENGTH) {
				return this.t('pipelinq', 'A BSN is exactly 9 digits')
			}

			if (this.validation.errorCode === BSN_ERROR_CHECKSUM) {
				return this.t('pipelinq', 'This BSN does not pass the 11-check')
			}

			return this.t('pipelinq', 'BSN passes the 11-check')
		},

		/**
		 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#5.1
		 */
		canLookup() {
			return this.validation.isFormallyValid && this.lookupState !== 'loading'
		},

		fullName() {
			if (!this.persoon) return ''
			const parts = [
				this.persoon.given_names,
				this.persoon.name_prefix,
				this.persoon.surname,
			].filter(Boolean)
			return parts.join(' ')
		},

		/**
		 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#5.1
		 */
		address() {
			if (!this.persoon) return null
			if (this.persoon.indicationSecret === '1' && !this.revealedAddress)
				return null
			if (this.revealedVerblijfplaats) return this.revealedVerblijfplaats
			return this.persoon.residence || null
		},
	},

	methods: {
		openDoelbinding() {
			if (!this.canLookup) return
			this.errorMessage = ''
			this.showModal = true
		},

		/**
		 * @param {object} payload The BRP lookup request.
		 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#5.1
		 */
		async onLookup(payload) {
			this.showModal = false
			this.lookupState = 'loading'
			this.errorMessage = ''
			try {
				const url = generateUrl('/apps/pipelinq/api/brp/lookup')
				const response = await axios.post(url, {
					bsn: this.rawBsn,
					verzoekreden: payload.verzoekreden,
					purposeBinding: payload.purposeBinding,
					basis: payload.basis,
					gekoppeldContact: this.contactId,
					vogScreening: payload.vogScreening,
				})
				const data = response.data || {}
				this.persoon = data.persoon || null
				this.cacheHit = Boolean(data.responseInCache)
				this.lookupState = this.persoon ? 'success' : 'error'
				if (this.persoon) {
					showSuccess(this.t('pipelinq', 'BRP data retrieved'))
					this.$emit('contact-updated')
				}
			} catch (err) {
				this.lookupState = 'error'
				const data = err?.response?.data || {}
				this.errorMessage =
					data.errorMessage
					|| this.t(
						'pipelinq',
						'BRP is currently unavailable — please try again in a few minutes.',
					)
				showError(this.errorMessage)
			}
		},

		/**
		 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#5.1
		 */
		async revealAddress() {
			try {
				const url = generateUrl(
					'/apps/pipelinq/api/brp/contact/{id}/reveal-address',
					{ id: this.contactId },
				)
				const response = await axios.post(url)
				this.revealedAddress = true
				this.revealedVerblijfplaats = response.data?.residence || null
				showSuccess(
					this.t('pipelinq', 'Address revealed — audit record created.'),
				)
			} catch (err) {
				const data = err?.response?.data || {}
				showError(
					data.errorMessage
						|| this.t('pipelinq', 'Could not reveal address.'),
				)
			}
		},
	},
}
</script>

<style scoped>
.brp-panel {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 8px);
}

.brp-panel__bsn-row {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline, 8px);
	align-items: flex-end;
}

.brp-panel__status {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 0;
}

.brp-panel__status--error {
	color: var(--color-error, #c2392a);
}

.brp-panel__persoon {
	border-top: 1px solid var(--color-border, #e8e8e8);
	margin-top: 8px;
	padding-top: 8px;
}

.brp-panel__persoon-header {
	display: flex;
	gap: 8px;
	align-items: center;
	font-size: 1.05em;
}

.brp-panel__geheim-icon {
	color: var(--color-error, #c2392a);
	font-size: 1.2em;
}

.brp-panel__cache-badge {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast, #767676);
}

.brp-panel__persoon-fields {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 4px 12px;
	margin: 8px 0;
}

.brp-panel__persoon-fields dt {
	color: var(--color-text-maxcontrast, #767676);
}

.brp-panel__secret {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 8px;
	background: var(--color-background-hover, #f5f5f5);
	border-radius: 4px;
}

.brp-panel__address {
	padding-top: 4px;
}
</style>
