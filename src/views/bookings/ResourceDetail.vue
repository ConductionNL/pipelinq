<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Resource detail + edit page — appointment-booking member 11.

  View mode renders headline info, the weekly working-hours grid and the
  vacation list. Edit mode wraps ResourceForm. On save we delegate to the
  ObjectService and best-effort invalidate this resource's
  availabilityCache rows (REQ-APT-002).

  @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
-->
<template>
	<div v-if="editing || isNew">
		<div class="resource-detail__header">
			<NcButton @click="onFormCancel">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2 v-if="isNew">
				{{ t('pipelinq', 'New resource') }}
			</h2>
			<h2 v-else>
				{{ resourceData.name || t('pipelinq', 'Resource') }}
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
		:back-route="{ name: 'Resources' }"
		:back-label="t('pipelinq', 'Back to list')"
		:loading="loading"
		:sidebar="{ enabled: !isNew && !loading }"
		object-type="pipelinq_resource"
		:object-id="resourceId"
		:sidebar-props="sidebarProps">
		<template #actions>
			<NcButton variant="primary" @click="editing = true">
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
	</CnDetailPage>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { computed } from 'vue'
import {
	CnDetailPage,
	CnDetailCard,
	useObjectSubscription,
} from '@conduction/nextcloud-vue'
import ResourceForm from './ResourceForm.vue'
import DeleteResourceDialog from '../../dialogs/DeleteResourceDialog.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ResourceDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		ResourceForm,
		DeleteResourceDialog,
	},
	props: {
		id: { type: String, default: null },
	},
	/**
	 * Live updates for the viewed resource (or-object-{uuid} via the
	 * nc-vue liveUpdatesPlugin, default-on since beta.212). Events are
	 * refetch hints — the plugin re-runs fetchObject('resource', id)
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
		useObjectSubscription(objectStore, 'resource', liveObjectId, {
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
			editing: false,
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
			return this.objectStore.getObject('resource', this.resourceId) || {}
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
			await this.objectStore.fetchObject('resource', this.resourceId)
		}
	},
	methods: {
		async onFormSave(formData) {
			const saved = await this.objectStore.saveObject('resource', formData)
			if (!saved) {
				const error = this.objectStore.getError?.('resource')
				showError(
					error?.message || t('pipelinq', 'Failed to save resource.'),
				)
				return
			}
			showSuccess(t('pipelinq', 'Resource saved.'))
			await this.invalidateAvailability(saved.id || formData.id)
			if (this.isNew) {
				this.$router.push({
					name: 'ResourceDetail',
					params: { id: saved.id },
				})
			} else {
				await this.objectStore.fetchObject('resource', this.resourceId)
				this.editing = false
			}
		},
		onFormCancel() {
			if (this.isNew) {
				this.$router.push({ name: 'Resources' })
			} else {
				this.editing = false
			}
		},
		async confirmDelete() {
			this.showDelete = false
			const ok = await this.objectStore.deleteObject(
				'resource',
				this.resourceId,
			)
			if (ok) {
				this.$router.push({ name: 'Resources' })
			} else {
				const error = this.objectStore.getError?.('resource')
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
	text-align: left;
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
