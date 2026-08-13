<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!-- @spec openspec/changes/kcc-werkplek/tasks.md#task-3.1 -->
<template>
	<div class="werkplek-agent-status">
		<button
			type="button"
			class="werkplek-agent-status__toggle"
			:class="{
				'werkplek-agent-status__toggle--available': isAvailable,
				'werkplek-agent-status__toggle--unavailable': !isAvailable,
			}"
			:disabled="saving"
			:aria-pressed="isAvailable"
			:aria-label="ariaLabel"
			@click="toggle">
			<span class="werkplek-agent-status__dot" />
			<span class="werkplek-agent-status__label">{{ label }}</span>
		</button>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'

/**
 * Agent availability toggle button for the KCC Werkplek.
 *
 * Renders a green "Beschikbaar" / grey "Niet beschikbaar" button driven
 * by the `isAvailable` prop. Clicking the toggle PUTs the new value to
 * `/api/kcc-werkplek/availability`; failures revert the toggle and
 * surface a user-facing error dialog so the agent knows the change did
 * not stick (REQ-KWP-040).
 *
 * @spec openspec/changes/kcc-werkplek/tasks.md#task-3.1
 * @spec openspec/specs/kcc-werkplek/spec.md
 */
export default {
	name: 'WerkplekAgentStatus',

	props: {
		/**
		 * Whether the agent is currently marked available.
		 */
		isAvailable: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:isAvailable'],

	data() {
		return {
			saving: false,
		}
	},

	computed: {
		/**
		 * Visible button label — "Beschikbaar" / "Niet beschikbaar".
		 *
		 * @return {string}
		 */
		label() {
			return this.isAvailable
				? this.t('pipelinq', 'Available')
				: this.t('pipelinq', 'Unavailable')
		},
		/**
		 * Aria label describing the toggle action (a11y).
		 *
		 * @return {string}
		 */
		ariaLabel() {
			return this.isAvailable
				? this.t(
						'pipelinq',
						'Currently available — click to mark unavailable',
					)
				: this.t(
						'pipelinq',
						'Currently unavailable — click to mark available',
					)
		},
	},

	methods: {
		/**
		 * Toggle the availability flag. The new value is sent to the server;
		 * if the call fails the UI is reverted (parent watches isAvailable).
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/kcc-werkplek/tasks.md#task-3.1
		 */
		async toggle() {
			if (this.saving) return
			const next = !this.isAvailable
			this.saving = true
			// Optimistic update — emit immediately so the parent reflects the toggle.
			this.$emit('update:isAvailable', next)
			try {
				const url = generateUrl(
					'/apps/pipelinq/api/kcc-werkplek/availability',
				)
				await axios.put(url, { isAvailable: next })
			} catch (e) {
				// Revert + surface the error.
				this.$emit('update:isAvailable', !next)
				try {
					showError(
						this.t(
							'pipelinq',
							'Could not update availability. Please try again.',
						),
					)
				} catch {
					// dialogs lib may be missing in tests — ignore.
				}
				// eslint-disable-next-line no-console
				console.warn('[WerkplekAgentStatus] availability update failed', e)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.werkplek-agent-status {
	display: inline-flex;
	align-items: center;
}

.werkplek-agent-status__toggle {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 6px 14px;
	border-radius: var(--border-radius-pill, 999px);
	border: 1px solid var(--color-border);
	background: var(--color-background-dark);
	cursor: pointer;
	font: inherit;
}

.werkplek-agent-status__toggle:disabled {
	opacity: 0.6;
	cursor: progress;
}

.werkplek-agent-status__dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: var(--color-text-maxcontrast);
}

.werkplek-agent-status__toggle--available .werkplek-agent-status__dot {
	background: var(--color-success);
}

.werkplek-agent-status__toggle--available {
	border-color: var(--color-success);
	color: var(--color-success);
}

.werkplek-agent-status__toggle--unavailable .werkplek-agent-status__dot {
	background: var(--color-text-maxcontrast);
}
</style>
