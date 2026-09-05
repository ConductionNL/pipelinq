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
		<h2>{{ t('pipelinq', 'Social accounts') }}</h2>

		<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
		<NcNoteCard v-if="notice" type="success">{{ notice }}</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="24" />

		<p v-else-if="accounts.length === 0" class="social-accounts__empty">
			{{ t('pipelinq', 'No social accounts yet.') }}
		</p>

		<ul v-else class="social-accounts__list">
			<li
				v-for="account in accounts"
				:key="accountId(account)"
				class="social-accounts__row"
				:data-testid="'social-account-' + account.network">
				<div class="social-accounts__identity">
					<strong>{{ account.displayName || account.handle }}</strong>
					<span class="social-accounts__handle">{{ account.handle }}</span>
					<span class="social-accounts__network">{{
						networkLabel(account.network)
					}}</span>
				</div>

				<div class="social-accounts__state">
					<span
						class="social-accounts__chip"
						:style="{ color: chip(account.status).color }">
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
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import {
	attachCredential,
	fetchAccounts,
	fetchConnectParameters,
	revokeAccount,
	startBrokerConnection,
} from '../../services/socialApi.js'
import { accountStatusChip, networkLimits } from '../../services/socialNetworks.js'

export default {
	name: 'SocialAccountsView',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
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
	padding: 20px;
}

.social-accounts__list {
	list-style: none;
	padding: 0;
}

.social-accounts__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
	flex-wrap: wrap;
}

.social-accounts__identity {
	display: flex;
	flex-direction: column;
}

.social-accounts__handle,
.social-accounts__network,
.social-accounts__reason {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.social-accounts__state {
	display: flex;
	flex-direction: column;
	max-width: 420px;
}

.social-accounts__chip {
	font-weight: bold;
}

.social-accounts__actions {
	display: flex;
	gap: 8px;
}
</style>
