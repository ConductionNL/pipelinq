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
	<div id="section-mail-transports" class="deliverability-settings">
		<NcSettingsSection
			class="cn-settings-section--wide"
			:name="t('pipelinq', 'Mail transports')"
			:description="
				t(
					'pipelinq',
					'How your mailings are sent, and whether your sending domain will pass Gmail and Yahoo bulk sender checks.',
				)
			">
			<div class="deliverability-settings__toolbar">
				<NcButton class="cn-button--tonal" :disabled="loading" @click="fetchTransports">
					{{ t('pipelinq', 'Refresh') }}
				</NcButton>
			</div>

			<NcLoadingIcon v-if="loading" :size="28" />

			<NcEmptyContent
				v-else-if="transports.length === 0"
				:description="t('pipelinq', 'No mail transports configured yet.')" />

			<ul v-else class="deliverability-settings__records">
				<li
					v-for="transport in transports"
					:key="transport.id"
					class="deliverability-settings__record"
					data-testid="mail-transport">
					<h3 class="deliverability-settings__record-name">
						{{ transport.displayName }}
					</h3>
					<dl class="deliverability-settings__facts">
						<div>
							<dt>{{ t('pipelinq', 'Kind') }}</dt>
							<dd>{{ kindLabel(transport.kind) }}</dd>
						</div>
						<div>
							<dt>{{ t('pipelinq', 'Sender domain') }}</dt>
							<dd>{{ transport.senderDomain || '—' }}</dd>
						</div>
						<div>
							<dt>{{ t('pipelinq', 'Deliverability') }}</dt>
							<dd>
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
							</dd>
						</div>
					</dl>
					<div class="deliverability-settings__record-actions">
						<NcCheckboxRadioSwitch
							type="switch"
							:modelValue="!!transport.active"
							:aria-label="t('pipelinq', 'Active')"
							@update:modelValue="(v) => toggleActive(transport, v)">
							{{
								transport.active
									? t('pipelinq', 'Active')
									: t('pipelinq', 'Inactive')
							}}
						</NcCheckboxRadioSwitch>
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
						<NcButton
							:disabled="checkingId === transport.id"
							@click="checkDeliverability(transport)">
							{{ t('pipelinq', 'Check now') }}
						</NcButton>
					</div>
				</li>
			</ul>
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
	&__toolbar {
		display: flex;
		gap: 8px;
		margin-bottom: 12px;
	}

	&__records {
		display: flex;
		flex-direction: column;
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__record {
		display: flex;
		flex-direction: column;
		gap: 10px;
		padding: 14px 0;
		border-top: 1px solid var(--color-border);

		&:last-child {
			border-bottom: 1px solid var(--color-border);
		}
	}

	&__record-name {
		margin: 0;
		font-size: 15px;
		font-weight: 600;
		overflow-wrap: anywhere;
	}

	&__facts {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
		gap: 8px 16px;
		margin: 0;

		dt,
		dd {
			display: block;
			width: auto;
			margin: 0;
			padding: 0;
			text-align: start;
		}

		dt {
			font-size: 12px;
			color: var(--color-text-maxcontrast);
		}

		dd {
			overflow-wrap: break-word;
		}
	}

	&__record-actions {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px 16px;
	}

	&__verdict {
		display: flex;
		flex-wrap: wrap;
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
