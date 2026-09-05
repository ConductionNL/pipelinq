<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.

  Journey create and edit (marketing-integrated-campaigns). One component
  serves both routes; edit mode is the :id param, matching the CampaignNew /
  CampaignEdit convention.

  🔴 IT IS NOT A DECLARATIVE FORM, AND THAT IS THE WHOLE POINT. Every write
  compiles the journey into an OpenRegister flow, and only POST and PATCH
  /api/journeys do that. A journey saved through the generic object dialog
  would be stored and never compiled, which looks exactly like a journey
  whose trigger has not fired yet.

  @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
-->
<template>
	<div class="journey-form">
		<header class="journey-form__header">
			<NcButton variant="tertiary" @click="back">
				{{ t('pipelinq', 'Back to journeys') }}
			</NcButton>
			<h2>{{ heading }}</h2>
		</header>

		<NcLoadingIcon v-if="loading" :size="32" />

		<form v-else class="journey-form__body" @submit.prevent="save">
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

			<NcSelect
				v-model="triggerKind"
				data-testid="journey-form-trigger"
				:options="triggerOptions"
				:clearable="false"
				:inputLabel="t('pipelinq', 'Trigger')"
				label="label"
				trackBy="value" />

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

			<fieldset class="journey-form__group">
				<legend>{{ t('pipelinq', 'Condition') }}</legend>
				<NcTextField
					v-model="condition.field"
					:label="t('pipelinq', 'Field')"
					:helperText="
						t('pipelinq', 'Leave it empty and the action always runs.')
					" />
				<NcSelect
					v-model="conditionOperator"
					:options="operatorOptions"
					:clearable="false"
					:inputLabel="t('pipelinq', 'Operator')"
					label="label"
					trackBy="value" />
				<NcTextField
					v-model="condition.value"
					:label="t('pipelinq', 'Value')" />
			</fieldset>

			<fieldset class="journey-form__group">
				<legend>{{ t('pipelinq', 'Action') }}</legend>
				<NcSelect
					v-model="actionKind"
					data-testid="journey-form-action"
					:options="actionOptions"
					:clearable="false"
					:inputLabel="t('pipelinq', 'What happens')"
					label="label"
					trackBy="value" />

				<template v-if="actionKind && actionKind.value === 'sendMailing'">
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

				<template v-else>
					<NcTextField
						v-model="action.taskSubject"
						:label="t('pipelinq', 'Task subject')" />
					<NcTextField
						v-model="action.taskAssignee"
						:label="t('pipelinq', 'Assign to')" />
				</template>
			</fieldset>

			<NcSelect
				v-model="status"
				:options="statusOptions"
				:clearable="false"
				:inputLabel="t('pipelinq', 'Status')"
				label="label"
				trackBy="value" />

			<div class="journey-form__actions">
				<NcButton
					variant="primary"
					data-testid="journey-form-save"
					:disabled="saving || !form.name"
					@click="save">
					{{ t('pipelinq', 'Save') }}
				</NcButton>
			</div>
		</form>

		<p
			v-if="flowMessage"
			class="journey-form__flow"
			data-testid="journey-form-flow"
			role="status">
			{{ flowMessage }}
		</p>

		<p v-if="error" class="journey-form__error" role="alert">{{ error }}</p>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import { flowStatusMessage } from '../../services/journeyLabels.js'
import { fetchJourney, saveJourney } from '../../services/journeysApi.js'

export default {
	name: 'JourneyFormView',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
	},

	data() {
		return {
			loading: true,
			saving: false,
			error: '',
			flowMessage: '',
			cron: '0 7 * * *',
			triggerKind: null,
			conditionOperator: null,
			actionKind: null,
			intent: null,
			status: null,
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
	},

	computed: {
		/**
		 * The journey being edited, empty when creating one.
		 *
		 * @return {string} The id.
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
		 */
		journeyId() {
			return this.$route?.params?.id || ''
		},

		/**
		 * @return {string} What the page is called.
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
		 */
		heading() {
			return this.journeyId
				? this.t('pipelinq', 'Edit journey')
				: this.t('pipelinq', 'New journey')
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

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the journey when editing one, and seed the pickers.
		 *
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
		 */
		async load() {
			this.triggerKind = this.triggerOptions[0]
			this.conditionOperator = this.operatorOptions[0]
			this.actionKind = this.actionOptions[0]
			this.intent = this.intentOptions[0]
			this.status = this.statusOptions[0]

			if (this.journeyId) {
				await this.fetchJourney()
			}

			this.loading = false
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
				this.flowMessage = this.describeFlow(journey)
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not load this journey.')
			}
		},

		/**
		 * Write the journey, which compiles it.
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
				this.flowMessage = this.describeFlow(journey)
				this.back()
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not save this journey.')
			}
			this.saving = false
		},

		/**
		 * Say in words what the flow engine did with this journey.
		 *
		 * @param {object} journey The stored journey.
		 * @return {string} The message, empty when it compiled cleanly.
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
		 */
		describeFlow(journey) {
			return flowStatusMessage(journey, this.t)
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

		/**
		 * Back to the journeys list.
		 *
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
		 */
		back() {
			this.$router.push({ name: 'Journeys' })
		},
	},
}
</script>

<style scoped>
.journey-form {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	padding: 1rem;
	max-width: 46rem;
}

.journey-form__header {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.journey-form__body {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.journey-form__group {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 0.75rem;
}

.journey-form__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.journey-form__flow {
	color: var(--color-warning-text, var(--color-text-maxcontrast));
}

.journey-form__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
