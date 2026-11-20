<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The lead edit form on LeadDetail, mounted in the page's `form-dialog`
  - slot. It is the library's own schema form, saved through the page's own
  - `confirm`, with one step in between: when the save moves a commit or
  - best-case deal's close date out of its forecast period, SlipReasonModal
  - asks why first, and the save goes through only with the reason
  - (`slip_reason`). Cancelling the reason leaves the form open with the
  - edit unsaved.
  -
  - @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#requirement-a-deal-that-slips-out-of-its-forecast-period-needs-a-slip-reason-req-frc-012
  -->
<template>
	<div v-if="show" class="lead-edit-dialog">
		<CnFormDialog
			ref="formDialog"
			:schema="schema"
			:item="item"
			register="pipelinq"
			data-testid="lead-edit-form"
			@confirm="onConfirm"
			@close="onClose" />
		<SlipReasonModal
			v-if="pending"
			:fromDate="fromDate"
			:toDate="toDate"
			:initialReason="pending.slip_reason || ''"
			@confirm="onReason"
			@close="onReasonCancel" />
	</div>
</template>

<script>
import { CnFormDialog } from '@conduction/nextcloud-vue'
import SlipReasonModal from '../modals/SlipReasonModal.vue'
import { closeDay, needsSlipReason } from '../services/forecastSlipCheck.js'

/**
 * A close date as `YYYY-MM-DD`, for the reason dialog's sentence.
 *
 * @param {string|Date|null|undefined} value The stored or typed close date.
 * @return {string} The day, or an empty string.
 */
function dayText(value) {
	const d = closeDay(value)
	return d ? `${d.year}-${String(d.month).padStart(2, '0')}-${String(d.day).padStart(2, '0')}` : ''
}

export default {
	name: 'LeadEditDialog',

	components: {
		CnFormDialog,
		SlipReasonModal,
	},

	props: {
		/** Whether the page wants the form open (slot binding). */
		show: {
			type: Boolean,
			default: false,
		},

		/** The lead being edited (slot binding). */
		item: {
			type: Object,
			default: null,
		},

		/** The lead schema (slot binding). */
		schema: {
			type: Object,
			default: null,
		},

		/** The page's own save path (slot binding). */
		confirm: {
			type: Function,
			default: null,
		},

		/** The page's own close (slot binding). */
		close: {
			type: Function,
			default: null,
		},
	},

	data() {
		return {
			/** The form values waiting for a slip reason, or null. */
			pending: null,
		}
	},

	computed: {
		/**
		 * @return {string} The stored close date.
		 * @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#requirement-a-deal-that-slips-out-of-its-forecast-period-needs-a-slip-reason-req-frc-012
		 */
		fromDate() {
			return dayText(this.item?.expectedCloseDate)
		},

		/**
		 * @return {string} The close date being saved.
		 * @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#requirement-a-deal-that-slips-out-of-its-forecast-period-needs-a-slip-reason-req-frc-012
		 */
		toDate() {
			return dayText(this.pending?.expectedCloseDate)
		},
	},

	methods: {
		/**
		 * The form's Save: straight through, or first ask for a slip reason.
		 *
		 * @param {object} formData The form's values.
		 * @return {Promise<void>}
		 * @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#requirement-a-deal-that-slips-out-of-its-forecast-period-needs-a-slip-reason-req-frc-012
		 */
		async onConfirm(formData) {
			if (needsSlipReason(this.item, formData)) {
				this.pending = { ...formData }
				return
			}
			await this.save(formData)
		},

		/**
		 * The reason is given: save the edit with it.
		 *
		 * @param {string} reason The slip reason.
		 * @return {Promise<void>}
		 * @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#requirement-a-deal-that-slips-out-of-its-forecast-period-needs-a-slip-reason-req-frc-012
		 */
		async onReason(reason) {
			const data = { ...this.pending, slip_reason: reason }
			this.pending = null
			await this.save(data)
		},

		/**
		 * No reason given: the edit is not saved and the form stays open.
		 *
		 * @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#requirement-a-deal-that-slips-out-of-its-forecast-period-needs-a-slip-reason-req-frc-012
		 */
		onReasonCancel() {
			this.pending = null
			this.$refs.formDialog?.setResult?.({
				error: t('pipelinq', 'Not saved: a deal that slips out of its forecast period needs a slip reason.'),
			})
		},

		/**
		 * Save through the page and hand the outcome to the form.
		 *
		 * @param {object} data The values to save.
		 * @return {Promise<void>}
		 * @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#requirement-a-deal-that-slips-out-of-its-forecast-period-needs-a-slip-reason-req-frc-012
		 */
		async save(data) {
			if (typeof this.confirm !== 'function') {
				this.$refs.formDialog?.setResult?.({ error: t('pipelinq', 'The lead could not be saved.') })
				return
			}
			let result
			try {
				result = await this.confirm(data)
			} catch (e) {
				result = { error: e?.response?.data?.message || e?.message || t('pipelinq', 'The lead could not be saved.') }
			}
			this.$refs.formDialog?.setResult?.(result || { success: true })
		},

		/**
		 * Close the form through the page.
		 *
		 * @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#requirement-a-deal-that-slips-out-of-its-forecast-period-needs-a-slip-reason-req-frc-012
		 */
		onClose() {
			this.pending = null
			if (typeof this.close === 'function') {
				this.close()
			}
		},
	},
}
</script>
