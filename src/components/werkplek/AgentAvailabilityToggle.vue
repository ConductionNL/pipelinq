<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!-- @spec openspec/changes/kcc-werkplek/tasks.md#task-3.1 -->
<template>
	<div class="agent-availability-toggle">
		<button
			type="button"
			class="agent-availability-toggle__toggle"
			:class="{
				'agent-availability-toggle__toggle--available': isAvailable,
				'agent-availability-toggle__toggle--unavailable': !isAvailable,
			}"
			:disabled="saving"
			:aria-pressed="isAvailable"
			:aria-label="description"
			:title="description"
			@click="toggle">
			<span class="agent-availability-toggle__dot" />
			<span class="agent-availability-toggle__label">{{ label }}</span>
		</button>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'

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
	name: 'AgentAvailabilityToggle',

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
		 * What the status means and what a click does, used as tooltip and
		 * aria label. Routing skips agents marked unavailable.
		 *
		 * @return {string}
		 *
		 * @spec openspec/specs/kcc-werkplek/spec.md
		 */
		description() {
			return this.isAvailable
				? this.t(
						'pipelinq',
						'Available: routing suggests you for new contacts. Click to become unavailable.',
					)
				: this.t(
						'pipelinq',
						'Unavailable: routing skips you for new contacts. Click to become available.',
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
				console.warn('[AgentAvailabilityToggle] availability update failed', e)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.agent-availability-toggle {
	display: inline-flex;
	align-items: center;
}

.agent-availability-toggle__toggle {
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

.agent-availability-toggle__toggle:disabled {
	opacity: 0.6;
	cursor: progress;
}

.agent-availability-toggle__dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: var(--color-text-maxcontrast);
}

.agent-availability-toggle__toggle--unavailable:hover:not(:disabled) {
	background: var(--color-background-hover);
}

/* --color-success is a background tint; --color-success-text is the matching foreground. */
.agent-availability-toggle__toggle--available {
	background: var(--color-success);
	border-color: var(--color-success-hover);
	color: var(--color-success-text);
}

.agent-availability-toggle__toggle--available:hover:not(:disabled) {
	background: var(--color-success-hover);
}

.agent-availability-toggle__toggle--available .agent-availability-toggle__dot {
	background: var(--color-success-text);
}
</style>
