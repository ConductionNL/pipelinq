<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - ScorecardManager — the call scorecards on the admin page (contact-moments-call-review
  - D3, task 2.2). A scorecard holds the criteria a recorded call is scored on, each with
  - a weight and a scale of 1 to 5. Scorecards are OpenRegister objects: this section
  - reads and writes them through OpenRegister's object API, which refuses a write by
  - anyone but an administrator.
  -->
<template>
	<CnSettingsSection
		:name="t('pipelinq', 'Call scorecards')"
		:description="t('pipelinq', 'The criteria a team lead scores a recorded call on. The total of a review is the mean of its scores, weighted per criterion.')">
		<template #actions>
			<NcButton
				class="cn-button--tonal"
				variant="secondary"
				data-testid="scorecard-new"
				@click="startNew">
				<template #icon>
					<Plus :size="16" />
				</template>
				{{ t('pipelinq', 'New scorecard') }}
			</NcButton>
		</template>

		<p v-if="loadFailed" class="scorecard-manager__note" role="alert">
			{{ t('pipelinq', 'The scorecards could not be loaded.') }}
		</p>
		<p v-else-if="!loading && scorecards.length === 0 && !draft" class="scorecard-manager__note">
			{{ t('pipelinq', 'No scorecard yet. Add one to start reviewing calls.') }}
		</p>

		<ul v-if="scorecards.length > 0" class="scorecard-manager__list">
			<li v-for="card in scorecards" :key="card.id" class="scorecard-manager__item">
				<span class="scorecard-manager__title">{{ card.title }}</span>
				<span class="scorecard-manager__meta">
					{{ n('pipelinq', '%n criterion', '%n criteria', (card.criteria || []).length) }}
					· {{ card.isActive === false ? t('pipelinq', 'Not active') : t('pipelinq', 'Active') }}
				</span>
				<NcButton variant="tertiary" :data-testid="`scorecard-edit-${card.id}`" @click="edit(card)">
					{{ t('pipelinq', 'Edit') }}
				</NcButton>
			</li>
		</ul>

		<form v-if="draft" class="scorecard-manager__form" data-testid="scorecard-form" @submit.prevent="save">
			<NcTextField
				v-model="draft.title"
				:label="t('pipelinq', 'Title')"
				data-testid="scorecard-title" />
			<NcCheckboxRadioSwitch v-model="draft.isActive" type="switch">
				{{ t('pipelinq', 'Active') }}
			</NcCheckboxRadioSwitch>

			<fieldset class="scorecard-manager__criteria">
				<legend>{{ t('pipelinq', 'Criteria') }}</legend>
				<div
					v-for="(row, index) in draft.criteria"
					:key="row.uid"
					class="scorecard-manager__criterion"
					:data-testid="`scorecard-criterion-${index}`">
					<NcTextField v-model="row.label" :label="t('pipelinq', 'Criterion')" />
					<NcTextField
						v-model="row.weight"
						type="number"
						min="0"
						step="0.5"
						:label="t('pipelinq', 'Weight')" />
					<NcTextField
						v-model="row.scale"
						type="number"
						min="1"
						max="5"
						:label="t('pipelinq', 'Highest score')" />
					<NcButton
						variant="tertiary"
						:aria-label="t('pipelinq', 'Remove criterion')"
						:data-testid="`scorecard-remove-${index}`"
						@click="removeCriterion(index)">
						<template #icon>
							<DeleteOutline :size="20" />
						</template>
					</NcButton>
				</div>
				<NcButton variant="secondary" data-testid="scorecard-add-criterion" @click="addCriterion">
					<template #icon>
						<Plus :size="16" />
					</template>
					{{ t('pipelinq', 'Add criterion') }}
				</NcButton>
			</fieldset>

			<ul v-if="problems.length > 0" class="scorecard-manager__problems" role="alert">
				<li v-for="problem in problems" :key="problem">
					{{ t('pipelinq', problem) }}
				</li>
			</ul>

			<div class="scorecard-manager__buttons">
				<NcButton variant="primary" type="submit" :disabled="saving" data-testid="scorecard-save">
					{{ t('pipelinq', 'Save scorecard') }}
				</NcButton>
				<NcButton variant="tertiary" @click="draft = null">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
			</div>
		</form>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { normaliseCriteria, scorecardProblems } from '../../utils/scorecardCriteria.js'

const BASE = '/apps/openregister/api/objects/pipelinq/callScorecard'

let rowSeq = 0

/**
 * An editor row for a criterion.
 *
 * @param {object} criterion The stored criterion, or an empty one.
 * @return {object} The row.
 */
function toRow(criterion = {}) {
	rowSeq++
	return {
		uid: rowSeq,
		key: criterion.key || '',
		label: criterion.label || '',
		weight: String(criterion.weight ?? 1),
		scale: String(criterion.scale ?? 5),
	}
}

export default {
	name: 'ScorecardManager',
	components: { CnSettingsSection, DeleteOutline, NcButton, NcCheckboxRadioSwitch, NcTextField, Plus },

	data() {
		return {
			scorecards: [],
			loading: false,
			loadFailed: false,
			draft: null,
			problems: [],
			saving: false,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the scorecards.
		 *
		 * @spec openspec/changes/contact-moments-call-review/tasks.md#task-2.2
		 */
		async load() {
			this.loading = true
			this.loadFailed = false
			try {
				const { data } = await axios.get(generateUrl(BASE), { params: { _limit: 50 } })
				this.scorecards = (data?.results || []).map((card) => ({ ...card, id: card.id || card['@self']?.id }))
			} catch {
				this.scorecards = []
				this.loadFailed = true
			} finally {
				this.loading = false
			}
		},

		/** Open an empty scorecard with one criterion. */
		startNew() {
			this.problems = []
			this.draft = { id: null, title: '', isActive: true, criteria: [toRow()] }
		},

		/**
		 * Open a stored scorecard for editing.
		 *
		 * @param {object} card The scorecard.
		 */
		edit(card) {
			this.problems = []
			this.draft = {
				id: card.id,
				title: card.title || '',
				isActive: card.isActive !== false,
				criteria: (card.criteria || []).map((criterion) => toRow(criterion)),
			}
		},

		/** Add an empty criterion row. */
		addCriterion() {
			this.draft.criteria.push(toRow())
		},

		/**
		 * Remove a criterion row.
		 *
		 * @param {number} index The row's position.
		 */
		removeCriterion(index) {
			this.draft.criteria.splice(index, 1)
		},

		/**
		 * Save the scorecard: POST a new one, PUT a stored one.
		 *
		 * @spec openspec/changes/contact-moments-call-review/tasks.md#task-2.2
		 */
		async save() {
			const payload = {
				title: this.draft.title.trim(),
				isActive: this.draft.isActive,
				criteria: normaliseCriteria(this.draft.criteria),
			}
			this.problems = scorecardProblems(payload)
			if (this.problems.length > 0) {
				return
			}
			this.saving = true
			try {
				if (this.draft.id) {
					await axios.put(generateUrl(`${BASE}/{id}`, { id: this.draft.id }), payload)
				} else {
					await axios.post(generateUrl(BASE), payload)
				}
				showSuccess(t('pipelinq', 'Scorecard saved'))
				this.draft = null
				await this.load()
			} catch (error) {
				showError(error?.response?.data?.message || t('pipelinq', 'The scorecard could not be saved.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.scorecard-manager__note {
	color: var(--color-text-maxcontrast);
}

.scorecard-manager__list {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-bottom: calc(var(--default-grid-baseline) * 4);
}

.scorecard-manager__item {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 3);
}

.scorecard-manager__title {
	font-weight: bold;
}

.scorecard-manager__meta {
	color: var(--color-text-maxcontrast);
	flex: 1;
}

.scorecard-manager__form,
.scorecard-manager__criteria {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.scorecard-manager__criteria {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 3);
}

.scorecard-manager__criterion {
	display: grid;
	grid-template-columns: minmax(0, 3fr) minmax(0, 1fr) minmax(0, 1fr) auto;
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: end;
}

.scorecard-manager__problems {
	color: var(--color-error-text);
}

.scorecard-manager__buttons {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
