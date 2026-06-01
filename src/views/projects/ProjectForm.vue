<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->

<!--
  Inline project edit/create form.

  Emits save(formData) when submitted, cancel() when the user clicks cancel.

  @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.2
-->
<template>
	<div class="project-form">
		<div class="form-grid">
			<div class="form-field form-field--required">
				<label for="pf-name">{{ t('pipelinq', 'Naam') }} *</label>
				<input
					id="pf-name"
					v-model="form.name"
					type="text"
					class="form-input"
					:placeholder="t('pipelinq', 'Naam van het project')"
					required />
				<span v-if="errors.name" class="form-error">{{ errors.name }}</span>
			</div>

			<div class="form-field">
				<label for="pf-status">{{ t('pipelinq', 'Status') }}</label>
				<select id="pf-status" v-model="form.status" class="form-input">
					<option value="open">
						{{ t('pipelinq', 'Open') }}
					</option>
					<option value="in_progress">
						{{ t('pipelinq', 'In uitvoering') }}
					</option>
					<option value="on_hold">
						{{ t('pipelinq', 'In de wacht') }}
					</option>
					<option value="completed">
						{{ t('pipelinq', 'Voltooid') }}
					</option>
					<option value="cancelled">
						{{ t('pipelinq', 'Geannuleerd') }}
					</option>
				</select>
			</div>

			<div class="form-field form-field--full">
				<label for="pf-description">{{ t('pipelinq', 'Beschrijving') }}</label>
				<textarea
					id="pf-description"
					v-model="form.description"
					class="form-input"
					rows="3"
					:placeholder="t('pipelinq', 'Scope van het project')" />
			</div>

			<div class="form-field">
				<label for="pf-billable">{{ t('pipelinq', 'Factureerbaar') }}</label>
				<select id="pf-billable" v-model="form.billable" class="form-input">
					<option :value="true">
						{{ t('pipelinq', 'Ja') }}
					</option>
					<option :value="false">
						{{ t('pipelinq', 'Nee') }}
					</option>
				</select>
			</div>

			<div class="form-field">
				<label for="pf-hourly-rate">{{ t('pipelinq', 'Uurtarief (EUR)') }}</label>
				<input
					id="pf-hourly-rate"
					v-model.number="form.hourlyRate"
					type="number"
					class="form-input"
					min="0"
					step="0.01" />
			</div>

			<div class="form-field">
				<label for="pf-budget-hours">{{ t('pipelinq', 'Budget uren') }}</label>
				<input
					id="pf-budget-hours"
					v-model.number="form.budgetHours"
					type="number"
					class="form-input"
					min="0"
					step="0.5" />
			</div>

			<div class="form-field">
				<label for="pf-budget-amount">{{ t('pipelinq', 'Budget bedrag (EUR)') }}</label>
				<input
					id="pf-budget-amount"
					v-model.number="form.budgetAmount"
					type="number"
					class="form-input"
					min="0"
					step="0.01" />
			</div>

			<div class="form-field">
				<label for="pf-start-date">{{ t('pipelinq', 'Startdatum') }}</label>
				<input
					id="pf-start-date"
					v-model="form.startDate"
					type="date"
					class="form-input" />
			</div>

			<div class="form-field">
				<label for="pf-end-date">{{ t('pipelinq', 'Einddatum') }}</label>
				<input
					id="pf-end-date"
					v-model="form.endDate"
					type="date"
					class="form-input" />
			</div>

			<div class="form-field">
				<label for="pf-color">{{ t('pipelinq', 'Kleur') }}</label>
				<div class="color-row">
					<input
						id="pf-color"
						v-model="form.color"
						type="color"
						class="color-picker" />
					<input
						v-model="form.color"
						type="text"
						class="form-input"
						:placeholder="t('pipelinq', '#hexkleur')" />
				</div>
			</div>
		</div>

		<div class="form-actions">
			<NcButton @click="$emit('cancel')">
				{{ t('pipelinq', 'Annuleren') }}
			</NcButton>
			<NcButton type="primary" :disabled="!form.name" @click="onSave">
				{{ t('pipelinq', 'Opslaan') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'ProjectForm',

	components: {
		NcButton,
	},

	props: {
		/**
		 * Existing project data for editing (empty object for new project).
		 *
		 * @type {object}
		 */
		project: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['save', 'cancel'],

	data() {
		return {
			form: {
				name: this.project.name || '',
				description: this.project.description || '',
				status: this.project.status || 'open',
				billable: this.project.billable !== undefined ? this.project.billable : true,
				budgetHours: this.project.budgetHours || null,
				budgetAmount: this.project.budgetAmount || null,
				hourlyRate: this.project.hourlyRate || null,
				startDate: this.project.startDate || '',
				endDate: this.project.endDate || '',
				color: this.project.color || '',
				client: this.project.client || null,
			},
			errors: {},
		}
	},

	methods: {
		/**
		 * Validate and emit save event.
		 */
		onSave() {
			this.errors = {}
			if (!this.form.name) {
				this.errors.name = t('pipelinq', 'Naam is verplicht')
				return
			}
			const data = { ...this.form }
			// Preserve id if editing
			if (this.project.id) {
				data.id = this.project.id
			}
			// Clean up empty optional fields
			if (!data.budgetHours) delete data.budgetHours
			if (!data.budgetAmount) delete data.budgetAmount
			if (!data.hourlyRate) delete data.hourlyRate
			if (!data.startDate) delete data.startDate
			if (!data.endDate) delete data.endDate
			if (!data.color) delete data.color
			if (!data.client) delete data.client

			this.$emit('save', data)
		},
	},
}
</script>

<style scoped>
.project-form {
	padding: 20px;
}

.form-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.form-field {
	display: flex;
	flex-direction: column;
}

.form-field--full {
	grid-column: 1 / -1;
}

.form-field label {
	font-weight: 500;
	margin-bottom: 4px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.form-input {
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;
}

.form-input:focus {
	outline: none;
	border-color: var(--color-primary);
}

.form-error {
	color: #e53935;
	font-size: 12px;
	margin-top: 4px;
}

.color-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.color-picker {
	width: 40px;
	height: 38px;
	padding: 2px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
}

.form-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 24px;
}
</style>
