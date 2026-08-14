<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Resource edit form — appointment-booking member 11.

  Captures name, type, skills, the seven-weekday workingHours sub-table,
  the vacations sub-table (date ranges), optional calendarSyncId + userId,
  bookable toggle, maxConcurrent, and status.

  Validation rules (REQ-APT-002 / "workingHours open<close, vacations
  start<=end"):
    - For each workingHours row: openTime < closeTime (HH:MM string compare
      is safe because both values match the schema's HH:MM regex).
    - For each vacations row: startDate <= endDate (ISO YYYY-MM-DD string
      compare).

  @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
-->
<template>
	<div class="resource-form" data-testid="resource-form">
		<div class="form-row">
			<div class="form-group">
				<NcTextField
					id="resource-name"
					:label="t('pipelinq', 'Name') + ' *'"
					:modelValue="form.name"
					:error="!!errors.name"
					:helperText="errors.name"
					:maxlength="255"
					@update:modelValue="
						(v) => {
							form.name = v
							validateField('name')
						}
					" />
			</div>
			<div class="form-group">
				<label for="resource-type">{{ t('pipelinq', 'Type') }} *</label>
				<NcSelect
					v-model="form.type"
					inputId="resource-type"
					:aria-label-combobox="t('pipelinq', 'Resource type')"
					:options="typeOptions"
					:reduce="(o) => o.value"
					label="label" />
			</div>
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="resource-status">{{ t('pipelinq', 'Status') }} *</label>
				<NcSelect
					v-model="form.status"
					inputId="resource-status"
					:aria-label-combobox="t('pipelinq', 'Status')"
					:options="statusOptions"
					:reduce="(o) => o.value"
					label="label" />
			</div>
			<div class="form-group">
				<NcTextField
					id="resource-max-concurrent"
					:label="t('pipelinq', 'Max concurrent bookings')"
					type="number"
					:modelValue="String(form.maxConcurrent ?? 1)"
					@update:modelValue="
						(v) =>
							(form.maxConcurrent =
								v === '' ? 1 : Math.max(1, Number(v)))
					" />
			</div>
			<div class="form-group toggle-group">
				<input
					id="resource-bookable"
					v-model="form.bookable"
					type="checkbox" />
				<label for="resource-bookable">{{
					t('pipelinq', 'Bookable')
				}}</label>
			</div>
		</div>

		<div class="form-group">
			<NcTextField
				id="resource-skills"
				:label="t('pipelinq', 'Skills (comma-separated)')"
				:modelValue="skillsCsv"
				@update:modelValue="onSkillsInput" />
		</div>

		<div class="form-row">
			<div class="form-group">
				<NcTextField
					id="resource-user-id"
					:label="t('pipelinq', 'Nextcloud user ID (staff only)')"
					:modelValue="form.userId || ''"
					@update:modelValue="(v) => (form.userId = v)" />
			</div>
			<div class="form-group">
				<NcTextField
					id="resource-calendar-sync"
					:label="t('pipelinq', 'Calendar sync link (UUID)')"
					:modelValue="form.calendarSyncId || ''"
					@update:modelValue="(v) => (form.calendarSyncId = v)" />
			</div>
		</div>

		<div class="form-group">
			<label>{{ t('pipelinq', 'Working hours') }}</label>
			<table class="hours-table">
				<thead>
					<tr>
						<th scope="col">{{ t('pipelinq', 'Day') }}</th>
						<th scope="col">{{ t('pipelinq', 'Open') }}</th>
						<th scope="col">{{ t('pipelinq', 'Close') }}</th>
						<th scope="col" />
					</tr>
				</thead>
				<tbody>
					<tr v-for="(row, idx) in form.workingHours" :key="idx">
						<td>
							<NcSelect
								v-model="row.day"
								:inputId="`hours-day-${idx}`"
								:aria-label-combobox="t('pipelinq', 'Day')"
								:options="dayOptions"
								:reduce="(o) => o.value"
								label="label" />
						</td>
						<td>
							<input
								:id="`hours-open-${idx}`"
								v-model="row.openTime"
								type="time"
								:aria-label="t('pipelinq', 'Open time')" />
						</td>
						<td>
							<input
								:id="`hours-close-${idx}`"
								v-model="row.closeTime"
								type="time"
								:aria-label="t('pipelinq', 'Close time')" />
						</td>
						<td>
							<NcButton variant="tertiary" @click="removeHours(idx)">
								&times;
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<NcButton variant="secondary" class="add-btn" @click="addHours">
				{{ t('pipelinq', 'Add working hours row') }}
			</NcButton>
			<p v-if="hoursError" class="error-text">
				{{ hoursError }}
			</p>
		</div>

		<div class="form-group">
			<label>{{ t('pipelinq', 'Vacations / unavailable windows') }}</label>
			<table class="hours-table">
				<thead>
					<tr>
						<th scope="col">{{ t('pipelinq', 'Start date') }}</th>
						<th scope="col">{{ t('pipelinq', 'End date') }}</th>
						<th scope="col">{{ t('pipelinq', 'Label') }}</th>
						<th scope="col" />
					</tr>
				</thead>
				<tbody>
					<tr v-for="(row, idx) in form.vacations" :key="idx">
						<td>
							<input
								:id="`vac-start-${idx}`"
								v-model="row.startDate"
								type="date"
								:aria-label="t('pipelinq', 'Vacation start date')" />
						</td>
						<td>
							<input
								:id="`vac-end-${idx}`"
								v-model="row.endDate"
								type="date"
								:aria-label="t('pipelinq', 'Vacation end date')" />
						</td>
						<td>
							<input
								:id="`vac-label-${idx}`"
								v-model="row.label"
								type="text"
								:aria-label="t('pipelinq', 'Vacation label')" />
						</td>
						<td>
							<NcButton
								variant="tertiary"
								@click="removeVacation(idx)">
								&times;
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<NcButton variant="secondary" class="add-btn" @click="addVacation">
				{{ t('pipelinq', 'Add vacation') }}
			</NcButton>
			<p v-if="vacationError" class="error-text">
				{{ vacationError }}
			</p>
		</div>

		<div class="resource-form__actions">
			<NcButton
				variant="primary"
				:disabled="!isValid"
				data-testid="resource-form-save"
				@click="onSave">
				{{ t('pipelinq', 'Save') }}
			</NcButton>
			<NcButton data-testid="resource-form-cancel" @click="$emit('cancel')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'

const DAYS = [
	'monday',
	'tuesday',
	'wednesday',
	'thursday',
	'friday',
	'saturday',
	'sunday',
]

export default {
	name: 'ResourceForm',
	components: { NcButton, NcSelect, NcTextField },
	props: {
		resource: { type: Object, default: () => ({}) },
	},

	emits: ['save', 'cancel'],
	data() {
		return {
			form: this.emptyForm(),
			errors: { name: '' },
		}
	},

	computed: {
		isValid() {
			const hasName = (this.form.name || '').trim().length > 0
			const hasType = !!this.form.type
			const noErrors = Object.values(this.errors).every((e) => !e)
			return (
				hasName
				&& hasType
				&& noErrors
				&& !this.hoursError
				&& !this.vacationError
			)
		},

		skillsCsv() {
			return (this.form.skills || []).join(', ')
		},

		typeOptions() {
			return [
				{ value: 'staff', label: t('pipelinq', 'Staff') },
				{ value: 'room', label: t('pipelinq', 'Room') },
				{ value: 'equipment', label: t('pipelinq', 'Equipment') },
			]
		},

		statusOptions() {
			return [
				{ value: 'active', label: t('pipelinq', 'Active') },
				{ value: 'inactive', label: t('pipelinq', 'Inactive') },
				{ value: 'archived', label: t('pipelinq', 'Archived') },
			]
		},

		dayOptions() {
			return DAYS.map((d) => ({ value: d, label: t('pipelinq', d) }))
		},

		/**
		 * @return {string} Error text when any workingHours row has openTime
		 *  >= closeTime, empty otherwise.
		 *
		 * @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
		 */
		hoursError() {
			const bad = (this.form.workingHours || []).find((r) => {
				if (!r.openTime || !r.closeTime) return false
				return r.openTime >= r.closeTime
			})
			return bad ? t('pipelinq', 'Open time must be before close time.') : ''
		},

		/**
		 * @return {string} Error text when any vacations row has startDate
		 *  > endDate, empty otherwise.
		 */
		vacationError() {
			const bad = (this.form.vacations || []).find((r) => {
				if (!r.startDate || !r.endDate) return false
				return r.startDate > r.endDate
			})
			return bad
				? t(
						'pipelinq',
						'Vacation start date must be on or before the end date.',
					)
				: ''
		},
	},

	watch: {
		resource: {
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
				type: 'staff',
				skills: [],
				workingHours: [],
				vacations: [],
				calendarSyncId: '',
				userId: '',
				bookable: true,
				maxConcurrent: 1,
				status: 'active',
			}
		},

		populateForm(data) {
			this.form = {
				...this.emptyForm(),
				...data,
				skills: Array.isArray(data.skills) ? [...data.skills] : [],
				workingHours: Array.isArray(data.workingHours)
					? data.workingHours.map((r) => ({ ...r }))
					: [],

				vacations: Array.isArray(data.vacations)
					? data.vacations.map((r) => ({ ...r }))
					: [],
			}
			this.errors = { name: '' }
		},

		validateField(field) {
			if (field === 'name') {
				this.errors.name = String(this.form.name || '').trim()
					? ''
					: t('pipelinq', 'Name is required')
			}
		},

		validateAll() {
			this.validateField('name')
			return this.isValid
		},

		onSkillsInput(value) {
			this.form.skills = (value || '')
				.split(',')
				.map((s) => s.trim())
				.filter((s) => s.length > 0)
		},

		addHours() {
			this.form.workingHours.push({
				day: 'monday',
				openTime: '09:00',
				closeTime: '17:00',
			})
		},

		removeHours(idx) {
			this.form.workingHours.splice(idx, 1)
		},

		addVacation() {
			this.form.vacations.push({ startDate: '', endDate: '', label: '' })
		},

		removeVacation(idx) {
			this.form.vacations.splice(idx, 1)
		},

		onSave() {
			if (!this.validateAll()) {
				return
			}
			const data = { ...this.form }
			data.skills = [...(this.form.skills || [])]
			data.workingHours = (this.form.workingHours || []).map((r) => ({
				day: r.day,
				openTime: r.openTime,
				closeTime: r.closeTime,
			}))
			data.vacations = (this.form.vacations || []).map((r) => ({
				startDate: r.startDate,
				endDate: r.endDate,
				label: r.label || '',
			}))
			if (this.resource?.id) {
				data.id = this.resource.id
			}
			this.$emit('save', data)
		},
	},
}
</script>

<style scoped>
.resource-form {
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

.hours-table {
	width: 100%;
	border-collapse: collapse;
}

.hours-table th,
.hours-table td {
	padding: 6px;
	border-bottom: 1px solid var(--color-border);
}

.hours-table input[type='time'],
.hours-table input[type='date'],
.hours-table input[type='text'] {
	width: 100%;
	padding: 4px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.add-btn {
	margin-top: 8px;
}

.error-text {
	color: var(--color-error);
	margin-top: 6px;
	font-size: 13px;
}

.resource-form__actions {
	display: flex;
	gap: 12px;
	margin-top: 24px;
}
</style>
