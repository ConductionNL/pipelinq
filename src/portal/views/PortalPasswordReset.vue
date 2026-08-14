<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="portal-password-reset">
		<h1>{{ t('pipelinq', 'Reset your password') }}</h1>

		<form v-if="!token" @submit.prevent="requestReset">
			<div class="portal-field">
				<label for="portal-reset-email">{{
					t('pipelinq', 'Email address')
				}}</label>
				<input
					id="portal-reset-email"
					v-model="email"
					type="email"
					autocomplete="email"
					required />
			</div>
			<button type="submit" class="portal-button-primary">
				{{ t('pipelinq', 'Send reset link') }}
			</button>
		</form>

		<form v-else @submit.prevent="doReset">
			<div class="portal-field">
				<label for="portal-new-password">{{
					t('pipelinq', 'New password')
				}}</label>
				<input
					id="portal-new-password"
					v-model="password"
					type="password"
					autocomplete="new-password"
					minlength="10"
					required
					:aria-describedby="error ? 'portal-reset-error' : null" />
			</div>
			<button type="submit" class="portal-button-primary">
				{{ t('pipelinq', 'Set new password') }}
			</button>
		</form>

		<p v-if="message" role="status" aria-live="polite" class="portal-success">
			{{ message }}
		</p>
		<p v-if="error" id="portal-reset-error" role="alert" class="portal-error">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { portalApi } from '../portalApi.js'

export default {
	name: 'PortalPasswordReset',
	data() {
		const params = new URLSearchParams(window.location.search)
		return {
			email: '',
			password: '',
			token: params.get('token') || '',
			message: '',
			error: '',
		}
	},
	methods: {
		async requestReset() {
			this.message = ''
			this.error = ''
			try {
				await portalApi.passwordResetRequest(this.email)
				this.message = t(
					'pipelinq',
					'If that email address exists, a reset link has been sent.',
				)
			} catch (e) {
				this.error = e.message
			}
		},
		async doReset() {
			this.message = ''
			this.error = ''
			try {
				await portalApi.passwordReset(this.token, this.password)
				this.message = t(
					'pipelinq',
					'Your password has been reset. You can now sign in.',
				)
				setTimeout(() => this.$router.push('/login'), 2000)
			} catch (e) {
				this.error =
					e.message || t('pipelinq', 'Could not reset your password.')
			}
		},
	},
}
</script>
