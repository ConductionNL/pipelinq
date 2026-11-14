<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  BulkChanges: the bulk changes this person made to clients and contacts,
  newest first, with Undo while OpenRegister's reversal window is open.

  A custom page because it lists OpenRegister bulk jobs, not register objects
  (design D4). OpenRegister lists only the caller's own jobs.

  @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md
-->
<template>
	<div class="bulk-changes">
		<h2>{{ t('pipelinq', 'Bulk changes') }}</h2>
		<p class="bulk-changes__intro">
			{{ t('pipelinq', 'Changes you made to many clients or contacts at once. You can undo a change for seven days.') }}
		</p>
		<NcLoadingIcon v-if="loading" />
		<p v-else-if="loadError" class="bulk-changes__error" role="alert">
			{{ loadError }}
		</p>
		<NcEmptyContent v-else-if="rows.length === 0" :name="t('pipelinq', 'No bulk changes yet')" />
		<table v-else class="bulk-changes__table">
			<thead>
				<tr>
					<th scope="col">{{ t('pipelinq', 'When') }}</th>
					<th scope="col">{{ t('pipelinq', 'Field') }}</th>
					<th scope="col">{{ t('pipelinq', 'Records') }}</th>
					<th scope="col">{{ t('pipelinq', 'State') }}</th>
					<th scope="col"><span class="hidden-visually">{{ t('pipelinq', 'Actions') }}</span></th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in rows" :key="row.job.id" :data-job="row.job.id">
					<td>{{ formatDate(row.job.created) }}</td>
					<td>{{ row.properties.join(', ') }}</td>
					<td>{{ row.count }}</td>
					<td>{{ row.job.state }}</td>
					<td>
						<NcButton v-if="row.undo.canUndo" @click="startUndo(row.job)">
							{{ t('pipelinq', 'Undo') }}
						</NcButton>
						<span v-else-if="row.undo.undone">{{ t('pipelinq', 'Undone') }}</span>
						<span v-else-if="row.undo.until">{{ t('pipelinq', 'Could be undone until {date}', { date: formatDate(row.undo.until) }) }}</span>
					</td>
				</tr>
			</tbody>
		</table>

		<BulkUndoDialog
			v-if="reversal"
			:reversal="reversal"
			:busy="committing"
			:error="undoError"
			@cancel="closeUndo"
			@confirm="confirmUndo" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import BulkUndoDialog from '../../dialogs/BulkUndoDialog.vue'
import { bulkJobsApi } from '../../services/bulkJobsApi.js'
import { useSettingsStore } from '../../store/modules/settings.js'
import { changedCount, changedProperties, clientJobs, undoState } from '../../utils/bulkJobs.js'

export default {
	name: 'BulkChanges',
	components: { BulkUndoDialog, NcButton, NcEmptyContent, NcLoadingIcon },
	data() {
		return {
			jobs: [],
			loading: true,
			loadError: '',
			reversal: null,
			committing: false,
			undoError: '',
		}
	},

	computed: {
		rows() {
			const config = useSettingsStore().config || {}
			const now = new Date()
			return clientJobs(this.jobs, { register: config.register, schemas: [config.client_schema, config.contact_schema] })
				.map((job) => ({ job, properties: changedProperties(job), count: changedCount(job), undo: undoState(job, now) }))
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		async load() {
			this.loading = true
			this.loadError = ''
			try {
				const settings = useSettingsStore()
				if (!settings.config) {
					await settings.fetchSettings()
				}
				this.jobs = await bulkJobsApi.list()
			} catch (e) {
				this.loadError = e.message
			} finally {
				this.loading = false
			}
		},

		async startUndo(job) {
			this.undoError = ''
			try {
				this.reversal = await bulkJobsApi.reverse(job.id)
			} catch (e) {
				this.loadError = e.message
			}
		},

		closeUndo() {
			this.reversal = null
			this.undoError = ''
		},

		async confirmUndo() {
			this.committing = true
			try {
				await bulkJobsApi.commit(this.reversal.id)
				this.closeUndo()
				await this.load()
			} catch (e) {
				this.undoError = e.message
			} finally {
				this.committing = false
			}
		},

		formatDate(value) {
			if (!value) {
				return ''
			}
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString()
		},
	},
}
</script>

<style scoped>
.bulk-changes {
	padding: 16px 24px;
}

.bulk-changes__intro {
	color: var(--color-text-maxcontrast);
}

.bulk-changes__error {
	color: var(--color-error-text);
}

.bulk-changes__table {
	width: 100%;
	border-collapse: collapse;
}

.bulk-changes__table th,
.bulk-changes__table td {
	padding: 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}
</style>
