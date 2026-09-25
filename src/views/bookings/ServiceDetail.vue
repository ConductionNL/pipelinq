<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Service detail + edit page — appointment-booking member 11.

  View mode uses CnDetailPage with cards for the headline info, multi-step
  composition and policies. Edit opens the schema-driven CnFormDialog, with
  ServiceStepsEditor in its multiStep slot; creating a service (id "new")
  still uses the full-page ServiceForm.

  On save we delegate to the central ObjectService and then fire a best-effort
  availability-cache invalidation for every Resource that lists this service
  in its requiredResourceTypes (REQ-APT-015 scenario "AvailabilityCache MUST
  be invalidated"). Failures are non-fatal — the per-resource refresh job
  rebuilds the cache hourly.

  @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
  @spec openspec/specs/appointment-booking/spec.md
-->
<template>
	<div v-if="isNew">
		<div class="service-detail__header">
			<NcButton @click="onFormCancel">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2>
				{{ t('pipelinq', 'New service') }}
			</h2>
		</div>
		<ServiceForm
			:service="serviceData"
			@save="onFormSave"
			@cancel="onFormCancel" />
	</div>

	<CnDetailPage
		v-else
		:title="serviceData.name || t('pipelinq', 'Service')"
		:subtitle="t('pipelinq', 'Service')"
		:backRoute="{ name: 'Services' }"
		:backLabel="t('pipelinq', 'Back to list')"
		:loading="loading"
		:sidebar="{ enabled: !isNew && !loading }"
		objectType="pipelinq_service"
		:objectId="serviceId"
		:sidebarProps="sidebarProps">
		<template #actions>
			<NcButton variant="primary" @click="openEditDialog">
				{{ t('pipelinq', 'Edit') }}
			</NcButton>
			<NcButton variant="error" @click="showDelete = true">
				{{ t('pipelinq', 'Delete') }}
			</NcButton>
		</template>

		<CnDetailCard :title="t('pipelinq', 'Service information')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Name') }}</label>
					<span>{{ serviceData.name || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Status') }}</label>
					<span>{{ statusLabel(serviceData.status) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Duration') }}</label>
					<span>{{ formatDuration(serviceData.durationMinutes) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Price') }}</label>
					<span>{{
						formatCurrency(serviceData.price, serviceData.currency)
					}}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Buffer before / after') }}</label>
					<span
						>{{ serviceData.bufferBeforeMinutes || 0 }} /
						{{ serviceData.bufferAfterMinutes || 0 }} min</span
					>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Bookable online') }}</label>
					<span>{{
						serviceData.bookableOnline
							? t('pipelinq', 'Yes')
							: t('pipelinq', 'No')
					}}</span>
				</div>
			</div>
			<div v-if="serviceData.description" class="info-field info-field--full">
				<label>{{ t('pipelinq', 'Description') }}</label>
				<p>{{ serviceData.description }}</p>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Multi-step composition')">
			<div v-if="!steps.length" class="section-empty">
				<p>{{ t('pipelinq', 'Single-step service — no composition.') }}</p>
			</div>
			<div v-else class="viewTableContainer">
				<table class="viewTable">
					<thead>
						<tr>
							<th scope="col">{{ t('pipelinq', '#') }}</th>
							<th scope="col">{{ t('pipelinq', 'Duration') }}</th>
							<th scope="col">{{ t('pipelinq', 'Resource type') }}</th>
							<th scope="col">{{ t('pipelinq', 'Skill') }}</th>
							<th scope="col">{{ t('pipelinq', 'Allow gap') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(step, idx) in steps" :key="idx">
							<td>{{ idx + 1 }}</td>
							<td>{{ step.durationMinutes }} min</td>
							<td>{{ step.resourceType || '-' }}</td>
							<td>{{ step.skillRequired || '-' }}</td>
							<td>
								{{
									step.allowGap
										? t('pipelinq', 'Yes')
										: t('pipelinq', 'No')
								}}
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Policies')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Requires deposit') }}</label>
					<span>{{
						serviceData.requiresDeposit
							? t('pipelinq', 'Yes')
							: t('pipelinq', 'No')
					}}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Deposit amount') }}</label>
					<span>{{
						formatCurrency(
							serviceData.depositAmount,
							serviceData.currency,
						)
					}}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'No-show fee') }}</label>
					<span>{{
						formatCurrency(serviceData.noShowFee, serviceData.currency)
					}}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Cancellation policy') }}</label>
					<span>{{ serviceData.cancellationPolicy || 'free' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Cancellation window') }}</label>
					<span
						>{{ serviceData.cancellationHoursBefore || 0 }}
						{{ t('pipelinq', 'hours') }}</span
					>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Required skills') }}</label>
					<span>{{ requiredSkillsLabel }}</span>
				</div>
			</div>
		</CnDetailCard>

		<DeleteServiceDialog
			v-if="showDelete"
			:name="serviceData.name"
			@confirm="confirmDelete"
			@cancel="showDelete = false" />

		<CnFormDialog
			v-if="showEditDialog && serviceSchema"
			ref="editDialog"
			:schema="serviceSchema"
			:item="serviceData"
			:dialogTitle="t('pipelinq', 'Edit service')"
			:excludeFields="['requiredResourceTypes']"
			:fieldOverrides="editFieldOverrides"
			size="large"
			:columns="2"
			@confirm="onEditConfirm"
			@close="showEditDialog = false">
			<!-- The schema form cannot edit an array of step objects. The
			     duration is read off the dialog's live form data, with the
			     saved value until the ref is set. -->
			<template #field-multiStep="{ value, updateField }">
				<ServiceStepsEditor
					:modelValue="value || []"
					:durationMinutes="$refs.editDialog?.formData?.durationMinutes ?? serviceData.durationMinutes"
					@update:modelValue="(steps) => updateField('multiStep', steps)" />
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
import ServiceStepsEditor from '../../components/bookings/ServiceStepsEditor.vue'
import DeleteServiceDialog from '../../dialogs/DeleteServiceDialog.vue'
import ServiceForm from './ServiceForm.vue'
import { useObjectStore } from '../../store/modules/object.js'

const STATUS_LABELS = {
	draft: 'Draft',
	active: 'Active',
	archived: 'Archived',
}

export default {
	name: 'ServiceDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		CnFormDialog,
		ServiceForm,
		ServiceStepsEditor,
		DeleteServiceDialog,
	},

	props: {
		id: { type: String, default: null },
	},

	/**
	 * Live updates for the viewed service (or-object-{uuid} via the
	 * nc-vue liveUpdatesPlugin, default-on since beta.212). Events are
	 * refetch hints — the plugin re-runs fetchObject('appointmentService', id)
	 * into the same store cache serviceData renders from. Re-scopes on
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
		useObjectSubscription(objectStore, 'appointmentService', liveObjectId, {
			enabled: computed(() =>
				Boolean(
					liveObjectId.value && objectStore.objectTypeRegistry?.service,
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

		serviceId() {
			return this.id || null
		},

		isNew() {
			return !this.serviceId || this.serviceId === 'new'
		},

		loading() {
			return this.objectStore.loading?.service || false
		},

		serviceData() {
			if (this.isNew) return {}
			return (
				this.objectStore.getObject('appointmentService', this.serviceId)
				|| {}
			)
		},

		steps() {
			return Array.isArray(this.serviceData.multiStep)
				? this.serviceData.multiStep
				: []
		},

		requiredSkillsLabel() {
			const skills = this.serviceData.requiredSkills || []
			return skills.length ? skills.join(', ') : '-'
		},

		serviceSchema() {
			return this.objectStore.getSchema('appointmentService')
		},

		/**
		 * Labels, order and the rules ServiceForm enforced, for the edit
		 * dialog's schema-generated fields (the schema carries no titles).
		 *
		 * @return {object} fieldOverrides for CnFormDialog.
		 */
		editFieldOverrides() {
			return {
				name: { label: t('pipelinq', 'Name'), order: 1 },
				status: {
					label: t('pipelinq', 'Status'),
					order: 2,
					enumLabels: {
						draft: t('pipelinq', 'Draft'),
						active: t('pipelinq', 'Active'),
						archived: t('pipelinq', 'Archived'),
					},
				},

				description: { label: t('pipelinq', 'Description'), order: 3, widget: 'textarea' },
				durationMinutes: {
					label: t('pipelinq', 'Duration (minutes)'),
					order: 4,
					validation: { minimum: 1, maximum: 1440 },
				},

				bufferBeforeMinutes: { label: t('pipelinq', 'Buffer before (min)'), order: 5 },
				bufferAfterMinutes: { label: t('pipelinq', 'Buffer after (min)'), order: 6 },
				price: { label: t('pipelinq', 'Price'), order: 7 },
				currency: { label: t('pipelinq', 'Currency'), order: 8, validation: { maxLength: 3 } },
				requiredSkills: { label: t('pipelinq', 'Required skills'), order: 9 },
				multiStep: { label: t('pipelinq', 'Multi-step composition'), order: 10, widget: 'json' },
				bookableOnline: { label: t('pipelinq', 'Bookable online'), order: 11 },
				requiresDeposit: { label: t('pipelinq', 'Requires deposit'), order: 12 },
				depositAmount: {
					label: t('pipelinq', 'Deposit amount'),
					order: 13,
					visibleWhen: { field: 'requiresDeposit', truthy: true },
				},

				noShowFee: { label: t('pipelinq', 'No-show fee'), order: 14 },
				cancellationPolicy: {
					label: t('pipelinq', 'Cancellation policy'),
					order: 15,
					enumLabels: {
						free: t('pipelinq', 'Free'),
						'charge-deposit': t('pipelinq', 'Charge deposit'),
						'always-charge': t('pipelinq', 'Always charge'),
					},
				},

				cancellationHoursBefore: { label: t('pipelinq', 'Cancellation hours before'), order: 16 },
			}
		},

		sidebarProps() {
			const cfg = this.objectStore.objectTypeRegistry?.service || {}
			return {
				title: t('pipelinq', 'Service'),
				register: cfg.register || '',
				schema: cfg.schema || '',
				hiddenTabs: ['tasks'],
			}
		},
	},

	async mounted() {
		if (!this.isNew) {
			await this.objectStore.fetchObject('appointmentService', this.serviceId)
		}
	},

	methods: {
		async openEditDialog() {
			await this.objectStore.fetchSchema('appointmentService')
			if (!this.serviceSchema) {
				showError(t('pipelinq', 'Could not load the service form.'))
				return
			}
			this.showEditDialog = true
		},

		/**
		 * Save the edit dialog's data and report the outcome back to it.
		 *
		 * @param {object} formData The dialog's form data.
		 */
		async onEditConfirm(formData) {
			const dialog = this.$refs.editDialog
			const saved = await this.objectStore.saveObject(
				'appointmentService',
				{ ...formData, id: this.serviceId },
			)
			if (!saved) {
				const error = this.objectStore.getError?.('appointmentService')
				dialog?.setResult({ error: error?.message || t('pipelinq', 'Failed to save service.') })
				return
			}
			dialog?.setResult({ success: true })
			await this.invalidateAvailability(saved.id || this.serviceId)
			await this.objectStore.fetchObject('appointmentService', this.serviceId)
		},

		async onFormSave(formData) {
			const saved = await this.objectStore.saveObject(
				'appointmentService',
				formData,
			)
			if (!saved) {
				const error = this.objectStore.getError?.('appointmentService')
				showError(error?.message || t('pipelinq', 'Failed to save service.'))
				return
			}
			showSuccess(t('pipelinq', 'Service saved.'))
			await this.invalidateAvailability(saved.id || formData.id)
			this.$router.push({
				name: 'ServiceDetail',
				params: { id: saved.id },
			})
		},

		onFormCancel() {
			this.$router.push({ name: 'Services' })
		},

		async confirmDelete() {
			this.showDelete = false
			const ok = await this.objectStore.deleteObject(
				'appointmentService',
				this.serviceId,
			)
			if (ok) {
				this.$router.push({ name: 'Services' })
			} else {
				const error = this.objectStore.getError?.('appointmentService')
				showError(
					error?.message || t('pipelinq', 'Failed to delete service.'),
				)
			}
		},

		/**
		 * Best-effort availability-cache invalidation after a service save.
		 *
		 * Fetches every Resource whose `requiredResourceTypes` overlaps the
		 * service and deletes its cached availability rows so the next
		 * portal slot search recomputes. Failures are silent — the hourly
		 * AvailabilityCacheRefreshJob converges the cache eventually.
		 *
		 * @param {string} serviceId The saved service id.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		async invalidateAvailability(serviceId) {
			if (!serviceId) return
			try {
				const resources = await this.objectStore.fetchCollection(
					'appointmentResource',
					{ _limit: 200 },
				)
				const types = this.serviceData.requiredResourceTypes || []
				const targets = (resources || []).filter((r) => {
					if (!types.length) return true
					return types.includes(r.type)
				})
				for (const resource of targets) {
					try {
						const cached = await this.objectStore.fetchCollection(
							'availabilityCache',
							{
								resourceId: resource.id,
								_limit: 200,
							},
						)
						for (const row of cached || []) {
							await this.objectStore.deleteObject(
								'availabilityCache',
								row.id,
							)
						}
					} catch {
						// Per-resource invalidation failure must not block save.
					}
				}
			} catch {
				// Resource list fetch failure: cache is regenerated hourly.
			}
		},

		formatDuration(minutes) {
			const n = Number(minutes) || 0
			if (n < 60) return t('pipelinq', '{n} min', { n })
			const h = Math.floor(n / 60)
			const m = n % 60
			return m === 0
				? t('pipelinq', '{n}h', { n: h })
				: t('pipelinq', '{h}h {m}min', { h, m })
		},

		formatCurrency(value, currency) {
			const code = currency || 'EUR'
			const n = Number(value) || 0
			try {
				return new Intl.NumberFormat('nl-NL', {
					style: 'currency',
					currency: code,
					maximumFractionDigits: 2,
				}).format(n)
			} catch {
				return `${code} ${n}`
			}
		},

		statusLabel(status) {
			return t('pipelinq', STATUS_LABELS[status] || status || '-')
		},
	},
}
</script>

<style scoped>
.service-detail__header {
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

.info-field--full {
	grid-column: 1 / -1;
	margin-top: 16px;
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
