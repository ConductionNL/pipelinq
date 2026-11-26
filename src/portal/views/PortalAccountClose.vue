<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="portal-account-close">
		<h1>{{ t('pipelinq', 'Close your portal account') }}</h1>

		<p v-if="!token" role="alert" class="portal-error">
			{{ t('pipelinq', 'This confirmation link is incomplete. Open the link from the email again.') }}
		</p>

		<template v-else-if="!closed">
			<p>
				{{ t('pipelinq', 'After closing you can no longer sign in, and every open session ends. Records the law requires us to keep are kept.') }}
			</p>
			<button
				class="portal-button-danger"
				:disabled="busy"
				@click="confirm">
				{{ t('pipelinq', 'Close my account for good') }}
			</button>
		</template>

		<p v-if="closed" role="status" aria-live="polite" class="portal-success">
			{{ t('pipelinq', 'Your account is closed. You can no longer sign in.') }}
		</p>
		<p v-if="error" role="alert" class="portal-error">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { clearToken, portalApi } from '../portalApi.js'

/**
 * The page the account-closing email links to. Opening the link closes
 * nothing: a mail scanner that follows links must not close an account, so
 * the client confirms with the button, which sends the link's token.
 *
 * @spec openspec/changes/specify-capability-rows/specs/customer-portal/spec.md#requirement-a-client-closes-their-own-portal-account
 */
export default {
	name: 'PortalAccountClose',
	data() {
		const params = new URLSearchParams(window.location.search)
		return {
			token: params.get('token') || '',
			busy: false,
			closed: false,
			error: '',
		}
	},

	methods: {
		/**
		 * Confirm the closing with the link's token; on success forget the local session.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/specify-capability-rows/specs/customer-portal/spec.md#requirement-a-client-closes-their-own-portal-account
		 */
		async confirm() {
			this.error = ''
			this.busy = true
			try {
				await portalApi.confirmClose(this.token)
				clearToken()
				this.closed = true
			} catch (e) {
				this.error = e.message || t('pipelinq', 'Could not close your account.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>
