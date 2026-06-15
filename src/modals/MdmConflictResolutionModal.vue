<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<NcDialog
		:name="t('pipelinq', 'Resolve attribute conflict')"
		:open="true"
		size="large"
		@closing="$emit('close')">
		<div class="conflict">
			<NcEmptyContent v-if="!conflicts.length"
				:name="t('pipelinq', 'No attribute conflicts to resolve')" />

			<template v-else>
				<p>{{ t('pipelinq', 'For each attribute with disagreeing sources, choose the authoritative source. Optionally make the choice a persistent trust rule.') }}</p>

				<div v-for="conflict in conflicts" :key="conflict.attribute" class="conflict__row">
					<h3>{{ conflict.attribute }}</h3>
					<NcSelect v-model="selection[conflict.attribute]"
						:options="conflict.options"
						:clearable="false"
						label="label"
						:input-label="t('pipelinq', 'Winning source')" />
				</div>

				<NcCheckboxRadioSwitch :model-value="persistRule"
					type="switch"
					@update:model-value="persistRule = $event">
					{{ t('pipelinq', 'Always use this rule (create trust configuration)') }}
				</NcCheckboxRadioSwitch>

				<NcTextField :value.sync="rationale"
					:label="t('pipelinq', 'Rationale')" />
			</template>
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="saving || !conflicts.length"
				@click="save">
				{{ t('pipelinq', 'Save resolution') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcEmptyContent, NcSelect, NcTextField } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'

export default {
	name: 'MdmConflictResolutionModal',
	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcEmptyContent, NcSelect, NcTextField },
	props: {
		entity: {
			type: Object,
			required: true,
		},
		sourceRecords: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['close', 'saved'],
	data() {
		return {
			selection: {},
			persistRule: false,
			rationale: '',
			saving: false,
		}
	},
	computed: {
		conflicts() {
			const byAttribute = {}
			this.sourceRecords.forEach((record) => {
				const mapped = record.mappedAttributes || {}
				Object.keys(mapped).forEach((attribute) => {
					const value = mapped[attribute]
					if (value === null || value === '') return
					byAttribute[attribute] = byAttribute[attribute] || []
					byAttribute[attribute].push({
						label: `${value} (${record.sourceSystem})`,
						value,
						sourceSystem: record.sourceSystem,
					})
				})
			})
			return Object.keys(byAttribute)
				.filter((attribute) => new Set(byAttribute[attribute].map((o) => o.value)).size > 1)
				.map((attribute) => ({ attribute, options: byAttribute[attribute] }))
		},
	},
	methods: {
		async save() {
			this.saving = true
			try {
				if (this.persistRule) {
					await this.persistTrustRules()
				}
				showSuccess(t('pipelinq', 'Conflict resolution saved'))
				this.$emit('saved')
			} catch (e) {
				showError(t('pipelinq', 'Could not save the conflict resolution'))
			} finally {
				this.saving = false
			}
		},
		async persistTrustRules() {
			const requests = this.conflicts
				.filter((conflict) => this.selection[conflict.attribute])
				.map((conflict) => axios.post(generateUrl('/apps/pipelinq/api/mdm/trust-config'), {
					entityType: this.entity.entityType,
					attribute: conflict.attribute,
					sourceSystem: this.selection[conflict.attribute].sourceSystem,
					trustTier: 'gold',
					manualOverrideAllowed: true,
					rationale: this.rationale,
				}))
			await Promise.all(requests)
		},
	},
}
</script>

<style scoped lang="scss">
.conflict {
	&__row {
		margin-bottom: 16px;
	}
}
</style>
