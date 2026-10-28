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

			<table
				v-else
				class="deliverability-settings__table"
				data-testid="mail-transports">
				<thead>
					<tr>
						<th scope="col">{{ t('pipelinq', 'Name') }}</th>
						<th scope="col">{{ t('pipelinq', 'Kind') }}</th>
						<th scope="col">{{ t('pipelinq', 'Sender domain') }}</th>
						<th scope="col">{{ t('pipelinq', 'Deliverability') }}</th>
						<th scope="col">{{ t('pipelinq', 'Default') }}</th>
						<th scope="col">{{ t('pipelinq', 'Active') }}</th>
						<th scope="col">{{ t('pipelinq', 'Actions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="transport in transports"
						:key="transport.id"
						data-testid="mail-transport">
						<td>{{ transport.displayName }}</td>
						<td>{{ kindLabel(transport.kind) }}</td>
						<td>{{ transport.senderDomain || '—' }}</td>
						<td>
							<span
								v-if="!transport.senderDomain"
								class="cn-status-badge cn-status-badge--default">
								{{ t('pipelinq', 'No sender domain') }}
							</span>
							<span v-else class="deliverability-settings__verdict">
								<span
									class="cn-status-badge"
									:class="dkimBadgeClass(transport)">
									{{
										transport.dkimVerified
											? t('pipelinq', 'DKIM found')
											: t('pipelinq', 'DKIM missing')
									}}
								</span>
								<span
									class="cn-status-badge"
									:class="dmarcBadgeClass(transport)">
									{{ dmarcVerdictText(transport) }}
								</span>
							</span>
						</td>
						<td>
							<span
								v-if="transport.default"
								class="cn-status-badge cn-status-badge--primary">
								{{ t('pipelinq', 'Default') }}
							</span>
							<NcButton v-else @click="setDefault(transport)">
								{{ t('pipelinq', 'Make default') }}
							</NcButton>
						</td>
						<td>
							<NcCheckboxRadioSwitch
								type="switch"
								:modelValue="!!transport.active"
								:aria-label="t('pipelinq', 'Active')"
								@update:modelValue="(v) => toggleActive(transport, v)" />
						</td>
						<td>
							<NcButton
								:disabled="checkingId === transport.id"
								@click="checkDeliverability(transport)">
								{{ t('pipelinq', 'Check now') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</CnSettingsSection>
	</div>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
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
