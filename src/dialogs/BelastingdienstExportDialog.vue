<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - BelastingdienstExportDialog — admin-only modal to confirm the date range
  - and format (XML or JSON) for the kassakoppeling audit log export pack
  - delivered to the Dutch tax authority. Extracted to its own file per
  - ADR-004 (modal-isolation).
  -
  - @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#5.1
  -->
<template>
	<NcDialog
		:name="t('pipelinq', 'Exporteren naar Belastingdienst')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="bd-export">
			<p>
				{{ t('pipelinq', 'Selecteer de periode en het exportformaat voor de Belastingdienst kassakoppeling controlepakket. Alle audit-entries binnen het bereik worden geëxporteerd; de hash-keten integriteit wordt automatisch in de export-metadata opgenomen.') }}
			</p>

			<div class="form-row">
				<label for="bd-from">{{ t('pipelinq', 'Vanaf (datum)') }}</label>
				<input
					id="bd-from"
					v-model="fromDate"
					type="date"
					:aria-label="t('pipelinq', 'Vanaf datum')">
			</div>

			<div class="form-row">
				<label for="bd-to">{{ t('pipelinq', 'Tot en met (datum)') }}</label>
				<input
					id="bd-to"
					v-model="toDate"
					type="date"
					:aria-label="t('pipelinq', 'Tot en met datum')">
			</div>

			<div class="form-row format-row">
				<span class="format-label">{{ t('pipelinq', 'Formaat') }}</span>
				<NcCheckboxRadioSwitch
					:checked.sync="format"
					value="xml"
					name="bd-format"
					type="radio">
					{{ t('pipelinq', 'XML (kanoniek Belastingdienst formaat)') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked.sync="format"
					value="json"
					name="bd-format"
					type="radio">
					{{ t('pipelinq', 'JSON (ontwikkelaarsvriendelijk)') }}
				</NcCheckboxRadioSwitch>
			</div>

			<p v-if="errorMessage" class="bd-export__error">
				{{ errorMessage }}
			</p>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Annuleren') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="submitting"
				@click="submit">
				{{ t('pipelinq', 'Download exportpakket') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog } from '@nextcloud/vue'

export default {
	name: 'BelastingdienstExportDialog',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
	},
	props: {
		submitting: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['close', 'confirm'],
	data() {
		const today = new Date()
		const firstOfMonth = new Date(today.getFullYear(), today.getMonth(), 1)
		return {
			fromDate: this.formatIsoDate(firstOfMonth),
			toDate: this.formatIsoDate(today),
			format: 'xml',
			errorMessage: '',
		}
	},
	methods: {
		/**
		 * Format a Date object as YYYY-MM-DD.
		 *
		 * @param {Date} date The date to format.
		 * @return {string} The formatted ISO date.
		 */
		formatIsoDate(date) {
			const year = date.getFullYear()
			const month = String(date.getMonth() + 1).padStart(2, '0')
			const day = String(date.getDate()).padStart(2, '0')
			return `${year}-${month}-${day}`
		},
		/**
		 * Validate inputs and emit the confirm event with the selected range and format.
		 */
		submit() {
			this.errorMessage = ''
			if (!this.fromDate || !this.toDate) {
				this.errorMessage = t('pipelinq', 'Vul beide datums in (verplicht voor de Belastingdienst).')
				return
			}
			if (this.fromDate > this.toDate) {
				this.errorMessage = t('pipelinq', '"Vanaf" datum moet voor of gelijk zijn aan "tot en met" datum.')
				return
			}
			this.$emit('confirm', {
				from: this.fromDate,
				to: this.toDate,
				format: this.format,
			})
		},
	},
}
</script>

<style scoped>
.bd-export {
	padding: 8px 0;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.form-row {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.form-row label,
.format-label {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.form-row input[type="date"] {
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.format-row {
	gap: 8px;
}

.bd-export__error {
	color: var(--color-error);
	font-size: 13px;
	margin: 0;
}
</style>
