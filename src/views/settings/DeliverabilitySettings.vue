<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - DeliverabilitySettings is the admin panel for marketing-mail-transports:
  - it lists every mailTransport (instance mail server, a sender's Mail
  - account, or a bulk provider), lets an admin toggle active/default, and
  - shows a cached SPF/DKIM/DMARC verdict per sender domain. It manages the
  - mailTransport OpenRegister object directly via useObjectStore — only the
  - DNS check itself (POST .../check-deliverability) is a custom endpoint,
  - mirroring MessagingSettings.vue's channelProvider pattern.
  -
  - @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
  -->
<template>
	<div class="deliverability-settings">
		<NcSettingsSection
			:name="t('pipelinq', 'Mail transports')"
			:description="
				t(
					'pipelinq',
					'How your mailings are sent, and whether your sending domain will pass Gmail and Yahoo bulk sender checks.',
				)
			">
			<div class="deliverability-settings__toolbar">
				<NcButton :disabled="loading" @click="fetchTransports">
					{{ t('pipelinq', 'Refresh') }}
				</NcButton>
			</div>

			<NcLoadingIcon v-if="loading" :size="28" />

			<NcEmptyContent
				v-else-if="transports.length === 0"
				:description="t('pipelinq', 'No mail transports configured yet.')" />

			<div v-else class="deliverability-settings__table-wrap">
				<table class="deliverability-settings__table">
					<thead>
						<tr>
							<th scope="col">{{ t('pipelinq', 'Name') }}</th>
							<th scope="col">{{ t('pipelinq', 'Kind') }}</th>
							<th scope="col">{{ t('pipelinq', 'Sender domain') }}</th>
							<th scope="col">{{ t('pipelinq', 'Active') }}</th>
							<th scope="col">{{ t('pipelinq', 'Default') }}</th>
							<th scope="col">
								{{ t('pipelinq', 'Deliverability') }}
							</th>
							<th
								scope="col"
								class="deliverability-settings__col-actions">
								{{ t('pipelinq', 'Actions') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="transport in transports" :key="transport.id">
							<td>{{ transport.displayName }}</td>
							<td>{{ kindLabel(transport.kind) }}</td>
							<td>{{ transport.senderDomain || '—' }}</td>
							<td>
								<NcCheckboxRadioSwitch
									type="switch"
									:modelValue="!!transport.active"
									:aria-label="t('pipelinq', 'Active')"
									@update:modelValue="
										(v) => toggleActive(transport, v)
									">
									{{
										transport.active
											? t('pipelinq', 'Active')
											: t('pipelinq', 'Inactive')
									}}
								</NcCheckboxRadioSwitch>
							</td>
							<td>
								<NcCheckboxRadioSwitch
									type="switch"
									:modelValue="!!transport.default"
									:disabled="!!transport.default"
									:aria-label="t('pipelinq', 'Default transport')"
									@update:modelValue="() => setDefault(transport)">
									{{
										transport.default
											? t('pipelinq', 'Default')
											: t('pipelinq', 'Make default')
									}}
								</NcCheckboxRadioSwitch>
							</td>
							<td>
								<span
									v-if="!transport.senderDomain"
									class="deliverability-settings__badge deliverability-settings__badge--off">
									{{ t('pipelinq', 'No sender domain') }}
								</span>
								<span
									v-else
									class="deliverability-settings__verdict">
									<span
										class="deliverability-settings__badge"
										:class="dkimBadgeClass(transport)">
										{{
											transport.dkimVerified
												? t('pipelinq', 'DKIM found')
												: t('pipelinq', 'DKIM missing')
										}}
									</span>
									<span
										class="deliverability-settings__badge"
										:class="dmarcBadgeClass(transport)">
										{{ dmarcVerdictText(transport) }}
									</span>
								</span>
							</td>
							<td class="deliverability-settings__col-actions">
								<NcButton
									:disabled="checkingId === transport.id"
									@click="checkDeliverability(transport)">
									{{ t('pipelinq', 'Check now') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</NcSettingsSection>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcEmptyContent,
	NcLoadingIcon,
	NcSettingsSection,
} from '@nextcloud/vue'
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
		NcButton,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcSettingsSection,
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
		 */
		kindLabel(kind) {
			return t('pipelinq', kindLabel(kind))
		},

		/**
		 * @param {object} transport The transport row.
		 * @return {string} Human DMARC verdict text.
		 */
		dmarcVerdictText(transport) {
			return t('pipelinq', dmarcVerdictText(transport.dmarcStatus))
		},

		/**
		 * @param {object} transport The transport row.
		 * @return {string} CSS class for the DKIM badge.
		 */
		dkimBadgeClass(transport) {
			return dkimBadgeClass(transport.dkimVerified)
		},

		/**
		 * @param {object} transport The transport row.
		 * @return {string} CSS class for the DMARC badge.
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
	&__toolbar {
		display: flex;
		gap: 8px;
		margin-bottom: 12px;
	}

	&__table-wrap {
		overflow-x: auto;
	}

	&__table {
		width: 100%;
		border-collapse: collapse;

		th,
		td {
			padding: 8px 12px;
			text-align: left;
			vertical-align: middle;
			border-bottom: 1px solid var(--color-border);
		}
	}

	&__col-actions {
		white-space: nowrap;
	}

	&__verdict {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	&__badge {
		display: inline-block;
		padding: 2px 8px;
		border-radius: var(--border-radius-pill, 16px);
		font-size: 12px;

		&--on {
			background-color: var(--color-success, #46ba61);
			color: var(--color-primary-element-text, #fff);
		}

		&--off {
			background-color: var(--color-error, #e9322d);
			color: var(--color-primary-element-text, #fff);
		}

		&--sandbox {
			background-color: var(--color-warning, #e9a825);
			color: var(--color-primary-element-text, #fff);
		}
	}
}
</style>
