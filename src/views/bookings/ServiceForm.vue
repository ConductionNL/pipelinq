<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Service edit form — appointment-booking member 11.

  Captures the full Service schema: name, description, duration, buffer
  windows, price/currency, required skills, multiStep composition (sub-table
  with add / delete / reorder), bookable/deposit toggles, deposit amount,
  noShowFee, cancellation policy + hours-before.

  Validation is shallow on purpose — the schema is the source of truth and
  OR rejects malformed payloads on save. The form blocks save while a
  required field is empty so the round-trip is friendly. multiStep totals
  MUST equal the top-level durationMinutes (REQ-APT-001 scenario "duration
  sums to multi-step total"); enforced client-side here AND server-side by
  AvailabilityService when the booking is created.

  @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
-->
<template>
	<div class="service-form" data-testid="service-form">
		<div class="form-group">
			<NcTextField
				id="service-name"
				:label="t('pipelinq', 'Name') + ' *'"
				:model-value="form.name"
				:error="!!errors.name"
				:helper-text="errors.name"
				:maxlength="255"
				@update:model-value="
					(v) => {
						form.name = v
						validateField('name')
					}
				" />
		</div>

		<div class="form-group">
			<label for="service-description">{{
				t('pipelinq', 'Description')
			}}</label>
			<textarea
				id="service-description"
				v-model="form.description"
				rows="3"
				maxlength="4000" />
		</div>

		<div class="form-row">
			<div class="form-group">
				<NcTextField
					id="service-duration"
					:label="t('pipelinq', 'Duration (minutes)') + ' *'"
					type="number"
					:model-value="String(form.durationMinutes ?? '')"
					:error="!!errors.durationMinutes"
					:helper-text="errors.durationMinutes"
					@update:model-value="
						(v) => {
							form.durationMinutes = v === '' ? null : Number(v)
							validateField('durationMinutes')
						}
					" />
			</div>
			<div class="form-group">
				<NcTextField
					id="service-buffer-before"
					:label="t('pipelinq', 'Buffer before (min)')"
					type="number"
					:model-value="String(form.bufferBeforeMinutes ?? 0)"
					@update:model-value="
						(v) => (form.bufferBeforeMinutes = v === '' ? 0 : Number(v))
					" />
			</div>
			<div class="form-group">
				<NcTextField
					id="service-buffer-after"
					:label="t('pipelinq', 'Buffer after (min)')"
					type="number"
					:model-value="String(form.bufferAfterMinutes ?? 0)"
					@update:model-value="
						(v) => (form.bufferAfterMinutes = v === '' ? 0 : Number(v))
					" />
			</div>
		</div>

		<div class="form-row">
			<div class="form-group">
				<NcTextField
					id="service-price"
					:label="t('pipelinq', 'Price')"
					type="number"
					:model-value="String(form.price ?? 0)"
					@update:model-value="
						(v) => (form.price = v === '' ? 0 : Number(v))
					" />
			</div>
			<div class="form-group">
				<NcTextField
					id="service-currency"
					:label="t('pipelinq', 'Currency')"
					:model-value="form.currency || 'EUR'"
					:maxlength="3"
					@update:model-value="
						(v) => (form.currency = (v || '').toUpperCase())
					" />
			</div>
		</div>

		<div class="form-group">
			<NcTextField
				id="service-skills"
				:label="t('pipelinq', 'Required skills (comma-separated)')"
				:model-value="skillsCsv"
				@update:model-value="onSkillsInput" />
		</div>

		<div class="form-group">
			<label>{{ t('pipelinq', 'Multi-step composition') }}</label>
			<div v-if="form.multiStep.length === 0" class="form-empty">
				{{ t('pipelinq', 'Single-step service — no multi-step rows.') }}
			</div>
			<table v-else class="step-table">
				<thead>
					<tr>
						<th scope="col">{{ t('pipelinq', '#') }}</th>
						<th scope="col">{{ t('pipelinq', 'Duration (min)') }}</th>
						<th scope="col">{{ t('pipelinq', 'Resource type') }}</th>
						<th scope="col">{{ t('pipelinq', 'Skill required') }}</th>
						<th scope="col">{{ t('pipelinq', 'Allow gap') }}</th>
						<th scope="col" />
					</tr>
				</thead>
				<tbody>
					<tr v-for="(step, idx) in form.multiStep" :key="idx">
						<td>{{ idx + 1 }}</td>
						<td>
							<input
								:id="`step-duration-${idx}`"
								v-model.number="step.durationMinutes"
								type="number"
								min="0"
								:aria-label="t('pipelinq', 'Step duration')" />
						</td>
						<td>
							<NcSelect
								v-model="step.resourceType"
								:input-id="`step-resource-${idx}`"
								:aria-label-combobox="t('pipelinq', 'Resource type')"
								:options="resourceTypeOptions"
								:reduce="(o) => o.value"
								label="label" />
						</td>
						<td>
							<input
								:id="`step-skill-${idx}`"
								v-model="step.skillRequired"
								type="text"
								:aria-label="t('pipelinq', 'Skill slug')" />
						</td>
						<td class="center">
							<input
								:id="`step-gap-${idx}`"
								v-model="step.allowGap"
								type="checkbox"
								:aria-label="t('pipelinq', 'Allow gap')" />
						</td>
						<td>
							<NcButton
								variant="tertiary"
								:disabled="idx === 0"
								@click="moveStep(idx, -1)">
								&#9650;
							</NcButton>
							<NcButton
								variant="tertiary"
								:disabled="idx === form.multiStep.length - 1"
								@click="moveStep(idx, 1)">
								&#9660;
							</NcButton>
							<NcButton variant="tertiary" @click="removeStep(idx)">
								&times;
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<NcButton variant="secondary" class="add-step" @click="addStep">
				{{ t('pipelinq', 'Add step') }}
			</NcButton>
			<p v-if="multiStepWarning" class="warning-text">
				{{ multiStepWarning }}
			</p>
		</div>

		<div class="form-row">
			<div class="form-group toggle-group">
				<input
					id="service-bookable"
					v-model="form.bookableOnline"
					type="checkbox" />
				<label for="service-bookable">{{
					t('pipelinq', 'Bookable online')
				}}</label>
			</div>
			<div class="form-group toggle-group">
				<input
					id="service-requires-deposit"
					v-model="form.requiresDeposit"
					type="checkbox" />
				<label for="service-requires-deposit">{{
					t('pipelinq', 'Requires deposit')
				}}</label>
			</div>
		</div>

		<div class="form-row">
			<div class="form-group">
				<NcTextField
					id="service-deposit-amount"
					:label="t('pipelinq', 'Deposit amount')"
					type="number"
					:model-value="String(form.depositAmount ?? 0)"
					:disabled="!form.requiresDeposit"
					@update:model-value="
						(v) => (form.depositAmount = v === '' ? 0 : Number(v))
					" />
			</div>
			<div class="form-group">
				<NcTextField
					id="service-no-show-fee"
					:label="t('pipelinq', 'No-show fee')"
					type="number"
					:model-value="String(form.noShowFee ?? 0)"
					@update:model-value="
						(v) => (form.noShowFee = v === '' ? 0 : Number(v))
					" />
			</div>
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="service-cancellation-policy">{{
					t('pipelinq', 'Cancellation policy')
				}}</label>
				<NcSelect
					v-model="form.cancellationPolicy"
					input-id="service-cancellation-policy"
					:aria-label-combobox="t('pipelinq', 'Cancellation policy')"
					:options="cancellationPolicyOptions"
					:reduce="(o) => o.value"
					label="label" />
			</div>
			<div class="form-group">
				<NcTextField
					id="service-cancellation-hours"
					:label="t('pipelinq', 'Cancellation hours before')"
					type="number"
					:model-value="String(form.cancellationHoursBefore ?? 24)"
					@update:model-value="
						(v) =>
							(form.cancellationHoursBefore =
								v === '' ? 24 : Number(v))
					" />
			</div>
		</div>

		<div class="form-group">
			<label for="service-status">{{ t('pipelinq', 'Status') }} *</label>
			<NcSelect
				v-model="form.status"
				input-id="service-status"
				:aria-label-combobox="t('pipelinq', 'Status')"
				:options="statusOptions"
				:reduce="(o) => o.value"
				label="label" />
		</div>

		<div class="service-form__actions">
			<NcButton
				variant="primary"
				:disabled="!isValid"
				data-testid="service-form-save"
				@click="onSave">
				{{ t('pipelinq', 'Save') }}
			</NcButton>
			<NcButton data-testid="service-form-cancel" @click="$emit('cancel')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'

const RESOURCE_TYPES = ['staff', 'room', 'equipment']

export default {
	name: 'ServiceForm',
	components: { NcButton, NcSelect, NcTextField },
	props: {
		service: {
			type: Object,
			default: () => ({}),
		},
	},
	emits: ['save', 'cancel'],
	data() {
		return {
			form: this.emptyForm(),
			errors: {
				name: '',
				durationMinutes: '',
			},
		}
	},
	computed: {
		isValid() {
			const hasName = (this.form.name || '').trim().length > 0
			const hasDuration =
				Number.isInteger(this.form.durationMinutes)
				&& this.form.durationMinutes > 0
			const noErrors = Object.values(this.errors).every((e) => !e)
			return hasName && hasDuration && noErrors
		},
		skillsCsv() {
			return (this.form.requiredSkills || []).join(', ')
		},
		statusOptions() {
			return [
				{ value: 'draft', label: t('pipelinq', 'Draft') },
				{ value: 'active', label: t('pipelinq', 'Active') },
				{ value: 'archived', label: t('pipelinq', 'Archived') },
			]
		},
		cancellationPolicyOptions() {
			return [
				{ value: 'free', label: t('pipelinq', 'Free') },
				{ value: 'charge-deposit', label: t('pipelinq', 'Charge deposit') },
				{ value: 'always-charge', label: t('pipelinq', 'Always charge') },
			]
		},
		resourceTypeOptions() {
			return RESOURCE_TYPES.map((v) => ({ value: v, label: t('pipelinq', v) }))
		},
		/**
		 * Surfaces an inline warning when the multiStep duration total
		 * disagrees with the top-level durationMinutes — REQ-APT-001
		 * "duration sums to multi-step total".
		 *
		 * @return {string} Warning message, or empty.
		 */
		multiStepWarning() {
			if (!this.form.multiStep || this.form.multiStep.length === 0) {
				return ''
			}
			const sum = this.form.multiStep.reduce(
				(acc, s) => acc + (Number(s.durationMinutes) || 0),
				0,
			)
			if (sum === this.form.durationMinutes) {
				return ''
			}
			return t(
				'pipelinq',
				'Multi-step total ({sum} min) does not match Duration ({duration} min).',
				{
					sum,
					duration: this.form.durationMinutes || 0,
				},
			)
		},
	},
	watch: {
		service: {
			immediate: true,
			handler(val) {
				if (val && Object.keys(val).length > 0) {
					this.populateForm(val)
				}
			},
		},
	},
	methods: {
		emptyForm() {
			return {
				name: '',
				description: '',
				durationMinutes: 30,
				bufferBeforeMinutes: 0,
				bufferAfterMinutes: 0,
				price: 0,
				currency: 'EUR',
				requiredSkills: [],
				multiStep: [],
				bookableOnline: true,
				requiresDeposit: false,
				depositAmount: 0,
				noShowFee: 0,
				cancellationPolicy: 'free',
				cancellationHoursBefore: 24,
				status: 'active',
			}
		},
		populateForm(data) {
			this.form = {
				...this.emptyForm(),
				...data,
				multiStep: Array.isArray(data.multiStep)
					? data.multiStep.map((s) => ({ ...s }))
					: [],
				requiredSkills: Array.isArray(data.requiredSkills)
					? [...data.requiredSkills]
					: [],
			}
			this.errors = { name: '', durationMinutes: '' }
		},
		validateField(field) {
			switch (field) {
				case 'name':
					if (!String(this.form.name || '').trim()) {
						this.errors.name = t('pipelinq', 'Name is required')
					} else {
						this.errors.name = ''
					}
					break
				case 'durationMinutes':
					if (
						!Number.isInteger(this.form.durationMinutes)
						|| this.form.durationMinutes <= 0
					) {
						this.errors.durationMinutes = t(
							'pipelinq',
							'Duration must be a positive whole number',
						)
					} else if (this.form.durationMinutes > 1440) {
						this.errors.durationMinutes = t(
							'pipelinq',
							'Duration cannot exceed 24 hours',
						)
					} else {
						this.errors.durationMinutes = ''
					}
					break
			}
		},
		validateAll() {
			this.validateField('name')
			this.validateField('durationMinutes')
			return this.isValid
		},
		/**
		 * Parse a comma-separated skill list into the array form.
		 *
		 * @param {string} value The raw input.
		 */
		onSkillsInput(value) {
			this.form.requiredSkills = (value || '')
				.split(',')
				.map((s) => s.trim())
				.filter((s) => s.length > 0)
		},
		addStep() {
			this.form.multiStep.push({
				durationMinutes: 0,
				resourceType: 'staff',
				skillRequired: '',
				allowGap: false,
			})
		},
		removeStep(idx) {
			this.form.multiStep.splice(idx, 1)
		},
		/**
		 * Reorder a step by `delta` positions, clamped to the array bounds.
		 *
		 * @param {number} idx   Source index.
		 * @param {number} delta Direction (+1 = down, -1 = up).
		 */
		moveStep(idx, delta) {
			const target = idx + delta
			if (target < 0 || target >= this.form.multiStep.length) {
				return
			}
			const step = this.form.multiStep.splice(idx, 1)[0]
			this.form.multiStep.splice(target, 0, step)
		},
		onSave() {
			if (!this.validateAll()) {
				return
			}
			const data = { ...this.form }
			data.multiStep = (this.form.multiStep || []).map((s) => ({
				durationMinutes: Number(s.durationMinutes) || 0,
				resourceType: s.resourceType || 'staff',
				skillRequired: (s.skillRequired || '').trim(),
				allowGap: !!s.allowGap,
			}))
			data.requiredSkills = [...(this.form.requiredSkills || [])]
			if (this.service?.id) {
				data.id = this.service.id
			}
			this.$emit('save', data)
		},
	},
}
</script>

<style scoped>
.service-form {
	max-width: 960px;
	padding: 0 4px;
}

.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}

.form-group textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
}

.form-row {
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
}

.form-row .form-group {
	flex: 1 1 200px;
}

.toggle-group {
	display: flex;
	align-items: center;
	gap: 8px;
}

.toggle-group label {
	margin: 0;
	font-weight: normal;
}

.step-table {
	width: 100%;
	border-collapse: collapse;
}

.step-table th,
.step-table td {
	padding: 6px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.step-table input[type='number'],
.step-table input[type='text'] {
	width: 100%;
	padding: 4px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.step-table .center {
	text-align: center;
}

.add-step {
	margin-top: 8px;
}

.form-empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.warning-text {
	color: var(--color-warning);
	margin-top: 6px;
	font-size: 13px;
}

.service-form__actions {
	display: flex;
	gap: 12px;
	margin-top: 24px;
}
</style>
