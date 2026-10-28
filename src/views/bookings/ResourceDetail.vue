<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Resource detail + edit page — appointment-booking member 11.

  View mode renders headline info, the weekly working-hours grid and the
  vacation list. Edit opens the schema-driven CnFormDialog, with the
  working-hours and vacation editors in its field slots; creating a resource
  (id "new") still uses the full-page ResourceForm. On save we delegate to the
  ObjectService; the server expires this resource's cached availability
  (AvailabilityCacheInvalidationListener, REQ-APT-002).

  @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
-->
<template>
	<div v-if="isNew">
		<div class="resource-detail__header">
			<NcButton :to="{ name: 'Resources' }">
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
		:breadcrumb="{ label: t('pipelinq', 'Resources'), route: 'Resources' }"
		:typePill="typePill"
		:statusPill="statusPill"
		:headerMeta="headerMeta"
		:loading="loading"
		:sidebar="{ enabled: !isNew && !loading }"
		objectType="appointmentResource"
		:objectStore="objectStore"
		:objectId="resourceId"
		:follow="false"
		:sidebarProps="sidebarProps"
		:sideColumn="sideColumn"
		:headerActions="headerActions">
		<template #actions>
			<NcButton variant="secondary" @click="openEditDialog">
				<template #icon>
					<Pencil :size="20" />
				</template>
				{{ t('pipelinq', 'Edit') }}
			</NcButton>
		</template>

		<template #widget-resource-week>
			<CnDetailCard :title="t('pipelinq', 'This week')">
				<KpiFigure
					:value="weekLoad.count"
					:caption="weekCaption" />
			</CnDetailCard>
		</template>

		<CnTabs card :ariaLabel="t('pipelinq', 'Resource')">
			<CnTab :title="t('pipelinq', 'Overview')">
		<CnDetailCard :title="t('pipelinq', 'Resource details')">
			<div class="resource-facts">
				<div class="resource-facts__item">
					<span class="resource-facts__label">{{ t('pipelinq', 'Name') }}</span>
					<span class="resource-facts__value">{{ resourceData.name || '-' }}</span>
				</div>
				<div class="resource-facts__item">
					<span class="resource-facts__label">{{ t('pipelinq', 'Type') }}</span>
					<span class="resource-facts__value">{{ typeLabel }}</span>
				</div>
				<div class="resource-facts__item">
					<span class="resource-facts__label">{{ t('pipelinq', 'Status') }}</span>
					<span class="resource-facts__value">
						<CnStatusBadge
							:label="statusLabel"
							:variant="resourceData.status === 'active' ? 'success' : 'default'"
							size="small" />
					</span>
				</div>
				<div class="resource-facts__item">
					<span class="resource-facts__label">{{ t('pipelinq', 'Bookable') }}</span>
					<span class="resource-facts__value">
						{{
							resourceData.bookable
								? t('pipelinq', 'Yes')
								: t('pipelinq', 'No')
						}}
					</span>
				</div>
				<div class="resource-facts__item">
					<span class="resource-facts__label">{{ t('pipelinq', 'Max concurrent') }}</span>
					<span class="resource-facts__value">{{ resourceData.maxConcurrent || 1 }}</span>
				</div>
				<div class="resource-facts__item">
					<span class="resource-facts__label">{{ t('pipelinq', 'Skills') }}</span>
					<span class="resource-facts__value">{{ skillsLabel }}</span>
				</div>
				<div class="resource-facts__item">
					<span class="resource-facts__label">{{ t('pipelinq', 'Nextcloud user') }}</span>
					<span class="resource-facts__value">{{ resourceData.userId || '-' }}</span>
				</div>
				<div class="resource-facts__item">
					<span class="resource-facts__label">{{ t('pipelinq', 'Calendar sync link') }}</span>
					<span class="resource-facts__value">{{ resourceData.calendarSyncId || '-' }}</span>
				</div>
			</div>
		</CnDetailCard>

		<div class="resource-schedule">
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
								<td>{{ dayLabel(row.day) }}</td>
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
								<th scope="col">{{ t('pipelinq', 'Begin') }}</th>
								<th scope="col">{{ t('pipelinq', 'End') }}</th>
								<th scope="col">{{ t('pipelinq', 'Label') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="(row, idx) in vacations" :key="idx">
								<td>{{ shortDate(row.startDate) }}</td>
								<td>{{ shortDate(row.endDate) }}</td>
								<td>{{ row.label || '-' }}</td>
							</tr>
						</tbody>
					</table>
				</div>
				<p class="resource-schedule__note">
					{{ t('pipelinq', 'This resource cannot be booked in these periods.') }}
				</p>
			</CnDetailCard>
		</div>
			</CnTab>
			<CnTab :title="t('pipelinq', 'History')" lazy>
				<CnAuditTrailCard register="pipelinq" schema="appointmentResource" :objectId="resourceId" />
			</CnTab>
		</CnTabs>

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
					@update:modelValue="
						(rows) => updateField('workingHours', rows)
					" />
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
	CnAuditTrailCard,
	CnDetailCard,
	CnDetailPage,
	CnFormDialog,
	CnStatusBadge,
	CnTab,
	CnTabs,
	useObjectSubscription,
} from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import { computed } from 'vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ResourceHoursEditor from '../../components/bookings/ResourceHoursEditor.vue'
import ResourceVacationsEditor from '../../components/bookings/ResourceVacationsEditor.vue'
import KpiFigure from '../../components/KpiFigure.vue'
import DeleteResourceDialog from '../../dialogs/DeleteResourceDialog.vue'
import ResourceForm from './ResourceForm.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { vacationsError, workingHoursError } from '../../utils/resourceValidation.js'
import { resourceWeekLoad, weekBounds } from '../../utils/resourceWeek.js'

export default {
	name: 'ResourceDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		CnFormDialog,
		CnAuditTrailCard,
		CnStatusBadge,
		CnTab,
		CnTabs,
		Pencil,
		ResourceForm,
		ResourceHoursEditor,
		ResourceVacationsEditor,
		DeleteResourceDialog,
		KpiFigure,
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
			weekBookings: [],
			staffLine: { function: '', department: '' },
		}
	},

	computed: {
		/**
		 * Delete sits in the header's More menu, as the board draws it (Edit,
		 * the buildiq square, More); it opens the same confirm dialog.
		 *
		 * @return {Array<object>} The menu entries.
		 * @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
		 */
		headerActions() {
			return [{
				id: 'delete',
				label: t('pipelinq', 'Delete'),
				onSelect: () => {
					this.showDelete = true
				},
			}]
		},

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

		/**
		 * The line under the name: the linked colleague's function and
		 * department from their Nextcloud profile ("Woo-adviseur · Woo en
		 * informatie"); empty for a resource without a user.
		 *
		 * @return {string}
		 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
		 */
		headerMeta() {
			return [this.staffLine.function, this.staffLine.department]
				.filter((part) => typeof part === 'string' && part.trim() !== '')
				.join(' · ')
		},

		/**
		 * The side card the PqResource board draws beside the tabs.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
		 */
		sideColumn() {
			return [{ id: 'resource-week', type: 'custom', title: t('pipelinq', 'This week') }]
		},

		/**
		 * This week's bookings and their share of the bookable time.
		 *
		 * @return {{count: number, percent: (number|null)}}
		 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
		 */
		weekLoad() {
			return resourceWeekLoad({
				bookings: this.weekBookings,
				resourceId: this.resourceId,
				workingHours: this.workingHours,
				vacations: this.vacations,
				now: new Date(),
			})
		},

		/**
		 * The line under the week's count: "bookings, 74% of the bookable time".
		 *
		 * @return {string}
		 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
		 */
		weekCaption() {
			if (this.weekLoad.percent === null) {
				return t('pipelinq', 'bookings, no bookable time this week')
			}
			return t('pipelinq', 'bookings, {percent}% of the bookable time', { percent: this.weekLoad.percent })
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

		/**
		 * The resource type as its label ("Staff").
		 *
		 * @return {string}
		 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
		 */
		typeLabel() {
			const labels = { staff: t('pipelinq', 'Staff'), room: t('pipelinq', 'Room'), equipment: t('pipelinq', 'Equipment') }
			return labels[this.resourceData.type] || this.resourceData.type || '-'
		},

		/**
		 * The status as its label ("Active").
		 *
		 * @return {string}
		 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
		 */
		statusLabel() {
			const labels = { active: t('pipelinq', 'Active'), inactive: t('pipelinq', 'Inactive'), archived: t('pipelinq', 'Archived') }
			return labels[this.resourceData.status] || this.resourceData.status || '-'
		},

		/**
		 * The type pill above the name, as the PqResource board draws it.
		 *
		 * @return {object}
		 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
		 */
		typePill() {
			return { field: 'type', variant: 'info', labels: { staff: t('pipelinq', 'Staff'), room: t('pipelinq', 'Room'), equipment: t('pipelinq', 'Equipment') } }
		},

		/**
		 * The status pill beside the type.
		 *
		 * @return {object}
		 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
		 */
		statusPill() {
			return {
				field: 'status',
				colorMap: { active: 'success', inactive: 'default', archived: 'default' },
				labels: { active: t('pipelinq', 'Active'), inactive: t('pipelinq', 'Inactive'), archived: t('pipelinq', 'Archived') },
			}
		},

		skillsLabel() {
			const skills = this.resourceData.skills || []
			return skills.length ? skills.join(', ') : '-'
		},

		/**
		 * The resource schema, once fetched, for the edit dialog.
		 *
		 * @return {object|null}
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		resourceSchema() {
			return this.objectStore.getSchema('appointmentResource')
		},

		/**
		 * Translated labels and order for the edit dialog's schema fields, as
		 * ResourceForm had them. The schema's English descriptions are cleared
		 * because they are not translated.
		 *
		 * @return {object} fieldOverrides for CnFormDialog.
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
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

				maxConcurrent: {
					label: t('pipelinq', 'Max concurrent bookings'),
					order: 4,
				},

				bookable: { label: t('pipelinq', 'Bookable'), order: 5 },
				skills: { label: t('pipelinq', 'Skills'), order: 6 },
				userId: {
					label: t('pipelinq', 'Nextcloud user ID (staff only)'),
					order: 7,
				},

				calendarSyncId: {
					label: t('pipelinq', 'Calendar sync link (UUID)'),
					order: 8,
				},

				workingHours: {
					label: t('pipelinq', 'Working hours'),
					order: 9,
					widget: 'json',
				},

				vacations: {
					label: t('pipelinq', 'Vacations / unavailable windows'),
					order: 10,
					widget: 'json',
				},
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
			await Promise.all([this.fetchWeekBookings(), this.fetchStaffLine()])
		}
	},

	methods: {
		/**
		 * Read the linked colleague's function and department. A resource
		 * without a user, or a failed read, leaves the line empty.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
		 */
		async fetchStaffLine() {
			const userId = this.resourceData.userId
			if (typeof userId !== 'string' || userId === '') {
				return
			}
			try {
				const { data } = await axios.get(generateUrl('/apps/pipelinq/api/staff/{userId}/profile', { userId }))
				this.staffLine = { function: data?.function || '', department: data?.department || '' }
			} catch {
				this.staffLine = { function: '', department: '' }
			}
		},

		/**
		 * Load this week's bookings for the "This week" card. A failed load
		 * leaves the card at zero rather than blanking the page.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
		 */
		async fetchWeekBookings() {
			const { start, end } = weekBounds(new Date())
			try {
				const rows = await this.objectStore.fetchCollection('appointmentBooking', {
					'startAt[gte]': start.toISOString(),
					'startAt[lt]': end.toISOString(),
					_limit: 500,
				})
				this.weekBookings = Array.isArray(rows) ? rows : []
			} catch {
				this.weekBookings = []
			}
		},

		/**
		 * A stored weekday ("tuesday") as its label ("Dinsdag").
		 *
		 * @param {string} day The stored day.
		 * @return {string} The label.
		 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
		 */
		dayLabel(day) {
			const word = String(day || '')
			if (word === '') {
				return '-'
			}
			return t('pipelinq', word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
		},

		/**
		 * A stored date ("2026-10-19") as a short date ("19 okt 2026").
		 *
		 * @param {string} value The stored date.
		 * @return {string} The date, or '-' when empty.
		 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
		 */
		shortDate(value) {
			const date = new Date(String(value || ''))
			if (!value || Number.isNaN(date.getTime())) {
				return value || '-'
			}
			return new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' }).format(date)
		},

		/**
		 * Fetch the resource schema and open the edit dialog on it.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
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
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
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
				dialog?.setResult({
					error:
						error?.message || t('pipelinq', 'Failed to save resource.'),
				})
				return
			}
			dialog?.setResult({ success: true })
			await this.objectStore.fetchObject(
				'appointmentResource',
				this.resourceId,
			)
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

.resource-facts {
	display: grid;
	grid-template-columns: repeat(3, minmax(0, 1fr));
	gap: 0 24px;
}

.resource-facts__item {
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.resource-facts__label {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.resource-facts__value {
	display: block;
	font-weight: 600;
}

.resource-schedule {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
	gap: 20px;
}

.resource-schedule__note {
	margin: 12px 0 0;
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
