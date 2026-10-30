<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!-- @spec openspec/changes/kcc-werkplek/tasks.md#task-3.1 -->
<template>
	<div class="werkplek-agent-status">
		<!-- In a call the control reads "In gesprek" and opens a menu that still sets the availability (PqWerkplekKlant). -->
		<NcActions
			v-if="inCall"
			class="werkplek-agent-status__call"
			variant="secondary"
			:forceMenu="true"
			:menuName="t('pipelinq', 'In call')"
			data-testid="werkplek-call-state">
			<template #icon>
				<span class="werkplek-agent-status__dot werkplek-agent-status__dot--call" aria-hidden="true" />
			</template>
			<NcActionButton
				type="radio"
				:modelValue="isAvailable"
				:disabled="saving"
				data-testid="werkplek-call-available"
				@click="save(true)">
				{{ t('pipelinq', 'Available') }}
			</NcActionButton>
			<NcActionButton
				type="radio"
				:modelValue="!isAvailable"
				:disabled="saving"
				data-testid="werkplek-call-unavailable"
				@click="save(false)">
				{{ t('pipelinq', 'Unavailable') }}
			</NcActionButton>
		</NcActions>
		<button
			v-else
			type="button"
			class="werkplek-agent-status__toggle"
			:class="{
				'werkplek-agent-status__toggle--available': isAvailable,
				'werkplek-agent-status__toggle--unavailable': !isAvailable,
			}"
			:disabled="saving"
			:aria-pressed="isAvailable"
			:aria-label="description"
			:title="description"
			@click="toggle">
			<span class="werkplek-agent-status__dot" />
			<span class="werkplek-agent-status__label">{{ label }}</span>
			<ChevronDown :size="16" aria-hidden="true" />
		</button>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcActionButton, NcActions } from '@nextcloud/vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'

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

	components: { ChevronDown, NcActionButton, NcActions },

	props: {
		/**
		 * Whether the agent is currently marked available.
		 */
		isAvailable: {
			type: Boolean,
			default: false,
		},

		/**
		 * Whether a call is on: the control reads "In call" and its menu sets the availability.
		 *
		 * @spec openspec/changes/werkplek-in-call-tiles-and-header/specs/kcc-werkplek/spec.md#requirement-the-in-call-header-marks-the-call
		 */
		inCall: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:isAvailable', 'saved'],

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
		toggle() {
			return this.save(!this.isAvailable)
		},

		/**
		 * Set the availability to `next` (the in-call menu's choice); nothing
		 * happens while a save runs or when it already holds.
		 *
		 * @param {boolean} next The availability to save.
		 * @return {Promise<void>}
		 * @spec openspec/changes/werkplek-in-call-tiles-and-header/specs/kcc-werkplek/spec.md#requirement-the-in-call-header-marks-the-call
		 */
		async save(next) {
			if (this.saving || next === this.isAvailable) return
			this.saving = true
			// Optimistic update — emit immediately so the parent reflects the toggle.
			this.$emit('update:isAvailable', next)
			try {
				const url = generateUrl(
					'/apps/pipelinq/api/kcc-werkplek/availability',
				)
				await axios.put(url, { isAvailable: next })
				this.$emit('saved', next)
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

/* The board's availability control (PqDashboard): a neutral button with a
   coloured dot, the state and a chevron; clicking switches the state. */
.werkplek-agent-status__toggle {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	min-height: 40px;
	padding: 0 12px 0 14px;
	border-radius: var(--border-radius-element, 8px);
	border: 1px solid var(--color-border-dark);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	font: inherit;
	font-size: 14px;
	font-weight: 600;
}

.werkplek-agent-status__toggle:hover:not(:disabled) {
	background: var(--color-background-hover);
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
	background: var(--color-success-text);
}

/* In a call the same outlined control reads "In gesprek" with an amber dot. */
.werkplek-agent-status__call :deep(.action-item__menutoggle) {
	min-height: 40px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-weight: 600;
}

.werkplek-agent-status__dot--call {
	display: inline-block;
	background: var(--color-warning);
}
</style>
