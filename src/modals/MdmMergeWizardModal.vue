<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<NcDialog
		:name="t('pipelinq', 'Merge master entities')"
		:open="true"
		size="large"
		@closing="$emit('close')">
		<div class="merge-wizard">
			<NcLoadingIcon v-if="loading" :size="32" />

			<NcNoteCard v-else-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<template v-else-if="preview">
				<h3>{{ t('pipelinq', 'Post-merge golden record') }}</h3>
				<table class="merge-wizard__table">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Attribute') }}</th>
							<th>{{ t('pipelinq', 'Winning value') }}</th>
							<th>{{ t('pipelinq', 'Source') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(value, key) in preview.postMergeGoldenRecord" :key="key">
							<td>{{ key }}</td>
							<td>{{ value }}</td>
							<td>{{ sourceFor(key) }}</td>
						</tr>
					</tbody>
				</table>

				<h3>{{ t('pipelinq', 'Downstream impact') }}</h3>
				<ul class="merge-wizard__impact">
					<li v-for="impact in preview.downstreamImpact" :key="impact.targetSystem">
						{{ impact.targetSystem }} — {{ impact.changeType }}
					</li>
				</ul>

				<NcNoteCard type="info">
					{{ t('pipelinq', 'This merge can be reversed until {date}.', { date: preview.reversibleUntil }) }}
				</NcNoteCard>

				<label class="merge-wizard__reason">
					{{ t('pipelinq', 'Reason') }}
					<NcSelect v-model="mergeReason"
						:options="reasonOptions"
						:clearable="false"
						:input-label="t('pipelinq', 'Merge reason')" />
				</label>
			</template>
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="executing || !preview"
				@click="execute">
				{{ t('pipelinq', 'Execute merge') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'

export default {
	name: 'MdmMergeWizardModal',
	components: { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcSelect },
	props: {
		candidate: {
			type: Object,
			required: true,
		},
	},
	emits: ['close', 'merged'],
	data() {
		return {
			loading: true,
			executing: false,
			error: '',
			preview: null,
			mergeReason: 'data-stewardship-review',
			reasonOptions: ['data-stewardship-review', 'manual-bulk', 'migration'],
		}
	},
	mounted() {
		this.fetchPreview()
	},
	methods: {
		async fetchPreview() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.post(generateUrl('/apps/pipelinq/api/mdm/merge/preview'), {
					fromMasterId: this.candidate.fromMasterId,
					intoMasterId: this.candidate.intoMasterId,
				})
				this.preview = data
			} catch (e) {
				this.error = t('pipelinq', 'The merge preview could not be generated.')
			} finally {
				this.loading = false
			}
		},
		sourceFor(key) {
			const meta = this.preview && this.preview.attributeProvenance && this.preview.attributeProvenance[key]
			return meta ? meta.sourceSystem : ''
		},
		async execute() {
			this.executing = true
			try {
				await axios.post(generateUrl('/apps/pipelinq/api/mdm/merge/execute'), {
					fromMasterId: this.candidate.fromMasterId,
					intoMasterId: this.candidate.intoMasterId,
					mergeReason: this.mergeReason,
				})
				showSuccess(t('pipelinq', 'Master entities merged'))
				this.$emit('merged')
			} catch (e) {
				const message = (e.response && e.response.data && e.response.data.message)
					|| t('pipelinq', 'The merge could not be completed.')
				showError(message)
			} finally {
				this.executing = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.merge-wizard {
	min-height: 200px;

	&__table {
		width: 100%;
		border-collapse: collapse;
		margin-bottom: 16px;

		th, td {
			text-align: left;
			padding: 6px 10px;
			border-bottom: 1px solid var(--color-border);
		}
	}

	&__impact {
		margin: 0 0 16px 18px;
	}

	&__reason {
		display: block;
		margin-top: 16px;
	}
}
</style>
