<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div ref="root" class="portal-widget">
		<component :is="innerView" :embedded="true" @logout="onLogout" />
	</div>
</template>

<script>
import PortalLogin from './PortalLogin.vue'
import PortalDashboard from './PortalDashboard.vue'
import { getToken } from '../portalApi.js'

export default {
	name: 'PortalWidget',
	components: { PortalLogin, PortalDashboard },
	data() {
		return { observer: null }
	},
	computed: {
		isEmbedded() {
			return window.self !== window.top
		},
		innerView() {
			return getToken() ? 'PortalDashboard' : 'PortalLogin'
		},
	},
	mounted() {
		this.postResize()
		if (typeof ResizeObserver !== 'undefined') {
			this.observer = new ResizeObserver(() => this.postResize())
			this.observer.observe(this.$refs.root)
		}
	},
	beforeUnmount() {
		if (this.observer) {
			this.observer.disconnect()
		}
	},
	methods: {
		postResize() {
			if (this.isEmbedded && this.$refs.root) {
				window.parent.postMessage({ type: 'portal:resize', height: this.$refs.root.scrollHeight }, '*')
			}
		},
		onLogout() {
			if (this.isEmbedded) {
				window.parent.postMessage({ type: 'portal:logout' }, '*')
			}
		},
	},
}
</script>
