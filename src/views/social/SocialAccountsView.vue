<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The connected social accounts, and the one place a connection is started,
  - restarted or ended.
  -
  - A CUSTOM page rather than a declarative type:index, for one reason that is
  - not about styling: the Connect action is a three-step conversation the
  - declarative grammar has no verb for. Pipelinq answers WHAT to connect, the
  - browser posts that to OpenRegister's own connect endpoint with the user's
  - session, and the network's consent screen comes back to this page's own
  - path. The authorization code and the token never pass through Pipelinq at
  - any step, which is the arrangement rule 2 of the marketing architecture
  - asks for and the reason the flow cannot be a form field.
  -
  - The page is PATH-routed. The return address is
  - /apps/pipelinq/social-accounts/{id}, because OpenRegister's
  - `safeReturnUrl()` keeps only the PATH of a proposed return URL: a query
  - string would be dropped and the account would be unidentifiable on the way
  - back.
  -
  - @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
  -->
<template>
	<div class="social-accounts" data-testid="social-accounts">
		<h2 class="social-accounts__title">
			{{ t('pipelinq', 'Social accounts') }}
		</h2>

		<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
		<NcNoteCard v-if="notice" type="success">{{ notice }}</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="32" class="social-accounts__loading" />

		<NcEmptyContent
			v-else-if="accounts.length === 0"
			class="social-accounts__empty"
			:name="t('pipelinq', 'No social accounts yet.')">
			<template #icon>
				<ShareVariantOutline :size="20" />
			</template>
		</NcEmptyContent>

		<!-- One grid for the whole list, and each row a subgrid of it, so the
			columns line up across rows whatever each row's content length. -->
		<ul v-else class="social-accounts__list">
			<li
				v-for="account in accounts"
				:key="accountId(account)"
				class="social-accounts__row"
				:data-testid="'social-account-' + account.network">
				<span class="social-accounts__icon" aria-hidden="true">
					<component :is="networkIcon(account.network)" :size="24" />
				</span>

				<div class="social-accounts__identity">
					<strong class="social-accounts__name">
						{{ account.displayName || account.handle }}
					</strong>
					<span class="social-accounts__meta">
						<template v-if="showHandle(account)">
							{{ account.handle }} ·
						</template>
						{{ networkLabel(account.network) }}
					</span>
				</div>

				<div class="social-accounts__state">
					<span class="social-accounts__chip">
						<span
							class="social-accounts__dot"
							:style="{ background: chip(account.status).color }" />
						{{ chip(account.status).label }}
					</span>
					<span v-if="reasonFor(account)" class="social-accounts__reason">
						{{ reasonFor(account) }}
					</span>
				</div>

				<div class="social-accounts__actions">
					<NcButton
						v-if="canConnect(account)"
						variant="primary"
						:disabled="busy === accountId(account)"
						@click="connect(account)">
						{{ connectLabel(account) }}
					</NcButton>
					<NcButton
						v-if="account.credentialRef"
						variant="tertiary"
						:disabled="busy === accountId(account)"
						@click="revoke(account)">
						{{ t('pipelinq', 'Revoke') }}
					</NcButton>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import ShareVariantOutline from 'vue-material-design-icons/ShareVariantOutline.vue'
import {
	attachCredential,
	fetchAccounts,
	fetchConnectParameters,
	revokeAccount,
	startBrokerConnection,
} from '../../services/socialApi.js'
import { networkIcon } from '../../services/socialNetworkIcons.js'
import { accountStatusChip, networkLimits } from '../../services/socialNetworks.js'

export default {
	name: 'SocialAccountsView',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		ShareVariantOutline,
	},

	data() {
		return {
			loading: false,
			busy: '',
			error: '',
			notice: '',
			accounts: [],
			readiness: {},
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * The id of one account row.
		 *
		 * @param {object} account The account.
		 * @return {string} Its id.
		 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
		 */
		accountId(account) {
			return account?.id || account?.uuid || ''
		},

		/**
		 * The chip a status renders.
		 *
		 * @param {string} status The stored status.
		 * @return {object} The chip.
		 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
		 */
		chip(status) {
			return accountStatusChip(status)
		},

		/**
		 * The network's own name.
		 *
		 * @param {string} network The network.
		 * @return {string} Its label.
		 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
		 */
		networkLabel(network) {
			return networkLimits(network).label
		},

		/**
		 * The icon an account's network is shown with.
		 *
		 * @param {string} network The network.
		 * @return {object} Its icon component.
		 *
		 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
		 */
		networkIcon(network) {
			return networkIcon(network)
		},

		/**
		 * Whether the handle adds anything under the name. It does not when the
		 * name already is the handle, or when there is no separate name.
		 *
		 * @param {object} account The account.
		 * @return {boolean} True when the handle differs from the shown name.
		 *
		 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
		 */
		showHandle(account) {
			return Boolean(
				account?.displayName
				&& account?.handle
				&& account.handle !== account.displayName,
			)
		},

		/**
		 * What to tell the marketer about this account, preferring the account's
		 * own reason over the network's general one.
		 *
		 * @param {object} account The account.
		 * @return {string} The reason, or an empty string.
		 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
		 */
		reasonFor(account) {
			if (account?.statusReason) {
				return account.statusReason
			}
			return this.readiness?.[account?.network]?.reason || ''
		},

		/**
		 * Whether a Connect button can do anything at all. A network with no
		 * developer application filed gets its reason instead of a button that
		 * would fail.
		 *
		 * @param {object} account The account.
		 * @return {boolean} True when connecting is possible.
		 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
		 */
		canConnect(account) {
			return (
				this.readiness?.[account?.network]?.state !== 'not_configured'
				&& account?.publishMode !== 'share'
			)
		},

		/**
		 * @param {object} account The account.
		 * @return {string} Connect, or Reconnect for a grant that has ended.
		 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
		 */
		connectLabel(account) {
			return account?.credentialRef
				? t('pipelinq', 'Reconnect')
				: t('pipelinq', 'Connect')
		},

		/**
		 * Load the accounts, then finish a connection when the browser came
		 * back from a consent screen.
		 *
		 * @return {Promise<void>} Resolves once loaded.
		 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const answer = await fetchAccounts()
				this.accounts = answer.data
				this.readiness = answer.readiness
			} catch {
				this.error = t('pipelinq', 'The accounts could not be loaded.')
			} finally {
				this.loading = false
			}

			await this.finishConnection()
		},

		/**
		 * Record the credential when the consent screen sent the browser back
		 * here with `?connected=ok`.
		 *
		 * @return {Promise<void>} Resolves once recorded.
		 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
		 */
		async finishConnection() {
			const id = this.$route?.params?.id || ''
			const outcome = this.$route?.query?.connected || ''
			if (!id || !outcome) {
				return
			}

			if (outcome !== 'ok') {
				this.error = t(
					'pipelinq',
					'The connection was not completed. Try connecting the account again.',
				)
				return
			}

			try {
				await attachCredential(id)
				this.notice = t('pipelinq', 'The account is connected.')
				const answer = await fetchAccounts()
				this.accounts = answer.data
				this.readiness = answer.readiness
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('pipelinq', 'The connection could not be recorded.')
			}
		},

		/**
		 * Start, or restart, a connection.
		 *
		 * @param {object} account The account.
		 * @return {Promise<void>} Resolves once the browser is sent onward.
		 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
		 */
		async connect(account) {
			const id = this.accountId(account)
			this.busy = id
			this.error = ''
			try {
				const connect = await fetchConnectParameters(id)
				const url = await startBrokerConnection(connect)
				if (!url) {
					this.error = t(
						'pipelinq',
						'The connection could not be started.',
					)
					return
				}
				window.location.href = url
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('pipelinq', 'The connection could not be started.')
			} finally {
				this.busy = ''
			}
		},

		/**
		 * End a connection. The account keeps its row so the publications that
		 * already went out still name it.
		 *
		 * @param {object} account The account.
		 * @return {Promise<void>} Resolves once revoked.
		 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
		 */
		async revoke(account) {
			const id = this.accountId(account)
			this.busy = id
			this.error = ''
			try {
				await revokeAccount(id)
				const answer = await fetchAccounts()
				this.accounts = answer.data
				this.readiness = answer.readiness
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('pipelinq', 'The connection could not be revoked.')
			} finally {
				this.busy = ''
			}
		},
	},
}
</script>

<style scoped>
.social-accounts {
	box-sizing: border-box;
	width: 100%;
	max-width: 960px;
	margin: 0 auto;
	padding: 24px 20px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.social-accounts__title {
	margin: 0;
}

.social-accounts__loading {
	margin: 32px auto;
}

/* The edge columns are `auto`, not fixed: a subgrid row's padding is added to
   its edge tracks, and a fixed 44px track cannot grow to hold it. */
.social-accounts__list {
	display: grid;
	grid-template-columns: auto minmax(160px, 1fr) minmax(0, 2fr) auto;
	gap: 8px 16px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.social-accounts__row {
	grid-column: 1 / -1;
	display: grid;
	grid-template-columns: subgrid;
	align-items: center;
	padding: 14px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.social-accounts__icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 44px;
	height: 44px;
	border-radius: 50%;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.social-accounts__identity,
.social-accounts__state {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.social-accounts__name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.social-accounts__meta,
.social-accounts__reason {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.social-accounts__chip {
	display: inline-flex;
	align-items: center;
	align-self: flex-start;
	gap: 6px;
	padding: 2px 10px;
	border-radius: 999px;
	background: var(--color-background-dark);
	font-weight: 600;
	font-size: 0.9em;
}

.social-accounts__dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
}

.social-accounts__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}

/* Narrow screens: name beside the icon, status and actions underneath. */
@media (max-width: 720px) {
	.social-accounts__list {
		grid-template-columns: auto minmax(0, 1fr);
	}

	.social-accounts__row {
		row-gap: 10px;
	}

	.social-accounts__state,
	.social-accounts__actions {
		grid-column: 2;
	}

	.social-accounts__actions {
		justify-content: flex-start;
	}
}
</style>
