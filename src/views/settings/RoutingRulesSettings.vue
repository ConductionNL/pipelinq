<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - RoutingRulesSettings edits the rules of the shipped "Route incoming tickets"
  - flow as rows (requests-inbound-to-queue D7): channel, sender address,
  - sender domain, mailbox and client segment decide the category and priority
  - of a new inbound ticket, and the first row that matches wins. The flow is
  - OpenRegister's (ADR-022): the first save adopts it, which switches routing
  - on, and every save updates and publishes it. OpenRegister refuses a table
  - it cannot run and the refusal is shown as it comes.
  -
  - @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
  -->
<template>
	<NcSettingsSection
		id="section-routing-rules"
		:name="t('pipelinq', 'Routing rules')"
		:description="
			t(
				'pipelinq',
				'Give a new ticket from mail, WhatsApp, SMS, Facebook or Instagram a category and a priority. The rules are tried from top to bottom and the first one that matches is used. Leave a field empty to match anything.',
			)
		">
		<NcLoadingIcon v-if="loading" />
		<NcNoteCard v-else-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>
		<NcNoteCard v-else-if="!flow" type="warning" data-testid="routing-rules-missing">
			{{ t('pipelinq', 'The routing flow is not installed yet. Re-import the register configuration, then open this page again.') }}
		</NcNoteCard>
		<template v-else>
			<NcNoteCard v-if="!flow.enabled" type="info" data-testid="routing-rules-off">
				{{ t('pipelinq', 'Routing is off. New tickets land in the Queue without a category. Saving the rules switches routing on.') }}
			</NcNoteCard>

			<p v-if="rows.length === 0" class="routing-rules__empty">
				{{ t('pipelinq', 'There are no rules yet.') }}
			</p>
			<fieldset
				v-for="(row, index) in rows"
				:key="index"
				class="routing-rules__row"
				data-testid="routing-rule">
				<legend>{{ t('pipelinq', 'Rule {number}', { number: index + 1 }) }}</legend>
				<div class="routing-rules__fields">
					<NcSelect
						:modelValue="row.channel || null"
						:options="channels"
						:inputLabel="t('pipelinq', 'Channel')"
						:placeholder="t('pipelinq', 'Any channel')"
						@update:modelValue="update(index, 'channel', $event || '')" />
					<NcTextField
						:modelValue="row.fromAddress"
						:label="t('pipelinq', 'Sender address')"
						@update:modelValue="update(index, 'fromAddress', $event)" />
					<NcTextField
						:modelValue="row.fromDomain"
						:label="t('pipelinq', 'Sender domain')"
						@update:modelValue="update(index, 'fromDomain', $event)" />
					<NcTextField
						:modelValue="row.mailbox"
						:label="t('pipelinq', 'Mailbox or account')"
						@update:modelValue="update(index, 'mailbox', $event)" />
					<NcTextField
						:modelValue="row.clientSegment"
						:label="t('pipelinq', 'Client segment')"
						@update:modelValue="update(index, 'clientSegment', $event)" />
					<NcTextField
						:modelValue="row.category"
						:label="t('pipelinq', 'Sets category')"
						@update:modelValue="update(index, 'category', $event)" />
					<NcSelect
						:modelValue="row.priority"
						:options="priorities"
						:inputLabel="t('pipelinq', 'Sets priority')"
						:clearable="false"
						@update:modelValue="update(index, 'priority', $event || 'normal')" />
				</div>
				<div class="routing-rules__actions">
					<NcButton
						variant="tertiary"
						:disabled="index === 0"
						@click="move(index, -1)">
						{{ t('pipelinq', 'Move up') }}
					</NcButton>
					<NcButton
						variant="tertiary"
						:disabled="index === rows.length - 1"
						@click="move(index, 1)">
						{{ t('pipelinq', 'Move down') }}
					</NcButton>
					<NcButton variant="tertiary" @click="remove(index)">
						{{ t('pipelinq', 'Remove rule') }}
					</NcButton>
				</div>
			</fieldset>

			<p class="routing-rules__hint">
				{{ t('pipelinq', 'Everything else gets no category and normal priority.') }}
			</p>

			<NcNoteCard v-for="problem in problemTexts" :key="problem" type="warning">
				{{ problem }}
			</NcNoteCard>
			<NcNoteCard v-if="saveError" type="error" data-testid="routing-rules-refused">
				{{ saveError }}
			</NcNoteCard>
			<NcNoteCard v-if="saved" type="success">
				{{ t('pipelinq', 'The routing rules are saved and in use.') }}
			</NcNoteCard>

			<div class="routing-rules__actions">
				<NcButton variant="secondary" @click="add">
					{{ t('pipelinq', 'Add rule') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="saving || problems.length > 0"
					data-testid="routing-rules-save"
					@click="save">
					{{ flow.enabled ? t('pipelinq', 'Save routing rules') : t('pipelinq', 'Save rules and switch routing on') }}
				</NcButton>
			</div>
		</template>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcSettingsSection,
	NcTextField,
} from '@nextcloud/vue'
import {
	CHANNELS,
	emptyRow,
	findRoutingFlow,
	FLOW_APP,
	moveRow,
	nodesWithRows,
	PRIORITIES,
	rowProblems,
	rowsFromTable,
	tableOf,
} from '../../services/routingRules.js'

export default {
	name: 'RoutingRulesSettings',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcSettingsSection,
		NcTextField,
	},

	data() {
		return {
			flow: null,
			rows: [],
			loading: true,
			saving: false,
			saved: false,
			loadError: '',
			saveError: '',
			channels: CHANNELS,
			priorities: PRIORITIES,
		}
	},

	computed: {
		/**
		 * What stops the rows from being saved.
		 *
		 * @return {Array<{row: number, problem: string}>} The problems.
		 *
		 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
		 */
		problems() {
			return rowProblems(this.rows)
		},

		/**
		 * The problems as sentences.
		 *
		 * @return {Array<string>} One sentence per problem.
		 *
		 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
		 */
		problemTexts() {
			return this.problems.map(({ row, problem }) => (problem === 'no-condition'
				? t('pipelinq', 'Rule {number} has no condition, so it would match every ticket. Fill in at least one field.', { number: row })
				: t('pipelinq', 'Rule {number} sets no category.', { number: row })))
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * An OpenRegister flow endpoint.
		 *
		 * @param {string} path The path under /api/flows.
		 * @return {string} The URL.
		 *
		 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
		 */
		flowUrl(path = '') {
			return generateUrl('/apps/openregister/api/flows' + path)
		},

		/**
		 * Read the shipped routing flow and its rules.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
		 */
		async load() {
			this.loading = true
			this.loadError = ''
			try {
				const { data } = await axios.get(this.flowUrl(), { params: { app: FLOW_APP, limit: 200 } })
				this.flow = findRoutingFlow(data?.results)
				this.rows = this.flow ? rowsFromTable(tableOf(this.flow)) : []
			} catch {
				this.loadError = t('pipelinq', 'Could not load the routing rules.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Change one field of one row.
		 *
		 * @param {number} index The row.
		 * @param {string} key The field.
		 * @param {string} value The new value.
		 * @return {void}
		 *
		 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
		 */
		update(index, key, value) {
			this.saved = false
			this.rows = this.rows.map((row, at) => (at === index ? { ...row, [key]: value } : row))
		},

		/**
		 * Add an empty rule at the bottom.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
		 */
		add() {
			this.saved = false
			this.rows = [...this.rows, emptyRow()]
		},

		/**
		 * Move a rule up or down.
		 *
		 * @param {number} index The rule.
		 * @param {number} step -1 for up, 1 for down.
		 * @return {void}
		 *
		 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
		 */
		move(index, step) {
			this.saved = false
			this.rows = moveRow(this.rows, index, step)
		},

		/**
		 * Remove a rule.
		 *
		 * @param {number} index The rule.
		 * @return {void}
		 *
		 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
		 */
		remove(index) {
			this.saved = false
			this.rows = this.rows.filter((row, at) => at !== index)
		},

		/**
		 * Adopt the flow when it is still off, store the rules and publish them.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
		 */
		async save() {
			if (this.problems.length > 0) {
				return
			}
			this.saving = true
			this.saved = false
			this.saveError = ''
			const id = encodeURIComponent(String(this.flow.uuid ?? this.flow.id))
			try {
				if (!this.flow.enabled) {
					await axios.post(this.flowUrl(`/${id}/adopt`))
				}
				const { data } = await axios.put(this.flowUrl(`/${id}`), { nodes: nodesWithRows(this.flow, this.rows) })
				await axios.post(this.flowUrl(`/${id}/publish`))
				this.flow = { ...this.flow, ...(data && typeof data === 'object' ? data : {}), enabled: true }
				this.rows = rowsFromTable(tableOf(this.flow))
				this.saved = true
			} catch (error) {
				const body = error?.response?.data
				this.saveError = body?.error || body?.message || t('pipelinq', 'Could not save the routing rules.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.routing-rules__row {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 3);
	margin-bottom: calc(var(--default-grid-baseline) * 3);
}

.routing-rules__fields {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: calc(var(--default-grid-baseline) * 2);
}

.routing-rules__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-top: calc(var(--default-grid-baseline) * 2);
}

.routing-rules__empty,
.routing-rules__hint {
	color: var(--color-text-maxcontrast);
}
</style>
