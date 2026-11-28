<template>
	<CnDialog width="confirm" :name="t('pipelinq', 'Delete pipeline')" @closing="$emit('cancel')">
		<p>
			{{
				t('pipelinq', 'Are you sure you want to delete "{title}"?', {
					title: pipeline.title,
				})
			}}
		</p>
		<p v-if="affectedCount > 0" class="delete-warning">
			{{
				t(
					'pipelinq',
					'{count} leads/requests are on this pipeline. They will be removed from the pipeline but not deleted.',
					{ count: affectedCount },
				)
			}}
		</p>
		<p
			v-if="pipeline.stages && pipeline.stages.length > 0"
			class="delete-warning">
			{{
				t(
					'pipelinq',
					'This pipeline has {count} stages. All stage configuration will be lost.',
					{ count: pipeline.stages.length },
				)
			}}
		</p>
		<template #actions>
			<NcButton @click="$emit('cancel')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="error" @click="$emit('confirm')">
				{{ t('pipelinq', 'Delete') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'DeletePipelineDialog',
	components: {
		NcButton,
		CnDialog,
	},

	props: {
		/**
		 * The pipeline pending deletion.
		 */
		pipeline: {
			type: Object,
			required: true,
		},

		/**
		 * Number of leads/requests currently on the pipeline.
		 */
		affectedCount: {
			type: Number,
			default: 0,
		},
	},

	emits: ['cancel', 'confirm'],
}
</script>
