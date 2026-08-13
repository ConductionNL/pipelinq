<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="portal-login">
		<h1>{{ t('pipelinq', 'Sign in to the portal') }}</h1>
		<form @submit.prevent="onSubmit">
			<div class="portal-field">
				<label for="portal-email">{{
					t('pipelinq', 'Email address')
				}}</label>
				<input
					id="portal-email"
					v-model="email"
					type="email"
					autocomplete="email"
					required
					:aria-describedby="error ? 'portal-login-error' : null" />
			</div>
			<div class="portal-field">
				<label for="portal-password">{{ t('pipelinq', 'Password') }}</label>
				<input
					id="portal-password"
					v-model="password"
					type="password"
					autocomplete="current-password"
					required />
			</div>
			<div v-if="mfaRequired" class="portal-field">
				<label for="portal-totp">{{
					t('pipelinq', 'Verification code')
				}}</label>
				<input
					id="portal-totp"
					v-model="totpCode"
					type="text"
					inputmode="numeric"
					autocomplete="one-time-code"
					maxlength="6" />
			</div>
			<p
				v-if="error"
				id="portal-login-error"
				role="alert"
				class="portal-error">
				{{ error }}
			</p>
			<button type="submit" :disabled="loading" class="portal-button-primary">
				{{ t('pipelinq', 'Sign in') }}
			</button>
		</form>
		<p>
			<a href="#/password-reset">{{
				t('pipelinq', 'Forgotten your password?')
			}}</a>
		</p>
	</div>
</template>

<script>
import { portalApi, setToken } from '../portalApi.js'

export default {
	name: 'PortalLogin',
	data() {
		return {
			email: '',
			password: '',
			totpCode: '',
			mfaRequired: false,
			error: '',
			loading: false,
		}
	},
	methods: {
		async onSubmit() {
			this.error = ''
			this.loading = true
			try {
				const result = await portalApi.login(
					this.email,
					this.password,
					this.totpCode || null,
				)
				if (result.status === 'authenticated' && result.token) {
					setToken(result.token, result.expiresAt)
					this.$router.push('/dashboard')
					return
				}
				if (result.mfaRequired) {
					this.mfaRequired = true
					this.error =
						result.status === 'mfa-enrollment-required'
							? t(
									'pipelinq',
									'Two-factor authentication setup is required.',
								)
							: t('pipelinq', 'Enter your verification code.')
				}
			} catch (e) {
				this.error =
					e.message || t('pipelinq', 'Email or password is incorrect.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
