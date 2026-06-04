<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div v-if="visible"
		class="portal-session-warning"
		role="alert"
		aria-live="polite">
		<span>{{ t('pipelinq', 'Your session expires in {seconds} seconds.', { seconds }) }}</span>
		<button class="portal-button-link" @click="logout">
			{{ t('pipelinq', 'Log out') }}
		</button>
		<button class="portal-button-primary" @click="extend">
			{{ t('pipelinq', 'Extend session') }}
		</button>
	</div>
</template>

<script>
import { portalApi, getExpiry, setToken, getToken, clearToken } from '../portalApi.js'

const WARN_AT_SECONDS = 60

export default {
	name: 'PortalSessionWarning',
	data() {
		return { seconds: WARN_AT_SECONDS, visible: false, timer: null }
	},
	mounted() {
		this.timer = window.setInterval(this.tick, 1000)
	},
	beforeUnmount() {
		if (this.timer) {
			window.clearInterval(this.timer)
		}
	},
	methods: {
		tick() {
			if (!getToken()) {
				this.visible = false
				return
			}
			const remaining = Math.round((getExpiry() - Date.now()) / 1000)
			if (remaining <= 0) {
				this.expire()
				return
			}
			if (remaining <= WARN_AT_SECONDS) {
				this.visible = true
				this.seconds = remaining
			} else {
				this.visible = false
			}
		},
		async extend() {
			try {
				const result = await portalApi.extendSession()
				setToken(getToken(), result.expiresAt)
				this.visible = false
			} catch (e) {
				this.expire()
			}
		},
		async logout() {
			try {
				await portalApi.logout()
			} catch (e) {
				// best-effort
			}
			this.expire()
		},
		expire() {
			clearToken()
			this.visible = false
			if (this.$router.currentRoute.value.path !== '/login') {
				this.$router.push('/login')
			}
		},
	},
}
</script>
