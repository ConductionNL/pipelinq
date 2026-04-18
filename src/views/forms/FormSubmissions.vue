<template>
	<div class="form-submissions">
		<div class="submissions-header">
			<NcButton type="tertiary" @click="$router.push({ name: 'Forms' })">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
			</NcButton>
			<h2>{{ $t('pipelinq', 'Submissions') }}</h2>
			<NcButton type="secondary" @click="exportCsv">
				<template #icon>
					<Download :size="20" />
				</template>
				{{ $t('pipelinq', 'Export CSV') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" />

		<NcEmptyContent
			v-else-if="submissions.length === 0"
			:name="t('pipelinq', 'No submissions yet')"
			:description="t('pipelinq', 'Submissions will appear here when the form receives responses.')">
			<template #icon>
				<FormatListBulleted :size="64" />
			</template>
		</NcEmptyContent>

		<table v-else class="submissions-table">
			<thead>
				<tr>
					<th>{{ $t('pipelinq', 'Submitted') }}</th>
					<th>{{ $t('pipelinq', 'Status') }}</th>
					<th>{{ $t('pipelinq', 'Contact') }}</th>
					<th>{{ $t('pipelinq', 'Lead') }}</th>
					<th>{{ $t('pipelinq', 'Data') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="sub in submissions" :key="sub.id">
					<td>{{ formatDate(sub.submittedAt) }}</td>
					<td>
						<span :class="'status-' + sub.status">{{ sub.status }}</span>
					</td>
					<td>{{ sub.contactId || '-' }}</td>
					<td>{{ sub.leadId || '-' }}</td>
					<td class="data-cell">
						{{ formatData(sub.data) }}
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from '../../store/store.js'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Download from 'vue-material-design-icons/Download.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'

export default {
	name: 'FormSubmissions',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		ArrowLeft,
		Download,
		FormatListBulleted,
	},
	props: {
		formId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			loading: false,
			submissions: [],
		}
	},
	mounted() {
		this.fetchSubmissions()
	},
	methods: {
		async fetchSubmissions() {
			this.loading = true
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.fetchObjects('intakeSubmission', {
					filters: { form: this.formId },
					orderBy: { submittedAt: 'desc' },
				})
				this.submissions = result?.results || []
			} catch (e) {
				console.error('Failed to load submissions', e)
			} finally {
				this.loading = false
			}
		},
		formatDate(dateStr) {
			return new Date(dateStr).toLocaleString('nl-NL')
		},
		formatData(data) {
			if (!data) return '-'
			return Object.entries(data).map(([k, v]) => k + ': ' + v).join(', ')
		},
		exportCsv() {
			const url = generateUrl('/apps/pipelinq/api/forms/{id}/submissions/export', { id: this.formId })
			window.open(url, '_blank')
		},
	},
}
</script>

<style scoped>
.form-submissions {
	padding: 20px;
	max-width: 1200px;
}

.submissions-header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 20px;
}

.submissions-header h2 {
	flex: 1;
}

.submissions-table {
	width: 100%;
	border-collapse: collapse;
}

.submissions-table th,
.submissions-table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.submissions-table th {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.status-processed {
	color: var(--color-success);
}

.status-rejected {
	color: var(--color-error);
}

.status-spam {
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
}

.data-cell {
	max-width: 400px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
</style>
