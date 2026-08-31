<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="portal-app" :class="{ 'portal-app--embedded': embedded }">
		<a class="portal-skip-link" href="#portal-main-content">
			{{ t('pipelinq', 'Skip to main content') }}
		</a>
		<header v-if="!embedded && authenticated" class="portal-header">
			<span class="portal-brand">{{ branding.displayName }}</span>
			<nav class="portal-nav" :aria-label="t('pipelinq', 'Portal navigation')">
				<router-link to="/dashboard">
					{{ t('pipelinq', 'Documents') }}
				</router-link>
				<router-link to="/requests">
					{{ t('pipelinq', 'Requests') }}
				</router-link>
				<router-link to="/profile">
					{{ t('pipelinq', 'My details') }}
				</router-link>
				<router-link v-if="isB2b" to="/delegations">
					{{ t('pipelinq', 'Shared access') }}
				</router-link>
				<router-link to="/export">
					{{ t('pipelinq', 'Privacy') }}
				</router-link>
				<button class="portal-button-link" @click="logout">
					{{ t('pipelinq', 'Log out') }}
				</button>
			</nav>
		</header>
		<main id="portal-main-content" class="portal-main" tabindex="-1">
			<router-view />
		</main>
		<PortalSessionWarning v-if="!embedded" />
	</div>
</template>

<script>
import PortalSessionWarning from './components/PortalSessionWarning.vue'
import { clearToken, getToken, portalApi } from './portalApi.js'
import { isAuthenticated } from './portalRoutes.js'

export default {
	name: 'PortalApp',
	components: { PortalSessionWarning },
	data() {
		return {
			branding: {
				displayName: t('pipelinq', 'Customer portal'),
				brandPrimaryColor: '#21468B',
			},

			isB2b: false,
			embedded: window.self !== window.top,
		}
	},

	computed: {
		authenticated() {
			return isAuthenticated()
		},
	},

	async mounted() {
		try {
			this.branding = await portalApi.tenantConfig()
			this.applyBranding()
		} catch {
			// Branding is best-effort; the portal still works with defaults.
		}
		if (this.authenticated) {
			try {
				const profile = await portalApi.profile()
				this.isB2b = profile.accountType === 'b2b'
			} catch {
				// ignore
			}
		}
	},

	methods: {
		applyBranding() {
			const root = document.documentElement
			if (this.branding.brandPrimaryColor) {
				root.style.setProperty(
					'--portal-brand-primary',
					this.branding.brandPrimaryColor,
				)
			}
			if (this.branding.brandSecondaryColor) {
				root.style.setProperty(
					'--portal-brand-secondary',
					this.branding.brandSecondaryColor,
				)
			}
		},

		async logout() {
			try {
				await portalApi.logout()
			} catch {
				// best-effort
			}
			clearToken()
			this.$router.push('/login')
		},

		hasToken() {
			return !!getToken()
		},
	},
}
</script>

<style scoped>
.portal-app {
	max-width: 960px;
	margin: 0 auto;
	padding: 1rem;
	color: var(--color-main-text, #222);
}

.portal-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	border-bottom: 2px solid var(--portal-brand-primary, #21468b);
	padding-bottom: 0.5rem;
	margin-bottom: 1rem;
}

.portal-nav a,
.portal-nav button {
	margin-left: 1rem;
}

.portal-field {
	margin-bottom: 1rem;
	display: flex;
	flex-direction: column;
}

.portal-field input,
.portal-field select,
.portal-field textarea {
	padding: 0.5rem;
	border: 1px solid var(--color-border, #999);
	border-radius: 4px;
}

.portal-table {
	width: 100%;
	border-collapse: collapse;
}

.portal-table th,
.portal-table td {
	text-align: left;
	padding: 0.5rem;
	border-bottom: 1px solid var(--color-border, #ddd);
}

.portal-button-primary {
	background: var(--portal-brand-primary, #21468b);
	color: #fff;
	border: none;
	border-radius: 4px;
	padding: 0.5rem 1rem;
	cursor: pointer;
}

.portal-button-danger {
	background: #b00020;
	color: #fff;
	border: none;
	border-radius: 4px;
	padding: 0.5rem 1rem;
	cursor: pointer;
}

.portal-button-link {
	background: none;
	border: none;
	color: var(--portal-brand-primary, #21468b);
	text-decoration: underline;
	cursor: pointer;
	padding: 0;
}

.portal-error {
	color: #b00020;
}

.portal-success {
	color: #1a7f37;
}

.portal-session-warning {
	position: fixed;
	bottom: 1rem;
	left: 50%;
	transform: translateX(-50%);
	background: #fff;
	border: 2px solid var(--portal-brand-primary, #21468b);
	border-radius: 8px;
	padding: 1rem;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}

.portal-app :focus-visible {
	outline: 2px solid var(--portal-brand-primary, #21468b);
	outline-offset: 2px;
}

.portal-app--embedded .portal-header {
	display: none;
}
</style>
