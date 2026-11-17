<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.

The Identifiers section on ClientDetail (clients-registry-numbers-and-enrichment,
design D5): the organisation's official numbers (KvK, RSIN, VAT, DUNS, OIN), each
with the status, date and source of its last register check, an Add form and a
Check action on a KvK or VAT number. The format of a number is checked by the
server on save (ClientIdentifierFormatGuard); a refusal is shown here as it comes.

@spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-3.1
-->
<template>
	<div class="client-identifiers" data-testid="client-identifiers">
		<p v-if="rows.length === 0" class="client-identifiers__empty">
			{{ t('pipelinq', 'No official numbers yet.') }}
		</p>
		<table v-else class="client-identifiers__table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('pipelinq', 'Scheme') }}
					</th>
					<th scope="col">
						{{ t('pipelinq', 'Number') }}
					</th>
					<th scope="col">
						{{ t('pipelinq', 'Check status') }}
					</th>
					<th scope="col">
						{{ t('pipelinq', 'Checked at') }}
					</th>
					<th scope="col">
						<span class="hidden-visually">{{ t('pipelinq', 'Actions') }}</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(row, index) in rows" :key="row.scheme + ':' + row.value" data-testid="client-identifier-row">
					<td>{{ schemeLabel(row.scheme) }}</td>
					<td class="client-identifiers__value">
						{{ row.value }}
					</td>
					<td>
						<span
							class="client-identifiers__status"
							:class="'client-identifiers__status--' + statusOf(row)"
							data-testid="client-identifier-status">
							{{ statusLabel(statusOf(row)) }}
						</span>
						<span v-if="sourceNote(row)" class="client-identifiers__source">
							{{ sourceNote(row) }}
						</span>
					</td>
					<td>{{ dateLabel(row.checkedAt) }}</td>
					<td class="client-identifiers__actions">
						<NcButton
							v-if="isCheckable(row)"
							variant="tertiary"
							:disabled="busy"
							:aria-label="t('pipelinq', 'Check {number}', { number: row.value })"
							data-testid="client-identifier-check"
							@click="check(row)">
							{{ t('pipelinq', 'Check') }}
						</NcButton>
						<NcButton
							variant="tertiary"
							:disabled="busy"
							:aria-label="t('pipelinq', 'Remove {number}', { number: row.value })"
							@click="remove(index)">
							{{ t('pipelinq', 'Remove') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<form class="client-identifiers__add" data-testid="client-identifier-add" @submit.prevent="add">
			<NcSelect
				v-model="newScheme"
				class="client-identifiers__scheme"
				:options="schemeOptions"
				:inputLabel="t('pipelinq', 'Scheme')"
				:clearable="false"
				:disabled="busy" />
			<NcTextField
				v-model="newValue"
				class="client-identifiers__number"
				:label="t('pipelinq', 'Number')"
				:disabled="busy" />
			<NcButton type="submit" variant="secondary" :disabled="busy || newValue.trim() === ''">
				<template #icon>
					<NcLoadingIcon v-if="busy" :size="18" />
				</template>
				{{ t('pipelinq', 'Add') }}
			</NcButton>
		</form>
		<p v-if="error" class="client-identifiers__error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import { checkableScheme, normaliseNumber, refusalMessage } from '../../utils/clientIdentifiers.js'

export default {
	name: 'ClientIdentifiersSection',

	components: { NcButton, NcLoadingIcon, NcSelect, NcTextField },

	props: {
		/** The client's id (`@objectId`). */
		clientId: {
			type: String,
			required: true,
		},

		/** The client's `identifiers` (`@object.identifiers`). */
		identifiers: {
			type: Array,
			default: () => [],
		},

		/** The register slug the client lives in. */
		register: {
			type: String,
			default: 'pipelinq',
		},

		/** The schema slug of the client. */
		schema: {
			type: String,
			default: 'client',
		},
	},

	emits: ['saved'],

	data() {
		return {
			rows: Array.isArray(this.identifiers) ? this.identifiers.map((row) => ({ ...row })) : [],
			newScheme: null,
			newValue: '',
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The schemes to pick from.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-3.1
		 */
		schemeOptions() {
			return ['kvk', 'rsin', 'vat', 'duns', 'oin'].map((id) => ({ id, label: this.schemeLabel(id) }))
		},
	},

	watch: {
		identifiers(next) {
			this.rows = Array.isArray(next) ? next.map((row) => ({ ...row })) : []
		},
	},

	created() {
		this.newScheme = this.schemeOptions[0]
	},

	methods: {
		/**
		 * A scheme in words.
		 *
		 * @param {string} scheme The scheme.
		 * @return {string}
		 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-3.1
		 */
		schemeLabel(scheme) {
			return {
				kvk: this.t('pipelinq', 'KvK number'),
				rsin: this.t('pipelinq', 'RSIN'),
				vat: this.t('pipelinq', 'VAT number'),
				duns: this.t('pipelinq', 'DUNS number'),
				oin: this.t('pipelinq', 'OIN'),
			}[scheme] || scheme
		},

		/**
		 * A row's status, unchecked when it has none.
		 *
		 * @param {object} row The identifier.
		 * @return {string}
		 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-3.1
		 */
		statusOf(row) {
			return ['valid', 'invalid', 'unavailable'].includes(row.checkStatus) ? row.checkStatus : 'unchecked'
		},

		/**
		 * A status in words.
		 *
		 * @param {string} status The status.
		 * @return {string}
		 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-3.1
		 */
		statusLabel(status) {
			return {
				valid: this.t('pipelinq', 'Valid'),
				invalid: this.t('pipelinq', 'Not valid'),
				unavailable: this.t('pipelinq', 'Register unavailable'),
				unchecked: this.t('pipelinq', 'Not checked'),
			}[status]
		},

		/**
		 * Where the number was checked, or why it could not be.
		 *
		 * @param {object} row The identifier.
		 * @return {string}
		 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-3.1
		 */
		sourceNote(row) {
			if (row.checkSource === 'shillinq not installed') {
				return this.t('pipelinq', 'VAT numbers are checked when shillinq is installed.')
			}
			return row.checkSource ? this.t('pipelinq', 'Checked with {source}', { source: row.checkSource }) : ''
		},

		/**
		 * A check time as a local date.
		 *
		 * @param {string} value The ISO time.
		 * @return {string}
		 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-3.1
		 */
		dateLabel(value) {
			if (typeof value !== 'string' || value === '') return ''
			const when = new Date(value)
			return Number.isNaN(when.getTime()) ? '' : when.toLocaleDateString()
		},

		/**
		 * Whether a row can be checked against a register.
		 *
		 * @param {object} row The identifier.
		 * @return {boolean}
		 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-3.1
		 */
		isCheckable(row) {
			return checkableScheme(row.scheme)
		},

		/**
		 * Add the typed number and save the list.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-3.1
		 */
		async add() {
			const scheme = this.newScheme ? this.newScheme.id : 'kvk'
			const value = normaliseNumber(scheme, this.newValue)
			if (value === '') return
			const saved = await this.save([...this.rows, { scheme, value, checkStatus: 'unchecked' }])
			if (saved) this.newValue = ''
		},

		/**
		 * Remove one number and save the list.
		 *
		 * @param {number} index The row.
		 * @return {Promise<void>}
		 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-3.1
		 */
		async remove(index) {
			await this.save(this.rows.filter((row, position) => position !== index))
		},

		/**
		 * Write the list onto the client; the server refuses a malformed number.
		 *
		 * @param {Array<object>} list The new list.
		 * @return {Promise<boolean>} Whether it was saved.
		 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-3.1
		 */
		async save(list) {
			this.busy = true
			this.error = ''
			try {
				await axios.patch(
					generateUrl(`/apps/openregister/api/objects/${this.register}/${this.schema}/${encodeURIComponent(this.clientId)}`),
					{ identifiers: list },
				)
				this.rows = list
				this.$emit('saved', list)
				return true
			} catch (error) {
				this.error = refusalMessage(error, this.t('pipelinq', 'The number could not be saved.'))
				return false
			} finally {
				this.busy = false
			}
		},

		/**
		 * Check one number against its register and show the outcome.
		 *
		 * @param {object} row The identifier.
		 * @return {Promise<void>}
		 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-3.1
		 */
		async check(row) {
			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.post(
					generateUrl(`/apps/pipelinq/api/clients/${encodeURIComponent(this.clientId)}/identifiers/check`),
					{ scheme: row.scheme, value: row.value },
				)
				const checked = data && data.identifier ? data.identifier : null
				if (checked) {
					this.rows = this.rows.map((item) => (item === row ? { ...item, ...checked } : item))
				}
			} catch (error) {
				this.error = refusalMessage(error, this.t('pipelinq', 'The number could not be checked.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.client-identifiers__table {
	width: 100%;
	border-collapse: collapse;
}

.client-identifiers__table th,
.client-identifiers__table td {
	padding: 6px 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.client-identifiers__value {
	font-variant-numeric: tabular-nums;
}

.client-identifiers__status {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.client-identifiers__status--valid {
	background: var(--color-success);
	color: var(--color-primary-element-text);
}

.client-identifiers__status--invalid {
	background: var(--color-error);
	color: var(--color-primary-element-text);
}

.client-identifiers__status--unavailable {
	background: var(--color-warning);
	color: var(--color-main-text);
}

.client-identifiers__source {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.client-identifiers__actions {
	display: flex;
	gap: 4px;
	justify-content: flex-end;
}

.client-identifiers__add {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: flex-end;
	margin-top: 12px;
}

.client-identifiers__scheme {
	min-width: 180px;
}

.client-identifiers__number {
	flex: 1 1 200px;
}

.client-identifiers__empty,
.client-identifiers__error {
	margin: 8px 0;
}

.client-identifiers__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
