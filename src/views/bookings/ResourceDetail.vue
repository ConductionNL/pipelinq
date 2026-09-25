<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Resource detail + edit page — appointment-booking member 11.

  View mode renders headline info, the weekly working-hours grid and the
  vacation list. Edit opens the schema-driven CnFormDialog, with the
  working-hours and vacation editors in its field slots; creating a resource
  (id "new") still uses the full-page ResourceForm. On save we delegate to the
  ObjectService and best-effort invalidate this resource's availabilityCache
  rows (REQ-APT-002).

  @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
-->
<template>
	<div v-if="isNew">
		<div class="resource-detail__header">
			<NcButton @click="onFormCancel">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2>
				{{ t('pipelinq', 'New resource') }}
			</h2>
		</div>
		<ResourceForm
			:resource="resourceData"
			@save="onFormSave"
			@cancel="onFormCancel" />
	</div>

	<CnDetailPage
		v-else
		:title="resourceData.name || t('pipelinq', 'Resource')"
		:subtitle="t('pipelinq', 'Resource')"
		:backRoute="{ name: 'Resources' }"
		:backLabel="t('pipelinq', 'Back to list')"
		:loading="loading"
		:sidebar="{ enabled: !isNew && !loading }"
		objectType="pipelinq_resource"
		:objectId="resourceId"
		:sidebarProps="sidebarProps">
		<template #actions>
			<NcButton variant="primary" @click="openEditDialog">
				{{ t('pipelinq', 'Edit') }}
			</NcButton>
			<NcButton variant="error" @click="showDelete = true">
				{{ t('pipelinq', 'Delete') }}
			</NcButton>
		</template>

		<CnDetailCard :title="t('pipelinq', 'Resource information')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Name') }}</label>
					<span>{{ resourceData.name || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Type') }}</label>
					<span>{{ resourceData.type || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Status') }}</label>
					<span>{{ resourceData.status || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Bookable') }}</label>
					<span>{{
						resourceData.bookable
							? t('pipelinq', 'Yes')
							: t('pipelinq', 'No')
					}}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Max concurrent') }}</label>
					<span>{{ resourceData.maxConcurrent || 1 }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Skills') }}</label>
					<span>{{ skillsLabel }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Nextcloud user') }}</label>
					<span>{{ resourceData.userId || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Calendar sync link') }}</label>
					<span>{{ resourceData.calendarSyncId || '-' }}</span>
				</div>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Working hours')">
			<div v-if="!workingHours.length" class="section-empty">
				<p>{{ t('pipelinq', 'No working hours configured.') }}</p>
			</div>
			<div v-else class="viewTableContainer">
				<table class="viewTable">
					<thead>
						<tr>
							<th scope="col">{{ t('pipelinq', 'Day') }}</th>
							<th scope="col">{{ t('pipelinq', 'Open') }}</th>
							<th scope="col">{{ t('pipelinq', 'Close') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(row, idx) in workingHours" :key="idx">
							<td>{{ t('pipelinq', row.day || '-') }}</td>
							<td>{{ row.openTime || '-' }}</td>
							<td>{{ row.closeTime || '-' }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Vacations')">
			<div v-if="!vacations.length" class="section-empty">
				<p>{{ t('pipelinq', 'No vacations recorded.') }}</p>
			</div>
			<div v-else class="viewTableContainer">
				<table class="viewTable">
					<thead>
						<tr>
							<th scope="col">{{ t('pipelinq', 'Start') }}</th>
							<th scope="col">{{ t('pipelinq', 'End') }}</th>
							<th scope="col">{{ t('pipelinq', 'Label') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(row, idx) in vacations" :key="idx">
							<td>{{ row.startDate || '-' }}</td>
							<td>{{ row.endDate || '-' }}</td>
							<td>{{ row.label || '-' }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</CnDetailCard>

		<DeleteResourceDialog
			v-if="showDelete"
			:name="resourceData.name"
			@confirm="confirmDelete"
			@cancel="showDelete = false" />

		<CnFormDialog
			v-if="showEditDialog && resourceSchema"
			ref="editDialog"
			:schema="resourceSchema"
			:item="resourceData"
			:dialogTitle="t('pipelinq', 'Edit resource')"
			:fieldOverrides="editFieldOverrides"
			size="large"
			:columns="2"
			@confirm="onEditConfirm"
			@close="showEditDialog = false">
			<!-- The schema form cannot edit arrays of objects. -->
			<template #field-workingHours="{ value, error, updateField }">
				<ResourceHoursEditor
					:modelValue="value || []"
					:error="error || ''"
					@update:modelValue="(rows) => updateField('workingHours', rows)" />
			</template>
			<template #field-vacations="{ value, error, updateField }">
				<ResourceVacationsEditor
					:modelValue="value || []"
					:error="error || ''"
					@update:modelValue="(rows) => updateField('vacations', rows)" />
			</template>
		</CnFormDialog>
	</CnDetailPage>
</template>

<script>
import {
	CnDetailCard,
	CnDetailPage,
	CnFormDialog,
	useObjectSubscription,
} from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton } from '@nextcloud/vue'
import { computed } from 'vue'
import ResourceHoursEditor from '../../components/bookings/ResourceHoursEditor.vue'
import ResourceVacationsEditor from '../../components/bookings/ResourceVacationsEditor.vue'
import DeleteResourceDialog from '../../dialogs/DeleteResourceDialog.vue'
import ResourceForm from './ResourceForm.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { vacationsError, workingHoursError } from '../../utils/resourceValidation.js'

export default {
	name: 'ResourceDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		CnFormDialog,
		ResourceForm,
		ResourceHoursEditor,
		ResourceVacationsEditor,
		DeleteResourceDialog,
	},

	props: {
		id: { type: String, default: null },
	},

	/**
	 * Live updates for the viewed resource (or-object-{uuid} via the
	 * nc-vue liveUpdatesPlugin, default-on since beta.212). Events are
	 * refetch hints — the plugin re-runs fetchObject('appointmentResource', id)
	 * into the same store cache resourceData renders from. Re-scopes on
	 * id change, releases on unmount, skips the create archetype.
	 *
	 * @param {object} props Component props
	 * @return {object} Empty — the subscription is side-effect only
	 * @spec openspec/specs/realtime-updates-ui/spec.md
	 */
	setup(props) {
		const objectStore = useObjectStore()
		const liveObjectId = computed(() =>
			props.id && props.id !== 'new' ? props.id : null,
		)
		useObjectSubscription(objectStore, 'appointmentResource', liveObjectId, {
			enabled: computed(() =>
				Boolean(
					liveObjectId.value && objectStore.objectTypeRegistry?.resource,
				),
			),
		})
		return {}
	},

	data() {
		return {
			showEditDialog: false,
			showDelete: false,
		}
	},

	computed: {
		objectStore() {
			return useObjectStore()
		},

		resourceId() {
			return this.id || null
		},

		isNew() {
			return !this.resourceId || this.resourceId === 'new'
		},

		loading() {
			return this.objectStore.loading?.resource || false
		},

		resourceData() {
			if (this.isNew) return {}
			return (
				this.objectStore.getObject('appointmentResource', this.resourceId)
				|| {}
			)
		},

		workingHours() {
			return Array.isArray(this.resourceData.workingHours)
				? this.resourceData.workingHours
				: []
		},

		vacations() {
			return Array.isArray(this.resourceData.vacations)
				? this.resourceData.vacations
				: []
		},

		skillsLabel() {
			const skills = this.resourceData.skills || []
			return skills.length ? skills.join(', ') : '-'
		},

		resourceSchema() {
			return this.objectStore.getSchema('appointmentResource')
		},

		/**
		 * Translated labels and order for the edit dialog's schema fields, as
		 * ResourceForm had them. The schema's English descriptions are cleared
		 * because they are not translated.
		 *
		 * @return {object} fieldOverrides for CnFormDialog.
		 */
		editFieldOverrides() {
			const overrides = {
				name: { label: t('pipelinq', 'Name'), order: 1 },
				type: {
					label: t('pipelinq', 'Type'),
					order: 2,
					enumLabels: {
						staff: t('pipelinq', 'Staff'),
						room: t('pipelinq', 'Room'),
						equipment: t('pipelinq', 'Equipment'),
					},
				},

				status: {
					label: t('pipelinq', 'Status'),
					order: 3,
					enumLabels: {
						active: t('pipelinq', 'Active'),
						inactive: t('pipelinq', 'Inactive'),
						archived: t('pipelinq', 'Archived'),
					},
				},

				maxConcurrent: { label: t('pipelinq', 'Max concurrent bookings'), order: 4 },
				bookable: { label: t('pipelinq', 'Bookable'), order: 5 },
				skills: { label: t('pipelinq', 'Skills'), order: 6 },
				userId: { label: t('pipelinq', 'Nextcloud user ID (staff only)'), order: 7 },
				calendarSyncId: { label: t('pipelinq', 'Calendar sync link (UUID)'), order: 8 },
				workingHours: { label: t('pipelinq', 'Working hours'), order: 9, widget: 'json' },
				vacations: { label: t('pipelinq', 'Vacations / unavailable windows'), order: 10, widget: 'json' },
			}
			for (const key of Object.keys(overrides)) {
				overrides[key].description = ''
				overrides[key].descriptionLong = ''
			}
			return overrides
		},

		sidebarProps() {
			const cfg = this.objectStore.objectTypeRegistry?.resource || {}
			return {
				title: t('pipelinq', 'Resource'),
				register: cfg.register || '',
				schema: cfg.schema || '',
				hiddenTabs: ['tasks'],
			}
		},
	},

	async mounted() {
		if (!this.isNew) {
			await this.objectStore.fetchObject(
				'appointmentResource',
				this.resourceId,
			)
		}
	},

	methods: {
		async openEditDialog() {
			await this.objectStore.fetchSchema('appointmentResource')
			if (!this.resourceSchema) {
				showError(t('pipelinq', 'Could not load the resource form.'))
				return
			}
			this.showEditDialog = true
		},

		/**
		 * Check the row rules, save the edit dialog's data and report the
		 * outcome back to it. A rule violation keeps the dialog open on the
		 * offending field.
		 *
		 * @param {object} formData The dialog's form data.
		 */
		async onEditConfirm(formData) {
			const dialog = this.$refs.editDialog
			const fieldErrors = {}
			const hoursError = workingHoursError(formData.workingHours)
			const vacationError = vacationsError(formData.vacations)
			if (hoursError) {
				fieldErrors.workingHours = hoursError
			}
			if (vacationError) {
				fieldErrors.vacations = vacationError
			}
			if (Object.keys(fieldErrors).length > 0) {
				dialog?.setValidationErrors(fieldErrors)
				return
			}

			const saved = await this.objectStore.saveObject('appointmentResource', {
				...formData,
				id: this.resourceId,
				workingHours: (formData.workingHours || []).map((r) => ({
					day: r.day,
					openTime: r.openTime,
					closeTime: r.closeTime,
				})),
				vacations: (formData.vacations || []).map((r) => ({
					startDate: r.startDate,
					endDate: r.endDate,
					label: r.label || '',
				})),
			})
			if (!saved) {
				const error = this.objectStore.getError?.('appointmentResource')
				dialog?.setResult({ error: error?.message || t('pipelinq', 'Failed to save resource.') })
				return
			}
			dialog?.setResult({ success: true })
			await this.invalidateAvailability(saved.id || this.resourceId)
			await this.objectStore.fetchObject('appointmentResource', this.resourceId)
		},

		async onFormSave(formData) {
			const saved = await this.objectStore.saveObject(
				'appointmentResource',
				formData,
			)
			if (!saved) {
				const error = this.objectStore.getError?.('appointmentResource')
				showError(
					error?.message || t('pipelinq', 'Failed to save resource.'),
				)
				return
			}
			showSuccess(t('pipelinq', 'Resource saved.'))
			await this.invalidateAvailability(saved.id || formData.id)
			this.$router.push({
				name: 'ResourceDetail',
				params: { id: saved.id },
			})
		},

		onFormCancel() {
			this.$router.push({ name: 'Resources' })
		},

		async confirmDelete() {
			this.showDelete = false
			const ok = await this.objectStore.deleteObject(
				'appointmentResource',
				this.resourceId,
			)
			if (ok) {
				this.$router.push({ name: 'Resources' })
			} else {
				const error = this.objectStore.getError?.('appointmentResource')
				showError(
					error?.message || t('pipelinq', 'Failed to delete resource.'),
				)
			}
		},

		/**
		 * Best-effort invalidation of this resource's availability cache rows.
		 *
		 * @param {string} resourceId The resource UUID.
		 * @return {Promise<void>}
		 */
		async invalidateAvailability(resourceId) {
			if (!resourceId) return
			try {
				const cached = await this.objectStore.fetchCollection(
					'availabilityCache',
					{
						resourceId,
						_limit: 200,
					},
				)
				for (const row of cached || []) {
					try {
						await this.objectStore.deleteObject(
							'availabilityCache',
							row.id,
						)
					} catch {
						// per-row failure tolerated
					}
				}
			} catch {
				// list failure tolerated
			}
		},
	},
}
</script>

<style scoped>
.resource-detail__header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 20px;
	padding: 20px 20px 0;
}

.info-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.info-field {
	margin-bottom: 8px;
}

.info-field label {
	display: block;
	font-weight: bold;
	margin-bottom: 2px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.viewTableContainer {
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	overflow: hidden;
	box-shadow: 0 2px 4px var(--color-box-shadow);
	border: 1px solid var(--color-border);
}

.viewTable {
	width: 100%;
	border-collapse: collapse;
}

.viewTable th,
.viewTable td {
	padding: 12px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.viewTable th {
	background-color: var(--color-background-dark);
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.section-empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 20px;
}
</style>
