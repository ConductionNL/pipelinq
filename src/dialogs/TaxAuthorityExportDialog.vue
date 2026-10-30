<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - TaxAuthorityExportDialog: admin-only modal to confirm the date range
  - and format (XML or JSON) for the kassakoppeling audit log export pack
  - delivered to the Dutch tax authority, and to download it. Registered as
  - TaxAuthorityExportModal, the target of the audit log's export header
  - action. Extracted to its own file per ADR-004 (modal-isolation).
  -
  - @spec openspec/specs/pos-kassakoppeling-audit/spec.md
  -->
<template>
	<CnDialog
		width="form"
		:name="t('pipelinq', 'Export for the tax authority')"
		size="normal"
		@closing="$emit('close')">
		<div class="bd-export">
			<p>
				{{
					t(
						'pipelinq',
						'Select the period and export format for the cash register audit package for the tax authority. All audit entries within the range are exported; the hash chain integrity is automatically included in the export metadata.',
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
					{{ t('pipelinq', 'XML (the tax authority format)') }}
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
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'

export default {
	name: 'TaxAuthorityExportDialog',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		CnDialog,
	},

	emits: ['close'],
	data() {
		const today = new Date()
		const firstOfMonth = new Date(today.getFullYear(), today.getMonth(), 1)
		return {
			fromDate: this.formatIsoDate(firstOfMonth),
			toDate: this.formatIsoDate(today),
			format: 'xml',
			errorMessage: '',
			submitting: false,
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
		 * Validate the range, then download the export package and close.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/pos-kassakoppeling-audit/spec.md
		 */
		async submit() {
			this.errorMessage = ''
			if (!this.fromDate || !this.toDate) {
				this.errorMessage = t(
					'pipelinq',
					'Fill in both dates (the tax authority requires them).',
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
			this.submitting = true
			try {
				if (await this.download()) {
					showSuccess(t('pipelinq', 'Tax authority export downloaded.'))
					this.$emit('close')
				}
			} catch {
				showError(t('pipelinq', 'Tax authority export failed.'))
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Fetch the export package for the chosen range and format and save it.
		 *
		 * @return {Promise<boolean>} Whether the file was saved.
		 * @spec openspec/specs/pos-kassakoppeling-audit/spec.md
		 */
		async download() {
			const params = new URLSearchParams({ from: this.fromDate, to: this.toDate, format: this.format })
			const response = await fetch(
				generateUrl(`/apps/pipelinq/api/kassakoppeling/audit/export?${params.toString()}`),
				{ method: 'GET', headers: { requesttoken: OC.requestToken } },
			)
			if (!response.ok) {
				showError(response.status === 403
					? t('pipelinq', 'Only administrators may export for the tax authority.')
					: t('pipelinq', 'Tax authority export failed.'))
				return false
			}
			const blob = await response.blob()
			const matched = (response.headers.get('Content-Disposition') || '').match(/filename="?([^";]+)"?/)
			const objectUrl = window.URL.createObjectURL(blob)
			const link = document.createElement('a')
			link.href = objectUrl
			link.download = matched
				? matched[1]
				: `cash-register-audit-export-${this.fromDate}-to-${this.toDate}.${this.format}`
			document.body.appendChild(link)
			link.click()
			link.remove()
			window.URL.revokeObjectURL(objectUrl)
			return true
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
