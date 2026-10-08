<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Editor for a service's multiStep composition, on BookingRowsEditor. Used by
  ServiceForm and by the service edit dialog's `#field-multiStep` slot.

  Warns when the step total disagrees with the service duration
  (REQ-APT-001 "duration sums to multi-step total").

  A step can name a product from the catalogue with a quantity and a unit,
  so a service such as "OpenWoo app" is composed of, say, 8 hours of
  implementation, monthly hosting and an SLA (booking-and-service-pages).

  @spec openspec/specs/appointment-booking/spec.md
-->
<template>
	<BookingRowsEditor
		:modelValue="steps"
		:title="t('pipelinq', 'Multi-step composition')"
		:columns="columns"
		:newRow="newStep"
		numbered
		reorderable
		:labels="labels"
		:emptyText="
			t(
				'pipelinq',
				'Single-step service. Add steps to split it across resources.',
			)
		"
		:addLabel="t('pipelinq', 'Add step')"
		:message="totalWarning"
		data-testid="service-steps-editor"
		@update:modelValue="(v) => $emit('update:modelValue', v)">
		<template #summary>
			{{
				t('pipelinq', '{sum} of {duration} min', {
					sum: stepTotal,
					duration: durationMinutes || 0,
				})
			}}
		</template>
		<template #cell-productId="{ row, index, update }">
			<NcSelect
				:modelValue="productOption(row.productId)"
				:inputId="`service-step-product-${uid}-${index}`"
				:aria-label-combobox="
					t('pipelinq', 'Step {n} product', { n: index + 1 })
				"
				labelOutside
				:placeholder="t('pipelinq', 'No product')"
				:options="productOptions"
				:loading="productsLoading"
				label="label"
				@update:modelValue="(o) => update(o ? o.value : undefined)" />
		</template>
		<template #cell-quantity="{ row, index, update }">
			<NcTextField
				:modelValue="row.quantity == null ? '' : String(row.quantity)"
				type="number"
				min="0"
				step="any"
				labelOutside
				:aria-label="t('pipelinq', 'Step {n} quantity', { n: index + 1 })"
				@update:modelValue="
					(v) => update(v === '' ? undefined : Number(v))
				" />
		</template>
		<template #cell-unit="{ row, index, update }">
			<NcSelect
				:modelValue="row.unit || null"
				:inputId="`service-step-unit-${uid}-${index}`"
				:aria-label-combobox="
					t('pipelinq', 'Step {n} unit', { n: index + 1 })
				"
				labelOutside
				:options="unitOptions"
				:reduce="(o) => o.value"
				label="label"
				@update:modelValue="(v) => update(v || undefined)" />
		</template>
		<template #cell-durationMinutes="{ row, index, update }">
			<NcTextField
				:modelValue="String(row.durationMinutes ?? 0)"
				type="number"
				min="0"
				labelOutside
				:aria-label="
					t('pipelinq', 'Step {n} duration in minutes', { n: index + 1 })
				"
				@update:modelValue="(v) => update(Number(v) || 0)" />
		</template>
		<template #cell-resourceType="{ row, index, update }">
			<NcSelect
				:modelValue="row.resourceType"
				:inputId="`service-step-resource-${uid}-${index}`"
				:aria-label-combobox="
					t('pipelinq', 'Step {n} resource type', { n: index + 1 })
				"
				labelOutside
				:clearable="false"
				:options="resourceTypeOptions"
				:reduce="(o) => o.value"
				label="label"
				@update:modelValue="update" />
		</template>
		<template #cell-skillRequired="{ row, index, update }">
			<NcTextField
				:modelValue="row.skillRequired || ''"
				labelOutside
				:placeholder="t('pipelinq', 'Any')"
				:aria-label="
					t('pipelinq', 'Step {n} required skill', { n: index + 1 })
				"
				@update:modelValue="update" />
		</template>
		<template #cell-allowGap="{ row, index, update }">
			<NcCheckboxRadioSwitch
				:modelValue="!!row.allowGap"
				type="switch"
				:aria-label="
					t('pipelinq', 'Step {n} allows a gap', { n: index + 1 })
				"
				@update:modelValue="update" />
		</template>
	</BookingRowsEditor>
</template>

<script>
import { NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'
import BookingRowsEditor from './BookingRowsEditor.vue'
import { STEP_UNITS, unitLabel } from '../../services/serviceSteps.js'
import { useObjectStore } from '../../store/modules/object.js'

let instanceCount = 0

export default {
	name: 'ServiceStepsEditor',
	components: { BookingRowsEditor, NcCheckboxRadioSwitch, NcSelect, NcTextField },

	props: {
		/** The steps: `{ durationMinutes, resourceType, skillRequired, allowGap }[]`. */
		modelValue: {
			type: Array,
			default: () => [],
		},

		/** The service's total duration, checked against the step total. */
		durationMinutes: {
			type: Number,
			default: null,
		},
	},

	emits: ['update:modelValue', 'catalogue'],

	data() {
		instanceCount += 1
		return { uid: instanceCount, products: [], productsLoading: false }
	},

	computed: {
		/**
		 * The service's steps, or none when the value is not an array.
		 *
		 * @return {Array<object>}
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		steps() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},

		/**
		 * The row editor's columns: duration, resource type, skill and gap.
		 *
		 * @return {Array<object>}
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		columns() {
			return [
				{
					key: 'productId',
					label: t('pipelinq', 'Product'),
					width: 'minmax(10rem, 1.5fr)',
				},
				{
					key: 'quantity',
					label: t('pipelinq', 'Quantity'),
					width: 'minmax(4.5rem, 6rem)',
				},
				{
					key: 'unit',
					label: t('pipelinq', 'Unit'),
					width: 'minmax(6rem, 8rem)',
				},
				{
					key: 'durationMinutes',
					label: t('pipelinq', 'Duration (min)'),
					width: 'minmax(5rem, 7rem)',
				},
				{
					key: 'resourceType',
					label: t('pipelinq', 'Resource type'),
					width: 'minmax(8rem, 1fr)',
				},
				{
					key: 'skillRequired',
					label: t('pipelinq', 'Skill required'),
					width: 'minmax(8rem, 1fr)',
				},
				{
					key: 'allowGap',
					label: t('pipelinq', 'Allow gap'),
					width: '4.5rem',
				},
			]
		},

		/**
		 * Accessible labels for each step's move and remove buttons.
		 *
		 * @return {object}
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		labels() {
			return {
				moveUp: (n) => t('pipelinq', 'Move step {n} up', { n }),
				moveDown: (n) => t('pipelinq', 'Move step {n} down', { n }),
				remove: (n) => t('pipelinq', 'Remove step {n}', { n }),
			}
		},

		/**
		 * The catalogue's products as picker options.
		 *
		 * @return {Array<{value: string, label: string}>}
		 *
		 * @spec openspec/changes/booking-and-service-pages/specs/appointment-booking/spec.md
		 */
		productOptions() {
			return this.products
				.filter((p) => p && p.id)
				.map((p) => ({
					value: p.id,
					label: p.name || p.title || p.id,
				}))
		},

		/**
		 * The units a step's quantity can be in.
		 *
		 * @return {Array<{value: string, label: string}>}
		 *
		 * @spec openspec/changes/booking-and-service-pages/specs/appointment-booking/spec.md
		 */
		unitOptions() {
			return STEP_UNITS.map((value) => ({ value, label: unitLabel(value) }))
		},

		/**
		 * The resource types a step can need.
		 *
		 * @return {Array<{value: string, label: string}>}
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		resourceTypeOptions() {
			return [
				{ value: 'staff', label: t('pipelinq', 'Staff') },
				{ value: 'room', label: t('pipelinq', 'Room') },
				{ value: 'equipment', label: t('pipelinq', 'Equipment') },
			]
		},

		/**
		 * The steps' combined duration, in minutes.
		 *
		 * @return {number}
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		stepTotal() {
			return this.steps.reduce(
				(acc, s) => acc + (Number(s.durationMinutes) || 0),
				0,
			)
		},

		/**
		 * A warning when the steps' total differs from the service duration.
		 *
		 * @return {string} The warning, or an empty string.
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		totalWarning() {
			if (this.steps.length === 0 || this.stepTotal === this.durationMinutes) {
				return ''
			}
			return t(
				'pipelinq',
				'Multi-step total ({sum} min) does not match Duration ({duration} min).',
				{ sum: this.stepTotal, duration: this.durationMinutes || 0 },
			)
		},
	},

	/**
	 * Load the product catalogue for the step product picker, and hand it to
	 * the parent, which shows the saved steps by product name.
	 *
	 * @spec openspec/changes/booking-and-service-pages/specs/appointment-booking/spec.md
	 * @spec openspec/changes/review-audit-fixes-b/specs/appointment-booking/spec.md#requirement-a-saved-composition-step-shows-its-product-name-req-raf-040
	 */
	async mounted() {
		this.productsLoading = true
		try {
			const rows = await useObjectStore().fetchCollection('product', {
				_limit: 500,
			})
			this.products = Array.isArray(rows) ? rows : []
			this.$emit('catalogue', this.products)
		} catch {
			this.products = []
		} finally {
			this.productsLoading = false
		}
	},

	methods: {
		/**
		 * The picker option for a product id, or null when none is set.
		 * An id the catalogue does not know still shows, as the id.
		 *
		 * @param {string} id The product id.
		 * @return {{value: string, label: string}|null}
		 *
		 * @spec openspec/changes/booking-and-service-pages/specs/appointment-booking/spec.md
		 */
		productOption(id) {
			if (!id) {
				return null
			}
			return (
				this.productOptions.find((o) => o.value === id) || {
					value: id,
					label: id,
				}
			)
		},

		/**
		 * A new step: staff, no duration, no skill, no gap.
		 *
		 * @return {object}
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		newStep() {
			return {
				durationMinutes: 0,
				resourceType: 'staff',
				skillRequired: '',
				allowGap: false,
			}
		},
	},
}
</script>
