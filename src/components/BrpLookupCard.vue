<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<div class="brp-lookup">
		<div class="brp-lookup__input-row">
			<NcTextField
				:value.sync="bsnInput"
				:label="t('pipelinq', 'BSN')"
				:placeholder="t('pipelinq', 'e.g. 123456782')"
				:error="showError"
				:success="isValid"
				inputmode="numeric"
				autocomplete="off"
				@update:value="onInput" />
			<NcButton
				type="primary"
				:disabled="!isValid"
				@click="openModal">
				{{ t('pipelinq', 'Ophalen uit BRP') }}
			</NcButton>
		</div>

		<p v-if="showError" class="brp-lookup__validation brp-lookup__validation--error">
			{{ validationMessage }}
		</p>
		<p v-else-if="isValid" class="brp-lookup__validation brp-lookup__validation--ok">
			✓ {{ t('pipelinq', 'Valid BSN') }}
		</p>

		<!-- Resolved Persoon detail -->
		<div v-if="persoon" class="brp-persoon">
			<div class="brp-persoon__name">
				<span v-if="geheimhouding" class="brp-persoon__secret-icon" :title="t('pipelinq', '[SECRET]')">🔒</span>
				<strong>{{ fullName }}</strong>
				<span v-if="fromCache" class="brp-persoon__cache-badge">{{ t('pipelinq', '⚡ from cache') }}</span>
			</div>

			<div class="brp-persoon__grid">
				<div class="brp-persoon__field">
					<label>{{ t('pipelinq', 'Date of birth') }}</label>
					<span>{{ persoon.geboortedatum || '-' }}</span>
				</div>
				<div class="brp-persoon__field">
					<label>{{ t('pipelinq', 'Place of birth') }}</label>
					<span>{{ persoon.geboorteplaats || '-' }}</span>
				</div>
				<div class="brp-persoon__field">
					<label>{{ t('pipelinq', 'Gender') }}</label>
					<span>{{ persoon.geslacht || '-' }}</span>
				</div>
			</div>

			<div class="brp-persoon__address">
				<label>{{ t('pipelinq', 'Address') }}</label>
				<div v-if="!geheimhouding && persoon.verblijfplaats">
					<div>{{ addressLine }}</div>
					<div>{{ postcodeLine }}</div>
				</div>
				<div v-else class="brp-persoon__secret">
					{{ t('pipelinq', '[SECRET]') }}
					<a class="brp-persoon__secret-link" @click="revealAddress">
						{{ t('pipelinq', 'Show address under accountability') }}
					</a>
				</div>
			</div>
		</div>

		<BrpDoelbindingModal
			v-if="modalOpen"
			:bsn="bsnInput"
			:contact-id="contactId"
			@close="modalOpen = false"
			@resolved="onResolved" />
	</div>
</template>

<script>
import { NcTextField, NcButton } from '@nextcloud/vue'
import { validateBsn, BSN_ERROR_LENGTH } from '../services/bsnValidation.js'
import BrpDoelbindingModal from '../modals/BrpDoelbindingModal.vue'

export default {
	name: 'BrpLookupCard',
	components: {
		NcTextField,
		NcButton,
		BrpDoelbindingModal,
	},
	props: {
		/** The Pipelinq contact UUID the lookup is performed for. */
		contactId: {
			type: String,
			default: '',
		},
	},
	emits: ['timeline-event'],
	data() {
		return {
			bsnInput: '',
			errorCode: null,
			touched: false,
			modalOpen: false,
			persoon: null,
			geheimhouding: false,
			fromCache: false,
		}
	},
	computed: {
		/**
		 * Whether the current input passes the client-side 11-proef.
		 *
		 * @return {boolean} The validity.
		 */
		isValid() {
			return this.errorCode === null && this.bsnInput.length === 9
		},
		/**
		 * Show the error only once the field has been touched and is non-empty.
		 *
		 * @return {boolean} Whether to render the error.
		 */
		showError() {
			return this.touched && this.bsnInput.length > 0 && this.errorCode !== null
		},
		/**
		 * The localized validation message for the current error.
		 *
		 * @return {string} The message.
		 */
		validationMessage() {
			if (this.errorCode === BSN_ERROR_LENGTH) {
				return t('pipelinq', 'A BSN consists of exactly 9 digits')
			}
			return t('pipelinq', 'This BSN does not pass the 11-proef')
		},
		/**
		 * Compose the full name from the resolved person record.
		 *
		 * @return {string} The full name.
		 */
		fullName() {
			if (!this.persoon) {
				return ''
			}
			return [this.persoon.voornamen, this.persoon.voorvoegsel, this.persoon.geslachtsnaam]
				.filter(Boolean)
				.join(' ')
		},
		/**
		 * Street + house number line of the resolved address.
		 *
		 * @return {string} The line.
		 */
		addressLine() {
			const v = this.persoon?.verblijfplaats || {}
			return [v.straat, v.huisnummer, v.huisletter].filter(Boolean).join(' ')
		},
		/**
		 * Postcode + city line of the resolved address.
		 *
		 * @return {string} The line.
		 */
		postcodeLine() {
			const v = this.persoon?.verblijfplaats || {}
			return [v.postcode, v.woonplaats].filter(Boolean).join(' ')
		},
	},
	methods: {
		/**
		 * Re-validate on every keystroke and reset any stale result.
		 *
		 * @param {string} value The new input value.
		 */
		onInput(value) {
			this.bsnInput = String(value || '').replace(/\D/g, '').slice(0, 9)
			this.touched = true
			this.errorCode = validateBsn(this.bsnInput).errorCode
		},
		/**
		 * Open the doelbinding modal once the BSN is valid.
		 */
		openModal() {
			if (this.isValid) {
				this.modalOpen = true
			}
		},
		/**
		 * Render the resolved person and emit a BSN-free timeline event.
		 *
		 * @param {object} payload The lookup response.
		 */
		onResolved(payload) {
			this.persoon = payload.persoon || null
			this.geheimhouding = !!payload.geheimhouding
			this.fromCache = !!payload.responseInCache

			// Timeline text NEVER includes the BSN (REQ-BSN-009-02).
			this.$emit('timeline-event', {
				action: 'brp-lookup-uitgevoerd',
				text: t('pipelinq', 'BRP data retrieved (reason: {reason}, cache: {cache})', {
					reason: payload.verzoekreden || '-',
					cache: this.fromCache ? t('pipelinq', 'yes') : t('pipelinq', 'no'),
				}),
			})
		},
		/**
		 * Reveal the address of a protected person under accountability.
		 *
		 * Re-runs the lookup with an explicit accountability flag so the backend
		 * writes an additional audit entry; until then the address stays hidden.
		 */
		revealAddress() {
			this.modalOpen = true
		},
	},
}
</script>

<style scoped>
.brp-lookup {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.brp-lookup__input-row {
	display: flex;
	align-items: flex-end;
	gap: 12px;
}

.brp-lookup__validation {
	font-size: 13px;
	margin: 0;
}

.brp-lookup__validation--error {
	color: var(--color-error);
}

.brp-lookup__validation--ok {
	color: var(--color-success);
}

.brp-persoon {
	margin-top: 12px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.brp-persoon__name {
	display: flex;
	align-items: center;
	gap: 10px;
	font-size: 16px;
	margin-bottom: 12px;
}

.brp-persoon__cache-badge {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.brp-persoon__grid {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 12px;
	margin-bottom: 12px;
}

.brp-persoon__field label,
.brp-persoon__address label {
	display: block;
	font-weight: bold;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 2px;
}

.brp-persoon__secret {
	color: var(--color-warning);
}

.brp-persoon__secret-link {
	display: block;
	margin-top: 4px;
	color: var(--color-primary);
	cursor: pointer;
	text-decoration: underline;
}
</style>
