<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - DeliverabilitySettings is the admin panel for marketing-mail-transports:
  - it lists every mailTransport (instance mail server, a sender's Mail
  - account, or a bulk provider), lets an admin toggle active/default, and
  - shows a cached SPF/DKIM/DMARC verdict per sender domain, one table row
  - per transport as the PqBeheerMarketing board draws it. It manages the
  - mailTransport OpenRegister object directly via useObjectStore — only the
  - DNS check itself (POST .../check-deliverability) is a custom endpoint,
  - mirroring MessagingSettings.vue's channelProvider pattern.
  -
  - @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
  -->
<template>
	<div id="section-mail-transports" class="deliverability-settings">
		<CnSettingsSection
			class="cn-settings-section--wide"
			:name="t('pipelinq', 'Mail transports')"
			:description="
				t(
					'pipelinq',
					'How your mailings are sent, and whether your sending domain will pass Gmail and Yahoo bulk sender checks.',
				)
			">
			<template #actions>
				<NcButton
					class="cn-button--tonal"
					:disabled="loading"
					@click="fetchTransports">
					<template #icon>
						<Refresh :size="16" />
					</template>
					{{ t('pipelinq', 'Refresh') }}
				</NcButton>
			</template>

			<NcLoadingIcon v-if="loading" :size="28" />

			<NcEmptyContent
				v-else-if="transports.length === 0"
				:description="t('pipelinq', 'No mail transports configured yet.')" />

			<CnDataTable
				v-else
				class="deliverability-settings__table"
				data-testid="mail-transports"
				:columns="columns"
				:schema="tableSchema"
				:rows="tableRows"
				:rowClass="() => 'deliverability-settings__row'"
				filterable
				localHeaders>
				<template #column-kind="{ row }">
					{{ kindLabel(row.kind) }}
				</template>
				<template #column-deliverability="{ row }">
					<span
						v-if="!row.transport.senderDomain"
						class="cn-status-badge cn-status-badge--default">
						{{ t('pipelinq', 'No sender domain') }}
					</span>
					<span v-else class="deliverability-settings__verdict">
						<span
							class="cn-status-badge"
							:class="dkimBadgeClass(row.transport)">
							{{ dkimText(row.transport) }}
						</span>
						<span
							class="cn-status-badge"
							:class="dmarcBadgeClass(row.transport)">
							{{ dmarcVerdictText(row.transport) }}
						</span>
					</span>
				</template>
				<template #column-default="{ row }">
					<span
						v-if="row.default"
						class="cn-status-badge cn-status-badge--primary">
						{{ t('pipelinq', 'Default') }}
					</span>
					<NcButton v-else @click="setDefault(row.transport)">
						{{ t('pipelinq', 'Make default') }}
					</NcButton>
				</template>
				<template #column-active="{ row }">
					<NcCheckboxRadioSwitch
						type="switch"
						:modelValue="row.active"
						:aria-label="t('pipelinq', 'Active')"
						@update:modelValue="(v) => toggleActive(row.transport, v)" />
				</template>
				<template #actions-header>
					{{ t('pipelinq', 'Actions') }}
				</template>
				<template #row-actions="{ row }">
					<NcButton
						:disabled="checkingId === row.id"
						@click="checkDeliverability(row.transport)">
						{{ t('pipelinq', 'Check now') }}
					</NcButton>
				</template>
			</CnDataTable>
		</CnSettingsSection>
	</div>
</template>

<script>
import { CnDataTable, CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcEmptyContent,
	NcLoadingIcon,
} from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import { useObjectStore } from '../../store/modules/object.js'
import {
	dkimBadgeClass,
	dmarcBadgeClass,
	dmarcVerdictText,
	kindLabel,
} from '../../utils/deliverabilityLabels.js'

export default {
	name: 'DeliverabilitySettings',
	components: {
		CnDataTable,
		CnSettingsSection,
		NcButton,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		Refresh,
	},

	data() {
		return {
			transports: [],
			loading: false,
			checkingId: null,
		}
	},

	computed: {
		/**
		 * The table's columns, every one sortable and filterable from its head
		 * except the deliverability verdict, which only filters.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
		 */
		columns() {
			return [
				{ key: 'displayName', label: t('pipelinq', 'Name') },
				{ key: 'kind', label: t('pipelinq', 'Kind') },
				{ key: 'senderDomain', label: t('pipelinq', 'Sender domain') },
				{ key: 'deliverability', label: t('pipelinq', 'Deliverability'), sortable: false },
				{ key: 'default', label: t('pipelinq', 'Default') },
				{ key: 'active', label: t('pipelinq', 'Active') },
			]
		},

		/**
		 * The fields the heads sort and filter on; a kind filters by its label.
		 *
		 * @return {object}
		 *
		 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
		 */
		tableSchema() {
			const kinds = ['instance', 'mailAccount', 'provider']
			return {
				properties: {
					displayName: { type: 'string' },
					kind: { type: 'string', enum: kinds, 'x-enum-labels': Object.fromEntries(kinds.map((kind) => [kind, this.kindLabel(kind)])) },
					senderDomain: { type: 'string' },
					deliverability: { type: 'string' },
					default: { type: 'boolean' },
					active: { type: 'boolean' },
				},
			}
		},

		/**
		 * One row per transport, its verdict in words for the head filter, the
		 * transport itself kept for the cell controls.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
		 */
		tableRows() {
			return this.transports.map((transport) => ({
				id: transport.id,
				displayName: transport.displayName,
				kind: transport.kind,
				senderDomain: transport.senderDomain || '—',
				deliverability: transport.senderDomain
					? this.dkimText(transport) + ', ' + this.dmarcVerdictText(transport)
					: t('pipelinq', 'No sender domain'),
				default: !!transport.default,
				active: !!transport.active,
				transport,
			}))
		},

		/**
		 * @spec openspec/changes/marketing-mail-transports/tasks.md#4.2
		 */
		objectStore() {
			return useObjectStore()
		},
	},

	async mounted() {
		await this.fetchTransports()
	},

	methods: {
		/**
		 * @param {string} kind The transport kind.
		 * @return {string} Human label for a transport kind.
		 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
		 */
		kindLabel(kind) {
			return t('pipelinq', kindLabel(kind))
		},

		/**
		 * @param {object} transport The transport row.
		 * @return {string} Human DMARC verdict text.
		 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
		 */
		dmarcVerdictText(transport) {
			return t('pipelinq', dmarcVerdictText(transport.dmarcStatus))
		},

		/**
		 * Whether a DKIM record was found, in words.
		 *
		 * @param {object} transport The transport.
		 * @return {string}
		 *
		 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
		 */
		dkimText(transport) {
			return transport.dkimVerified ? t('pipelinq', 'DKIM found') : t('pipelinq', 'DKIM missing')
		},

		/**
		 * @param {object} transport The transport row.
		 * @return {string} CSS class for the DKIM badge.
		 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
		 */
		dkimBadgeClass(transport) {
			return dkimBadgeClass(transport.dkimVerified)
		},

		/**
		 * @param {object} transport The transport row.
		 * @return {string} CSS class for the DMARC badge.
		 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
		 */
		dmarcBadgeClass(transport) {
			return dmarcBadgeClass(transport.dmarcStatus)
		},

		/**
		 * @spec openspec/changes/marketing-mail-transports/tasks.md#4.2
		 */
		async fetchTransports() {
			this.loading = true
			try {
				this.transports =
					(await this.objectStore.fetchCollection('mailTransport', {
						_limit: 200,
					})) || []
			} catch {
				this.transports = []
				showError(t('pipelinq', 'Failed to load mail transports.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} transport The transport to toggle.
		 * @param {boolean} value The new active state.
		 * @spec openspec/changes/marketing-mail-transports/tasks.md#4.2
		 */
		async toggleActive(transport, value) {
			try {
				await this.objectStore.saveObject('mailTransport', {
					...transport,
					active: value,
				})
				showSuccess(t('pipelinq', 'Transport updated.'))
				await this.fetchTransports()
			} catch (e) {
				showError(
					e.message || t('pipelinq', 'Failed to update the transport.'),
				)
			}
		},

		/**
		 * Make one transport the default, unsetting default on every other
		 * transport first so exactly one row carries default=true.
		 *
		 * @param {object} transport The transport to make default.
		 * @spec openspec/changes/marketing-mail-transports/tasks.md#4.2
		 */
		async setDefault(transport) {
			try {
				const others = this.transports.filter(
					(t) => t.id !== transport.id && t.default,
				)
				for (const other of others) {
					await this.objectStore.saveObject('mailTransport', {
						...other,
						default: false,
					})
				}
				await this.objectStore.saveObject('mailTransport', {
					...transport,
					default: true,
				})
				showSuccess(t('pipelinq', 'Default transport updated.'))
				await this.fetchTransports()
			} catch (e) {
				showError(
					e.message
						|| t('pipelinq', 'Failed to set the default transport.'),
				)
			}
		},

		/**
		 * @param {object} transport The transport whose sender domain to check.
		 * @spec openspec/changes/marketing-mail-transports/tasks.md#4.1
		 */
		async checkDeliverability(transport) {
			this.checkingId = transport.id
			try {
				const { data } = await axios.post(
					generateUrl(
						'/apps/pipelinq/api/mail-transports/{id}/check-deliverability',
						{
							id: transport.id,
						},
					),
					{ refresh: true },
				)
				const index = this.transports.findIndex((t) => t.id === transport.id)
				if (index !== -1) {
					this.transports.splice(index, 1, {
						...this.transports[index],
						dkimVerified: data.dkimVerified,
						dmarcStatus: data.dmarcStatus,
						deliverabilityCheckedAt: data.checkedAt,
					})
				}
			} catch (e) {
				showError(
					e.message || t('pipelinq', 'Failed to check deliverability.'),
				)
			} finally {
				this.checkingId = null
			}
		},
	},
}
</script>

<style scoped lang="scss">
.deliverability-settings {
	&__table {
		width: 100%;
		border-collapse: collapse;
	}

	&__verdict {
		display: inline-flex;
		flex-wrap: wrap;
		gap: 4px;
	}
}
</style>
