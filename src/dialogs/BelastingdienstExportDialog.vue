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
		:name="t('pipelinq', 'Export to Belastingdienst')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="bd-export">
			<p>
				{{
					t(
						'pipelinq',
						'Select the period and export format for the Belastingdienst cash register audit package. All audit entries within the range are exported; the hash chain integrity is automatically included in the export metadata.',
					)
				}}
			</p>

			<div class="form-row">
				<label for="bd-from">{{ t('pipelinq', 'From (date)') }}</label>
				<input
					id="bd-from"
					v-model="fromDate"
					type="date"
					:aria-label="t('pipelinq', 'From date')" />
			</div>

			<div class="form-row">
				<label for="bd-to">{{
					t('pipelinq', 'Up to and including (date)')
				}}</label>
				<input
					id="bd-to"
					v-model="toDate"
					type="date"
					:aria-label="t('pipelinq', 'Up to and including date')" />
			</div>

			<div class="form-row format-row">
				<span class="format-label">{{ t('pipelinq', 'Format') }}</span>
				<NcCheckboxRadioSwitch
					v-model="format"
					value="xml"
					name="bd-format"
					type="radio">
					{{ t('pipelinq', 'XML (canonical Belastingdienst format)') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="format"
					value="json"
					name="bd-format"
					type="radio">
					{{ t('pipelinq', 'JSON (developer-friendly)') }}
				</NcCheckboxRadioSwitch>
			</div>

			<p v-if="errorMessage" class="bd-export__error">
				{{ errorMessage }}
			</p>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="submitting" @click="submit">
				{{ t('pipelinq', 'Download export package') }}
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
				this.errorMessage = t(
					'pipelinq',
					'Fill in both dates (required for the Belastingdienst).',
				)
				return
			}
			if (this.fromDate > this.toDate) {
				this.errorMessage = t(
					'pipelinq',
					'The "from" date must be on or before the "to and including" date.',
				)
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

.form-row input[type='date'] {
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
