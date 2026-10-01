<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - Journey create / edit, mounted into the `form-dialog` slot of both the
  - Journeys index page and the JourneyDetail page. It saves through
  - POST / PATCH /api/journeys rather than the slot's `confirm`, because every
  - write compiles the journey into an OpenRegister flow and only that endpoint
  - does so. A journey saved through the generic object dialog would be stored
  - and never compiled, which looks exactly like one whose trigger has not
  - fired yet.
  -
  - @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
  -->
<template>
	<NcDialog
		v-if="show"
		:name="
			isEditing ? t('pipelinq', 'Edit journey') : t('pipelinq', 'New journey')
		"
		:open="true"
		size="normal"
		:closeOnClickOutside="false"
		@closing="close()">
		<div class="journey-form">
			<NcLoadingIcon v-if="loading" :size="32" class="journey-form__loading" />

			<template v-else>
				<NcNoteCard
					v-if="flowMessage"
					type="warning"
					class="journey-form__note"
					data-testid="journey-form-flow">
					{{ flowMessage }}
				</NcNoteCard>

				<NcTextField
					v-model="form.name"
					data-testid="journey-form-name"
					:label="t('pipelinq', 'Name')"
					:required="true" />

				<NcTextField
					v-model="form.description"
					:label="t('pipelinq', 'Description')"
					:placeholder="
						t('pipelinq', 'Who this journey is for, in one sentence')
					" />

				<div class="journey-form__grid">
					<NcSelect
						v-model="triggerKind"
						data-testid="journey-form-trigger"
						:options="triggerOptions"
						:clearable="false"
						:inputLabel="t('pipelinq', 'Trigger')"
						label="label"
						trackBy="value" />

					<NcSelect
						v-model="status"
						:options="statusOptions"
						:clearable="false"
						:inputLabel="t('pipelinq', 'Status')"
						label="label"
						trackBy="value" />
				</div>

				<NcTextField
					v-if="triggerKind && triggerKind.value === 'shillinqSignal'"
					v-model="cron"
					:label="t('pipelinq', 'Schedule')"
					:helperText="
						t(
							'pipelinq',
							'A bookkeeping change announces nothing, so this journey looks for it on a schedule.',
						)
					" />

				<div class="journey-form__grid">
					<NcTextField
						v-model="form.audienceSegment"
						data-testid="journey-form-audience"
						:label="t('pipelinq', 'Audience')"
						:helperText="
							t(
								'pipelinq',
								'A segment the contact must still match. Leave it empty to reach everyone the trigger delivered.',
							)
						" />

					<NcTextField
						v-model="form.waitFor"
						data-testid="journey-form-wait"
						:label="t('pipelinq', 'Wait')"
						:placeholder="t('pipelinq', 'for example: 5 days')" />
				</div>

				<section class="journey-form__section">
					<h3 class="journey-form__heading">
						{{ t('pipelinq', 'Condition') }}
					</h3>
					<NcTextField
						v-model="condition.field"
						:label="t('pipelinq', 'Field')"
						:helperText="
							t(
								'pipelinq',
								'Leave it empty and the action always runs.',
							)
						" />
					<div class="journey-form__grid">
						<NcSelect
							v-model="conditionOperator"
							class="journey-form__operator"
							:options="operatorOptions"
							:clearable="false"
							:inputLabel="t('pipelinq', 'Operator')"
							label="label"
							trackBy="value" />
						<NcTextField
							v-model="condition.value"
							:label="t('pipelinq', 'Value')" />
					</div>
				</section>

				<section class="journey-form__section">
					<h3 class="journey-form__heading">
						{{ t('pipelinq', 'Action') }}
					</h3>
					<NcSelect
						v-model="actionKind"
						data-testid="journey-form-action"
						:options="actionOptions"
						:clearable="false"
						:inputLabel="t('pipelinq', 'What happens')"
						label="label"
						trackBy="value" />

					<template
						v-if="actionKind && actionKind.value === 'sendMailing'">
						<div class="journey-form__grid">
							<NcTextField
								v-model="action.templateId"
								:label="t('pipelinq', 'Template')" />
							<NcTextField
								v-model="action.listId"
								:label="t('pipelinq', 'Mailing list')"
								:helperText="
									t(
										'pipelinq',
										'Leave it empty and the send is checked against the channel consent instead of a list.',
									)
								" />
						</div>
						<NcSelect
							v-model="intent"
							:options="intentOptions"
							:clearable="false"
							:inputLabel="t('pipelinq', 'Send intent')"
							label="label"
							trackBy="value" />
						<p class="journey-form__hint">
							{{
								t(
									'pipelinq',
									'A promotional send skips a customer in dunning. A service message reaches them anyway.',
								)
							}}
						</p>
					</template>

					<div v-else class="journey-form__grid">
						<NcTextField
							v-model="action.taskSubject"
							:label="t('pipelinq', 'Task subject')" />
						<NcTextField
							v-model="action.taskAssignee"
							:label="t('pipelinq', 'Assign to')" />
					</div>
				</section>

				<NcNoteCard v-if="error" type="error" class="journey-form__note">
					{{ error }}
				</NcNoteCard>
			</template>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="close()">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="journey-form-save"
				:disabled="saving || loading || !form.name"
				@click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('pipelinq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { showWarning } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { flowStatusMessage } from '../services/journeyLabels.js'
import { fetchJourney, saveJourney } from '../services/journeysApi.js'

/**
 * @return {object} A blank journey form.
 */
function blankState() {
	return {
		error: '',
		flowMessage: '',
		cron: '0 7 * * *',
		condition: { field: '', operator: 'equals', value: '' },
		action: {
			kind: 'createTask',
			templateId: '',
			listId: '',
			intent: 'promotional',
			taskSubject: '',
			taskType: 'callback',
			taskAssignee: '',
		},

		form: {
			name: '',
			description: '',
			audienceSegment: '',
			waitFor: '',
		},
	}
}

export default {
	name: 'JourneyFormDialog',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	inheritAttrs: false,

	props: {
		/** Whether the host page has the dialog open. */
		show: {
			type: Boolean,
			default: false,
		},

		/** The journey being edited, or null to create one. */
		item: {
			type: Object,
			default: null,
		},

		/** Closes the dialog. */
		close: {
			type: Function,
			default: () => {},
		},

		/** Re-reads the index page's list; the detail page does not pass it. */
		refresh: {
			type: Function,
			default: null,
		},
	},

	data() {
		return {
			loading: false,
			saving: false,
			triggerKind: null,
			conditionOperator: null,
			actionKind: null,
			intent: null,
			status: null,
			...blankState(),
		}
	},

	computed: {
		/**
		 * @return {string} The id of the journey being edited, empty when creating one.
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
		 */
		journeyId() {
			const item = this.item || {}
			return String(item.id || item['@self']?.id || item.uuid || '')
		},

		/** @return {boolean} Whether an existing journey is being edited. */
		isEditing() {
			return this.journeyId !== ''
		},

		/**
		 * @return {Array<object>} The four trigger kinds the schema declares.
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
		 */
		triggerOptions() {
			return [
				{
					value: 'leadStageChanged',
					label: this.t('pipelinq', 'A lead moved stage'),
				},
				{
					value: 'contractRenewalWindow',
					label: this.t('pipelinq', 'A contract is up for renewal'),
				},
				{
					value: 'listConfirmed',
					label: this.t('pipelinq', 'Someone confirmed a subscription'),
				},
				{
					value: 'shillinqSignal',
					label: this.t('pipelinq', 'A bookkeeping signal changed'),
				},
			]
		},

		/**
		 * @return {Array<object>} The four condition operators.
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
		 */
		operatorOptions() {
			return [
				{ value: 'equals', label: this.t('pipelinq', 'Is') },
				{ value: 'notEquals', label: this.t('pipelinq', 'Is not') },
				{ value: 'isNull', label: this.t('pipelinq', 'Is empty') },
				{ value: 'isNotNull', label: this.t('pipelinq', 'Is filled in') },
			]
		},

		/**
		 * @return {Array<object>} The two actions.
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
		 */
		actionOptions() {
			return [
				{ value: 'createTask', label: this.t('pipelinq', 'Create a task') },
				{
					value: 'sendMailing',
					label: this.t('pipelinq', 'Send a mailing'),
				},
			]
		},

		/**
		 * @return {Array<object>} The two send intents.
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-promotional-send-skips-a-customer-in-dunning
		 */
		intentOptions() {
			return [
				{ value: 'promotional', label: this.t('pipelinq', 'Promotional') },
				{ value: 'service', label: this.t('pipelinq', 'Service message') },
			]
		},

		/**
		 * @return {Array<object>} The three statuses.
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
		 */
		statusOptions() {
			return [
				{ value: 'draft', label: this.t('pipelinq', 'Draft') },
				{ value: 'active', label: this.t('pipelinq', 'Active') },
				{ value: 'paused', label: this.t('pipelinq', 'Paused') },
			]
		},
	},

	watch: {
		show: {
			immediate: true,
			/**
			 * Load the form each time the dialog opens.
			 *
			 * @param {boolean} open Whether the dialog is open.
			 *
			 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
			 */
			handler(open) {
				if (open) {
					this.load()
				}
			},
		},
	},

	methods: {
		/**
		 * Reset the form, seed the pickers, and read the journey when editing one.
		 *
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
		 */
		async load() {
			Object.assign(this, blankState())
			this.triggerKind = this.triggerOptions[0]
			this.conditionOperator = this.operatorOptions[0]
			this.actionKind = this.actionOptions[0]
			this.intent = this.intentOptions[0]
			this.status = this.statusOptions[0]

			if (this.isEditing) {
				this.loading = true
				await this.fetchJourney()
				this.loading = false
			}
		},

		/**
		 * Read the journey being edited into the form.
		 *
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
		 */
		async fetchJourney() {
			try {
				const journey = await fetchJourney(this.journeyId)
				this.form.name = journey.name || ''
				this.form.description = journey.description || ''
				this.form.audienceSegment = journey.audienceSegment || ''
				this.form.waitFor = journey.waitFor || ''
				this.cron = journey.trigger?.cron || this.cron
				this.condition = { ...this.condition, ...(journey.condition || {}) }
				this.action = { ...this.action, ...(journey.action || {}) }
				this.triggerKind = this.pick(
					this.triggerOptions,
					journey.trigger?.kind,
				)
				this.conditionOperator = this.pick(
					this.operatorOptions,
					journey.condition?.operator,
				)
				this.actionKind = this.pick(this.actionOptions, journey.action?.kind)
				this.intent = this.pick(this.intentOptions, journey.action?.intent)
				this.status = this.pick(this.statusOptions, journey.status)
				this.flowMessage = flowStatusMessage(journey, this.t)
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not load this journey.')
			}
		},

		/**
		 * Write the journey, which compiles it. When the flow engine did not
		 * take it, the journey is still saved, so the dialog closes and says why
		 * in a notification.
		 *
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				const journey = await saveJourney(
					{
						...this.form,
						status: this.status?.value || 'draft',
						trigger: {
							kind: this.triggerKind?.value || 'leadStageChanged',
							cron: this.cron,
						},
						condition: {
							...this.condition,
							operator: this.conditionOperator?.value || 'equals',
						},
						action: {
							...this.action,
							kind: this.actionKind?.value || 'createTask',
							intent: this.intent?.value || 'promotional',
						},
					},
					this.journeyId,
				)
				const flowMessage = flowStatusMessage(journey, this.t)
				if (flowMessage) {
					showWarning(flowMessage)
				}
				if (this.refresh) {
					this.refresh()
				} else {
					emit('cn:page:refresh', {})
				}
				this.close()
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not save this journey.')
			} finally {
				this.saving = false
			}
		},

		/**
		 * Find the option matching a stored value.
		 *
		 * @param {Array<object>} options The options.
		 * @param {string} value The stored value.
		 * @return {object} The option, falling back to the first.
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
		 */
		pick(options, value) {
			return options.find((option) => option.value === value) || options[0]
		},
	},
}
</script>

<style scoped>
.journey-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding-bottom: 8px;
}

.journey-form__loading {
	margin: 32px auto;
}

.journey-form__grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	align-items: start;
	gap: 12px 16px;
}

.journey-form__grid :deep(.v-select.select),
.journey-form__section > :deep(.v-select.select) {
	width: 100%;
	margin: 0;
}

/* Compounded with the grid rule above so it outranks its margin reset. */
.journey-form__grid :deep(.v-select.select.journey-form__operator) {
	margin-block-start: 6px;
}

.journey-form__section {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.journey-form__heading {
	margin: 0;
	font-size: 1.1em;
}

.journey-form__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.journey-form__note {
	margin: 0;
}
</style>
