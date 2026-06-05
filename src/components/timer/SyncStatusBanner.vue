<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<!--
  SyncStatusBanner — displays online/offline status and the number of
  captures pending sync to the time-tracker leaf.

  @spec openspec/changes/time-entry-mobile/tasks.md#task-3.1
-->
<template>
	<div
		class="sync-status-banner"
		:class="[`sync-status-banner--${statusClass}`]"
		role="status"
		:aria-live="isOffline ? 'assertive' : 'polite'">
		<span class="sync-status-banner__icon" aria-hidden="true">
			{{ isOffline ? '⚡' : (isSyncing ? '↻' : '✓') }}
		</span>
		<span class="sync-status-banner__text">{{ bannerText }}</span>
	</div>
</template>

<script>
export default {
	name: 'SyncStatusBanner',

	props: {
		/** Whether the device is currently offline. */
		isOffline: {
			type: Boolean,
			default: false,
		},

		/** Whether a sync flush is in progress. */
		isSyncing: {
			type: Boolean,
			default: false,
		},

		/** Number of captures pending sync to the leaf. */
		pendingCount: {
			type: Number,
			default: 0,
		},
	},

	computed: {
		/**
		 * CSS modifier class for the current status.
		 *
		 * @return {string}
		 */
		statusClass() {
			if (this.isOffline) return 'offline'
			if (this.isSyncing) return 'syncing'
			if (this.pendingCount > 0) return 'pending'
			return 'online'
		},

		/**
		 * Human-readable status text.
		 *
		 * @return {string}
		 */
		bannerText() {
			if (this.isOffline) {
				if (this.pendingCount > 0) {
					return this.t('pipelinq', '%n capture pending sync', '%n captures pending sync', this.pendingCount)
				}
				return this.t('pipelinq', 'Offline — entries will sync when reconnected')
			}
			if (this.isSyncing) {
				return this.t('pipelinq', 'Syncing to server…')
			}
			if (this.pendingCount > 0) {
				return this.t('pipelinq', '%n capture pending sync', '%n captures pending sync', this.pendingCount)
			}
			return this.t('pipelinq', 'Online — all entries synced')
		},
	},
}
</script>

<style scoped>
.sync-status-banner {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 16px;
	border-radius: var(--border-radius-element, 6px);
	font-size: 0.875rem;
	font-weight: 500;
	min-height: 44px;
}

.sync-status-banner--offline {
	background-color: var(--color-warning-bg, #f8d7da);
	color: var(--color-warning-text, #842029);
	border: 1px solid var(--color-warning-border, #f5c2c7);
}

.sync-status-banner--syncing {
	background-color: var(--color-info-bg, #d1ecf1);
	color: var(--color-info-text, #0c5460);
	border: 1px solid var(--color-info-border, #bee5eb);
}

.sync-status-banner--pending {
	background-color: var(--color-info-bg, #fff3cd);
	color: var(--color-info-text, #856404);
	border: 1px solid var(--color-info-border, #ffecb5);
}

.sync-status-banner--online {
	background-color: var(--color-success-bg, #d1e7dd);
	color: var(--color-success-text, #0f5132);
	border: 1px solid var(--color-success-border, #badbcc);
}

.sync-status-banner__icon {
	font-size: 1.1rem;
	flex-shrink: 0;
}
</style>
