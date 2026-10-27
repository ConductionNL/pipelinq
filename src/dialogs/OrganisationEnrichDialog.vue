<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Fill the missing registry fields of the selected organisations from the
  - KvK (board PqOrganisatiesVerrijken). Each proposal is a field the KvK knows
  - and the organisation lacks or holds differently; a different value starts
  - unticked. Only what is ticked is written.
  -
  - @spec openspec/changes/organisation-enrichment/specs/client-management/spec.md
  -->
<template>
	<CnDialog
		:name="t('pipelinq', 'Fill in missing details')"
		:eyebrow="n('pipelinq', 'Organisations · %n selected', 'Organisations · %n selected', selectedIds.length)"
		size="large"
		width="wizard"
		:noClose="saving"
		@closing="$emit('close')">
		<div class="enrich">
			<p class="enrich__intro">
				{{ n('pipelinq', '%n organisation selected. Pipelinq looked it up in the KvK Handelsregister and only fills empty fields, unless you tick a change.', '%n organisations selected. Pipelinq looked them up in the KvK Handelsregister and only fills empty fields, unless you tick a change.', selectedIds.length) }}
			</p>

			<NcLoadingIcon v-if="loading" :size="28" />

			<template v-else>
				<div class="enrich__pills" data-testid="enrich-summary">
					<CnStatusBadge :label="n('pipelinq', '%n proposal', '%n proposals', proposals.length)" variant="primary" />
					<CnStatusBadge
						v-if="differing > 0"
						:label="n('pipelinq', '%n differs from your details', '%n differ from your details', differing)"
						variant="warning" />
					<CnStatusBadge
						v-if="notFound.length"
						:label="t('pipelinq', '{count} not found: {names}', { count: notFound.length, names: notFound.join(', ') })"
						variant="default" />
				</div>

				<table v-if="proposals.length" class="enrich__table" data-testid="enrich-proposals">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Use') }}</th>
							<th>{{ t('pipelinq', 'Organisation') }}</th>
							<th>{{ t('pipelinq', 'Field') }}</th>
							<th>{{ t('pipelinq', 'Now') }}</th>
							<th>{{ t('pipelinq', 'Found') }}</th>
							<th>{{ t('pipelinq', 'Source') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(proposal, index) in proposals" :key="proposal.clientId + proposal.field">
							<td>
								<input
									v-model="accepted[index]"
									type="checkbox"
									:aria-label="t('pipelinq', 'Use {field} for {name}', { field: fieldLabel(proposal.field), name: proposal.name })">
							</td>
							<td><strong>{{ proposal.name }}</strong></td>
							<td>{{ fieldLabel(proposal.field) }}</td>
							<td>
								<span v-if="proposal.current === ''" class="enrich__empty">{{ t('pipelinq', 'empty') }}</span>
								<template v-else>
									{{ proposal.current }}
								</template>
							</td>
							<td>{{ proposal.found }}</td>
							<td>
								{{ proposal.source }}
								<CnStatusBadge
									v-if="proposal.differs"
									:label="t('pipelinq', 'differs from what you have')"
									variant="warning" />
							</td>
						</tr>
					</tbody>
				</table>

				<NcNoteCard type="info">
					{{ t('pipelinq', 'Every field filled in keeps its source and date in the organisation\'s history. Personal data from the BRP is never filled in bulk.') }}
				</NcNoteCard>
			</template>
		</div>

		<template #actions>
			<NcButton
				:disabled="loading || !proposals.length"
				data-testid="enrich-download"
				@click="download">
				{{ t('pipelinq', 'Download proposals') }}
			</NcButton>
			<NcButton :disabled="saving" @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="saving || acceptedCount === 0"
				data-testid="enrich-apply"
				@click="apply">
				{{ n('pipelinq', 'Fill in %n field', 'Fill in %n fields', acceptedCount) }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog, CnStatusBadge } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

const BASE = '/apps/pipelinq/api/organisations/enrich'

/** The words a proposal's field reads as. */
const FIELD_LABELS = {
	kvkNumber: 'KvK number',
	legalForm: 'Legal form',
	place: 'Place',
}

export default {
	name: 'OrganisationEnrichDialog',
	components: { CnDialog, CnStatusBadge, NcButton, NcLoadingIcon, NcNoteCard },

	props: {
		/** The selected organisations, from the list's bulk action. */
		selectedIds: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['close'],

	data() {
		return {
			loading: true,
			saving: false,
			proposals: [],
			notFound: [],
			accepted: [],
		}
	},

	computed: {
		/**
		 * @return {number} How many proposals hold a value different from the organisation's.
		 * @spec openspec/changes/organisation-enrichment/specs/client-management/spec.md
		 */
		differing() {
			return this.proposals.filter((proposal) => proposal.differs).length
		},

		/**
		 * @return {number} How many proposals are ticked.
		 * @spec openspec/changes/organisation-enrichment/specs/client-management/spec.md
		 */
		acceptedCount() {
			return this.accepted.filter(Boolean).length
		},
	},

	async created() {
		await this.load()
	},

	methods: {
		/**
		 * Ask for the proposals. An empty field starts ticked, a different value unticked.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/organisation-enrichment/specs/client-management/spec.md
		 */
		async load() {
			this.loading = true
			try {
				const { data } = await axios.post(generateUrl(`${BASE}/proposals`), { ids: this.selectedIds })
				this.proposals = Array.isArray(data?.proposals) ? data.proposals : []
				this.notFound = Array.isArray(data?.notFound) ? data.notFound : []
				this.accepted = this.proposals.map((proposal) => !proposal.differs)
			} catch {
				showError(this.t('pipelinq', 'Could not look the organisations up.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {string} field A client field.
		 * @return {string} Its label.
		 * @spec openspec/changes/organisation-enrichment/specs/client-management/spec.md
		 */
		fieldLabel(field) {
			return FIELD_LABELS[field] ? this.t('pipelinq', FIELD_LABELS[field]) : field
		},

		/**
		 * Write the ticked proposals, then let the list read them again.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/organisation-enrichment/specs/client-management/spec.md
		 */
		async apply() {
			this.saving = true
			try {
				const changes = this.proposals
					.filter((proposal, index) => this.accepted[index])
					.map((proposal) => ({ clientId: proposal.clientId, field: proposal.field, value: proposal.found }))
				const { data } = await axios.post(generateUrl(`${BASE}/apply`), { changes })
				showSuccess(this.n('pipelinq', '%n field filled in.', '%n fields filled in.', Number(data?.updated) || 0))
				emit('cn:page:refresh', {})
				this.$emit('close')
			} catch {
				showError(this.t('pipelinq', 'Could not fill in the details.'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * The proposals as a CSV file.
		 *
		 * @spec openspec/changes/organisation-enrichment/specs/client-management/spec.md
		 */
		download() {
			const quote = (value) => '"' + String(value ?? '').replace(/"/g, '""') + '"'
			const lines = [[this.t('pipelinq', 'Organisation'), this.t('pipelinq', 'Field'), this.t('pipelinq', 'Now'), this.t('pipelinq', 'Found'), this.t('pipelinq', 'Source')].map(quote).join(',')]
			for (const proposal of this.proposals) {
				lines.push([proposal.name, this.fieldLabel(proposal.field), proposal.current, proposal.found, proposal.source].map(quote).join(','))
			}
			const blob = new Blob([lines.join('\n') + '\n'], { type: 'text/csv' })
			const link = document.createElement('a')
			link.href = URL.createObjectURL(blob)
			link.download = 'organisation-proposals.csv'
			link.click()
			URL.revokeObjectURL(link.href)
		},
	},
}
</script>

<style scoped>
.enrich {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.enrich__pills {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.enrich__table {
	width: 100%;
	border-collapse: collapse;
}

.enrich__table th,
.enrich__table td {
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: middle;
}

.enrich__empty {
	color: var(--color-text-maxcontrast);
}
</style>
